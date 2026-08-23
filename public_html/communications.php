<?php
declare(strict_types=1);
/**
 * communications.php — LE JOURNAL DES ÉCHANGES, tous canaux, tous modules.
 *
 * Demande d'Emmanuel du 20/08/2026 : voir les envois de mails ET de SMS **par
 * agence**, **par collaborateur** et **par dossier**, avec bascule mail / SMS —
 * et les deux ensemble dès qu'on est sur un dossier.
 *
 * ── La mise en page ─────────────────────────────────────────────────────────
 * Filtre en CASCADE à gauche, liste cliquable à droite. La cascade n'est pas un
 * empilement de menus : chaque niveau ne propose que ce qui existe COMPTE TENU
 * des niveaux au-dessus, et affiche son nombre. On ne peut donc pas construire
 * un filtre qui ne renvoie rien — le zéro se voit avant d'être choisi.
 *
 * ── Ce qui est affiché, et d'où ça vient ────────────────────────────────────
 * Une seule source : `communications`, alimentée par les DEUX points de passage
 * uniques (`send_mail()` et `sms_envoyer()`). Aucun rapprochement par date ou par
 * adresse — un journal qui devine n'est pas un journal.
 *
 * L'historique ANTÉRIEUR est repris par `20260820b_communications_reprise`, à
 * partir de sources déjà ancrées — `sms_envois`, `mail_history.recipient_type`
 * (« bien:905 », « dossier_vente:11 ») et `bail_signatures`. J'avais d'abord écrit
 * que les mails n'avaient aucun ancrage : c'était faux, il existait et personne ne
 * le lisait.
 *
 * ⚠️ Le message et les pièces sont affichés, mais `corps` est stocké MASQUÉ (codes,
 * IBAN) et les pièces ne portent que leurs NOMS : le journal dit ce qui a été
 * envoyé, il ne redistribue pas les fichiers ni les secrets.
 *
 * Périmètre : la société de la session. Un admin (rôle 1) voit tout.
 */
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo     = $GLOBALS['pdo'];
$userSoc = (int)($_SESSION['id_societe'] ?? 0);
$roleId  = (int)($_SESSION['id_role'] ?? 0);
$isAdmin = ($roleId === 1);
$h       = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$tableOk = true;
try { $pdo->query("SELECT 1 FROM communications LIMIT 1"); } catch (Throwable) { $tableOk = false; }

// ── Les critères ────────────────────────────────────────────────────────────
$fCanal   = (string)($_GET['canal'] ?? 'tous');
if (!in_array($fCanal, ['tous', 'mail', 'sms'], true)) $fCanal = 'tous';
$fAgence  = (int)($_GET['a'] ?? 0);
$fUser    = (int)($_GET['u'] ?? 0);
$fObjType = strtoupper(trim((string)($_GET['ot'] ?? '')));
$fObjId   = (int)($_GET['oid'] ?? 0);
$fQ       = trim((string)($_GET['q'] ?? ''));
$page     = max(1, (int)($_GET['p'] ?? 1));
$parPage  = 50;

/* Sur un DOSSIER précis, la bascule mail/SMS n'a pas de sens : c'est justement là
   qu'on veut les deux ensemble, pour relire l'échange dans l'ordre. */
$surDossier = ($fObjType !== '' && $fObjId > 0);
if ($surDossier) $fCanal = 'tous';

/**
 * Construit le WHERE en EXCLUANT un niveau — c'est ce qui fait la cascade :
 * la liste des agences se compte sans le filtre agence, sinon elle n'afficherait
 * qu'une seule ligne, la sienne, et on ne pourrait plus en changer.
 */
