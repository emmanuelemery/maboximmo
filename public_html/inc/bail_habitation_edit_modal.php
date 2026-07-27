<?php
/**
 * inc/bail_habitation_edit_modal.php — Modal d'édition du BAIL HABITATION NU (loi 89-462).
 * Gauche : formulaire des champs à remplir (groupés). Droite : aperçu LIVE du bail (accordéon :
 * articles sans blanc = repliés / lecture seule ; articles avec un blanc « … » = dépliés).
 *
 * Usage : bail_habitation_modal();  puis JS bailHabOpenModal({bien_id, bail_id?, values?})
 */
declare(strict_types=1);

if (!function_exists('bail_habitation_modal')) {
    function bail_habitation_modal(): void
    {
        $prev = function_exists('app_url') ? app_url('/api/bail_habitation_preview.php') : '/api/bail_habitation_preview.php';
        $save = function_exists('app_url') ? app_url('/api/bail_habitation_save.php')   : '/api/bail_habitation_save.php';
        ?>
<div id="bhModal" class="bh-modal" hidden>
  <div class="bh-dialog">
    <div class="bh-top">
      <div><b id="bh-title">🏠 Projet de bail habitation</b> <span class="bh-mut">loi n° 89-462 · modèle FNAIM</span></div>
      <div style="display:flex;gap:8px;align-items:center;">
        <span id="bh-msg" class="bh-mut"></span>
        <button type="button" class="bh-btn bh-btn-p" id="bh-save" onclick="bailHabSave()">💾 Enregistrer</button>
        <button type="button" class="bh-btn" onclick="bailHabClose()">✕ Fermer</button>
      </div>
    </div>
    <div class="bh-cols">
      <div class="bh-form" id="bh-form"></div>
      <div class="bh-preview"><div id="bh-pv"><p class="bh-mut" style="padding:20px;">Renseigne les champs — l'aperçu s'affiche ici.</p></div></div>
    </div>
  </div>
</div>
<style>
.bh-modal{position:fixed;inset:0;z-index:9500;background:rgba(18,23,33,.55);display:flex;align-items:center;justify-content:center;padding:14px;}
.bh-modal[hidden]{display:none;}
.bh-dialog{background:#fff;border-radius:14px;width:min(1300px,97vw);height:92vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.35);}
.bh-top{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid #eef0f2;}
.bh-mut{font-size:11px;color:#8a8680;font-style:italic;}
.bh-cols{flex:1;display:flex;min-height:0;}
.bh-form{width:430px;flex:none;overflow:auto;padding:12px 14px;background:#faf9fb;border-right:1px solid #ece7f5;}
.bh-preview{flex:1;overflow:auto;background:#f4f6f9;padding:14px;}
.bh-grp{background:#fff;border:1px solid #ece7f5;border-radius:9px;margin-bottom:9px;overflow:hidden;}
.bh-grp>h4{margin:0;font-size:12px;font-weight:800;color:#243B5C;padding:9px 12px;cursor:pointer;background:#f3f0fa;user-select:none;}
.bh-grp>h4::before{content:'▾ ';color:#a99fc4;}
.bh-grp.closed>h4::before{content:'▸ ';}
.bh-grp.closed>.bh-grp-b{display:none;}
.bh-grp-b{padding:10px 12px;}
.bh-f{display:block;margin-bottom:9px;}
.bh-f>span{display:block;font-size:11px;font-weight:700;color:#5a5650;margin-bottom:3px;}
.bh-f input,.bh-f select,.bh-f textarea{width:100%;padding:7px 9px;border:1px solid #cbd5e1;border-radius:7px;font-size:13px;box-sizing:border-box;font-family:inherit;}
.bh-row{display:flex;gap:8px;} .bh-row>.bh-f{flex:1;}
.bh-btn{border:1px solid #d6dade;background:#eceef1;color:#374151;border-radius:7px;padding:7px 13px;font-weight:800;cursor:pointer;font-size:12.5px;}
.bh-btn-p{border:none;background:#84A7AB;color:#fff;}
/* aperçu accordéon */
#bh-pv .bh-pdf{background:#fff;padding:22px 26px;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.08);font-family:"Times New Roman",serif;font-size:13px;line-height:1.5;color:#1c2226;}
#bh-pv .bh-acc>.bh-acc-h{cursor:pointer;user-select:none;}
#bh-pv .bh-acc:not(.open)>.bh-acc-b{display:none;}
#bh-pv .bh-acc>.bh-acc-h::before{content:'▸ ';color:#b9b3a8;}
#bh-pv .bh-acc.open>.bh-acc-h::before{content:'▾ ';}
#bh-pv .bh-acc-todo>.bh-acc-h{color:#b45309;}
</style>
<script>
(function(){
  var PREV=<?= json_encode($prev, JSON_UNESCAPED_SLASHES) ?>, SAVE=<?= json_encode($save, JSON_UNESCAPED_SLASHES) ?>;
  var M={bienId:0,bailId:0}, _pvTimer=null, _pvSeq=0;
  var g=function(id){return document.getElementById(id);};

  // Définition des groupes/champs (label, id colonne, type).
  var GROUPS=[
    ['Locataire',[
      ['select','locataire_type','Type',[['physique','Personne physique'],['societe','Société']]],
      ['text','locataire_nom','Nom'],['text','locataire_prenom','Prénom'],
      ['text','locataire_raison_sociale','Raison sociale (si société)'],
      ['date','locataire_date_naissance','Né(e) le'],['text','locataire_lieu_naissance','À (lieu de naissance)'],
      ['text','locataire_nationalite','Nationalité'],
      ['text','locataire_email','Email'],['text','locataire_telephone','Téléphone'],
      ['text','locataire_adresse','Adresse actuelle']
    ]],
    ['Bailleur',[
      ['text','bailleur_representant_nom','Représentant du bailleur (si ≠ propriétaire)']
    ]],
    ['Logement (objet)',[
      ['num','surface_habitable','Surface habitable (m²)'],['int','nb_pieces','Nombre de pièces'],
      ['text','dpe_classe','Classe DPE'],['text','equipement_tic','Équipement accès internet/TIC'],
      ['check','en_copropriete','En copropriété'],['text','lot_copropriete','Lot de copropriété'],
      ['text','lot_tantiemes','Tantièmes'],['check','colocation','Colocation']
    ]],
    ['Durée',[
      ['date','date_prise_effet','Date de prise d\'effet'],
      ['date','prorata_date_debut','Date de début (prorata)']
    ]],
    ['Loyer & révision',[
      ['num','loyer_mensuel_hc','Loyer mensuel HC (€)'],
      ['check','zone_tendue','Zone tendue'],
      ['num','loyer_reference','Loyer de référence (€/m²)'],['num','loyer_reference_majore','Loyer réf. majoré (€/m²)'],
      ['num','complement_loyer','Complément de loyer (€)'],['text','complement_loyer_caracteristiques','Caractéristiques du complément'],
      ['num','dernier_loyer_montant','Dernier loyer précédent (€)'],
      ['date','dernier_loyer_date_versement','Date de versement (dernier)'],['date','dernier_loyer_date_revision','Dernière révision (dernier)'],
      ['text','date_revision_jour_mois','Date de révision (JJ/MM)'],
      ['text','indice_trimestre','Trimestre IRL de référence'],['num','indice_valeur','Valeur de l\'indice IRL']
    ]],
    ['Charges',[
      ['num','charges_mensuelles','Provision/forfait charges (€)'],
      ['select','charges_type','Type',[['provisions','Provisions (régularisées)'],['forfait','Forfait']]],
      ['int','teom_annee','TEOM — année'],['num','teom_montant','TEOM — montant (€)'],
      ['num','assurance_colocataires_mensuel','Assurance colocataires (€/mois)']
    ]],
    ['Paiement',[
      ['text','paiement_jour','Payable au plus tard le (jour du mois)'],
      ['text','paiement_beneficiaire','Entre les mains de (bénéficiaire)']
    ]],
    ['Dépôt de garantie',[
      ['num','depot_garantie','Dépôt de garantie (€)']
    ]],
    ['Honoraires de location',[
      ['num','honoraires_plafond_visite_m2','Plafond visite/dossier/rédaction (€/m²)'],
      ['num','honoraires_plafond_edl_m2','Plafond état des lieux (€/m²)'],
      ['num','hono_bailleur_visite','Bailleur — visite/dossier/rédaction (€ TTC)'],
      ['num','hono_bailleur_entremise','Bailleur — entremise/négociation (€ TTC)'],
      ['num','hono_bailleur_edl','Bailleur — état des lieux (€ TTC)'],
      ['num','hono_locataire_visite','Locataire — visite/dossier/rédaction (€ TTC)'],
      ['num','hono_locataire_edl','Locataire — état des lieux (€ TTC)']
    ]],
    ['Dépenses énergétiques',[
      ['num','depenses_energie_min','Estimation min (€/an)'],['num','depenses_energie_max','Estimation max (€/an)'],
      ['int','depenses_energie_annee','Année de référence']
    ]],
    ['Travaux',[
      ['text','travaux_realises_3ans','Travaux réalisés'],['text','travaux_prevus_3ans','Travaux à réaliser']
    ]],
    ['Signature',[
      ['text','lieu_signature','Fait à (lieu)']
    ]]
  ];

  function buildForm(){
    var f=g('bh-form'); f.innerHTML='';
    GROUPS.forEach(function(grp,gi){
      var box=document.createElement('div'); box.className='bh-grp'+(gi>0?' closed':'');
      var h=document.createElement('h4'); h.textContent=grp[0]; h.onclick=function(){box.classList.toggle('closed');};
      var b=document.createElement('div'); b.className='bh-grp-b';
      grp[1].forEach(function(fd){
        var type=fd[0], col=fd[1], lbl=fd[2];
        var wrap=document.createElement('label'); wrap.className='bh-f';
        if(type==='check'){
          wrap.innerHTML='<span style="display:inline-flex;align-items:center;gap:6px;"><input type="checkbox" id="bh-'+col+'" style="width:auto;"> '+lbl+'</span>';
        } else {
          var inp;
          if(type==='select'){ inp='<select id="bh-'+col+'">'+fd[3].map(function(o){return '<option value="'+o[0]+'">'+o[1]+'</option>';}).join('')+'</select>'; }
          else if(type==='date'){ inp='<input type="date" id="bh-'+col+'">'; }
          else if(type==='num'){ inp='<input type="number" step="0.01" id="bh-'+col+'">'; }
          else if(type==='int'){ inp='<input type="number" step="1" id="bh-'+col+'">'; }
          else { inp='<input type="text" id="bh-'+col+'">'; }
          wrap.innerHTML='<span>'+lbl+'</span>'+inp;
        }
        b.appendChild(wrap);
      });
      box.appendChild(h); box.appendChild(b); f.appendChild(box);
    });
    // liaison : toute saisie rafraîchit l'aperçu (debounce).
    f.querySelectorAll('input,select,textarea').forEach(function(el){
      el.addEventListener('input', schedulePreview); el.addEventListener('change', schedulePreview);
    });
  }

  function payload(){
    var p={bail_id:M.bailId||0, bien_id:M.bienId||0};
    GROUPS.forEach(function(grp){ grp[1].forEach(function(fd){
      var col=fd[1], el=g('bh-'+col); if(!el) return;
      if(fd[0]==='check') p[col]=el.checked?1:0;
      else p[col]=el.value;
    });});
    return p;
  }

  function schedulePreview(){ clearTimeout(_pvTimer); _pvTimer=setTimeout(renderPreview,350); }
  function renderPreview(){
    var box=g('bh-pv'); var seq=++_pvSeq; box.style.opacity='0.5';
    fetch(PREV,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload())})
      .then(function(r){return r.json();}).then(function(j){
        if(seq!==_pvSeq) return; box.style.opacity='';
        if(j&&j.ok){ box.innerHTML='<div class="bh-pdf">'+j.html+'</div>'; accordion(box); }
        else box.innerHTML='<p class="bh-mut" style="color:#c0392b;padding:16px;">Aperçu : '+((j&&j.error)||'erreur')+'</p>';
      }).catch(function(e){ if(seq===_pvSeq){ box.style.opacity=''; } });
  }
  // Replie les articles (h3) ; déplie ceux qui contiennent encore un blanc « … » à compléter.
  function accordion(box){
    var view=box.querySelector('.bh-pdf'); if(!view) return;
    var nodes=Array.prototype.slice.call(view.childNodes), body=null;
    nodes.forEach(function(n){
      if(n.nodeType===1 && n.tagName==='H3'){
        var sec=document.createElement('div'); sec.className='bh-acc';
        var head=document.createElement('div'); head.className='bh-acc-h'; head.innerHTML=n.innerHTML;
        body=document.createElement('div'); body.className='bh-acc-b';
        sec.appendChild(head); sec.appendChild(body); view.insertBefore(sec,n); view.removeChild(n);
        head.addEventListener('click',function(){sec.classList.toggle('open');});
      } else if(n.nodeType===1 && n.tagName==='H2'){ body=null; }
      else if(body){ body.appendChild(n); }
    });
    var todo=/…{2,}|………/;
    Array.prototype.forEach.call(view.querySelectorAll('.bh-acc'),function(sec){
      var b=sec.querySelector('.bh-acc-b');
      if(b && /…/.test(b.textContent||'')){ sec.classList.add('open','bh-acc-todo'); }
    });
  }

  window.bailHabSave=function(){
    var btn=g('bh-save'), msg=g('bh-msg'); btn.disabled=true; msg.style.color='#5a5650'; msg.textContent='⏳ Enregistrement…';
    fetch(SAVE,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload())})
      .then(function(r){return r.json();}).then(function(j){
        btn.disabled=false;
        if(j&&j.ok){ M.bailId=j.bail_id; msg.style.color='#15803d'; msg.textContent='✅ Enregistré (bail #'+j.bail_id+')';
          window.BAIL_HAB_SAVED=j.bail_id; }
        else { msg.style.color='#c0392b'; msg.textContent='❌ '+((j&&j.error)||'Échec'); }
      }).catch(function(e){ btn.disabled=false; msg.style.color='#c0392b'; msg.textContent='❌ Réseau : '+e; });
  };
  window.bailHabClose=function(){ g('bhModal').hidden=true; if(window.BAIL_HAB_SAVED && typeof window.bailHabOnClose==='function') window.bailHabOnClose(window.BAIL_HAB_SAVED); };
  window.bailHabOpenModal=function(pf){
    pf=pf||{}; M.bienId=parseInt(pf.bien_id,10)||0; M.bailId=parseInt(pf.bail_id,10)||0; window.BAIL_HAB_SAVED=0;
    if(!g('bh-form').children.length) buildForm();
    // reset
    g('bh-form').querySelectorAll('input,select').forEach(function(el){ if(el.type==='checkbox') el.checked=false; else el.value=''; });
    var v=pf.values||{};
    Object.keys(v).forEach(function(k){ var el=g('bh-'+k); if(!el) return; if(el.type==='checkbox') el.checked=!!parseInt(v[k],10); else if(v[k]!=null) el.value=v[k]; });
    g('bh-title').textContent = M.bailId ? '🏠 Modifier le bail habitation #'+M.bailId : '🏠 Nouveau bail habitation';
    g('bh-msg').textContent='';
    g('bhModal').hidden=false;
    renderPreview();
  };
})();
</script>
        <?php
    }
}
