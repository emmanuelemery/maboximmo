<?php
/**
 * COHÉRENCE ET COUVERTURE DE L'INTÉGRATION — sur le staging réel, phases scellées.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CE FICHIER EXISTE À CAUSE DES 26 LOTS PERDUS. La phase 2 voyait 98 lots là où le document
 *    en imprime 124 ; elle était scellée, validée, et le bilan d'intégration s'est construit
 *    sur son chiffre amputé. Rien ne l'a signalé — il a fallu qu'Emmanuel compte à la main.
 *
 * ⚠️ `EXACTITUDE ≠ EXHAUSTIVITÉ.` Une phase peut être juste sur ce qu'elle traite et fausse
 *    sur la population. Les deux se démontrent, ou la phase n'est pas valide.
 *
 * Usage : php crgi_coherence.php [import_id]
 */
declare(strict_types=1);
require_once __DIR__ . '/../../inc/crg_integration.php';
require_once __DIR__ . '/../../inc/crgi_arbitrage.php';
require_once __DIR__ . '/../../inc/crgi_pilotage.php';

$pdo = $GLOBALS['pdo'];
// ⚠️ PAS D'IMPORT ÉCRIT EN DUR. « ?? 5 » a survécu à l'annulation de l'import 5 : la suite
//    continuait à tourner, sur un staging vide, et rendait du vert sans rien contrôler.
//
// ⚠️ ET PAS UN SEUL DÉPÔT NON PLUS. Choisir « le plus avancé » suffisait tant qu'un corpus
//    vivait à la fois ; dès qu'une passe d'apprentissage en a ouvert un second, la suite a
//    quitté le dépôt certifié pour le nouveau — et l'ancien n'était plus contrôlé du tout.
//    Un instrument qui ne regarde qu'un sujet à la fois ne prouve pas la non-régression :
//    il déplace simplement son angle mort. On contrôle DONC TOUS les dépôts complets.
if (isset($argv[1])) {
    $aControler = [(int)$argv[1]];
} else {
    $aControler = $pdo->query(
        "SELECT i.id FROM crgi_import i
           JOIN crgi_phase p ON p.import_id = i.id AND p.statut = 'VALIDEE'
          WHERE i.statut <> 'ANNULE'
          GROUP BY i.id HAVING COUNT(p.id) >= 6 ORDER BY i.id"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!$aControler) {
        $aControler = [crgi_import_reference($pdo)];
    }
    // ⚠️ UN DÉPÔT ÉCARTÉ EN SILENCE EST PIRE QU'UN DÉPÔT ROUGE. La suite a affiché
    //    « COHÉRENCE : 132/132 » — parfaitement vert — alors qu'elle ne couvrait que TROIS
    //    dépôts sur quatre : le quatrième avait une phase interrompue, il n'entrait donc pas
    //    dans la liste des « complets » et disparaissait du compte sans un mot. Un instrument
    //    qui rétrécit son sujet sans le dire annonce une réussite sur ce qu'il a bien voulu
    //    regarder. On nomme donc TOUJOURS ceux qu'on laisse dehors, et pourquoi.
    $ecartes = $pdo->query(
        "SELECT i.id, COUNT(c.id) crg,
                GROUP_CONCAT(DISTINCT CONCAT(p.phase, ':', p.statut) ORDER BY p.phase) phases
           FROM crgi_import i
           LEFT JOIN crgi_crg c ON c.import_id = i.id
           LEFT JOIN crgi_phase p ON p.import_id = i.id
          WHERE i.statut <> 'ANNULE'
          GROUP BY i.id
         HAVING crg > 0 AND SUM(p.statut = 'VALIDEE') < 6
          ORDER BY i.id"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ecartes as $e) {
        printf("⚠️  import %d ÉCARTÉ du contrôle — %d CRG déposés, mais une phase n’est pas "
             . "scellée : %s\n", $e['id'], $e['crg'], $e['phases']);
    }
    if ($ecartes) {
        echo "    Un dépôt interrompu n’est pas un dépôt sans intérêt : rejouez ses phases, "
           . "sinon le vert ci-dessous ne parle pas de lui.\n\n";
    }
}
if (count($aControler) > 1) {
    // ⚠️ CHAQUE DÉPÔT DANS SON PROPRE PROCESSUS : la suite est écrite pour un import, et
    //    mélanger deux états en mémoire ferait un rapport que personne ne saurait relire.
    $total = $verts = 0;
    $rouges = [];
    foreach ($aControler as $id) {
        $sortie = [];
        exec(escapeshellarg(PHP_BINARY) . ' -d max_execution_time=0 '
             . escapeshellarg(__FILE__) . ' ' . (int)$id . ' 2>&1', $sortie, $code);
        $txt = implode("
", $sortie);
        if (preg_match('~COHÉRENCE\s*:\s*(\d+)/(\d+)~u', $txt, $m)) {
            $verts += (int)$m[1];
            $total += (int)$m[2];
        }
        echo "── import {$id} : " . ($code === 0 ? 'VERT' : 'ROUGE') . "
";
        if ($code !== 0) {
            $rouges[$id] = $txt;
        }
    }
    echo "
COHÉRENCE : {$verts}/{$total}
";
    foreach ($rouges as $id => $txt) {
        echo "
──── import {$id} ────
";
        foreach (explode("
", $txt) as $l) {
            if (str_contains($l, 'ÉCHEC')) {
                echo '  ' . trim($l) . "
";
            }
        }
    }
    exit($rouges ? 1 : 0);
}
$importId = (int)$aControler[0];
$ok = 0;
$ko = [];

/** Un contrôle, avec l'incident réel qu'il défend. */
function controle(string $titre, string $incident, callable $fn): void
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

function exiger(bool $cond, string $message): void
{
    if (!$cond) {
        throw new RuntimeException($message);
    }
}

echo "COHÉRENCE — import {$importId}\n";

// ── COUVERTURE : ATTENDUE = EXAMINÉE + EXCLUE, INEXPLIQUÉE = 0 ─────────────────────────────
foreach (crgi_couverture($pdo, $importId) as $c) {
    controle(
        sprintf('couverture P%d — %s', $c['phase'], $c['population']),
        'Une phase peut être exacte et incomplète : la population inexpliquée doit être nulle.',
        function () use ($c) {
            exiger($c['inexpliquee'] === 0, sprintf(
                '%d objets inexpliqués : attendue %d, examinée %d, exclue %d',
                $c['inexpliquee'], $c['attendue'], $c['examinee'], $c['exclue']
            ));
        }
    );
}

// ── FRONTIÈRES : deux phases qui parlent du même objet en comptent autant ──────────────────
foreach (crgi_frontieres($pdo, $importId) as $f) {
    controle(
        sprintf('frontière %s : %s → %s', $f['objet'], $f['amont'], $f['aval']),
        'Une phase AVAL ne doit jamais connaître un objet que l’amont ignore : c’est ainsi '
        . 'que 26 lots ont disparu du patrimoine sans que rien ne le signale.',
        function () use ($f) {
            exiger(empty($f['manquants_amont']), sprintf(
                '%d objets connus de « %s » et absents de « %s » : %s',
                count($f['manquants_amont']), $f['aval'], $f['amont'],
                implode(', ', array_slice($f['manquants_amont'], 0, 5))
            ));
            if ($f['sens'] !== 'inclusion') {
                exiger(empty($f['manquants_aval']), sprintf(
                    '%d objets connus de « %s » et absents de « %s » : %s',
                    count($f['manquants_aval']), $f['amont'], $f['aval'],
                    implode(', ', array_slice($f['manquants_aval'], 0, 5))
                ));
            }
        }
    );
}

// ── NOUVEAUTÉ ≠ EXCLUSION ─────────────────────────────────────────────────────────────────
controle(
    'REFUS — une valeur que personne n’a déclarée',
    'Un filtre écrit en positif définit sans le dire tout l’univers autorisé. '
    . '`doublon_statut = "UNIQUE"` a fait disparaître des comptes rendus ENTIERS le jour où un '
    . 'troisième statut est apparu : ils n’étaient ni traités, ni exclus, ni arbitrés — ils '
    . 'n’étaient nulle part, et seul un écart de couverture de deux unités le signalait, tout '
    . 'au bout de la chaîne. Une valeur nouvelle n’est pas une erreur ; qu’elle passe '
    . 'inaperçue en est une.',
    function () use ($pdo, $importId) {
        $inconnues = crgi_valeurs_non_declarees($pdo, $importId);
        $dit = array_map(fn($x) => $x['ou'] . ' = « ' . $x['valeur'] . ' »', $inconnues);
        exiger($inconnues === [],
               count($inconnues) . ' valeur(s) hors du vocabulaire déclaré : '
               . implode(' ; ', array_slice($dit, 0, 5))
               . ' — à déclarer dans `CRGI_VOCABULAIRE`, ou à traiter.');
    }
);

controle(
    'REFUS — « illisible » là où le document a été parfaitement ouvert',
    'Quatre numérisations d’un dépôt portaient « ILLISIBLE — la source elle-même ne se lit '
    . 'pas » alors que le moteur avait ouvert le fichier et compté ses pages : elles n’ont '
    . 'simplement pas de couche texte. Le mot le plus alarmant envoyait chercher une panne du '
    . 'moteur là où il fallait lancer un OCR. `OCR REQUIS` existait déjà — rien n’y menait.',
    function () use ($pdo, $importId) {
        $st = $pdo->prepare('SELECT nom_original, LEFT(message, 60) m FROM crgi_piece
                              WHERE import_id = ? AND etat = "ILLISIBLE"
                                AND message LIKE "%AUCUNE COUCHE TEXTE%"');
        $st->execute([$importId]);
        $mal = $st->fetchAll(PDO::FETCH_ASSOC);
        exiger(!$mal,
               count($mal) . ' pièce(s) déclarées illisibles alors que le moteur dit qu’elles '
               . "n’ont pas de couche texte (état attendu : OCR REQUIS) :\n      · "
               . implode("\n      · ", array_column($mal, 'nom_original')));
    }
);

controle(
    'REFUS — une pièce déposée sans état',
    'Un fichier déposé est soit analysé, soit écarté AVEC SON MOTIF. S’il n’est ni l’un ni '
    . 'l’autre, il a disparu avant même d’entrer dans les phases métier, et aucun contrôle '
    . 'aval ne peut le voir : ils comparent des populations qui ne l’ont jamais reçu.',
    function () use ($pdo, $importId) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM crgi_piece
                              WHERE import_id = ? AND (etat IS NULL OR etat = "")');
        $st->execute([$importId]);
        exiger((int)$st->fetchColumn() === 0, 'des pièces déposées n’ont aucun état');
    }
);

// ── LES SCEAUX ────────────────────────────────────────────────────────────────────────────
controle(
    'aucune phase validée n’est périmée',
    'Un sceau périmé signifie que le résultat validé n’est plus celui qui est en base.',
    function () use ($pdo, $importId) {
        foreach ([0, 1, 2, 3, 4, 5] as $p) {
            $e = crgi_phase_validee($pdo, $importId, $p);
            exiger(!($e['validee'] && $e['perimee']), "la phase {$p} est PÉRIMÉE");
        }
    }
);

controle(
    'aucune empreinte ne dépend d’un AUTO_INCREMENT',
    'Les empreintes portaient l’`id` de staging : une relecture STRICTEMENT IDENTIQUE '
    . 'périmait la phase alors que rien n’avait changé. Un sceau qui crie au loup ne vaut pas '
    . 'mieux qu’un sceau muet.',
    function () {
        // ⚠️ LE FICHIER PEUT ÊTRE EN CRLF. Chercher « \n}\n » n'y trouvait rien, le corps
        //    débordait sur la fonction suivante, et le test accusait la phase 5 d'un `id`
        //    qui appartenait à sa voisine. On normalise les fins de ligne d'abord.
        $src = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../../inc/crg_integration.php'));
        foreach ([2, 3, 4, 5] as $p) {
            $i = strpos($src, "function crgi_empreinte_phase{$p}(");
            exiger($i !== false, "empreinte de la phase {$p} introuvable");
            // Le corps s'arrête à la première accolade fermante en colonne 0 : découper à une
            // longueur fixe faisait déborder le contrôle sur la fonction suivante, et le test
            // accusait la phase 5 d'un `id` qui appartenait au voisin.
            $j = strpos($src, "\n}\n", $i);
            $corps = substr($src, $i, ($j === false ? 2600 : $j - $i));
            exiger(!preg_match('~SELECT\s+(?:\w+\.)?id\b~i', $corps),
                   "l’empreinte de la phase {$p} sélectionne un id technique");
            exiger(str_contains($corps, 'sort($l'),
                   "l’empreinte de la phase {$p} ne trie pas son contenu");
        }
    }
);

// ── LE PLAN D'INTÉGRATION ─────────────────────────────────────────────────────────────────
controle(
    'le plan rend compte de TOUS les mouvements',
    'Un bilan qui ne totalise pas son propre matériau ne prouve rien : 628 lignes étaient '
    . 'restées hors du plan.',
    function () use ($pdo, $importId) {
        $b = crgi_bilan_phase5($pdo, $importId);
        exiger($b['boucle'], sprintf('%d mouvements sur %d couverts',
                                     $b['couverts'], $b['mouvements']));
    }
);

controle(
    'REFUS — « SUPPRIMER » n’existe pas dans le plan',
    '`ABSENT DU NOUVEAU CORPUS ≠ SUPPRIMER` et `ANCIEN LOCATAIRE ≠ SUPPRIMER`. Le maximum '
    . 'est ARCHIVER, et il se démontre.',
    function () use ($pdo, $importId) {
        $st = $pdo->prepare('SELECT DISTINCT action FROM crgi_plan WHERE import_id = ?');
        $st->execute([$importId]);
        $permis = ['CREER', 'METTRE A JOUR', 'ARCHIVER', 'INCHANGE', 'A ARBITRER',
                   'NON INTEGRABLE'];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $a) {
            exiger(in_array($a, $permis, true), "action interdite dans le plan : « {$a} »");
        }
    }
);

controle(
    'REFUS — aucun « CRÉER » sans confrontation à MBI',
    '`OBSERVÉ DANS LE CORPUS ≠ NOUVEAU DANS MBI` : annoncer 119 locataires à créer aurait '
    . 'fabriqué 88 doublons de locataires que MBI porte déjà.',
    function () use ($pdo, $importId) {
        foreach (['LOCATAIRES', 'PROPRIETAIRES'] as $famille) {
            $st = $pdo->prepare('SELECT SUM(nombre) FROM crgi_plan
                                  WHERE import_id = ? AND famille = ? AND action = "INCHANGE"');
            $st->execute([$importId, $famille]);
            exiger((int)$st->fetchColumn() > 0,
                   "{$famille} : aucune ligne INCHANGÉ — la confrontation à MBI n’a pas eu lieu");
        }
    }
);

controle(
    'REFUS — aucune page citée n’est hors du document',
    'Toute la promesse « 1 CLIC = PREUVE » repose sur ce numéro. La phase 2 ajoutait l’offset '
    . 'du CRG à une page que le lecteur rendait DÉJÀ absolue : sur un dépôt de 906 pages, '
    . '`crgi_immeuble.page` montait à 1809 — 905 + 905 - 1. Un arbitrage d’immeuble sur deux '
    . 'renvoyait vers une page inexistante, et personne ne le voyait puisque `crgi_occupation` '
    . 'et `crgi_mouvement`, eux, étaient justes.',
    function () use ($pdo, $importId) {
        // ⚠️ CONTRÔLE GÉNÉRIQUE : toute table de staging qui cite une page doit la citer
        //    DANS les bornes de sa pièce. Il vaut pour la table ajoutée demain.
        $pages = (int)$pdo->query('SELECT COALESCE(MAX(nb_pages), 0) FROM crgi_piece
                                    WHERE import_id = ' . $importId)->fetchColumn();
        exiger($pages > 0, 'aucune pièce : le contrôle ne prouverait rien');
        $fautes = [];
        foreach (['crgi_immeuble', 'crgi_lot', 'crgi_occupation', 'crgi_mouvement',
                  'crgi_page'] as $t) {
            $col = $t === 'crgi_page' ? 'page_no' : 'page';
            $st = $pdo->prepare("SELECT COUNT(*) n, MAX(`$col`) m FROM `$t`
                                  WHERE import_id = ? AND `$col` > ?");
            $st->execute([$importId, $pages]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if ((int)$r['n'] > 0) {
                $fautes[] = sprintf('%s : %d lignes, jusqu’à la page %d (le document en a %d)',
                                    $t, (int)$r['n'], (int)$r['m'], $pages);
            }
        }
        exiger(!$fautes, implode(' · ', $fautes));
    }
);

controle(
    'REFUS — aucun lot ne perd son occupant parce qu’une page se termine',
    'Quand le bloc d’un lot commence au BAS d’une page, sa ligne « Locataire: » est imprimée '
    . 'sur la suivante, sous l’en-tête « Suite ». La phase 3 ne complétait que le solde : '
    . 'l’observation restait SANS occupant alors que le document le nomme, et la phase 4 le '
    . 'lisait. Un objet démontré en aval et inconnu en amont, c’est ainsi que 26 lots ont '
    . 'disparu du patrimoine sans que rien ne le signale. Constaté sur CHAPONOST (BALDYGA '
    . 'Axel, LECOEUVRE Arnaud) ET sur VIENNE (MONCHANIN Francoise, lot 09 du compte '
    . '1105404307, dans deux CRG).',
    function () use ($pdo, $importId) {
        // ⚠️ LE CONTRÔLE EST GÉNÉRIQUE, PAS NOMINATIF : tout lot dont la phase 4 nomme
        //    l’occupant et dont la phase 3 n’en nomme aucun est un échec, quel que soit le
        //    corpus, l’éditeur ou l’agence.
        $st = $pdo->prepare(
            'SELECT c.compte, o.lot_reference, MIN(m.page) page,
                    MIN(m.locataire) nomme_par_p4
               FROM crgi_occupation o
               JOIN crgi_crg c ON c.id = o.crg_id
               JOIN crgi_mouvement m ON m.import_id = o.import_id AND m.crg_id = o.crg_id
                                    AND m.lot_reference = o.lot_reference
                                    AND m.locataire IS NOT NULL AND m.locataire <> ""
              WHERE o.import_id = ? AND (o.locataire IS NULL OR o.locataire = "")
              GROUP BY c.compte, o.lot_reference'
        );
        $st->execute([$importId]);
        $perdus = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $perdus[] = sprintf('%s / lot %s → « %s » (p.%d)',
                $r['compte'], $r['lot_reference'], $r['nomme_par_p4'], (int)$r['page']);
        }
        exiger(!$perdus, count($perdus) . ' lot(s) dont la phase 4 nomme l’occupant et la '
                       . 'phase 3 non : ' . implode(' · ', array_slice($perdus, 0, 4)));
    }
);

controle(
    'REFUS — un code de compte ne se rapproche jamais hors de son système',
    '`01600000` est SCI JOURNET chez `loca_immo_lyon` ; le CRG EMERY IMMO qui porte ce même '
    . 'code appartient à Madame GERMAIN. Chercher par le code seul rattachait silencieusement '
    . 'un CRG de RIOM au mandant lyonnais, et le faux rapprochement se serait propagé à tout '
    . 'ce que les phases suivantes construisent dessus. L’identité est le couple '
    . '`(code, système)` — `P3A-COMPTE-03`.',
    function () use ($pdo, $importId) {
        // La correspondance doit couvrir TOUTE famille que le référentiel sait reconnaître :
        // une famille absente rendrait le contrôle muet au lieu de rouge.
        foreach (['lyon', 'emery_immo', 'septeo_spi'] as $f) {
            exiger(isset(CRGI_SYSTEME_DU_FORMAT[$f]),
                   "la famille {$f} n’a pas de système MBI déclaré");
        }
        // Et aucun CRG de l’import ne doit être rattaché à un compte d’un AUTRE système.
        $st = $pdo->prepare(
            'SELECT g.compte, g.format, c.systeme, COUNT(*) n
               FROM crgi_crg g
               JOIN crg_trimestres tr ON tr.id = g.mbi_trimestre_id
               JOIN proprietaire_comptes_crg c ON c.id = tr.id_compte_mandant
              WHERE g.import_id = ?
              GROUP BY g.compte, g.format, c.systeme'
        );
        $st->execute([$importId]);
        $fautes = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $attendu = CRGI_SYSTEME_DU_FORMAT[(string)$r['format']] ?? null;
            if ($attendu !== null && (string)$r['systeme'] !== $attendu) {
                $fautes[] = $r['compte'] . ' (' . $r['format'] . ' → ' . $r['systeme'] . ')';
            }
        }
        exiger(!$fautes, 'rapprochements hors système : ' . implode(', ', $fautes));
    }
);

// ── AUCUNE ÉCRITURE MÉTIER ────────────────────────────────────────────────────────────────
controle(
    'aucune écriture dans les données métier de MBI',
    'Le module est entièrement en staging : aucune table métier ne doit bouger.',
    function () use ($pdo) {
        $temoins = ['biens' => 1379, 'bien_baux' => 670, 'immeubles' => 1009,
                    'proprietaires' => 393, 'crg_trimestres' => 501, 'crg_ecritures' => 26711,
                    'crg_situations_locataires' => 3226];
        foreach ($temoins as $t => $attendu) {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
            exiger($n === $attendu, "{$t} : {$n} lignes au lieu de {$attendu}");
        }
    }
);

controle(
    'REFUS — le code d’intégration n’écrit jamais dans une table métier',
    'La garantie ne doit pas reposer sur un comptage a posteriori : le code lui-même ne doit '
    . 'porter aucun INSERT/UPDATE/DELETE hors du staging `crgi_*`.',
    function () {
        $src = file_get_contents(__DIR__ . '/../../inc/crg_integration.php');
        $interdits = ['biens', 'bien_baux', 'immeubles', 'proprietaires', 'crg_trimestres',
                      'crg_ecritures', 'crg_situations_locataires', 'tiers'];
        foreach ($interdits as $t) {
            foreach (['INSERT INTO', 'UPDATE', 'DELETE FROM'] as $verbe) {
                $motif = '~' . preg_quote($verbe, '~') . '\s+`?' . preg_quote($t, '~') . '`?\b~i';
                exiger(!preg_match($motif, $src),
                       "« {$verbe} {$t} » trouvé dans le code d’intégration");
            }
        }
    }
);


// ── LES INCIDENTS QUI NE SE VOIENT QUE SUR LES DONNÉES ────────────────────────────────────
controle(
    'une page blanche est le verso de la précédente, pas une page perdue',
    'L’impression en PDF produit 221 pages blanches. Les laisser non affectées afficherait '
    . '« 221 pages non identifiées » sur un dépôt parfaitement lisible.',
    function () use ($pdo, $importId) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM crgi_page
                              WHERE import_id = ? AND (signal_page IS NULL OR signal_page = "")
                                AND crg_id IS NULL');
        $st->execute([$importId]);
        exiger((int)$st->fetchColumn() === 0, 'des pages restent sans signal ET sans CRG');
    }
);

controle(
    'REFUS — deux lots « 01 » de deux comptes ne sont pas le même lot',
    'Grouper sur la seule référence a fusionné l’appartement 01 de RAYNAL (occupant SANCHEZ) '
    . 'et celui de MARTINEZ (occupant CHAYNARD) : deux chronologies stables entrelacées, d’où '
    . 'un départ et deux changements ENTIÈREMENT FABRIQUÉS.',
    function () use ($pdo, $importId) {
        // ⚠️ ON N'EXIGE PLUS ZÉRO CONFLIT — ON EXIGE ZÉRO CONFLIT MUET. Exiger zéro rendait ce
        //    contrôle rouge sur un phénomène que le moteur ne PEUT pas trancher : une même
        //    personne lue de deux façons par la couche texte. Un rouge permanent que personne
        //    ne peut résoudre n'est pas un garde-fou, c'est un bruit qu'on finit par ignorer.
        //    La fusion reste interdite ; ce qui change, c'est que le doute devient une QUESTION.
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM (
                SELECT c.compte, o.lot_reference, o.date_arrete
                  FROM crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
                 WHERE o.import_id = ? AND o.locataire IS NOT NULL
                 GROUP BY c.compte, o.lot_reference, o.date_arrete, o.rang
                HAVING COUNT(DISTINCT o.locataire) > 1) t'
        );
        $st->execute([$importId]);
        $detectes = (int)$st->fetchColumn();
        $arbitrables = 0;
        foreach (crgi_conflits_identite($pdo, $importId, 'CONFLIT_OCCUPANT') as $x) {
            $arbitrables++;
        }
        exiger($detectes === $arbitrables,
               $detectes . ' conflits d’occupant détectés, ' . $arbitrables
               . ' arbitrables : la différence est invisible pour Emmanuel');
    }
);

// ── L'INVARIANT DES IDENTITÉS ────────────────────────────────────────────────────────────
controle(
    'REFUS — un conflit d’identité qui n’est qu’une virgule',
    '« 15 rue Siméon Gouet » et « 15, Rue Siméon Gouet » : le document imprime le MÊME '
    . 'immeuble avec deux typographies, et le moteur en faisait une question d’identité. '
    . '`NORMALISATION N’EST PAS RAPPROCHEMENT APPROXIMATIF` (INTEG-RAPPRO-02) — on ignore '
    . 'accents, casse et séparateurs, RIEN d’autre ; ce qui reste identique après cela n’est '
    . 'pas un désaccord à trancher.',
    function () use ($pdo, $importId) {
        $faux = [];
        foreach (crgi_conflits_identite($pdo, $importId) as $c) {
            $distinctes = array_unique(array_map(
                fn($l) => crgi_plat((string)$l['valeur']), $c['lectures']));
            if (count($distinctes) < 2) {
                $faux[] = $c['type'] . ' « ' . mb_substr((string)$c['cle'], 0, 40) . ' » : '
                        . implode(' / ', array_map(fn($l) => $l['valeur'],
                                                   array_slice($c['lectures'], 0, 2)));
            }
        }
        exiger(!$faux, count($faux) . ' conflit(s) purement typographique(s) posés en '
                       . "question :\n      · " . implode("\n      · ", $faux));
    }
);

controle(
    'REFUS — un conflit d’identité détecté mais invisible',
    'Le harnais voyait « FaULARSEN Swan » et « FOU LARSEN Swan » et virait au rouge ; l’écran '
    . 'd’arbitrage, lui, ne posait aucune question. Le moteur savait qu’il ne savait pas, et '
    . 'Emmanuel n’avait nulle part où trancher. Un défaut détecté sans porte de sortie est un '
    . 'échec de couverture, pas un contrôle.',
    function () use ($pdo, $importId) {
        $conflits = crgi_conflits_identite($pdo, $importId);
        $dansLaFile = [];
        foreach (crgi_arbitrages($pdo, $importId) as $g) {
            if (!str_starts_with((string)$g['groupe'], 'IDENTITE-')) {
                continue;
            }
            foreach ($g['lignes'] as $l) {
                $dansLaFile[$g['cible'] . ':' . (int)$l['cible_id']] = true;
            }
        }
        $muets = [];
        foreach ($conflits as $c) {
            if (empty($dansLaFile[$c['type'] . ':' . $c['cible_id']])) {
                $muets[] = $c['type'] . ' ' . $c['cle'];
            }
            // Et chaque côté du conflit doit être atteignable, sinon la question est aveugle.
            foreach ($c['lectures'] as $l) {
                exiger((int)$l['crg_id'] > 0 && (int)$l['page'] > 0,
                       'une lecture de « ' . $c['cle'] . ' » n’a pas de preuve atteignable');
            }
        }
        exiger($muets === [],
               count($muets) . ' conflit(s) détecté(s) hors de la file : '
               . implode(' ; ', array_slice($muets, 0, 3)));
    }
);

controle(
    'REFUS — aucune identité n’est fusionnée sur une ressemblance',
    'Rapprocher deux noms voisins créerait une identité par approximation. La mesure de '
    . 'proximité a le droit d’ouvrir une question ; jamais de la fermer.',
    function () use ($pdo, $importId) {
        // Le moteur n'écrit jamais de décision de sa propre initiative : toute décision
        // d'identité présente en base doit porter un auteur humain.
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM crgi_arbitrage
              WHERE import_id = ? AND cible_type LIKE "CONFLIT\_%" AND decide_par IS NULL'
        );
        $st->execute([$importId]);
        exiger((int)$st->fetchColumn() === 0,
               'une décision d’identité existe sans auteur humain');
    }
);

