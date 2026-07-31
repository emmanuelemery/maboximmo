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

// Settings (display)
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

// Biens + arbitrage join (liste rapide, sans CRG lourd)
$sql = "
    SELECT
        b.id, b.reference_bien, b.designation, b.adresse_1, b.ville, b.code_postal,
        b.surface_habitable, b.numero_lot, b.statut_occupation, b.prix_vente_estime, b.loyer_hc,
        COALESCE(bt.libelle, tb2.libelle) AS type_libelle,
        (SELECT ba.locataire_nom FROM baux ba WHERE ba.id_bien = b.id AND ba.statut = 'actif' ORDER BY ba.id DESC LIMIT 1) AS locataire_nom,
        a.decision, a.posture, a.statut_locatif,
        a.vacance_debut, a.vacance_mois,
        a.loyer_actuel_mensuel, a.loyer_potentiel_mensuel,
        a.taxe_fonciere, a.charges_non_recup, a.assurance, a.entretien, a.frais_gestion, a.autres_couts_annuels,
        a.travaux_niveau, a.travaux_1an, a.travaux_3ans, a.travaux_5ans,
        a.prix_estime, a.prix_vente_realiste, a.frais_agence, a.frais_notaire, a.cout_acte_en_main,
        a.delai_vente_mois, a.liquidite_niveau, a.risque_niveau
    FROM biens b
    LEFT JOIN bien_types bt  ON bt.id  = b.id_bien_type
    LEFT JOIN types_bien tb2 ON tb2.id = b.id_type_bien
    LEFT JOIN arbitrage_biens a ON a.id_bien = b.id AND a.id_societe = ?
    WHERE $where
    ORDER BY b.ville ASC, b.designation ASC, b.id DESC
    LIMIT 800
";

$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([$societeId], $params));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Pré-calcul métriques list (sans CRG)
$biens = [];
foreach ($rows as $r) {
    $arb = $r; // colonnes arbitrage déjà dans le row (préfixées a.)
    $crg = [];
    $metrics = arb_compute_metrics($r, $arb, $crg, $settings);

    $decision = (string)($r['decision'] ?? '');
    $recommendation = $decision !== '' ? $decision : (
        ($metrics['vacance_mois'] >= 9 || $metrics['score_strategique'] < 35) ? 'arbitrer' : 'conserver'
    );

    $statut = (string)($r['statut_locatif'] ?? '');
    if ($statut === '') {
        $statutOcc = (string)($r['statut_occupation'] ?? '');
        $statut = match ($statutOcc) {
            'vacant' => 'vide',
            'occupe', 'occupé' => 'loue',
            default => '',
        };
    }

    $biens[] = [
        'row' => $r,
        'metrics' => $metrics,
        'recommendation' => $recommendation,
        'statut_locatif' => $statut,
    ];
}

