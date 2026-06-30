<?php
/**
 * bailleur_save_surface.php — Enregistre/modifie la surface Carrez d'un bien
 * (base de calcul VENTE depuis le tableau Patrimoine actif).
 * POST : id_bien, id_proprietaire, surface_carrez
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/roles_services.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$pdo          = $GLOBALS['pdo'];
$userId       = (int)current_user_id();
$roleId       = (int)current_role_id();
$isSuperAdmin = is_super_admin();

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
if (!$isSuperAdmin && !hasServiceAccess($roleId, 'bailleur')) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Accès réservé au module Bailleur']); exit;
}
if (function_exists('is_readonly_user') && is_readonly_user()) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Compte en lecture seule']); exit;
}

$idBien  = (int)($_POST['id_bien'] ?? 0);
$idProp  = (int)($_POST['id_proprietaire'] ?? 0);
$surface = (float)str_replace(',', '.', (string)($_POST['surface_carrez'] ?? '0'));
if ($idBien <= 0 || $idProp <= 0) { echo json_encode(['ok'=>false,'error'=>'paramètres manquants']); exit; }
if ($surface < 0 || $surface > 100000) { echo json_encode(['ok'=>false,'error'=>'surface invalide']); exit; }

// ── Ownership ──
if (!$isSuperAdmin) {
    $chk = $pdo->prepare("SELECT 1 FROM user_proprietaires WHERE id_user=? AND id_proprietaire=? LIMIT 1");
    $chk->execute([$userId, $idProp]);
    if (!$chk->fetchColumn()) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Propriétaire hors périmètre']); exit;
    }
}

try {
    // Le bien existe ?
    $st = $pdo->prepare("SELECT id FROM biens WHERE id=? LIMIT 1");
    $st->execute([$idBien]);
    if (!$st->fetchColumn()) { echo json_encode(['ok'=>false,'error'=>'bien introuvable']); exit; }

    // Vérifier l'appartenance via la CRG (source de vérité du module bailleur) :
    // biens.id_proprietaire peut être NULL/désaligné (ex. FOCH #260 dont les biens
    // restent à 2/NULL). On valide donc le lien bien↔propriétaire par la CRG,
    // avec repli sur biens.id_proprietaire.
    // Lien bien↔propriétaire : non vérifié pour le super admin (accès global).
    if (!$isSuperAdmin) {
        $chkB = $pdo->prepare("SELECT 1 FROM crg_situations_locataires c
            JOIN crg_trimestres t ON c.id_crg = t.id
            WHERE c.id_bien = ? AND t.id_proprietaire = ? LIMIT 1");
        $chkB->execute([$idBien, $idProp]);
        $okLien = (bool)$chkB->fetchColumn();
        if (!$okLien) {
            $chkB2 = $pdo->prepare("SELECT 1 FROM biens WHERE id = ? AND id_proprietaire = ? LIMIT 1");
            $chkB2->execute([$idBien, $idProp]);
            $okLien = (bool)$chkB2->fetchColumn();
        }
        if (!$okLien) {
            echo json_encode(['ok'=>false,'error'=>'bien hors périmètre propriétaire']); exit;
        }
    }

    $val = $surface > 0 ? $surface : null;
    $pdo->prepare("UPDATE biens SET surface_carrez = ?, date_modification = NOW() WHERE id = ?")
        ->execute([$val, $idBien]);

    echo json_encode(['ok'=>true, 'id_bien'=>$idBien, 'surface_carrez'=>$val]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
