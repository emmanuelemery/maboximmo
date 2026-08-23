<?php
/**
 * p/doc_signature.php — LA PAGE DU SIGNATAIRE, pour un document de la GED.
 *
 * URL : /p/doc_signature.php?t=<jeton>
 *
 * Pendant exact de `p/bail_signature.php`, mais pour une pièce quelconque : on n'affiche
 * pas un acte reconstruit, on affiche LE PDF tel qu'il a été envoyé, et le signataire
 * remplit les zones qui lui reviennent.
 *
 * ── TROIS RÈGLES VENUES D'EMMANUEL (23/08/2026) ────────────────────────────────────
 * 1. TOUT LE DOCUMENT EST VISIBLE. On ne signe pas ce qu'on n'a pas pu lire — et un
 *    signataire à qui l'on ne montrerait que ses cases pourrait faire tomber son
 *    engagement en disant qu'il n'a jamais vu le reste. Ses zones sont surlignées et
 *    « Zone suivante » l'y amène, mais rien n'est masqué.
 * 2. IL VOIT SA SIGNATURE IMMÉDIATEMENT. Sans retour visible, il croit que ça n'a pas
 *    marché : il recommence, ou il appelle. Le tracé est donc dessiné sur le document
 *    dès qu'il l'a posé, et rechargé depuis la base s'il revient.
 * 3. L'APPOSITION N'A PAS LIEU ICI. Elle attend la dernière signature : réassembler le
 *    PDF à chaque passage en changerait l'empreinte, alors que c'est elle qui prouve que
 *    le document n'a pas bougé entre deux signatures.
 *
 * ⚠️ Le PDF est servi par `api/ged_doc_serve.php` avec le JETON — la page est publique,
 * il n'y a pas de session. C'est la cage GED qui décide, jamais une URL directe.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/bail_signature.php';

$pdo   = $GLOBALS['pdo'];
$h     = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$token = (string)($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{32,128}$/i', $token)) { http_response_code(400); exit('Lien invalide.'); }

$sig = bsig_get_by_token($pdo, $token);
if (!$sig) { http_response_code(404); exit('Ce lien n\'existe pas.'); }

$dejaSigne = (string)($sig['statut'] ?? '') === 'signe';
$expire    = bsig_is_expired($sig);
$docId     = (int)($sig['objet_id'] ?? 0);
$role      = (string)($sig['role_code'] ?? '');
$nomSig    = trim((string)($sig['nom_signataire'] ?? ''));

/* La porte : une seule variable porte la raison, et elle sert à refuser ET à l'expliquer.
   C'est la leçon du 22/08 — un écran qui bloque sans rien dire est un écran cassé. */
$porte = null;
if ((string)($sig['objet_type'] ?? '') !== 'ged') $porte = 'Ce lien ne porte pas sur un document.';
elseif ($expire)  $porte = 'Ce lien de signature a dépassé sa durée de validité (' . (int)(BSIG_TTL_MIN / 60)
                         . ' heures). Votre agence peut le réactiver en un clic — le même lien redeviendra valable.';

