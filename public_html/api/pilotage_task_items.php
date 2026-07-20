<?php
declare(strict_types=1);

/**
 * api/pilotage_task_items.php — CRUD checklist & étapes d'une mission (Tranche 3).
 * Body JSON : { task_id, kind:'checklist'|'step', op:'add'|'update'|'delete'|'reorder'|'duplicate_from', ... }
 * Toute modification de contenu déclenche le downgrade vert→jaune (versionné).
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/pilotage.php';
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

$taskId = (int)($body['task_id'] ?? 0);
$kind = (string)($body['kind'] ?? '');
$op = (string)($body['op'] ?? '');
if ($taskId <= 0 || !in_array($kind, ['checklist', 'step'], true)) pl_out(['ok' => false, 'error' => 'Paramètres invalides'], 400);

if ($soc <= 0 && is_super_admin()) { $st = $pdo->prepare("SELECT id_societe FROM pilotage_tasks WHERE id=?"); $st->execute([$taskId]); $soc = (int)$st->fetchColumn(); }
if (!pilotage_task_scope_ok($pdo, $taskId, $soc)) pl_out(['ok' => false, 'error' => 'Mission hors périmètre.'], 403);

$table = $kind === 'checklist' ? 'pilotage_task_checklist_items' : 'pilotage_task_steps';

try {
    $pdo->beginTransaction();
    $downgraded = pilotage_touch_yellow_if_green($pdo, $soc, $taskId);

    if ($op === 'add') {
        if ($kind === 'checklist') {
            $ord = (int)$pdo->query("SELECT COALESCE(MAX(display_order),0)+1 FROM pilotage_task_checklist_items WHERE task_id=" . (int)$taskId)->fetchColumn();
            $pdo->prepare("INSERT INTO pilotage_task_checklist_items (id_societe,task_id,label,description,is_mandatory,display_order) VALUES (?,?,?,?,?,?)")
                ->execute([$soc, $taskId, (string)($body['label'] ?? 'Nouvel élément'), $body['description'] ?? null, (int)($body['is_mandatory'] ?? 1), $ord]);
        } else {
            $ord = (int)$pdo->query("SELECT COALESCE(MAX(display_order),0)+1 FROM pilotage_task_steps WHERE task_id=" . (int)$taskId)->fetchColumn();
            $pdo->prepare("INSERT INTO pilotage_task_steps (id_societe,task_id,step_number,title,description,responsible_role,requires_validation,display_order) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$soc, $taskId, $ord, (string)($body['title'] ?? 'Nouvelle étape'), $body['description'] ?? null, $body['responsible_role'] ?? null, (int)($body['requires_validation'] ?? 0), $ord]);
        }
        $newId = (int)$pdo->lastInsertId();
        pilotage_history($pdo, $soc, $taskId, 'add_' . $kind, null, null, (string)$newId);
        $pdo->commit();
        pl_out(['ok' => true, 'id' => $newId, 'downgraded_to_yellow' => $downgraded]);
    }

    if ($op === 'update') {
        $id = (int)($body['id'] ?? 0);
        $own = $pdo->prepare("SELECT 1 FROM $table WHERE id=? AND task_id=?"); $own->execute([$id, $taskId]);
        if (!$own->fetchColumn()) { $pdo->rollBack(); pl_out(['ok' => false, 'error' => 'Élément introuvable'], 404); }
        $allowed = $kind === 'checklist' ? ['label', 'description', 'is_mandatory'] : ['title', 'description', 'responsible_role', 'requires_validation'];
        $set = []; $vals = [];
        foreach ((array)($body['fields'] ?? []) as $k => $v) { if (in_array($k, $allowed, true)) { $set[] = "`$k`=?"; $vals[] = ($v === '' ? null : $v); } }
        if (!$set) { $pdo->rollBack(); pl_out(['ok' => false, 'error' => 'Rien à modifier'], 400); }
        $vals[] = $id;
        $pdo->prepare("UPDATE $table SET " . implode(',', $set) . " WHERE id=?")->execute($vals);
        pilotage_history($pdo, $soc, $taskId, 'update_' . $kind, null, null, (string)$id);
        $pdo->commit();
        pl_out(['ok' => true, 'downgraded_to_yellow' => $downgraded]);
    }

    if ($op === 'delete') {
        $id = (int)($body['id'] ?? 0);
        $pdo->prepare("DELETE FROM $table WHERE id=? AND task_id=?")->execute([$id, $taskId]);
        pilotage_history($pdo, $soc, $taskId, 'delete_' . $kind, null, (string)$id, null);
        $pdo->commit();
        pl_out(['ok' => true, 'downgraded_to_yellow' => $downgraded]);
    }

    if ($op === 'reorder') {
        $ids = array_map('intval', (array)($body['ids'] ?? []));
        $pos = 1;
        $up = $pdo->prepare("UPDATE $table SET display_order=? " . ($kind === 'step' ? ", step_number=? " : '') . "WHERE id=? AND task_id=?");
        foreach ($ids as $id) {
            if ($kind === 'step') $up->execute([$pos, $pos, $id, $taskId]);
            else $up->execute([$pos, $id, $taskId]);
            $pos++;
        }
        pilotage_history($pdo, $soc, $taskId, 'reorder_' . $kind);
        $pdo->commit();
        pl_out(['ok' => true]);
    }

    if ($op === 'duplicate_from' && $kind === 'checklist') {
        $srcId = (int)($body['source_task_id'] ?? 0);
        if (!pilotage_task_scope_ok($pdo, $srcId, $soc)) { $pdo->rollBack(); pl_out(['ok' => false, 'error' => 'Source hors périmètre'], 403); }
        $src = $pdo->prepare("SELECT label,description,is_mandatory,display_order FROM pilotage_task_checklist_items WHERE task_id=? ORDER BY display_order");
        $src->execute([$srcId]);
        $base = (int)$pdo->query("SELECT COALESCE(MAX(display_order),0) FROM pilotage_task_checklist_items WHERE task_id=" . (int)$taskId)->fetchColumn();
        $ins = $pdo->prepare("INSERT INTO pilotage_task_checklist_items (id_societe,task_id,label,description,is_mandatory,display_order) VALUES (?,?,?,?,?,?)");
        $n = 0;
        foreach ($src->fetchAll(PDO::FETCH_ASSOC) as $r) { $ins->execute([$soc, $taskId, $r['label'], $r['description'], $r['is_mandatory'], ++$base]); $n++; }
        pilotage_history($pdo, $soc, $taskId, 'duplicate_checklist', null, (string)$srcId, (string)$n);
        $pdo->commit();
        pl_out(['ok' => true, 'copied' => $n, 'downgraded_to_yellow' => $downgraded]);
    }

    $pdo->rollBack();
    pl_out(['ok' => false, 'error' => 'op inconnue'], 400);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    pl_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
