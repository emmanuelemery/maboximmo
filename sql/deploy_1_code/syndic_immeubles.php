<?php
/*
 * syndic_immeubles.php
 * Rôle  : Liste des immeubles — Module Syndic V2
 * Thème : Bleu-gris clair
 * Dépend: inc/bootstrap.php, inc/auth.php, sidebar_syndic.php,
 *         css/tokens.css, css/base.css, css/components.css,
 *         css/layout.css, css/theme-syndic.css
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$roleId = current_role_id();   // 1=admin, 2=manager, 3=user
$pdo    = $GLOBALS['pdo'];

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ── Filtres GET ──────────────────────────────────────────────
$fRef     = trim($_GET['reference']    ?? '');
$fNom     = trim($_GET['nom']          ?? '');
$fVille   = trim($_GET['ville']        ?? '');
$fType    = $_GET['type']              ?? '';
$fEtab    = (int)($_GET['etablissement'] ?? 0);
$fGest    = (int)($_GET['gestionnaire']  ?? 0);
$fVue     = $_GET['vue']               ?? 'cards';   // cards | table
$sort     = $_GET['sort']              ?? 'i.reference';
$order    = strtoupper($_GET['order']  ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

// Colonnes triables autorisées
$sortAllowed = ['i.reference','i.nom','i.ville','i.nb_lots','infos.honoraires_ht'];
if (!in_array($sort, $sortAllowed, true)) $sort = 'i.reference';

// ── Construction requête ─────────────────────────────────────
$conds  = [];
$params = [];

if ($fRef !== '') { $conds[] = 'i.reference LIKE ?'; $params[] = "%$fRef%"; }
if ($fNom !== '') { $conds[] = 'i.nom LIKE ?';       $params[] = "%$fNom%"; }
if ($fVille !== '') { $conds[] = 'i.ville LIKE ?';   $params[] = "%$fVille%"; }
if ($fType !== '') { $conds[] = 'i.type = ?';        $params[] = $fType; }
if ($fEtab > 0)   { $conds[] = 'i.id_etablissement = ?'; $params[] = $fEtab; }
if ($fGest > 0)   { $conds[] = 'i.gestionnaire = ?'; $params[] = $fGest; }

// Manager : restreindre à son établissement
if ($roleId === 2) {
    $myEtab = (int)($_SESSION['id_etablissement'] ?? 0);
    if ($myEtab > 0) { $conds[] = 'i.id_etablissement = ?'; $params[] = $myEtab; }
}

$where = $conds ? ' WHERE ' . implode(' AND ', $conds) : '';

$sql = "
    SELECT i.*,
           e.nom  AS nom_etablissement,
           u.nom_complet AS nom_gestionnaire,
           infos.honoraires_ht, infos.date_ag_prochaine,
           infos.hono_2026, infos.hono_2027
    FROM immeubles i
    LEFT JOIN etablissements e    ON i.id_etablissement = e.id
    LEFT JOIN users u             ON i.gestionnaire = u.id
    LEFT JOIN immeubles_infos infos ON infos.id_immeuble = i.id
    $where
    ORDER BY $sort $order
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$immeubles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── KPIs ─────────────────────────────────────────────────────
$totalImm  = count($immeubles);
$totalLots = array_sum(array_column($immeubles, 'nb_lots'));
$totalHono = array_sum(array_map(fn($r) => (float)($r['honoraires_ht'] ?? 0), $immeubles));
$nbAGMonth = count(array_filter($immeubles, fn($r) =>
    !empty($r['date_ag_prochaine']) &&
    substr($r['date_ag_prochaine'], 0, 7) === date('Y-m')
));

// ── Référentiels filtres ─────────────────────────────────────
$etablissements = $pdo->query("SELECT id, nom FROM etablissements ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$gestionnaires  = $pdo->query("SELECT id, nom_complet FROM users WHERE actif=1 ORDER BY nom_complet")->fetchAll(PDO::FETCH_ASSOC);

// ── Helper : sort link ────────────────────────────────────────
function sortLink(string $field, string $label, string $cur, string $ord): string {
    $params = $_GET; $params['sort'] = $field;
    $params['order'] = ($cur === $field && $ord === 'ASC') ? 'DESC' : 'ASC';
    $arrow = ($cur === $field) ? ($ord === 'ASC' ? ' ▲' : ' ▼') : '';
    return '<a href="?' . http_build_query($params) . '" style="color:inherit;text-decoration:none">'
         . htmlspecialchars($label) . $arrow . '</a>';
}

// ── Helper : type badge ───────────────────────────────────────
function typeBadge(?string $type): string {
    $map = [
        'sdc'        => ['sdc',    'SDC'],
        'Appartement'=> ['sdc',    'Appt'],
        'Maison individuelle' => ['maison','Maison'],
        'Maison  - Jumelée'  => ['maison','Jumelée'],
        'Local commercial'   => ['local', 'Local'],
        'Garage'     => ['garage', 'Garage'],
        'autre'      => ['autre',  'Autre'],
    ];
    $t = $type ?? '';
    foreach ($map as $key => [$cls, $lbl]) {
        if (stripos($t, $key) !== false) return "<span class=\"imm-type-badge $cls\">$lbl</span>";
    }
    return '<span class="imm-type-badge autre">' . htmlspecialchars($t ?: '—') . '</span>';
}

// ── Date AG proche ? ──────────────────────────────────────────
function agStatus(?string $dateAG): string {
    if (!$dateAG) return '';
    $ts  = strtotime($dateAG);
    $now = time();
    $diff = ($ts - $now) / 86400;
    if ($diff < 0)  return '<span style="color:#a8a49e;font-size:10px">AG passée</span>';
    if ($diff < 30) return '<span style="color:#e08020;font-size:10px;font-weight:600">⚡ AG dans ' . (int)$diff . 'j</span>';
    if ($diff < 90) return '<span style="color:#4878a6;font-size:10px">AG dans ' . (int)$diff . 'j</span>';
    return '<span style="color:#a8a49e;font-size:10px">' . date('d/m/Y', $ts) . '</span>';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Immeubles — Syndic MaBoxImmo</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Mono:wght@400;500&family=Sora:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/tokens.css">
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/components.css">
    <link rel="stylesheet" href="css/layout.css">
    <link rel="stylesheet" href="css/theme-syndic.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Sora', sans-serif; background: #ffffff; color: #1a1816; min-height: 100vh; display: flex; }
        .shell { width: 100%; min-height: 100vh; }
        .sb-content { margin-left: 220px; display: flex; flex-direction: column; height: 100vh; overflow: hidden; }

        /* Topbar */
        .topbar { display: flex; align-items: center; gap: 10px; padding: 0 24px 0 20px; height: 56px; background: #ffffff; box-shadow: 0 4px 12px rgba(196,192,186,0.45); position: sticky; top: 0; z-index: 100; flex-shrink: 0; }
        .topbar-back { width: 34px; height: 34px; border-radius: 10px; background: #ffffff; box-shadow: 4px 4px 10px #c4c0ba, -4px -4px 10px #ffffff; display: flex; align-items: center; justify-content: center; cursor: pointer; border: none; flex-shrink: 0; }
        .topbar-back:active { box-shadow: inset 3px 3px 7px #c4c0ba, inset -3px -3px 8px #ffffff; }
        .topbar-back svg { width:15px; height:15px; stroke:#7a9ab8; fill:none; stroke-width:2; stroke-linecap:round; stroke-linejoin:round; }
        .topbar-gap { width: 40px; flex-shrink: 0; }
        .topbar-breadcrumb { display: flex; align-items: center; gap: 8px; font-family: 'DM Mono', monospace; font-size: 13px; letter-spacing: 0.06em; color: #8a8680; }
        .topbar-breadcrumb .active { color: #4878a6; font-weight: 500; font-size: 14px; }
        .topbar-sep { color: #c8c4be; font-size: 16px; }
        .topbar-spacer { flex: 1; }
        .topbar-kpi { display: flex; align-items: center; gap: 6px; margin-right: 20px; }
        .kpi-chip { display: flex; flex-direction: column; align-items: center; padding: 4px 12px; border-radius: 8px; background: #ffffff; box-shadow: inset 2px 2px 5px #c4c0ba, inset -2px -2px 5px #ffffff; }
        .kpi-chip-label { font-family: 'DM Mono', monospace; font-size: 8px; color: #a8a49e; letter-spacing: 0.12em; text-transform: uppercase; }
        .kpi-chip-value { font-family: 'DM Mono', monospace; font-size: 13px; font-weight: 700; color: #4878a6; }
        .kpi-chip.accent .kpi-chip-value { color: #4878a6; }
        .topbar-notif { position: relative; width: 36px; height: 36px; border-radius: 10px; background: #ffffff; box-shadow: 4px 4px 10px #c4c0ba, -4px -4px 10px #ffffff; display: flex; align-items: center; justify-content: center; cursor: pointer; border: none; flex-shrink: 0; }
        .topbar-notif svg { width:16px; height:16px; stroke:#8a8680; fill:none; stroke-width:1.6; }
        .topbar-notif-dot { position: absolute; top: 6px; right: 6px; width: 7px; height: 7px; border-radius: 50%; background: #4878a6; border: 1.5px solid #ffffff; }
        .topbar-avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #6898bf, #4878a6); display: flex; align-items: center; justify-content: center; font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 700; color: #fff; flex-shrink: 0; }

        /* Main */
        .main { flex: 1; overflow-y: auto; padding: 0 28px 28px; }
        .page-head { display: flex; align-items: center; justify-content: space-between; height: 80px; flex-shrink: 0; border-bottom: 1px solid rgba(196,192,186,0.3); margin-bottom: 20px; gap: 20px; }
        .page-head-module { font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 500; letter-spacing: 0.22em; text-transform: uppercase; color: #a8a49e; margin-bottom: 3px; }
        .page-head-title { font-family: 'Sora', sans-serif; font-size: 22px; font-weight: 700; color: #1a1816; letter-spacing: -0.02em; }
        .page-head-actions { display: flex; align-items: center; gap: 10px; }

        /* sec-head */
        .sec-head { display: flex; align-items: center; gap: 14px; margin: 15px 0 10px; }
        .sec-txt { font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500; letter-spacing: 0.28em; text-transform: uppercase; white-space: nowrap; flex-shrink: 0; background: linear-gradient(180deg,#8eb4d3 0%,#4878a6 40%,#25486a 70%,#6898bf 100%); -webkit-background-clip:text; -webkit-text-fill-color:transparent; background-clip:text; }
        .line-l { height: 1.5px; width: 28px; flex-shrink: 0; background: linear-gradient(90deg, transparent 0%, #25486a 40%, #8eb4d3 100%); border-radius: 2px; }
        .line-r { height: 1.5px; flex: 1; background: linear-gradient(90deg, #8eb4d3 0%, #4878a6 30%, #25486a 55%, transparent 100%); border-radius: 2px; }

        /* Boutons V2 */
        .v2-btn { padding: 0 16px; height: 34px; border-radius: 999px; cursor: pointer; border: none; outline: none; font-family: 'Sora', sans-serif; font-size: 11px; letter-spacing: 0.04em; background: #ffffff; box-shadow: 4px 4px 10px #c4c0ba, -4px -4px 10px #ffffff; font-weight: 600; color: #3a3830; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: box-shadow 0.15s; }
        .v2-btn:active { box-shadow: inset 3px 3px 7px #c4c0ba, inset -3px -3px 8px #ffffff; }
        .v2-btn.primary { background: #4878a6; color: #fff; }
        .v2-btn.primary:active { box-shadow: inset 3px 3px 7px rgba(0,0,0,0.25), inset -3px -3px 8px #ffffff; }

        /* Vue toggle */
        .vue-toggle { display: flex; gap: 4px; background: #ffffff; border-radius: 10px; padding: 3px; box-shadow: inset 2px 2px 5px #c4c0ba, inset -2px -2px 5px #ffffff; }
        .vue-btn { height: 28px; padding: 0 10px; border: none; border-radius: 8px; cursor: pointer; font-family: 'Sora', sans-serif; font-size: 11px; font-weight: 500; color: #8a8680; background: transparent; transition: all 0.15s; }
        .vue-btn.active { background: #ffffff; box-shadow: 3px 3px 7px #c4c0ba, -3px -3px 8px #ffffff; color: #4878a6; font-weight: 700; }

        /* Filtres */
        .filter-bar { display: flex; align-items: flex-end; gap: 10px; flex-wrap: wrap; margin-bottom: 18px; background: #ffffff; border-radius: 14px; box-shadow: 5px 5px 12px #c4c0ba, -5px -5px 12px #ffffff; padding: 14px 18px; }
        .tf { display: flex; flex-direction: column; gap: 4px; }
        .tf label { font-family: 'DM Mono', monospace; font-size: 8px; font-weight: 600; letter-spacing: 0.18em; text-transform: uppercase; color: #a8a49e; }
        .tf input, .tf select { height: 32px; padding: 0 10px; background: #ffffff; box-shadow: inset 3px 3px 6px #c4c0ba, inset -3px -3px 8px #ffffff; border: none; border-radius: 8px; font-family: 'Sora', sans-serif; font-size: 11px; color: #1a1816; outline: none; min-width: 90px; }
        .tf input:focus, .tf select:focus { box-shadow: inset 3px 3px 6px #c4c0ba, inset -3px -3px 8px #ffffff, 0 0 0 2px rgba(72,120,166,0.3); }
        .filter-actions { display: flex; gap: 6px; align-items: flex-end; padding-bottom: 0; }

        /* KPIs */
        .kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 20px; }
        .kpi-box { background: #ffffff; border-radius: 14px; box-shadow: 5px 5px 12px #c4c0ba, -5px -5px 12px #ffffff; padding: 14px 18px; }
        .kpi-box-label { font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 500; letter-spacing: 0.18em; text-transform: uppercase; color: #a8a49e; margin-bottom: 6px; }
        .kpi-box-value { font-size: 24px; font-weight: 700; color: #4878a6; letter-spacing: -0.02em; line-height: 1; }
        .kpi-box-sub { font-size: 11px; color: #8a8680; margin-top: 4px; }

        /* Grille cards */
        .imm-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }

        /* Card immeuble */
        .imm-card { background: #ffffff; border-radius: 16px; box-shadow: 6px 6px 14px #c4c0ba, -6px -6px 14px #ffffff; padding: 16px 18px; display: flex; flex-direction: column; gap: 10px; transition: box-shadow 0.15s, transform 0.12s; }
        .imm-card:hover { box-shadow: 8px 8px 18px #c8cbd2, -8px -8px 18px #ffffff; transform: translateY(-2px); }
        .imm-card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
        .imm-card-ref { font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 600; letter-spacing: 0.12em; color: #4878a6; text-transform: uppercase; }
        .imm-card-nom { font-size: 13px; font-weight: 700; color: #1a1816; line-height: 1.3; margin-top: 2px; }
        .imm-card-adresse { font-size: 11px; color: #8a8680; margin-top: 2px; display: flex; align-items: center; gap: 4px; }
        .imm-card-body { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .meta-chip { display: flex; align-items: center; gap: 4px; font-family: 'DM Mono', monospace; font-size: 10px; color: #6a6660; }
        .meta-chip strong { color: #1a1816; font-size: 12px; }
        .imm-card-footer { display: flex; align-items: center; justify-content: space-between; padding-top: 10px; border-top: 1px solid rgba(196,192,186,0.4); }
        .imm-card-actions { display: flex; gap: 6px; }

        /* Btn icon */
        .btn-icon { width: 30px; height: 30px; border-radius: 10px; background: #ffffff; box-shadow: 3px 3px 7px #c4c0ba, -3px -3px 8px #ffffff; border: none; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 13px; text-decoration: none; color: #3a3830; transition: box-shadow 0.12s; flex-shrink: 0; }
        .btn-icon:hover { box-shadow: 4px 4px 10px #c4c0ba, -4px -4px 10px #ffffff; }
        .btn-icon:active { box-shadow: inset 2px 2px 5px #c4c0ba, inset -2px -2px 5px #ffffff; }
        .btn-icon.primary { background: #4878a6; color: #fff; }
        .btn-icon.edit    { background: #ffffff; }

        /* Table view */
        .tbl-wrap { background: #ffffff; border-radius: 14px; box-shadow: 5px 5px 12px #c4c0ba, -5px -5px 12px #ffffff; overflow: hidden; }
        .imm-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .imm-table th { padding: 10px 12px; text-align: left; font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 500; letter-spacing: 0.14em; text-transform: uppercase; color: #4878a6; border-bottom: 2px solid rgba(196,192,186,0.5); background: #f0f1f3; white-space: nowrap; }
        .imm-table td { padding: 10px 12px; border-bottom: 1px solid rgba(196,192,186,0.3); vertical-align: middle; }
        .imm-table tr:last-child td { border-bottom: none; }
        .imm-table tr:hover td { background: rgba(72,120,166,0.04); }
        .td-ref { font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 600; color: #4878a6; letter-spacing: 0.08em; }
        .td-nom { font-weight: 600; color: #1a1816; }
        .td-num { text-align: right; font-family: 'DM Mono', monospace; font-size: 11px; }
        .td-actions { white-space: nowrap; }
        .td-actions a, .td-actions button { margin-right: 4px; }

        /* Badge type */
        .imm-type-badge { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 999px; font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 500; letter-spacing: 0.06em; text-transform: uppercase; white-space: nowrap; }
        .imm-type-badge.sdc    { background: #dce8f2; color: #25486a; }
        .imm-type-badge.maison { background: #e8f4e8; color: #2a5a2a; }
        .imm-type-badge.local  { background: #fef5db; color: #7a4e0a; }
        .imm-type-badge.garage { background: #f0f0f0; color: #4a4a4a; }
        .imm-type-badge.autre  { background: #f5eeff; color: #5a3a7a; }

        /* Empty */
        .empty-state { text-align: center; padding: 60px 20px; color: #8a8680; }
        .empty-state-icon { font-size: 40px; margin-bottom: 14px; opacity: 0.5; }
        .empty-state-txt { font-size: 14px; }
    </style>
</head>
<body>
<div class="shell">

    <?php include __DIR__ . '/sidebar_syndic.php'; ?>

    <div class="sb-content">

        <!-- TOPBAR -->
        <header class="topbar">
            <button class="topbar-back" onclick="history.back()" title="Retour">
                <svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
            </button>
            <button class="topbar-back" onclick="history.forward()" title="Avancer">
                <svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
            </button>
            <nav class="topbar-breadcrumb" style="margin-left:40px">
                <span>Syndic</span>
                <span class="topbar-sep">›</span>
                <span class="active">Immeubles</span>
            </nav>

            <div class="topbar-spacer"></div>

            <!-- KPIs topbar -->
            <div class="topbar-kpi">
                <div class="kpi-chip">
                    <span class="kpi-chip-label">Immeubles</span>
                    <span class="kpi-chip-value"><?= $totalImm ?></span>
                </div>
                <div class="kpi-chip">
                    <span class="kpi-chip-label">Lots</span>
                    <span class="kpi-chip-value"><?= number_format($totalLots, 0, ',', ' ') ?></span>
                </div>
                <div class="kpi-chip accent">
                    <span class="kpi-chip-label">Honoraires HT</span>
                    <span class="kpi-chip-value"><?= number_format($totalHono, 0, ',', ' ') ?> €</span>
                </div>
            </div>

            <button class="topbar-notif" title="Notifications">
                <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
                <?php if ($nbAGMonth > 0): ?><span class="topbar-notif-dot"></span><?php endif; ?>
            </button>
            <div class="topbar-avatar"><?= strtoupper(substr($_SESSION['prenom'] ?? '?', 0, 1) . substr($_SESSION['nom'] ?? '', 0, 1)) ?></div>
        </header>

        <!-- MAIN -->
        <main class="main">

            <!-- PAGE HEAD -->
            <div class="page-head">
                <div>
                    <div class="page-head-module">Module Syndic</div>
                    <div class="page-head-title">Immeubles</div>
                </div>
                <div class="page-head-actions">
                    <!-- Vue toggle -->
                    <div class="vue-toggle">
                        <?php
                        $pVue = $_GET; $pVue['vue'] = 'cards';
                        $pVue2 = $_GET; $pVue2['vue'] = 'table';
                        ?>
                        <button class="vue-btn <?= $fVue === 'cards' ? 'active' : '' ?>"
                            onclick="location.href='?<?= h(http_build_query($pVue)) ?>'">⊞ Cards</button>
                        <button class="vue-btn <?= $fVue === 'table' ? 'active' : '' ?>"
                            onclick="location.href='?<?= h(http_build_query($pVue2)) ?>'">☰ Tableau</button>
                    </div>
                    <?php if ($roleId === 1): ?>
                    <a href="syndic_immeuble_form.php" class="v2-btn primary">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Ajouter
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- FILTRES -->
            <form method="GET" id="filter-form">
                <input type="hidden" name="vue" value="<?= h($fVue) ?>">
                <div class="filter-bar">
                    <div class="tf">
                        <label>Référence</label>
                        <input type="text" name="reference" value="<?= h($fRef) ?>" style="width:80px" placeholder="ex. 1070">
                    </div>
                    <div class="tf">
                        <label>Nom</label>
                        <input type="text" name="nom" value="<?= h($fNom) ?>" style="width:140px" placeholder="Nom immeuble">
                    </div>
                    <div class="tf">
                        <label>Ville</label>
                        <input type="text" name="ville" value="<?= h($fVille) ?>" style="width:110px">
                    </div>
                    <div class="tf">
                        <label>Type</label>
                        <select name="type">
                            <option value="">— Tous —</option>
                            <?php foreach (['sdc','Appartement','Maison individuelle','Maison  - Jumelée','Local commercial','Garage'] as $t): ?>
                                <option value="<?= h($t) ?>" <?= $fType === $t ? 'selected' : '' ?>><?= h($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($roleId === 1): ?>
                    <div class="tf">
                        <label>Établissement</label>
                        <select name="etablissement" style="min-width:130px">
                            <option value="0">— Tous —</option>
                            <?php foreach ($etablissements as $e): ?>
                                <option value="<?= $e['id'] ?>" <?= $fEtab === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="tf">
                        <label>Gestionnaire</label>
                        <select name="gestionnaire" style="min-width:130px">
                            <option value="0">— Tous —</option>
                            <?php foreach ($gestionnaires as $g): ?>
                                <option value="<?= $g['id'] ?>" <?= $fGest === (int)$g['id'] ? 'selected' : '' ?>><?= h($g['nom_complet']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="filter-actions">
                        <button type="submit" class="v2-btn primary" style="height:32px;font-size:11px">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            Filtrer
                        </button>
                        <a href="syndic_immeubles.php" class="v2-btn" style="height:32px;font-size:11px">✕ Reset</a>
                    </div>
                </div>
            </form>

            <!-- KPI ROW -->
            <div class="kpi-row">
                <div class="kpi-box">
                    <div class="kpi-box-label">Immeubles</div>
                    <div class="kpi-box-value"><?= $totalImm ?></div>
                    <div class="kpi-box-sub">dans la sélection</div>
                </div>
                <div class="kpi-box">
                    <div class="kpi-box-label">Total lots</div>
                    <div class="kpi-box-value"><?= number_format($totalLots, 0, ',', ' ') ?></div>
                    <div class="kpi-box-sub">tous immeubles</div>
                </div>
                <div class="kpi-box">
                    <div class="kpi-box-label">Honoraires HT</div>
                    <div class="kpi-box-value" style="font-size:18px"><?= number_format($totalHono, 0, ',', ' ') ?> €</div>
                    <div class="kpi-box-sub">total sélection</div>
                </div>
                <div class="kpi-box">
                    <div class="kpi-box-label">AG ce mois</div>
                    <div class="kpi-box-value" style="<?= $nbAGMonth > 0 ? 'color:#e08020' : '' ?>"><?= $nbAGMonth ?></div>
                    <div class="kpi-box-sub"><?= $nbAGMonth > 0 ? 'à préparer !' : 'aucune' ?></div>
                </div>
            </div>

            <!-- SEC HEAD -->
            <div class="sec-head">
                <div class="line-l"></div>
                <span class="sec-txt"><?= $totalImm ?> immeuble<?= $totalImm > 1 ? 's' : '' ?> — vue <?= $fVue ?></span>
                <div class="line-r"></div>
            </div>

            <?php if (empty($immeubles)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">🏢</div>
                <div class="empty-state-txt">Aucun immeuble trouvé avec ces critères.</div>
                <a href="syndic_immeubles.php" class="v2-btn" style="margin-top:14px;display:inline-flex">Réinitialiser les filtres</a>
            </div>

            <?php elseif ($fVue === 'cards'): ?>
            <!-- ═══ VUE CARDS ═══ -->
            <div class="imm-grid">
                <?php foreach ($immeubles as $imm):
                    // Construire URL fiche avec filtres actifs
                    $ficheParams = $_GET;
                    $ficheParams['id'] = $imm['id'];
                    unset($ficheParams['vue']);
                    $ficheUrl = 'syndic_immeuble_fiche.php?' . http_build_query($ficheParams);
                ?>
                <div class="imm-card" onclick="location.href='<?= h($ficheUrl) ?>'" style="cursor:pointer">
                    <div class="imm-card-head">
                        <div>
                            <div class="imm-card-ref"><?= h($imm['reference']) ?></div>
                            <div class="imm-card-nom"><?= h($imm['nom']) ?></div>
                            <div class="imm-card-adresse">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#a8a49e" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                <?= h($imm['ville']) ?>
                            </div>
                        </div>
                        <?= typeBadge($imm['type']) ?>
                    </div>

                    <div class="imm-card-body">
                        <div class="meta-chip">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#7a9ab8" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M2 9h20M8 3v18"/></svg>
                            <strong><?= (int)$imm['nb_lots'] ?></strong>&nbsp;lots
                        </div>
                        <?php if ($imm['honoraires_ht']): ?>
                        <div class="meta-chip">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#7a9ab8" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                            <strong><?= number_format((float)$imm['honoraires_ht'], 0, ',', ' ') ?> €</strong>
                        </div>
                        <?php endif; ?>
                        <?php if ($imm['nom_gestionnaire']): ?>
                        <div class="meta-chip" style="color:#6a6660">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#a8a49e" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <?= h($imm['nom_gestionnaire']) ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="imm-card-footer">
                        <div><?= agStatus($imm['date_ag_prochaine']) ?></div>
                        <div class="imm-card-actions" onclick="event.stopPropagation()">
                            <a href="<?= h($ficheUrl) ?>" class="btn-icon primary" title="Fiche">📄</a>
                            <?php if ($roleId <= 2): ?>
                            <a href="syndic_immeuble_form.php?id=<?= (int)$imm['id'] ?>" class="btn-icon edit" title="Modifier">✏️</a>
                            <?php endif; ?>
                            <a href="syndic_retour_ag.php?id_immeuble=<?= (int)$imm['id'] ?>&annee=<?= date('Y') ?>" class="btn-icon" title="Retour AG">📋</a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php else: ?>
            <!-- ═══ VUE TABLEAU ═══ -->
            <div class="tbl-wrap">
                <table class="imm-table">
                    <thead>
                        <tr>
                            <th><?= sortLink('i.reference', 'Réf.', $sort, $order) ?></th>
                            <th><?= sortLink('i.nom', 'Nom', $sort, $order) ?></th>
                            <th><?= sortLink('i.ville', 'Ville', $sort, $order) ?></th>
                            <th>Type</th>
                            <th><?= sortLink('i.nb_lots', 'Lots', $sort, $order) ?></th>
                            <th><?= sortLink('infos.honoraires_ht', 'Hono. HT', $sort, $order) ?></th>
                            <th>Établ.</th>
                            <th>Gestionnaire</th>
                            <th>Prochaine AG</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($immeubles as $imm):
                        $ficheParams = $_GET;
                        $ficheParams['id'] = $imm['id'];
                        unset($ficheParams['vue']);
                        $ficheUrl = 'syndic_immeuble_fiche.php?' . http_build_query($ficheParams);
                    ?>
                        <tr>
                            <td class="td-ref"><?= h($imm['reference']) ?></td>
                            <td class="td-nom">
                                <a href="<?= h($ficheUrl) ?>" style="color:inherit;text-decoration:none"><?= h($imm['nom']) ?></a>
                            </td>
                            <td><?= h($imm['ville']) ?></td>
                            <td><?= typeBadge($imm['type']) ?></td>
                            <td class="td-num"><?= (int)$imm['nb_lots'] ?></td>
                            <td class="td-num"><?= $imm['honoraires_ht'] ? number_format((float)$imm['honoraires_ht'], 0, ',', ' ') . ' €' : '—' ?></td>
                            <td style="font-size:11px;color:#6a6660"><?= h($imm['nom_etablissement'] ?? '—') ?></td>
                            <td style="font-size:11px;color:#6a6660"><?= h($imm['nom_gestionnaire'] ?? '—') ?></td>
                            <td><?= agStatus($imm['date_ag_prochaine']) ?></td>
                            <td class="td-actions">
                                <a href="<?= h($ficheUrl) ?>" class="btn-icon primary" title="Fiche" style="display:inline-flex">📄</a>
                                <?php if ($roleId <= 2): ?>
                                <a href="syndic_immeuble_form.php?id=<?= (int)$imm['id'] ?>" class="btn-icon" title="Modifier" style="display:inline-flex">✏️</a>
                                <?php endif; ?>
                                <a href="syndic_retour_ag.php?id_immeuble=<?= (int)$imm['id'] ?>&annee=<?= date('Y') ?>" class="btn-icon" title="Retour AG" style="display:inline-flex">📋</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        </main>
    </div>
</div>
</body>
</html>
