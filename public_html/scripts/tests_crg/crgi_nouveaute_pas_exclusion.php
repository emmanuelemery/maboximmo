<?php
/**
 * NOUVEAUTÉ ≠ EXCLUSION — LA FIXTURE NÉGATIVE.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CE FICHIER EXISTE À CAUSE DE HUIT FILTRES `doublon_statut = "UNIQUE"`. Le 03/09/2026,
 *    l'inventaire, le patrimoine, l'occupation et la finance filtraient tous sur la LISTE DES
 *    STATUTS CONNUS. Des comptes rendus COMPLÉMENTAIRES — qualifiés « montants réellement
 *    différents », donc porteurs d'information que nul autre document ne portait — étaient
 *    écartés de TOUTES les phases, sans une ligne pour le dire. Ils n'étaient pas rejetés :
 *    ils n'étaient jamais regardés.
 *
 * ⚠️ LA RÈGLE QU'EMMANUEL A POSÉE LE 04/09/2026 : « ON N'INCLUT PAS UNE LISTE FERMÉE DES
 *    STATUTS CONNUS. ON EXCLUT UNIQUEMENT CE QUI EST DÉMONTRÉ COMME NON CONTRIBUTIF. » Un
 *    filtre positif est une bombe à retardement : il fonctionne parfaitement jusqu'au jour où
 *    le moteur apprend un état de plus, et ce jour-là il fait disparaître des documents en
 *    silence. Un filtre négatif, lui, ne peut se tromper que sur ce qu'il nomme.
 *
 * ⚠️ LA PREUVE NE PEUT PAS ÊTRE UNE RELECTURE. « J'ai vérifié les filtres » ne vaut rien :
 *    c'est ce qu'on croyait déjà. On fabrique donc un document PORTANT UN STATUT QUE PERSONNE
 *    N'A JAMAIS ÉCRIT — un statut inventé pour ce test, absent du vocabulaire déclaré — et on
 *    exige qu'il traverse les quatre passages. S'il en manque un seul, le test le nomme.
 *
 * ⚠️ ET L'ORACLE SE PREND DANS LE CODE, PAS DANS UNE LISTE À CÔTÉ. Les prédicats confrontés
 *    sont EXTRAITS DE LA SOURCE : le filtre écrit demain sera éprouvé sans qu'on ait pensé à
 *    l'ajouter ici. Une liste tenue à la main aurait le même défaut que celle qu'elle défend.
 *
 * ⚠️ AUCUNE ÉCRITURE MÉTIER : tout se passe dans un import JETABLE, créé puis annulé.
 *
 * Usage : php crgi_nouveaute_pas_exclusion.php [moteur.php]
 *
 * ⚠️ LE SECOND ARGUMENT N'EST PAS UN CONFORT : il sert à PROUVER QUE CE TEST MORD. On lui
 *    passe une copie du moteur dans laquelle le filtre positif a été remis, et on exige le
 *    rouge. Sans cela, ce fichier ne serait qu'un vert de plus.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../inc/crg_integration.php';

$moteur = $argv[1] ?? dirname(__DIR__, 2) . '/inc/crg_integration.php';
$pdo = $GLOBALS['pdo'];
$ok = 0;
$ko = [];

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

function exiger(bool $cond, string $m): void
{
    if (!$cond) {
        throw new RuntimeException($m);
    }
}

// ⚠️ UN STATUT QUE LE MOTEUR N'A JAMAIS ÉCRIT, ET QUI N'EST PAS DANS LE VOCABULAIRE DÉCLARÉ.
//    C'est tout l'objet du test : ce que le code ne connaît pas, il ne doit pas l'écarter.
const STATUT_INEDIT = 'PARTAGE AVEC UN CONFRERE';

echo "NOUVEAUTÉ ≠ EXCLUSION — un document au statut inconnu traverse-t-il tout ?\n";

controle(
    'le statut de la fixture est bien INCONNU du moteur',
    'Un test qui éprouve un statut déjà déclaré n’éprouve rien : il rejouerait le cas nominal '
    . 'en croyant explorer l’inconnu.',
    function () {
        $connus = CRGI_VOCABULAIRE['crgi_crg.doublon_statut'] ?? [];
        exiger($connus !== [], 'le vocabulaire de `doublon_statut` n’est pas déclaré');
        exiger(!in_array(STATUT_INEDIT, $connus, true),
               'le statut de la fixture figure déjà dans le vocabulaire déclaré : '
               . implode(', ', $connus));
        $source = file_get_contents($GLOBALS['moteur']);
        exiger(!str_contains((string)$source, STATUT_INEDIT),
               'le moteur mentionne ce statut quelque part : il n’est donc pas inconnu');
    }
);

// ── LES PRÉDICATS, LUS DANS LA SOURCE ────────────────────────────────────────────────────
// ⚠️ ON LES EXTRAIT, ON NE LES RECOPIE PAS. Un oracle recopié vieillit à la vitesse de la
//    mémoire de celui qui l'a écrit.
/**
 * LES PRÉDICATS SUR `doublon_statut`, TELS QUE LE FICHIER LES ÉCRIT.
 *
 * ⚠️ UN PRÉDICAT SE RECONNAÎT À CE QUI LE PRÉCÈDE. `WHERE`, `AND` ou `OR` : c'est là qu'une
 *    requête restreint une population. Le même nom de colonne après un `SET` ne restreint
 *    rien — il écrit. Confondre les deux faisait accuser l'UPDATE qui POSE le statut.
 */
