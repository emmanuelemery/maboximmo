<?php
/**
 * api/bail_sign_get.php — Récupère le tracé + la photo-preuve d'une signature déjà prise,
 * pour les ré-afficher dans le modal quand on revient sur un signataire validé.
 *
 * POST JSON : { bail_id, sig_id }  →  { ok, statut, nom, signed_at, signature_data, photo_preuve }
 * Auth : user connecté + scope société (bypass admin, exception bailleur rôles 9/10).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }

$pdo=$GLOBALS['pdo'];
$userId=(int)($_SESSION['user_id']??0); $userSoc=(int)($_SESSION['id_societe']??0); $isAdmin=((int)($_SESSION['id_role']??0)===1);
$body=json_decode(file_get_contents('php://input')?:'{}',true)?:[];
$bailId=(int)($body['bail_id']??0);
$sigId=(int)($body['sig_id']??0);
if ($bailId<=0 || $sigId<=0) exit(json_encode(['ok'=>false,'error'=>'bail_id et sig_id requis']));

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

// photo_preuve peut ne pas exister (migration non passée) → repli.
try {
    $q=$pdo->prepare("SELECT statut, nom_signataire, signed_at, signature_data, photo_preuve FROM bail_signatures WHERE id=? AND id_bail=? LIMIT 1");
    $q->execute([$sigId,$bailId]);
} catch (Throwable) {
    $q=$pdo->prepare("SELECT statut, nom_signataire, signed_at, signature_data FROM bail_signatures WHERE id=? AND id_bail=? LIMIT 1");
    $q->execute([$sigId,$bailId]);
}
$row=$q->fetch(PDO::FETCH_ASSOC);
if (!$row){ http_response_code(404); exit(json_encode(['ok'=>false,'error'=>'Signature introuvable'])); }

echo json_encode([
    'ok'=>true,
    'statut'=>(string)$row['statut'],
    'nom'=>(string)($row['nom_signataire'] ?? ''),
    'signed_at'=>(string)($row['signed_at'] ?? ''),
    'signature_data'=>(string)($row['signature_data'] ?? ''),
    'photo_preuve'=>(string)($row['photo_preuve'] ?? ''),
], JSON_UNESCAPED_UNICODE);
