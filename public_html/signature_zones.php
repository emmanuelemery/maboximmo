<?php
/**
 * signature_zones.php — L'ÉDITEUR DE ZONES : poser sur un PDF de la GED les
 * emplacements à remplir (signature, paraphe, date, texte, case), comme dans Adobe.
 *
 * URL : signature_zones.php?doc=<id GED>[&embed=1]
 *
 * ⚠️ LE DOCUMENT N'EST PAS RÉGÉNÉRÉ. On affiche le PDF réel, tel qu'il est en GED, via
 * pdf.js — le même moteur que `api/pdf_viewer.php`, à la même version. Ce qu'on voit ici
 * est exactement ce sur quoi les valeurs seront apposées : c'est la condition pour que
 * placer une signature à l'écran veuille dire quelque chose.
 *
 * ⚠️🔥 LES COORDONNÉES SONT EN POURCENTAGE DE LA PAGE, jamais en pixels d'écran.
 * pdf.js rend à une échelle qui dépend de la largeur disponible, du zoom du navigateur
 * et du DPI ; l'apposition finale, elle, travaillera en millimètres sur une page qui peut
 * être A4, Letter ou un scan de traviole. Le pourcentage est la seule unité qui survive
 * aux trois. Toute la conversion se fait ICI, à la création de la zone, et nulle part
 * ailleurs — l'écran manipule des pixels, la base ne connaît que des pourcentages.
 *
 * ⚠️ Le droit de poser des zones = le droit d'OUVRIR le document. On ne réinvente pas de
 * contrôle de périmètre : on demande la pièce à GedAccess, et si elle refuse il n'y a
 * rien à annoter. Le servage passe par `api/ged_doc_serve.php`, jamais par une URL
 * directe vers le fichier (cf. les 403 Hostinger sur les liens directs).
 *
 * `embed=1` retire le bandeau : la page est alors destinée à être ouverte dans le module
 * secondaire, depuis le bouton « ✍️ Signer » de la barre de titre du document.
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/csrf.php';
require_once __DIR__ . '/inc/ged_access.php';
require_login();

$pdo   = $GLOBALS['pdo'];
$docId = (int)($_GET['doc'] ?? 0);
$embed = !empty($_GET['embed']);
$h     = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

/* ── Le document, et le droit de le regarder ─────────────────────────────────── */
$erreur = ''; $docNom = ''; $nbPagesConnu = 0;
if ($docId <= 0) {
    $erreur = 'Aucun document demandé. Ajoute ?doc=<identifiant GED> à l\'adresse.';
} else {
    try {
        $g = GedAccess::grant($docId, 'preview');   // ⚠️ 'preview', pas 'view' : cf. GED_USAGES
        if (empty($g['path']) || !is_file($g['path'])) {
            $erreur = 'Ce document est inaccessible, ou son fichier est introuvable sur le disque.';
        } else {
            $st = $pdo->prepare("SELECT name_display, mime_type FROM ged_documents WHERE id = ? LIMIT 1");
            $st->execute([$docId]);
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $docNom = (string)($row['name_display'] ?? ('Document #' . $docId));
            /* Un PDF est exigé : pdf.js ne sait pas afficher un .docx, et on ne veut
               surtout pas laisser poser des zones sur un document qu'on ne pourra pas
               annoter à la fin. Mieux vaut le dire ici que produire un acte vide. */
            $estPdf = stripos((string)($row['mime_type'] ?? ''), 'pdf') !== false
                   || strtolower((string)pathinfo($g['path'], PATHINFO_EXTENSION)) === 'pdf';
            if (!$estPdf) $erreur = 'Ce document n\'est pas un PDF : les zones ne peuvent y être posées.';
        }
    } catch (Throwable $e) {
        $erreur = 'Document inaccessible : ' . $e->getMessage();
    }
}