$where = function (string $sauf = '') use ($isAdmin, $userSoc, $fCanal, $fAgence, $fUser, $fObjType, $fObjId, $fQ): array {
    $w = []; $p = [];
    if (!$isAdmin && $userSoc > 0)              { $w[] = 'c.id_societe = ?'; $p[] = $userSoc; }
    if ($fCanal !== 'tous' && $sauf !== 'canal'){ $w[] = 'c.canal = ?';      $p[] = $fCanal; }
    if ($fAgence > 0 && $sauf !== 'agence')     { $w[] = 'c.id_agence = ?';  $p[] = $fAgence; }
    if ($fUser   > 0 && $sauf !== 'user')       { $w[] = 'c.id_user = ?';    $p[] = $fUser; }
    if ($fObjType !== '' && $sauf !== 'dossier'){ $w[] = 'c.objet_type = ?'; $p[] = $fObjType; }
    if ($fObjId   > 0 && $sauf !== 'dossier')   { $w[] = 'c.objet_id = ?';   $p[] = $fObjId; }
    if ($fQ !== '')                             { $w[] = '(c.destinataire LIKE ? OR c.sujet LIKE ?)'; $p[] = '%'.$fQ.'%'; $p[] = '%'.$fQ.'%'; }
    return [$w ? ('WHERE ' . implode(' AND ', $w)) : '', $p];
};

$compte = function (string $select, string $sauf, string $join = '') use ($pdo, $where): array {
    [$sqlW, $p] = $where($sauf);
    try {
        $st = $pdo->prepare("SELECT $select, COUNT(*) AS n FROM communications c $join $sqlW GROUP BY 1, 2 ORDER BY 2");
        $st->execute($p);
        return $st->fetchAll(PDO::FETCH_NUM) ?: [];
    } catch (Throwable $e) { error_log('[communications compte] ' . $e->getMessage()); return []; }
};

$lignes = []; $total = 0; $lCanaux = []; $lAgences = []; $lCollabs = []; $lTypes = []; $lDossiers = [];
if ($tableOk) {
    [$sqlW, $p] = $where();
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM communications c $sqlW");
        $st->execute($p);
        $total = (int)$st->fetchColumn();

        /* LIMIT/OFFSET interpolés après cast entier : un LIMIT lié en PDO émulé
           se retrouve entre quotes et MySQL le refuse. */
        $lim = $parPage; $off = ($page - 1) * $parPage;
        $st = $pdo->prepare("
            SELECT c.*, TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))) AS collab, a.nom_agence
              FROM communications c
         LEFT JOIN users   u ON u.id = c.id_user
         LEFT JOIN agences a ON a.id = c.id_agence
              $sqlW
          ORDER BY c.created_at DESC, c.id DESC
             LIMIT $lim OFFSET $off");
        $st->execute($p);
        $lignes = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { error_log('[communications liste] ' . $e->getMessage()); }

    $lCanaux   = $compte("c.canal, c.canal AS lbl", 'canal');
    $lAgences  = $compte("c.id_agence, COALESCE(a.nom_agence, '— sans agence')", 'agence', "LEFT JOIN agences a ON a.id = c.id_agence");
    $lCollabs  = $compte("c.id_user, COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.prenom,''),' ',COALESCE(u.nom,''))),''), '— automate')", 'user', "LEFT JOIN users u ON u.id = c.id_user");
    $lTypes    = $compte("COALESCE(c.objet_type,''), COALESCE(c.objet_type,'— non rattaché')", 'dossier');
    if ($fObjType !== '') {
        $lDossiers = $compte("c.objet_id, CONCAT('#', COALESCE(c.objet_id,0))", 'dossier');
    }
}

/** Lien conservant les autres critères. Un changement de filtre revient page 1. */
$lien = static function (array $maj): string {
    $q = array_merge($_GET, $maj);
    unset($q['p']);
    foreach ($q as $k => $v) if ($v === '' || $v === 0 || $v === '0' || $v === null) unset($q[$k]);
    return '?' . http_build_query($q);
};

