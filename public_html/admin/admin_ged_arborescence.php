<?php
declare(strict_types=1);

/**
 * Ma GED Box V1 — Admin arborescence dynamique
 *
 * CRUD complet sur ged_folders : ajout / modification / archivage,
 * choix parent, ordre d'affichage, scope société/agence/service,
 * dossier système intouchable (sauf super admin).
 *
 * Boutons : "Ajouter racine", "Recalculer path_cache", "Resynchroniser".
 *
 * Réservé aux admins (id_role = 1) — interdit aux autres rôles.
 *
 * AJOUT uniquement : ne touche aucune page ni table existante.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/ged_functions.php';
require_login();

$roleId = (int)($_SESSION['id_role'] ?? 0);
if ($roleId !== 1) {
    http_response_code(403);
    exit('<h1>403 — Accès réservé aux administrateurs.</h1>');
}

$pdo = ged_pdo();
$csrf = csrf_token('admin_ged_arborescence');
$flash = null;

// ─── Actions POST ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf('admin_ged_arborescence');
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create') {
            $id = ged_create_folder([
                'parent_id'    => isset($_POST['parent_id']) && (int)$_POST['parent_id'] > 0 ? (int)$_POST['parent_id'] : null,
                'name_display' => (string)($_POST['name_display'] ?? ''),
                'slug'         => trim((string)($_POST['slug'] ?? '')) ?: null,
                'module'       => trim((string)($_POST['module'] ?? '')) ?: null,
                'scope'        => (string)($_POST['scope'] ?? 'global'),
                'societe_id'   => isset($_POST['societe_id']) && (int)$_POST['societe_id'] > 0 ? (int)$_POST['societe_id'] : null,
                'agence_id'    => isset($_POST['agence_id'])  && (int)$_POST['agence_id']  > 0 ? (int)$_POST['agence_id']  : null,
                'service_id'   => isset($_POST['service_id']) && (int)$_POST['service_id'] > 0 ? (int)$_POST['service_id'] : null,
                'position'     => isset($_POST['position'])   ? (int)$_POST['position']   : null,
            ]);
            $flash = ['type' => 'success', 'msg' => "✅ Dossier #{$id} créé."];
        }
        elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('id manquant');
            $changed = ged_update_folder($id, [
                'name_display' => (string)($_POST['name_display'] ?? ''),
                'slug'         => trim((string)($_POST['slug'] ?? '')) ?: null,
                'module'       => trim((string)($_POST['module'] ?? '')) ?: null,
                'scope'        => (string)($_POST['scope'] ?? 'global'),
                'parent_id'    => isset($_POST['parent_id']) && (int)$_POST['parent_id'] > 0 ? (int)$_POST['parent_id'] : null,
                'societe_id'   => isset($_POST['societe_id']) && (int)$_POST['societe_id'] > 0 ? (int)$_POST['societe_id'] : null,
                'agence_id'    => isset($_POST['agence_id'])  && (int)$_POST['agence_id']  > 0 ? (int)$_POST['agence_id']  : null,
                'service_id'   => isset($_POST['service_id']) && (int)$_POST['service_id'] > 0 ? (int)$_POST['service_id'] : null,
                'position'     => isset($_POST['position'])   ? (int)$_POST['position']   : null,
            ]);
            $flash = ['type' => $changed ? 'success' : 'warning',
                      'msg'  => $changed ? "✅ Dossier #{$id} mis à jour." : "Aucune modification."];
        }
        elseif ($action === 'archive') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('id manquant');
            ged_archive_folder($id);
            $flash = ['type' => 'success', 'msg' => "🗄️ Dossier #{$id} archivé (soft delete)."];
        }
        elseif ($action === 'recalc') {
            $tenantArg = isset($_POST['tenant_id']) && $_POST['tenant_id'] !== ''
                ? (int)$_POST['tenant_id'] : null;
            $count = ged_recalculate_folder_tree($tenantArg);
            $flash = ['type' => 'success', 'msg' => "♻️ {$count} dossiers recalculés (path_cache + depth)."];
        }
        elseif ($action === 'resync') {
            // Trace uniquement ; la vraie sync Drive n'est pas encore branchée.
            $st = $pdo->prepare("INSERT INTO ged_arbo_sync_log (tenant_id, actor_id, sync_type, scope, status, finished_at, nb_folders_processed)
                                 VALUES (?, ?, 'resync_drive', 'placeholder', 'done', NOW(), 0)");
            $st->execute([ged_current_tenant_id(), ged_current_user_id()]);
            $flash = ['type' => 'warning', 'msg' => "ℹ️ Resync Drive : trace enregistrée. La synchro réelle Drive sera branchée plus tard."];
        }
        else {
            throw new RuntimeException("Action inconnue : {$action}");
        }
    } catch (Throwable $e) {
        $flash = ['type' => 'error', 'msg' => '❌ ' . $e->getMessage()];
    }
}

// ─── Lecture arbre + détection présence colonnes métier (ALTER 06) ─
$includeArchived = !empty($_GET['archived']);
$tree = ged_get_tree(ged_current_tenant_id(), $includeArchived);

// Charge folder_kind + entity_type pour chaque dossier (LEFT JOIN sur l'arbre déjà construit)
$folderMeta = [];
try {
    $rowsMeta = $pdo->query("SELECT id, folder_kind, is_virtual, entity_type, entity_id, storage_path
                              FROM ged_folders WHERE is_archived IN (0,1)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rowsMeta as $rm) $folderMeta[(int)$rm['id']] = $rm;
} catch (Throwable) {
    // colonnes ALTER 06 pas encore appliquées
}

// Compteur docs par dossier (LEFT JOIN agrégé)
$docsByFolder = [];
try {
    $rows = $pdo->query("SELECT folder_id, COUNT(*) AS n
                         FROM ged_documents
                         WHERE folder_id IS NOT NULL AND status <> 'deleted'
                         GROUP BY folder_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    $docsByFolder = $rows ?: [];
} catch (Throwable) {
    // Table pas encore créée
}

// Pour le select "parent" du formulaire d'ajout : liste plate ordonnée par path
$flatList = [];
$walk = static function (array $nodes, int $level = 0) use (&$walk, &$flatList): void {
    foreach ($nodes as $n) {
        $flatList[] = ['id' => (int)$n['id'], 'depth' => $level, 'name' => (string)$n['name_display'], 'slug' => (string)$n['slug'], 'is_archived' => (int)$n['is_archived']];
        if (!empty($n['children'])) $walk($n['children'], $level + 1);
    }
};
$walk($tree);

$appLayout = true;
$pageTitle = 'GED — Arborescence';
$bodyClass = '';
require_once __DIR__ . '/../inc/header.php';
require_once __DIR__ . '/../inc/ged_inject_sidebar.php'; // V2.5 — sidebar GED + container

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<style>
  .ged-wrap { max-width: 1300px; margin: 0 auto; padding: 24px 20px; }
  .ged-wrap h1 { font-size: 22px; color: #0f172a; margin: 0 0 6px; }
  .ged-wrap .sub { color: #64748b; font-size: 13px; margin: 0 0 18px; }
  .ged-flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 18px; font-size: 13px; }
  .ged-flash.success { background: #f0fdf4; border-left: 4px solid #16a34a; color: #14532d; }
  .ged-flash.warning { background: #fffbeb; border-left: 4px solid #f59e0b; color: #92400e; }
  .ged-flash.error   { background: #fef2f2; border-left: 4px solid #dc2626; color: #991b1b; }
  .ged-toolbar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
  .ged-btn { padding: 8px 14px; border-radius: 8px; background: #0ea5e9; color: #fff; border: none; font-size: 12px; font-weight: 700; cursor: pointer; font-family: inherit; }
  .ged-btn:hover { background: #0284c7; }
  .ged-btn.ghost { background: #fff; color: #0369a1; border: 1px solid #0ea5e9; }
  .ged-btn.warning { background: #f59e0b; }
  .ged-btn.danger { background: #fff; color: #dc2626; border: 1px solid #fecaca; }
  .ged-grid { display: grid; grid-template-columns: 1.5fr 1fr; gap: 18px; }
  @media (max-width: 980px) { .ged-grid { grid-template-columns: 1fr; } }
  .ged-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 16px 18px; }
  .ged-card h2 { font-size: 14px; font-weight: 700; color: #0f172a; margin: 0 0 14px; padding-bottom: 8px; border-bottom: 1px solid #e5e7eb; }
  ul.ged-tree, ul.ged-tree ul { list-style: none; padding-left: 18px; margin: 0; }
  ul.ged-tree { padding-left: 0; }
  .ged-node { display: flex; align-items: center; gap: 8px; padding: 5px 8px; border-radius: 6px; font-size: 13px; line-height: 1.3; }
  .ged-node:hover { background: #f1f5f9; }
  .ged-node.archived { opacity: .55; text-decoration: line-through; }
  .ged-name { font-weight: 600; color: #0f172a; }
  .ged-slug { font-family: monospace; font-size: 11px; color: #64748b; }
  .ged-badge { padding: 2px 8px; border-radius: 99px; font-size: 10px; font-weight: 700; }
  .ged-badge.system { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
  .ged-badge.module { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }
  .ged-badge.docs   { background: #f0fdf4; color: #166534; border: 1px solid #86efac; }
  .ged-badge.kind-business_view { background: #ecfeff; color: #0369a1; border: 1px solid #67e8f9; }
  .ged-badge.kind-storage_folder { background: #f5f3ff; color: #6d28d9; border: 1px solid #c4b5fd; }
  .ged-badge.kind-system          { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
  .ged-badge.virtual { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; font-style: italic; }
  .ged-badge.entity { background: #fef3c7; color: #78350f; border: 1px solid #fde68a; }
  .ged-row-actions a { font-size: 11px; color: #0369a1; text-decoration: none; margin-right: 8px; }
  .ged-row-actions a:hover { text-decoration: underline; }
  .ged-form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px; }
  .ged-form-row label { display: block; font-size: 11px; font-weight: 600; color: #475569; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .04em; }
  .ged-form-row input, .ged-form-row select { width: 100%; padding: 7px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px; box-sizing: border-box; }
  .ged-form-actions { display: flex; gap: 8px; margin-top: 12px; }
  .ged-tip { font-size: 11px; color: #64748b; margin-top: 6px; line-height: 1.5; }
  .ged-meta { font-size: 11px; color: #94a3b8; margin-left: 10px; }
</style>

<div class="ged-wrap">
  <h1>📁 GED — Arborescence</h1>
  <p class="sub">CRUD de l'arbre des répertoires (jusqu'à 6 niveaux). Source de vérité = BDD MaBoxImmo, Drive = stockage physique.</p>

  <?php if ($flash): ?>
    <div class="ged-flash <?= $h($flash['type']) ?>"><?= $flash['msg'] /* déjà sécurisé */ ?></div>
  <?php endif; ?>

  <div class="ged-toolbar">
    <form method="POST" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
      <input type="hidden" name="action" value="recalc">
      <button type="submit" class="ged-btn ghost" title="Recalcule path_cache + depth pour l'arbre">♻️ Recalculer path_cache</button>
    </form>
    <form method="POST" style="display:inline">
      <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
      <input type="hidden" name="action" value="resync">
      <button type="submit" class="ged-btn ghost" title="Trace une demande de resync Drive (placeholder, branchement réel à venir)">🔄 Resynchroniser arborescence</button>
    </form>
    <a href="?<?= $includeArchived ? '' : 'archived=1' ?>" class="ged-btn ghost">
      <?= $includeArchived ? '👁️ Masquer archivés' : '🗄️ Voir aussi les archivés' ?>
    </a>
    <a href="../test_ged_arborescence.php" class="ged-btn ghost" target="_blank">🧪 Page de test</a>
  </div>

  <div class="ged-grid">
    <!-- ─── Arbre ────────────────────────────────────────── -->
    <div class="ged-card">
      <h2>Arbre des répertoires (<?= count($flatList) ?> dossier<?= count($flatList) > 1 ? 's' : '' ?>)</h2>
      <?php if (empty($tree)): ?>
        <div style="color:#94a3b8;font-size:13px;font-style:italic;">Aucun dossier. Le seed a-t-il été appliqué ?</div>
      <?php else:
        $renderTree = static function (array $nodes) use (&$renderTree, &$docsByFolder, &$folderMeta, $h): void {
          echo '<ul class="ged-tree">';
          foreach ($nodes as $n) {
            $isArch = (int)$n['is_archived'] === 1;
            $isSys  = (int)$n['is_system'] === 1;
            $count  = (int)($docsByFolder[$n['id']] ?? 0);
            $meta   = $folderMeta[(int)$n['id']] ?? [];
            $kind   = (string)($meta['folder_kind'] ?? '');
            $isVirt = isset($meta['is_virtual']) ? (int)$meta['is_virtual'] : null;
            $eType  = (string)($meta['entity_type'] ?? '');
            $eId    = (int)($meta['entity_id'] ?? 0);
            ?>
            <li>
              <div class="ged-node<?= $isArch ? ' archived' : '' ?>">
                <span style="color:#94a3b8">└─</span>
                <span class="ged-name"><?= $h($n['name_display']) ?></span>
                <span class="ged-slug"><?= $h($n['slug']) ?></span>
                <?php if ($kind !== ''): ?><span class="ged-badge kind-<?= $h($kind) ?>" title="Type de dossier"><?= $h($kind) ?></span><?php endif; ?>
                <?php if ($isVirt === 1): ?><span class="ged-badge virtual" title="Dossier virtuel : pas matérialisé sur Drive">virtuel</span><?php endif; ?>
                <?php if ($eType !== '' && $eId > 0): ?><span class="ged-badge entity" title="Lié à une entité métier"><?= $h($eType) ?>#<?= $eId ?></span><?php endif; ?>
                <?php if ($isSys): ?><span class="ged-badge system" title="Dossier système, modification réservée au super admin">SYS</span><?php endif; ?>
                <?php if (!empty($n['module'])): ?><span class="ged-badge module"><?= $h($n['module']) ?></span><?php endif; ?>
                <?php if ($count > 0): ?><span class="ged-badge docs"><?= $count ?> doc<?= $count > 1 ? 's' : '' ?></span><?php endif; ?>
                <span class="ged-meta">depth=<?= (int)$n['depth'] ?> · path=<?= $h($n['path_cache']) ?></span>
                <span class="ged-row-actions" style="margin-left:auto">
                  <a href="#" data-edit-id="<?= (int)$n['id'] ?>"
                     data-name="<?= $h($n['name_display']) ?>"
                     data-slug="<?= $h($n['slug']) ?>"
                     data-module="<?= $h($n['module'] ?? '') ?>"
                     data-scope="<?= $h($n['scope']) ?>"
                     data-parent="<?= (int)($n['parent_id'] ?? 0) ?>"
                     data-position="<?= (int)$n['position'] ?>"
                     data-societe="<?= (int)($n['societe_id'] ?? 0) ?>"
                     data-agence="<?= (int)($n['agence_id'] ?? 0) ?>"
                     data-service="<?= (int)($n['service_id'] ?? 0) ?>">✏️ Modifier</a>
                  <?php if (!$isArch): ?>
                    <a href="#" data-archive-id="<?= (int)$n['id'] ?>" data-archive-name="<?= $h($n['name_display']) ?>" style="color:#dc2626">🗄️ Archiver</a>
                  <?php endif; ?>
                </span>
              </div>
              <?php if (!empty($n['children'])) $renderTree($n['children']); ?>
            </li>
            <?php
          }
          echo '</ul>';
        };
        $renderTree($tree);
      endif; ?>
    </div>

    <!-- ─── Formulaire ajout/édition ─────────────────────── -->
    <div class="ged-card">
      <h2 id="ged-form-title">➕ Ajouter un dossier</h2>
      <form method="POST" id="ged-folder-form">
        <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
        <input type="hidden" name="action" value="create" id="ged-form-action">
        <input type="hidden" name="id" value="" id="ged-form-id">

        <div class="ged-form-row">
          <div>
            <label>Parent</label>
            <select name="parent_id" id="ged-form-parent">
              <option value="">— (racine niveau 1) —</option>
              <?php foreach ($flatList as $f):
                if ($f['is_archived']) continue;
                $pad = str_repeat('— ', $f['depth']); ?>
                <option value="<?= $f['id'] ?>"><?= $h($pad . $f['name'] . ' [' . $f['slug'] . ']') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label>Position (ordre)</label>
            <input type="number" name="position" id="ged-form-position" placeholder="auto">
          </div>
        </div>

        <div class="ged-form-row">
          <div>
            <label>Nom humain (lisible)</label>
            <input type="text" name="name_display" id="ged-form-name" placeholder="Ex. Factures fournisseurs 2026" required>
          </div>
          <div>
            <label>Slug (URL/système)</label>
            <input type="text" name="slug" id="ged-form-slug" placeholder="auto si vide">
          </div>
        </div>

        <div class="ged-form-row">
          <div>
            <label>Module métier</label>
            <input type="text" name="module" id="ged-form-module" placeholder="DIRECTION, SYNDIC, RH...">
          </div>
          <div>
            <label>Scope</label>
            <select name="scope" id="ged-form-scope">
              <option value="global">global</option>
              <option value="societe">societe</option>
              <option value="agence">agence</option>
              <option value="service">service</option>
              <option value="entity">entity</option>
            </select>
          </div>
        </div>

        <div class="ged-form-row">
          <div>
            <label>Société (id)</label>
            <input type="number" name="societe_id" id="ged-form-societe">
          </div>
          <div>
            <label>Agence (id)</label>
            <input type="number" name="agence_id" id="ged-form-agence">
          </div>
        </div>

        <div class="ged-form-row">
          <div>
            <label>Service (id)</label>
            <input type="number" name="service_id" id="ged-form-service">
          </div>
        </div>

        <div class="ged-tip">
          💡 <strong>name_display</strong> = nom lisible modifiable. <strong>slug</strong> = nom URL/système (généré auto si vide).
          <strong>name_canonical</strong> est dérivé automatiquement du slug.
        </div>

        <div class="ged-form-actions">
          <button type="submit" class="ged-btn">💾 Enregistrer</button>
          <button type="button" class="ged-btn ghost" id="ged-form-reset">↺ Réinitialiser</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Form archive caché -->
