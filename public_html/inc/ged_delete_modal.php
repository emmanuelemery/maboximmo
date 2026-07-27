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
      <h3 style="margin:0 0 8px;font-size:17px;color:#334155;">Supprimer ou archiver le document</h3>
      <div id="gdd-name" style="font-weight:800;color:#334155;font-size:13px;word-break:break-word;margin-bottom:8px;"></div>
      <p style="font-size:12.5px;color:#64748b;line-height:1.5;margin:0 0 6px;"><b>📦 Archiver</b> : retire de la liste sans supprimer (consultable via « Voir les archives »). <b>🗑️ Supprimer</b> : retire de toutes les fiches (récupérable par un admin).</p>
      <label id="gdd-motif-wrap" style="display:none;font-size:12px;color:#a26a1c;font-weight:700;">Motif (obligatoire pour un doc bancaire)
        <input type="text" id="gdd-motif" placeholder="Motif…" style="width:100%;margin-top:4px;padding:8px 10px;border:1px solid #cbd8da;border-radius:8px;">
      </label>
      <div style="display:flex;gap:10px;align-items:center;margin-top:16px;flex-wrap:wrap;">
        <button type="button" onclick="gddClose()" style="border:1px solid #cbd8da;background:#f4f9f9;color:#5b6b70;border-radius:9px;padding:9px 16px;font-weight:800;cursor:pointer;">Annuler</button>
        <button type="button" onclick="gddDo('archive')" style="border:none;background:#a26a1c;color:#fff;border-radius:9px;padding:9px 16px;font-weight:800;cursor:pointer;" title="Retirer de la liste sans supprimer">📦 Archiver</button>
        <button type="button" id="gdd-confirm" onclick="gddDo('delete')" style="border:none;background:#c0392b;color:#fff;border-radius:9px;padding:9px 18px;font-weight:800;cursor:pointer;">🗑️ Supprimer</button>
        <span id="gdd-msg" style="flex:1;font-size:12.5px;font-weight:700;"></span>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
  var URLDEL  = <?= json_encode($__gddDel) ?>;
  var URLVIEW = <?= json_encode($__gddView) ?>;
  var URLARCH = <?= json_encode(function_exists('app_url') ? app_url('/api/ged_documents_archived.php') : '/api/ged_documents_archived.php') ?>;
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
  window.gddDo = function(action){
    var msg=document.getElementById('gdd-msg'), motif=(document.getElementById('gdd-motif').value||'').trim();
    msg.style.color='#5f8f93'; msg.textContent = action==='archive' ? '⏳ Archivage…' : '⏳ Suppression…';
    fetch(URLDEL,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({id_doc:curId, action:action, motif:motif})})
      .then(function(r){return r.json();}).then(function(j){
        if(j&&j.ok){ if(curEl){ var row=curEl.closest('div'); if(row) row.remove(); } gddClose();
          try { document.dispatchEvent(new CustomEvent('ged-doc-deleted', {detail:{id:curId, action:action}})); } catch(e){} }
        else {
          if(j && /bancaire/i.test(j.error||'')){ document.getElementById('gdd-motif-wrap').style.display=''; }
          msg.style.color='#c0392b'; msg.textContent='❌ '+((j&&j.error)||'Échec');
        }
      }).catch(function(e){ msg.style.color='#c0392b'; msg.textContent='❌ '+e; });
  };
  document.getElementById('gdd-modal').addEventListener('mousedown', function(e){ if(e.target===this) gddClose(); });

  // ── Voir/masquer les documents ARCHIVÉS d'une entité, injectés dans la même card. ──
  var esc=function(s){var d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;};
  window.gedToggleArchives = function(btn, type, id){
    var host=btn.parentNode.querySelector('.gdd-arch-list');
    if(host){ host.remove(); btn.textContent=btn.dataset.lbl||'📦 Voir les archives'; return; }
    btn.dataset.lbl=btn.textContent; btn.textContent='⏳…';
    fetch(URLARCH,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({entity_type:type, entity_id:id})})
      .then(function(r){return r.json();}).then(function(j){
        btn.textContent='📦 Masquer les archives';
        var box=document.createElement('div'); box.className='gdd-arch-list'; box.style.cssText='margin-top:6px;border-top:1px dashed #d6cfc4;padding-top:6px;';
        var docs=(j&&j.docs)||[];
        if(!docs.length){ box.innerHTML='<div style="font-size:11.5px;color:#9a9690;font-style:italic;padding:4px 0;">Aucun document archivé.</div>'; }
        else { docs.forEach(function(d){
          var r=document.createElement('div'); r.style.cssText='display:flex;gap:8px;align-items:center;font-size:12px;padding:4px 0;opacity:.75;';
          r.innerHTML='<span style="font-family:monospace;color:#a26a1c;font-size:10px;">[archivé]</span>'
            +'<a href="'+URLVIEW+'?id='+d.id+'&mode=inline" target="_blank" style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#334155;text-decoration:none;" title="'+esc(d.name_display)+'">📄 '+esc(d.name_display)+'</a>'
            +'<button type="button" title="Restaurer" style="border:none;background:transparent;color:#1f7a4d;cursor:pointer;font-size:13px;">↩️</button>'
            +'<button type="button" title="Supprimer" style="border:none;background:transparent;color:#c0392b;cursor:pointer;font-size:13px;">🗑️</button>';
          r.children[2].onclick=function(){ gedRestoreDoc(d.id, r); };
          r.children[3].onclick=function(){ gedDeleteDoc(d.id, d.name_display, r); };
          box.appendChild(r);
        }); }
        btn.parentNode.appendChild(box);
      }).catch(function(e){ btn.textContent=btn.dataset.lbl; alert('❌ '+e); });
  };
  window.gedRestoreDoc = function(id, el){
    fetch(URLDEL,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({id_doc:id, action:'restore'})})
      .then(function(r){return r.json();}).then(function(j){
        if(j&&j.ok){ if(el) el.remove(); }
        else alert('❌ '+((j&&j.error)||'Restauration impossible'));
      }).catch(function(e){ alert('❌ '+e); });
  };
})();
</script>
