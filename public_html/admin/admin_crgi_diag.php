<?php
declare(strict_types=1);
/**
 * ADMIN — DIAGNOSTIC D'UN IMPORT CRG (module `crgi_*`).
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CETTE PAGE EXISTE PARCE QU'IL A FALLU UNE HEURE ET DIX-HUIT REQUÊTES SQL POUR RÉPONDRE
 *    À « qu'est-ce que le moteur n'a pas su lire ? ». Le 03/09/2026, le dépôt CHAPONOST a été
 *    lu sans une modification — 197 CRG, 12 236 mouvements — mais 421 montants restaient sans
 *    nature, et RIEN à l'écran ne disait lesquels ni pourquoi. La réponse était en base ; elle
 *    n'était nulle part.
 *
 * ⚠️ ELLE SE REFAIT À CHAQUE INTÉGRATION, PARCE QU'ELLE NE GARDE RIEN. Aucun cache, aucune
 *    table de rapport : tout est recompté à l'affichage sur le staging `crgi_*`. Un rapport
 *    figé aurait vieilli au premier rejeu de phase, et on aurait arbitré sur un état mort.
 *
 * ⚠️ LECTURE SEULE, TOTALE. Pas un INSERT, pas un UPDATE, pas un DELETE — comme
 *    `admin_crg_diag.php` pour l'ancien import, dont elle est la sœur : celle-là contrôle
 *    `crg_trimestres`, celle-ci le staging du module d'intégration. Ne pas les confondre.
 *
 * ⚠️ ET ELLE NE PROPOSE AUCUNE NATURE. Elle montre ce que le document imprime — section,
 *    colonne, page, libellé, montant — et se tait. `NE JAMAIS DÉDUIRE UNE NATURE D'UN
 *    LIBELLÉ` : la qualification est un arbitrage humain, cette page ne fait que le rendre
 *    possible en dix secondes au lieu d'une heure.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/crg_integration.php';
require_admin_or_super_admin();

$pdo = $GLOBALS['pdo'];
$importId = (int)($_GET['import'] ?? 0) ?: crgi_import_courant($pdo);

$imports = $pdo->query(
    'SELECT id, libelle, statut, nb_pages, cree_le FROM crgi_import
      ORDER BY (statut = "ANNULE"), id DESC LIMIT 30'
)->fetchAll(PDO::FETCH_ASSOC);

/** Une requête bornée à l'import courant, rendue en tableau. */
$q = function (string $sql) use ($pdo, $importId): array {
    $st = $pdo->prepare($sql);
    $st->execute([$importId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
};
$un = function (string $sql) use ($pdo, $importId) {
    $st = $pdo->prepare($sql);
    $st->execute([$importId]);
    return $st->fetchColumn();
};

$courant = null;
foreach ($imports as $im) {
    if ((int)$im['id'] === $importId) {
        $courant = $im;
    }
}

// ── L'IDENTITÉ DU DÉPÔT ───────────────────────────────────────────────────────────────────
$pieces   = $q('SELECT nom_original, nb_pages, etat, message FROM crgi_piece WHERE import_id = ?');
$formats  = $q('SELECT COALESCE(format,"?") f, COUNT(*) n FROM crgi_crg WHERE import_id = ?
                 GROUP BY f ORDER BY n DESC');
$agences  = $q('SELECT COALESCE(agence,"(non imprimée)") a, agence_source, COUNT(*) n
                  FROM crgi_crg WHERE import_id = ? GROUP BY a, agence_source ORDER BY n DESC');
$phases   = $q('SELECT phase, statut, message FROM crgi_phase WHERE import_id = ? ORDER BY phase');
$nbCrg    = (int)$un('SELECT COUNT(*) FROM crgi_crg WHERE import_id = ?');
$nbMvt    = (int)$un('SELECT COUNT(*) FROM crgi_mouvement WHERE import_id = ?');
$nbPages  = (int)$un('SELECT COUNT(*) FROM crgi_page WHERE import_id = ?');
$nbAffect = (int)$un('SELECT COUNT(*) FROM crgi_page WHERE import_id = ? AND crg_id IS NOT NULL');

// ── CE QUE LE MOTEUR N'A PAS SU NOMMER ────────────────────────────────────────────────────
// ⚠️ GROUPÉ PAR SECTION × COLONNE, PARCE QUE C'EST LÀ QUE SE PREND LA DÉCISION. Une liste de
//    421 lignes ne s'arbitre pas ; quatre groupes, si.
$muets = $q(
    'SELECT COALESCE(NULLIF(section,""),"(sans section)") sec,
            COALESCE(NULLIF(colonne,""),"(hors colonne)") col,
            COUNT(*) n, ROUND(SUM(montant),2) total, MIN(page) p1, MAX(page) p2
       FROM crgi_mouvement
      WHERE import_id = ? AND categorie = "INDETERMINABLE"
      GROUP BY sec, col ORDER BY n DESC'
);
$exemples = $pdo->prepare(
    'SELECT m.page, m.libelle, m.montant, m.maille, c.compte, c.proprietaire, c.id AS crg_id
       FROM crgi_mouvement m JOIN crgi_crg c ON c.id = m.crg_id
      WHERE m.import_id = ? AND m.categorie = "INDETERMINABLE"
        AND COALESCE(NULLIF(m.section,""),"(sans section)") = ?
        AND COALESCE(NULLIF(m.colonne,""),"(hors colonne)") = ?
      ORDER BY m.page LIMIT 3'
);

$inconnues = $q(
    'SELECT SUBSTRING(section, 10) titre, COUNT(*) n, ROUND(SUM(montant),2) total
       FROM crgi_mouvement WHERE import_id = ? AND section LIKE "INCONNUE:%"
      GROUP BY titre ORDER BY n DESC'
);

// ── LES CONTRÔLES QUI DOIVENT RESTER À ZÉRO ───────────────────────────────────────────────
$controles = [
    ['Agrégats comptés comme des mouvements',
     (int)$un('SELECT COUNT(*) FROM crgi_mouvement WHERE import_id = ?
                AND categorie LIKE "AGREGAT%" AND additionnable = 1'),
     'Un agrégat additionné, c’est le document compté deux fois. `AGRÉGAT ≠ MOUVEMENT '
     . 'ÉLÉMENTAIRE`.'],
    ['Détails de calcul comptés comme des mouvements',
     (int)$un('SELECT COUNT(*) FROM crgi_mouvement WHERE import_id = ?
                AND categorie LIKE "DETAIL%" AND additionnable = 1'),
     'Une TVA ou une assiette « base: » n’est pas un mouvement distinct de celui qu’elle '
     . 'détaille.'],
    ['Réimpressions comptées deux fois',
     (int)$un('SELECT COUNT(*) FROM crgi_mouvement WHERE import_id = ?
                AND reimpression = 1 AND additionnable = 1'),
     'Une page réimprimée à l’identique n’est pas un second événement.'],
    ['Stocks additionnés à des flux',
     (int)$un('SELECT COUNT(*) FROM crgi_mouvement WHERE import_id = ?
                AND categorie IN ("ENCOURS","SOLDE") AND flux = 1'),
     '`STOCK ≠ FLUX` : un encours est une photographie, jamais un mouvement de la période.'],
    ['Pages porteuses de texte sans aucun signal',
     (int)$un('SELECT COUNT(*) FROM crgi_page WHERE import_id = ? AND crg_id IS NULL
                AND signal_page LIKE "aucun signal%"'),
     'Une page sans signal reste visible ici : c’est la seule façon d’affirmer « 0 page '
     . 'perdue » sans mentir.'],
];

// ⚠️ LE DÉTECTEUR DE DOUBLE COMPTAGE. Un même montant, sur une même page, porté par TROIS
//    natures additionnables ou plus : ce n'est pas une preuve, c'est une question. Le cas
//    « LADY NETTOYAGE » de CHAPONOST en a trois légitimes — appel au lot, encaissement au
//    lot, dépense au compte. Au-delà, on regarde.
$suspects = $q(
    'SELECT m.page, ROUND(m.montant,2) montant, COUNT(DISTINCT m.categorie) natures,
            COUNT(*) lignes, GROUP_CONCAT(DISTINCT m.categorie ORDER BY m.categorie
                                          SEPARATOR " · ") liste
       FROM crgi_mouvement m
      WHERE m.import_id = ? AND m.additionnable = 1 AND m.montant <> 0
      GROUP BY m.page, ROUND(m.montant,2)
     HAVING COUNT(DISTINCT m.categorie) >= 3
      ORDER BY natures DESC, lignes DESC LIMIT 12'
);

// ── LA COUVERTURE, POUR SITUER LES MUETS DANS L'ENSEMBLE ──────────────────────────────────
$categories = $q(
    'SELECT categorie, COUNT(*) n, SUM(additionnable) add_,
            ROUND(SUM(CASE WHEN additionnable = 1 THEN montant ELSE 0 END),2) total
       FROM crgi_mouvement WHERE import_id = ? GROUP BY categorie ORDER BY n DESC'
);
$signaux = $q(
    'SELECT signal_page, COUNT(*) n FROM crgi_page
      WHERE import_id = ? AND crg_id IS NULL GROUP BY signal_page ORDER BY n DESC'
);

$pageTitle = '🔎 Diagnostic d’un import CRG';
$extraCss  = '<link rel="stylesheet" href="' . asset_url('/css/crg_integration.css') . '">';
require_once __DIR__ . '/../inc/agency_layout_top.php';

$euro = fn($v) => number_format((float)$v, 2, ',', ' ') . ' €';
?>
<div class="crgi">

<div class="crgi-carte">
  <form method="get" class="crgi-diag-barre">
    <label for="import">Import</label>
    <select name="import" id="import" onchange="this.form.submit()">
      <?php foreach ($imports as $im): ?>
        <option value="<?= (int)$im['id'] ?>" <?= (int)$im['id'] === $importId ? 'selected' : '' ?>>
          n°<?= (int)$im['id'] ?> — <?= h($im['libelle'] ?: '(sans libellé)') ?>
          · <?= h((string)$im['statut']) ?> · <?= (int)$im['nb_pages'] ?> p.
        </option>
      <?php endforeach; ?>
    </select>
    <noscript><button type="submit">Voir</button></noscript>
    <a class="crgi-diag-lien"
       href="<?= h(app_url('/admin/admin_crg_integration.php')) ?>?import=<?= $importId ?>">
      ← revenir au parcours d’intégration
    </a>
  </form>

  <?php if (!$courant || !$nbCrg): ?>
    <p class="crgi-note">Cet import ne porte aucun CRG analysé — il n’y a rien à diagnostiquer.
      Lancez la phase 0 depuis le parcours d’intégration.</p>
  <?php else: ?>

  <div class="crgi-diag-chiffres">
    <div><span>CRG détectés</span><b><?= $nbCrg ?></b></div>
    <div><span>Pages</span><b><?= $nbAffect ?> <i>/ <?= $nbPages ?></i></b></div>
    <div><span>Mouvements</span><b><?= number_format($nbMvt, 0, ',', ' ') ?></b></div>
    <div class="<?= $muets ? 'crgi-diag-alerte' : '' ?>">
      <span>Sans nature</span>
      <b><?= array_sum(array_column($muets, 'n')) ?></b>
    </div>
  </div>

  <p class="crgi-sous">
    <?php foreach ($formats as $f): ?>
      <b><?= h($f['f']) ?></b> <?= (int)$f['n'] ?> CRG &nbsp;
    <?php endforeach; ?>
    ·
    <?php foreach ($agences as $a): ?>
      <?= h($a['a']) ?> <i>(<?= h(strtolower((string)$a['agence_source'])) ?>)</i>
      <?= (int)$a['n'] ?> &nbsp;
    <?php endforeach; ?>
  </p>
  <?php foreach ($phases as $ph): ?>
    <?php if (!empty($ph['message'])): ?>
      <p class="crgi-note">Phase <?= (int)$ph['phase'] ?> — <?= h((string)$ph['message']) ?></p>
    <?php endif; ?>
  <?php endforeach; ?>
</div>

<!-- ═══ CE QUE LE MOTEUR N'A PAS SU NOMMER ═══════════════════════════════════════════ -->
<div class="crgi-carte">
  <h2>Ce que le moteur n’a pas su nommer</h2>
  <p class="crgi-sous">
    Groupé par <b>section × colonne</b>, parce que c’est là que la décision se prend : une
    liste de montants ne s’arbitre pas, quatre groupes si. La page montre ce que le document
    imprime et <b>ne propose aucune nature</b> — une nature ne se déduit jamais d’un libellé.
  </p>

  <?php if (!$muets): ?>
    <p class="crgi-note crgi-diag-ok">Tous les montants lus portent une nature démontrée.</p>
  <?php else: ?>
    <?php foreach ($muets as $m): ?>
      <?php $exemples->execute([$importId, $m['sec'], $m['col']]); ?>
      <div class="crgi-diag-groupe">
        <div class="crgi-diag-tete">
          <b><?= h(str_replace('INCONNUE:', '', (string)$m['sec'])) ?></b>
          <?php if (str_starts_with((string)$m['sec'], 'INCONNUE:')): ?>
            <span class="crgi-diag-tag">section inconnue</span>
          <?php endif; ?>
          <span class="crgi-diag-col">colonne <?= h((string)$m['col']) ?></span>
          <span class="crgi-diag-vol">
            <?= (int)$m['n'] ?> ligne<?= (int)$m['n'] > 1 ? 's' : '' ?>
            · <?= $euro($m['total']) ?>
            · pages <?= (int)$m['p1'] ?><?= (int)$m['p1'] !== (int)$m['p2'] ? '–' . (int)$m['p2'] : '' ?>
          </span>
        </div>
        <table class="crgi-diag-table">
          <?php foreach ($exemples->fetchAll(PDO::FETCH_ASSOC) as $e): ?>
            <tr>
              <td class="crgi-diag-p">
                <a href="<?= h(app_url('/admin/crgi_page.php')) ?>?crg=<?= (int)$e['crg_id'] ?>#page=<?= (int)$e['page'] ?>"
                   title="ouvrir la page du PDF">p.<?= (int)$e['page'] ?></a>
              </td>
              <td class="crgi-diag-lib"><?= h(mb_substr((string)$e['libelle'], 0, 78)) ?></td>
              <td class="crgi-diag-m"><?= $euro($e['montant']) ?></td>
              <td class="crgi-diag-ctx">
                <?= h((string)$e['maille']) ?> ·
                <?= h((string)$e['compte']) ?>
                <?= $e['proprietaire'] ? '· ' . h(mb_substr((string)$e['proprietaire'], 0, 26)) : '' ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<!-- ═══ SECTIONS QUE LE DOCUMENT IMPRIME ET QUE LA DOCTRINE NE CONNAÎT PAS ═══════════ -->
<?php if ($inconnues): ?>
<div class="crgi-carte">
  <h2>Sections inconnues</h2>
  <p class="crgi-sous">
    Le document les imprime, la doctrine ne les nomme pas. Elles sont <b>retenues sous leur
    titre imprimé</b> et jamais interprétées. Chaque comptable nomme ses sections comme il
    l’entend : une nouvelle entrée s’<b>ajoute</b> à la table des libellés, elle n’en
    remplace aucune.
  </p>
  <table class="crgi-diag-table crgi-diag-large">
    <tr><th>Titre imprimé</th><th>Lignes</th><th>Montant</th></tr>
    <?php foreach ($inconnues as $s): ?>
      <tr>
        <td class="crgi-diag-lib"><b><?= h((string)$s['titre']) ?></b></td>
        <td><?= (int)$s['n'] ?></td>
        <td class="crgi-diag-m"><?= $euro($s['total']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<!-- ═══ LES CONTRÔLES QUI DOIVENT RESTER À ZÉRO ══════════════════════════════════════ -->
<div class="crgi-carte">
  <h2>Contrôles d’intégrité</h2>
  <table class="crgi-diag-table crgi-diag-large">
    <?php foreach ($controles as [$titre, $n, $pourquoi]): ?>
      <tr class="<?= $n > 0 ? 'crgi-diag-rouge' : '' ?>">
        <td class="crgi-diag-n"><b><?= (int)$n ?></b></td>
        <td class="crgi-diag-lib"><b><?= h($titre) ?></b><br>
          <span class="crgi-diag-ctx"><?= h($pourquoi) ?></span></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<!-- ═══ DOUBLE COMPTAGE : UNE QUESTION, PAS UNE PREUVE ═══════════════════════════════ -->
<div class="crgi-carte">
  <h2>Mêmes montants, plusieurs natures</h2>
  <p class="crgi-sous">
    Un même montant, sur une même page, porté par <b>trois natures additionnables ou plus</b>.
    <b>Ce n’est pas une anomalie</b> : une charge légitimement appelée au locataire, encaissée,
    puis supportée par le propriétaire produit trois lignes justes. C’est une <b>question</b> —
    au-delà de trois, on regarde.
  </p>
  <?php if (!$suspects): ?>
    <p class="crgi-note crgi-diag-ok">Aucun montant ne porte trois natures ou plus.</p>
  <?php else: ?>
    <table class="crgi-diag-table crgi-diag-large">
      <tr><th>Page</th><th>Montant</th><th>Natures</th><th>Lesquelles</th></tr>
      <?php foreach ($suspects as $s): ?>
        <tr>
          <td class="crgi-diag-p">p.<?= (int)$s['page'] ?></td>
          <td class="crgi-diag-m"><?= $euro($s['montant']) ?></td>
          <td class="crgi-diag-n"><?= (int)$s['natures'] ?></td>
          <td class="crgi-diag-ctx"><?= h((string)$s['liste']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<!-- ═══ COUVERTURE ══════════════════════════════════════════════════════════════════ -->
<div class="crgi-carte">
  <h2>Couverture</h2>
  <table class="crgi-diag-table crgi-diag-large">
    <tr><th>Nature</th><th>Lignes</th><th>Additionnables</th><th>Montant sommé</th></tr>
    <?php foreach ($categories as $c): ?>
      <tr class="<?= $c['categorie'] === 'INDETERMINABLE' && (int)$c['n'] ? 'crgi-diag-rouge' : '' ?>">
        <td class="crgi-diag-lib"><?= h((string)$c['categorie']) ?></td>
        <td class="crgi-diag-n"><?= (int)$c['n'] ?></td>
        <td class="crgi-diag-n"><?= (int)$c['add_'] ?></td>
        <td class="crgi-diag-m"><?= $euro($c['total']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>

  <?php if ($signaux): ?>
    <h3 class="crgi-diag-h3">Pages non rattachées à un CRG</h3>
    <table class="crgi-diag-table crgi-diag-large">
      <?php foreach ($signaux as $s): ?>
        <tr>
          <td class="crgi-diag-n"><?= (int)$s['n'] ?></td>
          <td class="crgi-diag-lib"><?= h((string)$s['signal_page']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>

<?php endif; /* $courant && $nbCrg */ ?>
</div>
<?php require_once __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