<form method="POST" id="ged-archive-form" style="display:none">
  <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
  <input type="hidden" name="action" value="archive">
  <input type="hidden" name="id" value="" id="ged-archive-id">
</form>

<script>
(function () {
  const form     = document.getElementById('ged-folder-form');
  const fId      = document.getElementById('ged-form-id');
  const fAction  = document.getElementById('ged-form-action');
  const fTitle   = document.getElementById('ged-form-title');
  const fParent  = document.getElementById('ged-form-parent');
  const fName    = document.getElementById('ged-form-name');
  const fSlug    = document.getElementById('ged-form-slug');
  const fModule  = document.getElementById('ged-form-module');
  const fScope   = document.getElementById('ged-form-scope');
  const fSoc     = document.getElementById('ged-form-societe');
  const fAg      = document.getElementById('ged-form-agence');
  const fSvc     = document.getElementById('ged-form-service');
  const fPos     = document.getElementById('ged-form-position');

  function resetForm() {
    fId.value = '';
    fAction.value = 'create';
    fTitle.textContent = '➕ Ajouter un dossier';
    fParent.value = '';
    fName.value = '';
    fSlug.value = '';
    fModule.value = '';
    fScope.value = 'global';
    fSoc.value = '';
    fAg.value = '';
    fSvc.value = '';
    fPos.value = '';
  }
  document.getElementById('ged-form-reset').addEventListener('click', resetForm);

  // Édition : remplir le formulaire depuis les data-attributes
  document.querySelectorAll('[data-edit-id]').forEach(a => {
    a.addEventListener('click', e => {
      e.preventDefault();
      fId.value = a.dataset.editId;
      fAction.value = 'update';
      fTitle.textContent = '✏️ Modifier le dossier #' + a.dataset.editId;
      fParent.value = a.dataset.parent === '0' ? '' : a.dataset.parent;
      fName.value = a.dataset.name || '';
      fSlug.value = a.dataset.slug || '';
      fModule.value = a.dataset.module || '';
      fScope.value = a.dataset.scope || 'global';
      fSoc.value = a.dataset.societe === '0' ? '' : a.dataset.societe;
      fAg.value  = a.dataset.agence  === '0' ? '' : a.dataset.agence;
      fSvc.value = a.dataset.service === '0' ? '' : a.dataset.service;
      fPos.value = a.dataset.position || '';
      window.scrollTo({ top: form.offsetTop - 20, behavior: 'smooth' });
    });
  });

  // Archive
  document.querySelectorAll('[data-archive-id]').forEach(a => {
    a.addEventListener('click', e => {
      e.preventDefault();
      const id = a.dataset.archiveId;
      const name = a.dataset.archiveName || ('#' + id);
      if (!confirm('Archiver le dossier « ' + name + ' » ?\n\n(Soft delete : aucune suppression physique, le dossier reste consultable via "Voir aussi les archivés")')) return;
      document.getElementById('ged-archive-id').value = id;
      document.getElementById('ged-archive-form').submit();
    });
  });
})();
</script>
