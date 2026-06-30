<?php
declare(strict_types=1);
/**
 * bien_nouveau.php — Écran « Enrichir un bien » (pilote, admin only, local + prod).
 *
 * Manifeste MaBoxImmo : on n'ouvre PAS sur un formulaire, et on n'empile PAS les
 * sections. CARROUSEL d'étapes — un seul focus à la fois (Lois 7 & 8), ce qu'aucun
 * logiciel immo ne fait. On ouvre sur l'ADRESSE, le seul endroit où le clavier sert
 * (Loi 1 : création = vendeur + adresse). Chaque action rembourse immédiatement
 * (Lois 4, 10, 11) : adresse → immeuble reconnu + infos reprises, en pastilles de
 * confiance (Loi 5). Le DPE ne bloque rien (Loi 6) : c'est la porte « Publier ».
 *
 * Le RAIL de pastilles en haut reste visible pendant la navigation : focus unique
 * du carrousel + vue d'ensemble de la confiance de tout le bien.
 *
 * Étape 1 livrée : ADRESSE → reconnaissance immeuble + remboursement.
 * Étapes 2-4 : slides présentes, briques suivantes.
 *
 * Accès : admin / super admin uniquement (pilote).
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_admin_or_super_admin();

$GOOGLE_MAPS_API_KEY = $GLOBALS['GOOGLE_MAPS_API_KEY'] ?? (defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '');

$pageTitle = 'Enrichir un bien';
$appLayout = true;
require_once __DIR__ . '/inc/header.php';

if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
$au = static fn(string $p) => function_exists('app_url') ? app_url($p) : $p;
$assets = static fn(string $p) => function_exists('asset_url') ? asset_url($p) : $p;
?>
<style>
  .bn-wrap { max-width: 900px; margin: 0 auto; padding: 20px 18px 80px; }
  .bn-sub { color:#64748b; font-size:13px; margin:0 0 16px; }
  .dot { width:11px; height:11px; border-radius:50%; display:inline-block; }
  .dot-g{background:#16a34a} .dot-y{background:#eab308} .dot-r{background:#dc2626} .dot-w{background:#cbd5e1}

  /* ── Rail d'étapes (toujours visible) ── */
  .bn-rail { display:flex; gap:8px; margin-bottom:18px; }
  .bn-step { flex:1; background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:10px 12px; cursor:pointer;
             display:flex; align-items:center; gap:9px; transition:border-color .15s, box-shadow .15s; min-width:0; }
  .bn-step:hover { border-color:#cbd5e1; }
  .bn-step.active { border-color:#0ea5e9; box-shadow:0 0 0 3px rgba(14,165,233,.12); }
  .bn-step-ico { font-size:17px; flex:none; }
  .bn-step-txt { min-width:0; }
  .bn-step-t { font-size:12.5px; font-weight:700; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .bn-step-s { font-size:11px; color:#64748b; display:flex; align-items:center; gap:5px; }

  /* ── Carrousel ── */
  .bn-viewport { overflow:hidden; border-radius:16px; }
  .bn-track { display:flex; transition:transform .35s cubic-bezier(.19,1,.22,1); }
  .bn-slide { flex:0 0 100%; box-sizing:border-box; }
  .bn-card { background:#fff; border:1px solid #e5e7eb; border-radius:16px; padding:24px; box-shadow:0 1px 2px rgba(15,23,42,.04); }
  .bn-card h3 { font-size:16px; font-weight:700; color:#0f172a; margin:0 0 4px; }
  .bn-card .bn-cd { font-size:12.5px; color:#64748b; margin:0 0 16px; }
  .bn-locked-note { font-size:13px; color:#94a3b8; padding:20px 0; text-align:center; }

  .bn-addr-input { width:100%; padding:15px 16px; border:2px solid #0ea5e9; border-radius:12px; font-size:16px;
                   font-family:inherit; box-sizing:border-box; outline:none; }
  .bn-addr-input:focus { box-shadow:0 0 0 4px rgba(14,165,233,.14); }
  .bn-addr-hint { font-size:12px; color:#94a3b8; margin-top:8px; }

  .bn-payback { margin-top:16px; border-radius:12px; padding:14px 16px; display:none; }
  .bn-payback.known   { background:#f0fdf4; border:1px solid #bbf7d0; }
  .bn-payback.unknown { background:#f8fafc; border:1px solid #e2e8f0; }
  .bn-payback-line { font-size:14px; font-weight:700; color:#14532d; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
  .bn-payback.unknown .bn-payback-line { color:#334155; }
  .bn-gain { background:#16a34a; color:#fff; font-size:11px; font-weight:700; border-radius:20px; padding:2px 9px; }
  .bn-chips { display:flex; flex-wrap:wrap; gap:7px; margin-top:12px; }
  .bn-chip { background:#fff; border:1px solid #d1fae5; border-radius:9px; padding:6px 10px; font-size:12px; color:#0f172a; display:inline-flex; gap:7px; align-items:center; }
  .bn-chip b { font-weight:600; color:#475569; }
  .bn-next-btn { margin-top:14px; background:#0f172a; color:#fff; border:none; border-radius:10px; padding:11px 20px; font-size:14px; font-weight:700; cursor:pointer; }
  .bn-spin { font-size:13px; color:#64748b; margin-top:14px; display:none; }
  .bn-nav { display:flex; justify-content:space-between; margin-top:16px; }
  .bn-nav button { background:#fff; border:1px solid #cbd5e1; border-radius:10px; padding:9px 16px; font-size:13px; font-weight:600; color:#475569; cursor:pointer; }
  .bn-nav button:disabled { opacity:.4; cursor:default; }

  .bn-map-btn { margin-top:14px; display:none; gap:10px; flex-wrap:wrap; }
  .bn-eye { background:linear-gradient(135deg,#243B5C,#1a2c45); color:#fff; border:none; border-radius:11px; padding:11px 18px; font-size:13px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:9px; box-shadow:0 4px 14px rgba(36,59,92,.28); transition:transform .12s, box-shadow .12s; }
  .bn-eye:hover { transform:translateY(-1px); box-shadow:0 7px 20px rgba(36,59,92,.36); }
  .bn-eye .bn-eye-dot { width:7px; height:7px; border-radius:50%; background:#D4A047; box-shadow:0 0 0 3px rgba(212,160,71,.3); }

  /* ── Modal géo premium (3 onglets) ── */
  .bn-modal { position:fixed; inset:0; z-index:9000; display:none; align-items:center; justify-content:center; padding:24px; }
  .bn-modal.open { display:flex; animation:bnFade .2s ease; }
  @keyframes bnFade { from{opacity:0} to{opacity:1} }
  .bn-modal-ov { position:absolute; inset:0; background:rgba(15,23,42,.66); backdrop-filter:blur(3px); }
  .bn-modal-card { position:relative; background:#0b1220; border-radius:18px; width:min(1040px,100%); max-height:92vh;
                   display:flex; flex-direction:column; overflow:hidden; box-shadow:0 30px 90px rgba(0,0,0,.5);
                   animation:bnPop .28s cubic-bezier(.19,1,.22,1); }
  @keyframes bnPop { from{transform:translateY(14px) scale(.98); opacity:0} to{transform:none; opacity:1} }
  .bn-modal-head { display:flex; align-items:center; gap:14px; padding:16px 20px;
                   background:linear-gradient(135deg,#243B5C 0%,#1a2c45 60%,#16243a 100%); border-bottom:1px solid rgba(255,255,255,.07); }
  .bn-modal-head .bn-mh-ico { width:38px; height:38px; border-radius:11px; display:grid; place-items:center; font-size:19px;
                              background:rgba(212,160,71,.16); border:1px solid rgba(212,160,71,.4); }
  .bn-modal-head h4 { margin:0; font-size:15px; font-weight:800; color:#fff; flex:1; letter-spacing:.2px; }
  .bn-modal-head .bn-mh-sub { font-size:11.5px; color:#a9bcd6; font-weight:500; margin-top:2px; }
  .bn-modal-x { background:rgba(255,255,255,.1); border:none; border-radius:9px; padding:8px 12px; font-size:16px; color:#fff; cursor:pointer; transition:background .12s; }
  .bn-modal-x:hover { background:rgba(255,255,255,.22); }

  .bn-modal-tabs { display:flex; gap:8px; padding:12px 20px; background:#0f1a2e; }
  .bn-tab { flex:1; border:1px solid rgba(255,255,255,.1); background:rgba(255,255,255,.04); color:#c7d4e6; border-radius:11px;
            padding:10px 12px; font-size:13px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px;
            transition:all .15s; }
  .bn-tab:hover { background:rgba(255,255,255,.09); color:#fff; }
  .bn-tab.active { background:linear-gradient(135deg,#D4A047,#c08e2f); color:#1a2233; border-color:#D4A047; box-shadow:0 4px 14px rgba(212,160,71,.3); }
  .bn-tab .bn-tab-ico { font-size:16px; }

  .bn-modal-body { flex:1; min-height:440px; background:#0b1220; position:relative; }
  .bn-pane { display:none; }
  .bn-pane.show { display:block; }
  .bn-modal-body iframe { width:100%; height:62vh; border:0; display:block; background:#0b1220; }
  .bn-pane-empty { color:#8aa0bf; font-size:13px; padding:60px 20px; text-align:center; }
  .bn-modal-foot { padding:10px 20px; background:#0f1a2e; border-top:1px solid rgba(255,255,255,.06); display:flex; justify-content:flex-end; }
  .bn-modal-foot a { color:#D4A047; font-size:12.5px; font-weight:600; text-decoration:none; }
  .bn-modal-foot a:hover { text-decoration:underline; }
</style>

<div class="bn-wrap">
  <p class="bn-sub">Une adresse, et le logiciel travaille. On ne crée pas un bien — on l'enrichit, événement après événement.</p>

  <!-- RAIL d'étapes : toujours visible, montre la confiance de tout le bien -->
  <div class="bn-rail" id="bn-rail">
    <div class="bn-step active" data-go="0">
      <span class="bn-step-ico">📍</span>
      <span class="bn-step-txt"><span class="bn-step-t">Adresse</span>
        <span class="bn-step-s" id="rs-0"><i class="dot dot-w"></i> À saisir</span></span>
    </div>
    <div class="bn-step" data-go="1">
      <span class="bn-step-ico">👤</span>
      <span class="bn-step-txt"><span class="bn-step-t">Propriétaire</span>
        <span class="bn-step-s" id="rs-1"><i class="dot dot-w"></i> En attente</span></span>
    </div>
    <div class="bn-step" data-go="2">
      <span class="bn-step-ico">📄</span>
      <span class="bn-step-txt"><span class="bn-step-t">DPE</span>
        <span class="bn-step-s" id="rs-2"><i class="dot dot-r"></i> Manquant</span></span>
    </div>
    <div class="bn-step" data-go="3">
      <span class="bn-step-ico">📷</span>
      <span class="bn-step-txt"><span class="bn-step-t">Photos</span>
        <span class="bn-step-s" id="rs-3"><i class="dot dot-w"></i> À traiter</span></span>
    </div>
  </div>

  <div class="bn-viewport">
    <div class="bn-track" id="bn-track">

      <!-- SLIDE 1 — ADRESSE -->
      <div class="bn-slide">
        <div class="bn-card">
          <h3>📍 Adresse de l'immeuble</h3>
          <p class="bn-cd">Tapez l'adresse — recherche Google obligatoire. Le seul endroit où le clavier sert.</p>
          <input type="text" id="bn-addr" class="bn-addr-input"
                 placeholder="Ex : 15 rue de la République, Lyon…"
                 autocomplete="off"
                 data-places-input
                 data-places-endpoint="<?= h($au('/api/places_autocomplete.php')) ?>"
                 data-places-details-endpoint="<?= h($au('/api/places_details.php')) ?>"
                 data-places-geocode-endpoint="<?= h($au('/api/geocode_address.php')) ?>"
                 data-places-street1="bn-f-adresse1"
                 data-places-postal="bn-f-cp"
                 data-places-city="bn-f-ville"
                 data-places-lat="bn-f-lat"
                 data-places-lng="bn-f-lng"
                 data-places-place-id="bn-f-placeid"
                 data-places-formatted="bn-f-formatted"
                 data-places-immeuble-id="bn-f-immeuble-id"
                 data-places-country-code="fr">
          <div class="bn-addr-hint">Résultats combinés : immeubles déjà connus en base + suggestions Google.</div>

          <input type="hidden" id="bn-f-adresse1"><input type="hidden" id="bn-f-cp"><input type="hidden" id="bn-f-ville">
          <input type="hidden" id="bn-f-lat"><input type="hidden" id="bn-f-lng"><input type="hidden" id="bn-f-placeid">
          <input type="hidden" id="bn-f-formatted"><input type="hidden" id="bn-f-immeuble-id">

          <div class="bn-spin" id="bn-spin">⏳ Le logiciel travaille…</div>
          <div class="bn-payback" id="bn-payback">
            <div class="bn-payback-line" id="bn-payback-line"></div>
            <div class="bn-chips" id="bn-chips"></div>
            <div class="bn-chips" id="bn-chips-extra" style="margin-top:7px"></div>
            <!-- GARDE-FOU anti-doublon : lots & propriétaires déjà connus dans l'immeuble -->
            <div id="bn-contenu" style="margin-top:12px;display:none;border:1px solid #e2e8f0;border-radius:12px;padding:12px 14px;background:#fbfdff">
              <div id="bn-contenu-head" style="font-size:12.5px;font-weight:800;color:#243B5C;margin-bottom:8px"></div>
              <div id="bn-contenu-lots"></div>
              <div id="bn-contenu-props" style="font-size:12px;color:#475569;margin-top:8px"></div>
            </div>
            <div id="bn-copro" style="margin-top:10px;display:none">
              <div style="font-size:11px;font-weight:700;color:#243B5C;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px;display:flex;align-items:center;gap:8px;flex-wrap:wrap">🏛️ Copropriété — Registre National <span id="bn-copro-imm" style="background:#D4A047;color:#1a2233;border-radius:20px;padding:2px 9px;font-weight:800;text-transform:none;letter-spacing:0"></span>
                <span id="fresh-registre" style="font-weight:500;text-transform:none;letter-spacing:0;color:#64748b;display:inline-flex;align-items:center;gap:5px"></span>
                <button type="button" class="bn-refresh" data-refresh="registre" style="text-transform:none;letter-spacing:0;background:#fff;border:1px solid #cbd5e1;border-radius:8px;padding:3px 9px;font-size:11px;font-weight:600;color:#475569;cursor:pointer">🔄 Actualiser</button>
              </div>
              <div class="bn-chips" id="bn-copro-chips"></div>
            </div>
            <div id="bn-urba" style="margin-top:10px;display:none">
              <div style="font-size:11px;font-weight:700;color:#243B5C;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px">🏗️ Urbanisme — zone &amp; constructibilité</div>
              <div id="bn-abf" style="display:none;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:9px 12px;font-size:12.5px;font-weight:600;margin-bottom:8px"></div>
              <div class="bn-chips" id="bn-urba-chips"></div>
            </div>
            <div id="bn-risks" style="margin-top:10px;display:none">
              <div style="font-size:11px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">⚠️ Risques (ERP officiel)
                <a id="bn-risks-link" href="#" target="_blank" rel="noopener" style="color:#1d4ed8;font-weight:600;text-transform:none;letter-spacing:0">↗ version web</a>
                <span id="fresh-risques" style="font-weight:500;text-transform:none;letter-spacing:0;color:#64748b;display:inline-flex;align-items:center;gap:5px"></span>
                <button type="button" class="bn-refresh" data-refresh="risques" style="text-transform:none;letter-spacing:0;background:#fff;border:1px solid #cbd5e1;border-radius:8px;padding:3px 9px;font-size:11px;font-weight:600;color:#475569;cursor:pointer">🔄 Actualiser</button>
              </div>
              <div class="bn-chips" id="bn-risks-chips"></div>
            </div>
            <!-- Rapport ERP officiel chargé AUTOMATIQUEMENT (pas de clic — Loi 11) -->
            <div id="bn-erp-inline" style="margin-top:12px;display:none">
              <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px">
                <span style="font-size:11px;font-weight:700;color:#243B5C;text-transform:uppercase;letter-spacing:.04em">📄 Rapport ERP — État des Risques (officiel)</span>
                <a id="bn-erp-full" href="#" target="_blank" rel="noopener" style="font-size:11.5px;font-weight:600;color:#1d4ed8;text-decoration:none">↗ plein écran</a>
              </div>
              <div id="bn-erp-frame-wrap" style="position:relative;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;background:#f8fafc;height:520px">
                <div id="bn-erp-loading" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#64748b;font-size:13px">⏳ Le logiciel charge le rapport ERP officiel…</div>
              </div>
            </div>
            <div class="bn-map-btn" id="bn-map-btn">
              <img id="bn-streetview" alt="Vue de l'immeuble" title="Cliquer pour ouvrir Plan · Street View · 3D"
                   style="display:none;width:150px;height:90px;object-fit:cover;border-radius:10px;border:1px solid #e5e7eb;cursor:pointer">
              <button type="button" class="bn-eye" id="bn-eye"><span class="bn-eye-dot"></span>🛰️ Voir l'immeuble — Plan · Street View · 3D</button>
            </div>
            <div id="bn-photos" style="margin-top:10px;display:none">
              <div style="font-size:11px;font-weight:700;color:#243B5C;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px">📷 Photos Google</div>
              <div id="bn-photos-grid" style="display:flex;gap:8px;flex-wrap:wrap"></div>
            </div>
            <button type="button" class="bn-next-btn" id="bn-next">Continuer → le propriétaire</button>
          </div>
        </div>
      </div>

      <!-- SLIDE 2 — PROPRIÉTAIRE -->
      <div class="bn-slide">
        <div class="bn-card">
          <h3>👤 Propriétaire</h3>
          <p class="bn-cd">Vendeur / bailleur — rattaché ou créé. Réutilise le sélecteur de tiers.</p>
          <div class="bn-locked-note" id="lock-proprio">⚪ Renseignez d'abord l'adresse pour activer cette étape.</div>
        </div>
      </div>

      <!-- SLIDE 3 — DPE -->
      <div class="bn-slide">
        <div class="bn-card">
          <h3>📄 DPE &amp; diagnostics</h3>
          <p class="bn-cd">Déposez le PDF : 27 champs remplis d'un coup. Ne bloque que la publication, jamais la saisie.</p>
          <div class="bn-locked-note">🔴 Manquant — brique suivante (le moteur d'extraction existe déjà).</div>
        </div>
      </div>

      <!-- SLIDE 4 — PHOTOS -->
      <div class="bn-slide">
        <div class="bn-card">
          <h3>📷 Photos</h3>
          <p class="bn-cd">L'IA détecte les pièces et propose un ordre.</p>
          <div class="bn-locked-note">⚪ Pas encore traité — brique suivante.</div>
        </div>
      </div>

    </div>
  </div>

  <div class="bn-nav">
    <button type="button" id="bn-prev" disabled>← Précédent</button>
    <button type="button" id="bn-fwd">Suivant →</button>
  </div>
</div>

<!-- ════ MODAL GÉO — mini-carrousel 3 vues (Plan 2D · Street View · 3D Earth) ════ -->
<div class="bn-modal" id="bn-geo-modal">
  <div class="bn-modal-ov" data-geo-close></div>
  <div class="bn-modal-card">
    <div class="bn-modal-head">
      <div class="bn-mh-ico">🛰️</div>
      <div style="flex:1;min-width:0;">
        <h4>Vue de l'immeuble</h4>
        <div class="bn-mh-sub" id="bn-geo-addr">—</div>
      </div>
      <button type="button" class="bn-modal-x" data-geo-close aria-label="Fermer">✕</button>
    </div>

    <div class="bn-modal-tabs">
      <button type="button" class="bn-tab active" data-geo-go="0"><span class="bn-tab-ico">🗺️</span> Plan 2D</button>
      <button type="button" class="bn-tab" data-geo-go="1"><span class="bn-tab-ico">📷</span> Street View</button>
      <button type="button" class="bn-tab" data-geo-go="2"><span class="bn-tab-ico">🌍</span> Vue 3D / Earth</button>
    </div>

    <div class="bn-modal-body">
      <div class="bn-pane show" id="bn-pane-0"><div class="bn-pane-empty">Chargement du plan…</div></div>
      <div class="bn-pane"      id="bn-pane-1"><div class="bn-pane-empty">Chargement Street View…</div></div>
      <div class="bn-pane"      id="bn-pane-2"><div class="bn-pane-empty">Chargement de la vue aérienne…</div></div>
    </div>

    <div class="bn-modal-foot">
      <a href="#" id="bn-geo-ext" target="_blank" rel="noopener">↗ Ouvrir dans Google Maps</a>
    </div>
  </div>
</div>

<script src="<?= h($assets('/js/places.js')) ?>?v=<?= @filemtime(__DIR__ . '/js/places.js') ?: '1' ?>"></script>
<?php if (!empty($GOOGLE_MAPS_API_KEY)): ?>
<script async src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode($GOOGLE_MAPS_API_KEY) ?>&libraries=places&callback=initPlacesAutocomplete"></script>
<?php endif; ?>
<script>
(function(){
  var RECON  = <?= json_encode($au('/api/immeuble_reconnaitre.php')) ?>;
  var ALT    = <?= json_encode($au('/api/geo_altitude.php')) ?>;
  var CAD    = <?= json_encode($au('/api/geo_cadastre_plu.php')) ?>;
  var RISK   = <?= json_encode($au('/api/geo_risques.php')) ?>;
  var URBA   = <?= json_encode($au('/api/geo_urbanisme.php')) ?>;
  var PHOTOS = <?= json_encode($au('/api/places_photos.php')) ?>;
  var CONTENU = <?= json_encode($au('/api/immeuble_contenu.php')) ?>;
  var ADDMANDAT = <?= json_encode($au('/api/bien_add_mandat.php')) ?>;
  var BIEN360 = <?= json_encode($au('/bien_360.php')) ?>;

  function escAttr(s){ return (''+s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }

  // Garde-fou anti-doublon : affiche les lots & propriétaires déjà connus de l'immeuble,
  // ou dit EXPLICITEMENT qu'il n'y en a aucun (zéro doute — confiance).
  function renderContenu(immId, isNew){
    var box = document.getElementById('bn-contenu');
    var head = document.getElementById('bn-contenu-head');
    var lotsEl = document.getElementById('bn-contenu-lots');
    var propsEl = document.getElementById('bn-contenu-props');
    box.style.display = 'block';
    if (isNew || !immId) {
      head.innerHTML = '✓ <span style="color:#16a34a">Nouvel immeuble — aucun lot existant.</span> Vous créez le 1ᵉʳ bien ici.';
      lotsEl.innerHTML = ''; propsEl.innerHTML = '';
      return;
    }
    head.textContent = '⏳ Vérification des lots déjà connus…';
    lotsEl.innerHTML = ''; propsEl.innerHTML = '';
    fetch(CONTENU + '?immeuble_id=' + encodeURIComponent(immId))
      .then(function(r){ return r.json(); })
      .then(function(a){
        if (!a || !a.ok) { head.textContent = ''; box.style.display='none'; return; }
        var lots = a.lots || [];
        if (!lots.length) {
          head.innerHTML = '✓ <span style="color:#16a34a">Aucun lot géré dans cet immeuble</span> — vous créez le premier bien ici.';
          return;
        }
        head.innerHTML = '🏠 ' + lots.length + ' lot(s) déjà connu(s) dans cet immeuble — <b>reprenez l\'existant, ne recréez pas</b> :';
        lotsEl.innerHTML = lots.map(function(l){
          var meta = [];
          if (l.lot) meta.push('Lot ' + escAttr(l.lot));
          if (l.etage !== null) meta.push('Ét. ' + l.etage);
          if (l.surface) meta.push(l.surface + ' m²');
          if (l.statut) meta.push(escAttr(l.statut));
          if (l.mission) meta.push('🎯 ' + escAttr(l.mission));
          var who = l.proprio ? (' · 👤 ' + escAttr(l.proprio)) : '';
          var enVente = (l.mission === 'vente');
          // Pièces requises pour la vente (porte) : 🟢 présent / 🔴 manquant
          var piece = function(label, ok){
            return '<span style="font-size:11px;display:inline-flex;align-items:center;gap:4px;color:' + (ok?'#16a34a':'#dc2626') + '">'
                 + '<i class="dot dot-' + (ok?'g':'r') + '"></i>' + label + '</span>';
          };
          var pieces = '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:3px">'
            + piece('DPE', l.has_dpe) + piece('Mandat vente', l.has_mandat_vente) + piece('Acte de propriété', l.has_acte)
            + '</div>';
          return '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:8px 0;border-top:1px solid #eef2f7">'
            + '<div style="flex:1;min-width:0;font-size:12.5px;color:#0f172a"><b>' + (escAttr(l.reference) || ('Bien #' + l.id)) + '</b>'
            + '<span style="color:#64748b"> · ' + meta.join(' · ') + who + '</span>' + pieces + '</div>'
            + '<a href="' + BIEN360 + '?id=' + l.id + '" style="font-size:12px;font-weight:600;color:#1d4ed8;text-decoration:none;border:1px solid #bfdbfe;border-radius:8px;padding:5px 10px">Ouvrir</a>'
            + (enVente
                ? '<span style="font-size:12px;font-weight:700;color:#16a34a">déjà en vente ✓</span>'
                : '<button type="button" class="bn-sell" data-bien="' + l.id + '" style="font-size:12px;font-weight:700;color:#fff;background:#243B5C;border:none;border-radius:8px;padding:6px 11px;cursor:pointer">→ Mettre en vente</button>')
            + '</div>';
        }).join('');
        var props = a.proprietaires || [];
        if (props.length) {
          propsEl.innerHTML = '👥 Propriétaires déjà connus ici : '
            + props.map(function(p){ return '<b>' + escAttr(p.nom) + '</b>' + (p.nb_lots>1?(' ('+p.nb_lots+' lots)'):''); }).join(' · ')
            + ' — <i>réutilisez-les, ne recréez pas un doublon.</i>';
        }
        // Actions « Mettre en vente » (ajoute la mission vente au bien EXISTANT — Phase 1)
        lotsEl.querySelectorAll('.bn-sell').forEach(function(btn){
          btn.addEventListener('click', function(){
            var bid = btn.getAttribute('data-bien');
            btn.disabled = true; btn.textContent = '⏳…';
            fetch(ADDMANDAT, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ bien_id:+bid, type_mandat:'vente' }) })
              .then(function(r){ return r.json(); })
              .then(function(res){
                if (res && res.ok) { window.location.href = BIEN360 + '?id=' + bid; }
                else { btn.disabled=false; btn.textContent='→ Mettre en vente'; alert('Échec : ' + (res && res.error || '?')); }
              })
              .catch(function(){ btn.disabled=false; btn.textContent='→ Mettre en vente'; });
          });
        });
      })
      .catch(function(){ head.textContent=''; box.style.display='none'; });
  }
  var COPRO  = <?= json_encode($au('/api/registre_copro.php')) ?>;
  var ERPPDF = <?= json_encode($au('/api/erp_rapport_pdf.php')) ?>;
  var SAVE   = <?= json_encode($au('/api/immeuble_enrichir_save.php')) ?>;
  var CSRF   = <?= json_encode(csrf_token('immeuble_enrichir')) ?>;
  // État d'enrichissement (capturé puis persisté sur l'immeuble — Loi 2)
  var st = { immId:0, cadastre:null, plu:null, altitude:null, risques:null, registre:null };

  function frFR(dt){ if(!dt) return ''; var d=new Date((''+dt).replace(' ','T')); return isNaN(d)?'':d.toLocaleDateString('fr-FR'); }

  // Persiste une source si l'immeuble est connu (sinon différé à la naissance du bien).
  function persist(source){
    if (!st.immId) return;
    var payload = { immeuble_id: st.immId, csrf: CSRF };
    if (source === 'cadastre') { payload.cadastre = st.cadastre; payload.plu = st.plu; payload.altitude = st.altitude; }
    if (source === 'registre') { payload.registre = st.registre; }
    if (source === 'risques')  { payload.risques  = st.risques; }
    fetch(SAVE, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) })
      .then(function(r){ return r.json(); })
      .then(function(res){ if (res && res.ok) markFresh(source, res.dates[source]); })
      .catch(function(){});
  }
  function markFresh(source, dt){
    var el = document.getElementById('fresh-' + source);
    if (el) el.innerHTML = '<i class="dot dot-g"></i> à jour le ' + (frFR(dt) || "aujourd'hui");
  }
  var GKEY   = <?= json_encode((string)$GOOGLE_MAPS_API_KEY) ?>;
  var TOTAL = 4, cur = 0, addrDone = false;
  var geo = { lat:'', lng:'', label:'' };
  var track = document.getElementById('bn-track');
  var steps = document.querySelectorAll('.bn-step');
  var prevB = document.getElementById('bn-prev'), fwdB = document.getElementById('bn-fwd');

  function go(i){
    if (i < 0 || i >= TOTAL) return;
    cur = i;
    track.style.transform = 'translateX(-' + (i*100) + '%)';
    steps.forEach(function(s,idx){ s.classList.toggle('active', idx===i); });
    prevB.disabled = (i===0);
    fwdB.disabled  = (i===TOTAL-1);
  }
  document.getElementById('bn-prev').addEventListener('click', function(){ go(cur-1); });
  document.getElementById('bn-fwd').addEventListener('click', function(){ go(cur+1); });
  steps.forEach(function(s){ s.addEventListener('click', function(){ go(+s.getAttribute('data-go')); }); });

  function setRail(idx, color, txt){
    document.getElementById('rs-' + idx).innerHTML = '<i class="dot dot-' + color + '"></i> ' + txt;
  }

  var input    = document.getElementById('bn-addr');
  var spin     = document.getElementById('bn-spin');
  var payback  = document.getElementById('bn-payback');
  var line     = document.getElementById('bn-payback-line');
  var chipsBox = document.getElementById('bn-chips');

  function chip(label, value, color){
    return '<span class="bn-chip"><i class="dot dot-' + (color||'g') + '"></i><b>' + label + '</b>' + (value ? ' ' + value : '') + '</span>';
  }

  document.getElementById('bn-next').addEventListener('click', function(){
    document.getElementById('lock-proprio').textContent = '🟡 (Brique suivante) — sélecteur de tiers à brancher ici.';
    setRail(1, 'y', 'À renseigner');
    go(1);
  });

  var chipsExtra = document.getElementById('bn-chips-extra');
  var mapBtn = document.getElementById('bn-map-btn');

  // Compteur dynamique : compte les chips réellement affichés (base + altitude
  // async + infos immeuble), peu importe l'ordre d'arrivée.
  function refreshCount(){
    var el = document.getElementById('bn-count');
    if (!el) return;
    var n = chipsBox.querySelectorAll('.bn-chip:not(a)').length + chipsExtra.querySelectorAll('.bn-chip:not(a)').length;
    el.textContent = n + ' infos reprises';
  }

  input.addEventListener('places:filled', function(ev){
    var d = ev.detail || {};
    addrDone = true;
    spin.style.display = 'none';
    payback.style.display = 'block';
    chipsExtra.innerHTML = '';

    // Mémorise la géo pour le modal + révèle le bouton de visualisation
    geo.lat = d.latitude || ''; geo.lng = d.longitude || '';
    geo.label = d.adresse_formatee || ((d.adresse_1||'') + ' ' + (d.ville||''));
    if (geo.lat && geo.lng) {
      mapBtn.style.display = 'flex';
      erpLoadInline();
      // Vignette photo Street View (façade) — Google, quasi toujours dispo
      if (GKEY) {
        var sv = document.getElementById('bn-streetview');
        sv.src = 'https://maps.googleapis.com/maps/api/streetview?size=300x180&location=' + encodeURIComponent(geo.lat + ',' + geo.lng) + '&fov=80&pitch=10&key=' + GKEY;
        sv.style.display = 'block';
        sv.onclick = geoOpen;
      }
      // Galerie photos Google Places — n'apparaît QUE s'il y en a
      var pid = d.google_place_id || d.place_id || '';
      if (pid) {
        fetch(PHOTOS + '?place_id=' + encodeURIComponent(pid))
          .then(function(r){ return r.json(); })
          .then(function(a){
            if (!a || !a.ok || !a.photos || !a.photos.length) return;
            document.getElementById('bn-photos-grid').innerHTML = a.photos.map(function(u){
              return '<a href="' + u + '" target="_blank" rel="noopener"><img src="' + u + '" alt="photo" loading="lazy" style="width:120px;height:90px;object-fit:cover;border-radius:9px;border:1px solid #e5e7eb"></a>';
            }).join('');
            document.getElementById('bn-photos').style.display = 'block';
          }).catch(function(){});
      }
      // Urbanisme détaillé (zone, destinations, prescriptions, ABF)
      fetch(URBA + '?lat=' + encodeURIComponent(geo.lat) + '&lng=' + encodeURIComponent(geo.lng))
        .then(function(r){ return r.json(); })
        .then(function(a){
          if (!a || !a.ok || !a.zone) return;
          var chipsU = document.getElementById('bn-urba-chips');
          var html = [];
          if (a.zone.libelle)  html.push(chip('Zone', a.zone.libelle + (a.zone.type ? ' (' + a.zone.type + ')' : ''), 'g'));
          if (a.zone.libelong) html.push(chip('Libellé', a.zone.libelong, 'g'));
          if (a.destinations && a.destinations.autorise) html.push(chip('Destinations autorisées', a.destinations.autorise, 'g'));
          if (a.destinations && a.destinations.interdit) html.push(chip('Interdites', a.destinations.interdit, 'g'));
          (a.prescriptions || []).forEach(function(pr){
            html.push('<span class="bn-chip" style="border-color:#fde68a;background:#fffbeb"><i class="dot" style="background:#d97706"></i><b>Servitude</b> ' + pr + '</span>');
          });
          if (a.reglement && (a.reglement.url || a.reglement.nom)) {
            var ru = a.reglement.url || ('https://www.geoportail-urbanisme.gouv.fr/map/#tile=1&lon=' + geo.lng + '&lat=' + geo.lat + '&zoom=18');
            html.push('<a class="bn-chip" href="' + ru + '" target="_blank" rel="noopener" style="border-color:#bfdbfe;text-decoration:none">↗ <b style="color:#1d4ed8">Règlement de la zone</b>' + (a.reglement.nom ? ' (' + a.reglement.nom + ')' : '') + '</a>');
          }
          chipsU.innerHTML = html.join('');
          if (a.abf) {
            var abfBox = document.getElementById('bn-abf');
            abfBox.innerHTML = '🏛️ Secteur protégé — <b>avis ABF obligatoire</b>' + (a.abf_motif ? ' · ' + a.abf_motif : '');
            abfBox.style.display = 'block';
          }
          document.getElementById('bn-urba').style.display = 'block';
        }).catch(function(){});
    }

    // Altitude (Google Elevation) — influence la zone climatique du DPE (Loi 4)
    if (geo.lat && geo.lng) {
      fetch(ALT + '?lat=' + encodeURIComponent(geo.lat) + '&lng=' + encodeURIComponent(geo.lng))
        .then(function(r){ return r.json(); })
        .then(function(a){
          if (a && a.ok && a.altitude != null) {
            st.altitude = a.altitude;
            var zone = a.altitude > 800 ? ' (zone DPE >800 m)' : '';
            chipsExtra.insertAdjacentHTML('beforeend', chip('Altitude', a.altitude + ' m' + zone, 'g'));
            refreshCount();
            if (st.cadastre) persist('cadastre'); // altitude rejoint le bloc cadastre
          }
        }).catch(function(){});

      // Cadastre + zone PLU (API Carto IGN, officiel 🟢)
      fetch(CAD + '?lat=' + encodeURIComponent(geo.lat) + '&lng=' + encodeURIComponent(geo.lng))
        .then(function(r){ return r.json(); })
        .then(function(a){
          if (!a || !a.ok) return;
          st.cadastre = a.parcelle || null;
          st.plu = a.plu || null;
          if (a.parcelle) {
            var ref = [a.parcelle.section, a.parcelle.numero].filter(Boolean).join(' ');
            if (ref) chipsExtra.insertAdjacentHTML('beforeend', chip('Parcelle', ref + (a.parcelle.commune ? ' · ' + a.parcelle.commune : ''), 'g'));
            if (a.parcelle.contenance) chipsExtra.insertAdjacentHTML('beforeend', chip('Surface parcelle', a.parcelle.contenance + ' m²', 'g'));
          }
          if (a.plu && a.plu.type) {
            chipsExtra.insertAdjacentHTML('beforeend', chip('Zone PLU', a.plu.type + (a.plu.libelle ? ' — ' + a.plu.libelle : ''), 'g'));
          }
          // Lien officiel : règlement PLU de la commune (Géoportail de l'Urbanisme), centré sur la parcelle
          var gpu = 'https://www.geoportail-urbanisme.gouv.fr/map/#tile=1&lon=' + encodeURIComponent(geo.lng) + '&lat=' + encodeURIComponent(geo.lat) + '&zoom=18';
          chipsExtra.insertAdjacentHTML('beforeend',
            '<a class="bn-chip" href="' + gpu + '" target="_blank" rel="noopener" style="border-color:#bfdbfe;color:#1d4ed8;text-decoration:none">↗ <b style="color:#1d4ed8">Voir le PLU de la commune</b></a>');
          refreshCount();
          persist('cadastre');
        }).catch(function(){});

      // Risques naturels & technologiques (Géorisques, officiel) → ERP
      fetch(RISK + '?lat=' + encodeURIComponent(geo.lat) + '&lng=' + encodeURIComponent(geo.lng))
        .then(function(r){ return r.json(); })
        .then(function(a){
          if (!a || !a.ok || !a.risques || !a.risques.length) return;
          st.risques = a; persist('risques');
          var box = document.getElementById('bn-risks');
          var chipsR = document.getElementById('bn-risks-chips');
          if (a.url) document.getElementById('bn-risks-link').href = a.url;
          chipsR.innerHTML = a.risques.map(function(rq){
            var statut = rq.statut ? ' · ' + rq.statut : '';
            var amber = /important|fort|existant/i.test(rq.statut) ? '#dc2626' : '#d97706';
            return '<span class="bn-chip bn-risk" style="border-color:#fde68a;background:#fffbeb">'
                 + '<i class="dot" style="background:' + amber + '"></i><b>' + rq.label + '</b>'
                 + '<span style="color:#92400e">' + statut + '</span></span>';
          }).join('');
          box.style.display = 'block';
        }).catch(function(){});

      // Copropriété — Registre National (parcelle → immatriculation → tout, officiel)
      fetch(COPRO + '?lat=' + encodeURIComponent(geo.lat) + '&lng=' + encodeURIComponent(geo.lng))
        .then(function(r){ return r.json(); })
        .then(function(a){
          if (!a || !a.ok || !a.trouve) return;
          st.registre = a; persist('registre');
          document.getElementById('bn-copro-imm').textContent = a.immatriculation || '';
          document.getElementById('bn-copro-chips').innerHTML = (a.infos || []).map(function(info){
            return chip(info.label, info.value, 'g');
          }).join('');
          document.getElementById('bn-copro').style.display = 'block';
        }).catch(function(){});
    }

    // Chaque fait UNE fois : l'adresse normalisée contient déjà CP + ville ;
    // le GPS résume lat/long. Pas de chip redondant.
    var base = [];
    var adr = d.adresse_formatee || ((d.adresse_1||'') + ' ' + (d.code_postal||'') + ' ' + (d.ville||'')).trim();
    if (adr) base.push(chip('Adresse', adr, 'g'));
    if (d.quartier)         base.push(chip('Quartier', d.quartier, 'g'));
    if (d.latitude && d.longitude) base.push(chip('GPS', (+d.latitude).toFixed(5) + ', ' + (+d.longitude).toFixed(5), 'g'));

    // Affichage provisoire pendant la reconnaissance anti-doublon
    chipsBox.innerHTML = base.join('');
    payback.className = 'bn-payback';
    spin.style.display = 'block'; spin.textContent = '⏳ Je vérifie si cet immeuble existe déjà…';

    // Reconnaissance ROBUSTE anti-doublon (3 stratégies, comme bien_autosave) —
    // on NE se fie PAS au immeuble_connu de places_details (format adresse_cle ≠).
    var q = '?adresse_1=' + encodeURIComponent(d.adresse_1 || '')
          + '&adresse_2=' + encodeURIComponent(d.adresse_2 || '')
          + '&code_postal=' + encodeURIComponent(d.code_postal || '')
          + '&ville=' + encodeURIComponent(d.ville || '')
          + '&place_id=' + encodeURIComponent(d.google_place_id || d.place_id || '')
          + '&lat=' + encodeURIComponent(d.latitude || '')
          + '&lng=' + encodeURIComponent(d.longitude || '');
    fetch(RECON + q)
      .then(function(r){ return r.json(); })
      .then(function(a){
        spin.style.display = 'none';
        if (a && a.connu) {
          payback.className = 'bn-payback known';
          setRail(0, 'g', 'Immeuble reconnu');
          st.immId = a.immeuble_id || 0;
          // Fraîcheur déjà stockée
          if (a.enrichi) {
            if (a.enrichi.cadastre) markFresh('cadastre', a.enrichi.cadastre);
            if (a.enrichi.registre) markFresh('registre', a.enrichi.registre);
            if (a.enrichi.risques)  markFresh('risques',  a.enrichi.risques);
          }
          // Flush des sources captées avant la reconnaissance
          if (st.cadastre) persist('cadastre');
          if (st.registre) persist('registre');
          if (st.risques)  persist('risques');
          // Garde-fou anti-doublon : lots & propriétaires déjà connus
          renderContenu(st.immId, false);
          var rep = a.infos || [];
          var n = base.length + rep.length;
          var via = a.match === 'gps' ? ' par GPS' : (a.match === 'place_id' ? ' par Place ID' : (a.match ? ' par adresse' : ''));
          line.innerHTML = '🟢 Immeuble reconnu' + via + ' (#' + a.immeuble_id + ')'
            + (a.nb_biens ? ' · ' + a.nb_biens + ' lot(s) déjà en base' : '')
            + ' <span class="bn-gain" id="bn-count"></span> <span class="bn-gain">~6 min gagnées</span>';
          var html = base.slice();
          rep.forEach(function(info){ html.push(chip(info.label, info.value, 'g')); });
          chipsBox.innerHTML = html.join('');
          refreshCount();
        } else {
          payback.className = 'bn-payback unknown';
          setRail(0, 'w', 'Nouvel immeuble');
          line.innerHTML = '⚪ Nouvel immeuble — aucun doublon trouvé, je le crée à partir de Google'
            + ' <span class="bn-gain" id="bn-count" style="background:#64748b"></span>';
          chipsBox.innerHTML = base.join('');
          refreshCount();
          renderContenu(0, true); // nouvel immeuble : dit explicitement « aucun lot existant »
        }
      })
      .catch(function(){
        spin.style.display = 'none';
        payback.className = 'bn-payback unknown';
        setRail(0, 'w', 'Vérif. impossible');
        line.innerHTML = '⚠️ Reconnaissance indisponible — vérifie manuellement avant de créer'
          + ' <span class="bn-gain" style="background:#dc2626">doublon possible</span>';
        chipsBox.innerHTML = base.join('');
      });
  });

  // ── Rapport ERP : chargé AUTOMATIQUEMENT inline (aucun clic — Loi 11) ──
  function erpLoadInline(){
    if (!geo.lat || !geo.lng) return;
    var src = ERPPDF + '?lat=' + encodeURIComponent(geo.lat) + '&lng=' + encodeURIComponent(geo.lng);
    var panel = document.getElementById('bn-erp-inline');
    var wrap  = document.getElementById('bn-erp-frame-wrap');
    document.getElementById('bn-erp-full').href = src;
    panel.style.display = 'block';
    if (wrap.querySelector('iframe')) return; // déjà chargé
    var f = document.createElement('iframe');
    f.style.cssText = 'width:100%;height:100%;border:0;display:block';
    f.src = src;
    f.onload = function(){ var l = document.getElementById('bn-erp-loading'); if (l) l.remove(); };
    wrap.appendChild(f);
  }

  // ── Modal géo : mini-carrousel 3 vues ──
  var modal = document.getElementById('bn-geo-modal');
  var geoCur = 0, geoLoaded = [false,false,false];

  function geoUrl(i){
    var c = geo.lat + ',' + geo.lng;
    if (i === 0) return 'https://www.google.com/maps/embed/v1/place?key=' + GKEY + '&q=' + encodeURIComponent(c) + '&zoom=18&maptype=roadmap';
    if (i === 1) return 'https://www.google.com/maps/embed/v1/streetview?key=' + GKEY + '&location=' + c + '&heading=210&pitch=10&fov=80';
    return 'https://www.google.com/maps/embed/v1/view?key=' + GKEY + '&center=' + c + '&zoom=19&maptype=satellite';
  }
  function geoLoad(i){
    if (geoLoaded[i] || !geo.lat || !geo.lng || !GKEY) return;
    geoLoaded[i] = true;
    var pane = document.getElementById('bn-pane-' + i);
    var f = document.createElement('iframe');
    f.setAttribute('loading','lazy'); f.setAttribute('allowfullscreen','');
    f.referrerPolicy = 'no-referrer-when-downgrade';
    f.src = geoUrl(i);
    pane.innerHTML = ''; pane.appendChild(f);
  }
  function geoGo(i){
    geoCur = i;
    document.querySelectorAll('.bn-tab').forEach(function(t,idx){ t.classList.toggle('active', idx===i); });
    document.querySelectorAll('.bn-pane').forEach(function(p,idx){ p.classList.toggle('show', idx===i); });
    geoLoad(i);
  }
  function geoOpen(){
    if (!geo.lat || !geo.lng) return;
    document.getElementById('bn-geo-addr').textContent = geo.label || (geo.lat + ', ' + geo.lng);
    document.getElementById('bn-geo-ext').href = 'https://www.google.com/maps/search/?api=1&query=' + geo.lat + ',' + geo.lng;
    geoLoaded = [false,false,false];
    document.querySelectorAll('.bn-pane').forEach(function(p,idx){
      p.innerHTML = '<div class="bn-pane-empty">Chargement…</div>'; p.classList.toggle('show', idx===0);
    });
    modal.classList.add('open');
    geoGo(0);
  }
  function geoClose(){ modal.classList.remove('open'); }

  document.getElementById('bn-eye').addEventListener('click', geoOpen);
  document.querySelectorAll('[data-geo-close]').forEach(function(el){ el.addEventListener('click', geoClose); });
  document.querySelectorAll('[data-geo-go]').forEach(function(t){ t.addEventListener('click', function(){ geoGo(+t.getAttribute('data-geo-go')); }); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') geoClose(); });

  // ── Boutons 🔄 : réactualisent une source live + re-persistent ──
  document.querySelectorAll('.bn-refresh').forEach(function(b){
    b.addEventListener('click', function(){
      if (!geo.lat || !geo.lng) return;
      var src = b.getAttribute('data-refresh');
      var fresh = document.getElementById('fresh-' + src);
      if (fresh) fresh.innerHTML = '⏳ actualisation…';
      var url = src === 'registre' ? COPRO : (src === 'risques' ? RISK : CAD);
      fetch(url + '?lat=' + encodeURIComponent(geo.lat) + '&lng=' + encodeURIComponent(geo.lng))
        .then(function(r){ return r.json(); })
        .then(function(a){
          if (src === 'registre' && a && a.trouve) st.registre = a;
          else if (src === 'risques' && a && a.risques) st.risques = a;
          else if (src === 'cadastre' && a) { st.cadastre = a.parcelle; st.plu = a.plu; }
          persist(src); // persist() marque la fraîcheur au succès
        })
        .catch(function(){ if (fresh) fresh.innerHTML = '⚠️ échec'; });
    });
  });

  go(0);
})();
</script>
<?php require_once __DIR__ . '/inc/footer.php'; ?>