/* Les zones déjà posées — on rouvre sur l'état enregistré, pas sur une page blanche. */
$zones = [];
if ($erreur === '') {
    try {
        $st = $pdo->prepare("SELECT id, page, x, y, w, h, type, role_code, libelle, obligatoire
                               FROM signature_zones WHERE ged_document_id = ? ORDER BY page, ordre, id");
        $st->execute([$docId]);
        $zones = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        // Table absente = migration 20260823a non jouée. On le DIT, on ne montre pas un écran vide.
        $erreur = 'La table des zones est absente : rejouer la migration 20260823a_signature_zones.';
    }
}

$pdfUrl = app_url('/api/ged_doc_serve.php?id=' . $docId);
$csrf   = csrf_token('signature_zones');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($docNom ?: 'Zones de signature') ?> — zones de signature</title>
<style>
  *{box-sizing:border-box;}
  body{margin:0;font-family:'Sora',system-ui,-apple-system,sans-serif;background:#eef1f5;color:#243B5C;}
  .sz-bar{display:flex;align-items:center;gap:14px;padding:11px 18px;background:#243B5C;color:#fff;flex-wrap:wrap;}
  .sz-bar h1{font-size:16px;margin:0;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:46vw;}
  .sz-bar .sp{flex:1;}
  .sz-wrap{display:flex;gap:16px;align-items:flex-start;padding:16px;}
  .sz-pages{flex:1;min-width:0;display:flex;flex-direction:column;gap:18px;align-items:center;}
  /* Le calque de zones se superpose EXACTEMENT au canvas : même conteneur, même taille.
     Toute marge ou bordure ici décalerait les zones par rapport au document. */
  .sz-page{position:relative;box-shadow:0 4px 18px rgba(36,59,92,.18);background:#fff;line-height:0;}
  .sz-layer{position:absolute;inset:0;cursor:crosshair;}
  .sz-z{position:absolute;border:2px solid #5f8f93;background:rgba(95,143,147,.16);border-radius:3px;
        cursor:move;line-height:1.2;font-size:11px;font-weight:700;color:#2c4b4d;padding:2px 4px;overflow:hidden;}
  .sz-z[data-t="date"]{border-color:#8a6d1b;background:rgba(212,160,71,.18);color:#7a5d12;}
  .sz-z[data-t="paraphe"]{border-color:#6b5ca5;background:rgba(107,92,165,.16);color:#4b3f7d;}
  .sz-z[data-t="texte"]{border-color:#3b7a57;background:rgba(59,122,87,.15);color:#2c5a41;}
  .sz-z[data-t="case"]{border-color:#b5352e;background:rgba(181,53,46,.13);color:#8c2a24;}
  .sz-z.on{outline:2px dashed #243B5C;outline-offset:2px;}
  .sz-h{position:absolute;right:-5px;bottom:-5px;width:12px;height:12px;background:#243B5C;border:2px solid #fff;border-radius:3px;cursor:nwse-resize;}
  .sz-x{position:absolute;right:-8px;top:-9px;width:17px;height:17px;border-radius:50%;background:#b5352e;color:#fff;
        border:2px solid #fff;font-size:10px;font-weight:800;line-height:13px;text-align:center;cursor:pointer;padding:0;}
  .sz-side{width:330px;flex:none;position:sticky;top:16px;background:#fff;border-radius:12px;
           box-shadow:0 2px 12px rgba(36,59,92,.10);padding:14px;max-height:calc(100vh - 32px);overflow:auto;}
  .sz-side h2{font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin:0 0 8px;}
  .sz-pal{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:14px;}
  .sz-pal button{border:1.5px solid #cbd5e1;background:#fff;border-radius:9px;padding:8px 6px;font-size:12px;
                 font-weight:800;cursor:pointer;color:#334155;font-family:inherit;}
  .sz-pal button.on{background:#243B5C;color:#fff;border-color:#243B5C;}
  .sz-list{display:flex;flex-direction:column;gap:7px;}
  .sz-item{border:1px solid #e2e8f0;border-radius:9px;padding:8px 9px;font-size:12px;background:#f8fafc;}
  .sz-item.on{border-color:#243B5C;background:#eef2f7;}
  .sz-item input,.sz-item select{width:100%;padding:4px 6px;border:1px solid #cbd5e1;border-radius:6px;
                                 font-size:11.5px;margin-top:4px;font-family:inherit;}
  /* Les rôles : des pastilles cliquables, pas un champ. Elles s'enroulent sur deux
     lignes plutôt que de déborder — le panneau ne s'élargit pas. */
  .sz-roles{display:flex;flex-wrap:wrap;gap:4px;margin-top:6px;}
  .sz-roles button{border:1px solid #cbd5e1;background:#fff;border-radius:20px;padding:3px 9px;
                   font-size:11px;font-weight:700;color:#475569;cursor:pointer;font-family:inherit;}
  .sz-roles button.on{background:#243B5C;border-color:#243B5C;color:#fff;}
  /* Une zone affectée porte une pastille de couleur : on voit d'un coup d'œil, sur le
     document, ce qui reste à attribuer — sans lire la liste de droite. */
  .sz-z[data-role]:not([data-role=""])::after{content:'';position:absolute;left:-5px;top:-5px;
    width:9px;height:9px;border-radius:50%;background:#243B5C;border:2px solid #fff;}
  .sz-act{border:none;border-radius:9px;padding:9px 16px;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;}
  .sz-save{background:#15803d;color:#fff;}
  .sz-etat{font-size:12px;font-weight:700;}
  .sz-err{margin:40px auto;max-width:640px;background:#fff;border:1px solid #e0a3a0;border-left:5px solid #b5352e;
          border-radius:10px;padding:18px 22px;color:#8c2a24;font-size:14px;line-height:1.6;}
  .sz-aide{font-size:11.5px;color:#6b7280;line-height:1.5;margin:10px 0 0;border-top:1px dashed #e2e8f0;padding-top:9px;}
</style>
</head>
<body>

<?php if ($erreur !== ''): ?>
  <div class="sz-err"><strong>⛔ <?= $h($erreur) ?></strong></div>
<?php else: ?>

<?php if (!$embed): ?>
<div class="sz-bar">
  <h1>✍️ <?= $h($docNom) ?></h1>
  <span class="sp"></span>
  <span class="sz-etat" id="szEtat"></span>
  <button type="button" class="sz-act sz-save" id="szSave">💾 Enregistrer les zones</button>
</div>
<?php endif; ?>

<div class="sz-wrap">
  <div class="sz-pages" id="szPages"><div style="padding:40px;color:#6b7280;font-size:13px;">⏳ Chargement du document…</div></div>

  <aside class="sz-side">
    <h2>Poser une zone</h2>
    <?php /* On choisit d'abord CE QU'ON POSE, puis on trace. L'inverse — tracer puis
             qualifier — obligerait à revenir sur chaque rectangle, et sur un document
             de dix pages c'est là qu'on oublie d'en qualifier un. */ ?>
    <div class="sz-pal" id="szPal">
      <button type="button" data-t="signature" class="on">✍️ Signature</button>
      <button type="button" data-t="paraphe">🅿️ Paraphe</button>
      <button type="button" data-t="date">📅 Date</button>
      <button type="button" data-t="texte">🔤 Texte</button>
      <button type="button" data-t="case">☑️ Case</button>
    </div>
    <h2>Zones posées (<span id="szNb">0</span>)</h2>
    <div class="sz-list" id="szList"></div>
    <p class="sz-aide">
      Choisis un type, puis <b>trace un cadre</b> sur le document. Déplace-le en le tirant,
      redimensionne par le coin, supprime par la croix.<br>
      Les positions sont enregistrées en <b>pourcentage de la page</b> : elles restent justes
      quel que soit le zoom, l'écran ou le format du papier.
    </p>
    <?php if ($embed): ?>
      <div style="margin-top:12px;"><button type="button" class="sz-act sz-save" id="szSave" style="width:100%;">💾 Enregistrer les zones</button></div>
      <div class="sz-etat" id="szEtat" style="margin-top:8px;"></div>
    <?php endif; ?>
  </aside>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script>
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';

var DOC_ID = <?= (int)$docId ?>;
var CSRF   = <?= json_encode($csrf) ?>;
var URL_SAVE = <?= json_encode(app_url('/api/signature_zones_save.php')) ?>;
var ZONES  = <?= json_encode($zones, JSON_UNESCAPED_UNICODE) ?>;   // état enregistré
var LBL    = {signature:'Signature', paraphe:'Paraphe', date:'Date', texte:'Texte', case:'Case'};
/* Les rôles proposés. Le `code` est celui qu'emploie déjà la chaîne de signature
   (`bail_signatures.role_code`) : on ne crée pas un second vocabulaire, sinon les zones
   ne se rattacheraient à personne au moment de l'envoi. Le libellé, lui, est écrit pour
   un humain — c'est tout l'intérêt de fermer le choix. */
var ROLES = [
  {code:'mandataire', ico:'🏢', lbl:'L’agence'},
  {code:'preneur',    ico:'🔑', lbl:'Preneur'},
  {code:'bailleur',   ico:'🏛️', lbl:'Bailleur'},
  {code:'caution',    ico:'🛡️', lbl:'Caution'},
  {code:'salarie',    ico:'👤', lbl:'Salarié'},
  {code:'tiers',      ico:'🤝', lbl:'Tiers'}
];
var typeCourant = 'signature', sel = null;

/* Chaque zone vit en POURCENTAGE. `el` est sa représentation à l'écran, recalculée à
   partir des pourcentages — jamais l'inverse. C'est ce sens unique qui garantit que ce
   qu'on voit et ce qu'on enregistre ne peuvent pas diverger. */
var zones = (ZONES || []).map(function(z){
  return {page:+z.page, x:+z.x, y:+z.y, w:+z.w, h:+z.h, type:z.type,
          role_code:z.role_code||'', libelle:z.libelle||'', obligatoire:+z.obligatoire ? 1 : 0, el:null};
});

document.querySelectorAll('#szPal button').forEach(function(b){
  b.addEventListener('click', function(){
    document.querySelectorAll('#szPal button').forEach(function(x){ x.classList.remove('on'); });
    b.classList.add('on'); typeCourant = b.dataset.t;
  });
});

function etat(txt, couleur){ var e=document.getElementById('szEtat'); if(e){ e.textContent=txt; e.style.color=couleur||'#cbd5e1'; } }

function placer(z){
  if (!z.el) return;
  z.el.style.left = z.x + '%'; z.el.style.top = z.y + '%';
  z.el.style.width = z.w + '%'; z.el.style.height = z.h + '%';
}

function dessiner(z, layer){
  var d = document.createElement('div');
  d.className = 'sz-z'; d.dataset.t = z.type; d.dataset.role = z.role_code || '';
  d.textContent = z.libelle || LBL[z.type] || z.type;
  var h = document.createElement('div'); h.className = 'sz-h'; d.appendChild(h);
  var x = document.createElement('button'); x.type='button'; x.className='sz-x'; x.textContent='✕'; d.appendChild(x);
  layer.appendChild(d); z.el = d; placer(z);

  d.addEventListener('mousedown', function(ev){
    if (ev.target === x) return;
    ev.stopPropagation();
    selectionner(z);
    var r = layer.getBoundingClientRect();
    var mode = (ev.target === h) ? 'size' : 'move';
    var ox = ev.clientX, oy = ev.clientY, zx = z.x, zy = z.y, zw = z.w, zh = z.h;
    function bouge(e){
      var dx = (e.clientX - ox) / r.width * 100, dy = (e.clientY - oy) / r.height * 100;
      if (mode === 'move') { z.x = Math.max(0, Math.min(100 - z.w, zx + dx)); z.y = Math.max(0, Math.min(100 - z.h, zy + dy)); }
      else { z.w = Math.max(1, Math.min(100 - z.x, zw + dx)); z.h = Math.max(0.8, Math.min(100 - z.y, zh + dy)); }
      placer(z);
    }
    function fin(){ document.removeEventListener('mousemove', bouge); document.removeEventListener('mouseup', fin); liste(); }
    document.addEventListener('mousemove', bouge); document.addEventListener('mouseup', fin);
  });
  x.addEventListener('click', function(ev){
    ev.stopPropagation();
    d.remove(); zones.splice(zones.indexOf(z), 1); if (sel === z) sel = null; liste();
  });
}

/* ⚠️🔥 SÉLECTIONNER NE RECONSTRUIT PAS LA LISTE.
   Première version : `selectionner()` appelait `liste()`, qui vide le panneau et le
   redessine. Or cliquer dans le champ « Libellé » remonte au conteneur de la ligne,
   qui sélectionne, qui reconstruit… et détruit le champ au moment même où on venait de
   le cliquer. Impossible d'y taper une seule lettre. Signalé le 23/08, à l'écran.
   La sélection est un changement d'ÉTAT VISUEL : on bascule des classes sur ce qui
   existe déjà. `liste()` est réservée aux changements de STRUCTURE — ajout, suppression.
   C'est la règle générale derrière ce bug : ne jamais redessiner ce que l'utilisateur
   est en train de manipuler. */
function selectionner(z){
  sel = z;
  zones.forEach(function(o){
    if (o.el)   o.el.classList.toggle('on', o === z);
    if (o.item) o.item.classList.toggle('on', o === z);
  });
}

function liste(){
  var L = document.getElementById('szList'); L.innerHTML = '';
  document.getElementById('szNb').textContent = zones.length;
  zones.forEach(function(z, i){
    var d = document.createElement('div');
    d.className = 'sz-item' + (z === sel ? ' on' : '');
    d.innerHTML = '<b>' + (LBL[z.type] || z.type) + '</b> · page ' + z.page
      + ' <span style="color:#8a8694;">(' + z.x.toFixed(1) + ' % , ' + z.y.toFixed(1) + ' %)</span>';
    var lib = document.createElement('input');
    lib.placeholder = 'Libellé montré au signataire'; lib.value = z.libelle;
    lib.addEventListener('input', function(){
      z.libelle = lib.value;
      /* On met à jour l'étiquette de la zone À LA MAIN, sans repasser par `liste()` :
         redessiner la liste arracherait le champ en cours de frappe. */
      if (z.el) z.el.childNodes[0].nodeValue = z.libelle || (LBL[z.type] || z.type);
    });
    /* ── LE SIGNATAIRE SE CHOISIT, IL NE SE TAPE PAS ──────────────────────────────
       C'était un champ libre : il fallait connaître le vocabulaire interne
       (`preneur`, `mandataire`…), le taper sans faute, et une coquille passait
       inaperçue jusqu'à l'envoi — où la zone n'aurait été proposée à personne.
       Un rôle est un choix FERMÉ : on le montre en toutes lettres et on le clique. */
    var choix = document.createElement('div'); choix.className = 'sz-roles';
    ROLES.forEach(function(r){
      var b = document.createElement('button');
      b.type = 'button'; b.dataset.r = r.code; b.textContent = r.ico + ' ' + r.lbl;
      b.title = 'Cette zone sera à remplir par : ' + r.lbl;
      if (z.role_code === r.code) b.classList.add('on');
      b.addEventListener('click', function(e){
        e.stopPropagation();
        // Recliquer le rôle déjà choisi le retire : une zone peut n'être affectée à personne.
        z.role_code = (z.role_code === r.code) ? '' : r.code;
        choix.querySelectorAll('button').forEach(function(o){ o.classList.toggle('on', o.dataset.r === z.role_code); });
        if (z.el) z.el.dataset.role = z.role_code;
      });
      choix.appendChild(b);
    });
    /* Le clic sur un CHAMP ne doit pas remonter à la ligne : sans ça il déclenchait la
       sélection, donc le défilement automatique, et le curseur sautait hors du champ. */
    lib.addEventListener('mousedown', function(e){ e.stopPropagation(); });
    lib.addEventListener('click',     function(e){ e.stopPropagation(); });
    d.appendChild(lib); d.appendChild(choix);
    d.addEventListener('click', function(){ selectionner(z); if (z.el) z.el.scrollIntoView({block:'center', behavior:'smooth'}); });
    z.item = d;
    L.appendChild(d);
  });
}

/* ── Rendu du PDF : une page = un canvas + un calque de MÊME taille ──────────────── */
pdfjsLib.getDocument(<?= json_encode($pdfUrl) ?>).promise.then(function(pdf){
  var host = document.getElementById('szPages'); host.innerHTML = '';
  var largeur = Math.min(900, host.clientWidth || 900);
  var chaine = Promise.resolve();
  for (var n = 1; n <= pdf.numPages; n++) {
    (function(num){
      chaine = chaine.then(function(){
        return pdf.getPage(num).then(function(page){
          var v1 = page.getViewport({scale:1});
          /* L'échelle est déduite de la LARGEUR disponible : le document remplit la
             colonne quel que soit son format d'origine. Elle ne sert qu'à l'affichage —
             rien de ce qui est enregistré n'en dépend. */
          var vp = page.getViewport({scale: largeur / v1.width});
          var box = document.createElement('div'); box.className = 'sz-page';
          box.style.width = vp.width + 'px'; box.style.height = vp.height + 'px';
          var cv = document.createElement('canvas'); cv.width = vp.width; cv.height = vp.height;
          box.appendChild(cv);
          var layer = document.createElement('div'); layer.className = 'sz-layer'; layer.dataset.page = num;
          box.appendChild(layer); host.appendChild(box);

          /* Tracer une zone : on part d'un point, on tire, on relâche. Les pixels ne
             servent que le temps du geste — la zone naît directement en pourcentage. */
          layer.addEventListener('mousedown', function(ev){
            if (ev.target !== layer) return;
            var r = layer.getBoundingClientRect();
            var x0 = (ev.clientX - r.left) / r.width * 100, y0 = (ev.clientY - r.top) / r.height * 100;
            var z = {page:num, x:x0, y:y0, w:1, h:1, type:typeCourant, role_code:'', libelle:'', obligatoire:1, el:null};
            zones.push(z); dessiner(z, layer); selectionner(z);
            function bouge(e){
              var xx = (e.clientX - r.left) / r.width * 100, yy = (e.clientY - r.top) / r.height * 100;
              z.x = Math.max(0, Math.min(x0, xx)); z.y = Math.max(0, Math.min(y0, yy));
              z.w = Math.max(1, Math.min(100 - z.x, Math.abs(xx - x0)));
              z.h = Math.max(0.8, Math.min(100 - z.y, Math.abs(yy - y0)));
              placer(z);
            }
            function fin(){ document.removeEventListener('mousemove', bouge); document.removeEventListener('mouseup', fin); liste(); }
            document.addEventListener('mousemove', bouge); document.addEventListener('mouseup', fin);
          });

          zones.filter(function(z){ return z.page === num; }).forEach(function(z){ dessiner(z, layer); });
          return page.render({canvasContext: cv.getContext('2d'), viewport: vp}).promise;
        });
      });
    })(n);
  }
  return chaine.then(function(){ liste(); etat(pdf.numPages + ' page(s)', '#cbd5e1'); });
}).catch(function(e){
  document.getElementById('szPages').innerHTML =
    '<div class="sz-err">⛔ Le document n\'a pas pu être affiché : ' + String(e) + '</div>';
});

document.getElementById('szSave').addEventListener('click', function(){
  var btn = this, old = btn.textContent; btn.disabled = true; btn.textContent = '⏳ Enregistrement…';
  fetch(URL_SAVE, {
    method:'POST', credentials:'same-origin',
    headers:{'Content-Type':'application/json', 'X-CSRF-Token': CSRF},   // ⚠️ en-tête : $_POST est vide sur un corps JSON
    body: JSON.stringify({doc_id: DOC_ID, zones: zones.map(function(z){
      return {page:z.page, x:z.x, y:z.y, w:z.w, h:z.h, type:z.type,
              role_code:z.role_code, libelle:z.libelle, obligatoire:z.obligatoire};
    })})
  }).then(function(r){ return r.text(); }).then(function(brut){
    btn.disabled = false; btn.textContent = old;
    var j = null; try { j = JSON.parse(brut); } catch(e){}
    if (!j)      { etat('réponse inattendue du serveur', '#ffb4ae'); return; }
    if (!j.ok)   { etat(j.error || 'échec', '#ffb4ae'); return; }
    etat('✅ ' + j.message, '#8ee7a8');
  }).catch(function(e){ btn.disabled = false; btn.textContent = old; etat('réseau : ' + e, '#ffb4ae'); });
});
</script>

<?php endif; ?>
</body>
</html>