/** Une entrée de la colonne de gauche : libellé, compte, état actif. */
$item = static function (string $url, string $lbl, int $n, bool $on, string $icone = ''): string {
    return '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" class="cmf-i' . ($on ? ' on' : '') . '">'
         . '<span class="cmf-l">' . ($icone !== '' ? $icone . ' ' : '') . htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') . '</span>'
         . '<span class="cmf-n">' . $n . '</span></a>';
};

ob_start();
?>
<style>
/* Cascade à gauche, liste à droite — la largeur fixe de la colonne de filtres
   évite qu'elle danse quand un libellé long apparaît. */
.cm-wrap{display:grid;grid-template-columns:266px 1fr;gap:16px;align-items:start;}
@media(max-width:900px){.cm-wrap{grid-template-columns:1fr;}}
.cm-card{background:#fff;border:1px solid #e8ecf1;border-radius:14px;padding:13px 14px;}
.cm-sec{font-size:10.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#94a3b8;margin:14px 0 6px;}
.cm-sec:first-child{margin-top:0;}
.cmf-i{display:flex;justify-content:space-between;align-items:center;gap:8px;text-decoration:none;
       padding:5px 9px;border-radius:8px;font-size:12.5px;color:#334155;font-weight:600;}
.cmf-i:hover{background:#f1f5f9;}
.cmf-i.on{background:#243B5C;color:#fff;font-weight:800;}
.cmf-l{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.cmf-n{font-family:'DM Mono',monospace;font-size:11px;opacity:.75;flex:0 0 auto;}
.cm-row{display:grid;grid-template-columns:118px 74px 44px 1fr 1fr 130px;gap:10px;align-items:center;
        padding:9px 11px;border-top:1px solid #eef2f6;cursor:pointer;font-size:12.5px;}
.cm-row:hover{background:#f8fafc;}
.cm-row.ko{background:#fdf5f4;}
.cm-head{display:grid;grid-template-columns:118px 74px 44px 1fr 1fr 130px;gap:10px;padding:7px 11px;
         font-size:10.5px;font-weight:800;letter-spacing:.05em;text-transform:uppercase;color:#94a3b8;}
@media(max-width:760px){.cm-row,.cm-head{grid-template-columns:1fr;}.cm-head{display:none;}}
.cm-mono{font-family:'DM Mono',monospace;font-size:11.5px;color:#475569;}
</style>

<?php if (!$tableOk): ?>
  <div style="padding:16px 18px;border:1.5px solid #e0a3a0;background:#fdeceb;border-radius:12px;color:#b5352e;font-size:14px;">
    <b>Le journal n'existe pas encore sur cet environnement.</b><br>
    Jouer la migration <code>20260820a_communications</code> dans <i>admin/admin_migrations.php</i>.
  </div>
<?php else: ?>
<div class="cm-wrap">

  <!-- ── COLONNE GAUCHE : la cascade ─────────────────────────────────────── -->
  <div class="cm-card">
    <div class="cm-sec">Canal</div>
    <?php if ($surDossier): ?>
      <div style="font-size:11.5px;color:#8a6d1b;background:#fdf8ec;border:1px solid #e2d5b0;border-radius:8px;padding:7px 9px;line-height:1.4;">
        Sur un dossier, <b>mail et SMS sont affichés ensemble</b> — pour relire l'échange dans l'ordre.
      </div>
    <?php else:
      $nTot = 0; foreach ($lCanaux as $c) $nTot += (int)$c[2];
      echo $item($lien(['canal' => null]), 'Tout', $nTot, $fCanal === 'tous');
      foreach ($lCanaux as $c) {
          $v = (string)$c[0];
          echo $item($lien(['canal' => $v]), $v === 'sms' ? 'SMS' : ucfirst($v), (int)$c[2], $fCanal === $v, $v === 'sms' ? '📱' : '📧');
      }
    endif; ?>

    <div class="cm-sec">Agence</div>
    <?php
    $n = 0; foreach ($lAgences as $a) $n += (int)$a[2];
    echo $item($lien(['a' => null, 'u' => null]), 'Toutes', $n, $fAgence === 0);
    foreach ($lAgences as $a) {
        /* Changer d'agence remet le collaborateur à zéro : garder un collaborateur
           d'une autre agence produirait une liste vide, sans que rien ne le dise. */
        echo $item($lien(['a' => (int)$a[0], 'u' => null]), (string)$a[1], (int)$a[2], $fAgence === (int)$a[0]);
    }
    ?>

    <div class="cm-sec">Collaborateur</div>
    <?php
    $n = 0; foreach ($lCollabs as $c) $n += (int)$c[2];
    echo $item($lien(['u' => null]), 'Tous', $n, $fUser === 0);
    foreach ($lCollabs as $c) echo $item($lien(['u' => (int)$c[0]]), (string)$c[1], (int)$c[2], $fUser === (int)$c[0]);
    ?>

    <div class="cm-sec">Dossier</div>
    <?php
    $n = 0; foreach ($lTypes as $t) $n += (int)$t[2];
    echo $item($lien(['ot' => null, 'oid' => null]), 'Tous', $n, $fObjType === '');
    foreach ($lTypes as $t) {
        $v = (string)$t[0];
        echo $item($lien(['ot' => $v ?: null, 'oid' => null]), (string)$t[1], (int)$t[2], $fObjType === $v && $v !== '');
    }
    if ($lDossiers) {
        echo '<div class="cm-sec">' . $h($fObjType) . ' — n°</div>';
        foreach (array_slice($lDossiers, 0, 40) as $d) {
            echo $item($lien(['oid' => (int)$d[0]]), (string)$d[1], (int)$d[2], $fObjId === (int)$d[0]);
        }
    }
    ?>

    <div class="cm-sec">Recherche</div>
    <form method="get">
      <?php foreach (['canal'=>$fCanal,'a'=>$fAgence,'u'=>$fUser,'ot'=>$fObjType,'oid'=>$fObjId] as $k=>$v):
            if ($v !== '' && $v !== 0 && $v !== 'tous'): ?>
        <input type="hidden" name="<?= $h($k) ?>" value="<?= $h($v) ?>">
      <?php endif; endforeach; ?>
      <input type="search" name="q" value="<?= $h($fQ) ?>" placeholder="destinataire ou objet…"
             style="width:100%;padding:7px 10px;border:1px solid #cbd5e1;border-radius:8px;font-size:12.5px;box-sizing:border-box;">
    </form>
  </div>

  <!-- ── COLONNE DROITE : la liste cliquable ─────────────────────────────── -->
  <div class="cm-card" style="padding:6px 0 10px;">
    <?php if (!$lignes): ?>
      <div style="padding:26px;text-align:center;color:#64748b;font-size:13.5px;">
        Aucun échange ne correspond.<br>
        <span style="font-size:12px;">⚠️ Le journal ne contient que les envois faits <b>depuis le 20/08/2026</b> — rien d'antérieur n'a jamais été enregistré.</span>
      </div>
    <?php else: ?>
      <div class="cm-head">
        <div>Horodatage</div><div>Canal</div><div title="Pièces jointes">📎</div><div>Destinataire</div><div>Expéditeur</div><div>Dossier</div>
      </div>
      <?php foreach ($lignes as $i => $l):
          $ko  = ($l['statut'] === 'echec');
          $sms = ($l['canal'] === 'sms'); ?>
        <div class="cm-row<?= $ko ? ' ko' : '' ?>" onclick="cmOuvrir(<?= (int)$i ?>)">
          <div class="cm-mono"><?= $h(date('d/m/Y H:i', strtotime((string)$l['created_at']))) ?></div>
          <div style="font-size:11.5px;font-weight:800;color:<?= $sms ? '#8a6d1b' : '#1d4ed8' ?>;">
            <?= $sms ? '📱 SMS' : '📧 Mail' ?><?= $ko ? '<br><span style="color:#b5352e;font-size:10.5px;">échec</span>' : '' ?>
          </div>
          <?php /* La présence de pièces se voit DANS LA LISTE : sans ça il fallait
                   ouvrir chaque ligne pour découvrir qu'il n'y avait rien à voir.
                   Le nombre est affiché — « il y a des pièces » et « il y en a trois »
                   ne se décident pas de la même façon. */ ?>
          <div style="text-align:center;font-size:12px;color:<?= (int)$l['nb_pieces'] > 0 ? '#8a6d1b' : '#e2e8f0' ?>;font-weight:800;"
               title="<?= (int)$l['nb_pieces'] > 0 ? (int)$l['nb_pieces'] . ' pièce(s) jointe(s)' : 'aucune pièce jointe' ?>">
            <?= (int)$l['nb_pieces'] > 0 ? '📎' . ((int)$l['nb_pieces'] > 1 ? (int)$l['nb_pieces'] : '') : '·' ?>
          </div>
          <?php /* ⚠️ Un numéro seul n'apprend rien. « 0033758391114 » ne dit pas à qui on
                   a écrit, et sur un SMS c'est TOUT ce qu'on affichait — le nom était en
                   base (`destinataire_nom`) et servait uniquement au panneau de détail.
                   Le nom passe donc devant, l'adresse ou le numéro en dessous. */ ?>
          <div style="overflow:hidden;text-overflow:ellipsis;">
            <?php if (trim((string)($l['destinataire_nom'] ?? '')) !== ''): ?>
              <div style="font-weight:700;color:#243B5C;overflow:hidden;text-overflow:ellipsis;"><?= $h($l['destinataire_nom']) ?></div>
              <div style="font-size:11px;color:#8a8694;overflow:hidden;text-overflow:ellipsis;"><?= $h($l['destinataire']) ?></div>
            <?php else: ?>
              <?= $h($l['destinataire']) ?>
            <?php endif; ?>
          </div>
          <?php /* ⚠️🔥 « — automate » se déclenchait dès qu'aucun utilisateur n'était en
                   session. Or le code SMS part de la PAGE PUBLIQUE de signature : personne
                   n'est connecté, mais ce n'est pas un robot — c'est le signataire qui vient
                   de le demander. Emmanuel a vu « automate » sur une relance qu'il venait
                   lui-même de déclencher (23/08). On dit désormais ce qu'on sait :
                   le collaborateur, sinon l'origine journalisée, et « automate » en dernier
                   recours seulement — quand c'en est vraiment un. */ ?>
          <div style="overflow:hidden;text-overflow:ellipsis;color:#475569;">
            <?= $h($l['collab'] ?: (trim((string)($l['expediteur'] ?? '')) !== '' ? $l['expediteur'] : '— automate')) ?>
          </div>
          <div style="font-size:11.5px;font-weight:700;color:<?= !empty($l['objet_type']) ? '#8a6d1b' : '#b0b8c1' ?>;">
            <?= !empty($l['objet_type']) ? $h($l['objet_type']) . ' #' . (int)$l['objet_id'] : 'non rattaché' ?>
          </div>
        </div>
      <?php endforeach; ?>

      <?php $nbPages = (int)ceil($total / $parPage); if ($nbPages > 1): ?>
      <div style="display:flex;gap:6px;justify-content:center;margin-top:12px;flex-wrap:wrap;">
        <?php for ($i = max(1, $page - 4); $i <= min($nbPages, $page + 4); $i++):
              $q = $_GET; $q['p'] = $i; ?>
          <a href="?<?= $h(http_build_query($q)) ?>" style="text-decoration:none;padding:5px 11px;border-radius:7px;font-size:12.5px;font-weight:<?= $i === $page ? '800' : '600' ?>;background:<?= $i === $page ? '#243B5C' : '#fff' ?>;color:<?= $i === $page ? '#fff' : '#475569' ?>;border:1px solid #cbd5e1;"><?= $i ?></a>
        <?php endfor; ?>
      </div>
      <?php endif; ?>
      <div style="font-family:'DM Mono',monospace;font-size:10px;color:#9a9690;margin:8px 12px 0;text-align:right;">
        <?= number_format($total, 0, ',', ' ') ?> échange<?= $total > 1 ? 's' : '' ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ⚠️ Modal au niveau RACINE, jamais dans une carte : un ancêtre porteur d'un
     `transform` redéfinit le référentiel de `position:fixed` et décentre tout. -->
<div id="cmModal" style="display:none;position:fixed;inset:0;z-index:9700;background:rgba(15,18,24,.55);align-items:center;justify-content:center;padding:18px;">
  <div style="background:#fff;border-radius:14px;max-width:620px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.3);overflow:hidden;">
    <div id="cmModalHead" style="background:#243B5C;color:#fff;padding:13px 18px;font-weight:800;font-size:15px;">Échange</div>
    <div id="cmModalBody" style="padding:16px 20px;max-height:70vh;overflow:auto;font-size:13.5px;color:#334155;"></div>
    <div style="padding:11px 18px;border-top:1px solid #eef2f6;display:flex;justify-content:flex-end;">
      <button type="button" onclick="document.getElementById('cmModal').style.display='none'"
              style="border:none;background:#243B5C;color:#fff;border-radius:9px;padding:8px 17px;font-weight:800;cursor:pointer;">Fermer</button>
    </div>
  </div>
</div>
<script>
var CM = <?= json_encode(array_map(static function (array $l): array {
    return [
        'date'   => date('d/m/Y à H\hi', strtotime((string)$l['created_at'])),
        'canal'  => $l['canal'],
        'dest'   => $l['destinataire'],
        'destN'  => $l['destinataire_nom'],
        'collab' => $l['collab'] ?: '— automate',
        'agence' => $l['nom_agence'],
        'exp'    => $l['expediteur'],
        'objet'  => $l['objet_type'] ? ($l['objet_type'] . ' #' . (int)$l['objet_id']) : '',
        'sujet'  => $l['sujet'],
        'extrait'=> $l['extrait'],
        /* ⚠️ Corps TRONQUÉ dans la charge de la page : 50 lignes × un corps complet,
           c'est plusieurs Mo de JSON à chaque affichage pour n'en lire qu'un seul.
           Au-delà de la coupe, on le DIT plutôt que de tronquer en silence. */
        'corps'  => mb_substr((string)($l['corps'] ?? ''), 0, 6000),
        'coupe'  => mb_strlen((string)($l['corps'] ?? '')) > 6000,
        'pieces' => json_decode((string)($l['pieces_json'] ?? '[]'), true) ?: [],
        'pj'     => (int)$l['nb_pieces'],
        'statut' => $l['statut'],
        'err'    => $l['error_message'],
        'ref'    => $l['ref_technique'],
    ];
}, $lignes), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
function cmEsc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
function cmLigne(k,v){ return v ? '<div style="display:flex;gap:10px;padding:5px 0;border-bottom:1px solid #f1f5f9;">'
  + '<div style="flex:0 0 118px;color:#94a3b8;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;">'+k+'</div>'
  + '<div style="flex:1;">'+cmEsc(v)+'</div></div>' : ''; }
window.cmOuvrir = function(i){
  var c = CM[i]; if(!c) return;
  document.getElementById('cmModalHead').textContent =
      (c.canal === 'sms' ? '📱 SMS' : '📧 Mail') + ' — ' + c.date;
  var b = cmLigne('Destinataire', c.dest + (c.destN ? ' (' + c.destN + ')' : ''))
        + cmLigne('Expéditeur', c.collab + (c.agence ? ' · ' + c.agence : ''))
        + cmLigne('Adresse d\'envoi', c.exp)
        + cmLigne('Dossier', c.objet || 'non rattaché')
        + cmLigne('Objet', c.sujet)
        + cmLigne('Pièces jointes', c.pj ? String(c.pj) : '')
        + cmLigne('Statut', c.statut === 'echec' ? 'ÉCHEC — ' + (c.err || '') : 'Envoyé')
        + cmLigne('Réf. technique', c.ref);
  /* ⚠️ « Aucune pièce » et « pièces dont on ignore le nom » ne sont PAS la même
     chose, et l'écran doit les distinguer. Les envois repris de `mail_history`
     n'ont qu'un COMPTE (`attachments: 2`) : les noms n'ont jamais été enregistrés.
     Ne rien afficher laissait croire à un envoi sans pièce jointe — c'est-à-dire
     à un fait faux. */
  if ((!c.pieces || !c.pieces.length) && c.pj > 0) {
    b += '<div style="margin-top:12px;padding:9px 12px;background:#fffbeb;border:1px solid #f0dcbf;'
       + 'border-radius:9px;font-size:12px;color:#8a6d1b;">📎 ' + c.pj + ' pièce(s) jointe(s) — '
       + 'noms non enregistrés (envoi antérieur au journal).</div>';
  }
  if (c.pieces && c.pieces.length) {
    /* Les NOMS des pièces, pas des liens : le journal dit ce qui a été joint, il
       ne redistribue pas les fichiers — la GED reste la seule porte d'accès. */
    b += '<div style="margin-top:12px;"><div style="font-size:11.5px;font-weight:700;color:#94a3b8;'
       + 'text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px;">Pièces jointes</div>';
    c.pieces.forEach(function(f){
      b += '<div style="font-size:12.5px;color:#334155;padding:4px 9px;background:#f8fafc;'
         + 'border:1px solid #eef2f6;border-radius:7px;margin-bottom:4px;">📎 ' + cmEsc(f) + '</div>';
    });
    b += '</div>';
  }
  var texte = c.corps || c.extrait;
  if (texte) {
    /* `white-space:pre-wrap` sur du texte ÉCHAPPÉ : le corps est stocké en texte
       avec ses sauts de ligne. L'injecter en HTML rejouerait le message dans la
       page — un mail archivé n'a rien à faire tourner ici. */
    b += '<div style="margin-top:12px;"><div style="font-size:11.5px;font-weight:700;color:#94a3b8;'
       + 'text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px;">Message</div>'
       + '<div style="padding:11px 13px;background:#f8fafc;border:1px solid #eef2f6;border-radius:10px;'
       + 'font-size:12.5px;color:#334155;line-height:1.55;white-space:pre-wrap;">' + cmEsc(texte) + '</div>'
       + '<div style="font-size:11px;color:#94a3b8;margin-top:6px;">'
       + (c.coupe ? 'Message tronqué à l\'affichage. ' : '')
       + 'Les codes et coordonnées bancaires sont masqués à l\'enregistrement.</div></div>';
  }
  document.getElementById('cmModalBody').innerHTML = b;
  document.getElementById('cmModal').style.display = 'flex';
};
document.getElementById('cmModal').addEventListener('mousedown', function(e){
  if (e.target === this) this.style.display = 'none';
});
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape') document.getElementById('cmModal').style.display = 'none';
});
</script>
<?php endif; ?>
<?php
$layout_title   = 'Communications';
$layout_module  = 'Ma Box Agency';
/* Barre AGENCE : place définitive arrêtée le 20/08/2026. L'écran sert d'abord à
   retrouver ce qu'on a envoyé à un client — c'est un outil de travail, pas un
   outil d'administration. */
$layout_sidebar = 'sidebar_agency';
$layout_content = ob_get_clean();
require_once __DIR__ . '/inc/layout_maboximmo.php';
