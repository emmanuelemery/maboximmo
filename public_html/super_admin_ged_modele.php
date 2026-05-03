<?php
declare(strict_types=1);

/**
 * GED — Modèle général (arborescence catalogue complète)
 *
 * Page READ-ONLY qui dump l'intégralité du catalogue ged_level_codes (N1→N5)
 * sous forme d'arbre dépliable. Rafraîchie à chaque visite : reflète
 * AUTOMATIQUEMENT toutes les modifications faites via super_admin_ged_niveaux.php.
 *
 * Aucune sync Drive ici — voir Phase 1.5 + 1.6 (Service Account + helper
 * drive_create_folder + hook INSERT ged_folders → Drive).
 *
 * Réservée aux utilisateurs connectés (lecture seule).
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/ged_functions.php';
require_login();

$pdo = ged_pdo();
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// ─── Charge tous les niveaux en 1 requête ──────────────────────────
$rows = $pdo->query("
    SELECT level_number, parent_n1, parent_n2, parent_n3, parent_n4,
           code, label, position, is_active,
           COALESCE(is_entity_placeholder, 0) AS is_entity_placeholder
    FROM ged_level_codes
    ORDER BY level_number ASC, position ASC, label ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ─── Construit l'arbre en mémoire ──────────────────────────────────
// Structure : tree[code_n1] = [
//   'meta' => [...],
//   'children' => [code_n2 => ['meta'=>..., 'children' => [...]] ]
// ]
$tree = [];
foreach ($rows as $r) {
    $lvl = (int)$r['level_number'];
    $code = (string)$r['code'];

    if ($lvl === 1) {
        $tree[$code]['meta'] = $r;
        if (!isset($tree[$code]['children'])) $tree[$code]['children'] = [];
    } elseif ($lvl === 2) {
        $p1 = (string)$r['parent_n1'];
        if (!isset($tree[$p1])) continue;
        $tree[$p1]['children'][$code]['meta'] = $r;
        if (!isset($tree[$p1]['children'][$code]['children'])) $tree[$p1]['children'][$code]['children'] = [];
    } elseif ($lvl === 3) {
        $p1 = (string)$r['parent_n1']; $p2 = (string)$r['parent_n2'];
        if (!isset($tree[$p1]['children'][$p2])) continue;
        $tree[$p1]['children'][$p2]['children'][$code]['meta'] = $r;
        if (!isset($tree[$p1]['children'][$p2]['children'][$code]['children'])) $tree[$p1]['children'][$p2]['children'][$code]['children'] = [];
    } elseif ($lvl === 4) {
        $p1 = (string)$r['parent_n1']; $p2 = (string)$r['parent_n2']; $p3 = (string)$r['parent_n3'];
        if (!isset($tree[$p1]['children'][$p2]['children'][$p3])) continue;
        $tree[$p1]['children'][$p2]['children'][$p3]['children'][$code]['meta'] = $r;
        if (!isset($tree[$p1]['children'][$p2]['children'][$p3]['children'][$code]['children'])) $tree[$p1]['children'][$p2]['children'][$p3]['children'][$code]['children'] = [];
    } elseif ($lvl === 5) {
        $p1 = (string)$r['parent_n1']; $p2 = (string)$r['parent_n2']; $p3 = (string)$r['parent_n3']; $p4 = (string)$r['parent_n4'];
        if (!isset($tree[$p1]['children'][$p2]['children'][$p3]['children'][$p4])) continue;
        $tree[$p1]['children'][$p2]['children'][$p3]['children'][$p4]['children'][$code]['meta'] = $r;
    }
}

// ─── Stats globales ────────────────────────────────────────────────
$stats = ['n1' => 0, 'n2' => 0, 'n3' => 0, 'n4' => 0, 'n5' => 0, 'placeholders' => 0, 'inactifs' => 0];
foreach ($rows as $r) {
    $key = 'n' . (int)$r['level_number'];
    if (isset($stats[$key])) $stats[$key]++;
    if ((int)$r['is_entity_placeholder'] === 1) $stats['placeholders']++;
    if ((int)$r['is_active'] === 0) $stats['inactifs']++;
}

// ─── Sync Drive : status check ─────────────────────────────────────
$driveSyncStatus = 'not_configured';
$driveSyncMsg    = '⏳ Service Account Google Drive pas encore configuré (Phase 1.5)';
try {
    if (defined('GOOGLE_SERVICE_ACCOUNT_JSON') && is_file(GOOGLE_SERVICE_ACCOUNT_JSON)) {
        $driveSyncStatus = 'configured';
        $driveSyncMsg    = '✅ Service Account configuré, sync à activer (Phase 1.6)';
    }
} catch (Throwable) {}

$pageTitle    = 'GED — Modèle général';
$pageSubtitle = 'Ma GED Box · Vue arborescente complète du catalogue';
$layoutSidebar = 'sidebar_ged';
require_once __DIR__ . '/inc/agency_layout_top.php';
?>

<style>
  .gmod-wrap { max-width: 1200px; }
  .gmod-stats {
    display: grid; grid-template-columns: repeat(7, 1fr);
    gap: 10px; margin-bottom: 20px;
  }
  .gmod-stat {
    background: #fff; padding: 12px 14px; border-radius: 10px;
    box-shadow: 4px 4px 10px #c8c4be, -4px -4px 10px #fff;
    text-align: center;
  }
  .gmod-stat-label { font-size: 10px; text-transform: uppercase; color: #9a9690; letter-spacing: .04em; }
  .gmod-stat-value { font-size: 22px; font-weight: 800; color: #2c2a28; margin-top: 2px; }

  .gmod-drive-banner {
    padding: 14px 18px; border-radius: 10px; margin-bottom: 20px;
    font-size: 13px; display: flex; align-items: center; gap: 10px;
  }
  .gmod-drive-banner.warn { background: #fffbeb; color: #92400e; border-left: 4px solid #f59e0b; }
  .gmod-drive-banner.ok   { background: #ecfdf5; color: #065f46; border-left: 4px solid #16a34a; }

  .gmod-tree-controls {
    margin-bottom: 16px; display: flex; gap: 10px;
  }
  .gmod-btn {
    padding: 8px 16px; background: #fff; color: #4878a6;
    border: none; border-radius: 8px; cursor: pointer; font-size: 12px; font-weight: 700;
    box-shadow: 4px 4px 10px #c8c4be, -4px -4px 10px #fff;
  }
  .gmod-btn:hover { box-shadow: 2px 2px 5px #c8c4be, -2px -2px 5px #fff; }

  .gmod-tree {
    background: #fff; padding: 18px 22px; border-radius: 12px;
    box-shadow: 4px 4px 14px #c8c4be, -4px -4px 14px #fff;
    font-family: 'Sora', sans-serif;
  }
  .gmod-node {
    margin: 2px 0; border-radius: 6px;
  }
  .gmod-node-header {
    display: flex; align-items: center; gap: 8px;
    padding: 6px 10px; cursor: pointer;
    border-radius: 6px; transition: background .12s;
  }
  .gmod-node-header:hover { background: #f8f7f5; }
  .gmod-toggle {
    width: 16px; display: inline-block; text-align: center;
    color: #9a9690; font-size: 11px; user-select: none;
  }
  .gmod-toggle.empty { visibility: hidden; }
  .gmod-badge {
    display: inline-block; padding: 1px 7px; border-radius: 99px;
    font-size: 9px; font-weight: 700; min-width: 22px; text-align: center;
  }
  .gmod-badge.l1 { background: #dbeafe; color: #1e40af; }
  .gmod-badge.l2 { background: #fce7f3; color: #be185d; }
  .gmod-badge.l3 { background: #fef3c7; color: #92400e; }
  .gmod-badge.l4 { background: #dcfce7; color: #14532d; }
  .gmod-badge.l5 { background: #f3e8ff; color: #6b21a8; }
  .gmod-label { font-weight: 600; color: #2c2a28; font-size: 13.5px; }
  .gmod-label.inactive { opacity: .4; text-decoration: line-through; }
  .gmod-code {
    font-family: 'DM Mono', monospace; font-size: 10px; color: #9a9690;
    margin-left: 8px;
  }
  .gmod-icon-entity {
    font-size: 11px; color: #a855f7; margin-left: 4px;
  }
  .gmod-children {
    margin-left: 22px; border-left: 1px dashed #e0ddd9;
    padding-left: 12px;
  }
  .gmod-empty { color: #c8c4be; font-style: italic; font-size: 12px; padding: 4px 10px; }
  .gmod-count {
    background: #f1f5f9; color: #64748b;
    font-size: 9px; font-weight: 700; padding: 1px 6px; border-radius: 8px;
    margin-left: 6px;
  }
</style>

<div class="gmod-wrap">

  <h1 style="font-family:Sora,sans-serif;font-size:22px;color:#2c2a28;margin:0 0 6px">
    🗂️ GED — Modèle général (arborescence catalogue)
  </h1>
  <p style="color:#6b6660;font-size:13px;margin:0 0 22px">
    Vue read-only de tout le catalogue <code>ged_level_codes</code>. Rafraîchie à
    chaque visite : reflète automatiquement les modifications faites depuis la
    page <a href="/super_admin_ged_niveaux.php" style="color:#4878a6">Niveaux N1→N6</a>.
  </p>

  <!-- Stats -->
  <div class="gmod-stats">
    <div class="gmod-stat"><div class="gmod-stat-label">N1</div><div class="gmod-stat-value"><?= $stats['n1'] ?></div></div>
    <div class="gmod-stat"><div class="gmod-stat-label">N2</div><div class="gmod-stat-value"><?= $stats['n2'] ?></div></div>
    <div class="gmod-stat"><div class="gmod-stat-label">N3</div><div class="gmod-stat-value"><?= $stats['n3'] ?></div></div>
    <div class="gmod-stat"><div class="gmod-stat-label">N4</div><div class="gmod-stat-value"><?= $stats['n4'] ?></div></div>
    <div class="gmod-stat"><div class="gmod-stat-label">N5</div><div class="gmod-stat-value"><?= $stats['n5'] ?></div></div>
    <div class="gmod-stat"><div class="gmod-stat-label">📝 Entités</div><div class="gmod-stat-value" style="color:#a855f7"><?= $stats['placeholders'] ?></div></div>
    <div class="gmod-stat"><div class="gmod-stat-label">Inactifs</div><div class="gmod-stat-value" style="color:#9a9690"><?= $stats['inactifs'] ?></div></div>
  </div>

  <!-- Drive sync banner -->
  <div class="gmod-drive-banner <?= $driveSyncStatus === 'configured' ? 'ok' : 'warn' ?>">
    🔌 <strong>Sync Google Drive :</strong> <?= $driveSyncMsg ?>
    &nbsp;<small>(Drive sync = Phase 1.5 + 1.6 — voir <a href="/super_admin_ged_guide.php" target="_blank">Guide GED</a>)</small>
  </div>

  <!-- Controls -->
  <div class="gmod-tree-controls">
    <button class="gmod-btn" onclick="document.querySelectorAll('.gmod-children').forEach(el => el.style.display='block'); document.querySelectorAll('.gmod-toggle:not(.empty)').forEach(el => el.textContent='▼')">
      ⊞ Tout déplier
    </button>
    <button class="gmod-btn" onclick="document.querySelectorAll('.gmod-children').forEach(el => el.style.display='none'); document.querySelectorAll('.gmod-toggle:not(.empty)').forEach(el => el.textContent='▶')">
      ⊟ Tout replier
    </button>
    <button class="gmod-btn" onclick="window.print()">
      🖨️ Imprimer
    </button>
  </div>

  <!-- Arbre -->
  <div class="gmod-tree">
    <?php
    function gmod_render_node(array $node, int $level, callable $h): void {
        $meta = $node['meta'];
        $children = $node['children'] ?? [];
        $hasChildren = !empty($children);
        $isPlaceholder = (int)$meta['is_entity_placeholder'] === 1;
        $isInactive = (int)$meta['is_active'] === 0;
        ?>
        <div class="gmod-node">
          <div class="gmod-node-header" onclick="
            const ch = this.parentNode.querySelector(':scope > .gmod-children');
            const tg = this.querySelector('.gmod-toggle');
            if (!ch) return;
            const isOpen = ch.style.display !== 'none';
            ch.style.display = isOpen ? 'none' : 'block';
            if (tg && !tg.classList.contains('empty')) tg.textContent = isOpen ? '▶' : '▼';
          ">
            <span class="gmod-toggle <?= $hasChildren ? '' : 'empty' ?>"><?= $hasChildren ? '▼' : '·' ?></span>
            <span class="gmod-badge l<?= $level ?>">N<?= $level ?></span>
            <span class="gmod-label <?= $isInactive ? 'inactive' : '' ?>">
              <?= $h($meta['label']) ?><?php if ($isPlaceholder): ?><span class="gmod-icon-entity" title="Entité à instancier (DOSSIER, IMMEUBLE, BIEN, ...)">📝</span><?php endif; ?>
            </span>
            <span class="gmod-code"><?= $h($meta['code']) ?></span>
            <?php if ($hasChildren): ?>
              <span class="gmod-count"><?= count($children) ?></span>
            <?php endif; ?>
          </div>
          <?php if ($hasChildren): ?>
            <div class="gmod-children">
              <?php foreach ($children as $childCode => $childNode):
                gmod_render_node($childNode, $level + 1, $h);
              endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
        <?php
    }

    if (empty($tree)): ?>
      <div class="gmod-empty">Aucun niveau dans le catalogue. Va sur <a href="/super_admin_ged_niveaux.php">Niveaux</a> pour en ajouter.</div>
    <?php else:
      foreach ($tree as $n1Code => $n1Node):
        gmod_render_node($n1Node, 1, $h);
      endforeach;
    endif; ?>
  </div>

  <p style="margin-top:18px;font-size:11.5px;color:#9a9690;text-align:center">
    💡 Cette page lit en direct depuis <code>ged_level_codes</code>. Aucune
    cache. Modifications visibles instantanément après refresh.<br>
    Pour modifier : <a href="/super_admin_ged_niveaux.php" style="color:#4878a6">page Niveaux N1→N6</a>.
    Pour la sync Google Drive (à venir) : Phase 1.5 + 1.6.
  </p>

</div>

<?php require_once __DIR__ . '/inc/agency_layout_bottom.php';
