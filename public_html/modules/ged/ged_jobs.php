<?php
declare(strict_types=1);

/**
 * Ma GED Box — Helper queue interne (ged_jobs)
 * =============================================
 *
 * API publique pour la queue de tâches lourdes différées (analyse IA avancée,
 * OCR cascade, import lots, etc.) — au lieu du fastcgi_finish_request()
 * best-effort qui n'est pas fiable selon l'hébergeur.
 *
 * Pattern :
 *   1. L'API HTTP appelle gedJobsEnqueue('analyze_advanced', [...])
 *      → réponse JSON immédiate au client (job_id renvoyé)
 *   2. Le worker CLI (scripts/ged_worker.php) tourne via cron 1/min,
 *      claim les jobs queued, les exécute, marque done/error
 *   3. Retry auto : un job error avec attempts < max_attempts est re-claimable
 *
 * Verrou : SELECT FOR UPDATE SKIP LOCKED si dispo (MySQL 8+),
 *           sinon UPDATE atomique avec WHERE locked_at IS NULL.
 *
 * AJOUT uniquement — ne touche aucune table existante.
 */

if (!function_exists('ged_jobs_pdo')) {
    function ged_jobs_pdo(): PDO
    {
        $pdo = $GLOBALS['pdo'] ?? null;
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('ged_jobs: PDO indisponible (bootstrap.php non chargé)');
        }
        return $pdo;
    }
}

/**
 * Pousse un job dans la queue.
 *
 * @param string   $type        Type de job ('analyze_advanced', 'ocr', 'classify', 'link', 'rename', 'import_zip', ...)
 * @param array    $payload     Paramètres JSON-serializable du job
 * @param int|null $societeId   Scope société (multi-tenant)
 * @param int|null $userId      User à l'origine
 * @param int      $maxAttempts Nb max de tentatives (défaut 3)
 * @return int                  ID du job créé
 */
