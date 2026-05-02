<?php
declare(strict_types=1);

/**
 * Page de test GED V1 — Vérification arborescence dynamique BDD.
 *
 * Affiche :
 *   - Racines (depth = 0) avec leurs slugs et name_canonical
 *   - Tout l'arbre sous forme indentée
 *   - path_cache calculé
 *   - depth de chaque niveau
 *   - nb de documents par dossier (si la table ged_documents est dispo)
 *   - Erreurs éventuelles (migrations manquantes, seed pas appliqué, etc.)
 *
 * Réservée aux utilisateurs connectés (lecture seule, pas d'action).
 *
 * AJOUT uniquement : ne touche aucune page ni table existante.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/ged_functions.php';
require_login();

$pdo = ged_pdo();
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// ─── Diagnostic préalable : tables présentes ? ────────────────
$expectedTables = [
    'ged_folders', 'ged_folder_levels', 'ged_folder_templates', 'ged_folder_template_nodes',
    'ged_documents', 'ged_document_links', 'ged_document_relations',
    'ged_tags', 'ged_document_tags', 'ged_index',
    'ged_permissions', 'ged_audit_log', 'ged_retention_rules', 'ged_arbo_sync_log',
];
$tablesStatus = [];
foreach ($expectedTables as $t) {
    try {
        $pdo->query("SELECT 1 FROM `{$t}` LIMIT 1");
        $tablesStatus[$t] = true;
    } catch (Throwable) {
        $tablesStatus[$t] = false;
    }
}
$missingTables = array_keys(array_filter($tablesStatus, fn($v) => !$v));

// ─── Lecture des dossiers ──────────────────────────────────────
$nbFolders = 0; $nbRoot = 0;
$tree = [];
try {
    $nbFolders = (int)$pdo->query("SELECT COUNT(*) FROM ged_folders WHERE is_archived = 0")->fetchColumn();
    $nbRoot    = (int)$pdo->query("SELECT COUNT(*) FROM ged_folders WHERE is_archived = 0 AND parent_id IS NULL")->fetchColumn();
    $tree = ged_get_tree(ged_current_tenant_id(), false);
} catch (Throwable $e) {
    // tables pas créées
}

// ─── Compteur docs par dossier ────────────────────────────────
$docsByFolder = [];
try {
    $rows = $pdo->query("SELECT folder_id, COUNT(*) AS n FROM ged_documents
                         WHERE folder_id IS NOT NULL AND status <> 'deleted'
                         GROUP BY folder_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    $docsByFolder = $rows ?: [];
} catch (Throwable) {}

// ─── Audit récent ─────────────────────────────────────────────
$recentAudit = [];
try {
    $stmt = $pdo->query("SELECT id, action, target_type, target_id, message, created_at, actor_id
                         FROM ged_audit_log
                         ORDER BY id DESC LIMIT 20");
    $recentAudit = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {}

$appLayout = true;
$pageTitle = 'GED — Test arborescence';
$bodyClass = '';
@require_once __DIR__ . '/inc/header.php';
?>
<style>
  .gtest-wrap { max-width: 1200px; margin: 0 auto; padding: 24px 20px; font-family: system-ui, sans-serif; }
  .gtest-wrap h1 { font-size: 22px; color: #0f172a; margin: 0 0 6px; }
  .gtest-wrap .sub { color: #64748b; font-size: 13px; margin: 0 0 18px; }
  .gtest-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 18px; margin-bottom: 16px; }
  .gtest-card h2 { font-size: 14px; font-weight: 700; color: #0f172a; margin: 0 0 12px; padding-bottom: 8px; border-bottom: 1px solid #e5e7eb; }
  .gtest-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  @media (max-width: 800px) { .gtest-row { grid-template-columns: 1fr; } }
  .gtest-stat { padding: 10px 14px; background: #f8fafc; border-radius: 8px; font-size: 13px; color: #475569; }
  .gtest-stat strong { color: #0f172a; font-size: 18px; display: block; margin-bottom: 2px; }
  .gtest-table { width: 100%; border-collapse: collapse; font-size: 12px; }
  .gtest-table th, .gtest-table td { padding: 6px 10px; text-align: left; border-bottom: 1px solid #f1f5f9; }
  .gtest-table th { background: #f8fafc; font-weight: 600; color: #475569; }
  .gtest-table .ok  { color: #16a34a; font-weight: 700; }
  .gtest-table .ko  { color: #dc2626; font-weight: 700; }
  ul.gtest-tree, ul.gtest-tree ul { list-style: none; padding-left: 18px; margin: 0; }
  ul.gtest-tree { padding-left: 0; }
  .gtest-node { font-size: 13px; padding: 3px 6px; border-radius: 4px; }
  .gtest-node:hover { background: #f1f5f9; }
  .gtest-meta { font-family: monospace; font-size: 11px; color: #94a3b8; }
  .gtest-name { font-weight: 600; color: #0f172a; }
  .gtest-badge { display: inline-block; padding: 1px 7px; border-radius: 99px; font-size: 10px; font-weight: 700; margin-left: 6px; }
  .gtest-badge.docs   { background: #f0fdf4; color: #166534; border: 1px solid #86efac; }
  .gtest-badge.system { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
  .gtest-error { background: #fef2f2; border-left: 4px solid #dc2626; padding: 12px 16px; border-radius: 8px; color: #991b1b; font-size: 13px; margin-bottom: 16px; }
  .gtest-error code { font-family: monospace; }
  .gtest-ok { background: #f0fdf4; border-left: 4px solid #16a34a; padding: 12px 16px; border-radius: 8px; color: #14532d; font-size: 13px; margin-bottom: 16px; }
</style>

<div class="gtest-wrap">
  <h1>🧪 GED — Test arborescence</h1>
  <p class="sub">Vérifie que les migrations GED V1 sont appliquées et que l'arborescence se lit correctement.</p>

  <?php if (!empty($missingTables)): ?>
    <div class="gtest-error">
      ⚠️ <strong><?= count($missingTables) ?> table(s) manquante(s)</strong> :
      <code><?= $h(implode(', ', $missingTables)) ?></code><br>
      → Va sur <a href="admin/admin_migrations.php">admin/admin_migrations.php</a> et applique les migrations <code>20260502_ged_v1_*</code>.
    </div>
  <?php else: ?>
    <div class="gtest-ok">✅ Les 14 tables GED V1 sont présentes en BDD.</div>
  <?php endif; ?>

  <!-- ─── Stats ─────────────────────────────────────────── -->
  <div class="gtest-card">
    <h2>📊 Stats</h2>
    <div class="gtest-row">
      <div class="gtest-stat"><strong><?= $nbFolders ?></strong> dossier(s) actif(s)</div>
      <div class="gtest-stat"><strong><?= $nbRoot ?></strong> racine(s) (depth = 0)</div>
    </div>
  </div>

  <!-- ─── État des tables ───────────────────────────────── -->
  <div class="gtest-card">
    <h2>🗄️ État des tables</h2>
    <table class="gtest-table">
      <thead><tr><th>Table</th><th>Statut</th></tr></thead>
      <tbody>
        <?php foreach ($tablesStatus as $t => $ok): ?>
          <tr>
            <td><code><?= $h($t) ?></code></td>
            <td class="<?= $ok ? 'ok' : 'ko' ?>"><?= $ok ? '✓ présente' : '✗ MANQUANTE' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- ─── Arbre ─────────────────────────────────────────── -->
  <div class="gtest-card">
    <h2>📁 Arborescence</h2>
    <?php if (empty($tree)): ?>
      <div style="color:#94a3b8;font-style:italic;font-size:13px;">
        Aucun dossier. Le seed <code>20260502_ged_v1_05_seed_arborescence</code> a-t-il été appliqué ?
      </div>
    <?php else:
      $renderTree = static function (array $nodes, int $level = 0) use (&$renderTree, &$docsByFolder, $h): void {
        echo '<ul class="gtest-tree">';
        foreach ($nodes as $n) {
          $count = (int)($docsByFolder[$n['id']] ?? 0);
          ?>
          <li>
            <div class="gtest-node">
              <span style="color:#cbd5e1"><?= str_repeat('— ', $level) ?></span>
              <span class="gtest-name"><?= $h($n['name_display']) ?></span>
              <?php if ((int)$n['is_system'] === 1): ?><span class="gtest-badge system">SYS</span><?php endif; ?>
              <?php if ($count > 0): ?><span class="gtest-badge docs"><?= $count ?> doc<?= $count > 1 ? 's' : '' ?></span><?php endif; ?>
              <span class="gtest-meta">  · slug=<?= $h($n['slug']) ?> · depth=<?= (int)$n['depth'] ?> · path=<?= $h($n['path_cache']) ?> · canonical=<?= $h($n['name_canonical']) ?></span>
            </div>
            <?php if (!empty($n['children'])) $renderTree($n['children'], $level + 1); ?>
          </li>
          <?php
        }
        echo '</ul>';
      };
      $renderTree($tree);
    endif; ?>
  </div>

  <!-- ─── Audit récent ──────────────────────────────────── -->
  <div class="gtest-card">
    <h2>📜 Journal d'audit (20 dernières entrées)</h2>
    <?php if (empty($recentAudit)): ?>
      <div style="color:#94a3b8;font-style:italic;font-size:13px;">Aucune entrée d'audit pour l'instant.</div>
    <?php else: ?>
      <table class="gtest-table">
        <thead><tr><th>Date</th><th>Acteur</th><th>Action</th><th>Cible</th><th>Message</th></tr></thead>
        <tbody>
          <?php foreach ($recentAudit as $r): ?>
            <tr>
              <td class="gtest-meta"><?= $h($r['created_at']) ?></td>
              <td>#<?= (int)($r['actor_id'] ?? 0) ?></td>
              <td><code><?= $h($r['action']) ?></code></td>
              <td><?= $h(($r['target_type'] ?? '') . ' #' . ($r['target_id'] ?? '')) ?></td>
              <td><?= $h($r['message'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <p style="text-align:center;margin-top:20px;font-size:12px;color:#64748b">
    → <a href="admin/admin_ged_arborescence.php">⚙️ Aller à l'admin pour modifier l'arbre</a>
  </p>
</div>
