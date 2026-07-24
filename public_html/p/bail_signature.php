<?php
declare(strict_types=1);
/**
 * p/bail_signature.php — Page publique de signature d'un bail commercial (token).
 * Sans authentification. Affiche le BAIL COMPLET + parcours de signature :
 *   acceptation des clauses → mention « bon pour acceptation » tapée + date → signature au doigt
 *   (pavé plein écran) → photo optionnelle (preuve, jamais diffusée).
 * Preuve (faisceau) = IP + horodatage + user-agent + tracé PNG + mention + photo optionnelle.
 * Lien valable BSIG_TTL_MIN minutes. À la dernière signature → PDF définitif → GED → envoi à tous.
 * URL : /p/bail_signature.php?t=<token_64_hex>
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/bail_signature.php';
require_once __DIR__ . '/../inc/bail_commercial_pdf.php';

$pdo = $GLOBALS['pdo'];
$token = (string)($_GET['t'] ?? ($_POST['t'] ?? ''));
if (!preg_match('/^[a-f0-9]{32,128}$/i', $token)) { http_response_code(400); die('Lien invalide.'); }

$sig = bsig_get_by_token($pdo, $token);
if (!$sig) { http_response_code(404); die('Ce lien n\'existe pas.'); }

function bsig_client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) return trim(explode(',', (string)$_SERVER[$k])[0]);
    }
    return '';
}

// Normalisation pour comparer la mention tapée à la mention attendue (casse/espaces/accents souples).
function bsig_norm(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['é'=>'e','è'=>'e','ê'=>'e','à'=>'a','â'=>'a','î'=>'i','ï'=>'i','ô'=>'o','û'=>'u','ç'=>'c']);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';
    return trim(preg_replace('/\s+/', ' ', $s) ?? '');
}

$isCaution = (($sig['role_code'] ?? '') === 'caution');
$mentionAttendue = $isCaution ? 'Bon pour caution solidaire, lu et approuvé' : 'Lu et approuvé, bon pour acceptation';

$expired = bsig_is_expired($sig);
$dejaSigne = ($sig['statut'] === 'signe');

$flash = null; $justSigned = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$dejaSigne && !$expired) {
    $nom       = trim((string)($_POST['nom_signataire'] ?? ''));
    $approuve  = !empty($_POST['lu_approuve']);
    $mention   = trim((string)($_POST['mention_manuscrite'] ?? ''));
    $dateMain  = trim((string)($_POST['date_manuscrite'] ?? ''));
    $sigData   = (string)($_POST['signature_data'] ?? '');
    $photoData = (string)($_POST['photo_data'] ?? '');
    $photoOk   = !empty($_POST['photo_consent']);

    if ($nom === '')                                   { $flash = 'Merci d\'indiquer votre nom et prénom.'; }
    elseif (!$approuve)                                { $flash = 'Merci de cocher l\'acceptation des clauses du bail.'; }
    elseif (bsig_norm($mention) !== bsig_norm($mentionAttendue)) { $flash = 'Merci de recopier exactement la mention : « ' . $mentionAttendue . ' ».'; }
    elseif ($dateMain === '')                          { $flash = 'Merci d\'inscrire la date à la main.'; }
    elseif (strncmp($sigData, 'data:image', 10) !== 0) { $flash = 'Merci de signer dans le cadre avec votre doigt.'; }
    else {
        $photoToSave = ($photoOk && strncmp($photoData, 'data:image', 10) === 0) ? $photoData : null;
        $res = bsig_sign($pdo, $token, $nom, bsig_client_ip(), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''), $sigData, $photoToSave);
        if (!empty($res['ok'])) {
            // Trace la mention manuscrite + date (best-effort : ne casse pas si la colonne n'existe pas).
            try {
                $pdo->prepare("UPDATE bail_signatures SET mention_manuscrite = ? WHERE id = ?")
                    ->execute([mb_substr($mention . ' — ' . $dateMain, 0, 255), (int)$sig['id']]);
            } catch (Throwable $e) { /* colonne absente → ignoré */ }
            $justSigned = true; $sig = bsig_get_by_token($pdo, $token); $dejaSigne = true;
        } else { $flash = $res['error'] ?? 'Erreur lors de la signature.'; }
    }
}