function gedJobsEnqueue(
    string $type,
    array $payload = [],
    ?int $societeId = null,
    ?int $userId = null,
    int $maxAttempts = 3
): int {
    $pdo = ged_jobs_pdo();
    $st = $pdo->prepare("
        INSERT INTO ged_jobs
            (type, payload_json, status, max_attempts, id_societe, id_user)
        VALUES (?, ?, 'queued', ?, ?, ?)
    ");
    $st->execute([
        $type,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        max(1, min(10, $maxAttempts)),
        $societeId,
        $userId,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Réclame jusqu'à $maxJobs jobs disponibles pour exécution.
 * Verrou par UPDATE atomique : un seul worker peut claim un job donné.
 *
 * Conditions de claim :
 *   - status IN ('queued', 'error')
 *   - attempts < max_attempts
 *   - locked_at IS NULL (jamais verrouillé)
 *      OU locked_at < NOW() - INTERVAL N MINUTES (verrou expiré)
 *
 * @param string $workerId  Identifiant du worker (PID@host)
 * @param int    $maxJobs   Nb max de jobs à claim (1 par défaut)
 * @param int    $lockTimeoutMinutes  Délai après lequel un verrou est considéré expiré
 * @return array            Liste des jobs claim ([{id, type, payload_json, attempts, ...}])
 */
function gedJobsClaim(string $workerId, int $maxJobs = 1, int $lockTimeoutMinutes = 10): array
{
    $pdo = ged_jobs_pdo();

    // Sélection des candidats (sans verrou) puis UPDATE atomique 1 par 1.
    // Pas de SELECT FOR UPDATE SKIP LOCKED pour rester compatible MySQL 5.7.
    $candidates = $pdo->prepare("
        SELECT id FROM ged_jobs
        WHERE status IN ('queued', 'error')
          AND attempts < max_attempts
          AND (locked_at IS NULL OR locked_at < (NOW() - INTERVAL ? MINUTE))
        ORDER BY id ASC
        LIMIT ?
    ");
    $candidates->bindValue(1, $lockTimeoutMinutes, PDO::PARAM_INT);
    $candidates->bindValue(2, $maxJobs, PDO::PARAM_INT);
    $candidates->execute();
    $ids = $candidates->fetchAll(PDO::FETCH_COLUMN) ?: [];

    $claimed = [];
    foreach ($ids as $id) {
        // Tentative de claim atomique : si déjà pris par un autre worker, skip
        $upd = $pdo->prepare("
            UPDATE ged_jobs
            SET status = 'running', locked_at = NOW(), locked_by = ?,
                attempts = attempts + 1, started_at = NOW()
            WHERE id = ?
              AND status IN ('queued', 'error')
              AND (locked_at IS NULL OR locked_at < (NOW() - INTERVAL ? MINUTE))
        ");
        $upd->bindValue(1, $workerId);
        $upd->bindValue(2, (int)$id, PDO::PARAM_INT);
        $upd->bindValue(3, $lockTimeoutMinutes, PDO::PARAM_INT);
        $upd->execute();

        if ($upd->rowCount() === 1) {
            $row = $pdo->prepare("SELECT * FROM ged_jobs WHERE id = ?");
            $row->execute([(int)$id]);
            $job = $row->fetch(PDO::FETCH_ASSOC);
            if ($job) $claimed[] = $job;
        }
    }
    return $claimed;
}

/**
 * Marque un job comme terminé avec succès.
 *
 * @param int        $jobId
 * @param array|null $result  Résultat optionnel à stocker dans result_json
 */
function gedJobsMarkDone(int $jobId, ?array $result = null): void
{
    $pdo = ged_jobs_pdo();
    $pdo->prepare("
        UPDATE ged_jobs
        SET status = 'done', finished_at = NOW(), error_message = NULL,
            result_json = ?
        WHERE id = ?
    ")->execute([
        $result === null ? null : json_encode($result, JSON_UNESCAPED_UNICODE),
        $jobId,
    ]);
}

/**
 * Marque un job comme erreur. Retryable selon attempts < max_attempts.
 *
 * @param int    $jobId
 * @param string $msg
 * @param bool   $forceFinal  Si true, marque définitivement en erreur (ne retry pas)
 */
function gedJobsMarkError(int $jobId, string $msg, bool $forceFinal = false): void
{
    $pdo = ged_jobs_pdo();

    if ($forceFinal) {
        // Force max_attempts atteint pour empêcher le retry
        $pdo->prepare("
            UPDATE ged_jobs
            SET status = 'error', finished_at = NOW(), error_message = ?,
                attempts = max_attempts
            WHERE id = ?
        ")->execute([substr($msg, 0, 60000), $jobId]);
    } else {
        $pdo->prepare("
            UPDATE ged_jobs
            SET status = 'error', finished_at = NOW(), error_message = ?
            WHERE id = ?
        ")->execute([substr($msg, 0, 60000), $jobId]);
    }
}

/**
 * Stats rapides pour le dashboard admin.
 *
 * @return array  ['queued' => N, 'running' => N, 'done' => N, 'error' => N]
 */
function gedJobsStats(?int $societeId = null): array
{
    $pdo = ged_jobs_pdo();
    $where = $societeId === null ? '' : "WHERE id_societe = " . (int)$societeId;
    $rows = $pdo->query("SELECT status, COUNT(*) AS n FROM ged_jobs {$where} GROUP BY status")
                ->fetchAll(PDO::FETCH_KEY_PAIR);
    return array_merge(['queued' => 0, 'running' => 0, 'done' => 0, 'error' => 0], $rows ?: []);
}

/**
 * Décode le payload JSON d'un job.
 *
 * @param array $job  Ligne ged_jobs (avec colonne payload_json)
 * @return array
 */
function gedJobsPayload(array $job): array
{
    $raw = (string)($job['payload_json'] ?? '');
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Réinitialise un job en 'queued' pour relance manuelle (admin).
 * Utile depuis un dashboard pour forcer un retry sur un job 'error' déjà au max.
 */
function gedJobsRetry(int $jobId): void
{
    $pdo = ged_jobs_pdo();
    $pdo->prepare("
        UPDATE ged_jobs
        SET status = 'queued', attempts = 0, locked_at = NULL, locked_by = NULL,
            started_at = NULL, finished_at = NULL, error_message = NULL
        WHERE id = ?
    ")->execute([$jobId]);
}
