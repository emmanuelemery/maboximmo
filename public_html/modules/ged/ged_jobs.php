<?php
declare(strict_types=1);

/**
 * GED MaBoxImmo — Queue jobs (asynchrone fiable via BDD + cron).
 * Fichier : modules/ged/ged_jobs.php
 *
 * Philosophie :
 * - Ne jamais dépendre du "continue après réponse HTTP" (best-effort).
 * - Enqueue depuis l'UI, exécute via cron tokenisé.
 */

require_once __DIR__ . '/ged_functions.php';

/**
 * Enqueue un job GED.
 *
 * @param array<string,mixed> $payload
 */
function ged_jobs_enqueue(string $jobType, ?int $analysisId, array $payload, int $priority = 5, ?string $runAt = null, int $maxAttempts = 3): int
{
    $pdo = ged_get_pdo();

    // Best-effort anti-doublon : si un job identique est déjà queued/running pour la même analyse, on renvoie son id.
    if ($analysisId !== null) {
        $st = $pdo->prepare("
            SELECT id FROM ged_jobs
            WHERE job_type = :t
              AND analysis_id = :aid
              AND status IN ('queued','running')
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $st->execute(['t' => $jobType, 'aid' => $analysisId]);
        $existing = (int)$st->fetchColumn();
        if ($existing > 0) return $existing;
    }

    $stmt = $pdo->prepare("
        INSERT INTO ged_jobs
            (job_type, status, priority, run_at, analysis_id, payload_json, attempts, max_attempts, created_at)
        VALUES
            (:t, 'queued', :p, :run_at, :aid, :payload, 0, :max, NOW())
    ");
    $stmt->execute([
        't'      => $jobType,
        'p'      => max(0, min(9, $priority)),
        'run_at' => $runAt,
        'aid'    => $analysisId,
        'payload'=> json_encode($payload, JSON_UNESCAPED_UNICODE),
        'max'    => max(1, min(10, $maxAttempts)),
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Claim le prochain job exécutable.
 *
 * @return array<string,mixed>|null
 */
function ged_jobs_claim_next(PDO $pdo, string $workerId, array $allowedTypes = [], int $leaseSeconds = 240): ?array
{
    $whereTypes = '';
    $params = [];
    if (!empty($allowedTypes)) {
        $in = [];
        foreach (array_values($allowedTypes) as $i => $t) {
            $k = 't' . $i;
            $in[] = ':' . $k;
            $params[$k] = (string)$t;
        }
        $whereTypes = ' AND job_type IN (' . implode(',', $in) . ')';
    }

    // 1) Prend le 1er job queued, prioritaire, exécutable, non locké.
    $sql = "
        SELECT *
        FROM ged_jobs
        WHERE status = 'queued'
          AND (run_at IS NULL OR run_at <= NOW())
          AND (locked_until IS NULL OR locked_until < NOW())
          {$whereTypes}
        ORDER BY priority ASC, created_at ASC
        LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $job = $st->fetch(PDO::FETCH_ASSOC);
    if (!$job) return null;

    // 2) Tentative claim atomique (optimistic)
    $upd = $pdo->prepare("
        UPDATE ged_jobs
        SET status='running',
            locked_by = :by,
            locked_until = DATE_ADD(NOW(), INTERVAL :lease SECOND),
            started_at = COALESCE(started_at, NOW()),
            attempts = attempts + 1,
            updated_at = NOW()
        WHERE id = :id
          AND status = 'queued'
    ");
    $upd->execute([
        'by'    => $workerId,
        'lease' => max(30, min(900, $leaseSeconds)),
        'id'    => (int)$job['id'],
    ]);
    if ($upd->rowCount() !== 1) return null;

    // Reload pour garantir cohérence
    $st2 = $pdo->prepare("SELECT * FROM ged_jobs WHERE id = ?");
    $st2->execute([(int)$job['id']]);
    $job2 = $st2->fetch(PDO::FETCH_ASSOC);
    return $job2 ?: null;
}

function ged_jobs_mark_done(PDO $pdo, int $jobId): void
{
    $pdo->prepare("
        UPDATE ged_jobs
        SET status='done',
            locked_until=NULL,
            locked_by=NULL,
            finished_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ")->execute([$jobId]);
}

function ged_jobs_mark_error(PDO $pdo, int $jobId, string $err): void
{
    $pdo->prepare("
        UPDATE ged_jobs
        SET status='error',
            last_error = ?,
            locked_until=NULL,
            locked_by=NULL,
            finished_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ")->execute([mb_substr($err, 0, 4000), $jobId]);
}

/**
 * Exécute un job GED (dispatcher).
 * @param array<string,mixed> $jobRow
 */
function ged_jobs_run(PDO $pdo, array $jobRow): void
{
    $type = (string)($jobRow['job_type'] ?? '');
    if ($type === 'ged_analyze_advanced') {
        $payload = json_decode((string)($jobRow['payload_json'] ?? ''), true);
        if (!is_array($payload)) $payload = [];
        $analysisId = isset($jobRow['analysis_id']) ? (int)$jobRow['analysis_id'] : 0;
        $forcedService = isset($payload['forced_service']) ? trim((string)$payload['forced_service']) : null;
        if ($forcedService === '') $forcedService = null;

        require_once __DIR__ . '/ged_extraction.php';

        // Charge fichier local
        $st = $pdo->prepare("SELECT storage_driver, storage_file_id FROM ged_analyses WHERE id = ?");
        $st->execute([$analysisId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || empty($r['storage_file_id']) || (($r['storage_driver'] ?? 'local') !== 'local')) {
            throw new RuntimeException('Analyse introuvable ou fichier non-local (quarantaine requise).');
        }
        $base = dirname(__DIR__, 3) . '/storage/ged';
        $abs  = $base . '/' . $r['storage_file_id'];
        if (!is_file($abs)) throw new RuntimeException('Fichier quarantaine introuvable.');

        // Run IA extraction
        $extr = gedExtractDocument($abs, $forcedService);
        $ai   = $extr['data'] ?? [];
        if (!is_array($ai)) $ai = [];
        $ai['_analysis_level'] = 'ai_advanced_v1';
        $ai['forced_service']  = $forcedService;

        $dateDoc = !empty($ai['date_document']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$ai['date_document'])
            ? (string)$ai['date_document'] : null;

        $upd = $pdo->prepare("
            UPDATE ged_analyses SET
                ocr_engine = :ocr_eng,
                ocr_text   = :ocr_text,
                ia_engine  = :ia_eng,
                suggested_module  = :module,
                suggested_level_2 = :n2,
                suggested_level_3 = :n3,
                detected_immeuble = :immeuble,
                detected_fournisseur = :fournisseur,
                detected_montant  = :montant,
                detected_date     = :date_doc,
                suggested_action  = :action,
                confidence_score  = :conf,
                ai_raw_response   = :ai_raw,
                date_document     = :date_doc2,
                status            = :status,
                updated_at        = NOW()
            WHERE id = :id
        ");
        $status = ($extr['ok'] ?? false) ? 'to_validate' : 'manual_review';
        $upd->execute([
            'ocr_eng'   => $extr['path_engine'] ?? 'unknown',
            'ocr_text'  => isset($extr['raw_text']) ? mb_substr((string)$extr['raw_text'], 0, 65535) : null,
            'ia_eng'    => $extr['model_used'] ?? 'unknown',
            'module'    => isset($ai['module'])      ? mb_substr((string)$ai['module'], 0, 50)       : null,
            'n2'        => isset($ai['niveau_2'])    ? mb_substr((string)$ai['niveau_2'], 0, 100)    : null,
            'n3'        => isset($ai['niveau_3'])    ? mb_substr((string)$ai['niveau_3'], 0, 100)    : null,
            'immeuble'  => isset($ai['immeuble'])    ? mb_substr((string)$ai['immeuble'], 0, 255)    : null,
            'fournisseur'=> isset($ai['fournisseur']) ? mb_substr((string)$ai['fournisseur'], 0, 255) : null,
            'montant'   => isset($ai['montant_ttc']) ? (float)$ai['montant_ttc']
                        : (isset($ai['montant_ht']) ? (float)$ai['montant_ht'] : null),
            'date_doc'  => $dateDoc,
            'action'    => isset($ai['action_proposee']) ? (string)$ai['action_proposee'] : null,
            'conf'      => isset($ai['confiance_globale']) ? (float)$ai['confiance_globale'] : null,
            'ai_raw'    => json_encode($ai, JSON_UNESCAPED_UNICODE),
            'date_doc2' => $dateDoc,
            'status'    => $status,
            'id'        => $analysisId,
        ]);

        return;
    }

    throw new RuntimeException("Job GED inconnu : {$type}");
}