// Rendu du BAIL COMPLET (même générateur que le PDF), avec les tracés déjà signés incrustés.
$bailHtml = '';
try {
    $ctx = bail_commercial_pdf_context($pdo, (int)$sig['id_bail']);
    if ($ctx) { $ctx['signatures'] = bsig_list_for_bail($pdo, (int)$sig['id_bail']); $bailHtml = bail_commercial_corps_fnaim($ctx); }
} catch (Throwable $e) { $bailHtml = ''; }

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$eur = static fn($v) => $v !== null && $v !== '' ? number_format((float)$v, 0, ',', ' ') . ' €' : '—';
$fmtDate = static fn($v) => $v ? date('d/m/Y', strtotime((string)$v)) : '—';
$adresse = trim(($sig['bien_adresse'] ?? '') . ' ' . ($sig['bien_cp'] ?? '') . ' ' . ($sig['bien_ville'] ?? ''));
$preneur = $sig['locataire_raison_sociale'] ?: trim((string)($sig['locataire_prenom'] ?? '') . ' ' . ($sig['locataire_nom'] ?? ''));
$roleLbl = $isCaution ? 'la caution (garant)' : 'le preneur';
$loyerA = ($sig['loyer_mensuel_hc'] ?? null) !== null ? (float)$sig['loyer_mensuel_hc'] * 12 : null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Signature du bail commercial</title>
<style>
  *{box-sizing:border-box;}
  body{margin:0;font-family:'Segoe UI',system-ui,sans-serif;background:#f1f5f9;color:#1f2937;}
  .wrap{max-width:720px;margin:0 auto;padding:20px 14px 70px;}
  .card{background:#fff;border-radius:16px;box-shadow:0 8px 30px rgba(0,0,0,.08);padding:24px 26px;margin-top:16px;}
  h1{font-size:22px;margin:0 0 4px;}
  .sub{color:#64748b;font-size:13px;}
  .terms{margin:18px 0;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;}
  .terms .row{display:flex;justify-content:space-between;padding:9px 14px;border-bottom:1px solid #f1f5f9;font-size:14px;gap:14px;}
  .terms .row:last-child{border-bottom:0;}
  .terms .k{color:#64748b;} .terms .v{font-weight:700;text-align:right;}
  .ok-banner{background:linear-gradient(135deg,#e9f7ef,#d7f0e0);border:1px solid #9ad3ab;color:#0b6b35;border-radius:12px;padding:18px 20px;}
  .exp-banner{background:#fef3c7;border:1px solid #f0d38a;color:#8a5a00;border-radius:12px;padding:18px 20px;}
  .err{background:#fde2e1;border:1px solid #f3b4b1;color:#a11;border-radius:10px;padding:10px 14px;margin-bottom:14px;font-size:14px;}
  label.fld{display:block;font-size:12px;font-weight:700;color:#475569;margin:14px 0 6px;text-transform:uppercase;letter-spacing:.05em;}
  input[type=text]{width:100%;padding:12px 14px;border:1px solid #cbd5e1;border-radius:10px;font-size:15px;}
  .chk{display:flex;gap:10px;align-items:flex-start;margin:14px 0;font-size:14px;}
  .chk input{margin-top:3px;transform:scale(1.3);}
  .btn{width:100%;margin-top:18px;padding:15px;border:none;border-radius:12px;background:linear-gradient(135deg,#5f8f93,#84A7AB);color:#fff;font-size:16px;font-weight:800;cursor:pointer;}
  .btn-sec{background:#eef2f6;color:#334155;font-weight:700;font-size:13px;padding:8px 14px;border:none;border-radius:9px;cursor:pointer;}
  .legal{font-size:11px;color:#94a3b8;margin-top:14px;line-height:1.5;}
  .proof{font-size:12px;color:#475569;margin-top:10px;}
  /* Bail complet repliable */
  details.bailbox{margin:16px 0;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;}
  details.bailbox>summary{cursor:pointer;padding:12px 16px;background:#eef2f6;font-weight:800;color:#243B5C;font-size:14px;list-style:none;}
  details.bailbox>summary::-webkit-details-marker{display:none;}
  .bailfull{max-height:52vh;overflow:auto;padding:16px 18px;background:#fff;font-family:'Times New Roman',Times,serif;font-size:13px;line-height:1.5;color:#1c2226;}
  .bailfull h1{font-size:18px;text-align:center;margin:0 0 4px;}
  .bailfull .sub,.bailfull .ref{text-align:center;font-size:11px;color:#777;font-style:italic;margin:0 0 6px;}
  .bailfull h2{font-size:13px;color:#2c4b4d;border-bottom:1px solid #cddcdc;padding-bottom:2px;margin:12px 0 4px;}
  .bailfull h3{font-size:12px;color:#243B5C;background:#eef2f6;padding:3px 7px;margin:10px 0 4px;}
  .bailfull p{margin:3px 0 6px;text-align:justify;}
  .bailfull .clabel{font-weight:bold;color:#2c4b4d;margin:6px 0 2px;}
  .bailfull ul{margin:3px 0 8px;padding-left:18px;} .bailfull li{margin:2px 0;text-align:justify;}
  .bailfull .tbl{width:100%;border-collapse:collapse;font-size:11px;margin:4px 0 10px;}
  .bailfull .tbl th{background:#e3ecec;text-align:left;padding:5px 7px;border:.5px solid #b9cccc;}
  .bailfull .tbl td{padding:4px 7px;border:.5px solid #cddada;}
  .bailfull img{max-width:100%;}
  /* Pavé de signature */
  .padwrap{margin-top:8px;}
  .pad{width:100%;height:200px;border:2px dashed #94a3b8;border-radius:12px;background:#fff;touch-action:none;display:block;}
  .padrow{display:flex;justify-content:space-between;align-items:center;margin-top:8px;}
  .photo-note{font-size:11px;color:#64748b;margin-top:4px;}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>🔑 Bail commercial</h1>
    <div class="sub"><?= $h($sig['numero_bail'] ?: '') ?> · <?= $h($sig['designation'] ?: $sig['reference_bien']) ?> · Vous signez en tant que <strong><?= $h($roleLbl) ?></strong></div>

    <div class="terms">
      <div class="row"><span class="k">Local</span><span class="v"><?= $h($sig['reference_bien']) ?></span></div>
      <?php if ($adresse !== ''): ?><div class="row"><span class="k">Adresse</span><span class="v"><?= $h($adresse) ?></span></div><?php endif; ?>
      <div class="row"><span class="k">Preneur</span><span class="v"><?= $h($preneur ?: '—') ?></span></div>
      <div class="row"><span class="k">Loyer annuel HT</span><span class="v"><?= $h($eur($loyerA)) ?></span></div>
      <div class="row"><span class="k">Charges / mois</span><span class="v"><?= $h($eur($sig['charges_mensuelles'] ?? null)) ?></span></div>
      <div class="row"><span class="k">Prise d'effet</span><span class="v"><?= $h($fmtDate($sig['date_prise_effet'] ?? null)) ?></span></div>
    </div>

    <?php if ($bailHtml !== ''): ?>
      <details class="bailbox">
        <summary>📄 Lire le bail complet avant de signer</summary>
        <div class="bailfull"><?= $bailHtml /* HTML généré côté serveur, mêmes classes que le PDF */ ?></div>
      </details>
    <?php endif; ?>

    <?php if ($dejaSigne): ?>
      <div class="ok-banner">
        <strong>✅ Bail signé.</strong><br>
        Signé par <strong><?= $h($sig['nom_signataire']) ?></strong> le <?= $h(date('d/m/Y à H:i', strtotime((string)$sig['signed_at']))) ?>.
        <div class="proof">Preuve enregistrée — IP : <?= $h($sig['ip'] ?: '—') ?> · horodatage : <?= $h($sig['signed_at']) ?>.</div>
      </div>
      <p class="legal">Une copie de ce bail signé est conservée par votre agence et vous est adressée par email dès que toutes les parties ont signé. Vous pouvez fermer cette page.</p>

    <?php elseif ($expired): ?>
      <div class="exp-banner">
        <strong>⏱️ Lien expiré.</strong><br>
        Ce lien de signature a dépassé sa durée de validité (<?= (int)BSIG_TTL_MIN ?> minutes). Merci de contacter votre agence pour recevoir un nouveau lien.
      </div>

    <?php else: ?>
      <?php if ($flash): ?><div class="err"><?= $h($flash) ?></div><?php endif; ?>
      <form method="post" id="sigform">
        <input type="hidden" name="t" value="<?= $h($token) ?>">
        <input type="hidden" name="signature_data" id="signature_data">
        <input type="hidden" name="photo_data" id="photo_data">

        <label class="fld">Votre nom et prénom</label>
        <input type="text" name="nom_signataire" value="<?= $h($_POST['nom_signataire'] ?? '') ?>" placeholder="Ex. Jean Dupont" required>

        <label class="chk">
          <input type="checkbox" name="lu_approuve" value="1" required>
          <span>J'ai lu l'intégralité du bail commercial et de ses annexes ci-dessus et j'en accepte les termes. Ma signature électronique a valeur d'engagement.</span>
        </label>

        <label class="fld">Recopiez la mention : « <?= $h($mentionAttendue) ?> »</label>
        <input type="text" name="mention_manuscrite" value="<?= $h($_POST['mention_manuscrite'] ?? '') ?>" placeholder="<?= $h($mentionAttendue) ?>" required>

        <label class="fld">Date (inscrite de votre main)</label>
        <input type="text" name="date_manuscrite" value="<?= $h($_POST['date_manuscrite'] ?? '') ?>" placeholder="Ex. <?= date('d/m/Y') ?>" required>

        <label class="fld">Signature (avec votre doigt)</label>
        <div class="padwrap">
          <canvas class="pad" id="pad"></canvas>
          <div class="padrow">
            <span class="photo-note">Signez dans le cadre ci-dessus.</span>
            <button type="button" class="btn-sec" id="pad-clear">Effacer</button>
          </div>
        </div>

        <label class="chk">
          <input type="checkbox" id="photo_consent" name="photo_consent" value="1">
          <span>J'accepte de joindre une photo de moi comme preuve de signature.
            <span class="photo-note">Cette photo sert <strong>uniquement de preuve de signature</strong> et ne sera <strong>jamais diffusée</strong> ni transmise à des tiers.</span></span>
        </label>
        <div id="photo-zone" style="display:none;">
          <video id="photo-video" playsinline autoplay muted style="width:100%;max-width:320px;border-radius:10px;background:#000;display:none;"></video>
          <canvas id="photo-canvas" style="display:none;"></canvas>
          <img id="photo-thumb" alt="" style="width:120px;border-radius:10px;display:none;border:1px solid #cbd5e1;">
          <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
            <button type="button" class="btn-sec" id="photo-start">📷 Activer la caméra</button>
            <button type="button" class="btn-sec" id="photo-snap" style="display:none;">📸 Capturer</button>
            <button type="button" class="btn-sec" id="photo-retake" style="display:none;">↻ Reprendre</button>
          </div>
          <div class="photo-note" id="photo-status">La photo est prise <b>en direct</b> par la caméra (aucun fichier à charger).</div>
        </div>

        <button class="btn" type="submit">✍️ Signer le bail</button>
        <p class="legal">
          Signature électronique (procédé simple, art. 1366-1367 du Code civil) : votre adresse IP (<?= $h(bsig_client_ip() ?: 'non détectée') ?>),
          l'horodatage, la mention recopiée et le tracé de votre signature sont enregistrés comme preuve. Lien valable <?= (int)BSIG_TTL_MIN ?> minutes.
        </p>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!$dejaSigne && !$expired): ?>
<script>
(function(){
  // ── Pavé de signature (canvas, tactile + souris) ──
  var pad = document.getElementById('pad');
  var hidden = document.getElementById('signature_data');
  var ctx = pad.getContext('2d');
  var drawing = false, hasDrawn = false, last = null;
  function fit(){
    var r = pad.getBoundingClientRect();
    var dpr = window.devicePixelRatio || 1;
    // Sauvegarde le tracé courant avant redimensionnement
    var prev = hasDrawn ? pad.toDataURL() : null;
    pad.width = Math.round(r.width * dpr);
    pad.height = Math.round(r.height * dpr);
    ctx.setTransform(dpr,0,0,dpr,0,0);
    ctx.lineWidth = 2.2; ctx.lineCap='round'; ctx.lineJoin='round'; ctx.strokeStyle='#111827';
    if (prev){ var img=new Image(); img.onload=function(){ ctx.drawImage(img,0,0,r.width,r.height); }; img.src=prev; }
  }
  function pos(e){
    var r = pad.getBoundingClientRect();
    var t = e.touches ? e.touches[0] : e;
    return { x: t.clientX - r.left, y: t.clientY - r.top };
  }
  function start(e){ drawing=true; last=pos(e); e.preventDefault(); }
  function move(e){
    if(!drawing) return;
    var p = pos(e);
    ctx.beginPath(); ctx.moveTo(last.x,last.y); ctx.lineTo(p.x,p.y); ctx.stroke();
    last = p; hasDrawn = true; e.preventDefault();
  }
  function end(){ drawing=false; }
  pad.addEventListener('mousedown',start); pad.addEventListener('mousemove',move);
  window.addEventListener('mouseup',end);
  pad.addEventListener('touchstart',start,{passive:false});
  pad.addEventListener('touchmove',move,{passive:false});
  pad.addEventListener('touchend',end);
  document.getElementById('pad-clear').addEventListener('click',function(){
    ctx.clearRect(0,0,pad.width,pad.height); hasDrawn=false; hidden.value='';
  });
  window.addEventListener('resize',fit); fit();

  // ── Photo-preuve : capture INSTANTANÉE par la caméra (getUserMedia), aucun fichier uploadé ──
  var consent = document.getElementById('photo_consent');
  var zone = document.getElementById('photo-zone');
  var status = document.getElementById('photo-status');
  var photoHidden = document.getElementById('photo_data');
  var video = document.getElementById('photo-video');
  var pcanvas = document.getElementById('photo-canvas');
  var thumb = document.getElementById('photo-thumb');
  var btnStart = document.getElementById('photo-start');
  var btnSnap = document.getElementById('photo-snap');
  var btnRetake = document.getElementById('photo-retake');
  var pstream = null;
  function photoStop(){ if(pstream){ pstream.getTracks().forEach(function(t){ t.stop(); }); pstream=null; } }
  function photoReset(){
    photoStop(); photoHidden.value='';
    video.style.display='none'; thumb.style.display='none';
    btnSnap.style.display='none'; btnRetake.style.display='none';
    btnStart.style.display='inline-block';
  }
  consent.addEventListener('change', function(){
    zone.style.display = consent.checked ? 'block' : 'none';
    if(!consent.checked){ photoReset(); status.textContent=''; }
    else { status.textContent='La photo est prise en direct par la caméra (aucun fichier à charger).'; }
  });
  btnStart.addEventListener('click', function(){
    if(!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia){
      status.textContent='Caméra non disponible sur cet appareil/navigateur.'; return;
    }
    navigator.mediaDevices.getUserMedia({ video:{ facingMode:'user' }, audio:false }).then(function(s){
      pstream=s; video.srcObject=s; video.style.display='block';
      var p=video.play(); if(p&&p.catch) p.catch(function(){});
      btnStart.style.display='none'; btnSnap.style.display='inline-block';
      thumb.style.display='none'; btnRetake.style.display='none';
      status.textContent='Cadrez votre visage, puis « Capturer ».';
    }).catch(function(){ status.textContent='Accès caméra refusé. Autorisez la caméra pour la preuve photo.'; });
  });
  btnSnap.addEventListener('click', function(){
    if(!pstream) return;
    var w=video.videoWidth||320, h=video.videoHeight||240, mx=480, s=Math.min(1, mx/Math.max(w,h));
    pcanvas.width=Math.round(w*s); pcanvas.height=Math.round(h*s);
    pcanvas.getContext('2d').drawImage(video,0,0,pcanvas.width,pcanvas.height);
    photoHidden.value = pcanvas.toDataURL('image/jpeg',0.82);
    thumb.src = photoHidden.value; thumb.style.display='block';
    photoStop(); video.style.display='none';
    btnSnap.style.display='none'; btnRetake.style.display='inline-block';
    status.textContent='Photo prise en direct (preuve, non diffusée).';
  });
  btnRetake.addEventListener('click', function(){
    photoHidden.value=''; thumb.style.display='none'; btnRetake.style.display='none';
    btnStart.click();
  });

  // ── Soumission : fige le tracé dans le champ caché ──
  document.getElementById('sigform').addEventListener('submit', function(e){
    if(!hasDrawn){ e.preventDefault(); alert('Merci de signer dans le cadre avec votre doigt.'); return; }
    hidden.value = pad.toDataURL('image/png');
  });
})();
</script>
<?php endif; ?>
</body>
</html>
