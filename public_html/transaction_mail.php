<?php
// transaction_mail.php — Composition d'un mail du dossier de vente.
// Colonne gauche : Contacts · Documents · Modèles. Colonne droite : éditeur. Sans IA.
// Réutilise mail_templates + dv_acteurs + GED + send_mail (via API mail_send).
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/ged_document_links.php';
require_once __DIR__ . '/inc/dossier_vente.php';
require_once __DIR__ . '/inc/ged_file_path.php';
require_login();

$idDossier = (int)($_GET['id_dossier'] ?? 0);
if ($idDossier <= 0 && isset($_GET['id_bien']) && ctype_digit((string)$_GET['id_bien'])) {
    $idDossier = dv_ensure_for_bien($pdo, (int)$_GET['id_bien'], ['source' => 'manual', 'id_user' => current_user_id()]);
}
$dossier = dv_get($pdo, $idDossier);
if (!$dossier) { http_response_code(404); exit('Dossier introuvable.'); }
$idBien = (int)$dossier['id_bien'];

// Bien + proprio pour placeholders
$stB = $pdo->prepare("SELECT b.reference_bien, b.designation, b.adresse_1, b.code_postal, b.ville,
                             COALESCE(NULLIF(b.adresse_1,''), i.adresse_1) AS adr,
                             COALESCE(NULLIF(b.ville,''), i.ville) AS vil, p.id_tiers AS proprio_tiers_id
                        FROM biens b LEFT JOIN immeubles i ON i.id = b.id_immeuble
                        LEFT JOIN proprietaires p ON p.id = b.id_proprietaire WHERE b.id = ? LIMIT 1");
$stB->execute([$idBien]);
$bien = $stB->fetch(PDO::FETCH_ASSOC) ?: [];
$refBien = $bien['reference_bien'] ?: ('#' . $idBien);
$refDossier = (string)($dossier['reference'] ?? '') ?: $refBien;
$bienLbl = $bien['designation'] ?: $refBien;
$adresse = trim(((string)($bien['adr'] ?? '')) . ' ' . ((string)($bien['code_postal'] ?? '')) . ' ' . ((string)($bien['vil'] ?? '')));

$acteurs = dv_acteurs($pdo, $idDossier);
$proprioNom = '';
foreach ($acteurs as $a) {
    if (in_array($a['role_code'], ['vendeur','prospect_vendeur'], true)) {
        $proprioNom = $a['nom_affichage'] ?: ($a['raison_sociale'] ?: trim(($a['prenom'] ?? '') . ' ' . ($a['nom'] ?? ''))); break;
    }
}

// Documents : dossier + bien (dédupliqués), avec type
$seen = []; $docs = [];
foreach (dv_documents($pdo, $idDossier) as $d) { $seen[$d['id']]=1; $docs[]=$d; }
foreach (gdl_documents_for_entity($pdo, 'BIEN', $idBien, ['limit'=>200]) as $d) { if(isset($seen[$d['id']]))continue; $seen[$d['id']]=1; $docs[]=$d; }

// Modèles de mail + mapping mots-clés → types de doc & rôles destinataires
$templates = [];
try { $templates = $pdo->query("SELECT id, titre, categorie, sujet, corps FROM mail_templates ORDER BY categorie, titre")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
$mapTpl = static function(string $s): array {
    $s = mb_strtolower($s);
    if (str_contains($s,'avis') || str_contains($s,'estimation')) return [['ESTIMATION'], ['vendeur','prospect_vendeur']];
    if (str_contains($s,'mandat'))                                return [['MANDAT_VENTE'], ['vendeur','prospect_vendeur']];
    if (str_contains($s,'offre'))                                 return [['OFFRE_ACHAT'], ['vendeur','prospect_vendeur']];
    if (str_contains($s,'acte') || str_contains($s,'compromis'))  return [['ACTE_AUTHENTIQUE','COMPROMIS'], ['notaire','notaire_acquereur','acquereur']];
    return [[], []];
};

// Expéditeur : l'utilisateur connecté (affiché + utilisé en Reply-To à l'envoi).
$expediteurEmail = ''; $expediteurNom = '';
try {
    $stE = $pdo->prepare("SELECT email, TRIM(CONCAT(COALESCE(prenom,''),' ',COALESCE(nom,''))) AS nom FROM users WHERE id = ? LIMIT 1");
    $stE->execute([(int)current_user_id()]);
    if ($u = $stE->fetch(PDO::FETCH_ASSOC)) { $expediteurEmail = (string)$u['email']; $expediteurNom = trim((string)$u['nom']); }
} catch (Throwable $e) {}

// Réouverture d'un mail de l'historique : pré-remplit sujet/corps/destinataires/PJ.
$prefill = null;
if (isset($_GET['from_history']) && ctype_digit((string)$_GET['from_history'])) {
    try {
        $stH = $pdo->prepare("SELECT subject, body, recipients_json FROM mail_history WHERE id = ? AND recipient_type = ? LIMIT 1");
        $stH->execute([(int)$_GET['from_history'], 'dossier_vente:' . $idDossier]);
        if ($h = $stH->fetch(PDO::FETCH_ASSOC)) {
            $meta = json_decode((string)$h['recipients_json'], true) ?: [];
            $prefill = [
                'subject' => (string)$h['subject'],
                'body'    => (string)$h['body'],
                'to'      => array_values(array_filter((array)($meta['to'] ?? []))),
                'docs'    => array_values(array_map('intval', (array)($meta['doc_ids'] ?? []))),
            ];
        }
    } catch (Throwable $e) {}
}

$csrf = function_exists('csrf_token') ? csrf_token('dossier_mail') : '';
$pageTitle = 'Mail · ' . $refDossier;
$bodyAttr  = 'data-theme-module="transaction"';
include __DIR__ . '/inc/agency_layout_top.php';
?>
<style>
/* Fond de page dégradé (même que FluxBox / dossier) */
.agency-content{
  min-height:100vh;
  background:
    linear-gradient(135deg,
      rgba(154,170,132,.18) 0%, rgba(255,255,255,0) 35%,
      rgba(72,120,166,.14) 60%, rgba(255,255,255,0) 85%,
      rgba(201,123,46,.16) 100%),
    #fafbfc !important;
  background-attachment:fixed !important;
}
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
    <a href="<?= h(app_url('/transaction_dossier.php?id_dossier=' . $idDossier)) ?>" style="color:#0e7490;font-weight:700;text-decoration:none;">← Dossier · <?= h($refDossier) ?></a>
    <span style="color:#64748b;"> · ✉️ Envoi de mail</span>
  </div>
  <div class="tm-cols">
    <!-- COLONNE GAUCHE : sélecteurs -->
    <div>
      <div class="tm-card">
        <h3>👥 Contacts</h3>
        <?php
          $hasContact = false;
          foreach ($acteurs as $a):
            $em = trim((string)($a['email'] ?? ''));
            if ($em === '') continue;
            $hasContact = true;
            $nom = $a['nom_affichage'] ?: ($a['raison_sociale'] ?: trim(($a['prenom'] ?? '') . ' ' . ($a['nom'] ?? ''))) ?: $em;
        ?>
          <label class="tm-opt">
            <input type="checkbox" class="tm-recip" value="<?= h($em) ?>" data-role="<?= h($a['role_code']) ?>">
            <span style="display:block;"><?= h($nom) ?><br><small><?= h($em) ?></small></span>
          </label>
        <?php endforeach; ?>
        <?php if (!$hasContact): ?><div class="tm-empty">Aucun acteur avec email. Ajoute-les dans le dossier.</div><?php endif; ?>
        <div class="tm-field" style="margin-top:8px;">
          <label>Ajouter un email libre</label>
          <input type="email" id="tm-free-email" class="tm-input" placeholder="email@exemple.fr (+ Entrée)">
        </div>
        <div id="tm-free-list"></div>
      </div>

      <div class="tm-card">
        <h3>📎 Documents</h3>
        <?php if (!$docs): ?><div class="tm-empty">Aucun document rattaché.</div><?php endif; ?>
        <?php foreach ($docs as $d):
          $hasFile = ged_file_path($pdo, (int)$d['id']) !== null; ?>
          <label class="tm-opt" style="<?= $hasFile ? '' : 'opacity:.55;' ?>">
            <input type="checkbox" class="tm-doc" value="<?= (int)$d['id'] ?>" data-type="<?= h($d['document_type'] ?? '') ?>" <?= $hasFile ? '' : 'disabled' ?>>
            <span><?= h($d['name_display'] ?: $d['name_file'] ?: ('Doc #' . $d['id'])) ?>
              <?php if (!empty($d['document_type'])): ?><span class="tm-badge"><?= h($d['document_type']) ?></span><?php endif; ?>
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
        <?php foreach ($templates as $t):
          [$dts, $roles] = $mapTpl($t['titre'] . ' ' . $t['categorie']); ?>
          <div class="tm-tpl"
               data-sujet="<?= h($t['sujet']) ?>"
               data-corps="<?= h($t['corps']) ?>"
               data-doctypes='<?= h(json_encode($dts)) ?>'
               data-roles='<?= h(json_encode($roles)) ?>'
               onclick="tmApplyTemplate(this)">
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
  const API = <?= json_encode(app_url('/api/transaction_dossier_mail_send.php')) ?>;
  const DOSSIER_ID = <?= (int)$idDossier ?>;
  const VARS = {
    '{{proprietaire}}': <?= json_encode($proprioNom ?: 'Madame, Monsieur') ?>,
    '{{bien}}': <?= json_encode($bienLbl) ?>,
    '{{adresse}}': <?= json_encode($adresse) ?>
  };
  function subst(s){ for(const k in VARS){ s = s.split(k).join(VARS[k]); } return s; }

  window.tmApplyTemplate = function(el){
    document.getElementById('tm-sujet').value = subst(el.dataset.sujet || '');
    document.getElementById('tm-corps').value = subst((el.dataset.corps || '').replace(/\\n/g,'\n'));
    const dts = JSON.parse(el.dataset.doctypes || '[]');
    const roles = JSON.parse(el.dataset.roles || '[]');
    // Pré-coche les docs du type concerné
    document.querySelectorAll('.tm-doc').forEach(c => { c.checked = dts.includes(c.dataset.type); });
    // Pré-coche les destinataires du rôle ciblé
    document.querySelectorAll('.tm-recip').forEach(c => { c.checked = roles.includes(c.dataset.role); });
  };

  // Emails libres
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
    // Inclut l'email libre saisi mais non encore validé par Entrée.
    const freeEl = document.getElementById('tm-free-email');
    const freeVal = (freeEl.value || '').trim();
    if(freeVal){
      if(!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(freeVal)){ msg.style.color='#ef4444'; msg.textContent='Email libre invalide : '+freeVal; freeEl.style.borderColor='#ef4444'; return; }
      if(!recipients.includes(freeVal)) recipients.push(freeVal);
    }
    const docs = Array.from(document.querySelectorAll('.tm-doc:checked')).map(c=>c.value);
    const sujet = document.getElementById('tm-sujet').value.trim();
    const corps = document.getElementById('tm-corps').value.trim();
    if(!recipients.length){ msg.style.color='#ef4444'; msg.textContent='Sélectionne au moins un destinataire.'; return; }
    if(!sujet || !corps){ msg.style.color='#ef4444'; msg.textContent='Sujet et corps requis.'; return; }
    const fd = new FormData();
    fd.append('id_dossier', DOSSIER_ID);
    fd.append('sujet', sujet);
    fd.append('corps', corps);
    fd.append('CSRF', <?= json_encode($csrf) ?>);
    recipients.forEach(r => fd.append('recipients[]', r));
    docs.forEach(d => fd.append('doc_ids[]', d));
    msg.style.color='#64748b'; msg.textContent='Envoi en cours…';
    try{
      const r = await fetch(API, {method:'POST', body:fd});
      const j = await r.json();
      if(j.ok){ msg.style.color='#15803d'; msg.textContent='✓ Envoyé à '+j.sent+' destinataire(s), '+j.nb_attachments+' pièce(s) jointe(s).'; }
      else { msg.style.color='#ef4444'; msg.textContent = j.error || 'Échec'; }
    }catch(e){ msg.style.color='#ef4444'; msg.textContent='Erreur réseau'; }
  };

  // Réouverture depuis l'historique : pré-remplit tout.
  const PREFILL = <?= json_encode($prefill) ?>;
  if (PREFILL) {
    document.getElementById('tm-sujet').value = PREFILL.subject || '';
    document.getElementById('tm-corps').value = PREFILL.body || '';
    const toSet = new Set(PREFILL.to || []);
    document.querySelectorAll('.tm-recip').forEach(c => { if(toSet.has(c.value)){ c.checked = true; toSet.delete(c.value); } });
    // Emails non présents dans les contacts → ajoutés en libre
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
