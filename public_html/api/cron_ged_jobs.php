<?php
declare(strict_types=1);

/**
 * Cron worker GED — exécute les jobs asynchrones stockés en BDD.
 * Fichier : public_html/api/cron_ged_jobs.php
 *
 * Usage Hostinger (hPanel → Tâches Cron) :
 *   Toutes les 2 minutes : curl -s "https://maboximmo.fr/api/cron_ged_jobs.php?token=XXX&limit=3" > /dev/null
 *
 * Sécurité :
 * - token secret dans config/cron.php (gitignored).
 */

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
error_reporting(0);

require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cron_ged_respond(bool $ok, string $message = '', array $extra = []): void
{
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

$cronConfig = __DIR__ . '/../config/cron.php';
if (!is_file($cronConfig)) {
    http_response_code(500);
    cron_ged_respond(false, 'config/cron.php manquant (copie depuis cron.template.php)');
}
require_once $cronConfig;

if (!defined('CRON_GED_TOKEN') || !is_string(constant('CRON_GED_TOKEN')) || constant('CRON_GED_TOKEN') === '') {
    http_response_code(500);
    cron_ged_respond(false, 'CRON_GED_TOKEN non défini dans config/cron.php');
}

$token = (string)($_GET['token'] ?? '');
if (!hash_equals((string)constant('CRON_GED_TOKEN'), $token)) {
    http_response_code(403);
    cron_ged_respond(false, 'Token invalide');
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 3;
$limit = max(1, min(10, $limit));

require_once __DIR__ . '/../modules/ged/ged_jobs.php';

$pdo = $GLOBALS['pdo'];
$workerId = 'cron:' . ($_SERVER['SERVER_NAME'] ?? 'server') . ':' . gethostname();

$done = 0;
$errors = 0;
$errorItems = [];

for ($i = 0; $i < $limit; $i++) {
    $job = ged_jobs_claim_next($pdo, $workerId, ['ged_analyze_advanced'], 240);
    if (!$job) break;

    $jobId = (int)($job['id'] ?? 0);
    try {
        ged_jobs_run($pdo, $job);
        ged_jobs_mark_done($pdo, $jobId);
        $done++;
    } catch (Throwable $e) {
        $errors++;
        $msg = $e->getMessage();
        ged_jobs_mark_error($pdo, $jobId, $msg);
        $errorItems[] = ['job_id' => $jobId, 'error' => mb_substr($msg, 0, 500)];
    }
}

cron_ged_respond(true, 'OK', [
    'processed' => $done + $errors,
    'done'      => $done,
    'errors'    => $errors,
    'error_items' => $errorItems,
]);
