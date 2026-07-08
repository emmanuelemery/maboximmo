<?php
/**
 * inc/geo_views_modal.php — Modal réutilisable « 3 vues Google » d'une adresse :
 *   Plan 2D · Street View · Vue 3D / Earth (aérienne satellite).
 *
 * USAGE :
 *   1. require_once __DIR__ . '/inc/geo_views_modal.php';  (une fois, en bas de page)
 *   2. Bouton : <button onclick="openGeoViews(LAT, LNG, 'libellé')">🛰️ Voir</button>
 *
 * Nécessite la clé Google Maps (Embed API) dans $GLOBALS['GOOGLE_MAPS_API_KEY'].
 * Extrait du carrousel d'origine (bien_nouveau_carrousel.php).
 */
declare(strict_types=1);
if (defined('GEO_VIEWS_MODAL_INCLUDED')) return;
define('GEO_VIEWS_MODAL_INCLUDED', true);
$GVKEY = $GLOBALS['GOOGLE_MAPS_API_KEY'] ?? (defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '');
?>
<div class="bn-modal" id="gv-modal">
  <div class="bn-modal-ov" data-gv-close></div>
  <div class="bn-modal-card">
    <div class="bn-modal-head">
      <div class="bn-mh-ico">🛰️</div>
      <div style="flex:1;min-width:0;">
        <h4>Vue de l'immeuble</h4>
        <div class="bn-mh-sub" id="gv-addr">—</div>
      </div>
      <button type="button" class="bn-modal-x" data-gv-close aria-label="Fermer">✕</button>
    </div>
    <div class="bn-modal-tabs">
      <button type="button" class="bn-tab active" data-gv-go="0"><span class="bn-tab-ico">🗺️</span> Plan 2D</button>
      <button type="button" class="bn-tab" data-gv-go="1"><span class="bn-tab-ico">📷</span> Street View</button>
      <button type="button" class="bn-tab" data-gv-go="2"><span class="bn-tab-ico">🌍</span> Vue 3D / Earth</button>
      <button type="button" class="bn-tab" data-gv-go="3" id="gv-tab-adjust" style="display:none;"><span class="bn-tab-ico">📍</span> Ajuster la position</button>
    </div>
    <div class="bn-modal-body">
      <div class="bn-pane show" id="gv-pane-0"><div class="bn-pane-empty">Chargement du plan…</div></div>
      <div class="bn-pane"      id="gv-pane-1"><div class="bn-pane-empty">Chargement Street View…</div></div>
      <div class="bn-pane"      id="gv-pane-2"><div class="bn-pane-empty">Chargement de la vue aérienne…</div></div>
      <div class="bn-pane"      id="gv-pane-3">
        <div style="padding:10px 16px;background:#0f1a2e;color:#c7d4e6;font-size:12.5px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <span>🎯 Fais glisser le point (ou clique) sur le <b>bâtiment exact</b>, puis enregistre.</span>
          <button type="button" id="gv-save-pos" style="margin-left:auto;border:none;background:#1f7a4d;color:#fff;border-radius:9px;padding:8px 16px;font-weight:800;cursor:pointer;">💾 Enregistrer la position</button>
          <span id="gv-save-msg" style="font-size:12px;font-weight:700;"></span>
        </div>
        <div id="gv-map-adjust" style="width:100%;height:56vh;background:#0b1220;"></div>
      </div>
    </div>
    <div class="bn-modal-foot">
      <a href="#" id="gv-ext" target="_blank" rel="noopener">↗ Ouvrir dans Google Maps</a>
    </div>
  </div>
