<?php
declare(strict_types=1);

/**
 * GED — Consultation des documents classés
 *
 * Liste paginée + filtrée des ged_documents du tenant.
 * Filtres : groupe métier (8 groupes), N1, recherche texte.
 * Origine FluxBox marquée si fluxbox_source_id NOT NULL.
 *
 * Spec : new page (validé EMERY 2026-05-13)
 */

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/ged_functions.php';
require_login();

$pdo = ged_pdo();
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$tenantId = (int)(ged_current_tenant_id() ?? 0);

// Filtres
$fGroup  = trim((string)($_GET['group']  ?? ''));
$fN1     = trim((string)($_GET['n1']     ?? ''));
$fSearch = trim((string)($_GET['q']      ?? ''));
$fOrigin = trim((string)($_GET['origin'] ?? '')); // all|fluxbox
$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

// Groupes UI → liste de N1
$groupMap = [
    'RH'           => ['02_RH'],
    'COMPTA'       => ['06_COMPTABILITE'],
    'BAILLEUR'     => ['03_GESTION_LOCATIVE'],
    'SYNDIC'       => ['04_SYNDIC'],
    'AGENCE'       => ['01_AGENCE', '05_TRANSACTION', '08_MARKETING_COMMUNICATION'],
    'FOURNISSEURS' => ['13_FOURNISSEURS'],
    'DIRECTION'    => ['01_DIRECTION', '09_MODELES_DOCUMENTS', '10_REFERENTIEL', '11_MAILS_COMMUNICATIONS', '12_ARCHIVES', '99_SYSTEME'],
    'JURIDIQUE'    => ['07_JURIDIQUE_CONTENTIEUX'],
];
$groupLabels = [
    'RH'=>'👥 RH', 'COMPTA'=>'💰 Comptabilité', 'BAILLEUR'=>'🏠 Gestion',
    'SYNDIC'=>'🏢 Syndic', 'AGENCE'=>'🤝 Agence', 'FOURNISSEURS'=>'🔧 Fournisseurs',
    'DIRECTION'=>'⚙️ Direction', 'JURIDIQUE'=>'⚖️ Juridique',
];

