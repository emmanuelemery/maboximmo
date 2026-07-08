<?php
/**
 * api/ged_documents_archived.php — Liste les documents ARCHIVÉS d'une entité (tiers/bien/immeuble/bail).
 * POST JSON : { entity_type: 'TIERS'|'BIEN'|'IMB'|'BAIL', entity_id } → { ok, docs:[{id,name_display,document_type,created_at}] }
 * Auth : user + scope société (bypass admin).
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
$type=strtoupper(trim((string)($body['entity_type']??'')));
$id=(int)($body['entity_id']??0);
if (!in_array($type,['TIERS','BIEN','IMB','BAIL'],true) || $id<=0) exit(json_encode(['ok'=>false,'error'=>'entity_type/entity_id invalides']));

$soc = $isAdmin ? '' : ' AND (gd.societe_id IS NULL OR gd.societe_id = ' . (int)$userSoc . ')';
$st=$pdo->prepare("SELECT DISTINCT gd.id, gd.name_display, gd.document_type, gd.created_at
                     FROM ged_documents gd
                     JOIN ged_document_links gdl ON gdl.document_id = gd.id
                    WHERE gd.status='archived' AND gdl.entity_type=? AND gdl.entity_id=? $soc
                    ORDER BY gd.created_at DESC LIMIT 50");
$st->execute([$type,$id]);
echo json_encode(['ok'=>true, 'docs'=>$st->fetchAll(PDO::FETCH_ASSOC) ?: []], JSON_UNESCAPED_UNICODE);
