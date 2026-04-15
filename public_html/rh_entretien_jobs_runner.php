<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/rh_entretien_v3.php';
require_once __DIR__ . '/inc/rh_entretien_v4.php';
require_once __DIR__ . '/inc/rh_entretien_v5.php';
require_once __DIR__ . '/inc/rh_entretien_v6.php';

/**
 * Batch jobs runner for rh_entretien.
 * - Safe for cron
 * - Can be called from CLI or browser
 */
function rh_entretien_jobs_runner(PDO $pdo, array $options = []): array
{
    $defaults = [
        'limit' => 20,
        'entretien_id' => null,
        'job_id' => null,
        'retry_errors' => false,
        'dry_run' => false,
        'log_path' => null,
    ];
    $opts = array_merge($defaults, $options);
    $limit = max(1, min(200, (int)$opts['limit']));

    $root = dirname(__DIR__);
    $logDir = $root . '/_work/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $logPath = $opts['log_path'] ?: ($logDir . '/rh_entretien_jobs_runner.log');

    $summary = [
        'processed' => 0,
        'errors' => 0,
        'retried' => 0,
        'limit' => $limit,
        'job_ids' => [],
    ];

    if (!rh_entretien_v5_table_exists($pdo, 'rh_entretien_jobs_calcul')) {
        rh_entretien_jobs_runner_log($logPath, 'Table rh_entretien_jobs_calcul introuvable.');
        return $summary;
    }

    if (!empty($opts['retry_errors'])) {
        $summary['retried'] = rh_entretien_jobs_runner_retry_errors($pdo, $opts, $logPath);
    }

    $where = "statut = 'a_faire'";
    if (!empty($opts['entretien_id'])) {
        $where .= ' AND entretien_id = ' . (int)$opts['entretien_id'];
    }
    if (!empty($opts['job_id'])) {
        $where .= ' AND id = ' . (int)$opts['job_id'];
    }

    $order = "'recalcul_global','recalcul_scores','recalcul_incoherences','recalcul_alertes','recalcul_profils'," .
             "'recalcul_recommandations','recalcul_decisions','recalcul_trajectoire','recalcul_diagnostic','recalcul_dashboard'";

    $sql = "SELECT * FROM rh_entretien_jobs_calcul WHERE $where ORDER BY FIELD(job_type, $order), id ASC LIMIT $limit";

    try {
        $jobs = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        rh_entretien_jobs_runner_log($logPath, 'Erreur SQL selection jobs: ' . $e->getMessage());
        return $summary;
    }

    if (empty($jobs)) {
        return $summary;
    }

    $jobIds = array_map('intval', array_column($jobs, 'id'));
    $summary['job_ids'] = $jobIds;

    if ($opts['dry_run']) {
        return $summary;
    }

    // Mark selected jobs as en_cours
    $placeholders = implode(',', array_fill(0, count($jobIds), '?'));
    try {
        $stmt = $pdo->prepare("UPDATE rh_entretien_jobs_calcul
            SET statut = 'en_cours', started_at = COALESCE(started_at, NOW()), updated_at = NOW()
            WHERE id IN ($placeholders) AND statut = 'a_faire'
        ");
        $stmt->execute($jobIds);
    } catch (PDOException $e) {
        rh_entretien_jobs_runner_log($logPath, 'Erreur SQL passage en_cours: ' . $e->getMessage());
        return $summary;
    }

    foreach ($jobs as $job) {
        $jobId = (int)$job['id'];
        $entretienId = (int)$job['entretien_id'];
        $jobType = (string)$job['job_type'];

        $ok = true;
        $errMsg = null;

        try {
            if ($jobType === 'recalcul_global') {
                $result = ['scores' => [], 'new_alerts' => []];
                rh_entretien_v5_recalcul_global($pdo, $entretienId, $result);

                // Global job clears any remaining queue for the entretien
                $stmt = $pdo->prepare("UPDATE rh_entretien_jobs_calcul
                    SET statut = 'termine', finished_at = NOW(), updated_at = NOW()
                    WHERE entretien_id = ? AND statut IN ('a_faire','en_cours')
                ");
                $stmt->execute([$entretienId]);

                $summary['processed']++;
                continue;
            }

            switch ($jobType) {
                case 'recalcul_scores':
                    rh_entretien_v5_recalcul_scores($pdo, $entretienId);
                    break;
                case 'recalcul_incoherences':
                    if (function_exists('rh_entretien_v3_compute')) {
                        rh_entretien_v3_compute($pdo, $entretienId, ['preserve_scores' => true]);
                    }
                    break;
                case 'recalcul_alertes':
                    rh_entretien_v5_recalcul_alertes($pdo, $entretienId, []);
                    break;
                case 'recalcul_profils':
                case 'recalcul_recommandations':
                    if (function_exists('rh_entretien_v3_compute')) {
                        rh_entretien_v3_compute($pdo, $entretienId, ['preserve_scores' => true]);
                    }
                    break;
                case 'recalcul_decisions':
                    if (function_exists('rh_entretien_v4_compute')) {
                        rh_entretien_v4_compute($pdo, $entretienId);
                    }
                    break;
                case 'recalcul_trajectoire':
                    rh_entretien_v5_recalcul_comparaison($pdo, $entretienId);
                    if (function_exists('rh_entretien_v4_compute')) {
                        rh_entretien_v4_compute($pdo, $entretienId);
                    }
                    break;
                case 'recalcul_dashboard':
                    rh_entretien_v5_recalcul_dashboard_cache($pdo, $entretienId);
                    if (function_exists('rh_entretien_v6_compute')) {
                        rh_entretien_v6_compute($pdo, $entretienId);
                    }
                    break;
                default:
                    break;
            }
        } catch (Throwable $e) {
            $ok = false;
            $errMsg = 'Job ' . $jobType . ' (id=' . $jobId . ', entretien=' . $entretienId . ') erreur: ' . $e->getMessage();
            rh_entretien_jobs_runner_log($logPath, $errMsg);
        }

        if ($ok) {
            rh_entretien_v5_mark_job($pdo, $jobId, 'termine');
            $summary['processed']++;
        } else {
            rh_entretien_v5_mark_job($pdo, $jobId, 'erreur', $errMsg);
            $summary['errors']++;
        }
    }

    return $summary;
}

function rh_entretien_jobs_runner_retry_errors(PDO $pdo, array $opts, string $logPath): int
{
    $where = "statut = 'erreur'";
    if (!empty($opts['entretien_id'])) {
        $where .= ' AND entretien_id = ' . (int)$opts['entretien_id'];
    }
    if (!empty($opts['job_id'])) {
        $where .= ' AND id = ' . (int)$opts['job_id'];
    }

    try {
        $stmt = $pdo->prepare("UPDATE rh_entretien_jobs_calcul
            SET statut = 'a_faire', started_at = NULL, finished_at = NULL, updated_at = NOW()
            WHERE $where
        ");
        $stmt->execute();
        $count = $stmt->rowCount();
        if ($count > 0) {
            rh_entretien_jobs_runner_log($logPath, 'Retry jobs en erreur: ' . $count);
        }
        return $count;
    } catch (PDOException $e) {
        rh_entretien_jobs_runner_log($logPath, 'Erreur SQL retry: ' . $e->getMessage());
        return 0;
    }
}

function rh_entretien_jobs_runner_log(string $logPath, string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($logPath, $line, FILE_APPEND);
}

function rh_entretien_jobs_runner_acquire_lock(string $lockPath)
{
    $fp = @fopen($lockPath, 'c+');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false;
    }
    ftruncate($fp, 0);
    fwrite($fp, (string)getmypid());
    fflush($fp);
    return $fp;
}

function rh_entretien_jobs_runner_release_lock($fp): void
{
    if (is_resource($fp)) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// ------------------------------------------------------------
// Entrypoint (CLI or browser)
// ------------------------------------------------------------
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        http_response_code(500);
        echo "Connexion PDO indisponible.\n";
        exit(1);
    }

    $isCli = (PHP_SAPI === 'cli');

    $args = [
        'limit' => 20,
        'retry_errors' => false,
        'entretien_id' => null,
        'job_id' => null,
        'dry_run' => false,
    ];

    if ($isCli) {
        $opts = getopt('', ['limit::', 'retry::', 'entretien_id::', 'job_id::', 'dry::']);
        if (isset($opts['limit'])) $args['limit'] = (int)$opts['limit'];
        if (isset($opts['retry'])) $args['retry_errors'] = (bool)(int)$opts['retry'];
        if (isset($opts['entretien_id'])) $args['entretien_id'] = (int)$opts['entretien_id'];
        if (isset($opts['job_id'])) $args['job_id'] = (int)$opts['job_id'];
        if (isset($opts['dry'])) $args['dry_run'] = (bool)(int)$opts['dry'];
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        if (isset($_GET['limit'])) $args['limit'] = (int)$_GET['limit'];
        if (isset($_GET['retry'])) $args['retry_errors'] = (bool)(int)$_GET['retry'];
        if (isset($_GET['entretien_id'])) $args['entretien_id'] = (int)$_GET['entretien_id'];
        if (isset($_GET['job_id'])) $args['job_id'] = (int)$_GET['job_id'];
        if (isset($_GET['dry'])) $args['dry_run'] = (bool)(int)$_GET['dry'];

        if (!isset($_GET['run'])) {
            echo "Runner RH Entretien V5/V6\n";
            echo "Utilisation: ?run=1&limit=20&retry=1&entretien_id=123&job_id=456&dry=1\n";
            exit(0);
        }
    }

    $root = dirname(__DIR__);
    $lockPath = $root . '/_work/rh_entretien_jobs_runner.lock';
    $lock = rh_entretien_jobs_runner_acquire_lock($lockPath);
    if ($lock === false) {
        echo "Runner deja en cours.\n";
        exit(0);
    }

    $summary = rh_entretien_jobs_runner($pdo, $args);
    rh_entretien_jobs_runner_release_lock($lock);

    echo "OK | processed={$summary['processed']} | errors={$summary['errors']} | retried={$summary['retried']} | limit={$summary['limit']}\n";
    if (!empty($summary['job_ids'])) {
        echo "jobs=" . implode(',', $summary['job_ids']) . "\n";
    }
}