<?php
/**
 * ged_doc_view.php — LA VISUALISATION D'UN DOCUMENT DE LA GED, avec ses deux actions.
 *
 * Ouverte dans le modal unique : `docModalOpen({titre:…, url:'ged_doc_view.php?id=N'})`.
 * Le modal dessine la barre de titre ; cette page y DÉCLARE ce qu'on peut faire du
 * document — c'est le contrat de `inc/doc_modal.php` : « la page chargée les DÉCLARE,
 * le modal les DESSINE ». On n'ajoute donc aucun bouton en dur dans le modal, et les
 * deux actions apparaissent partout où un document est ouvert.
 *
 *   ✍️ Signer   → ouvre le MODULE SECONDAIRE (signature_zones.php) par-dessus : on y pose
 *                 les emplacements, puis on signe. Demandé par Emmanuel le 23/08/2026.
 *   📨 Envoyer  → part vers la cérémonie de signature (lien nominatif, code SMS, suivi).
 *
 * ⚠️ Le PDF est servi par `api/ged_doc_serve.php`, jamais par une URL directe vers le
 * fichier : les liens directs vers les documents sont refusés en production (403
 * Hostinger), et surtout c'est la cage GED qui décide qui a le droit de voir quoi.
 */
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/ged_access.php';
require_login();

