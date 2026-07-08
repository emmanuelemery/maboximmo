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
$action=in_array(($body['action']??''),['delete','archive','restore'],true)?$body['action']:'delete';
$motif=trim((string)($body['motif']??''));
if ($idDoc<=0) exit(json_encode(['ok'=>false,'error'=>'id_doc requis']));

$st=$pdo->prepare("SELECT id, societe_id, document_type, security_level, hash_sha256, fluxbox_source_id FROM ged_documents WHERE id=? LIMIT 1");
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

$newStatus = $action === 'archive' ? 'archived' : ($action === 'restore' ? 'active' : 'deleted');
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

// Suppression réelle → « oublier » le hash dans le registre FluxBox (fluxbox_documents),
// sinon l'anti-doublon continue de bloquer le ré-import du MÊME fichier après suppression.
// (L'archivage NE touche pas au registre : le doc existe toujours.)
$forgot = 0;
if ($action === 'delete') {
    try {
        $srcId = (int)($doc['fluxbox_source_id'] ?? 0);
        $hash  = (string)($doc['hash_sha256'] ?? '');
        if ($srcId > 0) {
            $q = $pdo->prepare("DELETE FROM fluxbox_documents WHERE id=?");
            $q->execute([$srcId]); $forgot += $q->rowCount();
        }
        // Filet : purge aussi toute entrée résiduelle du même hash (tenant = société du doc).
        if ($hash !== '') {
            $tenant = (int)($doc['societe_id'] ?? 0);
            if ($tenant > 0) {
                $q = $pdo->prepare("DELETE FROM fluxbox_documents WHERE hash_sha256=? AND tenant_id=?");
                $q->execute([$hash, $tenant]);
            } else {
                $q = $pdo->prepare("DELETE FROM fluxbox_documents WHERE hash_sha256=?");
                $q->execute([$hash]);
            }
            $forgot += $q->rowCount();
        }
    } catch (Throwable $e) { /* registre absent/colonnes différentes : suppression GED reste effective */ }
}

echo json_encode(['ok'=>true,'action'=>$action,'status'=>$newStatus,'hash_forgotten'=>$forgot], JSON_UNESCAPED_UNICODE);