function predicats_du_moteur(string $chemin): array
{
    $lignes = explode("\n", (string)file_get_contents($chemin));
    $re = '~\b(?:WHERE|AND|OR)\s+(?:[a-z]+\.)?doublon_statut\s*'
        . '(<>|=|\bNOT\s+IN\b|\bIN\b|\bLIKE\b)\s*("[^"]*"|\'[^\']*\'|\([^)]*\)|\?)~i';

    // Les fonctions qui ÉCRIVENT le statut : elles en sont l'autorité, et sélectionner les
    // lignes à qualifier sur leur état courant est leur travail même. Liste DÉDUITE du code.
    $auteurs = [];
    $fn = '(hors fonction)';
    foreach ($lignes as $ligne) {
        if (preg_match('~^function\s+([a-z0-9_]+)~i', $ligne, $f)) {
            $fn = $f[1];
        }
        if (preg_match('~SET\s+[^\']*doublon_statut|doublon_statut\s*=\s*\?\s*,~i', $ligne)) {
            $auteurs[$fn] = true;
        }
    }

    $predicats = [];
    $fn = '(hors fonction)';
    foreach ($lignes as $i => $ligne) {
        if (preg_match('~^function\s+([a-z0-9_]+)~i', $ligne, $f)) {
            $fn = $f[1];
        }
        // Un commentaire CITE la règle, il ne l'applique pas.
        $nu = ltrim($ligne);
        if ($nu === '' || $nu[0] === '*' || str_starts_with($nu, '//')
            || str_starts_with($nu, '/*')) {
            continue;
        }
        // ⚠️ LA CONSTANTE EST UN PRÉDICAT, ET ELLE DOIT S'ÉPROUVER COMME TEL. Écrire
        //    `CRGI_CRG_PORTEURS` au lieu du littéral est un progrès — une seule autorité — mais
        //    cela rendrait le filtre INVISIBLE à ce contrôle, qui lit la source. On la
        //    reconnaît donc explicitement, et on confronte ce qu'elle vaut réellement.
        if (str_contains($ligne, 'CRGI_CRG_PORTEURS')
            && !str_starts_with($nu, 'const CRGI_CRG_PORTEURS')) {
            $predicats[] = [
                'ligne' => $i + 1, 'fonction' => $fn, 'auteur' => isset($auteurs[$fn]),
                'operateur' => '<>', 'valeur' => '"REENONCIATION"',
                'predicat' => CRGI_CRG_PORTEURS,
            ];
            continue;
        }
        if (!preg_match($re, $ligne, $m)) {
            continue;
        }
        $predicats[] = [
            'ligne'     => $i + 1,
            'fonction'  => $fn,
            'auteur'    => isset($auteurs[$fn]),
            'operateur' => strtoupper(preg_replace('~\s+~', ' ', trim($m[1]))),
            'valeur'    => trim($m[2]),
            'predicat'  => 'doublon_statut ' . trim($m[1]) . ' ' . trim($m[2]),
        ];
    }
    return $predicats;
}

/**
 * Ceux qui énumèrent un état connu au lieu d'exclure ce qui est démontré non contributif.
 *
 * ⚠️ CELUI QUI ÉCRIT LE STATUT PEUT LE LIRE POSITIVEMENT. `crgi_qualifier_collisions`
 *    sélectionne les collisions à qualifier sur leur état courant : c'est sa liste de
 *    travail, pas un filtre de population. La distinction ne se prend pas sur un nom de
 *    fonction, mais sur ce que la fonction FAIT à la colonne.
 */