$pdo   = $GLOBALS['pdo'];
$docId = (int)($_GET['id'] ?? 0);
$h     = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$erreur = ''; $nom = ''; $estPdf = false;
if ($docId <= 0) {
    $erreur = 'Aucun document demandé.';
} else {
    try {
        $g = GedAccess::grant($docId, 'preview');
        if (empty($g['path']) || !is_file($g['path'])) {
            $erreur = 'Ce document est inaccessible, ou son fichier est introuvable.';
        } else {
            $st = $pdo->prepare("SELECT name_display, mime_type FROM ged_documents WHERE id = ? LIMIT 1");
            $st->execute([$docId]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $nom = (string)($r['name_display'] ?? ('Document #' . $docId));
            $estPdf = stripos((string)($r['mime_type'] ?? ''), 'pdf') !== false
                   || strtolower((string)pathinfo($g['path'], PATHINFO_EXTENSION)) === 'pdf';
        }
    } catch (Throwable $e) {
        $erreur = 'Document inaccessible : ' . $e->getMessage();
    }
}

$urlDoc   = app_url('/api/ged_doc_serve.php?id=' . $docId);
$urlZones = app_url('/signature_zones.php?doc=' . $docId . '&embed=1');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title><?= $h($nom ?: 'Document') ?></title>
<style>
  html,body{margin:0;height:100%;background:#eef1f5;font-family:'Sora',system-ui,sans-serif;color:#243B5C;}
  iframe.doc{border:0;width:100%;height:100%;display:block;background:#fff;}
  .err{margin:40px auto;max-width:620px;background:#fff;border-left:5px solid #b5352e;border-radius:10px;
       padding:18px 22px;color:#8c2a24;font-size:14px;line-height:1.6;}
  /* Le module secondaire couvre TOUTE la fenêtre du navigateur, pas seulement la zone
     du document : il est monté dans la page parente quand c'est possible (même origine),
     sinon ici. Un éditeur coincé dans un cadre de 600 px serait inutilisable. */
  .sec{position:fixed;inset:0;z-index:99999;background:rgba(20,28,42,.55);display:flex;flex-direction:column;}
  .sec__bar{display:flex;align-items:center;gap:12px;background:#243B5C;color:#fff;padding:10px 16px;}
  .sec__bar b{font-size:15px;font-weight:700;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .sec__bar button{border:none;background:#ffffff22;color:#fff;border-radius:8px;padding:7px 14px;
                   font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;}
  .sec iframe{border:0;flex:1;width:100%;background:#eef1f5;}
</style>
</head>
<body>
<?php if ($erreur !== ''): ?>
  <div class="err"><strong>⛔ <?= $h($erreur) ?></strong></div>
<?php else: ?>
  <iframe class="doc" src="<?= $h($urlDoc) ?>#toolbar=1&navpanes=0&view=FitH" title="<?= $h($nom) ?>"></iframe>
<?php endif; ?>

<script>
(function(){
  var DOC_ID    = <?= (int)$docId ?>;
  var URL_ZONES = <?= json_encode($urlZones) ?>;
  var EST_PDF   = <?= $estPdf ? 'true' : 'false' ?>;
  var NOM       = <?= json_encode($nom, JSON_UNESCAPED_UNICODE) ?>;

  /* ── LE MODULE SECONDAIRE ────────────────────────────────────────────────────
     Monté dans la page PARENTE quand elle est accessible : le modal occupe déjà
     l'écran, et un éditeur ouvert à l'intérieur de son iframe serait enfermé dans
     la largeur du document. Même origine, donc l'accès est direct ; si un jour ce
     n'était plus le cas, on retombe sur un montage local plutôt que sur rien. */
  function ouvrirModuleSecondaire(url, titre){
    var hote = document;
    try { if (window.parent !== window && window.parent.document) hote = window.parent.document; } catch(e) {}
    var ov = hote.createElement('div');
    ov.className = 'sec';
    /* Styles posés en dur : l'overlay peut vivre dans la page parente, qui n'a aucune
       raison de connaître la feuille de style de cette page-ci. */
    ov.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(20,28,42,.55);display:flex;flex-direction:column;';
    var bar = hote.createElement('div');
    bar.style.cssText = 'display:flex;align-items:center;gap:12px;background:#243B5C;color:#fff;padding:10px 16px;font-family:Sora,system-ui,sans-serif;';
    var t = hote.createElement('b');
    t.textContent = titre;
    t.style.cssText = 'font-size:15px;font-weight:700;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
    var x = hote.createElement('button');
    x.type = 'button'; x.textContent = '✕ Fermer';
    x.style.cssText = 'border:none;background:#ffffff22;color:#fff;border-radius:8px;padding:7px 14px;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;';
    x.addEventListener('click', function(){ ov.remove(); });
    bar.appendChild(t); bar.appendChild(x);
    var fr = hote.createElement('iframe');
    fr.src = url; fr.style.cssText = 'border:0;flex:1;width:100%;background:#eef1f5;';
    ov.appendChild(bar); ov.appendChild(fr);
    hote.body.appendChild(ov);
    /* Échap ferme — sur la fenêtre qui héberge réellement l'overlay. */
    var win = (hote === document) ? window : window.parent;
    function esc(e){ if (e.key === 'Escape') { ov.remove(); win.removeEventListener('keydown', esc); } }
    win.addEventListener('keydown', esc);
  }

  // On expose l'ouverture : le bouton du modal l'appellera depuis la page parente.
  window.gedDocSigner = function(){ ouvrirModuleSecondaire(URL_ZONES, '✍️ ' + NOM); };

  /* ── DÉCLARATION DES ACTIONS AU MODAL ────────────────────────────────────────
     `docModalSetActions()` vit dans la page parente. On ne fait rien si le modal
     n'est pas là : la page reste consultable ouverte seule, simplement sans barre. */
  try {
    if (window.parent !== window && typeof window.parent.docModalSetTitre === 'function') {
      window.parent.docModalSetTitre(NOM);
    }
    if (window.parent !== window && typeof window.parent.docModalSetActions === 'function') {
      var actions = [];
      if (EST_PDF) {
        /* ⚠️ Réservé aux PDF : on ne peut ni afficher ni annoter un .docx, et laisser
           poser des zones sur un document qu'on ne saura pas signer produirait un acte
           vide. Mieux vaut ne pas proposer le bouton que de le proposer en vain. */
        actions.push({label:'✍️ Signer', titre:'Poser les emplacements de signature et signer ce document',
                      onclick: function(){ window.gedDocSigner(); }});
      }
      actions.push({label:'📨 Envoyer', titre:'Envoyer ce document pour signature — lien nominatif, code SMS et suivi',
                    onclick: function(){ alert('Envoi pour signature — à brancher sur la cérémonie.'); }});
      window.parent.docModalSetActions(actions);
      return;   // le modal dessine la barre : rien à faire de plus ici
    }
  } catch (e) {}

  /* ── REPLI : LA PAGE OUVERTE SEULE ───────────────────────────────────────────
     Sans modal parent, personne ne dessine la barre de titre — la page serait un PDF
     nu, sans aucune des deux actions. On la dessine alors nous-mêmes, à l'identique.
     Ce n'est pas qu'une commodité de test : un document ouvert dans un onglet doit
     rester actionnable, sinon l'utilisateur revient en arrière pour retrouver un
     bouton qu'il vient de voir ailleurs. */
  var bar = document.createElement('div');
  bar.style.cssText = 'display:flex;align-items:center;gap:10px;background:#243B5C;color:#fff;'
                    + 'padding:10px 16px;font-family:Sora,system-ui,sans-serif;';
  var t = document.createElement('b');
  t.textContent = NOM;
  t.style.cssText = 'font-size:15px;font-weight:700;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
  bar.appendChild(t);

  function bouton(txt, titre, fn){
    var b = document.createElement('button');
    b.type = 'button'; b.textContent = txt; b.title = titre;
    b.style.cssText = 'border:none;background:#ffffff22;color:#fff;border-radius:8px;padding:7px 14px;'
                    + 'font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;white-space:nowrap;';
    b.addEventListener('click', fn);
    bar.appendChild(b);
  }
  if (EST_PDF) bouton('✍️ Signer', 'Poser les emplacements de signature et signer ce document',
                      function(){ window.gedDocSigner(); });
  bouton('📨 Envoyer', 'Envoyer ce document pour signature — lien nominatif, code SMS et suivi',
         function(){ alert('Envoi pour signature — à brancher sur la cérémonie.'); });

  document.body.insertBefore(bar, document.body.firstChild);
  // Le document occupe ce qui reste : sans ça l'iframe garde 100 % et déborde sous la barre.
  var fr = document.querySelector('iframe.doc');
  if (fr) fr.style.height = 'calc(100% - 47px)';
})();
</script>
</body>
</html>
