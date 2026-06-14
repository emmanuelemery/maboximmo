<?php
/**
 * transaction_dossier_nouveau.php — Parcours guidé « Nouveau dossier de vente » (depuis 0).
 *
 * Étape 1 : propriétaire (rechercher/créer via tiers_selector + adresse Google).
 * Étape 2 : bien minimal (désignation, type, adresse Google) rattaché au propriétaire.
 * → crée le bien + ouvre son dossier (vendeur pré-rempli). Zéro page intermédiaire.
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/tiers_selector.php';
require_login();

$pageTitle = 'Nouveau dossier de vente';
include __DIR__ . '/inc/agency_layout_top.php';

$TYPES = [1=>'Appartement', 2=>'Maison', 4=>'Terrain', 5=>'Local commercial', 6=>'Bureau', 8=>'Parking', 9=>'Garage', 10=>'Entrepôt'];
?>
<style>
.nd-wrap{max-width:720px;margin:0 auto;padding:14px 16px 60px;}
.nd-steps{display:flex;gap:10px;margin:6px 0 20px;}
.nd-step-chip{flex:1;text-align:center;padding:10px;border-radius:10px;background:#f1f5f9;color:#64748b;font-weight:800;font-size:13px;}
.nd-step-chip.active{background:#0f6cbd;color:#fff;}
.nd-step-chip.done{background:#d7f0e0;color:#0b6b35;}
.nd-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:24px 26px;}
.nd-card h2{margin:0 0 4px;font-size:18px;}
.nd-card .sub{color:#64748b;font-size:13px;margin-bottom:18px;}
.nd-label{font-size:11px;font-weight:700;letter-spacing:.06em;color:#64748b;text-transform:uppercase;margin:14px 0 7px;}
.nd-types{display:flex;flex-wrap:wrap;gap:8px;}
.nd-type{border:1px solid #cbd5e1;background:#fff;border-radius:10px;padding:8px 14px;font-weight:700;font-size:13px;cursor:pointer;}
.nd-type.active{border-color:#0f6cbd;background:#eef5fc;color:#0c5aa0;box-shadow:0 0 0 2px #0f6cbd22;}
.nd-input{width:100%;padding:11px 13px;border:1px solid #cbd5e1;border-radius:10px;font-size:14px;box-sizing:border-box;}
.nd-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.nd-grid .full{grid-column:1/-1;}
.nd-bien-cols{display:grid;grid-template-columns:1fr 360px;gap:22px;align-items:start;}
@media(max-width:820px){.nd-bien-cols{grid-template-columns:1fr;}}
.nd-bien-right{border-left:1px solid #eef2f6;padding-left:20px;}
@media(max-width:820px){.nd-bien-right{border-left:0;padding-left:0;border-top:1px solid #eef2f6;padding-top:14px;}}
.nd-biens-list{display:flex;flex-direction:column;gap:7px;max-height:520px;overflow:auto;}
.nd-bien-btn{display:flex;flex-direction:column;align-items:flex-start;text-align:left;border:1px solid #cbd5e1;background:#fff;border-radius:10px;padding:10px 13px;cursor:pointer;line-height:1.35;width:100%;}
.nd-bien-btn:hover{border-color:#0f6cbd;background:#eef5fc;}
.nd-bien-btn .r{font-weight:800;font-size:13px;color:#0f172a;}
.nd-bien-btn .d{font-size:11.5px;color:#475569;}
.nd-bien-btn .d.loc{color:#0e7490;font-weight:700;}
.nd-bien-btn .d.vac{color:#94a3b8;font-style:italic;}
.nd-bien-btn .tags{margin-top:4px;display:flex;gap:5px;}
.nd-bien-tag{font-size:9px;font-weight:800;border-radius:5px;padding:1px 6px;}
.nd-bien-tag.vente{background:#fef3c7;color:#92600a;}
.nd-bien-tag.dossier{background:#d7f0e0;color:#0b6b35;}
.nd-actions{display:flex;justify-content:space-between;margin-top:22px;}
.nd-btn{border:none;border-radius:11px;padding:12px 22px;font-weight:800;font-size:14px;cursor:pointer;}
.nd-btn.prev{background:#eceef1;color:#374151;}
.nd-btn.next{background:linear-gradient(135deg,#0f9d58,#0b8043);color:#fff;}
.nd-btn:disabled{opacity:.5;cursor:not-allowed;}
.nd-msg{font-size:12px;color:#94a3b8;margin-top:10px;}
/* cascade modale adresse au-dessus de la modale création tiers */
.ts-modal-overlay{z-index:9500 !important;}
.tiers-selector .ts-dropdown{z-index:9600;}
.addr-modal{z-index:9700 !important;}
.addr-modal .places-dropdown{z-index:9800 !important;}
</style>

