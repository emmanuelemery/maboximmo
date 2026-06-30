<?php
/**
 * inc/topbar_search.php — Champ de recherche rapide pour la topbar (locataire / adresse / réf bien).
 * Autonome (markup + CSS + JS). À inclure dans la zone .tb-actions des layouts.
 * Résultats via api/quick_search.php → navigation vers bien_360.php.
 */
if (!function_exists('app_url')) { return; }
?>
<div class="tbs-wrap">
  <span class="tbs-ic">🔎</span>
  <input type="search" id="tbsInput" class="tbs-input" autocomplete="off" spellcheck="false"
         placeholder="Rechercher un locataire, une adresse…" aria-label="Recherche rapide">
  <div id="tbsResults" class="tbs-results" style="display:none;"></div>
</div>
<style>
.tbs-wrap{ position:relative; display:flex; align-items:center; gap:6px; }
.tbs-ic{ position:absolute; left:10px; font-size:13px; opacity:.55; pointer-events:none; }
.tbs-input{ width:230px; max-width:34vw; padding:8px 12px 8px 30px; border:1px solid #d7ccec;
  border-radius:10px; background:#faf8ff; font-size:13px; transition:width .15s, border-color .15s; }
.tbs-input:focus{ outline:none; width:300px; border-color:#5e35b1; background:#fff; }
.tbs-results{ position:absolute; top:calc(100% + 6px); right:0; width:min(420px,80vw); max-height:60vh;
  overflow:auto; background:#fff; border:1px solid #e6e0d6; border-radius:12px;
  box-shadow:0 12px 34px rgba(40,40,60,.18); z-index:9999; padding:6px; }
.tbs-item{ display:block; padding:9px 11px; border-radius:8px; text-decoration:none; color:#2c2a28; cursor:pointer; }
.tbs-item:hover, .tbs-item.act{ background:#f3eefb; }
.tbs-item .t{ font-weight:700; font-size:13px; }
.tbs-item .s{ font-size:11.5px; color:#7a766f; margin-top:1px; }
.tbs-empty{ padding:12px; text-align:center; color:#9a9690; font-size:12.5px; }
</style>
<script>
(function(){
  var inp = document.getElementById('tbsInput');
  var box = document.getElementById('tbsResults');
  if (!inp || !box) return;
  var URL  = <?= json_encode(app_url('/api/quick_search.php'), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var BIEN = <?= json_encode(app_url('/bien_360.php?id='), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var BAIL = <?= json_encode(app_url('/bail_360.php?id='), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  var t=null, items=[], idx=-1, esc=function(s){var d=document.createElement('div');d.textContent=(s==null?'':String(s));return d.innerHTML;};

  function hide(){ box.style.display='none'; idx=-1; }
  // Locataire/bail → fiche BAIL 360 ; à défaut → fiche bien.
  function goItem(r){ if(!r) return; if(r.bail_id>0) window.location.href=BAIL+r.bail_id; else if(r.id>0) window.location.href=BIEN+r.id; }
  function render(){
    if(!items.length){ box.innerHTML='<div class="tbs-empty">Aucun résultat</div>'; box.style.display='block'; return; }
    box.innerHTML = items.map(function(r,i){
      var line2 = [r.ref, (r.adresse||'')+(r.ville?(' · '+r.ville):'')].filter(Boolean).join(' — ');
      return '<a class="tbs-item'+(i===idx?' act':'')+'" data-i="'+i+'">'
           + '<div class="t">'+esc(r.loc || r.ref || ('Bien #'+r.id))+'</div>'
           + '<div class="s">'+esc(line2)+'</div></a>';
    }).join('');
    box.style.display='block';
    Array.prototype.forEach.call(box.querySelectorAll('.tbs-item'), function(el){
      el.addEventListener('mousedown', function(e){ e.preventDefault(); goItem(items[parseInt(el.getAttribute('data-i'),10)]); });
    });
  }
  function search(){
    var q = inp.value.trim();
    if (q.length < 2){ hide(); return; }
    fetch(URL + '?q=' + encodeURIComponent(q), {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(j){ items = (j&&j.results)||[]; idx=-1; render(); })
      .catch(function(){ hide(); });
  }
  inp.addEventListener('input', function(){ clearTimeout(t); t=setTimeout(search, 220); });
  inp.addEventListener('keydown', function(e){
    if (box.style.display==='none') return;
    if (e.key==='ArrowDown'){ e.preventDefault(); idx=Math.min(idx+1, items.length-1); render(); }
    else if (e.key==='ArrowUp'){ e.preventDefault(); idx=Math.max(idx-1, 0); render(); }
    else if (e.key==='Enter'){ if(idx>=0 && items[idx]){ e.preventDefault(); goItem(items[idx]); } }
    else if (e.key==='Escape'){ hide(); }
  });
  inp.addEventListener('focus', function(){ if(items.length) box.style.display='block'; });
  document.addEventListener('click', function(e){ if(!e.target.closest('.tbs-wrap')) hide(); });
})();
</script>
