<?php
// api/transaction_dossier_avant_contrat_save.php — Crée/maj l'avant-contrat du dossier + lots.
// POST : id_dossier, lots[] (id_bien sélectionnés), + champs dossier_avant_contrat.
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/dossier_vente.php';
require_once __DIR__ . '/../inc/avant_contrat.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$idDossier = (int)(post('id_dossier') ?? 0);
$dossier = dv_get($pdo, $idDossier);
if (!$dossier) { echo json_encode(['ok'=>false,'error'=>'dossier introuvable']); exit; }

// Scope société (super admin / manager bypass).
$roleId    = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isManager = ($roleId === 1 || $roleId === 2);
$idSoc     = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
if (!$isManager && $idSoc !== null && (int)$dossier['id_societe'] !== $idSoc) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'hors scope']); exit;
}

try {
    $idAc = dac_ensure($pdo, $idDossier, (int)current_user_id() ?: null);

    // Champs (whitelist via dac_columns)
    $vals = [];
    foreach (array_keys(dac_columns()) as $c) {
        if (array_key_exists($c, $_POST)) $vals[$c] = $_POST[$c];
    }
    // SRU : calcule la fin de rétractation (+10 j) si notif fournie et fin absente
    if (!empty($vals['sru_date_notification']) && empty($vals['sru_date_fin_retractation'])) {
        try { $d = new DateTime($vals['sru_date_notification']); $d->modify('+10 days');
              $vals['sru_date_fin_retractation'] = $d->format('Y-m-d'); } catch (Throwable $e) {}
    }
    dac_save($pdo, $idAc, $idDossier, $vals);

    // Lots sélectionnés
    if (isset($_POST['lots'])) {
        $lots = is_array($_POST['lots']) ? $_POST['lots'] : [];
        dac_set_lots($pdo, $idAc, $lots);
    }

    echo json_encode(['ok'=>true, 'id_avant_contrat'=>$idAc], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[avant_contrat_save] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
