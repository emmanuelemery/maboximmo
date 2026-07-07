<?php
/**
 * api/bail_cloturer.php — CLÔTURE de la cérémonie de signature (action explicite de l'agent).
 * Ne s'exécute que si toutes les parties enregistrées ont signé :
 *   bascule candidat→locataire + PDF signé en GED + envoi aux signataires.
 *
 * POST JSON : { bail_id }  →  { ok, message, doc_id?, mailed? }
 * Auth : user connecté + scope société (bypass admin, exception bailleur rôles 9/10).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/bail_signature.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }

$pdo=$GLOBALS['pdo'];
$userId=(int)($_SESSION['user_id']??0); $userSoc=(int)($_SESSION['id_societe']??0); $isAdmin=((int)($_SESSION['id_role']??0)===1);
$body=json_decode(file_get_contents('php://input')?:'{}',true)?:[];
$bailId=(int)($body['bail_id']??0);
if ($bailId<=0) exit(json_encode(['ok'=>false,'error'=>'bail_id requis']));

// Scope société
$st=$pdo->prepare("SELECT bb.id_societe, b.id_proprietaire FROM bien_baux bb JOIN biens b ON b.id=bb.id_bien WHERE bb.id=?");
$st->execute([$bailId]); $r=$st->fetch(PDO::FETCH_ASSOC);
if (!$r){ http_response_code(404); exit(json_encode(['ok'=>false,'error'=>'Bail introuvable'])); }
if (!$isAdmin && !empty($r['id_societe']) && (int)$r['id_societe']!==$userSoc){
    $ok=false;
    if (in_array((int)($_SESSION['id_role']??0),[9,10],true) && (int)($r['id_proprietaire']??0)>0){
        $c=$pdo->prepare("SELECT 1 FROM user_proprietaires WHERE id_user=? AND id_proprietaire=? LIMIT 1");
        $c->execute([$userId,(int)$r['id_proprietaire']]); $ok=(bool)$c->fetchColumn();
    }
    if(!$ok){ http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Hors périmètre'])); }
}

$res = bail_cloturer($pdo, $bailId);
if (empty($res['ok'])) exit(json_encode(['ok'=>false,'error'=>$res['error']??'Clôture impossible','signes'=>$res['signes']??null,'total'=>$res['total']??null], JSON_UNESCAPED_UNICODE));

$doc = (int)($res['finalize']['doc_id'] ?? 0);
echo json_encode([
    'ok'=>true,
    'doc_id'=>$doc,
    'message'=>'Bail clôturé et signé. Le PDF signé est classé en GED'.($doc?' (doc #'.$doc.')':'').' et envoyé aux signataires.',
], JSON_UNESCAPED_UNICODE);
