<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/arbitrage_access.php';
require_once __DIR__ . '/inc/arbitrage_scope.php';
require_once __DIR__ . '/inc/arbitrage_calc.php';

require_arbitrage_access();

$pdo = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
[$where, $params] = arb_scope_biens_where();

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

// Charger biens (rapide)
$sql = "
    SELECT
        b.id, b.designation, b.ville, b.adresse_1, b.prix_vente_estime, b.loyer_hc, b.statut_occupation,
        COALESCE(bt.libelle, tb2.libelle) AS type_libelle,
        a.decision, a.posture, a.statut_locatif,
        a.vacance_debut, a.vacance_mois,
        a.loyer_actuel_mensuel, a.loyer_potentiel_mensuel,
        a.taxe_fonciere, a.charges_non_recup, a.assurance, a.entretien, a.frais_gestion, a.autres_couts_annuels,
        a.travaux_niveau, a.travaux_1an, a.travaux_3ans, a.travaux_5ans,
        a.prix_estime, a.prix_vente_realiste, a.frais_agence, a.frais_notaire, a.cout_acte_en_main,
        a.delai_vente_mois, a.liquidite_niveau, a.risque_niveau
    FROM biens b
    LEFT JOIN bien_types bt ON bt.id = b.id_bien_type
    LEFT JOIN types_bien tb2 ON tb2.id = b.id_type_bien
    LEFT JOIN arbitrage_biens a ON a.id_bien = b.id AND a.id_societe = ?
    WHERE $where
    ORDER BY b.ville ASC, b.designation ASC
    LIMIT 800
";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$societeId], $params));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$items = [];
$totalCashSell = 0.0;
$totalCashSelected = 0.0;
$sellIds = [];

foreach ($rows as $r) {
    $arb = $r;
    $metrics = arb_compute_metrics($r, $arb, [], $settings);
    $cash = (float)($metrics['projection_18m']['vendre']['cash'] ?? 0.0);
    $decision = (string)($r['decision'] ?? '');
    if ($decision === 'vendre') {
        $totalCashSell += $cash;
        $sellIds[] = (int)$r['id'];
    }
    $items[] = ['row' => $r, 'metrics' => $metrics, 'cash' => $cash];
}

