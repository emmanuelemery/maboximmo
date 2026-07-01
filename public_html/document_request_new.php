<?php
// document_request_new.php — Créer une « Demande de document » (lien de dépôt sécurisé).
// Choix d'un modèle (candidat locataire, projet salaires…) ou pièces manuelles + rappels.
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/document_requests.php';
require_login();

$pdo = $GLOBALS['pdo'];
$templates = dr_templates($pdo);

// Filtre modèle : ?tpl=<code> n'affiche QUE ce modèle (ex. depuis un dossier de vente,
// on ne montre que « Mise en vente — dossier propriétaire »). Présélectionné côté JS.
$onlyTpl = trim((string)($_GET['tpl'] ?? ''));
if ($onlyTpl !== '') {
    $filtered = array_values(array_filter($templates, fn($t) => (string)$t['code'] === $onlyTpl));
    if ($filtered) $templates = $filtered;
}
$agences = $pdo->query("SELECT id, nom_agence FROM agences ORDER BY nom_agence")->fetchAll(PDO::FETCH_ASSOC);
$csrf = function_exists('csrf_token') ? csrf_token() : '';

// Comptable de la société courante (societes.comptable_email/nom) : pré-remplit
// le destinataire pour les demandes comptables (projet/bulletins de salaires,
// bilan…). Modifiable côté UI. Silencieux si colonnes/valeur absentes.
$comptableEmail = ''; $comptableNom = '';
try {
    $socId = (int)($_SESSION['id_societe'] ?? 0);
    if ($socId > 0) {
        $stC = $pdo->prepare("SELECT comptable_email, comptable_nom FROM societes WHERE id = ? LIMIT 1");
        $stC->execute([$socId]);
        if ($c = $stC->fetch(PDO::FETCH_ASSOC)) {
            $comptableEmail = trim((string)($c['comptable_email'] ?? ''));
            $comptableNom   = trim((string)($c['comptable_nom'] ?? ''));
        }
    }
} catch (Throwable $e) {}

// ── Contexte d'origine (rattachement automatique à un dossier) ───────────
// Appelé depuis un bouton de fiche : ?ctx=BIEN&id=123&back=<url>. Les pièces
// déposées seront classées en GED sur cette entité, sans intervention.
$ctxType = strtoupper(trim((string)($_GET['ctx'] ?? '')));
$ctxId   = (int)($_GET['id'] ?? 0);
$backUrl = (string)($_GET['back'] ?? '');
$ctxTitle = ''; $ctxEmail = '';
if ($ctxType !== '' && $ctxId > 0) {
    if (is_file(__DIR__ . '/inc/mail_context.php')) {
        require_once __DIR__ . '/inc/mail_context.php';
        try {
            $C = mail_context($pdo, $ctxType, $ctxId);
            if (!empty($C['ok'])) {
                $ctxTitle = (string)$C['title'];
                foreach (($C['contacts'] ?? []) as $ct) { if (!empty($ct['email'])) { $ctxEmail = $ct['email']; break; } }
            }
        } catch (Throwable $e) {}
    }
    // Fallback titre LISIBLE (évite « BIEN #843 ») via le résolveur d'entité.
    if (($ctxTitle === '' || preg_match('/#\s*\d+/', $ctxTitle)) && function_exists('dr_entity_label')) {
        $el = dr_entity_label($pdo, $ctxType, $ctxId);
        if ($el['label'] !== '') $ctxTitle = $el['label'];
    }
}

