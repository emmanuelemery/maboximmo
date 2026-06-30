<?php
// mail_compose.php — Composeur de mail GÉNÉRIQUE (contacts + documents + modèles + éditeur).
// Piloté par inc/mail_context.php : ?ctx=USER&id=15&back=<url>. Sans IA.
// Réutilise mail_templates + send_mail (via api/mail_compose_send.php). Modèle : transaction_mail.php.
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/mail_context.php';
require_login();

$ctxType = strtoupper(trim((string)($_GET['ctx'] ?? '')));
$ctxId   = (int)($_GET['id'] ?? 0);
$backUrl = (string)($_GET['back'] ?? '');
if ($ctxType === '' || $ctxId <= 0) { http_response_code(400); exit('Contexte mail invalide (ctx + id requis).'); }

$C = mail_context($pdo, $ctxType, $ctxId);
if (empty($C['ok'])) { http_response_code(404); exit('Contexte introuvable.'); }

$title    = $C['title'] ?: ($ctxType . ' #' . $ctxId);
$contacts = $C['contacts'];
$docs     = $C['docs'];

// Modèles de mail
$templates = [];
try { $templates = $pdo->query("SELECT id, titre, categorie, sujet, corps FROM mail_templates ORDER BY categorie, titre")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}

// Expéditeur = utilisateur connecté (Reply-To à l'envoi)
$expediteurEmail = '';
try {
    $stE = $pdo->prepare("SELECT email FROM users WHERE id = ? LIMIT 1");
    $stE->execute([(int)current_user_id()]);
    if ($u = $stE->fetch(PDO::FETCH_ASSOC)) { $expediteurEmail = (string)$u['email']; }
} catch (Throwable $e) {}

// Réouverture depuis l'historique
$prefill = null;
if (isset($_GET['from_history']) && ctype_digit((string)$_GET['from_history'])) {
    try {
        $stH = $pdo->prepare("SELECT subject, body, recipients_json FROM mail_history WHERE id = ? AND recipient_type = ? LIMIT 1");
        $stH->execute([(int)$_GET['from_history'], $C['history_key']]);
        if ($h = $stH->fetch(PDO::FETCH_ASSOC)) {
            $meta = json_decode((string)$h['recipients_json'], true) ?: [];
            $prefill = [
                'subject' => (string)$h['subject'],
                'body'    => (string)$h['body'],
                'to'      => array_values(array_filter((array)($meta['to'] ?? []))),
                'docs'    => array_values(array_map('strval', (array)($meta['doc_uids'] ?? []))),
            ];
        }
    } catch (Throwable $e) {}
}

