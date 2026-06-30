<?php
/**
 * bailleur_save_loyer_estime.php — Enregistre un LOYER ESTIMÉ (mensuel HC) pour un bien
 * depuis le tableau Patrimoine actif (lignes VACANT hors CRG). Stocké comme prix courant
 * de type 'loyer' dans bien_prix (source de vérité). Remplacé par le CRG dès qu'il existe.
 * POST : id_bien, id_proprietaire, loyer_estime
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/roles_services.php';
require_once __DIR__ . '/inc/bien_prix.php';
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

$idBien = (int)($_POST['id_bien'] ?? 0);
$idProp = (int)($_POST['id_proprietaire'] ?? 0);
$loyer  = (float)str_replace([' ', ','], ['', '.'], (string)($_POST['loyer_estime'] ?? '0'));
if ($idBien <= 0 || $idProp <= 0) { echo json_encode(['ok'=>false,'error'=>'paramètres manquants']); exit; }
if ($loyer < 0 || $loyer > 1000000) { echo json_encode(['ok'=>false,'error'=>'loyer invalide']); exit; }

// Ownership (sauf super-admin)
if (!$isSuperAdmin) {
    $chk = $pdo->prepare("SELECT 1 FROM user_proprietaires WHERE id_user=? AND id_proprietaire=? LIMIT 1");
    $chk->execute([$userId, $idProp]);
    if (!$chk->fetchColumn()) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Propriétaire hors périmètre']); exit;
    }
}

try {
    // Lien bien↔propriétaire : non vérifié pour le super admin (accès global).
    if (!$isSuperAdmin) {
        $st = $pdo->prepare("SELECT 1 FROM biens WHERE id=? AND id_proprietaire=? LIMIT 1");
        $st->execute([$idBien, $idProp]);
        if (!$st->fetchColumn()) {
            // repli : lien via CRG (cas id_proprietaire désaligné)
            $st2 = $pdo->prepare("SELECT 1 FROM crg_situations_locataires c JOIN crg_trimestres t ON c.id_crg=t.id WHERE c.id_bien=? AND t.id_proprietaire=? LIMIT 1");
            $st2->execute([$idBien, $idProp]);
            if (!$st2->fetchColumn()) { echo json_encode(['ok'=>false,'error'=>'bien hors périmètre propriétaire']); exit; }
        }
    }

    $res = bien_prix_valider($pdo, $idBien, 'loyer', $loyer, 'bailleur', $userId, 'Loyer estimé (bien vacant)');
    if (empty($res['ok'])) { echo json_encode(['ok'=>false,'error'=>$res['error'] ?? 'échec']); exit; }

    echo json_encode(['ok'=>true, 'id_bien'=>$idBien, 'loyer_estime'=>$loyer]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
