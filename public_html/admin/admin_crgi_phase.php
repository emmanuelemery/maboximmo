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
    // ⚠️ DEUX GUICHETS, PAS UN. La phase 2 tranche des COMPTES RENDUS, la phase 3 des
    //    OCCUPATIONS : deux tables, deux mémoires, deux jeux de réponses. Un seul traitement
    //    aurait enregistré une décision d'occupation sur un identifiant de compte rendu —
    //    silencieusement, sur le mauvais objet.
    $choix  = (string)($_POST['choix'] ?? '');
    $cible  = (string)($_POST['cible'] ?? 'CRG');
    $cibles = array_map('intval', (array)($_POST['id'] ?? $_POST['crg'] ?? []));
    $occ    = $cible === 'OCCUPATION';
    // ⚠️ TROISIÈME GUICHET : LE PÉRIMÈTRE. Il ne porte sur aucune ligne de staging — il porte
    //    sur un MANDAT, un IMMEUBLE ou un LOT, désignés en clair. Sa cible n'est donc pas un
    //    identifiant mais un couple (niveau, clé), et sa décision vit directement dans la
    //    mémoire durable : « la SCI FAVRE est vendue » ne se redemande jamais.
    if ($cible === 'PERIMETRE') {
        $n = 0;
        try {
            // ⚠️ CHAQUE LIGNE PORTE SA RÉPONSE, ET C'EST ELLE QUI FAIT FOI. Le choix du
            //    bas de tableau n'est qu'un raccourci qui préremplit les lignes cochées ; sans
            //    réponse par ligne, un immeuble vendu et un immeuble en contentieux — même
            //    proposition — auraient exigé deux envois.
            $reponses = (array)($_POST['reponse'] ?? []);
            foreach ((array)($_POST['perim'] ?? []) as $p) {
                [$imp, $agence, $niveau, $cle] = array_pad(explode('|', (string)$p, 4), 4, '');
                if ($niveau === '' || $cle === '') {
                    continue;
                }
                $r = array_key_exists((string)$p, $reponses)
                   ? (string)$reponses[(string)$p] : $choix;
                $com = (string)(((array)($_POST['commentaire'] ?? []))[(string)$p] ?? '');
                crgi_decider_perimetre($pdo, (int)$imp, $agence, $niveau, $cle, $r,
                                       (int)($_SESSION['user_id'] ?? 0), $com);
                $n++;
            }
            $messageDecision = $choix === ''
                ? $n . ' décision(s) retirée(s).'
                : $n . ' périmètre(s) classé(s) « ' . $choix . ' ».';
        } catch (Throwable $e) {
            $messageDecision = 'REFUSÉ — ' . $e->getMessage();
        }
        $cibles = [];
    }
    // ⚠️ ET LE GUICHET GÉNÉRIQUE SE TAIT QUAND UN AUTRE A RÉPONDU : sans cette garde, il
    //    écrasait le message par « 0 compte(s) rendu(s) classé(s) » — l'utilisateur voyait
    //    zéro alors que sa décision venait d'être enregistrée.
    if ($cible !== 'PERIMETRE') {
        $importsDe = $pdo->prepare($occ
            ? 'SELECT import_id FROM crgi_occupation WHERE id = ?'
            : 'SELECT import_id FROM crgi_crg WHERE id = ?');
        $n = 0;
        try {
            foreach ($cibles as $cibleId) {
                $importsDe->execute([$cibleId]);
                $imp = (int)$importsDe->fetchColumn();
                if (!$imp) {
                    continue;
                }
                if ($occ) {
                    crgi_decider_occupation($pdo, $imp, $cibleId, 'OCCUPATION-NON-TRANCHEE',
                                            $choix, (int)($_SESSION['user_id'] ?? 0));
                } else {
                    crgi_decider_crg($pdo, $imp, $cibleId, 'CRG-SANS-PATRIMOINE',
                                     CRGI_CHOIX_SANS_PATRIMOINE, $choix,
                                     (int)($_SESSION['user_id'] ?? 0));
                }
                $n++;
            }
            $quoi = $occ ? ' occupation(s)' : ' compte(s) rendu(s)';
            $messageDecision = $choix === ''
                ? $n . ' décision(s) retirée(s).'
                : $n . $quoi . ' classée(s) « ' . $choix . ' ».';
        } catch (Throwable $e) {
            $messageDecision = 'REFUSÉ — ' . $e->getMessage();
        }
        }
    }
