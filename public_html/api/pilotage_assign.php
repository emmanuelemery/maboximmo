<?php
declare(strict_types=1);

/**
 * api/pilotage_assign.php — Réaffecte l'exécutant principal d'une mission.
 * Body JSON : { task_id:int, user_id:int|null }  (user_id null = non attribuer)
 * Conserve les autres rôles (validateur/superviseur).
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/pilotage.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST requis'], JSON_UNESCAPED_UNICODE);
    exit;
}
verify_csrf_any('pilotage');

// Droit : manager/admin (répartition des missions). Rôles 1,2,7 + super admin.
$roleId = (int)current_role_id();
if (!in_array($roleId, [1, 2, 7], true) && !(function_exists('is_super_admin') && is_super_admin())) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Droit insuffisant pour réaffecter une mission.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = $GLOBALS['pdo'];
$soc = (int)(current_societe_id() ?? 0);
if ($soc <= 0 && !is_super_admin()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Société non déterminée.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON invalide'], JSON_UNESCAPED_UNICODE);
    exit;
}

$taskId = (int)($body['task_id'] ?? 0);
$userId = isset($body['user_id']) && $body['user_id'] !== null ? (int)$body['user_id'] : null;
if ($taskId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'task_id requis'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Super admin sans société : dériver la société de la mission.
if ($soc <= 0) {
    $st = $pdo->prepare("SELECT id_societe FROM pilotage_tasks WHERE id=?");
    $st->execute([$taskId]);
    $soc = (int)$st->fetchColumn();
}

if ($userId === null) {
    // « non attribuer » = retirer l'exécutant principal (les autres rôles restent)
    try {
        $chk = $pdo->prepare("SELECT 1 FROM pilotage_tasks WHERE id=? AND id_societe=?");
        $chk->execute([$taskId, $soc]);
        if (!$chk->fetchColumn()) throw new RuntimeException('Mission hors périmètre.');
        $pdo->prepare("UPDATE pilotage_task_assignments SET is_primary=0, is_active=0
                       WHERE task_id=? AND assignment_role='executor'")->execute([$taskId]);
        $pdo->prepare("INSERT INTO pilotage_task_history (id_societe,task_id,action,field,new_value,user_id)
                       VALUES (?,?,'unassign','executor',NULL,?)")
            ->execute([$soc, $taskId, (int)current_user_id()]);
        echo json_encode(['ok' => true, 'unassigned' => true], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

$res = pilotage_set_primary_executor($pdo, $soc, $taskId, $userId);
if (!$res['ok']) http_response_code(400);
echo json_encode($res, JSON_UNESCAPED_UNICODE);
