<?php
/**
 * inc/dpe_edit_modal.php — Modal de saisie des diagnostics (DPE) :
 *   • À GAUCHE : aperçu du PDF sans navigation de pages (#toolbar=0&navpanes=0).
 *   • À DROITE : recherche d'immeuble + boutons de LOTS de l'immeuble (choix rapide
 *                du bien) + tous les champs DPE à remplir, immeuble & bien modifiables.
 *
 * Usage : inclure une fois, puis ouvrir avec
 *   dpeDiagOpen({
 *     title, pdf_url|ged_document_id,
 *     id_immeuble?, immeuble_label?, id_bien?,
 *     fields?: { dpe_classe, ... }     // pré-remplissage (ex. issu de dpe_analyser)
 *   })
 * Sauvegarde : POST api/dpe_diag_save.php (→ dpe_enregistrer, service central).
 */
$ddCsrfGedGraph = function_exists('csrf_token') ? csrf_token('ged_graph') : '';
?>
<div id="ddBackdrop" class="dd-backdrop" onclick="if(event.target===this)dpeDiagClose()">
  <div class="dd-modal">
    <div class="dd-head">
      <h3 id="ddTitle">🌡️ Diagnostic DPE</h3>
      <div style="display:flex;gap:8px;align-items:center;">
        <button id="ddAnalyseBtn" class="dd-btn-ana" onclick="dpeDiagAnalyse('regex')" title="Extraction gratuite (texte + regex, aucune IA)">🔍 Analyser (gratuit)</button>
        <button id="ddAnalyseIaBtn" class="dd-btn-ia" onclick="dpeDiagAnalyse('ia')" title="Complément par IA / OCR — coût IA, à utiliser seulement si l'extraction gratuite est incomplète">✨ Compléter par IA</button>
        <span id="ddAnaMsg" style="font-size:12px;font-weight:700;"></span>
        <a id="ddOpen" href="#" target="_blank" class="dd-btn-sec">↗ Ouvrir le PDF</a>
        <button class="dd-btn-sec" onclick="dpeDiagClose()">✕ Fermer</button>
      </div>
    </div>
    <div class="dd-cols">
      <!-- Visuel PDF (gauche) -->
      <div class="dd-body"><iframe id="ddFrame" title="DPE"></iframe></div>

      <!-- Champs (droite) -->
      <aside class="dd-fields">
        <form id="ddForm" onsubmit="return false;">
          <input type="hidden" id="dd_id_bien">
          <input type="hidden" id="dd_id_immeuble">
          <input type="hidden" id="dd_ged_document_id">
          <input type="hidden" id="dd_onedrive_item_id">

          <div class="dd-sec">Immeuble</div>
          <div class="dd-search-wrap">
            <input id="dd_imm_search" autocomplete="off" placeholder="Rechercher un immeuble (adresse, ville, réf)…">
            <div id="dd_imm_results" class="dd-results"></div>
          </div>
          <div id="dd_imm_selected" class="dd-imm-selected" style="display:none;"></div>

          <div class="dd-sec">Lot / bien <span id="dd_lot_hint" style="color:#9a9690;font-weight:400;"></span></div>
          <div id="dd_lots" class="dd-lots"><span class="dd-muted">Sélectionnez d'abord un immeuble.</span></div>

          <div class="dd-sec">DPE — étiquettes</div>
          <div class="dd-row2">
            <label>Classe DPE
              <select id="dd_dpe_classe"><option value=""></option><option>A</option><option>B</option><option>C</option><option>D</option><option>E</option><option>F</option><option>G</option></select>
            </label>
            <label>Classe GES
              <select id="dd_ges_classe"><option value=""></option><option>A</option><option>B</option><option>C</option><option>D</option><option>E</option><option>F</option><option>G</option></select>
            </label>
          </div>
          <label class="dd-chk"><input type="checkbox" id="dd_dpe_vierge"> DPE vierge (sans étiquette)</label>

          <div class="dd-sec">DPE — valeurs</div>
          <div class="dd-row2">
            <label>Conso énergie (kWh/m²/an)<input type="number" id="dd_dpe_valeur"></label>
            <label>Émission GES (kgCO₂/m²/an)<input type="number" id="dd_ges_valeur"></label>
          </div>
          <div class="dd-row2">
            <label>Conso primaire<input type="number" step="0.01" id="dd_dpe_valeur_conso_primaire"></label>
            <label>Conso finale<input type="number" step="0.01" id="dd_dpe_valeur_conso_finale"></label>
          </div>

          <div class="dd-sec">DPE — identité</div>
          <div class="dd-row2">
            <label>N° ADEME<input id="dd_dpe_reference_certificat" placeholder="ex. 2369E1234567A"></label>
            <label>Version
              <select id="dd_dpe_version"><option value=""></option><option value="2021">2021</option><option value="2011">2011</option></select>
            </label>
          </div>
          <div class="dd-row2">
            <label>Date de réalisation<input type="date" id="dd_dpe_date_realisation"></label>
            <label>Date indice prix énergies<input type="date" id="dd_date_indice_prix_energies"></label>
          </div>

          <div class="dd-sec">Bien (modifiable)</div>
          <div class="dd-row2">
            <label>Surface habitable (m²)<input type="number" step="0.01" id="dd_surface_habitable"></label>
            <label>Surface Carrez (m²)<input type="number" step="0.01" id="dd_surface_carrez"></label>
          </div>
          <div class="dd-row2">
            <label>Nb pièces<input type="number" id="dd_nb_pieces"></label>
            <label>Énergie chauffage<input id="dd_chauffage_energie" placeholder="gaz, électricité…"></label>
          </div>
        </form>
        <div class="dd-save">
          <button class="dd-btn-go" id="ddSaveBtn" onclick="dpeDiagSave()">💾 Enregistrer le DPE sur le lot</button>
          <div id="ddMsg" style="font-size:12px;font-weight:700;margin-top:8px;"></div>
        </div>
      </aside>
    </div>
  </div>
