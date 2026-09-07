<?php
declare(strict_types=1);
/**
 * SUIVI PAR PHASE — une phase, ses chiffres, et SES SEULES QUESTIONS.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CET ÉCRAN EXISTE PARCE QU'ON TOURNAIT EN ROND. Enchaîner les six phases coûte une heure ;
 *    quand la troisième révèle un défaut, on a perdu l'heure et on recommence. Emmanuel,
 *    07/09/2026 : « je veux faire phase par phase pour détecter les problèmes et résoudre très
 *    vite ». On lance une phase, on regarde, on corrige, on relance — et il faut donc un
 *    endroit qui montre UNE phase, sans le bruit des cinq autres.
 *
 * ⚠️ ET SURTOUT : LES ARBITRAGES DE CETTE PHASE, ET D'ELLE SEULE. La phase 1 posait six
 *    questions d'homonymie que RIEN n'affichait — aucune cible d'arbitrage n'existait pour
 *    elle. Le moteur posait une question sans avoir prévu où l'on répond. Une question sans
 *    guichet n'est pas une question, c'est une perte.
 *
 * ⚠️ ON CHOISIT LA BASE, PARCE QU'ON TRAVAILLE SUR DEUX. Les écrans existants lisent la base
 *    par défaut ; pendant les essais, le travail vit dans le bac à sable. Montrer l'un en
 *    croyant voir l'autre est la façon la plus sûre de valider un résultat qui n'existe pas.
 *    Le nom de la base lue est donc AFFICHÉ, toujours, en toutes lettres.
 *
 * ⚠️ TOUTE URL DE CETTE PAGE EST ABSOLUE, ET C'EST OBLIGATOIRE. Le gabarit pose un
 *    `<base href>` : une action ou un lien écrit « ?phase=2 » ne se résout PAS contre la
 *    page courante mais contre la RACINE du site. Le formulaire postait donc vers l'accueil,
 *    qui renvoie au login — et l'utilisateur croyait avoir été déconnecté en enregistrant sa
 *    réponse. Sa décision, elle, était perdue. On passe donc toujours par `app_url()`.
 *
 * ⚠️ ON RÉPOND ICI. Cet écran a d'abord été écrit en lecture seule, les décisions étant
 *    censées se prendre « dans la file » — sauf que la file ne connaît que les immeubles, les
 *    lots, les occupations et les mouvements. Les questions portant sur le COMPTE RENDU entier
 *    n'avaient donc aucun guichet, et Emmanuel n'avait nulle part où répondre. Écriture
 *    TECHNIQUE seulement : la décision vit dans `crgi_arbitrage`, rien n'est touché dans MBI.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/crg_integration.php';
require_admin_or_super_admin();

// ── La base regardée ────────────────────────────────────────────────────────────────────────
// ⚠️ LE BAC À SABLE N'EST PAS UNE VARIANTE DE LA BASE RÉELLE : c'est une AUTRE base, avec son
//    propre compte. On ouvre donc une connexion dédiée plutôt que de « basculer » celle du
//    reste de l'application, qui sert aussi l'authentification et les droits.
const CRGIP_BACS = ['maboximmo' => 'Base réelle', 'mbi_bis' => 'Bac à sable (essais)'];
$base = (string)($_GET['base'] ?? 'maboximmo');
if (!isset(CRGIP_BACS[$base])) {
    $base = 'maboximmo';
}
$pdo = $GLOBALS['pdo'];
if ($base !== 'maboximmo') {
    $secret = 'C:/Users/emery/.mbi/mbi_agent.pass';
    try {
        $pdo = new PDO('mysql:host=127.0.0.1;dbname=' . $base . ';charset=utf8mb4',
            'mbi_agent', is_file($secret) ? trim((string)file_get_contents($secret)) : '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (Throwable $e) {
        $erreurBase = $e->getMessage();
        $pdo = $GLOBALS['pdo'];
        $base = 'maboximmo';
    }
}

$phase = (int)($_GET['phase'] ?? 0);
if ($phase < 0 || $phase > 5) {
    $phase = 0;
}

// ── RÉPONDRE ────────────────────────────────────────────────────────────────────────────────
// ⚠️ ON RÉPOND LÀ OÙ LA QUESTION EST POSÉE. Cet écran a d'abord été écrit en lecture seule,
//    les décisions étant censées se prendre « dans la file » — sauf que la file ne connaît pas
//    ces questions-là. Emmanuel, 07/09/2026 : « je ne vois pas où mettre mes réponses ». Une
//    question sans guichet n'est pas une question, c'est une perte : le guichet est ici.
//
// ⚠️ ET ON RÉPOND POUR TOUT UN GROUPE D'UN GESTE. Dix-neuf lignes en vrac se traitent une par
//    une ; groupées par proposition, trois gestes suffisent. Chaque ligne garde sa réponse
//    propre — le groupe n'est qu'un raccourci, jamais une contrainte.
$messageDecision = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf('default');
    $choix = (string)($_POST['choix'] ?? '');
    $cibles = array_map('intval', (array)($_POST['crg'] ?? []));
    $importsDe = $pdo->prepare('SELECT import_id FROM crgi_crg WHERE id = ?');
    $n = 0;
    try {
        foreach ($cibles as $crgId) {
            $importsDe->execute([$crgId]);
            $imp = (int)$importsDe->fetchColumn();
            if (!$imp) {
                continue;
            }
            crgi_decider_crg($pdo, $imp, $crgId, 'CRG-SANS-PATRIMOINE',
                             CRGI_CHOIX_SANS_PATRIMOINE, $choix, (int)($_SESSION['user_id'] ?? 0));
            $n++;
        }
        $messageDecision = $choix === ''
            ? $n . ' décision(s) retirée(s).'
            : $n . ' compte(s) rendu(s) classé(s) « ' . $choix . ' ».';
    } catch (Throwable $e) {
        $messageDecision = 'REFUSÉ — ' . $e->getMessage();
    }
}
$csrf = csrf_token('default');

$imports = $pdo->query('SELECT id, libelle FROM crgi_import ORDER BY id')
               ->fetchAll(PDO::FETCH_KEY_PAIR);

/** L'état d'une phase pour un import, tel que la base le porte — jamais déduit. */
$etat = function (int $id) use ($pdo, $phase): array {
    $st = $pdo->prepare('SELECT statut, analyse_le, valide_le, secondes_machine
                           FROM crgi_phase WHERE import_id = ? AND phase = ?');
    $st->execute([$id, $phase]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: ['statut' => 'A FAIRE'];
};

// ── Les chiffres, phase par phase ───────────────────────────────────────────────────────────
// ⚠️ CHAQUE PHASE A SES PROPRES NOMBRES, ET ILS NE SE RESSEMBLENT PAS. Une table commune
//    forcerait à inventer des colonnes vides ; on décrit donc ce que CHAQUE phase démontre.
$compte = function (int $id, string $sql) use ($pdo) {
    $st = $pdo->prepare($sql);
    $st->execute([$id]);
    return (int)$st->fetchColumn();
};
$colonnes = [
    0 => ['fichiers' => 'SELECT COUNT(*) FROM crgi_piece WHERE import_id = ?',
          'CRG' => 'SELECT COUNT(*) FROM crgi_crg WHERE import_id = ?',
          'pages' => 'SELECT COUNT(*) FROM crgi_page WHERE import_id = ?',
          'propriétaires' => 'SELECT COUNT(DISTINCT proprietaire) FROM crgi_crg
                               WHERE import_id = ? AND proprietaire <> ""',
          'réénonciations' => 'SELECT COUNT(*) FROM crgi_crg
                                WHERE import_id = ? AND doublon_statut = "REENONCIATION"',
          'sans agence' => 'SELECT COUNT(*) FROM crgi_crg
                             WHERE import_id = ? AND agence_source = "INDETERMINABLE"',
          'sans période' => 'SELECT COUNT(*) FROM crgi_crg
                              WHERE import_id = ? AND periode_source = "INDETERMINABLE"'],
    1 => ['déjà connues' => 'SELECT COUNT(*) FROM crgi_crg
                              WHERE import_id = ? AND inventaire_statut = "DEJA CONNUE"',
          'nouvelles' => 'SELECT COUNT(*) FROM crgi_crg
                           WHERE import_id = ? AND inventaire_statut = "NOUVELLE"',
          'mandants nouveaux' => 'SELECT COUNT(*) FROM crgi_crg
                                   WHERE import_id = ? AND inventaire_statut = "NOUVEAU MANDANT"',
          'codes partagés' => 'SELECT COUNT(*) FROM crgi_crg
                           WHERE import_id = ? AND inventaire_statut = "CODE PARTAGE"',
          'à vérifier' => 'SELECT COUNT(*) FROM crgi_crg
                            WHERE import_id = ? AND inventaire_statut = "A VERIFIER"'],
    2 => ['immeubles lus' => 'SELECT COUNT(*) FROM crgi_immeuble WHERE import_id = ?',
          'immeubles distincts' => 'SELECT COUNT(*) FROM (SELECT 1 FROM crgi_immeuble
                WHERE import_id = ? GROUP BY COALESCE(NULLIF(TRIM(code),""),
                      CONCAT(nom, "|", COALESCE(code_postal, "")))) t',
          'lots lus' => 'SELECT COUNT(*) FROM crgi_lot WHERE import_id = ?',
          'immeubles à arbitrer' => 'SELECT COUNT(*) FROM crgi_immeuble
                                      WHERE import_id = ? AND statut = "A ARBITRER"',
          'lots à arbitrer' => 'SELECT COUNT(*) FROM crgi_lot
                                 WHERE import_id = ? AND statut = "A ARBITRER"'],
    3 => ['occupations' => 'SELECT COUNT(*) FROM crgi_occupation WHERE import_id = ?',
          'locataires nommés' => 'SELECT COUNT(DISTINCT locataire) FROM crgi_occupation
                                   WHERE import_id = ? AND locataire <> ""',
          'départs démontrés' => 'SELECT COUNT(*) FROM crgi_occupation
                                   WHERE import_id = ? AND statut = "PARTI DEMONTRE"',
          'à arbitrer' => 'SELECT COUNT(*) FROM crgi_occupation
                            WHERE import_id = ? AND statut = "A ARBITRER"'],
    4 => ['lignes d’argent' => 'SELECT COUNT(*) FROM crgi_mouvement WHERE import_id = ?',
          'mouvements' => 'SELECT COUNT(*) FROM crgi_mouvement WHERE import_id = ?
                            AND reimpression = 0 AND additionnable = 1 AND flux = 1',
          'sans nature' => 'SELECT COUNT(*) FROM crgi_mouvement
                             WHERE import_id = ? AND categorie = "INDETERMINABLE"',
          'compensations' => 'SELECT COUNT(*) FROM crgi_mouvement
                               WHERE import_id = ? AND categorie = "COMPENSATION ENTRE MANDATS"'],
    5 => ['lignes du plan' => 'SELECT COUNT(*) FROM crgi_plan WHERE import_id = ?',
          'à arbitrer' => 'SELECT COUNT(*) FROM crgi_plan
                            WHERE import_id = ? AND action = "A ARBITRER"'],
];

// ── LES QUESTIONS DE CETTE PHASE, ET D'ELLE SEULE ───────────────────────────────────────────
// ⚠️ CE QUI N'EST PAS RECONNU À 100 % EST UNE QUESTION — et rien d'autre n'en est une.
//    `INTEG-ARBITRAGE-00`. Un mandant nouveau est démontré : il n'apparaît pas ici.
$questions = [];
if ($phase === 1) {
    foreach ($imports as $id => $nom) {
        $st = $pdo->prepare(
            'SELECT id, compte, proprietaire, periode_cle, page_debut, inventaire_motif
               FROM crgi_crg WHERE import_id = ? AND inventaire_statut IN ("CODE PARTAGE",
                                                                          "A VERIFIER")
              ORDER BY compte, page_debut'
        );
        $st->execute([(int)$id]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $q) {
            $q['depot'] = $nom;
            $questions[] = $q;
        }
    }
}

// ── LES MANDATS NOUVEAUX — UNE INFORMATION, PAS UNE DÉCISION ────────────────────────────────
// ⚠️ « IL EST TOUJOURS TRÈS BIEN DE POSER LA QUESTION, AINSI JE ME RENDS COMPTE DE L'ACTIVITÉ
//    COMMERCIALE DES COLLABORATEURS » — Emmanuel, 07/09/2026. Un numéro de compte nouveau et
//    un nom de propriétaire nouveau, dans une agence, font un mandat nouveau : c'est DÉMONTRÉ,
//    donc ce n'est pas un arbitrage (`INTEG-ARBITRAGE-00` ne s'y applique pas). Mais c'est la
//    seule information de l'intégration qui se lise comme un RÉSULTAT DE GESTION : un dépôt
//    qui apporte quarante mandats raconte une agence qui gagne des clients ; le même dépôt
//    sans aucun raconte autre chose.
//
// ⚠️ ON LES NOMME, ON NE LES COMPTE PAS. Un compteur « 233 » ne dit rien à personne ; la liste
//    des noms se lit, se reconnaît, et se rapproche d'un collaborateur. C'est pour cela
//    qu'elle vit ici et pas dans une case de tableau.
// ── PHASE 2 : LES COMPTES RENDUS QUI NE PRODUISENT AUCUN PATRIMOINE ─────────────────────────
// ⚠️ CE SILENCE-LÀ EST UNE QUESTION, PAS UN RÉSULTAT. Le CRG est parfaitement lu — compte,
//    propriétaire, trimestre, agence — et ne rend NI immeuble NI lot. Quatre causes possibles,
//    et le document n'en désigne aucune. Voir `crgi_crg_sans_patrimoine()`.
$sansPatrimoine = [];
if ($phase === 2) {
    foreach ($imports as $id => $nom) {
        foreach (crgi_crg_sans_patrimoine($pdo, (int)$id) as $x) {
            $x['depot'] = $nom;
            $sansPatrimoine[] = $x;
        }
    }
}

$mandats = [];
if ($phase === 1) {
    foreach ($imports as $id => $nom) {
        $st = $pdo->prepare(
            'SELECT compte, MIN(proprietaire) proprietaire, COUNT(*) crg,
                    MIN(periode_cle) depuis, MIN(id) crg_id, MIN(page_debut) page
               FROM crgi_crg
              WHERE import_id = ? AND inventaire_statut = "NOUVEAU MANDANT"
              GROUP BY compte ORDER BY MIN(proprietaire)'
        );
        $st->execute([(int)$id]);
        $lignes = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($lignes) {
            $mandats[$nom] = $lignes;
        }
    }
}

$pageTitle = 'Intégration CRG — phase ' . $phase;
$extraCss  = '<link rel="stylesheet" href="' . asset_url('/css/crg_integration.css') . '">';
require_once __DIR__ . '/../inc/agency_layout_top.php';
$nb = fn($n) => number_format((int)$n, 0, ',', ' ');
?>
<div class="crgi">

  <p class="crgi-note">
    Base lue : <b><?= h(CRGIP_BACS[$base]) ?></b> — <code><?= h($base) ?></code>.
    <?php foreach (CRGIP_BACS as $b => $lib): if ($b === $base) { continue; } ?>
      · <a href="<?= h(app_url('/admin/admin_crgi_phase.php')) ?>?phase=<?= $phase ?>&amp;base=<?= h($b) ?>">voir <?= h($lib) ?></a>
    <?php endforeach; ?>
    <?php if (isset($erreurBase)): ?>
      <br><b style="color:var(--crgi-rouge)">Bac à sable injoignable</b> —
      <?= h($erreurBase) ?>
    <?php endif; ?>
  </p>

  <?php if ($messageDecision !== null): ?>
    <p class="crgi-note<?= str_starts_with($messageDecision, 'REFUSÉ') ? ' rouge' : '' ?>">
      <b><?= h($messageDecision) ?></b></p>
  <?php endif; ?>

  <div class="crgi-bord-parcours">
    <?php foreach (CRGI_PHASES as $p => $titre): ?>
      <a class="crgi-bord-pas <?= $p === $phase ? 'crgi-pas-encours' : 'crgi-pas-attente' ?>"
         href="<?= h(app_url('/admin/admin_crgi_phase.php')) ?>?phase=<?= $p ?>&amp;base=<?= h($base) ?>">
        <span class="crgi-bord-signe"><?= $p ?></span> <?= h($titre) ?></a>
    <?php endforeach; ?>
  </div>

  <h2>Phase <?= $phase ?> — <?= h(CRGI_PHASES[$phase] ?? '?') ?></h2>

  <?php if (!$imports): ?>
    <p class="crgi-note rouge">Aucun import dans cette base.</p>
  <?php else: ?>
    <div class="crgi-defile"><table>
      <tr><th>Agence</th><th>État</th><th class="num">Temps</th>
        <?php foreach (array_keys($colonnes[$phase]) as $c): ?>
          <th class="num"><?= h($c) ?></th>
        <?php endforeach; ?></tr>
      <?php $tot = array_fill_keys(array_keys($colonnes[$phase]), 0);
      foreach ($imports as $id => $nom): $e = $etat((int)$id); ?>
        <tr>
          <td><b><?= h($nom) ?></b></td>
          <td><?= h($e['statut']) ?><?= !empty($e['valide_le']) ? ' ✔' : '' ?></td>
          <td class="num"><?= $e['secondes_machine'] ? $nb($e['secondes_machine']) . ' s' : '—' ?></td>
          <?php foreach ($colonnes[$phase] as $c => $sql):
              $v = $compte((int)$id, $sql); $tot[$c] += $v; ?>
            <td class="num"><?= $nb($v) ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      <tr><td colspan="3"><b>TOTAL</b></td>
        <?php foreach ($tot as $v): ?><td class="num"><b><?= $nb($v) ?></b></td><?php endforeach; ?>
      </tr>
    </table></div>

    <?php if ($mandats): ?>
      <h2 style="margin-top:20px">Mandats nouveaux — l’activité commerciale du trimestre</h2>
      <p class="crgi-sous">
        Un numéro de compte nouveau et un propriétaire nouveau dans une agence :
        <b>c’est un mandat gagné</b>. Ce n’est pas une question — c’est démontré, et ça ne se
        décide pas. Mais ça se lit, et ça se rapporte à un collaborateur.
      </p>
      <?php foreach ($mandats as $depot => $lignes): ?>
        <div class="crgi-defile"><table>
          <tr><th colspan="5"><?= h((string)$depot) ?> —
              <b><?= $nb(count($lignes)) ?></b> mandat(s) nouveau(x)</th></tr>
          <tr><th>Compte</th><th>Propriétaire</th><th class="num">CRG</th>
              <th>Vu depuis</th><th>Preuve</th></tr>
          <?php foreach ($lignes as $m): ?>
            <tr>
              <td><code><?= h((string)$m['compte']) ?></code></td>
              <td><b><?= h(mb_substr((string)($m['proprietaire'] ?: '—'), 0, 40)) ?></b></td>
              <td class="num"><?= $nb($m['crg']) ?></td>
              <td><?= h((string)($m['depuis'] ?? '—')) ?></td>
              <td><a href="<?= h(app_url('/admin/crgi_page.php')) ?>?crg=<?= (int)$m['crg_id'] ?>&amp;base=<?= h($base) ?>#page=<?= (int)$m['page'] ?>"
                     target="_blank">page <?= (int)$m['page'] ?></a></td>
            </tr>
          <?php endforeach; ?>
        </table></div>
      <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($sansPatrimoine): ?>
      <h2 style="margin-top:20px">Comptes rendus sans aucun patrimoine —
        <?= $nb(count($sansPatrimoine)) ?> à trancher</h2>
      <p class="crgi-sous">
        Lu sans faute — compte, propriétaire, trimestre, agence — mais <b>ni immeuble ni
        lot</b>. Pas d’appel de loyer signifie plus de locataire actif ; <b>le document ne dit
        pas pourquoi</b>. La proposition dit ce que le fait observé rend le plus probable :
        elle se refuse d’un coup d’œil, puisque le fait est écrit à côté.
      </p>
      <?php
      // ⚠️ GROUPÉ PAR DÉPÔT, ET LES MÊMES PROPOSITIONS ENSEMBLE. Dix-neuf lignes en vrac se
      //    traitent une par une ; groupées, trois gestes suffisent.
      $parDepot = [];
      foreach ($sansPatrimoine as $x) { $parDepot[$x['depot']][$x['proposition']][] = $x; }
      foreach ($parDepot as $depot => $groupes): ?>
        <h3 style="margin:14px 0 4px"><?= h((string)$depot) ?></h3>
        <?php foreach ($groupes as $prop => $lignes): ?>
          <form method="post" class="crgi-defile"
                action="<?= h(app_url('/admin/admin_crgi_phase.php')) ?>?phase=<?= $phase ?>&amp;base=<?= h($base) ?>">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <table>
              <tr><th colspan="5">
                Proposition : <b><?= h((string)$prop) ?></b> —
                <?= $nb(count($lignes)) ?> compte(s) rendu(s), parce que ce compte
                <?= h((string)$lignes[0]['parce_que']) ?></th></tr>
              <tr><th>✓</th><th>Compte</th><th>Propriétaire</th><th>Période</th><th>Preuve</th></tr>
              <?php foreach ($lignes as $x): ?>
                <tr>
                  <td><input type="checkbox" name="crg[]" value="<?= (int)$x['id'] ?>" checked></td>
                  <td><code><?= h((string)$x['compte']) ?></code></td>
                  <td><b><?= h(mb_substr((string)($x['proprietaire'] ?: '—'), 0, 34)) ?></b>
                    <?php if (!empty($x['decision'])): ?>
                      <br><small style="color:var(--crgi-vert)">✔ déjà classé
                        « <?= h((string)$x['decision']) ?> »</small>
                    <?php endif; ?></td>
                  <td><?= h((string)($x['periode_cle'] ?? '—')) ?></td>
                  <td><a href="<?= h(app_url('/admin/crgi_page.php')) ?>?crg=<?= (int)$x['id'] ?>&amp;base=<?= h($base) ?>#page=<?= (int)$x['page_debut'] ?>"
                         target="_blank">page <?= (int)$x['page_debut'] ?></a></td>
                </tr>
              <?php endforeach; ?>
              <tr><td colspan="5" style="padding:10px 8px">
                Ma réponse pour les lignes cochées :
                <select name="choix" style="padding:5px 8px;font-size:13px">
                  <?php foreach (CRGI_CHOIX_SANS_PATRIMOINE as $c => $quoi): ?>
                    <option value="<?= h($c) ?>"<?= str_contains((string)$prop, $c) ? ' selected' : '' ?>>
                      <?= h($c) ?> — <?= h($quoi) ?></option>
                  <?php endforeach; ?>
                  <option value="">(retirer ma décision)</option>
                </select>
                <button type="submit" style="margin-left:8px;padding:6px 16px;font-weight:600">
                  Enregistrer</button>
              </td></tr>
            </table>
          </form>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <p class="crgi-note">
        <b>BIEN VENDU</b> le propriétaire ne le possède plus ·
        <b>BIEN VACANT</b> il le possède, personne ne l’occupe ·
        <b>GESTION TERMINÉE</b> il le possède et l’occupe, la gestion est ailleurs ·
        <b>COMPTE TECHNIQUE</b> coquille sans patrimoine, pour payer hors gestion.
        <br>Les trois premières sont des <b>événements commerciaux</b> : le document ne peut
        pas les connaître. La quatrième est une <b>nature de compte</b>, permanente — dite une
        fois, elle ne se redemande plus.
      </p>
    <?php endif; ?>

    <h2 style="margin-top:20px">Ce que cette phase demande à trancher</h2>
    <?php if ($phase !== 1): ?>
      <p class="crgi-note">
        Les questions des phases 2 à 5 vivent dans la file d'arbitrage, où la preuve est à un
        clic : <a href="<?= h(app_url('/admin/admin_crgi_seance.php')) ?>">séance d'arbitrage</a>.
        <br>Cet écran ne rend, pour l'instant, que les questions propres à la phase 1 —
        celles qui n'avaient aucun guichet.
      </p>
    <?php elseif (!$questions): ?>
      <p class="crgi-note">
        <b>Aucune.</b> Tout ce que la phase 1 a rencontré est démontré — déjà connu, nouveau,
        ou nouveau mandant. Un mandant absent de MBI n'est pas une erreur : c'est un nouveau,
        et il ne se décide pas.
      </p>
    <?php else: ?>
      <p class="crgi-sous">
        <b><?= $nb(count($questions)) ?> question(s).</b> Un code de compte n'est jamais global :
        il n'existe que dans son espace de nommage. Le MÊME NUMÉRO vit ailleurs — même mandant,
        ou deux mandants sans rapport ?
      </p>
      <div class="crgi-defile"><table>
        <tr><th>Agence</th><th>Compte</th><th>Propriétaire</th><th>Période</th>
            <th>Ce que le moteur a vu</th><th>Preuve</th></tr>
        <?php foreach ($questions as $q): ?>
          <tr>
            <td><?= h((string)$q['depot']) ?></td>
            <td><code><?= h((string)$q['compte']) ?></code></td>
            <td><?= h(mb_substr((string)($q['proprietaire'] ?? '—'), 0, 30)) ?></td>
            <td><?= h((string)($q['periode_cle'] ?? '—')) ?></td>
            <td style="font-size:12px"><?= h((string)$q['inventaire_motif']) ?></td>
            <td><a href="<?= h(app_url('/admin/crgi_page.php')) ?>?crg=<?= (int)$q['id'] ?>&amp;base=<?= h($base) ?>#page=<?= (int)$q['page_debut'] ?>"
                   target="_blank">page <?= (int)$q['page_debut'] ?></a></td>
          </tr>
        <?php endforeach; ?>
      </table></div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
