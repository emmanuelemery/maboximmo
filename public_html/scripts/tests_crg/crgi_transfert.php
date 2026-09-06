<?php
/**
 * LE TAUX DE TRANSFERT D'APPRENTISSAGE — ce qu'un corpus a coûté grâce à un autre.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CE FICHIER EXISTE PARCE QUE « 29 APPRENTISSAGES ENREGISTRÉS » NE DÉMONTRE RIEN. Un
 *    registre bien tenu peut décrire des règles qui ne servent jamais. Ce qui démontre, c'est
 *    qu'un corpus JAMAIS VU passe sans qu'on ait à rouvrir le code — et ce n'est pas une
 *    impression, cela se compte.
 *
 * ⚠️ LA QUESTION EXACTE EST : « CE PHÉNOMÈNE ÉTAIT-IL DÉJÀ APPRIS ? ». Un phénomène déjà
 *    rencontré sur un premier corpus et qui exige une modification de code sur le second est
 *    un **ÉCHEC DE GÉNÉRALISATION** — la règle avait été placée trop bas dans l'architecture.
 *    Un phénomène réellement nouveau, lui, n'est pas un échec : c'est du travail.
 *
 * ⚠️ ON COMPARE DES FAMILLES DE PHÉNOMÈNES, PAS DES DOCUMENTS. Deux corpus du même éditeur ne
 *    contiennent pas les mêmes comptes ; ils contiennent les mêmes SORTES de difficultés. Le
 *    taux porte donc sur les groupes d'arbitrage et les états de lecture, jamais sur des
 *    identifiants — sinon on mesurerait la ressemblance des dossiers, pas l'apprentissage.
 *
 * ⚠️ LECTURE SEULE.
 *
 * Usage : php crgi_transfert.php <import_référence> <import_nouveau>
 */
declare(strict_types=1);
require_once __DIR__ . '/../../inc/crgi_pilotage.php';

$pdo = $GLOBALS['pdo'];
$ref = (int)($argv[1] ?? 0);
$neuf = (int)($argv[2] ?? 0);
if (!$ref || !$neuf) {
    fwrite(STDERR, "usage : php crgi_transfert.php <import_référence> <import_nouveau>\n");
    exit(2);
}

