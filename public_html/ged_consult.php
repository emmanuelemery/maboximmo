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
    $sql = "SELECT d.id, d.uuid, d.name_display, d.name_canonical, d.source_module,
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
                            <a href="<?= $h($viewUrl) ?>&mode=inline" target="_blank" rel="noopener"
                               class="gcons-action-btn" title="Ouvrir dans un nouvel onglet">👁️</a>
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

</div>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
?>
