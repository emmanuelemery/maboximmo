<?php
declare(strict_types=1);
/**
 * api/entite_acteur_remove.php — Retire un acteur d'une entité (par id de lien). Staff only.
 * POST : id (entite_acteurs.id), csrf_token.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/csrf.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); exit(json_encode(['ok' => false, 'error' => 'POST requis'])); }
verify_csrf_any('default');

$roleId = (int)current_role_id();
$isStaff = (function_exists('is_super_admin') && is_super_admin()) || in_array($roleId, [1, 2, 3, 7, 8], true);
if (!$isStaff) { http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'Accès réservé au personnel.'])); }

$pdo = $GLOBALS['pdo'];
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit(json_encode(['ok' => false, 'error' => 'id manquant.'])); }

try {
    $pdo->prepare("DELETE FROM entite_acteurs WHERE id = ?")->execute([$id]);
    if (class_exists('AuditLog')) { try { AuditLog::log($pdo, 'ACTEUR_REMOVE', 'entite_acteurs', $id, [], []); } catch (Throwable) {} }
} catch (Throwable $e) { http_response_code(500); exit(json_encode(['ok' => false, 'error' => $e->getMessage()])); }

echo json_encode(['ok' => true]);
