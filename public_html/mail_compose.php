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

// Option : générer la fiche vitrine (affiche A3) à la volée pour l'attacher (bouton fiche 360).
// Le PDF est persisté en draft dans mbi_supports_commerciaux ; mail_context() le remonte ensuite
// comme document du bien (uid mbi_vitrine:*), ce qui permet aussi la ré-autorisation à l'envoi.
if ($ctxType === 'BIEN' && ($_GET['gen_affiche_vitrine'] ?? '') === '1') {
    try {
        require_once __DIR__ . '/inc/mbi_supports_pdf_generator.php';
        mbi_supports_pdf_generer($ctxId, 'affiche_vitrine', null, ['id_user' => (int)current_user_id()]);
    } catch (Throwable $e) { error_log('[mail vitrine] ' . $e->getMessage()); }
}

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

// Signature de l'utilisateur connecté (valable en général).
$signature = '';
try {
    $stS = $pdo->prepare("SELECT prenom, nom, fonction, COALESCE(NULLIF(email_pro,''),email) AS email, COALESCE(NULLIF(telephone_pro,''),telephone) AS tel FROM users WHERE id = ? LIMIT 1");
    $stS->execute([(int)current_user_id()]);
    if ($u = $stS->fetch(PDO::FETCH_ASSOC)) {
        $lines = [trim(((string)($u['prenom'] ?? '')) . ' ' . ((string)($u['nom'] ?? '')))];
        if (!empty($u['fonction'])) $lines[] = (string)$u['fonction'];
        if (!empty($u['tel']))      $lines[] = 'Tél. ' . $u['tel'];
        if (!empty($u['email']))    $lines[] = (string)$u['email'];
        $signature = "\n\n-- \n" . implode("\n", array_filter($lines));
    }
} catch (Throwable $e) {}

// Modèle par défaut « copie de document » (appliqué au chargement, modifiable).
$ref = (string)($C['reference'] ?? '');
$ged = (string)($C['ged_name'] ?? $title);
$refBlock = $ref !== '' ? "\n\nRéférence : " . $ref . "\n(Merci de rappeler cette référence pour tout retour de document.)" : '';
$defaultTpl = [
    'sujet' => 'Copie de document' . ($ged ? ' — ' . $ged : ''),
    'corps' => "Bonjour,\n\nVeuillez trouver ci-joint le document" . ($ged ? " « " . $ged . " »" : '') . "." . $refBlock . "\n\nBien cordialement," . $signature,
];

