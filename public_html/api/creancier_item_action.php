<?php
/**
 * api/creancier_item_action.php — Ajoute / supprime un item de dossier (échéance, procédure…).
 *
 * Les items de type ECHEANCE / PROCEDURE / ACTION avec une date alimentent l'AGENDA
 * (cf. creancier_agenda()). Ajout = accès dossier ; suppression = managers.
 *
 * POST : action=add|delete, id_dossier, csrf_token ('creancier_item')
 *   add    : type, titre, [date_echeance], [description], [montant], [priorite]
 *   delete : item_id
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/creancier_urgence_data.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
verify_csrf_any('creancier_item');

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

$TYPES = ['PROCEDURE','ECHEANCE','DETTE','RISQUE','DECISION','ACTION'];

if ($action === 'add') {
    $type  = strtoupper((string)($_POST['type'] ?? ''));
    if (!in_array($type, $TYPES, true)) { echo json_encode(['ok'=>false,'error'=>'Type invalide']); exit; }
    $titre = trim((string)($_POST['titre'] ?? ''));
    if ($titre === '') { echo json_encode(['ok'=>false,'error'=>'Titre requis']); exit; }
    if (mb_strlen($titre) > 190) $titre = mb_substr($titre, 0, 190);
    $date  = trim((string)($_POST['date_echeance'] ?? '')) ?: null;
    if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = null;
    $desc  = trim((string)($_POST['description'] ?? '')) ?: null;
    $montant = ($_POST['montant'] ?? '') !== '' ? round((float)str_replace([' ', ','], ['', '.'], (string)$_POST['montant']), 2) : null;
    $prio  = (int)($_POST['priorite'] ?? 0);

    $pdo->prepare("INSERT INTO creancier_dossier_item (id_dossier, type, titre, description, montant, date_echeance, priorite, created_by)
                   VALUES (?,?,?,?,?,?,?,?)")
        ->execute([$idDossier, $type, $titre, $desc, $montant, $date, $prio, $userId]);

    echo json_encode(['ok'=>true, 'id'=>(int)$pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete') {
    if (!$isMgr) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Suppression réservée aux managers']); exit; }
    $iid = (int)($_POST['item_id'] ?? 0);
    if ($iid <= 0) { echo json_encode(['ok'=>false,'error'=>'item_id requis']); exit; }
    $pdo->prepare("DELETE FROM creancier_dossier_item WHERE id = ? AND id_dossier = ?")->execute([$iid, $idDossier]);
    echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'action inconnue']);