$csrf = csrf_token('default');

// ⚠️ LA PREUVE EST SERVIE EN EXTRAIT, DONC LA PAGE EST RELATIVE. `crgi_page.php` ne rend plus
//    le dépôt entier — 616 Mo pour lire une ligne — mais les seules pages du compte rendu
//    concerné. La page 93 du dépôt devient donc la page 1 de l'extrait : garder le numéro
//    absolu dans le fragment ouvrirait le lecteur sur une page qui n'existe pas.
$pageDebutDe = $pdo->query('SELECT id, page_debut FROM crgi_crg')->fetchAll(PDO::FETCH_KEY_PAIR);
$preuve = function (int $crgId, int $pageAbs) use ($pageDebutDe, $base): string {
    $d = (int)($pageDebutDe[$crgId] ?? 1);
    $rel = max(1, $pageAbs - $d + 1);
    return app_url('/admin/crgi_page.php') . '?crg=' . $crgId . '&base=' . $base
         . '#page=' . $rel;
};

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
    // ⚠️ UNE LIGNE N'EST PAS UNE OCCUPATION. `crgi_occupation` enregistre une OBSERVATION par
    //    compte rendu : le même locataire est réénoncé à chaque période, 3,7 fois en moyenne
    //    sur un dépôt mensuel. Compter les lignes et les appeler « occupations » gonflait
    //    VIENNE de 131 à 480. Une occupation est un couple `lot × locataire` ; son état est
    //    celui de sa DERNIÈRE observation, jamais la somme des précédentes.
    3 => ['locataires' => 'SELECT COUNT(DISTINCT locataire) FROM crgi_occupation
                            WHERE import_id = ? AND locataire <> ""',
          'lots occupés' => 'SELECT COUNT(*) FROM (SELECT DISTINCT c.compte, o.lot_reference
                FROM crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
               WHERE o.import_id = ?) t',
          'occupations' => 'SELECT COUNT(*) FROM (SELECT DISTINCT c.compte, o.lot_reference,
                o.locataire FROM crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
               WHERE o.import_id = ?) t',
          'en place' => 'SELECT COUNT(*) FROM (SELECT SUBSTRING_INDEX(GROUP_CONCAT(o.statut
                ORDER BY o.date_arrete DESC, o.rang ASC), ",", 1) s
                FROM crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
               WHERE o.import_id = ? GROUP BY c.compte, o.lot_reference, o.locataire) t
              WHERE s IN ("IDENTIQUE", "CHANGEMENT DE LOCATAIRE", "NOUVEL ENTRANT")',
          'partis' => 'SELECT COUNT(*) FROM (SELECT SUBSTRING_INDEX(GROUP_CONCAT(o.statut
                ORDER BY o.date_arrete DESC, o.rang ASC), ",", 1) s
                FROM crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
               WHERE o.import_id = ? GROUP BY c.compte, o.lot_reference, o.locataire) t
              WHERE s IN ("PARTI DEMONTRE", "ANCIEN LOCATAIRE AVEC DETTE")',
          'observations lues' => 'SELECT COUNT(*) FROM crgi_occupation WHERE import_id = ?',
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

