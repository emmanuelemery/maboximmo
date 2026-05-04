<?php
declare(strict_types=1);

/**
 * Endpoint de re-comparaison d'un import salaires deja parse.
 *
 * Cas d'usage : l'utilisateur a modifie des valeurs en BDD (Salaire de base,
 * commissions, primes...) APRES l'upload du PDF -> la comparaison stockee
 * dans rh_salaires_comparaisons.compare_json est stale. Ce endpoint relance
 * le compare a partir du parsed_json (deja extrait du PDF, pas besoin de
 * re-parser le fichier) + des valeurs MBI a jour.
 *
 * URL : POST /rh_compare_recompute.php
 *   - id={id_comparaison} : recompute juste cette ligne
 *   - OU societe + mois + annee + type [+ agence] : recompute TOUTES les
 *     comparaisons matchantes (utile en mode multi-agences sans agence
 *     selectionnee)
 *
 * Securite : POST + CSRF + auth + rh_wf_can_access par agence.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/rh_salaire_workflow.php';
require_once __DIR__ . '/inc/rh_compare_lib.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
verify_csrf();

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();

// Mode 1 : recompute par id_comparaison
$compareId = (int)($_POST['id'] ?? 0);
$where = '';
$params = [];
if ($compareId > 0) {
    $where = 'id = ?';
    $params = [$compareId];
} else {
    $societeId = (int)($_POST['societe'] ?? 0);
    $mois      = (int)($_POST['mois'] ?? 0);
    $annee     = (int)($_POST['annee'] ?? 0);
    $type      = (string)($_POST['type'] ?? 'projet');
    $agence    = (int)($_POST['agence'] ?? 0);
    if ($societeId <= 0 || $mois <= 0 || $annee <= 0) {
        http_response_code(400);
        exit('Parametres manquants.');
    }
    $where = 'id_societe = ? AND mois = ? AND annee = ? AND type = ?';
    $params = [$societeId, $mois, $annee, $type];
    if ($agence > 0) {
        $where .= ' AND id_agence = ?';
        $params[] = $agence;
    }
}

$st = $pdo->prepare("SELECT * FROM rh_salaires_comparaisons WHERE $where");
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
if (empty($rows)) {
    $_SESSION['message_err'] = 'Aucune comparaison a recalculer.';
    header('Location: ' . ($_POST['redirect_to'] ?? 'rh_salaires.php'));
    exit;
}

$updated = 0;
$update = $pdo->prepare("UPDATE rh_salaires_comparaisons
    SET compare_json = ?, compare_ok = ?, total_pdf_brut = ?, total_expected_brut = ?, total_pdf_net = ?
    WHERE id = ?");

foreach ($rows as $row) {
    if (!rh_wf_can_access($pdo, $userId, (int)($row['id_agence'] ?? 0))) continue;

    $parsed = json_decode((string)$row['parsed_json'], true) ?: [];
    $employees = $parsed['employees'] ?? [];
    if (empty($employees)) continue; // pas de bulletins -> rien a recomparer

    $moisRef = sprintf('%04d-%02d-01', (int)$row['annee'], (int)$row['mois']);
    $expected = rh_load_expected_map($pdo, (int)$row['id_societe'], $moisRef, (int)($row['id_agence'] ?? 0));
    $compare  = rh_compare_bulletins_expected($expected, $employees);
    $conges   = rh_compute_conges_summary($pdo, $expected, (int)$row['mois'], (int)$row['annee'], $employees);
    $compare['conges'] = $conges;

    $totalNet = 0.0;
    foreach ($employees as $emp) {
        if (isset($emp['net']) && $emp['net'] !== null) $totalNet += (float)$emp['net'];
    }

    $update->execute([
        json_encode($compare, JSON_UNESCAPED_UNICODE),
        $compare['ok'] ? 1 : 0,
        $compare['total_pdf'],
        $compare['total_expected'],
        $totalNet,
        (int)$row['id'],
    ]);
    $updated++;
}

$_SESSION['message_ok'] = $updated > 0
    ? "Comparaison(s) recalculee(s) : $updated agence(s) mise(s) a jour."
    : 'Aucune ligne mise a jour.';
header('Location: ' . ($_POST['redirect_to'] ?? 'rh_salaires.php'));
exit;