$nomDoc = ''; $zones = []; $deja = [];
if ($porte === null) {
    try {
        $st = $pdo->prepare("SELECT name_display FROM ged_documents WHERE id = ? LIMIT 1");
        $st->execute([$docId]);
        $nomDoc = (string)($st->fetchColumn() ?: 'Document');

        $st = $pdo->prepare("SELECT id, page, x, y, w, h, type, role_code, libelle
                               FROM signature_zones WHERE ged_document_id = ? ORDER BY page, ordre, id");
        $st->execute([$docId]);
        $zones = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Ce qui est DÉJÀ rempli, par tout le monde : le document se lit tel qu'il est.
        $st = $pdo->prepare("SELECT v.id_zone, v.valeur_texte, v.valeur_image, v.id_signature
                               FROM signature_zone_valeurs v
                               JOIN signature_zones z ON z.id = v.id_zone
                              WHERE z.ged_document_id = ?");
        $st->execute([$docId]);
        foreach ($st as $r) { $deja[(int)$r['id_zone']] = $r; }
    } catch (Throwable $e) {
        $porte = 'Ce document n\'est pas lisible pour le moment. Merci de contacter votre agence.';
        error_log('[doc_signature] ' . $e->getMessage());
    }
}
if ($porte === null && !$zones) $porte = 'Aucune zone n\'a été préparée sur ce document.';

$mesZones = array_values(array_filter($zones, static fn($z) => (string)$z['role_code'] === $role));
if ($porte === null && !$mesZones) $porte = 'Aucune zone ne vous est attribuée sur ce document.';

$urlPdf = app_url('/api/ged_doc_serve.php?id=' . $docId . '&t=' . urlencode($token));
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Signature — <?= $h($nomDoc ?: 'document') ?></title>
<style>
  *{box-sizing:border-box;}
  body{margin:0;font-family:'Sora',system-ui,-apple-system,sans-serif;background:#eef1f5;color:#243B5C;}
  .bar{position:sticky;top:0;z-index:50;background:#243B5C;color:#fff;padding:10px 16px;
       display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
  .bar b{font-size:15px;flex:1;min-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .bar button{border:none;border-radius:9px;padding:9px 15px;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;}
  .suiv{background:#ffffff22;color:#fff;} .valider{background:#15803d;color:#fff;}
  .pages{display:flex;flex-direction:column;gap:16px;align-items:center;padding:16px 8px 60px;}
  .page{position:relative;box-shadow:0 4px 18px rgba(36,59,92,.18);background:#fff;line-height:0;}
  .layer{position:absolute;inset:0;}
  /* Une zone à MOI appelle le geste : bordure vive et fond ambré. Une zone déjà remplie
     par quelqu'un d'autre reste visible mais éteinte — on voit que le document avance. */
  .z{position:absolute;border-radius:3px;line-height:1.15;font-size:11px;overflow:hidden;}
  .z.mien{border:2px dashed #d4a047;background:rgba(212,160,71,.20);cursor:pointer;
          display:flex;align-items:center;justify-content:center;font-weight:800;color:#6b5518;text-align:center;}
  .z.mien.fait{border-style:solid;border-color:#15803d;background:rgba(21,128,61,.10);cursor:default;}
  .z.autre{border:1px dotted #b9c3d1;background:rgba(185,195,209,.12);}
  .z img{width:100%;height:100%;object-fit:contain;}
  .z .txt{width:100%;padding:1px 3px;color:#101418;font-weight:600;font-size:12px;text-align:left;}
  .msg{max-width:620px;margin:44px auto;background:#fff;border-radius:12px;padding:22px 26px;
       box-shadow:0 2px 12px rgba(36,59,92,.10);font-size:14px;line-height:1.65;}
  .msg h1{font-size:19px;margin:0 0 10px;}
  .modal{position:fixed;inset:0;background:rgba(20,28,42,.6);display:none;align-items:center;justify-content:center;z-index:99;padding:14px;}
  .modal.on{display:flex;}
  .box{background:#fff;border-radius:14px;padding:18px;max-width:520px;width:100%;}
  .box h2{font-size:16px;margin:0 0 4px;} .box p{font-size:12.5px;color:#6b7280;margin:0 0 12px;}
  canvas.pad{border:2px dashed #cbd5e1;border-radius:10px;width:100%;height:150px;touch-action:none;background:#fff;}
  input.tx{width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:9px;font-size:15px;font-family:inherit;}
  .box .btns{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;}
  .avert{max-width:900px;margin:10px auto 0;background:#fdf8ec;border-left:5px solid #d4a047;
         border-radius:10px;padding:11px 15px;font-size:12.5px;line-height:1.6;color:#6b5518;}
</style>
</head>
<body>

<?php if ($porte !== null): ?>
  <div class="msg"><h1>⏱️ <?= $h($nomDoc ?: 'Document') ?></h1><p><?= $h($porte) ?></p></div>
<?php elseif ($dejaSigne): ?>
  <div class="msg"><h1>✅ C'est signé</h1>
    <p>Vous avez signé <b><?= $h($nomDoc) ?></b><?= !empty($sig['signed_at']) ? ' le <b>' . $h(date('d/m/Y \à H\hi', strtotime((string)$sig['signed_at']))) . '</b>' : '' ?>.</p>
    <p>Dès que toutes les parties auront signé, le document définitif — signatures et justificatifs
    réunis — sera adressé par mail à chacune d'elles. Vous n'avez rien d'autre à faire.</p></div>
<?php else: ?>

<div class="bar">
  <b><?= $h($nomDoc) ?></b>
  <span id="reste" style="font-size:12.5px;opacity:.9;"></span>
  <button type="button" class="suiv" id="btnSuiv">↓ Zone suivante</button>
  <button type="button" class="valider" id="btnValider">✅ Valider ma signature</button>
</div>
<div class="avert">
  <b>Lisez l'intégralité du document avant de signer.</b> Vos emplacements sont encadrés en orange ;
  « Zone suivante » vous y conduit, mais rien n'est masqué — vous pouvez tout parcourir.
</div>
<div class="pages" id="pages"><div style="padding:40px;color:#6b7280;font-size:13px;">⏳ Chargement du document…</div></div>

<div class="modal" id="modal"><div class="box">
  <h2 id="mTitre">Signature</h2>
  <p id="mAide"></p>
  <canvas class="pad" id="pad" width="460" height="150"></canvas>
  <input type="text" class="tx" id="tx" style="display:none;">
  <div class="btns">
    <button type="button" class="valider" id="mOk">Valider</button>
    <button type="button" class="suiv" id="mClear" style="background:#eef1f5;color:#334155;">↺ Effacer</button>
    <button type="button" class="suiv" id="mNon" style="background:#eef1f5;color:#334155;">Annuler</button>
  </div>
</div></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
var TOKEN = <?= json_encode($token) ?>;
var ZONES = <?= json_encode($zones, JSON_UNESCAPED_UNICODE) ?>;
var DEJA  = <?= json_encode($deja, JSON_UNESCAPED_UNICODE) ?>;
var ROLE  = <?= json_encode($role) ?>;
var NOM   = <?= json_encode($nomSig, JSON_UNESCAPED_UNICODE) ?>;
var URL_SAVE = <?= json_encode(app_url('/api/signature_zone_valeur_save.php')) ?>;
var LBL = {signature:'Votre signature', paraphe:'Vos initiales', date:'La date', texte:'Le texte', case:'Cochez'};

var valeurs = {};    // ce que JE viens de remplir, en attente de validation
var elZone  = {};    // id_zone → élément à l'écran

function estMien(z){ return String(z.role_code || '') === ROLE; }
function majReste(){
  var t = ZONES.filter(estMien).length, f = Object.keys(valeurs).length;
  document.getElementById('reste').textContent = f + ' / ' + t + ' rempli(s)';
}

/* Dessiner le contenu d'une zone : ce que je viens de poser, ou ce qui était déjà là.
   Une seule fonction pour les deux — l'écran ne peut pas montrer autre chose que ce qui
   sera apposé. */
function peindre(z){
  var el = elZone[z.id]; if (!el) return;
  var v = valeurs[z.id] || DEJA[z.id] || DEJA[String(z.id)];
  el.innerHTML = '';
  if (v && (v.image || v.valeur_image)) {
    var im = document.createElement('img'); im.src = v.image || v.valeur_image; el.appendChild(im);
    el.classList.add('fait');
  } else if (v && (v.texte || v.valeur_texte)) {
    var s = document.createElement('div'); s.className = 'txt';
    s.textContent = v.texte || v.valeur_texte; el.appendChild(s);
    el.classList.add('fait');
  } else if (estMien(z)) {
    el.textContent = LBL[z.type] || z.type;
  }
  majReste();
}

/* ── La saisie ────────────────────────────────────────────────────────────────── */
var modal = document.getElementById('modal'), pad = document.getElementById('pad');
var ctx = pad.getContext('2d'), tx = document.getElementById('tx'), zCourante = null, vide = true;
ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#16233c';
var dess = false, lx = 0, ly = 0;
function pt(e){ var r = pad.getBoundingClientRect(); return [(e.clientX - r.left) * pad.width / r.width, (e.clientY - r.top) * pad.height / r.height]; }
pad.addEventListener('pointerdown', function(e){ dess = true; vide = false; pad.setPointerCapture(e.pointerId); var p = pt(e); lx = p[0]; ly = p[1]; });
pad.addEventListener('pointermove', function(e){ if (!dess) return; var p = pt(e); ctx.beginPath(); ctx.moveTo(lx, ly); ctx.lineTo(p[0], p[1]); ctx.stroke(); lx = p[0]; ly = p[1]; });
['pointerup','pointercancel','pointerleave'].forEach(function(ev){ pad.addEventListener(ev, function(){ dess = false; }); });
document.getElementById('mClear').addEventListener('click', function(){ ctx.clearRect(0,0,pad.width,pad.height); vide = true; });
document.getElementById('mNon').addEventListener('click', function(){ modal.classList.remove('on'); zCourante = null; });

function ouvrir(z){
  zCourante = z; vide = true;
  ctx.clearRect(0, 0, pad.width, pad.height);
  var dessin = (z.type === 'signature' || z.type === 'paraphe');
  pad.style.display = dessin ? '' : 'none';
  tx.style.display  = dessin ? 'none' : '';
  document.getElementById('mTitre').textContent = z.libelle || LBL[z.type] || z.type;
  document.getElementById('mAide').textContent = dessin
    ? 'Tracez avec le doigt ou la souris.'
    : (z.type === 'date' ? 'La date du jour est proposée, corrigez-la si besoin.'
                         : 'Saisissez le texte demandé.');
  if (!dessin) {
    var v = valeurs[z.id];
    tx.value = v ? (v.texte || '') : (z.type === 'date' ? new Date().toLocaleDateString('fr-FR')
                                   : (z.type === 'case' ? 'X' : (z.libelle || '')));
  }
  modal.classList.add('on');
  if (!dessin) setTimeout(function(){ tx.focus(); }, 60);
}

document.getElementById('mOk').addEventListener('click', function(){
  if (!zCourante) return;
  var z = zCourante;
  if (z.type === 'signature' || z.type === 'paraphe') {
    if (vide) { alert('Tracez votre signature avant de valider.'); return; }
    /* Recadrage sur le tracé : sinon on enverrait un PNG presque vide, et la signature
       apparaîtrait minuscule au milieu de sa zone. */
    var d = ctx.getImageData(0, 0, pad.width, pad.height).data, x0 = pad.width, y0 = pad.height, x1 = -1, y1 = -1;
    for (var y = 0; y < pad.height; y++) for (var x = 0; x < pad.width; x++) {
      if (d[(y * pad.width + x) * 4 + 3] > 12) { if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; }
    }
    if (x1 < 0) { alert('Le tracé est vide.'); return; }
    var m = 6; x0 = Math.max(0, x0 - m); y0 = Math.max(0, y0 - m);
    x1 = Math.min(pad.width - 1, x1 + m); y1 = Math.min(pad.height - 1, y1 + m);
    var o = document.createElement('canvas'); o.width = x1 - x0 + 1; o.height = y1 - y0 + 1;
    o.getContext('2d').drawImage(pad, x0, y0, o.width, o.height, 0, 0, o.width, o.height);
    valeurs[z.id] = {image: o.toDataURL('image/png')};
  } else {
    var t = tx.value.trim();
    if (t === '') { alert('Merci de compléter ce champ.'); return; }
    valeurs[z.id] = {texte: t};
  }
  modal.classList.remove('on');
  peindre(z);           // ⚠️ IL VOIT SA SIGNATURE TOUT DE SUITE — c'est la règle n° 2
  zCourante = null;
});

/* ── Rendu du document ────────────────────────────────────────────────────────── */
pdfjsLib.getDocument(<?= json_encode($urlPdf) ?>).promise.then(function(pdf){
  var host = document.getElementById('pages'); host.innerHTML = '';
  var largeur = Math.min(920, host.clientWidth || 920);
  var chaine = Promise.resolve();
  for (var n = 1; n <= pdf.numPages; n++) (function(num){
    chaine = chaine.then(function(){
      return pdf.getPage(num).then(function(page){
        var v1 = page.getViewport({scale:1});
        var vp = page.getViewport({scale: largeur / v1.width});
        var box = document.createElement('div'); box.className = 'page';
        box.style.width = vp.width + 'px'; box.style.height = vp.height + 'px';
        var cv = document.createElement('canvas'); cv.width = vp.width; cv.height = vp.height;
        var layer = document.createElement('div'); layer.className = 'layer';
        box.appendChild(cv); box.appendChild(layer); host.appendChild(box);

        ZONES.filter(function(z){ return +z.page === num; }).forEach(function(z){
          var d = document.createElement('div');
          d.className = 'z ' + (estMien(z) ? 'mien' : 'autre');
          d.style.left = z.x + '%'; d.style.top = z.y + '%';
          d.style.width = z.w + '%'; d.style.height = z.h + '%';
          if (estMien(z)) d.addEventListener('click', function(){ ouvrir(z); });
          layer.appendChild(d); elZone[z.id] = d; peindre(z);
        });
        return page.render({canvasContext: cv.getContext('2d'), viewport: vp}).promise;
      });
    });
  })(n);
  return chaine.then(majReste);
}).catch(function(e){
  document.getElementById('pages').innerHTML =
    '<div class="msg">⛔ Le document n\'a pas pu être affiché. Merci de contacter votre agence.</div>';
});

/* « Zone suivante » : la première non remplie, sinon la suivante en tournant. */
var curseur = -1;
document.getElementById('btnSuiv').addEventListener('click', function(){
  var mien = ZONES.filter(estMien);
  if (!mien.length) return;
  var vise = mien.filter(function(z){ return !valeurs[z.id] && !DEJA[z.id]; });
  var cible;
  if (vise.length) cible = vise[0];
  else { curseur = (curseur + 1) % mien.length; cible = mien[curseur]; }
  var el = elZone[cible.id];
  if (el) { el.scrollIntoView({block:'center', behavior:'smooth'}); el.style.outline = '3px solid #d4a047';
            setTimeout(function(){ el.style.outline = ''; }, 1400); }
});

document.getElementById('btnValider').addEventListener('click', function(){
  var mien = ZONES.filter(estMien);
  var manque = mien.filter(function(z){ return !valeurs[z.id] && !DEJA[z.id]; });
  if (manque.length) {
    alert('Il reste ' + manque.length + ' emplacement(s) à remplir.\n\nUtilisez « Zone suivante » pour y aller.');
    return;
  }
  var nom = NOM || prompt('Votre nom et prénom :') || '';
  if (!nom.trim()) { alert('Merci d\'indiquer votre nom.'); return; }
  if (!confirm('Valider votre signature ?\n\nElle sera horodatée et tracée à des fins de preuve.')) return;
  var b = this, old = b.textContent; b.disabled = true; b.textContent = '⏳…';
  fetch(URL_SAVE, {method:'POST', headers:{'Content-Type':'application/json'},
    body: JSON.stringify({token: TOKEN, nom: nom, valeurs: valeurs})})
    .then(function(r){ return r.text(); }).then(function(brut){
      b.disabled = false; b.textContent = old;
      var j = null; try { j = JSON.parse(brut); } catch(e){}
      if (!j)    { alert('Réponse inattendue du serveur.'); return; }
      if (!j.ok) { alert('⛔ ' + (j.error || 'Échec.')); return; }
      alert('✅ ' + j.message);
      location.reload();
    }).catch(function(e){ b.disabled = false; b.textContent = old; alert('Réseau : ' + e); });
});
</script>
<?php endif; ?>
</body>
</html>
