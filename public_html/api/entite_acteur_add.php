<?php
declare(strict_types=1);
/**
 * api/entite_acteur_add.php — Ajoute un ACTEUR (tiers + rôle) à une entité quelconque.
 * Socle générique du bouton « + » des contacts sur les 360 (voir inc/entite_acteurs.php).
 * POST : entity_type, entity_id, id_tiers, role, note?, csrf_token. Staff uniquement.
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
$entityType = strtoupper(preg_replace('/[^A-Za-z_]/', '', (string)($_POST['entity_type'] ?? '')));
$entityId   = (int)($_POST['entity_id'] ?? 0);
$idTiers    = (int)($_POST['id_tiers'] ?? 0);
$role       = trim((string)($_POST['role'] ?? '')) ?: 'contact';
$note       = trim((string)($_POST['note'] ?? '')) ?: null;
if ($entityType === '' || $entityId <= 0 || $idTiers <= 0) { http_response_code(400); exit(json_encode(['ok' => false, 'error' => 'Paramètres manquants.'])); }

$soc = (int)(function_exists('current_societe_id') ? (current_societe_id() ?? 0) : ($_SESSION['id_societe'] ?? 0)) ?: null;
try {
    $pdo->prepare("INSERT IGNORE INTO entite_acteurs (id_societe, entity_type, entity_id, id_tiers, role, note, created_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$soc, $entityType, $entityId, $idTiers, $role, $note, (int)(current_user_id() ?? 0)]);
    if (class_exists('AuditLog')) {
        try { AuditLog::log($pdo, 'ACTEUR_ADD', 'entite_acteurs', $entityId, [], ['entity_type' => $entityType, 'id_tiers' => $idTiers, 'role' => $role]); } catch (Throwable) {}
    }
} catch (Throwable $e) { http_response_code(500); exit(json_encode(['ok' => false, 'error' => $e->getMessage()])); }

echo json_encode(['ok' => true]);
