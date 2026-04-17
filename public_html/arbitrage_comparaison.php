<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/arbitrage_access.php';
require_once __DIR__ . '/inc/arbitrage_scope.php';
require_once __DIR__ . '/inc/arbitrage_calc.php';
require_once __DIR__ . '/inc/arbitrage_crg.php';

require_arbitrage_access();

$pdo = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);

[$where, $params] = arb_scope_biens_where();

$idsRaw = trim((string)($_GET['ids'] ?? ''));
$ids = [];
if ($idsRaw !== '') {
    foreach (preg_split('/[,\s]+/', $idsRaw) as $p) {
        $p = trim($p);
        if ($p !== '' && ctype_digit($p)) $ids[] = (int)$p;
    }
}
$ids = array_values(array_unique(array_filter($ids, fn($v) => $v > 0)));
$ids = array_slice($ids, 0, 12);

if (empty($ids)) {
    header('Location: ' . app_url('/arbitrage_biens.php'));
    exit;
}

// Settings
$settings = [
    'objectif_tresorerie' => 15000000.0,
    'horizon_mois' => 18,
    'rendement_reinvest_cible_pct' => 6.0,
];
try {
    $stmtS = $pdo->prepare("SELECT objectif_tresorerie, horizon_mois, rendement_reinvest_cible_pct FROM arbitrage_settings WHERE id_societe = ? LIMIT 1");
    $stmtS->execute([$societeId]);
    $rowS = $stmtS->fetch(PDO::FETCH_ASSOC);
    if ($rowS) {
        $settings['objectif_tresorerie'] = (float)($rowS['objectif_tresorerie'] ?? $settings['objectif_tresorerie']);
        $settings['horizon_mois'] = (int)($rowS['horizon_mois'] ?? $settings['horizon_mois']);
        $settings['rendement_reinvest_cible_pct'] = (float)($rowS['rendement_reinvest_cible_pct'] ?? $settings['rendement_reinvest_cible_pct']);
    }
} catch (Throwable $e) {}

$ph = implode(',', array_fill(0, count($ids), '?'));

// Charger biens (scope + ids)
$sql = "
    SELECT
        b.id, b.reference_bien, b.designation, b.ville, b.adresse_1, b.surface_habitable,
        b.statut_occupation, b.prix_vente_estime, b.loyer_hc,
        tb.libelle AS type_libelle,
        a.decision, a.posture, a.statut_locatif,
        a.vacance_debut, a.vacance_mois,
        a.loyer_actuel_mensuel, a.loyer_potentiel_mensuel,
        a.taxe_fonciere, a.charges_non_recup, a.assurance, a.entretien, a.frais_gestion, a.autres_couts_annuels,
        a.travaux_niveau, a.travaux_tags, a.travaux_1an, a.travaux_3ans, a.travaux_5ans,
        a.prix_estime, a.prix_vente_realiste, a.frais_agence, a.frais_notaire, a.cout_acte_en_main,
        a.delai_vente_mois, a.liquidite_niveau, a.risque_niveau
    FROM biens b
    LEFT JOIN types_bien tb ON tb.id = b.id_type_bien
    LEFT JOIN arbitrage_biens a ON a.id_bien = b.id AND a.id_societe = ?
    WHERE ($where) AND b.id IN ($ph)
";

$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$societeId], $params, $ids));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Ordre identique à la sélection
$byId = [];
foreach ($rows as $r) $byId[(int)$r['id']] = $r;
$selected = [];
foreach ($ids as $id) {
    if (isset($byId[$id])) $selected[] = $byId[$id];
}

$isDuel = count($selected) === 2;

$items = [];
foreach ($selected as $r) {
    $arb = $r;
    $crg = [];
    // Duel : on charge CRG (plus parlant)
    if ($isDuel) {
        $snap = arb_crg_latest_snapshot($pdo, (int)$r['id']);
        $crg = $snap['latest'] ?? [];
    }
    $metrics = arb_compute_metrics($r, $arb, $crg, $settings);
    $items[] = [
        'row' => $r,
        'metrics' => $metrics,
    ];
}

// Synthèse comparative (v1)
usort($items, fn($a, $b) => (int)$b['metrics']['score_strategique'] <=> (int)$a['metrics']['score_strategique']);
$keep = $items[0] ?? null;
$sell = $items[count($items) - 1] ?? null;

$synthCompare = '';
if ($isDuel && $keep && $sell) {
    $k = $keep['row'];
    $s = $sell['row'];
    $kScore = (int)$keep['metrics']['score_strategique'];
    $sScore = (int)$sell['metrics']['score_strategique'];
    $kCash = (float)($keep['metrics']['projection_18m']['vendre']['cash'] ?? 0);
    $sCash = (float)($sell['metrics']['projection_18m']['vendre']['cash'] ?? 0);
    $synthCompare =
        "Synthèse duel\n"
        . "- Priorité conservation : " . trim((string)($k['designation'] ?? ('Bien #' . (int)$k['id']))) . " (score {$kScore}/100).\n"
        . "- Priorité arbitrage/vente : " . trim((string)($s['designation'] ?? ('Bien #' . (int)$s['id']))) . " (score {$sScore}/100).\n"
        . "- Contribution cash : " . number_format($sCash, 0, ',', ' ') . " € vs " . number_format($kCash, 0, ',', ' ') . " € (ordre de grandeur).\n"
        . "- Lecture : arbitrer le moins stratégique ou celui qui libère le cash le plus utile au plan 15 M€ / 18 mois.\n";
}

