<?php
declare(strict_types=1);
/**
 * inc/bail_edit_modal.php — Modal « Créer un projet de bail commercial » (workflow type mandat).
 *
 * 2 colonnes comme le mandat : APERÇU LIVE du bail à gauche (se construit au fil de la saisie)
 * + FORMULAIRE à droite. Prefill auto depuis le bien (société/agence/propriétaire/immeuble/bien
 * lus en BDD par la page appelante). POST → api/bien_add_bail.php → bail_360 du projet.
 *
 * Ouverture JS : window.bailOpenCreateModal(prefill)   Fermeture : bailCloseModal()
 */
if (!function_exists('bail_edit_modal')) {
function bail_edit_modal(): void
{
    static $done = false; if ($done) return; $done = true;
    $api = function_exists('app_url') ? app_url('/api/bien_add_bail.php') : '/api/bien_add_bail.php';
    $apiExtract = function_exists('app_url') ? app_url('/api/bail_candidat_extract.php') : '/api/bail_candidat_extract.php';
    ?>
<div id="belModal" class="bel-modal" hidden>
  <div class="bel-box" role="dialog" aria-modal="true" aria-label="Créer un projet de bail">
    <div class="bel-head">
      <div class="bel-title">🔑 <span id="bel-title-text">Créer un projet de bail commercial</span></div>
      <button type="button" class="bel-x" onclick="bailCloseModal()" aria-label="Fermer">✕</button>
    </div>

    <div class="bel-main">
      <!-- Aperçu live (gauche) -->
      <div class="bel-preview" id="bel-preview">
        <div class="bel-viewbar">
          <span class="bel-viewseg">
            <button type="button" id="bel-view-simple" class="bel-vbtn" onclick="bailSetView('simple')">Résumé</button>
            <button type="button" id="bel-view-full" class="bel-vbtn is-on" onclick="bailSetView('full')">Bail étoffé</button>
          </span>
          <span class="bel-viewnote">📄 Le PDF sortira toujours le bail complet détaillé.</span>
        </div>
        <div id="bel-preview-body"></div>
      </div>

      <!-- Formulaire (droite) -->
      <div class="bel-form">
        <p class="bel-hint">Société, agence, propriétaire, immeuble et bien sont repris de la base. Tu complètes le candidat et les conditions ; l'aperçu se met à jour à gauche.</p>

        <div class="bel-sec">👤 Candidat locataire</div>
        <div class="bel-upload">
          <button type="button" class="bel-up-btn" onclick="bailOpenCandDocs()">📎 Charger les documents du candidat (GED)</button>
          <span class="bel-up-hint">Ouvre le module FluxBox : KBIS, pièce d'identité, RIB, bilan, prévisionnel… → nommés et classés en GED, rattachés au bail.</span>
          <div id="bel-up-msg" class="bel-up-msg"></div>
        </div>
        <div class="bel-grid">
          <label class="bel-f"><span>Type</span>
            <select id="bel-cand-type">
              <option value="societe">Société</option>
              <option value="physique">Personne physique</option>
            </select></label>
          <label class="bel-f bel-soc-only"><span>Raison sociale</span><input type="text" id="bel-cand-raison" placeholder="Ex. MOTO FEELING 74"></label>
          <label class="bel-f bel-soc-only"><span>SIREN</span><input type="text" id="bel-cand-siren" placeholder="Ex. 384714119"></label>
          <label class="bel-f bel-phys-only" hidden><span>Nom</span><input type="text" id="bel-cand-nom"></label>
          <label class="bel-f bel-phys-only" hidden><span>Prénom(s)</span><input type="text" id="bel-cand-prenom" placeholder="Tous les prénoms"></label>
          <label class="bel-f bel-phys-only" hidden><span>Date de naissance</span><input type="date" id="bel-cand-birthdate"></label>
          <label class="bel-f bel-phys-only" hidden><span>Lieu de naissance</span><input type="text" id="bel-cand-birthplace" placeholder="Ville (dépt)"></label>
          <label class="bel-f bel-phys-only" hidden><span>Nationalité</span><input type="text" id="bel-cand-nat" placeholder="Française"></label>
          <label class="bel-f bel-wide"><span id="bel-cand-adresse-lbl">Adresse (domicile / siège)</span><input type="text" id="bel-cand-adresse" placeholder="N°, voie, code postal, ville"></label>
          <label class="bel-f"><span>Email</span><input type="email" id="bel-cand-email"></label>
          <label class="bel-f"><span>Téléphone</span><input type="text" id="bel-cand-tel"></label>
          <label class="bel-f bel-soc-only"><span>Représentant</span><input type="text" id="bel-cand-rep" placeholder="Nom du signataire"></label>
          <label class="bel-f bel-soc-only"><span>Qualité représentant</span><input type="text" id="bel-cand-repq" placeholder="Gérant, Président…"></label>
        </div>

        <div class="bel-sec">🛡️ Garant / caution solidaire</div>
        <label class="bel-f bel-check" style="margin-bottom:8px"><input type="checkbox" id="bel-garant-present"> <span>Un garant se porte caution du preneur</span></label>
        <div class="bel-grid bel-garant-only" hidden>
          <label class="bel-f"><span>Type</span>
            <select id="bel-garant-type">
              <option value="physique">Personne physique</option>
              <option value="societe">Société</option>
            </select></label>
          <label class="bel-f bel-gphys-only"><span>Nom</span><input type="text" id="bel-garant-nom"></label>
          <label class="bel-f bel-gphys-only"><span>Prénom(s)</span><input type="text" id="bel-garant-prenom"></label>
          <label class="bel-f bel-gphys-only"><span>Date de naissance</span><input type="date" id="bel-garant-birthdate"></label>
          <label class="bel-f bel-gphys-only"><span>Lieu de naissance</span><input type="text" id="bel-garant-birthplace" placeholder="Ville (dépt)"></label>
          <label class="bel-f bel-gsoc-only" hidden><span>Raison sociale</span><input type="text" id="bel-garant-raison"></label>
          <label class="bel-f bel-gsoc-only" hidden><span>SIREN</span><input type="text" id="bel-garant-siren"></label>
          <label class="bel-f bel-wide"><span>Adresse du garant</span><input type="text" id="bel-garant-adresse" placeholder="N°, voie, code postal, ville"></label>
          <label class="bel-f"><span>Email</span><input type="email" id="bel-garant-email"></label>
          <label class="bel-f"><span>Téléphone</span><input type="text" id="bel-garant-tel"></label>
          <label class="bel-f"><span>Montant de l'engagement (€)</span><input type="number" step="0.01" id="bel-garant-montant" placeholder="Ex. 94200"></label>
          <label class="bel-f"><span>Durée de l'engagement (ans)</span><input type="number" step="1" id="bel-garant-duree" placeholder="Ex. 9"></label>
          <label class="bel-f bel-check"><input type="checkbox" id="bel-garant-solidaire" checked> <span>Caution solidaire</span></label>
        </div>

        <div class="bel-sec">📄 Conditions du bail</div>
        <div class="bel-grid">
          <label class="bel-f bel-wide"><span>Destination / activité autorisée</span>
            <input type="text" id="bel-destination" placeholder="Ex. Vente et réparation de cycles et motocycles"></label>
          <label class="bel-f"><span>Prise d'effet</span><input type="date" id="bel-date-effet"></label>
          <label class="bel-f"><span>Durée</span>
            <select id="bel-duree">
              <option value="108" selected>9 ans (108 mois)</option>
              <option value="36">Dérogatoire 3 ans</option>
            </select></label>
          <label class="bel-f bel-check"><input type="checkbox" id="bel-ferme"> <span>6 ans fermes (renonciation 1ʳᵉ triennale)</span></label>
          <label class="bel-f"><span>Loyer annuel HT/HC (€)</span><input type="number" step="0.01" id="bel-loyer" placeholder="Ex. 94200"></label>
          <label class="bel-f"><span>Provision charges / mois (€)</span><input type="number" step="0.01" id="bel-charges" placeholder="Ex. 800"></label>
          <label class="bel-f"><span>Paiement d'avance</span>
            <select id="bel-perio"><option value="mensuelle" selected>Par mois d'avance</option><option value="trimestrielle">Par trimestre d'avance</option></select></label>
          <label class="bel-f bel-check"><input type="checkbox" id="bel-tva" checked> <span>Assujetti à la TVA (loyer + TVA 20 %)</span></label>
          <label class="bel-f"><span>Provision taxe foncière / mois (€)</span><input type="number" step="0.01" id="bel-tf" placeholder="Ex. 250"></label>
          <label class="bel-f bel-check"><input type="checkbox" id="bel-tech" checked> <span>Honoraires gestion technique récupérables</span></label>
          <label class="bel-f bel-tech-only"><span>% gestion technique</span><input type="number" step="0.01" id="bel-tech-pct" value="1.5"></label>
          <label class="bel-f"><span>Honoraires bailleur TTC (€)</span><input type="number" step="0.01" id="bel-hono-bail" placeholder="Ex. 2000"></label>
          <label class="bel-f"><span>Honoraires locataire TTC (€)</span><input type="number" step="0.01" id="bel-hono-loc" placeholder="Ex. 8000"></label>
          <label class="bel-f"><span>Indice</span>
            <select id="bel-indice"><option value="ILC" selected>ILC (commercial)</option><option value="ILAT">ILAT</option><option value="ICC">ICC</option></select></label>
          <label class="bel-f"><span>Trimestre de base</span><input type="text" id="bel-indice-trim" placeholder="Ex. 3T2025"></label>
          <label class="bel-f"><span>Valeur indice de base</span><input type="number" step="0.001" id="bel-indice-val" placeholder="Ex. 137.09"></label>
          <label class="bel-f"><span>Dépôt de garantie (mois)</span><input type="number" step="1" id="bel-dg" placeholder="Ex. 3"></label>
          <label class="bel-f bel-check"><input type="checkbox" id="bel-erp"> <span>Local ERP (recevant du public)</span></label>
          <label class="bel-f bel-check"><input type="checkbox" id="bel-opt"> <span>Bail avec option d'achat</span></label>
          <label class="bel-f bel-opt-only" hidden><span>Prix option (€ HT)</span><input type="number" step="0.01" id="bel-opt-prix"></label>
          <label class="bel-f bel-opt-only" hidden><span>Délai de levée (mois)</span><input type="number" step="1" id="bel-opt-delai" placeholder="Ex. 24"></label>
        </div>

        <div class="bel-sec">📝 Conditions particulières</div>
        <div class="bel-grid">
          <label class="bel-f bel-wide"><span>Conditions particulières (générales)</span>
            <textarea id="bel-cp" rows="3" placeholder="Ex. franchise de loyer, travaux à charge, clause spécifique d'exploitation…" style="margin-top:4px;padding:8px 10px;border:1px solid #cbd8da;border-radius:8px;font-size:13px;resize:vertical;font-family:inherit;"></textarea></label>
          <label class="bel-f bel-wide"><span>Conditions particulières — loyer</span>
            <textarea id="bel-cp-loyer" rows="3" placeholder="Ex. loyer progressif par paliers, franchise 3 mois, indexation dérogatoire…" style="margin-top:4px;padding:8px 10px;border:1px solid #cbd8da;border-radius:8px;font-size:13px;resize:vertical;font-family:inherit;"></textarea></label>
        </div>
      </div>
    </div>

    <div class="bel-foot">
      <span id="bel-msg" class="bel-msg"></span>
      <button type="button" class="bel-btn bel-cancel" onclick="bailCloseModal()">Annuler</button>
      <button type="button" class="bel-btn bel-save" id="bel-save" onclick="bailSubmitProjet()">💾 Créer le projet</button>
    </div>
  </div>
</div>
<style>
.bel-modal{position:fixed;inset:0;z-index:9500;background:rgba(15,18,24,.55);display:flex;align-items:center;justify-content:center;padding:18px}
.bel-modal[hidden]{display:none !important}
.bel-box{background:#fff;border-radius:16px;width:min(1180px,97vw);max-height:94vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.35)}
.bel-head{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #eef0f2;background:linear-gradient(135deg,#84A7AB,#5f8f93)}
.bel-title{font-size:16px;font-weight:800;color:#fff}
.bel-x{border:none;background:rgba(255,255,255,.22);color:#fff;width:32px;height:32px;border-radius:50%;cursor:pointer;font-weight:700;font-size:15px}
.bel-x:hover{background:rgba(255,255,255,.35)}
.bel-main{flex:1;display:flex;min-height:0;overflow:hidden}
.bel-preview{width:46%;min-width:360px;overflow:auto;background:#eef1f3;padding:20px}
.bel-viewbar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:-4px 0 14px}
.bel-viewseg{display:inline-flex;background:#dde5e6;border-radius:999px;padding:3px}
.bel-vbtn{border:none;background:transparent;color:#5b6b70;font-size:11.5px;font-weight:800;padding:5px 13px;border-radius:999px;cursor:pointer}
.bel-vbtn.is-on{background:#fff;color:#3a5a5c;box-shadow:0 1px 3px rgba(20,30,50,.14)}
.bel-viewnote{font-size:10.5px;color:#8a97a0;font-style:italic}
.bel-form{flex:1;overflow:auto;padding:8px 18px 4px;border-left:1px solid #e7ebef}
.bel-hint{margin:8px 0 0;font-size:11.5px;color:#8a97a0}
.bel-sec{font-size:12px;font-weight:800;color:#5f8f93;text-transform:uppercase;letter-spacing:.05em;margin:14px 0 8px;border-bottom:1px solid #eef2f2;padding-bottom:5px}
.bel-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}
.bel-f{display:flex;flex-direction:column;font-size:12px;color:#5b6b70;font-weight:600}
.bel-f.bel-wide{grid-column:1/-1}
.bel-f input,.bel-f select{margin-top:4px;padding:8px 10px;border:1px solid #cbd8da;border-radius:8px;font-size:13px}
.bel-f.bel-check{flex-direction:row;align-items:center;gap:8px;color:#3a5a5c}
.bel-f.bel-check input{margin-top:0}
.bel-upload{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:0 0 10px;padding:10px;border:1px dashed #b7cdcf;border-radius:10px;background:#f4f9f9}
.bel-up-btn{border:1.5px solid #5f8f93;background:#fff;color:#3a5a5c;border-radius:999px;padding:6px 13px;font-size:12.5px;font-weight:800;cursor:pointer}
.bel-up-btn:hover{background:#eef5f5}
.bel-up-hint{font-size:11px;color:#8a97a0;flex:1;min-width:160px}
.bel-up-msg{width:100%;font-size:12px;font-weight:700}
.bel-foot{display:flex;align-items:center;gap:10px;padding:12px 18px;border-top:1px solid #eef0f2}
.bel-msg{flex:1;font-size:12.5px;font-weight:700}
.bel-btn{border:none;border-radius:9px;padding:9px 16px;font-weight:800;cursor:pointer}
.bel-cancel{background:#eceef1;color:#5b6b70}
.bel-save{background:#84A7AB;color:#fff}
.bel-save:disabled{opacity:.6;cursor:default}
/* Feuille d'aperçu */
.bel-paper{background:#fff;border:1px solid #dfe4e8;border-radius:6px;box-shadow:0 2px 10px rgba(20,30,50,.06);padding:26px 28px;font-size:12px;line-height:1.55;color:#2c3338;font-family:Georgia,"Times New Roman",serif}
.bel-paper h1{font-size:15px;text-align:center;letter-spacing:.04em;margin:0 0 16px;text-transform:uppercase}
.bel-paper h2{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#5f8f93;border-bottom:1px solid #eef2f2;padding-bottom:3px;margin:16px 0 6px}
.bel-paper b{color:#1f2a2c}
.bel-paper .mut{color:#9aa6ac;font-style:italic;font-size:11px}
.bel-paper .bel-c{text-align:center;color:#7a868c;font-size:10.5px;font-style:italic;margin:0 0 12px}
.bel-paper p{margin:4px 0 8px;text-align:justify}
.bel-tbl{width:100%;border-collapse:collapse;font-size:11px;margin:8px 0 6px}
.bel-tbl th{background:#e3ecec;color:#3a5a5c;text-align:left;padding:6px 8px;border:1px solid #cfdcdc;font-size:10.5px;text-transform:uppercase;letter-spacing:.03em}
.bel-tbl td{padding:5px 8px;border:1px solid #dbe4e4;vertical-align:top}
.bel-tbl td:last-child{white-space:nowrap;width:1%}
.bel-who{display:inline-block;padding:1px 8px;border-radius:999px;font-size:10px;font-weight:800;font-family:Arial,sans-serif}
.bel-who.bel-p{background:#eaf3ee;color:#2f7d52}
.bel-who.bel-b{background:#fdeede;color:#b06a1c}
</style>
<script>
(function(){
  var API = <?= json_encode($api) ?>;
  var API_EXTRACT = <?= json_encode($apiExtract) ?>;
  var API_SAVE = <?= json_encode(function_exists('app_url') ? app_url('/api/bail_save.php') : '/api/bail_save.php') ?>;
  // Derniers indices INSEE connus à la date de rédaction — à mettre à jour trimestriellement.
  // T1 2026 (publiés fin juin 2026) — ILC 135,26 (commerce) · ILAT 137,42 (bureaux/tertiaire). IRL 146,60 = habitation (hors bail commercial).
  var BEL_INDICES = { ILC:{trim:'T1 2026', val:'135.26'}, ILAT:{trim:'T1 2026', val:'137.42'} };
  var M = document.getElementById('belModal');
  var g = function(id){ return document.getElementById(id); };
  function esc(s){ return String(s==null?'':s).replace(/[&<>]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;'}[c];}); }
  function v(id){ var e=g(id); return e ? e.value.trim() : ''; }
  function ph(x){ return x && String(x).trim() ? esc(x) : '<span class="mut">…</span>'; }
  function eur(n){ n=parseFloat(n); return isNaN(n)?null:n.toLocaleString('fr-FR',{minimumFractionDigits:0,maximumFractionDigits:2})+' €'; }

  function toggleType(){
    var soc = g('bel-cand-type').value === 'societe';
    document.querySelectorAll('.bel-soc-only').forEach(function(e){ e.hidden = !soc; });
    document.querySelectorAll('.bel-phys-only').forEach(function(e){ e.hidden = soc; });
    var lbl=g('bel-cand-adresse-lbl'); if(lbl) lbl.textContent = soc ? 'Siège social' : 'Adresse (domicile)';
  }
  function toggleGarant(){
    var on = g('bel-garant-present').checked;
    document.querySelectorAll('.bel-garant-only').forEach(function(e){ e.hidden = !on; });
    var gsoc = g('bel-garant-type').value === 'societe';
    document.querySelectorAll('.bel-gsoc-only').forEach(function(e){ e.hidden = !on || !gsoc; });
    document.querySelectorAll('.bel-gphys-only').forEach(function(e){ e.hidden = !on || gsoc; });
  }
  function toggleTech(){ document.querySelectorAll('.bel-tech-only').forEach(function(e){ e.hidden = !g('bel-tech').checked; }); }
  g('bel-cand-type').addEventListener('change', function(){ toggleType(); render(); });
  g('bel-garant-present').addEventListener('change', function(){ toggleGarant(); render(); });
  g('bel-garant-type').addEventListener('change', function(){ toggleGarant(); render(); });
  g('bel-tech').addEventListener('change', function(){ toggleTech(); render(); });
  g('bel-opt').addEventListener('change', function(){ document.querySelectorAll('.bel-opt-only').forEach(function(e){ e.hidden = !g('bel-opt').checked; }); render(); });
  g('bel-indice').addEventListener('change', function(){
    var d=BEL_INDICES[this.value];
    if(d && !v('bel-indice-trim')){ g('bel-indice-trim').value=d.trim; g('bel-indice-val').value=d.val; }
    render();
  });
  M.querySelector('.bel-form').addEventListener('input', render);
  M.querySelector('.bel-form').addEventListener('change', render);

  function render(){
    var mode = M._view || 'full';   // 'simple' (résumé) | 'full' (étoffé) — pilote le toggle
    var pf = M._pf || {}; var ge = pf.gestionnaire || {};
    var type = g('bel-cand-type').value;
    var naissance = (v('bel-cand-birthdate') || v('bel-cand-birthplace'))
      ? ', né(e) le '+(v('bel-cand-birthdate')?esc(v('bel-cand-birthdate')):'…')+(v('bel-cand-birthplace')?' à '+esc(v('bel-cand-birthplace')):'') : '';
    var preneur = type==='societe'
      ? '<b>'+ph(v('bel-cand-raison'))+'</b>'+(v('bel-cand-siren')?', SIREN '+esc(v('bel-cand-siren')):'')+(v('bel-cand-adresse')?', dont le siège est '+esc(v('bel-cand-adresse')):'')+(v('bel-cand-rep')?', représentée par '+esc(v('bel-cand-rep'))+(v('bel-cand-repq')?' ('+esc(v('bel-cand-repq'))+')':''):'')
      : '<b>'+ph((v('bel-cand-prenom')+' '+v('bel-cand-nom')).trim())+'</b>'+naissance+(v('bel-cand-nat')?', de nationalité '+esc(v('bel-cand-nat')):'')+(v('bel-cand-adresse')?', demeurant '+esc(v('bel-cand-adresse')):'');
    // Garant / caution
    var garOn = g('bel-garant-present').checked, garT = g('bel-garant-type').value;
    var garNaiss = (v('bel-garant-birthdate') || v('bel-garant-birthplace'))
      ? ', né(e) le '+(v('bel-garant-birthdate')?esc(v('bel-garant-birthdate')):'…')+(v('bel-garant-birthplace')?' à '+esc(v('bel-garant-birthplace')):'') : '';
    var garId = garT==='societe'
      ? '<b>'+ph(v('bel-garant-raison'))+'</b>'+(v('bel-garant-siren')?', SIREN '+esc(v('bel-garant-siren')):'')+(v('bel-garant-adresse')?', '+esc(v('bel-garant-adresse')):'')
      : '<b>'+ph((v('bel-garant-prenom')+' '+v('bel-garant-nom')).trim())+'</b>'+garNaiss+(v('bel-garant-adresse')?', demeurant '+esc(v('bel-garant-adresse')):'');
    var garMontant = v('bel-garant-montant'), garDuree = v('bel-garant-duree');
    var loyerA = v('bel-loyer'), loyerM = parseFloat(loyerA)? (parseFloat(loyerA)/12):null;
    var dg = v('bel-dg'), dgEur = (dg && loyerM)? eur(dg*loyerM):null;
    var dureeTxt = g('bel-duree').value==='36' ? '3 années (dérogatoire)' : '9 années entières et consécutives';
    var ferme = g('bel-ferme').checked;
    // ── Argent : TVA, périodicité, gestion technique, prorata, décompte d'entrée ──
    var tvaOn = g('bel-tva').checked, tauxTva = 20;
    var tvaTxt = tvaOn ? 'majoré de la TVA au taux de '+tauxTva+' %' : 'non assujetti à la TVA (article 261 D du CGI)';
    var loyerMn = parseFloat(loyerA) ? parseFloat(loyerA)/12 : 0;
    var chargesMn = parseFloat(v('bel-charges'))||0;
    var tfMn = parseFloat(v('bel-tf'))||0;
    var techOn = g('bel-tech').checked, techPct = parseFloat(v('bel-tech-pct'))||0;
    var techMn = techOn ? loyerMn*techPct/100 : 0;
    var perio = g('bel-perio').value, mult = perio==='trimestrielle' ? 3 : 1;
    var perioTxt = perio==='trimestrielle' ? 'par trimestre d\'avance' : 'par mois d\'avance';
    var honoBail = parseFloat(v('bel-hono-bail'))||0, honoLoc = parseFloat(v('bel-hono-loc'))||0;
    var cp = v('bel-cp'), cpLoyer = v('bel-cp-loyer');
    var dgN = parseFloat(v('bel-dg'))||0, dgMontant = dgN*loyerMn;
    function ttc(x){ return tvaOn ? x*(1+tauxTva/100) : x; }
    function prorata(){
      var ds=v('bel-date-effet'); if(!ds) return null; var d=new Date(ds+'T00:00:00'); if(isNaN(d.getTime())) return null;
      var y=d.getFullYear(), m=d.getMonth(), day=d.getDate();
      if(perio==='trimestrielle'){ var qs=Math.floor(m/3)*3, s=new Date(y,qs,1), e=new Date(y,qs+3,0);
        var td=Math.round((e-s)/86400000)+1, rm=Math.round((e-d)/86400000)+1; return {ratio:rm/td, label:'trimestre'}; }
      var dim=new Date(y,m+1,0).getDate(); return {ratio:(dim-day+1)/dim, label:'mois'};
    }
    var pr=prorata(), rr = pr?pr.ratio:1;
    var decompte = [];
    if(loyerMn) decompte.push(['1ᵉʳ loyer'+(pr?' (prorata '+pr.label+')':'')+(tvaOn?' TTC':' HT'), ttc(loyerMn*mult*rr)]);
    if(chargesMn) decompte.push(['Provision charges courantes'+(pr?' (prorata)':'')+(tvaOn?' TTC':''), ttc(chargesMn*mult*rr)]);
    if(tfMn) decompte.push(['Provision taxe foncière'+(pr?' (prorata)':'')+(tvaOn?' TTC':''), ttc(tfMn*mult*rr)]);
    if(techMn) decompte.push(['Honoraires gestion technique '+techPct+' %'+(pr?' (prorata)':'')+(tvaOn?' TTC':''), ttc(techMn*mult*rr)]);
    if(honoLoc) decompte.push(['Honoraires locataire TTC', honoLoc]);
    if(dgMontant) decompte.push(['Dépôt de garantie', dgMontant]);
    var totalVerser = decompte.reduce(function(s,l){return s+l[1];},0);
    function decompteHtml(){
      if(!decompte.length) return '';
      var geX = M._pf && M._pf.gestionnaire || {};
      var dr=''; for(var di=0; di<decompte.length; di++){ dr+='<tr><td>'+esc(decompte[di][0])+'</td><td style="text-align:right;white-space:nowrap">'+(eur(decompte[di][1])||'—')+'</td></tr>'; }
      return '<h2>Décompte à verser à la signature</h2>'
        +'<table class="bel-tbl"><tbody>'+dr
        +'<tr><td><b>Total à verser ('+(g('bel-tva').checked?'TVA incluse sur loyer/charges':'hors TVA')+')</b></td><td style="text-align:right"><b>'+(eur(totalVerser)||'—')+'</b></td></tr></tbody></table>'
        +(geX.rib_iban?'<p class="mut">Règlement par virement — '+esc(geX.rib_nom||'compte de l\'agence')+', IBAN '+esc(geX.rib_iban)+'.</p>':'<p class="mut">Règlement par virement sur le compte de l\'agence (RIB en base à compléter).</p>');
    }
    var loyerMots = loyerA ? (eur(loyerA)+' hors taxes et hors charges') : '…';
    var dgTxt = dg ? (esc(dg)+' mois de loyer'+(dgEur?' ('+dgEur+')':'')) : '…';
    var indice = esc(g('bel-indice').value);
    var indiceBase = (v('bel-indice-trim')?esc(v('bel-indice-trim')):'…')+(v('bel-indice-val')?' — valeur '+esc(v('bel-indice-val')):'');
    var opt = g('bel-opt').checked, erp = g('bel-erp').checked;
    var n = 0;                                   // compteur d'articles auto
    var A = function(t,b){ n++; return '<h2>Article '+n+' — '+t+'</h2><p>'+b+'</p>'; };

    var html = '<div class="bel-paper">';
    html += '<h1>Bail commercial</h1>';
    html += '<p class="bel-c">Statut des baux commerciaux — articles L.145-1 et suivants du Code de commerce</p>';

    // ── Comparution ──
    html += '<h2>Entre les soussignés</h2>';
    html += '<p><b>LE BAILLEUR</b> — '+ph(pf.proprio_nom)+', propriétaire des locaux ci-après désignés, '
          + 'représenté et géré par la société <b>'+ph(ge.raison)+'</b>'
          + (ge.forme?', '+esc(ge.forme)+(ge.capital?' au capital de '+eur(ge.capital):''):'')
          + (ge.siren?', immatriculée sous le n° '+esc(ge.siren):'')
          + (ge.adresse?', dont le siège est '+esc(ge.adresse):'')
          + (ge.carte?', titulaire de la carte professionnelle n° '+esc(ge.carte)+(ge.carte_cci?' délivrée par '+esc(ge.carte_cci):''):'')
          + (ge.rcp?', assurance RCP '+esc(ge.rcp):'')
          + (ge.age_nom?', agence '+esc(ge.age_nom)+(ge.age_adresse?' — '+esc(ge.age_adresse):''):'')
          + ',<br>ci-après « <b>le Bailleur</b> », d\'une part,</p>';
    html += '<p><b>LE PRENEUR</b> — '+preneur+(v('bel-cand-email')?', '+esc(v('bel-cand-email')):'')+(v('bel-cand-tel')?', tél. '+esc(v('bel-cand-tel')):'')
          + ',<br>ci-après « <b>le Preneur</b> », d\'autre part.</p>';
    html += '<p>Lesquels ont préalablement exposé et arrêté ce qui suit.</p>';

    // ── Mode RÉSUMÉ : version condensée (le PDF sort toujours le complet) ──
    if(mode==='simple'){
      html += '<h2>1. Désignation</h2><p>'+ph(pf.bien_ref)+(pf.bien_adresse?', '+esc(pf.bien_adresse):'')+(pf.immeuble_nom?' — immeuble '+esc(pf.immeuble_nom):'')+(pf.bien_surface>0?', '+esc(pf.bien_surface)+' m²':'')+'.</p>';
      html += '<h2>2. Destination</h2><p>Activité exclusive : '+ph(v('bel-destination'))+'.</p>';
      html += '<h2>3. Durée</h2><p>'+dureeTxt+', à compter du '+ph(v('bel-date-effet'))+'.'+(ferme?' Renonciation à la 1ʳᵉ triennale (6 ans fermes).':'')+'</p>';
      html += '<h2>4. Loyer</h2><p><b>'+loyerMots+'</b>'+(loyerM?' (soit '+eur(loyerM)+'/mois)':'')+(v('bel-charges')?' + charges '+eur(v('bel-charges'))+'/mois':'')+(techMn?' + gestion technique '+techPct+' %':'')+', '+perioTxt+', '+(tvaOn?'+ TVA 20 %':'non assujetti TVA')+'.</p>';
      html += '<h2>5. Indexation</h2><p>Indice '+indice+(v('bel-indice-trim')?', base '+esc(v('bel-indice-trim')):'')+(v('bel-indice-val')?' (valeur '+esc(v('bel-indice-val'))+')':'')+', révision annuelle.</p>';
      html += '<h2>6. Dépôt de garantie</h2><p>'+dgTxt+'.</p>';
      html += decompteHtml();
      var sx=[];
      if(erp) sx.push('Local ERP — accessibilité à charge du preneur.');
      if(opt) sx.push('Option d\'achat'+(v('bel-opt-prix')?' au prix de '+eur(v('bel-opt-prix')):'')+'.');
      sx.push('Clauses : résolutoire · pénale 20 % · assurances (renonciation réciproque) · EDL huissier · cession (garantie 3 ans) · droit de préférence L.145-46-1.');
      html += '<h2>7. Charges & clauses</h2><p>'+sx.join('<br>')+'</p>';
      html += '<h2>Annexe 1 — Répartition des charges</h2><p class="mut">Tableau complet Bailleur/Preneur (art. L.145-40-2) dans la version étoffée et le PDF.</p>';
      html += '<p class="mut">Résumé — le PDF génère le bail commercial complet détaillé.</p>';
      html += '</div>';
      g('bel-preview-body').innerHTML = html;
      return;
    }

    // ── Articles (version ÉTOFFÉE) ──
    html += A('Désignation des locaux',
        'Le Bailleur donne à bail au Preneur, qui accepte, les locaux à usage commercial ci-après désignés : <b>'
        +ph(pf.bien_ref)+'</b>'+(pf.bien_adresse?', sis '+esc(pf.bien_adresse):'')
        +(pf.immeuble_nom?', dépendant de l\'immeuble '+esc(pf.immeuble_nom):'')
        +(pf.bien_lot?', lot n° '+esc(pf.bien_lot):'')
        +(pf.bien_etage?', '+esc(pf.bien_etage):'')
        +(pf.bien_surface>0?', d\'une surface d\'environ '+esc(pf.bien_surface)+' m²':'')
        +(pf.bien_copro?', '+esc(pf.bien_copro):'')
        +'.'
        +(pf.bien_description?' <b>Description :</b> '+esc(pf.bien_description):'')
        +' Le Preneur déclare parfaitement connaître les lieux pour les avoir visités et les prend dans leur état actuel, sans pouvoir exiger du Bailleur aucune remise en état, réparation ni travaux.');

    html += A('Destination',
        'Les locaux sont exclusivement destinés à l\'activité suivante : <b>'+ph(v('bel-destination'))+'</b>. '
        +'Toute modification, extension ou adjonction d\'activité (déspécialisation) devra être autorisée dans les conditions des articles L.145-47 et suivants du Code de commerce. '
        +'Le Preneur fera son affaire personnelle de toutes autorisations administratives nécessaires à son activité.');

    html += A('Durée',
        'Le présent bail est consenti pour une durée de <b>'+dureeTxt+'</b>, prenant effet le <b>'+ph(v('bel-date-effet'))+'</b>. '
        +(g('bel-duree').value==='36'
            ? 'Bail dérogatoire au statut au sens de l\'article L.145-5 du Code de commerce.'
            : 'Le Preneur aura la faculté de donner congé à l\'expiration de chaque période triennale, par acte extrajudiciaire ou lettre recommandée avec accusé de réception, dans les conditions de l\'article L.145-4 du Code de commerce.')
        +(ferme?' <b>Par dérogation, le Preneur renonce expressément à sa faculté de résiliation à l\'issue de la première période triennale</b> : les six (6) premières années sont fermes.':''));

    html += A('Loyer',
        'Le présent bail est consenti moyennant un loyer annuel de <b>'+loyerMots+'</b>'
        +(loyerM?', soit '+eur(loyerM)+' par mois':'')+', payable <b>'+perioTxt+'</b>. '
        +'Le loyer est '+tvaTxt+'. '
        +(ge.rib_iban?'Le loyer et les accessoires sont réglés par virement sur le compte '+esc(ge.rib_nom||'du mandataire')+' — IBAN '+esc(ge.rib_iban)+'.':'')
        +' Tout retard de paiement portera intérêt au taux légal majoré, sans préjudice de la clause résolutoire.'
        +(cpLoyer?'<br><b>Conditions particulières sur le loyer :</b> '+esc(cpLoyer):''));

    html += A('Révision — indexation',
        'Le loyer sera indexé annuellement, à la date anniversaire de la prise d\'effet, en fonction de la variation de l\'indice <b>'+indice+'</b> publié par l\'INSEE. '
        +'Indice de base : <b>'+indiceBase+'</b>. '
        +'La révision jouera de plein droit, à la hausse comme à la baisse, sans qu\'il soit besoin d\'aucune formalité, dans le respect du plafonnement légal de l\'article L.145-38 du Code de commerce.');

    html += A('Charges, impôts, taxes et redevances',
        'La répartition des charges, impôts, taxes et redevances entre le Bailleur et le Preneur, ainsi que leur mode de calcul, figure à <b>l\'Annexe 1 — Inventaire et répartition des charges</b>, établie conformément à l\'article L.145-40-2 du Code de commerce. '
        +'Le Preneur remboursera au Bailleur les charges mises à sa charge'+(chargesMn?', par provisions mensuelles de <b>'+eur(chargesMn)+'</b> régularisées annuellement':' sur justificatifs, avec régularisation annuelle')+'. '
        +(tfMn?'Il remboursera en outre la taxe foncière, par provision de '+eur(tfMn)+' par mois. ':'')
        +(techMn?'Sont également récupérés sur le Preneur les <b>honoraires de gestion technique à hauteur de '+techPct+' % du loyer</b> ('+eur(techMn)+' par mois), au titre du suivi technique de l\'immeuble. ':'')
        +'Un état récapitulatif annuel des charges sera communiqué au Preneur.');

    html += A('Dépôt de garantie',
        'À la signature des présentes, le Preneur verse au Bailleur, à titre de dépôt de garantie, une somme de <b>'+dgTxt+'</b>, '
        +'destinée à garantir la bonne exécution du bail. Cette somme, non productive d\'intérêts, sera restituée en fin de bail, déduction faite des sommes dont le Preneur pourrait être redevable, après restitution des locaux et apurement des comptes.');

    if(honoBail || honoLoc){
      var hp = [];
      if(honoBail) hp.push('<b>'+eur(honoBail)+' TTC à la charge du Bailleur</b>');
      if(honoLoc)  hp.push('<b>'+eur(honoLoc)+' TTC à la charge du Preneur</b>');
      html += A('Honoraires',
        'Les honoraires de négociation et de rédaction du présent bail s\'élèvent à '+hp.join(' et ')+'. '
        +'En matière de bail commercial, ces honoraires sont librement convenus entre les parties.');
    }

    html += decompteHtml();

    html += A('Entretien, réparations et travaux',
        'Le Preneur entretiendra les locaux en bon état de réparations locatives et d\'entretien pendant toute la durée du bail et les rendra en fin de jouissance en bon état. '
        +'Il supportera les travaux d\'entretien courant et les réparations de toute nature autres que celles relevant de l\'article 606 du Code civil. '
        +'<b>Demeurent à la charge du Bailleur les grosses réparations de l\'article 606 du Code civil</b> ainsi que les travaux de mise en conformité et de vétusté relevant desdites grosses réparations. '
        +'Le Preneur ne pourra effectuer aucune démolition, percement de mur ou transformation sans l\'accord écrit et préalable du Bailleur ; les embellissements et améliorations resteront acquis au Bailleur en fin de bail sans indemnité.');

    html += A('Assurances',
        'Le Preneur assurera, auprès d\'une compagnie notoirement solvable, ses risques locatifs, son mobilier, son matériel, ses marchandises, le recours des voisins et des tiers, ainsi que sa responsabilité civile d\'exploitation, et en justifiera à première demande. '
        +'Le Bailleur assurera l\'immeuble. '
        +'<b>Le Bailleur et le Preneur renoncent réciproquement, ainsi que leurs assureurs respectifs, à tout recours l\'un contre l\'autre</b> pour les dommages garantis par leurs polices respectives. '
        +'Le Preneur s\'interdit toute activité de nature à aggraver les risques ou à entraîner la résiliation ou la majoration des primes du Bailleur.');

    html += A('Destruction des locaux',
        'En cas de destruction totale des locaux par cas fortuit ou force majeure, le bail sera résilié de plein droit, sans indemnité. '
        +'En cas de destruction partielle, le bail se poursuivra avec réduction proportionnelle du loyer, sans que le Bailleur soit tenu de reconstruire ; le Preneur ne pourra prétendre à aucune indemnité ni dommages-intérêts.');

    html += A('État des lieux',
        'Un état des lieux contradictoire sera établi lors de la prise de possession et lors de la restitution des locaux, et annexé au bail (article L.145-40-1 du Code de commerce). '
        +'<b>À défaut d\'état des lieux de sortie amiable, celui-ci sera dressé par huissier de justice à l\'initiative de la partie la plus diligente, aux frais partagés.</b> '
        +'Le Preneur restituera les locaux en parfait état ; toute remise en état nécessaire sera exécutée à ses frais, et une indemnité d\'occupation restera due jusqu\'à restitution complète des clés et des locaux libres.');

    html += A('Cession et sous-location',
        'Toute sous-location totale ou partielle est interdite sauf accord écrit du Bailleur. '
        +'La cession du droit au bail est autorisée au seul profit de l\'acquéreur du fonds de commerce du Preneur, le Bailleur devant être appelé à concourir à l\'acte. '
        +'<b>En cas de cession, le cédant demeure garant solidaire du cessionnaire pour le paiement des loyers et l\'exécution du bail pendant trois (3) ans</b> à compter de la cession (article L.145-16-2 du Code de commerce).');

    html += A('Droit de préférence du Preneur',
        'En cas de projet de vente des locaux loués, le Bailleur informera le Preneur dans les conditions et selon la procédure de l\'article L.145-46-1 du Code de commerce, sous réserve des exceptions prévues par ce texte.');

    html += A('Clause résolutoire',
        'À défaut de paiement d\'un seul terme de loyer, de charges ou d\'accessoires à son échéance, ou d\'inexécution d\'une seule des conditions du bail, et un (1) mois après un commandement de payer ou une mise en demeure demeurés infructueux, '
        +'<b>le bail sera résilié de plein droit</b> si bon semble au Bailleur, sans qu\'il soit besoin de remplir aucune formalité judiciaire autre que celles prévues à l\'article L.145-41 du Code de commerce.');

    html += A('Clause pénale',
        'En cas de recouvrement forcé des sommes dues ou de résiliation aux torts du Preneur, les sommes dues seront majorées d\'une indemnité forfaitaire de <b>20 %</b>, à titre de clause pénale, sans préjudice des intérêts de retard et des frais de procédure.');

    html += A('Restitution et non-responsabilité du Bailleur',
        'À l\'expiration du bail, pour quelque cause que ce soit, le Preneur devra rendre les locaux libres de toute occupation. '
        +'Le Bailleur ne pourra être tenu responsable des dommages causés au Preneur, à son personnel, à ses marchandises ou aux tiers par le fait des lieux, des installations, d\'un sinistre, d\'un défaut d\'entretien imputable au Preneur, ou du fait d\'autres occupants, sauf faute lourde du Bailleur.');

    html += A('Diagnostics et état des risques',
        'Les diagnostics techniques obligatoires et l\'état des risques (naturels, miniers, technologiques, radon, pollution des sols) sont annexés au présent bail conformément à la réglementation en vigueur. '
        +(erp?'<b>Les locaux constituent un établissement recevant du public (ERP)</b> : le Preneur fait son affaire personnelle des obligations d\'accessibilité, de sécurité incendie et de conformité relatives à son exploitation.':'Le Preneur fera son affaire des obligations liées à son exploitation.'));

    html += A('Prescription',
        'Conformément à l\'article L.145-60 du Code de commerce, toutes les actions exercées en vertu du statut des baux commerciaux se prescrivent par deux (2) ans.');

    if(opt){
        html += A('Option d\'achat',
            'Le Bailleur consent au Preneur une option d\'achat des locaux'
            +(v('bel-opt-prix')?' au prix de <b>'+eur(v('bel-opt-prix'))+'</b> hors taxes et hors frais':'')
            +(v('bel-opt-delai')?', levable dans un délai de '+esc(v('bel-opt-delai'))+' mois à compter de la prise d\'effet du bail':'')
            +'. La levée de l\'option s\'effectuera par lettre recommandée avec accusé de réception ; à défaut de levée dans le délai, l\'option deviendra caduque de plein droit.');
    }

    if(garOn){
        html += A('Cautionnement solidaire',
            'Aux présentes intervient, en qualité de garant : '+garId+'. '
            +'Lequel se rend caution '+(g('bel-garant-solidaire').checked?'<b>solidaire</b>':'simple')+' du Preneur envers le Bailleur, '
            +'pour le paiement des loyers, charges, accessoires et de toutes sommes dues au titre du bail, '
            +(garMontant?'dans la limite de <b>'+eur(garMontant)+'</b>':'')
            +(garDuree?' et pour une durée de '+esc(garDuree)+' an(s)':'')+'. '
            +'La caution reconnaît avoir une parfaite connaissance de la nature et de l\'étendue de son engagement.');
    }

    if(cp){
        html += A('Conditions particulières',
            'Les parties conviennent en outre des conditions particulières suivantes, qui prévalent sur les clauses générales en cas de contradiction : '+esc(cp).replace(/\n/g,'<br>')+'.');
    }

    html += A('Élection de domicile — attribution de juridiction',
        'Pour l\'exécution des présentes, les parties élisent domicile en leurs sièges et adresses respectifs. '
        +'Tout litige relatif au présent bail relèvera de la compétence exclusive du Tribunal judiciaire du lieu de situation des locaux.');

    // ── Annexe 1 : répartition des charges (L.145-40-2) ──
    var charges = [
        ['Loyer et TVA','','P'],
        ['Consommations propres au local (eau, électricité, gaz, chauffage)','','P'],
        ['Entretien courant et menues réparations des locaux loués','','P'],
        ['Charges locatives récupérables de copropriété (nettoyage, ascenseur, espaces verts, éclairage des parties communes)','','P'],
        ['Taxe d\'enlèvement des ordures ménagères (TEOM) et taxe de balayage','','P'],
        ['Redevance d\'assainissement','','P'],
        ['Assurance des risques locatifs et responsabilité du Preneur','','P'],
        ['Taxe foncière et taxes additionnelles','stipulée à la charge du Preneur au présent bail','P'],
        ['Grosses réparations de l\'article 606 du Code civil (gros murs, voûtes, poutres, toitures, murs de soutènement)','','B'],
        ['Travaux de mise en conformité et de vétusté relevant des grosses réparations','','B'],
        ['Travaux et charges relatifs à des locaux vacants ou imputables à d\'autres locataires','','B'],
        ['Honoraires de gestion des loyers de l\'immeuble','','B'],
        ['Impôts dont le Bailleur est personnellement redevable (CRL, CFE du Bailleur)','','B']
    ];
    if(techMn) charges.splice(8,0,['Honoraires de gestion technique ('+techPct+' % du loyer)','récupérables sur le Preneur','P']);
    var rows = '';
    for(var ci=0; ci<charges.length; ci++){
        var c = charges[ci];
        var who = c[2]==='P' ? '<span class="bel-who bel-p">Preneur</span>' : '<span class="bel-who bel-b">Bailleur</span>';
        rows += '<tr><td>'+esc(c[0])+(c[1]?' <i>('+esc(c[1])+')</i>':'')+'</td><td>'+who+'</td></tr>';
    }
    html += '<h2>Annexe 1 — Inventaire et répartition des charges</h2>';
    html += '<p class="bel-c">Établie en application de l\'article L.145-40-2 du Code de commerce (loi Pinel).</p>';
    html += '<table class="bel-tbl"><thead><tr><th>Nature de la charge, taxe ou redevance</th><th>À la charge de</th></tr></thead><tbody>'+rows+'</tbody></table>';
    html += '<p class="mut">Le Bailleur remettra au Preneur, en cours de bail, l\'état prévisionnel des travaux et le récapitulatif des travaux réalisés (article L.145-40-2), ainsi que la régularisation annuelle des charges.</p>';

    html += '<p class="mut">Aperçu généré en direct à partir des données société/agence (base) et des conditions saisies. Le document définitif sera édité en PDF pour signature.</p>';
    html += '</div>';
    g('bel-preview-body').innerHTML = html;
  }

  // ── Upload docs candidat → extraction IA → pré-remplissage (non bloquant, n'écrase pas la saisie) ──
  function setIf(id,val){ var e=g(id); if(e && val!=null && String(val).trim()!=='' && !e.value){ e.value=String(val); } }
  function applyCandFields(f){
    f=f||{};
    if(f.type){ g('bel-cand-type').value = (f.type==='physique'?'physique':'societe'); toggleType(); }
    setIf('bel-cand-raison', f.raison_sociale); setIf('bel-cand-siren', f.siren);
    setIf('bel-cand-nom', f.nom); setIf('bel-cand-prenom', f.prenom);
    setIf('bel-cand-email', f.email); setIf('bel-cand-tel', f.telephone);
    setIf('bel-cand-rep', f.representant_nom); setIf('bel-cand-repq', f.representant_qualite);
    setIf('bel-cand-adresse', f.adresse); setIf('bel-cand-birthdate', f.date_naissance);
    setIf('bel-cand-birthplace', f.lieu_naissance); setIf('bel-cand-nat', f.nationalite);
    setIf('bel-destination', f.activite); setIf('bel-loyer', f.loyer_annuel_ht);
    render();
  }
  // Chargement des pièces du candidat = on OUVRE le module FluxBox/MBO (nommage + GED + liens),
  // avec le contexte du bail. Plus de mini-upload maison ; une seule mécanique documentaire.
  window.bailOpenCandDocs = function(){
    if (typeof window.fbxOpenUploadModal !== 'function'){
      var m=g('bel-up-msg'); if(m){ m.style.color='#c62828'; m.textContent='Module FluxBox indisponible sur cette page.'; }
      return;
    }
    var pf = M._pf || {};
    var type = g('bel-cand-type').value;
    var preneur = type==='societe' ? v('bel-cand-raison') : (v('bel-cand-prenom')+' '+v('bel-cand-nom')).trim();
    window.fbxOpenUploadModal({
      origin: 'bail_360',
      bail_id: M._editId || 0,
      bail_locataire: preneur || (pf.bail_locataire||''),
      bien_id: parseInt(pf.bien_id,10)||0,
      immeuble_nom: pf.immeuble_nom||'',
      entite_id_bdd: parseInt(pf.bien_id,10)||0,
      entite_nom: pf.bien_ref||'',
      entite_adresse: pf.bien_adresse||'',
      proprio_nom: pf.proprio_nom||'',
      card_label: 'PIÈCE DU CANDIDAT (BAIL)',
      n1: '03_GESTION_LOCATIVE',
      dossier_provisoire: 1   // marqueur RGPD : dossier candidat provisoire (voir statut dédié)
    });
  };

  function resetForm(){
    ['bel-cand-raison','bel-cand-siren','bel-cand-nom','bel-cand-prenom','bel-cand-email','bel-cand-tel',
     'bel-cand-rep','bel-cand-repq','bel-cand-adresse','bel-cand-birthdate','bel-cand-birthplace','bel-cand-nat',
     'bel-destination','bel-date-effet','bel-loyer','bel-charges',
     'bel-indice-trim','bel-indice-val','bel-dg','bel-opt-prix','bel-opt-delai',
     'bel-garant-nom','bel-garant-prenom','bel-garant-raison','bel-garant-siren','bel-garant-adresse',
     'bel-garant-birthdate','bel-garant-birthplace','bel-garant-email','bel-garant-tel',
     'bel-garant-montant','bel-garant-duree','bel-tf','bel-hono-bail','bel-hono-loc','bel-cp','bel-cp-loyer'].forEach(function(id){ var e=g(id); if(e) e.value=''; });
    g('bel-cand-type').value='societe'; g('bel-duree').value='108'; g('bel-indice').value='ILC';
    g('bel-garant-type').value='physique'; g('bel-perio').value='mensuelle';
    g('bel-tech-pct').value='1.5';
    var d0=BEL_INDICES['ILC']; if(d0){ g('bel-indice-trim').value=d0.trim; g('bel-indice-val').value=d0.val; } // dernier ILC connu
    ['bel-ferme','bel-erp','bel-opt','bel-garant-present'].forEach(function(id){ g(id).checked=false; });
    g('bel-garant-solidaire').checked=true; g('bel-tva').checked=true; g('bel-tech').checked=true;
    document.querySelectorAll('.bel-opt-only').forEach(function(e){ e.hidden=true; });
    toggleGarant(); toggleTech();
  }
  function fillForm(val){
    val=val||{};
    var set=function(id,v){ var e=g(id); if(e && v!=null && v!=='') e.value=String(v); };
    g('bel-cand-type').value = (val.locataire_type==='physique'?'physique':'societe');
    set('bel-cand-raison', val.locataire_raison_sociale); set('bel-cand-siren', val.locataire_siren);
    set('bel-cand-nom', val.locataire_nom); set('bel-cand-prenom', val.locataire_prenom);
    set('bel-cand-email', val.locataire_email); set('bel-cand-tel', val.locataire_telephone);
    set('bel-cand-rep', val.locataire_representant_nom); set('bel-cand-repq', val.locataire_representant_qualite);
    set('bel-cand-adresse', val.locataire_adresse); set('bel-cand-birthdate', val.locataire_date_naissance);
    set('bel-cand-birthplace', val.locataire_lieu_naissance); set('bel-cand-nat', val.locataire_nationalite);
    if(parseInt(val.garant_present||0,10)){
      g('bel-garant-present').checked=true;
      g('bel-garant-type').value = (val.garant_type==='societe'?'societe':'physique');
      set('bel-garant-nom', val.garant_nom); set('bel-garant-prenom', val.garant_prenom);
      set('bel-garant-raison', val.garant_raison_sociale); set('bel-garant-siren', val.garant_siren);
      set('bel-garant-adresse', val.garant_adresse); set('bel-garant-birthdate', val.garant_date_naissance);
      set('bel-garant-birthplace', val.garant_lieu_naissance); set('bel-garant-email', val.garant_email);
      set('bel-garant-tel', val.garant_telephone); set('bel-garant-montant', val.garant_montant_max);
      set('bel-garant-duree', val.garant_duree_ans);
      g('bel-garant-solidaire').checked = (parseInt(val.garant_solidaire==null?1:val.garant_solidaire,10)!==0);
    }
    toggleGarant();
    set('bel-destination', val.destination_activite); set('bel-date-effet', val.date_prise_effet);
    if(val.duree_mois){ g('bel-duree').value = (parseInt(val.duree_mois,10)===36?'36':'108'); }
    g('bel-ferme').checked = !!val.duree_ferme_ans;
    if(val.loyer_annuel_ht) set('bel-loyer', val.loyer_annuel_ht);
    else if(val.loyer_mensuel_hc) set('bel-loyer', Math.round(parseFloat(val.loyer_mensuel_hc)*12));
    set('bel-charges', val.charges_mensuelles);
    if(val.indice_type) g('bel-indice').value = val.indice_type;
    set('bel-indice-trim', val.indice_trimestre); set('bel-indice-val', val.indice_valeur);
    set('bel-dg', val.nb_termes_garantie);
    g('bel-erp').checked = !!parseInt(val.erp_local||0,10);
    g('bel-opt').checked = !!parseInt(val.option_achat||0,10);
    document.querySelectorAll('.bel-opt-only').forEach(function(e){ e.hidden = !g('bel-opt').checked; });
    set('bel-opt-prix', val.option_achat_prix); set('bel-opt-delai', val.option_achat_delai_mois);
    // Argent
    g('bel-tva').checked = (val.tva_applicable==null ? true : !!parseInt(val.tva_applicable,10));
    if(val.periodicite_paiement) g('bel-perio').value = (val.periodicite_paiement==='trimestrielle'?'trimestrielle':'mensuelle');
    set('bel-tf', val.provision_tf_mensuelle);
    if(val.honoraires_gestion_tech_pct!=null && val.honoraires_gestion_tech_pct!==''){ g('bel-tech').checked=true; set('bel-tech-pct', val.honoraires_gestion_tech_pct); }
    else { g('bel-tech').checked=false; }
    toggleTech();
    set('bel-hono-bail', val.honoraires_bailleur_ttc);
    set('bel-hono-loc', val.honoraires_locataire_ttc);
    set('bel-cp', val.conditions_particulieres);
    set('bel-cp-loyer', val.conditions_particulieres_loyer);
  }

  window.bailOpenCreateModal = function(pf){
    resetForm();
    M._pf = pf || {}; M._editId = 0; M._bienId = parseInt((pf||{}).bien_id,10) || 0; M._origin = (pf||{}).origin || 'bien_360';
    g('bel-title-text') && (g('bel-title-text').textContent='Créer un projet de bail commercial');
    g('bel-save').textContent='💾 Créer le projet';
    g('bel-msg').textContent=''; toggleType(); render();
    M.hidden = false;
  };
  window.bailOpenEditModal = function(pf){
    resetForm();
    M._pf = pf || {}; M._editId = parseInt((pf||{}).bail_id,10) || 0; M._bienId = parseInt((pf||{}).bien_id,10) || 0; M._origin = (pf||{}).origin || 'bail_360';
    fillForm((pf||{}).values || {});
    g('bel-title-text') && (g('bel-title-text').textContent='Modifier le projet de bail');
    g('bel-save').textContent='💾 Enregistrer les modifications';
    g('bel-msg').textContent=''; toggleType(); render();
    M.hidden = false;
  };
  window.bailSetView = function(mode){
    M._view = (mode==='simple' ? 'simple' : 'full');
    var s=g('bel-view-simple'), f=g('bel-view-full');
    if(s) s.classList.toggle('is-on', M._view==='simple');
    if(f) f.classList.toggle('is-on', M._view==='full');
    render();
  };
  window.bailCloseModal = function(){ M.hidden = true; };
  // Fermeture : clic sur le fond + touche Échap
  M.addEventListener('mousedown', function(e){ if(e.target===M) bailCloseModal(); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape' && !M.hidden) bailCloseModal(); });

  window.bailSubmitProjet = function(){
    var msg = g('bel-msg'), btn = g('bel-save');
    var edit = M._editId > 0;
    if (!edit && !M._bienId){ msg.style.color='#c62828'; msg.textContent='Bien manquant.'; return; }
    var type = g('bel-cand-type').value;
    var raison = v('bel-cand-raison'), nom = v('bel-cand-nom');
    if ((type==='societe' && !raison) || (type==='physique' && !nom)){
      msg.style.color='#c62828'; msg.textContent='Renseigne le candidat (raison sociale ou nom).'; return;
    }
    var payload = {
      bail_id: M._editId || undefined,
      bien_id: M._bienId, origin: M._origin,
      candidat: { type:type, raison_sociale:raison, siren:v('bel-cand-siren'),
        nom:nom, prenom:v('bel-cand-prenom'), email:v('bel-cand-email'),
        telephone:v('bel-cand-tel'), representant_nom:v('bel-cand-rep'),
        representant_qualite:v('bel-cand-repq'),
        adresse:v('bel-cand-adresse'), date_naissance:g('bel-cand-birthdate').value,
        lieu_naissance:v('bel-cand-birthplace'), nationalite:v('bel-cand-nat') },
      garant: g('bel-garant-present').checked ? {
        present:1, type:g('bel-garant-type').value,
        nom:v('bel-garant-nom'), prenom:v('bel-garant-prenom'),
        raison_sociale:v('bel-garant-raison'), siren:v('bel-garant-siren'),
        adresse:v('bel-garant-adresse'), date_naissance:g('bel-garant-birthdate').value,
        lieu_naissance:v('bel-garant-birthplace'), email:v('bel-garant-email'),
        telephone:v('bel-garant-tel'),
        montant_max:v('bel-garant-montant')!=='' ? parseFloat(v('bel-garant-montant')) : null,
        duree_ans:v('bel-garant-duree')!=='' ? parseInt(v('bel-garant-duree'),10) : null,
        solidaire:g('bel-garant-solidaire').checked?1:0
      } : { present:0 },
      destination: v('bel-destination'),
      date_prise_effet: g('bel-date-effet').value,
      duree_mois: parseInt(g('bel-duree').value,10),
      duree_ferme_ans: g('bel-ferme').checked ? 6 : null,
      loyer_annuel_ht: parseFloat(g('bel-loyer').value)||0,
      charges_mensuelles: v('bel-charges')!=='' ? parseFloat(v('bel-charges')) : null,
      indice_type: g('bel-indice').value, indice_trimestre: v('bel-indice-trim'),
      indice_valeur: v('bel-indice-val')!=='' ? parseFloat(v('bel-indice-val')) : null,
      nb_termes_garantie: v('bel-dg')!=='' ? parseInt(v('bel-dg'),10) : null,
      erp_local: g('bel-erp').checked?1:0,
      option_achat: g('bel-opt').checked?1:0,
      option_achat_prix: v('bel-opt-prix')!=='' ? parseFloat(v('bel-opt-prix')) : null,
      option_achat_delai_mois: v('bel-opt-delai')!=='' ? parseInt(v('bel-opt-delai'),10) : null,
      tva_applicable: g('bel-tva').checked?1:0, tva_taux: g('bel-tva').checked?20:0,
      periodicite: g('bel-perio').value,
      provision_tf: v('bel-tf')!=='' ? parseFloat(v('bel-tf')) : null,
      honoraires_tech_pct: g('bel-tech').checked ? (parseFloat(v('bel-tech-pct'))||0) : null,
      honoraires_bailleur: v('bel-hono-bail')!=='' ? parseFloat(v('bel-hono-bail')) : null,
      honoraires_locataire: v('bel-hono-loc')!=='' ? parseFloat(v('bel-hono-loc')) : null,
      conditions_particulieres: v('bel-cp'),
      conditions_particulieres_loyer: v('bel-cp-loyer')
    };
    btn.disabled=true; msg.style.color='#5f8f93'; msg.textContent = edit ? '⏳ Enregistrement…' : '⏳ Création…';
    fetch(edit ? API_SAVE : API,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)})
      .then(function(r){return r.json();}).then(function(j){
        btn.disabled=false;
        if(j&&j.ok){ msg.style.color='#2d8a4e'; msg.textContent='✅ '+j.message; setTimeout(function(){ window.location.href=j.redirect; },900); }
        else { msg.style.color='#c62828'; msg.textContent='❌ '+((j&&j.error)||'Échec'); }
      }).catch(function(e){ btn.disabled=false; msg.style.color='#c62828'; msg.textContent='❌ Réseau : '+e; });
  };
})();
</script>
    <?php
}
}
