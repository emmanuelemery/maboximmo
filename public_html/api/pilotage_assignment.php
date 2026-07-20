<?php
declare(strict_types=1);

/**
 * api/pilotage_assignment.php — Gestion fine des affectations d'une mission (modal).
 * Body JSON : { task_id, op, user_id, role }
 *   op = add        : ajoute un collaborateur avec un rôle (executor/validator/supervisor/backup/informed)
 *   op = remove     : retire un rôle d'un collaborateur
 *   op = set_primary: désigne l'exécutant principal (conserve les autres rôles)
 * Ne supprime jamais les autres rôles lors d'une réaffectation d'exécutant principal.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/pilotage.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function pl_out(array $a, int $code = 200): void { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pl_out(['ok' => false, 'error' => 'POST requis'], 405);
verify_csrf_any('pilotage');

$roleId = (int)current_role_id();
if (!in_array($roleId, [1, 2, 7], true) && !(function_exists('is_super_admin') && is_super_admin())) {
    pl_out(['ok' => false, 'error' => 'Droit insuffisant pour gérer les affectations.'], 403);
}

$pdo = $GLOBALS['pdo'];
$soc = (int)(current_societe_id() ?? 0);
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) pl_out(['ok' => false, 'error' => 'JSON invalide'], 400);

$taskId = (int)($body['task_id'] ?? 0);
$userId = (int)($body['user_id'] ?? 0);
$op = (string)($body['op'] ?? '');
$role = (string)($body['role'] ?? 'executor');
$validRoles = ['executor', 'primary_responsible', 'validator', 'supervisor', 'backup', 'informed'];
if ($taskId <= 0 || $userId <= 0) pl_out(['ok' => false, 'error' => 'task_id et user_id requis'], 400);
if (!in_array($role, $validRoles, true)) pl_out(['ok' => false, 'error' => 'Rôle invalide'], 400);

if ($soc <= 0 && is_super_admin()) { $st = $pdo->prepare("SELECT id_societe FROM pilotage_tasks WHERE id=?"); $st->execute([$taskId]); $soc = (int)$st->fetchColumn(); }
if (!pilotage_task_scope_ok($pdo, $taskId, $soc)) pl_out(['ok' => false, 'error' => 'Mission hors périmètre.'], 403);
$cu = $pdo->prepare("SELECT 1 FROM users WHERE id=? AND id_societe=?"); $cu->execute([$userId, $soc]);
if (!$cu->fetchColumn()) pl_out(['ok' => false, 'error' => 'Collaborateur hors périmètre.'], 403);

try {
    if ($op === 'set_primary') {
        $res = pilotage_set_primary_executor($pdo, $soc, $taskId, $userId);
        if (!$res['ok']) pl_out($res, 400);
        pl_out(['ok' => true]);
    }

    if ($op === 'add') {
        $primary = $role === 'executor' && !empty($body['is_primary']);
        if ($primary) {
            $res = pilotage_set_primary_executor($pdo, $soc, $taskId, $userId);
            pl_out($res['ok'] ? ['ok' => true] : $res, $res['ok'] ? 200 : 400);
        }
        $pdo->prepare("INSERT INTO pilotage_task_assignments (id_societe,task_id,user_id,assignment_role,is_primary,is_active)
                       VALUES (?,?,?,?,0,1)
                       ON DUPLICATE KEY UPDATE is_active=1")->execute([$soc, $taskId, $userId, $role]);
        pilotage_history($pdo, $soc, $taskId, 'assign_add', $role, null, (string)$userId);
        pl_out(['ok' => true]);
    }

    if ($op === 'remove') {
        $pdo->prepare("UPDATE pilotage_task_assignments SET is_active=0 WHERE task_id=? AND user_id=? AND assignment_role=?")
            ->execute([$taskId, $userId, $role]);
        pilotage_history($pdo, $soc, $taskId, 'assign_remove', $role, (string)$userId, null);
        pl_out(['ok' => true]);
    }

    pl_out(['ok' => false, 'error' => 'op inconnue'], 400);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    pl_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
