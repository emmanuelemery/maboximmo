<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/roles_services.php';

require_login();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    exit('Erreur: PDO non disponible');
}

$roleId = current_role_id();
$userId = current_user_id();
$userAgenceId = current_agence_id();

// Admin only access (roleId = 1)
if ($roleId !== 1) {
    http_response_code(403);
    header("Location: /MaBoxImmo2026/public_html/landing.php");
    exit('Accès non autorisé.');
}

// Get filter values from URL
$selectedYear = !empty($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$filterSociete = !empty($_GET['societe']) && $roleId === 1 ? (int)$_GET['societe'] : null;
$filterAgence = !empty($_GET['agence']) && in_array($roleId, [1, 2]) ? (int)$_GET['agence'] : null;

// Get all societes, agences for filters (admin only)
$societes = [];
$agences = [];
if ($roleId === 1) {
    $societes = $pdo->query("SELECT id, nom FROM societes WHERE actif = 1 ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
    $agences = $pdo->query("SELECT id, nom_agence FROM agences ORDER BY nom_agence")->fetchAll(PDO::FETCH_ASSOC);
}

// French holidays
$holidays = [
    '01-01' => 'Jour de l\'an',
    '05-01' => 'Fête du Travail',
    '05-08' => 'Fête de la Victoire',
    '07-14' => 'Fête nationale',
    '08-15' => 'Assomption',
    '11-01' => 'Toussaint',
    '11-11' => 'Armistice',
    '12-25' => 'Noël',
];

// Motif labels in French
$motifLabels = [
    'absence_injustifiee_deduite' => 'Absence injustifiée',
    'absence_justifiee_non_deduite' => 'Absence justifiée',
    'absence_justifiee_deduite_heures' => 'Absence justifiée (heures)',
    'maladie_justifiee_non_deduite' => 'Maladie justifiée',
    'maladie_non_justifiee_deduite' => 'Maladie non justifiée',
    'maladie_justifiee_deduite' => 'Maladie justifiée (déduite)',
    'rtt' => 'RTT',
    'conges_payes' => 'Congés payés',
    'autre_legal_non_deduit' => 'Autre légal',
    'autre_legal_deduit' => 'Autre légal (déduit)',
];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function mois_fr($m) { $n = [1=>'Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre']; return $n[(int)$m] ?? ''; }

// Count working days excluding weekends and holidays
function count_working_days($start_date, $end_date) {
    global $holidays;
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $end->modify('+1 day');

    $count = 0;
    for ($date = clone $start; $date < $end; $date->modify('+1 day')) {
        $dayOfWeek = (int)$date->format('N'); // 1=Monday, 7=Sunday
        if ($dayOfWeek < 6) { // Not weekend
            $mdStr = $date->format('m-d');
            if (!isset($holidays[$mdStr])) { // Not a holiday
                $count++;
            }
        }
    }
    return $count;
}

// ===== EXPORT FEATURES =====

// Handle PDF export
if (!empty($_POST['export_pdf'])) {
    header('Content-Type: application/json');
    // PDF export will be handled by JavaScript using TCPDF library
    exit(json_encode(['success' => true, 'message' => 'Export PDF']));
}

// Handle Excel export
if (!empty($_POST['export_excel'])) {
    header('Content-Type: application/json');
    // Excel export will be handled by JavaScript
    exit(json_encode(['success' => true, 'message' => 'Export Excel']));
}

// ===== DATA QUERIES =====

// Build base SQL for user data
$userNameExpr = "TRIM(CONCAT_WS(' ', u.prenom, u.nom))";

// Get leave requests for the year
$sqlLeaves = "
    SELECT c.*, u.id, u.prenom, u.nom, u.id_societe, u.id_agence, s.nom as nom_societe, a.nom_agence
    FROM conges c
    JOIN users u ON c.id_user = u.id
    LEFT JOIN societes s ON u.id_societe = s.id
    LEFT JOIN agences a ON u.id_agence = a.id
    WHERE YEAR(c.date_debut) = ? AND c.statut != 'archivé'
";

$paramsLeaves = [$selectedYear];

if ($roleId === 1) {
    if ($filterSociete !== null) {
        $sqlLeaves .= " AND u.id_societe = ?";
        $paramsLeaves[] = $filterSociete;
    }
    if ($filterAgence !== null) {
        $sqlLeaves .= " AND u.id_agence = ?";
        $paramsLeaves[] = $filterAgence;
    }
} elseif ($roleId === 2) {
    if ($userAgenceId) {
        $sqlLeaves .= " AND u.id_agence = ?";
        $paramsLeaves[] = $userAgenceId;
    }
} else {
    $sqlLeaves .= " AND c.id_user = ?";
    $paramsLeaves[] = $userId;
}

$sqlLeaves .= " ORDER BY c.date_debut DESC";

$stmtLeaves = $pdo->prepare($sqlLeaves);
$stmtLeaves->execute($paramsLeaves);
$leaveRequests = $stmtLeaves->fetchAll(PDO::FETCH_ASSOC);

// Get balances for the year
$sqlBalances = "
    SELECT cs.*, u.id, u.prenom, u.nom, u.actif, u.id_societe, u.id_agence
    FROM conges_soldes cs
    JOIN users u ON cs.id_user = u.id
    WHERE YEAR(STR_TO_DATE(CONCAT(cs.mois_annee, '-01'), '%Y-%m-%d')) = ? AND u.actif = 1
";

$paramsBalances = [$selectedYear];

if ($roleId === 1) {
    if ($filterSociete !== null) {
        $sqlBalances .= " AND u.id_societe = ?";
        $paramsBalances[] = $filterSociete;
    }
    if ($filterAgence !== null) {
        $sqlBalances .= " AND u.id_agence = ?";
        $paramsBalances[] = $filterAgence;
    }
} elseif ($roleId === 2) {
    if ($userAgenceId) {
        $sqlBalances .= " AND u.id_agence = ?";
        $paramsBalances[] = $userAgenceId;
    }
} else {
    $sqlBalances .= " AND cs.id_user = ?";
    $paramsBalances[] = $userId;
}

$sqlBalances .= " ORDER BY " . $userNameExpr;

$stmtBalances = $pdo->prepare($sqlBalances);
$stmtBalances->execute($paramsBalances);
$balances = $stmtBalances->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$totalLeaves = count($leaveRequests);
$totalWorkingDays = 0;
$motifCounts = [];
$statusCounts = ['validé' => 0, 'refusé' => 0, 'en_attente' => 0];

foreach ($leaveRequests as $leave) {
    $workDays = count_working_days($leave['date_debut'], $leave['date_fin']);
    $totalWorkingDays += $workDays;

    $motif = $leave['motif'] ?? 'autre_legal_non_deduit';
    if (!isset($motifCounts[$motif])) {
        $motifCounts[$motif] = 0;
    }
    $motifCounts[$motif]++;

    $status = $leave['statut'] ?? 'en_attente';
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }
}

$avgPerEmployee = count($balances) > 0 ? round($totalLeaves / count($balances), 2) : 0;

// Prepare chart data for leave types
$chartLabels = [];
$chartData = [];
arsort($motifCounts);
foreach ($motifCounts as $motif => $count) {
    $label = $motifLabels[$motif] ?? $motif;
    $chartLabels[] = $label;
    $chartData[] = $count;
}

$chartLabelsJson = json_encode($chartLabels);
$chartDataJson = json_encode($chartData);

?><!doctype html>
<html lang="fr" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/theme-rh.css">
    <?php include __DIR__ . '/inc/theme-init.php'; ?>
    <title>Historique Congés - MABOXIMMO</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:"Manrope",sans-serif;background:var(--bg);color:var(--ink);display:flex;min-height:100vh}
        .mbi-sidebar{position:fixed;left:0;top:0;width:var(--sidebar-w);height:100vh;background:var(--sidebar);border-right:1px solid var(--stroke);overflow-y:auto;padding:12px 0}
        .mbi-sidebar-head{padding:10px 12px;border-bottom:1px solid var(--stroke);margin-bottom:12px}
        .mbi-sidebar-brand strong{font-size:14px;display:block}
        .mbi-sidebar-brand span{font-size:11px;color:var(--muted)}
        .mbi-sidebar-section{padding:12px;font-size:13px;font-weight:700;text-transform:uppercase;color:var(--ink);margin:16px 8px 10px;background:rgba(72,120,166,0.06);border-left:3px solid rgba(72,120,166,0.2);border-radius:4px;letter-spacing:0.5px}
        .mbi-nav{list-style:none}
        .mbi-nav li a{display:flex;align-items:center;gap:4px;padding:6px 12px;color:var(--muted);text-decoration:none;font-size:15px;transition:all 0.2s}
        .mbi-nav li a:hover{color:var(--ink);background:#ffffff}
        .mbi-nav li a.active{color:var(--accent);background:rgba(72,120,166,0.08)}
        .mbi-main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column}
        .mbi-topbar{height:64px;background:var(--bg-soft);border-bottom:1px solid var(--stroke);display:flex;align-items:center;justify-content:space-between;padding:0 30px}
        .mbi-topbar h1{font-size:18px;color:var(--ink)}
        .mbi-content{flex:1;padding:30px;overflow-y:auto}
        .container{max-width:1400px;margin:0 auto}
        .filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:24px}
        .filter-group{display:flex;flex-direction:column;gap:6px}
        .filter-group label{font-size:12px;font-weight:600;color:var(--muted)}
        .filter-group select{padding:8px 12px;background:#ffffff;border:1px solid var(--stroke);border-radius:8px;color:var(--ink);font-family:inherit;cursor:pointer;font-size:14px}
        .filter-group select:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 2px rgba(72,120,166,0.12)}
        .reset-btn{padding:8px 14px;background:rgba(16,185,129,0.2);border:1px solid rgba(16,185,129,0.4);color:#4a6038;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px;text-decoration:none;transition:all 0.2s;align-self:flex-end}
        .reset-btn:hover{background:rgba(16,185,129,0.3);border-color:rgba(16,185,129,0.6)}
        .card{background:#ffffff;border:1px solid var(--stroke);border-radius:12px;padding:20px;margin-bottom:20px}
        .card-title{font-size:16px;font-weight:700;color:var(--ink);margin-bottom:16px;display:flex;align-items:center;gap:8px}
        .table-wrapper{overflow-x:auto}
        table{width:100%;border-collapse:collapse;font-size:13px}
        th{background:rgba(72,120,166,0.08);padding:12px;text-align:left;font-weight:600;color:var(--accent);border-bottom:1px solid var(--stroke)}
        td{padding:12px;border-bottom:1px solid var(--stroke);color:var(--ink)}
        tr:hover{background:rgba(72,120,166,0.04)}
        tr:last-child td{border-bottom:none}
        .badge{display:inline-block;padding:4px 10px;border-radius:4px;font-size:11px;font-weight:600;white-space:nowrap}
        .status-valide{background:rgba(16,185,129,0.2);color:#4a6038}
        .status-refuse{background:rgba(239,90,107,0.2);color:#FF6B7A}
        .status-attente{background:rgba(255,165,0,0.2);color:#FFD700}
        .grid-2{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px}
        .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
        .stat-item{background:rgba(72,120,166,0.08);border:1px solid rgba(72,120,166,0.2);border-radius:8px;padding:12px;text-align:center}
        .stat-value{font-size:24px;font-weight:700;color:var(--accent);margin-bottom:4px}
        .stat-label{font-size:11px;color:var(--muted);text-transform:uppercase}
        .export-buttons{display:flex;gap:12px;margin-bottom:20px}
        .btn{padding:8px 16px;background:rgba(72,120,166,0.12);border:1px solid rgba(72,120,166,0.25);color:var(--accent);border-radius:6px;cursor:pointer;font-weight:600;font-size:13px;transition:all 0.2s;text-decoration:none;display:inline-block}
        .btn:hover{background:rgba(72,120,166,0.2);border-color:var(--accent)}
        .btn-gold{background:rgba(255,215,0,0.2);border:1px solid rgba(255,215,0,0.4);color:#FFD700}
        .btn-gold:hover{background:rgba(255,215,0,0.3);border-color:#FFD700}
        .chart-container{position:relative;height:300px;margin-bottom:20px}
        .no-data{color:var(--muted);font-size:14px;padding:20px;text-align:center}
        @media(max-width:1024px){.grid-2{grid-template-columns:1fr}.stats-grid{grid-template-columns:repeat(2,1fr)}.filter-group{grid-column:span 1}.mbi-sidebar{transform:translateX(-100%)}.mbi-main{margin-left:0}}
        @media print{body{background:#fff;color:#000}.mbi-sidebar{display:none}.mbi-main{margin-left:0}.mbi-topbar{display:none}.export-buttons{display:none}.filters{display:none}.card{border:1px solid #000;page-break-inside:avoid}}
    </style>
</head>
<body>
<?php include __DIR__ . '/inc/rh_sidebar.php'; ?>

<main class="mbi-main">
    <div class="mbi-topbar">
        <h1>📊 Historique Congés</h1>
        <?php require_once __DIR__ . '/inc/role_switcher.php'; ?>
    </div>

    <div class="mbi-content">
        <div class="container">
            <form method="GET" style="display:contents">
                <div class="filters">
                    <div class="filter-group">
                        <label>Année</label>
                        <select name="year" onchange="this.form.submit()">
                            <?php for ($y = (int)date('Y') - 3; $y <= (int)date('Y') + 3; $y++): ?>
                                <option value="<?=$y?>" <?=($y === $selectedYear ? 'selected' : '')?>>
                                    <?=$y?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <?php if ($roleId === 1): ?>
                    <div class="filter-group">
                        <label>Société</label>
                        <select name="societe" onchange="this.form.submit()">
                            <option value="">Toutes</option>
                            <?php foreach($societes as $s): ?>
                                <option value="<?=$s['id']?>" <?=($filterSociete === $s['id'] ? 'selected' : '')?>>
                                    <?=h($s['nom'])?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Agence</label>
                        <select name="agence" onchange="this.form.submit()">
                            <option value="">Toutes</option>
                            <?php foreach($agences as $a): ?>
                                <option value="<?=$a['id']?>" <?=($filterAgence === $a['id'] ? 'selected' : '')?>>
                                    <?=h($a['nom_agence'])?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <a href="rh_conges_historiq.php" class="reset-btn">🔄 Réinitialiser</a>
                </div>
            </form>

            <div class="export-buttons">
                <button class="btn" onclick="exportPDF()">📄 Exporter PDF</button>
                <button class="btn btn-gold" onclick="exportExcel()">📊 Exporter Excel</button>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-value"><?=$totalLeaves?></div>
                    <div class="stat-label">Demandes totales</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?=$totalWorkingDays?></div>
                    <div class="stat-label">Jours ouvrables utilisés</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?=$avgPerEmployee?></div>
                    <div class="stat-label">Moyenne par employé</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?=key($motifCounts) ? ($motifLabels[key($motifCounts)] ?? key($motifCounts)) : '-'?></div>
                    <div class="stat-label">Type le plus courant</div>
                </div>
            </div>

            <!-- Balance Summary Card -->
            <?php if (!empty($balances)): ?>
            <div class="card">
                <div class="card-title">📋 Soldes Annuels - <?=$selectedYear?></div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Collaborateur</th>
                                <th style="text-align:right">Jours acquis</th>
                                <th style="text-align:right">Jours pris</th>
                                <th style="text-align:right">Jours restants</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($balances as $balance):
                                $fullName = trim(($balance['prenom'] ?? '') . ' ' . ($balance['nom'] ?? ''));
                                $totalAcquired = ((float)$balance['conge_a_prendre'] ?? 0) + ((float)$balance['conge_en_acquisition'] ?? 0);
                                $totalTaken = (float)$balance['conge_pris_n'] ?? 0;
                                $remaining = (float)$balance['solde_restant'] ?? 0;
                            ?>
                            <tr>
                                <td><?=h($fullName)?></td>
                                <td style="text-align:right"><?=number_format($totalAcquired, 2, ',', ' ')?></td>
                                <td style="text-align:right"><?=number_format($totalTaken, 2, ',', ' ')?></td>
                                <td style="text-align:right"><?=number_format($remaining, 2, ',', ' ')?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php else: ?>
            <div class="card">
                <div class="card-title">📋 Soldes Annuels - <?=$selectedYear?></div>
                <p class="no-data">Aucun solde de congé disponible pour cette période</p>
            </div>
            <?php endif; ?>

            <!-- Charts Section -->
            <div class="grid-2">
                <div class="card">
                    <div class="card-title">📊 Distribution des types de congés</div>
                    <?php if (!empty($chartData)): ?>
                    <div class="chart-container">
                        <canvas id="motifChart"></canvas>
                    </div>
                    <?php else: ?>
                    <p class="no-data">Aucune donnée disponible</p>
                    <?php endif; ?>
                </div>
                <div class="card">
                    <div class="card-title">✓ Statuts des demandes</div>
                    <div style="padding:20px">
                        <div style="display:flex;justify-content:space-around;align-items:center">
                            <div style="text-align:center">
                                <div style="font-size:32px;font-weight:700;color:#4a6038;margin-bottom:8px"><?=$statusCounts['validé']?></div>
                                <div style="font-size:12px;color:var(--muted)">Approuvées</div>
                            </div>
                            <div style="text-align:center">
                                <div style="font-size:32px;font-weight:700;color:#FFD700;margin-bottom:8px"><?=$statusCounts['en_attente']?></div>
                                <div style="font-size:12px;color:var(--muted)">En attente</div>
                            </div>
                            <div style="text-align:center">
                                <div style="font-size:32px;font-weight:700;color:#FF6B7A;margin-bottom:8px"><?=$statusCounts['refuse']?></div>
                                <div style="font-size:12px;color:var(--muted)">Refusées</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Leave Requests Table -->
            <div class="card">
                <div class="card-title">📅 Demandes de congés - <?=$selectedYear?></div>
                <?php if (!empty($leaveRequests)): ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Collaborateur</th>
                                <th>Dates</th>
                                <th style="text-align:center">Jours</th>
                                <th>Type</th>
                                <th style="text-align:center">Statut</th>
                                <th>Date demande</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leaveRequests as $leave):
                                $fullName = trim(($leave['prenom'] ?? '') . ' ' . ($leave['nom'] ?? ''));
                                $startDate = new DateTime($leave['date_debut']);
                                $endDate = new DateTime($leave['date_fin']);
                                $daysCount = count_working_days($leave['date_debut'], $leave['date_fin']);
                                $motif = $leave['motif'] ?? 'autre_legal_non_deduit';
                                $motifLabel = $motifLabels[$motif] ?? $motif;
                                $status = $leave['statut'] ?? 'en_attente';
                                $statusClass = 'status-' . $status;
                                $requestDate = new DateTime($leave['date_demande'] ?? '');
                            ?>
                            <tr>
                                <td><?=h($fullName)?></td>
                                <td><?=$startDate->format('d/m/Y')?> → <?=$endDate->format('d/m/Y')?></td>
                                <td style="text-align:center"><?=$daysCount?> j</td>
                                <td><?=h($motifLabel)?></td>
                                <td style="text-align:center">
                                    <span class="badge <?=$statusClass?>">
                                        <?=ucfirst($status)?>
                                    </span>
                                </td>
                                <td><?=$requestDate->format('d/m/Y H:i')?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <p class="no-data">Aucune demande de congé pour cette période</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>

<script>
// Chart.js initialization
window.addEventListener('load', function() {
    const chartCanvas = document.getElementById('motifChart');
    if (chartCanvas) {
        const labels = <?=$chartLabelsJson?>;
        const data = <?=$chartDataJson?>;

        new Chart(chartCanvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Nombre de demandes',
                    data: data,
                    backgroundColor: 'rgba(102, 217, 255, 0.3)',
                    borderColor: 'rgba(102, 217, 255, 1)',
                    borderWidth: 2,
                    borderRadius: 6,
                    hoverBackgroundColor: 'rgba(102, 217, 255, 0.5)'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { color: '#7a91a8', stepSize: 1 },
                        grid: { color: 'rgba(255, 255, 255, 0.08)' }
                    },
                    x: {
                        ticks: { color: '#7a91a8', maxRotation: 45, minRotation: 0 },
                        grid: { display: false }
                    }
                }
            }
        });
    }
});

// Export to PDF
function exportPDF() {
    const element = document.querySelector('.mbi-content');
    const opt = {
        margin: 10,
        filename: 'historique-conges-<?=$selectedYear?>.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, backgroundColor: '#1a2a3a', color: '#d0e4ff' },
        jsPDF: { orientation: 'landscape', unit: 'mm', format: 'a4' }
    };
    html2pdf().set(opt).from(element).save();
}

// Export to Excel
function exportExcel() {
    const tables = document.querySelectorAll('table');
    let html = '<table border="1">\n';

    tables.forEach((table, idx) => {
        if (idx > 0) html += '\n<tr><td colspan="10">&nbsp;</td></tr>\n';
        const rows = table.querySelectorAll('tr');
        rows.forEach(row => {
            html += '<tr>';
            row.querySelectorAll('th, td').forEach(cell => {
                const tag = cell.tagName.toLowerCase();
                html += `<${tag}>${cell.textContent}</${tag}>`;
            });
            html += '</tr>\n';
        });
    });
    html += '</table>';

    const blob = new Blob([html], { type: 'application/vnd.ms-excel;charset=utf-8' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'historique-conges-<?=$selectedYear?>.xls';
    link.click();
}
</script>
</body>
</html>
