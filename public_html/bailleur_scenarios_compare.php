<?php
/**
 * bailleur_scenarios_compare.php — Comparaison de 2-3 scénarios de valorisation.
 *
 * Tableau simplifié, lisible, groupé Propriétaire → Immeuble → Bien (repli/dépli),
 * avec pour chaque bien : locataire, surface, loyer, puis la VALEUR RETENUE
 * (bien_prix.prix_vente) de CHAQUE scénario sélectionné (colonne éditable).
 * Sous-totaux par immeuble et par propriétaire + total général.
 * Édition inline → Valider (enregistre via bailleur_scenario_save_bulk.php) ou Annuler.
 *
 * Module BAILLEUR : sidebar bailleur, cloisonné au périmètre du bailleur.
 * Params : ?scenarios=courant,dusart,ifi  (+ ?bailleur=ID ou ?props[]= pour le périmètre)
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/roles_services.php';
require_login();

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$roleId = (int)current_role_id();
$isSuperAdmin = is_super_admin();
if (!$isSuperAdmin && !hasServiceAccess($roleId, 'bailleur')) {
    http_response_code(403); exit('Accès réservé au module Bailleur.');
}
function scc_h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ── Périmètre propriétaires (identique à bailleur_patrimoine_actif) ──
$propIds = [];
if ($isSuperAdmin) {
    $asBailleur = isset($_GET['bailleur']) ? (int)$_GET['bailleur'] : 0;
    if ($asBailleur > 0) {
        $stB = $pdo->prepare("SELECT id_proprietaire FROM user_proprietaires WHERE id_user=?");
        $stB->execute([$asBailleur]);
        foreach ($stB->fetchAll(PDO::FETCH_COLUMN) as $sid) { if ((int)$sid > 0) $propIds[] = (int)$sid; }
    } elseif (isset($_GET['props'])) {
        foreach ((array)$_GET['props'] as $sid) { if ((int)$sid > 0) $propIds[] = (int)$sid; }
    } elseif (!empty($_SESSION['bailleur_props'])) {
        foreach ($_SESSION['bailleur_props'] as $sid) { if ((int)$sid > 0) $propIds[] = (int)$sid; }
    }
} else {
    $stmtP = $pdo->prepare("SELECT id_proprietaire FROM user_proprietaires WHERE id_user=?");
    $stmtP->execute([$userId]);
    foreach ($stmtP->fetchAll(PDO::FETCH_COLUMN) as $sid) { if ((int)$sid > 0) $propIds[] = (int)$sid; }
    if (empty($propIds)) $propIds = [-1]; // aucun périmètre → rien
}
$hasPerim = !empty($propIds);
$permWhere = $hasPerim ? ('b.id_proprietaire IN (' . implode(',', array_map('intval', $propIds)) . ')') : '1=1';

// ── Scénarios sélectionnés (2-3) ──
$rawScen = (string)($_GET['scenarios'] ?? '');
$selCodes = [];
foreach (explode(',', $rawScen) as $c) {
    $c = preg_replace('/[^a-z0-9_\-]/', '', strtolower(trim($c)));
    if ($c !== '' && !in_array($c, $selCodes, true)) $selCodes[] = $c;
}
$selCodes = array_slice($selCodes, 0, 3);

// Libellés des scénarios (depuis bien_prix)
$scenLabels = [];
if ($selCodes) {
    $ph = implode(',', array_fill(0, count($selCodes), '?'));
    $stL = $pdo->prepare("SELECT scenario_code, scenario_label FROM bien_prix
                          WHERE scenario_code IN ($ph) AND scenario_label IS NOT NULL AND scenario_label<>''
                          GROUP BY scenario_code ORDER BY MAX(id) DESC");
    $stL->execute($selCodes);
    foreach ($stL->fetchAll(PDO::FETCH_ASSOC) as $r) { $scenLabels[$r['scenario_code']] = $r['scenario_label']; }
}
$labelFor = function(string $c) use ($scenLabels): string {
    if ($c === 'courant') return 'Courant';
    return $scenLabels[$c] ?? ucfirst(str_replace('_', ' ', $c));
};

// ── Données : biens du périmètre portant une valeur dans ≥1 scénario sélectionné ──
$rows = [];
if ($selCodes && $hasPerim) {
    $ph = implode(',', array_fill(0, count($selCodes), '?'));
    $sql = "
        SELECT b.id AS id_bien, b.reference_bien, b.adresse_1 AS bien_adresse, b.ville AS bien_ville,
               b.surface_habitable, b.numero_lot, b.id_proprietaire, b.id_immeuble,
               i.nom_immeuble, i.adresse_1 AS imm_adresse, i.ville AS imm_ville,
               COALESCE(NULLIF(pr.societe,''), NULLIF(CONCAT_WS(' ',pr.prenom,pr.nom),''), CONCAT('Propriétaire #',pr.id)) AS proprio_nom,
               ba.locataire_lbl, ba.loyer_mensuel_hc
        FROM biens b
        LEFT JOIN immeubles i ON i.id = b.id_immeuble
        LEFT JOIN proprietaires pr ON pr.id = b.id_proprietaire
        LEFT JOIN (
            SELECT x.id_bien,
                   COALESCE(NULLIF(x.locataire_raison_sociale,''), NULLIF(CONCAT_WS(' ',x.locataire_prenom,x.locataire_nom),'')) AS locataire_lbl,
                   x.loyer_mensuel_hc
            FROM bien_baux x
            JOIN (SELECT id_bien, MAX(id) AS mid FROM bien_baux WHERE statut='actif' GROUP BY id_bien) m
              ON m.mid = x.id
        ) ba ON ba.id_bien = b.id
        WHERE $permWhere
          AND EXISTS (SELECT 1 FROM bien_prix bp WHERE bp.id_bien=b.id AND bp.type_valeur='prix_vente'
                        AND bp.is_courant=1 AND bp.scenario_code IN ($ph) AND bp.montant>0)
        ORDER BY proprio_nom, i.id, b.id";
    $st = $pdo->prepare($sql);
    $st->execute($selCodes);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// Prix par bien × scénario
$prixMap = []; // [id_bien][code] = montant
if ($rows) {
    $bids = array_map(fn($r) => (int)$r['id_bien'], $rows);
    $phB = implode(',', array_fill(0, count($bids), '?'));
    $phS = implode(',', array_fill(0, count($selCodes), '?'));
    $stP = $pdo->prepare("SELECT id_bien, scenario_code, montant FROM bien_prix
                          WHERE type_valeur='prix_vente' AND is_courant=1
                            AND id_bien IN ($phB) AND scenario_code IN ($phS)");
    $stP->execute(array_merge($bids, $selCodes));
    foreach ($stP->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $prixMap[(int)$r['id_bien']][$r['scenario_code']] = (float)$r['montant'];
    }
}

// Locataire + loyer depuis le DERNIER trimestre CRG (source affichée par le patrimoine),
// fallback sur le bail actif (déjà joint dans $rows).
$crgMap = []; // [id_bien] => ['loc'=>, 'loyer'=>]
if ($rows) {
    $bids = array_map(fn($r) => (int)$r['id_bien'], $rows);
    $phB = implode(',', array_fill(0, count($bids), '?'));
    $sqlC = "SELECT s.id_bien, s.locataire_nom, s.loyer_appele
             FROM crg_situations_locataires s
             JOIN crg_trimestres t ON t.id = s.id_crg
             WHERE s.id_bien IN ($phB) AND s.loyer_appele > 0
               AND (t.annee, t.trimestre) = (
                   SELECT t2.annee, t2.trimestre FROM crg_trimestres t2
                   WHERE t2.id_proprietaire = t.id_proprietaire
                     AND (t2.parse_statut IS NULL OR t2.parse_statut <> 'erreur')
                   ORDER BY t2.annee DESC, t2.trimestre DESC LIMIT 1)
             ORDER BY s.id DESC";
    $stC = $pdo->prepare($sqlC);
    $stC->execute($bids);
    foreach ($stC->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $bid = (int)$r['id_bien'];
        if (!isset($crgMap[$bid])) $crgMap[$bid] = ['loc' => $r['locataire_nom'], 'loyer' => (float)$r['loyer_appele']];
    }
}
// Injecte dans $rows (CRG prioritaire, sinon bail)
foreach ($rows as &$r) {
    $bid = (int)$r['id_bien'];
    $r['loc_final']   = $crgMap[$bid]['loc']   ?? $r['locataire_lbl'] ?? '';
    $r['loyer_final'] = $crgMap[$bid]['loyer'] ?? $r['loyer_mensuel_hc'] ?? 0;
}
unset($r);

// Regroupement Propriétaire → Immeuble → Biens
$tree = []; // [propId] => ['nom'=>, 'immeubles'=>[immId=>['nom'=>,'biens'=>[]]]]
foreach ($rows as $r) {
    $pid = (int)$r['id_proprietaire'];
    $iid = (int)($r['id_immeuble'] ?: 0);
    if (!isset($tree[$pid])) $tree[$pid] = ['nom' => $r['proprio_nom'], 'immeubles' => []];
    if (!isset($tree[$pid]['immeubles'][$iid])) {
        $immNom = $iid > 0 ? ($r['nom_immeuble'] ?: ($r['imm_adresse'] ?: 'Immeuble #' . $iid)) : 'Biens isolés';
        $tree[$pid]['immeubles'][$iid] = ['nom' => $immNom, 'biens' => []];
    }
    $tree[$pid]['immeubles'][$iid]['biens'][] = $r;
}

$fmt = fn($v) => number_format((float)$v, 0, ',', ' ');
$nbBiens = count($rows);

$pageTitle    = 'Comparaison de scénarios';
$pageSubtitle = 'Ma Box Bailleur · Valorisation — ' . $nbBiens . ' bien' . ($nbBiens > 1 ? 's' : '');
$layoutSidebar = 'sidebar_bailleur_module';
$current_page  = 'bailleur_patrimoine_actif';
$extraCss = '<style>
.scc-wrap { padding:14px 18px 60px; }
.scc-toolbar { position:sticky; top:0; z-index:20; background:#fff; display:flex; align-items:center;
  gap:10px; flex-wrap:wrap; padding:10px 12px; border:1px solid #e3ebf5; border-radius:12px; margin-bottom:12px; box-shadow:0 1px 4px rgba(0,0,0,.04); }
.scc-chip { background:#eef4fb; color:#2d4a72; border:1px solid #d7e4f3; border-radius:999px; padding:4px 12px; font-weight:700; font-size:12.5px; }
.scc-chip.cur { background:#e7f6ee; color:#166534; border-color:#bbe7cc; }
.scc-btn { border:1px solid #cbd5e1; background:#fff; color:#243B5C; border-radius:8px; padding:6px 12px; font-weight:700; font-size:12.5px; cursor:pointer; }
.scc-btn:hover { background:#f3f6fb; }
.scc-btn.primary { background:#0e6b75; color:#fff; border-color:#0e6b75; }
.scc-btn.primary:hover { background:#0b565e; }
.scc-btn.warn { background:#fef3e2; color:#b45309; border-color:#f3d2a6; }
.scc-info { color:#64748b; font-size:12px; }
table.scc { border-collapse:collapse; width:100%; font-size:13px; background:#fff; }
table.scc th, table.scc td { padding:7px 10px; border-bottom:1px solid #eef2f7; }
table.scc thead th { position:sticky; top:60px; background:#243B5C; color:#fff; text-align:left; font-size:11.5px; letter-spacing:.03em; z-index:10; }
table.scc thead th.num { text-align:right; }
td.num, th.num { text-align:right; font-variant-numeric:tabular-nums; }
tr.prop-row { background:#eef2fb; cursor:pointer; }
tr.prop-row td { font-weight:800; color:#243B5C; border-top:2px solid #c7d6ee; }
tr.imm-row { background:#f6f9fd; cursor:pointer; }
tr.imm-row td { font-weight:700; color:#3a5a86; }
tr.bien-row:hover { background:#fafcff; }
.scc-toggle { display:inline-block; width:14px; color:#64748b; }
.scc-loc { color:#334155; } .scc-ref { font-weight:600; color:#243B5C; } .scc-adr { color:#64748b; font-size:11.5px; }
input.scc-val { width:104px; text-align:right; border:1px solid #d4a047; border-radius:6px; padding:4px 8px; font-size:12.5px; font-variant-numeric:tabular-nums; background:#fffdf7; }
input.scc-val:focus { outline:2px solid #d4a047; background:#fff; }
input.scc-val.ro { border-color:#e2e8f0; background:#f8fafc; color:#475569; }
input.scc-val.dirty { border-color:#0e6b75; background:#e7f6ee; }
.scc-empty { color:#cbd5e1; }
</style>';
require_once __DIR__ . '/inc/agency_layout_top.php';
?>
<div class="scc-wrap">

  <div class="scc-toolbar">
    <strong style="color:#243B5C;">⚖️ Scénarios :</strong>
    <?php foreach ($selCodes as $c): ?>
      <span class="scc-chip <?= $c==='courant'?'cur':'' ?>"><?= scc_h($labelFor($c)) ?></span>
    <?php endforeach; ?>
    <span style="flex:1 1 auto;"></span>
    <button type="button" class="scc-btn" onclick="sccToggleAll(true)">⊞ Tout déplier</button>
    <button type="button" class="scc-btn" onclick="sccToggleAll(false)">⊟ Tout replier</button>
    <button type="button" class="scc-btn warn" id="scc-cancel" onclick="sccCancel()" disabled>↺ Annuler</button>
    <button type="button" class="scc-btn primary" id="scc-save" onclick="sccSave()" disabled>💾 Valider</button>
    <span class="scc-info" id="scc-info"></span>
  </div>

  <?php if (!$selCodes): ?>
    <p>Aucun scénario sélectionné. Reviens au patrimoine et choisis 2 ou 3 scénarios à comparer.</p>
  <?php elseif (!$nbBiens): ?>
    <p>Aucun bien avec une valeur dans les scénarios choisis, sur ce périmètre.</p>
  <?php else: ?>
  <table class="scc" id="scc-table">
    <thead>
      <tr>
        <th>Bien</th>
        <th>Locataire</th>
        <th class="num">Surface</th>
        <th class="num">Loyer/mois</th>
        <?php foreach ($selCodes as $c): ?>
          <th class="num"><?= scc_h($labelFor($c)) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($tree as $pid => $P): ?>
        <?php
          // sous-totaux propriétaire (initiaux)
          $propTot = array_fill_keys($selCodes, 0.0);
          foreach ($P['immeubles'] as $IM) foreach ($IM['biens'] as $b) foreach ($selCodes as $c)
            $propTot[$c] += (float)($prixMap[(int)$b['id_bien']][$c] ?? 0);
        ?>
        <tr class="prop-row" data-prop="<?= $pid ?>" onclick="sccToggleProp(<?= $pid ?>)">
          <td colspan="4"><span class="scc-toggle" id="tg-p<?= $pid ?>">▾</span> 🧑 <?= scc_h($P['nom']) ?></td>
          <?php foreach ($selCodes as $c): ?>
            <td class="num" data-subtot-prop="<?= $pid ?>" data-scen="<?= scc_h($c) ?>"><?= $fmt($propTot[$c]) ?> €</td>
          <?php endforeach; ?>
        </tr>
        <?php foreach ($P['immeubles'] as $iid => $IM): ?>
          <?php
            $immTot = array_fill_keys($selCodes, 0.0);
            foreach ($IM['biens'] as $b) foreach ($selCodes as $c)
              $immTot[$c] += (float)($prixMap[(int)$b['id_bien']][$c] ?? 0);
          ?>
          <tr class="imm-row prop-<?= $pid ?>" data-prop="<?= $pid ?>" data-imm="<?= $iid ?>" onclick="sccToggleImm(<?= $pid ?>,<?= $iid ?>)">
            <td colspan="4" style="padding-left:26px;"><span class="scc-toggle" id="tg-i<?= $pid ?>_<?= $iid ?>">▾</span> 🏢 <?= scc_h($IM['nom']) ?></td>
            <?php foreach ($selCodes as $c): ?>
              <td class="num" data-subtot-imm="<?= $pid ?>_<?= $iid ?>" data-scen="<?= scc_h($c) ?>"><?= $fmt($immTot[$c]) ?> €</td>
            <?php endforeach; ?>
          </tr>
          <?php foreach ($IM['biens'] as $b): $bid=(int)$b['id_bien']; ?>
            <tr class="bien-row prop-<?= $pid ?> imm-<?= $pid ?>_<?= $iid ?>" data-prop="<?= $pid ?>" data-imm="<?= $iid ?>">
              <td style="padding-left:40px;">
                <span class="scc-ref"><?= scc_h($b['reference_bien'] ?: ('#'.$bid)) ?></span>
                <span class="scc-adr"><?= scc_h(trim((string)($b['bien_adresse'] ?? '').' '.($b['bien_ville'] ?? ''))) ?></span>
              </td>
              <td class="scc-loc"><?= scc_h(($b['loc_final'] ?? '') ?: '—') ?></td>
              <td class="num"><?= $b['surface_habitable'] ? $fmt($b['surface_habitable']).' m²' : '—' ?></td>
              <td class="num"><?= !empty($b['loyer_final']) ? $fmt($b['loyer_final']).' €' : '—' ?></td>
              <?php foreach ($selCodes as $c):
                    $has = isset($prixMap[$bid][$c]);
                    $val = $has ? (float)$prixMap[$bid][$c] : '';
                    $ro  = ($c === 'courant'); // le Courant (prix diffusé) n'est pas éditable en masse
              ?>
                <td class="num">
                  <input type="text" inputmode="numeric" class="scc-val<?= $ro?' ro':'' ?>"
                         data-bien="<?= $bid ?>" data-prop="<?= $pid ?>" data-imm="<?= $iid ?>"
                         data-scen="<?= scc_h($c) ?>" data-initial="<?= $has ? (int)$val : '' ?>"
                         value="<?= $has ? $fmt($val) : '' ?>" <?= $ro?'readonly':'' ?>
                         placeholder="—" oninput="sccOnInput(this)">
                </td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="prop-row">
        <td colspan="4"><strong>TOTAL GÉNÉRAL</strong></td>
        <?php foreach ($selCodes as $c): ?>
          <td class="num" data-grandtot data-scen="<?= scc_h($c) ?>"><strong>—</strong></td>
        <?php endforeach; ?>
      </tr>
    </tfoot>
  </table>
  <?php endif; ?>
</div>

<script>
const SCC_SCEN = <?= json_encode($selCodes) ?>;
const SCC_LABELS = <?= json_encode(array_map(fn($c)=>$labelFor($c), array_combine($selCodes ?: ['_'], $selCodes ?: ['_'])), JSON_UNESCAPED_UNICODE) ?>;
const SCC_BAILLEUR = <?= (int)($_GET['bailleur'] ?? 0) ?>;

function sccNum(s){ return parseFloat(String(s).replace(/[^0-9.-]/g,'')) || 0; }
function sccFmt(n){ return (Math.round(n)).toLocaleString('fr-FR'); }

function sccOnInput(inp){
  const init = inp.dataset.initial === '' ? null : parseFloat(inp.dataset.initial);
  const cur  = inp.value.trim() === '' ? null : sccNum(inp.value);
  const dirty = (init === null ? cur !== null : cur !== init);
  inp.classList.toggle('dirty', dirty);
  sccRecalc();
  sccUpdateButtons();
}

function sccRecalc(){
  // sous-totaux immeuble + propriétaire + total général, par scénario
  const grand = {}; SCC_SCEN.forEach(c=>grand[c]=0);
  // reset accumulateurs
  const propAcc = {}, immAcc = {};
  document.querySelectorAll('input.scc-val').forEach(inp=>{
    const c=inp.dataset.scen, p=inp.dataset.prop, i=inp.dataset.imm;
    const v = inp.value.trim()==='' ? 0 : sccNum(inp.value);
    propAcc[p]=propAcc[p]||{}; propAcc[p][c]=(propAcc[p][c]||0)+v;
    immAcc[p+'_'+i]=immAcc[p+'_'+i]||{}; immAcc[p+'_'+i][c]=(immAcc[p+'_'+i][c]||0)+v;
    grand[c]+=v;
  });
  document.querySelectorAll('[data-subtot-prop]').forEach(td=>{
    const p=td.dataset.subtotProp, c=td.dataset.scen; td.textContent=sccFmt((propAcc[p]&&propAcc[p][c])||0)+' €';
  });
  document.querySelectorAll('[data-subtot-imm]').forEach(td=>{
    const k=td.dataset.subtotImm, c=td.dataset.scen; td.textContent=sccFmt((immAcc[k]&&immAcc[k][c])||0)+' €';
  });
  document.querySelectorAll('[data-grandtot]').forEach(td=>{
    const c=td.dataset.scen; td.innerHTML='<strong>'+sccFmt(grand[c])+' €</strong>';
  });
}

function sccDirtyInputs(){ return [...document.querySelectorAll('input.scc-val.dirty')]; }
function sccUpdateButtons(){
  const n = sccDirtyInputs().length;
  document.getElementById('scc-save').disabled = n===0;
  document.getElementById('scc-cancel').disabled = n===0;
  document.getElementById('scc-info').textContent = n ? (n+' valeur'+(n>1?'s':'')+' modifiée'+(n>1?'s':'')) : '';
}

function sccCancel(){
  sccDirtyInputs().forEach(inp=>{
    inp.value = inp.dataset.initial==='' ? '' : sccFmt(parseFloat(inp.dataset.initial));
    inp.classList.remove('dirty');
  });
  sccRecalc(); sccUpdateButtons();
}

async function sccSave(){
  const dirty = sccDirtyInputs().filter(i=>!i.classList.contains('ro'));
  if(!dirty.length){ return; }
  // grouper par scénario (le Courant est exclu — non enregistrable en masse)
  const byScen = {};
  dirty.forEach(inp=>{
    const c=inp.dataset.scen; if(c==='courant') return;
    const prix = inp.value.trim()==='' ? 0 : sccNum(inp.value);
    if(prix<=0) return;
    (byScen[c]=byScen[c]||[]).push({b:parseInt(inp.dataset.bien,10), prix:prix});
  });
  const info=document.getElementById('scc-info'); info.textContent='Enregistrement…';
  let okTot=0, errs=[];
  for(const c of Object.keys(byScen)){
    const fd=new FormData();
    fd.append('scenario_code', c);
    fd.append('scenario_label', SCC_LABELS[c]||c);
    fd.append('items', JSON.stringify(byScen[c]));
    try{
      const res=await fetch('bailleur_scenario_save_bulk.php',{method:'POST',body:fd,credentials:'same-origin'});
      const d=await res.json();
      if(d.ok) okTot+=d.n||0; else errs.push((SCC_LABELS[c]||c)+': '+(d.error||'échec'));
    }catch(e){ errs.push((SCC_LABELS[c]||c)+': '+e.message); }
  }
  if(errs.length){ info.textContent='⚠️ '+errs.join(' · '); }
  else {
    info.textContent='✅ '+okTot+' valeur(s) enregistrée(s)';
    // les valeurs deviennent le nouvel état initial
    dirty.forEach(inp=>{ if(inp.dataset.scen!=='courant'){ const v=inp.value.trim()===''?'':String(Math.round(sccNum(inp.value))); inp.dataset.initial=v; inp.classList.remove('dirty'); }});
    sccUpdateButtons();
  }
}

function sccToggleProp(pid){
  const rows=document.querySelectorAll('.prop-'+pid);
  const tg=document.getElementById('tg-p'+pid);
  const hide = tg.textContent==='▾';
  tg.textContent = hide ? '▸' : '▾';
  rows.forEach(r=>{ r.style.display = hide ? 'none' : ''; });
}
function sccToggleImm(pid,iid){
  event.stopPropagation();
  const rows=document.querySelectorAll('.imm-'+pid+'_'+iid);
  const tg=document.getElementById('tg-i'+pid+'_'+iid);
  const hide = tg.textContent==='▾';
  tg.textContent = hide ? '▸' : '▾';
  rows.forEach(r=>{ r.style.display = hide ? 'none' : ''; });
}
function sccToggleAll(expand){
  document.querySelectorAll('.prop-row [id^="tg-p"]').forEach(tg=>{ tg.textContent = expand ? '▾' : '▸'; });
  document.querySelectorAll('.imm-row [id^="tg-i"]').forEach(tg=>{ tg.textContent = expand ? '▾' : '▸'; });
  document.querySelectorAll('.imm-row, .bien-row').forEach(r=>{ r.style.display = expand ? '' : 'none'; });
}

document.addEventListener('DOMContentLoaded', sccRecalc);
</script>

<?php require_once __DIR__ . '/inc/agency_layout_bottom.php'; ?>
