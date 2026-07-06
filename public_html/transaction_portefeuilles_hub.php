<?php
// transaction_portefeuilles_hub.php — Hub Bailleur / Portefeuilles.
// Top bar + onglets PERMANENTS plein écran (sans sidebar) : Tableau de bord, Patrimoine actif,
// Portefeuille à vendre, Portefeuilles enregistrés, Biens en vente (transaction).
// Les outils s'ouvrent en iframe (mode ?embed=1 : ni sidebar ni topbar interne).
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'] ?? db();

// ── Périmètre selon l'utilisateur (règle d'or) + "voir en tant que" pour le staff ──
require_once __DIR__ . '/inc/portefeuille_scope.php';
$scope    = pf_scope($pdo);
$isStaff  = $scope['is_staff'];
$viewAs   = (int)$scope['view_as'];
$scopeIds = $scope['ids'];
$bailleurs = $isStaff ? pf_bailleurs_list($pdo) : [];   // pour le filtre staff
$in = !empty($scopeIds) ? implode(',', array_map('intval', $scopeIds)) : '0';

// ── KPIs patrimoine de l'utilisateur ───────────────────────────────
$prixExpr = "COALESCE((SELECT bp.montant FROM bien_prix bp WHERE bp.id_bien=b.id AND bp.type_valeur='prix_vente' AND bp.is_courant=1 ORDER BY bp.date_validation DESC, bp.id DESC LIMIT 1), b.prix_demande_initial, b.prix_vente_estime)";
$loyExpr  = "COALESCE((SELECT bb.loyer_mensuel_hc FROM bien_baux bb WHERE bb.id_bien=b.id ORDER BY (bb.date_fin IS NULL OR bb.date_fin>=CURDATE()) DESC, bb.id DESC LIMIT 1), b.loyer_hc)";
// « En vente » = au moins une annonce de vente diffusée (portails / site / MaBoxImmo / site perso), non archivée.
$venteExpr = "EXISTS(SELECT 1 FROM annonces a WHERE a.id_bien=b.id AND a.type_transaction='vente'
    AND (a.visible_portails=1 OR a.visible_site=1 OR a.visible_maboximmo=1 OR a.visible_site_perso=1)
    AND (a.etat_publication IS NULL OR a.etat_publication<>'archive'))";
