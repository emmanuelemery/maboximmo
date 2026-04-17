<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/arbitrage_access.php';
require_once __DIR__ . '/inc/arbitrage_calc.php';
require_once __DIR__ . '/inc/arbitrage_crg.php';
require_once __DIR__ . '/inc/arbitrage_text.php';

require_arbitrage_access();

$pdo = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$userId = (int)current_user_id();
$roleId = (int)current_role_id();
$isAdmin = in_array($roleId, [1, 7], true) || !empty($_SESSION['super_admin']);

$bienId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($bienId <= 0) {
    http_response_code(400);
    exit('ID bien requis.');
}

$stmtBien = $pdo->prepare("
    SELECT
        b.*,
        tb.code AS type_code, tb.libelle AS type_libelle,
        p.societe AS proprietaire_societe, p.nom AS proprietaire_nom, p.prenom AS proprietaire_prenom,
        (SELECT ba.locataire_nom FROM baux ba WHERE ba.id_bien = b.id AND ba.statut = 'actif' ORDER BY ba.id DESC LIMIT 1) AS locataire_nom,
        (SELECT ba.date_debut    FROM baux ba WHERE ba.id_bien = b.id AND ba.statut = 'actif' ORDER BY ba.id DESC LIMIT 1) AS bail_debut,
        (SELECT ba.date_fin      FROM baux ba WHERE ba.id_bien = b.id AND ba.statut = 'actif' ORDER BY ba.id DESC LIMIT 1) AS bail_fin
    FROM biens b
    LEFT JOIN types_bien tb ON tb.id = b.id_type_bien
    LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
    WHERE b.id = ?
    LIMIT 1
");
$stmtBien->execute([$bienId]);
$bien = $stmtBien->fetch(PDO::FETCH_ASSOC);
if (!$bien) {
    http_response_code(404);
    exit('Bien introuvable.');
}

// Contrôle d'accès (idem API)
if (!$isAdmin) {
    $bienSocieteId = (int)($bien['id_societe'] ?? 0);
    if ($societeId > 0 && $bienSocieteId === $societeId) {
        // ok
    } else {
        $code = strtoupper((string)($_SESSION['code_acces'] ?? ''));
        if ($code === 'SIR') {
            $propId = (int)($bien['id_proprietaire'] ?? 0);
            $allowed = in_array($propId, get_sir_proprietaire_ids($userId), true);
            if (!$allowed) {
                http_response_code(403);
                exit('Accès refusé.');
            }
        } else {
            http_response_code(403);
            exit('Accès refusé.');
        }
    }
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

// Charger arbitrage_biens (créer si absent)
$arb = [];
try {
    $stmtA = $pdo->prepare("SELECT * FROM arbitrage_biens WHERE id_societe = ? AND id_bien = ? LIMIT 1");
    $stmtA->execute([$societeId, $bienId]);
    $arb = $stmtA->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $arb = [];
}
if (empty($arb)) {
    try {
        $pdo->prepare("INSERT INTO arbitrage_biens (id_societe, id_bien, posture, travaux_niveau, argumentaire_source, created_at, updated_at, updated_by) VALUES (?,?, 'neutre','aucun','template', NOW(), NOW(), ?)")
            ->execute([$societeId, $bienId, $userId]);
        $arb = [
            'id_societe' => $societeId,
            'id_bien' => $bienId,
            'posture' => 'neutre',
            'travaux_niveau' => 'aucun',
        ];
    } catch (Throwable $e) {
        // migration absente : la page reste lisible mais l'autosave échouera
        $arb = [
            'id_societe' => $societeId,
            'id_bien' => $bienId,
            'posture' => 'neutre',
            'travaux_niveau' => 'aucun',
        ];
    }
}

// CRG snapshot + métriques
$crgSnap = arb_crg_latest_snapshot($pdo, $bienId);
$metrics = arb_compute_metrics($bien, $arb, $crgSnap['latest'] ?? [], $settings);

// Textes init (template)
$synthese = arb_build_synthese_template($bien, $arb, $crgSnap, $metrics, $settings);
$argTpl   = arb_build_argumentaire_template($bien, $arb, $crgSnap, $metrics, $settings);
$finalTxt = arb_merge_argumentaire_final($synthese, $crgSnap, (string)($arb['commentaire_emery'] ?? ''), (string)($arb['conclusion_emery'] ?? ''), $argTpl);

function v(?string $v): string { return $v === null ? '' : $v; }

$csrf = csrf_token('arbitrage');
$designation = trim((string)($bien['designation'] ?? $bien['reference_bien'] ?? 'Bien'));
$ville = trim((string)($bien['ville'] ?? ''));
$adresse = trim((string)($bien['adresse_1'] ?? ''));
$type = trim((string)($bien['type_libelle'] ?? $bien['type_bien'] ?? ''));
$proprio = trim((string)($bien['proprietaire_societe'] ?? ''));
if ($proprio === '') {
    $proprio = trim((string)(($bien['proprietaire_prenom'] ?? '') . ' ' . ($bien['proprietaire_nom'] ?? '')));
}

$decision = (string)($arb['decision'] ?? '');
$posture  = (string)($arb['posture'] ?? 'neutre');
$statutLoc = (string)($arb['statut_locatif'] ?? '');
$travNiv  = (string)($arb['travaux_niveau'] ?? 'aucun');

$signals = (array)($crgSnap['signals'] ?? []);
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= h($csrf) ?>">
  <title>Arbitrage — <?= h($designation) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('/css/base.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('/css/components.css') ?>">
  <link rel="stylesheet" href="<?= asset_url('/css/arbitrage.css') ?>">
</head>
<body>
  <div class="arb-shell" id="arb-detail"
       data-page="detail"
       data-bien-id="<?= (int)$bienId ?>"
       data-autosave-url="<?= h(app_url('/api/arbitrage_autosave.php')) ?>">

    <header class="arb-topbar">
      <div class="arb-topbar-left">
        <a class="arb-back" href="<?= app_url('/arbitrage_biens.php') ?>">← Biens</a>
        <div class="arb-title">Fiche arbitrage</div>
        <div class="arb-subtitle"><?= h($designation) ?><?= $ville ? ' — ' . h($ville) : '' ?></div>
      </div>
      <div class="arb-topbar-actions">
        <button class="arb-btn" type="button" data-mode-toggle>Mode client</button>
        <a class="arb-btn" href="<?= app_url('/arbitrage_comparaison.php?ids=' . (int)$bienId) ?>">Comparer</a>
        <button class="arb-btn arb-btn-primary" type="button" data-print>Imprimer</button>
      </div>
    </header>

    <main class="arb-split">
      <!-- Colonne gauche : saisie / décisions -->
      <section class="arb-left" aria-label="Données et décisions">
        <div class="arb-card arb-card-head">
          <div class="arb-head-lines">
            <div class="arb-h1"><?= h($designation) ?></div>
            <div class="arb-h2"><?= h($adresse) ?><?= $ville ? ' · ' . h($ville) : '' ?></div>
            <div class="arb-meta">
              <span class="arb-meta-pill"><?= h($type ?: 'Type —') ?></span>
              <?php if ($proprio): ?><span class="arb-meta-pill"><?= h($proprio) ?></span><?php endif; ?>
              <?php if (!empty($bien['surface_habitable'])): ?><span class="arb-meta-pill"><?= number_format((float)$bien['surface_habitable'], 1, ',', ' ') ?> m²</span><?php endif; ?>
              <?php if (!empty($bien['locataire_nom'])): ?><span class="arb-meta-pill">Locataire : <?= h((string)$bien['locataire_nom']) ?></span><?php endif; ?>
              <?php if (!empty($bien['bail_debut'])): ?><span class="arb-meta-pill">Bail : <?= h((string)$bien['bail_debut']) ?><?= !empty($bien['bail_fin']) ? ' → ' . h((string)$bien['bail_fin']) : '' ?></span><?php endif; ?>
            </div>
          </div>
        </div>

        <div class="arb-card">
          <div class="arb-card-title">Décision rapide</div>
          <div class="arb-btnrow" role="group" aria-label="Décision">
            <button class="arb-toggle <?= $decision === 'conserver' ? 'is-on' : '' ?>" type="button" data-choice="decision" data-value="conserver">Conserver</button>
            <button class="arb-toggle <?= $decision === 'vendre' ? 'is-on' : '' ?>" type="button" data-choice="decision" data-value="vendre">Vendre</button>
            <button class="arb-toggle <?= $decision === 'arbitrer' ? 'is-on' : '' ?>" type="button" data-choice="decision" data-value="arbitrer">Arbitrer</button>
            <button class="arb-toggle <?= $decision === 'reinvestir' ? 'is-on' : '' ?>" type="button" data-choice="decision" data-value="reinvestir">Réinvestir</button>
          </div>
        </div>

        <div class="arb-card">
          <div class="arb-card-title">Posture d’analyse IA</div>
          <div class="arb-btnrow" role="group" aria-label="Posture">
            <button class="arb-toggle <?= $posture === 'neutre' ? 'is-on' : '' ?>" type="button" data-choice="posture" data-value="neutre">Neutre</button>
            <button class="arb-toggle <?= $posture === 'patrimoniale' ? 'is-on' : '' ?>" type="button" data-choice="posture" data-value="patrimoniale">Patrimoniale</button>
            <button class="arb-toggle <?= $posture === 'urgence_tresorerie' ? 'is-on' : '' ?>" type="button" data-choice="posture" data-value="urgence_tresorerie">Urgence trésorerie</button>
          </div>
        </div>

        <div class="arb-card">
          <div class="arb-card-title">Statut locatif</div>
          <div class="arb-btnrow" role="group" aria-label="Statut locatif">
            <button class="arb-toggle <?= $statutLoc === 'loue' ? 'is-on' : '' ?>" type="button" data-choice="statut_locatif" data-value="loue">Loué</button>
            <button class="arb-toggle <?= $statutLoc === 'partiel' ? 'is-on' : '' ?>" type="button" data-choice="statut_locatif" data-value="partiel">Partiel</button>
            <button class="arb-toggle <?= $statutLoc === 'vide' ? 'is-on' : '' ?>" type="button" data-choice="statut_locatif" data-value="vide">Vide</button>
          </div>

          <div class="arb-grid-2">
            <label class="arb-field">
              <span>Début de vacance</span>
              <input type="date" value="<?= h(v($arb['vacance_debut'] ?? null)) ?>" data-field="vacance_debut">
            </label>
            <label class="arb-field">
              <span>Vacance (mois)</span>
              <input type="number" inputmode="numeric" min="0" step="1" value="<?= h(v(isset($arb['vacance_mois']) ? (string)$arb['vacance_mois'] : null)) ?>" data-field="vacance_mois">
            </label>
            <label class="arb-field">
              <span>Loyer actuel (€/mois)</span>
              <input type="number" inputmode="decimal" min="0" step="1" value="<?= h(v(isset($arb['loyer_actuel_mensuel']) ? (string)$arb['loyer_actuel_mensuel'] : null)) ?>" data-field="loyer_actuel_mensuel">
            </label>
            <label class="arb-field">
              <span>Loyer potentiel (€/mois)</span>
              <input type="number" inputmode="decimal" min="0" step="1" value="<?= h(v(isset($arb['loyer_potentiel_mensuel']) ? (string)$arb['loyer_potentiel_mensuel'] : null)) ?>" data-field="loyer_potentiel_mensuel">
            </label>
          </div>
        </div>

        <div class="arb-card">
          <div class="arb-card-title">Coûts du bien (annuels)</div>
          <div class="arb-grid-2">
            <label class="arb-field"><span>Taxe foncière</span><input type="number" min="0" step="1" value="<?= h(v(isset($arb['taxe_fonciere']) ? (string)$arb['taxe_fonciere'] : null)) ?>" data-field="taxe_fonciere"></label>
            <label class="arb-field"><span>Charges non récup.</span><input type="number" min="0" step="1" value="<?= h(v(isset($arb['charges_non_recup']) ? (string)$arb['charges_non_recup'] : null)) ?>" data-field="charges_non_recup"></label>
            <label class="arb-field"><span>Assurance</span><input type="number" min="0" step="1" value="<?= h(v(isset($arb['assurance']) ? (string)$arb['assurance'] : null)) ?>" data-field="assurance"></label>
            <label class="arb-field"><span>Entretien</span><input type="number" min="0" step="1" value="<?= h(v(isset($arb['entretien']) ? (string)$arb['entretien'] : null)) ?>" data-field="entretien"></label>
            <label class="arb-field"><span>Frais de gestion</span><input type="number" min="0" step="1" value="<?= h(v(isset($arb['frais_gestion']) ? (string)$arb['frais_gestion'] : null)) ?>" data-field="frais_gestion"></label>
            <label class="arb-field"><span>Autres coûts</span><input type="number" min="0" step="1" value="<?= h(v(isset($arb['autres_couts_annuels']) ? (string)$arb['autres_couts_annuels'] : null)) ?>" data-field="autres_couts_annuels"></label>
          </div>
        </div>

        <div class="arb-card">
          <div class="arb-card-title">Travaux / passif technique</div>
          <div class="arb-btnrow" role="group" aria-label="Niveau travaux">
            <button class="arb-toggle <?= $travNiv === 'aucun' ? 'is-on' : '' ?>" type="button" data-choice="travaux_niveau" data-value="aucun">Aucun</button>
            <button class="arb-toggle <?= $travNiv === 'a_prevoir' ? 'is-on' : '' ?>" type="button" data-choice="travaux_niveau" data-value="a_prevoir">À prévoir</button>
            <button class="arb-toggle <?= $travNiv === 'lourds' ? 'is-on' : '' ?>" type="button" data-choice="travaux_niveau" data-value="lourds">Lourds</button>
          </div>
          <div class="arb-tags" data-tags>
            <?php
              $tags = [];
              if (!empty($arb['travaux_tags'])) {
                  $decoded = json_decode((string)$arb['travaux_tags'], true);
                  $tags = is_array($decoded) ? $decoded : [];
              }
              $tagOptions = [
                  'toiture' => 'Toiture',
                  'toiture_amiantee' => 'Toiture amiantée',
                  'chauffage' => 'Chauffage',
                  'electricite' => 'Électricité',
                  'securite' => 'Sécurité',
                  'conformite' => 'Conformité',
                  'sinistres' => 'Sinistres',
                  'structure' => 'Structure',
                  'dpe' => 'Énergétique / DPE',
                  'accessibilite' => 'Accessibilité',
              ];
              foreach ($tagOptions as $k => $lbl):
                $on = in_array($k, $tags, true);
            ?>
              <button class="arb-tag <?= $on ? 'is-on' : '' ?>" type="button" data-tag="<?= h($k) ?>"><?= h($lbl) ?></button>
            <?php endforeach; ?>
          </div>
          <div class="arb-grid-2">
            <label class="arb-field"><span>Travaux 1 an</span><input type="number" min="0" step="1" value="<?= h(v(isset($arb['travaux_1an']) ? (string)$arb['travaux_1an'] : null)) ?>" data-field="travaux_1an"></label>
            <label class="arb-field"><span>Travaux 3 ans</span><input type="number" min="0" step="1" value="<?= h(v(isset($arb['travaux_3ans']) ? (string)$arb['travaux_3ans'] : null)) ?>" data-field="travaux_3ans"></label>
            <label class="arb-field"><span>Travaux 5 ans</span><input type="number" min="0" step="1" value="<?= h(v(isset($arb['travaux_5ans']) ? (string)$arb['travaux_5ans'] : null)) ?>" data-field="travaux_5ans"></label>
          </div>
        </div>

        <div class="arb-card">
          <div class="arb-card-title">Prix / décote / liquidité</div>
          <div class="arb-grid-2">
            <label class="arb-field"><span>Prix estimé</span><input type="number" min="0" step="1" value="<?= h(v(isset($arb['prix_estime']) ? (string)$arb['prix_estime'] : null)) ?>" data-field="prix_estime"></label>
            <label class="arb-field"><span>Prix de vente réaliste</span><input type="number" min="0" step="1" value="<?= h(v(isset($arb['prix_vente_realiste']) ? (string)$arb['prix_vente_realiste'] : null)) ?>" data-field="prix_vente_realiste"></label>
            <label class="arb-field"><span>Frais d’agence</span><input type="number" min="0" step="1" value="<?= h(v(isset($arb['frais_agence']) ? (string)$arb['frais_agence'] : null)) ?>" data-field="frais_agence"></label>
            <label class="arb-field"><span>Frais de notaire</span><input type="number" min="0" step="1" value="<?= h(v(isset($arb['frais_notaire']) ? (string)$arb['frais_notaire'] : null)) ?>" data-field="frais_notaire"></label>
            <label class="arb-field"><span>Coût acte en main</span><input type="number" min="0" step="1" value="<?= h(v(isset($arb['cout_acte_en_main']) ? (string)$arb['cout_acte_en_main'] : null)) ?>" data-field="cout_acte_en_main"></label>
            <label class="arb-field"><span>Délai vente (mois)</span><input type="number" min="1" step="1" value="<?= h(v(isset($arb['delai_vente_mois']) ? (string)$arb['delai_vente_mois'] : null)) ?>" data-field="delai_vente_mois"></label>
            <label class="arb-field"><span>Liquidité (1-5)</span><input type="number" min="1" max="5" step="1" value="<?= h(v(isset($arb['liquidite_niveau']) ? (string)$arb['liquidite_niveau'] : null)) ?>" data-field="liquidite_niveau"></label>
            <label class="arb-field"><span>Risque (1-5)</span><input type="number" min="1" max="5" step="1" value="<?= h(v(isset($arb['risque_niveau']) ? (string)$arb['risque_niveau'] : null)) ?>" data-field="risque_niveau"></label>
          </div>
        </div>

        <div class="arb-card">
          <div class="arb-card-title">Analyse & recommandation Emery</div>
          <div class="arb-field">
            <span>Commentaire (autosave)</span>
            <textarea rows="7" data-field="commentaire_emery" placeholder="Arguments terrain, contexte, nuance, stratégie..."><?= h((string)($arb['commentaire_emery'] ?? '')) ?></textarea>
          </div>
          <label class="arb-field">
            <span>Conclusion personnalisée</span>
            <input type="text" value="<?= h((string)($arb['conclusion_emery'] ?? '')) ?>" data-field="conclusion_emery" placeholder="Vente immédiate / Conservation / Arbitrage...">
          </label>
        </div>
      </section>

      <!-- Colonne droite : graphiques / impacts / synthèse -->
      <section class="arb-right" aria-label="Impacts et synthèse">
        <div class="arb-card arb-kpi-grid">
          <div class="arb-mini">
            <div class="arb-mini-label">Valeur estimée</div>
            <div class="arb-mini-value" data-kpi="prix"><?= number_format((float)($metrics['prix_vente_realiste'] ?? 0), 0, ',', ' ') ?> €</div>
          </div>
          <div class="arb-mini">
            <div class="arb-mini-label">Coût annuel</div>
            <div class="arb-mini-value" data-kpi="cout_annuel"><?= number_format((float)($metrics['cout_annuel'] ?? 0), 0, ',', ' ') ?> €</div>
          </div>
          <div class="arb-mini">
            <div class="arb-mini-label">Rendement réel</div>
            <div class="arb-mini-value" data-kpi="rendement"><?= number_format((float)($metrics['rendement_reel_pct'] ?? 0), 2, ',', ' ') ?>%</div>
          </div>
          <div class="arb-mini">
            <div class="arb-mini-label">Score stratégique</div>
            <div class="arb-mini-value" data-kpi="score"><?= (int)($metrics['score_strategique'] ?? 0) ?>/100</div>
          </div>
        </div>

        <div class="arb-card">
          <div class="arb-card-title">Graphiques décisionnels</div>
          <div class="arb-charts">
            <div class="arb-chart" data-chart="couts">
              <div class="arb-chart-title">Coût réel du bien</div>
              <svg class="arb-svg" viewBox="0 0 360 120" role="img" aria-label="Coût réel"></svg>
            </div>
            <div class="arb-chart" data-chart="scenarios">
              <div class="arb-chart-title">Vendre ou conserver (<?= (int)$settings['horizon_mois'] ?> mois)</div>
              <svg class="arb-svg" viewBox="0 0 360 120" role="img" aria-label="Scénarios"></svg>
            </div>
            <div class="arb-chart" data-chart="radar">
              <div class="arb-chart-title">Qualité stratégique</div>
              <svg class="arb-svg" viewBox="0 0 360 160" role="img" aria-label="Radar"></svg>
            </div>
          </div>
        </div>

        <div class="arb-card">
          <div class="arb-card-title">Informations issues des CRG</div>
          <?php if (empty($signals)): ?>
            <div class="arb-empty">Aucun signal CRG détecté (ou CRG non relié à ce lot).</div>
          <?php else: ?>
            <div class="arb-crg-cards" data-crg-cards>
              <?php foreach (array_slice($signals, 0, 10) as $s):
                  $sev = (string)($s['severity'] ?? 'info');
                  $title = (string)($s['title'] ?? '');
                  $value = (string)($s['value'] ?? '');
              ?>
                <div class="arb-crg-card arb-sev-<?= h($sev) ?>">
                  <div class="arb-crg-title"><?= h($title) ?></div>
                  <div class="arb-crg-value"><?= h($value) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="arb-card">
          <div class="arb-card-title">Synthèse automatique (non modifiable)</div>
          <pre class="arb-pre" data-text="synthese"><?= h($synthese) ?></pre>
          <div class="arb-row">
            <button class="arb-btn" type="button" data-copy="synthese">Copier</button>
            <button class="arb-btn arb-btn-primary" type="button" data-regen-ai>Régénérer (IA)</button>
            <div class="arb-save" data-save-state>—</div>
          </div>
        </div>

        <div class="arb-card">
          <div class="arb-card-title">Argumentaire final fusionné</div>
          <pre class="arb-pre arb-pre-large" data-text="argumentaire"><?= h($finalTxt) ?></pre>
          <div class="arb-row">
            <button class="arb-btn arb-btn-primary" type="button" data-copy="argumentaire">Copier en 1 clic</button>
          </div>
        </div>
      </section>
    </main>
  </div>

  <script src="<?= asset_url('/js/arbitrage/utils.js') ?>"></script>
  <script src="<?= asset_url('/js/arbitrage/charts.js') ?>"></script>
  <script src="<?= asset_url('/js/arbitrage/detail.js') ?>"></script>
</body>
</html>