// N1 disponibles selon le groupe
$n1Options = [];
try {
    $st = $pdo->query("
        SELECT code, label, COALESCE(business_group,'') AS bg
        FROM ged_level_codes
        WHERE level_number = 1 AND is_active = 1 AND COALESCE(is_virtual,0) = 0
        ORDER BY FIELD(business_group,'RH','COMPTA','BAILLEUR','SYNDIC','AGENCE','FOURNISSEURS','MARKETING','DIRECTION','JURIDIQUE'), position
    ");
    $n1Options = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {}

// Build WHERE
$where  = ['d.tenant_id = ?', "d.status <> 'deleted'"];
$params = [$tenantId];

if ($fGroup !== '' && isset($groupMap[$fGroup])) {
    $placeholders = implode(',', array_fill(0, count($groupMap[$fGroup]), '?'));
    $where[] = "d.source_module IN ($placeholders)";
    $params = array_merge($params, $groupMap[$fGroup]);
}
if ($fN1 !== '') {
    $where[] = 'd.source_module = ?';
    $params[] = $fN1;
}
if ($fSearch !== '') {
    $where[] = '(d.name_display LIKE ? OR d.name_canonical LIKE ?)';
    $needle = '%' . $fSearch . '%';
    $params[] = $needle;
    $params[] = $needle;
}
if ($fOrigin === 'fluxbox') {
    $where[] = 'd.fluxbox_source_id IS NOT NULL';
}

$whereSql = implode(' AND ', $where);

// Count total
$total = 0;
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM ged_documents d WHERE $whereSql");
    $st->execute($params);
    $total = (int)$st->fetchColumn();
} catch (Throwable $e) { $total = 0; }

// Listing paginé
$rows = [];
try {
    $sql = "SELECT d.id, d.uuid, d.name_display, d.name_canonical, d.name_file, d.source_module,
                   d.mime_type, d.size_bytes, d.created_at,
                   d.fluxbox_source_id, d.metadata
            FROM ged_documents d
            WHERE $whereSql
            ORDER BY d.created_at DESC
            LIMIT $perPage OFFSET $offset";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable) {}

$totalPages = max(1, (int)ceil($total / $perPage));

// Helpers display
$fmtSize = function (?int $bytes): string {
    if (!$bytes) return '—';
    if ($bytes < 1024) return $bytes . ' o';
    if ($bytes < 1024*1024) return round($bytes / 1024, 1) . ' Ko';
    return round($bytes / 1024 / 1024, 1) . ' Mo';
};
$fmtDate = function (?string $ts): string {
    if (!$ts) return '';
    $t = strtotime($ts);
    return $t ? date('d/m/Y H:i', $t) : '';
};
$extractClassement = function (?string $metaJson, string $n1): string {
    if (!$metaJson) return $n1;
    $m = json_decode($metaJson, true);
    if (!is_array($m)) return $n1;
    $c = $m['classement'] ?? [];
    $parts = array_filter([
        $n1,
        $c['n2'] ?? null,
        $c['n3'] ?? null,
        $c['n4'] ?? null,
    ]);
    return implode(' › ', array_map('strval', $parts));
};

$layout_title          = 'GED — Consultation';
$layout_module         = 'Ma GED Box';
$layout_sidebar        = 'sidebar_agency';
$layout_hide_page_head = true;

$layout_extra_css = '<style>
.gcons-wrap { max-width: 1200px; margin: 0 auto; padding: 20px 0 40px; }
.gcons-head { display:flex; justify-content:space-between; align-items:flex-start; gap:20px; margin-bottom:20px; flex-wrap:wrap; }
.gcons-title { font-family:"Sora",sans-serif; font-size:26px; font-weight:700; color:#243B5C; margin:0; }
.gcons-sub { font-size:13px; color:#64748b; margin-top:4px; }
.gcons-stats { background:#fff; padding:12px 18px; border-radius:14px; box-shadow:4px 4px 12px rgba(196,192,186,0.5), -4px -4px 12px #fff; font-size:13px; }
.gcons-stats strong { color:#243B5C; font-size:18px; }

.gcons-filters {
    background:#fff; border-radius:16px; padding:16px 18px;
    box-shadow:4px 4px 12px rgba(196,192,186,0.4), -4px -4px 12px #fff;
    margin-bottom:16px;
}
.gcons-filter-row { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
.gcons-filter-field { display:flex; flex-direction:column; gap:4px; min-width:160px; }
.gcons-filter-field label {
    font-size:10px; color:#64748b; font-weight:700;
    text-transform:uppercase; letter-spacing:0.05em;
}
.gcons-filter-field input, .gcons-filter-field select {
    padding:8px 12px; border:1px solid #cbd5e1; border-radius:8px;
    font-family:inherit; font-size:13px; background:#fff;
}
.gcons-filter-field input:focus, .gcons-filter-field select:focus {
    outline:2px solid #243B5C; outline-offset:0; border-color:#243B5C;
}
.gcons-filter-actions { display:flex; gap:8px; }
.gcons-btn {
    padding:9px 18px; border-radius:10px; border:none; cursor:pointer;
    font-family:inherit; font-size:13px; font-weight:600;
}
.gcons-btn-primary { background:linear-gradient(135deg,#243B5C,#1e3050); color:#fff; }
.gcons-btn-secondary { background:#f1f5f9; color:#475569; }

.gcons-groups { display:flex; gap:6px; flex-wrap:wrap; margin-top:10px; }
.gcons-group-chip {
    display:inline-flex; align-items:center; gap:6px;
    padding:6px 12px; border-radius:14px;
    background:#f8fafc; color:#475569;
    font-size:12px; font-weight:600;
    text-decoration:none; border:1.5px solid transparent;
    transition:all .15s;
}
.gcons-group-chip:hover { border-color:#cbd5e1; }
.gcons-group-chip.is-active { background:#243B5C; color:#fff; }

.gcons-table-wrap {
    background:#fff; border-radius:16px;
    box-shadow:4px 4px 12px rgba(196,192,186,0.4), -4px -4px 12px #fff;
    overflow:hidden;
}
.gcons-table { width:100%; border-collapse:collapse; font-size:13px; }
.gcons-table thead { background:#f8fafc; }
.gcons-table th {
    text-align:left; padding:12px 14px;
    font-size:11px; font-weight:700; color:#64748b;
    text-transform:uppercase; letter-spacing:0.05em;
    border-bottom:1px solid #e2e8f0;
}
.gcons-table td {
    padding:11px 14px; border-bottom:1px solid #f1f5f9;
    color:#2c2a28; vertical-align:top;
}
.gcons-table tr:hover { background:#fef9e8; }
.gcons-name { font-weight:600; color:#243B5C; }
.gcons-canon {
    font-family:"JetBrains Mono",monospace; font-size:11px; color:#64748b;
    word-break:break-all;
}
.gcons-path {
    font-size:11px; color:#92400e; background:#fef3c7;
    padding:2px 8px; border-radius:8px; display:inline-block;
}
.gcons-fluxbox-badge {
    font-size:10px; padding:2px 6px; border-radius:6px;
    background:#dcfce7; color:#166534; font-weight:700;
}
.gcons-action-btn {
    display:inline-flex; align-items:center; justify-content:center;
    width:30px; height:30px; margin:0 2px;
    border:1px solid #cbd5e1; border-radius:6px;
    background:#fff; text-decoration:none;
    font-size:14px; transition:all .12s;
}
.gcons-action-btn:hover { background:#243B5C; border-color:#243B5C; transform: translateY(-1px); }
.gcons-meta { font-size:11px; color:#94a3b8; }

.gcons-empty {
    padding:60px 20px; text-align:center; color:#64748b;
}
.gcons-empty-icon { font-size:48px; margin-bottom:8px; }

.gcons-pagination {
    display:flex; justify-content:center; gap:6px;
    margin-top:18px; flex-wrap:wrap;
}
.gcons-pagination a, .gcons-pagination span {
    padding:7px 12px; border-radius:8px;
    font-size:13px; text-decoration:none;
    background:#fff; color:#475569; border:1px solid #e2e8f0;
}
.gcons-pagination a:hover { background:#f8fafc; }
.gcons-pagination .is-current {
    background:#243B5C; color:#fff; border-color:#243B5C; font-weight:700;
}
</style>';

ob_start();
?>
<div class="gcons-wrap">

    <!-- Header -->
    <div class="gcons-head">
        <div>
            <h1 class="gcons-title">📚 GED — Consultation</h1>
            <div class="gcons-sub">Documents classés dans la GED. Recherche, filtre, navigation par classement métier.</div>
        </div>
        <div class="gcons-stats">
            <strong><?= number_format($total, 0, ',', ' ') ?></strong> document<?= $total > 1 ? 's' : '' ?>
            <?php if ($fGroup || $fN1 || $fSearch || $fOrigin === 'fluxbox'): ?>
                <span style="color:#94a3b8"> (filtré)</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filtres -->
    <form method="GET" class="gcons-filters">

        <div class="gcons-filter-row">
            <div class="gcons-filter-field" style="flex:1;">
                <label>🔎 Recherche</label>
                <input type="text" name="q" value="<?= $h($fSearch) ?>"
                       placeholder="Nom, contenu, classement…" autocomplete="off">
            </div>

            <div class="gcons-filter-field">
                <label>Métier N1</label>
                <select name="n1">
                    <option value="">— Tous —</option>
                    <?php foreach ($n1Options as $o):
                        $sel = $o['code'] === $fN1 ? 'selected' : ''; ?>
                        <option value="<?= $h($o['code']) ?>" <?= $sel ?>><?= $h($o['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="gcons-filter-field">
                <label>Origine</label>
                <select name="origin">
                    <option value="">— Toutes —</option>
                    <option value="fluxbox" <?= $fOrigin === 'fluxbox' ? 'selected' : '' ?>>📥 Issu de FluxBox</option>
                </select>
            </div>

            <div class="gcons-filter-actions">
                <button type="submit" class="gcons-btn gcons-btn-primary">Filtrer</button>
                <a href="?" class="gcons-btn gcons-btn-secondary" style="text-decoration:none;display:inline-flex;align-items:center;">Réinit.</a>
            </div>
        </div>

        <!-- Chips groupes métier (raccourci visuel) -->
        <div class="gcons-groups">
            <a href="?<?= http_build_query(array_merge($_GET, ['group'=>''])) ?>"
               class="gcons-group-chip <?= $fGroup === '' ? 'is-active' : '' ?>">
                Tous
            </a>
            <?php foreach ($groupLabels as $g => $lbl): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['group'=>$g, 'n1'=>''])) ?>"
                   class="gcons-group-chip <?= $fGroup === $g ? 'is-active' : '' ?>">
                    <?= $lbl ?>
                </a>
            <?php endforeach; ?>
        </div>
    </form>

    <!-- Table résultats -->
    <div class="gcons-table-wrap">
        <?php if (empty($rows)): ?>
            <div class="gcons-empty">
                <div class="gcons-empty-icon">📭</div>
                <h3>Aucun document trouvé</h3>
                <p>
                    <?php if ($total === 0 && !$fGroup && !$fN1 && !$fSearch): ?>
                        Aucun document classé dans la GED pour l'instant. <br>
                        Charge des documents via <a href="./fluxbox.php" style="color:#243B5C;font-weight:600;">🃏 FluxBox</a> et valide-les.
                    <?php else: ?>
                        Aucun résultat pour ces filtres. <a href="?" style="color:#243B5C;font-weight:600;">Réinitialiser</a>.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <table class="gcons-table">
                <thead>
                    <tr>
                        <th>Nom</th>
                        <th>Classement</th>
                        <th>Date</th>
                        <th>Taille</th>
                        <th>Origine</th>
                        <th style="width:110px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $path = $extractClassement($r['metadata'] ?? null, (string)($r['source_module'] ?? ''));
                    $viewUrl = (function_exists('app_url') ? app_url('/api/ged_document_view.php') : '/api/ged_document_view.php')
                             . '?id=' . (int)$r['id'];
                ?>
                    <tr>
                        <td>
                            <div class="gcons-name"><?= $h($r['name_display'] ?: $r['name_canonical']) ?></div>
                            <div class="gcons-canon"><?= $h($r['name_canonical']) ?></div>
                        </td>
                        <td>
                            <span class="gcons-path"><?= $h($path) ?></span>
                        </td>
                        <td class="gcons-meta"><?= $h($fmtDate($r['created_at'])) ?></td>
                        <td class="gcons-meta"><?= $h($fmtSize((int)($r['size_bytes'] ?? 0))) ?></td>
                        <td>
                            <?php if (!empty($r['fluxbox_source_id'])): ?>
                                <span class="gcons-fluxbox-badge">🃏 FluxBox</span>
                            <?php else: ?>
                                <span class="gcons-meta">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                              $docName = $r['name_display'] ?: $r['name_canonical'];
                              // Détection extension : on cherche dans plusieurs sources
                              //   1. name_file (vrai nom physique avec ext)
                              //   2. name_canonical (souvent ...20260516.pdf)
                              //   3. name_display
                              $docMime = (string)($r['mime_type'] ?? '');
                              $docExt = '';
                              foreach ([$r['name_file'] ?? '', $r['name_canonical'] ?? '', $docName] as $candidate) {
                                  $ext = strtolower(pathinfo((string)$candidate, PATHINFO_EXTENSION) ?: '');
                                  if ($ext !== '' && strlen($ext) <= 5) { $docExt = $ext; break; }
                              }
                              // Fallback via mime type
                              if ($docExt === '') {
                                  if (str_contains($docMime, 'outlook') || str_contains($docMime, 'ms-outlook')) $docExt = 'msg';
                                  elseif (str_contains($docMime, 'pdf')) $docExt = 'pdf';
                                  elseif (str_contains($docMime, 'jpeg') || str_contains($docMime, 'jpg')) $docExt = 'jpg';
                                  elseif (str_contains($docMime, 'png')) $docExt = 'png';
                              }
                            ?>
                            <button type="button"
                                    class="gcons-action-btn gcons-view-btn"
                                    data-view-url="<?= $h($viewUrl) ?>&mode=inline"
                                    data-download-url="<?= $h($viewUrl) ?>&mode=download"
                                    data-doc-name="<?= $h($docName) ?>"
                                    data-doc-ext="<?= $h($docExt) ?>"
                                    data-doc-mime="<?= $h($docMime) ?>"
                                    data-ged-id="<?= (int)$r['id'] ?>"
                                    title="Visualiser dans un modal">👁️</button>
                            <a href="<?= $h($viewUrl) ?>&mode=download"
                               class="gcons-action-btn" title="Télécharger">📥</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="gcons-pagination">
        <?php
        $baseParams = $_GET;
        $renderPage = function ($p, $label = null, $current = false) use ($baseParams, $h) {
            $baseParams['p'] = $p;
            $url = '?' . http_build_query($baseParams);
            $cls = $current ? 'is-current' : '';
            return '<a href="' . $h($url) . '" class="' . $cls . '">' . $h((string)($label ?? $p)) . '</a>';
        };
        if ($page > 1) echo $renderPage($page - 1, '← Précédent');
        // Compact: 1 .. p-2 p-1 p p+1 p+2 .. last
        $shown = [];
        for ($i = 1; $i <= $totalPages; $i++) {
            if ($i === 1 || $i === $totalPages || abs($i - $page) <= 2) $shown[] = $i;
        }
        $prev = 0;
        foreach ($shown as $i) {
            if ($prev > 0 && $i - $prev > 1) echo '<span>…</span>';
            echo $renderPage($i, null, $i === $page);
            $prev = $i;
        }
        if ($page < $totalPages) echo $renderPage($page + 1, 'Suivant →');
        ?>
    </div>
    <?php endif; ?>

    <!-- Sous-modal viewer de pièce jointe (PDF/image) — s'ouvre par-dessus -->
    <dialog id="gcons-modal-att" class="gcons-modal gcons-modal-att">
        <div class="gcons-modal-head">
            <h3 id="gcons-att-title">📎 Pièce jointe</h3>
            <button type="button" class="gcons-action-btn" data-att-close>✕ Fermer</button>
        </div>
        <div class="gcons-modal-body" id="gcons-att-body"
             style="display:flex;align-items:center;justify-content:center;background:#f1f5f9;border-radius:8px;height:78vh;overflow:auto;">
            <!-- L'iframe (PDF/HTML) OU l'img (images) sont affichés selon le type — un seul visible à la fois -->
            <iframe id="gcons-att-iframe" src="about:blank"
                    style="width:100%;height:100%;border:0;background:transparent;display:none;"
                    title="Pièce jointe"></iframe>
            <img id="gcons-att-image" src="" alt=""
                 style="max-width:100%;max-height:100%;object-fit:contain;display:none;" />
            <div id="gcons-att-fallback" style="display:none;text-align:center;padding:40px;">
                <div style="font-size:48px;margin-bottom:14px;">📄</div>
                <div style="font-size:16px;color:#243B5C;font-weight:600;margin-bottom:8px;">Aperçu non disponible</div>
                <div style="font-size:13px;color:#64748b;">Télécharge la pièce jointe pour l'ouvrir avec le bon logiciel.</div>
            </div>
        </div>
        <!-- Panneau édition (nom + cascade classement) — collapsible -->
        <details id="gcons-att-edit" style="margin:12px 0;border-top:1px dashed #cbd5e1;padding-top:10px;">
          <summary style="cursor:pointer;font-size:12px;color:#475569;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;">
            ✏️ Modifier le nom ou le classement avant d'enregistrer
          </summary>
          <div style="margin-top:12px;display:grid;gap:14px;">
            <label style="display:block;">
              <span style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">Nom du document</span>
              <input type="text" id="gcons-att-name-input" style="width:100%;padding:8px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px;margin-top:4px;" placeholder="Nom de la pièce jointe">
            </label>
            <div>
              <div style="font-size:11px;color:#475569;font-weight:700;text-transform:uppercase;margin-bottom:4px;">💼 Métier (N1)</div>
              <div class="fbx-btn-grid" id="gcons-att-row-metiers"><div class="fbx-loading">Chargement…</div></div>
            </div>
            <div>
              <div style="font-size:11px;color:#475569;font-weight:700;text-transform:uppercase;margin-bottom:4px;">📂 Domaine (N2)</div>
              <div class="fbx-btn-grid-sm" id="gcons-att-row-n2"><div class="fbx-row-empty">— Choisir un métier —</div></div>
            </div>
            <div>
              <div style="font-size:11px;color:#475569;font-weight:700;text-transform:uppercase;margin-bottom:4px;">📁 Sous-domaine (N3)</div>
              <div class="fbx-btn-grid-sm" id="gcons-att-row-n3"><div class="fbx-row-empty">— Choisir un domaine —</div></div>
            </div>
            <div>
              <div style="font-size:11px;color:#475569;font-weight:700;text-transform:uppercase;margin-bottom:4px;">📄 Catégorie (N4)</div>
              <div class="fbx-btn-grid-sm" id="gcons-att-row-n4"><div class="fbx-row-empty">— Choisir un sous-domaine —</div></div>
            </div>
            <div>
              <div style="font-size:11px;color:#475569;font-weight:700;text-transform:uppercase;margin-bottom:4px;">📑 Sous-catégorie (N5)</div>
              <div class="fbx-btn-grid-sm" id="gcons-att-row-n5"><div class="fbx-row-empty">— Choisir une catégorie —</div></div>
            </div>
            <input type="hidden" id="gcons-att-n1" value="">
            <input type="hidden" id="gcons-att-n2" value="">
            <input type="hidden" id="gcons-att-n3" value="">
            <input type="hidden" id="gcons-att-n4" value="">
            <input type="hidden" id="gcons-att-n5" value="">
            <div style="font-size:11px;color:#94a3b8;font-style:italic;">
              💡 Vide = classement du mail parent. Modifie seulement ce qui change.
            </div>
          </div>
        </details>
        <div class="gcons-modal-foot">
            <button type="button" id="gcons-att-save" class="gcons-action-btn"
                    style="background:linear-gradient(135deg,#16a34a,#15803d);color:#fff;border:none;font-weight:600;padding:8px 16px;border-radius:8px;cursor:pointer;">
                💾 Enregistrer dans la GED
            </button>
            <a id="gcons-att-open-new" href="#" target="_blank" rel="noopener" class="gcons-action-btn">↗️ Nouvel onglet</a>
            <a id="gcons-att-download"  href="#" class="gcons-action-btn">📥 Télécharger</a>
        </div>
    </dialog>

    <!-- Modal viewer direct — iframe pour PDF/image, message pour formats non-affichables -->
    <dialog id="gcons-modal-view" class="gcons-modal">
        <div class="gcons-modal-head">
            <h3 id="gcons-modal-title">👁️ Aperçu du document</h3>
            <button type="button" class="gcons-action-btn" data-modal-close>✕ Fermer</button>
        </div>
        <div class="gcons-modal-body">
            <iframe id="gcons-modal-iframe" src="about:blank"
                    style="width:100%;height:78vh;border:0;border-radius:8px;background:#f1f5f9;display:none;"
                    title="Aperçu du document"></iframe>
            <div id="gcons-modal-msgview" style="display:none;max-height:78vh;overflow-y:auto;"></div>
            <div id="gcons-modal-noview" style="display:none; padding:60px 20px; text-align:center;">
                <div style="font-size:48px; margin-bottom:14px;">📄</div>
                <div style="font-size:16px; color:#243B5C; font-weight:600; margin-bottom:8px;" id="gcons-noview-title">Format non affichable en aperçu</div>
                <div style="font-size:13px; color:#64748b; margin-bottom:24px;" id="gcons-noview-msg">Ce type de fichier ne peut pas être affiché directement dans le navigateur.</div>
                <a href="#" target="_blank" rel="noopener" id="gcons-noview-open" class="gcons-action-btn" style="display:inline-block; padding:10px 16px; margin-right:8px;">↗️ Ouvrir dans un nouvel onglet</a>
                <a href="#" id="gcons-noview-download" class="gcons-action-btn" style="display:inline-block; padding:10px 16px;">📥 Télécharger pour ouvrir localement</a>
            </div>
        </div>
        <div class="gcons-modal-foot" id="gcons-modal-foot">
            <a id="gcons-modal-open-new" href="#" target="_blank" rel="noopener"
               class="gcons-action-btn">↗️ Ouvrir dans un nouvel onglet</a>
            <a id="gcons-modal-download" href="#"
               class="gcons-action-btn">📥 Télécharger</a>
        </div>
    </dialog>

</div>

<style>
.gcons-modal {
  border: none; border-radius: 14px; padding: 0;
  max-width: 1100px; width: 92%; max-height: 92vh;
  box-shadow: 0 20px 60px rgba(0,0,0,0.2);
}
.gcons-modal::backdrop { background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(3px); }
.gcons-modal-head {
  display: flex; justify-content: space-between; align-items: center;
  padding: 16px 22px; border-bottom: 2px solid #243B5C;
}
.gcons-modal-head h3 {
  margin: 0; color: #243B5C; font-size: 18px; font-weight: 700;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 80%;
}
.gcons-modal-body { padding: 14px 22px; }
.gcons-modal-foot {
  padding: 12px 22px; border-top: 1px solid #e2e8f0;
  display: flex; justify-content: flex-end; gap: 10px;
}
.gcons-view-btn {
  background: none; border: 1px solid #cbd5e1; cursor: pointer;
}
.gcons-view-btn:hover { border-color: #243B5C; }
.gcons-modal-att { z-index: 9999; }
.gcons-modal-att::backdrop { background: rgba(15, 23, 42, 0.75); }
.gcons-att-view-btn {
  background: none; border: 1px solid #cbd5e1; cursor: pointer;
}
.gcons-att-view-btn:hover { border-color: #D4A047; background: #fef9e7; }

/* Sections fiche mail .msg dans le modal */
.gcons-msg-section {
  margin-bottom: 16px; padding: 14px 16px;
  background: #f8fafc;
  border-left: 3px solid #D4A047;
  border-radius: 8px;
}
.gcons-msg-section-title {
  font-size: 11px; font-weight: 700; color: #243B5C;
  text-transform: uppercase; letter-spacing: 0.05em;
  margin-bottom: 10px;
}
.gcons-msg-meta {
  display: grid; grid-template-columns: 120px 1fr; gap: 10px;
  padding: 4px 0; font-size: 13px;
}
.gcons-msg-meta > span:first-child { color: #64748b; font-weight: 600; }
.gcons-msg-meta > span:last-child, .gcons-msg-meta > code { color: #1e293b; word-break: break-word; }
</style>

<script>
(function() {
  const modal = document.getElementById('gcons-modal-view');
  const iframe = document.getElementById('gcons-modal-iframe');
  const noView = document.getElementById('gcons-modal-noview');
  const titleEl = document.getElementById('gcons-modal-title');
  const openNew = document.getElementById('gcons-modal-open-new');
  const downloadEl = document.getElementById('gcons-modal-download');
  const foot = document.getElementById('gcons-modal-foot');
  if (!modal || !iframe) return;

  // Formats affichables nativement par les navigateurs dans une iframe
  const VIEWABLE_EXT = ['pdf','jpg','jpeg','png','gif','bmp','webp','svg','txt','html','htm','xml','json'];
  const API_URL = '<?= $h(function_exists('app_url') ? app_url('/api/fluxbox_action.php') : '/api/fluxbox_action.php') ?>';

  function close() {
    iframe.src = 'about:blank';
    iframe.style.display = 'none';
    noView.style.display = 'none';
    const msgView = document.getElementById('gcons-modal-msgview');
    if (msgView) msgView.style.display = 'none';
    if (typeof modal.close === 'function') modal.close();
    else modal.removeAttribute('open');
  }

  function escapeHtml(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  // Cache du classement parent (pour pré-remplir la cascade du sous-modal PJ)
  let _gcParentClassement = null;

  async function loadMsgInModal(gedDocId, name, dlUrl, openUrl) {
    const msgView = document.getElementById('gcons-modal-msgview');
    iframe.style.display = 'none';
    noView.style.display = 'none';
    foot.style.display = 'none';
    msgView.style.display = 'block';
    msgView.innerHTML = '<div style="text-align:center;padding:40px;color:#64748b;font-style:italic;">⏳ Lecture du mail Outlook…</div>';

    try {
      const sep = API_URL.includes('?') ? '&' : '?';
      const res = await fetch(API_URL + sep + 'action=read_msg&ged_doc_id=' + encodeURIComponent(String(gedDocId)));
      const data = await res.json();
      if (!data.ok) {
        msgView.innerHTML = '<div style="padding:40px;color:#dc2626;">Erreur lecture .msg : ' +
          escapeHtml((data.errors || []).join(', ') || 'inconnue') + '</div>';
        return;
      }
      const d = data.data || {};
      // Stocke le classement parent pour la cascade du sous-modal PJ
      _gcParentClassement = d.parent_classement || null;
      const validEmail = d.from_email && /^[^\s/]+@[^\s/]+\.[^\s/]+$/.test(d.from_email);
      const validTo    = d.to && d.to.length >= 5 && (/@/.test(d.to) || /;/.test(d.to));
      const warnings = (d.warnings || []).length > 0
        ? '<div style="background:#fef3c7;border-left:3px solid #ca8a04;color:#92400e;padding:8px 12px;border-radius:6px;font-size:12px;margin-bottom:14px;">⚠️ ' + escapeHtml(d.warnings.join(' · ')) + '</div>'
        : '';

      let attsHtml = '';
      if ((d.attachments || []).length > 0) {
        // Image preview triggers
        const imgExts = ['jpg','jpeg','png','gif','bmp','webp','svg'];
        const sep = API_URL.includes('?') ? '&' : '?';
        attsHtml = '<div class="gcons-msg-section"><div class="gcons-msg-section-title">📎 Pièces jointes (' +
          d.attachments.length + ')</div><ul style="list-style:none;padding:0;margin:0;">' +
          d.attachments.map(a => {
            const ext = (a.name.split('.').pop() || '').toLowerCase();
            const isImage = imgExts.includes(ext);
            const isPdf = ext === 'pdf';
            const icon = isImage ? '🖼️' : (isPdf ? '📕' : (['doc','docx'].includes(ext) ? '📘' : (['xls','xlsx'].includes(ext) ? '📗' : '📎')));
            const baseUrl = API_URL + sep + 'action=download_msg_attachment&ged_doc_id=' + encodeURIComponent(String(gedDocId)) + '&idx=' + a.idx;
            const viewUrl = baseUrl + '&mode=inline';
            const dlUrl   = baseUrl + '&mode=download';
            return '<li style="display:flex;align-items:center;gap:10px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:8px 12px;margin-bottom:4px;font-size:13px;">' +
              '<span style="font-size:18px;">' + icon + '</span>' +
              '<span style="flex:1;color:#243B5C;font-weight:600;">' + escapeHtml(a.name) + '</span>' +
              (a.size ? '<span style="color:#94a3b8;font-size:11px;">' + (a.size/1024).toFixed(1) + ' Ko</span>' : '') +
              '<button type="button" class="gcons-action-btn gcons-att-view-btn" data-view-url="' + escapeHtml(viewUrl) + '" data-dl-url="' + escapeHtml(dlUrl) + '" data-name="' + escapeHtml(a.name) + '" data-ext="' + escapeHtml(ext) + '" style="padding:4px 10px;font-size:12px;" title="Ouvrir dans une popup">👁️</button>' +
              '<a href="' + escapeHtml(dlUrl) + '" class="gcons-action-btn" style="padding:4px 10px;font-size:12px;" title="Télécharger">📥</a>' +
              '</li>';
          }).join('') + '</ul></div>';
      }

      msgView.innerHTML =
        warnings +
        '<div class="gcons-msg-section">' +
        '<div class="gcons-msg-section-title">📍 Origine du document</div>' +
        '<div class="gcons-msg-meta"><span>Expéditeur</span><span>' + (escapeHtml(d.from) || '<em style="color:#94a3b8">non détecté</em>') + '</span></div>' +
        (validEmail ? '<div class="gcons-msg-meta"><span>Email</span><code style="font-size:12px;color:#243B5C;">' + escapeHtml(d.from_email) + '</code></div>' : '') +
        (validTo ? '<div class="gcons-msg-meta"><span>Destinataire</span><span>' + escapeHtml(d.to) + '</span></div>' : '') +
        (d.date_sent ? '<div class="gcons-msg-meta"><span>Date d\'envoi</span><span>' + escapeHtml(d.date_sent) + '</span></div>' : '') +
        '</div>' +
        '<div class="gcons-msg-section">' +
        '<div class="gcons-msg-section-title">🏷️ Sujet</div>' +
        '<div style="font-size:15px;font-weight:600;color:#243B5C;">' + (escapeHtml(d.subject) || '<em style="color:#94a3b8;font-weight:400;">aucun sujet</em>') + '</div>' +
        '</div>' +
        '<div class="gcons-msg-section">' +
        '<div class="gcons-msg-section-title">📝 Contenu</div>' +
        '<div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:14px 16px;font-size:13px;line-height:1.5;white-space:pre-wrap;max-height:350px;overflow-y:auto;">' +
          (escapeHtml(d.body_text) || '<em style="color:#94a3b8;">contenu non extrait</em>') +
        '</div></div>' +
        attsHtml +
        '<div style="text-align:right;padding:14px 0 4px 0;border-top:1px dashed #cbd5e1;margin-top:16px;">' +
        '<a href="' + escapeHtml(openUrl) + '" target="_blank" rel="noopener" class="gcons-action-btn" style="display:inline-block;padding:10px 16px;margin-right:8px;">↗️ Ouvrir nouvel onglet</a>' +
        '<a href="' + escapeHtml(dlUrl) + '" class="gcons-action-btn" style="display:inline-block;padding:10px 16px;">📥 Télécharger pour Outlook</a>' +
        '</div>';
    } catch (e) {
      msgView.innerHTML = '<div style="padding:40px;color:#dc2626;">Réseau : ' + escapeHtml(e.message) + '</div>';
    }
  }

  document.querySelectorAll('.gcons-view-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const url       = btn.getAttribute('data-view-url') || '';
      const dlUrl     = btn.getAttribute('data-download-url') || url.replace('mode=inline', 'mode=download');
      const name      = btn.getAttribute('data-doc-name') || 'Document';
      const ext       = (btn.getAttribute('data-doc-ext') || '').toLowerCase();
      const gedId     = parseInt(btn.getAttribute('data-ged-id') || '0', 10);
      const isViewable = VIEWABLE_EXT.includes(ext);
      const isMsg      = ext === 'msg';

      titleEl.textContent = '👁️ ' + name;

      if (isMsg && gedId > 0) {
        // .msg → modal mail dédié (sujet, expéditeur, corps, PJ)
        loadMsgInModal(gedId, name, dlUrl, url);
      } else if (isViewable) {
        iframe.src = url;
        iframe.style.display = 'block';
        noView.style.display = 'none';
        document.getElementById('gcons-modal-msgview').style.display = 'none';
        foot.style.display = 'flex';
        openNew.href = url;
        downloadEl.href = dlUrl;
      } else {
        iframe.src = 'about:blank';
        iframe.style.display = 'none';
        document.getElementById('gcons-modal-msgview').style.display = 'none';
        noView.style.display = 'block';
        foot.style.display = 'none';
        document.getElementById('gcons-noview-title').textContent =
          'Format .' + (ext || '?') + ' non affichable en aperçu';
        document.getElementById('gcons-noview-msg').textContent =
          'Les fichiers .' + ext + ' ne peuvent pas être affichés directement dans le navigateur. ' +
          'Ouvre-le dans un nouvel onglet ou télécharge-le pour l\'ouvrir avec le bon logiciel.';
        document.getElementById('gcons-noview-open').href = url;
        document.getElementById('gcons-noview-download').href = dlUrl;
      }

      if (typeof modal.showModal === 'function') modal.showModal();
      else modal.setAttribute('open', '');
    });
  });

  modal.querySelectorAll('[data-modal-close]').forEach(el =>
    el.addEventListener('click', close));
  modal.addEventListener('cancel', (e) => { e.preventDefault(); close(); });

  /* ─── Sous-modal PJ (popup au-dessus du modal mail) ─── */
  const attModal    = document.getElementById('gcons-modal-att');
  const attIframe   = document.getElementById('gcons-att-iframe');
  const attImage    = document.getElementById('gcons-att-image');
  const attFallback = document.getElementById('gcons-att-fallback');
  const attTitle    = document.getElementById('gcons-att-title');
  const attOpenNew  = document.getElementById('gcons-att-open-new');
  const attDl       = document.getElementById('gcons-att-download');
  const IMAGE_EXT  = ['jpg','jpeg','png','gif','bmp','webp','svg'];
  const PDFFRAME_EXT = ['pdf'];
  const IFRAME_EXT = ['txt','html','htm','xml','json'];

  function showAttElement(which) {
    if (attIframe)   attIframe.style.display   = (which === 'iframe') ? 'block' : 'none';
    if (attImage)    attImage.style.display    = (which === 'image')  ? 'block' : 'none';
    if (attFallback) attFallback.style.display = (which === 'fallback') ? 'block' : 'none';
  }

  function closeAtt() {
    if (!attModal) return;
    if (attIframe) attIframe.src = 'about:blank';
    if (attImage) attImage.src = '';
    showAttElement('iframe');
    if (typeof attModal.close === 'function') attModal.close();
    else attModal.removeAttribute('open');
  }

  // État courant du sous-modal PJ (pour le bouton save)
  let _currentAtt = null;

  /* ─── Cascade GED dans le sous-modal PJ (helpers locaux) ─── */
  async function gconsLoadGedChildren(level, parents) {
    try {
      const res = await fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'ged_cascade', level, ...parents }),
        credentials: 'same-origin',
      });
      const data = await res.json();
      return data.ok ? (data.data.items || []) : [];
    } catch (_) { return []; }
  }
  function gconsRenderBtnGrid(container, items, emptyMsg, onPick, selectedCode) {
    container.innerHTML = '';
    if (!items || items.length === 0) {
      const e = document.createElement('div'); e.className = 'fbx-row-empty'; e.textContent = emptyMsg;
      container.appendChild(e); return;
    }
    items.forEach(it => {
      const btn = document.createElement('button');
      btn.type = 'button'; btn.className = 'fbx-choice-btn'; btn.dataset.code = it.code;
      if (it.code === selectedCode) btn.classList.add('is-selected');
      const lab = document.createElement('span'); lab.className = 'fbx-choice-label';
      lab.textContent = it.label || it.code; btn.appendChild(lab);
      btn.addEventListener('click', () => {
        container.querySelectorAll('.fbx-choice-btn').forEach(b => b.classList.toggle('is-selected', b === btn));
        onPick(it.code);
      });
      container.appendChild(btn);
    });
  }
  const GC_METIER_DEF = {
    '01_AGENCE':{icon:'🏬',label:'Agence',pos:1},'02_RH':{icon:'👥',label:'RH',pos:2},
    '06_COMPTABILITE':{icon:'💰',label:'Compta',pos:3},'01_DIRECTION':{icon:'⚙️',label:'Direction',pos:4},
    '07_JURIDIQUE_CONTENTIEUX':{icon:'⚖️',label:'Juridique',pos:5},'08_MARKETING_COMMUNICATION':{icon:'📣',label:'Marketing',pos:6},
    '09_MODELES_DOCUMENTS':{icon:'📄',label:'Modèles',pos:7},'10_REFERENTIEL':{icon:'📚',label:'Référentiel',pos:8},
    '12_ARCHIVES':{icon:'📦',label:'Archives',pos:9},'99_SYSTEME':{icon:'🛠️',label:'Système',pos:10},
    '11_MAILS_COMMUNICATIONS':{icon:'📧',label:'Mail & Comm',pos:11},'03_GESTION_LOCATIVE':{icon:'🏠',label:'Gestion',pos:12},
    '04_SYNDIC':{icon:'🏢',label:'Syndic',pos:13},'05_TRANSACTION':{icon:'🤝',label:'Transaction',pos:14},
    '13_FOURNISSEURS':{icon:'🚚',label:'Fournisseurs',pos:15},
  };
  const gcAttSetVal = (lvl, v) => { const el = document.getElementById('gcons-att-' + lvl); if (el) el.value = v; };
  const gcAttGetVal = (lvl) => document.getElementById('gcons-att-' + lvl)?.value || '';
  async function gcAttPickN1(code) {
    gcAttSetVal('n1', code); ['n2','n3','n4','n5'].forEach(k => gcAttSetVal(k, ''));
    document.getElementById('gcons-att-row-metiers').querySelectorAll('.fbx-choice-btn').forEach(b => b.classList.toggle('is-selected', b.dataset.code === code));
    document.getElementById('gcons-att-row-n3').innerHTML = '<div class="fbx-row-empty">—</div>';
    document.getElementById('gcons-att-row-n4').innerHTML = '<div class="fbx-row-empty">—</div>';
    document.getElementById('gcons-att-row-n5').innerHTML = '<div class="fbx-row-empty">—</div>';
    document.getElementById('gcons-att-row-n2').innerHTML = '<div class="fbx-loading">Chargement…</div>';
    const items = await gconsLoadGedChildren(2, { n1: code });
    gconsRenderBtnGrid(document.getElementById('gcons-att-row-n2'), items, '— Aucun domaine —', gcAttPickN2);
  }
  async function gcAttPickN2(code) {
    gcAttSetVal('n2', code); ['n3','n4','n5'].forEach(k => gcAttSetVal(k, ''));
    document.getElementById('gcons-att-row-n4').innerHTML = '<div class="fbx-row-empty">—</div>';
    document.getElementById('gcons-att-row-n5').innerHTML = '<div class="fbx-row-empty">—</div>';
    document.getElementById('gcons-att-row-n3').innerHTML = '<div class="fbx-loading">Chargement…</div>';
    const items = await gconsLoadGedChildren(3, { n1: gcAttGetVal('n1'), n2: code });
    gconsRenderBtnGrid(document.getElementById('gcons-att-row-n3'), items, '— Aucun sous-domaine —', gcAttPickN3);
  }
  async function gcAttPickN3(code) {
    gcAttSetVal('n3', code); ['n4','n5'].forEach(k => gcAttSetVal(k, ''));
    document.getElementById('gcons-att-row-n5').innerHTML = '<div class="fbx-row-empty">—</div>';
    document.getElementById('gcons-att-row-n4').innerHTML = '<div class="fbx-loading">Chargement…</div>';
    const items = await gconsLoadGedChildren(4, { n1: gcAttGetVal('n1'), n2: gcAttGetVal('n2'), n3: code });
    gconsRenderBtnGrid(document.getElementById('gcons-att-row-n4'), items, '— Aucune catégorie —', gcAttPickN4);
  }
  async function gcAttPickN4(code) {
    gcAttSetVal('n4', code); gcAttSetVal('n5', '');
    document.getElementById('gcons-att-row-n5').innerHTML = '<div class="fbx-loading">Chargement…</div>';
    const items = await gconsLoadGedChildren(5, { n1: gcAttGetVal('n1'), n2: gcAttGetVal('n2'), n3: gcAttGetVal('n3'), n4: code });
    gconsRenderBtnGrid(document.getElementById('gcons-att-row-n5'), items, '— Aucune sous-cat —', (c) => gcAttSetVal('n5', c));
  }
  async function initGcAttCascade(parent) {
    parent = parent || { n1:'', n2:'', n3:'', n4:'', n5:'' };
    gcAttSetVal('n1', parent.n1 || ''); gcAttSetVal('n2', parent.n2 || '');
    gcAttSetVal('n3', parent.n3 || ''); gcAttSetVal('n4', parent.n4 || '');
    gcAttSetVal('n5', parent.n5 || '');
    let grouped = [];
    try {
      const res = await fetch(API_URL, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'fbx_context' }), credentials: 'same-origin',
      });
      const data = await res.json();
      grouped = data.ok ? (data.data.metiers_grouped || []) : [];
    } catch (_) {}
    const row = document.getElementById('gcons-att-row-metiers');
    if (!row) return;
    if (!grouped || grouped.length === 0) { row.innerHTML = '<div class="fbx-row-empty">Aucun métier.</div>'; return; }
    const allCodes = []; grouped.forEach(g => (g.codes || []).forEach(c => allCodes.push(c)));
    allCodes.sort((a, b) => (GC_METIER_DEF[a.code]?.pos ?? 999) - (GC_METIER_DEF[b.code]?.pos ?? 999));
    row.innerHTML = '';
    allCodes.forEach(c => {
      const def = GC_METIER_DEF[c.code] || { icon:'📁', label:(c.label||'').replace(/^\d+\s*-\s*/, '') };
      const btn = document.createElement('button');
      btn.type='button'; btn.className='fbx-choice-btn'; btn.dataset.code=c.code; btn.title=c.code;
      if (c.code === parent.n1) btn.classList.add('is-selected');
      btn.innerHTML = `<span class="fbx-choice-icon">${def.icon}</span><span class="fbx-choice-label">${def.label}</span>`;
      btn.addEventListener('click', () => gcAttPickN1(c.code));
      row.appendChild(btn);
    });
    if (parent.n1) gconsRenderBtnGrid(document.getElementById('gcons-att-row-n2'), await gconsLoadGedChildren(2, {n1:parent.n1}), '— Aucun domaine —', gcAttPickN2, parent.n2);
    if (parent.n1 && parent.n2) gconsRenderBtnGrid(document.getElementById('gcons-att-row-n3'), await gconsLoadGedChildren(3, {n1:parent.n1, n2:parent.n2}), '— Aucun sous-domaine —', gcAttPickN3, parent.n3);
    if (parent.n1 && parent.n2 && parent.n3) gconsRenderBtnGrid(document.getElementById('gcons-att-row-n4'), await gconsLoadGedChildren(4, {n1:parent.n1, n2:parent.n2, n3:parent.n3}), '— Aucune catégorie —', gcAttPickN4, parent.n4);
    if (parent.n1 && parent.n2 && parent.n3 && parent.n4) gconsRenderBtnGrid(document.getElementById('gcons-att-row-n5'), await gconsLoadGedChildren(5, {n1:parent.n1, n2:parent.n2, n3:parent.n3, n4:parent.n4}), '— Aucune sous-cat —', (c)=>gcAttSetVal('n5', c), parent.n5);
  }

  // Délégation : clic sur n'importe quel bouton .gcons-att-view-btn (créés dynamiquement)
  document.body.addEventListener('click', (e) => {
    const btn = e.target.closest('.gcons-att-view-btn');
    if (!btn) return;
    e.preventDefault();
    const url    = btn.getAttribute('data-view-url') || '';
    const dlUrl  = btn.getAttribute('data-dl-url')   || '';
    const name   = btn.getAttribute('data-name')     || 'Pièce jointe';
    const ext    = (btn.getAttribute('data-ext')     || '').toLowerCase();
    // Extrait idx + ged_doc_id depuis l'URL pour le bouton save
    const idxMatch  = url.match(/idx=(\d+)/);
    const gedMatch  = url.match(/ged_doc_id=(\d+)/);
    _currentAtt = {
      idx: idxMatch ? parseInt(idxMatch[1], 10) : -1,
      gedDocId: gedMatch ? parseInt(gedMatch[1], 10) : 0,
      name: name,
    };
    attTitle.textContent = '📎 ' + name;
    attOpenNew.href = url;
    attDl.href      = dlUrl;
    // Choix de l'affichage selon le type
    if (IMAGE_EXT.includes(ext)) {
      // Image → balise <img> qui s'adapte au modal via object-fit: contain
      attImage.src = url;
      attImage.alt = name;
      showAttElement('image');
      attIframe.src = 'about:blank';
    } else if (PDFFRAME_EXT.includes(ext)) {
      // PDF → iframe avec #view=FitH pour zoom-to-width auto
      attIframe.src = url + '#view=FitH&toolbar=1';
      showAttElement('iframe');
    } else if (IFRAME_EXT.includes(ext)) {
      // Text/HTML → iframe simple
      attIframe.src = url;
      showAttElement('iframe');
    } else {
      // Non affichable → fallback message
      attIframe.src = 'about:blank';
      attImage.src = '';
      showAttElement('fallback');
    }
    // Reset bouton save
    const saveBtn = document.getElementById('gcons-att-save');
    if (saveBtn) { saveBtn.disabled = false; saveBtn.textContent = '💾 Enregistrer dans la GED'; saveBtn.style.background = 'linear-gradient(135deg,#16a34a,#15803d)'; }
    // Pré-remplit nom PJ
    const nameInput = document.getElementById('gcons-att-name-input');
    if (nameInput) nameInput.value = name;
    // Init cascade avec classement parent (asynchrone)
    initGcAttCascade(_gcParentClassement || { n1:'',n2:'',n3:'',n4:'',n5:'' });
    if (typeof attModal.showModal === 'function') attModal.showModal();
    else attModal.setAttribute('open', '');
  });

  // Handler du bouton "Enregistrer dans la GED"
  document.getElementById('gcons-att-save')?.addEventListener('click', async () => {
    if (!_currentAtt || _currentAtt.idx < 0 || _currentAtt.gedDocId <= 0) {
      alert('Impossible de déterminer le contexte de la PJ.');
      return;
    }
    const saveBtn = document.getElementById('gcons-att-save');
    saveBtn.disabled = true;
    saveBtn.textContent = '⏳ Enregistrement…';
    // Récupère les overrides du formulaire d'édition
    const overrideName = (document.getElementById('gcons-att-name-input')?.value || '').trim();
    const overrideClassement = {};
    ['n1','n2','n3','n4','n5'].forEach(k => {
      const v = (document.getElementById('gcons-att-' + k)?.value || '').trim();
      if (v) overrideClassement['override_' + k] = v;
    });
    try {
      const res = await fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          action: 'save_msg_attachment',
          ged_doc_id: _currentAtt.gedDocId,
          idx: _currentAtt.idx,
          override_name: overrideName,
          ...overrideClassement,
        }),
        credentials: 'same-origin',
      });
      const data = await res.json();
      if (!data.ok) {
        saveBtn.disabled = false;
        saveBtn.textContent = '💾 Enregistrer dans la GED';
        alert('Erreur : ' + ((data.errors || []).join(', ') || 'inconnue'));
        return;
      }
      const d = data.data || {};
      saveBtn.textContent = (d.is_duplicate ? '✅ Déjà enregistré' : '✅ Enregistré') +
                            (d.ged_doc_id ? ' (#' + d.ged_doc_id + ')' : '');
      saveBtn.style.background = '#16a34a';
    } catch (e) {
      saveBtn.disabled = false;
      saveBtn.textContent = '💾 Enregistrer dans la GED';
      alert('Réseau : ' + e.message);
    }
  });
  attModal?.querySelectorAll('[data-att-close]').forEach(el =>
    el.addEventListener('click', closeAtt));
  attModal?.addEventListener('cancel', (e) => { e.preventDefault(); closeAtt(); });
})();
</script>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
