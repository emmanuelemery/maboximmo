<?php
declare(strict_types=1);
/**
 * investisseur/detail.php — Vue détaillée d'une analyse
 *
 * Affichage structuré :
 *   - Score hero + recommandation finale
 *   - Mini-charts (répartition coût + charges + radar scoring) cliquables
 *   - Synthèse
 *   - SWOT (forces / faiblesses / risques / opportunités)
 *   - Onglets argumentaires (prudent / équilibré / offensif)
 *   - Tableau KPI complet
 *   - Actions : éditer, dupliquer, comparer, présenter au client, supprimer
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/investisseur_helpers.php';
require_once __DIR__ . '/../inc/investisseur_interpretations.php';
require_once __DIR__ . '/../inc/investisseur_commentaires.php';
require_once __DIR__ . '/../inc/investisseur_valo.php';
require_once __DIR__ . '/../inc/investisseur_projection.php';

$pdo = $GLOBALS['pdo'];
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); die('Identifiant manquant.'); }

// Actions POST : dupliquer / supprimer / commentaires (add/edit/del/toggle)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_any();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete') {
        inv_delete($pdo, $id);
        header('Location: ' . (function_exists('app_url') ? app_url('/investisseur/') : '/investisseur/'));
        exit;
    }
    if ($action === 'duplicate') {
        $newId = inv_duplicate($pdo, $id);
        if ($newId) {
            header('Location: ' . (function_exists('app_url') ? app_url('/investisseur/detail.php?id=') : '/investisseur/detail.php?id=') . $newId);
            exit;
        }
    }

    // ─── Commentaires inline ─────────────────────────────────────
    if (in_array($action, ['comm_save','comm_delete','comm_toggle'], true)) {
        try {
            if ($action === 'comm_save') {
                $post = $_POST;
                $post['id_analyse'] = $id;
                $commId = !empty($_POST['comment_id']) ? (int)$_POST['comment_id'] : null;
                inv_comm_save($pdo, $post, $commId);
            } elseif ($action === 'comm_delete' && !empty($_POST['comment_id'])) {
                inv_comm_delete($pdo, (int)$_POST['comment_id']);
            } elseif ($action === 'comm_toggle' && !empty($_POST['comment_id'])) {
                $existing = inv_comm_load($pdo, (int)$_POST['comment_id']);
                if ($existing) {
                    inv_comm_save($pdo, array_merge($existing, ['actif' => $existing['actif'] ? '0' : 1]), (int)$_POST['comment_id']);
                }
            }
        } catch (Throwable $e) {
            $_SESSION['_inv_flash_error'] = $e->getMessage();
        }
        header('Location: ' . (function_exists('app_url') ? app_url('/investisseur/detail.php?id=') : '/investisseur/detail.php?id=') . $id . '#comm');
        exit;
    }

    // Recalcul = enregistrement à l'identique pour déclencher inv_recalc_and_merge (injection commentaires)
    if ($action === 'recalc') {
        try {
            $row = inv_load($pdo, $id);
            if ($row) inv_save($pdo, $row, $id);
        } catch (Throwable $e) { $_SESSION['_inv_flash_error'] = $e->getMessage(); }
        header('Location: ' . (function_exists('app_url') ? app_url('/investisseur/detail.php?id=') : '/investisseur/detail.php?id=') . $id);
        exit;
    }
}

$row = inv_load($pdo, $id);
if (!$row) { http_response_code(404); die('Analyse introuvable.'); }

$calc = [
    'cout_total'         => (float)$row['cout_total'],
    'rendement_brut'     => (float)$row['rendement_brut'],
    'rendement_net'      => (float)$row['rendement_net'],
    'cashflow_mensuel'   => (float)$row['cashflow_mensuel'],
    'mensualite_credit'  => (float)$row['mensualite_credit'],
    'revenu_annuel'      => (float)$row['revenu_annuel'],
    'charges_annuelles'  => (float)$row['charges_annuelles'],
    'score_risque'       => (int)$row['score_risque'],
    'score_attractivite' => (int)$row['score_attractivite'],
    'score_global'       => (int)$row['score_global'],
    'projection_10_ans'  => (float)$row['projection_10_ans'],
];

$sg = (int)$row['score_global'];
$color = inv_score_color($sg);
$lbl = inv_score_libelle($sg);

$pageTitle     = 'Analyse : ' . ($row['titre_analyse'] ?: '#' . $id);
$pageSubtitle  = 'Ma Box Bailleur › Investisseur › Détail';
$layoutSidebar = 'sidebar_bailleur';
$extraCss      = '<link rel="stylesheet" href="' . (function_exists('asset_url') ? asset_url('/investisseur/assets/investisseur.css') : '/investisseur/assets/investisseur.css') . '">';
$bodyAttr      = 'class="inv-body"';
require_once __DIR__ . '/../inc/agency_layout_top.php';

$u = function_exists('app_url') ? fn($p) => app_url($p) : fn($p) => $p;
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// Données charts (petits formats, cliquables)
$coutParts = array_filter([
    'Prix'      => (float)($row['prix_achat']    ?? 0),
    'Notaire'   => (float)($row['frais_notaire'] ?? 0) ?: (float)($row['prix_achat'] ?? 0) * 0.08,
    'Agence'    => (float)($row['frais_agence']  ?? 0),
    'Travaux'   => (float)($row['travaux']       ?? 0),
    'Ameublement' => (float)($row['ameublement'] ?? 0),
], fn($v) => $v > 0);

$chargesParts = array_filter([
    'Charges non récup.' => (float)($row['charges_non_recuperables'] ?? 0) * 12,
    'Taxe foncière'       => (float)($row['taxe_fonciere'] ?? 0),
    'Assurance PNO'       => (float)($row['assurance_pno'] ?? 0),
    'Gestion locative'    => (float)($row['loyer_estime'] ?? 0) * 12 * ((float)($row['gestion_locative'] ?? 0) / 100),
    'Entretien / imprévus'=> (float)($row['loyer_estime'] ?? 0) * 12 * ((float)($row['entretien_imprevus'] ?? 0) / 100),
], fn($v) => $v > 0);

$radarLabels = ['Attractivité','Sécurité','Emplacement','Tension','Potentiel','Lisibilité'];
$lisib = 0;
if (!empty($row['strategie'])) $lisib += 50;
$commLen = mb_strlen(trim((string)($row['commentaire_humain'] ?? '')));
$lisib += $commLen >= 80 ? 50 : ($commLen >= 20 ? 25 : 0);
$radarValues = [
    (int)$row['score_attractivite'],
    (int)$row['score_risque'],
    min(100, ((int)$row['qualite_emplacement']) * 20),
    min(100, ((int)$row['tension_locative']) * 20),
    min(100, ((int)$row['potentiel_valorisation']) * 20),
    $lisib,
];
?>
<!-- Menu d'ancres sticky (droite) -->
<nav class="inv-anchors">
    <a href="#meta">Vue</a>
    <a href="#score">Score</a>
    <a href="#arbitrage">Arbitrage</a>
    <a href="#valo">Valo</a>
    <a href="#swot">SWOT</a>
    <a href="#args">Argumentaires</a>
    <a href="#details">Chiffré</a>
    <a href="#comm">Commentaires</a>
</nav>
<style>
.inv-anchors {
    position: fixed;
    top: 120px;
    right: 14px;
    z-index: 40;
    background: #fff;
    border-radius: 12px;
    box-shadow: 4px 4px 10px #c8c4be, -4px -4px 10px #fff;
    padding: 10px 6px;
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 110px;
}
.inv-anchors a {
    display: block;
    padding: 6px 12px;
    font-family: 'DM Mono', monospace;
    font-size: 10px;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: #9a9690;
    text-decoration: none;
    border-left: 2px solid transparent;
    transition: all .15s;
}
.inv-anchors a:hover {
    color: #24324a;
    border-left-color: #24324a;
    background: #f9f7f2;
}
@media (max-width: 1280px) {
    .inv-anchors { display: none; }
}
</style>

<div class="inv-wrap">

    <div class="inv-header" id="meta">
        <h1><?= $h($row['titre_analyse']) ?></h1>
        <div style="flex:1"></div>
        <div class="inv-quickbar">
            <a href="<?= $h($u('/investisseur/nouvelle.php?id=' . $id)) ?>">✎ Éditer</a>
            <a href="<?= $h($u('/investisseur/presentation_client.php?id=' . $id)) ?>" class="primary">📄 Présentation</a>
            <a href="<?= $h($u('/investisseur/comparaison.php?ids=' . $id)) ?>">⚖ Comparer</a>
            <a href="<?= $h($u('/investisseur/')) ?>">☰ Liste</a>
        </div>
    </div>

    <!-- Meta bien -->
    <div class="inv-paper" style="border-left:4px solid <?= $h($color) ?>">
        <div style="display:flex; gap:24px; flex-wrap:wrap; align-items:center;">
            <div style="flex:1; min-width:240px;">
                <h3 style="margin:0"><?= $h($row['type_bien'] ?: 'Bien') ?> — <?= $h($row['ville']) ?></h3>
                <p style="margin:6px 0 0; color:#5a5a55; font-size:13px; line-height:1.5;">
                    <?php if ($row['surface']): ?><?= number_format((float)$row['surface'], 0, ',', ' ') ?> m²<?php endif; ?>
                    <?php if ($row['nb_pieces']): ?> · <?= (int)$row['nb_pieces'] ?> pièces<?php endif; ?>
                    <?php if (!empty($row['nb_parkings'])): ?> · <?= (int)$row['nb_parkings'] ?> parking(s)<?php endif; ?>
                    <?php if (!empty($row['photovoltaique'])): ?> · <strong style="color:#4f7a3a">☀ Photovoltaïque</strong><?php endif; ?>
                    <?php if ($row['adresse']): ?> · <?= $h($row['adresse']) ?><?php endif; ?>
                    <?php if ($row['reference_bien']): ?> · ref. <?= $h($row['reference_bien']) ?><?php endif; ?>
                    <?php if ($row['id_bien_source']): ?>
                        · <strong style="color:#4f7a3a">⇡ source BDD #<?= (int)$row['id_bien_source'] ?></strong>
                    <?php endif; ?>
                    · statut <strong><?= $h($row['statut']) ?></strong>
                </p>
                <?php if (!empty($row['locataire_nom']) || (!empty($row['bail_fin']) && $row['bail_fin'] !== '0000-00-00')): ?>
                    <p style="margin:8px 0 0; color:#24324a; font-size:13px;">
                        🔑 <strong>Locataire :</strong>
                        <?php if (!empty($row['locataire_nom'])): ?><?= $h($row['locataire_nom']) ?><?php else: ?>—<?php endif; ?>
                        <?php if (!empty($row['bail_fin']) && $row['bail_fin'] !== '0000-00-00'): ?>
                            · fin de bail : <strong><?= $h(date('d/m/Y', strtotime((string)$row['bail_fin']))) ?></strong>
                            <?php
                                $days = (int)floor((strtotime((string)$row['bail_fin']) - time()) / 86400);
                                if ($days > 0 && $days < 365):
                            ?>
                                · <span style="color:#d97a3a">⚠ échéance dans <?= $days ?> jours</span>
                            <?php elseif ($days < 0): ?>
                                · <span style="color:#b4443a">bail expiré</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
            <div class="inv-kpis">
                <div class="inv-kpi"><span class="v"><?= number_format((float)$row['rendement_brut'], 2, ',', ' ') ?>%</span><span class="l">Rdt brut</span></div>
                <div class="inv-kpi"><span class="v"><?= number_format((float)$row['rendement_net'], 2, ',', ' ') ?>%</span><span class="l">Rdt net</span></div>
                <div class="inv-kpi"><span class="v"><?= number_format((float)$row['cashflow_mensuel'], 0, ',', ' ') ?></span><span class="l">Cashflow/mois</span></div>
                <div class="inv-kpi"><span class="v"><?= number_format((float)$row['mensualite_credit'], 0, ',', ' ') ?></span><span class="l">Mensualité</span></div>
            </div>
        </div>
    </div>

    <!-- Score hero + SWOT -->
    <div id="score" style="display:grid; grid-template-columns: 1fr 1fr; gap:22px; align-items:stretch;">
        <div class="inv-paper">
            <h2>Score global</h2>
            <div class="inv-score-hero" style="--circle-color: <?= $h($color) ?>; --circle-pct: <?= max(0, min(100, $sg)) ?>%;">
                <div class="inv-score-circle">
                    <div style="position:relative; z-index:1; text-align:center;">
                        <div class="sc-num"><?= $sg ?></div>
                        <div class="sc-lbl">/100</div>
                    </div>
                </div>
                <div class="sh-text">
                    <h3><?= $h($lbl) ?></h3>
                    <p><strong>Recommandation :</strong> <?= $h($row['reco_finale']) ?></p>
                    <p style="margin-top:8px; font-size:12px; color:#9a9690">
                        Attractivité <?= (int)$row['score_attractivite'] ?>/100 · Sécurité <?= (int)$row['score_risque'] ?>/100
                    </p>
                </div>
            </div>
        </div>

        <div class="inv-paper">
            <h2>Profil (radar)</h2>
            <div class="inv-chart-grid">
                <div class="inv-chart-card">
                    <div class="inv-chart-wrap wide" title="Cliquer pour agrandir">
                        <canvas data-inv-chart="radar"
                                data-title="Profil de l'analyse"
                                data-labels='<?= $h(json_encode($radarLabels)) ?>'
                                data-values='<?= $h(json_encode($radarValues)) ?>'></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Mini-charts répartition -->
    <div class="inv-paper">
        <h2>Répartition</h2>
        <div class="inv-chart-grid">
            <?php if (!empty($coutParts)): ?>
            <div class="inv-chart-card">
                <h4>Coût d'acquisition</h4>
                <div class="inv-chart-wrap" title="Cliquer pour agrandir">
                    <canvas data-inv-chart="doughnut"
                            data-title="Répartition du coût d'acquisition"
                            data-labels='<?= $h(json_encode(array_keys($coutParts))) ?>'
                            data-values='<?= $h(json_encode(array_values($coutParts))) ?>'></canvas>
                </div>
                <div class="inv-chart-legend">Total : <strong><?= number_format((float)$row['cout_total'], 0, ',', ' ') ?> €</strong></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($chargesParts)): ?>
            <div class="inv-chart-card">
                <h4>Charges annuelles</h4>
                <div class="inv-chart-wrap" title="Cliquer pour agrandir">
                    <canvas data-inv-chart="doughnut"
                            data-title="Répartition des charges annuelles"
                            data-labels='<?= $h(json_encode(array_keys($chargesParts))) ?>'
                            data-values='<?= $h(json_encode(array_values($chargesParts))) ?>'></canvas>
                </div>
                <div class="inv-chart-legend">Total : <strong><?= number_format((float)$row['charges_annuelles'], 0, ',', ' ') ?> €</strong></div>
            </div>
            <?php endif; ?>

            <div class="inv-chart-card">
                <h4>Cashflow / mensualité / charges</h4>
                <div class="inv-chart-wrap" title="Cliquer pour agrandir">
                    <canvas data-inv-chart="bar"
                            data-title="Flux mensuels"
                            data-labels='<?= $h(json_encode(['Loyer HC','Charges /12','Mensualité','Cashflow'])) ?>'
                            data-values='<?= $h(json_encode([
                                round((float)$row['loyer_estime'], 0),
                                round((float)$row['charges_annuelles'] / 12, 0),
                                round((float)$row['mensualite_credit'], 0),
                                round((float)$row['cashflow_mensuel'], 0),
                            ])) ?>'></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Synthèse -->
    <div class="inv-paper">
        <h2>Synthèse</h2>
        <div class="inv-synth"><?= $h($row['synthese']) ?></div>
    </div>

    <!-- SWOT -->
    <div class="inv-paper">
        <h2 id="swot">Forces · Faiblesses · Risques · Opportunités</h2>
        <div class="inv-swot">
            <div class="inv-swot-card" style="--swot-color:#4f7a3a">
                <h4>Forces</h4>
                <div class="content"><?= $h($row['forces_txt']) ?></div>
            </div>
            <div class="inv-swot-card" style="--swot-color:#d97a3a">
                <h4>Faiblesses</h4>
                <div class="content"><?= $h($row['faiblesses_txt']) ?></div>
            </div>
            <div class="inv-swot-card" style="--swot-color:#b4443a">
                <h4>Risques</h4>
                <div class="content"><?= $h($row['risques_txt']) ?></div>
            </div>
            <div class="inv-swot-card" style="--swot-color:#4878a6">
                <h4>Opportunités</h4>
                <div class="content"><?= $h($row['opportunites_txt']) ?></div>
            </div>
        </div>
    </div>

    <!-- Argumentaires (3 tons) -->
    <div class="inv-paper">
        <h2 id="args">Argumentaires commerciaux</h2>
        <div class="inv-tabs" data-inv-tabs>
            <button type="button" class="inv-tab active" data-tab="prudent">Prudent</button>
            <button type="button" class="inv-tab"        data-tab="equilibre">Équilibré</button>
            <button type="button" class="inv-tab"        data-tab="offensif">Offensif</button>
        </div>
        <div class="inv-tab-content active" data-inv-tab-pane="prudent">
            <div class="inv-synth" style="border-left-color:#7ba056"><?= $h($row['argumentaire_prudent']) ?></div>
        </div>
        <div class="inv-tab-content" data-inv-tab-pane="equilibre">
            <div class="inv-synth" style="border-left-color:#4878a6"><?= $h($row['argumentaire_equilibre']) ?></div>
        </div>
        <div class="inv-tab-content" data-inv-tab-pane="offensif">
            <div class="inv-synth" style="border-left-color:#d97a3a"><?= $h($row['argumentaire_offensif']) ?></div>
        </div>
    </div>

    <!-- Détails chiffrés -->
    <div class="inv-paper">
        <h2 id="details">Détail chiffré</h2>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
            <table class="inv-kpi-table">
                <?php
                $pvCatalog = (float)($row['prix_vente_catalogue'] ?? 0);
                $pAchat = (float)$row['prix_achat'];
                $ecartNego = $pvCatalog > 0 ? ($pAchat - $pvCatalog) : 0;
                $ecartNegoPct = $pvCatalog > 0 ? ($ecartNego / $pvCatalog) * 100 : 0;
                ?>
                <?php if ($pvCatalog > 0 && abs($ecartNegoPct) > 0.1): ?>
                    <tr><th>Prix de vente catalogue</th><td style="color:#9a9690;"><?= number_format($pvCatalog, 0, ',', ' ') ?> €</td></tr>
                    <tr><th>Prix d'achat (négocié)</th><td><strong><?= number_format($pAchat, 0, ',', ' ') ?> €</strong></td></tr>
                    <tr><th>Écart de négociation</th><td style="color:<?= $ecartNego < 0 ? '#4f7a3a' : '#d97a3a' ?>; font-weight:700;">
                        <?= $ecartNego < 0 ? '−' : '+' ?><?= number_format(abs($ecartNego), 0, ',', ' ') ?> €
                        (<?= $ecartNego < 0 ? '−' : '+' ?><?= number_format(abs($ecartNegoPct), 1, ',', ' ') ?> %)
                    </td></tr>
                <?php else: ?>
                    <tr><th>Prix d'achat</th><td><?= number_format($pAchat, 0, ',', ' ') ?> €</td></tr>
                <?php endif; ?>
                <tr><th>Prix / m²</th><td><strong><?= (float)$row['prix_m2'] > 0 ? number_format((float)$row['prix_m2'], 0, ',', ' ') . ' €' : '—' ?></strong></td></tr>
                <tr><th>Frais de notaire</th><td><?= number_format((float)$row['frais_notaire'], 0, ',', ' ') ?> €</td></tr>
                <tr><th>Frais d'agence</th><td><?= number_format((float)$row['frais_agence'], 0, ',', ' ') ?> €</td></tr>
                <tr><th>Travaux</th><td><?= number_format((float)$row['travaux'], 0, ',', ' ') ?> €</td></tr>
                <tr><th>Coût total</th><td><strong><?= number_format((float)$row['cout_total'], 0, ',', ' ') ?> €</strong></td></tr>
                <tr><th>Honoraires vente est.</th><td><?= (float)($row['honoraires_vente'] ?? 0) > 0 ? number_format((float)$row['honoraires_vente'], 0, ',', ' ') . ' €' : '—' ?></td></tr>
                <tr><th>Apport</th><td><?= number_format((float)$row['apport'], 0, ',', ' ') ?> €</td></tr>
                <tr><th>Montant financé</th><td><?= number_format((float)$row['montant_finance'], 0, ',', ' ') ?> €</td></tr>
                <tr><th>Mensualité crédit</th><td><?= number_format((float)$row['mensualite_credit'], 0, ',', ' ') ?> €</td></tr>
            </table>
            <table class="inv-kpi-table">
                <tr><th>Loyer HC mensuel</th><td><?= number_format((float)$row['loyer_estime'], 0, ',', ' ') ?> €</td></tr>
                <tr><th>Revenu annuel net vacance</th><td><?= number_format((float)$row['revenu_annuel'], 0, ',', ' ') ?> €</td></tr>
                <tr><th>Charges annuelles</th><td><?= number_format((float)$row['charges_annuelles'], 0, ',', ' ') ?> €</td></tr>
                <tr><th>Rendement brut</th><td><?= number_format((float)$row['rendement_brut'], 2, ',', ' ') ?> %</td></tr>
                <tr><th>Rendement net</th><td><strong><?= number_format((float)$row['rendement_net'], 2, ',', ' ') ?> %</strong></td></tr>
                <tr><th>Multiple loyer</th><td><strong><?= (float)$row['multiple_loyer'] > 0 ? number_format((float)$row['multiple_loyer'], 1, ',', ' ') . '×' : '—' ?></strong></td></tr>
                <tr><th>Cashflow mensuel</th><td><strong><?= number_format((float)$row['cashflow_mensuel'], 0, ',', ' ') ?> €</strong></td></tr>
                <tr><th>Effort d'épargne</th><td><?= number_format((float)$row['effort_epargne'], 0, ',', ' ') ?> € / mois</td></tr>
                <tr><th>Projection 10 ans</th><td><?= number_format((float)$row['projection_10_ans'], 0, ',', ' ') ?> €</td></tr>
            </table>
        </div>
    </div>

    <!-- Commentaire humain -->
    <?php if (!empty($row['commentaire_humain'])): ?>
    <div class="inv-paper">
        <h2>Commentaire humain</h2>
        <div class="inv-synth" style="border-left-color:#9a9690; font-style:italic;"><?= $h($row['commentaire_humain']) ?></div>
    </div>
    <?php endif; ?>

    <!-- ─── Arbitrage : vendre aujourd'hui vs garder N ans ─────────── -->
    <?php
    $arb = inv_proj_arbitrage($row, [5, 10]);
    $meilleur = $arb['meilleur'];
    $colors = ['vendre_now' => '#d97a3a', 'garder_5' => '#7ba056', 'garder_10' => '#4f7a3a'];
    $fmtE = fn($v) => number_format((float)$v, 0, ',', ' ');
    ?>
    <div class="inv-paper" id="arbitrage" style="border-left:4px solid #24324a">
        <h2>🔄 Arbitrage : vendre aujourd'hui vs conserver</h2>

        <!-- Hypothèses live -->
        <details style="margin:0 0 18px;">
            <summary style="cursor:pointer; font-family:'Sora',sans-serif; font-size:12px; font-weight:600; color:#4878a6;">
                ⚙ Hypothèses de projection (cliquez pour affiner)
            </summary>
            <div class="inv-form-grid col4" style="margin-top:12px;">
                <div class="inv-field"><label>CRD crédit actuel (€)</label>
                    <input type="number" step="1000" id="arb_crd" value="<?= (float)($row['credit_crd'] ?? 0) ?>"></div>
                <div class="inv-field"><label>Durée restante crédit (mois)</label>
                    <input type="number" id="arb_duree" value="<?= (int)($row['credit_duree_restante_mois'] ?? 0) ?>"></div>
                <div class="inv-field"><label>Taux crédit (%)</label>
                    <input type="number" step="0.01" id="arb_taux" value="<?= (float)($row['taux_credit'] ?? 3.5) ?>"></div>
                <div class="inv-field"><label>IRA (% du CRD)</label>
                    <input type="number" step="0.1" id="arb_ira" value="<?= (float)($row['ira_pct'] ?? 3.0) ?>"></div>
                <div class="inv-field"><label>Revalorisation bien (%/an)</label>
                    <input type="number" step="0.1" id="arb_revalo" value="<?= (float)($row['revalorisation_bien_pct_an'] ?? 1.5) ?>"></div>
                <div class="inv-field"><label>Indexation loyer (%/an)</label>
                    <input type="number" step="0.1" id="arb_index" value="<?= (float)($row['indexation_loyer_pct_an'] ?? 1.0) ?>"></div>
                <div class="inv-field"><label>Imposition (%) — optionnel</label>
                    <input type="number" step="0.5" id="arb_impot" value="<?= $row['taux_imposition_pct'] !== null ? (float)$row['taux_imposition_pct'] : '' ?>" placeholder="Vide = brut avant impôt">
                    <span class="hint">Laissez vide pour calculs brut avant impôt</span></div>
                <div class="inv-field" style="padding-top:22px;">
                    <button type="button" class="inv-btn" id="arb-save">💾 Enregistrer ces hypothèses</button>
                </div>
            </div>
        </details>

        <!-- 3 scénarios côte à côte -->
        <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:14px;" id="arb-scenarios">
            <?php foreach (['vendre_now','garder_5','garder_10'] as $k):
                $s = $arb['scenarios'][$k] ?? null;
                if (!$s) continue;
                $isBest = ($k === $meilleur);
                $col = $colors[$k];
            ?>
            <div class="inv-paper" style="margin:0; border-left:5px solid <?= $col ?>; position:relative; <?= $isBest ? 'box-shadow: 0 4px 20px ' . $col . '40;' : '' ?>">
                <?php if ($isBest): ?>
                    <div style="position:absolute; top:-10px; right:12px; background:<?= $col ?>; color:#fff; padding:4px 12px; border-radius:999px; font-size:10px; font-family:'DM Mono',monospace; letter-spacing:.12em; text-transform:uppercase;">Recommandé</div>
                <?php endif; ?>
                <h3 style="margin:0 0 10px; color:<?= $col ?>; font-size:14px;"><?= $h($s['label']) ?></h3>
                <div style="font-family:'Sora',sans-serif; font-size:28px; font-weight:800; color:#24324a; line-height:1;" class="arb-cash" data-scenario="<?= $k ?>"><?= $fmtE($s['cash_net']) ?> €</div>
                <div style="font-family:'DM Mono',monospace; font-size:10px; color:#9a9690; letter-spacing:.12em; text-transform:uppercase; margin-top:4px;">Cash net encaissé</div>
                <div style="margin-top:14px; padding-top:10px; border-top:1px dashed #e6e1d7;">
                    <?php foreach ($s['detail'] as $lbl => $val):
                        $negative = (float)$val < 0;
                    ?>
                    <div style="display:flex; justify-content:space-between; font-size:12px; margin:3px 0; color:<?= $negative ? '#b4443a' : '#2c2a28' ?>">
                        <span style="color:#5a5a55;"><?= $h($lbl) ?></span>
                        <strong><?= $negative ? '−' : '' ?><?= $fmtE(abs((float)$val)) ?> €</strong>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div style="margin-top:16px; padding:14px 18px; background:#f9f7f2; border-left:4px solid <?= $colors[$meilleur] ?>; border-radius:8px; font-size:13.5px; line-height:1.55;" id="arb-conseil">
            💡 <strong><?= $h($arb['conseils']) ?></strong>
        </div>

        <!-- Graphique évolution cashflow + valeur bien -->
        <div class="inv-chart-grid" style="margin-top:18px;">
            <div class="inv-chart-card" style="background:#fff;">
                <h4>Évolution valeur du bien</h4>
                <div class="inv-chart-wrap wide" title="Cliquer pour agrandir">
                    <canvas id="arb-chart-valeur" data-inv-chart="bar"
                            data-title="Valeur du bien (€) par année"
                            data-labels='<?= $h(json_encode(array_map(fn($y) => 'An ' . $y['annee'], $arb['scenarios']['garder_10']['years']))) ?>'
                            data-values='<?= $h(json_encode(array_map(fn($y) => $y['valeur_bien_fin_annee'], $arb['scenarios']['garder_10']['years']))) ?>'></canvas>
                </div>
            </div>
            <div class="inv-chart-card" style="background:#fff;">
                <h4>Cashflow cumulé net</h4>
                <div class="inv-chart-wrap wide" title="Cliquer pour agrandir">
                    <canvas id="arb-chart-cashflow" data-inv-chart="bar"
                            data-title="Cashflow cumulé (€)"
                            data-labels='<?= $h(json_encode(array_map(fn($y) => 'An ' . $y['annee'], $arb['scenarios']['garder_10']['years']))) ?>'
                            data-values='<?= $h(json_encode(array_map(fn($y) => $y['cashflow_cumule'], $arb['scenarios']['garder_10']['years']))) ?>'></canvas>
                </div>
            </div>
            <div class="inv-chart-card" style="background:#fff;">
                <h4>Capital restant dû (crédit)</h4>
                <div class="inv-chart-wrap wide" title="Cliquer pour agrandir">
                    <canvas id="arb-chart-crd" data-inv-chart="bar"
                            data-title="CRD (€)"
                            data-labels='<?= $h(json_encode(array_map(fn($y) => 'An ' . $y['annee'], $arb['scenarios']['garder_10']['years']))) ?>'
                            data-values='<?= $h(json_encode(array_map(fn($y) => $y['crd_fin_annee'], $arb['scenarios']['garder_10']['years']))) ?>'></canvas>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        const form = {
            crd:    document.getElementById('arb_crd'),
            duree:  document.getElementById('arb_duree'),
            taux:   document.getElementById('arb_taux'),
            ira:    document.getElementById('arb_ira'),
            revalo: document.getElementById('arb_revalo'),
            index:  document.getElementById('arb_index'),
            impot:  document.getElementById('arb_impot'),
        };
        const btnSave = document.getElementById('arb-save');

        async function recompute() {
            const body = new FormData();
            body.append('id', <?= (int)$id ?>);
            body.append('credit_crd',                 form.crd.value);
            body.append('credit_duree_restante_mois', form.duree.value);
            body.append('taux_credit',                form.taux.value);
            body.append('ira_pct',                    form.ira.value);
            body.append('revalorisation_bien_pct_an', form.revalo.value);
            body.append('indexation_loyer_pct_an',    form.index.value);
            body.append('taux_imposition_pct',        form.impot.value);
            body.append('action', 'arbitrage_preview');
            try {
                const r = await fetch('<?= $h($u('/investisseur/arbitrage_api.php')) ?>', {method: 'POST', body});
                const j = await r.json();
                if (!j.ok) return;
                document.querySelectorAll('.arb-cash').forEach(el => {
                    const k = el.dataset.scenario;
                    if (j.scenarios[k]) {
                        el.textContent = new Intl.NumberFormat('fr-FR', {maximumFractionDigits: 0}).format(j.scenarios[k].cash_net) + ' €';
                    }
                });
                if (j.conseils) document.querySelector('#arb-conseil strong').textContent = j.conseils;
            } catch (e) { console.error(e); }
        }

        let t = null;
        Object.values(form).forEach(el => el && el.addEventListener('input', () => {
            clearTimeout(t); t = setTimeout(recompute, 500);
        }));

        if (btnSave) {
            btnSave.addEventListener('click', async () => {
                btnSave.textContent = 'Enregistrement...';
                const body = new FormData();
                body.append('id', <?= (int)$id ?>);
                body.append('_csrf_token', '<?= $h(csrf_token()) ?>');
                Object.entries(form).forEach(([k, el]) => {
                    const map = {crd:'credit_crd', duree:'credit_duree_restante_mois', taux:'taux_credit', ira:'ira_pct', revalo:'revalorisation_bien_pct_an', index:'indexation_loyer_pct_an', impot:'taux_imposition_pct'};
                    body.append(map[k], el.value);
                });
                body.append('action', 'arbitrage_save');
                try {
                    const r = await fetch('<?= $h($u('/investisseur/arbitrage_api.php')) ?>', {method: 'POST', body});
                    const j = await r.json();
                    btnSave.textContent = j.ok ? '✓ Enregistré' : '✗ Erreur';
                    setTimeout(() => btnSave.textContent = '💾 Enregistrer ces hypothèses', 2000);
                } catch (e) {
                    btnSave.textContent = '✗ Erreur'; console.error(e);
                }
            });
        }
    })();
    </script>

    <!-- ─── Valorisation bien par bien (mini-simulateur) ──────────── -->
    <?php
    $mini = inv_valo_compute_line($row, inv_valo_default_params());
    ?>
    <div class="inv-paper" id="valo" style="border-left:4px solid #4f7a3a">
        <h2>💰 Valorisation théorique (simulateur rapide)</h2>
        <div style="display:grid; grid-template-columns: 1.3fr 1fr; gap:22px; align-items:center;">
            <div>
                <div style="display:flex; align-items:center; gap:14px; margin-bottom:8px;">
                    <label style="font-family:'DM Mono',monospace; font-size:10px; letter-spacing:.12em; text-transform:uppercase; color:#9a9690;">Taux cible</label>
                    <input type="range" id="mini-taux" min="3" max="10" step="0.25" value="<?= $h((float)$mini['taux_applique']) ?>" style="flex:1; accent-color:#4f7a3a;">
                    <div style="font-family:'Sora',sans-serif; font-size:22px; font-weight:800; color:#4f7a3a; min-width:60px; text-align:right;">
                        <span id="mini-taux-val"><?= number_format((float)$mini['taux_applique'], 2, ',', ' ') ?></span>%
                    </div>
                </div>
                <p style="margin:0; font-size:11.5px; color:#9a9690;">
                    Secteur détecté : <strong><?= $h($mini['secteur']) ?></strong>
                    · Typologie : <strong><?= $h($mini['typologie']) ?></strong>
                    · Méthode : <?= $h($mini['methode']) ?>
                </p>
                <p style="margin:10px 0 0;">
                    <a href="<?= $h($u('/investisseur/valorisation.php')) ?>" class="inv-btn sm">Ouvrir le simulateur complet →</a>
                </p>
            </div>
            <div class="inv-kpis" style="gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                <div class="inv-kpi"><span class="v" id="mini-loyer"><?= number_format((float)$mini['loyer_annuel'], 0, ',', ' ') ?></span><span class="l">Loyer/an €</span></div>
                <div class="inv-kpi"><span class="v" id="mini-theo" style="color:#4f7a3a;"><?= number_format((float)$mini['prix_theorique'], 0, ',', ' ') ?></span><span class="l">Théorique €</span></div>
                <div class="inv-kpi"><span class="v" id="mini-hono"><?= number_format((float)$mini['honoraires_theoriques'], 0, ',', ' ') ?></span><span class="l">Hono est. €</span></div>
                <div class="inv-kpi" style="background:<?= (float)$mini['ecart_pct'] >= 0 ? '#eef3ea' : '#fae9e1' ?>;">
                    <span class="v" id="mini-ecart" style="color:<?= (float)$mini['ecart_pct'] >= 0 ? '#4f7a3a' : '#b4443a' ?>;"><?= (float)$mini['ecart_pct'] >= 0 ? '+' : '' ?><?= number_format((float)$mini['ecart_pct'], 1, ',', ' ') ?>%</span>
                    <span class="l">vs catalogue</span>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function () {
        const slider = document.getElementById('mini-taux');
        const elTxt  = document.getElementById('mini-taux-val');
        const elTheo = document.getElementById('mini-theo');
        const elHono = document.getElementById('mini-hono');
        const elEcart= document.getElementById('mini-ecart');
        const loyerAn = <?= (float)$mini['loyer_annuel'] ?>;
        const prixCat = <?= (float)$mini['prix_catalogue'] ?>;
        const tauxHono = <?= (float)inv_valo_default_params()['taux_honoraires'] ?>;
        if (!slider) return;
        slider.addEventListener('input', (e) => {
            const t = parseFloat(e.target.value);
            elTxt.textContent = t.toFixed(2).replace('.', ',');
            const theo = loyerAn > 0 && t > 0 ? loyerAn / (t / 100) : prixCat;
            const hono = theo * (tauxHono / 100);
            elTheo.textContent = theo.toLocaleString('fr-FR', {maximumFractionDigits: 0});
            elHono.textContent = hono.toLocaleString('fr-FR', {maximumFractionDigits: 0});
            const ecartPct = prixCat > 0 ? ((theo - prixCat) / prixCat) * 100 : 0;
            elEcart.textContent = (ecartPct >= 0 ? '+' : '') + ecartPct.toFixed(1).replace('.', ',') + '%';
            elEcart.style.color = ecartPct >= 0 ? '#4f7a3a' : '#b4443a';
        });
    })();
    </script>

    <!-- ─── Commentaires orientés (inline) ─────────────────────────── -->
    <?php
    $comments = [];
    try {
        $comments = inv_comm_fetch($pdo, ['id_analyse' => $id, 'inclure_bien_via_analyse' => true, 'actif' => 1]);
    } catch (Throwable $e) {}
    $cats = inv_comm_categories();
    $ors  = inv_comm_orientations();
    $catColor = [
        'force'=>'#4f7a3a','faiblesse'=>'#d97a3a','risque'=>'#b4443a',
        'opportunite'=>'#4878a6','instruction_ia'=>'#7a6898','neutre'=>'#9a9690',
    ];
    ?>
    <div class="inv-paper" id="comm">
        <h2>💬 Commentaires orientés <span style="font-size:12px; font-weight:400; color:#9a9690; margin-left:10px;">(enrichissent la synthèse + guident l'analyse IA)</span></h2>

        <?php if (!empty($_SESSION['_inv_flash_error'])): ?>
            <div style="color:#b4443a; font-size:13px; margin-bottom:12px;">
                <?= $h($_SESSION['_inv_flash_error']); unset($_SESSION['_inv_flash_error']); ?>
            </div>
        <?php endif; ?>

        <!-- Ajout rapide -->
        <form method="post" style="margin-bottom:22px">
            <input type="hidden" name="_csrf_token" value="<?= $h(csrf_token()) ?>">
            <input type="hidden" name="action" value="comm_save">
            <div class="inv-form-grid">
                <div class="inv-field">
                    <label>Catégorie</label>
                    <select name="categorie">
                        <?php foreach ($cats as $k => $lbl): ?>
                            <option value="<?= $h($k) ?>"><?= $h($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="inv-field">
                    <label>Orientation</label>
                    <select name="orientation">
                        <?php foreach ($ors as $k => $lbl): ?>
                            <option value="<?= $h($k) ?>"><?= $h($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="inv-field">
                    <label>Poids (1 info — 5 déterminant)</label>
                    <input type="number" min="1" max="5" name="poids" value="3">
                </div>
                <div class="inv-field span-full">
                    <label>Titre (optionnel)</label>
                    <input type="text" name="titre" placeholder="Ex : Vue dégagée, contexte juridique, pression locale…">
                </div>
                <div class="inv-field span-full">
                    <label>Contenu *</label>
                    <textarea name="contenu" rows="3" required placeholder="Ce commentaire sera intégré à la synthèse et peut servir de directive IA pour régénérer l'argumentaire."></textarea>
                </div>
            </div>
            <div style="margin-top:12px; display:flex; gap:10px; justify-content:flex-end;">
                <button type="submit" class="inv-btn primary">+ Ajouter le commentaire</button>
            </div>
        </form>

        <!-- Liste des commentaires -->
        <?php if (empty($comments)): ?>
            <div style="color:#9a9690; font-size:13px; text-align:center; padding:14px;">Aucun commentaire actif sur cette analyse.</div>
        <?php else: ?>
            <?php foreach ($comments as $c):
                $col = $catColor[$c['categorie']] ?? '#9a9690';
            ?>
            <div class="inv-card-list" style="--bar-color: <?= $h($col) ?>; margin-bottom:10px;">
                <div class="ic-title-block">
                    <div class="ic-title"><?= $h($c['titre'] ?: ($cats[$c['categorie']] ?? 'Note')) ?></div>
                    <div class="ic-sub">
                        <?= $h($cats[$c['categorie']] ?? $c['categorie']) ?>
                        · <?= $h($ors[$c['orientation']] ?? $c['orientation']) ?>
                        · poids <?= (int)$c['poids'] ?>/5
                        · <?= $h(date('d/m/Y', strtotime($c['created_at']))) ?>
                    </div>
                    <div style="margin-top:6px; font-size:13px; color:#2c2a28; white-space:pre-wrap;"><?= $h($c['contenu']) ?></div>
                </div>
                <div class="ic-actions">
                    <form method="post" style="margin:0">
                        <input type="hidden" name="_csrf_token" value="<?= $h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="comm_toggle">
                        <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                        <button type="submit" class="inv-btn sm ghost">Archiver</button>
                    </form>
                    <form method="post" style="margin:0" onsubmit="return confirm('Supprimer ?');">
                        <input type="hidden" name="_csrf_token" value="<?= $h(csrf_token()) ?>">
                        <input type="hidden" name="action" value="comm_delete">
                        <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                        <button type="submit" class="inv-btn sm danger">Suppr.</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>

            <div style="margin-top:14px; text-align:center;">
                <form method="post" style="display:inline">
                    <input type="hidden" name="_csrf_token" value="<?= $h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="recalc">
                    <button type="submit" class="inv-btn">🔄 Recalculer la synthèse en intégrant les commentaires</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <!-- Actions destructives -->
    <div class="inv-paper" style="display:flex; justify-content:space-between; align-items:center;">
        <span style="color:#9a9690; font-size:12px;">Mis à jour le <?= $h(date('d/m/Y H:i', strtotime($row['updated_at']))) ?></span>
        <div style="display:flex; gap:10px;">
            <form method="post" style="margin:0">
                <input type="hidden" name="_csrf_token" value="<?= $h(csrf_token()) ?>">
                <input type="hidden" name="action" value="duplicate">
                <button type="submit" class="inv-btn sm">Dupliquer</button>
            </form>
            <form method="post" style="margin:0" onsubmit="return confirm('Supprimer définitivement cette analyse ?');">
                <input type="hidden" name="_csrf_token" value="<?= $h(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete">
                <button type="submit" class="inv-btn sm danger">Supprimer</button>
            </form>
        </div>
    </div>

</div>
<script src="<?= $h(function_exists('asset_url') ? asset_url('/investisseur/assets/investisseur.js') : '/investisseur/assets/investisseur.js') ?>"></script>
<?php require_once __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
