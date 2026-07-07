<?php
/**
 * api/bail_sign_presentiel.php — Signature EN PRÉSENTIEL (à l'écran de l'agent).
 * Le signataire (preneur ou garant) dessine sa signature sur le pad ; on l'enregistre
 * dans bail_signatures (statut signe + image du tracé). Même bascule que la signature en ligne.
 *
 * POST JSON : { bail_id, role: 'preneur'|'caution', nom, signature(dataURL PNG) }
 *   → { ok, all_signed, message }
 * Auth : user connecté + scope société (bypass admin).
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
$role=in_array(($body['role']??''),['preneur','caution','bailleur','mandataire'],true)?$body['role']:'preneur';
$nom=trim((string)($body['nom']??''));
$sig=(string)($body['signature']??'');
$photo=(string)($body['photo']??'');
if ($bailId<=0) exit(json_encode(['ok'=>false,'error'=>'bail_id requis']));
if ($nom==='') exit(json_encode(['ok'=>false,'error'=>'Nom du signataire requis']));

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

// Assure qu'un token existe pour ce rôle, puis récupère-le.
bsig_create_for_signataires($pdo, $bailId, $userId);   // crée preneur + caution
$stT=$pdo->prepare("SELECT token FROM bail_signatures WHERE id_bail=? AND role_code=? AND statut<>'refuse' ORDER BY id DESC LIMIT 1");
$stT->execute([$bailId,$role]);
$token=(string)$stT->fetchColumn();
// Bailleur / mandataire : signataires optionnels, créés à la volée au moment où ils signent.
if ($token==='' && in_array($role,['bailleur','mandataire'],true)){
    $tok=bsig_token();
    $idSoc=(int)($r['id_societe']??0) ?: null;
    $pdo->prepare("INSERT INTO bail_signatures
        (id_bail, role_code, id_societe, token, statut, nom_signataire, id_user_created, created_at)
        VALUES (?,?,?,?,'pending',?,?,NOW())")
        ->execute([$bailId,$role,$idSoc,$tok,$nom,$userId]);
    $token=$tok;
}
if ($token===''){ exit(json_encode(['ok'=>false,'error'=>'Aucun signataire '.$role.' sur ce bail.'], JSON_UNESCAPED_UNICODE)); }

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = 'PRÉSENTIEL · ' . ($_SERVER['HTTP_USER_AGENT'] ?? '');
$res = bsig_sign($pdo, $token, $nom, $ip, $ua, $sig ?: null, $photo ?: null);
if (empty($res['ok'])) exit(json_encode(['ok'=>false,'error'=>$res['error']??'Échec signature'], JSON_UNESCAPED_UNICODE));

// id de la ligne signée (pour rafraîchir l'état côté UI sans reload)
$sigId=0;
try { $qi=$pdo->prepare("SELECT id FROM bail_signatures WHERE token=? LIMIT 1"); $qi->execute([$token]); $sigId=(int)$qi->fetchColumn(); } catch (Throwable) {}

echo json_encode(['ok'=>true, 'all_signed'=>!empty($res['all_signed']),
    'sig_id'=>$sigId, 'role'=>$role, 'signes'=>(int)($res['signes']??0), 'total'=>(int)($res['total']??0),
    'message'=>!empty($res['all_signed']) ? 'Bail signé par toutes les parties.' : 'Signature enregistrée.'], JSON_UNESCAPED_UNICODE);
