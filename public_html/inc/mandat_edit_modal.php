<?php
/**
 * inc/mandat_edit_modal.php — Modal d'édition d'un mandat : champs BDD éditables À GAUCHE,
 * aperçu du PDF À DROITE. Sauvegarde via api/mandat_save.php.
 *
 * Usage : inclure une fois dans la page, puis ouvrir avec
 *   mandatEditOpen({ id, ged_document_id, numero_mandat, ... })  // objet = ligne mandats_registre
 */
$csrfMandat = function_exists('csrf_token') ? csrf_token('mandat_extraire') : '';
?>
<div id="meBackdrop" class="me-backdrop" onclick="if(event.target===this)mandatEditClose()">
  <div class="me-modal">
    <div class="me-head">
      <h3 id="meTitle">📜 Mandat</h3>
      <div style="display:flex;gap:8px;">
        <a id="meOpen" href="#" target="_blank" class="me-btn-sec">↗ Ouvrir le PDF</a>
        <button class="me-btn-sec" onclick="mandatEditClose()">✕ Fermer</button>
      </div>
    </div>
    <div class="me-cols">
      <aside class="me-fields">
        <form id="meForm" onsubmit="return false;">
          <input type="hidden" name="id" id="me_id">
          <div class="me-sec">Identité</div>
          <div class="me-row2">
            <label>N° courant (récent)<input name="numero_mandat" id="me_numero_mandat"></label>
            <label>N° ancien (si 2 n°)<input name="numero_mandat_alt" id="me_numero_mandat_alt" placeholder="ex. 10"></label>
          </div>
          <label>Mandant(s)<input name="mandant_noms" id="me_mandant_noms"></label>
          <label>Mandataire<input name="mandataire" id="me_mandataire"></label>
          <label>Bien<input name="bien_designation" id="me_bien_designation"></label>

          <div class="me-sec">Durée & statut</div>
          <label>Date d'effet<input type="date" name="date_effet" id="me_date_effet"></label>
          <div class="me-row2">
            <label>Durée initiale (ans)<input type="number" name="duree_initiale_ans" id="me_duree_initiale_ans"></label>
            <label>Fin théorique<input type="date" name="date_fin_theorique" id="me_date_fin_theorique"></label>
          </div>
          <div class="me-checks">
            <label class="me-chk"><input type="checkbox" name="duree_ferme" id="me_duree_ferme" value="1"> Durée ferme</label>
            <label class="me-chk"><input type="checkbox" name="tacite_reconduction" id="me_tacite_reconduction" value="1"> Tacite reconduction</label>
          </div>
          <div class="me-row2">
            <label>Période reconduction (ans)<input type="number" name="periode_reconduction_ans" id="me_periode_reconduction_ans"></label>
            <label>Préavis résiliation (mois)<input type="number" name="preavis_resiliation_mois" id="me_preavis_resiliation_mois"></label>
          </div>
          <label>Date de résiliation (si dénoncé)<input type="date" name="date_resiliation" id="me_date_resiliation"></label>
          <label>Statut <span style="color:#9a9690;font-weight:400;">(auto-calculé, modifiable)</span>
            <select name="statut" id="me_statut"><option value="actif">actif</option><option value="termine">terminé</option><option value="inconnu">inconnu</option></select>
          </label>

          <div class="me-sec">Honoraires</div>
          <div class="me-row2">
            <label>Gestion % HT<input type="number" step="0.01" name="hono_gestion_taux_ht" id="me_hono_gestion_taux_ht"></label>
            <label>Gestion % TTC<input type="number" step="0.01" name="hono_gestion_taux_ttc" id="me_hono_gestion_taux_ttc"></label>
          </div>
          <label>Assiette<input name="hono_gestion_assiette" id="me_hono_gestion_assiette" placeholder="encaissements"></label>
          <div class="me-row2">
            <label>Location<input name="hono_location" id="me_hono_location" placeholder="1 mois de loyer"></label>
            <label>Remise location %<input type="number" step="0.01" name="hono_location_remise_pct" id="me_hono_location_remise_pct"></label>
          </div>
          <div class="me-row2">
            <label>Contentieux<input name="hono_contentieux" id="me_hono_contentieux"></label>
            <label>Décl. fiscale €<input type="number" step="0.01" name="hono_declaration_fiscale_eur" id="me_hono_declaration_fiscale_eur"></label>
          </div>
          <label>CRG périodicité<input name="crg_periodicite" id="me_crg_periodicite" placeholder="trimestrielle"></label>
          <label>Travaux sans accord proprio (seuil)<input name="travaux_seuil_autorisation" id="me_travaux_seuil_autorisation" placeholder="1 loyer mensuel / 150 €"></label>
        </form>
        <div class="me-save">
          <button class="me-btn-go" id="meSaveBtn" onclick="mandatEditSave()">💾 Enregistrer</button>
          <div id="meMsg" style="font-size:12px;font-weight:700;margin-top:8px;"></div>
        </div>
      </aside>
      <div class="me-body"><iframe id="meFrame" title="Mandat"></iframe></div>
    </div>
  </div>
