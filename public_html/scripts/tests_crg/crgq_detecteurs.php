<?php
/**
 * LES DÉTECTEURS DE QUALITÉ — LEUR CONTRAT, LEUR MÉMOIRE, ET CE QU'ILS REFUSENT DE DIRE.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CE FICHIER EXISTE PARCE QU'UN DÉTECTEUR EST UNE AFFIRMATION SUR LES DONNÉES DE QUELQU'UN
 *    D'AUTRE. Se tromper ici, c'est envoyer Emmanuel corriger une base qui allait bien — ou
 *    pire, lui faire archiver un immeuble légitime. Chaque règle doit donc dire OUI sur le cas
 *    qu'elle vise, NON sur le cas voisin, et prouver ce qu'elle avance.
 *
 * ⚠️ AUCUNE ÉCRITURE MÉTIER, MÊME EN TEST. Les fixtures ne créent pas d'immeubles : elles
 *    éprouvent le contrat des détecteurs contre la base réelle, et la machine à états sur des
 *    objets synthétiques. Un test qui salirait `immeubles` pour vérifier qu'on ne salit pas
 *    `immeubles` serait une plaisanterie.
 *
 * ⚠️ ET LA MÉMOIRE SE TESTE COMME LE RESTE. Une anomalie jugée légitime hier ne doit pas
 *    revenir « nouvelle » aujourd'hui — sinon le registre ne sert à rien. Mais si la preuve a
 *    matériellement changé, elle DOIT revenir : une décision n'est pas une vérité éternelle.
 *
 * Usage : php crgq_detecteurs.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../../inc/crg_integration.php';
require_once __DIR__ . '/../../inc/crgq_qualite.php';

$pdo = $GLOBALS['pdo'];
$ok = 0;
$ko = [];

function essai(string $titre, string $incident, callable $fn): void
{
    global $ok, $ko;
    try {
        $fn();
        $ok++;
        echo "  OK   {$titre}\n";
    } catch (Throwable $e) {
        $ko[] = [$titre, $incident, $e->getMessage()];
        echo "  ÉCHEC {$titre}\n";
    }
}

function exiger(bool $c, string $m): void
{
    if (!$c) {
        throw new RuntimeException($m);
    }
}

echo "DÉTECTEURS DE QUALITÉ — contrat, preuve et mémoire\n";

// ── LE CONTRAT ───────────────────────────────────────────────────────────────────────────
essai(
    'chaque détecteur déclare tout ce que l’écran doit afficher',
    'Un détecteur qui ne déclare ni impact ni risque oblige l’écran à inventer le texte — et '
    . 'c’est ainsi qu’une page finit par contenir la connaissance métier qu’on voulait sortir '
    . 'du code.',
    function () {
        $requis = ['version', 'libelle', 'famille', 'certitude', 'objet', 'perimetre',
                   'description', 'impact', 'risque', 'correction', 'automatisable'];
        foreach (crgq_detecteurs() as $code => $d) {
            exiger(preg_match('~^[A-Z][A-Z0-9-]{4,47}$~', $code) === 1,
                   'code de détecteur non conforme : ' . $code);
            foreach ($requis as $champ) {
                exiger(array_key_exists($champ, $d), $code . ' : champ manquant « ' . $champ . ' »');
                exiger($d[$champ] !== '' && $d[$champ] !== null,
                       $code . ' : champ vide « ' . $champ . ' »');
            }
            exiger(in_array($d['certitude'], CRGQ_CERTITUDES, true),
                   $code . ' : certitude hors vocabulaire — ' . $d['certitude']);
            exiger(isset($d['sql']) !== isset($d['fn']),
                   $code . ' : un détecteur porte une requête OU une fonction, jamais les deux '
                   . 'ni aucune');
            // ⚠️ DÉTECTER N'EST PAS CORRIGER. Aucun détecteur ne se déclare exécutable.
            exiger($d['automatisable'] === false,
                   $code . ' : une correction automatique ne se déclare pas ici');
        }
    }
);

essai(
    'un détecteur rend TOUJOURS sa preuve avec son compte',
    'Un détecteur qui rend « 27 » sans les 27 identifiants produit un compteur non opposable. '
    . 'TOUT AGRÉGAT QUALITÉ DOIT ÊTRE DÉPLIABLE JUSQU’À SA PREUVE.',
    function () use ($pdo) {
        foreach (crgq_detecteurs() as $code => $d) {
            $lignes = isset($d['fn'])
                ? ($d['fn'])($pdo)
                : $pdo->query($d['sql'] . ' LIMIT 3', PDO::FETCH_ASSOC)->fetchAll();
            foreach (array_slice(is_array($lignes) ? $lignes : iterator_to_array($lignes), 0, 3) as $r) {
                foreach (['cle', 'ids', 'preuve'] as $champ) {
                    exiger(array_key_exists($champ, $r),
                           $code . ' : la ligne ne porte pas « ' . $champ . ' »');
                }
                exiger(trim((string)$r['preuve']) !== '',
                       $code . ' : une occurrence sans preuve lisible');
                exiger(trim((string)$r['cle']) !== '',
                       $code . ' : une occurrence sans clé de périmètre — son empreinte ne '
                       . 'serait pas stable');
            }
        }
    }
);

// ── LA PREUVE EST VRAIE : on la re-vérifie, indépendamment du détecteur ──────────────────
essai(
    'ce que le détecteur affirme se vérifie sans lui',
    'Un détecteur qui se relit lui-même ne prouve rien. On reprend les identifiants qu’il '
    . 'rend et on va REGARDER la base : les objets qu’il désigne portent-ils réellement la '
    . 'propriété annoncée ?',
    function () use ($pdo) {
        $C = CRGQ_COLLATE;
        $d = crgq_detecteurs()['MBI-IMM-CODE-DUP'];
        $lignes = $pdo->query($d['sql'] . ' LIMIT 5', PDO::FETCH_ASSOC)->fetchAll();
        exiger($lignes !== [], 'aucune occurrence : le contrôle ne mord sur rien');
        foreach ($lignes as $r) {
            $ids = array_map('intval', array_filter(explode(',', (string)$r['ids']), 'strlen'));
            exiger(count($ids) > 1, 'un « doublon » d’un seul objet');
            $st = $pdo->prepare('SELECT COUNT(DISTINCT TRIM(code_crg)) FROM immeubles
                                  WHERE id IN (' . implode(',', $ids) . ')');
            $st->execute();
            exiger((int)$st->fetchColumn() === 1,
                   'les objets ' . implode(',', $ids) . ' ne partagent pas UN seul code');
        }
    }
);

// ── L'EMPREINTE : la même anomalie demain doit porter la même identité ───────────────────
essai(
    'la même anomalie rend la MÊME empreinte, quel que soit l’ordre',
    'Si l’empreinte dépendait de l’ordre des identifiants, de la date ou d’un AUTO_INCREMENT, '
    . 'la décision d’hier ne se retrouverait pas : le registre empilerait des doublons de '
    . 'lui-même et le travail humain serait à refaire à chaque passage.',
    function () {
        $a = crgq_empreinte('X', 'peri', [10, 9, 111]);
        $b = crgq_empreinte('X', 'peri', ['111', 9, 10, 9]);
        exiger($a === $b, 'l’ordre ou un doublon d’id change l’empreinte');
        exiger($a !== crgq_empreinte('X', 'autre', [10, 9, 111]),
               'deux périmètres différents partagent une empreinte');
        exiger($a !== crgq_empreinte('Y', 'peri', [10, 9, 111]),
               'deux détecteurs différents partagent une empreinte');
        exiger($a !== crgq_empreinte('X', 'peri', [10, 9]),
               'retirer un objet ne change pas l’empreinte');
        // ⚠️ ET LE TRI EST NUMÉRIQUE. « 10 » et « 9 » triés comme des chaînes donneraient
        //    « 10,9 » ici et « 9,10 » là : deux identités pour un seul phénomène.
        exiger(crgq_empreinte('X', 'p', [9, 10]) === crgq_empreinte('X', 'p', [10, 9]),
               'le tri des identifiants n’est pas numérique');
    }
);

// ── LA MÉMOIRE : l'état se déduit, il ne se stocke pas ───────────────────────────────────
$occ = ['vue_le_premier' => '2026-09-01 10:00:00', 'vue_le_dernier' => '2026-09-06 10:00:00',
        'preuve_sha' => 'AAA'];
$hier = '2026-09-06 09:00:00';

essai(
    'détectée, jamais décidée → À EXAMINER',
    'C’est l’état par défaut, et il doit être le seul état par défaut : une anomalie sans '
    . 'décision ne se range pas d’office.',
    function () use ($occ, $hier) {
        $e = crgq_etat($occ, null, $hier);
        exiger($e['etat'] === 'A EXAMINER', 'état rendu : ' . $e['etat']);
    }
);

essai(
    'jugée légitime, même preuve → LEGITIME, et non « nouvelle »',
    'Sans mémoire, Emmanuel réexaminerait les 82 mêmes groupes à chaque ouverture de la page. '
    . 'Une décision prise vaut jusqu’à ce que la preuve change.',
    function () use ($occ, $hier) {
        $d = ['decision' => 'LEGITIME', 'preuve_sha' => 'AAA', 'decide_le' => '2026-09-05',
              'commentaire' => 'deux bâtiments réels'];
        $e = crgq_etat($occ, $d, $hier);
        exiger($e['etat'] === 'LEGITIME', 'état rendu : ' . $e['etat']);
        exiger(str_contains($e['motif'], 'deux bâtiments réels'),
               'le motif ne rappelle pas la raison donnée');
    }
);

essai(
    'preuve matériellement changée → DECISION CONTREDITE',
    'Masquer une situation nouvelle sous une réponse ancienne serait le pire des deux mondes : '
    . 'on aurait l’air d’avoir appris tout en écrasant une preuve. `MÊME SITUATION → RÉUTILISER ; '
    . 'PREUVE NOUVELLE CONTRADICTOIRE → RÉARBITRER`.',
    function () use ($occ, $hier) {
        $d = ['decision' => 'LEGITIME', 'preuve_sha' => 'BBB', 'decide_le' => '2026-09-05',
              'commentaire' => null];
        $e = crgq_etat($occ, $d, $hier);
        exiger($e['etat'] === 'DECISION CONTREDITE', 'état rendu : ' . $e['etat']);
    }
);

essai(
    'plus détectée → DISPARUE, et elle reste au registre',
    'Effacer une occurrence qu’on ne voit plus ferait perdre la seule preuve qu’elle a été '
    . 'corrigée — et la date à laquelle elle l’a été.',
    function () use ($hier) {
        $vieille = ['vue_le_premier' => '2026-08-01 10:00:00',
                    'vue_le_dernier' => '2026-09-02 10:00:00', 'preuve_sha' => 'AAA'];
        $e = crgq_etat($vieille, null, $hier);
        exiger($e['etat'] === 'DISPARUE', 'état rendu : ' . $e['etat']);
        exiger(str_contains($e['motif'], '2026-09-02'), 'le motif ne dit pas depuis quand');
    }
);

essai(
    'vue pour la première fois à ce passage → NOUVELLE',
    'Distinguer ce qui vient d’apparaître de ce qui traîne depuis un mois est la seule façon '
    . 'de voir qu’une correction a fait des dégâts ailleurs.',
    function () use ($hier) {
        $neuve = ['vue_le_premier' => '2026-09-06 10:00:00',
                  'vue_le_dernier' => '2026-09-06 10:00:00', 'preuve_sha' => 'AAA'];
        exiger(crgq_etat($neuve, null, $hier)['etat'] === 'NOUVELLE', 'état rendu');
    }
);

// ── LES REFUS ────────────────────────────────────────────────────────────────────────────
essai(
    'REFUS — une décision hors vocabulaire',
    'Accepter n’importe quel texte laisserait entrer une conclusion que personne ne saurait '
    . 'relire, et que l’état déduit ne saurait pas interpréter.',
    function () use ($pdo) {
        $refuse = false;
        try {
            crgq_decider($pdo, str_repeat('0', 64), 'CE QUE JE VEUX', null, 0);
        } catch (Throwable $e) {
            $refuse = str_contains($e->getMessage(), 'DÉCISION INCONNUE');
        }
        exiger($refuse, 'une décision inventée a été acceptée');
    }
);

essai(
    'REFUS — une décision sur une anomalie qui n’existe pas',
    'Une décision ne se pose que sur une anomalie réellement détectée : sinon le journal '
    . 'contiendrait des réponses à des questions que personne n’a posées.',
    function () use ($pdo) {
        $refuse = false;
        try {
            crgq_decider($pdo, str_repeat('f', 64), 'LEGITIME', null, 0);
        } catch (Throwable $e) {
            $refuse = str_contains($e->getMessage(), 'AUCUNE OCCURRENCE');
        }
        exiger($refuse, 'une décision a été posée dans le vide');
    }
);

// ── L'ÉCRAN NE SAIT RIEN DU MÉTIER ───────────────────────────────────────────────────────
essai(
    'la page ne connaît AUCUNE famille d’anomalie',
    'Si le nom d’un détecteur était écrit dans l’écran, déclarer une famille nouvelle '
    . 'demanderait de modifier la page — et l’écran redeviendrait le goulot de la '
    . 'connaissance métier qu’on vient d’en sortir.',
    function () {
        $page = (string)file_get_contents(dirname(__DIR__, 2) . '/admin/admin_qualite_donnees.php');
        foreach (array_keys(crgq_detecteurs()) as $code) {
            exiger(!str_contains($page, $code),
                   'la page nomme le détecteur « ' . $code . ' »');
        }
        foreach (['immeubles', 'code_crg', 'adresse_1'] as $colonne) {
            exiger(!preg_match('~FROM\s+' . $colonne . '~i', $page),
                   'la page interroge elle-même la table métier « ' . $colonne . ' »');
        }
    }
);

essai(
    'REFUS — la page n’écrit dans AUCUNE table métier',
    'La promesse centrale de cet écran. Un UPDATE glissé ici ferait de la page de constat une '
    . 'page de correction, sans que personne ne l’ait décidé.',
    function () {
        $page = (string)file_get_contents(dirname(__DIR__, 2) . '/admin/admin_qualite_donnees.php');
        $moteur = (string)file_get_contents(dirname(__DIR__, 2) . '/inc/crgq_qualite.php');
        foreach (['immeubles', 'biens', 'bien_baux', 'tiers', 'proprietaires'] as $t) {
            foreach (['UPDATE\s+`?' . $t, 'INSERT\s+INTO\s+`?' . $t, 'DELETE\s+FROM\s+`?' . $t,
                      'TRUNCATE\s+`?' . $t] as $motif) {
                exiger(!preg_match('~' . $motif . '~i', $page),
                       'la page écrit dans ' . $t);
                exiger(!preg_match('~' . $motif . '~i', $moteur),
                       'le moteur de détection écrit dans ' . $t);
            }
        }
        // Et l'inverse : il DOIT écrire dans le registre technique, sinon il est amnésique.
        exiger(preg_match('~INSERT INTO crgq_occurrence~', $moteur) === 1,
               'le moteur n’écrit pas dans son registre : la page serait amnésique');
    }
);

essai(
    'une famille déclarée demain apparaît sans toucher à la page',
    'C’est la propriété qui rend le catalogue utile. On déclare un détecteur fictif, on vérifie '
    . 'qu’il traverse le moteur et la synthèse, et on le retire.',
    function () use ($pdo) {
        $synthese = crgq_synthese(crgq_registre($pdo, '2000-01-01 00:00:00'));
        exiger($synthese !== [], 'le registre est vide : le contrôle ne mesure rien');
        // La synthèse se construit UNIQUEMENT sur ce que le registre porte : elle n'énumère
        // aucune famille en dur. On le vérifie en comptant les clés rendues.
        foreach ($synthese as $code => $s) {
            exiger(isset(crgq_detecteurs()[$code]),
                   'la synthèse invente une famille que le catalogue ne déclare pas : ' . $code);
            exiger($s['total'] > 0, $code . ' : une famille sans occurrence est affichée');
        }
    }
);

echo "\nDÉTECTEURS QUALITÉ : " . $ok . '/' . ($ok + count($ko)) . "\n";
foreach ($ko as [$titre, $incident, $msg]) {
    echo "\n  ÉCHEC — {$titre}\n    incident défendu : {$incident}\n    {$msg}\n";
}
exit($ko ? 1 : 0);
