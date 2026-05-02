<?php
declare(strict_types=1);

/**
 * Ma GED Box V1.1 — Page Gestion des niveaux N1→N6 (super admin)
 *
 * Vue arborescente : clique N1 → liste N2 → clique → liste N3 → … → N5.
 * Permet d'ajouter/désactiver les niveaux qui pilotent la cascade UI de
 * super_admin_ged_import.php.
 *
 * AJOUT uniquement. Réservé super admin (id_role = 1).
 * Layout MBI standard (header.php).
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/ged_import_functions.php';
require_login();

$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Accès réservé super admin.</h1>');
}

$pdo = ged_pdo();
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$flash = null;

// ─── Action POST : ajout d'un niveau ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'add_level') {
            $level = (int)($_POST['level'] ?? 0);
            if ($level < 1 || $level > 5) throw new RuntimeException('level entre 1 et 5');
            $code = trim((string)($_POST['code'] ?? ''));
            $label = trim((string)($_POST['label'] ?? ''));
            if ($code === '' || $label === '') throw new RuntimeException('code et label requis');
            $code = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', $code));
            $st = $pdo->prepare("
                INSERT IGNORE INTO ged_level_codes
                    (tenant_id, level_number, parent_n1, parent_n2, parent_n3, parent_n4, code, label, position, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $st->execute([
                ged_current_tenant_id(),
                $level,
                $_POST['parent_n1'] ?? null,
                $_POST['parent_n2'] ?? null,
                $_POST['parent_n3'] ?? null,
                $_POST['parent_n4'] ?? null,
                $code,
                $label,
                (int)($_POST['position'] ?? 0),
            ]);
            $flash = ['type' => 'success', 'msg' => "✅ Niveau N{$level} '{$label}' ({$code}) ajouté."];
        }
        elseif ($action === 'toggle_active') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('id requis');
            $pdo->prepare("UPDATE ged_level_codes SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
            $flash = ['type' => 'success', 'msg' => "🔄 Niveau #{$id} bascule actif/inactif."];
        }
    } catch (Throwable $e) {
        $flash = ['type' => 'error', 'msg' => '❌ ' . $e->getMessage()];
    }
}

// ─── Lecture courante ─────────────────────────────────────────
$selN1 = (string)($_GET['n1'] ?? '');
$selN2 = (string)($_GET['n2'] ?? '');
$selN3 = (string)($_GET['n3'] ?? '');
$selN4 = (string)($_GET['n4'] ?? '');

function load_levels(PDO $pdo, int $level, array $parents): array
{
    $where = "level_number = ?";
    $params = [$level];
    foreach (['n1','n2','n3','n4'] as $i => $k) {
        if ($level > $i + 1) {
            $col = "parent_{$k}";
            $val = $parents[$k] ?? null;
            if ($val) { $where .= " AND `{$col}` = ?"; $params[] = $val; }
            else      { $where .= " AND (`{$col}` IS NULL OR `{$col}` = '')"; }
        }
    }
    $sql = "SELECT id, code, label, position, is_active FROM ged_level_codes
            WHERE {$where} ORDER BY position ASC, label ASC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$parents = ['n1' => $selN1, 'n2' => $selN2, 'n3' => $selN3, 'n4' => $selN4];
$cols = [];
$cols[1] = load_levels($pdo, 1, $parents);
if ($selN1) $cols[2] = load_levels($pdo, 2, $parents);
if ($selN2) $cols[3] = load_levels($pdo, 3, $parents);
if ($selN3) $cols[4] = load_levels($pdo, 4, $parents);
if ($selN4) $cols[5] = load_levels($pdo, 5, $parents);

$appLayout = true;
$pageTitle = 'GED — Niveaux N1→N6';
$bodyClass = '';
require_once __DIR__ . '/inc/header.php';
?>
<link rel="stylesheet" href="/css/ged_import.css?v=<?= @filemtime(__DIR__ . '/css/ged_import.css') ?: time() ?>">

<div class="gnv-wrap">
  <h1>🏷️ GED — Gestion des niveaux N1→N6</h1>
  <p class="sub">Cascade visuelle. Clique un niveau pour voir ses enfants. N6 reste libre (pas de référentiel obligatoire).</p>

  <?php if ($flash): ?>
    <div class="gimp-flash <?= $h($flash['type']) ?>"><?= $flash['msg'] ?></div>
  <?php endif; ?>

  <div style="display:grid;grid-template-columns:repeat(<?= count($cols) ?>, 1fr);gap:14px">
    <?php for ($lvl = 1; $lvl <= 5; $lvl++):
      if (!isset($cols[$lvl])) continue;
      $items = $cols[$lvl];
      $selected = ['n1'=>$selN1,'n2'=>$selN2,'n3'=>$selN3,'n4'=>$selN4,'n5'=>'']['n' . $lvl];
    ?>
      <div class="gnv-card">
        <h2>N<?= $lvl ?> <?= $lvl > 1 ? '<small style="font-weight:400;font-size:11px;color:#94a3b8">sous '.$h($parents['n'.($lvl-1)]).'</small>' : '' ?></h2>
        <ul class="gnv-list">
          <?php if (empty($items)): ?>
            <li class="gnv-empty">Aucun niveau — ajoute-en un ci-dessous.</li>
          <?php else:
            foreach ($items as $it):
              $childParams = $parents;
              $childParams['n' . $lvl] = $it['code'];
              for ($i = $lvl + 1; $i <= 4; $i++) $childParams['n' . $i] = ''; // reset descendants
              $url = '?' . http_build_query(array_filter([
                'n1' => $childParams['n1'], 'n2' => $childParams['n2'],
                'n3' => $childParams['n3'], 'n4' => $childParams['n4']
              ]));
              $isActive = $selected === $it['code'];
            ?>
              <li class="<?= $isActive ? 'is-active' : '' ?>" onclick="window.location='<?= $h($url) ?>'">
                <span><?= $h($it['label']) ?> <small><?= $h($it['code']) ?></small></span>
                <form method="POST" style="display:inline" onclick="event.stopPropagation()">
                  <input type="hidden" name="action" value="toggle_active">
                  <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                  <button type="submit" class="gimp-btn <?= $it['is_active'] ? 'gimp-btn-ghost' : 'gimp-btn-danger' ?>" style="padding:2px 6px;font-size:10px" title="Bascule actif/inactif">
                    <?= $it['is_active'] ? '✓' : '✗' ?>
                  </button>
                </form>
              </li>
            <?php endforeach;
          endif; ?>
        </ul>

        <!-- Form ajout d'un niveau -->
        <form method="POST" style="margin-top:14px;border-top:1px dashed #e5e7eb;padding-top:10px">
          <input type="hidden" name="action" value="add_level">
          <input type="hidden" name="level" value="<?= $lvl ?>">
          <?php if ($lvl > 1): ?><input type="hidden" name="parent_n1" value="<?= $h($selN1) ?>"><?php endif; ?>
          <?php if ($lvl > 2): ?><input type="hidden" name="parent_n2" value="<?= $h($selN2) ?>"><?php endif; ?>
          <?php if ($lvl > 3): ?><input type="hidden" name="parent_n3" value="<?= $h($selN3) ?>"><?php endif; ?>
          <?php if ($lvl > 4): ?><input type="hidden" name="parent_n4" value="<?= $h($selN4) ?>"><?php endif; ?>
          <input type="text" name="code" placeholder="CODE (ex: TRAVAUX)" required
                 style="width:100%;padding:5px 8px;border:1px solid #cbd5e1;border-radius:4px;font-size:11px;margin-bottom:4px">
          <input type="text" name="label" placeholder="Libellé humain" required
                 style="width:100%;padding:5px 8px;border:1px solid #cbd5e1;border-radius:4px;font-size:11px;margin-bottom:4px">
          <input type="number" name="position" placeholder="Position" value="50"
                 style="width:100%;padding:5px 8px;border:1px solid #cbd5e1;border-radius:4px;font-size:11px;margin-bottom:4px">
          <button type="submit" class="gimp-btn gimp-btn-primary" style="width:100%;padding:5px;font-size:11px">+ Ajouter N<?= $lvl ?></button>
        </form>
      </div>
    <?php endfor; ?>
  </div>

  <div style="margin-top:20px;padding:12px 16px;background:#f0f9ff;border:1px solid #67e8f9;border-radius:8px;font-size:12px;color:#0c4a6e">
    💡 <strong>N6 = libre.</strong> Pas de référentiel à gérer ici, l'utilisateur le saisit librement
    dans la modal d'import (suggestions sauvegardées pour réutilisation future).
  </div>

  <p style="text-align:center;margin-top:18px;font-size:12px;color:#64748b">
    → <a href="/super_admin_ged_import.php" style="color:#0369a1">📥 Aller à la page d'import</a>
  </p>
</div>
