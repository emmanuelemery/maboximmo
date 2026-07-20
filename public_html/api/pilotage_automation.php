<?php
declare(strict_types=1);

/**
 * api/pilotage_automation.php — Gestion des règles d'automatisation d'une mission.
 * Body JSON : { automation_id, op, ... }
 *   op = toggle   : active/désactive (is_active)
 *   op = set_delay: modifie délai { delay_value, delay_unit }
 *   op = fire     : lance manuellement la règle (moteur sûr) sur un contexte de test
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/pilotage_automations.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function pl_out(array $a, int $code = 200): void { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pl_out(['ok' => false, 'error' => 'POST requis'], 405);
verify_csrf_any('pilotage');
if (!pilotage_can_edit()) pl_out(['ok' => false, 'error' => 'Droit insuffisant.'], 403);

$pdo = $GLOBALS['pdo'];
$soc = (int)(current_societe_id() ?? 0);
$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) pl_out(['ok' => false, 'error' => 'JSON invalide'], 400);

$autoId = (int)($body['automation_id'] ?? 0);
$op = (string)($body['op'] ?? '');
if ($autoId <= 0) pl_out(['ok' => false, 'error' => 'automation_id requis'], 400);

// scope : la règle appartient à une mission de la société
$row = $pdo->prepare("SELECT a.*, t.id_societe AS task_soc FROM pilotage_task_automations a
                      JOIN pilotage_tasks t ON t.id = a.source_task_id WHERE a.id=?");
$row->execute([$autoId]);
$auto = $row->fetch(PDO::FETCH_ASSOC);
if (!$auto) pl_out(['ok' => false, 'error' => 'Règle introuvable'], 404);
if ($soc > 0 && !is_super_admin() && (int)$auto['task_soc'] !== $soc) pl_out(['ok' => false, 'error' => 'Hors périmètre.'], 403);
$soc = (int)$auto['task_soc'];

try {
    if ($op === 'toggle') {
        $new = (int)$auto['is_active'] === 1 ? 0 : 1;
        $pdo->prepare("UPDATE pilotage_task_automations SET is_active=?, updated_at=NOW() WHERE id=?")->execute([$new, $autoId]);
        pl_out(['ok' => true, 'is_active' => (bool)$new]);
    }
    if ($op === 'set_delay') {
        $dv = isset($body['delay_value']) && $body['delay_value'] !== '' ? (int)$body['delay_value'] : null;
        $du = $body['delay_unit'] ?? null;
        $valid = ['minute', 'hour', 'day', 'business_day', 'week', 'month'];
        if ($du !== null && !in_array($du, $valid, true)) pl_out(['ok' => false, 'error' => 'Unité invalide'], 400);
        $pdo->prepare("UPDATE pilotage_task_automations SET delay_value=?, delay_unit=?, updated_at=NOW() WHERE id=?")->execute([$dv, $du, $autoId]);
        pl_out(['ok' => true]);
    }
    if ($op === 'fire') {
        // lancement manuel sur un contexte de test (non engageant)
        $ctx = is_array($body['context'] ?? null) ? $body['context'] : [];
        $ctx['trigger_status'] = $auto['trigger_status'] ?: ($ctx['trigger_status'] ?? '');
        $runs = pilotage_automation_fire($pdo, $soc, (int)$auto['source_task_id'], $auto['trigger_type'], $ctx);
        // ne garder que le run de CETTE règle
        $mine = array_values(array_filter($runs, fn($r) => (int)$r['automation_id'] === $autoId));
        pl_out(['ok' => true, 'runs' => $mine]);
    }
    pl_out(['ok' => false, 'error' => 'op inconnue'], 400);
} catch (Throwable $e) {
    pl_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
