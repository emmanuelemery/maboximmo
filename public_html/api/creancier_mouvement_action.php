<?php
/**
 * api/creancier_mouvement_action.php — Ajoute / supprime un mouvement financier d'un dossier.
 *
 * Registre unifié : versement au créancier, honoraires avocat, frais huissier, frais procédure.
 * Ajout = tout intervenant ayant accès au dossier. Suppression = managers.
 *
 * POST : action=add|delete, id_dossier, csrf_token ('creancier_mouvement')
 *   add    : type, montant, [date_mouvement], [id_tiers_beneficiaire], [mode], [reference], [ged_document_id], [note]
 *   delete : mouvement_id
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/creancier_urgence_data.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
verify_csrf_any('creancier_mouvement');

$pdo     = $GLOBALS['pdo'];
$userId  = (int)current_user_id();
$roleId  = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isSuper = function_exists('is_super_admin') && is_super_admin();
$isMgr   = $isSuper || in_array($roleId, [1, 2, 3, 7], true);

$action    = (string)($_POST['action'] ?? '');
$idDossier = (int)($_POST['id_dossier'] ?? 0);

if ($idDossier <= 0 || !creancier_user_can_access_dossier($pdo, $idDossier, $userId)) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Accès dossier refusé']); exit;
}

$TYPES = ['versement_creancier','honoraire_avocat','frais_huissier','frais_procedure','autre'];
$MODES = ['virement','cheque','prelevement','especes','autre'];

if ($action === 'add') {
    $type    = (string)($_POST['type'] ?? '');
    if (!in_array($type, $TYPES, true)) { echo json_encode(['ok'=>false,'error'=>'Type invalide']); exit; }
    $montant = round((float)str_replace([' ', ','], ['', '.'], (string)($_POST['montant'] ?? '0')), 2);
    if ($montant <= 0) { echo json_encode(['ok'=>false,'error'=>'Montant requis (> 0)']); exit; }
    $date    = trim((string)($_POST['date_mouvement'] ?? '')) ?: null;
    if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = null;
    $benef   = (int)($_POST['id_tiers_beneficiaire'] ?? 0) ?: null;
    $mode    = (string)($_POST['mode'] ?? '');
    $mode    = in_array($mode, $MODES, true) ? $mode : null;
    $ref     = trim((string)($_POST['reference'] ?? '')) ?: null;
    $gedId   = (int)($_POST['ged_document_id'] ?? 0) ?: null;
    $note    = trim((string)($_POST['note'] ?? '')) ?: null;

    // Contexte tenant depuis le dossier.
    $stD = $pdo->prepare("SELECT id_societe, id_agence FROM creancier_dossier WHERE id = ?");
    $stD->execute([$idDossier]); $ctx = $stD->fetch(PDO::FETCH_ASSOC) ?: [];

    $pdo->prepare("INSERT INTO creancier_mouvement
        (id_dossier, type, montant, date_mouvement, id_tiers_beneficiaire, mode, reference, ged_document_id, note, id_societe, id_agence, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$idDossier, $type, $montant, $date, $benef, $mode, $ref, $gedId, $note,
                   $ctx['id_societe'] ?? null, $ctx['id_agence'] ?? null, $userId]);

    echo json_encode(['ok'=>true, 'id'=>(int)$pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'add_bulk') {
    $raw = (string)($_POST['lignes'] ?? '');
    $arr = json_decode($raw, true);
    if (!is_array($arr) || !$arr) { echo json_encode(['ok'=>false,'error'=>'Aucune ligne']); exit; }

    $stD = $pdo->prepare("SELECT id_societe, id_agence FROM creancier_dossier WHERE id = ?");
    $stD->execute([$idDossier]); $ctx = $stD->fetch(PDO::FETCH_ASSOC) ?: [];

    $ins = $pdo->prepare("INSERT INTO creancier_mouvement
        (id_dossier, type, montant, date_mouvement, reference, ged_document_id, note, id_societe, id_agence, created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?)");
    $n = 0;
    foreach ($arr as $l) {
        $type = (string)($l['type'] ?? '');
        if (!in_array($type, $TYPES, true)) continue;
        $montant = round((float)($l['montant'] ?? 0), 2);
        if ($montant <= 0) continue;
        $date = (isset($l['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$l['date'])) ? $l['date'] : null;
        $ref  = trim((string)($l['reference'] ?? '')) ?: null;
        $gedId= (int)($l['ged_document_id'] ?? 0) ?: null;
        $note = trim((string)(($l['libelle'] ?? '') . (!empty($l['beneficiaire']) ? ' · ' . $l['beneficiaire'] : ''))) ?: null;
        if ($note !== null) $note = mb_substr($note, 0, 255);
        $ins->execute([$idDossier, $type, $montant, $date, $ref, $gedId, $note,
                       $ctx['id_societe'] ?? null, $ctx['id_agence'] ?? null, $userId]);
        $n++;
    }
    echo json_encode(['ok'=>true, 'inserted'=>$n], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete') {
    if (!$isMgr) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Suppression réservée aux managers']); exit; }
    $mid = (int)($_POST['mouvement_id'] ?? 0);
    if ($mid <= 0) { echo json_encode(['ok'=>false,'error'=>'mouvement_id requis']); exit; }
    $pdo->prepare("DELETE FROM creancier_mouvement WHERE id = ? AND id_dossier = ?")->execute([$mid, $idDossier]);
    echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'action inconnue']);