</div>
<style>
  #gv-modal.bn-modal { position:fixed; inset:0; z-index:9000; display:none; align-items:center; justify-content:center; padding:24px; }
  #gv-modal.open { display:flex; }
  #gv-modal .bn-modal-ov { position:absolute; inset:0; background:rgba(15,23,42,.66); backdrop-filter:blur(3px); }
  #gv-modal .bn-modal-card { position:relative; background:#0b1220; border-radius:18px; width:min(1040px,100%); max-height:92vh; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 30px 80px rgba(0,0,0,.5); }
  #gv-modal .bn-modal-head { display:flex; align-items:center; gap:14px; padding:16px 20px; background:#0f1a2e; }
  #gv-modal .bn-mh-ico { width:38px; height:38px; border-radius:11px; display:grid; place-items:center; font-size:19px; background:rgba(212,160,71,.18); }
  #gv-modal .bn-modal-head h4 { margin:0; font-size:15px; font-weight:800; color:#fff; flex:1; letter-spacing:.2px; }
  #gv-modal .bn-mh-sub { font-size:11.5px; color:#a9bcd6; font-weight:500; margin-top:2px; }
  #gv-modal .bn-modal-x { background:rgba(255,255,255,.1); border:none; border-radius:9px; padding:8px 12px; font-size:16px; color:#fff; cursor:pointer; }
  #gv-modal .bn-modal-x:hover { background:rgba(255,255,255,.22); }
  #gv-modal .bn-modal-tabs { display:flex; gap:8px; padding:12px 20px; background:#0f1a2e; }
  #gv-modal .bn-tab { flex:1; border:1px solid rgba(255,255,255,.1); background:rgba(255,255,255,.04); color:#c7d4e6; border-radius:11px; padding:10px 8px; font-family:inherit; font-weight:700; font-size:12.5px; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:7px; }
  #gv-modal .bn-tab:hover { background:rgba(255,255,255,.09); color:#fff; }
  #gv-modal .bn-tab.active { background:linear-gradient(135deg,#D4A047,#c08e2f); color:#1a2233; border-color:#D4A047; box-shadow:0 4px 14px rgba(212,160,71,.3); }
  #gv-modal .bn-tab-ico { font-size:16px; }
  #gv-modal .bn-modal-body { flex:1; min-height:440px; background:#0b1220; position:relative; }
  #gv-modal .bn-pane { display:none; }
  #gv-modal .bn-pane.show { display:block; }
  #gv-modal .bn-modal-body iframe { width:100%; height:62vh; border:0; display:block; background:#0b1220; }
  #gv-modal .bn-pane-empty { color:#8aa0bf; font-size:13px; padding:60px 20px; text-align:center; }
  #gv-modal .bn-modal-foot { padding:10px 20px; background:#0f1a2e; border-top:1px solid rgba(255,255,255,.06); display:flex; justify-content:flex-end; }
  #gv-modal .bn-modal-foot a { color:#D4A047; font-size:12.5px; font-weight:600; text-decoration:none; }
  #gv-modal .bn-modal-foot a:hover { text-decoration:underline; }
