<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/roles_services.php';

require_login();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { http_response_code(500); exit('Erreur: PDO non disponible'); }

$roleId       = current_role_id();
$userId       = current_user_id();
$congeAgenceScope = function_exists('can_manage_salaires_agence') ? can_manage_salaires_agence() : 0;

// 2 notions distinctes :
//   - $isSuperAdmin (role=1) : voit TOUTES les agences, peut bricoler les filtres
//   - $isCongesAdmin (role=1 OU gestion_salaires=1) : a accès aux boutons
//     export/valider, MAIS son périmètre data reste limité à son agence
//     (sauf super admin). Cf. demande : "que les users de l'agence du user
//     connecté".
$isSuperAdmin  = ($roleId === 1);
$isCongesAdmin = $isSuperAdmin || ($congeAgenceScope > 0);

// Récupère l'agence RÉELLE du user en BDD (plus fiable que la session, qui
// peut être trompée par le mode test admin ou ne pas avoir id_agence settée).
$stUa = $pdo->prepare("SELECT id_agence FROM users WHERE id = ? LIMIT 1");
$stUa->execute([$userId]);
$userAgenceId = (int)($stUa->fetchColumn() ?: 0);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function mois_fr($m) { $n=[1=>'Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre']; return $n[(int)$m]??''; }

// ── Filtres ────────────────────────────────────────────────────────────────
$curY          = (int)date('Y');
$curM          = (int)date('m');
$selectedYear  = !empty($_GET['year'])    ? (int)$_GET['year']    : $curY;
$selectedMonth = (!empty($_GET['month']) && $_GET['month'] !== 'tous') ? (int)$_GET['month'] : 'tous';
$filterSociete = (!empty($_GET['societe']) && $_GET['societe'] !== 'toutes') ? (int)$_GET['societe'] : 'toutes';
$filterAgence  = (!empty($_GET['agence'])  && $_GET['agence']  !== 'toutes') ? (int)$_GET['agence']  : 'toutes';
$filterUser    = (!empty($_GET['user'])    && $_GET['user']    !== 'tous')   ? (int)$_GET['user']    : 'tous';

// 🔒 Sécurité multi-tenant : SEUL le super admin (role=1) voit toutes les
// agences. Tout autre user (y compris gestion_salaires=1) ne voit QUE
// l'historique des users de SA propre agence — peu importe les paramètres
// GET qu'il bricole. Si pas d'agence connue → fallback sur l'user lui-même.
if (!$isSuperAdmin) {
    if ($userAgenceId > 0) {
        $filterAgence  = $userAgenceId;
        $filterSociete = 'toutes'; // ignoré, filterAgence prime
    } else {
        $filterUser    = $userId;
        $filterAgence  = 'toutes';
        $filterSociete = 'toutes';
    }
}

// Calcul des pills mois (-3 → +3 autour du mois courant)
$moisFr = [1=>'Janv',2=>'Févr',3=>'Mars',4=>'Avr',5=>'Mai',6=>'Juin',
           7=>'Juil',8=>'Août',9=>'Sept',10=>'Oct',11=>'Nov',12=>'Déc'];
$quickMonths = [];
for ($delta = -3; $delta <= 3; $delta++) {
    $ts = mktime(0,0,0,$curM+$delta,1,$curY);
    $quickMonths[] = [(int)date('m',$ts), (int)date('Y',$ts), $moisFr[(int)date('m',$ts)]];
}

