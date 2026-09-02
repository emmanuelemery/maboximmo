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

$pdo = $GLOBALS['pdo'];
// ⚠️ PAS D'IMPORT ÉCRIT EN DUR. « ?? 5 » a survécu à l'annulation de l'import 5 : la suite
//    continuait à tourner, sur un staging vide, et rendait du vert sans rien contrôler.
$importId = (int)($argv[1] ?? crgi_import_courant($pdo));
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
        // aucune identité `compte × lot` ne doit porter deux occupants au même arrêté
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM (
                SELECT c.compte, o.lot_reference, o.date_arrete
                  FROM crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
                 WHERE o.import_id = ? AND o.locataire IS NOT NULL
                 GROUP BY c.compte, o.lot_reference, o.date_arrete, o.rang
                HAVING COUNT(DISTINCT o.locataire) > 1) t'
        );
        $st->execute([$importId]);
        exiger((int)$st->fetchColumn() === 0,
               'une identité compte × lot porte deux occupants au même arrêté et au même rang');
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
        $st = $pdo->prepare('SELECT COUNT(*) FROM crgi_mouvement
                              WHERE import_id = ? AND reimpression = 1');
        $st->execute([$importId]);
        exiger((int)$st->fetchColumn() > 0,
               'aucune réimpression détectée — la règle ne serait plus éprouvée');
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