$csrf = csrf_token('arbitrage');
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= h($csrf) ?>">
  <title>Arbitrage patrimonial — Biens</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('/css/base.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('/css/components.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('/css/arbitrage.css') ?>">
</head>
<body>
  <div class="arb-shell" id="arb-biens"
       data-objectif="<?= (float)$settings['objectif_tresorerie'] ?>"
       data-horizon="<?= (int)$settings['horizon_mois'] ?>"
       data-page="biens">

    <header class="arb-topbar">
      <div class="arb-topbar-left">
        <div class="arb-title">Arbitrage patrimonial</div>
        <div class="arb-subtitle">Décision, argumentaire et présentation client</div>
      </div>
      <div class="arb-topbar-actions">
        <a class="arb-btn" href="<?= app_url('/plan_tresorerie.php') ?>">Plan trésorerie</a>
        <button class="arb-btn" type="button" data-mode-toggle>Mode client</button>
        <button class="arb-btn arb-btn-primary" type="button" data-compare-selected disabled>Comparer sélection</button>
      </div>
    </header>

    <section class="arb-kpis">
      <div class="arb-kpi">
        <div class="arb-kpi-label">Objectif trésorerie</div>
        <div class="arb-kpi-value" data-kpi-objectif><?= number_format((float)$settings['objectif_tresorerie'], 0, ',', ' ') ?> €</div>
      </div>
      <div class="arb-kpi">
        <div class="arb-kpi-label">Horizon</div>
        <div class="arb-kpi-value"><?= (int)$settings['horizon_mois'] ?> mois</div>
      </div>
      <div class="arb-kpi">
        <div class="arb-kpi-label">Biens</div>
        <div class="arb-kpi-value"><?= count($biens) ?></div>
      </div>
      <div class="arb-kpi">
        <div class="arb-kpi-label">Cash mobilisable (sel.)</div>
        <div class="arb-kpi-value" data-kpi-cash>0 €</div>
      </div>
    </section>

    <section class="arb-filters" aria-label="Filtres rapides">
      <button class="arb-chip" type="button" data-filter="vide">Biens vides</button>
      <button class="arb-chip" type="button" data-filter="loue">Biens loués</button>
      <button class="arb-chip" type="button" data-filter="partiel">Partiellement loués</button>
      <button class="arb-chip" type="button" data-filter="travaux">Avec travaux</button>
      <button class="arb-chip" type="button" data-filter="rentable">Très rentables</button>
      <button class="arb-chip" type="button" data-filter="coute">Qui coûtent</button>
      <button class="arb-chip" type="button" data-filter="vendre">À vendre</button>
      <button class="arb-chip" type="button" data-filter="conserver">À conserver</button>
      <button class="arb-chip" type="button" data-filter="vacance_longue">Vacance longue</button>
      <button class="arb-chip" type="button" data-filter="liq_forte">Forte liquidité</button>
      <button class="arb-chip" type="button" data-filter="liq_faible">Faible liquidité</button>
      <button class="arb-chip arb-chip-muted" type="button" data-filter-clear>Réinitialiser</button>
    </section>

    <main class="arb-main">
      <div class="arb-table-wrap">
        <table class="arb-table" aria-label="Liste des biens">
          <thead>
            <tr>
              <th class="arb-col-sel">Sel.</th>
              <th>Bien</th>
              <th>Adresse</th>
              <th>Ville</th>
              <th>Locataire</th>
              <th>Type</th>
              <th class="arb-num">Prix</th>
              <th class="arb-num">Vente réaliste</th>
              <th class="arb-num">Loyer/an</th>
              <th>Statut</th>
              <th class="arb-num">Vacance</th>
              <th class="arb-num">Coût/an</th>
              <th class="arb-num">Rdt réel</th>
              <th class="arb-num">Score</th>
              <th>Reco</th>
              <th>Posture</th>
              <th>Travaux</th>
              <th>Risque</th>
              <th>Liquidité</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($biens as $b):
              $r = $b['row'];
              $m = $b['metrics'];
              $id = (int)$r['id'];
              $designation = trim((string)($r['designation'] ?? $r['reference_bien'] ?? ''));
              $adresse = trim((string)($r['adresse_1'] ?? ''));
              $cp = trim((string)($r['code_postal'] ?? ''));
              $ville = trim((string)($r['ville'] ?? ''));
              $type = trim((string)($r['type_libelle'] ?? ''));
              $locataire = trim((string)($r['locataire_nom'] ?? ''));
              $statut = (string)($b['statut_locatif'] ?? '');
              $posture = (string)($r['posture'] ?? 'neutre');
              $trav = (string)($r['travaux_niveau'] ?? 'aucun');
              $risk = (int)($m['risque_niveau'] ?? 3);
              $liq  = (int)($m['liquidite_niveau'] ?? 3);
              $decision = (string)($r['decision'] ?? '');
              $reco = (string)($b['recommendation'] ?? '');
              $prixEstime = (float)($r['prix_estime'] ?? $r['prix_vente_estime'] ?? 0);
              $prixReal = (float)($m['prix_vente_realiste'] ?? 0);
              $loyerAn = (float)($m['loyer_actuel_mensuel'] ?? 0) * 12.0;
              $vacMois = (int)($m['vacance_mois'] ?? 0);
              $coutAn = (float)($m['cout_annuel'] ?? 0);
              $rdt = (float)($m['rendement_reel_pct'] ?? 0);
              $score = (int)($m['score_strategique'] ?? 0);

              $rowFilters = [];
              if ($statut !== '') $rowFilters[] = $statut;
              if ($trav !== 'aucun') $rowFilters[] = 'travaux';
              if ($rdt >= 6.0) $rowFilters[] = 'rentable';
              if ($coutAn >= 6000) $rowFilters[] = 'coute';
              if ($vacMois >= 9) $rowFilters[] = 'vacance_longue';
              if ($reco !== '') $rowFilters[] = $reco;
              if ($liq >= 4) $rowFilters[] = 'liq_forte';
              if ($liq <= 2) $rowFilters[] = 'liq_faible';
              $filterStr = implode(' ', array_unique($rowFilters));
          ?>
            <tr class="arb-row"
                data-bien-id="<?= $id ?>"
                data-filters="<?= h($filterStr) ?>"
                data-cash="<?= (float)($m['projection_18m']['vendre']['cash'] ?? 0) ?>">
              <td class="arb-col-sel">
                <input type="checkbox" class="arb-check" aria-label="Sélectionner" data-select>
              </td>
              <td>
                <a class="arb-link" href="<?= app_url('/arbitrage_bien_detail.php?id=' . $id) ?>">
                  <?= h($designation !== '' ? $designation : ('Bien #' . $id)) ?>
                </a>
              </td>
              <td>
                <?= $adresse !== '' ? h($adresse) : '—' ?>
                <?php if ($cp !== ''): ?><div class="arb-muted"><?= h($cp) ?></div><?php endif; ?>
              </td>
              <td><?= h($ville) ?></td>
              <td><?= $locataire !== '' ? h($locataire) : '—' ?></td>
              <td><?= h($type) ?></td>
              <td class="arb-num"><?= $prixEstime > 0 ? number_format($prixEstime, 0, ',', ' ') . ' €' : '—' ?></td>
              <td class="arb-num"><?= $prixReal > 0 ? number_format($prixReal, 0, ',', ' ') . ' €' : '—' ?></td>
              <td class="arb-num"><?= $loyerAn > 0 ? number_format($loyerAn, 0, ',', ' ') . ' €' : '—' ?></td>
              <td>
                <span class="arb-pill arb-pill-<?= h($statut ?: 'muted') ?>"><?= h($statut ?: '—') ?></span>
              </td>
              <td class="arb-num"><?= $vacMois > 0 ? $vacMois . ' m' : '—' ?></td>
              <td class="arb-num"><?= $coutAn > 0 ? number_format($coutAn, 0, ',', ' ') . ' €' : '—' ?></td>
              <td class="arb-num"><?= number_format($rdt, 2, ',', ' ') ?>%</td>
              <td class="arb-num"><span class="arb-score" data-score="<?= $score ?>"><?= $score ?></span></td>
              <td>
                <span class="arb-pill arb-pill-reco"><?= h($reco ?: ($decision ?: '—')) ?></span>
              </td>
              <td><span class="arb-pill arb-pill-muted"><?= h($posture) ?></span></td>
              <td><span class="arb-pill arb-pill-muted"><?= h($trav) ?></span></td>
              <td class="arb-num"><span class="arb-badge-risk" data-risk="<?= $risk ?>"><?= $risk ?>/5</span></td>
              <td class="arb-num"><span class="arb-badge-liq" data-liq="<?= $liq ?>"><?= $liq ?>/5</span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </main>
  </div>

  <script src="<?= asset_url('/js/arbitrage/utils.js') ?>"></script>
  <script src="<?= asset_url('/js/arbitrage/biens.js') ?>"></script>
</body>
</html>
