<?php
declare(strict_types=1);
/**
 * ADMIN — INTÉGRATION CRG.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * La page par laquelle un CRG entrera dans MBI. Elle est construite comme la vraie interface,
 * pas comme une maquette : si le parcours fonctionne, il n'y a rien à reconstruire ensuite.
 *
 * ⚠️ RIEN N'EST ÉCRIT DANS LES DONNÉES MÉTIER TANT QUE LES SIX PHASES NE SONT PAS VALIDÉES.
 *    Tout vit dans les tables `crgi_*` et dans `data/crg_integration/`. `ANNULER L'IMPORT`
 *    rend le dépôt et son analyse au néant sans qu'aucune donnée MBI n'ait bougé.
 *
 * ⚠️ `FICHIER PHYSIQUE ≠ CRG MÉTIER`. On ne demande jamais « de quelle agence est ce PDF » :
 *    un dépôt peut mêler plusieurs agences et plusieurs mois. C'est le moteur qui le découvre,
 *    page par page, et qui déclare son niveau de certitude.
 *
 * ⚠️ UNE PHASE NE S'OUVRE QU'APRÈS VALIDATION DE LA PRÉCÉDENTE. Le bouton de la phase suivante
 *    n'apparaît pas : il ne s'agit pas de dissuader, il s'agit que le moteur ne parte pas.
 *
 * Livraison en cours : dépôt · import_id · annulation · PHASE 0 · validation de la phase 0.
 * Les phases 1 à 5 sont dessinées mais volontairement inertes.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/crg_integration.php';
require_admin_or_super_admin();

$pdo = $GLOBALS['pdo'];
$csrf = csrf_token('default');
$importId = (int)($_GET['import'] ?? 0);

$imports = $pdo->query(
    'SELECT * FROM crgi_import ORDER BY (statut = "ANNULE"), cree_le DESC LIMIT 40'
)->fetchAll(PDO::FETCH_ASSOC);

$courant = null;
$phases = [];
$bilan = null;
$crgs = [];
$pieces = [];
if ($importId > 0) {
    $st = $pdo->prepare('SELECT * FROM crgi_import WHERE id = ?');
    $st->execute([$importId]);
    $courant = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
if ($courant) {
    $st = $pdo->prepare('SELECT * FROM crgi_phase WHERE import_id = ? ORDER BY phase');
    $st->execute([$importId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $phases[(int)$p['phase']] = $p;
    }
    $st = $pdo->prepare('SELECT * FROM crgi_piece WHERE import_id = ? ORDER BY id');
    $st->execute([$importId]);
    $pieces = $st->fetchAll(PDO::FETCH_ASSOC);

    if ($pdo->prepare('SELECT 1')->execute() && $pieces) {
        $bilan = crgi_bilan_phase0($pdo, $importId);
        $st = $pdo->prepare(
            'SELECT c.*, p.nom_original, a.nom_agence,
                    (SELECT COUNT(*) FROM crgi_page g WHERE g.crg_id = c.id) AS nb_pages_liees
               FROM crgi_crg c
               JOIN crgi_piece p ON p.id = c.piece_id
               LEFT JOIN agences a ON a.id = c.agence_id
              WHERE c.import_id = ? ORDER BY c.piece_id, c.page_debut'
        );
        $st->execute([$importId]);
        $crgs = $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
$etat0 = $courant ? crgi_phase_validee($pdo, $importId, 0) : ['validee' => false, 'perimee' => false];
$etat1 = $courant ? crgi_phase_validee($pdo, $importId, 1) : ['validee' => false, 'perimee' => false];
// ⚠️ LE BILAN DE LA PHASE 1 NE SE CHARGE QUE SI ELLE A TOURNÉ. L'appeler avant
//    afficherait des zéros qu'on lirait comme « rien à rapprocher ».
$bilan1 = ($courant && in_array((string)($phases[1]['statut'] ?? ''), ['A VALIDER', 'VALIDEE'], true))
    ? crgi_bilan_phase1($pdo, $importId) : null;
$etat2 = $courant ? crgi_phase_validee($pdo, $importId, 2) : ['validee' => false, 'perimee' => false];
$etat3 = $courant ? crgi_phase_validee($pdo, $importId, 3) : ['validee' => false, 'perimee' => false];
$bilan3 = ($courant && in_array((string)($phases[3]['statut'] ?? ''), ['A VALIDER', 'VALIDEE'], true))
    ? crgi_bilan_phase3($pdo, $importId) : null;
$etat4 = $courant ? crgi_phase_validee($pdo, $importId, 4) : ['validee' => false, 'perimee' => false];
$bilan4 = ($courant && in_array((string)($phases[4]['statut'] ?? ''), ['A VALIDER', 'VALIDEE'], true))
    ? crgi_bilan_phase4($pdo, $importId) : null;
$etat5 = $courant ? crgi_phase_validee($pdo, $importId, 5) : ['validee' => false, 'perimee' => false];
$bilan5 = ($courant && in_array((string)($phases[5]['statut'] ?? ''), ['A VALIDER', 'VALIDEE'], true))
    ? crgi_bilan_phase5($pdo, $importId) : null;
// ⚠️ UN MONTANT DOIT TOUJOURS POUVOIR S'OUVRIR JUSQU'À SES LIGNES ET LEURS PAGES. Sans ce
//    détail, l'écran affirmerait des totaux qu'on ne pourrait pas contredire.
$detailMvt = [];
if ($bilan4 && isset($_GET['mvt'])) {
    $ou = ['import_id = ?', 'categorie = ?'];
    $args = [$importId, (string)$_GET['mvt']];
    if (isset($_GET['compte'])) {
        $ou[] = 'crg_id IN (SELECT id FROM crgi_crg WHERE import_id = ? AND compte = ?)';
        $args[] = $importId;
        $args[] = (string)$_GET['compte'];
    }
    if (isset($_GET['arrete'])) {
        $ou[] = 'date_arrete = ?';
        $args[] = (string)$_GET['arrete'];
    }
    $st = $pdo->prepare('SELECT * FROM crgi_mouvement WHERE ' . implode(' AND ', $ou)
                       . ' AND additionnable = 1 ORDER BY page, id LIMIT 400');
    $st->execute($args);
    $detailMvt = $st->fetchAll(PDO::FETCH_ASSOC);
}
$bilan2 = ($courant && in_array((string)($phases[2]['statut'] ?? ''), ['A VALIDER', 'VALIDEE'], true))
    ? crgi_bilan_phase2($pdo, $importId) : null;
$modifies = $aArbitrer = [];
if ($bilan2) {
    $st = $pdo->prepare('SELECT * FROM crgi_immeuble WHERE import_id = ? AND statut = ?
                          GROUP BY COALESCE(code, CONCAT(nom,"|",code_postal)) ORDER BY page');
    $st->execute([$importId, 'MODIFIE']);
    $modifies = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute([$importId, 'A ARBITRER']);
    $aArbitrer = $st->fetchAll(PDO::FETCH_ASSOC);
}

/** La pastille d'état d'une phase — couleur ET mot. */
function crgi_pastille(string $statut): string
{
    $classe = ['EN ATTENTE' => 'attente', 'EN ANALYSE' => 'analyse', 'A VALIDER' => 'avalider',
               'VALIDEE' => 'validee', 'BLOQUEE' => 'bloquee', 'ANNULE' => 'annule',
               'ANALYSE EN COURS' => 'analyse', 'VALIDE PARTIELLEMENT' => 'avalider',
               'PRET A INTEGRER' => 'avalider', 'INTEGRE' => 'validee'][$statut] ?? 'attente';
    return '<span class="crgi-p ' . $classe . '">' . h($statut) . '</span>';
}

$pageTitle = '📥 Intégration CRG';
// ⚠️ LE LAYOUT MBI POSE UN `<base href>` SUR LA RACINE DE L'APPLICATION. Un chemin
//    `../css/…` y est donc résolu depuis la racine, pas depuis `admin/` : la feuille de style
//    partait en 404 et la page s'affichait entièrement nue. On passe par `asset_url()`, comme
//    le reste de l'administration.
$extraCss = '<link rel="stylesheet" href="' . asset_url('/css/crg_integration.css') . '">';
require_once __DIR__ . '/../inc/agency_layout_top.php';
?>
<div class="crgi">

