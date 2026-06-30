<?php
/**
 * avis_echeance_import.php — Import en masse d'avis d'échéance ICS → GED des locataires.
 * Glisser plusieurs PDF : attribution déterministe (sans IA), classement auto si match certain,
 * sinon dépôt dans la pile FluxBox. Réservé staff/manager.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/csrf.php';
require_login();

$pageTitle    = 'Import avis d\'échéance';
$pageSubtitle = 'Ma Box Agency · GED locataires';
$bodyAttr     = 'data-theme-module="transaction"';
include __DIR__ . '/inc/agency_layout_top.php';
?>
<div style="max-width:980px;margin:0 auto;padding:18px;">
  <h1 style="font-size:22px;margin:0 0 6px;">📥 Import en masse — Avis d'échéance</h1>
  <p style="color:#6b7280;font-size:13.5px;margin:0 0 18px;">
    Glissez tous les avis du mois (PDF ICS). Attribution <b>déterministe</b> via mandat + immeuble + nom du locataire —
    <b>sans IA</b>. Match certain → classé direct dans la GED du locataire (lié bail + bien). Sinon → pile FluxBox.
  </p>

  <form id="avForm" enctype="multipart/form-data">
    <?= csrf_field('avis_import') ?>
    <input type="file" name="document[]" id="avFile" multiple accept=".pdf" style="display:none;">
    <div id="avDrop" tabindex="0" style="border:2px dashed #b39ddb;border-radius:14px;background:#faf8ff;padding:40px 18px;text-align:center;cursor:pointer;">
      <div style="font-size:40px;line-height:1;">📥</div>
      <div style="font-weight:800;color:#5b21b6;margin-top:8px;font-size:16px;">Glissez vos avis d'échéance ici</div>
      <div style="font-size:12.5px;color:#7a766f;margin-top:4px;">ou cliquez pour parcourir · plusieurs PDF acceptés</div>
      <div id="avPicked" style="font-size:12.5px;color:#2d8a4e;font-weight:700;margin-top:10px;"></div>
    </div>
    <div style="display:flex;gap:10px;align-items:center;margin-top:12px;">
      <button type="submit" id="avBtn" style="background:linear-gradient(135deg,#5e35b1,#7e57c2);color:#fff;border:none;border-radius:10px;padding:11px 22px;font-weight:800;cursor:pointer;">🚀 Lancer l'import</button>
      <span id="avMsg" style="font-size:13px;font-weight:700;"></span>
    </div>
  </form>

  <div id="avResults" style="margin-top:20px;"></div>
</div>

<script>
(function(){
  var form=document.getElementById('avForm'), input=document.getElementById('avFile'),
      drop=document.getElementById('avDrop'), picked=document.getElementById('avPicked'),
      btn=document.getElementById('avBtn'), msg=document.getElementById('avMsg'), out=document.getElementById('avResults');
  var URL=<?= json_encode(app_url('/api/avis_echeance_import.php'), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var BAIL=<?= json_encode(app_url('/bail_360.php?id='), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var PILE=<?= json_encode(app_url('/fluxbox_pile.php'), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var esc=function(s){var d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;};
  var chosen=null;

  function names(fl){return Array.prototype.map.call(fl,function(f){return f.name;}).join(', ');}
  drop.addEventListener('click',function(){input.click();});
  input.addEventListener('change',function(){ if(input.files.length){ chosen=input.files; picked.textContent='📄 '+input.files.length+' fichier(s) : '+names(input.files);}});
  ['dragenter','dragover'].forEach(function(e){drop.addEventListener(e,function(ev){ev.preventDefault();ev.stopPropagation();drop.style.background='#efe7f7';drop.style.borderColor='#5e35b1';});});
  ['dragleave','dragend'].forEach(function(e){drop.addEventListener(e,function(ev){ev.preventDefault();ev.stopPropagation();drop.style.background='#faf8ff';drop.style.borderColor='#b39ddb';});});
  drop.addEventListener('drop',function(ev){ev.preventDefault();ev.stopPropagation();drop.style.background='#faf8ff';drop.style.borderColor='#b39ddb';
    if(ev.dataTransfer&&ev.dataTransfer.files.length){chosen=ev.dataTransfer.files;picked.textContent='📄 '+chosen.length+' fichier(s) : '+names(chosen);}});

  function badge(s){var c={'classé':'#2d8a4e','pile':'#8a6d1b','erreur':'#c62828'}[s]||'#6b7280';
    return '<span style="font-weight:800;color:'+c+'">'+esc(s)+'</span>';}

  form.addEventListener('submit',function(ev){
    ev.preventDefault();
    var fl = chosen || input.files;
    if(!fl||!fl.length){msg.style.color='#c62828';msg.textContent='❌ Sélectionnez des PDF.';return;}
    var fd=new FormData();
    fd.append('csrf_token', form.querySelector('[name=csrf_token]').value);
    for(var i=0;i<fl.length;i++) fd.append('document[]', fl[i]);
    var prev=btn.textContent; btn.disabled=true; btn.textContent='⏳ Traitement…'; msg.textContent='';
    fetch(URL,{method:'POST',body:fd,credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(j){
        btn.disabled=false; btn.textContent=prev;
        if(!j||!j.ok){msg.style.color='#c62828';msg.textContent='❌ '+((j&&j.error)||'Échec');return;}
        msg.style.color='#2d8a4e';
        msg.innerHTML='✓ '+j.total+' traité(s) · <b>'+j.classes+'</b> classé(s) · '+j.pile+' en pile'+(j.erreurs?(' · '+j.erreurs+' erreur(s)'):'');
        var rows=(j.results||[]).map(function(r){
          var link = r.status==='classé' && r.bail_id ? '<a href="'+BAIL+r.bail_id+'" target="_blank">bail #'+r.bail_id+'</a>'
                   : (r.status==='pile' ? '<a href="'+PILE+'" target="_blank">→ pile</a>' : '—');
          return '<tr><td style="padding:6px 8px;">'+esc(r.name)+'</td><td style="padding:6px 8px;">'+badge(r.status)+'</td>'
               + '<td style="padding:6px 8px;">'+esc(r.locataire||'—')+'</td><td style="padding:6px 8px;">'+link+'</td>'
               + '<td style="padding:6px 8px;color:#7a766f;font-size:12px;">'+esc(r.reason||'')+'</td></tr>';
        }).join('');
        out.innerHTML='<table style="width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08);font-size:13px;">'
          +'<thead><tr style="background:#ede7f6;color:#4527a0;text-align:left;"><th style="padding:8px;">Fichier</th><th style="padding:8px;">Statut</th><th style="padding:8px;">Locataire</th><th style="padding:8px;">Lien</th><th style="padding:8px;">Détail</th></tr></thead><tbody>'+rows+'</tbody></table>';
      })
      .catch(function(e){btn.disabled=false;btn.textContent=prev;msg.style.color='#c62828';msg.textContent='❌ Réseau : '+e;});
  });
})();
</script>
<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
