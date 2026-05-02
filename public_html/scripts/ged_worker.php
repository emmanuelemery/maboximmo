<?php
declare(strict_types=1);

/**
 * Ma GED Box — Worker CLI cron-safe
 * ==================================
 *
 * Consume les jobs queued/error de ged_jobs, les exécute, marque done/error.
 *
 * UTILISATION (Hostinger cron — toutes les minutes, max 5 jobs/run) :
 *   * * * * * php /home/u630423897/domains/maboximmo.fr/public_html/scripts/ged_worker.php --max=5
 *
 * Test à la main :
 *   php ged_worker.php --max=3 --verbose
 *
 * Options :
 *   --max=N      Nb max de jobs traités par exécution (défaut 5)
 *   --type=X     Limiter à un type de job (ex: --type=analyze_advanced)
 *   --verbose    Affiche le détail par job
 *
 * SÉCURITÉ : CLI uniquement. Refuse l'accès HTTP.
 *
 * Dispatch des types de jobs :
 *   - analyze_advanced  → handle_analyze_advanced($payload)
 *   - (autres types)    → ajoute un handler ici quand pertinent
 *
 * AJOUT uniquement.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Accès interdit : worker CLI uniquement.\n");
}

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../modules/ged/ged_jobs.php';

$opts    = getopt('', ['max::', 'type::', 'verbose']);
$maxJobs = isset($opts['max']) && ctype_digit((string)$opts['max']) ? max(1, min(20, (int)$opts['max'])) : 5;
$onlyType = isset($opts['type']) && is_string($opts['type']) ? trim((string)$opts['type']) : null;
$verbose = array_key_exists('verbose', $opts);

$workerId = (string)getmypid() . '@' . gethostname();
$startTs = microtime(true);

if ($verbose) echo "[ged_worker] start worker={$workerId} max={$maxJobs}" . ($onlyType ? " type={$onlyType}" : '') . "\n";

try {
    $jobs = gedJobsClaim($workerId, $maxJobs);
} catch (Throwable $e) {
    fwrite(STDERR, "[ged_worker] ERREUR claim : " . $e->getMessage() . "\n");
    exit(1);
}

if (!$jobs) {
    if ($verbose) echo "[ged_worker] aucun job a traiter.\n";
    exit(0);
}

$ok = 0; $ko = 0;
foreach ($jobs as $job) {
    $jobId = (int)$job['id'];
    $type  = (string)$job['type'];

    if ($onlyType !== null && $type !== $onlyType) {
        // Pas notre type : on relâche le verrou pour qu'un autre worker le prenne
        gedJobsMarkError($jobId, "skipped (filtered by --type={$onlyType})", false);
        continue;
    }

    $payload = gedJobsPayload($job);
    if ($verbose) echo "[ged_worker] job#{$jobId} type={$type} attempts={$job['attempts']}/{$job['max_attempts']}\n";

    try {
        $result = ged_worker_dispatch($type, $payload, $job);
        gedJobsMarkDone($jobId, $result);
        $ok++;
        if ($verbose) echo "  ✓ done\n";
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $isFinal = (int)$job['attempts'] >= (int)$job['max_attempts']; // attempts a déjà été incrémenté par claim
        gedJobsMarkError($jobId, $msg, $isFinal);
        $ko++;
        if ($verbose) echo "  ✗ error : {$msg}" . ($isFinal ? ' (final)' : ' (retryable)') . "\n";
        error_log("[ged_worker] job#{$jobId} type={$type} KO : {$msg}");
    }
}

$elapsed = round(microtime(true) - $startTs, 2);
if ($verbose || ($ok + $ko) > 0) {
    echo "[ged_worker] done : {$ok} OK, {$ko} KO, en {$elapsed}s\n";
}
exit($ko > 0 ? 1 : 0);

// ────────────────────────────────────────────────────────────────────────────
// Dispatcher : ajoute un case par type de job
// ────────────────────────────────────────────────────────────────────────────

/**
 * @param string $type     Type du job
 * @param array  $payload  Payload décodé
 * @param array  $job      Ligne brute ged_jobs (pour id_societe, id_user, etc.)
 * @return array|null      Résultat optionnel à stocker dans result_json
 * @throws Throwable
 */
