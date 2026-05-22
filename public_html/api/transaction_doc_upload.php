<?php
// api/transaction_doc_upload.php — Upload d'un document rattaché à un bien (V0)
// Rattachement : ged_documents (source_module='05_TRANSACTION') + métadonnées JSON pointant le bien.
// V0 : fichier stocké dans public_html/uploads/ged/transaction/{bien_id}/
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/transaction_chg_staging.php';
require_once __DIR__ . '/../inc/transaction_doc_lib.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

// L'upload peut suivre une analyse IA longue → connexion potentiellement coupée
$pdo = db_keepalive();

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$idBien   = (int)(post('id_bien') ?? 0);
$typeDoc  = (string)(post('type_document') ?? 'AUTRE');
$visib    = (string)(post('visibilite') ?? 'interne');
$commentaire = trim((string)(post('commentaire') ?? ''));
$stagingId   = (int)(post('staging_id') ?? 0);

if ($idBien <= 0) { echo json_encode(['ok'=>false,'error'=>'id_bien manquant']); exit; }

// Mode staging : on récupère le fichier déjà stagé en BDD au lieu d'un upload direct
$stagingRow = null;
if ($stagingId > 0) {
    $userIdScope = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);
    $stagingRow = tr_staging_check_owner($pdo, $stagingId, $userIdScope);
    if (!$stagingRow) { echo json_encode(['ok'=>false,'error'=>'staging introuvable']); exit; }
    $stagedPath = __DIR__ . '/../' . $stagingRow['stored_path'];
    if (!is_file($stagedPath)) { echo json_encode(['ok'=>false,'error'=>'fichier staging absent']); exit; }
    // On simule un $_FILES pour réutiliser la fonction existante
    $_FILES['fichier'] = [
        'name'     => (string)$stagingRow['filename'],
        'type'     => (string)$stagingRow['mime_type'],
        'tmp_name' => $stagedPath,
        'error'    => UPLOAD_ERR_OK,
        'size'     => (int)$stagingRow['size_bytes'],
    ];
    // Flag interne : on est en mode staging, ne pas faire move_uploaded_file
    $GLOBALS['_tr_upload_from_staging'] = true;
} elseif (!isset($_FILES['fichier']) || ($_FILES['fichier']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    echo json_encode(['ok'=>false,'error'=>'staging_id ou fichier requis']); exit;
}

$roleId       = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isSuperAdmin = ($roleId === 1);
$idSocieteSession = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
$idAgenceSession  = isset($_SESSION['id_agence'])  ? (int)$_SESSION['id_agence']  : null;
$idUser           = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);

// La fonction transaction_upload_document() est dans inc/transaction_doc_lib.php

try {
    // Scope check
    $st = $pdo->prepare('SELECT id_societe FROM biens WHERE id = ? LIMIT 1');
    $st->execute([$idBien]);
    $bSoc = $st->fetchColumn();
    if ($bSoc === false) { echo json_encode(['ok'=>false,'error'=>'bien introuvable']); exit; }
    if (!$isSuperAdmin && $idSocieteSession !== null && $bSoc !== null && (int)$bSoc !== $idSocieteSession) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'hors scope']); exit;
    }

    $mode = !empty($GLOBALS['_tr_upload_from_staging']) ? 'copy' : 'upload';
    $res = transaction_upload_document(
        $pdo, $idBien, $_FILES['fichier'], $typeDoc, $visib, $commentaire,
        $idSocieteSession, $idAgenceSession, $idUser ?: null, $mode
    );

    // Si staging utilisé : on supprime la ligne staging + le fichier source
    if ($stagingRow) {
        $abs = __DIR__ . '/../' . $stagingRow['stored_path'];
        if (is_file($abs)) @unlink($abs);
        $pdo->prepare('DELETE FROM transaction_chargement_staging WHERE id = ?')->execute([(int)$stagingRow['id']]);
    }

    echo json_encode(['ok'=>true] + $res);
} catch (Throwable $e) {
    error_log('[transaction_doc_upload] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
