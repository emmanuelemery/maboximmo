<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();
$pdo    = $GLOBALS['pdo'];
$roleId = current_role_id();
$userId = current_user_id();
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Accès réservé manager (2) et admin (1)
if ($roleId !== 1 && $roleId !== 2) {
    http_response_code(403); exit('Accès réservé aux managers et administrateurs.');
}

// --- Filtres ---
$filterAgence = ($roleId === 1 && !empty($_GET['agence_id'])) ? (int)$_GET['agence_id'] : 0;

// Agences (admin)
$agences = [];
if ($roleId === 1) {
    try {
        $agences = $pdo->query("SELECT id, nom_agence FROM agences ORDER BY nom_agence")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { /* ignoré */ }
}

// Clause scope
$scopeWhere  = ($roleId === 2) ? " AND e.manager_id = $userId" : "";
$agenceWhere = ($roleId === 1 && $filterAgence > 0) ? " AND e.agence_id = $filterAgence" : "";
$baseWhere   = "WHERE 1=1 $scopeWhere $agenceWhere";

// --- Widgets ---
$widgets = [
    'total_planifie'    => 0,
    'en_cours_mois'     => 0,
    'termines_mois'     => 0,
    'taux_signature'    => 0,
    'alertes_actives'   => 0,
];

try {
    // Total planifiés (non archivés)
    $stmt = $pdo->query("SELECT COUNT(*) FROM rh_entretiens e $baseWhere AND e.statut NOT IN ('archive')");
    $widgets['total_planifie'] = (int)$stmt->fetchColumn();

    // En cours ce mois
    $stmt = $pdo->query("SELECT COUNT(*) FROM rh_entretiens e $baseWhere AND e.statut = 'en_cours' AND MONTH(e.date_planifiee) = MONTH(NOW()) AND YEAR(e.date_planifiee) = YEAR(NOW())");
    $widgets['en_cours_mois'] = (int)$stmt->fetchColumn();

    // Terminés ce mois
    $stmt = $pdo->query("SELECT COUNT(*) FROM rh_entretiens e $baseWhere AND e.statut IN ('termine','signe') AND MONTH(e.updated_at) = MONTH(NOW()) AND YEAR(e.updated_at) = YEAR(NOW())");
    $widgets['termines_mois'] = (int)$stmt->fetchColumn();

    // Taux signature
    $total = (int)$pdo->query("SELECT COUNT(*) FROM rh_entretiens e $baseWhere AND e.statut IN ('termine','signe')")->fetchColumn();
    $signes = (int)$pdo->query("SELECT COUNT(*) FROM rh_entretiens e $baseWhere AND e.statut = 'signe'")->fetchColumn();
    $widgets['taux_signature'] = $total > 0 ? round(($signes / $total) * 100) : 0;

    // Alertes actives
    $stmtAl = $pdo->query("SELECT COUNT(*) FROM rh_entretien_alertes al JOIN rh_entretiens e ON e.id = al.entretien_id $baseWhere AND al.resolu = 0");
    $widgets['alertes_actives'] = (int)$stmtAl->fetchColumn();
} catch (PDOException $e) { /* ignoré */ }

// --- Répartition par statut (donut) ---
$statutData = [];
try {
    $stmt = $pdo->query("SELECT e.statut, COUNT(*) AS cnt FROM rh_entretiens e $baseWhere AND e.statut != 'archive' GROUP BY e.statut");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $statutData[$row['statut']] = (int)$row['cnt'];
    }
} catch (PDOException $e) { /* ignoré */ }

// --- Évolution mensuelle (12 derniers mois) ---
$evolutionData = [];
try {
    $stmt = $pdo->query("
        SELECT DATE_FORMAT(e.date_planifiee, '%Y-%m') AS mois, COUNT(*) AS cnt
        FROM rh_entretiens e $baseWhere
        AND e.date_planifiee >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(e.date_planifiee, '%Y-%m')
        ORDER BY mois ASC
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $evolutionData[] = $row;
    }
} catch (PDOException $e) { /* ignoré */ }

// --- Distribution scores globaux ---
$scoresDistrib = [
    '0-1' => 0, '1-2' => 0, '2-3' => 0, '3-4' => 0, '4-5' => 0
];
try {
    $stmt = $pdo->query("
        SELECT sc.score
        FROM rh_entretien_scores sc
        JOIN rh_entretiens e ON e.id = sc.entretien_id
        $baseWhere AND sc.axe = 'performance' AND sc.score IS NOT NULL
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $v = (float)$row['score'] * 5;
        if ($v <= 1)      $scoresDistrib['0-1']++;
        elseif ($v <= 2)  $scoresDistrib['1-2']++;
        elseif ($v <= 3)  $scoresDistrib['2-3']++;
        elseif ($v <= 4)  $scoresDistrib['3-4']++;
        else              $scoresDistrib['4-5']++;
    }
} catch (PDOException $e) { /* ignoré */ }