$societes   = $pdo->query("SELECT id, nom FROM societes WHERE actif = 1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
$allAgences = $pdo->query("SELECT id, nom_agence, id_societe FROM agences ORDER BY nom_agence")->fetchAll(PDO::FETCH_ASSOC);

$agencesFiltered = [];
if ($filterSociete !== 'toutes') {
    foreach ($allAgences as $ag) { if ((int)$ag['id_societe'] === (int)$filterSociete) $agencesFiltered[] = $ag; }
} else {
    $agencesFiltered = $allAgences;
}

// Users filtrés selon société + agence sélectionnées
$sqlUsers = "SELECT id, prenom, nom FROM users WHERE actif = 1";
$pUsers = [];
if ($filterSociete !== 'toutes') { $sqlUsers .= " AND id_societe = ?"; $pUsers[] = $filterSociete; }
if ($filterAgence  !== 'toutes') { $sqlUsers .= " AND id_agence = ?";  $pUsers[] = $filterAgence; }
$sqlUsers .= " ORDER BY prenom, nom";
$stmtU = $pdo->prepare($sqlUsers); $stmtU->execute($pUsers);
$allUsers = $stmtU->fetchAll(PDO::FETCH_ASSOC);

// ── Jours fériés ──────────────────────────────────────────────────────────
$holidays = ['01-01'=>true,'05-01'=>true,'05-08'=>true,'07-14'=>true,
             '08-15'=>true,'11-01'=>true,'11-11'=>true,'12-25'=>true];

function count_working_days($start, $end) {
    global $holidays;
    $d = new DateTime($start); $e = new DateTime($end); $e->modify('+1 day');
    $n = 0;
    for ($c = clone $d; $c < $e; $c->modify('+1 day')) {
        $dow = (int)$c->format('N');
        if ($dow < 6 && !isset($holidays[$c->format('m-d')])) $n++;
    }
    return $n;
}

// ── Motifs ────────────────────────────────────────────────────────────────
$motifLabels = [
    'conges_payes'                    => 'Congés payés',
    'rtt'                             => 'RTT',
    'maladie_justifiee_non_deduite'   => 'Maladie justifiée',
    'maladie_non_justifiee_deduite'   => 'Maladie non justifiée',
    'maladie_justifiee_deduite'       => 'Maladie (déduite)',
    'absence_injustifiee_deduite'     => 'Absence injustifiée',
    'absence_justifiee_non_deduite'   => 'Absence justifiée',
    'absence_justifiee_deduite_heures'=> 'Absence (heures)',
    'autre_legal_non_deduit'          => 'Autre légal',
    'autre_legal_deduit'              => 'Autre légal (déduit)',
];

// ── Données congés ────────────────────────────────────────────────────────
$sqlLeaves  = "SELECT c.*, u.id AS uid, u.prenom, u.nom, u.id_societe, u.id_agence,
                      s.nom AS nom_societe, a.nom_agence
               FROM conges c
               JOIN users u ON c.id_user = u.id
               LEFT JOIN societes s ON u.id_societe = s.id
               LEFT JOIN agences  a ON u.id_agence  = a.id
               WHERE YEAR(c.date_debut) = ? AND c.statut != 'archivé'";
$pLeaves = [$selectedYear];
if ($selectedMonth !== 'tous') { $sqlLeaves .= " AND MONTH(c.date_debut) = ?"; $pLeaves[] = $selectedMonth; }
if ($filterSociete !== 'toutes') { $sqlLeaves .= " AND u.id_societe = ?"; $pLeaves[] = $filterSociete; }
if ($filterAgence  !== 'toutes') { $sqlLeaves .= " AND u.id_agence = ?";  $pLeaves[] = $filterAgence; }
if ($filterUser    !== 'tous')   { $sqlLeaves .= " AND u.id = ?";         $pLeaves[] = $filterUser; }
$sqlLeaves .= " ORDER BY c.date_debut DESC";
$stmtL = $pdo->prepare($sqlLeaves); $stmtL->execute($pLeaves);
$leaveRequests = $stmtL->fetchAll(PDO::FETCH_ASSOC);

// ── Soldes ────────────────────────────────────────────────────────────────
$sqlBal = "SELECT cs.*, u.id, u.prenom, u.nom, u.actif
           FROM conges_soldes cs
           JOIN users u ON cs.id_user = u.id
           WHERE YEAR(STR_TO_DATE(CONCAT(cs.mois_annee, '-01'), '%Y-%m-%d')) = ? AND u.actif = 1";
$pBal = [$selectedYear];
if ($selectedMonth !== 'tous')   { $sqlBal .= " AND MONTH(STR_TO_DATE(CONCAT(cs.mois_annee, '-01'), '%Y-%m-%d')) = ?"; $pBal[] = $selectedMonth; }
if ($filterSociete !== 'toutes') { $sqlBal .= " AND u.id_societe = ?"; $pBal[] = $filterSociete; }
if ($filterAgence  !== 'toutes') { $sqlBal .= " AND u.id_agence = ?";  $pBal[] = $filterAgence; }
if ($filterUser    !== 'tous')   { $sqlBal .= " AND cs.id_user = ?";   $pBal[] = $filterUser; }
$sqlBal .= " ORDER BY u.prenom, u.nom";
$stmtB = $pdo->prepare($sqlBal); $stmtB->execute($pBal);
$balances = $stmtB->fetchAll(PDO::FETCH_ASSOC);

// ── Stats ─────────────────────────────────────────────────────────────────
$totalLeaves = count($leaveRequests);
$totalWorkingDays = 0;
$motifCounts  = [];
$statusCounts = ['validé'=>0,'refusé'=>0,'en_attente'=>0];
foreach ($leaveRequests as $l) {
    $totalWorkingDays += count_working_days($l['date_debut'], $l['date_fin']);
    $motifCounts[$l['motif'] ?? 'autre_legal_non_deduit'] = ($motifCounts[$l['motif'] ?? 'autre_legal_non_deduit'] ?? 0) + 1;
    if (isset($statusCounts[$l['statut']])) $statusCounts[$l['statut']]++;
}
$avgPerEmployee = count($balances) > 0 ? round($totalLeaves / count($balances), 1) : 0;
arsort($motifCounts);
$topMotif = key($motifCounts) ? ($motifLabels[key($motifCounts)] ?? key($motifCounts)) : '—';

// Label topbar
$selectedUserName = '';
if ($filterUser !== 'tous') {
    foreach ($allUsers as $u) {
        if ((int)$u['id'] === (int)$filterUser) { $selectedUserName = $u['prenom'].' '.$u['nom']; break; }
    }
}
$moisFrFull = [1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',
               7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre'];
$periodeLabel = $selectedMonth !== 'tous'
    ? ($moisFrFull[$selectedMonth].' '.$selectedYear)
    : (string)$selectedYear;

$chartLabels = []; $chartData = [];
foreach ($motifCounts as $mo => $cnt) { $chartLabels[] = $motifLabels[$mo] ?? $mo; $chartData[] = $cnt; }

// ── Layout variables ─────────────────────────────────────────────────────
$layout_title   = 'Historique Congés';
$layout_module  = 'Ma Box RH';
$layout_sidebar = 'rh_sidebar';

$layout_head_kpis = '
<div class="ph-kpi"><span class="ph-kpi-label">Demandes</span><span class="ph-kpi-value">' . $totalLeaves . '</span></div>
<div class="ph-kpi"><span class="ph-kpi-label">Jours ouvrés</span><span class="ph-kpi-value" style="color:#3a7a6a">' . $totalWorkingDays . '</span></div>
<div class="ph-kpi"><span class="ph-kpi-label">Moy./collab.</span><span class="ph-kpi-value" style="color:#7a6830">' . $avgPerEmployee . '</span></div>
<div class="ph-kpi"><span class="ph-kpi-label">Type dominant</span><span class="ph-kpi-value" style="font-size:11px">' . h($topMotif) . '</span></div>
<div class="ph-kpi"><span class="ph-kpi-label">Validées</span><span class="ph-kpi-value" style="color:#3a7a6a">' . $statusCounts['validé'] . '</span></div>
<div class="ph-kpi"><span class="ph-kpi-label">Refusées</span><span class="ph-kpi-value" style="color:#8a5040">' . $statusCounts['refusé'] . '</span></div>';

// Exports réservés à l'admin société. Le user normal accède en lecture seule.
$layout_head_actions = '';
if ($isCongesAdmin) {
    $layout_head_actions = '
<button class="ph-btn" onclick="exportPDF()"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> Export PDF</button>
<button class="ph-btn" onclick="exportExcel()"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="15" y1="3" x2="15" y2="21"/></svg> Export Excel</button>
<a class="ph-btn" href="exporter_conges_user_annuel_pdf.php?annee=' . $selectedYear . '" target="_blank"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg> PDF par user</a>';
}

$layout_extra_css = <<<'EXTRACSS'
<style>
    /* ── Scope pills ── */
    .ph-scope { display: flex; flex-direction: column; gap: 11px; justify-content: center; min-width: 0; }
    .ph-scope-row { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }
    .ph-scope-label { font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.10em; color: var(--shadow-dark); width: 46px; flex-shrink: 0; text-align: right; }
    .ph-scope-btns { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .ph-scope-pill {
        height: 24px; padding: 0 12px; border-radius: 999px; background: var(--bg-primary);
        box-shadow: 2px 2px 5px var(--shadow-dark), -2px -2px 5px var(--shadow-light);
        font-family: 'Sora', sans-serif; font-size: 10px; font-weight: 500; color: #8a8680;
        text-decoration: none; display: inline-flex; align-items: center; white-space: nowrap;
        transition: box-shadow 0.12s, color 0.12s;
    }
    .ph-scope-pill:hover { color: #36577d; }
    .ph-scope-pill.active { box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light); color: #36577d; font-weight: 700; }

    /* ── Section title ── */
    .sec-head { display: flex; align-items: center; gap: 14px; margin: 15px 0 12px; }
    .sec-txt {
        font-family: 'DM Mono', monospace; font-size: 10px; font-weight: 500;
        letter-spacing: 0.28em; text-transform: uppercase; white-space: nowrap; flex-shrink: 0;
        background: linear-gradient(180deg, #7a9060 0%, #4a6038 40%, #304828 70%, #607848 100%);
        -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
    }
    .line-l { height: 1.5px; width: 28px; flex-shrink: 0; background: linear-gradient(90deg, transparent 0%, #304828 40%, #9ab870 100%); border-radius: 2px; }
    .line-r { height: 1.5px; flex: 1; background: linear-gradient(90deg, #9ab870 0%, #607848 30%, #4a6038 55%, transparent 100%); border-radius: 2px; }

    /* ── Action strip ── */
    .action-strip { display: flex; align-items: flex-start; gap: 20px; margin-bottom: 20px; }
    .action-strip-filters { display: flex; flex-direction: column; gap: 12px; flex: 0 0 auto; }
    .filter-row { display: flex; align-items: center; gap: 15px; }
    .filter-label { font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.12em; color: #a8a49e; width: 46px; flex-shrink: 0; text-align: right; }
    .filter-btns { display: flex; align-items: center; gap: 12px; }
    .filter-pill {
        height: 28px; min-width: 68px; padding: 0 12px; border-radius: 999px; background: var(--bg-primary);
        box-shadow: 3px 3px 7px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
        border: none; cursor: pointer; outline: none;
        font-family: 'Sora', sans-serif; font-size: 11px; font-weight: 500; color: #6a6660;
        transition: box-shadow 0.12s, color 0.12s; white-space: nowrap; text-align: center;
    }
    .filter-pill:hover { color: #36577d; }
    .filter-pill.active { box-shadow: inset 3px 3px 6px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light); color: #36577d; font-weight: 700; }
    .filter-more {
        height: 28px; width: 68px; padding: 0 6px; text-align: center;
        background: var(--bg-primary); box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light);
        border: none; border-radius: 8px; font-family: 'Sora', sans-serif; font-size: 11px; color: #6a6660;
        cursor: pointer; outline: none;
    }
    .action-strip-btns { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-start; padding-top: 6px; }

    /* ── Boutons V2 ── */
    .v2-btn {
        display: inline-flex; align-items: center; gap: 6px; height: 34px; padding: 0 16px;
        border-radius: 999px; border: none; cursor: pointer;
        font-family: 'Sora', sans-serif; font-size: 11px; font-weight: 600; letter-spacing: 0.03em;
        background: var(--bg-primary); color: #36577d; text-decoration: none;
        box-shadow: 4px 4px 10px var(--shadow-dark), -4px -4px 10px var(--shadow-light);
        transition: box-shadow 0.12s, color 0.12s; white-space: nowrap;
    }
    .v2-btn:hover { box-shadow: inset 3px 3px 7px var(--shadow-dark), inset -3px -3px 8px var(--shadow-light); }
    .v2-btn.success { color: #3a7a6a; }
    .v2-btn.warn    { color: #7a6830; }

    /* ── KPI row (in-content) ── */
    .kpi-row { display: flex; gap: 14px; margin-bottom: 20px; flex-wrap: wrap; }
    .kpi-card {
        flex: 1; min-width: 130px; background: #e2ede6; border-radius: 14px;
        box-shadow: 5px 5px 12px #bec8ba, -5px -5px 12px var(--shadow-light);
        padding: 12px 16px; display: flex; align-items: center; gap: 12px;
    }
    .kpi-icon {
        width: 34px; height: 34px; border-radius: 9px; background: #e2ede6;
        box-shadow: inset 3px 3px 6px #bec8ba, inset -3px -3px 8px var(--shadow-light);
        display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .kpi-icon svg { width: 15px; height: 15px; fill: none; stroke-width: 1.6; }
    .kpi-lbl { font-size: 9px; font-family: 'DM Mono', monospace; letter-spacing: 0.12em; text-transform: uppercase; color: #8a9880; }
    .kpi-val { font-size: 18px; font-family: 'DM Mono', monospace; font-weight: 500; color: #36577d; line-height: 1.1; }
    .kpi-val.green { color: #3a7a6a; }
    .kpi-val.orange { color: #7a6830; }
    .kpi-val.red    { color: #8a5040; }

    /* ── Stat statuts ── */
    .status-row { display: flex; gap: 14px; margin-bottom: 20px; flex-wrap: wrap; }
    .status-chip {
        flex: 1; min-width: 100px; background: var(--bg-primary); border-radius: 14px;
        box-shadow: 5px 5px 12px var(--shadow-dark), -5px -5px 12px var(--shadow-light);
        padding: 14px 16px; text-align: center;
    }
    .status-chip-val { font-family: 'DM Mono', monospace; font-size: 28px; font-weight: 500; line-height: 1; margin-bottom: 6px; }
    .status-chip-lbl { font-size: 10px; font-family: 'DM Mono', monospace; letter-spacing: 0.12em; text-transform: uppercase; color: #a8a49e; }

    /* ── Tables V2 ── */
    .table-card {
        background: var(--bg-primary); border-radius: 20px;
        box-shadow: 8px 8px 18px var(--shadow-dark), -8px -8px 18px var(--shadow-light);
        overflow: hidden; margin-bottom: 20px;
    }
    .table-card-head {
        display: flex; align-items: center; justify-content: space-between;
        padding: 14px 20px; border-bottom: 1px solid rgba(196,192,186,0.35);
    }
    .table-card-title { font-size: 13px; font-weight: 600; color: #1a1816; }
    .table-scroll { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; }
    thead th {
        padding: 10px 14px; text-align: left;
        font-family: 'DM Mono', monospace; font-size: 9px; font-weight: 700;
        letter-spacing: 0.18em; text-transform: uppercase; color: #4a6038;
        border-bottom: 1.5px solid rgba(122,144,96,0.25);
        background: transparent;
    }
    tbody td { padding: 10px 14px; font-size: 12px; color: #1a1816; border-bottom: 1px solid rgba(196,192,186,0.2); }
    tbody tr:last-child td { border-bottom: none; }
    tbody tr:nth-child(odd)  td { background: #dde4ed; }
    tbody tr:nth-child(even) td { background: #e0e8dc; }
    tbody tr:hover td { filter: brightness(0.97); }

    /* Badges statut */
    .status-pill {
        display: inline-flex; padding: 2px 10px; border-radius: 999px;
        font-family: 'DM Mono', monospace; font-size: 9px; letter-spacing: 0.08em; font-weight: 500;
    }
    .s-valide  { background: rgba(74,96,56,0.12);  color: #3a7a6a; }
    .s-attente { background: rgba(196,122,48,0.12); color: #7a6830; }
    .s-refuse  { background: rgba(204,92,88,0.12);  color: #8a5040; }

    /* ── Chart card ── */
    .chart-card {
        background: var(--bg-primary); border-radius: 20px;
        box-shadow: 8px 8px 18px var(--shadow-dark), -8px -8px 18px var(--shadow-light);
        padding: 20px; margin-bottom: 20px;
    }
    .chart-title { font-size: 13px; font-weight: 600; color: #1a1816; margin-bottom: 16px; }
    .chart-wrap { position: relative; height: 260px; }

    /* ── Empty ── */
    .empty-row td { text-align: center; padding: 30px !important; color: #a8a49e; font-style: italic; font-size: 12px; background: transparent !important; }

    @media (max-width: 900px) { .action-strip { flex-direction: column; } }
</style>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
EXTRACSS;

// Pre-encode JSON for JS heredoc
$_chartLabelsJson = json_encode($chartLabels);
$_chartDataJson   = json_encode($chartData);
$layout_extra_js = <<<EXTRAJS
<script>
function setFilter(month, year) {
    document.getElementById('f-month').value = month;
    document.getElementById('f-year').value  = year;
    document.getElementById('filter-form').submit();
}
function setYear(y) { setFilter(document.getElementById('f-month').value, y); }

window.addEventListener('load', function() {
    const canvas = document.getElementById('motifChart');
    if (!canvas) return;
    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: {$_chartLabelsJson},
            datasets: [{
                label: 'Demandes',
                data: {$_chartDataJson},
                backgroundColor: 'rgba(54,87,125,0.18)',
                borderColor: '#36577d',
                borderWidth: 1.5,
                borderRadius: 6,
                hoverBackgroundColor: 'rgba(54,87,125,0.3)'
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, ticks: { color: '#8a8680', stepSize: 1 }, grid: { color: 'rgba(196,192,186,0.25)' } },
                x: { ticks: { color: '#8a8680', maxRotation: 35, font: { size: 10 } }, grid: { display: false } }
            }
        }
    });
});

function exportPDF() {
    const el = document.querySelector('.layout-main');
    html2pdf().set({
        margin: 10,
        filename: 'historique-conges-{$selectedYear}.pdf',
        image: { type: 'jpeg', quality: 0.97 },
        html2canvas: { scale: 2, backgroundColor: 'var(--bg-secondary)' },
        jsPDF: { orientation: 'landscape', unit: 'mm', format: 'a4' }
    }).from(el).save();
}

function exportExcel() {
    const table = document.getElementById('leaves-table');
    let html = '<table border="1">';
    table.querySelectorAll('tr').forEach(row => {
        html += '<tr>';
        row.querySelectorAll('th,td').forEach(cell => {
            const tag = cell.tagName.toLowerCase();
            html += '<' + tag + '>' + cell.textContent.trim() + '</' + tag + '>';
        });
        html += '</tr>';
    });
    html += '</table>';
    const blob = new Blob([html], { type: 'application/vnd.ms-excel;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'historique-conges-{$selectedYear}.xls';
    a.click();
}
</script>
EXTRAJS;

// ── Content ──────────────────────────────────────────────────────────────
ob_start();
?>

<?php
// Nom lisible de l'agence du user (pour le label "Mon agence")
$myAgenceName = '';
if (!$isSuperAdmin && $userAgenceId > 0) {
    foreach ($allAgences as $ag) {
        if ((int)$ag['id'] === $userAgenceId) { $myAgenceName = (string)$ag['nom_agence']; break; }
    }
}
?>
<!-- Scope société / agence / user
     - Super admin (role=1) : peut bricoler Sté + Agc librement
     - Tout autre user (y compris gestion_salaires=1) : Agc figée sur la sienne -->
<div class="ph-scope" style="margin-bottom:20px">
    <?php if ($isSuperAdmin): ?>
    <div class="ph-scope-row">
        <span class="ph-scope-label">Sté</span>
        <div class="ph-scope-btns">
            <a href="?<?= http_build_query(array_merge($_GET,['societe'=>'toutes','agence'=>'toutes','user'=>'tous'])) ?>"
               class="ph-scope-pill <?= $filterSociete==='toutes'?'active':'' ?>">Toutes</a>
            <?php foreach ($societes as $s): ?>
            <a href="?<?= http_build_query(array_merge($_GET,['societe'=>$s['id'],'agence'=>'toutes','user'=>'tous'])) ?>"
               class="ph-scope-pill <?= (string)$filterSociete===(string)$s['id']?'active':'' ?>">
                <?= h($s['nom']) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php if ($filterSociete !== 'toutes' && !empty($agencesFiltered)): ?>
    <div class="ph-scope-row">
        <span class="ph-scope-label">Agc</span>
        <div class="ph-scope-btns">
            <a href="?<?= http_build_query(array_merge($_GET,['agence'=>'toutes','user'=>'tous'])) ?>"
               class="ph-scope-pill <?= $filterAgence==='toutes'?'active':'' ?>">Toutes</a>
            <?php foreach ($agencesFiltered as $ag): ?>
            <a href="?<?= http_build_query(array_merge($_GET,['agence'=>$ag['id'],'user'=>'tous'])) ?>"
               class="ph-scope-pill <?= (string)$filterAgence===(string)$ag['id']?'active':'' ?>">
                <?= h($ag['nom_agence']) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="ph-scope-row">
        <span class="ph-scope-label">Agc</span>
        <div class="ph-scope-btns">
            <span class="ph-scope-pill active" style="cursor:default;">
                <?= $myAgenceName !== '' ? h($myAgenceName) : 'Mon périmètre' ?>
            </span>
        </div>
    </div>
    <?php endif; ?>
    <?php if (!empty($allUsers)): ?>
    <div class="ph-scope-row">
        <span class="ph-scope-label">User</span>
        <div class="ph-scope-btns">
            <a href="?<?= http_build_query(array_merge($_GET,['user'=>'tous'])) ?>"
               class="ph-scope-pill <?= $filterUser==='tous'?'active':'' ?>">Tous</a>
            <?php foreach ($allUsers as $u): ?>
            <a href="?<?= http_build_query(array_merge($_GET,['user'=>$u['id']])) ?>"
               class="ph-scope-pill <?= (string)$filterUser===(string)$u['id']?'active':'' ?>">
                <?= h($u['prenom'].' '.$u['nom']) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ── ACTIONS ── -->
<div class="sec-head">
    <span class="line-l"></span><span class="sec-txt">Filtres &amp; Export</span><span class="line-r"></span>
</div>

<div class="action-strip">
    <!-- Filtre année -->
    <form method="GET" id="filter-form" class="action-strip-filters">
        <input type="hidden" name="societe" value="<?= h((string)$filterSociete) ?>">
        <input type="hidden" name="agence"  value="<?= h((string)$filterAgence) ?>">
        <input type="hidden" name="user"    value="<?= h((string)$filterUser) ?>">
        <!-- Ligne mois -->
        <div class="filter-row">
            <span class="filter-label">Mois</span>
            <div class="filter-btns">
                <button type="button" class="filter-pill <?= $selectedMonth==='tous'?'active':'' ?>"
                        onclick="setFilter('tous', document.getElementById('f-year').value)">Tous</button>
                <?php foreach ($quickMonths as [$qm, $qy, $ql]):
                    $active = ($selectedMonth===$qm && $selectedYear===$qy); ?>
                <button type="button" class="filter-pill <?= $active?'active':'' ?>"
                        onclick="setFilter(<?=$qm?>, <?=$qy?>)"><?= $ql ?></button>
                <?php endforeach; ?>
                <select class="filter-more" onchange="setFilter(this.value, document.getElementById('f-year').value)" title="Autre mois">
                    <option value="">…</option>
                    <?php
                    $quickMVals = array_column($quickMonths, 0);
                    for ($m=1; $m<=12; $m++):
                        if (in_array($m, $quickMVals, true)) continue; ?>
                    <option value="<?=$m?>" <?=$selectedMonth===$m?'selected':''?>><?= $moisFr[$m] ?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>
        <!-- Ligne année -->
        <div class="filter-row">
            <span class="filter-label">Année</span>
            <div class="filter-btns">
                <?php foreach ([$curY-2,$curY-1,$curY] as $qy): ?>
                <button type="button" class="filter-pill <?= $selectedYear===$qy?'active':'' ?>"
                        onclick="setFilter(document.getElementById('f-month').value, <?= $qy ?>)"><?= $qy ?></button>
                <?php endforeach; ?>
                <select class="filter-more" onchange="setFilter(document.getElementById('f-month').value, this.value)" title="Autre année">
                    <option value="">…</option>
                    <?php for ($a=$curY+2; $a>=$curY-5; $a--):
                        if (in_array($a,[$curY,$curY-1,$curY-2])) continue; ?>
                    <option value="<?=$a?>" <?=$selectedYear===$a?'selected':''?>><?=$a?></option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>
        <input type="hidden" id="f-month" name="month" value="<?= h((string)$selectedMonth) ?>">
        <input type="hidden" id="f-year"  name="year"  value="<?= $selectedYear ?>">
    </form>
</div>

<!-- ── KPI ── -->
<div class="kpi-row">
    <div class="kpi-card">
        <div class="kpi-icon">
            <svg viewBox="0 0 24 24" stroke="#36577d"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        </div>
        <div><div class="kpi-lbl">Demandes</div><div class="kpi-val"><?= $totalLeaves ?></div></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon">
            <svg viewBox="0 0 24 24" stroke="#3a7a6a"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        </div>
        <div><div class="kpi-lbl">Jours ouvrés</div><div class="kpi-val green"><?= $totalWorkingDays ?></div></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon">
            <svg viewBox="0 0 24 24" stroke="#7a6830"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        </div>
        <div><div class="kpi-lbl">Moy. / collab.</div><div class="kpi-val orange"><?= $avgPerEmployee ?></div></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon">
            <svg viewBox="0 0 24 24" stroke="#36577d"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <div><div class="kpi-lbl">Type dominant</div><div style="font-size:11px;font-weight:600;color:#36577d;margin-top:2px"><?= h($topMotif) ?></div></div>
    </div>
</div>

<!-- ── Statuts ── -->
<div class="status-row">
    <div class="status-chip">
        <div class="status-chip-val" style="color:#3a7a6a"><?= $statusCounts['validé'] ?></div>
        <div class="status-chip-lbl">Validées</div>
    </div>
    <div class="status-chip">
        <div class="status-chip-val" style="color:#7a6830"><?= $statusCounts['en_attente'] ?></div>
        <div class="status-chip-lbl">En attente</div>
    </div>
    <div class="status-chip">
        <div class="status-chip-val" style="color:#8a5040"><?= $statusCounts['refusé'] ?></div>
        <div class="status-chip-lbl">Refusées</div>
    </div>
</div>

<!-- ── Graphique ── -->
<?php if (!empty($chartData)): ?>
<div class="sec-head"><span class="line-l"></span><span class="sec-txt">Répartition par type</span><span class="line-r"></span></div>
<div class="chart-card">
    <div class="chart-wrap"><canvas id="motifChart"></canvas></div>
</div>
<?php endif; ?>

<!-- ── Soldes annuels ── -->
<div class="sec-head"><span class="line-l"></span><span class="sec-txt">Soldes <?= $selectedYear ?></span><span class="line-r"></span></div>
<div class="table-card">
    <div class="table-card-head">
        <span class="table-card-title">Soldes de congés annuels</span>
        <span style="font-family:'DM Mono',monospace;font-size:10px;color:#a8a49e"><?= count($balances) ?> collaborateur<?= count($balances)>1?'s':'' ?></span>
    </div>
    <div class="table-scroll">
        <table>
            <thead><tr>
                <th>Collaborateur</th>
                <th style="text-align:right">Acquis</th>
                <th style="text-align:right">Pris</th>
                <th style="text-align:right">Restant</th>
            </tr></thead>
            <tbody>
            <?php if (empty($balances)): ?>
                <tr class="empty-row"><td colspan="4">Aucun solde disponible pour cette période</td></tr>
            <?php else: ?>
                <?php foreach ($balances as $b):
                    $name = trim(($b['prenom']??'').' '.($b['nom']??''));
                    $acq  = ((float)($b['conge_a_prendre']??0)) + ((float)($b['conge_en_acquisition']??0));
                    $pris = (float)($b['conge_pris_n']??0);
                    $rest = (float)($b['solde_restant']??0);
                ?>
                <tr>
                    <td><?= h($name) ?></td>
                    <td style="text-align:right;font-family:'DM Mono',monospace"><?= number_format($acq,2,',',' ') ?></td>
                    <td style="text-align:right;font-family:'DM Mono',monospace"><?= number_format($pris,2,',',' ') ?></td>
                    <td style="text-align:right;font-family:'DM Mono',monospace;color:<?= $rest<0?'#8a5040':'#3a7a6a' ?>;font-weight:600"><?= number_format($rest,2,',',' ') ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Tableau demandes ── -->
<div class="sec-head"><span class="line-l"></span><span class="sec-txt">Toutes les demandes <?= $selectedYear ?></span><span class="line-r"></span></div>
<div class="table-card">
    <div class="table-card-head">
        <span class="table-card-title">Historique des congés</span>
        <span style="font-family:'DM Mono',monospace;font-size:10px;color:#a8a49e"><?= $totalLeaves ?> entrée<?= $totalLeaves>1?'s':'' ?></span>
    </div>
    <div class="table-scroll">
        <table id="leaves-table">
            <thead><tr>
                <th>Collaborateur</th>
                <th>Société / Agence</th>
                <th>Dates</th>
                <th style="text-align:center">Jours</th>
                <th>Type</th>
                <th style="text-align:center">Statut</th>
                <th>Demandé le</th>
            </tr></thead>
            <tbody>
            <?php if (empty($leaveRequests)): ?>
                <tr class="empty-row"><td colspan="7">Aucune demande pour cette période</td></tr>
            <?php else: ?>
                <?php foreach ($leaveRequests as $l):
                    $name   = trim(($l['prenom']??'').' '.($l['nom']??''));
                    $days   = count_working_days($l['date_debut'], $l['date_fin']);
                    $sLabel = ($motifLabels[$l['motif']] ?? $l['motif']);
                    $sCls   = ['validé'=>'s-valide','refusé'=>'s-refuse','en_attente'=>'s-attente'][$l['statut']] ?? 's-attente';
                    $sText  = ['validé'=>'Validé','refusé'=>'Refusé','en_attente'=>'En attente'][$l['statut']] ?? $l['statut'];
                    $agInfo = array_filter([$l['nom_societe']??null, $l['nom_agence']??null]);
                ?>
                <tr>
                    <td style="font-weight:600"><?= h($name) ?></td>
                    <td style="font-size:11px;color:#8a8680"><?= h(implode(' / ', $agInfo)) ?></td>
                    <td style="font-family:'DM Mono',monospace;font-size:11px">
                        <?= h(date('d/m/Y', strtotime($l['date_debut']))) ?> → <?= h(date('d/m/Y', strtotime($l['date_fin']))) ?>
                    </td>
                    <td style="text-align:center;font-family:'DM Mono',monospace;font-weight:600;color:#36577d"><?= $days ?></td>
                    <td style="font-size:11px"><?= h($sLabel) ?></td>
                    <td style="text-align:center"><span class="status-pill <?= $sCls ?>"><?= $sText ?></span></td>
                    <td style="font-family:'DM Mono',monospace;font-size:10px;color:#8a8680">
                        <?= h(date('d/m/Y', strtotime($l['date_demande']??'now'))) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
