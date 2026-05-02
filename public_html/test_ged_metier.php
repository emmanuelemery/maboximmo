<?php
declare(strict_types=1);

/**
 * Page de test GED métier — Scénario complet propriétaire → bien → locataire → document.
 *
 * Cette page démontre :
 *   1. Création d'un propriétaire FICTIF + instanciation TPL_PROPRIETAIRE
 *      sous 05_GESTION_LOCATIVE/01_PROPRIETAIRES → 5 sous-dossiers auto
 *   2. Création d'un bien FICTIF + instanciation TPL_BIEN sous le proprio
 *      → 4 sous-dossiers auto
 *   3. Création d'un locataire FICTIF + instanciation TPL_LOCATAIRE sous le bien
 *      → 7 sous-dossiers auto
 *   4. Création d'un document FICTIF (BAIL) lié simultanément aux 3 entités
 *      via ged_document_links → apparition dans 3 vues (proprio + bien + loc)
 *
 * Les "entités" ici sont fictives (juste des id arbitraires), aucun INSERT
 * dans biens/proprietaires/tiers réels. Pas d'effet de bord sur les données.
 *
 * Bouton "🧹 Nettoyer le test" pour rollback complet (supprime les
 * dossiers et documents créés par cette page de test).
 *
 * AJOUT uniquement — ne touche aucune page existante.
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/ged_functions.php';
require_login();

$pdo = ged_pdo();
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// ─── Constantes du scénario de test (entités fictives) ────────
const TEST_PROPRIO_ID    = 999991;
const TEST_PROPRIO_LABEL = 'TEST DUPONT Jean';
const TEST_IMMEUBLE_ID   = 999990;
const TEST_IMMEUBLE_LABEL = '12 Rue Victor Hugo Lyon';
const TEST_BIEN_ID       = 999992;
const TEST_BIEN_LABEL    = 'Appartement T3 - 3e étage';
const TEST_LOC_ID        = 999993;
const TEST_LOC_LABEL     = 'TEST MARTIN Sophie';
const TEST_DOC_NAME      = 'Bail signé 2026 — TEST';

$flash = null;

// ─── Action ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = (string)($_POST['action'] ?? '');

        if ($action === 'run') {
            // 1. Trouver le dossier 05_GESTION_LOCATIVE / 01_PROPRIETAIRES
            $st = $pdo->prepare("SELECT id FROM ged_folders WHERE slug = '01_proprietaires'
                                 AND parent_id = (SELECT id FROM ged_folders WHERE slug = '05_gestion_locative' AND parent_id IS NULL LIMIT 1)
                                 LIMIT 1");
            $st->execute();
            $proprietairesFolderId = (int)($st->fetchColumn() ?: 0);
            if ($proprietairesFolderId === 0) {
                throw new RuntimeException("Dossier 05_GESTION_LOCATIVE/01_PROPRIETAIRES introuvable. Applique d'abord les migrations 20260502_ged_v1_05 et 20260502_ged_v1_07.");
            }

            // 2. Instanciate TPL_PROPRIETAIRE
            $proprioRoot = ged_instantiate_template_for_entity('TPL_PROPRIETAIRE', $proprietairesFolderId,
                TEST_PROPRIO_LABEL, 'proprietaire', TEST_PROPRIO_ID);

            // 3. Trouve le sous-dossier "05_IMMEUBLES" du propriétaire
            $st = $pdo->prepare("SELECT id FROM ged_folders WHERE parent_id = ? AND slug = '05_immeubles' LIMIT 1");
            $st->execute([$proprioRoot]);
            $immeublesFolderId = (int)($st->fetchColumn() ?: 0);
            if ($immeublesFolderId === 0) throw new RuntimeException("Sous-dossier 05_immeubles du propriétaire introuvable (applique migration 20260502_ged_v1_08_immeuble_template)");

            // 4. Instanciate TPL_IMMEUBLE sous 05_immeubles (NOUVEAU NIVEAU)
            $immeubleRoot = ged_instantiate_template_for_entity('TPL_IMMEUBLE', $immeublesFolderId,
                TEST_IMMEUBLE_LABEL, 'immeuble', TEST_IMMEUBLE_ID);

            // 5. Trouve le sous-dossier "05_BIENS" de l'immeuble
            $st = $pdo->prepare("SELECT id FROM ged_folders WHERE parent_id = ? AND slug = '05_biens' LIMIT 1");
            $st->execute([$immeubleRoot]);
            $biensFolderId = (int)($st->fetchColumn() ?: 0);
            if ($biensFolderId === 0) throw new RuntimeException("Sous-dossier 05_biens de l'immeuble introuvable");

            // 6. Instanciate TPL_BIEN sous 05_biens (de l'immeuble)
            $bienRoot = ged_instantiate_template_for_entity('TPL_BIEN', $biensFolderId,
                TEST_BIEN_LABEL, 'bien', TEST_BIEN_ID);

            // 7. Trouve "04_LOCATAIRES" du bien
            $st = $pdo->prepare("SELECT id FROM ged_folders WHERE parent_id = ? AND slug = '04_locataires' LIMIT 1");
            $st->execute([$bienRoot]);
            $locatairesFolderId = (int)($st->fetchColumn() ?: 0);
            if ($locatairesFolderId === 0) throw new RuntimeException("Sous-dossier 04_locataires du bien introuvable");

            // 8. Instanciate TPL_LOCATAIRE
            $locRoot = ged_instantiate_template_for_entity('TPL_LOCATAIRE', $locatairesFolderId,
                TEST_LOC_LABEL, 'locataire', TEST_LOC_ID);

            // 9. Trouve "02_BAIL" du locataire (sous-dossier où ranger le doc)
            $st = $pdo->prepare("SELECT id FROM ged_folders WHERE parent_id = ? AND slug = '02_bail' LIMIT 1");
            $st->execute([$locRoot]);
            $bailFolderId = (int)($st->fetchColumn() ?: 0);

            // 10. Crée un doc FICTIF (pas de fichier physique)
            $canonical = ged_generate_canonical_name(
                'BAIL', '2026-05-01', TEST_IMMEUBLE_LABEL . ' ' . TEST_BIEN_LABEL, 'RE', 'EM'
            );
            $uuid = ged_generate_uuid();
            $pdo->prepare("
                INSERT INTO ged_documents
                    (uuid, tenant_id, folder_id, name_display, name_canonical, name_file,
                     document_type, source_module, storage_provider, mime_type, size_bytes,
                     security_level, status, version, created_by)
                VALUES (?, ?, ?, ?, ?, ?, 'BAIL', 'GESTION_LOCATIVE', 'local', 'application/pdf', 0,
                        'interne', 'active', 1, ?)
            ")->execute([
                $uuid, ged_current_tenant_id(), $bailFolderId ?: null,
                TEST_DOC_NAME, $canonical, $canonical . '.pdf',
                ged_current_user_id(),
            ]);
            $docId = (int)$pdo->lastInsertId();

            // 11. Lie le doc aux 4 entités (apparition dans 4 vues métier)
            ged_link_document_to_entity($docId, 'proprietaire', TEST_PROPRIO_ID,   'principal');
            ged_link_document_to_entity($docId, 'immeuble',     TEST_IMMEUBLE_ID,  'secondaire');
            ged_link_document_to_entity($docId, 'bien',         TEST_BIEN_ID,      'secondaire');
            ged_link_document_to_entity($docId, 'locataire',    TEST_LOC_ID,       'principal');

            $flash = ['type' => 'success', 'msg' => "✅ Scénario joué : 4 arborescences créées (proprio → immeuble → bien → locataire) + 1 document lié à 4 entités. Doc #{$docId}, canonical=<code>{$canonical}</code>"];
        }
        elseif ($action === 'cleanup') {
            // Cleanup HARD : DELETE physique pour permettre un vrai re-run propre
            // (l'idempotence par slug+parent réutilise sinon les vieux dossiers
            //  archivés et la nouvelle structure ne se construit pas correctement).

            // 1. Liens documents-entités fictives
            $pdo->prepare("DELETE FROM ged_document_links WHERE entity_type IN ('proprietaire','immeuble','bien','locataire')
                           AND entity_id IN (?, ?, ?, ?)")
                ->execute([TEST_PROPRIO_ID, TEST_IMMEUBLE_ID, TEST_BIEN_ID, TEST_LOC_ID]);

            // 2. Documents fictifs (DELETE physique car aucun fichier réel)
            $pdo->prepare("DELETE FROM ged_documents WHERE name_display = ?")->execute([TEST_DOC_NAME]);

            // 3. Identifie le dossier racine du proprio fictif + tous ses descendants
            $st = $pdo->prepare("SELECT id FROM ged_folders WHERE entity_type = 'proprietaire' AND entity_id = ?");
            $st->execute([TEST_PROPRIO_ID]);
            $proprioRoots = $st->fetchAll(PDO::FETCH_COLUMN);

            // 4. Récupère tous les descendants (parcours large via path_cache LIKE)
            $allFolderIds = [];
            foreach ($proprioRoots as $rootId) {
                $rootId = (int)$rootId;
                $allFolderIds[] = $rootId;
                // Récupère le slug du root pour faire un LIKE sur path_cache
                $stSlug = $pdo->prepare("SELECT path_cache FROM ged_folders WHERE id = ?");
                $stSlug->execute([$rootId]);
                $rootPath = (string)($stSlug->fetchColumn() ?: '');
                if ($rootPath !== '') {
                    $stDesc = $pdo->prepare("SELECT id FROM ged_folders WHERE path_cache LIKE ?");
                    $stDesc->execute([$rootPath . '/%']);
                    foreach ($stDesc->fetchAll(PDO::FETCH_COLUMN) as $did) $allFolderIds[] = (int)$did;
                }
            }

            // 5. Ajoute aussi tous les dossiers liés aux entités test (sécurité)
            $st = $pdo->prepare("SELECT id FROM ged_folders WHERE entity_type IN ('immeuble','bien','locataire')
                                 AND entity_id IN (?, ?, ?)");
            $st->execute([TEST_IMMEUBLE_ID, TEST_BIEN_ID, TEST_LOC_ID]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $did) $allFolderIds[] = (int)$did;
            $allFolderIds = array_unique($allFolderIds);

            // 6. DELETE physique des dossiers (en partant des plus profonds)
            if (!empty($allFolderIds)) {
                $placeholders = implode(',', array_fill(0, count($allFolderIds), '?'));
                $pdo->prepare("DELETE FROM ged_folders WHERE id IN ({$placeholders})")
                    ->execute($allFolderIds);
            }

            $flash = ['type' => 'success', 'msg' => "🧹 Cleanup HARD : " . count($allFolderIds) . " dossiers supprimés physiquement. Tu peux relancer le scénario propre."];
        }
    } catch (Throwable $e) {
        $flash = ['type' => 'error', 'msg' => '❌ ' . $e->getMessage()];
    }
}

// ─── Lecture des vues ─────────────────────────────────────────
$proprioDocs  = ged_get_documents_for_entity('proprietaire', TEST_PROPRIO_ID,  50);
$immeubleDocs = ged_get_documents_for_entity('immeuble',     TEST_IMMEUBLE_ID, 50);
$bienDocs     = ged_get_documents_for_entity('bien',         TEST_BIEN_ID,     50);
$locDocs      = ged_get_documents_for_entity('locataire',    TEST_LOC_ID,      50);

// Arbres dynamiques (les sous-arbres créés par le scénario)
$st = $pdo->prepare("SELECT id, name_display, slug, depth, path_cache FROM ged_folders
                     WHERE entity_type = 'proprietaire' AND entity_id = ? AND is_archived = 0
                     ORDER BY depth ASC, position ASC, id ASC");
$st->execute([TEST_PROPRIO_ID]);
$proprioFolders = $st->fetchAll(PDO::FETCH_ASSOC);

$st = $pdo->prepare("SELECT id, name_display, slug, depth, path_cache FROM ged_folders
                     WHERE entity_type = 'immeuble' AND entity_id = ? AND is_archived = 0
                     ORDER BY depth ASC, position ASC, id ASC");
$st->execute([TEST_IMMEUBLE_ID]);
$immeubleFolders = $st->fetchAll(PDO::FETCH_ASSOC);

$st = $pdo->prepare("SELECT id, name_display, slug, depth, path_cache FROM ged_folders
                     WHERE entity_type = 'bien' AND entity_id = ? AND is_archived = 0
                     ORDER BY depth ASC, position ASC, id ASC");
$st->execute([TEST_BIEN_ID]);
$bienFolders = $st->fetchAll(PDO::FETCH_ASSOC);

$st = $pdo->prepare("SELECT id, name_display, slug, depth, path_cache FROM ged_folders
                     WHERE entity_type = 'locataire' AND entity_id = ? AND is_archived = 0
                     ORDER BY depth ASC, position ASC, id ASC");
$st->execute([TEST_LOC_ID]);
$locFolders = $st->fetchAll(PDO::FETCH_ASSOC);

$appLayout = true;
$pageTitle = 'GED — Test métier';
$bodyClass = '';
@require_once __DIR__ . '/inc/header.php';
?>
<style>
  .gtm-wrap { max-width: 1300px; margin: 0 auto; padding: 24px 20px; }
  .gtm-wrap h1 { font-size: 22px; color: #0f172a; margin: 0 0 6px; }
  .gtm-wrap .sub { color: #64748b; font-size: 13px; margin: 0 0 18px; }
  .gtm-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px 18px; margin-bottom: 16px; }
  .gtm-card h2 { font-size: 14px; font-weight: 700; color: #0f172a; margin: 0 0 12px; padding-bottom: 8px; border-bottom: 1px solid #e5e7eb; }
  .gtm-toolbar { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
  .gtm-btn { padding: 10px 18px; border: none; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 700; font-family: inherit; }
  .gtm-btn.go { background: #0ea5e9; color: #fff; }
  .gtm-btn.go:hover { background: #0284c7; }
  .gtm-btn.clean { background: #fff; color: #dc2626; border: 1px solid #fecaca; }
  .gtm-flash { padding: 12px 16px; border-radius: 10px; margin-bottom: 18px; font-size: 13px; }
  .gtm-flash.success { background: #f0fdf4; border-left: 4px solid #16a34a; color: #14532d; }
  .gtm-flash.error   { background: #fef2f2; border-left: 4px solid #dc2626; color: #991b1b; }
  .gtm-row { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 12px; }
  @media (max-width: 1400px) { .gtm-row { grid-template-columns: 1fr 1fr; } }
  @media (max-width: 800px)  { .gtm-row { grid-template-columns: 1fr; } }
  ul.gtm-tree, ul.gtm-tree ul { list-style: none; padding-left: 14px; margin: 0; font-size: 12.5px; line-height: 1.6; }
  ul.gtm-tree { padding-left: 0; }
  .gtm-folder { font-weight: 600; color: #0f172a; }
  .gtm-meta { font-family: monospace; font-size: 10px; color: #94a3b8; }
  table.gtm-doc-table { width: 100%; border-collapse: collapse; font-size: 12px; }
  table.gtm-doc-table th, table.gtm-doc-table td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #f1f5f9; }
  table.gtm-doc-table th { background: #f8fafc; font-weight: 600; color: #475569; font-size: 11px; }
  .gtm-doc-name { font-weight: 700; color: #0f172a; }
  .gtm-doc-canon { font-family: monospace; font-size: 10px; color: #64748b; }
  .gtm-badge { display: inline-block; padding: 1px 7px; border-radius: 99px; font-size: 10px; font-weight: 700; }
  .gtm-badge.principal  { background: #dbeafe; color: #1e40af; }
  .gtm-badge.secondaire { background: #f1f5f9; color: #475569; }
  .gtm-warn { background: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px 16px; border-radius: 8px; color: #92400e; font-size: 13px; margin-bottom: 16px; }
</style>

<div class="gtm-wrap">
  <h1>🧪 GED — Test métier (propriétaire → immeuble → bien → locataire → document)</h1>
  <p class="sub">Démonstration : 1 document apparaît dans 4 vues métier différentes via <code>ged_document_links</code> (zéro duplication).</p>

  <?php if ($flash): ?>
    <div class="gtm-flash <?= $h($flash['type']) ?>"><?= $flash['msg'] /* déjà secured */ ?></div>
  <?php endif; ?>

  <div class="gtm-warn">
    ℹ️ Ce test crée 4 entités <strong>fictives</strong> (proprio 999991, immeuble 999990, bien 999992, locataire 999993) et 1 document factice. Aucun fichier physique. Aucun INSERT dans biens/proprietaires/tiers réels. Cleanup possible via le bouton ci-dessous.
  </div>

  <div class="gtm-toolbar">
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="run">
      <button type="submit" class="gtm-btn go">▶️ Jouer le scénario</button>
    </form>
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="cleanup">
      <button type="submit" class="gtm-btn clean" onclick="return confirm('Archiver les données de test ?')">🧹 Nettoyer le test</button>
    </form>
    <a href="admin/admin_ged_arborescence.php" class="gtm-btn clean" style="text-decoration:none;color:#0369a1;border-color:#0ea5e9">⚙️ Voir l'admin</a>
  </div>

  <div class="gtm-row">
    <!-- ─── Vue PROPRIÉTAIRE ──────────────────────────── -->
    <div class="gtm-card">
      <h2>👤 Vue Propriétaire #<?= TEST_PROPRIO_ID ?> (<?= $h(TEST_PROPRIO_LABEL) ?>)</h2>
      <strong style="font-size:12px;color:#475569">Arborescence :</strong>
      <ul class="gtm-tree">
        <?php foreach ($proprioFolders as $f): ?>
          <li>
            <span style="color:#cbd5e1"><?= str_repeat('— ', (int)$f['depth']) ?></span>
            <span class="gtm-folder"><?= $h($f['name_display']) ?></span>
            <span class="gtm-meta">  · <?= $h($f['path_cache']) ?></span>
          </li>
        <?php endforeach; ?>
        <?php if (empty($proprioFolders)): ?>
          <li style="color:#94a3b8;font-style:italic">Aucune. Lance le scénario.</li>
        <?php endif; ?>
      </ul>
      <strong style="font-size:12px;color:#475569;display:block;margin-top:12px">📄 Documents liés (<?= count($proprioDocs) ?>) :</strong>
      <table class="gtm-doc-table">
        <tbody>
          <?php foreach ($proprioDocs as $d): ?>
            <tr>
              <td>
                <div class="gtm-doc-name"><?= $h($d['name_display']) ?></div>
                <div class="gtm-doc-canon"><?= $h($d['name_canonical']) ?></div>
              </td>
              <td><span class="gtm-badge <?= $h($d['link_role']) ?>"><?= $h($d['link_role']) ?></span></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($proprioDocs)): ?>
            <tr><td colspan="2" style="color:#94a3b8;font-style:italic">Aucun document lié.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- ─── Vue IMMEUBLE ─────────────────────────────── -->
    <div class="gtm-card">
      <h2>🏢 Vue Immeuble #<?= TEST_IMMEUBLE_ID ?> (<?= $h(TEST_IMMEUBLE_LABEL) ?>)</h2>
      <strong style="font-size:12px;color:#475569">Arborescence :</strong>
      <ul class="gtm-tree">
        <?php foreach ($immeubleFolders as $f): ?>
          <li>
            <span style="color:#cbd5e1"><?= str_repeat('— ', (int)$f['depth']) ?></span>
            <span class="gtm-folder"><?= $h($f['name_display']) ?></span>
            <span class="gtm-meta">  · <?= $h($f['path_cache']) ?></span>
          </li>
        <?php endforeach; ?>
        <?php if (empty($immeubleFolders)): ?>
          <li style="color:#94a3b8;font-style:italic">Aucune. Lance le scénario.</li>
        <?php endif; ?>
      </ul>
      <strong style="font-size:12px;color:#475569;display:block;margin-top:12px">📄 Documents liés (<?= count($immeubleDocs) ?>) :</strong>
      <table class="gtm-doc-table">
        <tbody>
          <?php foreach ($immeubleDocs as $d): ?>
            <tr>
              <td>
                <div class="gtm-doc-name"><?= $h($d['name_display']) ?></div>
                <div class="gtm-doc-canon"><?= $h($d['name_canonical']) ?></div>
              </td>
              <td><span class="gtm-badge <?= $h($d['link_role']) ?>"><?= $h($d['link_role']) ?></span></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($immeubleDocs)): ?>
            <tr><td colspan="2" style="color:#94a3b8;font-style:italic">Aucun document lié.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- ─── Vue BIEN ─────────────────────────────────── -->
    <div class="gtm-card">
      <h2>🏠 Vue Bien #<?= TEST_BIEN_ID ?> (<?= $h(TEST_BIEN_LABEL) ?>)</h2>
      <strong style="font-size:12px;color:#475569">Arborescence :</strong>
      <ul class="gtm-tree">
        <?php foreach ($bienFolders as $f): ?>
          <li>
            <span style="color:#cbd5e1"><?= str_repeat('— ', (int)$f['depth']) ?></span>
            <span class="gtm-folder"><?= $h($f['name_display']) ?></span>
            <span class="gtm-meta">  · <?= $h($f['path_cache']) ?></span>
          </li>
        <?php endforeach; ?>
        <?php if (empty($bienFolders)): ?>
          <li style="color:#94a3b8;font-style:italic">Aucune. Lance le scénario.</li>
        <?php endif; ?>
      </ul>
      <strong style="font-size:12px;color:#475569;display:block;margin-top:12px">📄 Documents liés (<?= count($bienDocs) ?>) :</strong>
      <table class="gtm-doc-table">
        <tbody>
          <?php foreach ($bienDocs as $d): ?>
            <tr>
              <td>
                <div class="gtm-doc-name"><?= $h($d['name_display']) ?></div>
                <div class="gtm-doc-canon"><?= $h($d['name_canonical']) ?></div>
              </td>
              <td><span class="gtm-badge <?= $h($d['link_role']) ?>"><?= $h($d['link_role']) ?></span></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($bienDocs)): ?>
            <tr><td colspan="2" style="color:#94a3b8;font-style:italic">Aucun document lié.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- ─── Vue LOCATAIRE ────────────────────────────── -->
    <div class="gtm-card">
      <h2>👨‍👩 Vue Locataire #<?= TEST_LOC_ID ?> (<?= $h(TEST_LOC_LABEL) ?>)</h2>
      <strong style="font-size:12px;color:#475569">Arborescence :</strong>
      <ul class="gtm-tree">
        <?php foreach ($locFolders as $f): ?>
          <li>
            <span style="color:#cbd5e1"><?= str_repeat('— ', (int)$f['depth']) ?></span>
            <span class="gtm-folder"><?= $h($f['name_display']) ?></span>
            <span class="gtm-meta">  · <?= $h($f['path_cache']) ?></span>
          </li>
        <?php endforeach; ?>
        <?php if (empty($locFolders)): ?>
          <li style="color:#94a3b8;font-style:italic">Aucune. Lance le scénario.</li>
        <?php endif; ?>
      </ul>
      <strong style="font-size:12px;color:#475569;display:block;margin-top:12px">📄 Documents liés (<?= count($locDocs) ?>) :</strong>
      <table class="gtm-doc-table">
        <tbody>
          <?php foreach ($locDocs as $d): ?>
            <tr>
              <td>
                <div class="gtm-doc-name"><?= $h($d['name_display']) ?></div>
                <div class="gtm-doc-canon"><?= $h($d['name_canonical']) ?></div>
              </td>
              <td><span class="gtm-badge <?= $h($d['link_role']) ?>"><?= $h($d['link_role']) ?></span></td>
            </tr>
          <?php endforeach; ?>
          <?php if (empty($locDocs)): ?>
            <tr><td colspan="2" style="color:#94a3b8;font-style:italic">Aucun document lié.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="gtm-card">
    <h2>📊 Vérification : 1 doc, 4 vues, 0 duplication</h2>
    <p style="font-size:13px;color:#475569">
      Si le scénario a bien tourné, tu dois voir <strong>le même document</strong>
      "<?= $h(TEST_DOC_NAME) ?>" apparaître dans les 4 colonnes ci-dessus
      (proprio + immeuble + bien + locataire), avec un <code>name_canonical</code>
      identique. C'est la démonstration que <code>ged_document_links</code> permet
      une vue multi-entités SANS duplication du document en BDD.
    </p>
  </div>
</div>
