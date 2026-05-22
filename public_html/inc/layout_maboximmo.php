<?php
/**
 * inc/layout_maboximmo.php — Layout normalisé unique pour tout le site MaBoxImmo
 *
 * UTILISATION dans chaque page :
 * ─────────────────────────────
 *  $layout_title        = 'Titre de la page';
 *  $layout_module       = 'Ma Box Agency · Syndic';
 *  $layout_sidebar      = 'sidebar_agency';           // fichier sidebar dans inc/ sans .php
 *  $layout_topbar_right = '<button ...>...</button>';  // boutons topbar droite (optionnel)
 *  $layout_head_kpis    = '<div class="ph-kpi">...</div>'; // KPI mini-cards (optionnel)
 *  $layout_head_filters = '<div class="ph-filter">...</div>'; // filtres centrés (optionnel)
 *  $layout_head_actions = '<a class="ph-btn">...</a>';  // boutons page-head droite (optionnel)
 *  $layout_extra_css    = '<style>...</style>';         // CSS page-spécifique (optionnel)
 *  $layout_extra_js     = '<script>...</script>';       // JS avant </body> (optionnel)
 *
 *  ob_start();
 *  // ... contenu HTML de la page ...
 *  $layout_content = ob_get_clean();
 *  require_once __DIR__ . '/inc/layout_maboximmo.php';
 */
declare(strict_types=1);

$layout_title        = $layout_title        ?? 'MaBoxImmo';
$layout_module       = $layout_module       ?? '';
$layout_sidebar      = $layout_sidebar      ?? 'sidebar_agency';
$layout_topbar_right = $layout_topbar_right ?? '';
$layout_head_kpis    = $layout_head_kpis    ?? '';
$layout_head_filters = $layout_head_filters ?? '';
$layout_head_actions = $layout_head_actions ?? '';
$layout_extra_css    = $layout_extra_css    ?? '';
$layout_extra_js     = $layout_extra_js     ?? '';
$layout_content      = $layout_content      ?? '';
// Permet de masquer complètement le page-head (KPIs + filtres + actions)
// sur les pages où il n'a pas d'utilité (ex: pages _prov du rôle user).
$layout_hide_page_head = $layout_hide_page_head ?? false;
// Titre court affiché dans le page-head (sans le nom du user).
// Si non défini, on extrait la partie avant " — " du layout_title.
$layout_page_title = $layout_page_title ?? explode(' — ', $layout_title)[0];

