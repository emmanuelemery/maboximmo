<?php
/**
 * inc/ged_delete_modal.php — Modal RÉUTILISABLE de suppression d'un document GED.
 * Affiche un petit aperçu du document à gauche + confirmation à droite.
 *
 * USAGE :
 *   1. require_once __DIR__ . '/inc/ged_delete_modal.php'; (une fois, en bas de page)
 *   2. Bouton : onclick="gedDeleteDoc(<id>, '<nom>', this)"  (this = élément de ligne à retirer)
 */
declare(strict_types=1);
if (defined('GED_DELETE_MODAL_INCLUDED')) return;
define('GED_DELETE_MODAL_INCLUDED', true);
$__gddDel  = function_exists('app_url') ? app_url('/api/ged_document_delete.php') : '/api/ged_document_delete.php';
$__gddView = function_exists('app_url') ? app_url('/api/ged_document_view.php') : '/api/ged_document_view.php';
?>
<div id="gdd-modal" style="display:none;position:fixed;inset:0;z-index:9700;background:rgba(15,18,24,.55);align-items:center;justify-content:center;padding:18px;">
  <div style="background:#fff;border-radius:16px;width:min(700px,96vw);overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.35);display:flex;">
    <div style="width:210px;flex:none;background:#0b1220;display:flex;align-items:center;justify-content:center;padding:12px;">
      <iframe id="gdd-preview" title="Aperçu" style="width:186px;height:250px;border:0;background:#fff;border-radius:6px;"></iframe>
    </div>
    <div style="flex:1;min-width:0;padding:20px 22px;">
      <h3 style="margin:0 0 8px;font-size:17px;color:#c0392b;">🗑️ Supprimer le document</h3>
      <div id="gdd-name" style="font-weight:800;color:#334155;font-size:13px;word-break:break-word;margin-bottom:8px;"></div>
      <p style="font-size:12.5px;color:#64748b;line-height:1.5;margin:0 0 6px;">Le document sera <b>retiré de toutes les fiches</b> (tiers, bien, immeuble, bail). Il reste <b>récupérable par un administrateur</b>.</p>
      <label id="gdd-motif-wrap" style="display:none;font-size:12px;color:#a26a1c;font-weight:700;">Motif (obligatoire pour un doc bancaire)
        <input type="text" id="gdd-motif" placeholder="Motif…" style="width:100%;margin-top:4px;padding:8px 10px;border:1px solid #cbd8da;border-radius:8px;">
      </label>
      <div style="display:flex;gap:10px;align-items:center;margin-top:16px;flex-wrap:wrap;">
        <button type="button" onclick="gddClose()" style="border:1px solid #cbd8da;background:#f4f9f9;color:#5b6b70;border-radius:9px;padding:9px 16px;font-weight:800;cursor:pointer;">Annuler</button>
        <button type="button" id="gdd-confirm" onclick="gddConfirm()" style="border:none;background:#c0392b;color:#fff;border-radius:9px;padding:9px 18px;font-weight:800;cursor:pointer;">Supprimer</button>
        <span id="gdd-msg" style="flex:1;font-size:12.5px;font-weight:700;"></span>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
  var URLDEL  = <?= json_encode($__gddDel) ?>;
  var URLVIEW = <?= json_encode($__gddView) ?>;
  var curId=0, curEl=null;
  window.gedDeleteDoc = function(id, name, el){
    curId=parseInt(id,10)||0; curEl=el||null;
    document.getElementById('gdd-name').textContent = name || ('Document #'+curId);
    var pv=document.getElementById('gdd-preview'); pv.src = URLVIEW+'?id='+curId+'&mode=inline';
    document.getElementById('gdd-msg').textContent='';
    document.getElementById('gdd-motif-wrap').style.display='none';
    document.getElementById('gdd-modal').style.display='flex';
  };
  window.gddClose = function(){
    document.getElementById('gdd-modal').style.display='none';
    document.getElementById('gdd-preview').src='about:blank';
  };
  window.gddConfirm = function(){
    var msg=document.getElementById('gdd-msg'), motif=(document.getElementById('gdd-motif').value||'').trim();
    msg.style.color='#5f8f93'; msg.textContent='⏳ Suppression…';
    fetch(URLDEL,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({id_doc:curId, action:'delete', motif:motif})})
      .then(function(r){return r.json();}).then(function(j){
        if(j&&j.ok){ if(curEl){ var row=curEl.closest('div'); if(row) row.remove(); } gddClose(); }
        else {
          // Doc bancaire → on demande un motif (archivage motivé).
          if(j && /bancaire/i.test(j.error||'')){ document.getElementById('gdd-motif-wrap').style.display=''; }
          msg.style.color='#c0392b'; msg.textContent='❌ '+((j&&j.error)||'Échec');
        }
      }).catch(function(e){ msg.style.color='#c0392b'; msg.textContent='❌ '+e; });
  };
  document.getElementById('gdd-modal').addEventListener('mousedown', function(e){ if(e.target===this) gddClose(); });
})();
</script>
