<?php
/**
 * mon_specimen.php — MA SIGNATURE, MON PARAPHE, LE CACHET DE LA SOCIÉTÉ.
 *
 * On les enregistre UNE FOIS, ils sont réutilisés à chaque document signé. Trois objets
 * distincts, jamais une image unique : la personne porte sa signature et son paraphe, la
 * société porte son cachet. Les empiler dans un seul fichier condamnerait à tout refaire
 * au moindre changement de société, et interdirait de poser la mention ailleurs que
 * collée sous le tracé.
 *
 * ⚠️ La mention (« Bon pour accord », « Lu et approuvé ») n'est PAS ici : c'est un type
 * de zone, avec son texte, posé où on veut sur le document.
 *
 * ⚖️ CE QUE CE SPÉCIMEN PROUVE : RIEN, À LUI SEUL. Une image recollée sur un PDF n'est
 * pas une signature au sens de l'article 1367 al. 2 du Code civil — n'importe qui
 * disposant du fichier pourrait la reproduire. Elle est l'APPARENCE, ce que le
 * destinataire reconnaît. La PREUVE, c'est l'identification (compte, horodatage, IP,
 * empreinte du document), consignée dans les justificatifs joints à l'acte. L'écran le
 * dit à l'utilisateur : il ne doit pas croire détenir plus que ce qu'il a.
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/csrf.php';
require_login();

$pdo    = $GLOBALS['pdo'];
$h      = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$userId = function_exists('current_user_id') ? (int)current_user_id() : 0;
$socId  = (int)($_SESSION['id_societe'] ?? 0);

/* Les spécimens ACTIFS — l'écran montre ce qui sera réellement apposé. */
$actifs = ['signature'=>null, 'paraphe'=>null, 'cachet'=>null];
$erreur = '';
try {
    $st = $pdo->prepare("SELECT type, image_data, largeur_px, hauteur_px, created_at
                           FROM signature_specimens
                          WHERE actif = 1
                            AND ((proprietaire_type='user' AND proprietaire_id=?)
                              OR (proprietaire_type='societe' AND proprietaire_id=?))
                          ORDER BY id DESC");
    $st->execute([$userId, $socId]);
    foreach ($st as $r) { if (array_key_exists($r['type'], $actifs) && !$actifs[$r['type']]) $actifs[$r['type']] = $r; }
} catch (Throwable $e) {
    $erreur = 'La table des spécimens est absente : rejouer la migration 20260823b_signature_specimens.';
}
$csrf = csrf_token('signature_specimen');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ma signature</title>
<style>
  *{box-sizing:border-box;}
  body{margin:0;font-family:'Sora',system-ui,sans-serif;background:#eef1f5;color:#243B5C;}
  .bar{background:#243B5C;color:#fff;padding:12px 20px;font-size:16px;font-weight:700;}
  .wrap{max-width:960px;margin:18px auto;padding:0 16px;display:flex;flex-direction:column;gap:16px;}
  .card{background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(36,59,92,.10);padding:16px 18px;}
  .card h2{font-size:13px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin:0 0 4px;}
  .card p.sub{font-size:12px;color:#8a8694;margin:0 0 12px;line-height:1.5;}
  .zone{display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;}
  canvas.pad{border:2px dashed #cbd5e1;border-radius:10px;background:#fff;touch-action:none;cursor:crosshair;}
  .actu{border:1px solid #e2e8f0;border-radius:10px;padding:8px;background:#f8fafc;min-width:210px;text-align:center;}
  .actu img{max-width:190px;max-height:80px;}
  .actu .q{font-size:11px;color:#8a8694;margin-top:5px;}
  .btns{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;}
  button,.fauxbtn{border:none;border-radius:9px;padding:8px 15px;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;}
  .ok{background:#15803d;color:#fff;} .sec{background:#fff;border:1.5px solid #cbd5e1;color:#334155;}
  .etat{font-size:12px;font-weight:700;margin-left:6px;}
  .avert{background:#fdf8ec;border-left:5px solid #d4a047;border-radius:10px;padding:13px 16px;font-size:12.5px;line-height:1.6;color:#6b5518;}
  .err{background:#fff;border-left:5px solid #b5352e;border-radius:10px;padding:16px;color:#8c2a24;font-size:14px;}
</style>
</head>
<body>
<div class="bar">✍️ Ma signature</div>
<div class="wrap">

<?php if ($erreur !== ''): ?>
  <div class="err"><strong>⛔ <?= $h($erreur) ?></strong></div>
<?php else: ?>

  <div class="avert">
    <b>Ce tracé est une apparence, pas une preuve.</b> Ce qui vaut signature électronique,
    c'est votre identification dans MaBoxImmo : votre compte, l'horodatage, votre adresse IP
    et l'empreinte du document signé — tout cela figure dans les justificatifs joints à chaque
    acte. Le tracé sert à ce que le destinataire vous reconnaisse, rien de plus.
  </div>

  <?php
  $blocs = [
    ['signature', '✍️ Ma signature',   'Tracez-la comme sur papier. Elle sera apposée dans les zones « Signature » qui vous sont attribuées.'],
    ['paraphe',   '🅿️ Mon paraphe',    'Vos initiales, pour les zones « Paraphe » — souvent une par page.'],
    ['cachet',    '🏢 Cachet de la société', 'Facultatif. Il appartient à la SOCIÉTÉ, pas à vous : il ne vous suivra pas si vous changez d\'établissement. Déposez une image PNG à fond transparent.'],
  ];
  foreach ($blocs as [$type, $titre, $sous]):
    $a = $actifs[$type] ?? null;
  ?>
  <div class="card" data-type="<?= $h($type) ?>">
    <h2><?= $h($titre) ?></h2>
    <p class="sub"><?= $sous ?></p>
    <div class="zone">
      <div>
        <canvas class="pad" width="420" height="130"></canvas>
        <div class="btns">
          <button type="button" class="ok js-save">💾 Enregistrer</button>
          <button type="button" class="sec js-clear">↺ Effacer</button>
          <label class="fauxbtn sec" style="display:inline-block;">
            📁 Déposer une image
            <input type="file" accept="image/png" class="js-file" hidden>
          </label>
          <span class="etat js-etat"></span>
        </div>
      </div>
      <div class="actu">
        <?php if ($a): ?>
          <img src="<?= $h($a['image_data']) ?>" alt="<?= $h($titre) ?>">
          <div class="q">Enregistré le <?= $h(date('d/m/Y \à H\hi', strtotime((string)$a['created_at']))) ?></div>
        <?php else: ?>
          <div class="q" style="padding:26px 4px;">Aucun spécimen enregistré</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

<?php endif; ?>
</div>

<script>
var CSRF = <?= json_encode($csrf) ?>;
var URL_SAVE = <?= json_encode(app_url('/api/signature_specimen_save.php')) ?>;

document.querySelectorAll('.card[data-type]').forEach(function(card){
  var type = card.dataset.type;
  var cv   = card.querySelector('canvas.pad');
  var ctx  = cv.getContext('2d');
  var etat = card.querySelector('.js-etat');
  var vide = true;

  ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#16233c';

  /* Le tracé suit le POINTEUR, doigt compris : `pointerdown/move/up` couvre souris,
     stylet et tactile d'un seul jeu d'événements — une signature se fait souvent au
     doigt, sur une tablette posée devant le client. */
  var dessine = false, px = 0, py = 0;
  function pos(e){ var r = cv.getBoundingClientRect(); return [e.clientX - r.left, e.clientY - r.top]; }
  cv.addEventListener('pointerdown', function(e){
    dessine = true; vide = false; cv.setPointerCapture(e.pointerId);
    var p = pos(e); px = p[0]; py = p[1];
  });
  cv.addEventListener('pointermove', function(e){
    if (!dessine) return;
    var p = pos(e);
    ctx.beginPath(); ctx.moveTo(px, py); ctx.lineTo(p[0], p[1]); ctx.stroke();
    px = p[0]; py = p[1];
  });
  ['pointerup','pointercancel','pointerleave'].forEach(function(ev){
    cv.addEventListener(ev, function(){ dessine = false; });
  });

  card.querySelector('.js-clear').addEventListener('click', function(){
    ctx.clearRect(0, 0, cv.width, cv.height); vide = true; dire('', '');
  });

  function dire(txt, couleur){ etat.textContent = txt; etat.style.color = couleur || '#6b7280'; }

  /* Une image déposée est dessinée DANS le canvas : le reste du parcours — recadrage,
     enregistrement — est alors strictement identique à un tracé. Un seul chemin de code,
     donc un seul comportement à vérifier. */
  card.querySelector('.js-file').addEventListener('change', function(e){
    var f = e.target.files && e.target.files[0]; if (!f) return;
    if (f.type !== 'image/png') { dire('PNG uniquement (fond transparent).', '#b5352e'); return; }
    var img = new Image();
    img.onload = function(){
      ctx.clearRect(0, 0, cv.width, cv.height);
      var k = Math.min(cv.width / img.width, cv.height / img.height, 1);
      ctx.drawImage(img, (cv.width - img.width * k) / 2, (cv.height - img.height * k) / 2, img.width * k, img.height * k);
      vide = false; dire('Image chargée — pensez à enregistrer.', '#8a6d1b');
    };
    img.onerror = function(){ dire('Image illisible.', '#b5352e'); };
    img.src = URL.createObjectURL(f);
  });

  card.querySelector('.js-save').addEventListener('click', function(){
    if (vide) { dire('Rien à enregistrer : tracez d\'abord.', '#b5352e'); return; }
    /* ⚠️ On RECADRE sur le tracé avant d'envoyer. Sans ça on enregistrerait un PNG de
       420×130 dont l'essentiel est transparent : apposé dans une zone, le tracé
       apparaîtrait minuscule et perdu au milieu du vide, alors que la zone semblait
       bien dimensionnée à l'écran. */
    var d = ctx.getImageData(0, 0, cv.width, cv.height).data;
    var x0 = cv.width, y0 = cv.height, x1 = -1, y1 = -1;
    for (var y = 0; y < cv.height; y++) {
      for (var x = 0; x < cv.width; x++) {
        if (d[(y * cv.width + x) * 4 + 3] > 12) {
          if (x < x0) x0 = x; if (x > x1) x1 = x;
          if (y < y0) y0 = y; if (y > y1) y1 = y;
        }
      }
    }
    if (x1 < 0) { dire('Le tracé est vide.', '#b5352e'); return; }
    var mar = 6;
    x0 = Math.max(0, x0 - mar); y0 = Math.max(0, y0 - mar);
    x1 = Math.min(cv.width - 1, x1 + mar); y1 = Math.min(cv.height - 1, y1 + mar);
    var out = document.createElement('canvas');
    out.width = x1 - x0 + 1; out.height = y1 - y0 + 1;
    out.getContext('2d').drawImage(cv, x0, y0, out.width, out.height, 0, 0, out.width, out.height);

    var btn = this, old = btn.textContent; btn.disabled = true; btn.textContent = '⏳…';
    fetch(URL_SAVE, {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json', 'X-CSRF-Token': CSRF},
      body: JSON.stringify({type: type, image: out.toDataURL('image/png')})
    }).then(function(r){ return r.text(); }).then(function(brut){
      btn.disabled = false; btn.textContent = old;
      var j = null; try { j = JSON.parse(brut); } catch(e){}
      if (!j)    { dire('Réponse inattendue du serveur.', '#b5352e'); return; }
      if (!j.ok) { dire(j.error || 'Échec.', '#b5352e'); return; }
      dire('✅ ' + j.message, '#166534');
      var apercu = card.querySelector('.actu');
      apercu.innerHTML = '<img alt=""><div class="q">Enregistré à l\'instant</div>';
      apercu.querySelector('img').src = out.toDataURL('image/png');
    }).catch(function(e){ btn.disabled = false; btn.textContent = old; dire('Réseau : ' + e, '#b5352e'); });
  });
});
</script>
</body>
</html>
