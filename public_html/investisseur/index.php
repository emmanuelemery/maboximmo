<?php
declare(strict_types=1);
/**
 * investisseur/index.php — Dashboard + Liste + Filtres typologie + Multi-sélection
 *
 * Permet :
 *   - KPI consolidés scope user
 *   - Filtre par statut / typologie / score mini / texte libre
 *   - Sélection multi-biens (checkboxes) → bouton "Analyser la sélection"
 *   - Bouton "Analyser le filtre actif" → bascule dans comparaison.php en mode portefeuille
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

require_once __DIR__ . '/../inc/investisseur_helpers.php';

$pdo = $GLOBALS['pdo'];

$filters = [
    'statut'    => trim((string)($_GET['statut']    ?? '')),
    'typologie' => trim((string)($_GET['typologie'] ?? '')),
    'min_score' => $_GET['min_score'] ?? '',
    'q'         => trim((string)($_GET['q']         ?? '')),
];
$rows = inv_list($pdo, $filters, 300);

// Stats scope (non filtrées) + stats sous-ensemble filtré
$scope = inv_scope_where();
$sqlStats = "SELECT
    COUNT(*) AS nb_total,
    SUM(CASE WHEN statut='brouillon' THEN 1 ELSE 0 END) AS nb_brouillon,
    SUM(CASE WHEN statut='finalisee' THEN 1 ELSE 0 END) AS nb_finalisee,
    SUM(CASE WHEN statut='archivee'  THEN 1 ELSE 0 END) AS nb_archivee,
    AVG(NULLIF(score_global,0))  AS score_moy,
    AVG(NULLIF(rendement_net,0)) AS rdn_moy
  FROM investisseur_analyses
  WHERE {$scope['sql']}";
$st = $pdo->prepare($sqlStats);
foreach ($scope['params'] as $k => $v) $st->bindValue($k, $v);
$st->execute();
$stats = $st->fetch(PDO::FETCH_ASSOC) ?: [];

// KPI sous-ensemble filtré (pour le bandeau "vue filtre")
$sumPrix = 0.0; $sumLoyerAn = 0.0; $sumSurface = 0.0; $sumTF = 0.0;
$rdNetAcc = 0.0; $rdNetN = 0;
foreach ($rows as $r) {
    $sumPrix    += (float)$r['prix_achat'];
    $sumLoyerAn += (float)$r['loyer_estime'] * 12;
    $sumSurface += (float)$r['surface'];
    $sumTF      += (float)$r['taxe_fonciere'];
    if ((float)$r['rendement_net'] > 0) { $rdNetAcc += (float)$r['rendement_net']; $rdNetN++; }
}
$rdNetMoy = $rdNetN ? round($rdNetAcc / $rdNetN, 2) : 0.0;
$prixM2Moy = $sumSurface > 0 ? round($sumPrix / $sumSurface, 0) : 0;
$multMoy = $sumLoyerAn > 0 ? round($sumPrix / $sumLoyerAn, 2) : 0;

$pageTitle     = 'Analyse Investisseur';
$pageSubtitle  = 'Ma Box Bailleur › Investisseur';
$layoutSidebar = 'sidebar_bailleur';
$extraCss      = '<link rel="stylesheet" href="' . (function_exists('asset_url') ? asset_url('/investisseur/assets/investisseur.css') : '/investisseur/assets/investisseur.css') . '">';
$bodyAttr      = 'class="inv-body"';
require_once __DIR__ . '/../inc/agency_layout_top.php';

$u = function_exists('app_url') ? fn($p) => app_url($p) : fn($p) => $p;
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$typos = inv_typologies();
$isFilterActive = ($filters['statut']!=='' || $filters['typologie']!=='' || $filters['min_score']!=='' || $filters['q']!=='');

// Query string courant sans 'ids' (pour reconstruire URL comparaison via filtre)
$qsFilter = array_filter([
    'statut'    => $filters['statut'],
    'typologie' => $filters['typologie'],
    'min_score' => $filters['min_score'],
    'q'         => $filters['q'],
], fn($v) => $v !== '' && $v !== null);
?>
<div class="inv-wrap">

    <div class="inv-header">
        <h1>Analyse Investisseur</h1>
        <div class="inv-kpis">
            <div class="inv-kpi"><span class="v"><?= (int)($stats['nb_total'] ?? 0) ?></span><span class="l">Analyses</span></div>
            <div class="inv-kpi"><span class="v"><?= (int)($stats['nb_brouillon'] ?? 0) ?></span><span class="l">Brouillon</span></div>
            <div class="inv-kpi"><span class="v"><?= (int)($stats['nb_finalisee'] ?? 0) ?></span><span class="l">Finalisées</span></div>
            <div class="inv-kpi"><span class="v"><?= isset($stats['score_moy']) && $stats['score_moy']!==null ? (int)round((float)$stats['score_moy']) : '—' ?></span><span class="l">Score moy.</span></div>
            <div class="inv-kpi"><span class="v"><?= isset($stats['rdn_moy']) && $stats['rdn_moy']!==null ? number_format((float)$stats['rdn_moy'], 1, ',', ' ').'%' : '—' ?></span><span class="l">Rdt net moy.</span></div>
        </div>
        <div style="flex:1"></div>
        <div class="inv-quickbar">
            <a href="<?= $h($u('/investisseur/prix_priorites.php')) ?>" class="primary" style="background:#b4443a;">🎯 Prix &amp; priorités</a>
            <a href="<?= $h($u('/investisseur/valorisation.php')) ?>" class="primary" style="background:#4f7a3a;">💰 Simulateur</a>
            <a href="<?= $h($u('/investisseur/nouvelle.php')) ?>">+ Nouvelle</a>
            <a href="<?= $h($u('/investisseur/comparaison.php')) ?>">⚖ Comparer</a>
        </div>
    </div>

    <!-- Filtres -->
    <form method="get" id="inv-filter-form">
        <div class="inv-pills">
            <span class="inv-pill-label">Typologie</span>
            <a class="inv-pill <?= $filters['typologie']==='' ? 'active' : '' ?>" href="?<?= $h(http_build_query(array_merge($_GET, ['typologie' => '']))) ?>">Toutes</a>
            <?php foreach ($typos as $tk => $td): ?>
                <a class="inv-pill <?= $filters['typologie']===$tk ? 'active' : '' ?>"
                   href="?<?= $h(http_build_query(array_merge($_GET, ['typologie' => $tk]))) ?>"><?= $h($td[0]) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="inv-pills">
            <span class="inv-pill-label">Statut</span>
            <a class="inv-pill <?= $filters['statut']===''           ? 'active' : '' ?>" href="?<?= $h(http_build_query(array_merge($_GET, ['statut' => '']))) ?>">Tous</a>
            <a class="inv-pill <?= $filters['statut']==='brouillon'  ? 'active' : '' ?>" href="?<?= $h(http_build_query(array_merge($_GET, ['statut' => 'brouillon']))) ?>">Brouillon</a>
            <a class="inv-pill <?= $filters['statut']==='finalisee'  ? 'active' : '' ?>" href="?<?= $h(http_build_query(array_merge($_GET, ['statut' => 'finalisee']))) ?>">Finalisées</a>
            <a class="inv-pill <?= $filters['statut']==='archivee'   ? 'active' : '' ?>" href="?<?= $h(http_build_query(array_merge($_GET, ['statut' => 'archivee']))) ?>">Archivées</a>

            <span class="inv-pill-label" style="margin-left:16px">Score ≥</span>
            <?php foreach ([['',''], ['30','30+'], ['50','50+'], ['65','65+'], ['75','75+']] as $s): ?>
                <a class="inv-pill <?= (string)$filters['min_score']===(string)$s[0] ? 'active' : '' ?>"
                   href="?<?= $h(http_build_query(array_merge($_GET, ['min_score' => $s[0]]))) ?>"><?= $s[1] ?: 'Tous' ?></a>
            <?php endforeach; ?>

            <div style="flex:1"></div>
            <input type="text" name="q" placeholder="Titre, ville, locataire, référence…"
                   value="<?= $h($filters['q']) ?>"
                   style="font-family:'Sora',sans-serif; font-size:13px; padding:8px 14px; border-radius:999px; border:1px solid #e6e1d7; background:#fff; min-width:220px;">
            <button type="submit" class="inv-btn sm">Filtrer</button>
            <?php if ($isFilterActive): ?>
                <a href="<?= $h($u('/investisseur/')) ?>" class="inv-btn sm ghost">Reset</a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Bandeau consolidé du filtre actif / sélection -->
    <?php if (!empty($rows)): ?>
    <div class="inv-paper" style="padding:14px 20px; margin-bottom:14px; display:flex; align-items:center; gap:22px; flex-wrap:wrap; border-left:4px solid #24324a">
        <div>
            <div style="font-family:'DM Mono',monospace; font-size:10px; letter-spacing:.12em; text-transform:uppercase; color:#9a9690;">
                <?= $isFilterActive ? 'Vue filtrée' : 'Portefeuille complet' ?>
            </div>
            <div style="font-family:'Sora',sans-serif; font-size:18px; font-weight:700; color:#24324a;"><?= count($rows) ?> bien<?= count($rows) > 1 ? 's' : '' ?></div>
        </div>
        <div class="inv-kpis">
            <div class="inv-kpi"><span class="v"><?= number_format($sumPrix, 0, ',', ' ') ?></span><span class="l">Valeur € totale</span></div>
            <div class="inv-kpi"><span class="v"><?= number_format($sumLoyerAn, 0, ',', ' ') ?></span><span class="l">Loyers € / an</span></div>
            <div class="inv-kpi"><span class="v"><?= $rdNetMoy > 0 ? number_format($rdNetMoy, 2, ',', ' ').'%' : '—' ?></span><span class="l">Rdt net moy.</span></div>
            <div class="inv-kpi"><span class="v"><?= number_format($sumSurface, 0, ',', ' ') ?></span><span class="l">Surface m²</span></div>
            <div class="inv-kpi"><span class="v"><?= $prixM2Moy ? number_format($prixM2Moy, 0, ',', ' ') : '—' ?></span><span class="l">Prix m² moy.</span></div>
            <div class="inv-kpi"><span class="v"><?= $multMoy ? number_format($multMoy, 1, ',', ' ').'×' : '—' ?></span><span class="l">Multiple moy.</span></div>
            <div class="inv-kpi"><span class="v"><?= number_format($sumTF, 0, ',', ' ') ?></span><span class="l">Taxe fonc. €</span></div>
        </div>
        <div style="flex:1"></div>
        <div style="display:flex; gap:8px;">
            <button type="button" class="inv-btn" id="inv-analyse-selection" disabled>⚖ Analyser la sélection <span id="inv-sel-count" style="opacity:.6"></span></button>
            <?php if ($isFilterActive): ?>
                <a href="<?= $h($u('/investisseur/comparaison.php?mode=groupe&' . http_build_query($qsFilter))) ?>" class="inv-btn primary">📊 Analyser le filtre (<?= count($rows) ?>)</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="inv-sep">Analyses (<?= count($rows) ?>)</div>

    <?php if (empty($rows)): ?>
        <div class="inv-empty">
            <h3>Aucune analyse</h3>
            <p>Lancez votre première étude d'investissement, ou importez un bien déjà en base (CRG + arbitrage).</p>
            <div style="margin-top:20px; display:flex; gap:10px; justify-content:center;">
                <a href="<?= $h($u('/investisseur/nouvelle.php')) ?>" class="inv-btn primary">+ Nouvelle analyse</a>
                <a href="<?= $h($u('/investisseur/nouvelle.php?from_crg=1')) ?>" class="inv-btn">⇡ Depuis bien existant</a>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($rows as $row):
            $sg = (int)$row['score_global'];
            $color = inv_score_color($sg);
            $lbl = inv_score_libelle($sg);
            $href = $u('/investisseur/detail.php?id=' . (int)$row['id']);
            $prixM2 = (float)($row['prix_m2'] ?? 0) ?: ((float)$row['prix_achat'] && (float)$row['surface'] > 0 ? (float)$row['prix_achat'] / (float)$row['surface'] : 0);
            $mult   = (float)($row['multiple_loyer'] ?? 0) ?: (((float)$row['prix_achat'] > 0 && (float)$row['loyer_estime'] > 0) ? (float)$row['prix_achat'] / ((float)$row['loyer_estime'] * 12) : 0);
            $bailFin = !empty($row['bail_fin']) && $row['bail_fin'] !== '0000-00-00' ? date('m/Y', strtotime((string)$row['bail_fin'])) : '';
        ?>
        <div class="inv-card-list" style="--bar-color: <?= $h($color) ?>; position:relative;">
            <label style="position:absolute; top:10px; left:12px; z-index:2; cursor:pointer;" title="Sélectionner">
                <input type="checkbox" class="inv-sel" value="<?= (int)$row['id'] ?>" style="width:16px; height:16px; cursor:pointer;">
            </label>
            <a href="<?= $h($href) ?>" class="ic-title-block" style="text-decoration:none; padding-left:24px;">
                <div class="ic-title"><?= $h($row['titre_analyse']) ?></div>
                <div class="ic-sub">
                    <?= $h($row['type_bien'] ?: 'Bien') ?>
                    <?php if ($row['ville']): ?> · <?= $h($row['ville']) ?><?php endif; ?>
                    <?php if ($row['surface']): ?> · <?= number_format((float)$row['surface'], 0, ',', ' ') ?> m²<?php endif; ?>
                    <?php if (!empty($row['photovoltaique'])): ?> · <span style="color:#4f7a3a">☀ PV</span><?php endif; ?>
                    <?php if (!empty($row['locataire_nom'])): ?> · 🔑 <?= $h($row['locataire_nom']) ?><?php endif; ?>
                    <?php if ($bailFin): ?> · bail → <?= $h($bailFin) ?><?php endif; ?>
                </div>
            </a>
            <a href="<?= $h($href) ?>" class="ic-metric" style="text-decoration:none; color:inherit;">
                <span class="mv"><?= number_format((float)$row['rendement_net'], 1, ',', ' ') ?>%</span>
                <span class="ml">Rdt net</span>
            </a>
            <a href="<?= $h($href) ?>" class="ic-metric" style="text-decoration:none; color:inherit;">
                <span class="mv"><?= $prixM2 > 0 ? number_format($prixM2, 0, ',', ' ') : '—' ?></span>
                <span class="ml">€/m²</span>
            </a>
            <a href="<?= $h($href) ?>" class="ic-metric" style="text-decoration:none; color:inherit;">
                <span class="mv"><?= $mult > 0 ? number_format($mult, 1, ',', ' ').'×' : '—' ?></span>
                <span class="ml">Multiple</span>
            </a>
            <a href="<?= $h($href) ?>" class="ic-metric" style="text-decoration:none; color:inherit;">
                <span class="mv"><?= number_format((float)$row['prix_achat'], 0, ',', ' ') ?></span>
                <span class="ml">Prix €</span>
            </a>
            <a href="<?= $h($href) ?>" class="ic-score" style="background: <?= $h($color) ?>; text-decoration:none;">
                <span class="sv"><?= $sg ?></span>
                <span class="sl"><?= $h($lbl) ?></span>
            </a>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

</div>

<script>
(function () {
    const btn = document.getElementById('inv-analyse-selection');
    const cnt = document.getElementById('inv-sel-count');
    const boxes = document.querySelectorAll('input.inv-sel');
    function update() {
        const ids = Array.from(boxes).filter(b => b.checked).map(b => b.value);
        if (cnt) cnt.textContent = ids.length ? '(' + ids.length + ')' : '';
        if (btn) btn.disabled = ids.length < 2;
    }
    boxes.forEach(b => b.addEventListener('change', update));
    if (btn) {
        btn.addEventListener('click', () => {
            const ids = Array.from(boxes).filter(b => b.checked).map(b => b.value);
            if (ids.length < 2) return;
            const base = <?= json_encode($u('/investisseur/comparaison.php')) ?>;
            const mode = ids.length > 5 ? 'groupe' : 'compare';
            window.location.href = base + '?mode=' + mode + '&ids=' + ids.join(',');
        });
    }
})();
</script>
<script src="<?= $h(function_exists('asset_url') ? asset_url('/investisseur/assets/investisseur.js') : '/investisseur/assets/investisseur.js') ?>"></script>
<?php require_once __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
