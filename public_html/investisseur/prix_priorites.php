<?php
declare(strict_types=1);
/**
 * investisseur/prix_priorites.php — Workbench d'arbitrage de réunion
 *
 * Table éditable inline : pour chaque analyse on peut saisir un NOUVEAU prix
 * de vente catalogue + une priorité 1-10. Les KPI (rdt brut/net, prix/m²,
 * multiple, cashflow, scénarios vente 5/10 ans) se recalculent en AJAX à
 * chaque modification du prix, SANS sauver — le save est explicite.
 *
 * Usage typique : en réunion avec le propriétaire, on teste plusieurs prix
 * pour chaque bien, on voit l'impact immédiat sur la rentabilité et le cash
 * net à 5/10 ans, puis on fige les valeurs retenues.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/investisseur_helpers.php';
require_once __DIR__ . '/../inc/investisseur_calculs.php';

$pdo = $GLOBALS['pdo'];

$filters = [
    'typologie' => trim((string)($_GET['typologie'] ?? '')),
    'prop'      => (int)($_GET['prop'] ?? 0),
    'q'         => trim((string)($_GET['q'] ?? '')),
];
$tri = trim((string)($_GET['tri'] ?? 'priorite')); // priorite | prix_desc | rdt_desc | titre

// Scope propriétaires du user (on réutilise user_proprietaires si proprio, sinon scope société)
$scope = inv_scope_where('a');
$where = [$scope['sql']];
$params = $scope['params'];
if ($filters['typologie'] !== '') {
    [$frag, $tParams] = inv_typologie_sql_or($filters['typologie'], 'typo');
    $where[] = str_replace('type_bien', 'a.type_bien', $frag);
    $params = array_merge($params, $tParams);
}
if ($filters['prop'] > 0) {
    $where[] = 'a.id_proprietaire = :f_prop';
    $params[':f_prop'] = $filters['prop'];
}
if ($filters['q'] !== '') {
    // Placeholders uniques (MariaDB/PDO refuse les réutilisations avec EMULATE_PREPARES=false)
    $where[] = '(a.titre_analyse LIKE :f_q1 OR a.ville LIKE :f_q2 OR a.reference_bien LIKE :f_q3 OR a.locataire_nom LIKE :f_q4)';
    $needle = '%' . $filters['q'] . '%';
    $params[':f_q1'] = $needle;
    $params[':f_q2'] = $needle;
    $params[':f_q3'] = $needle;
    $params[':f_q4'] = $needle;
}

$orderBy = match ($tri) {
    'prix_desc' => 'a.prix_vente_catalogue DESC',
    'rdt_desc'  => 'a.rendement_net DESC',
    'titre'     => 'a.titre_analyse ASC',
    default     => 'COALESCE(a.priorite_vente, 0) DESC, a.score_global DESC',
};

$sql = "SELECT a.id, a.titre_analyse, a.ville, a.type_bien, a.surface, a.loyer_estime,
               a.prix_vente_catalogue, a.prix_achat, a.priorite_vente,
               a.rendement_brut, a.rendement_net, a.prix_m2, a.multiple_loyer,
               a.cashflow_mensuel, a.score_global,
               a.id_proprietaire, p.societe AS prop_societe
        FROM investisseur_analyses a
        LEFT JOIN proprietaires p ON p.id = a.id_proprietaire
        WHERE " . implode(' AND ', $where) . "
        ORDER BY $orderBy
        LIMIT 500";
$st = $pdo->prepare($sql);
foreach ($params as $k => $v) $st->bindValue($k, $v);
$st->execute();
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Propriétaires pour le filtre
$propsDispos = [];
$propIds = array_values(array_unique(array_filter(array_map(fn($r) => (int)$r['id_proprietaire'], $rows))));
if (!empty($propIds)) {
    $in = implode(',', $propIds);
    $propsDispos = $pdo->query("SELECT id, COALESCE(societe, CONCAT(nom,' ',prenom)) AS label FROM proprietaires WHERE id IN ($in) ORDER BY societe")->fetchAll(PDO::FETCH_ASSOC);
}

// Stats
$totCatalogue = array_sum(array_column($rows, 'prix_vente_catalogue'));
$totLoyerAn = array_sum(array_map(fn($r) => (float)$r['loyer_estime'] * 12, $rows));
$nbPrior = count(array_filter($rows, fn($r) => (int)($r['priorite_vente'] ?? 0) > 0));

$pageTitle     = 'Prix & priorités';
$pageSubtitle  = 'Ma Box Bailleur › Investisseur › Édition rapide';
$layoutSidebar = 'sidebar_bailleur';
$extraCss      = '<link rel="stylesheet" href="' . (function_exists('asset_url') ? asset_url('/investisseur/assets/investisseur.css') : '/investisseur/assets/investisseur.css') . '">';
$bodyAttr      = 'class="inv-body"';
require_once __DIR__ . '/../inc/agency_layout_top.php';

$u = function_exists('app_url') ? fn($p) => app_url($p) : fn($p) => $p;
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmt = fn($v) => number_format((float)$v, 0, ',', ' ');
$typos = inv_typologies();
?>
<style>
.pp-table { width: 100%; border-collapse: separate; border-spacing: 0 6px; font-family: 'Sora', sans-serif; }
.pp-row { background: #fff; box-shadow: 2px 2px 6px rgba(196,192,186,.4); border-radius: 10px; }
.pp-row td { padding: 10px 12px; vertical-align: middle; border-top: 1px solid #f0ede6; border-bottom: 1px solid #f0ede6; }
.pp-row td:first-child { border-left: 4px solid var(--row-color, #c8c4be); border-top-left-radius: 10px; border-bottom-left-radius: 10px; }
.pp-row td:last-child  { border-top-right-radius: 10px; border-bottom-right-radius: 10px; }
.pp-row.dirty td:first-child { border-left-color: #d97a3a; }
.pp-row.saved td:first-child { border-left-color: #4f7a3a; }
.pp-row .titre { font-weight: 700; color: #24324a; font-size: 13px; line-height: 1.25; max-width: 260px; }
.pp-row .meta  { font-family: 'DM Mono', monospace; font-size: 10px; color: #9a9690; letter-spacing: .05em; text-transform: uppercase; margin-top: 2px; }
.pp-row .kpi-cell { text-align: center; font-size: 12.5px; font-family: 'Sora', sans-serif; }
.pp-row .kpi-cell .v { font-weight: 700; color: #24324a; font-size: 13.5px; }
.pp-row .kpi-cell .l { font-size: 9px; color: #9a9690; letter-spacing: .1em; text-transform: uppercase; display: block; margin-top: 1px; }
.pp-input-prix {
    width: 120px; padding: 6px 10px; border: 1px solid #e6e1d7;
    border-radius: 8px; font-family: 'Sora', sans-serif; font-size: 13px;
    font-weight: 700; color: #24324a; text-align: right;
}
.pp-input-prix:focus { border-color: #24324a; outline: none; box-shadow: 0 0 0 2px rgba(36,50,74,.12); }
.pp-input-prio {
    width: 46px; padding: 6px 4px; border: 1px solid #e6e1d7;
    border-radius: 8px; font-family: 'Sora', sans-serif; font-size: 13px;
    font-weight: 700; text-align: center; color: #b4443a;
}
.pp-input-prio:focus { border-color: #b4443a; outline: none; }
.pp-input-prio[data-p="0"] { color: #c8c4be; }
.pp-prio-wrap { position: relative; display: inline-flex; align-items: center; gap: 4px; }
.pp-prio-wrap::before { content: '⚡'; color: #b4443a; font-size: 12px; }
.pp-scen { display: flex; gap: 6px; flex-wrap: wrap; justify-content: center; }
.pp-scen .box { padding: 4px 8px; background: #f9f7f2; border-radius: 5px; min-width: 90px; text-align: center; }
.pp-scen .box.best { background: #eef3ea; border: 1.5px solid #4f7a3a; }
.pp-scen .box .lbl { font-size: 8.5px; color: #9a9690; letter-spacing: .08em; text-transform: uppercase; display: block; }
.pp-scen .box .val { font-family: 'Sora',sans-serif; font-weight: 700; font-size: 12.5px; color: #24324a; }
.pp-save-btn {
    padding: 5px 10px; border-radius: 6px; border: 1px solid #d4d7de;
    background: #fff; color: #555; font-size: 11px; font-weight: 600;
    cursor: pointer; font-family: 'Sora', sans-serif;
}
.pp-save-btn.dirty { border-color: #d97a3a; background: #fef3e6; color: #d97a3a; animation: pp-pulse 1.5s infinite; }
.pp-save-btn.saved { border-color: #4f7a3a; background: #eef3ea; color: #4f7a3a; }
@keyframes pp-pulse { 0%,100% { opacity: 1 } 50% { opacity: .5 } }
.pp-toolbar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
.pp-spin { color: #9a9690; font-style: italic; font-size: 10.5px; }
</style>

<div class="inv-wrap">

    <div class="inv-header">
        <h1>🎯 Prix & priorités — Workbench</h1>
        <div class="inv-kpis">
            <div class="inv-kpi"><span class="v"><?= count($rows) ?></span><span class="l">Biens</span></div>
            <div class="inv-kpi"><span class="v"><?= $fmt($totCatalogue) ?></span><span class="l">Valeur cat. €</span></div>
            <div class="inv-kpi"><span class="v"><?= $nbPrior ?></span><span class="l">Prioritaires</span></div>
            <div class="inv-kpi"><span class="v"><?= $fmt($totLoyerAn) ?></span><span class="l">Loyers/an €</span></div>
        </div>
        <div style="flex:1"></div>
        <div class="inv-quickbar">
            <a href="<?= $h($u('/investisseur/valorisation.php')) ?>">💰 Simulateur global</a>
            <a href="<?= $h($u('/investisseur/')) ?>">☰ Liste</a>
        </div>
    </div>

    <!-- Toolbar filtres -->
    <form method="get" class="inv-paper pp-toolbar">
        <span class="inv-pill-label">Tri</span>
        <?php foreach (['priorite' => '⚡ Priorité', 'prix_desc' => 'Prix ↓', 'rdt_desc' => 'Rdt net ↓', 'titre' => 'A→Z'] as $k => $lbl): ?>
            <a class="inv-pill <?= $tri === $k ? 'active' : '' ?>" href="?<?= http_build_query(array_merge($_GET, ['tri' => $k])) ?>"><?= $h($lbl) ?></a>
        <?php endforeach; ?>

        <span style="margin-left:14px;"></span><span class="inv-pill-label">Typologie</span>
        <a class="inv-pill <?= $filters['typologie'] === '' ? 'active' : '' ?>" href="?<?= http_build_query(array_merge($_GET, ['typologie' => ''])) ?>">Toutes</a>
        <?php foreach ($typos as $tk => $td): ?>
            <a class="inv-pill <?= $filters['typologie'] === $tk ? 'active' : '' ?>" href="?<?= http_build_query(array_merge($_GET, ['typologie' => $tk])) ?>"><?= $h($td[0]) ?></a>
        <?php endforeach; ?>

        <div style="flex:1"></div>
        <?php if (!empty($propsDispos)): ?>
        <select name="prop" onchange="this.form.submit()" style="font-family:'Sora',sans-serif; font-size:12px; padding:6px 10px; border-radius:999px; border:1px solid #e6e1d7;">
            <option value="0">— Toutes SCI —</option>
            <?php foreach ($propsDispos as $p): ?>
                <option value="<?= (int)$p['id'] ?>" <?= $filters['prop'] === (int)$p['id'] ? 'selected' : '' ?>><?= $h($p['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <input type="text" name="q" placeholder="Titre, ville, locataire..." value="<?= $h($filters['q']) ?>"
               style="font-family:'Sora',sans-serif; font-size:12px; padding:7px 14px; border-radius:999px; border:1px solid #e6e1d7; min-width:180px;">
        <button type="submit" class="inv-btn sm">Filtrer</button>
        <?php if ($tri !== 'priorite' || $filters['typologie'] !== '' || $filters['prop'] > 0 || $filters['q'] !== ''): ?>
            <a href="<?= $h($u('/investisseur/prix_priorites.php')) ?>" class="inv-btn sm ghost">Reset</a>
        <?php endif; ?>
    </form>

    <!-- Tableau -->
    <?php if (empty($rows)): ?>
        <div class="inv-empty"><h3>Aucune analyse à afficher.</h3></div>
    <?php else: ?>
    <table class="pp-table">
        <thead>
            <tr style="font-family:'DM Mono',monospace; font-size:9.5px; letter-spacing:.12em; text-transform:uppercase; color:#9a9690;">
                <th style="text-align:left; padding:8px 14px;">Bien</th>
                <th style="padding:8px 8px;">Prix actuel</th>
                <th style="padding:8px 8px;">Nouveau prix</th>
                <th style="padding:8px 8px;">Prio /10</th>
                <th style="padding:8px 8px;">Rdt net</th>
                <th style="padding:8px 8px;">€/m²</th>
                <th style="padding:8px 8px;">Mult.</th>
                <th style="padding:8px 8px;">Vente now / 5 ans / 10 ans</th>
                <th style="padding:8px 14px;"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $sg = (int)$r['score_global'];
            $rowCol = inv_score_color($sg);
            $prio = (int)($r['priorite_vente'] ?? 0);
            $prixCat = (float)$r['prix_vente_catalogue'];
        ?>
        <tr class="pp-row" id="row-<?= (int)$r['id'] ?>" data-id="<?= (int)$r['id'] ?>" data-loyer="<?= (float)$r['loyer_estime'] ?>" style="--row-color: <?= $h($rowCol) ?>;">
            <td>
                <div class="titre"><?= $h($r['titre_analyse']) ?></div>
                <div class="meta">
                    <?= $h($r['prop_societe'] ?: '—') ?>
                    <?php if ($r['ville']): ?> · <?= $h($r['ville']) ?><?php endif; ?>
                    <?php if ($r['surface']): ?> · <?= $fmt($r['surface']) ?> m²<?php endif; ?>
                    · <?= $sg ?>/100
                </div>
            </td>
            <td class="kpi-cell"><span class="v"><?= $fmt($prixCat) ?> €</span><span class="l">catalogue</span></td>
            <td class="kpi-cell">
                <input type="number" class="pp-input-prix" name="prix" value="<?= $prixCat > 0 ? (int)$prixCat : '' ?>" step="1000" placeholder="<?= $prixCat > 0 ? (int)$prixCat : '—' ?>">
            </td>
            <td class="kpi-cell">
                <div class="pp-prio-wrap">
                    <input type="number" class="pp-input-prio" name="prio" min="0" max="10" step="1" value="<?= $prio ?>" data-p="<?= $prio ?>">
                </div>
            </td>
            <td class="kpi-cell"><span class="v c-rdt"><?= number_format((float)$r['rendement_net'], 2, ',', ' ') ?>%</span><span class="l">net</span></td>
            <td class="kpi-cell"><span class="v c-m2"><?= (float)$r['prix_m2'] > 0 ? $fmt($r['prix_m2']) : '—' ?></span><span class="l">€/m²</span></td>
            <td class="kpi-cell"><span class="v c-mult"><?= (float)$r['multiple_loyer'] > 0 ? number_format((float)$r['multiple_loyer'], 1, ',', ' ') . '×' : '—' ?></span><span class="l">mult.</span></td>
            <td>
                <div class="pp-scen">
                    <div class="box c-sc-now"><span class="lbl">Vendre now</span><span class="val">—</span></div>
                    <div class="box c-sc-5"><span class="lbl">+5 ans</span><span class="val">—</span></div>
                    <div class="box c-sc-10"><span class="lbl">+10 ans</span><span class="val">—</span></div>
                </div>
            </td>
            <td style="white-space:nowrap;">
                <button type="button" class="pp-save-btn" onclick="ppSave(<?= (int)$r['id'] ?>)" title="Sauvegarder">💾</button>
                <a href="<?= $h($u('/investisseur/reunion.php?id=' . (int)$r['id'])) ?>"
                   class="pp-save-btn" style="text-decoration:none; background:#b4443a; color:#fff; border-color:#b4443a;" title="Mode réunion bien par bien">🎤</a>
                <a href="<?= $h($u('/investisseur/detail.php?id=' . (int)$r['id'])) ?>" target="_blank"
                   class="pp-save-btn" style="text-decoration:none;" title="Analyse complète (nouvel onglet)">🔍</a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <div style="margin-top:20px; padding:14px 18px; background:#eef3ea; border-left:4px solid #4f7a3a; border-radius:8px; font-size:13px; color:#2c2a28;">
        💡 <strong>Mode réunion</strong> : modifiez les prix pour voir instantanément l'impact sur le rendement et le cash net à 5/10 ans.
        Les scénarios se mettent à jour automatiquement (ne sauve pas). Cliquez 💾 pour figer le prix et la priorité en base.
    </div>

</div>

<script>
const ppCsrf  = <?= json_encode(function_exists('csrf_token') ? csrf_token() : '') ?>;
const ppApi   = <?= json_encode($u('/investisseur/prix_api.php')) ?>;
const ppFmt   = (v, dec=0) => (v === null || v === undefined || isNaN(v)) ? '—' : new Intl.NumberFormat('fr-FR', {maximumFractionDigits: dec, minimumFractionDigits: dec}).format(v);

// ─── Simulation AJAX ─────────────────────────────────────────────────
async function ppSimulate(id) {
    const row = document.getElementById('row-' + id);
    if (!row) return;
    const prix = row.querySelector('.pp-input-prix').value;
    if (!prix || prix === '0') return;
    const fd = new FormData();
    fd.append('action', 'simulate');
    fd.append('id', id);
    fd.append('prix_vente_catalogue', prix);
    try {
        const r = await fetch(ppApi, {method: 'POST', body: fd});
        const j = await r.json();
        if (!j.ok) return;
        row.querySelector('.c-rdt').textContent  = ppFmt(j.kpi.rendement_net, 2) + '%';
        row.querySelector('.c-m2').textContent   = j.kpi.prix_m2 > 0 ? ppFmt(j.kpi.prix_m2) : '—';
        row.querySelector('.c-mult').textContent = j.kpi.multiple_loyer > 0 ? ppFmt(j.kpi.multiple_loyer, 1) + '×' : '—';

        const best = j.arbitrage.meilleur;
        const setBox = (cls, val, isBest) => {
            const box = row.querySelector('.' + cls);
            box.querySelector('.val').textContent = ppFmt(val) + ' €';
            box.classList.toggle('best', isBest);
        };
        setBox('c-sc-now', j.arbitrage.vendre_now, best === 'vendre_now');
        setBox('c-sc-5',   j.arbitrage.garder_5,   best === 'garder_5');
        setBox('c-sc-10',  j.arbitrage.garder_10,  best === 'garder_10');

        row.classList.add('dirty');
        row.classList.remove('saved');
        const btn = row.querySelector('.pp-save-btn');
        btn.classList.add('dirty'); btn.classList.remove('saved');
        btn.textContent = '💾 Sauver';
    } catch (e) { console.error(e); }
}

// ─── Save AJAX ───────────────────────────────────────────────────────
async function ppSave(id) {
    const row = document.getElementById('row-' + id);
    if (!row) return;
    const prix = row.querySelector('.pp-input-prix').value || '';
    const prio = row.querySelector('.pp-input-prio').value || '0';
    const btn  = row.querySelector('.pp-save-btn');
    btn.disabled = true; btn.textContent = '…';

    const fd = new FormData();
    fd.append('action', 'save');
    fd.append('id', id);
    fd.append('_csrf_token', ppCsrf);
    fd.append('priorite_vente', prio);
    if (prix !== '') fd.append('prix_vente_catalogue', prix);
    try {
        const r = await fetch(ppApi, {method: 'POST', body: fd});
        const j = await r.json();
        btn.disabled = false;
        if (j.ok) {
            btn.textContent = '✓';
            btn.classList.remove('dirty'); btn.classList.add('saved');
            row.classList.remove('dirty'); row.classList.add('saved');
            // Refresh KPI depuis la réponse (ils ont été recalculés côté serveur)
            if (j.kpi) {
                row.querySelector('.c-rdt').textContent  = ppFmt(j.kpi.rendement_net, 2) + '%';
                row.querySelector('.c-m2').textContent   = j.kpi.prix_m2 > 0 ? ppFmt(j.kpi.prix_m2) : '—';
                row.querySelector('.c-mult').textContent = j.kpi.multiple_loyer > 0 ? ppFmt(j.kpi.multiple_loyer, 1) + '×' : '—';
            }
            setTimeout(() => btn.textContent = '💾', 1500);
        } else {
            btn.textContent = '✗';
            alert('Erreur : ' + (j.error || 'inconnue'));
            setTimeout(() => btn.textContent = '💾', 2000);
        }
    } catch (e) {
        btn.disabled = false; btn.textContent = '✗';
        setTimeout(() => btn.textContent = '💾', 2000);
    }
}

// ─── Debounce sur input prix ─────────────────────────────────────────
let ppDebounce = {};
document.querySelectorAll('.pp-input-prix').forEach(inp => {
    inp.addEventListener('input', e => {
        const id = parseInt(e.target.closest('.pp-row').dataset.id);
        clearTimeout(ppDebounce[id]);
        ppDebounce[id] = setTimeout(() => ppSimulate(id), 400);
    });
});
document.querySelectorAll('.pp-input-prio').forEach(inp => {
    inp.addEventListener('change', e => {
        const row = e.target.closest('.pp-row');
        row.classList.add('dirty');
        const btn = row.querySelector('.pp-save-btn');
        btn.classList.add('dirty'); btn.classList.remove('saved');
        btn.textContent = '💾 Sauver';
    });
});
// Ctrl+S = save all dirty
document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        document.querySelectorAll('.pp-row.dirty').forEach(r => ppSave(parseInt(r.dataset.id)));
    }
});
</script>

<?php require_once __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
