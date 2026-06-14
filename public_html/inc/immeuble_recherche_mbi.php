<?php
/**
 * inc/immeuble_recherche_mbi.php — STANDARD MBI : modal de recherche/création d'immeuble.
 *
 * Règle (Emmanuel 2026-06-14) — à utiliser PARTOUT :
 *   1) recherche d'adresse Google (autocomplete inline = mécanisme qui FONCTIONNE,
 *      cf. agency_immeuble_form) + immeubles déjà en base (anti-doublon),
 *   2) nom de l'immeuble APRÈS la recherche,
 *   3) AUCUNE attribution (immeuble accessible à tout le monde),
 *   4) logo Ma Box Immo = marque « validé & fonctionnel ».
 *
 * USAGE :
 *   require_once __DIR__.'/inc/immeuble_recherche_mbi.php';
 *   ... en bas de page : immeuble_mbi_render(); immeuble_mbi_assets();
 *   ... ouvrir : ImmeubleRechercheMBI.open(function(imm){ ... imm.id, imm.nom, imm.adresse_1 ... });
 */
declare(strict_types=1);

if (!function_exists('immeuble_mbi_render')) {
    function immeuble_mbi_render(): void
    {
        if (defined('IMMEUBLE_MBI_RENDERED')) return;
        define('IMMEUBLE_MBI_RENDERED', true);
        $logo = function_exists('asset_url') ? asset_url('/images/mbi_annonces_logo2.png') : '/images/mbi_annonces_logo2.png';
        $ep   = function_exists('app_url') ? app_url('/api/places_autocomplete.php') : '/api/places_autocomplete.php';
        $epD  = function_exists('app_url') ? app_url('/api/places_details.php') : '/api/places_details.php';
        $epG  = function_exists('app_url') ? app_url('/api/geocode_address.php') : '/api/geocode_address.php';
        $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        ?>
        <div class="imbm-backdrop" id="imbm-modal" aria-hidden="true">
          <div class="imbm-card" role="dialog" aria-modal="true">
            <div class="imbm-head">
              <img class="imbm-logo" src="<?= $h($logo) ?>" alt="Ma Box Immo — validé">
              <h3>🏢 Rechercher / créer un immeuble</h3>
              <button type="button" class="imbm-x" data-imbm-close aria-label="Fermer">×</button>
            </div>
            <div class="imbm-body">
              <!-- 1. Recherche Google + immeubles existants (autocomplete inline) -->
              <label class="imbm-label">🔍 Rechercher l'adresse</label>
              <input type="text" id="imbm-search" class="imbm-input"
                     placeholder="Commencez à taper l'adresse (ex: 13 rue Louis Blanc)…"
                     autocomplete="off"
                     data-places-input
                     data-places-endpoint="<?= $h($ep) ?>"
                     data-places-details-endpoint="<?= $h($epD) ?>"
                     data-places-geocode-endpoint="<?= $h($epG) ?>"
                     data-places-street1="imbm-adr1"
                     data-places-postal="imbm-cp"
                     data-places-city="imbm-ville"
                     data-places-lat="imbm-lat"
                     data-places-lng="imbm-lng"
                     data-places-place-id="imbm-placeid"
                     data-places-formatted="imbm-formatted"
                     data-places-immeuble-id="imbm-immeuble"
                     data-places-country-code="fr">
              <div class="imbm-hint">Immeubles déjà enregistrés + suggestions Google.</div>

              <!-- Adresse (remplie par la recherche, modifiable) -->
              <label class="imbm-label">Adresse</label>
              <input type="text" id="imbm-adr1" class="imbm-input" placeholder="N° et rue">
              <div class="imbm-grid">
                <input type="text" id="imbm-cp" class="imbm-input" placeholder="CP">
                <input type="text" id="imbm-ville" class="imbm-input" placeholder="Ville">
              </div>

              <!-- 2. Nom de l'immeuble APRÈS la recherche -->
              <label class="imbm-label">Nom de l'immeuble</label>
              <input type="text" id="imbm-nom" class="imbm-input" placeholder="Ex. Résidence Les Tilleuls (optionnel)">

              <input type="hidden" id="imbm-lat"><input type="hidden" id="imbm-lng">
              <input type="hidden" id="imbm-placeid"><input type="hidden" id="imbm-formatted">
              <input type="hidden" id="imbm-immeuble">
              <div class="imbm-msg" id="imbm-msg"></div>
            </div>
            <div class="imbm-foot">
              <button type="button" class="imbm-btn cancel" data-imbm-close>Annuler</button>
              <button type="button" class="imbm-btn ok" id="imbm-valider">✅ Valider l'immeuble</button>
            </div>
          </div>
        </div>
        <?php
    }
}