controle(
    'REFUS — une réimpression n’est pas un nouvel événement',
    'Trois CRG du compte 1105403390 contiennent leurs propres pages DEUX FOIS, caractère pour '
    . 'caractère. Les compter doublait l’argent de ce compte.',
    function () use ($pdo, $importId) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM crgi_mouvement
                              WHERE import_id = ? AND reimpression = 1 AND additionnable = 1');
        $st->execute([$importId]);
        exiger((int)$st->fetchColumn() === 0,
               'des lignes réimprimées entrent dans les totaux');
        // ⚠️ UN CONTRÔLE DU MOTEUR N'EXIGE PAS LA PRÉSENCE DU PHÉNOMÈNE. Cette ligne
        //    demandait « au moins une réimpression », pour s'assurer que la règle restait
        //    éprouvée. L'intention était juste, le moyen non : sur un corpus qui n'en contient
        //    aucune — ce qui est parfaitement légitime —, elle accusait le moteur d'un défaut
        //    qui n'existait pas. C'est un contrôle du CORPUS déguisé en contrôle du moteur.
        //    La preuve que la règle sait mordre appartient à l'ÉPREUVE DES TESTS, qui
        //    réintroduit le défaut et exige le rouge ; ici on ne vérifie que l'invariant.
        $st = $pdo->prepare('SELECT COUNT(*) FROM crgi_mouvement
                              WHERE import_id = ? AND reimpression = 1');
        $st->execute([$importId]);
        $vues = (int)$st->fetchColumn();
        echo '       (' . $vues . " ligne(s) réimprimée(s) dans ce dépôt)\n";
    }
);

