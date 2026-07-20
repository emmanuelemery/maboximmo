<?php
declare(strict_types=1);

/**
 * api/pilotage_task_save.php — Édition d'une mission (Tranche 3).
 * Body JSON : { task_id, op, ... }
 *   op = save_fields : met à jour les champs de contenu ; si la mission était VERTE,
 *                      snapshot + repasse en JAUNE (nouvelle version à contrôler).
 *   op = validate    : passe au VERT (réservé habilités).
 *   op = reopen      : repasse au JAUNE (réservé habilités).
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/pilotage.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function pl_out(array $a, int $code = 200): void { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pl_out(['ok' => false, 'error' => 'POST requis'], 405);
verify_csrf_any('pilotage');

$pdo = $GLOBALS['pdo'];
$soc = (int)(current_societe_id() ?? 0);
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) pl_out(['ok' => false, 'error' => 'JSON invalide'], 400);

$taskId = (int)($body['task_id'] ?? 0);
$op = (string)($body['op'] ?? '');
if ($taskId <= 0) pl_out(['ok' => false, 'error' => 'task_id requis'], 400);

// périmètre société (super admin : dérive la société de la mission)
if ($soc <= 0 && is_super_admin()) {
    $st = $pdo->prepare("SELECT id_societe FROM pilotage_tasks WHERE id=?"); $st->execute([$taskId]); $soc = (int)$st->fetchColumn();
}
if (!pilotage_task_scope_ok($pdo, $taskId, $soc)) pl_out(['ok' => false, 'error' => 'Mission hors périmètre.'], 403);

try {
    if ($op === 'validate' || $op === 'reopen') {
        if (!pilotage_can_validate()) pl_out(['ok' => false, 'error' => 'Droit insuffisant pour valider.'], 403);
        $target = $op === 'validate' ? 'green' : 'yellow';
        $cur = $pdo->prepare("SELECT documentation_status FROM pilotage_tasks WHERE id=?"); $cur->execute([$taskId]);
        $old = (string)$cur->fetchColumn();
        $uid = (int)current_user_id();
        if ($op === 'validate') {
            $pdo->prepare("UPDATE pilotage_tasks SET documentation_status='green', validated_by=?, validated_at=NOW(), updated_by=?, updated_at=NOW() WHERE id=?")
                ->execute([$uid, $uid, $taskId]);
        } else {
            // rouvrir : snapshot de l'état vert avant de le quitter
            if ($old === 'green') pilotage_snapshot_version($pdo, $soc, $taskId, $uid);
            $pdo->prepare("UPDATE pilotage_tasks SET documentation_status='yellow', updated_by=?, updated_at=NOW() WHERE id=?")
                ->execute([$uid, $taskId]);
        }
        pilotage_history($pdo, $soc, $taskId, $op, 'documentation_status', $old, $target, $body['reason'] ?? null);
        pl_out(['ok' => true, 'status' => $target]);
    }

    if ($op === 'save_fields') {
        if (!pilotage_can_edit()) pl_out(['ok' => false, 'error' => 'Droit insuffisant.'], 403);
        // champs éditables autorisés
        $allowed = [
            'objective', 'expected_result', 'short_description', 'procedure_text', 'legal_notes', 'accounting_notes',
            'errors_to_avoid', 'internal_notes', 'required_level', 'frequency_type', 'frequency_value',
            'trigger_event', 'estimated_duration_minutes', 'automation_level', 'priority_level', 'ged_entity_type', 'default_doc_template',
        ];
        $fields = (array)($body['fields'] ?? []);
        $set = []; $vals = [];
        foreach ($fields as $k => $v) {
            if (!in_array($k, $allowed, true)) continue;
            $set[] = "`$k`=?";
            $vals[] = ($v === '' ? null : $v);
        }
        if (!$set) pl_out(['ok' => false, 'error' => 'Aucun champ modifiable fourni.'], 400);

        $pdo->beginTransaction();
        $downgraded = pilotage_touch_yellow_if_green($pdo, $soc, $taskId);
        $vals[] = (int)current_user_id();
        $vals[] = $taskId;
        $pdo->prepare("UPDATE pilotage_tasks SET " . implode(',', $set) . ", updated_by=?, updated_at=NOW() WHERE id=?")->execute($vals);
        pilotage_history($pdo, $soc, $taskId, 'edit', implode(',', array_keys(array_intersect_key($fields, array_flip($allowed)))), null, null, null);
        $pdo->commit();
        pl_out(['ok' => true, 'downgraded_to_yellow' => $downgraded]);
    }

    pl_out(['ok' => false, 'error' => 'op inconnue'], 400);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    pl_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
