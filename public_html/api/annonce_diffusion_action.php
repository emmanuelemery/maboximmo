<?php
/**
 * api/annonce_diffusion_action.php — Suspendre ou archiver une annonce.
 *   action=suspend  : décoche tous les sites (visible_* = 0). L'annonce reste (pause vente/loc).
 *   action=archive  : décoche tous les sites + etat_publication='archive' (clôture l'annonce).
 *   action=reactiver: etat_publication='brouillon' (réouvre une annonce archivée).
 * POST : id_annonce, action, csrf_token (form 'ajouter_bien').
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }
verify_csrf_any('ajouter_bien');

$pdo          = $GLOBALS['pdo'];
$societeId    = (int)($_SESSION['id_societe'] ?? 0);
$isSuperAdmin = ((int)($_SESSION['id_role'] ?? 0) === 1);

$idA    = isset($_POST['id_annonce']) && ctype_digit((string)$_POST['id_annonce']) ? (int)$_POST['id_annonce'] : 0;
$action = (string)($_POST['action'] ?? '');
if ($idA <= 0) { exit(json_encode(['ok'=>false,'error'=>'id_annonce manquant'])); }
if (!in_array($action, ['suspend','archive','reprendre'], true)) { exit(json_encode(['ok'=>false,'error'=>'action invalide'])); }

try {
    // Scope société via le bien de l'annonce
    $st = $pdo->prepare("SELECT a.id, b.id_societe FROM annonces a LEFT JOIN biens b ON b.id = a.id_bien WHERE a.id = ? LIMIT 1");
    $st->execute([$idA]); $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) { exit(json_encode(['ok'=>false,'error'=>'annonce introuvable'])); }
    if (!$isSuperAdmin && $societeId > 0 && (int)$row['id_societe'] !== $societeId) {
        http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Annonce hors de votre société']));
    }

    if ($action === 'reprendre') {
        // Lève la suspension / désarchive → retour en brouillon (l'utilisateur re-sélectionne les canaux).
        $pdo->prepare("UPDATE annonces SET etat_publication='brouillon', date_modification=NOW() WHERE id=?")->execute([$idA]);
        echo json_encode(['ok'=>true, 'etat'=>'brouillon']); exit;
    }

    // suspend / archive : décochent tous les sites + posent l'état.
    $etat = $action === 'archive' ? 'archive' : 'suspendu';
    $pdo->prepare("UPDATE annonces SET visible_maboximmo=0, visible_site_perso=0, visible_portails=0, etat_publication=?, date_modification=NOW() WHERE id=?")
        ->execute([$etat, $idA]);

    echo json_encode(['ok'=>true, 'action'=>$action, 'sites_decoches'=>true, 'etat'=>$etat], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[annonce_diffusion_action] ' . $e->getMessage());
    http_response_code(500); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