controle(
    'REFUS — un CRG complémentaire n’est pas une réénonciation',
    'Deux CRG du même compte, de la même période et du même arrêté ne partageaient qu’UNE '
    . 'page de contenu sur trois : deux documents complémentaires. Dédoublonner sur la clé '
    . 'aurait détruit le second.',
    function () use ($pdo, $importId) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM crgi_crg
                              WHERE import_id = ? AND doublon_qualification = "B"
                                AND doublon_statut <> "UNIQUE"');
        $st->execute([$importId]);
        exiger((int)$st->fetchColumn() === 0,
               'un CRG qualifié « montants réellement différents » a été écarté');
        $st = $pdo->prepare('SELECT COUNT(*) FROM crgi_crg
                              WHERE import_id = ? AND doublon_statut = "MEME CLE CONTENU DIFFERENT"');
        $st->execute([$importId]);
        exiger((int)$st->fetchColumn() === 0,
               'des collisions restent non qualifiées : elles seraient invisibles pour la suite');
        // ⚠️ « JE NE SAIS PAS TRANCHER » PEUT CACHER « JE N'AI RIEN LU ». Quatre collisions
        //    étaient classées indéterminables sur le motif « trop peu de montants lus (0 et
        //    0) » : le moteur ne reconnaissait tout simplement pas le séparateur décimal de
        //    l'éditeur ICS. Une prudence de façade sur un défaut de lecture est pire qu'une
        //    erreur franche — elle a l'air d'une décision.
        $st = $pdo->prepare('SELECT COUNT(*) FROM crgi_crg
                              WHERE import_id = ? AND doublon_qualif_motif LIKE "LECTURE :%"');
        $st->execute([$importId]);
        exiger((int)$st->fetchColumn() === 0,
               'une collision est restée sans verdict parce que le document n’a pas été LU — '
               . 'ce n’est pas une indétermination métier, c’est un défaut de moteur');
    }
);

controle(
    'le plan couvre TOUT le vocabulaire déclaré des natures',
    'La catégorie « IMPOTS ET TAXES » était déclarée au vocabulaire et produite par le moteur, '
    . 'mais aucune famille du plan ne la reprenait : 340 mouvements — une taxe foncière '
    . 'entière — n’étaient ni intégrés, ni exclus, ni arbitrés. Ils n’étaient nulle part. Il a '
    . 'fallu un corpus de 14 370 lignes pour que l’écart devienne visible ; sur un petit dépôt '
    . 'il serait passé inaperçu pendant des mois.',
    function () use ($pdo, $importId) {
        // ⚠️ L'ORACLE EST LE VOCABULAIRE, PAS UNE LISTE À CÔTÉ. Une nature déclarée demain
        //    devra trouver sa famille, sans qu'on ait pensé à allonger ce contrôle.
        $declarees = CRGI_VOCABULAIRE['crgi_mouvement.categorie'] ?? [];
        exiger($declarees !== [], 'le vocabulaire des natures n’est pas déclaré');
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/inc/crg_integration.php');
        // Le bloc des familles du plan, tel que la phase 5 l'écrit.
        $orphelines = [];
        foreach ($declarees as $cat) {
            if (!preg_match('~\[\s*\'[A-ZÉ \'’]+\'\s*,\s*\[[^\]]*' . preg_quote($cat, '~')
                            . '[^\]]*\]~u', $source)
                && !str_contains($cat, 'NON ADDITIONNABLE') && $cat !== 'INDETERMINABLE') {
                $orphelines[] = $cat;
            }
        }
        exiger(!$orphelines,
               "nature(s) déclarée(s) qu’aucune famille du plan ne reprend :\n      · "
               . implode("\n      · ", $orphelines));
        // Et la preuve par les faits : le plan doit refermer sur TOUS les mouvements du dépôt.
        $b = crgi_bilan_phase5($pdo, $importId);
        exiger($b['boucle'],
               $b['couverts'] . ' mouvements couverts sur ' . $b['mouvements']);
    }
);

controle(
    'chaque question déclare SA CAUSE — document ou base',
    'Une ambiguïté née des doublons de MBI comptée comme une difficulté de lecture fait '
    . 'baisser le taux de compréhension du lecteur pour une faute qui n’est pas la sienne — et '
    . 'envoie chercher la réponse dans le mauvais document. La cause est DÉCLARÉE par la '
    . 'famille d’arbitrage, jamais devinée d’après son nom.',
    function () use ($pdo, $importId) {
        $sans = [];
        foreach (crgi_arbitrages($pdo, $importId) as $g) {
            $c = $g['cause'] ?? null;
            if (!in_array($c, ['DOCUMENT', 'MBI'], true)) {
                $sans[] = (string)$g['groupe'] . ' → ' . var_export($c, true);
            }
        }
        exiger(!$sans, "famille(s) sans cause déclarée :\n      · " . implode("\n      · ", $sans));
        // ⚠️ ET LES DEUX COMPTEURS NE SE SOMMENT PAS EN CACHETTE : leur total doit retomber
        //    exactement sur la file, sinon une question serait comptée deux fois ou nulle part.
        $p = crgi_pilotage($pdo, $importId);
        exiger($p['causes']['DOCUMENT'] + $p['causes']['MBI'] === $p['arbitrages']['total'],
               'les causes ne totalisent pas la file : '
               . $p['causes']['DOCUMENT'] . ' + ' . $p['causes']['MBI'] . ' ≠ '
               . $p['arbitrages']['total']);
    }
);

controle(
    'REFUS — une question à laquelle personne ne peut répondre',
    'Douze écritures « Solde » identiques dans la même situation : le moteur demandait à '
    . 'Emmanuel LAQUELLE correspond. Il voit exactement ce que le moteur voit — rien de plus. '
    . 'Une question sans réponse possible n’est pas un arbitrage, c’est une limite : elle se '
    . 'nomme, se compte et ne se pose pas. Emmanuel, 04/09/2026 : « je ne veux décider que sur '
    . '20 à 30 points au total ».',
    function () use ($pdo, $importId) {
        // Ce qui est indécidable pour tout le monde ne doit JAMAIS entrer dans la file.
        $st = $pdo->prepare('SELECT COUNT(*) FROM crgi_mouvement
                              WHERE import_id = ? AND rapprochement = "NON RAPPROCHABLE"');
        $st->execute([$importId]);
        $muets = (int)$st->fetchColumn();
        $dansLaFile = 0;
        foreach (crgi_file_arbitrages($pdo, $importId) as $a) {
            if (($a['contexte']['id'] ?? null) === null || $a['cible'] !== 'MOUVEMENT') {
                continue;
            }
            $q = $pdo->prepare('SELECT rapprochement FROM crgi_mouvement WHERE id = ?');
            $q->execute([(int)$a['cible_id']]);
            if ((string)$q->fetchColumn() === 'NON RAPPROCHABLE') {
                $dansLaFile++;
            }
        }
        exiger($dansLaFile === 0,
               $dansLaFile . ' mouvement(s) indécidables pour tout le monde sont pourtant posés '
               . 'en question');
        // ⚠️ ET L'INVERSE EST TOUT AUSSI INTERDIT : requalifier une VRAIE question en limite
        //    ferait disparaître du travail réel. Ce qui est décidable reste dans la file.
        $st = $pdo->prepare('SELECT COUNT(*) FROM crgi_mouvement
                              WHERE import_id = ? AND rapprochement = "CANDIDAT NON DEMONTRABLE"');
        $st->execute([$importId]);
        $vraies = (int)$st->fetchColumn();
        if ($vraies > 0) {
            $vues = 0;
            foreach (crgi_file_arbitrages($pdo, $importId, ['detail' => 1]) as $a) {
                if ($a['groupe'] === 'MOUVEMENT-CANDIDAT-NON-DEMONTRABLE') {
                    $vues++;
                }
            }
            exiger($vues === $vraies,
                   $vraies . ' mouvement(s) réellement à trancher, ' . $vues . ' dans la file');
        }
        echo '       (' . $muets . ' ligne(s) sans réponse possible, nommées et hors file ; '
           . $vraies . " à trancher)\n";
    }
);

controle(
    'REFUS — aucun cumul sur des périodes qui se chevauchent',
    '60 comptes sur 72 portent des CRG dont les périodes se recouvrent : le loyer d’avril est '
    . 'énoncé dans le relevé d’avril ET dans celui d’avril-mai. Un « total du dépôt » '
    . 'compterait avril deux fois.',
    function () use ($pdo, $importId) {
        $b = crgi_bilan_phase4($pdo, $importId);
        exiger(!empty($b['par_arrete']), 'le bilan ne présente pas les montants par arrêté');
        $page = file_get_contents(__DIR__ . '/../../admin/admin_crg_integration.php');
        exiger(str_contains($page, 'par_arrete'),
               'l’écran ne lit pas les montants par arrêté');
        exiger(str_contains($page, 'aucun montant'),
               'l’écran ne dit pas pourquoi la table des natures ne porte pas de montant');
    }
);

controle(
    'REFUS — l’absence d’un locataire n’est pas un départ',
    'Un lot qui cesse d’apparaître ne prouve rien : le CRG peut ne pas avoir été déposé. Le '
    . 'départ n’est retenu que si le document le DÉMONTRE — réénonciation avec un autre '
    . 'occupant, ou fin de bail imprimée et atteinte à l’arrêté.',
    function () use ($pdo, $importId) {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM crgi_occupation
              WHERE import_id = ? AND statut IN ("PARTI DEMONTRE", "ANCIEN LOCATAIRE AVEC DETTE")
                AND (bail_au IS NULL OR bail_au > date_arrete)
                AND statut_motif NOT LIKE "%réénoncé%"'
        );
        $st->execute([$importId]);
        exiger((int)$st->fetchColumn() === 0,
               'un départ est retenu sans réénonciation ni fin de bail atteinte');
    }
);

controle(
    'REFUS — un congé postérieur à l’arrêté ne fait pas partir l’occupant',
    '13 congés du dépôt sont datés APRÈS la date d’arrêté : ils décrivent un occupant TOUJOURS '
    . 'EN PLACE. Sans cette borne, 42 faux anciens locataires.',
    function () use ($pdo, $importId) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM crgi_occupation
                              WHERE import_id = ? AND bail_au > date_arrete
                                AND statut IN ("PARTI DEMONTRE", "ANCIEN LOCATAIRE AVEC DETTE")');
        $st->execute([$importId]);
        exiger((int)$st->fetchColumn() === 0,
               'un occupant est déclaré parti alors que son congé est postérieur à l’arrêté');
    }
);

controle(
    'REFUS — la maille d’affichage n’est jamais plus fine que la maille de la preuve',
    'Une charge démontrée au compte RESTE au compte. Aucun prorata, aucun rattachement forcé '
    . 'à un lot ou à un locataire.',
    function () use ($pdo, $importId) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM crgi_mouvement
                              WHERE import_id = ? AND maille = "COMPTE"
                                AND (lot_reference IS NOT NULL OR locataire IS NOT NULL)');
        $st->execute([$importId]);
        exiger((int)$st->fetchColumn() === 0,
               'un mouvement démontré au compte porte un lot ou un locataire');
        $st = $pdo->prepare('SELECT COUNT(*) FROM crgi_mouvement
                              WHERE import_id = ? AND maille = "LOT"
                                AND (lot_reference IS NULL OR lot_reference = "")');
        $st->execute([$importId]);
        exiger((int)$st->fetchColumn() === 0, 'un mouvement à la maille LOT n’a pas de lot');
    }
);

controle(
    'REFUS — un montant qu’on ne sait pas placer reste INDETERMINABLE',
    'Aucune affectation « au plus proche » sans borne. Un indéterminable est conservé, compté '
    . 'et remonté à l’écran — jamais rangé dans la colonne d’à côté.',
    function () use ($pdo, $importId) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM crgi_mouvement
                              WHERE import_id = ? AND categorie = "INDETERMINABLE"
                                AND additionnable = 1');
        $st->execute([$importId]);
        exiger((int)$st->fetchColumn() === 0, 'un indéterminable entre dans les totaux');
    }
);

