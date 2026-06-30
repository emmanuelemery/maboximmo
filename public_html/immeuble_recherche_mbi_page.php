<?php
/**
 * immeuble_recherche_mbi_page.php — STANDARD MBI : page dédiée (à mettre dans une iframe).
 *
 * Conçue pour le composant inc/immeuble_recherche_mbi.php. Page autonome (pas de
 * sidebar/topbar) → l'autocomplete Google fonctionne (vraie page), et AUCUNE
 * attribution (immeuble accessible à tout le monde). Flux : adresse Google →
 * immeubles existants (anti-doublon) → nom APRÈS → « Valider et lier ».
 *
 * À la validation : POST api/immeuble_recherche_mbi.php (crée/réutilise sans
 * attribution) puis postMessage au parent { type:'imbm_created', immeuble }.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$logo = function_exists('asset_url') ? asset_url('/images/mbi_annonces_logo2.png') : '/images/mbi_annonces_logo2.png';
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$key = (string)($GLOBALS['GOOGLE_MAPS_API_KEY'] ?? '');
// Mode "pick" : on RENVOIE l'adresse choisie au parent SANS créer d'immeuble
// (utilisé par agency_immeuble_fiche pour remplir les champs de l'immeuble courant).
$pickMode = (($_GET['mode'] ?? '') === 'pick');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rechercher / créer un immeuble</title>
<style>
  *{box-sizing:border-box;}
  body{margin:0;font-family:'Segoe UI',system-ui,sans-serif;background:#f8fafc;color:#1f2937;}
  .imp-wrap{max-width:500px;margin:0 auto;padding:12px 16px 14px;}
  .imp-head{display:none;}
  .imp-sub{color:#64748b;font-size:12px;margin-bottom:10px;line-height:1.35;}
  .imp-label{display:block;font-size:10.5px;font-weight:700;letter-spacing:.05em;color:#475569;text-transform:uppercase;margin:11px 0 4px;}
  .imp-input{width:100%;padding:9px 12px;border:1px solid #cbd5e1;border-radius:9px;font-size:14px;outline:none;}
  .imp-input:focus{border-color:#0ea5e9;box-shadow:0 0 0 3px rgba(14,165,233,.12);}
  .imp-grid{display:grid;grid-template-columns:140px 1fr;gap:8px;margin-top:8px;}
  .imp-hint{font-size:11px;color:#94a3b8;margin-top:5px;}
  .imp-gps{font-size:11px;color:#0e7490;margin-top:5px;font-variant-numeric:tabular-nums;min-height:14px;}
  .imp-foot{display:flex;justify-content:flex-end;gap:10px;margin-top:16px;}
  .imp-btn{border:none;border-radius:11px;padding:13px 22px;font-weight:800;font-size:14px;cursor:pointer;}
  .imp-btn.cancel{background:#fff;color:#475569;border:1px solid #cbd5e1;}
  .imp-btn.ok{background:linear-gradient(135deg,#0f9d58,#0b8043);color:#fff;}
  .imp-btn:disabled{opacity:.5;cursor:not-allowed;}
  .imp-msg{font-size:13px;margin-top:12px;min-height:18px;}
  /* dropdown de l'autocomplete (places.js l'ajoute au body) */
  .places-dropdown{position:absolute;z-index:9999;background:#fff;border:1px solid #cbd5e1;border-radius:10px;box-shadow:0 10px 28px rgba(0,0,0,.14);max-height:300px;overflow:auto;}
  .places-item{padding:11px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid #eef2f7;}
  .places-item:last-child{border-bottom:0;}
  .places-item:hover,.places-item.active{background:rgba(14,165,233,.08);}
</style>
</head>
<body>
<div class="imp-wrap">
  <div class="imp-sub">Commencez par l'adresse (immeubles déjà enregistrés + Google), puis nommez l'immeuble. Accessible à tout le monde — aucune attribution.</div>

  <label class="imp-label">🔍 Rechercher l'adresse</label>
  <input type="text" id="imp-search" class="imp-input" autocomplete="off"
         placeholder="Commencez à taper l'adresse (ex: 13 rue Louis Blanc)…"
         data-places-input
         data-places-endpoint="<?= $h(app_url('/api/places_autocomplete.php')) ?>"
         data-places-details-endpoint="<?= $h(app_url('/api/places_details.php')) ?>"
         data-places-geocode-endpoint="<?= $h(app_url('/api/geocode_address.php')) ?>"
         data-places-street1="imp-adr1"
         data-places-postal="imp-cp"
         data-places-city="imp-ville"
         data-places-lat="imp-lat"
         data-places-lng="imp-lng"
         data-places-place-id="imp-placeid"
         data-places-formatted="imp-formatted"
         data-places-immeuble-id="imp-immeuble"
         data-places-country-code="fr">
  <div class="imp-hint">Immeubles déjà enregistrés + suggestions Google.</div>

  <label class="imp-label">Adresse</label>
  <input type="text" id="imp-adr1" class="imp-input" placeholder="N° et rue">
  <div class="imp-grid">
    <input type="text" id="imp-cp" class="imp-input" placeholder="CP">
    <input type="text" id="imp-ville" class="imp-input" placeholder="Ville">
  </div>

  <label class="imp-label">Nom de l'immeuble</label>
  <input type="text" id="imp-nom" class="imp-input" placeholder="Ex. Résidence Les Tilleuls (optionnel)">
  <div class="imp-gps" id="imp-gps"></div>

  <input type="hidden" id="imp-lat"><input type="hidden" id="imp-lng">
  <input type="hidden" id="imp-placeid"><input type="hidden" id="imp-formatted">
  <input type="hidden" id="imp-immeuble">

  <div class="imp-msg" id="imp-msg"></div>
  <div class="imp-foot">
    <button type="button" class="imp-btn cancel" id="imp-cancel">Annuler</button>
    <button type="button" class="imp-btn ok" id="imp-valider"><?= $pickMode ? '✅ Utiliser cette adresse' : '✅ Valider et lier au bien' ?></button>
  </div>
</div>

<?php $placesV = @filemtime(__DIR__ . '/js/places.js') ?: time(); ?>
<script src="<?= $h(asset_url('/js/places.js')) ?>?v=<?= $placesV ?>"></script>
<?php if ($key !== ''): ?>
<script async src="https://maps.googleapis.com/maps/api/js?key=<?= $h($key) ?>&libraries=places&callback=initPlacesAutocomplete"></script>
<?php endif; ?>
<script>
(function(){
  const API = <?= json_encode(app_url('/api/immeuble_recherche_mbi.php')) ?>;
  const PICK = <?= $pickMode ? 'true' : 'false' ?>;
  const $ = id => document.getElementById(id);
  function tell(type, payload){ if(window.parent && window.parent!==window){ window.parent.postMessage(Object.assign({type:type}, payload||{}), '*'); } }

  // Proposition auto du nom d'immeuble depuis l'adresse : "13 Rue Louis Blanc" -> "13 Louis Blanc".
  function deriveNom(adr){
    if(!adr) return '';
    const types=/\b(rue|avenue|av|bd|boulevard|impasse|imp|chemin|chem|allee|all[ée]e|place|pl|route|rte|quai|cours|passage|pass|square|sq|sentier|villa|voie|montee|mont[ée]e|esplanade|faubourg|fbg|traverse)\b/gi;
    return adr.replace(types,' ').replace(/\s+/g,' ').trim();
  }
  let nomTouched=false;
  $('imp-nom').addEventListener('input', ()=>{ nomTouched = $('imp-nom').value.trim() !== ''; });

  // Hauteur réelle envoyée au parent (modal qui colle au contenu).
  // min 470px : laisse la place au déroulant de l'autocomplete (sinon coupé par l'iframe).
  function sendHeight(){ tell('imbm_height', {height: Math.max(Math.ceil(document.querySelector('.imp-wrap').getBoundingClientRect().height) + 24, 470)}); }
  window.addEventListener('load', sendHeight);
  if (window.ResizeObserver) new ResizeObserver(sendHeight).observe(document.querySelector('.imp-wrap'));

  // L'autocomplete remplit l'adresse par programme (sans event) : on surveille.
  let lastAdr='';
  setInterval(()=>{
    const adr=$('imp-adr1').value.trim();
    if(adr!==lastAdr){ lastAdr=adr; if(!nomTouched){ $('imp-nom').value = deriveNom(adr); } }
    // Coordonnées GPS (effet pro) sous le nom, automatique.
    const la=$('imp-lat').value.trim(), lo=$('imp-lng').value.trim();
    const gps = (la && lo) ? '📍 GPS : ' + parseFloat(la).toFixed(6) + ', ' + parseFloat(lo).toFixed(6) : '';
    if($('imp-gps').textContent !== gps) $('imp-gps').textContent = gps;
  }, 500);
  sendHeight();

  $('imp-cancel').addEventListener('click', ()=> tell('imbm_cancel'));

  $('imp-valider').addEventListener('click', async function(){
    const adr1=$('imp-adr1').value.trim(), sel=$('imp-immeuble').value;
    const msg=$('imp-msg');
    if(!adr1 && !sel){ msg.style.color='#dc2626'; msg.textContent='Renseignez l\'adresse via la recherche.'; return; }
    // Mode "pick" : on renvoie juste l'adresse au parent, AUCUNE création d'immeuble.
    if (PICK) {
      tell('imbm_picked', { address: {
        nom: $('imp-nom').value,
        adresse_1: adr1,
        code_postal: $('imp-cp').value,
        ville: $('imp-ville').value,
        latitude: $('imp-lat').value,
        longitude: $('imp-lng').value,
        google_place_id: $('imp-placeid').value,
        formatted: $('imp-formatted').value
      }});
      return;
    }
    const btn=$('imp-valider'); btn.disabled=true; msg.style.color='#64748b'; msg.textContent='Enregistrement…';
    try{
      const body=new URLSearchParams({
        nom:$('imp-nom').value, adresse_1:adr1,
        code_postal:$('imp-cp').value, ville:$('imp-ville').value,
        latitude:$('imp-lat').value, longitude:$('imp-lng').value,
        google_place_id:$('imp-placeid').value, id_immeuble_selected:sel||''
      });
      const res=await fetch(API,{method:'POST',credentials:'same-origin',body});
      const out=await res.json();
      if(!out.ok){ msg.style.color='#dc2626'; msg.textContent='Erreur : '+(out.error||'inconnue'); btn.disabled=false; return; }
      msg.style.color='#0b8043'; msg.textContent='✓ Immeuble '+(out.created?'créé':'rattaché')+' — fermeture…';
      tell('imbm_created', {immeuble: out.immeuble, created: out.created});
    }catch(err){ msg.style.color='#dc2626'; msg.textContent='Erreur réseau : '+err.message; btn.disabled=false; }
  });
})();
</script>
</body>
</html>
