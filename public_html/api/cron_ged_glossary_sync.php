<?php
declare(strict_types=1);

/**
 * Cron quotidien — filet de sécurité du glossaire GED.
 * Fichier : public_html/api/cron_ged_glossary_sync.php
 *
 * Rôle : rattraper toute entité (société, agence, user, immeuble) créée
 *        sans hook glossaire (script de migration, import, INSERT direct).
 *
 * Usage Hostinger (hPanel → Tâches Cron), tous les jours à 03:00 :
 *   curl -s "https://maboximmo.fr/api/cron_ged_glossary_sync.php?token=XXX" > /dev/null
 *
 * Sécurité : même token que les autres crons GED (CRON_GED_TOKEN).
 */

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
error_reporting(0);
set_time_limit(600);
ignore_user_abort(true);

require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cron_glossary_respond(bool $ok, string $message = '', array $extra = []): void
{
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

$cronConfig = __DIR__ . '/../config/cron.php';
if (!is_file($cronConfig)) {
    http_response_code(500);
    cron_glossary_respond(false, 'config/cron.php manquant');
}
require_once $cronConfig;

if (!defined('CRON_GED_TOKEN') || !is_string(constant('CRON_GED_TOKEN')) || constant('CRON_GED_TOKEN') === '') {
    http_response_code(500);
    cron_glossary_respond(false, 'CRON_GED_TOKEN non défini dans config/cron.php');
}

$token = (string)($_GET['token'] ?? '');
if (!hash_equals((string)constant('CRON_GED_TOKEN'), $token)) {
    http_response_code(403);
    cron_glossary_respond(false, 'Token invalide');
}

require_once __DIR__ . '/../inc/ged_glossary.php';

$pdo = $GLOBALS['pdo'];

$stats = [
    'societe'  => ['scanned' => 0, 'created' => 0, 'updated_label' => 0, 'unchanged' => 0, 'errors' => 0],
    'agence'   => ['scanned' => 0, 'created' => 0, 'updated_label' => 0, 'unchanged' => 0, 'errors' => 0],
    'user'     => ['scanned' => 0, 'created' => 0, 'updated_label' => 0, 'unchanged' => 0, 'errors' => 0],
    'immeuble' => ['scanned' => 0, 'created' => 0, 'updated_label' => 0, 'unchanged' => 0, 'errors' => 0],
];

$applyResult = function (string $category, array $r) use (&$stats): void {
    $status = (string)($r['status'] ?? 'error');
    $stats[$category]['scanned']++;
    if (isset($stats[$category][$status])) {
        $stats[$category][$status]++;
    } else {
        $stats[$category]['errors']++;
    }
};

try {
    // Sociétés
    $rows = $pdo->query("SELECT id, nom FROM societes ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $label = trim((string)($r['nom'] ?? ''));
        if ($label === '') continue;
        $applyResult('societe', ged_glossary_sync_entity('societe', (int)$r['id'], $label, 'societes', [], $pdo));
    }

    // Agences
    $rows = $pdo->query("SELECT id, nom_agence, code_agence FROM agences ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $label = trim((string)($r['nom_agence'] ?? ''));
        if ($label === '') continue;
        $opts = [];
        if (!empty($r['code_agence'])) $opts['code_existant'] = (string)$r['code_agence'];
        $applyResult('agence', ged_glossary_sync_entity('agence', (int)$r['id'], $label, 'agences', $opts, $pdo));
    }

    // Users actifs uniquement (les inactifs n'ont pas besoin de code de fichier)
    $rows = $pdo->query("SELECT id, nom, prenom FROM users WHERE actif = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $label = trim(((string)($r['prenom'] ?? '')) . ' ' . (string)($r['nom'] ?? ''));
        if ($label === '') $label = 'User#' . (int)$r['id'];
        $applyResult('user', ged_glossary_sync_entity('user', (int)$r['id'], $label, 'users', ['user_id' => (int)$r['id']], $pdo));
    }

    // Immeubles
    $rows = $pdo->query("SELECT id, nom_immeuble, reference_immeuble FROM immeubles ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $label = trim((string)($r['nom_immeuble'] ?? ''));
        if ($label === '') continue;
        $opts = [];
        if (!empty($r['reference_immeuble'])) $opts['reference'] = (string)$r['reference_immeuble'];
        $applyResult('immeuble', ged_glossary_sync_entity('immeuble', (int)$r['id'], $label, 'immeubles', $opts, $pdo));
    }
} catch (Throwable $e) {
    http_response_code(500);
    cron_glossary_respond(false, 'Erreur : ' . $e->getMessage(), ['stats' => $stats]);
}

$totalCreated = 0;
$totalUpdated = 0;
foreach ($stats as $s) {
    $totalCreated += $s['created'];
    $totalUpdated += $s['updated_label'];
}

cron_glossary_respond(true, "OK — {$totalCreated} créé(s), {$totalUpdated} libellé(s) maj", [
    'stats' => $stats,
    'date'  => date('c'),
]);