controle(
    'REFUS — un stock n’entre jamais dans un total de flux',
    '`STOCK ≠ FLUX` : encours et soldes sont des photographies, jamais additionnées entre '
    . 'deux périodes ni mêlées aux mouvements.',
    function () use ($pdo, $importId) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM crgi_mouvement
                              WHERE import_id = ? AND categorie IN ("ENCOURS", "SOLDE")
                                AND flux = 1');
        $st->execute([$importId]);
        exiger((int)$st->fetchColumn() === 0, 'un encours ou un solde est marqué comme flux');
    }
);


controle(
    'le plan boucle FAMILLE PAR FAMILLE, pas seulement en total',
    'Un compteur global masque une population : deux grands totaux peuvent se refermer alors '
    . 'qu’une famille en perd la moitié. Les 5 successions étaient comptées DEUX FOIS — en '
    . 'CRÉER pour l’entrant et en ARCHIVER pour le sortant — soit 483 verdicts pour 478 '
    . 'observations, invisibles dans le total général.',
    function () use ($pdo, $importId) {
        // La population source de chaque famille, mesurée SUR SA PROPRE MAILLE : une
        // occupation observée sur sept périodes n’est pas sept objets à écrire.
        $sources = [
            'PROPRIETAIRES' => 'SELECT COUNT(DISTINCT proprietaire) FROM crgi_crg
                                 WHERE import_id = ? AND proprietaire IS NOT NULL
                                   AND proprietaire <> ""',
            'COMPTES MANDANTS' => 'SELECT COUNT(DISTINCT compte) FROM crgi_crg WHERE import_id = ?',
            'IMMEUBLES' => 'SELECT COUNT(*) FROM (SELECT COALESCE(code, CONCAT(nom,"|",code_postal)) k
                              FROM crgi_immeuble WHERE import_id = ? GROUP BY k) t',
            'LOTS' => 'SELECT COUNT(*) FROM (SELECT DISTINCT c.compte, o.reference
                         FROM crgi_lot o JOIN crgi_crg c ON c.id = o.crg_id
                        WHERE o.import_id = ?) t',
            'LOCATAIRES' => 'SELECT COUNT(DISTINCT locataire) FROM crgi_occupation
                              WHERE import_id = ? AND locataire IS NOT NULL',
            'OCCUPATIONS' => 'SELECT COUNT(*) FROM crgi_occupation WHERE import_id = ?',
            'APPELS' => 'SELECT COUNT(*) FROM crgi_mouvement WHERE import_id = ? AND categorie IN
                          ("LOYER APPELE","CHARGE APPELEE AU LOCATAIRE",
                           "AUTRE APPELE AU LOCATAIRE","INDETERMINABLE")',
            'ENCAISSEMENTS' => 'SELECT COUNT(*) FROM crgi_mouvement
                                 WHERE import_id = ? AND categorie = "ENCAISSEMENT"',
            'ENCOURS' => 'SELECT COUNT(*) FROM crgi_mouvement
                           WHERE import_id = ? AND categorie = "ENCOURS"',
            'CHARGES' => 'SELECT COUNT(*) FROM crgi_mouvement
                           WHERE import_id = ? AND categorie = "CHARGE"',
            'FRAIS ET ASSURANCES' => 'SELECT COUNT(*) FROM crgi_mouvement
                                       WHERE import_id = ? AND categorie = "FRAIS ET ASSURANCES"',
            'FLUX PROPRIETAIRE' => 'SELECT COUNT(*) FROM crgi_mouvement
                                     WHERE import_id = ? AND categorie = "VERSEMENT PROPRIETAIRE"',
            'SOLDES' => 'SELECT COUNT(*) FROM crgi_mouvement
                          WHERE import_id = ? AND categorie = "SOLDE"',
            'AGREGATS ET DETAILS' => 'SELECT COUNT(*) FROM crgi_mouvement
                                       WHERE import_id = ? AND categorie IN
                                        ("AGREGAT (NON ADDITIONNABLE)","DETAIL (NON ADDITIONNABLE)")',
        ];
        $st = $pdo->prepare('SELECT famille, SUM(nombre) n FROM crgi_plan
                              WHERE import_id = ? GROUP BY famille');
        $st->execute([$importId]);
        $verdicts = $st->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($sources as $famille => $sql) {
            $q = $pdo->prepare($sql);
            $q->execute([$importId]);
            $source = (int)$q->fetchColumn();
            $total = (int)($verdicts[$famille] ?? 0);
            exiger($source === $total, sprintf(
                '%s : %d objets en source, %d verdicts (écart %+d)',
                $famille, $source, $total, $source - $total
            ));
        }
    }
);

