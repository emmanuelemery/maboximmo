<?php
/**
 * bailleur_patrimoine.php — Dashboard du module PATRIMOINE (Ma Box Bailleur).
 *
 * Workspace à onglets (iframes) AVEC la sidebar bailleur conservée (navigation entre modules).
 * 4 accès demandés (Emmanuel 2026-08) :
 *   1. Patrimoine actif  → tableau général modifiable (bailleur_patrimoine_actif.php)
 *   2. Arbitrage         → sélection multi-biens + prix proposé (transaction_portefeuilles.php)
 *   3. Biens à proposer  → patrimoine mode « proposer » (bailleur_patrimoine_actif.php?mode=proposer)
 *   4. Propositions      → portefeuilles enregistrés (transaction_portefeuilles_liste.php)
 * « En vente » N'EST PAS ici : c'est le module TRANSACTION (complet, à part).
 * Réutilise le mécanisme d'onglets du hub + patrimoine_totaux (source unique KPI).
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'] ?? db();

// Périmètre bailleur (règle d'or) + « voir en tant que » pour le staff.
require_once __DIR__ . '/inc/portefeuille_scope.php';
$scope    = pf_scope($pdo);
$isStaff  = $scope['is_staff'];
$viewAs   = (int)$scope['view_as'];
$scopeIds = $scope['ids'];
$bailleurs = $isStaff ? pf_bailleurs_list($pdo) : [];
$in = !empty($scopeIds) ? implode(',', array_map('intval', $scopeIds)) : '0';
$bSuffix = $viewAs > 0 ? '&bailleur=' . $viewAs : '';

// KPIs (identiques à l'onglet Patrimoine actif via la fonction partagée).
$prixExpr = "COALESCE((SELECT bp.montant FROM bien_prix bp WHERE bp.id_bien=b.id AND bp.type_valeur='prix_vente' AND bp.is_courant=1 ORDER BY bp.date_validation DESC, bp.id DESC LIMIT 1), b.prix_demande_initial, b.prix_vente_estime)";
$loyExpr  = "COALESCE((SELECT bb.loyer_mensuel_hc FROM bien_baux bb WHERE bb.id_bien=b.id ORDER BY (bb.date_fin IS NULL OR bb.date_fin>=CURDATE()) DESC, bb.id DESC LIMIT 1), b.loyer_hc)";
$k = ['nb'=>0,'val_tot'=>0,'nb_prop'=>0,'val_prop'=>0,'loyer'=>0,'nb_portef'=>0];
if ($in !== '0') {
    require_once __DIR__ . '/inc/patrimoine_base.php';
    $tot = patrimoine_totaux($pdo, "AND ct.id_proprietaire IN ($in)");
    $k['nb'] = (int)$tot['nb_occupes']; $k['val_tot'] = (float)$tot['valeur'];
    $row = $pdo->query("SELECT SUM(b.a_proposer=1) nb_prop,
               SUM(CASE WHEN b.a_proposer=1 THEN $prixExpr ELSE 0 END) val_prop,
               SUM($loyExpr) loyer
        FROM biens b WHERE b.id_proprietaire IN ($in)
          AND (b.statut_bien IS NULL OR b.statut_bien NOT IN ('supprime','archive','vendu'))
          AND (b.date_retrait_commercialisation IS NULL AND b.prix_final_vente IS NULL)")->fetch(PDO::FETCH_ASSOC) ?: [];
    $k['nb_prop']=(int)($row['nb_prop']??0); $k['val_prop']=(float)($row['val_prop']??0); $k['loyer']=(float)($row['loyer']??0);
    try { $k['nb_portef'] = (int)$pdo->query("SELECT COUNT(*) FROM portefeuilles WHERE id_proprietaire IN ($in)")->fetchColumn(); } catch (Throwable $e) {}
}

// Onglets : clé, libellé, icône, URL iframe (vide = panneau dashboard interne).
$TABS = [
    ['k'=>'dash',        'lbl'=>'Tableau de bord','ic'=>'📊', 'url'=>''],
    ['k'=>'patrimoine',  'lbl'=>'Patrimoine actif','ic'=>'🏛️','url'=>app_url('/bailleur_patrimoine_actif.php?embed=1' . $bSuffix)],
    ['k'=>'arbitrage',   'lbl'=>'Arbitrage',       'ic'=>'⚖️','url'=>app_url('/transaction_portefeuilles.php?embed=1' . $bSuffix)],
    ['k'=>'avendre',     'lbl'=>'Biens à proposer','ic'=>'🏷️','url'=>app_url('/bailleur_patrimoine_actif.php?embed=1&mode=proposer' . $bSuffix)],
    ['k'=>'propositions','lbl'=>'Propositions',    'ic'=>'📚','url'=>app_url('/transaction_portefeuilles_liste.php?embed=1' . $bSuffix)],
];

$pageTitle     = 'Patrimoine';
$pageSubtitle  = 'Ma Box Bailleur';
$layoutSidebar = 'sidebar_bailleur_module';
$U = fn($p) => htmlspecialchars(app_url($p), ENT_QUOTES, 'UTF-8');
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$eur = fn($v) => number_format((float)$v, 0, ',', ' ') . ' €';

$extraCss = <<<'CSS'
<style>
:root{ --hb-ink:#2c2a28; --hb-soft:#7a766f; --hb-bg:#f4f1ec; --hb-line:#ece7df; --hb-blue:#4878a6;
  --hb-green:#2d8a4e; --hb-amber:#a8741d; --hb-purple:#6b4aa0; --hb-sh:0 1px 2px rgba(44,42,40,.05),0 6px 20px rgba(44,42,40,.07); }
.agency-content{ display:flex; flex-direction:column; height:100vh; overflow:hidden; }
.agency-topbar{ margin-bottom:0 !important; flex:none; }
.hb-tabs{ display:flex; gap:4px; padding:0 14px; background:#fff; border-bottom:1px solid var(--hb-line); flex:none; overflow-x:auto; }
.hb-tab{ display:inline-flex; align-items:center; gap:8px; padding:13px 18px; font-size:14px; font-weight:700; color:var(--hb-soft);
  border:none; background:none; cursor:pointer; border-bottom:3px solid transparent; white-space:nowrap; transition:.12s; }
.hb-tab:hover{ color:var(--hb-ink); background:#faf8f5; }
.hb-tab.active{ color:#fff; background:var(--hb-blue); border-bottom-color:var(--hb-blue); border-radius:10px 10px 0 0; }
.hb-asbar{ margin-left:auto; display:flex; align-items:center; gap:8px; padding:7px 4px; }
.hb-asbar label{ font-size:12px; font-weight:700; color:var(--hb-soft); white-space:nowrap; }
.hb-asbar select{ padding:7px 10px; border-radius:9px; border:1px solid var(--hb-line); background:#fff; font-size:13px; font-weight:600; cursor:pointer; max-width:260px; }
.hb-body{ flex:1; position:relative; overflow:hidden; background:var(--hb-bg); }
.hb-panel{ position:absolute; inset:0; display:none; overflow:auto; }
.hb-panel.active{ display:block; }
.hb-panel iframe{ width:100%; height:100%; border:0; display:block; }
.hb-dash{ padding:22px; max-width:1200px; margin:0 auto; }
.hb-h{ font-size:20px; font-weight:800; color:var(--hb-ink); margin:0 0 4px; }
.hb-sub{ font-size:13px; color:var(--hb-soft); margin:0 0 18px; }
.hb-kpis{ display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:14px; margin-bottom:24px; }
.hb-kpi{ background:#fff; border-radius:16px; padding:16px 18px; box-shadow:var(--hb-sh); position:relative; overflow:hidden; }
.hb-kpi::before{ content:""; position:absolute; left:0; top:0; bottom:0; width:5px; background:var(--c,#ccc); }
.hb-kpi .ic{ position:absolute; right:14px; top:14px; font-size:22px; opacity:.85; }
.hb-kpi .lbl{ font-size:11px; text-transform:uppercase; letter-spacing:.04em; font-weight:700; color:var(--hb-soft); }
.hb-kpi .val{ font-size:24px; font-weight:800; color:var(--hb-ink); line-height:1.1; margin-top:4px; }
.hb-kpi .sub{ font-size:11.5px; color:#a8a39a; margin-top:2px; }
.hb-kpi.blue{ --c:var(--hb-blue);} .hb-kpi.green{ --c:var(--hb-green);} .hb-kpi.amber{ --c:var(--hb-amber);} .hb-kpi.purple{ --c:var(--hb-purple);}
.hb-cards{ display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:16px; }
.hb-card{ background:#fff; border-radius:16px; padding:20px; box-shadow:var(--hb-sh); cursor:pointer; transition:.15s; border:1px solid transparent; text-align:left; }
.hb-card:hover{ transform:translateY(-2px); border-color:var(--hb-blue); }
.hb-card .cic{ font-size:30px; }
.hb-card .ct{ font-size:16px; font-weight:800; color:var(--hb-ink); margin-top:10px; }
.hb-card .cd{ font-size:13px; color:var(--hb-soft); margin-top:4px; }
</style>
CSS;

include __DIR__ . '/inc/agency_layout_top.php';
?>

<!-- Onglets permanents -->
<div class="hb-tabs" id="hb-tabs">
    <?php foreach ($TABS as $i => $t): ?>
        <button class="hb-tab<?= $i === 0 ? ' active' : '' ?>" data-tab="<?= $e($t['k']) ?>" data-src="<?= $e($t['url']) ?>" onclick="hbTab(this)">
            <span><?= $t['ic'] ?></span><?= $e($t['lbl']) ?>
        </button>
    <?php endforeach; ?>
    <?php if ($isStaff): ?>
    <div class="hb-asbar">
        <label>👁️ Voir en tant que</label>
        <select onchange="hbViewAs(this.value)">
            <option value="0">— Tout le périmètre —</option>
            <?php foreach ($bailleurs as $b): ?>
                <option value="<?= (int)$b['id'] ?>"<?= $viewAs === (int)$b['id'] ? ' selected' : '' ?>><?= $e($b['label']) ?> (<?= (int)$b['nb'] ?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
</div>

<div class="hb-body">
    <!-- Panneau Tableau de bord -->
    <div class="hb-panel active" data-panel="dash">
        <div class="hb-dash">
            <p class="hb-sub" style="margin-top:4px">Vue d'ensemble du patrimoine · <?= (int)$k['nb'] ?> bien(s) actif(s)</p>
            <div class="hb-kpis">
                <div class="hb-kpi blue"><span class="ic">🏛️</span><div class="lbl">Patrimoine actif</div><div class="val"><?= $e($eur($k['val_tot'])) ?></div><div class="sub"><?= (int)$k['nb'] ?> bien(s) occupé(s)</div></div>
                <div class="hb-kpi green"><span class="ic">🏷️</span><div class="lbl">Biens à proposer</div><div class="val"><?= $e($eur($k['val_prop'])) ?></div><div class="sub"><?= (int)$k['nb_prop'] ?> bien(s) proposé(s)</div></div>
                <div class="hb-kpi amber"><span class="ic">📚</span><div class="lbl">Propositions</div><div class="val"><?= (int)$k['nb_portef'] ?></div><div class="sub">portefeuille(s) enregistré(s)</div></div>
                <div class="hb-kpi purple"><span class="ic">💶</span><div class="lbl">Loyers / mois</div><div class="val"><?= $e($eur($k['loyer'])) ?></div><div class="sub"><?= $e($eur($k['loyer'] * 12)) ?> /an</div></div>
            </div>
            <div class="hb-cards">
                <button class="hb-card" onclick="hbGo('patrimoine')"><div class="cic">🏛️</div><div class="ct">Patrimoine actif</div><div class="cd">Tableau général modifiable : propriétaires, immeubles, biens, CRG, loyers, prix.</div></button>
                <button class="hb-card" onclick="hbGo('arbitrage')"><div class="cic">⚖️</div><div class="ct">Arbitrage</div><div class="cd">Sélectionner les biens d'un périmètre et fixer un prix proposé (décider quoi vendre).</div></button>
                <button class="hb-card" onclick="hbGo('avendre')"><div class="cic">🏷️</div><div class="ct">Biens à proposer</div><div class="cd">Choisir les biens à proposer à la vente, ajuster les prix, filtrer par prix / surface.</div></button>
                <button class="hb-card" onclick="hbGo('propositions')"><div class="cic">📚</div><div class="ct">Propositions</div><div class="cd">Reprendre une sélection enregistrée ou en créer une nouvelle (portefeuilles).</div></button>
            </div>
        </div>
    </div>
    <!-- Panneaux outils (iframes chargés à la demande) -->
    <?php foreach ($TABS as $t): if ($t['url'] === '') continue; ?>
        <div class="hb-panel" data-panel="<?= $e($t['k']) ?>"><iframe data-src="<?= $e($t['url']) ?>" title="<?= $e($t['lbl']) ?>"></iframe></div>
    <?php endforeach; ?>
</div>

<script>
let hbCurrent = 'dash';
const hbScroll = {};
function hbActivate(key){
    if (key === hbCurrent) return;
    const cur = document.querySelector('.hb-panel[data-panel="'+hbCurrent+'"] iframe');
    if (cur && cur.contentWindow) { try { hbScroll[hbCurrent] = cur.contentWindow.scrollY || 0; } catch(_){} }
    hbCurrent = key;
    document.querySelectorAll('.hb-tab').forEach(t=>t.classList.toggle('active', t.dataset.tab===key));
    document.querySelectorAll('.hb-panel').forEach(p=>p.classList.toggle('active', p.dataset.panel===key));
    const panel = document.querySelector('.hb-panel[data-panel="'+key+'"]');
    if (panel){
        const f = panel.querySelector('iframe');
        if (f && f.dataset.src){
            const sep = f.dataset.src.indexOf('?') >= 0 ? '&' : '?';
            const saved = hbScroll[key] || 0;
            f.onload = function(){ try { f.contentWindow.scrollTo(0, saved); } catch(_){} };
            f.src = f.dataset.src + sep + '_hb=' + Date.now();
        }
    }
    try { history.replaceState(null,'', location.pathname + location.search + '#' + key); } catch(_){}
}
function hbTab(btn){ hbActivate(btn.dataset.tab); }
function hbGo(key){ hbActivate(key); }
function hbViewAs(v){ const h = location.hash || ''; location.href = 'bailleur_patrimoine.php' + (parseInt(v,10) > 0 ? ('?bailleur=' + v) : '') + h; }
const h = (location.hash||'').replace('#',''); if (h && document.querySelector('.hb-tab[data-tab="'+h+'"]')) hbActivate(h);
</script>
