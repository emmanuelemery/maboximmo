<?php
declare(strict_types=1);

/**
 * api/pilotage_declencheur.php — Référentiel PARTAGÉ des déclencheurs (collaboratif).
 * Tous les collaborateurs de la société voient et complètent la même liste (pas de doublon).
 *
 * GET  ?action=list[&service=gestion]      → { triggers:[{...,actions:[...]}] }
 * POST action=add_trigger    { label, service, icon? }
 * POST action=update_trigger { id, field, value }         (label|description|example_text|icon|service_slug|status)
 * POST action=delete_trigger { id }
 * POST action=add_action     { declencheur_id, label }
 * POST action=update_action  { id, field, value }         (label_libre|detail)
 * POST action=delete_action  { id }
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function pl_out(array $a, int $code = 200): void { http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$pdo = $GLOBALS['pdo'];
$soc = (int)(current_societe_id() ?? 0);
$uid = (int)current_user_id();

/* ---------- Lecture (partagée société) ---------- */
if (($_GET['action'] ?? '') === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $service = trim((string)($_GET['service'] ?? ''));
    $params = [$soc];
    $sql = "SELECT d.*, TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) AS author
            FROM pilotage_declencheurs d LEFT JOIN users u ON u.id = d.created_by
            WHERE d.id_societe = ? AND d.is_active = 1";
    if ($service !== '') { $sql .= " AND d.service_slug = ?"; $params[] = $service; }
    $sql .= " ORDER BY d.display_order, d.id DESC";
    $st = $pdo->prepare($sql); $st->execute($params);
    $triggers = $st->fetchAll(PDO::FETCH_ASSOC);

    // actions de chaque déclencheur (en une requête)
    $actionsBy = [];
    if ($triggers) {
        $ids = array_column($triggers, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $aq = $pdo->prepare("SELECT m.id, m.declencheur_id, m.label_libre, m.detail, m.task_id,
                                    TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) AS author
                             FROM pilotage_declencheur_missions m LEFT JOIN users u ON u.id = m.created_by
                             WHERE m.declencheur_id IN ($ph) ORDER BY m.display_order, m.id");
        $aq->execute($ids);
        foreach ($aq->fetchAll(PDO::FETCH_ASSOC) as $r) $actionsBy[$r['declencheur_id']][] = $r;
    }
    foreach ($triggers as &$t) { $t['actions'] = $actionsBy[$t['id']] ?? []; $t['nb_actions'] = count($t['actions']); }
    pl_out(['ok' => true, 'triggers' => $triggers]);
}

/* ---------- Écritures ---------- */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') pl_out(['ok' => false, 'error' => 'POST requis'], 405);
verify_csrf_any('pilotage');
$action = (string)($_POST['action'] ?? '');

// garde-fou société pour une entité
$ownTrigger = function (int $id) use ($pdo, $soc): bool {
    $s = $pdo->prepare("SELECT 1 FROM pilotage_declencheurs WHERE id=? AND id_societe=?"); $s->execute([$id, $soc]); return (bool)$s->fetchColumn();
};

try {
    if ($action === 'add_trigger') {
        $label = trim((string)($_POST['label'] ?? ''));
        if ($label === '') pl_out(['ok' => false, 'error' => 'Libellé requis'], 400);
        $service = trim((string)($_POST['service'] ?? 'gestion')) ?: 'gestion';
        $icon = trim((string)($_POST['icon'] ?? '')) ?: null;
        $ord = (int)$pdo->query("SELECT COALESCE(MAX(display_order),0)+1 FROM pilotage_declencheurs WHERE id_societe=" . (int)$soc)->fetchColumn();
        $pdo->prepare("INSERT INTO pilotage_declencheurs (id_societe,service_slug,label,icon,display_order,created_by) VALUES (?,?,?,?,?,?)")
            ->execute([$soc, $service, $label, $icon, $ord, $uid]);
        pl_out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    }

    if ($action === 'update_trigger') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$ownTrigger($id)) pl_out(['ok' => false, 'error' => 'Hors périmètre'], 403);
        $field = (string)($_POST['field'] ?? '');
        $allowed = ['label', 'description', 'example_text', 'icon', 'service_slug', 'status'];
        if (!in_array($field, $allowed, true)) pl_out(['ok' => false, 'error' => 'Champ invalide'], 400);
        $val = $_POST['value'] ?? '';
        $pdo->prepare("UPDATE pilotage_declencheurs SET `$field`=?, updated_at=NOW() WHERE id=? AND id_societe=?")
            ->execute([$val === '' ? null : $val, $id, $soc]);
        pl_out(['ok' => true]);
    }

    if ($action === 'delete_trigger') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$ownTrigger($id)) pl_out(['ok' => false, 'error' => 'Hors périmètre'], 403);
        $pdo->prepare("UPDATE pilotage_declencheurs SET is_active=0 WHERE id=? AND id_societe=?")->execute([$id, $soc]);
        pl_out(['ok' => true]);
    }

    if ($action === 'add_action') {
        $did = (int)($_POST['declencheur_id'] ?? 0);
        if (!$ownTrigger($did)) pl_out(['ok' => false, 'error' => 'Hors périmètre'], 403);
        $label = trim((string)($_POST['label'] ?? ''));
        if ($label === '') pl_out(['ok' => false, 'error' => 'Action requise'], 400);
        $ord = (int)$pdo->query("SELECT COALESCE(MAX(display_order),0)+1 FROM pilotage_declencheur_missions WHERE declencheur_id=" . (int)$did)->fetchColumn();
        $pdo->prepare("INSERT INTO pilotage_declencheur_missions (id_societe,declencheur_id,label_libre,display_order,created_by) VALUES (?,?,?,?,?)")
            ->execute([$soc, $did, $label, $ord, $uid]);
        pl_out(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    }

    if ($action === 'update_action') {
        $id = (int)($_POST['id'] ?? 0);
        $field = (string)($_POST['field'] ?? '');
        if (!in_array($field, ['label_libre', 'detail'], true)) pl_out(['ok' => false, 'error' => 'Champ invalide'], 400);
        // scope via jointure
        $chk = $pdo->prepare("SELECT 1 FROM pilotage_declencheur_missions WHERE id=? AND id_societe=?"); $chk->execute([$id, $soc]);
        if (!$chk->fetchColumn()) pl_out(['ok' => false, 'error' => 'Hors périmètre'], 403);
        $val = $_POST['value'] ?? '';
        $pdo->prepare("UPDATE pilotage_declencheur_missions SET `$field`=?, updated_at=NOW() WHERE id=? AND id_societe=?")
            ->execute([$val === '' ? null : $val, $id, $soc]);
        pl_out(['ok' => true]);
    }

    if ($action === 'delete_action') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM pilotage_declencheur_missions WHERE id=? AND id_societe=?")->execute([$id, $soc]);
        pl_out(['ok' => true]);
    }

    pl_out(['ok' => false, 'error' => 'action inconnue'], 400);
} catch (Throwable $e) {
    pl_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