controle(
    'les trois phases comptent le même nombre de lots',
    'La phase 2 en voyait 98 là où le document en imprime 124 : 26 lots absents du patrimoine, '
    . 'et le bilan d’intégration construit sur le chiffre amputé.',
    function () use ($pdo, $importId) {
        $n = function (string $sql) use ($pdo, $importId) {
            $st = $pdo->prepare($sql);
            $st->execute([$importId]);
            return (int)$st->fetchColumn();
        };
        $p2 = $n('SELECT COUNT(*) FROM (SELECT DISTINCT c.compte, o.reference FROM crgi_lot o
                    JOIN crgi_crg c ON c.id = o.crg_id WHERE o.import_id = ?) t');
        $p3 = $n('SELECT COUNT(*) FROM (SELECT DISTINCT c.compte, o.lot_reference
                    FROM crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
                   WHERE o.import_id = ?) t');
        $p4 = $n('SELECT COUNT(*) FROM (SELECT DISTINCT c.compte, m.lot_reference
                    FROM crgi_mouvement m JOIN crgi_crg c ON c.id = m.crg_id
                   WHERE m.import_id = ? AND m.lot_reference IS NOT NULL
                     AND m.lot_reference <> "") t');
        exiger($p2 === $p3 && $p3 === $p4,
               "P2 = {$p2}, P3 = {$p3}, P4 = {$p4} — les trois doivent être égaux");
    }
);


