<?php
/**
 * api/ged_document_delete.php — Supprime (soft) ou archive un document GED, depuis n'importe
 * quelle fiche (tiers, bien, immeuble…).
 *
 * POST JSON : { id_doc, action: 'delete'|'archive', motif? }  →  { ok, action }
 * Auth : user + scope société (bypass admin). Garde-fou bancaire_inviolable : pas de suppression
 * physique, archivage avec motif uniquement.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }

$pdo=$GLOBALS['pdo'];
$isAdmin=((int)($_SESSION['id_role']??0)===1); $userSoc=(int)($_SESSION['id_societe']??0);
$body=json_decode(file_get_contents('php://input')?:'{}',true)?:[];
$idDoc=(int)($body['id_doc']??0);
$action=in_array(($body['action']??''),['delete','archive'],true)?$body['action']:'delete';
$motif=trim((string)($body['motif']??''));
if ($idDoc<=0) exit(json_encode(['ok'=>false,'error'=>'id_doc requis']));

$st=$pdo->prepare("SELECT id, societe_id, document_type, security_level FROM ged_documents WHERE id=? LIMIT 1");
$st->execute([$idDoc]); $doc=$st->fetch(PDO::FETCH_ASSOC);
if (!$doc){ http_response_code(404); exit(json_encode(['ok'=>false,'error'=>'Document introuvable'])); }
if (!$isAdmin && !empty($doc['societe_id']) && (int)$doc['societe_id']!==$userSoc){
    http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Hors périmètre société']));
}

// Garde-fou documents bancaires inviolables : jamais de suppression physique/soft-delete,
// seulement archivage motivé (traçabilité comptable).
$isBancaire = ($doc['security_level'] ?? '') === 'bancaire'
    || stripos((string)$doc['document_type'], 'bancaire') !== false;
if ($isBancaire) {
    if ($action === 'delete') exit(json_encode(['ok'=>false,'error'=>'Document bancaire inviolable : suppression interdite. Archivage motivé uniquement.'], JSON_UNESCAPED_UNICODE));
    if ($motif === '') exit(json_encode(['ok'=>false,'error'=>'Motif obligatoire pour archiver un document bancaire.'], JSON_UNESCAPED_UNICODE));
}

$newStatus = $action === 'archive' ? 'archived' : 'deleted';
$userId=(int)($_SESSION['user_id']??0);
try {
    // Trace le motif/auteur dans metadata sans écraser le reste.
    $pdo->prepare("UPDATE ged_documents
                      SET status=?,
                          metadata=JSON_SET(COALESCE(metadata,'{}'), '$.suppression',
                                   JSON_OBJECT('action', ?, 'motif', ?, 'by', ?, 'at', NOW())),
                          updated_at=NOW()
                    WHERE id=?")->execute([$newStatus, $action, $motif, $userId, $idDoc]);
} catch (Throwable $e) {
    // Repli si JSON_SET indisponible.
    $pdo->prepare("UPDATE ged_documents SET status=?, updated_at=NOW() WHERE id=?")->execute([$newStatus, $idDoc]);
}

echo json_encode(['ok'=>true,'action'=>$action,'status'=>$newStatus], JSON_UNESCAPED_UNICODE);
