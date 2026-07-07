<?php
/**
 * api/societe_rib_delete.php — Supprime un compte bancaire (societes_rib).
 * POST JSON : { id, id_societe }  →  { ok }
 * Auth : user + scope société (bypass super-admin role=1).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/comptes_bancaires.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }

$pdo=$GLOBALS['pdo'];
$isAdmin=((int)($_SESSION['id_role']??0)===1);
$userSoc=(int)($_SESSION['id_societe']??0);
$body=json_decode(file_get_contents('php://input')?:'{}',true)?:[];
$id=(int)($body['id']??0); $idSoc=(int)($body['id_societe']??0);
if ($id<=0 || $idSoc<=0) exit(json_encode(['ok'=>false,'error'=>'id et id_societe requis']));
if (!$isAdmin && $idSoc!==$userSoc){ http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Hors périmètre société'])); }

$ok = cb_delete($pdo, $id, $idSoc);
echo json_encode(['ok'=>$ok, 'error'=>$ok?null:'Compte introuvable'], JSON_UNESCAPED_UNICODE);
