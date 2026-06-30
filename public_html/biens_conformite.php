<?php
/**
 * biens_conformite.php — Tableau de conformité documentaire par bien.
 *
 * Pour chaque bien visible par l'utilisateur : présence/lettre du DPE, présence du
 * BAIL en GED, présence du MANDAT (propriétaire) en GED. Si un doc manque → bouton
 * « Charger » qui ouvre l'uploader universel Fluxbox pré-ciblé sur le bien/proprio.
 *
 * Accessible à TOUS les utilisateurs connectés (mise à jour collaborative).
 * Périmètre : super admin = tout ; sinon société / agence de l'utilisateur.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo  = $GLOBALS['pdo'];
$isSuper = function_exists('is_super_admin') && is_super_admin();
$ag   = current_agence_id();
$soc  = current_societe_id();

// ── Périmètre ───────────────────────────────────────────────────────────
$scopeWhere = '';
$params = [];
if ($isSuper) {
    $scopeWhere = '';
} elseif (!empty($soc)) {
    $scopeWhere = ' AND pr.id_agence IN (SELECT id FROM agences WHERE id_societe = :soc) ';
    $params[':soc'] = (int)$soc;
} elseif (!empty($ag)) {
    $scopeWhere = ' AND pr.id_agence = :ag ';
    $params[':ag'] = (int)$ag;
} else {
    $scopeWhere = ' AND 1=0 ';
}

$mandatTypes = "('MANDAT','MANDAT_GESTION','MANDAT_LOCATION','MANDAT_VENTE','MANDAT_SYNDIC','MANDAT_GESTION_LOCATIVE')";
$bailTypes   = "('BAIL','BAIL_SIGNE')";

$sql = "
SELECT b.id, b.reference_bien, b.dpe_classe, b.ges_classe, b.id_immeuble,
       (SELECT d.dpe_classe FROM dpe_diags d WHERE d.id_bien=b.id
          ORDER BY d.est_diag_principal DESC, d.id DESC LIMIT 1) AS diag_classe,
       (SELECT gd.id FROM ged_documents gd
          JOIN ged_document_links gdl ON gdl.document_id=gd.id
         WHERE gd.status='active' AND gdl.entity_type='BIEN' AND gdl.entity_id=b.id
           AND UPPER(gd.document_type) LIKE '%DPE%'
         ORDER BY gd.id DESC LIMIT 1) AS dpe_doc_id,
       COALESCE(NULLIF(b.adresse_1,''), i.adresse_1) AS adresse,
       COALESCE(NULLIF(b.code_postal,''), i.code_postal) AS code_postal,
       COALESCE(NULLIF(b.ville,''), i.ville) AS ville,
       pr.id AS proprio_id, pr.id_tiers, pr.id_agence,
       COALESCE(NULLIF(pr.societe,''), NULLIF(TRIM(CONCAT_WS(' ', pr.prenom, pr.nom)),''), CONCAT('Propriétaire #', pr.id)) AS proprio_nom,
       (SELECT gd.id FROM ged_documents gd
                 JOIN ged_document_links gdl ON gdl.document_id=gd.id
                WHERE gd.status='active' AND gdl.entity_type='BIEN' AND gdl.entity_id=b.id
                  AND UPPER(gd.document_type) IN $bailTypes
                ORDER BY gd.id DESC LIMIT 1) AS bail_doc_id,
       (SELECT gd.id FROM ged_documents gd
                 JOIN ged_document_links gdl ON gdl.document_id=gd.id
                WHERE gd.status='active'
                  AND UPPER(gd.document_type) IN $mandatTypes
                  AND ((gdl.entity_type='TIERS' AND gdl.entity_id=pr.id_tiers)
                       OR (gdl.entity_type='BIEN' AND gdl.entity_id=b.id))
                ORDER BY gd.id DESC LIMIT 1) AS mandat_doc_id,
       EXISTS (SELECT 1 FROM ged_documents gd
                 JOIN ged_document_links gdl ON gdl.document_id=gd.id
                WHERE gd.status='active' AND gdl.entity_type='BIEN' AND gdl.entity_id=b.id
                  AND UPPER(gd.document_type) LIKE '%DPE%') AS has_dpe_doc
FROM biens b
JOIN proprietaires pr ON pr.id = b.id_proprietaire
LEFT JOIN immeubles i ON i.id = b.id_immeuble
WHERE (b.statut_bien IS NULL OR b.statut_bien NOT IN ('supprime','archive','vendu'))
  $scopeWhere
ORDER BY proprio_nom, adresse, b.reference_bien";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Société + nom d'agence (préremplissage uploader + filtre par agence)
$agSoc = []; $agNom = [];
foreach ($pdo->query("SELECT id, id_societe, nom_agence, code_agence FROM agences") as $a) {
    $agSoc[(int)$a['id']] = (int)$a['id_societe'];
    $agNom[(int)$a['id']] = trim((string)($a['nom_agence'] ?: $a['code_agence'] ?: ('Agence #'.$a['id'])));
}
// Agences réellement présentes dans le tableau (pour peupler le filtre)
$agencesPresentes = [];
foreach ($rows as $r) {
    $aid = (int)($r['id_agence'] ?? 0);
    if ($aid > 0 && !isset($agencesPresentes[$aid])) $agencesPresentes[$aid] = $agNom[$aid] ?? ('Agence #'.$aid);
}
asort($agencesPresentes);

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// KPIs (totaux ; recalculés côté client selon l'agence sélectionnée)
$nb = count($rows); $nbNoDpe = 0; $nbNoBail = 0; $nbNoMandat = 0; $nbGedNoLetter = 0;
foreach ($rows as $r) {
    $letter = trim((string)($r['dpe_classe'] ?: $r['diag_classe']));
    $isVierge = (mb_strtolower($letter) === 'vierge');
    $hasLetter = ($letter !== '' && !$isVierge);
    if (!$hasLetter && !$isVierge && empty($r['has_dpe_doc'])) $nbNoDpe++;
    if (!$hasLetter && !$isVierge && !empty($r['has_dpe_doc'])) $nbGedNoLetter++;
    if (empty($r['bail_doc_id']))   $nbNoBail++;
    if (empty($r['mandat_doc_id'])) $nbNoMandat++;
}

$layout_title  = 'Conformité documentaire des biens';
$layout_module = 'Documents · Biens';

ob_start();
?>
<div class="bc-wrap">
  <h1 class="bc-h1">📋 Conformité documentaire des biens</h1>
  <p class="bc-sub">DPE, bail et mandat par bien. Cliquez « Charger » pour déposer un document manquant (classé automatiquement en GED).</p>

  <div class="bc-kpis">
    <span><b id="k-nb"><?= $nb ?></b> biens</span>
    <button class="bc-kpi bad" onclick="bcFilter('nodpe')"><b id="k-nodpe"><?= $nbNoDpe ?></b> sans DPE</button>
    <button class="bc-kpi bad" onclick="bcFilter('nobail')"><b id="k-nobail"><?= $nbNoBail ?></b> sans bail</button>
    <button class="bc-kpi bad" onclick="bcFilter('nomandat')"><b id="k-nomandat"><?= $nbNoMandat ?></b> sans mandat</button>
    <button class="bc-kpi" onclick="bcFilter('all')">Tout afficher</button>
  </div>

  <div class="bc-analyse">
    <button id="bc-ana-btn" class="bc-ana-btn" onclick="bcAnalyseAll(this)">⚡ Analyser gratuitement les DPE en GED non renseignés
      (<b id="k-gednoletter"><?= $nbGedNoLetter ?></b>)</button>
    <span id="bc-ana-msg" class="bc-mut"></span>
    <div class="bc-mut" style="margin-top:4px;">Extraction <b>regex</b> sur les PDF déjà classés en GED, pour récupérer la lettre. Aucun coût IA. Les PDF scannés sont ignorés (ils nécessiteraient l'IA).</div>
  </div>

  <div class="bc-controls">
    <input id="bc-search" class="bc-search" placeholder="🔎 Rechercher (réf, adresse, propriétaire)…" oninput="bcSearch(this.value)">
    <?php if (count($agencesPresentes) > 1): ?>
    <select id="bc-agence" class="bc-agence" onchange="bcAgence(this.value)">
      <option value="">🏢 Toutes les agences (<?= $nb ?>)</option>
      <?php foreach ($agencesPresentes as $aid => $anom): ?>
        <option value="<?= (int)$aid ?>"><?= $h($anom) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
  </div>

  <table class="bc-tbl">
    <thead><tr>
      <th>Bien</th><th>Propriétaire</th><th>DPE</th><th>Bail</th><th>Mandat</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $bid = (int)$r['id']; $pid = (int)$r['proprio_id'];
        $age = (int)($r['id_agence'] ?? 0); $socId = $agSoc[$age] ?? 0;
        // Lettre DPE : biens.dpe_classe puis repli sur le diagnostic (dpe_diags).
        $dpe = trim((string)($r['dpe_classe'] ?: $r['diag_classe']));
        $dpeVierge = (mb_strtolower($dpe) === 'vierge');
        if ($dpeVierge) $dpe = '';
        $hasDpe = $dpe !== '' || $dpeVierge || !empty($r['has_dpe_doc']);
        $bailDocId = (int)($r['bail_doc_id'] ?? 0); $mandatDocId = (int)($r['mandat_doc_id'] ?? 0);
        $hasBail = $bailDocId > 0; $hasMandat = $mandatDocId > 0;
        $adr = trim(($r['adresse'] ?? '') . ' ' . ($r['code_postal'] ?? '') . ' ' . ($r['ville'] ?? ''));
        $gedNoLetter = ($hasDpe && $dpe === '' && !$dpeVierge);
        $cls = [];
        if (!$hasDpe)     $cls[] = 'nodpe';
        if ($gedNoLetter) $cls[] = 'gednoletter';
        if (!$hasBail)    $cls[] = 'nobail';
        if (!$hasMandat)  $cls[] = 'nomandat';
        $hay = mb_strtolower(($r['reference_bien'] ?? '') . ' ' . $adr . ' ' . ($r['proprio_nom'] ?? ''));
        $prefill = $h(json_encode(['bien_id'=>$bid,'proprio_id'=>$pid,'age_id'=>$age,'soc_id'=>$socId,'origin'=>'conformite']));
    ?>
      <tr class="bc-row <?= implode(' ', $cls) ?>" data-h="<?= $h($hay) ?>" data-agence="<?= $age ?>" data-bien="<?= $bid ?>" data-prefill="<?= $prefill ?>">
        <td><b><?= $h($r['reference_bien'] ?: ('#'.$bid)) ?></b><div class="bc-mut"><?= $h($adr) ?></div></td>
        <td><?= $h($r['proprio_nom']) ?></td>
        <!-- DPE -->
        <?php
          $dpeDocId = (int)($r['dpe_doc_id'] ?? 0);
          $immId    = (int)($r['id_immeuble'] ?? 0);
          // Clic → ouvre le modal DPE existant (visuel + édition) sur le doc GED.
          $editAttr = $dpeDocId > 0
            ? ' role="button" title="Cliquer pour voir/modifier le DPE (visuel)" onclick="bcEditDpe('.$bid.','.$dpeDocId.','.$immId.",'".$h(strtoupper($dpe)).'\')" style="cursor:pointer;"'
            : '';
        ?>
        <td id="dpe-cell-<?= $bid ?>">
          <?php if ($dpe !== ''): ?>
            <span class="bc-dpe dpe-<?= $h(strtoupper($dpe)) ?>"<?= $editAttr ?>><?= $h(strtoupper($dpe)) ?></span>
          <?php elseif ($dpeVierge): ?>
            <span class="bc-dpe-vierge"<?= $editAttr ?>>Vierge</span>
          <?php elseif ($hasDpe): ?>
            <span class="bc-ok"<?= $editAttr ?>>✓ en GED <span class="bc-mut">(modifier)</span></span>
          <?php else: ?>
            <button class="bc-load" onclick='bcLoadDpe(this)' title="Ouvrir le OneDrive du propriétaire pour récupérer le DPE">＋ Charger DPE</button>
          <?php endif; ?>
        </td>
        <!-- Bail -->
        <td>
          <?php if ($hasBail): ?>
            <span class="bc-ok bc-clic" title="Voir le bail (visuel)" onclick="bcView(<?= $bailDocId ?>,'Bail')">✓ <span class="bc-mut">voir</span></span>
          <?php else: ?><button class="bc-load" onclick='bcLoadDpe(this)' title="Ouvrir le OneDrive du propriétaire pour récupérer le bail">＋ Charger bail</button><?php endif; ?>
        </td>
        <!-- Mandat -->
        <td>
          <?php if ($hasMandat): ?>
            <span class="bc-ok bc-clic" title="Voir le mandat (visuel)" onclick="bcView(<?= $mandatDocId ?>,'Mandat')">✓ <span class="bc-mut">voir</span></span>
          <?php else: ?><button class="bc-load" onclick='bcLoad(this)'>＋ Charger mandat</button><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if (!$rows): ?><p class="bc-mut">Aucun bien dans votre périmètre.</p><?php endif; ?>
</div>

<style>
.bc-wrap{max-width:1150px;margin:0 auto;padding:8px 4px;}
.bc-h1{font-size:22px;margin:0 0 4px;} .bc-sub{color:#6b7280;font-size:13px;margin:0 0 14px;}
.bc-kpis{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px;}
.bc-kpis>span{font-size:13px;color:#374151;} .bc-kpis b{font-size:16px;}
.bc-kpi{border:1px solid #e5e7eb;background:#fff;border-radius:20px;padding:5px 13px;cursor:pointer;font-size:12px;font-weight:700;color:#374151;}
.bc-kpi.bad{border-color:#fecaca;color:#b91c1c;background:#fef2f2;} .bc-kpi:hover{filter:brightness(.97);}
.bc-controls{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px;}
.bc-search{flex:1;min-width:240px;max-width:420px;padding:8px 12px;border:1px solid #d4d7de;border-radius:8px;font-size:13px;}
.bc-analyse{margin:0 0 14px;padding:10px 12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;}
.bc-ana-btn{background:#1f7a46;color:#fff;border:none;border-radius:8px;padding:9px 15px;font-weight:800;cursor:pointer;font-size:13px;}
.bc-ana-btn:hover{background:#176038;} .bc-ana-btn:disabled{opacity:.5;}
.bc-agence{padding:8px 12px;border:1px solid #d4d7de;border-radius:8px;font-size:13px;background:#fff;font-weight:700;color:#374151;}
.bc-tbl{width:100%;border-collapse:collapse;font-size:13px;}
.bc-tbl th,.bc-tbl td{text-align:left;padding:8px 10px;border-bottom:1px solid #eef0f2;vertical-align:middle;}
.bc-tbl th{font-size:11px;text-transform:uppercase;color:#6b7280;letter-spacing:.03em;}
.bc-mut{color:#9ca3af;font-size:11px;}
.bc-ok{color:#166534;font-weight:800;}
.bc-clic{cursor:pointer;} .bc-clic:hover{text-decoration:underline;}
.bc-load{background:#fff;border:1px solid #c97b2e;color:#b45309;border-radius:6px;padding:4px 10px;font-weight:700;cursor:pointer;font-size:12px;white-space:nowrap;}
.bc-load:hover{background:#fff7ed;}
.bc-dpe{display:inline-block;min-width:22px;text-align:center;font-weight:900;color:#fff;border-radius:5px;padding:2px 7px;font-size:13px;}
.dpe-A{background:#16a34a;} .dpe-B{background:#65a30d;} .dpe-C{background:#a3a30d;} .dpe-D{background:#ca8a04;}
.dpe-E{background:#ea580c;} .dpe-F{background:#dc2626;} .dpe-G{background:#7f1d1d;}
.bc-dpe-vierge{display:inline-block;font-weight:800;color:#6b7280;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:5px;padding:2px 8px;font-size:12px;}
</style>

<script>
// Bail / mandat manquants → uploader universel Fluxbox (pré-ciblé bien/proprio).
function bcLoad(btn){
  var tr=btn.closest('.bc-row'); var pf={}; try{ pf=JSON.parse(tr.getAttribute('data-prefill')); }catch(e){}
  if(typeof window.fbxOpenUploadModal==='function'){ window.fbxOpenUploadModal(pf); }
  else { alert('Uploader indisponible sur cette page.'); }
}
// Charger un DPE → ouvrir le OneDrive du propriétaire (nouvel onglet).
function bcLoadDpe(btn){
  var tr=btn.closest('.bc-row'); var pf={}; try{ pf=JSON.parse(tr.getAttribute('data-prefill')); }catch(e){}
  var pid=pf.proprio_id||0; if(!pid){ return bcLoad(btn); }
  var old=btn.textContent; btn.textContent='⏳ OneDrive…'; btn.disabled=true;
  fetch('/api/onedrive_proprio_url.php?proprio_id='+pid,{credentials:'same-origin'}).then(r=>r.json()).then(function(j){
    btn.disabled=false; btn.textContent=old;
    if(j&&j.ok&&j.url){ window.open(j.url,'_blank','noopener'); }
    else { alert('OneDrive du propriétaire introuvable'+((j&&j.error)?(' : '+j.error):'')+'. Bascule sur l\'upload.'); bcLoad(btn); }
  }).catch(function(){ btn.disabled=false; btn.textContent=old; bcLoad(btn); });
}
// Bail / Mandat en GED → visualiseur de document générique existant.
function bcView(docId, label){
  if(typeof window.mvptModalView==='function'){ window.mvptModalView(docId, label||'Document'); }
  else { window.open('/api/ged_doc_serve.php?id='+docId,'_blank','noopener'); }
}
// DPE déjà en GED → ouvrir le modal existant (visuel PDF + édition).
function bcEditDpe(bienId, docId, immId, letter){
  if(typeof window.dpeDiagOpen!=='function'){ alert('Modal DPE indisponible.'); return; }
  window.dpeDiagOpen({ title:'🌡️ DPE — bien #'+bienId, ged_document_id:docId,
    id_immeuble:immId||0, id_bien:bienId, fields: letter?{dpe_classe:letter}:{} });
}
var bcCurFilter='all', bcCurAgence='';
function bcFilter(f){ bcCurFilter=f; bcApply(); }
function bcSearch(){ bcApply(); }
function bcAgence(v){ bcCurAgence=v||''; bcApply(); bcRecountKpis(); }
function bcRowInAgence(tr){ return !bcCurAgence || (tr.getAttribute('data-agence')===bcCurAgence); }
function bcApply(){
  var q=(document.getElementById('bc-search').value||'').toLowerCase().trim();
  document.querySelectorAll('.bc-row').forEach(function(tr){
    var okF = bcCurFilter==='all' || tr.classList.contains(bcCurFilter);
    var okS = !q || (tr.getAttribute('data-h')||'').indexOf(q)>=0;
    tr.style.display = (okF && okS && bcRowInAgence(tr)) ? '' : 'none';
  });
}
// KPI recalculés pour l'agence sélectionnée (indépendamment du filtre/recherche).
function bcRecountKpis(){
  var nb=0,nodpe=0,nobail=0,nomandat=0,gnl=0;
  document.querySelectorAll('.bc-row').forEach(function(tr){
    if(!bcRowInAgence(tr)) return; nb++;
    if(tr.classList.contains('nodpe')) nodpe++;
    if(tr.classList.contains('nobail')) nobail++;
    if(tr.classList.contains('nomandat')) nomandat++;
    if(tr.classList.contains('gednoletter')) gnl++;
  });
  document.getElementById('k-nb').textContent=nb;
  document.getElementById('k-nodpe').textContent=nodpe;
  document.getElementById('k-nobail').textContent=nobail;
  document.getElementById('k-nomandat').textContent=nomandat;
  document.getElementById('k-gednoletter').textContent=gnl;
}

// ── Analyse gratuite (regex) en série de tous les DPE en GED non renseignés ──
function bcAnalyseAll(btn){
  var msg=document.getElementById('bc-ana-msg'); btn.disabled=true;
  msg.style.color='#6b7280'; msg.textContent='⏳ Récupération de la liste…';
  fetch('/api/dpe_ged_analyse_batch.php?mode=list',{credentials:'same-origin'}).then(r=>r.json()).then(function(j){
    if(!j||!j.ok||!j.items.length){ btn.disabled=false; msg.textContent=(j&&j.ok)?'Rien à analyser.':'❌ liste indisponible'; return; }
    // On ne traite que les biens visibles dans l'agence sélectionnée si un filtre agence est actif.
    var items=j.items.filter(function(it){ var tr=document.querySelector('.bc-row[data-bien="'+it.bien_id+'"]'); return tr && bcRowInAgence(tr); });
    var total=items.length, done=0, ok=0, skip=0;
    if(!total){ btn.disabled=false; msg.textContent='Aucun DPE à analyser dans cette agence.'; return; }
    (function next(){
      if(!items.length){ btn.disabled=false; msg.style.color='#166534'; msg.textContent='✅ Terminé : '+ok+' lettre(s) récupérée(s), '+skip+' ignoré(s) (scannés/illisibles) sur '+total+'.'; bcRecountKpis(); return; }
      var it=items.shift(); done++;
      msg.style.color='#6b7280'; msg.textContent='⏳ Analyse '+done+'/'+total+'…';
      fetch('/api/dpe_ged_analyse_batch.php?mode=one&bien_id='+it.bien_id+'&doc_id='+it.doc_id,{credentials:'same-origin'})
        .then(r=>r.json()).then(function(a){
          var tr=document.querySelector('.bc-row[data-bien="'+it.bien_id+'"]');
          var cell=document.getElementById('dpe-cell-'+it.bien_id);
          if(a&&a.ok){ ok++;
            if(tr){ tr.classList.remove('gednoletter'); }
            if(cell){
              if(a.vierge){ cell.innerHTML='<span class="bc-dpe-vierge">Vierge</span>'; }
              else if(a.classe){ var L=String(a.classe).toUpperCase(); cell.innerHTML='<span class="bc-dpe dpe-'+L+'">'+L+'</span>'; }
              else { cell.innerHTML='<span class="bc-ok">✓ en GED</span>'; }
            }
          } else { skip++; }
          next();
        }).catch(function(){ skip++; next(); });
    })();
  }).catch(function(){ btn.disabled=false; msg.style.color='#c62828'; msg.textContent='❌ réseau'; });
}
</script>

<?php include __DIR__ . '/inc/fluxbox_upload_modal.php'; ?>
<?php include __DIR__ . '/inc/dpe_edit_modal.php'; ?>
<?php include __DIR__ . '/inc/mvpt_modal_doc_viewer.php'; ?>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