</div>
<style>
.dd-backdrop{position:fixed;inset:0;z-index:9100;background:rgba(15,18,24,.55);}
.dd-backdrop:not(.open){display:none!important;} .dd-backdrop.open{display:flex;align-items:center;justify-content:center;}
.dd-modal{background:#fff;border-radius:14px;width:min(1280px,96vw);max-height:94vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.35);}
.dd-head{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid #eef0f2;}
.dd-head h3{margin:0;font-size:16px;}
.dd-cols{flex:1;display:flex;min-height:0;overflow:hidden;}
.dd-body{flex:1;background:#f8fafc;}
.dd-body iframe{width:100%;height:84vh;border:0;display:block;}
.dd-fields{width:410px;flex:none;overflow:auto;background:#f1f8f4;border-left:1px solid #d9eadf;padding:12px 14px;}
.dd-fields label{display:block;font-size:11px;color:#5a6b60;font-weight:700;margin:8px 0 0;}
.dd-fields input,.dd-fields select{width:100%;padding:6px 8px;border:1px solid #cdd7d1;border-radius:6px;font-size:13px;margin-top:2px;box-sizing:border-box;background:#fff;}
.dd-sec{font-size:11px;font-weight:800;color:#1f7a46;text-transform:uppercase;letter-spacing:.03em;margin:14px 0 4px;padding-bottom:3px;border-bottom:1px solid #d9eadf;}
.dd-sec:first-of-type{margin-top:0;}
.dd-row2{display:flex;gap:8px;} .dd-row2 label{flex:1;}
.dd-chk{display:flex!important;align-items:center;gap:6px;font-weight:700;margin-top:8px;}
.dd-chk input{width:auto!important;margin:0!important;}
.dd-muted{color:#9a9690;font-size:12px;}
.dd-search-wrap{position:relative;}
.dd-results{position:absolute;left:0;right:0;top:100%;background:#fff;border:1px solid #cdd7d1;border-radius:0 0 8px 8px;max-height:240px;overflow:auto;z-index:5;display:none;box-shadow:0 8px 18px rgba(0,0,0,.12);}
.dd-results.show{display:block;}
.dd-results .dd-r{padding:7px 10px;cursor:pointer;font-size:12px;border-bottom:1px solid #f0f3f1;}
.dd-results .dd-r:hover{background:#eaf6ef;}
.dd-results .dd-r b{color:#1f7a46;}
.dd-imm-selected{margin-top:8px;background:#fff;border:1px solid #cdd7d1;border-radius:8px;padding:8px 10px;font-size:12px;display:flex;justify-content:space-between;align-items:center;gap:8px;}
.dd-imm-selected .dd-change{font-size:11px;color:#1f7a46;cursor:pointer;text-decoration:underline;white-space:nowrap;}
.dd-lots{display:flex;flex-wrap:wrap;gap:6px;}
.dd-lot{border:1px solid #cdd7d1;background:#fff;border-radius:8px;padding:6px 9px;font-size:12px;font-weight:700;cursor:pointer;color:#374151;}
.dd-lot:hover{border-color:#1f7a46;background:#eaf6ef;}
.dd-lot.active{background:#1f7a46;color:#fff;border-color:#1f7a46;}
.dd-lot .dd-lot-dpe{font-size:10px;opacity:.7;margin-left:4px;}
.dd-save{text-align:center;margin-top:16px;padding-top:14px;border-top:1px solid #d9eadf;}
.dd-btn-go{background:#1f7a46;color:#fff;border:none;border-radius:9px;padding:11px 22px;font-weight:800;cursor:pointer;font-size:14px;}
.dd-btn-go:hover{background:#176038;} .dd-btn-go:disabled{opacity:.5;}
.dd-btn-sec{padding:6px 12px;background:#eceef1;border:1px solid #d6dade;color:#374151;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700;text-decoration:none;}
.dd-btn-ana{padding:6px 12px;background:#1f7a46;border:1px solid #176038;color:#fff;border-radius:6px;cursor:pointer;font-size:12px;font-weight:800;}
.dd-btn-ana:hover{background:#176038;} .dd-btn-ana:disabled{opacity:.5;cursor:default;}
.dd-btn-ia{padding:6px 12px;background:#fff;border:1px solid #b48bd8;color:#6b21a8;border-radius:6px;cursor:pointer;font-size:12px;font-weight:800;}
.dd-btn-ia:hover{background:#f6eefc;} .dd-btn-ia:disabled{opacity:.5;cursor:default;}
@media(max-width:900px){.dd-cols{flex-direction:column;}.dd-fields{width:auto;max-height:46vh;}.dd-body iframe{height:46vh;}}
</style>
<script>
(function(){
  var CSRF_GED=<?= json_encode($ddCsrfGedGraph, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  // Champs <input>/<select> simples (id = dd_<key>) mappés vers fields[key]
  var FIELDS=['dpe_classe','ges_classe','dpe_valeur','ges_valeur','dpe_valeur_conso_primaire',
    'dpe_valeur_conso_finale','dpe_reference_certificat','dpe_version','dpe_date_realisation',
    'date_indice_prix_energies','surface_habitable','surface_carrez','nb_pieces','chauffage_energie'];
  function $(id){ return document.getElementById(id); }
  function val(v){ return (v===null||v===undefined)?'':String(v); }
  var _searchTimer=null;

  window.dpeDiagOpen=function(d){
    d=d||{};
    // Le modal doit être un enfant DIRECT de <body> : sinon un ancêtre du layout
    // avec transform/overflow casse le position:fixed (modal décalé/caché à gauche).
    var bd=$('ddBackdrop'); if(bd && bd.parentNode!==document.body) document.body.appendChild(bd);
    $('ddTitle').textContent = d.title || '🌡️ Diagnostic DPE';
    // Reset
    FIELDS.forEach(function(f){ var el=$('dd_'+f); if(el) el.value=''; });
    $('dd_dpe_vierge').checked=false;
    $('dd_id_bien').value=''; $('dd_id_immeuble').value=''; $('dd_ged_document_id').value=d.ged_document_id||'';
    $('dd_onedrive_item_id').value=d.onedrive_item_id||'';
    $('ddMsg').textContent=''; $('ddAnaMsg').textContent='';
    // Boutons d'analyse : disponibles si on a une source (GED ou fichier OneDrive)
    var showAna = (d.ged_document_id || d.onedrive_item_id) ? '' : 'none';
    $('ddAnalyseBtn').style.display = showAna;
    $('ddAnalyseIaBtn').style.display = showAna;
    $('dd_imm_search').value=''; $('dd_imm_results').classList.remove('show');
    $('dd_imm_selected').style.display='none'; $('dd_imm_selected').innerHTML='';
    $('dd_lots').innerHTML='<span class="dd-muted">Sélectionnez d\'abord un immeuble.</span>';
    $('dd_lot_hint').textContent='';

    // Pré-remplissage des champs (ex. extraits du PDF)
    var fields=d.fields||{};
    FIELDS.forEach(function(f){ var el=$('dd_'+f); if(el && fields[f]!==undefined && fields[f]!==null) el.value=val(fields[f]); });
    if(fields.dpe_vierge!==undefined) $('dd_dpe_vierge').checked=!!(+fields.dpe_vierge);

    // PDF à gauche. PRIORITÉ au fichier OneDrive servi EN INLINE via notre proxy
    // (l'URL @microsoft.graph.downloadUrl forcerait un téléchargement hors modal).
    var hash='#toolbar=0&navpanes=0&scrollbar=0&view=FitH', src='about:blank', openHref='#';
    if(d.onedrive_item_id){
      var pu='/api/dpe_lyon_pdf_proxy.php?item_id='+encodeURIComponent(d.onedrive_item_id);
      src=pu+hash; openHref=pu;
    } else if(d.ged_document_id){
      src='/api/ged_doc_serve.php?id='+d.ged_document_id+hash; openHref='/api/ged_document_view.php?id='+d.ged_document_id+'&mode=inline';
    } else if(d.pdf_url){
      src=d.pdf_url + (d.pdf_url.indexOf('#')<0?hash:''); openHref=d.pdf_url;
    }
    $('ddFrame').src=src; $('ddOpen').href=openHref;

    // Immeuble pré-sélectionné
    if(d.id_immeuble){ ddSelectImmeuble(d.id_immeuble, d.immeuble_label||'', d.id_bien||0); }

    $('ddBackdrop').classList.add('open');
  };

  window.dpeDiagClose=function(){ $('ddBackdrop').classList.remove('open'); $('ddFrame').src='about:blank'; };

  // ── Recherche immeuble (autocomplete) ──
  $('dd_imm_search').addEventListener('input',function(){
    var q=this.value.trim(); var box=$('dd_imm_results');
    if(q.length<2){ box.classList.remove('show'); box.innerHTML=''; return; }
    clearTimeout(_searchTimer);
    _searchTimer=setTimeout(function(){
      fetch('/api/ik_search_immeubles.php?q='+encodeURIComponent(q),{credentials:'same-origin'})
        .then(r=>r.json()).then(function(rows){
          if(!Array.isArray(rows)||!rows.length){ box.innerHTML='<div class="dd-r dd-muted">Aucun immeuble</div>'; box.classList.add('show'); return; }
          box.innerHTML=rows.map(function(r){
            var lbl=(r.adresse||r.nom_immeuble||'')+' '+(r.code_postal||'')+' '+(r.ville||'');
            return '<div class="dd-r" data-id="'+r.id+'" data-label="'+lbl.replace(/"/g,'&quot;')+'"><b>'+(r.adresse||r.nom_immeuble||'?')+'</b> — '+(r.code_postal||'')+' '+(r.ville||'')+(r.reference_immeuble?' · '+r.reference_immeuble:'')+'</div>';
          }).join('');
          box.classList.add('show');
          box.querySelectorAll('.dd-r[data-id]').forEach(function(el){
            el.addEventListener('click',function(){ ddSelectImmeuble(+el.getAttribute('data-id'), el.getAttribute('data-label'), 0); });
          });
        }).catch(function(){ box.classList.remove('show'); });
    },220);
  });
  document.addEventListener('click',function(e){ if(!e.target.closest('.dd-search-wrap')) $('dd_imm_results').classList.remove('show'); });

  // ── Sélection immeuble + chargement des lots ──
  function ddSelectImmeuble(id, label, preselectBien){
    $('dd_id_immeuble').value=id;
    $('dd_imm_results').classList.remove('show'); $('dd_imm_search').value='';
    $('dd_lots').innerHTML='<span class="dd-muted">Chargement des lots…</span>';
    fetch('/api/immeuble_lots.php?id_immeuble='+id,{credentials:'same-origin'})
      .then(r=>r.json()).then(function(j){
        if(!j||!j.ok){ $('dd_lots').innerHTML='<span class="dd-muted">'+((j&&j.error)||'erreur')+'</span>'; return; }
        var im=j.immeuble;
        var disp=(im.adresse_1||im.nom_immeuble||'')+' '+(im.code_postal||'')+' '+(im.ville||'');
        $('dd_imm_selected').style.display='flex';
        $('dd_imm_selected').innerHTML='<span>🏢 <b>'+disp+'</b></span><span class="dd-change" onclick="document.getElementById(\'dd_imm_search\').focus()">changer</span>';
        $('dd_lot_hint').textContent='('+j.lots.length+' lot'+(j.lots.length>1?'s':'')+')';
        if(!j.lots.length){ $('dd_lots').innerHTML='<span class="dd-muted">Aucun lot sur cet immeuble.</span>'; return; }
        $('dd_lots').innerHTML='';
        j.lots.forEach(function(lot){
          var b=document.createElement('button'); b.type='button'; b.className='dd-lot'; b.dataset.id=lot.id;
          function esc(s){ return String(s||'').replace(/[&<>"]/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
          var sub=[]; if(lot.proprio) sub.push('👤 '+esc(lot.proprio));
          if(lot.reference_bien) sub.push(esc(lot.reference_bien));
          if(lot.surface) sub.push(lot.surface+' m²');
          b.innerHTML=esc(lot.label)+(lot.has_dpe?'<span class="dd-lot-dpe">✓DPE</span>':'')
            +(sub.length?'<div style="font-weight:400;font-size:10px;color:inherit;opacity:.8;">'+sub.join(' · ')+'</div>':'');
          b.title=(lot.proprio?('Propriétaire : '+lot.proprio+' — '):'')+'Réf '+(lot.reference_bien||lot.id)+(lot.has_dpe?' — possède déjà un DPE':'');
          b.addEventListener('click',function(){ ddPickLot(lot, b); });
          $('dd_lots').appendChild(b);
        });
        if(preselectBien){ var t=$('dd_lots').querySelector('.dd-lot[data-id="'+preselectBien+'"]'); if(t) t.click(); }
      }).catch(function(){ $('dd_lots').innerHTML='<span class="dd-muted">réseau</span>'; });
  }
  window.ddSelectImmeuble=ddSelectImmeuble;

  function ddPickLot(lot, btn){
    $('dd_id_bien').value=lot.id;
    $('dd_lots').querySelectorAll('.dd-lot').forEach(function(x){ x.classList.remove('active'); });
    btn.classList.add('active');
    // Pré-remplir les champs bien SI vides (ne pas écraser une saisie)
    if(!$('dd_surface_habitable').value && lot.surface) $('dd_surface_habitable').value=lot.surface;
    if(!$('dd_nb_pieces').value && lot.nb_pieces) $('dd_nb_pieces').value=lot.nb_pieces;
  }

  // Écrit les champs DPE sur le bien (avec éventuel ged_document_id du PDF classé).
  function ddSaveFields(bienId, gedDocId, msg, btn){
    var fields={}; FIELDS.forEach(function(f){ var el=$('dd_'+f); if(el && el.value!=='') fields[f]=el.value; });
    fields.dpe_vierge = $('dd_dpe_vierge').checked ? 1 : 0;
    fetch('/api/dpe_diag_save.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({ id_bien:bienId, ged_document_id:gedDocId||0, fields:fields })})
      .then(r=>r.json()).then(function(j){
        btn.disabled=false;
        if(j&&j.ok){ msg.style.color='#1f7a46'; msg.textContent='✅ DPE enregistré sur le bien (diag #'+(j.diag_id||'')+')'+(gedDocId?' · PDF classé en GED (#'+gedDocId+')':'')+'.'; }
        else { msg.style.color='#c62828'; msg.textContent='❌ '+((j&&j.error)||'échec'); }
      }).catch(function(){ btn.disabled=false; msg.style.color='#c62828'; msg.textContent='❌ réseau (enregistrement champs)'; });
  }

  // ── Sauvegarde ──
  // Si le PDF vient de OneDrive (onedrive_item_id), on le CLASSE d'abord en GED
  // (graph_doc_commit, SANS analyse IA → no_analyse=1), puis on écrit les champs.
  window.dpeDiagSave=function(){
    var bienId=+$('dd_id_bien').value, msg=$('ddMsg'), btn=$('ddSaveBtn');
    if(!bienId){ msg.style.color='#c62828'; msg.textContent='⚠️ Choisissez un lot (bien) avant d\'enregistrer.'; return; }
    btn.disabled=true; msg.style.color='#6b7280';
    var itemId=$('dd_onedrive_item_id').value, existingGed=+$('dd_ged_document_id').value||0;

    if(itemId){
      msg.textContent='⏳ Classement du PDF en GED…';
      var fd=new FormData();
      fd.append('bien_id',bienId); fd.append('type_code','DIAG_DPE');
      fd.append('item_id',itemId); fd.append('no_analyse','1'); fd.append('csrf_token',CSRF_GED);
      fetch('/api/graph_doc_commit.php',{method:'POST',credentials:'same-origin',body:fd})
        .then(r=>r.json()).then(function(j){
          if(!j||!j.ok){ btn.disabled=false; msg.style.color='#c62828'; msg.textContent='❌ GED : '+((j&&j.error)||'classement échoué'); return; }
          var gedId=+j.ged_doc_id||0; $('dd_ged_document_id').value=gedId;
          msg.style.color='#6b7280'; msg.textContent='⏳ Enregistrement des champs…';
          ddSaveFields(bienId, gedId, msg, btn);
        }).catch(function(){ btn.disabled=false; msg.style.color='#c62828'; msg.textContent='❌ réseau (GED)'; });
    } else {
      msg.textContent='⏳ Enregistrement…';
      ddSaveFields(bienId, existingGed, msg, btn);
    }
  };

  // ── Extraction : mode 'regex' (gratuit) ou 'ia' (payant, sur demande explicite) ──
  // IMPORTANT : ne se déclenche QUE sur clic. Le mode 'ia' demande confirmation.
  window.dpeDiagAnalyse=function(mode){
    mode = (mode==='ia') ? 'ia' : 'regex';
    var docId=+$('dd_ged_document_id').value, itemId=$('dd_onedrive_item_id').value, msg=$('ddAnaMsg');
    var btn = (mode==='ia') ? $('ddAnalyseIaBtn') : $('ddAnalyseBtn');
    if(!docId && !itemId){ msg.style.color='#c62828'; msg.textContent='Aucune source PDF à analyser.'; return; }
    if(mode==='ia' && !window.confirm('Compléter par IA / OCR ?\n\nCela consomme du crédit IA (coût). À utiliser seulement si l\'extraction gratuite est incomplète.')) return;
    $('ddAnalyseBtn').disabled=true; $('ddAnalyseIaBtn').disabled=true;
    msg.style.color='#6b7280'; msg.textContent = (mode==='ia') ? '⏳ Analyse IA…' : '⏳ Analyse (gratuite)…';
    var src = docId ? ('ged_document_id='+docId) : ('onedrive_item_id='+encodeURIComponent(itemId));
    fetch('/api/dpe_analyse_doc.php?mode='+mode+'&'+src,{credentials:'same-origin'})
      .then(r=>r.json()).then(function(j){
        $('ddAnalyseBtn').disabled=false; $('ddAnalyseIaBtn').disabled=false;
        if(!j||!j.ok){ msg.style.color='#c62828'; msg.textContent='❌ '+((j&&j.error)||'extraction échouée'); return; }
        var f=j.fields||{}, n=0;
        // En IA on ne REMPLACE pas une valeur déjà saisie : on complète les champs vides.
        FIELDS.forEach(function(k){ var el=$('dd_'+k); if(!el) return;
          if(f[k]===undefined||f[k]===null||f[k]==='') return;
          if(mode==='ia' && el.value!=='') return; // complément non destructif
          el.value=val(f[k]); n++; });
        if(f.dpe_vierge!==undefined && !(mode==='ia' && $('dd_dpe_vierge').checked)) $('dd_dpe_vierge').checked=!!(+f.dpe_vierge);
        msg.style.color='#1f7a46';
        msg.textContent='✅ '+n+' champ(s) '+(mode==='ia'?'complétés par IA':'détectés')+' (fiabilité '+(j.score||0)+'%). Vérifiez puis enregistrez.';
      }).catch(function(){ $('ddAnalyseBtn').disabled=false; $('ddAnalyseIaBtn').disabled=false; msg.style.color='#c62828'; msg.textContent='❌ réseau'; });
  };

  document.addEventListener('keydown',function(e){ if(e.key==='Escape')dpeDiagClose(); });
})();
</script>