<div class="nd-wrap">
  <h1 style="margin:0 0 2px;font-size:22px;">🗂️ Nouveau dossier de vente</h1>
  <div style="color:#64748b;font-size:13px;">Le dossier est le point de départ : propriétaire → bien → tout le reste se passe dans le dossier.</div>

  <div class="nd-steps">
    <div class="nd-step-chip active" id="chip1">1 · Propriétaire</div>
    <div class="nd-step-chip" id="chip2">2 · Bien</div>
  </div>

  <!-- ÉTAPE 1 : PROPRIÉTAIRE -->
  <div class="nd-card" id="nd-step1">
    <h2>👤 Le propriétaire (vendeur)</h2>
    <div class="sub">Recherchez un propriétaire existant, ou créez-le (l'adresse passe par le modal Google).</div>
    <?php tiers_selector_render([
        'id'           => 'nd_tiers',
        'name'         => 'nd_id_tiers',
        'allow_create' => true,
        'placeholder'  => 'Nom, email, téléphone… ou créer',
    ]); ?>
    <div class="nd-actions">
      <span></span>
      <button type="button" class="nd-btn next" id="nd-next1" disabled onclick="ndNext1()">Suivant →</button>
    </div>
    <div class="nd-msg" id="nd-msg1"></div>
  </div>

  <!-- ÉTAPE 2 : BIEN -->
  <div class="nd-card" id="nd-step2" style="display:none;">
    <h2>🏠 Le bien à vendre</h2>
    <div class="sub">Propriétaire : <strong id="nd-prop-nom">—</strong>. Choisissez un de ses biens existants à droite, ou créez-en un nouveau.</div>

    <div class="nd-bien-cols">
    <div class="nd-bien-left">
    <div class="nd-label">Type de bien</div>
    <div class="nd-types" id="nd-types">
      <?php foreach ($TYPES as $id => $lib): ?>
        <button type="button" class="nd-type" data-type="<?= (int)$id ?>"><?= h($lib) ?></button>
      <?php endforeach; ?>
    </div>

    <div class="nd-label">Désignation (optionnel)</div>
    <input type="text" id="nd-designation" class="nd-input" placeholder="Ex. Appartement T3 avec balcon">

    <div class="nd-label">Adresse</div>
    <button type="button" class="nd-btn" style="background:#eef5fc;color:#0c5aa0;width:100%;"
            data-addr-modal-open
            data-addr-target-street1="nd-adr1" data-addr-target-postal="nd-cp"
            data-addr-target-city="nd-ville" data-addr-target-lat="nd-lat"
            data-addr-target-lng="nd-lng" data-addr-target-placeid="nd-placeid"
            data-addr-target-formatted="nd-formatted">📍 Rechercher l'adresse (Google)</button>
    <div class="nd-grid" style="margin-top:10px;">
      <input type="text" id="nd-adr1" class="nd-input full" placeholder="N° et rue" readonly>
      <input type="text" id="nd-cp" class="nd-input" placeholder="CP" readonly>
      <input type="text" id="nd-ville" class="nd-input" placeholder="Ville" readonly>
    </div>
    <input type="hidden" id="nd-lat"><input type="hidden" id="nd-lng">
    <input type="hidden" id="nd-placeid"><input type="hidden" id="nd-formatted">
    </div><!-- /nd-bien-left -->

    <div class="nd-bien-right">
      <div class="nd-label" style="margin-top:0;">Ses biens chez nous</div>
      <div id="nd-biens-list" class="nd-biens-list"><div class="nd-msg">Chargement…</div></div>
    </div>
    </div><!-- /nd-bien-cols -->

    <div class="nd-actions">
      <button type="button" class="nd-btn prev" onclick="ndStep(1)">← Retour</button>
      <button type="button" class="nd-btn next" id="nd-create" onclick="ndCreate()">Créer le dossier →</button>
    </div>
    <div class="nd-msg" id="nd-msg2"></div>
  </div>
</div>

<?php
require_once __DIR__ . '/inc/adresse_modal.php';
tiers_selector_assets();
?>
<script src="<?= h(asset_url('/js/places.js')) ?>"></script>
<script src="<?= h(asset_url('/js/adresse_modal.js')) ?>"></script>
<?php if (($GLOBALS['GOOGLE_MAPS_API_KEY'] ?? '') !== ''): ?>
<script async src="https://maps.googleapis.com/maps/api/js?key=<?= h($GLOBALS['GOOGLE_MAPS_API_KEY']) ?>&libraries=places&callback=initPlacesAutocomplete"></script>
<?php endif; ?>
<script>
(function(){
  const API_PROP = <?= json_encode(app_url('/api/transaction_dossier_new_proprio.php')) ?>;
  const API_BIEN = <?= json_encode(app_url('/api/transaction_dossier_new_bien.php')) ?>;
  const API_PBIENS = <?= json_encode(app_url('/api/transaction_dossier_proprio_biens.php')) ?>;
  const DOSSIER_URL = <?= json_encode(app_url('/transaction_dossier.php?id_bien=')) ?>;
  let idProprietaire = 0, idTiers = 0, selType = 0;

  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

  async function ndLoadBiens(){
    const box = document.getElementById('nd-biens-list');
    box.innerHTML = '<div class="nd-msg">Chargement…</div>';
    try{
      const res = await fetch(API_PBIENS+'?id_proprietaire='+idProprietaire, {credentials:'same-origin'});
      const out = await res.json();
      if(!out.ok || !out.biens.length){ box.innerHTML = '<div class="nd-msg">Aucun bien existant chez nous — créez-le à gauche.</div>'; return; }
      box.innerHTML = '';
      out.biens.forEach(b=>{
        const el = document.createElement('button');
        el.type='button'; el.className='nd-bien-btn';
        let tags='';
        if(b.en_vente) tags+='<span class="nd-bien-tag vente">en vente</span>';
        if(b.id_dossier) tags+='<span class="nd-bien-tag dossier">dossier ✓</span>';
        // ligne caractéristiques : type · surface · étage
        const carac = [
          b.type,
          (b.surface!=null ? Math.round(b.surface)+' m²' : null),
          (b.etage!=null ? (b.etage===0?'RDC':b.etage+'ᵉ ét.') : null)
        ].filter(Boolean).join(' · ');
        el.innerHTML =
          '<span class="r">'+esc(b.ref)+'</span>'+
          (carac?'<span class="d">'+esc(carac)+'</span>':'')+
          (b.adresse?'<span class="d">📍 '+esc(b.adresse)+'</span>':'')+
          (b.locataire?'<span class="d loc">👤 '+esc(b.locataire)+'</span>':'<span class="d vac">🔑 vacant</span>')+
          (tags?'<span class="tags">'+tags+'</span>':'');
        el.onclick = ()=>{ window.location.href = DOSSIER_URL + b.id; };
        box.appendChild(el);
      });
    }catch(err){ box.innerHTML = '<div class="nd-msg">Erreur de chargement.</div>'; }
  }

  const root = document.querySelector('[data-ts-root="nd_tiers"]');
  const tval = () => root ? root.querySelector('.ts-value').value : '';

  function refresh1(){ document.getElementById('nd-next1').disabled = !tval(); }
  if (root){
    root.addEventListener('tiers:selected', refresh1);
    root.addEventListener('tiers:created', refresh1);
    root.querySelector('.ts-search')?.addEventListener('input', ()=>setTimeout(refresh1,40));
    root.querySelector('.ts-clear')?.addEventListener('click', ()=>setTimeout(refresh1,10));
  }

  window.ndStep = function(n){
    document.getElementById('nd-step1').style.display = n===1?'':'none';
    document.getElementById('nd-step2').style.display = n===2?'':'none';
    document.getElementById('chip1').className = 'nd-step-chip ' + (n>1?'done':'active');
    document.getElementById('chip2').className = 'nd-step-chip ' + (n===2?'active':'');
  };

  window.ndNext1 = async function(){
    const id = tval(); if(!id) return;
    const msg = document.getElementById('nd-msg1'); msg.textContent='…';
    try{
      const res = await fetch(API_PROP, {method:'POST',credentials:'same-origin',body:new URLSearchParams({id_tiers:id})});
      const out = await res.json();
      if(!out.ok){ msg.textContent='Erreur : '+(out.error||'inconnue'); return; }
      idProprietaire = out.id_proprietaire; idTiers = out.id_tiers;
      document.getElementById('nd-prop-nom').textContent = out.nom;
      msg.textContent=''; ndStep(2); ndLoadBiens();
    }catch(err){ msg.textContent='Erreur réseau : '+err.message; }
  };

  document.getElementById('nd-types').addEventListener('click', e=>{
    const b=e.target.closest('.nd-type'); if(!b)return;
    document.querySelectorAll('#nd-types .nd-type').forEach(x=>x.classList.remove('active'));
    b.classList.add('active'); selType=parseInt(b.dataset.type,10);
  });

  window.ndCreate = async function(){
    const msg = document.getElementById('nd-msg2');
    if(!selType){ msg.textContent='Choisissez un type de bien.'; return; }
    const adr1 = document.getElementById('nd-adr1').value, ville = document.getElementById('nd-ville').value;
    if(!adr1 && !ville){ msg.textContent='Renseignez l\'adresse via 📍 Google.'; return; }
    const btn = document.getElementById('nd-create'); btn.disabled=true; msg.textContent='Création du dossier…';
    try{
      const body = new URLSearchParams({
        id_proprietaire:idProprietaire, id_tiers:idTiers, id_type_bien:selType,
        designation:document.getElementById('nd-designation').value||'',
        adresse_1:adr1, code_postal:document.getElementById('nd-cp').value||'', ville:ville,
        latitude:document.getElementById('nd-lat').value||'', longitude:document.getElementById('nd-lng').value||'',
        google_place_id:document.getElementById('nd-placeid').value||'', adresse_formatee:document.getElementById('nd-formatted').value||'',
        // Immeuble existant sélectionné dans le modal Google (anti-doublon)
        id_immeuble_selected:(document.getElementById('addr-modal-field-immeuble-id')||{}).value||''
      });
      const res = await fetch(API_BIEN, {method:'POST',credentials:'same-origin',body});
      const out = await res.json();
      if(!out.ok){ msg.textContent='Erreur : '+(out.error||'inconnue'); btn.disabled=false; return; }
      msg.style.color='#0b8043'; msg.textContent='✓ Dossier créé, ouverture…';
      window.location.href = out.url;
    }catch(err){ msg.textContent='Erreur réseau : '+err.message; btn.disabled=false; }
  };
})();
</script>
<?php include __DIR__ . '/inc/agency_layout_bottom.php'; ?>