/** Les familles de phénomènes qu'un dépôt a fait apparaître. */
function crgi_phenomenes(PDO $pdo, int $importId): array
{
    $p = [];
    // ❶ Les familles d'arbitrage : ce que le moteur n'a pas su trancher seul.
    $st = $pdo->prepare('SELECT DISTINCT groupe FROM crgi_arbitrage WHERE import_id = ?');
    $st->execute([$importId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $g) {
        $p['arbitrage:' . $g] = true;
    }
    foreach (crgi_file_arbitrages($pdo, $importId) as $a) {
        $p['arbitrage:' . $a['groupe']] = true;
    }
    // ❷ Les états de lecture : ce que la phase 0 a su nommer.
    $st = $pdo->prepare('SELECT DISTINCT etat FROM crgi_piece WHERE import_id = ?');
    $st->execute([$importId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $e) {
        $p['piece:' . $e] = true;
    }
    // ❸ Les familles documentaires reconnues.
    $st = $pdo->prepare('SELECT DISTINCT format FROM crgi_crg
                          WHERE import_id = ? AND format IS NOT NULL');
    $st->execute([$importId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $f) {
        $p['format:' . $f] = true;
    }
    // ❹ Les statuts de collision — la doctrine de la réénonciation.
    $st = $pdo->prepare('SELECT DISTINCT doublon_statut FROM crgi_crg
                          WHERE import_id = ? AND doublon_statut IS NOT NULL');
    $st->execute([$importId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) {
        $p['collision:' . $d] = true;
    }
    ksort($p);
    return array_keys($p);
}

$avant = crgi_phenomenes($pdo, $ref);
$apres = crgi_phenomenes($pdo, $neuf);
$connus = array_values(array_intersect($apres, $avant));
$nouveaux = array_values(array_diff($apres, $avant));

$pilRef = crgi_pilotage($pdo, $ref);
$pilNeuf = crgi_pilotage($pdo, $neuf);

echo "TRANSFERT D'APPRENTISSAGE — import {$ref} → import {$neuf}\n\n";
printf("  phénomènes rencontrés dans le nouveau dépôt : %d\n", count($apres));
printf("  déjà connus du dépôt de référence           : %d\n", count($connus));
printf("  réellement nouveaux                         : %d\n", count($nouveaux));

// ⚠️ LE TAUX PORTE SUR LES PHÉNOMÈNES DÉJÀ CONNUS, PAS SUR TOUS. Un phénomène neuf n'entame
//    pas le transfert : il n'avait rien à transférer. Ce qu'on mesure, c'est si ce qui était
//    appris a effectivement servi.
// ── CE QUE LE CODE A DÛ APPRENDRE **DE CE CORPUS-LÀ** ────────────────────────────────────
// ⚠️ « ÉCHEC DE GÉNÉRALISATION » SE MESURE, IL NE SE RACONTE PAS. Une règle placée trop bas
//    dans l'architecture se reconnaît à une trace très concrète : le NOM DU CORPUS apparaît
//    dans le moteur. Un moteur qui a généralisé ne connaît que des structures ; un moteur qui
//    a bricolé connaît des enseignes.
//
// ⚠️ ET LES NOMS NE SONT PAS ÉCRITS ICI. Ils viennent de la base — sinon ce fichier
//    contiendrait lui-même les identifiants du corpus qu'il prétend ne pas connaître.
$st = $pdo->prepare('SELECT DISTINCT agence FROM crgi_crg
                      WHERE import_id = ? AND agence IS NOT NULL AND agence <> ""');
$st->execute([$neuf]);
$enseignes = [];
foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $a) {
    foreach (preg_split('~[^A-Za-zÀ-ÿ]+~u', (string)$a) as $mot) {
        // Les mots courts et les mots communs du métier ne désignent aucun corpus.
        if (mb_strlen($mot) >= 4 && !in_array(mb_strtoupper($mot),
                ['REGIE', 'IMMO', 'AGENCE', 'GESTION', 'IMMOBILIER', 'IMMOBILIERE', 'SARL',
                 'GPE', 'GROUPE'], true)) {
            $enseignes[mb_strtoupper($mot)] = true;
        }
    }
}
// ⚠️ ON MESURE LE DIFF, PAS LE FICHIER — ET C'EST TOUTE LA DIFFÉRENCE. Le moteur nomme
//    légitimement des enseignes à deux endroits : la RECONNAISSANCE de famille (`crg_format`,
//    dont c'est le métier — « l'enseigne AVANT la structure ») et les CLÉS DE VARIANTE de
//    l'architecture ÉDITEUR → MOTEUR → VARIANTE, qui préexistent au dépôt. Compter ces
//    lignes-là accuserait l'architecture elle-même.
//
//    Ce qu'on traque, c'est du code AJOUTÉ pendant le traitement de CE corpus et qui nomme
//    son enseigne : une exception écrite pour un client, déguisée en règle. La fenêtre est
//    donc le travail non encore commité, et les commentaires en sont exclus — ils RACONTENT
//    l'incident, c'est leur rôle.
$racine = dirname(__DIR__, 3);
$moteurs = ['public_html/inc/crg_integration.php', 'public_html/scripts/crg_integration_ics.py',
            'public_html/scripts/crg_ics_core.py', 'public_html/scripts/crg_integration_lots.py',
            'public_html/scripts/crg_texte.py', 'public_html/scripts/crg_integration_phase0.py',
            'public_html/scripts/crg_integration_phase3.py',
            'public_html/scripts/crg_integration_phase4.py'];
$existants = array_values(array_filter($moteurs, fn($f) => is_file($racine . '/' . $f)));
$cmd = 'git -C ' . escapeshellarg($racine) . ' diff HEAD -- '
     . implode(' ', array_map('escapeshellarg', $existants)) . ' 2>&1';
$diff = (string)@shell_exec($cmd);
$mentions = [];
$fichier = '(?)';
foreach (explode("\n", $diff) as $ligne) {
    if (str_starts_with($ligne, '+++ b/')) {
        $fichier = basename(trim(substr($ligne, 6)));
        continue;
    }
    if (!str_starts_with($ligne, '+') || str_starts_with($ligne, '+++')) {
        continue;
    }
    $nu = ltrim(substr($ligne, 1));
    if ($nu === '' || $nu[0] === '*' || str_starts_with($nu, '#')
        || str_starts_with($nu, '//')) {
        continue;
    }
    foreach (array_keys($enseignes) as $mot) {
        if (mb_stripos($nu, $mot) !== false) {
            $mentions[] = sprintf('%s — %s', $fichier, trim(mb_substr($nu, 0, 100)));
        }
    }
}

printf("\n  enseignes du nouveau dépôt (lues en base) : %s\n",
       implode(', ', array_keys($enseignes)) ?: '(aucune)');
printf("  lignes de code AJOUTÉES qui les nomment  : %d\n", count($mentions));
foreach ($mentions as $m) {
    echo "     ⚠ {$m}\n";
}
echo $mentions
    ? "\n  ÉCHEC DE GÉNÉRALISATION : le moteur reconnaît une enseigne, pas une structure.\n"
    : "\n  AUCUNE EXCEPTION DE CORPUS : le moteur ne connaît que des structures.\n";

if ($nouveaux) {
    echo "\n  ce que ce dépôt apporte de neuf :\n";
    foreach ($nouveaux as $n) {
        echo "     · {$n}\n";
    }
}
printf("\n  référence : %d CRG · compris %s %% · autonomie %s %% · %d question(s)\n",
       $pilRef['volumes']['crg'], $pilRef['taux']['compris'], $pilRef['taux']['autonomie'],
       $pilRef['arbitrages']['total']);
printf("  nouveau   : %d CRG · compris %s %% · autonomie %s %% · %d question(s) · "
     . "%d évitée(s) par apprentissage\n",
       $pilNeuf['volumes']['crg'], $pilNeuf['taux']['compris'], $pilNeuf['taux']['autonomie'],
       $pilNeuf['arbitrages']['total'], $pilNeuf['apprentissages']['evitees']);
exit($mentions ? 1 : 0);
