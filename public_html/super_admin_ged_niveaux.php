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
require_admin_or_super_admin();

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
            // V2.5 : insertion de '' au lieu de NULL pour que UNIQUE KEY uk_ged_level_codes_path
            // soit effectif (cf. migration 20260503_ged_v2_23_strict_uniqueness.php)
            $st->execute([
                (int)(ged_current_tenant_id() ?? 0),
                $level,
                trim((string)($_POST['parent_n1'] ?? '')),
                trim((string)($_POST['parent_n2'] ?? '')),
                trim((string)($_POST['parent_n3'] ?? '')),
                trim((string)($_POST['parent_n4'] ?? '')),
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
        elseif ($action === 'toggle_virtual') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('id requis');
            $pdo->prepare("UPDATE ged_level_codes SET is_virtual = 1 - is_virtual WHERE id = ?")->execute([$id]);
            $flash = ['type' => 'success', 'msg' => "🔮 Niveau #{$id} : virtual basculé."];
        }
        elseif ($action === 'set_business_group') {
            $id = (int)($_POST['id'] ?? 0);
            $bg = trim((string)($_POST['business_group'] ?? ''));
            if ($id <= 0) throw new RuntimeException('id requis');
            $allowed = ['', 'RH','COMPTA','BAILLEUR','SYNDIC','AGENCE','FOURNISSEURS','MARKETING','ADMIN'];
            if (!in_array($bg, $allowed, true)) throw new RuntimeException('business_group invalide');
            $pdo->prepare("UPDATE ged_level_codes SET business_group = NULLIF(?, '') WHERE id = ? AND level_number = 1")
                ->execute([$bg, $id]);
            $flash = ['type' => 'success', 'msg' => "📍 Groupe métier mis à jour pour N1 #{$id}."];
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
    $sql = "SELECT id, code, label, position, is_active,
                   COALESCE(business_group, '') AS business_group,
                   COALESCE(is_virtual, 0) AS is_virtual,
                   COALESCE(is_entity_placeholder, 0) AS is_entity_placeholder
            FROM ged_level_codes
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
require_once __DIR__ . '/inc/ged_inject_sidebar.php'; // V2.5 — sidebar GED + container
?>
<link rel="stylesheet" href="/css/ged_import.css?v=<?= @filemtime(__DIR__ . '/css/ged_import.css') ?: time() ?>">
<link rel="stylesheet" href="/css/ged_admin.css?v=<?= @filemtime(__DIR__ . '/css/ged_admin.css') ?: time() ?>">
<style>
  .gnvx-badge-bg {
    display:inline-block; margin-left:6px; padding:1px 6px; font-size:9px; font-weight:700;
    border-radius:8px; background:#0ea5e9; color:#fff; vertical-align:middle;
    text-transform:uppercase; letter-spacing:.04em;
  }
  .gnvx-badge-mini { font-size:11px; margin-left:4px; opacity:.7; }
  .gnvx-bg-form { margin:0 6px; }
  .gnvx-bg-select {
    font-size:10px; padding:1px 4px; border:1px solid #cbd5e1; border-radius:4px;
    background:#f8fafc; color:#0f172a; cursor:pointer; max-width:110px;
  }
  .gnvx-item.is-virtual {
    background: repeating-linear-gradient(45deg, transparent, transparent 6px, rgba(148,163,184,.08) 6px, rgba(148,163,184,.08) 12px);
    opacity: .75;
  }
  .gnvx-item.is-virtual .gnvx-item-label::after {
    content: " (virtuel)"; font-size:10px; color:#64748b; font-style:italic;
  }
</style>

<div class="gnvx-wrap">
  <h1>🏷️ GED — Gestion des niveaux N1→N6</h1>
  <p class="sub">Cascade visuelle. Drag &amp; drop pour réordonner ou changer de parent. N6 reste libre (saisi à l'import).</p>

  <?php if ($flash): ?>
    <div class="gnvx-flash <?= $h($flash['type']) ?>"><?= $flash['msg'] ?></div>
  <?php endif; ?>

  <div class="gnvx-toolbar">
    <small>📍 Cliquez un niveau pour explorer ses enfants. Glissez les items pour les réordonner. ⚙️ pour archiver, 🗑️ pour supprimer (si vide).</small>
    <span style="flex:1"></span>
    <button type="button" id="gnvx-btn-recalc" class="gnvx-btn-recalc" title="Recalcule tous les path_cache + depth dans ged_folders">♻️ Recalculer arborescence</button>
  </div>

  <div class="gnvx-cols">
    <?php for ($lvl = 1; $lvl <= 5; $lvl++):
      if (!isset($cols[$lvl])) {
        // Colonne vide (verrouillée tant qu'on n'a pas sélectionné le niveau parent)
        ?>
        <div class="gnvx-col">
          <div class="gnvx-col-header">
            <div class="gnvx-col-title">
              <span class="gnvx-badge-level gnvx-badge-level-<?= $lvl ?>">N<?= $lvl ?></span>
              <small>verrouillé</small>
            </div>
          </div>
          <div class="gnvx-locked">— Sélectionne un niveau N<?= $lvl - 1 ?> ci-contre —</div>
        </div>
        <?php
        continue;
      }
      $items = $cols[$lvl];
      $selected = ['n1'=>$selN1,'n2'=>$selN2,'n3'=>$selN3,'n4'=>$selN4,'n5'=>''][('n' . $lvl)];
      // Data-attributs de la liste : parent_n1..parent_n4 (utilisé par drag & drop pour le move)
      $listParents = '';
      for ($p = 1; $p < $lvl; $p++) {
        $listParents .= ' data-parent-n' . $p . '="' . $h($parents['n' . $p] ?? '') . '"';
      }
    ?>
      <div class="gnvx-col">
        <div class="gnvx-col-header">
          <div class="gnvx-col-title">
            <span class="gnvx-badge-level gnvx-badge-level-<?= $lvl ?>">N<?= $lvl ?></span>
            <?php if ($lvl > 1): ?>
              <small>sous <code><?= $h($parents['n' . ($lvl - 1)] ?? '') ?></code></small>
            <?php else: ?>
              <small>Modules métier racine</small>
            <?php endif; ?>
          </div>
          <small style="color:#94a3b8;font-size:10px"><?= count($items) ?> items</small>
        </div>
        <ul class="gnvx-list" data-level="<?= $lvl ?>"<?= $listParents ?>>
          <?php if (empty($items)): ?>
            <li class="gnvx-empty">Aucun niveau — ajoute-en un ci-dessous.</li>
          <?php else:
            foreach ($items as $it):
              $childParams = $parents;
              $childParams['n' . $lvl] = $it['code'];
              for ($i = $lvl + 1; $i <= 4; $i++) $childParams['n' . $i] = '';
              $url = '?' . http_build_query(array_filter([
                'n1' => $childParams['n1'], 'n2' => $childParams['n2'],
                'n3' => $childParams['n3'], 'n4' => $childParams['n4']
              ]));
              $isActive   = $selected === $it['code'];
              $isArchived = !((int)$it['is_active']);
              $isVirtual  = (int)($it['is_virtual'] ?? 0) === 1;
              $isPlaceholder = (int)($it['is_entity_placeholder'] ?? 0) === 1;
              $bg = (string)($it['business_group'] ?? '');
            ?>
              <li class="gnvx-item <?= $isActive ? 'is-active' : '' ?> <?= $isArchived ? 'is-archived' : '' ?> <?= $isVirtual ? 'is-virtual' : '' ?>"
                  data-id="<?= (int)$it['id'] ?>"
                  data-level="<?= $lvl ?>"
                  data-code="<?= $h($it['code']) ?>"
                  data-label="<?= $h($it['label']) ?>"
                  onclick="if(!event.target.closest('.gnvx-action-btn,.gnvx-drag-handle,.gnvx-bg-select')) window.location='<?= $h($url) ?>'">
                <span class="gnvx-drag-handle" title="Glisser pour réordonner">⋮⋮</span>
                <span class="gnvx-item-label">
                  <?= $h($it['label']) ?>
                  <?php if ($lvl === 1 && $bg !== ''): ?>
                    <span class="gnvx-badge-bg" title="Groupe métier UI"><?= $h($bg) ?></span>
                  <?php endif; ?>
                  <?php if ($isPlaceholder): ?><span class="gnvx-badge-mini" title="Entity placeholder (instance à nommer)">📁</span><?php endif; ?>
                  <?php if ($isVirtual): ?><span class="gnvx-badge-mini" title="Virtual (filtre UI, pas matérialisé)">🔮</span><?php endif; ?>
                </span>
                <span class="gnvx-item-code"><?= $h($it['code']) ?></span>
                <?php if ($lvl === 1): ?>
                  <form method="POST" class="gnvx-bg-form" onclick="event.stopPropagation()">
                    <input type="hidden" name="action" value="set_business_group">
                    <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                    <select name="business_group" class="gnvx-bg-select" onchange="this.form.submit()" title="Groupe métier UI">
                      <option value="">— groupe —</option>
                      <?php foreach (['RH','COMPTA','BAILLEUR','SYNDIC','AGENCE','FOURNISSEURS','MARKETING','ADMIN'] as $g): ?>
                        <option value="<?= $g ?>" <?= $bg === $g ? 'selected' : '' ?>><?= $g ?></option>
                      <?php endforeach; ?>
                    </select>
                  </form>
                <?php endif; ?>
                <span class="gnvx-item-actions">
                  <form method="POST" style="display:inline" onclick="event.stopPropagation()">
                    <input type="hidden" name="action" value="toggle_virtual">
                    <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                    <button type="submit" class="gnvx-action-btn" title="<?= $isVirtual ? 'Désactiver virtuel' : 'Marquer virtuel (filtre)' ?>"><?= $isVirtual ? '✦' : '🔮' ?></button>
                  </form>
                  <?php if ($isArchived): ?>
                    <button type="button" class="gnvx-action-btn" data-btn-action="unarchive" data-id="<?= (int)$it['id'] ?>" title="Désarchiver">✓</button>
                  <?php else: ?>
                    <button type="button" class="gnvx-action-btn" data-btn-action="archive"   data-id="<?= (int)$it['id'] ?>" title="Archiver">⚙️</button>
                  <?php endif; ?>
                  <button type="button" class="gnvx-action-btn" data-btn-action="delete" data-id="<?= (int)$it['id'] ?>" title="Supprimer (si vide)">🗑️</button>
                </span>
              </li>
            <?php endforeach;
          endif; ?>
        </ul>

        <!-- Form ajout d'un niveau -->
        <form method="POST" class="gnvx-add-form">
          <input type="hidden" name="action" value="add_level">
          <input type="hidden" name="level" value="<?= $lvl ?>">
          <?php if ($lvl > 1): ?><input type="hidden" name="parent_n1" value="<?= $h($selN1) ?>"><?php endif; ?>
          <?php if ($lvl > 2): ?><input type="hidden" name="parent_n2" value="<?= $h($selN2) ?>"><?php endif; ?>
          <?php if ($lvl > 3): ?><input type="hidden" name="parent_n3" value="<?= $h($selN3) ?>"><?php endif; ?>
          <?php if ($lvl > 4): ?><input type="hidden" name="parent_n4" value="<?= $h($selN4) ?>"><?php endif; ?>
          <input type="text" name="code" placeholder="CODE (ex: TRAVAUX)" required>
          <input type="text" name="label" placeholder="Libellé humain" required>
          <input type="number" name="position" placeholder="Position" value="50">
          <button type="submit">+ Ajouter N<?= $lvl ?></button>
        </form>
      </div>
    <?php endfor; ?>
  </div>

  <div class="gnvx-tip">
    💡 <strong>N6 = libre.</strong> Pas de référentiel à gérer ici, l'utilisateur le saisit librement
    dans la modal d'import (suggestions sauvegardées pour réutilisation future).
    <br>
    🖱️ <strong>Drag &amp; drop</strong> : glisse un item dans sa colonne pour réordonner. Glisse-le
    dans une autre colonne (sous un autre parent) pour le déplacer (changement de parent).
  </div>

  <p style="text-align:center;margin-top:18px;font-size:12px;color:#64748b">
    → <a href="/super_admin_ged_import.php" style="color:#0369a1">📥 Aller à la page d'import</a>
  </p>
</div>

<script src="/js/ged_admin_niveaux.js?v=<?= @filemtime(__DIR__ . '/js/ged_admin_niveaux.js') ?: time() ?>"></script>