function lectures_positives(array $predicats): array
{
    $positifs = [];
    foreach ($predicats as $p) {
        if ($p['auteur']) {
            continue;
        }
        $exclusion = $p['operateur'] === '<>' && str_contains($p['valeur'], 'REENONCIATION');
        $comptage  = $p['operateur'] === '='  && str_contains($p['valeur'], 'REENONCIATION');
        if (!$exclusion && !$comptage) {
            $positifs[] = sprintf('ligne %d (%s) : %s', $p['ligne'], $p['fonction'],
                                  mb_substr($p['predicat'], 0, 90));
        }
    }
    return $positifs;
}

$predicats = predicats_du_moteur($moteur);

controle(
    'AUCUNE LECTURE n’énumère les statuts connus',
    'Huit requêtes filtraient `doublon_statut = "UNIQUE"`. Le jour où la qualification des '
    . 'collisions a produit un second état légitime, ces huit requêtes ont fait disparaître '
    . 'des CRG entiers de l’inventaire, du patrimoine, de l’occupation et de la finance.',
    function () use ($predicats) {
        exiger($predicats !== [], 'aucun filtre sur `doublon_statut` trouvé : lecture en échec');
        $positifs = lectures_positives($predicats);
        exiger(!$positifs,
               "lecture(s) énumérant des statuts connus :\n      · "
               . implode("\n      · ", $positifs));
    }
);

controle(
    'et ce contrôle SAIT voir le filtre positif : on le lui remet',
    'Un contrôle vert qui n’a jamais été vu rouge ne prouve rien. On réécrit le moteur avec '
    . '`doublon_statut = "UNIQUE"` dans une COPIE, et on exige que les lectures fautives '
    . 'soient nommées — sinon ce fichier n’est qu’un vert de plus.',
    function () use ($moteur) {
        $fautif = (string)file_get_contents($moteur);
        $fautif = str_replace('doublon_statut <> "REENONCIATION"',
                              'doublon_statut = "UNIQUE"', $fautif);
        $copie = tempnam(sys_get_temp_dir(), 'crgineg_') . '.php';
        file_put_contents($copie, $fautif);
        try {
            $vus = lectures_positives(predicats_du_moteur($copie));
        } finally {
            @unlink($copie);
        }
        exiger(count($vus) >= 5,
               'le filtre positif réintroduit n’a été vu que ' . count($vus) . ' fois : '
               . 'le contrôle ne mord pas');
    }
);

// ── LA FIXTURE : deux documents, une seule clé, deux contenus, un statut inédit ───────────
$temoin  = crgi_import_reference($pdo);
$jetable = crgi_creer_import($pdo, 'TEST NOUVEAUTÉ ≠ EXCLUSION — jetable, à supprimer', 0);
$crgA = $crgB = 0;