</style>
<script>
(function(){
  var GKEY = <?= json_encode((string)$GVKEY) ?>;
  var SAVE_URL = <?= json_encode(function_exists('app_url') ? app_url('/api/geo_save_coords.php') : '/api/geo_save_coords.php') ?>;
  var modal = document.getElementById('gv-modal');
  var cur = 0, loaded = [false,false,false], lat=0, lng=0;
  var saveCtx=null, adjLat=0, adjLng=0, mapsJs=false, adjMap=null, adjMarker=null;
  function url(i){
    var c = lat + ',' + lng;
    if (i === 0) return 'https://www.google.com/maps/embed/v1/place?key=' + GKEY + '&q=' + encodeURIComponent(c) + '&zoom=18&maptype=roadmap';
    if (i === 1) return 'https://www.google.com/maps/embed/v1/streetview?key=' + GKEY + '&location=' + c + '&heading=210&pitch=10&fov=80';
    return 'https://www.google.com/maps/embed/v1/view?key=' + GKEY + '&center=' + c + '&zoom=19&maptype=satellite';
  }
  function load(i){
    if (loaded[i] || !lat || !lng || !GKEY) return;
    loaded[i] = true;
    var pane = document.getElementById('gv-pane-' + i);
    var f = document.createElement('iframe');
    f.setAttribute('loading','lazy'); f.setAttribute('allowfullscreen','');
    f.referrerPolicy = 'no-referrer-when-downgrade'; f.src = url(i);
    pane.innerHTML = ''; pane.appendChild(f);
  }
  function go(i){
    cur = i;
    document.querySelectorAll('#gv-modal .bn-tab').forEach(function(t,idx){ t.classList.toggle('active', idx===i); });
    document.querySelectorAll('#gv-modal .bn-pane').forEach(function(p,idx){ p.classList.toggle('show', idx===i); });
    if (i === 3) { buildAdjust(); } else { load(i); }
  }
  // Charge l'API JS Google Maps une fois (pour la carte interactive d'ajustement).
  function loadMapsJs(cb){
    if (mapsJs && window.google && window.google.maps) { cb(); return; }
    if (!GKEY) { cb(); return; }
    var s=document.createElement('script');
    s.src='https://maps.googleapis.com/maps/api/js?key='+GKEY;
    s.async=true; s.onload=function(){ mapsJs=true; cb(); };
    s.onerror=function(){ document.getElementById('gv-map-adjust').innerHTML='<div class="bn-pane-empty">Carte interactive indisponible (activer « Maps JavaScript API » sur la clé Google).</div>'; };
    document.head.appendChild(s);
  }
  function buildAdjust(){
    var host=document.getElementById('gv-map-adjust'); if(!host) return;
    loadMapsJs(function(){
      if(!(window.google&&window.google.maps)){ return; }
      adjLat=lat; adjLng=lng;
      adjMap=new google.maps.Map(host,{center:{lat:lat,lng:lng},zoom:20,mapTypeId:'hybrid',streetViewControl:false,mapTypeControl:true});
      adjMarker=new google.maps.Marker({position:{lat:lat,lng:lng},map:adjMap,draggable:true,title:'Position du bâtiment'});
      function set(p){ adjLat=p.lat(); adjLng=p.lng(); }
      adjMarker.addListener('dragend',function(e){ set(e.latLng); });
      adjMap.addListener('click',function(e){ adjMarker.setPosition(e.latLng); set(e.latLng); });
    });
  }
  window.openGeoViews = function(la, ln, label, ctx){
    lat = parseFloat(la); lng = parseFloat(ln);
    if (!lat || !lng) { alert('Coordonnées GPS indisponibles pour cet immeuble.'); return; }
    saveCtx = (ctx && ctx.type && ctx.id) ? ctx : null;
    var adjTab=document.getElementById('gv-tab-adjust'); if(adjTab) adjTab.style.display = saveCtx ? '' : 'none';
    var sm=document.getElementById('gv-save-msg'); if(sm) sm.textContent='';
    document.getElementById('gv-addr').textContent = label || (lat + ', ' + lng);
    document.getElementById('gv-ext').href = 'https://www.google.com/maps/search/?api=1&query=' + lat + ',' + lng;
    loaded = [false,false,false]; adjMap=null;
    document.querySelectorAll('#gv-modal .bn-pane').forEach(function(p,idx){
      if(idx<3) p.innerHTML = '<div class="bn-pane-empty">Chargement…</div>';
      p.classList.toggle('show', idx===0);
    });
    modal.classList.add('open'); go(0);
  };
  // Enregistrement de la position ajustée.
  document.getElementById('gv-save-pos').addEventListener('click', function(){
    if(!saveCtx){ return; }
    var msg=document.getElementById('gv-save-msg');
    msg.style.color='#c7d4e6'; msg.textContent='⏳ Enregistrement…';
    fetch(SAVE_URL,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({type:saveCtx.type, id:saveCtx.id, lat:adjLat, lng:adjLng})})
      .then(function(r){return r.json();}).then(function(j){
        if(j&&j.ok){ lat=adjLat; lng=adjLng; loaded=[false,false,false];
          document.getElementById('gv-ext').href='https://www.google.com/maps/search/?api=1&query='+lat+','+lng;
          msg.style.color='#4ade80'; msg.textContent='✅ Position enregistrée.'; }
        else { msg.style.color='#f87171'; msg.textContent='❌ '+((j&&j.error)||'Échec'); }
      }).catch(function(e){ msg.style.color='#f87171'; msg.textContent='❌ '+e; });
  });
  function close(){ modal.classList.remove('open'); }
  document.querySelectorAll('[data-gv-close]').forEach(function(el){ el.addEventListener('click', close); });
  document.querySelectorAll('#gv-modal [data-gv-go]').forEach(function(t){ t.addEventListener('click', function(){ go(+t.getAttribute('data-gv-go')); }); });
  document.addEventListener('keydown', function(e){ if (e.key==='Escape') close(); });
})();
</script>
<?php /* fin geo_views_modal */ ?>