// --- Collaborateurs à suivre ---
$collabsSuivre = [];
try {
    $condProfil = ($roleId === 2) ? "AND e.manager_id = $userId" : ($filterAgence ? "AND e.agence_id = $filterAgence" : "");
    $stmt = $pdo->query("
        SELECT e.id, e.statut, e.date_planifiee, e.profil_auto, e.type_entretien,
               uc.prenom AS collab_prenom, uc.nom AS collab_nom,
               um.prenom AS manager_prenom, um.nom AS manager_nom,
               (SELECT COUNT(*) FROM rh_entretien_alertes al WHERE al.entretien_id = e.id AND al.resolu = 0 AND al.niveau = 'critique') AS alertes_critiques
        FROM rh_entretiens e
        JOIN users uc ON uc.id = e.collaborateur_id
        JOIN users um ON um.id = e.manager_id
        WHERE e.statut NOT IN ('archive')
        $condProfil
        HAVING (e.profil_auto IN ('a_risque','a_accompagner') OR alertes_critiques > 0 OR (e.statut = 'planifie' AND e.date_planifiee < NOW()))
        ORDER BY alertes_critiques DESC, e.date_planifiee ASC
        LIMIT 20
    ");
    $collabsSuivre = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* ignoré */ }

// Données JS
$jsStatutLabels = json_encode(array_keys($statutData));
$jsStatutData   = json_encode(array_values($statutData));
$jsEvoLabels    = json_encode(array_column($evolutionData, 'mois'));
$jsEvoData      = json_encode(array_column($evolutionData, 'cnt'));
$jsScoreDist    = json_encode(array_values($scoresDistrib));

$statutBadge = [
    'planifie'           => ['Planifié',     '#8899aa'],
    'en_cours'           => ['En cours',     '#ffa040'],
    'questionnaire_envoye' => ['Questionnaire envoyé', '#66b3ff'],
    'termine'            => ['Terminé',      '#22c55e'],
    'signe'              => ['Signé',        '#3b82f6'],
    'archive'            => ['Archivé',      '#6366f1'],
];
$profilMap = ['excellent' => 'Excellent', 'performant' => 'Performant', 'a_accompagner' => 'À accompagner', 'a_risque' => 'À risque'];

// ============================================================
// LAYOUT VARIABLES
// ============================================================
$layout_title       = 'Dashboard Entretiens';
$layout_module      = 'Ma Box RH';
$layout_sidebar     = 'rh_sidebar';
$layout_head_kpis   = '';
$layout_head_actions = '';

$layout_extra_css = <<<'EXTRACSS'
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*, *::before, *::after { box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--bg-soft, #f5f6fa); margin: 0; display: flex; }
.layout { display: flex; width: 100%; min-height: 100vh; padding-left: var(--sidebar-w, 250px); box-sizing: border-box; }
.content-area { flex: 1; min-width: 0; padding: 1.5rem; overflow-y: auto; }
.page-title { font-size: 1.3rem; font-weight: 700; color: #1a1a2e; margin: 0 0 1.25rem; }

/* Widgets */
.widgets-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
@media (max-width: 900px) { .widgets-row { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 500px) { .widgets-row { grid-template-columns: 1fr; } }
.widget-card { background: #fff; border-radius: 10px; padding: 1.2rem 1.25rem; box-shadow: 0 1px 6px rgba(0,0,0,.06); display: flex; flex-direction: column; gap: .25rem; }
.widget-label { font-size: .78rem; text-transform: uppercase; letter-spacing: .06em; color: #64748b; font-weight: 700; }
.widget-value { font-size: 2rem; font-weight: 800; color: #1a1a2e; line-height: 1.1; }
.widget-sub   { font-size: .78rem; color: #94a3b8; }
.widget-alerte .widget-value { color: #ef4444; }
.widget-sig .widget-value    { color: #3b82f6; }

/* Filtres */
.filters-bar { background: #fff; border-radius: 10px; padding: .75rem 1.25rem; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; box-shadow: 0 1px 6px rgba(0,0,0,.06); }
.filters-bar label { font-size: .83rem; color: #64748b; font-weight: 600; }
.filters-bar select { font-size: .85rem; padding: .3rem .6rem; border-radius: 6px; border: 1px solid #e2e8f0; background: #fff; }
.btn-filter { background: linear-gradient(135deg,#ffa040,#ff7c00); color: #fff; border: none; padding: .35rem 1rem; border-radius: 6px; font-size: .83rem; font-weight: 600; cursor: pointer; }

/* Graphiques */
.charts-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem; }
@media (max-width: 1100px) { .charts-row { grid-template-columns: 1fr 1fr; } }
@media (max-width: 700px)  { .charts-row { grid-template-columns: 1fr; } }
@media (max-width: 768px) { .layout { padding-left: 0; } }
.card { background: #fff; border-radius: 10px; padding: 1.25rem; box-shadow: 0 1px 6px rgba(0,0,0,.06); }
.card-title { font-size: .8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #64748b; margin: 0 0 1rem; border-bottom: 1px solid #f1f5f9; padding-bottom: .5rem; }
.chart-wrap { position: relative; height: 200px; }

/* Tableau */
.section-title { font-size: 1rem; font-weight: 700; color: #1a1a2e; margin: 0 0 .75rem; }
.alert-table { width: 100%; border-collapse: collapse; font-size: .87rem; background: #fff; border-radius: 10px; overflow: hidden; box-shadow: 0 1px 6px rgba(0,0,0,.06); }
.alert-table th { background: #f8fafc; padding: .65rem 1rem; text-align: left; font-size: .78rem; color: #4a6038; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; border-bottom: 2px solid #e2e8f0; }
.alert-table td { padding: .6rem 1rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.alert-table tr:last-child td { border-bottom: none; }
.alert-table tr:hover td { background: #fafbff; }
.badge { display: inline-flex; align-items: center; padding: .2rem .6rem; border-radius: 12px; font-size: .75rem; font-weight: 700; }
.badge-alerte { background: rgba(239,68,68,.12); color: #991b1b; }
.badge-retard  { background: rgba(251,191,36,.15); color: #92400e; }
.badge-risque  { background: rgba(239,68,68,.12); color: #991b1b; }
.badge-accomp  { background: rgba(251,191,36,.15); color: #92400e; }
.link-detail   { color: #ffa040; text-decoration: none; font-weight: 600; font-size: .82rem; }
.link-detail:hover { text-decoration: underline; }
</style>
EXTRACSS;

ob_start();
?>

        <!-- Filtres admin -->
        <?php if ($roleId === 1): ?>
        <form method="GET" class="filters-bar">
            <label for="agence_id">Agence</label>
            <select name="agence_id" id="agence_id">
                <option value="">Toutes les agences</option>
                <?php foreach ($agences as $ag): ?>
                <option value="<?= $ag['id'] ?>" <?= $filterAgence === (int)$ag['id'] ? 'selected' : '' ?>>
                    <?= h($ag['nom_agence']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-filter">Filtrer</button>
            <?php if ($filterAgence): ?>
            <a href="rh_entretien_dashboard.php" style="font-size:.83rem;color:#64748b;text-decoration:none;">✕ Réinitialiser</a>
            <?php endif; ?>
        </form>
        <?php endif; ?>

        <!-- Widgets -->
        <div class="widgets-row">
            <div class="widget-card">
                <div class="widget-label">Entretiens planifiés</div>
                <div class="widget-value"><?= $widgets['total_planifie'] ?></div>
                <div class="widget-sub">Total actifs</div>
            </div>
            <div class="widget-card">
                <div class="widget-label">Ce mois</div>
                <div class="widget-value"><?= $widgets['en_cours_mois'] + $widgets['termines_mois'] ?></div>
                <div class="widget-sub"><?= $widgets['en_cours_mois'] ?> en cours · <?= $widgets['termines_mois'] ?> terminés</div>
            </div>
            <div class="widget-card widget-sig">
                <div class="widget-label">Taux de signature</div>
                <div class="widget-value"><?= $widgets['taux_signature'] ?>%</div>
                <div class="widget-sub">Des entretiens terminés</div>
            </div>
            <div class="widget-card widget-alerte">
                <div class="widget-label">Alertes RH actives</div>
                <div class="widget-value"><?= $widgets['alertes_actives'] ?></div>
                <div class="widget-sub">Non résolues</div>
            </div>
        </div>

        <!-- Graphiques -->
        <div class="charts-row">
            <div class="card">
                <div class="card-title">Répartition par statut</div>
                <div class="chart-wrap">
                    <canvas id="chartDonut"></canvas>
                </div>
            </div>
            <div class="card">
                <div class="card-title">Évolution mensuelle (12 mois)</div>
                <div class="chart-wrap">
                    <canvas id="chartLine"></canvas>
                </div>
            </div>
            <div class="card">
                <div class="card-title">Distribution des scores (performance)</div>
                <div class="chart-wrap">
                    <canvas id="chartBar"></canvas>
                </div>
            </div>
        </div>

        <!-- Tableau collaborateurs à suivre -->
        <div class="section-title">Collaborateurs à suivre</div>
        <?php if (empty($collabsSuivre)): ?>
        <div class="card" style="text-align:center;color:#94a3b8;padding:2rem;">Aucun collaborateur nécessitant un suivi particulier.</div>
        <?php else: ?>
        <table class="alert-table">
            <thead>
                <tr>
                    <th>Collaborateur</th>
                    <th>Manager</th>
                    <th>Type</th>
                    <th>Statut</th>
                    <th>Profil</th>
                    <th>Alertes critiques</th>
                    <th>Motif</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($collabsSuivre as $c):
                $sbInfo = $statutBadge[$c['statut'] ?? ''] ?? ['Inconnu','#94a3b8'];
                $retard = ($c['statut'] === 'planifie' && !empty($c['date_planifiee']) && strtotime($c['date_planifiee']) < time());
            ?>
            <tr>
                <td><strong><?= h($c['collab_prenom'] . ' ' . $c['collab_nom']) ?></strong></td>
                <td><?= h($c['manager_prenom'] . ' ' . $c['manager_nom']) ?></td>
                <td><?= h($c['type_entretien'] ?? '—') ?></td>
                <td>
                    <span class="badge" style="background:<?= $sbInfo[1] ?>22;color:<?= $sbInfo[1] ?>;">
                        <?= h($sbInfo[0]) ?>
                    </span>
                </td>
                <td>
                    <?php if (!empty($c['profil_auto'])): ?>
                    <span class="badge <?= in_array($c['profil_auto'], ['a_risque','a_accompagner']) ? 'badge-risque' : '' ?>">
                        <?= h($profilMap[$c['profil_auto']] ?? $c['profil_auto']) ?>
                    </span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                    <?php if ((int)$c['alertes_critiques'] > 0): ?>
                    <span class="badge badge-alerte"><?= (int)$c['alertes_critiques'] ?> critique<?= (int)$c['alertes_critiques'] > 1 ? 's' : '' ?></span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                    <?php
                    $motifs = [];
                    if (in_array($c['profil_auto'] ?? '', ['a_risque','a_accompagner'])) $motifs[] = 'Profil';
                    if ((int)$c['alertes_critiques'] > 0) $motifs[] = 'Alerte';
                    if ($retard) $motifs[] = 'En retard';
                    echo !empty($motifs) ? implode(', ', $motifs) : '—';
                    ?>
                </td>
                <td>
                    <a href="rh_entretien_detail.php?id=<?= (int)$c['id'] ?>" class="link-detail">Détail →</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

<?php
$layout_content = ob_get_clean();

$_jsStatutLabels = $jsStatutLabels;
$_jsStatutData   = $jsStatutData;
$_jsEvoLabels    = $jsEvoLabels;
$_jsEvoData      = $jsEvoData;
$_jsScoreDist    = $jsScoreDist;

$layout_extra_js = <<<EXTRAJS
<script>
// Donut statuts
const statutLabels = {$_jsStatutLabels};
const statutData   = {$_jsStatutData};
const statutColors = {
    planifie: '#8899aa', questionnaire_envoye: '#66b3ff', en_cours: '#ffa040',
    termine: '#22c55e', signe: '#3b82f6', archive: '#6366f1'
};
new Chart(document.getElementById('chartDonut'), {
    type: 'doughnut',
    data: {
        labels: statutLabels,
        datasets: [{
            data: statutData,
            backgroundColor: statutLabels.map(l => (statutColors[l] || '#94a3b8') + '99'),
            borderColor: statutLabels.map(l => statutColors[l] || '#94a3b8'),
            borderWidth: 2,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 8 } } }
    }
});

// Line évolution
const evoLabels = {$_jsEvoLabels};
const evoData   = {$_jsEvoData};
new Chart(document.getElementById('chartLine'), {
    type: 'line',
    data: {
        labels: evoLabels,
        datasets: [{
            label: 'Entretiens',
            data: evoData,
            borderColor: '#ffa040',
            backgroundColor: 'rgba(255,160,64,0.1)',
            tension: 0.4,
            fill: true,
            pointRadius: 4,
            pointBackgroundColor: '#ffa040',
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { ticks: { font: { size: 10 } } },
            y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 10 } } }
        }
    }
});

// Bar distribution scores
const scoreDistData = {$_jsScoreDist};
new Chart(document.getElementById('chartBar'), {
    type: 'bar',
    data: {
        labels: ['0-1', '1-2', '2-3', '3-4', '4-5'],
        datasets: [{
            label: 'Nombre',
            data: scoreDistData,
            backgroundColor: ['#ef4444aa','#ffa040aa','#eab308aa','#22c55eaa','#3b82f6aa'],
            borderColor:      ['#ef4444','#ffa040','#eab308','#22c55e','#3b82f6'],
            borderWidth: 1.5,
            borderRadius: 4,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 10 } } },
            x: { ticks: { font: { size: 10 } } }
        }
    }
});
</script>
EXTRAJS;

require_once __DIR__ . '/inc/layout_maboximmo.php';
