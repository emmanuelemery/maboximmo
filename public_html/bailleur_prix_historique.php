<?php
/**
 * bailleur_prix_historique.php — Historique des prix/loyers validés d'un bien.
 * GET : id_bien. Accès : service bailleur, bien dans le périmètre de l'utilisateur.
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
if (!$isSuperAdmin && !hasServiceAccess($roleId, 'bailleur')) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Accès réservé au module Bailleur']); exit;
}

$idBien = (int)($_GET['id_bien'] ?? 0);
if ($idBien <= 0) { echo json_encode(['ok'=>false,'error'=>'id_bien requis']); exit; }

// Périmètre : super admin OU bien rattaché à un propriétaire de l'utilisateur (direct ou via CRG)
if (!$isSuperAdmin) {
    $c = $pdo->prepare("SELECT 1 FROM biens b
        WHERE b.id=? AND (
            b.id_proprietaire IN (SELECT id_proprietaire FROM user_proprietaires WHERE id_user=?)
            OR b.id IN (SELECT s.id_bien FROM crg_situations_locataires s JOIN crg_trimestres t ON t.id=s.id_crg
                        WHERE t.id_proprietaire IN (SELECT id_proprietaire FROM user_proprietaires WHERE id_user=?))
        ) LIMIT 1");
    $c->execute([$idBien, $userId, $userId]);
    if (!$c->fetchColumn()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Bien hors périmètre']); exit; }
}

$lignes = bien_prix_historique($pdo, $idBien);
foreach ($lignes as &$l) { $l['date_validation'] = date('d/m/Y H:i', strtotime((string)$l['date_validation'])); }
unset($l);

echo json_encode(['ok'=>true, 'id_bien'=>$idBien, 'lignes'=>$lignes], JSON_UNESCAPED_UNICODE);
