<?php
/**
 * api/bail_send_cancel.php — ANNULE l'envoi pour signature d'un bail (retour au statut « projet »).
 *
 * Usage : après un « Envoyer pour signature », l'agent veut corriger le bail et renvoyer une
 * NOUVELLE version. Cette action :
 *   1. remet le bail en statut 'projet' (redevient modifiable/renvoyable) ;
 *   2. INVALIDE tous les liens de signature en cours (bail_signatures → statut 'refuse',
 *      tracé/photo/mention effacés) → les anciens liens 48 h ne fonctionnent plus. Au prochain
 *      « Envoyer pour signature », de NOUVEAUX tokens sont émis (bsig réutilise seulement les
 *      tokens NON refusés) → nouvelle cérémonie propre sur la nouvelle version.
 *
 * Interdit si le bail est déjà clôturé (signe/actif/resilie) — là c'est un avenant qu'il faut.
 *
 * POST JSON : { bail_id }  →  { ok, message, invalidated }
 * Auth : user connecté + scope société (bypass admin, exception bailleur rôles 9/10).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }

$pdo = $GLOBALS['pdo'];
$userId = (int)($_SESSION['user_id'] ?? 0);
$userSoc = (int)($_SESSION['id_societe'] ?? 0);
$isAdmin = ((int)($_SESSION['id_role'] ?? 0) === 1);
$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$bailId = (int)($body['bail_id'] ?? 0);
if ($bailId <= 0) exit(json_encode(['ok'=>false,'error'=>'bail_id requis']));

// Bail + scope
$st = $pdo->prepare("SELECT bb.id_societe, bb.statut, b.id_proprietaire FROM bien_baux bb JOIN biens b ON b.id=bb.id_bien WHERE bb.id=?");
$st->execute([$bailId]); $r = $st->fetch(PDO::FETCH_ASSOC);
if (!$r) { http_response_code(404); exit(json_encode(['ok'=>false,'error'=>'Bail introuvable'])); }
if (!$isAdmin && !empty($r['id_societe']) && (int)$r['id_societe'] !== $userSoc) {
    $ok = false;
    if (in_array((int)($_SESSION['id_role'] ?? 0), [9,10], true) && (int)($r['id_proprietaire'] ?? 0) > 0) {
        $c = $pdo->prepare("SELECT 1 FROM user_proprietaires WHERE id_user=? AND id_proprietaire=? LIMIT 1");
        $c->execute([$userId, (int)$r['id_proprietaire']]); $ok = (bool)$c->fetchColumn();
    }
    if (!$ok) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Hors périmètre'])); }
}
// Déjà clôturé → signature figée : on n'annule pas (avenant requis).
if (in_array((string)$r['statut'], ['signe','actif','resilie'], true)) {
    exit(json_encode(['ok'=>false,'error'=>'Bail déjà clôturé — signature figée (avenant requis).'], JSON_UNESCAPED_UNICODE));
}
if ((string)$r['statut'] !== 'envoye') {
    exit(json_encode(['ok'=>false,'error'=>'Ce bail n\'est pas en cours d\'envoi (statut '.$r['statut'].').'], JSON_UNESCAPED_UNICODE));
}

try {
    $pdo->beginTransaction();
    // 1. Invalide TOUS les liens de signature en cours (tokens 48 h) → anciens liens morts.
    $inv = $pdo->prepare("UPDATE bail_signatures
                             SET statut='refuse', signature_data=NULL, signed_at=NULL, ip=NULL, lu_approuve=0
                           WHERE id_bail=? AND statut IN ('pending','signe')");
    $inv->execute([$bailId]);
    $invalidated = $inv->rowCount();
    try { $pdo->prepare("UPDATE bail_signatures SET photo_preuve=NULL, mention_manuscrite=NULL WHERE id_bail=?")->execute([$bailId]); } catch (Throwable) {}
    // 2. Retour au statut projet → modifiable + renvoyable.
    $pdo->prepare("UPDATE bien_baux SET statut='projet' WHERE id=?")->execute([$bailId]);
    $pdo->commit();
    echo json_encode(['ok'=>true, 'invalidated'=>$invalidated,
        'message'=>'Envoi annulé : le bail est repassé en projet et les liens de signature ont été invalidés. Modifie-le puis renvoie une nouvelle version.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Annulation échouée : '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
