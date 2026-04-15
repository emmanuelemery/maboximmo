<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

$user = check_auth_gestion('SIR');
$pdo  = $GLOBALS['pdo'];

if (!function_exists('e')) {
    function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$current_page = 'dashboard_sir';
$nav_context  = 'sir';

/* ── Multi-select: toggle proprietaires ─────────────── */
// Get ALL proprietaire IDs this user can access
$stmtAll = $pdo->prepare("SELECT id_proprietaire FROM user_proprietaires WHERE id_user = ?");
$stmtAll->execute([$user['id']]);
$all_prop_ids = array_column($stmtAll->fetchAll(), 'id_proprietaire');

// Handle toggle via GET
if (isset($_GET['toggle'])) {
    $toggleId = (string)$_GET['toggle'];
    $current = $_SESSION['sir_selected'] ?? $all_prop_ids;
    if ($toggleId === 'ALL') {
        $current = $all_prop_ids;
    } elseif ($toggleId === 'NONE') {
        $current = [];
    } elseif ($toggleId === 'SIR' || $toggleId === 'SABY') {
        // Select all of a label group
        $stmtGrp = $pdo->prepare("SELECT id_proprietaire FROM user_proprietaires WHERE id_user = ? AND label = ?");
        $stmtGrp->execute([$user['id'], $toggleId]);
        $current = array_column($stmtGrp->fetchAll(), 'id_proprietaire');
    } else {
        $id = (int)$toggleId;
        if (in_array($id, $all_prop_ids)) {
            if (in_array($id, $current)) {
                $current = array_values(array_diff($current, [$id]));
            } else {
                $current[] = $id;
            }
        }
    }
    $_SESSION['sir_selected'] = $current;
    // Redirect to clean URL
    header('Location: ' . app_url('/gestion/dashboard_sir.php'));
    exit;
}

$selected_ids = $_SESSION['sir_selected'] ?? $all_prop_ids;
// Filter to only valid IDs
$prop_ids = array_values(array_intersect($selected_ids, $all_prop_ids));
if (empty($prop_ids)) $prop_ids = $all_prop_ids;
if (empty($prop_ids)) {
    // Will render empty state below
    $prop_ids       = [];
    $entities       = [];
    $lastCrg        = null;
    $kpi            = [];
    $history        = [];
    $repartition    = [];
    $alertes        = [];
} else {
    $placeholders = implode(',', array_fill(0, count($prop_ids), '?'));

    /* ── Entity labels (each SCI individually) ────── */
    $stmtEnt = $pdo->prepare("
        SELECT up.id_proprietaire, up.label, p.societe
        FROM user_proprietaires up
        JOIN proprietaires p ON p.id = up.id_proprietaire
        WHERE up.id_user = ?
        ORDER BY up.ordre
    ");
    $stmtEnt->execute([$user['id']]);
    $allEntities = $stmtEnt->fetchAll(PDO::FETCH_ASSOC);
    // Distinct labels for group filter
    $entities = array_unique(array_column($allEntities, 'label'));

    /* ── Last CRG ───────────────────────────────────── */
    $stmtCrg = $pdo->prepare("
        SELECT id, annee, trimestre, date_arrete, total_credits, total_debits
        FROM crg_trimestres
        WHERE id_proprietaire IN ($placeholders) AND parse_statut = 'ok'
        ORDER BY annee DESC, trimestre DESC
        LIMIT 1
    ");
    $stmtCrg->execute($prop_ids);
    $lastCrg = $stmtCrg->fetch(PDO::FETCH_ASSOC);

    if ($lastCrg) {
        $lastAnnee     = (int)$lastCrg['annee'];
        $lastTrimestre = (int)$lastCrg['trimestre'];

        /* ── All CRG IDs for that trimester (multi-entity) */
        $stmtCrgIds = $pdo->prepare("
            SELECT id, total_credits, total_debits
            FROM crg_trimestres
            WHERE id_proprietaire IN ($placeholders)
              AND annee = ? AND trimestre = ? AND parse_statut = 'ok'
        ");
        $stmtCrgIds->execute(array_merge($prop_ids, [$lastAnnee, $lastTrimestre]));
        $crgRows   = $stmtCrgIds->fetchAll(PDO::FETCH_ASSOC);
        $crgIds    = array_column($crgRows, 'id');
        $sumCredits = array_sum(array_column($crgRows, 'total_credits'));
        $sumDebits  = array_sum(array_column($crgRows, 'total_debits'));

        if (empty($crgIds)) {
            $kpi = [];
        } else {
            $phCrg = implode(',', array_fill(0, count($crgIds), '?'));

            /* ── KPIs ───────────────────────────────── */
            $stmtKpi = $pdo->prepare("
                SELECT
                    SUM(CASE WHEN statut_trimestre = 'occupé' THEN total_impaye ELSE 0 END) AS impaye_actif,
                    SUM(CASE WHEN statut_trimestre = 'parti-débiteur' THEN solde_anterieur ELSE 0 END) AS creances_partis,
                    SUM(loyer_appele) AS total_loyers_appeles,
                    SUM(CASE WHEN statut_trimestre = 'occupé' THEN 1 ELSE 0 END) AS nb_occupes,
                    SUM(CASE WHEN statut_trimestre = 'vacant' THEN 1 ELSE 0 END) AS nb_vacants,
                    SUM(CASE WHEN statut_trimestre = 'parti-débiteur' THEN 1 ELSE 0 END) AS nb_partis
                FROM crg_situations_locataires
                WHERE id_crg IN ($phCrg)
            ");
            $stmtKpi->execute($crgIds);
            $kpi = $stmtKpi->fetch(PDO::FETCH_ASSOC) ?: [];
        }

        /* ── History: last 12 quarters ──────────────── */
        $stmtHist = $pdo->prepare("
            SELECT ct.annee, ct.trimestre,
                   SUM(ct.total_credits) AS credits,
                   SUM(s.total_impaye) AS impaye,
                   SUM(s.loyer_appele) AS loyer_appele
            FROM crg_trimestres ct
            JOIN crg_situations_locataires s ON s.id_crg = ct.id
            WHERE ct.id_proprietaire IN ($placeholders) AND ct.parse_statut = 'ok'
            GROUP BY ct.annee, ct.trimestre
            ORDER BY ct.annee DESC, ct.trimestre DESC
            LIMIT 12
        ");
        $stmtHist->execute($prop_ids);
        $history = array_reverse($stmtHist->fetchAll(PDO::FETCH_ASSOC));

        /* ── Répartition donut ──────────────────────── */
        if (!empty($crgIds)) {
            $stmtRep = $pdo->prepare("
                SELECT categorie_bien,
                       COUNT(*) AS nb,
                       SUM(CASE WHEN statut_trimestre = 'occupé' THEN loyer_appele ELSE 0 END) AS loyers
                FROM crg_situations_locataires
                WHERE id_crg IN ($phCrg)
                GROUP BY categorie_bien
            ");
            $stmtRep->execute($crgIds);
            $repartition = $stmtRep->fetchAll(PDO::FETCH_ASSOC);

            /* ── Alertes table ──────────────────────── */
            $stmtAlert = $pdo->prepare("
                SELECT s.numero_lot, s.type_bien, s.categorie_bien,
                       s.statut_trimestre, s.loyer_appele, s.total_impaye,
                       s.solde_anterieur, i.nom_immeuble
                FROM crg_situations_locataires s
                LEFT JOIN biens b ON b.id = s.id_bien
                LEFT JOIN immeubles i ON i.id = b.id_immeuble
                WHERE s.id_crg IN ($phCrg)
                  AND (s.total_impaye > 0 OR s.solde_anterieur > 0)
                ORDER BY s.total_impaye DESC
                LIMIT 20
            ");
            $stmtAlert->execute($crgIds);
            $alertes = $stmtAlert->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $repartition = [];
            $alertes     = [];
        }
    } else {
        $kpi         = [];
        $history     = [];
        $repartition = [];
        $alertes     = [];
        $sumCredits  = 0;
        $sumDebits   = 0;
    }
}

/* ── Computed KPIs ──────────────────────────────────── */
$impayeActif     = (float)($kpi['impaye_actif'] ?? 0);
$creancesPartis  = (float)($kpi['creances_partis'] ?? 0);
$totalLoyers     = (float)($kpi['total_loyers_appeles'] ?? 0);
$nbOccupes       = (int)($kpi['nb_occupes'] ?? 0);
$nbVacants       = (int)($kpi['nb_vacants'] ?? 0);
$nbPartis        = (int)($kpi['nb_partis'] ?? 0);
$encaissements   = (float)($sumCredits ?? 0);
$chargesNettes   = (float)($sumDebits ?? 0);
$tauxEncaissement = $totalLoyers > 0 ? round($encaissements / $totalLoyers * 100, 1) : 0;

/* ── Chart data prep ────────────────────────────────── */
$histLabels     = [];
$histCredits    = [];
$histImpayes    = [];
$histTaux       = [];
foreach ($history as $h) {
    $histLabels[]  = 'T' . $h['trimestre'] . ' ' . $h['annee'];
    $histCredits[] = round((float)$h['credits'], 2);
    $histImpayes[] = round((float)$h['impaye'], 2);
    $la = (float)$h['loyer_appele'];
    $histTaux[]    = $la > 0 ? round((float)$h['credits'] / $la * 100, 1) : 0;
}

$donutLabels = [];
$donutData   = [];
$donutColors = ['#4f8ef7', '#d4a843', '#e85b5b', '#8a8575'];
foreach ($repartition as $i => $r) {
    $donutLabels[] = ucfirst($r['categorie_bien'] ?? 'autre');
    $donutData[]   = (int)$r['nb'];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tableau de bord Investisseur — MaBoxImmo</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/base.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/layout.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/components.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/gestion.css') ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
.entity-switch{display:flex;gap:6px;margin-bottom:24px;}
.switch-btn{padding:7px 18px;border-radius:8px;border:1px solid var(--border-color);background:var(--bg-secondary);color:var(--text-secondary);font-size:13px;font-weight:500;cursor:pointer;text-decoration:none;transition:all .15s;}
.switch-btn:hover{border-color:#d4a843;color:var(--text-primary);}
.switch-btn.active{background:#d4a843;color:#fff;border-color:#d4a843;}
.kpi-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px;}
.kpi-card{background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:12px;padding:18px 20px;}
.kpi-label{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-tertiary);margin-bottom:6px;}
.kpi-val{font-size:22px;font-weight:600;font-family:'JetBrains Mono',monospace;color:var(--text-primary);}
.kpi-val.gold{color:#d4a843;}.kpi-val.red{color:#e85b5b;}.kpi-val.orange{color:#e8a23e;}.kpi-val.green{color:#49b86a;}.kpi-val.blue{color:#4f8ef7;}
.kpi-sub{font-size:11px;color:var(--text-tertiary);margin-top:4px;}
.charts-row{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;}
.chart-box{background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:12px;padding:20px;}
.chart-box h3{font-size:14px;font-weight:500;margin-bottom:16px;color:var(--text-primary);}
.alert-table{width:100%;border-collapse:collapse;font-size:13px;}
.alert-table thead th{text-align:left;padding:10px 8px;border-bottom:1px solid var(--border-color);color:var(--text-tertiary);text-transform:uppercase;font-size:10px;letter-spacing:.07em;font-weight:500;}
.alert-table thead th.r{text-align:right;}
.alert-table tbody td{padding:9px 8px;border-bottom:1px solid var(--border-color);color:var(--text-primary);}
.alert-table tbody td.mono{font-family:'JetBrains Mono',monospace;text-align:right;}
.alert-table tbody td.mono.red{color:#e85b5b;}
.badge-gest{display:inline-block;padding:3px 10px;border-radius:6px;font-size:11px;font-weight:500;}
.badge-occupé{background:rgba(73,184,106,.12);color:#49b86a;}
.badge-parti-débiteur{background:rgba(232,91,91,.12);color:#e85b5b;}
.badge-vacant{background:rgba(138,133,117,.12);color:#8a8575;}
</style>
</head>
<body>

<?php include __DIR__ . '/../sidebar_bailleur.php'; ?>
<?php include __DIR__ . '/inc/nav_gestion.php'; ?>

<main style="margin-left:260px;padding:32px 40px;">

<?php if (empty($prop_ids)): ?>
  <div style="padding:60px;text-align:center;color:var(--text-tertiary);">
    <p style="font-size:16px;">Aucune entité rattachée à votre compte.</p>
    <p style="font-size:13px;margin-top:8px;">Contactez votre administrateur pour configurer vos accès.</p>
  </div>
<?php else: ?>

  <style>
    .sir-selector { margin-bottom:20px; }
    .sir-selector h2 { font-size:18px; font-weight:600; margin:0 0 12px; color:var(--text-primary,#1a1816); }
    .sir-selector .sir-info { font-size:12px; color:var(--text-tertiary,var(--gray-500)); margin-bottom:10px; }
    .sir-group-label { font-size:10px; text-transform:uppercase; letter-spacing:.08em; color:var(--text-tertiary,var(--gray-500)); margin:10px 0 6px; font-weight:600; }
    .sir-btns { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:6px; }
    .sir-btn {
      display:inline-flex; align-items:center; gap:5px; padding:5px 12px; border-radius:6px;
      font-size:12px; font-weight:500; cursor:pointer; transition:all .15s;
      border:1px solid var(--gray-300,#d8d4ce); background:var(--gray-50,#f8f6f3);
      color:var(--text-secondary,var(--gray-600)); text-decoration:none;
    }
    .sir-btn:hover { border-color:var(--brand-primary,#36577d); color:var(--brand-primary,#36577d); }
    .sir-btn.on {
      background:var(--brand-primary,#36577d); color:#fff;
      border-color:var(--brand-primary,#36577d);
      box-shadow:0 2px 8px rgba(54,87,125,.2);
    }
    .sir-btn.on:hover { filter:brightness(1.1); }
    .sir-btn-quick {
      padding:4px 10px; font-size:11px; border-radius:5px;
      border:1px solid var(--gray-300,#d8d4ce); background:transparent;
      color:var(--text-tertiary,var(--gray-500)); cursor:pointer; text-decoration:none;
    }
    .sir-btn-quick:hover { background:var(--gray-100,#eef1f6); }
    .sir-count { font-size:11px; color:var(--text-tertiary,var(--gray-500)); margin-top:4px; }
  </style>

  <!-- Header + Multi-select -->
  <div class="sir-selector">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:8px;">
      <div>
        <h2>
          <?php
            $nbSelected = count($prop_ids);
            $nbTotal = count($all_prop_ids);
            if ($nbSelected === $nbTotal) {
                echo 'Groupe SIR &amp; SABY — Vue consolidée';
            } elseif ($nbSelected === 1) {
                foreach ($allEntities as $ae) {
                    if ((int)$ae['id_proprietaire'] === (int)$prop_ids[0]) { echo e($ae['societe']); break; }
                }
            } else {
                echo $nbSelected . ' entités sélectionnées';
            }
          ?>
        </h2>
        <div class="sir-info">
          <?php if ($lastCrg): ?>
            Dernier CRG : T<?= (int)$lastCrg['trimestre'] ?> <?= (int)$lastCrg['annee'] ?>
            <?php if ($lastCrg['date_arrete']): ?> — arrêté au <?= e($lastCrg['date_arrete']) ?><?php endif; ?>
          <?php else: ?>
            Aucun CRG disponible
          <?php endif; ?>
        </div>
      </div>
      <div style="display:flex;gap:6px;">
        <a href="?toggle=ALL" class="sir-btn-quick">Tout</a>
        <a href="?toggle=SIR" class="sir-btn-quick">Pôle SIR</a>
        <a href="?toggle=SABY" class="sir-btn-quick">Pôle SABY</a>
      </div>
    </div>

    <?php
      // Group entities by label
      $byLabel = [];
      foreach ($allEntities as $ae) {
          $byLabel[$ae['label']][] = $ae;
      }
    ?>
    <?php foreach ($byLabel as $label => $ents): ?>
      <div class="sir-group-label"><?= e($label) ?></div>
      <div class="sir-btns">
        <?php foreach ($ents as $ae):
          $isOn = in_array((int)$ae['id_proprietaire'], $prop_ids);
        ?>
          <a href="?toggle=<?= (int)$ae['id_proprietaire'] ?>"
             class="sir-btn <?= $isOn ? 'on' : '' ?>">
            <?= $isOn ? '✓' : '' ?> <?= e($ae['societe']) ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

    <div class="sir-count"><?= $nbSelected ?> / <?= $nbTotal ?> entités · <?= count($prop_ids) ?> propriétaires sélectionnés</div>
  </div>

  <!-- KPI cards (row 1) -->
  <div class="kpi-grid">
    <div class="kpi-card">
      <div class="kpi-label">Encaissements</div>
      <div class="kpi-val gold"><?= number_format($encaissements, 2, ',', ' ') ?> &euro;</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Impayés actifs</div>
      <div class="kpi-val red"><?= number_format($impayeActif, 2, ',', ' ') ?> &euro;</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Créances ex-locataires</div>
      <div class="kpi-val orange"><?= number_format($creancesPartis, 2, ',', ' ') ?> &euro;</div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Taux d'encaissement</div>
      <div class="kpi-val <?= $tauxEncaissement >= 90 ? 'green' : ($tauxEncaissement >= 70 ? 'orange' : 'red') ?>"><?= $tauxEncaissement ?> %</div>
    </div>
  </div>

  <!-- KPI cards (row 2) -->
  <div class="kpi-grid">
    <div class="kpi-card">
      <div class="kpi-label">Lots occupés</div>
      <div class="kpi-val green"><?= $nbOccupes ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Lots vacants</div>
      <div class="kpi-val"><?= $nbVacants ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Partis débiteurs</div>
      <div class="kpi-val red"><?= $nbPartis ?></div>
    </div>
    <div class="kpi-card">
      <div class="kpi-label">Charges nettes</div>
      <div class="kpi-val"><?= number_format($chargesNettes, 2, ',', ' ') ?> &euro;</div>
    </div>
  </div>

  <!-- Charts row: bar + doughnut -->
  <div class="charts-row">
    <div class="chart-box">
      <h3>Encaissements vs Impayés</h3>
      <canvas id="chartBar" height="260"></canvas>
    </div>
    <div class="chart-box">
      <h3>Répartition du portefeuille</h3>
      <canvas id="chartDonut" height="260"></canvas>
    </div>
  </div>

  <!-- Line chart: taux encaissement -->
  <div class="chart-box" style="margin-bottom:24px;">
    <h3>Taux d'encaissement par trimestre</h3>
    <canvas id="chartTaux" height="180"></canvas>
  </div>

  <!-- Alertes table -->
  <div class="chart-box">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
      <h3 style="margin-bottom:0;">Situations à surveiller</h3>
      <a href="<?= app_url('/gestion/patrimoine_sir.php') ?>" style="font-size:12px;color:#d4a843;text-decoration:none;">Voir le patrimoine &rarr;</a>
    </div>
    <?php if (empty($alertes)): ?>
      <p style="color:var(--text-tertiary);font-size:13px;padding:20px 0;text-align:center;">Aucune situation à signaler.</p>
    <?php else: ?>
      <table class="alert-table">
        <thead>
          <tr>
            <th>Immeuble</th>
            <th>Lot</th>
            <th>Type</th>
            <th>Catégorie</th>
            <th>Statut</th>
            <th class="r">Loyer</th>
            <th class="r">Impayé</th>
            <th class="r">Solde ant.</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($alertes as $a): ?>
          <tr>
            <td><?= e($a['nom_immeuble'] ?? '-') ?></td>
            <td><?= e($a['numero_lot'] ?? '-') ?></td>
            <td><?= e($a['type_bien'] ?? '-') ?></td>
            <td><?= e($a['categorie_bien'] ?? '-') ?></td>
            <td><span class="badge-gest badge-<?= e($a['statut_trimestre'] ?? 'vacant') ?>"><?= e($a['statut_trimestre'] ?? '-') ?></span></td>
            <td class="mono"><?= number_format((float)$a['loyer_appele'], 2, ',', ' ') ?> &euro;</td>
            <td class="mono<?= (float)$a['total_impaye'] > 0 ? ' red' : '' ?>"><?= number_format((float)$a['total_impaye'], 2, ',', ' ') ?> &euro;</td>
            <td class="mono"><?= number_format((float)$a['solde_anterieur'], 2, ',', ' ') ?> &euro;</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

<?php endif; ?>
</main>

<script>
<?php if (!empty($prop_ids) && $lastCrg): ?>
const chartColors = {gold:'#d4a843',red:'#e85b5b',blue:'#4f8ef7',muted:'#8a8575'};
const gridColor = 'rgba(255,255,255,.04)';
const tickColor = '#8a8575';

// Bar chart — Encaissements vs Impayés
new Chart(document.getElementById('chartBar'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($histLabels, JSON_UNESCAPED_UNICODE) ?>,
    datasets: [
      {label:'Encaissements',data:<?= json_encode($histCredits) ?>,backgroundColor:'rgba(79,142,247,.6)',borderColor:chartColors.blue,borderWidth:1,borderRadius:4},
      {label:'Impayés',data:<?= json_encode($histImpayes) ?>,backgroundColor:'rgba(232,91,91,.6)',borderColor:chartColors.red,borderWidth:1,borderRadius:4}
    ]
  },
  options: {
    responsive:true,
    plugins:{legend:{labels:{color:tickColor,font:{size:11}}}},
    scales:{
      x:{ticks:{color:tickColor,font:{size:11}},grid:{display:false}},
      y:{ticks:{color:tickColor,callback:v=>v.toLocaleString('fr-FR')+' €'},grid:{color:gridColor}}
    }
  }
});

// Doughnut
new Chart(document.getElementById('chartDonut'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode($donutLabels, JSON_UNESCAPED_UNICODE) ?>,
    datasets: [{
      data: <?= json_encode($donutData) ?>,
      backgroundColor: <?= json_encode(array_slice($donutColors, 0, count($donutData))) ?>,
      borderWidth: 0
    }]
  },
  options: {
    responsive:true,
    cutout:'60%',
    plugins:{legend:{position:'bottom',labels:{color:tickColor,font:{size:11},padding:14}}}
  }
});

// Line chart — Taux encaissement
new Chart(document.getElementById('chartTaux'), {
  type: 'line',
  data: {
    labels: <?= json_encode($histLabels, JSON_UNESCAPED_UNICODE) ?>,
    datasets: [{
      label:'Taux encaissement (%)',
      data: <?= json_encode($histTaux) ?>,
      borderColor: chartColors.gold,
      backgroundColor: 'rgba(212,168,67,.12)',
      fill: true,
      tension: .3,
      pointRadius: 4,
      pointBackgroundColor: chartColors.gold
    }]
  },
  options: {
    responsive:true,
    plugins:{legend:{display:false}},
    scales:{
      x:{ticks:{color:tickColor,font:{size:11}},grid:{display:false}},
      y:{min:0,max:100,ticks:{color:tickColor,callback:v=>v+'%'},grid:{color:gridColor}}
    }
  }
});
<?php endif; ?>
</script>
</body>
</html>