$csrf = function_exists('csrf_token') ? csrf_token('mail_compose') : '';
$pageTitle = 'Mail · ' . $title;
include __DIR__ . '/inc/agency_layout_top.php';
?>
<style>
.agency-content{min-height:100vh;background:linear-gradient(135deg,rgba(154,170,132,.18) 0%,rgba(255,255,255,0) 35%,rgba(72,120,166,.14) 60%,rgba(255,255,255,0) 85%,rgba(201,123,46,.16) 100%),#fafbfc !important;background-attachment:fixed !important;}
.tm-wrap{max-width:1240px;margin:0 auto;padding:14px 18px 48px;}
.tm-cols{display:grid;grid-template-columns:250px 230px 1fr;gap:16px;align-items:start;}
@media(max-width:980px){.tm-cols{grid-template-columns:1fr;}}
.tm-card{border:1px solid #e2e8f0;border-radius:14px;background:#fff;padding:14px 16px;margin-bottom:14px;box-shadow:0 1px 3px rgba(15,23,42,.05);}
.tm-card h3{margin:0 0 10px;font-size:13px;font-weight:900;color:#0f172a;}
.tm-opt{display:flex;align-items:flex-start;gap:7px;padding:5px 2px;border-bottom:1px solid #f1f5f9;font-size:11.5px;line-height:1.25;}
.tm-opt:last-child{border-bottom:none;}
.tm-opt input{margin-top:2px;flex-shrink:0;}
.tm-opt span{word-break:break-word;min-width:0;}
.tm-opt small{color:#94a3b8;font-size:10px;}
.tm-badge{font-size:8px;font-weight:700;color:#0f6cbd;background:#eef5fc;border-radius:5px;padding:1px 5px;}
.tm-tpl{padding:9px 11px;border:1px solid #e2e8f0;border-radius:10px;margin-bottom:7px;cursor:pointer;font-size:13px;font-weight:700;color:#334155;}
.tm-tpl:hover{border-color:#0e7490;background:#ecfeff;}
.tm-tpl small{display:block;font-weight:400;color:#94a3b8;font-size:11px;}
.tm-field{margin-bottom:10px;}
.tm-field label{display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:3px;}
.tm-input{width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:9px;font-size:14px;}
.tm-textarea{width:100%;min-height:320px;padding:11px;border:1px solid #cbd5e1;border-radius:9px;font-size:14px;line-height:1.5;resize:vertical;font-family:inherit;}
.tm-send{background:#0e7490;color:#fff;border:none;border-radius:10px;padding:12px 20px;font-size:14px;font-weight:800;cursor:pointer;}
.tm-send:hover{background:#0c6480;}
.tm-empty{color:#94a3b8;font-size:12px;font-style:italic;padding:6px 4px;}
</style>

<div class="tm-wrap">
  <div style="margin-bottom:12px;">
    <?php if ($backUrl !== ''): ?>
      <a href="<?= h($backUrl) ?>" style="color:#0e7490;font-weight:700;text-decoration:none;">← <?= h($title) ?></a>
      <span style="color:#64748b;"> · ✉️ Envoi de mail</span>
    <?php else: ?>
      <span style="color:#0f172a;font-weight:700;">✉️ Envoi de mail · <?= h($title) ?></span>
    <?php endif; ?>
  </div>
  <div class="tm-cols">
    <!-- COLONNE GAUCHE : sélecteurs -->
    <div>
      <div class="tm-card">
        <h3>👥 Contacts</h3>
        <?php if (!$contacts): ?><div class="tm-empty">Aucun contact avec email.</div><?php endif; ?>
        <?php foreach ($contacts as $a): $em = trim((string)($a['email'] ?? '')); if ($em === '') continue; ?>
          <label class="tm-opt">
            <input type="checkbox" class="tm-recip" value="<?= h($em) ?>" data-role="<?= h($a['role'] ?? '') ?>">
            <span style="display:block;"><?= h($a['nom'] ?: $em) ?><br><small><?= h($em) ?></small></span>
          </label>
        <?php endforeach; ?>
        <div class="tm-field" style="margin-top:8px;">
          <label>Ajouter un email libre</label>
          <input type="email" id="tm-free-email" class="tm-input" placeholder="email@exemple.fr (+ Entrée)">
        </div>
        <div id="tm-free-list"></div>
      </div>

      <div class="tm-card">
        <h3>📎 Documents</h3>
        <?php if (!$docs): ?><div class="tm-empty">Aucun document rattaché.</div><?php endif; ?>
        <?php foreach ($docs as $d): $hasFile = !empty($d['has_file']); ?>
          <label class="tm-opt" style="<?= $hasFile ? '' : 'opacity:.55;' ?>">
            <input type="checkbox" class="tm-doc" value="<?= h($d['uid']) ?>" data-type="<?= h($d['type'] ?? '') ?>" <?= $hasFile ? '' : 'disabled' ?>>
            <span><?= h($d['name']) ?>
              <?php if (!empty($d['type'])): ?><span class="tm-badge"><?= h($d['type']) ?></span><?php endif; ?>
              <?php if (!$hasFile): ?><small style="color:#ef4444;display:block;">⚠ fichier indisponible</small><?php endif; ?>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- COLONNE 2 : modèles -->
    <div>
      <div class="tm-card">
        <h3>📝 Modèle de mail</h3>
        <?php if (!$templates): ?><div class="tm-empty">Aucun modèle.</div><?php endif; ?>
        <?php foreach ($templates as $t): ?>
          <div class="tm-tpl" data-sujet="<?= h($t['sujet']) ?>" data-corps="<?= h($t['corps']) ?>" onclick="tmApplyTemplate(this)">
            <?= h($t['titre']) ?> <small><?= h($t['categorie']) ?></small>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- COLONNE 3 : éditeur -->
    <div class="tm-card">
      <h3 style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">✉️ Message
        <?php if ($expediteurEmail !== ''): ?>
          <span style="font-weight:400;font-size:11px;color:#64748b;">de <strong style="color:#0e7490;"><?= h($expediteurEmail) ?></strong></span>
        <?php endif; ?>
      </h3>
      <div class="tm-field"><label>Sujet</label><input type="text" id="tm-sujet" class="tm-input"></div>
      <div class="tm-field"><label>Corps du message (modifiable)</label><textarea id="tm-corps" class="tm-textarea"></textarea></div>
      <div style="display:flex;align-items:center;gap:14px;">
        <button type="button" class="tm-send" onclick="tmSend()">📤 Envoyer le mail</button>
        <span id="tm-msg" style="font-size:13px;"></span>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const API = <?= json_encode(app_url('/api/mail_compose_send.php')) ?>;
  const CTX = <?= json_encode($ctxType) ?>, ID = <?= (int)$ctxId ?>;
  const VARS = <?= json_encode((object)$C['placeholders'], JSON_UNESCAPED_UNICODE) ?>;
  function subst(s){ for(const k in VARS){ s = s.split(k).join(VARS[k]); } return s; }

  window.tmApplyTemplate = function(el){
    document.getElementById('tm-sujet').value = subst(el.dataset.sujet || '');
    document.getElementById('tm-corps').value = subst((el.dataset.corps || '').replace(/\\n/g,'\n'));
  };

  const freeEmails = [];
  document.getElementById('tm-free-email').addEventListener('keydown', function(e){
    if(e.key !== 'Enter') return; e.preventDefault();
    const v = this.value.trim(); if(!v) return;
    if(!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v)){ this.style.borderColor='#ef4444'; return; }
    this.style.borderColor='#cbd5e1';
    if(!freeEmails.includes(v)){ freeEmails.push(v);
      const tag = document.createElement('div'); tag.className='tm-opt';
      tag.innerHTML = '<input type="checkbox" class="tm-recip" value="'+v+'" checked data-role="libre"><span>'+v+'</span>';
      document.getElementById('tm-free-list').appendChild(tag);
    }
    this.value='';
  });

  window.tmSend = async function(){
    const msg = document.getElementById('tm-msg');
    const recipients = Array.from(document.querySelectorAll('.tm-recip:checked')).map(c=>c.value);
    const freeEl = document.getElementById('tm-free-email');
    const freeVal = (freeEl.value || '').trim();
    if(freeVal){
      if(!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(freeVal)){ msg.style.color='#ef4444'; msg.textContent='Email libre invalide : '+freeVal; return; }
      if(!recipients.includes(freeVal)) recipients.push(freeVal);
    }
    const docs = Array.from(document.querySelectorAll('.tm-doc:checked')).map(c=>c.value);
    const sujet = document.getElementById('tm-sujet').value.trim();
    const corps = document.getElementById('tm-corps').value.trim();
    if(!recipients.length){ msg.style.color='#ef4444'; msg.textContent='Sélectionne au moins un destinataire.'; return; }
    if(!sujet || !corps){ msg.style.color='#ef4444'; msg.textContent='Sujet et corps requis.'; return; }
    const fd = new FormData();
    fd.append('ctx', CTX); fd.append('id', ID);
    fd.append('sujet', sujet); fd.append('corps', corps);
    fd.append('CSRF', <?= json_encode($csrf) ?>);
    recipients.forEach(r => fd.append('recipients[]', r));
    docs.forEach(d => fd.append('doc_uids[]', d));
    msg.style.color='#64748b'; msg.textContent='Envoi en cours…';
    try{
      const r = await fetch(API, {method:'POST', body:fd});
      const j = await r.json();
      if(j.ok){ msg.style.color='#15803d'; msg.textContent='✓ Envoyé à '+j.sent+' destinataire(s), '+j.nb_attachments+' pièce(s) jointe(s).'; }
      else { msg.style.color='#ef4444'; msg.textContent = j.error || 'Échec'; }
    }catch(e){ msg.style.color='#ef4444'; msg.textContent='Erreur réseau'; }
  };

  const PREFILL = <?= json_encode($prefill) ?>;
  if (PREFILL) {
    document.getElementById('tm-sujet').value = PREFILL.subject || '';
    document.getElementById('tm-corps').value = PREFILL.body || '';
    const toSet = new Set(PREFILL.to || []);
    document.querySelectorAll('.tm-recip').forEach(c => { if(toSet.has(c.value)){ c.checked = true; toSet.delete(c.value); } });
    toSet.forEach(v => {
      const tag = document.createElement('div'); tag.className='tm-opt';
      tag.innerHTML = '<input type="checkbox" class="tm-recip" value="'+v+'" checked data-role="libre"><span>'+v+'</span>';
      document.getElementById('tm-free-list').appendChild(tag);
    });
    const docSet = new Set((PREFILL.docs || []).map(String));
    document.querySelectorAll('.tm-doc').forEach(c => { if(docSet.has(c.value) && !c.disabled) c.checked = true; });
  }
})();
</script>