$pageTitle = 'Demander un document';
include __DIR__ . '/inc/agency_layout_top.php';
?>
<style>
.agency-content{min-height:100vh;background:linear-gradient(135deg,rgba(154,170,132,.16) 0%,rgba(255,255,255,0) 40%,rgba(72,120,166,.12) 70%,#fafbfc 100%) fixed,#fafbfc !important;}
.dr-wrap{max-width:860px;margin:0 auto;padding:16px 18px 60px}
.dr-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:18px 20px;margin-bottom:16px;box-shadow:0 1px 3px rgba(15,23,42,.05)}
.dr-card h3{margin:0 0 12px;font-size:14px;font-weight:900;color:#0f172a}
.dr-field{margin-bottom:12px}
.dr-field label{display:block;font-size:12px;font-weight:700;color:#64748b;margin-bottom:4px}
.dr-input,.dr-select,.dr-textarea{width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:9px;font-size:14px;font-family:inherit}
.dr-textarea{min-height:70px;resize:vertical}
.dr-row{display:flex;gap:12px;flex-wrap:wrap}.dr-row>div{flex:1;min-width:180px}
.item{display:flex;gap:8px;align-items:center;padding:8px;border:1px solid #eef1f5;border-radius:9px;margin-bottom:7px;background:#fafbfc}
.item input[type=text]{flex:1;border:1px solid #cbd5e1;border-radius:7px;padding:7px 9px;font-size:13px}
.item select{border:1px solid #cbd5e1;border-radius:7px;padding:7px;font-size:12px}
.item .rm{background:#fee;border:1px solid #f3bcbc;color:#a01818;border-radius:7px;width:30px;height:30px;cursor:pointer;font-weight:700}
.dr-btn{background:#0e7490;color:#fff;border:none;border-radius:10px;padding:12px 22px;font-size:14px;font-weight:800;cursor:pointer}
.dr-btn.ghost{background:#fff;color:#0e7490;border:1px solid #0e7490}
.dr-mini{font-size:12px;color:#64748b}
.result{background:#e7f6ec;border:1px solid #b6e3c6;border-radius:12px;padding:16px;margin-bottom:16px}
.result a{color:#0e7490;font-weight:700;word-break:break-all}
.hidden{display:none}
</style>

<div class="dr-wrap">
  <h2 style="margin:0 0 4px">📥 Demander un document</h2>
  <p class="dr-mini" style="margin:0 0 16px">Envoie un lien sécurisé : le destinataire dépose les pièces, elles arrivent classées dans la GED, tu es notifié.</p>

  <?php if ($ctxType !== '' && $ctxId > 0): ?>
  <div style="background:#eef6fb;border:1px solid #cfe4f3;border-radius:12px;padding:12px 16px;margin-bottom:16px;font-size:13px;color:#0c4a6e">
    🔗 Demande <b>rattachée</b> à : <b><?= h($ctxTitle ?: ($ctxType . ' #' . $ctxId)) ?></b>
    <span class="dr-mini">(<?= h($ctxType) ?> · les pièces déposées seront classées automatiquement sur ce dossier)</span>
    <?php if ($backUrl !== ''): ?> · <a href="<?= h($backUrl) ?>" style="color:#0e7490">← retour au dossier</a><?php endif; ?>
  </div>
  <?php endif; ?>

  <div id="dr-result" class="result hidden"></div>

  <div class="dr-card">
    <h3>1. Modèle (optionnel)</h3>
    <select id="tplSelect" class="dr-select" onchange="applyTemplate()">
      <?php if ($onlyTpl === ''): ?><option value="">— Demande personnalisée —</option><?php endif; ?>
      <?php foreach ($templates as $t): ?>
        <option value="<?= h($t['code']) ?>" data-items='<?= h($t['items_json']) ?>' data-nom="<?= h($t['nom']) ?>" data-desc="<?= h($t['description'] ?? '') ?>" data-audience="<?= h($t['audience'] ?? '') ?>"><?= h($t['nom']) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="dr-mini" id="tplDesc" style="margin-top:8px"></p>
  </div>

  <div class="dr-card">
    <h3>2. Destinataire</h3>
    <div class="dr-row">
      <div class="dr-field"><label>Email *</label><input type="email" id="recEmail" class="dr-input" placeholder="comptable@cabinet.fr"></div>
      <div class="dr-field"><label>Nom (optionnel)</label><input type="text" id="recName" class="dr-input" placeholder="Cabinet Durand"></div>
    </div>
    <div class="dr-field"><label>Titre de la demande *</label><input type="text" id="titre" class="dr-input" placeholder="Dossier candidature — Appartement Lyon 7"></div>
    <div class="dr-field"><label>Message (optionnel)</label><textarea id="message" class="dr-textarea" placeholder="Un mot d'accompagnement…"></textarea></div>
  </div>

  <div class="dr-card">
    <h3>3. Pièces demandées</h3>
    <div id="itemsList"></div>
    <button type="button" class="dr-btn ghost" style="padding:8px 14px;font-size:13px" onclick="addItem()">+ Ajouter une pièce</button>
    <div style="margin-top:14px;padding-top:12px;border-top:1px dashed #e2e8f0">
      <label class="dr-mini" style="font-weight:700">Génération auto : une pièce par agence (ex. projet de salaires)</label>
      <div class="dr-row" style="margin-top:6px;align-items:flex-end">
        <div class="dr-field" style="margin:0"><label>Libellé</label><input type="text" id="genLabel" class="dr-input" placeholder="Projet de salaires"></div>
        <div class="dr-field" style="margin:0"><label>Période (AAAA-MM)</label><input type="text" id="genPeriod" class="dr-input" placeholder="2026-06"></div>
        <div class="dr-field" style="margin:0;max-width:180px"><label>Étape workflow</label>
          <select id="genDocType" class="dr-select">
            <option value="">— (aucune)</option>
            <option value="PROJET_SALAIRES">Projet de salaires</option>
            <option value="BULLETIN_SALAIRE">Bulletins finaux</option>
          </select>
        </div>
        <button type="button" class="dr-btn ghost" style="padding:9px 14px;font-size:13px" onclick="genAgences()">Générer par agence</button>
      </div>
      <p class="dr-mini" id="genSplitNote" style="margin-top:6px;color:#0e7490;display:none">🔀 Demande comptable : un lien distinct sera envoyé à <b>chaque comptable</b> (email de sa société), avec seulement ses agences — l'email saisi plus haut est ignoré.</p>
    </div>
  </div>

  <div class="dr-card">
    <h3>4. Échéance, sécurité & rappels</h3>
    <div class="dr-row">
      <div class="dr-field"><label>Valable (jours)</label><input type="number" id="expDays" class="dr-input" value="30" min="1"></div>
      <div class="dr-field"><label>Sécurité</label>
        <select id="gate" class="dr-select"><option value="1">Verrouillé à l'email du destinataire</option><option value="0">Lien ouvert</option></select>
      </div>
    </div>
    <div class="dr-field"><label>Rappels</label>
      <select id="remMode" class="dr-select" onchange="remToggle()">
        <option value="none">Aucun rappel</option>
        <option value="once">Une seule fois</option>
        <option value="recurring">Récurrent</option>
      </select>
    </div>
    <div class="dr-row" id="remCfg" style="display:none">
      <div class="dr-field"><label>1er rappel dans (jours)</label><input type="number" id="remFirst" class="dr-input" value="7" min="1"></div>
      <div class="dr-field hidden" id="remIntWrap"><label>Puis tous les (jours)</label><input type="number" id="remInt" class="dr-input" value="7" min="1"></div>
    </div>
  </div>

  <button type="button" class="dr-btn" onclick="submitRequest()">📤 Créer et envoyer le lien</button>
  <span id="dr-msg" style="margin-left:14px;font-size:13px"></span>
</div>

<script>
const CSRF = <?= json_encode($csrf) ?>;
const API  = <?= json_encode(app_url('/api/document_request_create.php')) ?>;
const CTX  = { type: <?= json_encode($ctxType ?: null) ?>, id: <?= (int)$ctxId ?>, title: <?= json_encode($ctxTitle) ?>, email: <?= json_encode($ctxEmail) ?> };
const COMPTABLE = { email: <?= json_encode($comptableEmail) ?>, nom: <?= json_encode($comptableNom) ?> };

function itemRow(it){
  it = it || {};
  const div = document.createElement('div');
  div.className = 'item';
  div.innerHTML =
    '<input type="text" class="i-label" placeholder="Libellé de la pièce" value="'+(it.label||'').replace(/"/g,'&quot;')+'">'
    +'<select class="i-kind"><option value="file">Fichier</option><option value="files">Multi-fichiers</option><option value="text">Texte</option><option value="photos">Photos</option></select>'
    +'<label class="dr-mini" style="display:flex;gap:4px;align-items:center"><input type="checkbox" class="i-req" '+(it.required==0?'':'checked')+'>Requis</label>'
    +'<input type="hidden" class="i-type" value="'+(it.doc_type||'')+'">'
    +'<input type="hidden" class="i-entity" value="'+(it.entity_type||'')+'">'
    +(it.entity_type?'<span class="dr-mini" style="white-space:nowrap;color:#0e7490;font-weight:700" title="Entité de classement GED">'+({IMMEUBLE:'🏛️ immeuble',TIERS:'👤 propriétaire',BIEN:'🏠 bien'}[it.entity_type]||it.entity_type)+'</span>':'')
    +'<button type="button" class="rm" onclick="this.closest(\'.item\').remove()">×</button>';
  if(it.kind) div.querySelector('.i-kind').value = it.kind;
  return div;
}
function addItem(it){ document.getElementById('itemsList').appendChild(itemRow(it)); }
function applyTemplate(){
  const sel = document.getElementById('tplSelect');
  const opt = sel.options[sel.selectedIndex];
  document.getElementById('itemsList').innerHTML = '';
  if(!sel.value){ document.getElementById('tplDesc').textContent=''; return; }
  if(!document.getElementById('titre').value) document.getElementById('titre').value = opt.dataset.nom || '';
  // Demande de nature comptable → pré-remplit le comptable de la société (si vide).
  window._genAudience = (opt.dataset.audience||'');
  if(window._genAudience==='comptable' && COMPTABLE.email){
    const em = document.getElementById('recEmail');
    if(!em.value){ em.value = COMPTABLE.email; if(COMPTABLE.nom && !document.getElementById('recName').value) document.getElementById('recName').value = COMPTABLE.nom; }
  }
  document.getElementById('genSplitNote').style.display = (window._genAudience==='comptable') ? 'block' : 'none';
  let items=[]; try{ items = JSON.parse(opt.dataset.items||'[]'); }catch(e){}
  items.forEach(it => {
    if(it.generator==='agences'){
      document.getElementById('genLabel').value = it.label;
      const dt = (it.doc_type||'').toUpperCase(); const gs = document.getElementById('genDocType');
      if(dt && Array.from(gs.options).some(o=>o.value===dt)) gs.value = dt;
    } else addItem(it);
  });
  document.getElementById('tplDesc').textContent = items.length ? (items.length+' pièce(s) pré-remplie(s) — modifiable') : '';
}
function genAgences(){
  // marque une génération côté serveur : on stocke un flag via un item caché spécial
  window._genAgences = {
    label: document.getElementById('genLabel').value.trim(),
    period: document.getElementById('genPeriod').value.trim(),
    doc_type: document.getElementById('genDocType').value,
  };
  const split = (window._genAudience==='comptable');
  document.getElementById('dr-msg').style.color = '#0e7490';
  document.getElementById('dr-msg').textContent = window._genAgences.label
    ? ('✓ une pièce sera générée par agence : '+window._genAgences.label + (split ? ' — scindé par comptable' : ''))
    : '';
}
function remToggle(){
  const m = document.getElementById('remMode').value;
  document.getElementById('remCfg').style.display = (m==='none')?'none':'flex';
  document.getElementById('remIntWrap').classList.toggle('hidden', m!=='recurring');
}
function collectItems(){
  return Array.from(document.querySelectorAll('#itemsList .item')).map(r => ({
    label: r.querySelector('.i-label').value.trim(),
    kind:  r.querySelector('.i-kind').value,
    doc_type: r.querySelector('.i-type').value,
    entity_type: (r.querySelector('.i-entity') ? r.querySelector('.i-entity').value : ''),
    required: r.querySelector('.i-req').checked ? 1 : 0,
  })).filter(i => i.label);
}
async function submitRequest(){
  const msg = document.getElementById('dr-msg');
  const payload = {
    CSRF: CSRF,
    titre: document.getElementById('titre').value.trim(),
    recipient_email: document.getElementById('recEmail').value.trim(),
    recipient_name: document.getElementById('recName').value.trim(),
    message: document.getElementById('message').value.trim(),
    template_code: document.getElementById('tplSelect').value || null,
    entity_type: CTX.type || null,
    entity_id: CTX.id || null,
    require_email_gate: document.getElementById('gate').value,
    expires_days: document.getElementById('expDays').value,
    reminder_mode: document.getElementById('remMode').value,
    reminder_first_days: document.getElementById('remFirst').value,
    reminder_interval_days: document.getElementById('remInt').value,
    items: collectItems(),
  };
  const splitMode = (window._genAudience==='comptable' && window._genAgences && window._genAgences.label);
  if(window._genAgences && window._genAgences.label){
    payload.generator = {
      type:'agences', label: window._genAgences.label, period: window._genAgences.period,
      doc_type: window._genAgences.doc_type || '', by_comptable: splitMode ? 1 : 0,
    };
  }
  if(!payload.titre){ msg.style.color='#ef4444'; msg.textContent='Titre requis.'; return; }
  if(!splitMode && !payload.recipient_email){ msg.style.color='#ef4444'; msg.textContent='Email requis.'; return; }
  if(!payload.items.length && !payload.generator){ msg.style.color='#ef4444'; msg.textContent='Ajoute au moins une pièce.'; return; }
  msg.style.color='#64748b'; msg.textContent='Création…';
  try{
    const r = await fetch(API, {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF}, body: JSON.stringify(payload)});
    const j = await r.json();
    if(j.ok && j.split){
      msg.textContent='';
      const box = document.getElementById('dr-result');
      box.classList.remove('hidden');
      let html = '<strong>✅ '+j.nb_requests+' demande(s) créée(s) — une par comptable.</strong>';
      (j.requests||[]).forEach(function(r){
        html += '<div style="margin-top:8px;padding-top:8px;border-top:1px solid #cfe9d6">'
          + '<b>'+(r.nom||r.email)+'</b> ('+r.email+') — '+r.nb_pieces+' agence(s) · '
          + (r.mail_sent ? 'lien envoyé ✅' : '⚠️ mail non parti')
          + '<div><a href="'+r.url+'" target="_blank" style="word-break:break-all">'+r.url+'</a></div></div>';
      });
      if((j.skipped||[]).length){ html += '<div style="margin-top:8px;color:#b45309;font-size:12px">⚠️ Agences sans comptable renseigné (ignorées) : '+j.skipped.join(', ')+'</div>'; }
      box.innerHTML = html;
      box.scrollIntoView({behavior:'smooth'});
    } else if(j.ok){
      msg.textContent='';
      const box = document.getElementById('dr-result');
      box.classList.remove('hidden');
      box.innerHTML = '<strong>✅ Demande créée — '+j.nb_pieces+' pièce(s).</strong>'
        + (j.mail_sent ? ' Lien envoyé par mail à '+payload.recipient_email+'.' : ' ⚠️ Mail non parti, copie le lien :')
        + '<div style="margin-top:8px"><a href="'+j.url+'" target="_blank">'+j.url+'</a></div>';
      box.scrollIntoView({behavior:'smooth'});
    } else { msg.style.color='#ef4444'; msg.textContent = j.error || 'Échec'; }
  }catch(e){ msg.style.color='#ef4444'; msg.textContent='Erreur réseau'; }
}
// Pré-remplissage depuis le contexte (fiche d'origine)
if (CTX.email) document.getElementById('recEmail').value = CTX.email;
if (CTX.title && !document.getElementById('titre').value) document.getElementById('titre').value = 'Documents — ' + CTX.title;
// init : applique le modèle présélectionné (?tpl=), sinon une pièce vide
if (document.getElementById('tplSelect').value) { applyTemplate(); }
else { addItem(); }
</script>