$_lInitials   = strtoupper(
    mb_substr($_SESSION['prenom'] ?? $_SESSION['nom'] ?? 'U', 0, 1) .
    mb_substr($_SESSION['nom'] ?? '', 0, 1)
);
$_sidebarFile = __DIR__ . '/' . basename($layout_sidebar) . '.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="<?= function_exists('csrf_token') ? csrf_token() : '' ?>">
<title><?= htmlspecialchars($layout_title) ?> — MaBoxImmo</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/tokens.css">
<link rel="stylesheet" href="css/base.css">
<link rel="stylesheet" href="css/components.css">
<link rel="stylesheet" href="css/sidebar.css">
<link rel="stylesheet" href="css/theme-white.css">
<style>
/* ═══════════════════════════════════════════════════════════════════════
   LAYOUT MABOXIMMO — Couleurs officielles : bleu pétrole, vert kaki, amande
   Ne jamais redéfinir ces classes dans les pages individuelles
   ═══════════════════════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Sora', sans-serif;
    background: #f7f8fa;
    color: #1a1816;
    overflow: hidden;
    height: 100vh;
}

/* ── Wrapper principal ────────────────────────────────────────────────── */
.mbi-layout-main {
    position: fixed;
    left: 220px;
    top: 0;
    right: 0;
    bottom: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* ── Topbar — fixe, 45px (réduit de 20% — 2026-05-17) ──────────────── */
.mbi-topbar {
    height: 45px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 0 28px 0 20px;
    background: var(--bg-primary, #ffffff);
    box-shadow: 0 4px 12px rgba(196,192,186,0.45);
    z-index: 100;
}
.mbi-topbar .tb-btn {
    width: 34px; height: 34px; border-radius: 10px;
    background: var(--bg-primary, #ffffff);
    box-shadow: 4px 4px 10px var(--shadow-dark, #d4d7de), -4px -4px 10px var(--shadow-light, #fff);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; border: none; flex-shrink: 0; color: #8a8680;
}
.mbi-topbar .tb-btn:active { box-shadow: inset 3px 3px 7px var(--shadow-dark, #d4d7de), inset -3px -3px 8px var(--shadow-light, #fff); }
.mbi-topbar .tb-btn svg { width: 15px; height: 15px; stroke: #9aaa84; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.mbi-topbar .tb-gap { width: 50px; flex-shrink: 0; }
.mbi-topbar .tb-breadcrumb {
    display: flex; align-items: center; gap: 8px;
    font-family: 'DM Mono', monospace; font-size: 13px;
    letter-spacing: 0.06em; color: #8a8680;
}
.mbi-topbar .tb-breadcrumb .tb-current { color: #4a6038; font-weight: 500; font-size: 14px; }
.mbi-topbar .tb-sep { color: #c8c4be; font-size: 16px; }
.mbi-topbar .tb-spacer { flex: 1; }
.mbi-topbar .tb-actions { display: flex; align-items: center; gap: 8px; }
.mbi-topbar .tb-avatar {
    width: 32px; height: 32px; border-radius: 50%;
    background: #36577d;
    display: flex; align-items: center; justify-content: center;
    font-family: 'DM Mono', monospace; font-size: 10px;
    color: #fff; font-weight: 500; letter-spacing: .05em;
    box-shadow: 3px 3px 8px var(--shadow-dark, #d4d7de), -3px -3px 8px var(--shadow-light, #fff);
    flex-shrink: 0;
}

/* ── Espace topbar / page-head ───────────────────────────────────────── */
.mbi-topbar-gap {
    height: 17px; flex-shrink: 0;
    background: #f7f8fa;
    border-bottom: 1px solid rgba(196,192,186,0.3);
}

/* ── Page-head — fixe, 96px ──────────────────────────────────────────── */
.mbi-page-head {
    height: 96px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 28px;
    background: var(--bg-secondary, #f7f8fa);
    border-bottom: 1px solid rgba(196,192,186,0.3);
    gap: 16px;
    position: relative;
    z-index: 50;
}
.mbi-page-head .ph-left { display: flex; align-items: center; flex-shrink: 0; }
.mbi-page-head .ph-kpi-strip {
    display: flex; flex-wrap: wrap; gap: 14px;
}
.mbi-page-head .ph-kpi {
    background: var(--bg-primary, #ffffff);
    border-radius: 8px;
    box-shadow: 2px 2px 5px var(--shadow-dark, #d4d7de), -2px -2px 5px var(--shadow-light, #fff);
    padding: 4px 8px;
    display: flex; flex-direction: column; gap: 1px;
}
.mbi-page-head .ph-kpi-val {
    font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 600;
    color: #2f587d; line-height: 1;
}
.mbi-page-head .ph-kpi-lbl {
    font-family: 'DM Mono', monospace; font-size: 7px;
    text-transform: uppercase; letter-spacing: .08em; color: #a8a49e;
}

/* Filtres centrés */
.mbi-page-head .ph-center { display: flex; align-items: center; gap: 10px; flex: 1; justify-content: center; }
.mbi-page-head .ph-filter { display: flex; flex-direction: column; gap: 3px; }
.mbi-page-head .ph-filter label {
    font-family: 'DM Mono', monospace; font-size: 8px; font-weight: 600;
    letter-spacing: .12em; text-transform: uppercase; color: #a8a49e;
}
.mbi-page-head .ph-filter select,
.mbi-page-head .ph-filter input {
    height: 34px; padding: 0 10px; min-width: 132px;
    background: #e8efe0;
    box-shadow: inset 2px 2px 5px rgba(180,190,170,0.5), inset -2px -2px 5px #fff;
    border: none; border-radius: 8px;
    font-family: 'Sora', sans-serif; font-size: 11px; color: #1a1816; outline: none;
}

/* Boutons page-head (card 2x2) */
.mbi-page-head .ph-right {
    flex-shrink: 0;
    background: var(--bg-primary, #ffffff);
    border-radius: 12px;
    box-shadow: 2px 2px 5px var(--shadow-dark, #d4d7de), -2px -2px 5px var(--shadow-light, #fff);
    padding: 14px 16px;
    display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;
}
.ph-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 3px;
    height: 18px; padding: 0 6px; border-radius: 6px; width: 68px;
    cursor: pointer; border: none; outline: none;
    font-family: 'Sora', sans-serif; font-size: 8px; letter-spacing: .03em;
    background: var(--bg-primary, #ffffff);
    box-shadow: 1px 1px 3px var(--shadow-dark, #d4d7de), -1px -1px 3px var(--shadow-light, #fff);
    font-weight: 600; color: #6a6660; text-decoration: none;
}
.ph-btn:hover { box-shadow: 2px 2px 5px var(--shadow-dark, #d4d7de), -2px -2px 5px var(--shadow-light, #fff); color: #3a3830; }
.ph-btn:active { box-shadow: inset 2px 2px 4px var(--shadow-dark, #d4d7de), inset -2px -2px 4px var(--shadow-light, #fff); }
.ph-btn.primary { background: #4878a6; color: #fff; }
.ph-btn.primary:hover { opacity: .9; }
.ph-btn.dispo { color: #c8c4be; font-style: italic; }
.ph-btn.dispo:hover { color: #a8a49e; }
.ph-btn svg { width: 8px; height: 8px; stroke: currentColor; fill: none; stroke-width: 2.5; stroke-linecap: round; stroke-linejoin: round; }

/* ── Contenu scrollable ──────────────────────────────────────────────── */
.mbi-content {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 20px 28px 40px;
    scrollbar-width: thin;
    scrollbar-color: #b8c8a8 transparent;
    max-width: 100%;
    box-sizing: border-box;
}
@media (max-width: 768px) {
    .mbi-content { padding: 12px 12px 30px; }
    .mbi-page-head { flex-wrap: wrap; gap: 8px; padding: 8px 12px; }
    .mbi-page-head .ph-left { flex-wrap: wrap; }
    .mbi-page-head .ph-right { flex-wrap: wrap; }
}
.mbi-content::-webkit-scrollbar { width: 5px; }
.mbi-content::-webkit-scrollbar-thumb { background: #b8c8a8; border-radius: 3px; }

/* ── Footer ──────────────────────────────────────────────────────────── */
.mbi-footer {
    flex-shrink: 0;
    height: 28px;
    display: flex; align-items: center; justify-content: center;
    font-family: 'DM Mono', monospace; font-size: 10px;
    color: #a8a49e; letter-spacing: .06em;
    border-top: 1px solid rgba(196,192,186,0.35);
    background: var(--bg-primary, #ffffff);
}

/* ═══════════════════════════════════════════════════════════════════════
   COMPOSANTS COMMUNS — réutilisables dans toutes les pages
   Couleurs : bleu pétrole #2f587d, vert kaki #4a6038, amande #b8c8a8
              terre cuite #8a5040, ocre #7a6830, émeraude #3a7a6a
   ═══════════════════════════════════════════════════════════════════════ */

/* Section title — barres fines, texte vert pétrole */
.section-header {
    display: flex; align-items: center; justify-content: space-between;
    margin: 24px 0 14px;
}
.section-title {
    display: flex; align-items: center; gap: 14px; flex: 1;
}
.sec-txt {
    font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500;
    letter-spacing: 0.28em; text-transform: uppercase; white-space: nowrap; flex-shrink: 0;
    background: linear-gradient(180deg, #7a9060 0%, #4a6038 40%, #304828 70%, #607848 100%);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.line-l {
    height: 1px; width: 28px; flex-shrink: 0; border-radius: 2px;
    background: linear-gradient(90deg, transparent 0%, #304828 40%, #9ab870 100%);
}
.line-r {
    height: 1px; flex: 1; border-radius: 2px;
    background: linear-gradient(90deg, #9ab870 0%, #607848 30%, #4a6038 55%, transparent 100%);
}

/* View toggle */
.view-toggle {
    display: flex; gap: 3px; background: var(--bg-primary, #ffffff);
    border-radius: 10px; padding: 3px;
    box-shadow: inset 3px 3px 7px var(--shadow-dark, #d4d7de), inset -3px -3px 8px var(--shadow-light, #fff);
    flex-shrink: 0;
}
.view-btn {
    width: 30px; height: 26px; border-radius: 7px; border: none;
    background: transparent; cursor: pointer; color: #8a8680;
    display: flex; align-items: center; justify-content: center;
    text-decoration: none;
}
.view-btn svg { width: 14px; height: 14px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.view-btn.active {
    background: var(--bg-primary, #ffffff);
    box-shadow: 3px 3px 7px var(--shadow-dark, #d4d7de), -3px -3px 8px var(--shadow-light, #fff);
    color: #2f587d;
}

/* Table */
.mbi-table-wrap {
    background: var(--bg-primary, #ffffff);
    border-radius: 16px;
    box-shadow: 6px 6px 14px var(--shadow-dark, #d4d7de), -6px -6px 14px var(--shadow-light, #fff);
    border: 2px solid #b8c8a8;
    overflow: hidden;
}
.mbi-table { width: 100%; border-collapse: collapse; }
.mbi-table thead th {
    padding: 10px 14px; text-align: left; white-space: nowrap;
    font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 700;
    letter-spacing: .14em; text-transform: uppercase; color: #4a6038;
    border-bottom: 1px solid rgba(196,192,186,0.5); background: var(--bg-secondary, #f0f1f3);
}
.mbi-table tbody td {
    padding: 10px 14px; font-size: 12px; color: #1a1816;
    border-bottom: 1px solid rgba(196,192,186,0.25); vertical-align: middle;
}
.mbi-table tbody tr:last-child td { border-bottom: none; }
.mbi-table tbody tr:hover td { background: rgba(72,120,166,0.04); }

.td-mono { font-family: 'DM Mono', monospace; font-size: 11px; color: #8a8680; letter-spacing: .06em; }
.td-name { font-size: 13px; font-weight: 600; color: #2f587d; }
.td-accent { font-family: 'DM Mono', monospace; color: #4878a6; font-weight: 600; }
.td-sm { font-size: 10px; }

/* Badge — relief neumorphique */
.mbi-badge {
    display: inline-flex; align-items: center; justify-content: center;
    padding: 3px 0; height: 22px; width: 62px;
    border-radius: 999px;
    font-family: 'DM Mono', monospace; font-size: 9px;
    font-weight: 700; letter-spacing: .06em; text-transform: uppercase; white-space: nowrap;
    box-shadow: 2px 2px 5px rgba(180,185,175,0.4), -2px -2px 5px #fff;
}

/* Mini-cards grid */
.cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 16px;
}
.mini-card {
    background: var(--bg-primary, #ffffff);
    border-radius: 16px;
    box-shadow: 6px 6px 14px var(--shadow-dark, #d4d7de), -6px -6px 14px var(--shadow-light, #fff);
    padding: 14px 16px; display: flex; flex-direction: column; gap: 8px;
    transition: box-shadow .15s, transform .12s;
}
.mini-card:hover { box-shadow: 8px 8px 18px #c8cbd2, -8px -8px 18px #fff; transform: translateY(-2px); }
.mini-card-head { display: flex; align-items: center; justify-content: space-between; }
.mini-card-name { font-size: 13px; font-weight: 700; color: #2f587d; line-height: 1.3; }
.mini-card-meta { font-size: 11px; color: #8a8680; }
.mini-card-foot { display: flex; align-items: center; justify-content: space-between; padding-top: 8px; border-top: 1px solid rgba(196,192,186,0.3); }

/* Card neumorphique */
.mbi-card {
    background: var(--bg-primary, #ffffff);
    border-radius: 16px;
    box-shadow: 6px 6px 14px var(--shadow-dark, #d4d7de), -6px -6px 14px var(--shadow-light, #fff);
    padding: 18px 20px;
}

/* AG rows */
.ag-row {
    display: flex; align-items: center; gap: 12px;
    padding: 8px 0; border-bottom: 1px solid rgba(196,192,186,0.2);
}
.ag-row:last-child { border-bottom: none; }
.ag-icon {
    width: 36px; height: 36px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.ag-icon svg { width: 15px; height: 15px; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.ag-body { flex: 1; min-width: 0; }
.ag-name { font-size: 12px; font-weight: 600; color: #1a1816; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ag-date { font-family: 'DM Mono', monospace; font-size: 10px; color: #a8a49e; }
.ag-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }

/* Alert rows */
.alert-list { display: flex; flex-direction: column; gap: 8px; }
.alert-row {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 14px; border-radius: 12px;
    background: var(--bg-primary, #ffffff);
    box-shadow: 4px 4px 10px var(--shadow-dark, #d4d7de), -4px -4px 10px var(--shadow-light, #fff);
}
.alert-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.alert-body { flex: 1; min-width: 0; }
.alert-title { font-size: 12px; font-weight: 600; color: #1a1816; }
.alert-sub { font-size: 10.5px; color: #8a8680; margin-top: 2px; }

/* Input neumorphique */
.mbi-input {
    height: 34px; padding: 0 12px;
    background: var(--bg-primary, #ffffff);
    box-shadow: inset 3px 3px 6px var(--shadow-dark, #d4d7de), inset -3px -3px 8px var(--shadow-light, #fff);
    border: none; border-radius: 8px;
    font-family: 'Sora', sans-serif; font-size: 12px; color: #1a1816;
    outline: none; width: 100%;
}
.mbi-input:focus { box-shadow: inset 3px 3px 6px var(--shadow-dark, #d4d7de), inset -3px -3px 8px var(--shadow-light, #fff), 0 0 0 2px rgba(72,120,166,0.3); }

/* ═══ RESPONSIVE ═══ */
@media (max-width: 900px) {
    .mbi-layout-main { margin-left: 0; left: 0; width: 100%; }
    .cards-grid { grid-template-columns: 1fr; }
    .mbi-page-head { height: auto; flex-wrap: wrap; padding: 12px 16px; }
    .mbi-page-head .ph-kpi-strip { grid-template-columns: repeat(3, 1fr); }
}
</style>
<?= $layout_extra_css ?>
</head>
<body>

<?php if (file_exists($_sidebarFile)): ?>
    <?php include $_sidebarFile; ?>
<?php endif; ?>

<div class="mbi-layout-main">

    <!-- ── Topbar ── -->
    <div class="mbi-topbar">
        <button class="tb-btn" onclick="history.back()" title="Retour">
            <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
        <button class="tb-btn" onclick="history.forward()" title="Avancer">
            <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
        <div class="tb-gap"></div>
        <div class="tb-breadcrumb">
            <?php if ($layout_module): ?>
                <span><?= htmlspecialchars($layout_module) ?></span>
                <span class="tb-sep">›</span>
            <?php endif; ?>
            <span class="tb-current"><?= htmlspecialchars($layout_title) ?></span>
        </div>
        <div class="tb-spacer"></div>
        <div class="tb-actions">
            <?= $layout_topbar_right ?>
            <button type="button" id="fbx-upload-open" class="tb-btn fbx-topbar-btn"
                    title="Charger des documents (Ctrl+U)" aria-label="Charger des documents">
                <span style="font-size:15px;">📥</span>
                <span class="fbx-topbar-btn-label">Charger</span>
                <span class="fbx-badge-live" id="fbx-topbar-badge" style="display:none;">0</span>
            </button>
            <div class="tb-avatar"><?= htmlspecialchars($_lInitials) ?></div>
        </div>
    </div>

    <?php if (!$layout_hide_page_head): ?>
    <!-- ── Espace topbar / page-head ── -->
    <div class="mbi-topbar-gap"></div>

    <!-- ── Page-head ── -->
    <div class="mbi-page-head">
        <div class="ph-left" style="display:flex;align-items:center;gap:20px">
            <div style="font-size:15px;font-weight:700;color:#2f587d;white-space:nowrap"><?= htmlspecialchars($layout_page_title) ?></div>
            <?php if ($layout_head_kpis): ?>
            <div class="ph-kpi-strip">
                <?= $layout_head_kpis ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($layout_head_filters): ?>
        <div class="ph-center">
            <?= $layout_head_filters ?>
        </div>
        <?php endif; ?>

        <?php if ($layout_head_actions): ?>
        <div class="ph-right">
            <?= $layout_head_actions ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Contenu scrollable ── -->
    <div class="mbi-content">
        <?= $layout_content ?>
    </div>

    <!-- ── Footer ── -->
    <div class="mbi-footer">
        MaBoxImmo <?= date('Y') ?> · <?= htmlspecialchars($layout_module ?: $layout_title) ?>
    </div>

</div>

<?= $layout_extra_js ?>

<?php
// ── Modale FluxBox d'upload universelle (disponible sur toutes les pages) ──
$_fbxModalPath = __DIR__ . '/fluxbox_upload_modal.php';
if (is_file($_fbxModalPath)) {
    require $_fbxModalPath;
}
?>
</body>
</html>