?><!doctype html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Arbitrage — Comparaison</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('/css/base.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('/css/components.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('/css/arbitrage.css') ?>">
</head>
<body>
  <div class="arb-shell" id="arb-compare" data-page="compare" data-duel="<?= $isDuel ? '1' : '0' ?>">
    <header class="arb-topbar">
      <div class="arb-topbar-left">
        <a class="arb-back" href="<?= app_url('/arbitrage_biens.php') ?>">← Biens</a>
        <div class="arb-title">Comparaison</div>
        <div class="arb-subtitle"><?= $isDuel ? 'Duel 2 biens' : ('Comparaison ' . count($items) . ' biens') ?></div>
      </div>
      <div class="arb-topbar-actions">
        <button class="arb-btn" type="button" data-mode-toggle>Mode client</button>
        <button class="arb-btn arb-btn-primary" type="button" data-print>Imprimer</button>
      </div>
    </header>

    <main class="arb-compare">
      <section class="arb-card">
        <div class="arb-card-title">Table comparative</div>
        <div class="arb-table-wrap">
          <table class="arb-table">
            <thead>
              <tr>
                <th>Bien</th>
                <th>Ville</th>
                <th class="arb-num">Prix</th>
                <th class="arb-num">Loyer/mois</th>
                <th class="arb-num">Coût/an</th>
                <th class="arb-num">Rdt réel</th>
                <th class="arb-num">Vacance</th>
                <th class="arb-num">Travaux</th>
                <th class="arb-num">Liquidité</th>
                <th class="arb-num">Risque</th>
                <th class="arb-num">Cash (vente)</th>
                <th class="arb-num">Score</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $it):
                  $r = $it['row'];
                  $m = $it['metrics'];
                  $id = (int)$r['id'];
                  $cash = (float)($m['projection_18m']['vendre']['cash'] ?? 0);
              ?>
                <tr class="arb-row"
                    data-bien-id="<?= $id ?>"
                    data-cash="<?= (float)$cash ?>"
                    data-score="<?= (int)$m['score_strategique'] ?>"
                    data-rendement="<?= (float)$m['rendement_reel_pct'] ?>"
                    data-cout="<?= (float)$m['cout_annuel'] ?>"
                    data-liq="<?= (int)$m['liquidite_niveau'] ?>"
                    data-risk="<?= (int)$m['risque_niveau'] ?>"
                    data-travaux="<?= (float)$m['travaux_total'] ?>"
                    data-prix="<?= (float)$m['prix_vente_realiste'] ?>">
                  <td><a class="arb-link" href="<?= app_url('/arbitrage_bien_detail.php?id=' . $id) ?>"><?= h(trim((string)($r['designation'] ?? ('Bien #' . $id)))) ?></a></td>
                  <td><?= h(trim((string)($r['ville'] ?? ''))) ?></td>
                  <td class="arb-num"><?= $m['prix_vente_realiste'] > 0 ? number_format((float)$m['prix_vente_realiste'], 0, ',', ' ') . ' €' : '—' ?></td>
                  <td class="arb-num"><?= $m['loyer_actuel_mensuel'] > 0 ? number_format((float)$m['loyer_actuel_mensuel'], 0, ',', ' ') . ' €' : '—' ?></td>
                  <td class="arb-num"><?= $m['cout_annuel'] > 0 ? number_format((float)$m['cout_annuel'], 0, ',', ' ') . ' €' : '—' ?></td>
                  <td class="arb-num"><?= number_format((float)$m['rendement_reel_pct'], 2, ',', ' ') ?>%</td>
                  <td class="arb-num"><?= (int)$m['vacance_mois'] > 0 ? ((int)$m['vacance_mois'] . ' m') : '—' ?></td>
                  <td class="arb-num"><?= $m['travaux_total'] > 0 ? number_format((float)$m['travaux_total'], 0, ',', ' ') . ' €' : '—' ?></td>
                  <td class="arb-num"><?= (int)$m['liquidite_niveau'] ?>/5</td>
                  <td class="arb-num"><?= (int)$m['risque_niveau'] ?>/5</td>
                  <td class="arb-num"><?= $cash > 0 ? number_format($cash, 0, ',', ' ') . ' €' : '—' ?></td>
                  <td class="arb-num"><span class="arb-score" data-score="<?= (int)$m['score_strategique'] ?>"><?= (int)$m['score_strategique'] ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <?php if ($isDuel): ?>
      <section class="arb-card">
        <div class="arb-card-title">Mode duel (synthèse)</div>
        <pre class="arb-pre" data-duel-synth><?= h($synthCompare) ?></pre>
        <div class="arb-charts">
          <div class="arb-chart" data-chart="duel-bars">
            <div class="arb-chart-title">Comparatif immédiat</div>
            <svg class="arb-svg" viewBox="0 0 360 140"></svg>
          </div>
          <div class="arb-chart" data-chart="duel-radar">
            <div class="arb-chart-title">Radar superposé</div>
            <svg class="arb-svg" viewBox="0 0 360 180"></svg>
          </div>
        </div>
      </section>
      <?php endif; ?>
    </main>
  </div>

  <script src="<?= asset_url('/js/arbitrage/utils.js') ?>"></script>
  <script src="<?= asset_url('/js/arbitrage/charts.js') ?>"></script>
  <script src="<?= asset_url('/js/arbitrage/comparaison.js') ?>"></script>
</body>
</html>
