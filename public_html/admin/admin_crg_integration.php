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