</div>
<style>
.me-backdrop{position:fixed;inset:0;z-index:9100;background:rgba(15,18,24,.55);}
.me-backdrop:not(.open){display:none!important;} .me-backdrop.open{display:flex;align-items:center;justify-content:center;}
.me-modal{background:#fff;border-radius:14px;width:min(1240px,96vw);max-height:94vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.35);}
.me-head{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid #eef0f2;}
.me-head h3{margin:0;font-size:16px;}
.me-cols{flex:1;display:flex;min-height:0;overflow:hidden;}
.me-fields{width:380px;flex:none;overflow:auto;background:#faf8ff;border-left:1px solid #ece7f5;padding:12px 14px;order:2;}
.me-body{order:1;}
.me-fields label{display:block;font-size:11px;color:#7a766f;font-weight:700;margin:8px 0 0;}
.me-fields input,.me-fields select{width:100%;padding:6px 8px;border:1px solid #d4d7de;border-radius:6px;font-size:13px;margin-top:2px;box-sizing:border-box;}
.me-sec{font-size:11px;font-weight:800;color:#5b21b6;text-transform:uppercase;letter-spacing:.03em;margin:14px 0 4px;padding-bottom:3px;border-bottom:1px solid #ece7f5;}
.me-sec:first-of-type{margin-top:0;}
.me-row2{display:flex;gap:8px;} .me-row2 label{flex:1;}
.me-checks{display:flex;gap:14px;margin-top:8px;} .me-chk{display:flex!important;align-items:center;gap:6px;font-weight:700;}
.me-chk input{width:auto!important;margin:0!important;}
.me-body{flex:1;background:#f8fafc;} .me-body iframe{width:100%;height:84vh;border:0;display:block;}
.me-save{text-align:center;margin-top:16px;padding-top:14px;border-top:1px solid #ece7f5;}
.me-btn-go{background:#2d8a4e;color:#fff;border:none;border-radius:9px;padding:11px 26px;font-weight:800;cursor:pointer;font-size:14px;}
.me-btn-go:hover{background:#256b3d;} .me-btn-go:disabled{opacity:.5;}
.me-btn-sec{padding:6px 12px;background:#eceef1;border:1px solid #d6dade;color:#374151;border-radius:6px;cursor:pointer;font-size:12px;font-weight:700;text-decoration:none;}
@media(max-width:880px){.me-cols{flex-direction:column;}.me-fields{width:auto;max-height:40vh;}.me-body iframe{height:50vh;}}
</style>
<script>
(function(){
  var CSRF=<?= json_encode($csrfMandat, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var FIELDS=['numero_mandat','numero_mandat_alt','mandant_noms','mandataire','bien_designation','date_effet','duree_initiale_ans',
    'date_fin_theorique','periode_reconduction_ans','preavis_resiliation_mois','date_resiliation','statut',
    'hono_gestion_taux_ht','hono_gestion_taux_ttc','hono_gestion_assiette','hono_location','hono_location_remise_pct',
    'hono_contentieux','hono_declaration_fiscale_eur','crg_periodicite','travaux_seuil_autorisation'];
  function val(v){ return (v===null||v===undefined)?'':String(v); }
  window.mandatEditOpen=function(d){
    document.getElementById('me_id').value=d.id||'';
    document.getElementById('meTitle').textContent='📜 Mandat n° '+(d.numero_mandat||'?')+' — '+(d.proprio_nom||d.mandant_noms||'');
    FIELDS.forEach(function(f){ var el=document.getElementById('me_'+f); if(el) el.value=val(d[f]); });
    document.getElementById('me_duree_ferme').checked = !!(+d.duree_ferme);
    document.getElementById('me_tacite_reconduction').checked = !!(+d.tacite_reconduction);
    var docId=d.ged_document_id||0;
    // #navpanes=0 masque les vignettes des pages, #toolbar=0 la barre — on ne garde QUE le doc.
    document.getElementById('meFrame').src = docId ? ('/api/ged_doc_serve.php?id='+docId+'#toolbar=0&navpanes=0&scrollbar=0&view=FitH') : 'about:blank';
    document.getElementById('meOpen').href = docId ? ('/api/ged_document_view.php?id='+docId+'&mode=inline') : '#';
    document.getElementById('meMsg').textContent='';
    document.getElementById('meBackdrop').classList.add('open');
  };
  window.mandatEditClose=function(){ document.getElementById('meBackdrop').classList.remove('open'); document.getElementById('meFrame').src='about:blank'; };
  window.mandatEditSave=function(){
    var b=document.getElementById('meSaveBtn'), m=document.getElementById('meMsg'); b.disabled=true;
    m.style.color='#6b7280'; m.textContent='⏳ Enregistrement…';
    var fd=new FormData(document.getElementById('meForm')); fd.append('csrf_token',CSRF);
    if(!document.getElementById('me_duree_ferme').checked) fd.set('duree_ferme','0');
    if(!document.getElementById('me_tacite_reconduction').checked) fd.set('tacite_reconduction','0');
    fetch('/api/mandat_save.php',{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(function(j){
      b.disabled=false;
      if(j&&j.ok){ m.style.color='#2d8a4e'; m.textContent='✅ Enregistré (statut: '+(j.statut||'')+'). Recharge pour voir la liste à jour.'; }
      else { m.style.color='#c62828'; m.textContent='❌ '+((j&&j.error)||'échec'); }
    }).catch(function(e){ b.disabled=false; m.style.color='#c62828'; m.textContent='❌ réseau'; });
  };
  document.addEventListener('keydown',function(e){ if(e.key==='Escape')mandatEditClose(); });
})();
</script>
