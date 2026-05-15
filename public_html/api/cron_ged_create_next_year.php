<?php
declare(strict_types=1);

/**
 * Cron annuel GED — crée le code EXERCICE_YYYY+1 dans ged_level_codes.
 * Fichier : public_html/api/cron_ged_create_next_year.php
 *
 * Cible : compléter automatiquement les codes EXERCICE_YYYY sous 06_COMPTABILITE
 *         pour que la GED ait toujours l'exercice de l'année suivante prêt.
 *
 * Usage Hostinger (hPanel → Tâches Cron) :
 *   1er janvier à 00:30 :
 *     curl -s "https://maboximmo.fr/api/cron_ged_create_next_year.php?token=XXX" > /dev/null
 *   (ou plus souvent — idempotent, INSERT IGNORE)
 *
 * Sécurité : même token que cron_ged_jobs.php (config/cron.php — CRON_GED_TOKEN).
 */

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
error_reporting(0);

require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cron_year_respond(bool $ok, string $message = '', array $extra = []): void
{
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

$cronConfig = __DIR__ . '/../config/cron.php';
if (!is_file($cronConfig)) {
    http_response_code(500);
    cron_year_respond(false, 'config/cron.php manquant');
}
require_once $cronConfig;

if (!defined('CRON_GED_TOKEN') || !is_string(constant('CRON_GED_TOKEN')) || constant('CRON_GED_TOKEN') === '') {
    http_response_code(500);
    cron_year_respond(false, 'CRON_GED_TOKEN non défini dans config/cron.php');
}

$token = (string)($_GET['token'] ?? '');
if (!hash_equals((string)constant('CRON_GED_TOKEN'), $token)) {
    http_response_code(403);
    cron_year_respond(false, 'Token invalide');
}

$pdo = $GLOBALS['pdo'];
$nextYear = (int)date('Y') + 1;
$code     = 'EXERCICE_' . $nextYear;
$label    = 'Exercice ' . $nextYear;
$position = $nextYear - 2022;

$parents = [
    ['n2' => 'COMPTABILITE_GENERALE', 'n4_set' => 'general'],
    ['n2' => 'FOURNISSEURS',          'n4_set' => 'fournisseurs'],
    ['n2' => 'CLIENTS',               'n4_set' => 'clients'],
    ['n2' => 'ARCHIVES',              'n4_set' => 'archives'],
];

$n4Templates = [
    'general' => [
        ['BILAN', 'Bilan', 1], ['GRAND_LIVRE', 'Grand livre', 2], ['BALANCE', 'Balance', 3],
        ['JOURNAUX', 'Journaux', 4], ['FEC', 'FEC', 5],
        ['ECRITURES_MANUELLES', 'Écritures manuelles', 6], ['CLOTURE', 'Clôture', 7],
    ],
    'fournisseurs' => [
        ['FACTURES_A_PAYER', 'Factures à payer', 1], ['FACTURES_PAYEES', 'Factures payées', 2],
        ['RAPPROCHEMENTS_FOURNISSEURS', 'Rapprochements fournisseurs', 3],
        ['AVOIRS', 'Avoirs', 4], ['LITIGES_PAIEMENT', 'Litiges paiement', 5],
        ['ECHEANCIER', 'Échéancier', 6],
    ],
    'clients' => [
        ['FACTURES_EMISES', 'Factures émises', 1], ['REGLEMENTS_RECUS', 'Règlements reçus', 2],
        ['AVOIRS', 'Avoirs', 3], ['RELANCES', 'Relances', 4],
        ['IMPAYES', 'Impayés', 5], ['ECHEANCIER', 'Échéancier', 6],
    ],
    'archives' => [
        ['BILANS', 'Bilans', 1], ['JOURNAUX', 'Journaux', 2], ['BALANCES', 'Balances', 3],
        ['DECLARATIONS', 'Déclarations', 4], ['ECRITURES', 'Écritures', 5],
    ],
];

$createdN3 = 0;
$createdN4 = 0;

try {
    $pdo->beginTransaction();

    $stN3 = $pdo->prepare("
        INSERT IGNORE INTO `ged_level_codes`
          (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`)
        VALUES (NULL, 3, '06_COMPTABILITE', :n2, :code, :label, :position)
    ");
    $stN4 = $pdo->prepare("
        INSERT IGNORE INTO `ged_level_codes`
          (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`)
        VALUES (NULL, 4, '06_COMPTABILITE', :n2, :n3, :code, :label, :position)
    ");

    foreach ($parents as $p) {
        $n2 = $p['n2'];
        $stN3->execute([
            ':n2' => $n2, ':code' => $code, ':label' => $label, ':position' => $position,
        ]);
        $createdN3 += $stN3->rowCount();

        foreach ($n4Templates[$p['n4_set']] as [$n4Code, $n4Label, $n4Pos]) {
            $stN4->execute([
                ':n2' => $n2, ':n3' => $code,
                ':code' => $n4Code, ':label' => $n4Label, ':position' => $n4Pos,
            ]);
            $createdN4 += $stN4->rowCount();
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    cron_year_respond(false, 'Erreur: ' . $e->getMessage());
}

cron_year_respond(true, "OK — exercice {$nextYear} prêt", [
    'year'        => $nextYear,
    'code'        => $code,
    'created_n3'  => $createdN3,
    'created_n4'  => $createdN4,
    'idempotent'  => $createdN3 === 0 && $createdN4 === 0 ? 'déjà existant' : null,
]);