// ── PHASE 3 : LES OCCUPATIONS QUE LE DOCUMENT NE TRANCHE PAS ────────────────────────────────
// ⚠️ « AUCUNE LIGNE LOCATAIRE » N'EST PAS UNE VACANCE. Le lot existe, le document n'en dit
//    rien pour cette période — et le vide ne se lit pas comme un départ (`ABSENCE ≠ DÉPART
//    DÉMONTRÉ`). C'est une question, et elle a désormais son guichet ici comme les autres.
// ⚠️ CE QUI N'APPELLE PLUS RIEN — la question posée à l'échelle où la réponse se donne.
//    99 lots d'un dépôt ne portent qu'un solde ; posée au lot, la question sort 99 fois.
//    « La SCI FAVRE est vendue intégralement, Oyonnax aussi » : une réponse, six lots.
$perimetres = [];
if ($phase === 3) {
    foreach ($imports as $id => $nom) {
        foreach (crgi_perimetres_sans_appel($pdo, (int)$id) as $g) {
            $g['depot'] = $nom;
            $g['import_id'] = (int)$id;
            $perimetres[] = $g;
        }
    }
}

// ⚠️ CE QUI MANQUE SE RÉCLAME. Un immeuble sans rue n'est pas un défaut de lecture quand le
//    document ne la porte pas — mais le laisser vide en silence livre un patrimoine incomplet
//    sans que personne ne le sache. « Il faudra nous signaler cette anomalie pour que nous
//    complétions » (Emmanuel, 08/09/2026).
$sansAdresse = [];
if ($phase === 2) {
    foreach ($imports as $id => $nom) {
        foreach (crgi_immeubles_sans_adresse($pdo, (int)$id) as $x) {
            $x['depot'] = $nom;
            $sansAdresse[] = $x;
        }
    }
}

// ⚠️ UN LOT SANS IMMEUBLE RESTE UN LOT SANS IMMEUBLE — mais il se réclame. Le code se
//    déduit de la référence ; quand elle est courte (« 048 », « 190 ») elle ne le porte pas.
//    Le rattacher au dernier immeuble de la page serait la faute type. Le rapprochement se
//    fera avec les taxes foncières et les propriétaires.
$sansImmeuble = [];
if ($phase === 2) {
    foreach ($imports as $id => $nom) {
        foreach (crgi_lots_sans_immeuble($pdo, (int)$id) as $x) {
            $x['depot'] = $nom;
            $sansImmeuble[] = $x;
        }
    }
}