if (!function_exists('immeuble_mbi_assets')) {
    function immeuble_mbi_assets(): void
    {
        if (defined('IMMEUBLE_MBI_ASSETS')) return;
        define('IMMEUBLE_MBI_ASSETS', true);
        $apiUrl = function_exists('app_url') ? app_url('/api/immeuble_recherche_mbi.php') : '/api/immeuble_recherche_mbi.php';
        $placesSrc = function_exists('asset_url') ? asset_url('/js/places.js') : '/js/places.js';
        $key = (string)($GLOBALS['GOOGLE_MAPS_API_KEY'] ?? '');
        $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        ?>
        <style>
          .imbm-backdrop{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:10000;align-items:flex-start;justify-content:center;padding:40px 16px;}
          .imbm-backdrop.open{display:flex;}
          .imbm-card{background:#fff;border-radius:16px;max-width:600px;width:100%;box-shadow:0 24px 64px rgba(0,0,0,.28);overflow:hidden;}
          .imbm-head{display:flex;align-items:center;gap:14px;padding:14px 20px;border-bottom:1px solid #e5e7eb;}
          .imbm-logo{height:64px;width:auto;flex:none;}
          .imbm-head h3{margin:0;font-size:17px;font-weight:800;color:#0f172a;flex:1;}
          .imbm-x{border:none;background:none;font-size:24px;color:#64748b;cursor:pointer;line-height:1;}
          .imbm-x:hover{color:#0f172a;}
          .imbm-body{padding:18px 20px;}
          .imbm-label{display:block;font-size:11px;font-weight:700;letter-spacing:.05em;color:#475569;text-transform:uppercase;margin:14px 0 6px;}
          .imbm-label:first-child{margin-top:0;}
          .imbm-input{width:100%;padding:11px 13px;border:1px solid #cbd5e1;border-radius:10px;font-size:14px;box-sizing:border-box;outline:none;}
          .imbm-input:focus{border-color:#0ea5e9;box-shadow:0 0 0 3px rgba(14,165,233,.12);}
          .imbm-grid{display:grid;grid-template-columns:160px 1fr;gap:10px;margin-top:10px;}
          .imbm-hint{font-size:11px;color:#94a3b8;margin-top:6px;}
          .imbm-msg{font-size:12px;margin-top:10px;min-height:16px;}
          .imbm-foot{padding:14px 20px;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;gap:10px;background:#f8fafc;}
          .imbm-btn{border:none;border-radius:10px;padding:11px 20px;font-weight:800;font-size:14px;cursor:pointer;}
          .imbm-btn.cancel{background:#fff;color:#475569;border:1px solid #cbd5e1;}
          .imbm-btn.ok{background:linear-gradient(135deg,#0f9d58,#0b8043);color:#fff;}
          .imbm-btn:disabled{opacity:.5;cursor:not-allowed;}
          /* l'autocomplete (ajouté au body par places.js) doit passer AU-DESSUS du modal */
          .places-dropdown{z-index:10001 !important;}
        </style>
        <script src="<?= $h($placesSrc) ?>"></script>
        <?php if ($key !== ''): ?>
        <script async src="https://maps.googleapis.com/maps/api/js?key=<?= $h($key) ?>&libraries=places&callback=initPlacesAutocomplete"></script>
        <?php endif; ?>
        <script>
        (function(){
          const API = <?= json_encode($apiUrl) ?>;
          let onResult = null;
          const $ = id => document.getElementById(id);

          function reset(){
            ['imbm-search','imbm-adr1','imbm-cp','imbm-ville','imbm-nom','imbm-lat','imbm-lng','imbm-placeid','imbm-formatted','imbm-immeuble'].forEach(id=>{ if($(id)) $(id).value=''; });
            $('imbm-msg').textContent='';
          }
          function open(cb){ onResult = (typeof cb==='function')?cb:null; reset(); $('imbm-modal').classList.add('open'); $('imbm-modal').setAttribute('aria-hidden','false'); setTimeout(()=>$('imbm-search').focus(),60); }
          function close(){ $('imbm-modal').classList.remove('open'); $('imbm-modal').setAttribute('aria-hidden','true'); }

          document.addEventListener('click', e=>{
            if(e.target.closest('[data-imbm-close]')) { close(); return; }
            if(e.target.id==='imbm-modal') { close(); }
          });
          document.addEventListener('keydown', e=>{ if(e.key==='Escape' && $('imbm-modal').classList.contains('open')) close(); });

          async function valider(){
            const adr1=$('imbm-adr1').value.trim(), sel=$('imbm-immeuble').value;
            if(!adr1 && !sel){ $('imbm-msg').style.color='#dc2626'; $('imbm-msg').textContent='Renseignez l\'adresse via la recherche.'; return; }
            const btn=$('imbm-valider'); btn.disabled=true; $('imbm-msg').style.color='#64748b'; $('imbm-msg').textContent='Enregistrement…';
            try{
              const body=new URLSearchParams({
                nom:$('imbm-nom').value, adresse_1:adr1,
                code_postal:$('imbm-cp').value, ville:$('imbm-ville').value,
                latitude:$('imbm-lat').value, longitude:$('imbm-lng').value,
                google_place_id:$('imbm-placeid').value, id_immeuble_selected:sel||''
              });
              const res=await fetch(API,{method:'POST',credentials:'same-origin',body});
              const out=await res.json();
              if(!out.ok){ $('imbm-msg').style.color='#dc2626'; $('imbm-msg').textContent='Erreur : '+(out.error||'inconnue'); btn.disabled=false; return; }
              if(onResult) onResult(out.immeuble, out.created);
              close();
            }catch(err){ $('imbm-msg').style.color='#dc2626'; $('imbm-msg').textContent='Erreur réseau : '+err.message; }
            finally{ btn.disabled=false; }
          }
          document.addEventListener('click', e=>{ if(e.target.id==='imbm-valider') valider(); });

          window.ImmeubleRechercheMBI = { open, close };
        })();
        </script>
        <?php
    }
}
