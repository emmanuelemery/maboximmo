<?php
declare(strict_types=1);
/**
 * investisseur/comparaison.php — Comparaison (2-5 biens) + Analyse de groupe (N biens)
 *
 * Modes :
 *   - ?mode=compare&ids=1,5,12  → vue terme-à-terme (radar + tableau best/worst), limit 5
 *   - ?mode=groupe&ids=1,5,12,20,... → vue portefeuille consolidé (KPI totaux + répartitions)
 *   - ?mode=groupe&typologie=commercial&ville=... → même chose mais depuis filtres (pas de sélection directe)
 *   - sans ids ni filtre → interface de sélection
 *
 * La bascule compare → groupe est automatique quand ids > 5.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

require_once __DIR__ . '/../inc/investisseur_helpers.php';

$pdo = $GLOBALS['pdo'];

$mode = (string)($_GET['mode'] ?? 'compare');
if (!in_array($mode, ['compare','groupe'], true)) $mode = 'compare';

// ── Sélection : soit ids explicites, soit filtres (=> on liste) ──
$ids = [];
if (!empty($_GET['ids'])) {
    $ids = array_values(array_filter(array_map('intval', explode(',', (string)$_GET['ids'])), fn($v) => $v > 0));
}

$rows = [];
$sourceLabel = '';

if (!empty($ids)) {
    $rows = inv_load_many($pdo, $ids);
    $sourceLabel = 'Sélection : ' . count($rows) . ' bien' . (count($rows) > 1 ? 's' : '');
    // Bascule auto si > 5
    if (count($rows) > 5 && $mode === 'compare') $mode = 'groupe';
} else {
    // Mode par filtre (si au moins un filtre)
    $filters = [
        'statut'    => trim((string)($_GET['statut']    ?? '')),
        'typologie' => trim((string)($_GET['typologie'] ?? '')),
        'min_score' => $_GET['min_score'] ?? '',
        'q'         => trim((string)($_GET['q']         ?? '')),
    ];
    $active = array_filter($filters, fn($v) => $v !== '' && $v !== null);
    if (!empty($active)) {
        $rows = inv_list($pdo, $filters, 500);
        $mode = 'groupe';
        $parts = [];
        if (!empty($filters['typologie'])) { $typ = inv_typologies()[$filters['typologie']][0] ?? $filters['typologie']; $parts[] = $typ; }
        if (!empty($filters['statut']))    $parts[] = $filters['statut'];
        if (!empty($filters['min_score'])) $parts[] = 'score ≥ ' . $filters['min_score'];
        if (!empty($filters['q']))         $parts[] = '"' . $filters['q'] . '"';
        $sourceLabel = 'Filtre : ' . (empty($parts) ? 'portefeuille complet' : implode(' · ', $parts)) . ' (' . count($rows) . ' biens)';
    }
}

// Pour l'interface de sélection
$available = inv_list($pdo, [], 300);

$pageTitle     = $mode === 'groupe' ? 'Analyse de groupe' : 'Comparaison';
$pageSubtitle  = 'Ma Box Bailleur › Investisseur › ' . ($mode === 'groupe' ? 'Groupe' : 'Comparer');
$layoutSidebar = 'sidebar_bailleur';
$extraCss      = '<link rel="stylesheet" href="' . (function_exists('asset_url') ? asset_url('/investisseur/assets/investisseur.css') : '/investisseur/assets/investisseur.css') . '">';
$bodyAttr      = 'class="inv-body"';
require_once __DIR__ . '/../inc/agency_layout_top.php';

$u = function_exists('app_url') ? fn($p) => app_url($p) : fn($p) => $p;
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

function cmp_mark(array $values, bool $higherIsBetter = true): array {
    if (empty($values)) return [];
    $vals = array_map('floatval', $values);
    $max = max($vals); $min = min($vals);
    $out = [];
    foreach ($values as $k => $v) {
        $cls = '';
        if ($max !== $min) {
            if ($higherIsBetter)  $cls = ($v == $max) ? 'best' : (($v == $min) ? 'worst' : '');
            else                  $cls = ($v == $min) ? 'best' : (($v == $max) ? 'worst' : '');
        }
        $out[$k] = $cls;
    }
    return $out;
}
?>
<div class="inv-wrap">

    <div class="inv-header">
        <h1><?= $h($pageTitle) ?></h1>
        <?php if ($sourceLabel): ?>
            <span style="font-family:'DM Mono',monospace; font-size:11px; color:#9a9690; letter-spacing:.08em; text-transform:uppercase;"><?= $h($sourceLabel) ?></span>
        <?php endif; ?>
        <div style="flex:1"></div>
        <div class="inv-quickbar">
            <a href="<?= $h($u('/investisseur/')) ?>">☰ Liste</a>
        </div>
    </div>

    <!-- Sélecteur toujours visible (pour affiner / rebasculer) -->
    <div class="inv-paper">
        <h2>Choisir les analyses à comparer / analyser</h2>
        <p style="font-size:12.5px; color:#5a5a55; margin:0 0 14px;">
            Cochez 2 à 5 biens pour une <strong>comparaison détaillée</strong>, ou plus de 5 pour passer en <strong>analyse de groupe consolidée</strong>.
            Vous pouvez aussi arriver ici via un filtre depuis la liste (bouton « Analyser le filtre »).
        </p>
        <form method="get" action="" id="cmp-form">
            <input type="hidden" name="mode" id="cmp-mode" value="<?= $h($mode) ?>">
            <div class="inv-form-grid" style="grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));">
                <?php foreach ($available as $a):
                    $checked = in_array((int)$a['id'], $ids, true);
                    $sgA = (int)$a['score_global'];
                    $clA = inv_score_color($sgA);
                ?>
                <label style="display:flex; gap:10px; align-items:center; padding:10px 14px; background:var(--inv-bg); border-radius:10px; cursor:pointer; border-left:4px solid <?= $h($clA) ?>;">
                    <input type="checkbox" name="ids_sel[]" value="<?= (int)$a['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                    <span style="flex:1">
                        <strong style="display:block; color:#24324a; font-size:13.5px"><?= $h($a['titre_analyse']) ?></strong>
                        <span style="font-family:'DM Mono',monospace; font-size:10px; color:#9a9690; text-transform:uppercase; letter-spacing:0.08em">
                            <?= $h($a['ville'] ?: '-') ?>
                            <?php if ($a['type_bien']): ?> · <?= $h($a['type_bien']) ?><?php endif; ?>
                            · <?= number_format((float)$a['rendement_net'], 1, ',', ' ') ?>% · score <?= $sgA ?>
                        </span>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:16px; text-align:right;">
                <input type="hidden" name="ids" value="<?= $h(implode(',', $ids)) ?>">
                <button type="submit" class="inv-btn primary">Lancer l'analyse</button>
            </div>
        </form>
    </div>

    <?php if (count($rows) < 2): ?>
        <?php if (!empty($rows)): ?>
            <div class="inv-empty">
                <h3>Sélectionnez au moins 2 analyses</h3>
                <p>La comparaison / analyse de groupe nécessite 2 biens minimum pour être pertinente.</p>
            </div>
        <?php endif; ?>
    <?php elseif ($mode === 'compare'): ?>

        <?php
        // ═══════════ MODE COMPARAISON (2 à 5 biens) ═══════════
        $radarLabels = ['Attractivité','Sécurité','Emplacement','Tension','Potentiel','Rdt net'];
        $radarSets = [];
        foreach ($rows as $r) {
            $radarSets[] = [
                'label' => $r['titre_analyse'],
                'values' => [
                    (int)$r['score_attractivite'],
                    (int)$r['score_risque'],
                    min(100, ((int)$r['qualite_emplacement']) * 20),
                    min(100, ((int)$r['tension_locative']) * 20),
                    min(100, ((int)$r['potentiel_valorisation']) * 20),
                    min(100, (int)round((float)$r['rendement_net'] * 10)),
                ]
            ];
        }
        $palette = ['rgba(36,50,74,0.75)','rgba(79,122,58,0.75)','rgba(184,68,58,0.75)','rgba(217,122,58,0.75)','rgba(72,120,166,0.75)'];

        $mScores = cmp_mark(array_column($rows, 'score_global'));
        $mRdtN   = cmp_mark(array_column($rows, 'rendement_net'));
        $mRdtB   = cmp_mark(array_column($rows, 'rendement_brut'));
        $mCash   = cmp_mark(array_column($rows, 'cashflow_mensuel'));
        $mPrix   = cmp_mark(array_column($rows, 'prix_achat'), false);
        $mCout   = cmp_mark(array_column($rows, 'cout_total'), false);
        $mMens   = cmp_mark(array_column($rows, 'mensualite_credit'), false);
        $mProj   = cmp_mark(array_column($rows, 'projection_10_ans'));
        $mPm2    = cmp_mark(array_column($rows, 'prix_m2'), false);
        $mMult   = cmp_mark(array_column($rows, 'multiple_loyer'), false);
        ?>

        <div class="inv-paper">
            <h2>Profil comparé (radar)</h2>
            <div class="inv-chart-grid">
                <div class="inv-chart-card" style="max-width:none; min-width:280px;">
                    <div class="inv-chart-wrap wide" style="max-width:400px; aspect-ratio:1/1;" title="Cliquer pour agrandir">
                        <canvas id="inv-radar-cmp"></canvas>
                    </div>
                    <div class="inv-chart-legend">
                        <?php foreach ($rows as $i => $r): ?>
                            <span class="dot" style="background: <?= $h($palette[$i % count($palette)]) ?>"></span><?= $h($r['titre_analyse']) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <script>
        (function () {
            function drawRadar() {
                if (!window.Chart) { setTimeout(drawRadar, 200); return; }
                const ctx = document.getElementById('inv-radar-cmp');
                if (!ctx) return;
                new Chart(ctx.getContext('2d'), {
                    type: 'radar',
                    data: {
                        labels: <?= json_encode($radarLabels) ?>,
                        datasets: [
                            <?php foreach ($radarSets as $i => $d): ?>
                            {
                                label: <?= json_encode($d['label']) ?>,
                                data: <?= json_encode($d['values']) ?>,
                                backgroundColor: '<?= $palette[$i % count($palette)] ?>'.replace('0.75', '0.20'),
                                borderColor: '<?= $palette[$i % count($palette)] ?>',
                                borderWidth: 2,
                                pointBackgroundColor: '<?= $palette[$i % count($palette)] ?>',
                                pointRadius: 3
                            },
                            <?php endforeach; ?>
                        ]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } },
                        scales: { r: {
                            suggestedMin: 0, suggestedMax: 100,
                            angleLines: { color: '#e6e1d7' },
                            grid: { color: '#e6e1d7' },
                            pointLabels: { font: { size: 11 }, color: '#5a5a55' },
                            ticks: { display: false }
                        } }
                    }
                });
            }
            drawRadar();
        })();
        </script>

        <div class="inv-paper inv-compare">
            <h2>Détail ligne à ligne</h2>
            <table>
                <thead>
                    <tr><th></th><?php foreach ($rows as $r): ?><th><?= $h($r['titre_analyse']) ?></th><?php endforeach; ?></tr>
                </thead>
                <tbody>
                    <tr><td class="label">Ville</td><?php foreach ($rows as $r): ?><td><?= $h($r['ville'] ?: '—') ?></td><?php endforeach; ?></tr>
                    <tr><td class="label">Type</td><?php foreach ($rows as $r): ?><td><?= $h($r['type_bien'] ?: '—') ?></td><?php endforeach; ?></tr>
                    <tr><td class="label">Surface</td><?php foreach ($rows as $r): ?><td><?= $r['surface'] ? number_format((float)$r['surface'], 0, ',', ' ') . ' m²' : '—' ?></td><?php endforeach; ?></tr>
                    <tr><td class="label">Locataire</td><?php foreach ($rows as $r): ?><td><?= $h($r['locataire_nom'] ?: '—') ?></td><?php endforeach; ?></tr>
                    <tr><td class="label">Fin de bail</td><?php foreach ($rows as $r): ?><td><?= !empty($r['bail_fin']) && $r['bail_fin'] !== '0000-00-00' ? $h(date('m/Y', strtotime((string)$r['bail_fin']))) : '—' ?></td><?php endforeach; ?></tr>
                    <tr><td class="label">Prix d'achat</td><?php foreach ($rows as $i=>$r): ?><td class="<?= $h($mPrix[$i]) ?>"><?= number_format((float)$r['prix_achat'], 0, ',', ' ') ?> €</td><?php endforeach; ?></tr>
                    <tr><td class="label">Prix / m²</td><?php foreach ($rows as $i=>$r): ?><td class="<?= $h($mPm2[$i]) ?>"><?= (float)$r['prix_m2'] > 0 ? number_format((float)$r['prix_m2'], 0, ',', ' ') . ' €' : '—' ?></td><?php endforeach; ?></tr>
                    <tr><td class="label">Coût total</td><?php foreach ($rows as $i=>$r): ?><td class="<?= $h($mCout[$i]) ?>"><?= number_format((float)$r['cout_total'], 0, ',', ' ') ?> €</td><?php endforeach; ?></tr>
                    <tr><td class="label">Mensualité crédit</td><?php foreach ($rows as $i=>$r): ?><td class="<?= $h($mMens[$i]) ?>"><?= number_format((float)$r['mensualite_credit'], 0, ',', ' ') ?> €</td><?php endforeach; ?></tr>
                    <tr><td class="label">Rdt brut</td><?php foreach ($rows as $i=>$r): ?><td class="<?= $h($mRdtB[$i]) ?>"><?= number_format((float)$r['rendement_brut'], 2, ',', ' ') ?> %</td><?php endforeach; ?></tr>
                    <tr><td class="label">Rdt net</td><?php foreach ($rows as $i=>$r): ?><td class="<?= $h($mRdtN[$i]) ?>"><?= number_format((float)$r['rendement_net'], 2, ',', ' ') ?> %</td><?php endforeach; ?></tr>
                    <tr><td class="label">Multiple loyer</td><?php foreach ($rows as $i=>$r): ?><td class="<?= $h($mMult[$i]) ?>"><?= (float)$r['multiple_loyer'] > 0 ? number_format((float)$r['multiple_loyer'], 1, ',', ' ') . '×' : '—' ?></td><?php endforeach; ?></tr>
                    <tr><td class="label">Cashflow / mois</td><?php foreach ($rows as $i=>$r): ?><td class="<?= $h($mCash[$i]) ?>"><?= number_format((float)$r['cashflow_mensuel'], 0, ',', ' ') ?> €</td><?php endforeach; ?></tr>
                    <tr><td class="label">Projection 10 ans</td><?php foreach ($rows as $i=>$r): ?><td class="<?= $h($mProj[$i]) ?>"><?= number_format((float)$r['projection_10_ans'], 0, ',', ' ') ?> €</td><?php endforeach; ?></tr>
                    <tr><td class="label">Score global</td><?php foreach ($rows as $i=>$r): ?><td class="<?= $h($mScores[$i]) ?>" style="font-size:16px"><?= (int)$r['score_global'] ?>/100</td><?php endforeach; ?></tr>
                    <tr><td class="label">Reco</td><?php foreach ($rows as $r): ?><td><strong><?= $h($r['reco_finale']) ?></strong></td><?php endforeach; ?></tr>
                    <tr><td class="label">Action</td><?php foreach ($rows as $r): ?><td><a href="<?= $h($u('/investisseur/detail.php?id=' . (int)$r['id'])) ?>" class="inv-btn sm">Détail →</a></td><?php endforeach; ?></tr>
                </tbody>
            </table>
        </div>

    <?php else: ?>

        <?php
        // ═══════════ MODE ANALYSE DE GROUPE (portefeuille consolidé) ═══════════
        $sumPrix = 0; $sumCout = 0; $sumLoyerAn = 0; $sumChargesAn = 0;
        $sumSurface = 0; $sumTF = 0; $sumHonoVente = 0; $nPV = 0;
        $rdNetAcc = 0; $rdNetN = 0; $rdBrutAcc = 0; $rdBrutN = 0;
        $scoreAcc = 0; $scoreN = 0;
        $repTypo = []; $repVille = []; $repLoc = [];
        foreach ($rows as $r) {
            $sumPrix      += (float)$r['prix_achat'];
            $sumCout      += (float)$r['cout_total'];
            $sumLoyerAn   += (float)$r['loyer_estime'] * 12;
            $sumChargesAn += (float)$r['charges_annuelles'];
            $sumSurface   += (float)$r['surface'];
            $sumTF        += (float)$r['taxe_fonciere'];
            $sumHonoVente += (float)($r['honoraires_vente'] ?? 0);
            if (!empty($r['photovoltaique'])) $nPV++;

            if ((float)$r['rendement_net']  > 0) { $rdNetAcc  += (float)$r['rendement_net'];  $rdNetN++; }
            if ((float)$r['rendement_brut'] > 0) { $rdBrutAcc += (float)$r['rendement_brut']; $rdBrutN++; }
            if ((int)$r['score_global'] > 0)     { $scoreAcc  += (int)$r['score_global'];    $scoreN++; }

            $tk = inv_typologie_of($r['type_bien']);
            $tkLbl = inv_typologies()[$tk][0] ?? 'Autre';
            $repTypo[$tkLbl] = ($repTypo[$tkLbl] ?? 0) + 1;

            $vkey = $r['ville'] ?: '—';
            $repVille[$vkey] = ($repVille[$vkey] ?? 0) + 1;

            if (!empty($r['locataire_nom'])) {
                $repLoc[$r['locataire_nom']] = ($repLoc[$r['locataire_nom']] ?? 0) + 1;
            }
        }
        arsort($repVille); $repVille = array_slice($repVille, 0, 10, true);
        arsort($repLoc);   $repLoc   = array_slice($repLoc, 0, 10, true);

        $rdNetMoy  = $rdNetN  ? round($rdNetAcc / $rdNetN, 2) : 0;
        $rdBrutMoy = $rdBrutN ? round($rdBrutAcc / $rdBrutN, 2) : 0;
        $scoreMoy  = $scoreN  ? (int)round($scoreAcc / $scoreN) : 0;
        $prixM2Moy = $sumSurface > 0 ? round($sumPrix / $sumSurface, 0) : 0;
        $multMoy   = $sumLoyerAn > 0 ? round($sumPrix / $sumLoyerAn, 2) : 0;
        // Rdt net pondéré par la valeur (plus représentatif)
        $rdNetPondere = 0;
        if ($sumPrix > 0) {
            $rdNetPonderee = 0;
            foreach ($rows as $r) $rdNetPonderee += (float)$r['rendement_net'] * (float)$r['prix_achat'];
            $rdNetPondere = round($rdNetPonderee / $sumPrix, 2);
        }

        // Top / flop
        $sorted = $rows;
        usort($sorted, fn($a, $b) => (int)$b['score_global'] <=> (int)$a['score_global']);
        $top3  = array_slice($sorted, 0, 3);
        $flop3 = array_slice(array_reverse($sorted), 0, 3);
        ?>

        <!-- KPI consolidés -->
        <div class="inv-paper">
            <h2>📊 Portefeuille consolidé (<?= count($rows) ?> biens)</h2>
            <div class="inv-kpis" style="flex-wrap:wrap; gap:10px;">
                <div class="inv-kpi"><span class="v"><?= number_format($sumPrix, 0, ',', ' ') ?></span><span class="l">Valeur totale €</span></div>
                <div class="inv-kpi"><span class="v"><?= number_format($sumLoyerAn, 0, ',', ' ') ?></span><span class="l">Loyers € / an</span></div>
                <div class="inv-kpi"><span class="v"><?= number_format($sumChargesAn, 0, ',', ' ') ?></span><span class="l">Charges € / an</span></div>
                <div class="inv-kpi"><span class="v"><?= $rdNetMoy > 0 ? number_format($rdNetMoy, 2, ',', ' ').'%' : '—' ?></span><span class="l">Rdt net moy.</span></div>
                <div class="inv-kpi"><span class="v"><?= $rdNetPondere > 0 ? number_format($rdNetPondere, 2, ',', ' ').'%' : '—' ?></span><span class="l">Rdt net pondéré</span></div>
                <div class="inv-kpi"><span class="v"><?= number_format($sumSurface, 0, ',', ' ') ?></span><span class="l">Surface m²</span></div>
                <div class="inv-kpi"><span class="v"><?= $prixM2Moy ? number_format($prixM2Moy, 0, ',', ' ') : '—' ?></span><span class="l">Prix m² moy.</span></div>
                <div class="inv-kpi"><span class="v"><?= $multMoy ? number_format($multMoy, 1, ',', ' ').'×' : '—' ?></span><span class="l">Multiple moy.</span></div>
                <div class="inv-kpi"><span class="v"><?= $scoreMoy ?: '—' ?></span><span class="l">Score moy.</span></div>
                <div class="inv-kpi"><span class="v"><?= number_format($sumTF, 0, ',', ' ') ?></span><span class="l">TF totale €</span></div>
                <div class="inv-kpi"><span class="v"><?= $nPV ?></span><span class="l">Photovoltaïque</span></div>
            </div>
        </div>

        <!-- Répartitions -->
        <div class="inv-paper">
            <h2>Répartition du portefeuille</h2>
            <div class="inv-chart-grid">
                <?php if (!empty($repTypo)): ?>
                <div class="inv-chart-card">
                    <h4>Par typologie</h4>
                    <div class="inv-chart-wrap" title="Cliquer pour agrandir">
                        <canvas data-inv-chart="doughnut"
                                data-title="Répartition par typologie"
                                data-labels='<?= $h(json_encode(array_keys($repTypo))) ?>'
                                data-values='<?= $h(json_encode(array_values($repTypo))) ?>'></canvas>
                    </div>
                    <div class="inv-chart-legend">Nombre de biens</div>
                </div>
                <?php endif; ?>
                <?php if (!empty($repVille)): ?>
                <div class="inv-chart-card">
                    <h4>Top villes</h4>
                    <div class="inv-chart-wrap wide" title="Cliquer pour agrandir">
                        <canvas data-inv-chart="bar"
                                data-title="Top villes (nb biens)"
                                data-labels='<?= $h(json_encode(array_keys($repVille))) ?>'
                                data-values='<?= $h(json_encode(array_values($repVille))) ?>'></canvas>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($repLoc)): ?>
                <div class="inv-chart-card">
                    <h4>Top locataires</h4>
                    <div class="inv-chart-wrap wide" title="Cliquer pour agrandir">
                        <canvas data-inv-chart="bar"
                                data-title="Top locataires"
                                data-labels='<?= $h(json_encode(array_keys($repLoc))) ?>'
                                data-values='<?= $h(json_encode(array_values($repLoc))) ?>'></canvas>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Top / Flop -->
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px;">
            <div class="inv-paper">
                <h2>🏆 Top 3 (score)</h2>
                <?php foreach ($top3 as $r):
                    $sg = (int)$r['score_global']; $cl = inv_score_color($sg);
                ?>
                <a href="<?= $h($u('/investisseur/detail.php?id=' . (int)$r['id'])) ?>" class="inv-card-list" style="--bar-color:<?= $h($cl) ?>; margin-bottom:8px; text-decoration:none;">
                    <div class="ic-title-block">
                        <div class="ic-title"><?= $h($r['titre_analyse']) ?></div>
                        <div class="ic-sub"><?= $h($r['ville']) ?> · <?= number_format((float)$r['rendement_net'], 1, ',', ' ') ?>% · <?= number_format((float)$r['prix_achat'], 0, ',', ' ') ?> €</div>
                    </div>
                    <div class="ic-score" style="background:<?= $h($cl) ?>"><span class="sv"><?= $sg ?></span><span class="sl">/100</span></div>
                </a>
                <?php endforeach; ?>
            </div>
            <div class="inv-paper">
                <h2>⚠ Bottom 3 (à arbitrer)</h2>
                <?php foreach ($flop3 as $r):
                    $sg = (int)$r['score_global']; $cl = inv_score_color($sg);
                ?>
                <a href="<?= $h($u('/investisseur/detail.php?id=' . (int)$r['id'])) ?>" class="inv-card-list" style="--bar-color:<?= $h($cl) ?>; margin-bottom:8px; text-decoration:none;">
                    <div class="ic-title-block">
                        <div class="ic-title"><?= $h($r['titre_analyse']) ?></div>
                        <div class="ic-sub"><?= $h($r['ville']) ?> · <?= number_format((float)$r['rendement_net'], 1, ',', ' ') ?>% · <?= number_format((float)$r['prix_achat'], 0, ',', ' ') ?> €</div>
                    </div>
                    <div class="ic-score" style="background:<?= $h($cl) ?>"><span class="sv"><?= $sg ?></span><span class="sl">/100</span></div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Tableau complet du groupe -->
        <div class="inv-paper inv-compare" style="margin-top:20px">
            <h2>Détail du groupe (<?= count($rows) ?> lignes)</h2>
            <table>
                <thead>
                    <tr>
                        <th style="text-align:left">Bien</th>
                        <th>Type</th>
                        <th>Ville</th>
                        <th>Surface</th>
                        <th>Prix</th>
                        <th>€/m²</th>
                        <th>Loyer/an</th>
                        <th>Rdt net</th>
                        <th>Mult.</th>
                        <th>Score</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $sg = (int)$r['score_global']; $cl = inv_score_color($sg);
                ?>
                    <tr>
                        <td class="label" style="font-size:12px;"><?= $h($r['titre_analyse']) ?></td>
                        <td><?= $h($r['type_bien'] ?: '—') ?></td>
                        <td><?= $h($r['ville'] ?: '—') ?></td>
                        <td><?= $r['surface'] ? number_format((float)$r['surface'], 0, ',', ' ') . ' m²' : '—' ?></td>
                        <td><?= number_format((float)$r['prix_achat'], 0, ',', ' ') ?> €</td>
                        <td><?= (float)$r['prix_m2'] > 0 ? number_format((float)$r['prix_m2'], 0, ',', ' ') : '—' ?></td>
                        <td><?= number_format((float)$r['loyer_estime'] * 12, 0, ',', ' ') ?> €</td>
                        <td><?= number_format((float)$r['rendement_net'], 2, ',', ' ') ?> %</td>
                        <td><?= (float)$r['multiple_loyer'] > 0 ? number_format((float)$r['multiple_loyer'], 1, ',', ' ') . '×' : '—' ?></td>
                        <td style="color:<?= $h($cl) ?>; font-weight:800"><?= $sg ?></td>
                        <td><a href="<?= $h($u('/investisseur/detail.php?id=' . (int)$r['id'])) ?>" class="inv-btn sm">→</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:var(--inv-bg); font-weight:700;">
                        <td class="label">TOTAL (<?= count($rows) ?>)</td>
                        <td>—</td>
                        <td>—</td>
                        <td><?= number_format($sumSurface, 0, ',', ' ') ?> m²</td>
                        <td><?= number_format($sumPrix, 0, ',', ' ') ?> €</td>
                        <td><?= $prixM2Moy ? number_format($prixM2Moy, 0, ',', ' ') : '—' ?></td>
                        <td><?= number_format($sumLoyerAn, 0, ',', ' ') ?> €</td>
                        <td><?= $rdNetPondere ? number_format($rdNetPondere, 2, ',', ' ') . ' %' : '—' ?></td>
                        <td><?= $multMoy ? number_format($multMoy, 1, ',', ' ') . '×' : '—' ?></td>
                        <td><?= $scoreMoy ?: '—' ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

    <?php endif; ?>

</div>

<script src="<?= $h(function_exists('asset_url') ? asset_url('/investisseur/assets/investisseur.js') : '/investisseur/assets/investisseur.js') ?>"></script>
<script>
// Synchronisation du champ ids caché à partir des checkboxes + bascule mode auto
(function () {
    const boxes = document.querySelectorAll('input[name="ids_sel[]"]');
    const hidden = document.querySelector('input[name="ids"]');
    const modeInput = document.getElementById('cmp-mode');
    function update() {
        const ids = Array.from(boxes).filter(b => b.checked).map(b => b.value);
        if (hidden) hidden.value = ids.join(',');
        if (modeInput) modeInput.value = ids.length > 5 ? 'groupe' : 'compare';
    }
    boxes.forEach(b => b.addEventListener('change', update));
})();
</script>
<?php require_once __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
