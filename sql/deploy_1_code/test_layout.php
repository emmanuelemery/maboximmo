<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo    = $GLOBALS['pdo'];
$roleId = (int)current_role_id();
$userId = current_user_id();
$initials = strtoupper(
    mb_substr($_SESSION['prenom'] ?? 'U', 0, 1) .
    mb_substr($_SESSION['nom'] ?? '', 0, 1)
);

$vue = $_GET['vue'] ?? 'list';

// ── Données démo ─────────────────────────────────────────────────
$rows = [
    ['CTR-2024-001', 'Résidence Les Acacias', 'Lyon',         48, '8 400 €',  '01/01/2024', 'actif'],
    ['CTR-2024-002', 'Copropriété du Parc',   'Villeurbanne', 32, '5 600 €',  '01/03/2024', 'actif'],
    ['CTR-2023-018', 'Immeuble Bellecour',     'Lyon',         64, '11 200 €', '01/06/2023', 'expiré'],
    ['CTR-2024-003', 'Le Clos Fleuri',         'Bron',         24, '4 200 €',  '01/05/2024', 'actif'],
    ['CTR-2024-004', 'Tour Horizon',           'Caluire',      96, '16 800 €', '01/07/2024', 'actif'],
    ['CTR-2023-021', 'Villa des Roses',        'Écully',       18, '3 150 €',  '15/09/2023', 'relance'],
    ['CTR-2024-005', 'Les Terrasses',          'Oullins',      56, '9 800 €',  '01/02/2024', 'actif'],
    ['CTR-2023-012', 'Parc des Cèdres',       'Tassin',       42, '7 350 €',  '01/04/2023', 'expiré'],
];

$statusMap = [
    'actif'   => ['Actif',   '#3a7a6a', '#e0f0eb'],
    'expiré'  => ['Expiré',  '#8a5040', '#f5e8e3'],
    'relance' => ['Relance', '#7a6830', '#f2edd8'],
];

$ags = [
    ['Résidence Les Acacias', '12 avr. 2026', 3,  '#8a5040'],
    ['Copropriété du Parc',   '28 avr. 2026', 19, '#7a6830'],
    ['Le Clos Fleuri',        '15 mai 2026',  52, '#2f587d'],
    ['Tour Horizon',          '03 juin 2026', 71, '#2f587d'],
];

