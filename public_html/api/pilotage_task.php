<?php
declare(strict_types=1);

/**
 * api/pilotage_task.php?id=123 — Détail complet d'une mission pour le modal.
 * Scopé société. Retourne task + assignments + checklist + steps + resources + legal.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/pilotage.php';
require_once __DIR__ . '/../inc/pilotage_automations.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'];
$soc = (int)(current_societe_id() ?? 0);
$taskId = (int)($_GET['id'] ?? 0);
if ($taskId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'id requis'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sql = "SELECT t.*, c.name AS category_name, s.name AS service_name
        FROM pilotage_tasks t
        LEFT JOIN pilotage_categories c ON c.id = t.category_id
        LEFT JOIN pilotage_services s ON s.id = t.service_id
        WHERE t.id = ?" . ($soc > 0 && !is_super_admin() ? " AND t.id_societe = ?" : "");
$st = $pdo->prepare($sql);
$st->execute($soc > 0 && !is_super_admin() ? [$taskId, $soc] : [$taskId]);
$task = $st->fetch(PDO::FETCH_ASSOC);
if (!$task) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Mission introuvable'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fetch = function (string $sql) use ($pdo, $taskId): array {
    $s = $pdo->prepare($sql);
    $s->execute([$taskId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
};

$assignments = $fetch("SELECT a.assignment_role, a.is_primary, u.prenom, u.nom
                       FROM pilotage_task_assignments a JOIN users u ON u.id = a.user_id
                       WHERE a.is_active=1 AND a.task_id=? ORDER BY a.is_primary DESC");
$checklist = $fetch("SELECT id, group_label, label, description, is_mandatory, auto_source, display_order
                     FROM pilotage_task_checklist_items WHERE task_id=? ORDER BY display_order, id");
$steps = $fetch("SELECT id, step_key, step_number, title, description, expected_result, automation_note, responsible_role, requires_validation, display_order
                 FROM pilotage_task_steps WHERE task_id=? ORDER BY display_order, step_number");
$resources = $fetch("SELECT resource_type, title, url, page_route, template_id, description
                     FROM pilotage_task_resources WHERE task_id=? ORDER BY display_order, id");
$legal = $fetch("SELECT title, summary, legal_reference, source_url, validation_status, validated_at
                 FROM pilotage_task_legal_rules WHERE task_id=? ORDER BY id");
$postes = $fetch("SELECT poste_code, assignment_role, is_primary FROM pilotage_task_postes WHERE task_id=? ORDER BY is_primary DESC");
$history = $fetch("SELECT h.action, h.field, h.old_value, h.new_value, h.reason, h.created_at,
                          TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) AS author
                   FROM pilotage_task_history h LEFT JOIN users u ON u.id = h.user_id
                   WHERE h.task_id=? ORDER BY h.id DESC LIMIT 100");
$versions = $fetch("SELECT version_number, documentation_status, created_at,
                           TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) AS author
                    FROM pilotage_task_versions v LEFT JOIN users u ON u.id = v.created_by
                    WHERE v.task_id=? ORDER BY version_number DESC");

// Collaborateurs de la société (pour les selects d'affectation du modal)
$socOfTask = (int)$task['id_societe'];
$uq = $pdo->prepare("SELECT id, TRIM(CONCAT(COALESCE(prenom,''),' ',COALESCE(nom,''))) AS name, fonction
                     FROM users WHERE id_societe=? AND actif=1 ORDER BY prenom, nom");
$uq->execute([$socOfTask]);
$socUsers = $uq->fetchAll(PDO::FETCH_ASSOC);

// Automatisations (enrichies de libellés FR)
$autos = array_map(function ($a) {
    return [
        'id' => (int)$a['id'],
        'label' => $a['label'],
        'trigger_type' => $a['trigger_type'],
        'trigger_label' => pilotage_trigger_label($a['trigger_type']),
        'trigger_status' => $a['trigger_status'],
        'condition_json' => $a['condition_json'],
        'action_type' => $a['action_type'],
        'action_label' => pilotage_action_label($a['action_type']),
        'target_task_id' => $a['target_task_id'] ? (int)$a['target_task_id'] : null,
        'target_task_name' => $a['target_task_name'],
        'assigned_user_rule' => $a['assigned_user_rule'],
        'delay_label' => pilotage_delay_label($a['delay_value'] !== null ? (int)$a['delay_value'] : null, $a['delay_unit']),
        'delay_value' => $a['delay_value'],
        'delay_unit' => $a['delay_unit'],
        'exec_mode' => $a['exec_mode'],
        'exec_mode_label' => pilotage_exec_mode_label($a['exec_mode']),
        'is_active' => (int)$a['is_active'] === 1,
        'last_run_at' => $a['last_run_at'],
        'last_run_status' => $a['last_run_status'],
        'last_run_error' => $a['last_run_error'],
    ];
}, pilotage_automations_for_task($pdo, $taskId));

// Contrôle préalable (readiness)
$readiness = pilotage_task_readiness($pdo, $taskId);

// Dossiers en cours (actions réelles non clôturées)
$ic = $pdo->prepare("SELECT COUNT(*) FROM pilotage_task_instances WHERE task_id=? AND status NOT IN ('completed','cancelled','valide_definitif')");
$ic->execute([$taskId]);
$nbInstances = (int)$ic->fetchColumn();

$canEdit = function_exists('pilotage_can_edit') && pilotage_can_edit();
$canValidate = function_exists('pilotage_can_validate') && pilotage_can_validate();
$canAssign = in_array((int)current_role_id(), [1, 2, 7], true) || is_super_admin();

echo json_encode([
    'ok' => true,
    'task' => [
        'id' => (int)$task['id'],
        'name' => $task['name'],
        'category_name' => $task['category_name'],
        'service_name' => $task['service_name'],
        'objective' => $task['objective'],
        'expected_result' => $task['expected_result'] ?? null,
        'entry_conditions' => $task['entry_conditions'] ?? null,
        'short_description' => $task['short_description'],
        'required_level' => $task['required_level'],
        'priority_level' => $task['priority_level'] ?? 'normal',
        'validated_at' => $task['validated_at'] ?? null,
        'updated_at' => $task['updated_at'] ?? null,
        'frequency_type' => $task['frequency_type'],
        'frequency_value' => $task['frequency_value'],
        'trigger_event' => $task['trigger_event'],
        'estimated_duration_minutes' => $task['estimated_duration_minutes'],
        'documentation_status' => $task['documentation_status'],
        'automation_level' => $task['automation_level'],
        'procedure_text' => $task['procedure_text'],
        'mbi_journey' => (function () use ($task) {
            $j = $task['mbi_journey_json'] ?? null;
            if (!$j) return null; $d = json_decode($j, true); return is_array($d) ? $d : null;
        })(),
        'legal_notes' => $task['legal_notes'],
        'accounting_notes' => $task['accounting_notes'],
        'errors_to_avoid' => $task['errors_to_avoid'],
        'ged_entity_type' => $task['ged_entity_type'],
        'default_doc_template' => $task['default_doc_template'],
    ],
    'assignments' => $assignments,
    'postes' => array_map(fn($p) => $p + ['poste_label' => function_exists('pilotage_poste_label') ? pilotage_poste_label($p['poste_code']) : $p['poste_code']], $postes),
    'checklist' => $checklist,
    'steps' => $steps,
    'resources' => $resources,
    'legal' => $legal,
    'history' => $history,
    'versions' => $versions,
    'automations' => $autos,
    'readiness' => $readiness,
    'nb_instances' => $nbInstances,
    'users' => $socUsers,
    'perms' => ['edit' => $canEdit, 'validate' => $canValidate, 'assign' => $canAssign],
], JSON_UNESCAPED_UNICODE);
