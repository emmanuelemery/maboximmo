<?php
/**
 * api/tiers_contact_remove.php — Retire un contact lié (lien tiers_contacts).
 * Soft-delete (actif=0) : conserve l'historique, le contact disparaît de la fiche.
 *
 * POST : lien_id, csrf_token (form 'tiers_contact'). Manager+ et scope société.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
verify_csrf_any('tiers_contact');

$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isMgr  = in_array($roleId, [1,2,3,7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isMgr) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'manager+ requis']); exit; }

$pdo    = $GLOBALS['pdo'];
$lienId = (int)($_POST['lien_id'] ?? 0);
if ($lienId <= 0) { echo json_encode(['ok'=>false,'error'=>'lien_id requis']); exit; }

// Contrôle de périmètre : le tiers-entité du lien doit appartenir à la société de l'user (bypass admin/super).
$isAdmin   = ($roleId === 1) || ($roleId === 7) || (function_exists('is_super_admin') && is_super_admin());
$userSocId = (int)($_SESSION['id_societe'] ?? 0);
$st = $pdo->prepare("SELECT te.id_societe
                       FROM tiers_contacts tc JOIN tiers te ON te.id = tc.id_tiers_entite
                      WHERE tc.id = ? LIMIT 1");
$st->execute([$lienId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Lien introuvable']); exit; }
if (!$isAdmin && !empty($row['id_societe']) && (int)$row['id_societe'] !== $userSocId) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Hors de votre société']); exit;
}

try {
    $pdo->prepare("UPDATE tiers_contacts SET actif = 0, date_fin = CURDATE() WHERE id = ?")->execute([$lienId]);
    echo json_encode(['ok'=>true, 'lien_id'=>$lienId]);
} catch (Throwable $e) {
    error_log('[tiers_contact_remove] ' . $e->getMessage());
    echo json_encode(['ok'=>false,'error'=>'Erreur suppression']);
}