$alerts = [
    ['Imm. Bellevue — fiche incomplète',  'Manque infos syndic',          '#8a5040'],
    ['Villa Pasteur — AG non planifiée',   'Aucune date AG 2026',          '#7a6830'],
    ['Le Cèdre Bleu — contrat expiré',    'Depuis le 15/01/2026',         '#8a5040'],
    ['Résid. Champagne — OK',             'Tous les documents à jour',    '#3a7a6a'],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Contrats Syndic — MaBoxImmo</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/tokens.css">
<link rel="stylesheet" href="css/base.css">
<link rel="stylesheet" href="css/components.css">
<link rel="stylesheet" href="css/sidebar.css">
<style>
/* ═══════════════════════════════════════════════════════
   LAYOUT
═══════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Sora', sans-serif;
    background: #f7f8fa;
    color: #1a1816;
    overflow: hidden;
    height: 100vh;
}

/* Main wrapper */
.mbi-layout-main {
    margin-left: 220px;
    height: 100vh;
    display: flex;
    flex-direction: column;
    width: calc(100% - 220px);
    overflow: hidden;
}

/* ── Topbar — fixe, 56px, style rh_dashboard ── */
.mbi-topbar {
    height: 56px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 0 28px 0 20px;
    background: var(--bg-primary, #ffffff);
    box-shadow: 0 4px 12px rgba(196,192,186,0.45);
    z-index: 100;
}
.tb-btn {
    width: 34px; height: 34px; border-radius: 10px;
    background: var(--bg-primary, #ffffff);
    box-shadow: 4px 4px 10px var(--shadow-dark, #d4d7de), -4px -4px 10px var(--shadow-light, #fff);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; border: none; flex-shrink: 0; color: #8a8680;
}
.tb-btn:active { box-shadow: inset 3px 3px 7px var(--shadow-dark, #d4d7de), inset -3px -3px 8px var(--shadow-light, #fff); }
.tb-btn svg { width: 15px; height: 15px; stroke: #9aaa84; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
.tb-gap { width: 50px; flex-shrink: 0; }
.tb-breadcrumb {
    display: flex; align-items: center; gap: 8px;
    font-family: 'DM Mono', monospace; font-size: 13px;
    letter-spacing: 0.06em; color: #8a8680;
}
.tb-breadcrumb .tb-current { color: #4a6038; font-weight: 500; font-size: 14px; }
.tb-sep { color: #c8c4be; font-size: 16px; }
.tb-spacer { flex: 1; }
.tb-actions { display: flex; align-items: center; gap: 8px; }
.tb-avatar {
    width: 32px; height: 32px; border-radius: 50%;
    background: #36577d;
    display: flex; align-items: center; justify-content: center;
    font-family: 'DM Mono', monospace; font-size: 10px;
    color: #fff; font-weight: 500; letter-spacing: .05em;
    box-shadow: 3px 3px 8px var(--shadow-dark, #d4d7de), -3px -3px 8px var(--shadow-light, #fff);
    flex-shrink: 0;
}

/* ── Page-head — fixe, 96px ── */
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
.ph-left { display: flex; align-items: center; flex-shrink: 0; }
.ph-kpi-strip {
    display: grid; grid-template-columns: repeat(3, 80px); gap: 5px;
}
.ph-kpi {
    width: 80px;
    background: var(--bg-primary, #ffffff);
    border-radius: 8px;
    box-shadow: 2px 2px 5px var(--shadow-dark, #d4d7de), -2px -2px 5px var(--shadow-light, #fff);
    padding: 4px 8px;
    display: flex; flex-direction: column; gap: 1px;
}
.ph-kpi-val {
    font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 600;
    color: #2f587d; line-height: 1;
}
.ph-kpi-lbl {
    font-family: 'DM Mono', monospace; font-size: 7px;
    text-transform: uppercase; letter-spacing: .08em; color: #a8a49e;
}

/* Filtres centrés */
.ph-center { display: flex; align-items: center; gap: 10px; flex: 1; justify-content: center; }
.ph-filter {
    display: flex; flex-direction: column; gap: 3px;
}
.ph-filter label {
    font-family: 'DM Mono', monospace; font-size: 8px; font-weight: 600;
    letter-spacing: .12em; text-transform: uppercase; color: #a8a49e;
}
.ph-filter select,
.ph-filter input {
    height: 34px; padding: 0 10px; min-width: 132px;
    background: #e8efe0;
    box-shadow: inset 2px 2px 5px rgba(180,190,170,0.5), inset -2px -2px 5px #fff;
    border: none; border-radius: 8px;
    font-family: 'Sora', sans-serif; font-size: 11px; color: #1a1816; outline: none;
}
.ph-right {
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

/* ── Contenu scrollable ── */
.mbi-content {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 20px 28px 40px;
    scrollbar-width: thin;
    scrollbar-color: #b8c8a8 transparent;
}
.mbi-content::-webkit-scrollbar { width: 5px; }
.mbi-content::-webkit-scrollbar-thumb { background: #b8c8a8; border-radius: 3px; }

/* ── Footer ── */
.mbi-footer {
    flex-shrink: 0;
    height: 28px;
    display: flex; align-items: center; justify-content: center;
    font-family: 'DM Mono', monospace; font-size: 10px;
    color: #a8a49e; letter-spacing: .06em;
    border-top: 1px solid rgba(196,192,186,0.35);
    background: var(--bg-primary, #ffffff);
}

/* ═══════════════════════════════════════════════════════
   COMPOSANTS COMMUNS
═══════════════════════════════════════════════════════ */

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

/* ═══ RESPONSIVE ═══ */
@media (max-width: 900px) {
    .mbi-layout-main { margin-left: 0; width: 100%; }
    .cards-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<?php include __DIR__ . '/inc/sidebar_agency.php'; ?>

<div class="mbi-layout-main">

    <!-- ── TOPBAR (fixe, 56px, boutons nav à gauche) ── -->
    <div class="mbi-topbar">
        <button class="tb-btn" onclick="history.back()" title="Retour">
            <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
        </button>
        <button class="tb-btn" onclick="history.forward()" title="Avancer">
            <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
        </button>
        <div class="tb-gap"></div>
        <div class="tb-breadcrumb">
            <span>Ma Box Agency · Syndic</span>
            <span class="tb-sep">›</span>
            <span class="tb-current">Contrats Syndic</span>
        </div>
        <div class="tb-spacer"></div>
        <div class="tb-actions">
            <button class="tb-btn" title="Notifications" style="position:relative">
                <svg viewBox="0 0 24 24" style="stroke:#8a8680"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
                <span style="position:absolute;top:6px;right:6px;width:7px;height:7px;border-radius:50%;background:#cc5c58;border:2px solid var(--bg-primary,#ffffff)"></span>
            </button>
            <div class="tb-avatar"><?= htmlspecialchars($initials) ?></div>
        </div>
    </div>

    <!-- ── Espace topbar / page-head ── -->
    <div style="height:17px;flex-shrink:0;background:#f7f8fa;border-bottom:1px solid rgba(196,192,186,0.3)"></div>

    <!-- ── PAGE-HEAD (fixe, 96px, infos sélection + actions) ── -->
    <div class="mbi-page-head">
        <div class="ph-left">
            <div class="ph-kpi-strip">
                <div class="ph-kpi">
                    <div class="ph-kpi-val"><?= count($rows) ?></div>
                    <div class="ph-kpi-lbl">Contrats</div>
                </div>
                <div class="ph-kpi">
                    <div class="ph-kpi-val">380</div>
                    <div class="ph-kpi-lbl">Lots</div>
                </div>
                <div class="ph-kpi">
                    <div class="ph-kpi-val" style="color:#4878a6">66 550 €</div>
                    <div class="ph-kpi-lbl">CA HT</div>
                </div>
                <div class="ph-kpi">
                    <div class="ph-kpi-val" style="color:#8a5040">2</div>
                    <div class="ph-kpi-lbl">Expirés</div>
                </div>
                <div class="ph-kpi">
                    <div class="ph-kpi-val" style="color:#7a6830">1</div>
                    <div class="ph-kpi-lbl">Relances</div>
                </div>
                <div class="ph-kpi">
                    <div class="ph-kpi-val" style="color:#3a7a6a">5</div>
                    <div class="ph-kpi-lbl">AG ce mois</div>
                </div>
            </div>
        </div>
        <div class="ph-center">
            <div class="ph-filter">
                <label>Statut</label>
                <select>
                    <option>— Tous —</option>
                    <option>Actif</option>
                    <option>Expiré</option>
                    <option>Relance</option>
                </select>
            </div>
            <div class="ph-filter">
                <label>Ville</label>
                <input type="text" placeholder="Rechercher...">
            </div>
            <div class="ph-filter">
                <label>Établissement</label>
                <select>
                    <option>— Tous —</option>
                    <option>Lyon</option>
                    <option>Villeurbanne</option>
                </select>
            </div>
        </div>
        <div class="ph-right">
            <a href="#" class="ph-btn primary">
                <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Nouveau
            </a>
            <a href="#" class="ph-btn primary">
                <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Exporter
            </a>
            <a href="#" class="ph-btn dispo">dispo</a>
            <a href="#" class="ph-btn dispo">dispo</a>
        </div>
    </div>

    <!-- ── CONTENU SCROLLABLE (seule zone qui défile) ── -->
    <div class="mbi-content">

        <!-- Section titre + vue toggle -->
        <div class="section-header">
            <div class="view-toggle">
                <a href="?vue=list" class="view-btn <?= $vue === 'list' ? 'active' : '' ?>" title="Liste">
                    <svg viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                </a>
                <a href="?vue=cards" class="view-btn <?= $vue === 'cards' ? 'active' : '' ?>" title="Mini-cards">
                    <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                </a>
            </div>
            <div class="section-title">
                <div class="line-l"></div>
                <span class="sec-txt">Liste des contrats</span>
                <div class="line-r"></div>
            </div>
        </div>

        <?php if ($vue === 'list'): ?>
        <!-- ═══ VUE LISTE ═══ -->
        <div class="mbi-table-wrap">
            <table class="mbi-table">
                <thead>
                    <tr>
                        <th>Référence</th>
                        <th>Immeuble</th>
                        <th>Ville</th>
                        <th>Lots</th>
                        <th>Honoraires HT</th>
                        <th>Date début</th>
                        <th>Statut</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $st = $statusMap[$r[6]] ?? ['?','#808080','#e8e8e8'];
                    ?>
                    <tr>
                        <td><span class="td-mono"><?= $r[0] ?></span></td>
                        <td><strong class="td-name"><?= $r[1] ?></strong></td>
                        <td><?= $r[2] ?></td>
                        <td><span class="td-mono"><?= $r[3] ?></span></td>
                        <td><span class="td-accent"><?= $r[4] ?></span></td>
                        <td><span class="td-mono td-sm"><?= $r[5] ?></span></td>
                        <td><span class="mbi-badge" style="background:<?= $st[2] ?>;color:<?= $st[1] ?>"><?= $st[0] ?></span></td>
                        <td style="text-align:right"><a href="#" class="ph-btn" style="height:26px;font-size:10px">Voir</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php else: ?>
        <!-- ═══ VUE MINI-CARDS ═══ -->
        <div class="cards-grid">
            <?php foreach ($rows as $r):
                $st = $statusMap[$r[6]] ?? ['?','#808080','#e8e8e8'];
            ?>
            <div class="mini-card">
                <div class="mini-card-head">
                    <span class="td-mono"><?= $r[0] ?></span>
                    <span class="mbi-badge" style="background:<?= $st[2] ?>;color:<?= $st[1] ?>"><?= $st[0] ?></span>
                </div>
                <div class="mini-card-name"><?= $r[1] ?></div>
                <div class="mini-card-meta"><?= $r[2] ?> · <?= $r[3] ?> lots</div>
                <div class="mini-card-foot">
                    <span class="td-accent"><?= $r[4] ?></span>
                    <span class="td-mono td-sm"><?= $r[5] ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ═══ Section 2 : deux colonnes ═══ -->
        <div class="section-header" style="margin-top:28px">
            <div class="section-title">
                <div class="line-l"></div>
                <span class="sec-txt">Suivi & Alertes</span>
                <div class="line-r"></div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
            <div class="mbi-card">
                <?php foreach ($ags as $ag): ?>
                <div class="ag-row">
                    <div class="ag-icon" style="background:<?= $ag[3] ?>18">
                        <svg viewBox="0 0 24 24" style="stroke:<?= $ag[3] ?>"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                    <div class="ag-body">
                        <div class="ag-name"><?= $ag[0] ?></div>
                        <div class="ag-date"><?= $ag[1] ?> · dans <?= $ag[2] ?> j</div>
                    </div>
                    <div class="ag-dot" style="background:<?= $ag[3] ?>"></div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="mbi-card">
                <div class="alert-list">
                    <?php foreach ($alerts as $a): ?>
                    <div class="alert-row">
                        <div class="alert-dot" style="background:<?= $a[2] ?>"></div>
                        <div class="alert-body">
                            <div class="alert-title"><?= $a[0] ?></div>
                            <div class="alert-sub"><?= $a[1] ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

    </div><!-- /mbi-content -->

    <!-- ── FOOTER ── -->
    <div class="mbi-footer">
        MaBoxImmo <?= date('Y') ?> · Ma Box Agency · Syndic
    </div>

</div><!-- /mbi-layout-main -->

</body>
</html>