$occupations = [];
if ($phase === 3) {
    foreach ($imports as $id => $nom) {
        foreach (crgi_occupations_a_trancher($pdo, (int)$id) as $x) {
            $x['depot'] = $nom;
            $occupations[] = $x;
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

// ⚠️ LE TITRE NOMME LA PHASE, PAS LE MODULE. Un onglet « Intégration CRG » sur six écrans
//    différents ne dit pas lequel on regarde.
$pageTitle = 'PHASE ' . $phase . ' — ' . (CRGI_PHASES[$phase] ?? '?');
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
      <?php
      $scellees = (int)$pdo->query('SELECT COUNT(*) FROM crgi_phase
                                     WHERE phase = ' . (int)$p . ' AND statut = "VALIDEE"')
                           ->fetchColumn();
      $total = count($imports);
      $classe = $p === $phase ? 'crgi-pas-encours'
              : ($total && $scellees >= $total ? 'crgi-pas-fini' : 'crgi-pas-attente'); ?>
      <a class="crgi-bord-pas <?= $classe ?>"
         href="<?= h(app_url('/admin/admin_crgi_phase.php')) ?>?phase=<?= $p ?>&amp;base=<?= h($base) ?>">
        <span class="crgi-bord-signe"><?= $p ?></span> <?= h($titre) ?>
        <?= $total && $scellees >= $total ? ' ✔' : ' ' . $scellees . '/' . $total ?></a>
    <?php endforeach; ?>
  </div>

  <h1 style="font-size:24px;margin:16px 0 4px">
    PHASE <?= $phase ?> — <?= h(CRGI_PHASES[$phase] ?? '?') ?></h1>
  <p class="crgi-sous" style="margin-bottom:14px">
    Cet écran ne montre <b>que cette phase</b>. Les cinq autres sont dans la barre ci-dessus.
  </p>

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
              <td><a href="<?= h($preuve((int)$m['crg_id'], (int)$m['page'])) ?>"
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
                  <td><a href="<?= h($preuve((int)$x['id'], (int)$x['page_debut'])) ?>"
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

    <?php if ($sansImmeuble): ?>
      <h2 style="margin-top:20px">Lots sans immeuble rattaché —
        <?= $nb(count($sansImmeuble)) ?> à rapprocher</h2>
      <p class="crgi-sous">
        Le code d’immeuble se lit dans la référence du lot. Quand elle est courte —
        « 048 », « 190 », « 276-04 » — <b>elle ne le porte pas</b>, et une page contient
        plusieurs immeubles : rattacher au dernier rencontré serait une faute qui ne se
        verrait plus jamais.
        <br><b>Ces lots entreront en base sans parent</b>, et le rapprochement se fera avec les
        taxes foncières et les propriétaires. La liste est ici pour que rien ne parte
        incomplet sans qu’on le sache.
      </p>
      <div class="crgi-defile"><table>
        <tr><th>Agence</th><th>Compte</th><th>Mandant</th><th>Lot</th><th>Type</th>
            <th>Occupant</th><th class="num">CRG</th><th>Preuve</th></tr>
        <?php foreach (array_slice($sansImmeuble, 0, 60) as $x): ?>
          <tr>
            <td><?= h((string)$x['depot']) ?></td>
            <td><code><?= h((string)$x['compte']) ?></code></td>
            <td><?= h(mb_substr((string)($x['proprietaire'] ?? ''), 0, 24)) ?></td>
            <td><code><?= h((string)$x['reference']) ?></code></td>
            <td><?= h((string)($x['type_bien'] ?? '')) ?></td>
            <td><?= h(mb_substr((string)($x['locataire'] ?: '—'), 0, 24)) ?></td>
            <td class="num"><?= $nb($x['crg']) ?></td>
            <td><a href="<?= h($preuve((int)$x['crg_id'], (int)$x['page'])) ?>"
                   target="_blank">page <?= (int)$x['page'] ?></a></td>
          </tr>
        <?php endforeach; ?>
      </table></div>
      <?php if (count($sansImmeuble) > 60): ?>
        <p class="crgi-note"><?= $nb(count($sansImmeuble) - 60) ?> autres non affichés.</p>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($sansAdresse): ?>
      <h2 style="margin-top:20px">Immeubles sans adresse de rue —
        <?= $nb(count($sansAdresse)) ?> à compléter</h2>
      <p class="crgi-sous">
        Le compte rendu nomme ces immeubles — « LES BALCONS DU CARDINAL » — et donne leur
        <b>code postal et leur ville</b>, mais <b>pas la rue</b> : elle n’est nulle part sur la
        page. Ce n’est pas un défaut de lecture, et on ne la devine pas.
        <br><b>Ce n’est pas un arbitrage</b> : c’est une donnée à compléter dans MBI. La liste
        est ici pour que rien ne parte incomplet sans qu’on le sache.
      </p>
      <div class="crgi-defile"><table>
        <tr><th>Agence</th><th>Immeuble</th><th>Code</th><th>Code postal</th><th>Ville</th>
            <th class="num">CRG</th><th>Mandant</th><th>Preuve</th></tr>
        <?php foreach ($sansAdresse as $x): ?>
          <tr>
            <td><?= h((string)$x['depot']) ?></td>
            <td><b><?= h(mb_substr((string)$x['nom'], 0, 40)) ?></b></td>
            <td><code><?= h((string)($x['code'] ?? '—')) ?></code></td>
            <td><?= h((string)($x['code_postal'] ?? '')) ?></td>
            <td><?= h(mb_substr((string)($x['ville'] ?? ''), 0, 22)) ?></td>
            <td class="num"><?= $nb($x['crg']) ?></td>
            <td><?= h(mb_substr((string)($x['proprietaire'] ?? '—'), 0, 26)) ?></td>
            <td><a href="<?= h($preuve((int)$x['crg_id'], (int)$x['page'])) ?>"
                   target="_blank">page <?= (int)$x['page'] ?></a></td>
          </tr>
        <?php endforeach; ?>
      </table></div>
    <?php endif; ?>

    <?php if ($perimetres): ?>
      <h2 style="margin-top:20px">Ce qui n’appelle plus rien —
        <?= $nb(count($perimetres)) ?> périmètre(s)</h2>
      <p class="crgi-sous">
        Ces lots portent un <b>solde</b> et <b>aucun appel</b> : ni loyer, ni charge, ni dépôt
        de garantie. Le compte rendu continue de les imprimer tant que le montant n’est pas
        apuré — <b>un bien vendu y figure des années après la vente</b>. Le document ne dit
        jamais « vendu » : il dit « plus rien n’est appelé ».
        <br>⚠️ <b>La question est posée à l’échelle où la réponse se donne</b> : un mandat
        entier, un immeuble entier, puis seulement les lots isolés. Chaque lot n’apparaît
        qu’une fois, au niveau le plus large qui le couvre.
      </p>
      <?php
      // ⚠️ GROUPÉ PAR DÉPÔT PUIS PAR PROPOSITION, MAIS RÉPONDABLE LIGNE PAR LIGNE. Le groupe
      //    n'est qu'un raccourci — « appliquer à tout ce qui est coché » — jamais une
      //    contrainte : chaque ligne garde SA réponse, et une seule validation les enregistre
      //    toutes. Sans cela, un immeuble vendu et un immeuble en contentieux, qui tombent
      //    dans la MÊME proposition, auraient exigé deux envois.
      $parDepotPer = [];
      foreach ($perimetres as $g) { $parDepotPer[$g['depot']][$g['proposition']][] = $g; }
      foreach ($parDepotPer as $depot => $groupes): ?>
        <h3 style="margin:14px 0 4px"><?= h((string)$depot) ?></h3>
        <?php foreach ($groupes as $prop => $lignes): ?>
          <form method="post" class="crgi-defile"
                action="<?= h(app_url('/admin/admin_crgi_phase.php')) ?>?phase=<?= $phase ?>&amp;base=<?= h($base) ?>">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="cible" value="PERIMETRE">
            <table>
              <tr><th colspan="8">
                Proposition : <b><?= h((string)$prop) ?></b> —
                <?= $nb(count($lignes)) ?> périmètre(s),
                <?= $nb(array_sum(array_column($lignes, 'lots'))) ?> lot(s), parce que
                <?= h((string)$lignes[0]['parce_que']) ?></th></tr>
              <tr>
                <th style="white-space:nowrap"><input type="checkbox" class="crgi-tout"
                       title="Tout cocher / tout décocher"
                       <?= array_filter($lignes, fn($x) => empty($x['decision'])) ? ' checked' : '' ?>></th>
                <th>Échelle</th><th>Mandant</th><th>Ce qui est concerné</th>
                <th class="num">Lots</th><th class="num">Solde porté</th>
                <th>Ma réponse</th><th>Preuve</th></tr>
              <?php foreach ($lignes as $g):
                  $val = $g['import_id'] . '|' . $g['agence'] . '|' . $g['niveau'] . '|' . $g['cle']; ?>
                <tr>
                  <?php // ⚠️ CE QUI EST DÉJÀ TRANCHÉ N'EST PLUS COCHÉ. Une ligne déjà répondue, cochée
                        //    par défaut, se ferait RÉÉCRIRE au premier « Enregistrer » du groupe —
                        //    une décision effacée par un geste qui ne la visait pas. ?>
                  <td><input type="checkbox" name="perim[]" value="<?= h($val) ?>"
                        <?= empty($g['decision']) ? ' checked' : '' ?>></td>
                  <td><b><?= h((string)$g['niveau']) ?></b></td>
                  <td><code><?= h((string)$g['compte']) ?></code><br>
                    <small><?= h(mb_substr((string)($g['proprietaire'] ?: '—'), 0, 26)) ?></small></td>
                  <td><?= h((string)$g['quoi']) ?>
                    <?php if ($g['exemples']): ?>
                      <br><small style="color:#666"><?= h(implode(' · ',
                            array_map(fn($e) => mb_substr($e, 0, 20), $g['exemples']))) ?></small>
                    <?php endif; ?>
                    <?php if (!empty($g['decision'])): ?>
                      <br><small style="color:var(--crgi-vert)">✔ déjà classé
                        « <?= h((string)$g['decision']) ?> »</small>
                    <?php endif; ?>
                    <?php // ⚠️ L'ANALYSE FINE, SOUS LA QUESTION. « Si tu n'as pas la réponse
                          //    certaine, tu dois nous donner une analyse fine du CRG pour nous
                          //    permettre de décider » — une question sans les faits oblige à
                          //    rouvrir le PDF, et une file qui coûte un aller-retour par ligne
                          //    ne se traite pas. ?>
                    <?php foreach ((array)($g['analyse'] ?? []) as $a): ?>
                      <br><small style="color:#444">· <?= h((string)$a) ?></small>
                    <?php endforeach; ?>
                    <?php // ⚠️ UNE LIGNE RECOUVRE PLUSIEURS LOTS « QUI N'ONT PAS LA MÊME
                          //    HISTOIRE » (Emmanuel). Grouper allège la file mais masque le
                          //    détail : on le rouvre à la demande, sans quitter la page ni
                          //    perdre ce qui est déjà saisi.
                    if (count((array)$g['faits']) > 1): ?>
                      <details style="margin-top:4px">
                        <summary style="cursor:pointer;font-size:12px;color:#2563eb">
                          voir les <?= $nb(count($g['faits'])) ?> lots</summary>
                        <table style="margin:4px 0 0;font-size:12px">
                          <?php foreach ($g['faits'] as $f): ?>
                            <tr>
                              <td><code><?= h((string)$f['lot']) ?></code></td>
                              <td><?= h(mb_substr((string)($f['immeuble'] ?? ''), 0, 38)) ?></td>
                              <td><?= h(mb_substr((string)($f['locataire'] ?: '— aucun occupant nommé'), 0, 28)) ?></td>
                              <td><?= $f['bail_au'] ? 'bail fini le ' . h((string)$f['bail_au']) : '' ?></td>
                              <td class="num"><?= $f['solde'] !== null
                                    ? number_format((float)$f['solde'], 2, ',', ' ') . ' €' : '' ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </table>
                      </details>
                    <?php endif; ?>
                    <?php // ⚠️ LE COMMENTAIRE EST UNE DONNÉE : le choix dit la nature commune,
                          //    le commentaire dit ce qui ne s'y range pas. Sans lui, il faudrait
                          //    éclater la ligne — et retrouver les 99 questions. ?>
                    <input type="text" name="commentaire[<?= h($val) ?>]"
                           value="<?= h((string)($g['commentaire'] ?? '')) ?>"
                           placeholder="ce que le document ne dit pas…"
                           style="width:97%;margin-top:5px;padding:3px 5px;font-size:12px"></td>
                  <td class="num"><?= $nb($g['lots']) ?></td>
                  <td class="num"><?= number_format((float)$g['solde'], 2, ',', ' ') ?> €</td>
                  <td><select name="reponse[<?= h($val) ?>]" class="crgi-rep"
                              style="padding:3px 5px;font-size:12px">
                      <?php foreach (CRGI_CHOIX_SANS_APPEL as $c => $quoi): ?>
                        <option value="<?= h($c) ?>"<?= $c === (string)($g['decision'] ?: $prop) ? ' selected' : '' ?>>
                          <?= h($c) ?></option>
                      <?php endforeach; ?>
                      <option value="">(retirer)</option>
                    </select></td>
                  <td><a href="<?= h($preuve((int)$g['crg_id'], (int)$g['page'])) ?>"
                         target="_blank">page <?= (int)$g['page'] ?></a></td>
                </tr>
              <?php endforeach; ?>
              <tr><td colspan="8" style="padding:10px 8px">
                Appliquer à tout ce qui est coché :
                <select class="crgi-appliquer" style="padding:5px 8px;font-size:13px">
                  <option value="">— choisir —</option>
                  <?php foreach (CRGI_CHOIX_SANS_APPEL as $c => $quoi): ?>
                    <option value="<?= h($c) ?>"><?= h($c) ?> — <?= h($quoi) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" style="margin-left:8px;padding:6px 16px;font-weight:600">
                  Enregistrer les lignes cochées</button>
              </td></tr>
            </table>
          </form>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <p class="crgi-note">
        <b>VENDU</b> le propriétaire ne le possède plus ·
        <b>GESTION TERMINÉE</b> il le possède encore, la gestion est ailleurs ·
        <b>VACANT</b> personne ne l’occupe, il reste en gestion ·
        <b>CONTENTIEUX</b> occupé, mais on ne quittance plus.
        <br>⚠️ <b>Un lot vendu et un lot en contentieux s’impriment à l’identique.</b> Le
        document ne les départage pas — seul vous le pouvez. Dite une fois, la réponse ne se
        redemande plus, ici comme dans les dépôts suivants.
      </p>
      <script>
      /* ⚠️ AUCUN GESTIONNAIRE DANS L'ATTRIBUT HTML. Le gabarit passe par du PHP qui échappe
         les guillemets ; un `onclick` contenant des quotes se retrouve tronqué et le bouton
         meurt en silence — piège déjà rencontré sur ce projet. Le câblage se fait ici. */
      document.querySelectorAll('.crgi-tout').forEach(function (t) {
        t.addEventListener('click', function () {
          this.closest('form').querySelectorAll('input[name="perim[]"]')
              .forEach(function (c) { c.checked = t.checked; });
        });
      });
      document.querySelectorAll('.crgi-appliquer').forEach(function (s) {
        s.addEventListener('change', function () {
          if (!s.value) { return; }
          s.closest('form').querySelectorAll('input[name="perim[]"]:checked')
              .forEach(function (c) {
                var r = c.closest('tr').querySelector('.crgi-rep');
                if (r) { r.value = s.value; }
              });
        });
      });
      </script>
    <?php endif; ?>

    <?php if ($occupations): ?>
      <h2 style="margin-top:20px">Occupations que le document ne tranche pas —
        <?= $nb(count($occupations)) ?></h2>
      <p class="crgi-sous">
        Le lot existe, mais le document <b>ne dit pas</b> qui l’occupe sur cette période — ou
        cesse d’en parler. Le vide ne se lit pas comme un départ : ce n’est pas une vacance
        démontrée. <b>La proposition dit ce que le fait observé rend le plus probable</b> : elle
        se refuse d’un coup d’œil, puisque le fait est écrit à côté.
      </p>
      <?php
      // ⚠️ GROUPÉ PAR PROPOSITION, COMME EN PHASE 2. Dix-sept lignes en vrac se traitent une
      //    par une ; groupées, trois gestes suffisent. Chaque ligne garde sa case : le groupe
      //    est un raccourci, jamais une contrainte.
      $parDepotOcc = [];
      foreach ($occupations as $x) { $parDepotOcc[$x['depot']][$x['proposition']][] = $x; }
      foreach ($parDepotOcc as $depot => $groupes): ?>
        <h3 style="margin:14px 0 4px"><?= h((string)$depot) ?></h3>
        <?php foreach ($groupes as $prop => $lignes): ?>
          <form method="post" class="crgi-defile"
                action="<?= h(app_url('/admin/admin_crgi_phase.php')) ?>?phase=<?= $phase ?>&amp;base=<?= h($base) ?>">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="cible" value="OCCUPATION">
            <table>
              <tr><th colspan="6">
                Proposition : <b><?= h((string)$prop) ?></b> —
                <?= $nb(count($lignes)) ?> occupation(s), parce que
                <?= h((string)$lignes[0]['parce_que']) ?></th></tr>
              <tr><th>✓</th><th>Compte</th><th>Lot</th><th>Période</th>
                  <th class="num">Appels</th><th>Preuve</th></tr>
              <?php foreach ($lignes as $x): ?>
                <tr>
                  <td><input type="checkbox" name="id[]" value="<?= (int)$x['id'] ?>" checked></td>
                  <td><code><?= h((string)$x['compte']) ?></code><br>
                    <small><?= h(mb_substr((string)($x['proprietaire'] ?: '—'), 0, 28)) ?></small></td>
                  <td><code><?= h((string)$x['lot_reference']) ?></code>
                    <?php if (!empty($x['decision'])): ?>
                      <br><small style="color:var(--crgi-vert)">✔ déjà classée
                        « <?= h((string)$x['decision']) ?> »</small>
                    <?php elseif (!empty($x['apprise'])): ?>
                      <br><small style="color:var(--crgi-vert)">✔ mémoire :
                        « <?= h((string)$x['apprise']) ?> »</small>
                    <?php endif; ?></td>
                  <td><?= h((string)($x['periode_cle'] ?? '—')) ?></td>
                  <td class="num"><?= $nb($x['appels']) ?></td>
                  <td><a href="<?= h($preuve((int)$x['crg_id'], (int)$x['page'])) ?>"
                         target="_blank">page <?= (int)$x['page'] ?></a></td>
                </tr>
              <?php endforeach; ?>
              <tr><td colspan="6" style="padding:10px 8px">
                Ma réponse pour les lignes cochées :
                <select name="choix" style="padding:5px 8px;font-size:13px">
                  <?php foreach (CRGI_CHOIX_OCCUPATION as $c => $quoi): ?>
                    <option value="<?= h($c) ?>"<?= $c === (string)$prop ? ' selected' : '' ?>>
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
        <b>Appels</b> = les lignes « Du … Au … » du bloc : <b>qui appelle un loyer est en
        place</b>. Un lot qui cesse d’être lu avec des appels en cours n’a pas perdu son
        locataire — c’est le lot qui sort.
        <br><b>LOCATAIRE TOUJOURS EN PLACE</b> et <b>LOGEMENT VACANT</b> ne valent que pour
        cette période, et se redemanderont au trimestre suivant : c’est voulu, le document peut
        dire le contraire. <b>GESTION TERMINÉE</b> et <b>LOT VENDU</b> décrivent le lot :
        dites-les une fois, elles ne se redemandent plus.
      </p>
    <?php endif; ?>

    <?php
    // ⚠️ UNE PHASE À LA FOIS, ET RIEN D'AUTRE. Cet écran affichait le bloc de la phase en
    //    cours PUIS un bloc générique renvoyant à la file — on lisait donc, sur la phase 3,
    //    ses propres questions suivies d'une phrase sur la phase 1. Emmanuel : « j'ai
    //    l'impression que c'est tout mélangé ». Un écran qui montre deux phases à la fois ne
    //    montre aucune des deux.
    if ($phase === 1 && $questions): ?>
      <h2 style="margin-top:20px">Codes de compte partagés — <?= $nb(count($questions)) ?></h2>
      <p class="crgi-sous">
        Un code de compte n'est jamais global : il n'existe que dans son espace de nommage.
        Le MÊME NUMÉRO vit ailleurs — même mandant, ou deux mandants sans rapport ?
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
            <td><a href="<?= h($preuve((int)$q['id'], (int)$q['page_debut'])) ?>"
                   target="_blank">page <?= (int)$q['page_debut'] ?></a></td>
          </tr>
        <?php endforeach; ?>
      </table></div>
    <?php endif; ?>

    <?php if (!$questions && !$mandats && !$sansPatrimoine && !$occupations && !$perimetres
        && !$sansAdresse && !$sansImmeuble): ?>
      <h2 style="margin-top:20px">Rien à trancher sur cette phase</h2>
      <p class="crgi-note">
        <?php if (in_array($phase, [4, 5], true)): ?>
          Les questions des phases 4 et 5 vivent dans la file d'arbitrage, où la preuve est à
          un clic : <a href="<?= h(app_url('/admin/admin_crgi_seance.php')) ?>">séance
          d'arbitrage</a>.
        <?php else: ?>
          <b>Aucune question.</b> Tout ce que cette phase a rencontré est démontré par le
          document ou déjà tranché.
        <?php endif; ?>
      </p>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../inc/agency_layout_bottom.php'; ?>
