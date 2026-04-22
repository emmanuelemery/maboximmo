<?php
declare(strict_types=1);
/**
 * investisseur/presentation_client.php — Feuille de présentation client A4
 *
 * Format imprimable (via @media print), orienté investisseur final.
 * Afficher : synthèse, KPI essentiels, argumentaire choisi (par défaut équilibré),
 * un mini-camembert coût + radar scoring.
 *
 * GET ?id=X (obligatoire), ?ton=prudent|equilibre|offensif (optionnel)
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/investisseur_helpers.php';
require_once __DIR__ . '/../inc/investisseur_partage.php';

$pdo = $GLOBALS['pdo'];
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); die('Identifiant manquant.'); }

$row = inv_load($pdo, $id);
if (!$row) { http_response_code(404); die('Analyse introuvable.'); }

$tonParam = (string)($_GET['ton'] ?? 'equilibre');
$tonParam = in_array($tonParam, ['prudent','equilibre','offensif'], true) ? $tonParam : 'equilibre';

$argumentaire = (string)$row['argumentaire_' . $tonParam];

$sg = (int)$row['score_global'];
$color = inv_score_color($sg);

$pageTitle     = 'Présentation client — ' . $row['titre_analyse'];
$pageSubtitle  = 'Ma Box Bailleur › Investisseur › Présentation';
$layoutSidebar = 'sidebar_bailleur';
$extraCss      = '<link rel="stylesheet" href="' . (function_exists('asset_url') ? asset_url('/investisseur/assets/investisseur.css') : '/investisseur/assets/investisseur.css') . '">';
$bodyAttr      = 'class="inv-body"';
require_once __DIR__ . '/../inc/agency_layout_top.php';

$u = function_exists('app_url') ? fn($p) => app_url($p) : fn($p) => $p;
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$coutParts = array_filter([
    'Prix'        => (float)($row['prix_achat']    ?? 0),
    'Notaire'     => (float)($row['frais_notaire'] ?? 0) ?: (float)($row['prix_achat'] ?? 0) * 0.08,
    'Agence'      => (float)($row['frais_agence']  ?? 0),
    'Travaux'     => (float)($row['travaux']       ?? 0),
    'Ameublement' => (float)($row['ameublement']   ?? 0),
], fn($v) => $v > 0);

$radarLabels = ['Attractivité','Sécurité','Emplacement','Tension','Potentiel','Rendement'];
$radarValues = [
    (int)$row['score_attractivite'],
    (int)$row['score_risque'],
    min(100, ((int)$row['qualite_emplacement']) * 20),
    min(100, ((int)$row['tension_locative']) * 20),
    min(100, ((int)$row['potentiel_valorisation']) * 20),
    min(100, (int)round((float)$row['rendement_net'] * 10)),
];
?>
<div class="inv-wrap">

    <div class="inv-header" style="no-print">
        <h1>Présentation client</h1>
        <div style="flex:1"></div>
        <div class="inv-quickbar">
            <a href="<?= $h($u('/investisseur/presentation_client.php?id=' . $id . '&ton=prudent')) ?>" class="<?= $tonParam==='prudent' ? 'primary' : '' ?>">Ton prudent</a>
            <a href="<?= $h($u('/investisseur/presentation_client.php?id=' . $id . '&ton=equilibre')) ?>" class="<?= $tonParam==='equilibre' ? 'primary' : '' ?>">Ton équilibré</a>
            <a href="<?= $h($u('/investisseur/presentation_client.php?id=' . $id . '&ton=offensif')) ?>" class="<?= $tonParam==='offensif' ? 'primary' : '' ?>">Ton offensif</a>
            <a href="<?= $h($u('/investisseur/partager.php?type=presentation&id_ref=' . $id . '&email=t.saby@groupe-sir.fr')) ?>" style="background:#4f7a3a; color:#fff;">📤 Partager au propriétaire</a>
            <a href="#" onclick="window.print(); return false;">🖨 Imprimer / PDF</a>
            <a href="<?= $h($u('/investisseur/detail.php?id=' . $id)) ?>">← Détail</a>
        </div>
    </div>

    <div class="pres-client">

        <h1><?= $h($row['titre_analyse']) ?></h1>
        <p class="pres-loc">
            <?= $h($row['type_bien'] ?: 'Bien') ?>
            <?php if ($row['ville']): ?> — <?= $h($row['ville']) ?><?php endif; ?>
            <?php if ($row['quartier']): ?> · <?= $h($row['quartier']) ?><?php endif; ?>
            <?php if ($row['surface']): ?> · <?= number_format((float)$row['surface'], 0, ',', ' ') ?> m²<?php endif; ?>
        </p>

        <!-- Synthèse -->
        <p class="pres-synth"><?= $h($row['synthese']) ?></p>

        <!-- Argumentaire choisi -->
        <div style="margin: 0 0 28px; padding: 16px 20px; background: #f3f6ed; border-left: 4px solid <?= $h($color) ?>; border-radius: 6px;">
            <h3 style="margin:0 0 8px; color:<?= $h($color) ?>; font-size:13px; text-transform:uppercase; letter-spacing:0.12em;">
                Argumentaire <?= $h($tonParam) ?>
            </h3>
            <p style="margin:0; font-size:14.5px; line-height:1.65; color:#333;"><?= $h($argumentaire) ?></p>
        </div>

        <!-- Deux colonnes : KPI + graphiques -->
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 28px; margin-top: 10px;">
            <div>
                <h3 style="font-size:13px; color:#24324a; text-transform:uppercase; letter-spacing:0.12em; margin:0 0 10px;">Indicateurs clés</h3>
                <table class="pres-kpi">
                    <tr><th>Prix d'achat</th><td><?= number_format((float)$row['prix_achat'], 0, ',', ' ') ?> €</td></tr>
                    <tr><th>Coût total</th><td><?= number_format((float)$row['cout_total'], 0, ',', ' ') ?> €</td></tr>
                    <tr><th>Loyer HC mensuel</th><td><?= number_format((float)$row['loyer_estime'], 0, ',', ' ') ?> € / mois</td></tr>
                    <tr><th>Rendement brut</th><td><?= number_format((float)$row['rendement_brut'], 2, ',', ' ') ?> %</td></tr>
                    <tr><th>Rendement net</th><td><strong><?= number_format((float)$row['rendement_net'], 2, ',', ' ') ?> %</strong></td></tr>
                    <tr><th>Cashflow mensuel</th><td><strong><?= number_format((float)$row['cashflow_mensuel'], 0, ',', ' ') ?> €</strong></td></tr>
                    <tr><th>Mensualité crédit</th><td><?= number_format((float)$row['mensualite_credit'], 0, ',', ' ') ?> €</td></tr>
                    <tr><th>Projection 10 ans</th><td><?= number_format((float)$row['projection_10_ans'], 0, ',', ' ') ?> €</td></tr>
                    <tr><th>Score global</th><td style="color:<?= $h($color) ?>; font-size:17px;"><strong><?= $sg ?>/100 — <?= $h(inv_score_libelle($sg)) ?></strong></td></tr>
                    <tr><th>Recommandation</th><td><strong><?= $h($row['reco_finale']) ?></strong></td></tr>
                </table>
            </div>
            <div>
                <h3 style="font-size:13px; color:#24324a; text-transform:uppercase; letter-spacing:0.12em; margin:0 0 10px;">Profil visuel</h3>
                <?php if (!empty($coutParts)): ?>
                <div class="inv-chart-card" style="background:#f9f7f2;">
                    <h4>Coût d'acquisition</h4>
                    <div class="inv-chart-wrap" title="Cliquer pour agrandir">
                        <canvas data-inv-chart="doughnut"
                                data-title="Répartition du coût"
                                data-labels='<?= $h(json_encode(array_keys($coutParts))) ?>'
                                data-values='<?= $h(json_encode(array_values($coutParts))) ?>'></canvas>
                    </div>
                </div>
                <?php endif; ?>
                <div class="inv-chart-card" style="background:#f9f7f2; margin-top:10px;">
                    <h4>Profil global</h4>
                    <div class="inv-chart-wrap wide" title="Cliquer pour agrandir">
                        <canvas data-inv-chart="radar"
                                data-title="Profil de l'investissement"
                                data-labels='<?= $h(json_encode($radarLabels)) ?>'
                                data-values='<?= $h(json_encode($radarValues)) ?>'></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div style="margin-top:36px; padding-top:18px; border-top:1px solid #eee; font-size:11px; color:#999;">
            Document préparé le <?= date('d/m/Y') ?>. Les chiffres présentés sont des estimations basées sur les hypothèses de saisie.
            Ils ne constituent pas une garantie de performance. À valider avec le client avant engagement.
        </div>
    </div>

</div>
<script src="<?= $h(function_exists('asset_url') ? asset_url('/investisseur/assets/investisseur.js') : '/investisseur/assets/investisseur.js') ?>"></script>
<?php require_once __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