function ged_worker_dispatch(string $type, array $payload, array $job): ?array
{
    switch ($type) {
        case 'analyze_advanced':
            return ged_worker_handle_analyze_advanced($payload, $job);

        // Ajouter d'autres handlers ici (ocr, classify, link, rename, import_zip, ...)

        default:
            throw new RuntimeException("Type de job inconnu : {$type}");
    }
}

/**
 * Handler : analyse IA avancée d'une analyse GED existante.
 *
 * Payload attendu :
 *   - id_analysis     (int, requis)
 *   - forced_service  (string|null, optionnel)
 *
 * NOTE : appelle gedExtractDocument() à partir du fichier local référencé
 * dans ged_analyses.url_local / storage_path. Si le pipeline d'extraction
 * existant n'est pas encore branché, ce handler doit être adapté quand
 * le bloc "advanced" de ged_inbox_action.php aura été basculé en enqueue.
 */
function ged_worker_handle_analyze_advanced(array $payload, array $job): ?array
{
    $idAnalysis = (int)($payload['id_analysis'] ?? 0);
    if ($idAnalysis <= 0) {
        throw new RuntimeException('payload.id_analysis manquant');
    }

    $pdo = $GLOBALS['pdo'];
    $forcedService = isset($payload['forced_service']) ? (string)$payload['forced_service'] : null;

    // Charger l'analyse + chemin local
    $st = $pdo->prepare("SELECT id, COALESCE(storage_path, '') AS storage_path,
                                COALESCE(nom_original, '') AS nom_original
                         FROM ged_analyses WHERE id = ?");
    $st->execute([$idAnalysis]);
    $analysis = $st->fetch(PDO::FETCH_ASSOC);
    if (!$analysis) {
        throw new RuntimeException("ged_analyses #{$idAnalysis} introuvable");
    }

    $absPath = '';
    $cand = (string)$analysis['storage_path'];
    if ($cand !== '') {
        $absPath = is_file($cand) ? $cand : (dirname(__DIR__) . '/' . ltrim($cand, '/'));
    }
    if ($absPath === '' || !is_file($absPath)) {
        throw new RuntimeException("Fichier local introuvable pour analyse #{$idAnalysis}");
    }

    // Appel pipeline existant (gedExtractDocument). Si non chargé, require.
    if (!function_exists('gedExtractDocument')) {
        @require_once __DIR__ . '/../modules/ged/ged_extraction.php';
    }
    if (!function_exists('gedExtractDocument')) {
        throw new RuntimeException('gedExtractDocument() indisponible');
    }

    $result = gedExtractDocument($absPath, $forcedService);
    if (empty($result['ok'])) {
        throw new RuntimeException('extraction KO : ' . (string)($result['errors'][0] ?? 'inconnu'));
    }

    // Persister le résultat dans ged_analyses (les colonnes existent déjà)
    try {
        $pdo->prepare("
            UPDATE ged_analyses
            SET ai_raw_response = ?,
                ia_engine = ?,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([
            json_encode($result['data'] ?? [], JSON_UNESCAPED_UNICODE),
            (string)($result['model_used'] ?? ($result['path_engine'] ?? '')),
            $idAnalysis,
        ]);
    } catch (Throwable $e) {
        // Si une colonne diffère, on remonte mais le job ne re-extraira pas
        throw new RuntimeException('UPDATE ged_analyses : ' . $e->getMessage());
    }

    return [
        'id_analysis' => $idAnalysis,
        'engine'      => $result['model_used'] ?? null,
        'path'        => $absPath,
    ];
}