$k = ['nb'=>0,'val_tot'=>0,'nb_prop'=>0,'val_prop'=>0,'nb_vente'=>0,'val_vente'=>0,'loyer'=>0];
if ($in !== '') {
    // « Patrimoine actif » = total IDENTIQUE à l'onglet Patrimoine actif (valeur + nb de biens),
    // via la fonction partagée (même population CRG + même règle de prix). Pas de divergence possible.
    require_once __DIR__ . '/inc/patrimoine_base.php';
    $tot = patrimoine_totaux($pdo, "AND ct.id_proprietaire IN ($in)");
    $k['nb'] = $tot['nb_occupes'];   // « Actifs » (occupés) — comme l'onglet
    $k['val_tot'] = $tot['valeur'];  // VALEUR PATRIMOINE — comme l'onglet (tous les biens)

    // Proposés / En vente / Loyers : sur le périmètre des biens du propriétaire.
    $row = $pdo->query("
        SELECT SUM(b.a_proposer=1) nb_prop,
               SUM(CASE WHEN b.a_proposer=1 THEN $prixExpr ELSE 0 END) val_prop,
               SUM($venteExpr) nb_vente,
               SUM(CASE WHEN $venteExpr THEN $prixExpr ELSE 0 END) val_vente,
               SUM($loyExpr) loyer
        FROM biens b
        WHERE b.id_proprietaire IN ($in)
          AND (b.statut_bien IS NULL OR b.statut_bien NOT IN ('supprime','archive','vendu'))
          AND (b.date_retrait_commercialisation IS NULL AND b.prix_final_vente IS NULL)
    ")->fetch(PDO::FETCH_ASSOC) ?: [];
    $k['nb_prop']=(int)($row['nb_prop']??0); $k['val_prop']=(float)($row['val_prop']??0);
    $k['nb_vente']=(int)($row['nb_vente']??0); $k['val_vente']=(float)($row['val_vente']??0);
    $k['loyer']=(float)($row['loyer']??0);
}

$pageTitle    = 'Bailleur — Portefeuilles';
$pageSubtitle = 'Ma Box Agency · Hub Bailleur';
$bodyAttr     = 'data-theme-module="transaction"';
$layoutNoSidebar = true;   // ce module n'a pas de sidebar : onglets plein écran

$U = fn($p) => htmlspecialchars(app_url($p), ENT_QUOTES, 'UTF-8');
$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$eur = fn($v) => number_format((float)$v, 0, ',', ' ') . ' €';

// Propagation du "voir en tant que" bailleur aux outils embarqués.
$bSuffix = $viewAs > 0 ? '&bailleur=' . $viewAs : '';
// Onglets : clé, libellé, icône, URL (vide = panneau dashboard interne)
$TABS = [
    ['k'=>'dash','lbl'=>'Tableau de bord','ic'=>'📊','url'=>''],
    ['k'=>'patrimoine','lbl'=>'Patrimoine actif','ic'=>'🏛️','url'=>app_url('/bailleur_patrimoine_actif.php?embed=1' . $bSuffix)],
    ['k'=>'avendre','lbl'=>'Biens à proposer','ic'=>'🏷️','url'=>app_url('/bailleur_patrimoine_actif.php?embed=1&mode=proposer' . $bSuffix)],
    ['k'=>'enreg','lbl'=>'Portefeuilles enregistrés','ic'=>'📚','url'=>app_url('/transaction_portefeuilles_liste.php?embed=1' . $bSuffix)],
    // Bailleur (non-staff) : la vue transaction complète est hors de sa cage → on pointe
    // vers sa page dédiée (whitelistée, embed = sans sidebar) pour éviter tout redirect/double-layout.
    ['k'=>'biens','lbl'=>'Biens en vente (transaction)','ic'=>'🎯','url'=>app_url(($isStaff ? '/transaction_index.php?embed=1' : '/bailleur_transactions.php?embed=1') . $bSuffix)],
];

$extraCss = <<<'CSS'
<style>
:root{ --hb-ink:#2c2a28; --hb-soft:#7a766f; --hb-bg:#f4f1ec; --hb-line:#ece7df; --hb-blue:#4878a6;
  --hb-green:#2d8a4e; --hb-red:#c0453f; --hb-purple:#6b4aa0; --hb-sh:0 1px 2px rgba(44,42,40,.05),0 6px 20px rgba(44,42,40,.07); }
.agency-content{ display:flex; flex-direction:column; height:100vh; overflow:hidden; }
.agency-topbar{ margin-bottom:0 !important; flex:none; }   /* la top bar compacte est désormais globale */
/* Onglets permanents */
.hb-tabs{ display:flex; gap:4px; padding:0 14px; background:#fff; border-bottom:1px solid var(--hb-line); flex:none; overflow-x:auto; }
.hb-tab{ display:inline-flex; align-items:center; gap:8px; padding:13px 18px; font-size:14px; font-weight:700; color:var(--hb-soft);
  border:none; background:none; cursor:pointer; border-bottom:3px solid transparent; white-space:nowrap; transition:.12s; }
.hb-tab:hover{ color:var(--hb-ink); background:#faf8f5; }
.hb-tab.active{ color:#fff; background:var(--hb-blue); border-bottom-color:var(--hb-blue); border-radius:10px 10px 0 0; }
.hb-tab.active:hover{ color:#fff; background:var(--hb-blue); }
.hb-asbar{ margin-left:auto; display:flex; align-items:center; gap:8px; padding:7px 4px; }
.hb-asbar label{ font-size:12px; font-weight:700; color:var(--hb-soft); white-space:nowrap; }
.hb-asbar select{ padding:7px 10px; border-radius:9px; border:1px solid var(--hb-line); background:#fff; font-size:13px; font-weight:600; cursor:pointer; max-width:260px; }
/* Zone de contenu plein écran */
.hb-body{ flex:1; position:relative; overflow:hidden; background:var(--hb-bg); }
.hb-panel{ position:absolute; inset:0; display:none; overflow:auto; }
.hb-panel.active{ display:block; }
.hb-panel iframe{ width:100%; height:100%; border:0; display:block; }
/* Dashboard */
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
.hb-kpi.green{ --c:var(--hb-green);} .hb-kpi.blue{ --c:var(--hb-blue);} .hb-kpi.red{ --c:var(--hb-red);} .hb-kpi.purple{ --c:var(--hb-purple);} .hb-kpi.amber{ --c:#a8741d;}
.hb-cards{ display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:16px; }
.hb-card{ background:#fff; border-radius:16px; padding:20px; box-shadow:var(--hb-sh); cursor:pointer; transition:.15s; border:1px solid transparent; text-align:left; }
.hb-card:hover{ transform:translateY(-2px); border-color:var(--hb-blue); }
.hb-card .cic{ font-size:30px; }
.hb-card .ct{ font-size:16px; font-weight:800; color:var(--hb-ink); margin-top:10px; }
.hb-card .cd{ font-size:13px; color:var(--hb-soft); margin-top:4px; }
/* subgrid : toutes les cards alignées sur la plus haute (rangée 1), toutes les notes sur la plus haute (rangée 2) */
.hb-cell{ display:grid; grid-template-rows:subgrid; grid-row:span 2; row-gap:10px; }
.hb-cell .hb-card{ display:flex; flex-direction:column; height:100%; min-height:132px; }
.hb-cell .hb-card .cd{ margin-top:auto; padding-top:6px; }
.hb-note{ height:100%; font-size:12.5px; line-height:1.5; color:var(--hb-soft); background:#faf8f5; border:1px solid var(--hb-line); border-left:3px solid var(--hb-blue); border-radius:10px; padding:11px 13px; }
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
    <button type="button" class="hb-cmp-btn" onclick="ouvrirCompareScenarios()" title="Comparer 2 ou 3 scénarios de valorisation côte à côte">⚖️ Comparer des scénarios</button>
</div>

<div class="hb-body">
    <!-- Panneau Tableau de bord -->
    <div class="hb-panel active" data-panel="dash">
        <div class="hb-dash">
            <p class="hb-sub" style="margin-top:4px">Vue d'ensemble de <?= $isStaff && $viewAs === 0 ? 'tout le périmètre' : 'ce patrimoine' ?> · <?= (int)$k['nb'] ?> bien(s)</p>

            <div class="hb-kpis">
                <div class="hb-kpi blue"><span class="ic">🏛️</span><div class="lbl">Patrimoine actif</div><div class="val"><?= $e($eur($k['val_tot'])) ?></div><div class="sub"><?= (int)$k['nb'] ?> bien(s)</div></div>
                <div class="hb-kpi green"><span class="ic">🏷️</span><div class="lbl">Biens proposés</div><div class="val"><?= $e($eur($k['val_prop'])) ?></div><div class="sub"><?= (int)$k['nb_prop'] ?> bien(s) proposé(s)</div></div>
                <div class="hb-kpi amber"><span class="ic">🎯</span><div class="lbl">Biens en vente</div><div class="val"><?= $e($eur($k['val_vente'])) ?></div><div class="sub"><?= (int)$k['nb_vente'] ?> bien(s) diffusé(s)</div></div>
                <div class="hb-kpi purple"><span class="ic">💶</span><div class="lbl">Loyers / mois</div><div class="val"><?= $e($eur($k['loyer'])) ?></div><div class="sub"><?= $e($eur($k['loyer'] * 12)) ?> /an</div></div>
            </div>

            <div class="hb-cards">
                <div class="hb-cell">
                    <button class="hb-card" onclick="hbGo('patrimoine')"><div class="cic">🏛️</div><div class="ct">Patrimoine actif</div><div class="cd">Tableau complet par propriétaire, CRG, loyers, impayés, simulateur de prix.</div></button>
                    <div class="hb-note">Tout ce que possède le propriétaire : l'inventaire complet (loyer, prix/m², rentabilité). La photo de départ — on regarde ce qu'il a, sans rien décider encore.</div>
                </div>
                <div class="hb-cell">
                    <button class="hb-card" onclick="hbGo('avendre')"><div class="cic">🏷️</div><div class="ct">Biens à proposer</div><div class="cd">Choisir les biens à proposer à la vente, ajuster les prix, filtrer par prix / surface.</div></button>
                    <div class="hb-note">Le tri : qu'est-ce qu'on propose ? On choisit les biens à proposer, on ajuste les prix, on filtre par prix et surface. L'atelier de préparation.</div>
                </div>
                <div class="hb-cell">
                    <button class="hb-card" onclick="hbGo('enreg')"><div class="cic">📚</div><div class="ct">Portefeuilles enregistrés</div><div class="cd">Reprendre une sélection existante ou en créer une nouvelle.</div></button>
                    <div class="hb-note">Les sélections prêtes à envoyer à un acheteur précis. Les prix et les honoraires restent ajustables, puis on envoie un lien privé. Le produit fini.</div>
                </div>
                <div class="hb-cell">
                    <button class="hb-card" onclick="hbGo('biens')"><div class="cic">🎯</div><div class="ct">Biens en vente (transaction)</div><div class="cd">Tableau Transactions : annonces, offres, documents, diffusion.</div></button>
                    <div class="hb-note">Les biens diffusés sur Internet (portails, site) pour trouver un acheteur. On y suit annonces, offres, documents et diffusion. L'étape « on cherche activement à vendre ».</div>
                </div>
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
const hbScroll = {};   // mémorise la position de scroll par onglet
function hbActivate(key){
    if (key === hbCurrent) return;   // déjà sur cet onglet
    // 1) sauvegarder le scroll de l'onglet qu'on quitte (iframe same-origin)
    const cur = document.querySelector('.hb-panel[data-panel="'+hbCurrent+'"] iframe');
    if (cur && cur.contentWindow) { try { hbScroll[hbCurrent] = cur.contentWindow.scrollY || 0; } catch(_){} }
    hbCurrent = key;
    document.querySelectorAll('.hb-tab').forEach(t=>t.classList.toggle('active', t.dataset.tab===key));
    document.querySelectorAll('.hb-panel').forEach(p=>p.classList.toggle('active', p.dataset.panel===key));
    // 2) (re)charger l'iframe → données toujours à jour, puis restaurer la position de scroll
    const panel = document.querySelector('.hb-panel[data-panel="'+key+'"]');
    if (panel){
        const f = panel.querySelector('iframe');
        if (f && f.dataset.src){
            const sep = f.dataset.src.indexOf('?') >= 0 ? '&' : '?';
            const saved = hbScroll[key] || 0;
            f.onload = function(){ try { f.contentWindow.scrollTo(0, saved); } catch(_){} };
            f.src = f.dataset.src + sep + '_hb=' + Date.now();   // rechargement = MAJ auto des infos
        }
    }
    // URL absolue (chemin complet) : sinon le <base href> résout '#key' contre la racine → refresh = accueil.
    try { history.replaceState(null,'', location.pathname + location.search + '#' + key); } catch(_){}
}
function hbTab(btn){ hbActivate(btn.dataset.tab); }
function hbViewAs(v){ const h = location.hash || ''; location.href = 'transaction_portefeuilles_hub.php' + (parseInt(v,10) > 0 ? ('?bailleur=' + v) : '') + h; }
function hbGo(key){ hbActivate(key); }
// Onglet initial depuis l'ancre (#patrimoine, etc.)
const h = (location.hash||'').replace('#',''); if (h && document.querySelector('.hb-tab[data-tab="'+h+'"]')) hbActivate(h);
</script>

<?php
// ── Scénarios disponibles (canoniques + ceux qui portent des prix) ──
$cmpScenarios = [];
try {
    foreach ($pdo->query("SELECT scenario_code, COALESCE(MAX(NULLIF(scenario_label,'')), scenario_code) AS lbl, COUNT(DISTINCT id_bien) AS nb
                          FROM bien_prix WHERE type_valeur='prix_vente' AND is_courant=1 AND montant>0
                          GROUP BY scenario_code ORDER BY (scenario_code='courant') DESC, nb DESC") as $r) { $cmpScenarios[] = $r; }
} catch (Throwable) {}
$_canon = [['scenario_code'=>'courant','lbl'=>'Courant'],['scenario_code'=>'ifi','lbl'=>'IFI'],
           ['scenario_code'=>'prix_min','lbl'=>'Prix mini'],['scenario_code'=>'prix_max','lbl'=>'Prix maxi']];
$_seen = array_column($cmpScenarios, 'scenario_code');
foreach ($_canon as $cs) { if (!in_array($cs['scenario_code'], $_seen, true)) { $cs['nb'] = 0; $cmpScenarios[] = $cs; } }
?>
<style>
.hb-cmp-btn{ margin-left:auto; background:#0e6b75; color:#fff; border:none; border-radius:8px; padding:7px 14px; font-weight:700; font-size:13px; cursor:pointer; white-space:nowrap; }
.hb-cmp-btn:hover{ background:#0b565e; }
</style>
<div id="cmp-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:99999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:16px;max-width:520px;width:92%;padding:22px 24px;box-shadow:0 20px 60px rgba(0,0,0,.35);">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
      <h3 style="margin:0;color:#243B5C;font-size:18px;">⚖️ Comparer des scénarios</h3>
      <button type="button" onclick="fermerCompareScenarios()" style="border:none;background:#f1f5f9;border-radius:50%;width:32px;height:32px;cursor:pointer;font-size:16px;">✕</button>
    </div>
    <p style="color:#64748b;font-size:13px;margin:4px 0 14px;">Sélectionne <strong>2 ou 3</strong> scénarios à comparer côte à côte.</p>
    <div style="display:flex;flex-direction:column;gap:8px;max-height:340px;overflow:auto;">
      <?php foreach ($cmpScenarios as $s): ?>
        <label style="display:flex;align-items:center;gap:10px;padding:9px 12px;border:1px solid #e3ebf5;border-radius:10px;cursor:pointer;">
          <input type="checkbox" class="cmp-cb" value="<?= $e($s['scenario_code']) ?>" onchange="cmpLimit(this)">
          <span style="font-weight:700;color:#2d4a72;"><?= $e($s['lbl']) ?></span>
          <span style="color:#94a3b8;font-size:12px;margin-left:auto;"><?= (int)$s['nb'] ?> prix</span>
        </label>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:18px;">
      <button type="button" onclick="fermerCompareScenarios()" style="border:1px solid #cbd5e1;background:#fff;border-radius:8px;padding:8px 14px;font-weight:700;cursor:pointer;">Annuler</button>
      <button type="button" id="cmp-go" onclick="lancerCompareScenarios()" disabled style="border:none;background:#0e6b75;color:#fff;border-radius:8px;padding:8px 18px;font-weight:700;cursor:pointer;">Comparer →</button>
    </div>
  </div>
</div>
<script>
const CMP_BAILLEUR = <?= (int)$viewAs ?>;
function ouvrirCompareScenarios(){ document.getElementById('cmp-modal').style.display='flex'; }
function fermerCompareScenarios(){ document.getElementById('cmp-modal').style.display='none'; }
function cmpChecked(){ return [...document.querySelectorAll('.cmp-cb:checked')].map(c=>c.value); }
function cmpLimit(cb){ if(cmpChecked().length>3){ cb.checked=false; } document.getElementById('cmp-go').disabled = (cmpChecked().length<2); }
function lancerCompareScenarios(){
  const sel=cmpChecked(); if(sel.length<2) return;
  let url='bailleur_scenarios_compare.php?scenarios='+encodeURIComponent(sel.join(','));
  if(CMP_BAILLEUR>0) url+='&bailleur='+CMP_BAILLEUR;
  window.location.href=url;
}
</script>
<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