<?php if (!$courant): ?>
  <div class="crgi-carte">
    <h2>Déposer un ou plusieurs CRG</h2>
    <p class="crgi-sous">
      Un CRG isolé, un dossier entier, ou un seul PDF contenant des centaines de comptes rendus
      de plusieurs agences et de plusieurs mois — le moteur ne suppose rien et découvre tout.
      Chaque dépôt ouvre un <b>import annulable</b> : rien n'est écrit dans MBI avant la
      validation des six phases.
    </p>
    <div class="crgi-champ">
      <label for="libelle">Libellé du dépôt</label>
      <input type="text" id="libelle"
             placeholder="ex. « CRG VIENNE + CHAPONOST, avril à juin 2026 »">
      <span class="crgi-aide">Pour vous y retrouver dans la liste des imports. Facultatif.</span>
    </div>

    <div class="crgi-champ">
      <label for="fichiers">Fichiers PDF</label>
      <input type="file" id="fichiers" accept="application/pdf" multiple>
      <span class="crgi-aide">
        Un ou plusieurs fichiers. Le dépôt se fait <b>par tranches de 6 Mo</b> : ce serveur
        limite un envoi à <?= h(ini_get('post_max_size')) ?> alors qu'un document de 906 pages
        en pèse près de 80, et un envoi direct n'afficherait aucune erreur — seulement
        « aucun fichier reçu » sur un dépôt parfaitement valide.
      </span>
    </div>

    <div class="crgi-jauge" id="zoneJauge" hidden><i id="jauge"></i></div>
    <div class="crgi-journal" id="journal" hidden></div>

    <div class="crgi-actions">
      <button class="crgi-b or" id="btnDeposer">Déposer et ouvrir l'import</button>
      <span class="crgi-aide" id="etatDepot"></span>
    </div>

    <details class="crgi-repli">
      <summary>Le fichier est déjà sur le serveur</summary>
      <div class="crgi-champ" style="margin-top:12px">
        <label for="cheminServeur">Chemin absolu</label>
        <input type="text" id="cheminServeur" placeholder="C:/tmp/CRG_906_pages.pdf">
        <span class="crgi-aide">
          Pour un très gros document déjà copié sur la machine. Seules les racines
          <code>C:/tmp</code>, <code>D:/</code>, <code>/tmp</code> et <code>/var/crg</code>
          sont acceptées — sans cette borne, cette page deviendrait un lecteur de fichiers
          arbitraire du serveur.
        </span>
      </div>
      <div class="crgi-actions">
        <button class="crgi-b creux" id="btnChemin">Rattacher ce fichier</button>
      </div>
    </details>
  </div>

  <div class="crgi-carte">
    <h2>Imports</h2>
    <?php if (!$imports): ?>
      <p class="crgi-sous">Aucun import pour l'instant.</p>
    <?php else: ?>
      <div class="crgi-defile"><table>
        <tr><th>#</th><th>Libellé</th><th>État</th><th class="num">Pièces</th>
            <th class="num">Pages</th><th>Créé le</th><th></th></tr>
        <?php foreach ($imports as $i): ?>
          <tr>
            <td class="num"><?= (int)$i['id'] ?></td>
            <td><?= h($i['libelle'] ?: '(sans libellé)') ?>
              <?php if ($i['annule_motif']): ?>
                <div class="crgi-muet" style="font-size:12px">annulé : <?= h($i['annule_motif']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= crgi_pastille((string)$i['statut']) ?></td>
            <td class="num"><?= (int)$i['nb_pieces'] ?></td>
            <td class="num"><?= number_format((int)$i['nb_pages'], 0, ',', ' ') ?></td>
            <td class="crgi-muet"><?= h((string)$i['cree_le']) ?></td>
            <td><a class="crgi-b creux" href="<?= h(app_url('/admin/admin_crg_integration.php')) ?>?import=<?= (int)$i['id'] ?>">Ouvrir</a></td>
          </tr>
        <?php endforeach; ?>
      </table></div>
    <?php endif; ?>
  </div>

<?php else: ?>

  <div class="crgi-carte">
    <div class="crgi-ligne" style="justify-content:space-between">
      <div>
        <h2>Import n°<?= (int)$courant['id'] ?> — <?= h($courant['libelle'] ?: '(sans libellé)') ?></h2>
        <p class="crgi-sous" style="margin:0">
          <?= crgi_pastille((string)$courant['statut']) ?>
          &nbsp;<?= (int)$courant['nb_pieces'] ?> pièce(s) ·
          <?= number_format((int)$courant['nb_pages'], 0, ',', ' ') ?> page(s) ·
          déposé le <?= h((string)$courant['cree_le']) ?>
        </p>
      </div>
      <div class="crgi-ligne">
        <a class="crgi-b creux" href="<?= h(app_url('/admin/admin_crg_integration.php')) ?>">← Tous les imports</a>
        <?php if ($courant['statut'] !== 'ANNULE' && $courant['statut'] !== 'INTEGRE'): ?>
          <button class="crgi-b danger" id="btnAnnuler">Annuler l'import</button>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="crgi-frise">
    <?php foreach (CRGI_PHASES as $n => $titre):
        $p = $phases[$n] ?? ['statut' => 'EN ATTENTE']; ?>
      <div class="crgi-etape<?= $n === 0 ? ' on' : '' ?>">
        <div class="n">PHASE <?= $n ?></div>
        <div class="t"><?= h($titre) ?></div>
        <?= crgi_pastille((string)$p['statut']) ?>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($courant['statut'] === 'ANNULE'): ?>
    <div class="crgi-note rouge">
      <b>Import annulé</b> le <?= h((string)$courant['annule_le']) ?>. Ses pages, ses CRG
      détectés et ses fichiers de staging ont été supprimés. Aucune donnée métier de MBI
      n'a jamais été écrite par cet import — c'est ce que le staging garantit.
    </div>
  <?php else: ?>

  <div class="crgi-carte">
    <h2>Phase 0 — Documents, agences, périodes</h2>
    <p class="crgi-sous">
      Avant toute lecture métier, le moteur parcourt chaque page pour reconstruire les
      <b>CRG logiques</b> : agence, période, date d'arrêté, propriétaire, compte mandant,
      page de début et de fin, et le <b>niveau de certitude</b> de chaque découpage.
    </p>

    <?php if ($pieces): ?>
      <div class="crgi-defile"><table>
        <tr><th>Pièce</th><th class="num">Pages</th><th class="num">Taille</th><th>État</th></tr>
        <?php foreach ($pieces as $p): ?>
          <tr>
            <td><?= h((string)$p['nom_original']) ?>
              <?php if ($p['message']): ?>
                <div style="font-size:12px;color:#b3261e"><?= h((string)$p['message']) ?></div>
              <?php endif; ?>
            </td>
            <td class="num"><?= number_format((int)$p['nb_pages'], 0, ',', ' ') ?></td>
            <td class="num"><?= number_format(((int)$p['taille_octets']) / 1048576, 1, ',', ' ') ?> Mo</td>
            <td><?= h((string)$p['etat']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table></div>
    <?php endif; ?>

    <div class="crgi-ligne">
      <button class="crgi-b or" id="btnAnalyser">Analyser les pages (phase 0)</button>
      <span class="crgi-muet" id="etatAnalyse"></span>
    </div>
    <div class="crgi-journal" id="journal" hidden></div>
  </div>

  <?php if ($bilan && $bilan['crg_detectes'] > 0): ?>
    <div class="crgi-carte">
      <h2>Bilan de la phase 0</h2>
      <p class="crgi-sous">
        Les quatre premiers chiffres doivent se répondre : autant de pages analysées que de
        pages déclarées, aucune page perdue, aucune page laissée sans CRG, aucun chevauchement.
      </p>
      <?php
      $ok = fn(bool $c) => $c ? 'ok' : 'mal';
      // ⚠️ TROIS SORTS POUR UNE PAGE, ET ILS NE SE CONFONDENT PAS. « Hors périmètre » n'est
      //    pas « non identifiée » : les appels de fonds sont parfaitement reconnus, ils ne
      //    sont simplement pas des CRG. Les fondre ferait passer un découpage juste pour un
      //    échec de lecture sur cent pages.
      $cases = [
          ['v' => $bilan['pages_analysees'], 'l' => 'pages analysées',
           'c' => $ok($bilan['pages_analysees'] === $bilan['pages_declarees'])],
          ['v' => $bilan['pages_affectees'], 'l' => 'pages rattachées à un CRG', 'c' => 'ok'],
          ['v' => $bilan['pages_hors_crg'],
           'l' => 'pages hors périmètre CRG — appels de fonds', 'c' => 'ok'],
          ['v' => $bilan['pages_non_affect'], 'l' => 'pages réellement NON identifiées',
           'c' => $ok($bilan['pages_non_affect'] === 0)],
          ['v' => $bilan['pages_perdues'], 'l' => 'pages perdues',
           'c' => $ok($bilan['pages_perdues'] === 0)],
          ['v' => $bilan['chevauchements'], 'l' => 'chevauchements',
           'c' => $ok($bilan['chevauchements'] === 0)],
      ];
      // ⚠️ DOCUMENT ≠ SITUATION MÉTIER ≠ ÉVÉNEMENT MÉTIER. Les trois se comptent séparément,
      //    et aucun n'efface l'autre. Une même clé ne vaut réédition que si le CONTENU
      //    concorde — sans quoi elle écraserait un CRG complémentaire.
      $d = $bilan['doublons'];
      $identite = [
          ['v' => $bilan['crg_detectes'], 'l' => 'CRG documentaires détectés', 'c' => 'ok'],
          ['v' => $bilan['situations'], 'l' => 'situations de gestion distinctes', 'c' => 'ok'],
          ['v' => (int)($d['REENONCIATION'] ?? 0),
           'l' => 'réénonciations démontrées — même clé ET même contenu', 'c' => 'ok'],
          ['v' => (int)($d['MEME CLE CONTENU DIFFERENT'] ?? 0),
           'l' => 'même clé, contenu DIFFÉRENT — à examiner',
           'c' => $ok((int)($d['MEME CLE CONTENU DIFFERENT'] ?? 0) === 0)],
      ];
      // ⚠️ LA PHASE 0 DOIT RÉPONDRE À « QUELLE AGENCE, QUELLE PÉRIODE ». Ces cinq cases sont
      //    le contrôle de cette promesse : tant qu'une seule est rouge, l'identification
      //    documentaire n'est pas terminée et la phase n'est pas validable.
      $as = $bilan['agence_source']; $ps = $bilan['periode_source'];
      $provenance = [
          ['v' => (int)($as['LUE'] ?? 0), 'l' => 'agence LUE dans le PDF', 'c' => 'ok'],
          ['v' => (int)($as['RAPPROCHEE'] ?? 0), 'l' => 'agence RAPPROCHÉE dans MBI', 'c' => 'ok'],
          ['v' => (int)($as['INDETERMINABLE'] ?? 0), 'l' => 'agence INDÉTERMINABLE',
           'c' => $ok((int)($as['INDETERMINABLE'] ?? 0) === 0)],
          ['v' => (int)($ps['LUE'] ?? 0), 'l' => 'période LUE', 'c' => 'ok'],
          ['v' => (int)($ps['INDETERMINABLE'] ?? 0), 'l' => 'période INDÉTERMINABLE',
           'c' => $ok((int)($ps['INDETERMINABLE'] ?? 0) === 0)],
      ]; ?>
      <div class="crgi-bilan">
        <?php foreach ($cases as $c): ?>
          <div class="<?= $c['c'] ?>">
            <div class="v"><?= number_format((int)$c['v'], 0, ',', ' ') ?></div>
            <div class="l"><?= h($c['l']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <h2 style="margin-top:18px">Combien de CRG, et combien de situations</h2>
      <p class="crgi-sous">
        Un document énoncé deux fois ne fait pas deux situations de gestion — mais une même
        clé ne suffit pas à le prouver. Une réénonciation n’est déclarée que si le
        <b>contenu concorde</b> ; sinon la ligne reste à examiner. <b>Rien n’est supprimé</b>,
        ni en base ni dans le PDF : les deux occurrences gardent leurs pages.
      </p>
      <div class="crgi-bilan">
        <?php foreach ($identite as $c): ?>
          <div class="<?= $c['c'] ?>">
            <div class="v"><?= number_format((int)$c['v'], 0, ',', ' ') ?></div>
            <div class="l"><?= h($c['l']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <h2 style="margin-top:18px">Quelle agence, quelle période — et d'où on le sait</h2>
      <p class="crgi-sous">
        Une agence <b>lue</b> est un fait du document ; une agence <b>rapprochée</b> est une
        conclusion de MBI à partir du compte mandant, quand une seule agence était possible.
        Les confondre ferait passer une déduction pour une lecture.
      </p>
      <div class="crgi-bilan">
        <?php foreach ($provenance as $c): ?>
          <div class="<?= $c['c'] ?>">
            <div class="v"><?= number_format((int)$c['v'], 0, ',', ' ') ?></div>
            <div class="l"><?= h($c['l']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <h2 style="margin-top:18px">Par agence et par période</h2>
      <div class="crgi-defile"><table>
        <tr><th>Agence</th><th>Période</th><th class="num">CRG</th><th class="num">dont certains</th></tr>
        <?php foreach ($bilan['par_agence'] as $l): ?>
          <tr>
            <td><?= h((string)$l['agence_vue']) ?>
              <div class="crgi-muet" style="font-size:11.5px"><?= h((string)$l['agence_source']) ?></div></td>
            <td><?= h((string)$l['periode']) ?></td>
            <td class="num"><?= (int)$l['n'] ?></td>
            <td class="num"><?= (int)$l['certains'] ?></td>
          </tr>
        <?php endforeach; ?>
      </table></div>
    </div>

    <details class="crgi-carte crgi-repli">
      <summary>Le détail des <?= count($crgs) ?> CRG détectés, page par page</summary>
      <p class="crgi-sous" style="margin-top:12px">
        Chaque ligne ouvre le PDF déposé <b>à sa page de début</b> : un découpage ne se croit
        pas, il se vérifie. Le motif dit toujours pourquoi le moteur est sûr — ou ne l'est pas.
      </p>
      <div class="crgi-defile"><table>
        <tr><th>Pages</th><th>Certitude</th><th>Agence</th><th>Période</th><th>Compte</th>
            <th>Propriétaire</th><th>Motif</th></tr>
        <?php foreach ($crgs as $c): ?>
          <tr>
            <td class="num">
              <a href="<?= h(app_url('/admin/crgi_page.php')) ?>?crg=<?= (int)$c['id'] ?>#page=<?= (int)$c['page_debut'] ?>"
                 target="_blank"
                 title="Ouvrir le PDF à la page <?= (int)$c['page_debut'] ?>">
                <?= (int)$c['page_debut'] ?>–<?= (int)$c['page_fin'] ?></a>
              <div class="crgi-muet" style="font-size:11.5px"><?= (int)$c['nb_pages_liees'] ?> p.</div>
            </td>
            <td><span class="crgi-cert <?= h((string)$c['certitude']) ?>"><?= h((string)$c['certitude']) ?></span></td>
            <td><?= h((string)($c['nom_agence'] ?? $c['agence'] ?? '—')) ?>
              <div class="crgi-muet" style="font-size:11.5px"><?= h((string)($c['format'] ?? '')) ?></div></td>
            <td><?= h((string)($c['periode_cle'] ?? '—')) ?>
              <?php if ($c['date_arrete']): ?>
                <div class="crgi-muet" style="font-size:11.5px">arrêté <?= h((string)$c['date_arrete']) ?></div>
              <?php endif; ?></td>
            <td><?= h((string)($c['compte'] ?? '—')) ?></td>
            <td><?= h((string)($c['proprietaire'] ?? '—')) ?></td>
            <td class="crgi-muet" style="font-size:12px"><?= h((string)$c['motif']) ?>
              <?php if ((string)$c['agence_source'] === 'INDETERMINABLE'): ?>
                <div style="color:#b3261e;margin-top:3px"><?= h((string)$c['agence_motif']) ?></div>
              <?php endif; ?>
              <?php if ($c['doublon_qualification']): ?>
                <div style="margin-top:3px"><b><?= h((string)$c['doublon_statut']) ?></b>
                  — <?= h((string)$c['doublon_qualif_motif']) ?></div>
              <?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </table></div>
    </details>

  <?php if ($etat0['validee'] && !$etat0['perimee']): ?>
    <div class="crgi-carte">
      <h2>Phase 1 — Inventaire : ce que MBI connaît déjà</h2>
      <p class="crgi-sous">
        Chaque situation issue de la phase 0 est confrontée à ce que MBI enregistre déjà
        (<code>compte mandant → situations de gestion</code>). <b>Aucune création,
        modification, suppression ni archivage</b> : la phase 1 lit MBI et n'écrit que dans
        le staging.
      </p>
      <div class="crgi-actions" style="border:0;padding-top:0;margin-top:0">
        <button class="crgi-b or" id="btnPhase1">
          <?= $bilan1 ? 'Relancer l’inventaire' : 'Lancer l’inventaire (phase 1)' ?></button>
        <span class="crgi-aide" id="etatPhase1"></span>
      </div>
    </div>

    <?php if ($bilan1): $t1 = $bilan1['totaux']; ?>
      <div class="crgi-carte">
        <h2>Bilan de la phase 1</h2>
        <p class="crgi-sous">
          Les réénonciations et les situations complémentaires viennent de la phase 0 : elles
          sont rappelées ici pour que le total se lise sans remonter d'un écran.
        </p>
        <?php
        $ok1 = fn(bool $c) => $c ? 'ok' : 'mal';
        $cartes1 = [
            ['v' => (int)($t1['DEJA CONNUE'] ?? 0), 'l' => 'déjà connues de MBI', 'c' => 'ok'],
            ['v' => (int)($t1['NOUVELLE'] ?? 0), 'l' => 'nouvelles — compte connu', 'c' => 'ok'],
            ['v' => (int)($t1['COMPTE INCONNU'] ?? 0), 'l' => 'compte inconnu de MBI',
             'c' => 'ok'],
            ['v' => (int)($t1['A VERIFIER'] ?? 0), 'l' => 'à vérifier',
             'c' => $ok1((int)($t1['A VERIFIER'] ?? 0) === 0)],
            ['v' => $bilan1['reenonciations'], 'l' => 'réénonciations (phase 0)', 'c' => 'ok'],
            ['v' => $bilan1['complementaires'], 'l' => 'complémentaires (phase 0)', 'c' => 'ok'],
            ['v' => count($bilan1['absentes']), 'l' => 'connues de MBI, absentes du dépôt',
             'c' => 'ok'],
        ]; ?>
        <div class="crgi-bilan">
          <?php foreach ($cartes1 as $c): ?>
            <div class="<?= $c['c'] ?>">
              <div class="v"><?= number_format((int)$c['v'], 0, ',', ' ') ?></div>
              <div class="l"><?= h($c['l']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="crgi-note">
          <b>ABSENT DU NOUVEAU CORPUS ≠ À SUPPRIMER DE MBI.</b> Les
          <?= count($bilan1['absentes']) ?> situations que MBI connaît et que ce dépôt ne
          rapporte pas sont listées à titre d'information. Un CRG non redéposé ne dit rien du
          mandat : il peut manquer à l'envoi, avoir été édité ailleurs, ou ne plus avoir lieu
          d'être. <b>Aucune action n'est proposée sur elles.</b>
        </div>

        <h2 style="margin-top:18px">Agence → période</h2>
        <div class="crgi-defile"><table>
          <tr><th>Agence</th><th>Période</th><th class="num">Situations</th>
              <th class="num">Déjà connues</th><th class="num">Nouvelles</th>
              <th class="num">Compte inconnu</th><th class="num">À vérifier</th>
              <th class="num">Complémentaires</th></tr>
          <?php foreach ($bilan1['par_agence'] as $l): ?>
            <tr>
              <td><?= h((string)$l['agence_vue']) ?></td>
              <td><?= h((string)$l['periode']) ?></td>
              <td class="num"><?= (int)$l['situations'] ?></td>
              <td class="num"><?= (int)$l['connues'] ?></td>
              <td class="num"><?= (int)$l['nouvelles'] ?></td>
              <td class="num"><?= (int)$l['comptes_inconnus'] ?></td>
              <td class="num"><?= (int)$l['a_verifier'] ?></td>
              <td class="num"><?= (int)$l['complementaires'] ?></td>
            </tr>
          <?php endforeach; ?>
        </table></div>
      </div>

      <div class="crgi-carte">
        <h2>Validation de la phase 1</h2>
        <?php if ($etat1['validee']): ?>
          <p class="crgi-sous"><?= crgi_pastille('VALIDEE') ?>
            le <?= h((string)($etat1['ligne']['valide_le'] ?? '')) ?>.
            La phase 2 — Patrimoine — n'est pas encore livrée.</p>
        <?php else: ?>
          <p class="crgi-sous">
            La phase 1 est une <b>comparaison</b> : rien n'a été écrit dans MBI, et l'import
            reste annulable. La validation scelle l'inventaire examiné.
          </p>
          <button class="crgi-b or" id="btnValider1">Valider la phase 1</button>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($etat1['validee'] && !$etat1['perimee']): ?>
    <div class="crgi-carte">
      <h2>Phase 2 — Patrimoine : propriétaire → compte → immeuble → bien</h2>
      <p class="crgi-sous">
        Chaque immeuble et chaque lot lus dans les CRG sont confrontés à ce que MBI enregistre.
        <b>Aucun rapprochement approximatif ne crée une identité</b> : une correspondance exacte
        donne <code>IDENTIQUE</code> ou <code>MODIFIÉ</code> avec son avant/après, une
        ressemblance donne <code>À ARBITRER</code> avec les candidats nommés.
        <b>Aucune écriture métier.</b>
      </p>
      <div class="crgi-actions" style="border:0;padding-top:0;margin-top:0">
        <button class="crgi-b or" id="btnPhase2">
          <?= $bilan2 ? 'Relancer la confrontation' : 'Lancer la confrontation (phase 2)' ?></button>
        <span class="crgi-aide" id="etatPhase2"></span>
      </div>
    </div>

    <?php if ($bilan2): ?>
      <div class="crgi-carte">
        <h2>Bilan de la phase 2</h2>
        <p class="crgi-sous">
          ⚠️ <b>Occurrence ≠ objet.</b> Un même lot est réénoncé à chaque période : ce dépôt en
          compte <?= (int)$bilan2['occurrences']['lots'] ?> occurrences pour
          <b><?= (int)$bilan2['distincts']['lots'] ?> lots</b>, et
          <?= (int)$bilan2['occurrences']['immeubles'] ?> occurrences pour
          <b><?= (int)$bilan2['distincts']['immeubles'] ?> immeubles</b>. Les chiffres ci-dessous
          comptent des <b>objets</b>.
        </p>
        <?php
        $ok2 = fn(bool $c) => $c ? 'ok' : 'mal';
        $bi = $bilan2['immeubles'];
        $bl = $bilan2['lots'];
        $niveaux = [
            ['t' => 'Immeubles', 'd' => (int)$bilan2['distincts']['immeubles'], 'b' => $bi],
            ['t' => 'Biens / lots', 'd' => (int)$bilan2['distincts']['lots'], 'b' => $bl],
        ]; ?>
        <div class="crgi-defile"><table>
          <tr><th>Niveau</th><th class="num">Objets détectés</th><th class="num">Identiques</th>
              <th class="num">Modifiés</th><th class="num">Nouveaux</th>
              <th class="num">À arbitrer</th></tr>
          <?php foreach ($niveaux as $n): ?>
            <tr>
              <td><b><?= h($n['t']) ?></b></td>
              <td class="num"><?= $n['d'] ?></td>
              <td class="num"><?= (int)($n['b']['IDENTIQUE'] ?? 0) ?></td>
              <td class="num"><?= (int)($n['b']['MODIFIE'] ?? 0) ?></td>
              <td class="num"><?= (int)($n['b']['NOUVEAU'] ?? 0) ?></td>
              <td class="num"><?= (int)($n['b']['A ARBITRER'] ?? 0) ?></td>
            </tr>
          <?php endforeach; ?>
        </table></div>

        <h2 style="margin-top:18px">Les <?= array_sum($bilan2['comptes']) ?> comptes que MBI ne connaît pas</h2>
        <p class="crgi-sous">
          <b>TIERS ≠ PROPRIÉTAIRE ≠ COMPTE MANDANT.</b> Un nouveau compte ne fait pas un nouveau
          propriétaire. Le candidat est <b>nommé</b>, jamais retenu d'office.
        </p>
        <?php
        $lib = [
            'A' => 'nouveau compte d’un propriétaire déjà connu',
            'B' => 'nouveau propriétaire ET nouveau compte',
            'C' => 'compte présent dans MBI sous une autre écriture',
            'D' => 'indéterminable — arbitrage',
        ]; ?>
        <div class="crgi-bilan">
          <?php foreach ($lib as $k => $l): ?>
            <div class="<?= $k === 'D' && (int)($bilan2['comptes'][$k] ?? 0) > 0 ? 'mal' : 'ok' ?>">
              <div class="v"><?= (int)($bilan2['comptes'][$k] ?? 0) ?></div>
              <div class="l"><b><?= $k ?></b> — <?= h($l) ?></div>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if ($modifies): ?>
          <h2 style="margin-top:18px">Ce qui a changé — avant → après</h2>
          <div class="crgi-defile"><table>
            <tr><th>Immeuble lu</th><th>Page</th><th>Écarts</th></tr>
            <?php foreach ($modifies as $m): ?>
              <tr>
                <td><?= h((string)$m['nom']) ?>
                  <div class="crgi-muet" style="font-size:11.5px">code <?= h((string)($m['code'] ?? '—')) ?></div></td>
                <td class="num"><?= (int)$m['page'] ?></td>
                <td style="white-space:pre-line"><?= h((string)$m['avant_apres']) ?></td>
              </tr>
            <?php endforeach; ?>
          </table></div>
        <?php endif; ?>

        <?php if ($aArbitrer): ?>
          <details class="crgi-repli">
            <summary>Les <?= count($aArbitrer) ?> immeubles à arbitrer, avec leurs candidats</summary>
            <div class="crgi-defile" style="margin-top:12px"><table>
              <tr><th>Immeuble lu</th><th>Page</th><th>Pourquoi</th></tr>
              <?php foreach ($aArbitrer as $m): ?>
                <tr>
                  <td><?= h((string)$m['nom']) ?>
                    <div class="crgi-muet" style="font-size:11.5px"><?= h((string)$m['code_postal']) ?>
                      <?= h((string)$m['ville']) ?></div></td>
                  <td class="num"><?= (int)$m['page'] ?></td>
                  <td class="crgi-muet" style="font-size:12px"><?= h((string)$m['motif']) ?></td>
                </tr>
              <?php endforeach; ?>
            </table></div>
          </details>
        <?php endif; ?>
      </div>

      <div class="crgi-carte">
        <h2>Validation de la phase 2</h2>
        <?php if ($etat2['validee'] && !$etat2['perimee']): ?>
          <p class="crgi-sous"><?= crgi_pastille('VALIDEE') ?>
            le <?= h((string)($etat2['ligne']['valide_le'] ?? '')) ?>.
            La phase 3 — Locataires / Occupation — n'est pas encore livrée.</p>
        <?php else: ?>
          <p class="crgi-sous">
            La phase 2 est une <b>confrontation</b> : rien n'a été créé, modifié, archivé ni
            supprimé dans MBI. La validation scelle le patrimoine examiné.
          </p>
          <button class="crgi-b or" id="btnValider2">Valider la phase 2</button>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($etat2['validee'] && !$etat2['perimee']): ?>
    <div class="crgi-carte">
      <h2>Phase 3 — Locataires / occupation</h2>
      <p class="crgi-sous">
        Pour chaque lot, la suite de ses occupants sur <b>toutes les périodes du dépôt</b> —
        pas seulement la dernière. C'est la succession documentaire qui démontre <i>quand</i>
        le titulaire change. <b>Un ancien locataire n'est jamais supprimé</b>, sa dette lui
        reste attachée, et <b>une absence n'est pas un départ</b>.
      </p>
      <div class="crgi-actions" style="border:0;padding-top:0;margin-top:0">
        <button class="crgi-b or" id="btnPhase3">
          <?= $bilan3 ? 'Relancer la lecture d’occupation' : 'Lancer la lecture (phase 3)' ?></button>
        <span class="crgi-aide" id="etatPhase3"></span>
      </div>
    </div>

    <?php if ($bilan3): $s3 = $bilan3['statuts']; ?>
      <div class="crgi-carte">
        <h2>Bilan de la phase 3</h2>
        <p class="crgi-sous">
          <?= (int)$bilan3['observations'] ?> observations sur
          <b><?= (int)$bilan3['lots'] ?> lots</b>,
          <?= (int)$bilan3['locataires'] ?> locataires et
          <b><?= (int)$bilan3['periodes'] ?> périodes</b>.
          Les encours sont des <b>photographies</b> : jamais additionnées entre deux périodes.
          <code>STOCK ≠ FLUX</code>.
        </p>
        <?php
        $ok3 = fn(bool $c) => $c ? 'ok' : 'mal';
        $cases3 = [
            ['v' => (int)($s3['IDENTIQUE'] ?? 0), 'l' => 'identiques', 'c' => 'ok'],
            ['v' => (int)($s3['NOUVEL ENTRANT'] ?? 0), 'l' => 'nouveaux entrants', 'c' => 'ok'],
            ['v' => (int)($s3['CHANGEMENT DE LOCATAIRE'] ?? 0),
             'l' => 'changements de locataire', 'c' => 'ok'],
            ['v' => (int)($s3['PARTI DEMONTRE'] ?? 0), 'l' => 'partis DÉMONTRÉS', 'c' => 'ok'],
            ['v' => (int)($s3['ANCIEN LOCATAIRE AVEC DETTE'] ?? 0),
             'l' => 'anciens locataires AVEC DETTE', 'c' => 'ok'],
            ['v' => (int)($s3['A ARBITRER'] ?? 0), 'l' => 'à arbitrer',
             'c' => $ok3((int)($s3['A ARBITRER'] ?? 0) === 0)],
            ['v' => (int)($bilan3['solde']['NON DEMONTRABLE'] ?? 0),
             'l' => 'soldes NON DÉMONTRABLES', 'c' => 'ok'],
        ]; ?>
        <div class="crgi-bilan">
          <?php foreach ($cases3 as $c): ?>
            <div class="<?= $c['c'] ?>">
              <div class="v"><?= number_format((int)$c['v'], 0, ',', ' ') ?></div>
              <div class="l"><?= h($c['l']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="crgi-note">
          <b>ABSENCE DANS UN NOUVEAU CRG ≠ DÉPART.</b> Un départ n'est <code>DÉMONTRÉ</code>
          que si le lot est réénoncé plus tard sans cet occupant. Un lot qui cesse simplement
          d'apparaître reste <code>À ARBITRER</code> — le CRG peut ne pas avoir été déposé.
          Et la dette d'un ancien locataire <b>lui reste attachée</b> : elle ne passe jamais au
          suivant (<code>P6A-CREANCE-07</code>).
        </div>

        <h2 style="margin-top:18px">Les <?= (int)$bilan3['lots_changes'] ?> lots dont la chronologie bouge</h2>
        <p class="crgi-sous">
          Locataire avant → locataire actuel, période par période, avec l'encours photographié
          à chaque arrêté. La variation compare la <b>première</b> et la <b>dernière</b>
          photographie lisibles — elle ne cumule rien.
        </p>
        <div class="crgi-defile"><table>
          <tr><th>Lot</th><th>Chronologie</th><th class="num">Encours</th>
              <th class="num">Variation</th></tr>
          <?php foreach ($bilan3['chronos'] as $ch): ?>
            <tr>
              <td><b><?= h((string)$ch['lot']) ?></b>
                <div class="crgi-muet" style="font-size:11.5px">compte <?= h((string)$ch['compte']) ?></div>
                <div class="crgi-muet" style="font-size:11.5px"><?= (int)$ch['periodes'] ?> périodes</div></td>
              <td style="font-size:12.5px">
                <?php foreach ($ch['suite'] as $o): ?>
                  <div>
                    <span class="crgi-muet"><?= h((string)$o['periode_cle']) ?></span> :
                    <b><?= h((string)($o['locataire'] ?? '— aucun locataire imprimé —')) ?></b>
                    <span class="crgi-cert <?= in_array((string)$o['statut'], ['A ARBITRER'], true) ? 'INDETERMINABLE' : (((string)$o['statut'] === 'IDENTIQUE') ? 'CERTAIN' : 'PROBABLE') ?>"
                          style="font-size:11px"><?= h((string)$o['statut']) ?></span>
                  </div>
                <?php endforeach; ?>
              </td>
              <td class="num" style="font-size:12.5px">
                <?php foreach ($ch['suite'] as $o): ?>
                  <div><?= $o['solde_source'] === 'LUE'
                        ? number_format((float)$o['solde'], 2, ',', ' ') . ' €'
                        : '<span class="crgi-muet">non démontrable</span>' ?></div>
                <?php endforeach; ?>
              </td>
              <td class="num">
                <?php if ($ch['variation'] === null): ?>
                  <span class="crgi-muet">non calculable</span>
                <?php else: ?>
                  <b style="color:<?= $ch['variation'] > 0 ? '#b3261e' : '#2f7d5d' ?>">
                    <?= ($ch['variation'] > 0 ? '+' : '') . number_format($ch['variation'], 2, ',', ' ') ?> €</b>
                  <div class="crgi-muet" style="font-size:11px">
                    <?= h((string)($ch['premier']['periode_cle'] ?? '')) ?> →
                    <?= h((string)($ch['dernier']['periode_cle'] ?? '')) ?></div>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </table></div>
      </div>

      <div class="crgi-carte">
        <h2>Validation de la phase 3</h2>
        <?php if ($etat3['validee'] && !$etat3['perimee']): ?>
          <p class="crgi-sous"><?= crgi_pastille('VALIDEE') ?>
            le <?= h((string)($etat3['ligne']['valide_le'] ?? '')) ?>.
            La phase 4 — Finances — n'est pas encore livrée.</p>
        <?php else: ?>
          <p class="crgi-sous">
            La phase 3 est une <b>lecture</b> : rien n'a été créé, modifié, archivé ni
            supprimé dans MBI, et aucun ancien locataire n'a été effacé.
          </p>
          <button class="crgi-b or" id="btnValider3">Valider la phase 3</button>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>


  <?php if ($etat3['validee'] && !$etat3['perimee']): ?>
    <div class="crgi-carte">
      <h2>Phase 4 — Finances</h2>
      <p class="crgi-sous">
        Chaque euro avec sa <b>nature</b>, sa <b>maille</b> et sa <b>page</b>. Dans ce document
        la <b>colonne EST la nature</b> : le même « 474,49 » est un loyer appelé sous
        <code>Loyers</code>, un encaissement sous <code>Crédit</code>, un impayé sous
        <code>Reste dû</code>. La lecture est donc géométrique — jamais au blanc, jamais au
        libellé seul.
      </p>
      <div class="crgi-actions" style="border:0;padding-top:0;margin-top:0">
        <button class="crgi-b or" id="btnPhase4">
          <?= $bilan4 ? 'Relancer la lecture financière' : 'Lancer la lecture (phase 4)' ?></button>
        <span class="crgi-aide" id="etatPhase4"></span>
      </div>
    </div>

    <?php if ($bilan4):
      $f4 = [];
      foreach ($bilan4['categories'] as $c) { $f4[(string)$c['categorie']] = $c; }
      $ns4 = [];
      foreach ($bilan4['non_sommes'] as $c) { $ns4[(string)$c['categorie']] = $c; }
      $eur = fn($v) => number_format((float)$v, 2, ',', ' ') . ' €';
      $mailles = [];
      foreach ($bilan4['par_maille'] as $r) {
          $mailles[(string)$r['categorie']][(string)$r['maille']] = (int)$r['n'];
      }
      ?>
      <div class="crgi-carte">
        <h2>Bilan de la phase 4</h2>
        <p class="crgi-sous">
          <?= number_format((int)$bilan4['mouvements'], 0, ',', ' ') ?> mouvements élémentaires
          sur <b><?= (int)$bilan4['crg'] ?> CRG</b> et
          <?= (int)$bilan4['pages'] ?> pages. Aucun total n'est stocké : tout se recalcule
          depuis ces lignes, et chacune renvoie à sa page.
        </p>

        <div class="crgi-note">
          <b>ON NE SOMME PAS CE DÉPÔT EN UN SEUL CHIFFRE.</b> <b>60 comptes sur 72</b> y ont des
          CRG dont les périodes <b>se chevauchent</b> — le loyer d'avril est énoncé dans le
          relevé d'avril <i>et</i> dans celui d'avril-mai. Un « total du dépôt » compterait donc
          avril deux fois. Les montants sont présentés <b>par date d'arrêté</b>, jamais cumulés.
        </div>

        <h2 style="margin-top:18px">Les flux, par date d'arrêté</h2>
        <div class="crgi-defile"><table>
          <tr><th>Arrêté</th>
            <th class="num">Loyers appelés</th><th class="num">Charges appelées</th>
            <th class="num">Autres appelés</th><th class="num">Encaissements</th>
            <th class="num">Charges</th><th class="num">Frais / assurances</th>
            <th class="num">Versements propriétaire</th></tr>
          <?php foreach ($bilan4['par_arrete'] as $arrete => $v):
            $c = fn(string $k) => isset($v[$k])
                ? '<a href="' . h(app_url('/admin/admin_crg_integration.php?import=' . $importId
                    . '&mvt=' . urlencode($k) . '&arrete=' . urlencode((string)$arrete)))
                  . '">' . number_format((float)$v[$k]['t'], 2, ',', ' ') . ' €'
                  . '<div class="crgi-muet" style="font-size:11px">' . (int)$v[$k]['n']
                  . ' lignes</div></a>'
                : '<span class="crgi-muet">—</span>'; ?>
            <tr>
              <td><b><?= h((string)$arrete) ?></b></td>
              <td class="num"><?= $c('LOYER APPELE') ?></td>
              <td class="num"><?= $c('CHARGE APPELEE AU LOCATAIRE') ?></td>
              <td class="num"><?= $c('AUTRE APPELE AU LOCATAIRE') ?></td>
              <td class="num"><?= $c('ENCAISSEMENT') ?></td>
              <td class="num"><?= $c('CHARGE') ?></td>
              <td class="num"><?= $c('FRAIS ET ASSURANCES') ?></td>
              <td class="num"><?= $c('VERSEMENT PROPRIETAIRE') ?></td>
            </tr>
          <?php endforeach; ?>
        </table></div>
        <div class="crgi-note">
          <b>APPEL ≠ ENCAISSEMENT.</b> Un encaissement peut solder une période <b>antérieure</b> :
          sur le premier CRG lu, 104,00 € et 474,49 € portent sur décembre et janvier, hors de la
          période du relevé. L'écart <code>appelé − encaissé</code> n'est donc jamais
          « l'impayé de la période », et la phase 4 ne le calcule nulle part.
        </div>

        <h2 style="margin-top:18px">Ce que le dépôt démontre, et à quelle maille</h2>
        <p class="crgi-sous">
          <b>Cette table ne porte volontairement aucun montant.</b> Un cumul sur tout le dépôt
          compterait deux fois les périodes qui se chevauchent : les montants ne se lisent que
          dans le tableau <b>par date d’arrêté</b> ci-dessus.
        </p>
        <div class="crgi-defile"><table>
          <tr><th>Catégorie</th><th class="num">Lignes</th>
              <th>Maille de la preuve</th><th>Ce que c'est</th></tr>
          <?php
          $lignes4 = [
            ['LOYER APPELE', 'Colonne <code>Loyers</code> du tableau d’appels du lot.'],
            ['CHARGE APPELEE AU LOCATAIRE',
             'Colonne <code>Charges</code> — provision appelée <b>au locataire</b>. '
             . '<b>DÉPENSE ≠ APPEL LOCATAIRE</b> : ce n’est pas une dépense du propriétaire.'],
            ['AUTRE APPELE AU LOCATAIRE', 'Colonne <code>Autres</code> du tableau d’appels.'],
            ['ENCAISSEMENT',
             'Colonne <code>Crédit</code> — somme reçue, qui peut solder de l’antérieur.'],
            ['CHARGE',
             'Sections <i>Factures dues</i>, <i>Charges de syndic</i>, '
             . '<i>Charges Propriétaire</i>.'],
            ['FRAIS ET ASSURANCES',
             'Sections <i>Honoraires de Gestion</i>, <i>GLI</i>, <i>GU Assurance</i>.'],
            ['VERSEMENT PROPRIETAIRE',
             'Ligne <code>Virement : … €</code>, écrite en clair hors de toute colonne.'],
          ];
          foreach ($lignes4 as [$cat, $quoi]):
            $c = $f4[$cat] ?? null; ?>
            <tr>
              <td><b><?= h($cat) ?></b></td>
              <td class="num"><?= $c ? number_format((int)$c['n'], 0, ',', ' ') : '0' ?></td>
              <td><?php foreach ($mailles[$cat] ?? [] as $m => $n): ?>
                    <span class="crgi-cert CERTAIN" style="font-size:11px"><?= h($m) ?></span>
                  <?php endforeach; ?></td>
              <td style="font-size:12px"><?= $quoi ?></td>
            </tr>
          <?php endforeach; ?>
          <tr>
            <td><b>ACOMPTE OU APPORT PROPRIÉTAIRE</b></td>
            <td class="num">0</td>
            <td><span class="crgi-cert INDETERMINABLE" style="font-size:11px">ABSENT</span></td>
            <td style="font-size:12px">
              <b>Cette catégorie n’existe pas dans ce dépôt, et c’est démontré</b> — pas supposé.
              Sur les 646 pages lues, aucune section d’acompte ni d’apport. Les seuls voisins
              sont 8 <i>« Reversement dépôt de garantie »</i> et 8 <i>« Avance de Trésorerie —
              Appel N° »</i>, qui sont des <b>appels de copropriété</b>, donc des charges.
            </td>
          </tr>
        </table></div>

        <h2 style="margin-top:18px">Les stocks, et ce qu'on ne somme jamais</h2>
        <div class="crgi-bilan">
          <div class="ok">
            <div class="v"><?= $eur($bilan4['encours']) ?></div>
            <div class="l">encours — <b>dernière situation connue</b> de chaque compte</div>
          </div>
          <div class="ok">
            <div class="v"><?= number_format((int)($ns4['ENCOURS']['n'] ?? 0), 0, ',', ' ') ?></div>
            <div class="l">photographies d'encours lues</div>
          </div>
          <div class="ok">
            <div class="v"><?= number_format((int)($ns4['SOLDE']['n'] ?? 0), 0, ',', ' ') ?></div>
            <div class="l">soldes (stocks)</div>
          </div>
          <div class="ok">
            <div class="v"><?= number_format((int)($ns4['AGREGAT (NON ADDITIONNABLE)']['n'] ?? 0), 0, ',', ' ') ?></div>
            <div class="l">agrégats du <i>Récapitulatif</i></div>
          </div>
          <div class="ok">
            <div class="v"><?= number_format((int)$bilan4['reimpressions'], 0, ',', ' ') ?></div>
            <div class="l">lignes de pages réimprimées</div>
          </div>
          <div class="<?= (int)$bilan4['indetermines'] === 0 ? 'ok' : 'mal' ?>">
            <div class="v"><?= (int)$bilan4['indetermines'] ?></div>
            <div class="l">INDÉTERMINABLES</div>
          </div>
        </div>
        <div class="crgi-note">
          <b>STOCK ≠ FLUX.</b> L'encours affiché est la <b>dernière photographie</b> de chaque
          compte, jamais la somme des photographies successives.
          <b>AGRÉGAT ≠ MOUVEMENT ÉLÉMENTAIRE</b> : le <i>Récapitulatif des immeubles</i> rejoue
          par immeuble ce que les blocs ont déjà dit — il est lu et conservé pour servir de
          contrôle, jamais additionné. Les <b>118 lignes réimprimées</b> viennent de 3 CRG du
          compte <code>1105403390</code> qui contiennent leurs propres pages <b>deux fois,
          caractère pour caractère</b> : conservées, tracées, jamais comptées. Cette
          démonstration porte sur le <b>texte de la page</b>, jamais sur les montants.
        </div>

        <h2 style="margin-top:18px">Agence → période → compte</h2>
        <p class="crgi-sous">
          Chaque montant renvoie à ses lignes sources et à leurs pages PDF.
        </p>
        <?php
        $arbre = [];
        foreach ($bilan4['arbre'] as $r) {
            $arbre[(string)$r['agence']][(string)$r['periode_cle']][(string)$r['compte']][] = $r;
        }
        foreach ($arbre as $agence => $periodes): ?>
          <details class="crgi-repli" open>
            <summary><b><?= h($agence) ?></b> — <?= count($periodes) ?> périodes</summary>
            <?php foreach ($periodes as $periode => $comptes): ?>
              <details class="crgi-repli" style="margin-left:14px">
                <summary><?= h($periode) ?> — <?= count($comptes) ?> comptes</summary>
                <div class="crgi-defile"><table>
                  <tr><th>Compte</th><th>Propriétaire</th>
                    <th class="num">Appelé</th><th class="num">Encaissé</th>
                    <th class="num">Charges</th><th class="num">Frais</th>
                    <th class="num">Versé</th></tr>
                  <?php foreach ($comptes as $compte => $lignes):
                    $t = [];
                    foreach ($lignes as $l) { $t[(string)$l['categorie']] = $l; }
                    $prop = (string)($lignes[0]['proprietaire'] ?? '');
                    $cell = function (string $k) use ($t, $importId, $compte) {
                        if (!isset($t[$k])) { return '<span class="crgi-muet">—</span>'; }
                        return '<a href="' . h(app_url('/admin/admin_crg_integration.php?import='
                            . $importId . '&mvt=' . urlencode($k) . '&compte='
                            . urlencode((string)$compte))) . '">'
                          . number_format((float)$t[$k]['total'], 2, ',', ' ') . ' €'
                          . '<div class="crgi-muet" style="font-size:11px">'
                          . (int)$t[$k]['n'] . ' lignes · p.' . (int)$t[$k]['page']
                          . '</div></a>';
                    }; ?>
                    <tr>
                      <td><code><?= h((string)$compte) ?></code></td>
                      <td style="font-size:12px"><?= h(mb_substr($prop, 0, 34)) ?></td>
                      <td class="num"><?= $cell('LOYER APPELE') ?></td>
                      <td class="num"><?= $cell('ENCAISSEMENT') ?></td>
                      <td class="num"><?= $cell('CHARGE') ?></td>
                      <td class="num"><?= $cell('FRAIS ET ASSURANCES') ?></td>
                      <td class="num"><?= $cell('VERSEMENT PROPRIETAIRE') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </table></div>
              </details>
            <?php endforeach; ?>
          </details>
        <?php endforeach; ?>
      </div>

      <?php if ($detailMvt): ?>
        <div class="crgi-carte">
          <h2>Les lignes derrière ce montant</h2>
          <p class="crgi-sous">
            <?= h((string)($_GET['mvt'] ?? '')) ?>
            <?= isset($_GET['compte']) ? ' · compte ' . h((string)$_GET['compte']) : '' ?>
            <?= isset($_GET['arrete']) ? ' · arrêté ' . h((string)$_GET['arrete']) : '' ?>
            — <?= count($detailMvt) ?> lignes, chacune avec sa page et la colonne qui la démontre.
          </p>
          <div class="crgi-defile"><table>
            <tr><th>Page</th><th>Date</th><th>Libellé</th><th>Lot</th><th>Locataire</th>
                <th>Colonne</th><th class="num">Montant</th></tr>
            <?php foreach ($detailMvt as $m): ?>
              <tr>
                <td><b><?= (int)$m['page'] ?></b></td>
                <td style="font-size:12px"><?= h((string)($m['date_piece'] ?? '—')) ?></td>
                <td style="font-size:12px"><?= h(mb_substr((string)$m['libelle'], 0, 62)) ?></td>
                <td style="font-size:12px"><?= h((string)($m['lot_reference'] ?? '—')) ?></td>
                <td style="font-size:12px"><?= h(mb_substr((string)($m['locataire'] ?? '—'), 0, 24)) ?></td>
                <td><span class="crgi-cert CERTAIN" style="font-size:11px"><?= h((string)$m['colonne']) ?></span></td>
                <td class="num"><b><?= number_format((float)$m['montant'], 2, ',', ' ') ?> €</b></td>
              </tr>
            <?php endforeach; ?>
          </table></div>
          <p class="crgi-sous" style="margin-top:10px">
            <a href="<?= h(app_url('/admin/admin_crg_integration.php?import=' . $importId)) ?>">
              ← revenir au bilan</a>
          </p>
        </div>
      <?php endif; ?>

      <div class="crgi-carte">
        <h2>Validation de la phase 4</h2>
        <?php if ($etat4['validee'] && !$etat4['perimee']): ?>
          <p class="crgi-sous"><?= crgi_pastille('VALIDEE') ?>
            le <?= h((string)($etat4['ligne']['valide_le'] ?? '')) ?>.
            La phase 5 n'est pas encore livrée.</p>
        <?php else: ?>
          <p class="crgi-sous">
            La phase 4 est une <b>lecture</b> : rien n'a été créé, modifié ni supprimé dans MBI.
            Les <?= number_format((int)$bilan4['mouvements'], 0, ',', ' ') ?> mouvements
            correspondent <b>exactement</b> aux montants imprimés sur les pages lues — ni perte,
            ni doublon.
          </p>
          <button class="crgi-b or" id="btnValider4">Valider la phase 4</button>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>


  <?php if ($etat4['validee'] && !$etat4['perimee']): ?>
    <div class="crgi-carte">
      <h2>Phase 5 — Bilan avant intégration</h2>
      <p class="crgi-sous">
        Une seule question : <b>si je valide l'intégration, qu'est-ce qui sera créé, mis à jour,
        archivé, laissé inchangé, arbitré ou refusé ?</b> Cette phase ne lit plus aucun PDF —
        tout ce qu'elle affirme est <b>dérivé des phases 0 à 4, scellées</b>.
      </p>
      <div class="crgi-actions" style="border:0;padding-top:0;margin-top:0">
        <button class="crgi-b or" id="btnPhase5">
          <?= $bilan5 ? 'Recalculer le bilan' : 'Établir le bilan (phase 5)' ?></button>
        <span class="crgi-aide" id="etatPhase5"></span>
      </div>
    </div>

    <?php if ($bilan5):
      $couleur = [
        'CREER' => '#2f7d5d', 'METTRE A JOUR' => '#8a6d1f', 'ARCHIVER' => '#7a5aa8',
        'INCHANGE' => '#6b7280', 'A ARBITRER' => '#b3261e', 'NON INTEGRABLE' => '#6b7280',
      ];
      $libelle = [
        'CREER' => 'CRÉER', 'METTRE A JOUR' => 'METTRE À JOUR', 'ARCHIVER' => 'ARCHIVER',
        'INCHANGE' => 'INCHANGÉ', 'A ARBITRER' => 'À ARBITRER',
        'NON INTEGRABLE' => 'NON INTÉGRABLE',
      ]; ?>
      <div class="crgi-carte">
        <h2>Ce qui serait écrit dans MBI</h2>
        <p class="crgi-sous">
          <b>Rien n'est écrit à ce stade</b>, et aucun bouton de cet écran n'écrit dans MBI.
          Le plan rend compte des <b><?= number_format((int)$bilan5['mouvements'], 0, ',', ' ') ?>
          mouvements</b> de la phase 4
          <?= $bilan5['boucle']
              ? '<b style="color:#2f7d5d">sans en laisser un seul de côté</b>'
              : '<b style="color:#b3261e">— MAIS ' . (int)($bilan5['mouvements'] - $bilan5['couverts'])
                . ' RESTENT HORS DU BILAN</b>' ?>.
        </p>
        <div class="crgi-bilan">
          <?php foreach (['CREER', 'METTRE A JOUR', 'ARCHIVER', 'INCHANGE', 'A ARBITRER',
                          'NON INTEGRABLE'] as $a): ?>
            <div class="<?= $a === 'A ARBITRER' && (int)($bilan5['par_action'][$a] ?? 0) > 0
                            ? 'mal' : 'ok' ?>">
              <div class="v"><?= number_format((int)($bilan5['par_action'][$a] ?? 0), 0, ',', ' ') ?></div>
              <div class="l"><?= h($libelle[$a]) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="crgi-note">
          <b>« SUPPRIMER » N'EXISTE PAS DANS CE VOCABULAIRE.</b> Le maximum est
          <code>ARCHIVER</code>, et il se démontre.
          <b>ABSENT DU NOUVEAU CORPUS ≠ SUPPRIMER</b> — les propriétaires, comptes, immeubles et
          lots que ce dépôt ne mentionne pas restent intacts.
          <b>ANCIEN LOCATAIRE ≠ SUPPRIMER</b> — une succession CLÔTURE l'occupation précédente
          et laisse la dette à son titulaire (<code>P6A-CREANCE-07</code>).
          <b>ARBITRAGE OUVERT ≠ ÉCRITURE AUTORISÉE</b> — chaque arbitrage dit ci-dessous ce
          qu'il bloque, et ce qu'il ne bloque pas.
        </div>

        <?php foreach ($bilan5['par_famille'] as $famille => $lignes): ?>
          <h2 style="margin-top:18px"><?= h($famille) ?></h2>
          <div class="crgi-defile"><table>
            <tr><th style="width:150px">Action</th><th class="num">Nombre</th><th>Maille</th>
                <th>Ce qui le justifie</th><th>Source</th></tr>
            <?php foreach ($lignes as $l): $a = (string)$l['action']; ?>
              <tr<?= (int)$l['nombre'] === 0 ? ' style="opacity:.55"' : '' ?>>
                <td><b style="color:<?= $couleur[$a] ?? '#6b7280' ?>"><?= h($libelle[$a] ?? $a) ?></b></td>
                <td class="num"><b><?= number_format((int)$l['nombre'], 0, ',', ' ') ?></b></td>
                <td style="font-size:12px"><?= h((string)$l['maille']) ?></td>
                <td style="font-size:12px">
                  <?= h((string)$l['motif']) ?>
                  <?php if ($l['bloque']): ?>
                    <div style="margin-top:4px;color:#b3261e;font-size:11.5px">
                      <b>Impact réel :</b> <?= h((string)$l['bloque']) ?></div>
                  <?php endif; ?>
                </td>
                <td style="font-size:11.5px"><span class="crgi-muet"><?= h((string)$l['source']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </table></div>
        <?php endforeach; ?>
      </div>

      <div class="crgi-carte">
        <h2><?= count($bilan5['arbitrages']) ?> lots en arbitrage ouvert
          — <?= (int)($bilan5['par_action']['A ARBITRER'] ?? 0) ?> décisions en attente</h2>
        <p class="crgi-sous">
          Un arbitrage n'est pas un défaut du système : il matérialise ce que le document
          <b>ne permet pas encore de démontrer sans décision humaine</b>. Chacun dit exactement
          ce qu'il empêche.
        </p>
        <div class="crgi-defile"><table>
          <tr><th>Compte</th><th>Lot</th><th class="num">Obs.</th><th class="num">Page</th>
              <th>Ce que le document ne démontre pas</th><th>Impact</th></tr>
          <?php foreach ($bilan5['arbitrages'] as $a): ?>
            <tr>
              <td><code><?= h((string)$a['compte']) ?></code></td>
              <td><b><?= h((string)$a['lot_reference']) ?></b></td>
              <td class="num"><?= (int)$a['n'] ?></td>
              <td class="num"><?= (int)$a['page'] ?></td>
              <td style="font-size:12px"><?= h(mb_substr((string)$a['motif'], 0, 150)) ?></td>
              <td style="font-size:11.5px">
                <span class="crgi-cert INDETERMINABLE" style="font-size:11px">occupation bloquée</span>
                <div class="crgi-muet" style="margin-top:3px">
                  n'empêche ni la création du lot, ni ses mouvements financiers</div>
              </td>
            </tr>
          <?php endforeach; ?>
        </table></div>
      </div>

      <div class="crgi-carte">
        <h2>Validation du bilan avant intégration</h2>
        <?php if ($etat5['validee'] && !$etat5['perimee']): ?>
          <p class="crgi-sous"><?= crgi_pastille('VALIDEE') ?>
            le <?= h((string)($etat5['ligne']['valide_le'] ?? '')) ?>.
            <b>Aucune écriture n'a été faite dans MBI</b> — valider ce bilan, c'est valider
            le <i>plan</i>, pas l'exécuter. L'intégration elle-même n'est pas livrée.</p>
        <?php else: ?>
          <p class="crgi-sous">
            Valider ce bilan signifie : <b>« je reconnais que c'est bien cela qui serait
            écrit »</b>. <b>Cela n'écrit rien.</b> Aucun bouton de cette page ne touche aux
            données métier de MBI, et l'import reste annulable dans son intégralité.
          </p>
          <button class="crgi-b or" id="btnValider5">Valider le bilan avant intégration</button>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

    <div class="crgi-carte">
      <h2>Validation de la phase 0</h2>
      <?php if ($etat0['validee'] && !$etat0['perimee']): ?>
        <p class="crgi-sous">
          <?= crgi_pastille('VALIDEE') ?> le <?= h((string)($etat0['ligne']['valide_le'] ?? '')) ?>.
          La phase 1 pourra s'ouvrir — elle n'est pas encore livrée.
        </p>
      <?php elseif ($etat0['validee'] && $etat0['perimee']): ?>
        <div class="crgi-note rouge">
          <b>Validation périmée.</b> L'analyse a été rejouée depuis, et le découpage n'est plus
          celui qui a été validé. Une validation porte sur un résultat précis, pas sur une
          phase : il faut réexaminer le bilan et valider à nouveau.
        </div>
        <button class="crgi-b or" id="btnValider">Valider la phase 0</button>
      <?php else: ?>
        <?php if (($bilan['bloquants'] ?? 0) > 0): ?>
          <div class="crgi-note rouge">
            <b>Identification incomplète — la phase 0 n'est pas validable en l'état.</b><br>
            <?= (int)($bilan['agence_source']['INDETERMINABLE'] ?? 0) ?> CRG sans agence établie,
            <?= (int)($bilan['periode_source']['INDETERMINABLE'] ?? 0) ?> sans période.
            La phase 0 doit répondre à <i>quel CRG → quelle agence → quelle période → quel
            compte → quelles pages</i> : signer un découpage dont on ignore l'agence ou le mois,
            c'est signer une page à moitié écrite. Les lignes concernées portent leur motif
            dans le tableau ci-dessus — elles demandent un arbitrage.
          </div>
        <?php endif; ?>
        <p class="crgi-sous">
          Examinez le bilan et les CRG ci-dessus. <b>Le moteur ne passera pas à la phase 1
          sans cette validation</b>, et la validation scelle l'empreinte du découpage examiné :
          si l'analyse est rejouée et que le résultat change, elle ne vaudra plus.
        </p>
        <button class="crgi-b or" id="btnValider"
                <?= ($bilan['bloquants'] ?? 0) > 0 ? 'disabled' : '' ?>>Valider la phase 0</button>
      <?php endif; ?>
    </div>
  <?php elseif ($pieces): ?>
    <?php
    // ⚠️ NE PAS DIRE « LANCEZ L'ANALYSE » QUAND ELLE A DÉJÀ ÉCHOUÉ. Sur le document réel de
    //    906 pages, la page invitait à relancer une analyse qui venait de buter sur un PDF
    //    sans couche texte : l'écran cachait la panne derrière une invitation.
    $bloquee = ($phases[0]['statut'] ?? '') === 'BLOQUEE';
    $illisibles = array_filter($pieces, fn($p) => $p['etat'] === 'ILLISIBLE'); ?>
    <?php if ($bloquee || $illisibles): ?>
      <div class="crgi-note rouge">
        <b>Phase 0 bloquée — la lecture a échoué, il n'y a rien à valider.</b>
        <?= h((string)($phases[0]['message'] ?? '')) ?>
        <?php foreach ($illisibles as $p): ?>
          <div style="margin-top:8px"><b><?= h((string)$p['nom_original']) ?></b> —
            <?= h((string)$p['message']) ?></div>
        <?php endforeach; ?>
        <div style="margin-top:8px">
          Zéro CRG sur un document non vide est une <b>panne de lecture</b>, jamais un constat :
          la phase reste bloquée tant que la cause n'est pas levée.
        </div>
      </div>
    <?php else: ?>
      <div class="crgi-note">
        Les pièces sont déposées. Lancez l'analyse pour reconstruire les CRG logiques.
      </div>
    <?php endif; ?>
  <?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

</div>

<script>
const CRGI = {
  csrf: <?= json_encode($csrf) ?>,
  // ⚠️ `fetch()` résout aussi contre le `<base href>` : un chemin relatif viserait
  //    `/MaBoxImmo2026/public_html/api/…` depuis n'importe quelle page. On l'écrit en absolu.
  api: <?= json_encode(app_url('/api/crg_integration_action.php')) ?>,
  importId: <?= (int)$importId ?>,
  // ⚠️ 6 Mo : nettement sous le post_max_size du serveur (<?= h(ini_get('post_max_size')) ?>).
  //    Une tranche qui frôlerait la limite échouerait sans message, exactement le défaut
  //    qu'on cherche à éviter.
  page: <?= json_encode(app_url('/admin/admin_crg_integration.php')) ?>,
  tranche: 6 * 1024 * 1024,
};

function journal(txt) {
  const j = document.getElementById('journal');
  if (!j) return;
  j.hidden = false;
  j.insertAdjacentHTML('beforeend', txt + '<br>');
  j.scrollTop = j.scrollHeight;
}

async function envoyer(data) {
  data.append('csrf_token', CRGI.csrf);
  const r = await fetch(CRGI.api, {method: 'POST', body: data});
  const j = await r.json().catch(() => ({ok: false, erreur: 'Réponse illisible du serveur.'}));
  if (!j.ok) throw new Error(j.erreur || 'Erreur inconnue.');
  return j;
}

// ── Dépôt par tranches ────────────────────────────────────────────────────────────────
document.getElementById('btnDeposer')?.addEventListener('click', async (e) => {
  const fichiers = document.getElementById('fichiers').files;
  if (!fichiers.length) { alert('Choisissez au moins un PDF.'); return; }
  e.target.disabled = true;
  try {
    const f = new FormData();
    f.append('action', 'creer');
    f.append('libelle', document.getElementById('libelle').value);
    const {import_id} = await envoyer(f);
    journal('Import n°' + import_id + ' ouvert.');

    let totalOctets = 0, envoyes = 0;
    for (const fi of fichiers) totalOctets += fi.size;

    for (const fi of fichiers) {
      journal('→ ' + fi.name + ' (' + (fi.size / 1048576).toFixed(1) + ' Mo)');
      for (let i = 0, n = 0; i < fi.size; i += CRGI.tranche, n++) {
        const d = new FormData();
        d.append('action', 'tranche');
        d.append('import_id', import_id);
        d.append('nom', fi.name);
        d.append('index', n);
        d.append('tranche', fi.slice(i, i + CRGI.tranche));
        await envoyer(d);
        envoyes += Math.min(CRGI.tranche, fi.size - i);
        document.getElementById('zoneJauge').hidden = false;
        document.getElementById('jauge').style.width =
          Math.round(envoyes / totalOctets * 100) + '%';
      }
      const fin = new FormData();
      fin.append('action', 'finir');
      fin.append('import_id', import_id);
      fin.append('nom', fi.name);
      const res = await envoyer(fin);
      journal('   déposé — ' + res.pages + ' page(s)');
    }
    location.href = CRGI.page + '?import=' + import_id;
  } catch (err) {
    journal('<b style="color:#b3261e">ÉCHEC : ' + err.message + '</b>');
    e.target.disabled = false;
  }
});

// ── Rattacher un fichier déjà présent sur le serveur ──────────────────────────────────
document.getElementById('btnChemin')?.addEventListener('click', async (e) => {
  const chemin = document.getElementById('cheminServeur').value.trim();
  if (!chemin) return;
  e.target.disabled = true;
  try {
    const f = new FormData();
    f.append('action', 'creer');
    f.append('libelle', document.getElementById('libelle').value || chemin);
    const {import_id} = await envoyer(f);
    const d = new FormData();
    d.append('action', 'chemin');
    d.append('import_id', import_id);
    d.append('chemin', chemin);
    await envoyer(d);
    location.href = CRGI.page + '?import=' + import_id;
  } catch (err) {
    journal('<b style="color:#b3261e">ÉCHEC : ' + err.message + '</b>');
    e.target.disabled = false;
  }
});

// ── Phase 0 ───────────────────────────────────────────────────────────────────────────
document.getElementById('btnAnalyser')?.addEventListener('click', async (e) => {
  e.target.disabled = true;
  document.getElementById('etatAnalyse').textContent = 'analyse en cours…';
  try {
    const d = new FormData();
    d.append('action', 'analyser');
    d.append('import_id', CRGI.importId);
    const r = await envoyer(d);
    journal('Analyse terminée : ' + r.bilan.crgs + ' CRG sur ' + r.bilan.pages + ' pages.');
    location.reload();
  } catch (err) {
    document.getElementById('etatAnalyse').textContent = '';
    journal('<b style="color:#b3261e">ÉCHEC : ' + err.message + '</b>');
    e.target.disabled = false;
  }
});

document.getElementById('btnValider')?.addEventListener('click', async (e) => {
  e.target.disabled = true;
  try {
    const d = new FormData();
    d.append('action', 'valider');
    d.append('import_id', CRGI.importId);
    d.append('phase', '0');
    await envoyer(d);
    location.reload();
  } catch (err) { alert(err.message); e.target.disabled = false; }
});

document.getElementById('btnPhase1')?.addEventListener('click', async (e) => {
  e.target.disabled = true;
  document.getElementById('etatPhase1').textContent = 'inventaire en cours…';
  try {
    const d = new FormData();
    d.append('action', 'phase1');
    d.append('import_id', CRGI.importId);
    await envoyer(d);
    location.reload();
  } catch (err) {
    document.getElementById('etatPhase1').textContent = '';
    alert(err.message);
    e.target.disabled = false;
  }
});

document.getElementById('btnPhase2')?.addEventListener('click', async (e) => {
  e.target.disabled = true;
  document.getElementById('etatPhase2').textContent = 'confrontation en cours…';
  try {
    const d = new FormData();
    d.append('action', 'phase2');
    d.append('import_id', CRGI.importId);
    await envoyer(d);
    location.reload();
  } catch (err) {
    document.getElementById('etatPhase2').textContent = '';
    alert(err.message);
    e.target.disabled = false;
  }
});

document.getElementById('btnPhase3')?.addEventListener('click', async (e) => {
  e.target.disabled = true;
  document.getElementById('etatPhase3').textContent = 'lecture en cours…';
  try {
    const d = new FormData();
    d.append('action', 'phase3');
    d.append('import_id', CRGI.importId);
    await envoyer(d);
    location.reload();
  } catch (err) {
    document.getElementById('etatPhase3').textContent = '';
    alert(err.message);
    e.target.disabled = false;
  }
});

document.getElementById('btnPhase4')?.addEventListener('click', async (e) => {
  e.target.disabled = true;
  document.getElementById('etatPhase4').textContent =
    'lecture géométrique des pages — environ une minute…';
  try {
    const d = new FormData();
    d.append('action', 'phase4');
    d.append('import_id', CRGI.importId);
    await envoyer(d);
    location.reload();
  } catch (err) {
    document.getElementById('etatPhase4').textContent = '';
    alert(err.message);
    e.target.disabled = false;
  }
});

document.getElementById('btnPhase5')?.addEventListener('click', async (e) => {
  e.target.disabled = true;
  document.getElementById('etatPhase5').textContent = 'consolidation des phases scellées…';
  try {
    const d = new FormData();
    d.append('action', 'phase5');
    d.append('import_id', CRGI.importId);
    await envoyer(d);
    location.reload();
  } catch (err) {
    document.getElementById('etatPhase5').textContent = '';
    alert(err.message);
    e.target.disabled = false;
  }
});

document.getElementById('btnValider5')?.addEventListener('click', async (e) => {
  e.target.disabled = true;
  try {
    const d = new FormData();
    d.append('action', 'valider');
    d.append('import_id', CRGI.importId);
    d.append('phase', '5');
    await envoyer(d);
    location.reload();
  } catch (err) { alert(err.message); e.target.disabled = false; }
});

document.getElementById('btnValider4')?.addEventListener('click', async (e) => {
  e.target.disabled = true;
  try {
    const d = new FormData();
    d.append('action', 'valider');
    d.append('import_id', CRGI.importId);
    d.append('phase', '4');
    await envoyer(d);
    location.reload();
  } catch (err) { alert(err.message); e.target.disabled = false; }
});

document.getElementById('btnValider3')?.addEventListener('click', async (e) => {
  e.target.disabled = true;
  try {
    const d = new FormData();
    d.append('action', 'valider');
    d.append('import_id', CRGI.importId);
    d.append('phase', '3');
    await envoyer(d);
    location.reload();
  } catch (err) { alert(err.message); e.target.disabled = false; }
});

document.getElementById('btnValider2')?.addEventListener('click', async (e) => {
  e.target.disabled = true;
  try {
    const d = new FormData();
    d.append('action', 'valider');
    d.append('import_id', CRGI.importId);
    d.append('phase', '2');
    await envoyer(d);
    location.reload();
  } catch (err) { alert(err.message); e.target.disabled = false; }
});

document.getElementById('btnValider1')?.addEventListener('click', async (e) => {
  e.target.disabled = true;
  try {
    const d = new FormData();
    d.append('action', 'valider');
    d.append('import_id', CRGI.importId);
    d.append('phase', '1');
    await envoyer(d);
    location.reload();
  } catch (err) { alert(err.message); e.target.disabled = false; }
});
document.getElementById('btnAnnuler')?.addEventListener('click', async (e) => {
  const motif = prompt("Annuler cet import — pourquoi ?\n\nSes pages, ses CRG détectés et ses "
                     + "fichiers de staging seront supprimés. Aucune donnée métier de MBI "
                     + "n'a été écrite.");
  if (motif === null) return;
  e.target.disabled = true;
  try {
    const d = new FormData();
    d.append('action', 'annuler');
    d.append('import_id', CRGI.importId);
    d.append('motif', motif);
    await envoyer(d);
    location.reload();
  } catch (err) { alert(err.message); e.target.disabled = false; }
});
</script>

<?php require_once __DIR__ . '/../inc/agency_layout_bottom.php'; ?>