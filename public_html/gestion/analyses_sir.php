<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();

if (!function_exists('e')) {
    function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
}

/* ── Vérification accès SIR ──────────────────────────── */
$idProp = (int)($_SESSION['id_proprietaire'] ?? 0);
if ($idProp === 0) {
    header('Location: ' . app_url('/login.php')); exit;
}
$stmtCheck = $pdo->prepare("SELECT type_dashboard FROM proprietaires WHERE id = ?");
$stmtCheck->execute([$idProp]);
if ($stmtCheck->fetchColumn() !== 'groupe_sir') {
    http_response_code(403);
    exit('Accès réservé au groupe SIR.');
}

$current_page = 'analyses_sir';
$nav_context  = 'sir';

/* ── 4 derniers CRG ──────────────────────────────────── */
$stmtQ = $pdo->prepare("
    SELECT id, annee, trimestre, total_debits, total_credits, total_tva, solde_report
    FROM crg_trimestres
    WHERE id_proprietaire = ? AND parse_statut = 'ok'
    ORDER BY annee DESC, trimestre DESC
    LIMIT 4
");
$stmtQ->execute([$idProp]);
$quarters = $stmtQ->fetchAll(PDO::FETCH_ASSOC);
$quarters = array_reverse($quarters); // chronological order

$chartLabels  = [];
$chartDebits  = [];
$chartCredits = [];
foreach ($quarters as $q) {
    $chartLabels[]  = 'T' . $q['trimestre'] . ' ' . $q['annee'];
    $chartDebits[]  = round((float)$q['total_debits'], 2);
    $chartCredits[] = round((float)$q['total_credits'], 2);
}

/* ── Ventilation par catégorie (dernier CRG) ─────────── */
$categories = [];
$lastCrgId  = !empty($quarters) ? (int)end($quarters)['id'] : 0;
if ($lastCrgId > 0) {
    $stmtCat = $pdo->prepare("
        SELECT categorie, SUM(debit) AS total_debit, SUM(credit) AS total_credit
        FROM crg_ecritures
        WHERE id_crg = ?
        GROUP BY categorie
        ORDER BY total_debit DESC
    ");
    $stmtCat->execute([$lastCrgId]);
    $categories = $stmtCat->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Analyses SIR — MaBoxImmo</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/base.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/layout.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/components.css') ?>">
<link rel="stylesheet" href="<?= asset_url('/css/gestion.css') ?>">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
</head>
<body class="theme-sir">

<?php include __DIR__ . '/../sidebar_bailleur.php'; ?>
<?php include __DIR__ . '/inc/nav_gestion.php'; ?>

<main style="margin-left:260px;padding:32px 40px;">
  <h1 style="font-size:22px;font-weight:600;margin-bottom:24px;color:var(--text-primary);">Analyses — Groupe SIR</h1>

  <!-- Chart évolution débits / crédits -->
  <div style="background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:12px;padding:20px;margin-bottom:24px;">
    <h2 style="font-size:14px;font-weight:500;margin-bottom:16px;color:var(--text-primary);">Évolution trimestrielle</h2>
    <canvas id="chartEvo" height="260"></canvas>
  </div>

  <!-- Comparaison trimestres -->
  <div style="background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:12px;padding:20px;margin-bottom:24px;overflow-x:auto;">
    <h2 style="font-size:14px;font-weight:500;margin-bottom:16px;color:var(--text-primary);">Comparaison par trimestre</h2>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead>
        <tr style="border-bottom:1px solid var(--border-color);color:var(--text-tertiary);text-transform:uppercase;font-size:10px;letter-spacing:.07em;">
          <th style="text-align:left;padding:8px;">Année</th>
          <th style="text-align:center;padding:8px;">Trim.</th>
          <th style="text-align:right;padding:8px;">Débits</th>
          <th style="text-align:right;padding:8px;">Crédits</th>
          <th style="text-align:right;padding:8px;">TVA</th>
          <th style="text-align:right;padding:8px;">Solde report</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($quarters as $q): ?>
        <tr style="border-bottom:1px solid var(--border-color);">
          <td style="padding:8px;color:var(--text-primary);"><?= (int)$q['annee'] ?></td>
          <td style="padding:8px;text-align:center;color:var(--text-primary);">T<?= (int)$q['trimestre'] ?></td>
          <td style="padding:8px;text-align:right;font-family:'JetBrains Mono',monospace;color:var(--color-danger);"><?= number_format((float)$q['total_debits'], 2, ',', ' ') ?> &euro;</td>
          <td style="padding:8px;text-align:right;font-family:'JetBrains Mono',monospace;color:var(--color-success);"><?= number_format((float)$q['total_credits'], 2, ',', ' ') ?> &euro;</td>
          <td style="padding:8px;text-align:right;font-family:'JetBrains Mono',monospace;"><?= number_format((float)$q['total_tva'], 2, ',', ' ') ?> &euro;</td>
          <td style="padding:8px;text-align:right;font-family:'JetBrains Mono',monospace;"><?= number_format((float)$q['solde_report'], 2, ',', ' ') ?> &euro;</td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($quarters)): ?>
        <tr><td colspan="6" style="padding:20px;text-align:center;color:var(--text-tertiary);">Aucun CRG disponible.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Ventilation par catégorie -->
  <div style="background:var(--bg-secondary);border:1px solid var(--border-color);border-radius:12px;padding:20px;overflow-x:auto;">
    <h2 style="font-size:14px;font-weight:500;margin-bottom:16px;color:var(--text-primary);">Ventilation par catégorie (dernier CRG)</h2>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      <thead>
        <tr style="border-bottom:1px solid var(--border-color);color:var(--text-tertiary);text-transform:uppercase;font-size:10px;letter-spacing:.07em;">
          <th style="text-align:left;padding:8px;">Catégorie</th>
          <th style="text-align:right;padding:8px;">Total débits</th>
          <th style="text-align:right;padding:8px;">Total crédits</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($categories as $c): ?>
        <tr style="border-bottom:1px solid var(--border-color);">
          <td style="padding:8px;color:var(--text-primary);"><?= e($c['categorie']) ?></td>
          <td style="padding:8px;text-align:right;font-family:'JetBrains Mono',monospace;color:var(--color-danger);"><?= number_format((float)$c['total_debit'], 2, ',', ' ') ?> &euro;</td>
          <td style="padding:8px;text-align:right;font-family:'JetBrains Mono',monospace;color:var(--color-success);"><?= number_format((float)$c['total_credit'], 2, ',', ' ') ?> &euro;</td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($categories)): ?>
        <tr><td colspan="3" style="padding:20px;text-align:center;color:var(--text-tertiary);">Aucune écriture disponible.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</main>

<script>
const ctx = document.getElementById('chartEvo').getContext('2d');
new Chart(ctx, {
  type: 'line',
  data: {
    labels: <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>,
    datasets: [
      {
        label: 'Débits (€)',
        data: <?= json_encode($chartDebits) ?>,
        borderColor: '#e85b5b',
        backgroundColor: 'rgba(232,91,91,.1)',
        tension: .3,
        fill: true,
        pointRadius: 5,
        pointBackgroundColor: '#e85b5b'
      },
      {
        label: 'Crédits (€)',
        data: <?= json_encode($chartCredits) ?>,
        borderColor: '#5cb85c',
        backgroundColor: 'rgba(92,184,92,.1)',
        tension: .3,
        fill: true,
        pointRadius: 5,
        pointBackgroundColor: '#5cb85c'
      }
    ]
  },
  options: {
    responsive: true,
    interaction: { mode: 'index', intersect: false },
    plugins: {
      legend: { labels: { color: '#8a8070', font: { size: 12 } } },
      tooltip: { callbacks: { label: c => c.dataset.label + ': ' + c.parsed.y.toLocaleString('fr-FR',{minimumFractionDigits:2}) + ' €' } }
    },
    scales: {
      x: { ticks: { color: '#8a8070' }, grid: { display: false } },
      y: { ticks: { color: '#8a8070', callback: v => v.toLocaleString('fr-FR') + ' €' }, grid: { color: 'rgba(255,255,255,.04)' } }
    }
  }
});
</script>
</body>
</html>
