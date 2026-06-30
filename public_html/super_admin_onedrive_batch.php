<?php
/**
 * super_admin_onedrive_batch.php — Tableau OneDrive → GED par BIEN (DPE ou BAIL).
 *
 * UN SEUL tableau, une ligne par bien (gestion CRG, agences 63-1/63-2), deux MODES :
 *   ?mode=dpe   → étiquette DPE  OK/VIDE, import types=dpe
 *   ?mode=baux  → étiquette BAIL OK/VIDE (bail actif), import types=bail_signe,edl_entree
 *                 (scope : biens LOUÉS = ayant un bail actif)
 *
 * Pour chaque ligne : réf cliquable → bien_360, propriétaire, 👁 aperçu (scan dry-run).
 * Outils : recherche, filtres Tous/Vides/OK, tri par colonne, compteur live, export CSV.
 *
 * Anti-doublon (moteur onedrive_classer) : hash identique re-lié ; cas multi-biens → pile ;
 * 1 seul DPE par bien (oc_commit_proposal) ; on n'agit que sur les « VIDE ».
 *
 * Sécurité : admin / super admin.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_admin_or_super_admin();
require_once __DIR__ . '/inc/onedrive_classer.php';

$pdo  = $GLOBALS['pdo'];
$mode = (($_GET['mode'] ?? 'dpe') === 'baux') ? 'baux' : 'dpe';

if ($mode === 'baux') {
    // Biens LOUÉS (bail actif) du périmètre, + présence d'un doc bail en GED.
    $sql = "
        SELECT b.id, b.reference_bien, b.lot_principal,
               COALESCE(NULLIF(b.adresse_1,''), i.adresse_1) AS adresse,
               COALESCE(NULLIF(b.ville,''), i.ville)         AS ville,
               b.id_proprietaire,
               COALESCE(NULLIF(p.societe,''), TRIM(CONCAT_WS(' ', p.prenom, p.nom))) AS proprio_nom,
               a.code_agence,
               COALESCE(NULLIF(ba.locataire_raison_sociale,''),
                        NULLIF(TRIM(CONCAT_WS(' ', ba.locataire_prenom, ba.locataire_nom)),'')) AS locataire,
               (EXISTS (SELECT 1 FROM ged_documents gd
                        WHERE gd.status='active'
                          AND gd.document_type IN ('bail_signe','bail','BAIL')
                          AND gd.id_bail = ba.id)) AS has_it   -- strictement le bail ACTIF
          FROM biens b
          JOIN (SELECT DISTINCT id_bien FROM crg_situations_locataires WHERE id_bien IS NOT NULL) crg ON crg.id_bien = b.id
          JOIN bien_baux ba ON ba.id_bien = b.id AND ba.statut = 'actif'
          LEFT JOIN immeubles i     ON i.id = b.id_immeuble
          LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
          LEFT JOIN agences a       ON a.id = b.id_agence
         WHERE b.statut_bien = 'actif' AND a.code_agence IN ('63-1','63-2')
         ORDER BY proprio_nom, b.reference_bien";
} else {
    // Tous les biens du périmètre + présence d'un DPE (base ou GED).
    $sql = "
        SELECT b.id, b.reference_bien, b.lot_principal,
               COALESCE(NULLIF(b.adresse_1,''), i.adresse_1) AS adresse,
               COALESCE(NULLIF(b.ville,''), i.ville)         AS ville,
               b.id_proprietaire,
               COALESCE(NULLIF(p.societe,''), TRIM(CONCAT_WS(' ', p.prenom, p.nom))) AS proprio_nom,
               a.code_agence, NULL AS locataire,
               (EXISTS (SELECT 1 FROM dpe_diags d WHERE d.id_bien = b.id)
                OR EXISTS (SELECT 1 FROM ged_documents gd
                           JOIN ged_document_links gdl ON gdl.document_id = gd.id
                                AND gdl.entity_type='BIEN' AND gdl.entity_id = b.id
                           WHERE gd.status='active' AND gd.document_type IN ('dpe','DPE','DIAG_DPE','DIAG'))) AS has_it
          FROM biens b
          JOIN (SELECT DISTINCT id_bien FROM crg_situations_locataires WHERE id_bien IS NOT NULL) crg ON crg.id_bien = b.id
          LEFT JOIN immeubles i     ON i.id = b.id_immeuble
          LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
          LEFT JOIN agences a       ON a.id = b.id_agence
         WHERE b.statut_bien = 'actif' AND a.code_agence IN ('63-1','63-2')
         ORDER BY proprio_nom, b.reference_bien";
}
try { $biens = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: []; }
catch (Throwable $e) { $biens = []; $loadErr = $e->getMessage(); }

$nbOk = 0; foreach ($biens as $b) { if ((int)$b['has_it']) $nbOk++; }
$nbVide = count($biens) - $nbOk;

$labelType = $mode === 'baux' ? 'BAIL' : 'DPE';
$csrf    = function_exists('csrf_token') ? csrf_token('onedrive_classer') : '';
$apiUrl  = app_url('/api/onedrive_classer.php');
$graphOk = function_exists('graph_is_configured') ? graph_is_configured() : true;
$urlDpe  = app_url('/super_admin_onedrive_batch.php?mode=dpe');
$urlBaux = app_url('/super_admin_onedrive_batch.php?mode=baux');

$layout_extra_css = '<style>
.ob-wrap{max-width:1240px;margin:0 auto;padding:18px 0 50px;}
.ob-head{background:linear-gradient(135deg,#ede7f6,#f3eefb);border-radius:16px;padding:18px 22px;margin-bottom:14px;}
.ob-head h1{margin:0 0 6px;font-size:20px;color:#4527a0;}
.ob-head p{margin:0;color:#6b5e8a;font-size:13px;}
.ob-tabs{display:flex;gap:8px;margin-bottom:12px;}
.ob-tab{padding:8px 18px;border-radius:10px 10px 0 0;font-weight:800;font-size:13px;text-decoration:none;color:#5e35b1;background:#efe9fb;border:1px solid #d8cef0;border-bottom:none;}
.ob-tab.active{background:#5e35b1;color:#fff;border-color:#5e35b1;}
.ob-bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px;}
.ob-btn{border:none;border-radius:10px;padding:9px 15px;font-weight:800;cursor:pointer;font-size:12.5px;}
.ob-btn-go{background:#5e35b1;color:#fff;}.ob-btn-go:disabled{opacity:.5;cursor:default;}
.ob-btn-sec{background:#eceef1;color:#333;}
.ob-btn-type{background:#fff;color:#5e35b1;border:1px solid #b39ddb;}.ob-btn-type:hover{background:#f3eefb;}
.ob-filter{background:#fff;color:#4527a0;border:1px solid #cdbdf0;border-radius:999px;padding:7px 14px;font-weight:800;cursor:pointer;font-size:12.5px;}
.ob-filter.active{background:#5e35b1;color:#fff;border-color:#5e35b1;}
.ob-search{border:1px solid #cdbdf0;border-radius:999px;padding:8px 14px;font-size:13px;min-width:230px;}
.ob-prog{font-weight:700;font-size:13px;color:#4527a0;}
table.ob{width:100%;border-collapse:collapse;font-size:13px;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.05);}
table.ob th{background:#ede7f6;color:#4527a0;text-align:left;padding:9px 12px;font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;}
table.ob th.sortable{cursor:pointer;user-select:none;}table.ob th.sortable:hover{background:#e2d7f7;}
table.ob td{padding:8px 12px;border-top:1px solid #f0ecf6;vertical-align:middle;}
table.ob tr.done{background:#f6fbf7;}table.ob tr.err{background:#fdf6f6;}
.ob-res{font-size:12px;font-weight:700;}
.badge-ok{background:#e7f6ec;color:#166534;font-weight:800;border-radius:6px;padding:2px 8px;font-size:11.5px;}
.badge-vide{background:#fdecea;color:#b91c1c;font-weight:800;border-radius:6px;padding:2px 8px;font-size:11.5px;}
.ob-warn{background:#fff3cd;border:1px solid #ffe69c;border-radius:10px;padding:12px 16px;color:#7a5b00;margin-bottom:14px;font-size:13px;}
.ob-modal{display:none;position:fixed;inset:0;z-index:9000;background:rgba(15,18,24,.55);align-items:center;justify-content:center;}
.ob-modal .box{background:#fff;border-radius:14px;width:min(1000px,95vw);max-height:90vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.35);}
</style>';

ob_start();
?>
<div class="ob-wrap">
  <div class="ob-head">
    <h1>🗂️ Documents OneDrive par bien — gestion CRG (63)</h1>
    <p>Une ligne par bien. La réf ouvre la fiche <b>bien 360°</b> (tous les docs). Sélectionne des biens « VIDE » puis classe depuis OneDrive.
       Anti-doublon : 1 seul <?= h($labelType) ?> par bien, fichier identique re-lié, cas ambigus ignorés.</p>
  </div>

  <div class="ob-tabs">
    <a class="ob-tab <?= $mode==='dpe'?'active':'' ?>"  href="<?= h($urlDpe) ?>">⚡ DPE</a>
    <a class="ob-tab <?= $mode==='baux'?'active':'' ?>" href="<?= h($urlBaux) ?>">📋 Baux (bail actif)</a>
  </div>

  <?php if (!empty($loadErr)): ?>
    <div class="ob-warn">⚠️ Erreur chargement : <?= h($loadErr) ?></div>
  <?php elseif (!$graphOk): ?>
    <div class="ob-warn">⚠️ Microsoft Graph n'est pas configuré — l'import échouera (l'affichage reste OK).</div>
  <?php endif; ?>

  <div class="ob-bar">
    <button type="button" class="ob-filter active" id="f-all" onclick="obFilter('all')">Tous (<span id="c-all"><?= count($biens) ?></span>)</button>
    <button type="button" class="ob-filter" id="f-vide" onclick="obFilter('vide')"><?= h($labelType) ?> vide (<span id="c-vide"><?= $nbVide ?></span>)</button>
    <button type="button" class="ob-filter" id="f-ok" onclick="obFilter('ok')"><?= h($labelType) ?> OK (<span id="c-ok"><?= $nbOk ?></span>)</button>
    <input type="search" class="ob-search" id="obSearch" placeholder="🔍 propriétaire, réf, ville<?= $mode==='baux'?', locataire':'' ?>…" oninput="obApply()">
    <label style="font-size:12px;color:#6b5e8a;font-weight:700;display:inline-flex;align-items:center;gap:6px;cursor:pointer;">
      <input type="checkbox" id="obHideSearched" onchange="obApply()"> Masquer les propriétaires déjà cherchés
    </label>
    <button type="button" class="ob-btn ob-btn-sec" onclick="obExportCsv()">⬇️ Export CSV</button>
  </div>

  <div class="ob-bar">
    <button type="button" class="ob-btn ob-btn-sec" onclick="obToggleAll(true)">Tout cocher (visible)</button>
    <button type="button" class="ob-btn ob-btn-sec" onclick="obToggleAll(false)">Décocher</button>
    <span style="font-weight:700;color:#6b5e8a;font-size:12px;margin-left:6px;">Classer la sélection :</span>
    <?php if ($mode === 'baux'): ?>
      <button type="button" class="ob-btn ob-btn-type" id="obGo" onclick="obRun('bail_signe,edl_entree')">📋 Bail + EDL</button>
      <button type="button" class="ob-btn ob-btn-type" onclick="obRun('bail_signe')">📋 Bail seul</button>
    <?php else: ?>
      <button type="button" class="ob-btn ob-btn-type" id="obGo" onclick="obRun('dpe')">⚡ DPE seul</button>
      <button type="button" class="ob-btn ob-btn-type" onclick="obRun('dpe,erp_ernmt,diagnostic_amiante,diagnostic_plomb,diagnostic_elec,diagnostic_gaz,diagnostic_termites,surface_carrez')">⚡ DPE + diagnostics</button>
    <?php endif; ?>
    <button type="button" class="ob-btn ob-btn-go" onclick="obRun('')" title="Classe TOUS les types certains du bien : DPE + diagnostics, bail actif, EDL d'entrée, taxe foncière (hors mandat)">▶ Tout (DPE + bail + EDL + TF…)</button>
    <button type="button" class="ob-btn" id="obStopBtn" onclick="obStop()" style="background:#c62828;color:#fff;display:none;">⏹ Arrêter</button>
    <button type="button" class="ob-btn ob-btn-sec" onclick="obClearSaved()" title="Vide les résultats mémorisés">🧹 Effacer</button>
    <span class="ob-prog" id="obProg"></span>
  </div>

  <table class="ob" id="obTable">
    <thead><tr>
      <th style="width:30px;"><input type="checkbox" onclick="obToggleAll(this.checked)"></th>
      <th class="sortable" style="width:150px;" onclick="obSort('ref')">Réf</th>
      <th class="sortable" onclick="obSort('prop')">Propriétaire<?= $mode==='baux'?' / locataire':'' ?></th>
      <th class="sortable" onclick="obSort('addr')">Adresse</th>
      <th class="sortable" style="width:80px;" onclick="obSort('status')"><?= h($labelType) ?></th>
      <th style="width:50px;"></th>
      <th style="width:230px;">Résultat</th>
    </tr></thead>
    <tbody>
    <?php if (empty($biens)): ?>
      <tr><td colspan="7" style="padding:18px;color:#9a9690;">Aucun bien.</td></tr>
    <?php else: foreach ($biens as $b):
        $hasIt = (int)$b['has_it'];
        $ref   = $b['reference_bien'] ?: ('#'.(int)$b['id']);
        $search= mb_strtolower(trim($ref.' '.($b['proprio_nom'] ?? '').' '.($b['ville'] ?? '').' '.($b['adresse'] ?? '').' '.($b['locataire'] ?? '')));
    ?>
      <tr id="ob-row-<?= (int)$b['id'] ?>" data-status="<?= $hasIt ?>"
          data-proprio="<?= (int)$b['id_proprietaire'] ?>" data-pnom="<?= h($b['proprio_nom'] ?? '') ?>"
          data-ref="<?= h(mb_strtolower($ref)) ?>" data-prop="<?= h(mb_strtolower($b['proprio_nom'] ?? '')) ?>"
          data-addr="<?= h(mb_strtolower(trim(($b['adresse'] ?? '').' '.($b['ville'] ?? '')))) ?>"
          data-search="<?= h($search) ?>">
        <td><input type="checkbox" class="ob-chk" value="<?= (int)$b['id'] ?>"></td>
        <td>
          <a href="<?= h(app_url('/bien_360.php?id=' . (int)$b['id'])) ?>" target="_blank" style="color:#5b21b6;text-decoration:none;font-weight:700;"><?= h($ref) ?> ↗</a>
          <?php if($b['lot_principal']): ?><div style="color:#b3aec0;font-size:11px;">lot <?= h($b['lot_principal']) ?></div><?php endif; ?>
        </td>
        <td>
          <?php if($b['id_proprietaire']): ?><a href="<?= h(app_url('/agency_proprietaire_fiche.php?id=' . (int)$b['id_proprietaire'])) ?>" style="color:#5b21b6;text-decoration:none;"><?= h($b['proprio_nom'] ?: '—') ?></a><?php else: ?><?= h($b['proprio_nom'] ?: '—') ?><?php endif; ?>
          <div style="color:#b3aec0;font-size:11px;"><?= h($b['code_agence']) ?><?php if($mode==='baux' && !empty($b['locataire'])): ?> · 👤 <?= h($b['locataire']) ?><?php endif; ?></div>
        </td>
        <td style="color:#7a766f;font-size:12px;"><?= h(trim(($b['adresse'] ?: '').' '.($b['ville'] ?: ''))) ?: '—' ?></td>
        <td><?= $hasIt ? '<span class="badge-ok">OK ✓</span>' : '<span class="badge-vide">VIDE</span>' ?></td>
        <td><button type="button" class="ob-btn ob-btn-sec" style="padding:5px 9px;font-size:12px;" onclick="obVoir(<?= (int)$b['id'] ?>,'<?= h(addslashes($ref)) ?>')" title="Voir les docs OneDrive du bien (sans rien classer)">👁</button></td>
        <td class="ob-res" id="ob-res-<?= (int)$b['id'] ?>" style="color:#9a9690;">—</td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<div class="ob-modal" id="obModal">
  <div class="box">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #eef0f2;">
      <h3 id="obModalTitle" style="margin:0;font-size:16px;color:#4527a0;">👁 Documents OneDrive</h3>
      <button type="button" onclick="document.getElementById('obModal').style.display='none'" style="border:1px solid #d6dade;background:#eceef1;border-radius:6px;padding:6px 12px;cursor:pointer;font-weight:700;">✕ Fermer</button>
    </div>
    <div id="obModalBody" style="flex:1;overflow:auto;padding:16px 18px;font-size:13px;"></div>
  </div>
</div>

<script>
(function(){
  var CSRF=<?= json_encode($csrf, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var URL=<?= json_encode($apiUrl, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var MODE=<?= json_encode($mode) ?>, LABEL=<?= json_encode($labelType) ?>;
  var PFICHE=<?= json_encode(app_url('/agency_proprietaire_fiche.php'), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var STORE='ob_results_'+MODE+'_v2';
  function load(){ try{return JSON.parse(localStorage.getItem(STORE)||'{}');}catch(e){return {};} }
  function save(o){ try{localStorage.setItem(STORE,JSON.stringify(o));}catch(e){} }
  var saved=load();
  // Propriétaires déjà cherchés (scan complet fait → rien de plus aux passages suivants)
  var SKEY='ob_searched_'+MODE+'_v1';
  function loadS(){ try{return JSON.parse(localStorage.getItem(SKEY)||'{}');}catch(e){return {};} }
  function saveS(o){ try{localStorage.setItem(SKEY,JSON.stringify(o));}catch(e){} }
  var searched=loadS();
  function markSearched(pid,persist){
    Array.prototype.slice.call(document.querySelectorAll('tr[data-proprio="'+pid+'"]')).forEach(function(tr){
      tr.setAttribute('data-searched','1');
      var pc=tr.children[2];
      if(pc && !pc.querySelector('.ob-searched')){
        var s=document.createElement('span'); s.className='ob-searched';
        s.style.cssText='margin-left:6px;background:#eef2ff;color:#3730a3;border-radius:6px;padding:1px 6px;font-size:10px;font-weight:800;';
        s.textContent='🔁 cherché'; pc.appendChild(s);
      }
    });
    if(persist){ searched[pid]=1; saveS(searched); }
  }
  var esc=function(s){var d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;};
  function rows(){ return Array.prototype.slice.call(document.querySelectorAll('table.ob tbody tr[id^="ob-row-"]')); }

  // Marque au chargement les propriétaires déjà cherchés :
  //  - ceux mémorisés explicitement,
  //  - + ceux ayant un résultat mémorisé DÉFINITIF (classé, déjà présent, dossier
  //    introuvable/vide, 0 classé…) — on exclut seulement les échecs « réseau ».
  Object.keys(searched).forEach(function(pid){ markSearched(pid,false); });
  Object.keys(saved).forEach(function(bid){
    var r=saved[bid]; if(!r||!r.txt) return;
    if(String(r.txt).toLowerCase().indexOf('réseau')>=0) return;   // échec réseau → reste à faire
    var tr=document.getElementById('ob-row-'+bid); if(!tr) return;
    var pid=tr.getAttribute('data-proprio'); if(pid) markSearched(pid,true);
  });

  var curFilter='all';
  // Filtre combiné (statut + recherche + déjà cherché) + compteurs live
  window.obApply=function(){
    var q=(document.getElementById('obSearch').value||'').toLowerCase().trim();
    var hideS=document.getElementById('obHideSearched'); hideS=hideS&&hideS.checked;
    var nAll=0,nOk=0,nVide=0;
    rows().forEach(function(tr){
      var ok=tr.getAttribute('data-status')==='1';
      nAll++; if(ok)nOk++; else nVide++;
      var passF=(curFilter==='all')||(curFilter==='vide'&&!ok)||(curFilter==='ok'&&ok);
      var passQ=!q || (tr.getAttribute('data-search')||'').indexOf(q)>=0;
      var passS=!hideS || tr.getAttribute('data-searched')!=='1';
      var show=passF&&passQ&&passS; tr.hidden=!show;
      if(!show){ var c=tr.querySelector('.ob-chk'); if(c) c.checked=false; }
    });
    document.getElementById('c-all').textContent=nAll;
    document.getElementById('c-ok').textContent=nOk;
    document.getElementById('c-vide').textContent=nVide;
  };
  window.obFilter=function(mode){
    curFilter=mode;
    ['all','vide','ok'].forEach(function(m){ document.getElementById('f-'+m).classList.toggle('active', m===mode); });
    obApply();
  };
  window.obToggleAll=function(on){
    rows().forEach(function(tr){ if(tr.hidden) return; var c=tr.querySelector('.ob-chk'); if(c) c.checked=!!on; });
  };

  // Tri par colonne
  var sortKey=null, sortAsc=true;
  window.obSort=function(key){
    sortAsc = (sortKey===key) ? !sortAsc : true; sortKey=key;
    var tb=document.querySelector('table.ob tbody');
    var arr=rows();
    arr.sort(function(a,b){
      var va,vb;
      if(key==='status'){ va=a.getAttribute('data-status'); vb=b.getAttribute('data-status'); }
      else { va=a.getAttribute('data-'+key)||''; vb=b.getAttribute('data-'+key)||''; }
      if(va<vb) return sortAsc?-1:1; if(va>vb) return sortAsc?1:-1; return 0;
    });
    arr.forEach(function(tr){ tb.appendChild(tr); });
  };

  function setRes(bid,txt,color,cls,persist,html){
    var el=document.getElementById('ob-res-'+bid); if(el){ if(html){el.innerHTML=txt;}else{el.textContent=txt;} el.style.color=color;}
    var row=document.getElementById('ob-row-'+bid); if(row){ row.className=(cls||''); }
    if(persist){ saved[bid]={txt:txt,color:color,cls:cls||'',html:!!html}; save(saved); }
  }
  Object.keys(saved).forEach(function(bid){ var r=saved[bid]; if(document.getElementById('ob-res-'+bid)) setRes(bid,r.txt,r.color,r.cls,false,r.html); });
  window.obClearSaved=function(){ if(confirm('Effacer les résultats mémorisés (et les marques « cherché ») ?')){ saved={}; save(saved); searched={}; saveS(searched); location.reload(); } };

  function fmt(j){
    if(j.scanned===0) return ['⚪ dossier vide','#9a9690',''];
    var err=(j.erreurs&&j.erreurs.length)||0;
    var txt=(j.scanned||0)+' trouvé(s) → '+(j.classes||0)+' classé(s)'
      +(j.dedup?(' · '+j.dedup+' déjà présent'):'')+(err?(' · '+err+' err'):'');
    var color=err?'#b45309':((j.classes>0)?'#2d8a4e':'#8a6d1b');
    var cls=err?'err':((j.classes>0)?'done':'');
    return [(j.classes>0?'✅ ':(err?'⚠️ ':'• '))+txt,color,cls];
  }
  function commitOne(bid,types){
    var fd=new FormData(); fd.append('csrf_token',CSRF); fd.append('id_bien',bid); fd.append('action','commit');
    if(types) fd.append('types',types);
    return fetch(URL,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();});
  }
  function markOk(bid){
    var row=document.getElementById('ob-row-'+bid);
    if(row){ row.setAttribute('data-status','1'); var cell=row.children[4]; if(cell) cell.innerHTML='<span class="badge-ok">OK ✓</span>';
      var c=row.querySelector('.ob-chk'); if(c) c.checked=false; }  // décoche mais garde la case sélectionnable
    obApply(); // recompte les compteurs en direct
  }

  // Classement GROUPÉ PAR PROPRIÉTAIRE : 1 appel par proprio (scan du dossier une
  // seule fois) au lieu d'un scan complet par bien → bien plus rapide, moins de réseau.
  function commitProprio(pid,types){
    var fd=new FormData(); fd.append('csrf_token',CSRF); fd.append('id_proprietaire',pid); fd.append('action','commit');
    if(types) fd.append('types',types);
    return fetch(URL,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();});
  }
  var STOP=false;
  window.obStop=function(){ STOP=true; var b=document.getElementById('obStopBtn'); if(b){b.disabled=true;b.textContent='⏹ Arrêt…';} };
  window.obRun=function(types){
    types=types||'';
    var checked=Array.prototype.slice.call(document.querySelectorAll('.ob-chk:checked'));
    if(!checked.length){alert('Coche au moins un bien.');return;}
    // regroupe les biens cochés par propriétaire
    var groups={}, order=[];
    checked.forEach(function(c){
      var tr=c.closest('tr'); var pid=tr.getAttribute('data-proprio')||'0';
      if(!groups[pid]){ groups[pid]={rows:[],nom:tr.getAttribute('data-pnom')||('#'+pid)}; order.push(pid); }
      groups[pid].rows.push(tr);
    });
    STOP=false;
    var go=document.getElementById('obGo'); if(go) go.disabled=true;
    var sb=document.getElementById('obStopBtn'); if(sb){sb.style.display='';sb.disabled=false;sb.textContent='⏹ Arrêter';}
    var prog=document.getElementById('obProg');
    var i=0,totalDocs=0,totalErr=0,nbBiens=checked.length;
    var lbl=types?(' ['+types.split(',')[0]+(types.indexOf(',')>0?'…':'')+']'):' [tout]';
    function finish(stp){ if(go)go.disabled=false; if(sb)sb.style.display='none';
      prog.textContent=(stp?'⏹ Arrêté':'✅ Terminé')+lbl+' — '+i+'/'+order.length+' propriétaire(s), '+totalDocs+' doc(s) classé(s)'+(totalErr?(', '+totalErr+' err'):'')+'. Recharge pour mettre à jour les étiquettes.'; }
    function next(){
      if(STOP){finish(true);return;}
      if(i>=order.length){finish(false);return;}
      var pid=order[i], g=groups[pid];
      prog.textContent='Propriétaire '+(i+1)+'/'+order.length+lbl+' — '+g.nom+' ('+g.rows.length+' bien[s])…';
      g.rows.forEach(function(tr){ setRes(tr.id.replace('ob-row-',''),'⏳ '+g.nom+'…','#8a6d1b','',false); });
      commitProprio(pid,types).then(function(j){
        if(j&&j.ok){
          totalDocs+=(j.classes||0); totalErr+=((j.erreurs&&j.erreurs.length)||0);
          var f=fmt(j);
          var html=esc(f[0]);
          if(j.pile>0){ html+=' · <a href="'+PFICHE+'?id='+pid+'&tab=pile&scan=1" target="_blank" style="color:#b45309;font-weight:800;">'+j.pile+' pile →</a>'; }
          g.rows.forEach(function(tr){ setRes(tr.id.replace('ob-row-',''),html,f[1],f[2],true,true); });
          markSearched(pid,true);   // scan complet réussi → marqué « cherché »
        } else {
          totalErr++;
          g.rows.forEach(function(tr){ setRes(tr.id.replace('ob-row-',''),'❌ '+((j&&j.error)||'échec'),'#c62828','err',true); });
        }
      }).catch(function(){ totalErr++;
        g.rows.forEach(function(tr){ setRes(tr.id.replace('ob-row-',''),'❌ réseau','#c62828','err',true); });
      }).finally(function(){ i++; next(); });
    }
    next();
  };

  // Export CSV (toutes les lignes du mode courant)
  window.obExportCsv=function(){
    var head=['Réf','Propriétaire','Adresse',LABEL];
    if(MODE==='baux') head.push('Locataire');
    var lines=[head.join(';')];
    rows().forEach(function(tr){
      var ref=(tr.querySelector('a')||{}).textContent||''; ref=ref.replace(/ ↗$/,'').trim();
      var prop=tr.getAttribute('data-prop')||'';
      var addr=tr.getAttribute('data-addr')||'';
      var st=tr.getAttribute('data-status')==='1'?'OK':'VIDE';
      var row=[ref,prop,addr,st];
      if(MODE==='baux'){ var s=tr.getAttribute('data-search')||''; row.push(''); }
      lines.push(row.map(function(v){return '"'+String(v).replace(/"/g,'""')+'"';}).join(';'));
    });
    var blob=new Blob(["﻿"+lines.join('\r\n')],{type:'text/csv;charset=utf-8;'});
    var a=document.createElement('a'); a.href=URL_create(blob); a.download='docs_'+MODE+'_63.csv'; a.click();
    setTimeout(function(){URL_revoke(a.href);},1000);
  };
  var URL_create=function(b){return (window.URL||window.webkitURL).createObjectURL(b);};
  var URL_revoke=function(u){try{(window.URL||window.webkitURL).revokeObjectURL(u);}catch(e){}};

  // Aperçu (scan dry-run)
  window.obVoir=function(bid,ref){
    var m=document.getElementById('obModal'), b=document.getElementById('obModalBody');
    document.getElementById('obModalTitle').textContent='👁 Bien '+ref+' — docs OneDrive (aperçu)';
    b.innerHTML='<div style="color:#6b7280;padding:24px;text-align:center;">⏳ Scan du dossier…</div>'; m.style.display='flex';
    var fd=new FormData(); fd.append('csrf_token',CSRF); fd.append('id_bien',bid); fd.append('action','scan');
    fetch(URL,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(function(j){
      if(!j||!j.ok){ b.innerHTML='<div style="color:#c62828;padding:20px;">❌ '+esc((j&&j.error)||'Erreur')+'</div>'; return; }
      var items=j.items||[];
      var tr=items.map(function(it){
        var col=it.status==='certain'?'#2d8a4e':(it.status==='pile'?'#8a6d1b':'#c62828');
        var cible=it.target==='BAIL'?('bail #'+it.bail_id):(it.target==='BIEN'?'ce bien':(it.target==='PROPRIO'?'propriétaire':'pile'));
        return '<tr><td style="padding:5px 8px;"><b>'+esc(it.type)+'</b><div style="color:#9a9690;font-size:10.5px;">'+esc(it.doc_type||'')+'</div></td>'
          +'<td style="padding:5px 8px;">'+esc(it.name)+'<div style="color:#5b21b6;font-size:11px;">↳ '+esc(it.name_display||'')+'</div></td>'
          +'<td style="padding:5px 8px;">'+esc(cible)+'</td><td style="padding:5px 8px;color:'+col+';font-weight:700;">'+esc(it.status)+'</td>'
          +'<td style="padding:5px 8px;color:#7a766f;font-size:11.5px;">'+esc(it.reason||'')+'</td></tr>';
      }).join('');
      var nbC=items.filter(function(x){return x.status==='certain';}).length;
      b.innerHTML='<div style="margin-bottom:8px;color:#6b7280;">Dossier <b>'+esc(j.folder||'')+'</b> · '+items.length+' doc(s) · <b>'+nbC+'</b> certain(s). <i>(aperçu — rien classé)</i></div>'
        +'<table style="width:100%;border-collapse:collapse;font-size:12.5px;"><thead><tr style="background:#ede7f6;color:#4527a0;text-align:left;">'
        +'<th style="padding:6px 8px;">Type</th><th style="padding:6px 8px;">Fichier</th><th style="padding:6px 8px;">Cible</th><th style="padding:6px 8px;">Statut</th><th style="padding:6px 8px;">Détail</th></tr></thead><tbody>'
        +(tr||'<tr><td colspan="5" style="padding:14px;color:#9a9690;">Aucun document reconnu.</td></tr>')+'</tbody></table>';
    }).catch(function(e){ b.innerHTML='<div style="color:#c62828;padding:20px;">❌ Réseau : '+esc(e)+'</div>'; });
  };

  // Applique filtres + compteurs au chargement (prend en compte « déjà cherché »)
  obApply();
})();
</script>
<?php
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