// ── MODE SIGNATURE (ctx=BAIL & mode=signature) : cérémonie de signature via ce composeur ──
// Crée les tokens (un par signataire), prépare destinataires + template + bloc preneur (RIB/total/
// assurance) + DPE auto-coché. Le lien est PERSONNALISÉ par destinataire à l'envoi (placeholder).
$signMode = ($ctxType === 'BAIL' && (($_GET['mode'] ?? '') === 'signature'));
$signData = [];      // email => ['url'=>, 'preneur_block'=>, 'token_id'=>]
$signPrefill = null;
if ($signMode) {
    require_once __DIR__ . '/inc/bail_signature.php';
    require_once __DIR__ . '/inc/bail_commercial_pdf.php';
    try {
        $sigs = bsig_create_for_signataires($pdo, $ctxId, (int)current_user_id());
        // Bloc PRENEUR : total à verser + RIB de gestion + attestation (preneur uniquement).
        $preneurBlock = '';
        try {
            $ctxB = bail_commercial_pdf_context($pdo, $ctxId);
            if ($ctxB) {
                $ge = $ctxB['gestionnaire'] ?? []; $cc = $ctxB['cond'] ?? [];
                $tvaOn=!empty($cc['tva_app']); $tvaT=(float)($cc['tva_taux'] ?? 20) ?: 20.0; $perM=(($cc['perio'] ?? '')==='trimestrielle')?3:1;
                $loyM=(float)($cc['loyer_m'] ?? 0); $chM=(float)($cc['charges_m'] ?? 0); $tfM=(float)($cc['prov_tf'] ?? 0);
                $techM=($cc['tech_pct'] ?? null)!==null ? $loyM*(float)$cc['tech_pct']/100 : 0.0;
                $echBaseT=$tvaOn ? ($loyM+$techM)*$perM*(1+$tvaT/100) : ($loyM+$techM)*$perM;
                $prR=1.0; $prRaw=($cc['prorata_date'] ?? '') ?: ($cc['date_effet'] ?? '');
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$prRaw)){ $pts=strtotime((string)$prRaw);$pm=(int)date('n',$pts);$pd=(int)date('j',$pts);$py=(int)date('Y',$pts); if($perM===3){$qs=intdiv($pm-1,3)*3+1;$q1=mktime(0,0,0,$qs,1,$py);$q2=mktime(0,0,0,$qs+3,0,$py);$tt=(int)round(($q2-$q1)/86400)+1;$rm=(int)round(($q2-$pts)/86400)+1;$prR=$tt>0?$rm/$tt:1.0;}else{$dim=(int)date('t',$pts);$prR=$dim>0?($dim-$pd+1)/$dim:1.0;} }
                $dgM=(float)($cc['dg_montant'] ?? 0);$deM=(float)($cc['droit_entree'] ?? 0);$loyAn=(float)($cc['loyer_a'] ?? $loyM*12);$hpP=$cc['hono_pct_pren'] ?? null;
                $honoP=$hpP!==null?$loyAn*(float)$hpP/100*1.20:(float)($cc['hono_loc'] ?? 0);
                $totSign=($echBaseT+$chM*$perM+$tfM*$perM)*$prR+$dgM+$deM+$honoP;
                $rib = !empty($ge['rib_iban']) ? ('<p style="background:#f4f7f7;border:1px solid #dbe6e6;border-radius:8px;padding:10px 12px;"><strong>RIB de gestion de l\'agence (versement)</strong><br>'.($ge['rib_nom']?htmlspecialchars((string)$ge['rib_nom']).'<br>':'').'IBAN : <strong>'.htmlspecialchars((string)$ge['rib_iban']).'</strong>'.($ge['rib_bic']?' &middot; BIC : <strong>'.htmlspecialchars((string)$ge['rib_bic']).'</strong>':'').'</p>') : '';
                $preneurBlock = ($totSign>0 ? '<p><strong>Montant total à verser à la signature : '.number_format($totSign,2,',',' ').' €</strong><br>Merci de régler l\'intégralité des sommes par virement sur le RIB ci-dessous.</p>' : '')
                    . $rib
                    . '<p>Pour prendre possession des lieux, merci de nous transmettre votre <strong>attestation d\'assurance</strong> (vous pourrez la joindre au moment de la signature).</p>';
            }
        } catch (Throwable $e) {}
        $to = [];
        foreach ($sigs as $s) {
            $email = trim((string)($s['destinataire_email'] ?? ''));
            if ($email==='' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            $isPre = (($s['role_code'] ?? '')==='preneur');
            $signData[$email] = ['url'=>bsig_build_url((string)$s['token']), 'preneur_block'=>$isPre?$preneurBlock:'', 'token_id'=>(int)$s['id']];
            $to[] = $email;
        }
        // DPE du bien auto-coché (parmi les docs du contexte). Le bail PDF est joint côté serveur.
        $signDocs = [];
        foreach (($docs ?? []) as $d) { $nm=strtolower((string)($d['name'] ?? '')); $ty=strtolower((string)($d['type'] ?? '')); if (!empty($d['has_file']) && (strpos($ty,'dpe')!==false || strpos($nm,'dpe')!==false)) $signDocs[] = (string)$d['uid']; }
        $signPrefill = [
            'subject' => 'Signature de votre bail commercial — ' . $title,
            'body'    => "Bonjour,\n\nNous sommes heureux de vous transmettre votre bail commercial, prêt à être signé.\n\nLa signature se fait très simplement depuis votre téléphone : ouvrez cet email sur votre mobile, cliquez sur le lien ci-dessous, lisez le bail puis signez avec votre doigt.\n\n{{LIEN_SIGNATURE}}\n\n{{BLOC_PRENEUR}}\n\nLe projet de bail est joint à cet email. Lien valable 48 heures ; votre signature est horodatée et tracée (adresse IP) à des fins de preuve." . $signature,
            'to'      => $to,
            'docs'    => $signDocs,
        ];
    } catch (Throwable $e) { error_log('[mail signature] '.$e->getMessage()); }
}

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
        <div class="tm-field" style="margin-bottom:8px;">
          <label>🔎 Rechercher un tiers</label>
          <input type="text" id="tm-tiers-search" class="tm-input" autocomplete="off" placeholder="nom, société, email…">
          <div id="tm-tiers-res" style="margin-top:6px;"></div>
        </div>
        <?php if (!$contacts): ?><div class="tm-empty">Aucun contact prérempli.</div><?php endif; ?>
        <?php foreach ($contacts as $a): $em = trim((string)($a['email'] ?? '')); if ($em === '') continue; ?>
          <?php $rle = (string)($a['role'] ?? ''); $rbadge = $rle === 'societe' ? 'Société' : ($rle === 'agence' ? 'Agence' : ''); ?>
          <label class="tm-opt">
            <input type="checkbox" class="tm-recip" value="<?= h($em) ?>" data-role="<?= h($rle) ?>"<?= !empty($a['checked']) ? ' checked' : '' ?>>
            <span style="display:block;"><?= h($a['nom'] ?: $em) ?><?php if ($rbadge): ?> <span class="tm-badge"><?= h($rbadge) ?></span><?php endif; ?><br><small><?= h($em) ?></small></span>
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

      <?php if (function_exists('is_super_admin') && is_super_admin() && $ctxType !== 'CREANCIER_DOSSIER'): ?>
      <div class="tm-card">
        <h3>🏢 Sociétés &amp; agences</h3>
        <input type="text" id="tm-soc-search" class="tm-input" placeholder="Filtrer une société…" style="margin-bottom:6px;">
        <div id="tm-soc-list"><div class="tm-empty">Chargement…</div></div>
      </div>
      <?php endif; ?>
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
      <?php if ($ctxType === 'MBO'): ?>
      <div class="tm-card">
        <h3>📎 Document en retour</h3>
        <label class="tm-opt"><input type="checkbox" id="rr-on"><span>Demander un document en retour</span></label>
        <div id="rr-box" style="display:none;margin-top:8px;">
          <div class="tm-field"><label>Nom GED (emplacement, modifiable)</label><input type="text" id="rr-ged" class="tm-input" value="<?= h((string)($C['ged_name'] ?? '')) ?>"></div>
          <label class="tm-opt"><input type="checkbox" id="rr-relance"><span>Relancer si pas reçu</span></label>
          <div class="tm-field" id="rr-delai-box" style="display:none;"><label>Délai / cadence de relance (jours)</label><input type="number" id="rr-delai" class="tm-input" value="7" min="1" style="width:100px;"></div>
          <button type="button" class="tm-send" style="margin-top:8px;" onclick="rrGenerate()">🔗 Générer le lien de dépôt</button>
          <div id="rr-msg" style="font-size:12px;margin-top:6px;color:#64748b;"></div>
        </div>
      </div>
      <?php endif; ?>
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
    if (SIGN_MODE) { fd.append('sign_mode','1'); fd.append('sign_data', JSON.stringify(SIGN_DATA)); }
    msg.style.color='#64748b'; msg.textContent='Envoi en cours…';
    try{
      const r = await fetch(API, {method:'POST', body:fd});
      const j = await r.json();
      if(j.ok){ msg.style.color='#15803d'; msg.textContent='✓ Envoyé à '+j.sent+' destinataire(s), '+j.nb_attachments+' pièce(s) jointe(s).'; }
      else { msg.style.color='#ef4444'; msg.textContent = j.error || 'Échec'; }
    }catch(e){ msg.style.color='#ef4444'; msg.textContent='Erreur réseau'; }
  };

  const SIGN_MODE = <?= $signMode ? 'true' : 'false' ?>;
  const SIGN_DATA = <?= json_encode($signData ?: (object)[], JSON_UNESCAPED_UNICODE) ?>;
  const PREFILL = <?= json_encode(($signMode && $signPrefill) ? $signPrefill : $prefill, JSON_UNESCAPED_UNICODE) ?>;
  const DEFAULT_TPL = <?= json_encode($defaultTpl, JSON_UNESCAPED_UNICODE) ?>;
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
  } else {
    // Modèle « copie de document » + signature, pré-rempli d'emblée.
    document.getElementById('tm-sujet').value = DEFAULT_TPL.sujet || '';
    document.getElementById('tm-corps').value = DEFAULT_TPL.corps || '';
    // Attache d'office les documents disponibles (utile depuis MaBoxOffice).
    document.querySelectorAll('.tm-doc').forEach(c => { if(!c.disabled) c.checked = true; });
    // Destinataires agence/société pré-cochés automatiquement (supprimables en décochant).
    document.querySelectorAll('.tm-recip').forEach(c => { var r=c.dataset.role||''; if(r==='agence'||r==='societe') c.checked = true; });
  }

  // ── Recherche de tiers → ajout en destinataire ──────────────────────────────
  const TIERSAPI = <?= json_encode(app_url('/api/mail_tiers_search.php')) ?>;
  const ts = document.getElementById('tm-tiers-search'), tr = document.getElementById('tm-tiers-res');
  let tsT = null;
  if (ts) ts.addEventListener('input', function(){ const q=this.value.trim(); clearTimeout(tsT); if(q.length<2){ tr.innerHTML=''; return; }
    tsT = setTimeout(function(){ fetch(TIERSAPI+'?q='+encodeURIComponent(q),{credentials:'same-origin'}).then(r=>r.json()).then(function(d){
      if(!d.ok || !d.tiers.length){ tr.innerHTML='<div class="tm-empty">Aucun tiers avec email</div>'; return; }
      tr.innerHTML = d.tiers.map(function(t){ return '<div class="tm-tpl" data-email="'+t.email.replace(/"/g,'&quot;')+'" data-nom="'+(t.nom||'').replace(/"/g,'&quot;')+'">'+(t.nom||t.email)+' <small>'+t.email+(t.role?' · '+t.role:'')+'</small></div>'; }).join('');
      tr.querySelectorAll('[data-email]').forEach(function(el){ el.onclick=function(){ addRecip(el.dataset.email, el.dataset.nom); ts.value=''; tr.innerHTML=''; }; });
    }).catch(function(){ tr.innerHTML=''; }); }, 250);
  });
  // ── Demander un document en retour (lien de dépôt → même emplacement GED) ────
  const RRAPI = <?= json_encode(app_url('/api/maboxoffice_request_return.php')) ?>;
  const MBODOC = <?= (int)($C['mbo_doc'] ?? 0) ?>;
  const rrOn=document.getElementById('rr-on'), rrBox=document.getElementById('rr-box'),
        rrRel=document.getElementById('rr-relance'), rrDelBox=document.getElementById('rr-delai-box');
  if(rrOn) rrOn.addEventListener('change', function(){ rrBox.style.display=this.checked?'block':'none'; });
  if(rrRel) rrRel.addEventListener('change', function(){ rrDelBox.style.display=this.checked?'block':'none'; });
  function firstRecipient(){ var c=document.querySelector('.tm-recip:checked'); return c?c.value:''; }
  window.rrGenerate = function(){
    var m=document.getElementById('rr-msg'); var email=firstRecipient();
    if(!email){ m.style.color='#ef4444'; m.textContent='Coche d\'abord un destinataire.'; return; }
    var ged=document.getElementById('rr-ged').value.trim();
    var rel=document.getElementById('rr-relance').checked?1:0;
    var del=rel?(parseInt(document.getElementById('rr-delai').value,10)||7):0;
    m.style.color='#64748b'; m.textContent='Création du lien…';
    fetch(RRAPI+'?doc='+encodeURIComponent(MBODOC)+'&email='+encodeURIComponent(email)+'&ged='+encodeURIComponent(ged)+'&relance='+rel+'&delai='+del,{credentials:'same-origin'})
      .then(r=>r.json()).then(function(d){ if(!d.ok){ m.style.color='#ef4444'; m.textContent='⚠ '+(d.error||'échec'); return; }
        m.style.color='#2e8b47'; m.textContent='✓ Lien créé et inséré dans le message.';
        var corps=document.getElementById('tm-corps');
        var bloc='\n\nPour me retourner un document, déposez-le ici :\n'+d.url+(d.expires_at?('\n(avant le '+d.expires_at.substr(8,2)+'/'+d.expires_at.substr(5,2)+'/'+d.expires_at.substr(0,4)+')'):'');
        corps.value = corps.value + bloc;
      }).catch(function(e){ m.style.color='#ef4444'; m.textContent='⚠ '+e; });
  };

  function addRecip(email, nom){ if(!email) return;
    let found=false; document.querySelectorAll('.tm-recip').forEach(function(c){ if(c.value.toLowerCase()===email.toLowerCase()){ c.checked=true; found=true; } });
    if(found) return;
    const tag=document.createElement('label'); tag.className='tm-opt';
    tag.innerHTML='<input type="checkbox" class="tm-recip" value="'+email.replace(/"/g,'&quot;')+'" checked data-role="tiers"><span>'+(nom||email)+'<br><small>'+email+'</small></span>';
    document.getElementById('tm-free-list').appendChild(tag);
  }

  // ── Super admin : toutes les sociétés & agences → ajout en destinataires ────
  const SOCAPI = <?= json_encode(app_url('/api/mail_societes.php')) ?>;
  var socData = [];
  function renderSocs(f){ var box=document.getElementById('tm-soc-list'); if(!box) return;
    var list = socData.filter(function(s){ return !f || s.nom.toLowerCase().indexOf(f)>=0; });
    if(!list.length){ box.innerHTML='<div class="tm-empty">Aucune société</div>'; return; }
    box.innerHTML = list.map(function(s){ var n=(s.email?1:0)+s.agences.length; return '<div class="tm-tpl" data-sid="'+s.id+'">'+s.nom+' <small>'+(n?(n+' email(s)'):'aucun email')+'</small></div>'; }).join('');
    box.querySelectorAll('[data-sid]').forEach(function(el){ el.onclick=function(){ var s=socData.filter(function(x){return x.id==el.dataset.sid;})[0]; if(!s) return; if(s.email) addRecip(s.email, s.nom+' (société)'); s.agences.forEach(function(a){ addRecip(a.email, a.nom+' (agence)'); }); }; });
  }
  (function loadSocs(){ var box=document.getElementById('tm-soc-list'); if(!box) return;
    fetch(SOCAPI,{credentials:'same-origin'}).then(r=>r.json()).then(function(d){ if(!d.ok){ box.innerHTML='<div class="tm-empty">Erreur</div>'; return; } socData=d.societes||[]; renderSocs(''); }).catch(function(){ box.innerHTML='<div class="tm-empty">Erreur</div>'; });
  })();
  var socSearch=document.getElementById('tm-soc-search'); if(socSearch) socSearch.addEventListener('input', function(){ renderSocs(this.value.trim().toLowerCase()); });
})();
</script>
