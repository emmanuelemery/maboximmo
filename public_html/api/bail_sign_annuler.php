<?php
/**
 * api/bail_sign_annuler.php — Annule / efface la signature d'un signataire (avant clôture).
 *   - preneur / caution (signataires requis, pré-créés) → remis à `pending`, tracé effacé ;
 *   - bailleur / mandataire (optionnels, créés à la volée) → la ligne est supprimée.
 * Interdit si le bail est déjà clôturé (statut signe/actif/resilie).
 *
 * POST JSON : { bail_id, sig_id }  →  { ok, message }
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

// Bail + scope
$st=$pdo->prepare("SELECT bb.id_societe, bb.statut, b.id_proprietaire FROM bien_baux bb JOIN biens b ON b.id=bb.id_bien WHERE bb.id=?");
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
// Bail déjà clôturé → on ne touche plus aux signatures.
if (in_array((string)$r['statut'], ['signe','actif','resilie'], true)) {
    exit(json_encode(['ok'=>false,'error'=>'Bail déjà clôturé — signature figée (avenant requis).'], JSON_UNESCAPED_UNICODE));
}

// La signature doit appartenir à ce bail.
$sg=$pdo->prepare("SELECT id, role_code FROM bail_signatures WHERE id=? AND id_bail=? LIMIT 1");
$sg->execute([$sigId,$bailId]); $row=$sg->fetch(PDO::FETCH_ASSOC);
if (!$row){ http_response_code(404); exit(json_encode(['ok'=>false,'error'=>'Signature introuvable'])); }

$role=(string)$row['role_code'];
if (in_array($role, ['bailleur','mandataire'], true)) {
    // Signataire optionnel → on supprime la ligne (elle sera recréée à la prochaine signature).
    $pdo->prepare("DELETE FROM bail_signatures WHERE id=?")->execute([$sigId]);
    $msg = 'Signature du '.$role.' supprimée.';
} else {
    // Signataire requis → on remet à pending, tracé et photo effacés.
    $pdo->prepare("UPDATE bail_signatures
                      SET statut='pending', signature_data=NULL, signed_at=NULL, ip=NULL, lu_approuve=0
                    WHERE id=?")->execute([$sigId]);
    // photo_preuve : best-effort (colonne peut être absente)
    try { $pdo->prepare("UPDATE bail_signatures SET photo_preuve=NULL WHERE id=?")->execute([$sigId]); } catch (Throwable) {}
    $msg = 'Signature effacée — le '.$role.' peut re-signer.';
}

echo json_encode(['ok'=>true, 'message'=>$msg], JSON_UNESCAPED_UNICODE);