controle(
    'P5 est scellée, et son sceau voit ce qu’il prétend couvrir',
    'Le plan est la dernière chose qu’Emmanuel lit avant de décider. Un plan qui changerait '
    . 'd’action ou de dénombrement après validation — « À ARBITRER » devenu « CRÉER » — sans '
    . 'que le sceau s’en aperçoive ferait signer autre chose que ce qui a été lu.',
    function () use ($pdo, $importId) {
        $e = crgi_phase_validee($pdo, $importId, 5);
        exiger($e['validee'], 'la phase 5 n’est pas validée');
        exiger(!$e['perimee'], 'la phase 5 est PÉRIMÉE');
        $st = $pdo->prepare('SELECT id, action, nombre FROM crgi_plan
                              WHERE import_id = ? ORDER BY id LIMIT 1');
        $st->execute([$importId]);
        $l = $st->fetch(PDO::FETCH_ASSOC);
        exiger((bool)$l, 'le plan est vide — le sceau ne prouverait rien');
        $ref = crgi_empreinte_phase5($pdo, $importId);
        $pdo->prepare('UPDATE crgi_plan SET action = "CREER" WHERE id = ?')->execute([$l['id']]);
        $mute = crgi_empreinte_phase5($pdo, $importId);
        $pdo->prepare('UPDATE crgi_plan SET action = ? WHERE id = ?')
            ->execute([$l['action'], $l['id']]);
        $restaure = crgi_empreinte_phase5($pdo, $importId);
        exiger($mute !== $ref, 'changer une action ne change pas l’empreinte');
        exiger($restaure === $ref, 'l’empreinte ne revient pas après restauration');
    }
);

echo "\nCOHÉRENCE : " . $ok . '/' . ($ok + count($ko)) . "\n";
foreach ($ko as [$titre, $incident, $msg]) {
    echo "\n  ÉCHEC — {$titre}\n    incident défendu : {$incident}\n    {$msg}\n";
}
exit($ko ? 1 : 0);
