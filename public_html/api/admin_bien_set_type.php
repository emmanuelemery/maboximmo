<?php
/**
 * api/admin_bien_set_type.php — Affecte rapidement le TYPE d'un bien (référentiel bien_types).
 *
 * Utilisé par la page de triage admin_biens_types.php (boutons Maison/Appartement/Entrepôt/
 * Bureau/Commerce). Écrit `biens.id_bien_type` (source de vérité, lue par le détail et les cards).
 *
 * POST : id_bien, id_bien_type, csrf_token ('admin_bien_type'). Super admin.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/csrf.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
verify_csrf_any('admin_bien_type');

$pdo    = $GLOBALS['pdo'];
$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isAdmin = ($roleId === 1 || $roleId === 7) || (function_exists('is_super_admin') && is_super_admin());
if (!$isAdmin) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Réservé aux super administrateurs']); exit; }

$idBien     = (int)($_POST['id_bien'] ?? 0);
$idBienType = (int)($_POST['id_bien_type'] ?? 0);

// Types autorisés depuis cette page (référentiel bien_types).
$ALLOWED = [1 => 'Appartement', 6 => 'Maison', 18 => 'Commerce (local commercial)', 20 => 'Bureau', 23 => 'Entrepôt'];
if ($idBien <= 0 || !isset($ALLOWED[$idBienType])) { echo json_encode(['ok'=>false,'error'=>'paramètres invalides']); exit; }

$st = $pdo->prepare("UPDATE biens SET id_bien_type = ?, date_modification = NOW() WHERE id = ?");
$st->execute([$idBienType, $idBien]);

echo json_encode(['ok'=>true, 'id_bien'=>$idBien, 'id_bien_type'=>$idBienType, 'libelle'=>$ALLOWED[$idBienType]], JSON_UNESCAPED_UNICODE);