$csrf = csrf_token('arbitrage');
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= h($csrf) ?>">
  <title>Arbitrage — Plan trésorerie</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('/css/base.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('/css/components.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('/css/arbitrage.css') ?>">
</head>
<body>
  <div class="arb-shell" id="arb-plan"
       data-page="plan"
       data-settings-save-url="<?= h(app_url('/api/arbitrage_settings_save.php')) ?>">

    <header class="arb-topbar">
      <div class="arb-topbar-left">
        <a class="arb-back" href="<?= app_url('/arbitrage_biens.php') ?>">← Biens</a>
        <div class="arb-title">Plan trésorerie</div>
        <div class="arb-subtitle">Objectif groupe : 15 M€ / 18 mois</div>
      </div>
      <div class="arb-topbar-actions">
        <button class="arb-btn" type="button" data-mode-toggle>Mode client</button>
        <button class="arb-btn arb-btn-primary" type="button" data-print>Imprimer</button>
      </div>
    </header>

    <main class="arb-plan">
      <section class="arb-card arb-plan-head">
        <div class="arb-card-title">Objectif & scénarios</div>
        <div class="arb-plan-grid">
          <div class="arb-plan-settings">
            <div class="arb-grid-3">
              <label class="arb-field">
                <span>Objectif trésorerie (€)</span>
                <input type="number" min="0" step="1000" value="<?= (float)$settings['objectif_tresorerie'] ?>" data-setting="objectif_tresorerie">
              </label>
              <label class="arb-field">
                <span>Horizon (mois)</span>
                <input type="number" min="6" max="60" step="1" value="<?= (int)$settings['horizon_mois'] ?>" data-setting="horizon_mois">
              </label>
              <label class="arb-field">
                <span>Rdt réinvest cible (%)</span>
                <input type="number" min="0" max="25" step="0.25" value="<?= (float)$settings['rendement_reinvest_cible_pct'] ?>" data-setting="rendement_reinvest_cible_pct">
              </label>
            </div>
            <div class="arb-row">
              <button class="arb-btn arb-btn-primary" type="button" data-save-settings>Enregistrer</button>
              <div class="arb-save" data-save-state>—</div>
            </div>
          </div>

          <div class="arb-plan-progress">
            <div class="arb-progress-title">Progression vers l’objectif</div>
            <div class="arb-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?= (float)$settings['objectif_tresorerie'] ?>" aria-valuenow="<?= (float)$totalCashSell ?>">
              <div class="arb-progress-fill" data-progress-fill></div>
            </div>
            <div class="arb-progress-kpis">
              <div class="arb-mini">
                <div class="arb-mini-label">Cash (sélection)</div>
                <div class="arb-mini-value" data-kpi="cash_sell"><?= number_format($totalCashSell, 0, ',', ' ') ?> €</div>
              </div>
              <div class="arb-mini">
                <div class="arb-mini-label">Objectif</div>
                <div class="arb-mini-value" data-kpi="objectif"><?= number_format((float)$settings['objectif_tresorerie'], 0, ',', ' ') ?> €</div>
              </div>
              <div class="arb-mini">
                <div class="arb-mini-label">Taux</div>
                <div class="arb-mini-value" data-kpi="taux">—</div>
              </div>
            </div>
            <div class="arb-muted">Sélectionnez des biens dans la table pour simuler un plan de cession.</div>
          </div>
        </div>
      </section>

      <section class="arb-card">
        <div class="arb-card-title">Portefeuille — contribution cash</div>
        <div class="arb-table-wrap">
          <table class="arb-table">
            <thead>
              <tr>
                <th class="arb-col-sel">Sel.</th>
                <th>Bien</th>
                <th>Ville</th>
                <th class="arb-num">Cash (vente)</th>
                <th class="arb-num">Rdt réel</th>
                <th class="arb-num">Coût/an</th>
                <th>Décision</th>
                <th class="arb-num">Score</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $it):
                  $r = $it['row'];
                  $m = $it['metrics'];
                  $id = (int)$r['id'];
                  $cash = (float)$it['cash'];
                  $decision = (string)($r['decision'] ?? '');
                  $score = (int)($m['score_strategique'] ?? 0);
              ?>
                <tr class="arb-row" data-bien-id="<?= $id ?>" data-cash="<?= (float)$cash ?>">
                  <td class="arb-col-sel">
                    <input type="checkbox" class="arb-check" data-select <?= $decision === 'vendre' ? 'checked' : '' ?>>
                  </td>
                  <td><a class="arb-link" href="<?= app_url('/arbitrage_bien_detail.php?id=' . $id) ?>"><?= h(trim((string)($r['designation'] ?? ('Bien #' . $id)))) ?></a><div class="arb-muted"><?= h(trim((string)($r['adresse_1'] ?? ''))) ?></div></td>
                  <td><?= h(trim((string)($r['ville'] ?? ''))) ?></td>
                  <td class="arb-num"><?= $cash > 0 ? number_format($cash, 0, ',', ' ') . ' €' : '—' ?></td>
                  <td class="arb-num"><?= number_format((float)$m['rendement_reel_pct'], 2, ',', ' ') ?>%</td>
                  <td class="arb-num"><?= $m['cout_annuel'] > 0 ? number_format((float)$m['cout_annuel'], 0, ',', ' ') . ' €' : '—' ?></td>
                  <td><span class="arb-pill arb-pill-reco"><?= h($decision ?: '—') ?></span></td>
                  <td class="arb-num"><span class="arb-score" data-score="<?= $score ?>"><?= $score ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="arb-card">
        <div class="arb-card-title">Évolution (simulation)</div>
        <div class="arb-charts">
          <div class="arb-chart" data-chart="treso">
            <div class="arb-chart-title">Progression mois par mois</div>
            <svg class="arb-svg" viewBox="0 0 720 160"></svg>
          </div>
        </div>
      </section>
    </main>
  </div>

  <script src="<?= asset_url('/js/arbitrage/utils.js') ?>"></script>
  <script src="<?= asset_url('/js/arbitrage/charts.js') ?>"></script>
  <script src="<?= asset_url('/js/arbitrage/plan.js') ?>"></script>
</body>
</html>
