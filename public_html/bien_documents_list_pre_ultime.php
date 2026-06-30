<?php
/**
 * bien_documents_list.php
 *
 * Liste des documents GED d'un bien + zone upload rapide.
 * Refonte 2026-05-24 — style fiche 360° (sidebar + topbar + breadcrumb + cards),
 * cohérent avec bien_doc_360.php et bien_360.php.
 *
 * Affiche tous les docs liés via ged_document_links (entity_type=BIEN)
 * groupés par module GED. Clic sur un doc → bien_doc_360.php pour review/édit.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/fiche_360_layout.php';
require_login();

$pdo = $GLOBALS['pdo'];
$bienId = (int)($_GET['id'] ?? 0);
$flash  = (string)($_GET['msg'] ?? '');
$commitStatus = (string)($_GET['commit'] ?? '');
$newDocId = (int)($_GET['new_doc'] ?? 0);

if ($bienId <= 0) { http_response_code(400); exit('id requis'); }

if (!function_exists('bdl_html')) {
    function bdl_html(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

// ─── Lecture bien (avec immeuble pour le breadcrumb) ──────────────────
$st = $pdo->prepare("SELECT b.*, i.adresse_1 AS imm_adresse, i.nom_immeuble AS imm_nom
                     FROM biens b LEFT JOIN immeubles i ON i.id = b.id_immeuble
                     WHERE b.id = ?");
$st->execute([$bienId]);
$bien = $st->fetch(PDO::FETCH_ASSOC);
if (!$bien) { http_response_code(404); exit("Bien #$bienId introuvable"); }

// Scope check
$isAdmin = ((int)($_SESSION['id_role'] ?? 0) === 1);
if (!$isAdmin && !empty($bien['id_societe']) && (int)$bien['id_societe'] !== (int)($_SESSION['id_societe'] ?? 0)) {
    http_response_code(403); exit('Hors société');
}

// ─── Propriétaire pour breadcrumb ──
$proprioTiersId = (int)($bien['id_tiers'] ?? 0);
$proprioLabel = '';
if ($proprioTiersId > 0) {
    try {
        $st = $pdo->prepare("SELECT raison_sociale, nom, prenom FROM tiers WHERE id = ?");
        $st->execute([$proprioTiersId]);
        $t = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $proprioLabel = (string)($t['raison_sociale'] ?? '') ?: trim(($t['prenom'] ?? '') . ' ' . ($t['nom'] ?? ''));
    } catch (Throwable) {}
}

// ─── Documents liés via ged_document_links ──
$docs = [];
try {
    $st = $pdo->prepare("
        SELECT d.id, d.uuid, d.name_display, d.name_file, d.document_type, d.source_module,
               d.mime_type, d.size_bytes, d.created_at, d.folder_id, d.fluxbox_source_id,
               l.relation_type, l.is_validated, l.validated_at,
               f.name_display AS folder_name, f.slug AS folder_slug
        FROM ged_document_links l
        JOIN ged_documents d ON d.id = l.document_id
        LEFT JOIN ged_folders f ON f.id = d.folder_id
        WHERE l.entity_type = 'BIEN' AND l.entity_id = ?
          AND d.status = 'active'
        ORDER BY d.created_at DESC
    ");
    $st->execute([$bienId]);
    $docs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {}

// Stats
$nbDocs    = count($docs);
$nbValides = count(array_filter($docs, fn($d) => (int)($d['is_validated'] ?? 0) === 1));

// Grouper par source_module (TRANSACTION / GESTION / SYNDIC / ...)
$byModule = [];
foreach ($docs as $d) {
    $mod = $d['source_module'] ?: 'AUTRE';
    $byModule[$mod][] = $d;
}
$nbModules = count($byModule);

// Cherche le card_id en attente (carte fluxbox non encore commitée pour ce bien)
$cardsPending = [];
try {
    $st = $pdo->prepare("
        SELECT c.id, c.document_id, c.titre, c.statut, c.created_at, d.fichier_nom
        FROM fluxbox_cartes c
        JOIN fluxbox_documents d ON d.id = c.document_id
        WHERE c.statut IN ('pending','in_progress')
          AND JSON_EXTRACT(c.proposition_json, '$.bien_id') = ?
        ORDER BY c.id DESC LIMIT 20
    ");
    $st->execute([$bienId]);
    $cardsPending = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {}

// ─── Layout 360° ──
$pageTitle    = 'Documents · Bien #' . $bienId;
$pageSubtitle = 'GED · ' . $nbDocs . ' doc(s) · ' . $nbModules . ' module(s)';
$extraCss     = fiche360_css();
include __DIR__ . '/inc/agency_layout_top.php';
?>

<script>window.APP_BASE = <?= json_encode(rtrim(app_url('/'), '/')) ?>;</script>

<style>
/* ─── bien_documents_list (style 360°) ─── */
.bdl-flash { padding: 12px 16px; border-radius: 8px; margin-bottom: 14px; font-size: 13px;
    box-shadow: 2px 2px 6px #e3dfd8; display: flex; align-items: center; gap: 10px; }
.bdl-flash.ok { background: #d9f0db; color: #14532d; border-left: 4px solid #2d6a35; }
.bdl-flash.ko { background: #fecaca; color: #7f1d1d; border-left: 4px solid #b91c1c; }

/* Stats KPI */
.bdl-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 14px; }
.bdl-stat { background: #fff; padding: 14px 16px; border-radius: 10px; box-shadow: 2px 2px 6px #e3dfd8;
    border-left: 4px solid #D4A047; }
.bdl-stat .v { font-size: 26px; font-weight: 800; color: #2c2a28; line-height: 1; }
.bdl-stat .l { font-size: 10.5px; color: #7a766f; text-transform: uppercase; letter-spacing: 0.04em; margin-top: 4px; }
.bdl-stat.green  { border-left-color: #2d6a35; }
.bdl-stat.blue   { border-left-color: #60a5fa; }
.bdl-stat.violet { border-left-color: #7c3aed; }
.bdl-stat.orange { border-left-color: #f59e0b; }
@media (max-width: 800px) { .bdl-stats { grid-template-columns: repeat(2, 1fr); } }

/* Zone upload */
.bdl-upload { background: linear-gradient(135deg, #fef3c7, #fde68a); border: 2px dashed #D4A047;
    border-radius: 12px; padding: 22px 20px; text-align: center; margin-bottom: 14px;
    box-shadow: 2px 2px 6px #e3dfd8; }
.bdl-upload .lbl { display: block; margin-bottom: 12px; font-size: 14px; font-weight: 700; color: #92400e; }
.bdl-upload form { display: inline-flex; gap: 10px; align-items: center; flex-wrap: wrap; justify-content: center; }
.bdl-upload input[type=file] { padding: 8px 12px; background: #fff; border: 1.5px solid #D4A047;
    color: #2c2a28; border-radius: 6px; font-family: inherit; font-size: 12.5px; }
.bdl-upload button { background: #D4A047; color: #fff; border: none; padding: 10px 22px;
    border-radius: 6px; font-weight: 700; font-family: inherit; font-size: 13px;
    cursor: pointer; box-shadow: 2px 2px 6px rgba(212,160,71,0.4); transition: all 0.15s; }
.bdl-upload button:hover { background: #b8862b; transform: translateY(-1px); }
.bdl-upload .sub { font-size: 11px; color: #92400e; margin-top: 10px; opacity: 0.8; }

/* Cartes en attente (FluxBox non commitées) */
.bdl-pending { background: #ede9fe; border-left: 4px solid #7c3aed; padding: 12px 16px;
    border-radius: 8px; margin-bottom: 14px; }
.bdl-pending h3 { margin: 0 0 8px; font-size: 12.5px; color: #5b21b6; }
.bdl-pending-item { display: flex; align-items: center; gap: 10px; padding: 6px 0;
    border-bottom: 1px solid #ddd6fe; font-size: 12px; }
.bdl-pending-item:last-child { border-bottom: none; }
.bdl-pending-item .name { flex: 1; color: #2c2a28; }
.bdl-pending-item .review-btn { background: #7c3aed; color: #fff; padding: 4px 10px;
    border-radius: 6px; text-decoration: none; font-size: 11px; font-weight: 700; }
.bdl-pending-item .review-btn:hover { background: #6d28d9; }

/* Liste docs par module */
.bdl-module { background: #fff; border-radius: 10px; padding: 14px 18px; margin-bottom: 12px;
    box-shadow: 2px 2px 6px #e3dfd8; border-left: 4px solid #60a5fa; }
.bdl-module h3 { margin: 0 0 10px; font-size: 12.5px; color: #2c2a28; display: flex; align-items: center; gap: 8px; }
.bdl-module h3 .count { background: #dbeafe; color: #1e40af; font-size: 10px; font-weight: 700;
    padding: 2px 8px; border-radius: 99px; margin-left: auto; }
.bdl-module.module-transaction { border-left-color: #eab308; }
.bdl-module.module-gestion     { border-left-color: #0e7490; }
.bdl-module.module-syndic      { border-left-color: #7c9885; }

.bdl-doc-item { display: grid; grid-template-columns: 1fr auto auto auto; gap: 12px;
    align-items: center; padding: 8px 12px; margin-bottom: 4px;
    background: #fafaf6; border-radius: 6px; font-size: 12px; transition: all 0.15s;
    border: 1px solid transparent; }
.bdl-doc-item:hover { background: #fef3c7; border-color: #D4A047; }
.bdl-doc-item .name { font-weight: 700; color: #2c2a28; }
.bdl-doc-item .name a { color: #1e40af; text-decoration: none; }
.bdl-doc-item .name a:hover { text-decoration: underline; }
.bdl-doc-item .meta { color: #7a766f; font-size: 10.5px; margin-top: 2px; }
.bdl-doc-item .badge-rel { display: inline-block; padding: 1px 7px; border-radius: 99px;
    font-size: 9.5px; font-weight: 700; margin-left: 6px; }
.bdl-doc-item .badge-rel.main      { background: #d9f0db; color: #14532d; }
.bdl-doc-item .badge-rel.reference { background: #dbeafe; color: #1e40af; }
.bdl-doc-item .badge-rel.annexe    { background: #fef3c7; color: #92400e; }
.bdl-doc-item .badge-validated { background: #d9f0db; color: #14532d; font-size: 9.5px;
    padding: 1px 7px; border-radius: 99px; font-weight: 700; }
.bdl-doc-item .size { font-size: 10.5px; color: #7a766f; }
.bdl-doc-item .date { font-size: 10.5px; color: #7a766f; }
.bdl-doc-item .view-btn { background: transparent; border: 1px solid #e3dfd8;
    color: #5a5650; font-size: 11px; padding: 4px 10px; border-radius: 6px;
    cursor: pointer; font-family: inherit; text-decoration: none; transition: all 0.15s; }
.bdl-doc-item .view-btn:hover { background: #D4A047; color: #fff; border-color: #D4A047; }

.bdl-empty { text-align: center; padding: 36px 16px; color: #7a766f; font-style: italic;
    background: #fff; border-radius: 10px; box-shadow: 2px 2px 6px #e3dfd8; }
.bdl-empty .ico { font-size: 36px; margin-bottom: 10px; }
</style>

<?php
// ─── Breadcrumb ──
$chaine = [];
if ($proprioTiersId > 0 && $proprioLabel !== '') {
    $chaine[] = ['icon' => '👤', 'label' => $proprioLabel, 'url' => app_url('/tiers_360.php?id=' . $proprioTiersId)];
}
if (!empty($bien['id_immeuble'])) {
    $chaine[] = ['icon' => '🏢', 'label' => $bien['imm_nom'] ?: $bien['imm_adresse'] ?: 'Immeuble', 'url' => app_url('/immeuble_360.php?id=' . (int)$bien['id_immeuble'])];
}
$chaine[] = ['icon' => '🏠', 'label' => 'Bien #' . $bienId, 'url' => app_url('/bien_360.php?id=' . $bienId)];
$chaine[] = ['icon' => '📁', 'label' => 'Documents', 'url' => null];
fiche360_breadcrumb($chaine, 'Patrimoine');

// ─── Header ──
$titreBien = $bien['designation'] ?: ($bien['adresse_1'] ?: $bien['imm_adresse'] ?: ('Bien #' . $bienId));
fiche360_header(
    '📁',
    'Documents du bien · ' . $titreBien,
    ['label' => $nbDocs . ' doc(s)', 'class' => 'actif'],
    'GED · ' . ($bien['adresse_1'] ?: $bien['imm_adresse'] ?: '?') . ($bien['code_postal'] ? ' · ' . $bien['code_postal'] . ' ' . ($bien['ville'] ?? '') : ''),
    [
        ['icon' => '📊', 'text' => $nbModules . ' module(s)'],
        ['icon' => '✓',  'text' => $nbValides . ' validé(s)'],
    ],
    [
        ['label' => '← Retour bien', 'url' => app_url('/bien_360.php?id=' . $bienId), 'class' => 'tr-btn'],
    ]
);
?>

<?php if ($commitStatus === 'ok'): ?>
    <div class="bdl-flash ok">
        ✅ <b>Document validé et persisté</b>
        <?php if ($newDocId > 0): ?> · ged_documents #<?= $newDocId ?><?php endif; ?>
        <?php if ($flash !== ''): ?> · <?= bdl_html($flash) ?><?php endif; ?>
    </div>
<?php elseif ($commitStatus === 'ko'): ?>
    <div class="bdl-flash ko">
        🔴 Erreur lors de la persistance
        <?php if ($flash !== ''): ?> · <?= bdl_html($flash) ?><?php endif; ?>
    </div>
<?php endif; ?>

<!-- KPI -->
<div class="bdl-stats">
    <div class="bdl-stat blue">
        <div class="v"><?= $nbDocs ?></div>
        <div class="l">Documents liés</div>
    </div>
    <div class="bdl-stat green">
        <div class="v"><?= $nbValides ?></div>
        <div class="l">Validés</div>
    </div>
    <div class="bdl-stat violet">
        <div class="v"><?= $nbModules ?></div>
        <div class="l">Modules</div>
    </div>
    <div class="bdl-stat orange">
        <div class="v"><?= count($cardsPending) ?></div>
        <div class="l">En attente review</div>
    </div>
</div>

<!-- Zone upload -->
<div class="bdl-upload">
    <span class="lbl">📤 Charger un ou plusieurs documents sur ce bien</span>
    <form method="POST" action="api/transaction_quick_upload.php" enctype="multipart/form-data">
        <input type="hidden" name="bien_id" value="<?= (int)$bienId ?>">
        <input type="file" name="document[]" multiple required accept=".pdf,.jpg,.jpeg,.png,.tiff,.zip">
        <button type="submit">📤 Upload + Review</button>
    </form>
    <div class="sub">Fichier seul, multi-fichiers ou ZIP (extraction auto) · PDF, images, ZIP autorisés</div>
</div>

<!-- Cartes en attente review -->
<?php if (!empty($cardsPending)): ?>
    <div class="bdl-pending">
        <h3>⏳ <?= count($cardsPending) ?> document(s) uploadés en attente de review/validation</h3>
        <?php foreach ($cardsPending as $c): ?>
            <div class="bdl-pending-item">
                <span class="name">📄 <?= bdl_html((string)($c['titre'] ?: $c['fichier_nom'])) ?>
                    <small style="color:#7a766f;">· uploadé le <?= bdl_html(substr((string)$c['created_at'], 0, 10)) ?></small>
                </span>
                <a class="review-btn"
                   href="<?= bdl_html(app_url('/bien_doc_360.php?bien_id=' . $bienId . '&doc_id=' . (int)$c['document_id'] . '&card_id=' . (int)$c['id'] . '&source=transaction')) ?>">
                    📋 Réviser
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Liste docs validés groupés par module -->
<?php if (empty($docs)): ?>
    <div class="bdl-empty">
        <div class="ico">📄</div>
        Aucun document validé sur ce bien.<br>
        Charge le premier via la zone ci-dessus 👆 ou réviser les documents en attente.
    </div>
<?php else: ?>
    <?php foreach ($byModule as $module => $items):
        $moduleLow = strtolower((string)$module);
        $moduleClass = 'bdl-module';
        if (str_contains($moduleLow, 'transaction')) $moduleClass .= ' module-transaction';
        elseif (str_contains($moduleLow, 'gestion')) $moduleClass .= ' module-gestion';
        elseif (str_contains($moduleLow, 'syndic'))  $moduleClass .= ' module-syndic';
    ?>
        <div class="<?= bdl_html($moduleClass) ?>">
            <h3>
                📂 Module <?= bdl_html((string)$module) ?>
                <span class="count"><?= count($items) ?> doc(s)</span>
            </h3>
            <?php foreach ($items as $d): ?>
                <div class="bdl-doc-item">
                    <div>
                        <div class="name">
                            <a href="javascript:void(0)" onclick="mvptModalView(<?= (int)$d['id'] ?>, '<?= bdl_html((string)($d['name_display'] ?? $d['name_file'])) ?>')">
                                📄 <?= bdl_html((string)($d['name_display'] ?? $d['name_file'])) ?>
                            </a>
                            <?php if (!empty($d['relation_type'])): ?>
                                <span class="badge-rel <?= bdl_html((string)$d['relation_type']) ?>"><?= bdl_html((string)$d['relation_type']) ?></span>
                            <?php endif; ?>
                            <?php if ((int)($d['is_validated'] ?? 0) === 1): ?>
                                <span class="badge-validated">✓ validé</span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($d['document_type']) || !empty($d['folder_name'])): ?>
                            <div class="meta">
                                <?php if (!empty($d['document_type'])): ?><?= bdl_html((string)$d['document_type']) ?><?php endif; ?>
                                <?php if (!empty($d['folder_name'])): ?>
                                    <?= !empty($d['document_type']) ? ' · ' : '' ?>📁 <?= bdl_html((string)$d['folder_name']) ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="size"><?= number_format((int)$d['size_bytes']/1024) ?> Ko</div>
                    <div class="date"><?= bdl_html(substr((string)$d['created_at'], 0, 10)) ?></div>
                    <?php if (!empty($d['fluxbox_source_id'])): ?>
                        <a class="view-btn" href="<?= bdl_html(app_url('/bien_doc_360.php?bien_id=' . $bienId . '&doc_id=' . (int)$d['fluxbox_source_id'])) ?>" title="Voir la fiche 360° du document">📋 360°</a>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php include __DIR__ . '/inc/mvpt_modal_doc_viewer.php'; ?>

<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