try {
    $pdo->prepare(
        'INSERT INTO crgi_piece (import_id, nom_original, sha256, taille_octets, nb_pages,
                                 chemin, etat, cree_le)
              VALUES (?, ?, ?, 0, 4, ?, "ANALYSEE", NOW())'
    )->execute([$jetable, 'fixture_negative.pdf', str_repeat('f', 64), 'fixture://negative']);
    $piece = (int)$pdo->lastInsertId();

    // ⚠️ MÊME CLÉ DOCUMENTAIRE, CONTENU DIFFÉRENT. C'est exactement la situation qui a produit
    //    les CRG complémentaires : `compte × période × arrêté` identiques, et deux jeux de
    //    montants qui ne se recouvrent pas.
    $ins = $pdo->prepare(
        'INSERT INTO crgi_crg (import_id, piece_id, page_debut, page_fin, agence, format,
                               periode_cle, periode_debut, periode_fin, date_arrete,
                               proprietaire, compte, certitude, doublon_statut, caracteres_lus,
                               cree_le)
              VALUES (?, ?, ?, ?, "FIXTURE", "ICS", "2026T1", "2026-01-01", "2026-03-31",
                      "2026-03-31", "FIXTURE NEGATIVE", "TESTNEG1", "CERTAIN", ?, 4000, NOW())'
    );
    $ins->execute([$jetable, $piece, 1, 2, 'UNIQUE']);
    $crgA = (int)$pdo->lastInsertId();
    $ins->execute([$jetable, $piece, 3, 4, STATUT_INEDIT]);
    $crgB = (int)$pdo->lastInsertId();

    controle(
        'la fixture pose bien DEUX documents sous UNE seule clé documentaire',
        'Une fixture qui ne collisionne pas ne prouve rien : elle éprouve un document seul.',
        function () use ($pdo, $jetable, $crgA, $crgB) {
            exiger($crgA > 0 && $crgB > 0, 'les deux CRG n’ont pas été créés');
            $st = $pdo->prepare('SELECT COUNT(*) FROM (SELECT compte, periode_cle, date_arrete
                                   FROM crgi_crg WHERE import_id = ?
                                  GROUP BY compte, periode_cle, date_arrete
                                 HAVING COUNT(*) > 1) t');
            $st->execute([$jetable]);
            exiger((int)$st->fetchColumn() === 1, 'les deux CRG ne partagent pas la même clé');
        }
    );

    controle(
        'le document au statut INÉDIT passe TOUS les filtres de la source',
        'Chaque prédicat trouvé dans le code est confronté à la fixture. Un filtre qui '
        . 'l’écarte est nommé avec sa ligne et sa fonction — on ne dit pas « un filtre », on '
        . 'dit lequel.',
        function () use ($pdo, $predicats, $jetable, $crgB) {
            $ecartent = [];
            $eprouves = 0;
            foreach ($predicats as $p) {
                // On éprouve les filtres de POPULATION : ceux qui décident qui traverse.
                if ($p['auteur'] || $p['operateur'] !== '<>') {
                    continue;
                }
                $eprouves++;
                $st = $pdo->prepare('SELECT COUNT(*) FROM crgi_crg
                                      WHERE import_id = ? AND id = ? AND ' . $p['predicat']);
                $st->execute([$jetable, $crgB]);
                if ((int)$st->fetchColumn() !== 1) {
                    $ecartent[] = sprintf('ligne %d (%s) : %s', $p['ligne'], $p['fonction'],
                                          $p['predicat']);
                }
            }
            exiger($eprouves >= 5,
                   'seulement ' . $eprouves . ' filtre(s) de population éprouvé(s) : '
                   . 'l’extraction ne voit plus le code qu’elle prétend confronter');
            exiger(!$ecartent,
                   "le document inédit est écarté par :\n      · "
                   . implode("\n      · ", $ecartent));
        }
    );

    // ── LES QUATRE PASSAGES ──────────────────────────────────────────────────────────────
    // ⚠️ ON SÈME CE QUE CHAQUE PASSAGE PRODUIT, ET ON EXIGE DE LE RETROUVER. Les phases ont
    //    besoin d'un PDF ; leurs LECTEURS, non. C'est sur eux que porte la démonstration :
    //    un objet rattaché au document inédit doit rester visible de bout en bout.
    $pdo->prepare('INSERT INTO crgi_immeuble (import_id, crg_id, page, code, nom, code_postal,
                                              ville, statut, motif)
                        VALUES (?, ?, 3, "FIXNEG", "IMMEUBLE FIXTURE", "69001", "LYON",
                                "NOUVEAU", "fixture négative")')
         ->execute([$jetable, $crgB]);
    $pdo->prepare('INSERT INTO crgi_lot (import_id, crg_id, page, reference, libelle, locataire,
                                         statut, motif)
                        VALUES (?, ?, 3, "NEG01", "LOT FIXTURE", "LOCATAIRE FIXTURE",
                                "NOUVEAU", "fixture négative")')
         ->execute([$jetable, $crgB]);
    $pdo->prepare('INSERT INTO crgi_occupation (import_id, crg_id, page, lot_reference,
                                                locataire, date_arrete, statut, statut_motif)
                        VALUES (?, ?, 3, "NEG01", "LOCATAIRE FIXTURE", "2026-03-31",
                                "IDENTIQUE", "fixture négative")')
         ->execute([$jetable, $crgB]);
    $pdo->prepare('INSERT INTO crgi_mouvement (import_id, crg_id, page, section, libelle,
                                               colonne, montant, maille, lot_reference,
                                               categorie, motif)
                        VALUES (?, ?, 3, "SECTION FIXTURE", "LIGNE FIXTURE", "loyers",
                                123.45, "LOT", "NEG01", "LOYER APPELE", "fixture négative")')
         ->execute([$jetable, $crgB]);

    foreach ([
        ['inventaire',  'crgi_crg',        'c.id = ' . $crgB],
        ['patrimoine',  'crgi_immeuble',   'x.crg_id = ' . $crgB],
        ['patrimoine',  'crgi_lot',        'x.crg_id = ' . $crgB],
        ['occupation',  'crgi_occupation', 'x.crg_id = ' . $crgB],
        ['finance',     'crgi_mouvement',  'x.crg_id = ' . $crgB],
    ] as [$passage, $table, $ou]) {
        controle(
            sprintf('passage « %s » : %s voit l’objet du document inédit', $passage, $table),
            'Le CRG complémentaire portait des immeubles, des lots, des occupations et des '
            . 'montants qu’aucun autre document ne portait. Filtré en amont, il ne manquait '
            . 'nulle part : il n’avait jamais existé.',
            function () use ($pdo, $table, $ou, $jetable) {
                $sql = $table === 'crgi_crg'
                    ? 'SELECT COUNT(*) FROM crgi_crg c
                        WHERE c.import_id = ? AND ' . CRGI_CRG_PORTEURS . ' AND ' . $ou
                    : 'SELECT COUNT(*) FROM `' . $table . '` x
                         JOIN crgi_crg c ON c.id = x.crg_id
                        WHERE x.import_id = ? AND ' . CRGI_CRG_PORTEURS . ' AND ' . $ou;
                $st = $pdo->prepare($sql);
                $st->execute([$jetable]);
                exiger((int)$st->fetchColumn() === 1,
                       'l’objet est invisible au passage « ' . $table . ' »');
            }
        );
    }

    controle(
        'et la RÉÉNONCIATION, elle, reste bel et bien écartée',
        'Un test qui n’exclut plus rien ne prouve pas la tolérance : il prouve l’absence de '
        . 'règle. La seule exclusion démontrée doit continuer de mordre.',
        function () use ($pdo, $jetable, $crgB) {
            $pdo->prepare('UPDATE crgi_crg SET doublon_statut = "REENONCIATION" WHERE id = ?')
                ->execute([$crgB]);
            $st = $pdo->prepare('SELECT COUNT(*) FROM crgi_crg
                                  WHERE import_id = ? AND ' . CRGI_CRG_PORTEURS . ' AND id = ?');
            $st->execute([$jetable, $crgB]);
            $exclu = (int)$st->fetchColumn() === 0;
            $pdo->prepare('UPDATE crgi_crg SET doublon_statut = ? WHERE id = ?')
                ->execute([STATUT_INEDIT, $crgB]);
            exiger($exclu, 'une réénonciation traverse le filtre : plus rien n’est exclu');
        }
    );

    controle(
        'le vocabulaire déclaré SIGNALE le statut inédit, sans l’écarter',
        'Tolérer n’est pas ignorer. Un état que personne n’a déclaré doit faire rougir le '
        . 'contrôle de cohérence — c’est ainsi qu’on l’apprend — tout en restant intégré.',
        function () use ($pdo, $jetable) {
            $inconnues = crgi_valeurs_non_declarees($pdo, $jetable);
            $vu = false;
            foreach ($inconnues as $u) {
                if (($u['valeur'] ?? '') === STATUT_INEDIT) {
                    $vu = true;
                }
            }
            exiger($vu, 'le statut inédit passe inaperçu du contrôle des valeurs déclarées : '
                        . 'il serait intégré sans que personne ne l’apprenne jamais');
        }
    );
} finally {
    crgi_annuler($pdo, $jetable, 0, 'fixture négative — nettoyage automatique');
    $st = $pdo->prepare('SELECT COUNT(*) FROM crgi_crg WHERE import_id = ?');
    $st->execute([$jetable]);
    if ((int)$st->fetchColumn() !== 0) {
        echo "  ⚠️  l'import jetable {$jetable} n'a pas été entièrement annulé\n";
    }
    $pdo->prepare('DELETE FROM crgi_import WHERE id = ?')->execute([$jetable]);
}

echo "\nNOUVEAUTÉ ≠ EXCLUSION : {$ok}/" . ($ok + count($ko)) . "\n";
foreach ($ko as [$titre, $incident, $msg]) {
    echo "\n  ÉCHEC — {$titre}\n    incident défendu : {$incident}\n    {$msg}\n";
}
echo "\n  (témoin : l’import de référence reste " . ($temoin ?: 'aucun') . ")\n";
exit($ko ? 1 : 0);
