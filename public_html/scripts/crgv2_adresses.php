<?php
declare(strict_types=1);
/**
 * crgv2_adresses.php — L'ADRESSE DU PROPRIÉTAIRE, QUI ÉTAIT IMPRIMÉE ET QUE JE N'AI PAS LUE.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ J'AI DIT « LE CRG NE DONNE PAS L'ADRESSE DES PROPRIÉTAIRES ». C'ÉTAIT FAUX, et c'est la
 *    faute la plus coûteuse de cet import : la table `tiers` est entrée avec 0 adresse sur
 *    1 782, alors que le bloc d'adressage est en haut de la PREMIÈRE PAGE de chaque compte
 *    rendu — c'est un document qu'on met sous enveloppe.
 *
 *        COMPTE RENDU DE GESTION            Monsieur XERRI Florent
 *                                           37 Chemin DE MORAND
 *        Agence: A3 - REGIE EMERY - VIENNE  38670 CHASSE-SUR-RHONE
 *                                           FRANCE
 *
 *    `UNE LACUNE DE SORTIE N'EST PAS UNE LACUNE DE SOURCE.` Le staging ne portait pas
 *    l'adresse parce que le lecteur ne l'avait jamais captée — pas parce qu'elle manquait.
 *    Dire « le document ne le donne pas » est une affirmation SUR LE DOCUMENT : elle exige
 *    d'avoir ouvert la page.
 *
 * ⚠️ ET L'ADRESSE DE L'AGENCE N'EST PAS CELLE DU PROPRIÉTAIRE. Sur le format `emery_immo`,
 *    le seul bloc d'adressage de la page est celui de l'AGENCE — « EMERY IMMOBILIER, 12
 *    PLACE SAINT-JEAN, 63200 RIOM ». Prendre « le bloc en haut à droite » y aurait donné à
 *    235 propriétaires l'adresse de leur régie. On part donc du NOM DU PROPRIÉTAIRE, déjà
 *    reconnu par les phases, et on lit ce qui le suit DANS SA COLONNE.
 *
 * Usage :  php crgv2_adresses.php            → C:/tmp/crgv2/adresses.json
 */

require_once __DIR__ . '/crgv2_norm.php';

const SORTIE  = 'C:/tmp/crgv2';
const CACHE   = 'C:/tmp/crgv2/txt';

/** Les lignes qui ne sont pas une adresse et qui ferment le bloc. */
const ARRETS = [
    'MADAME', 'MONSIEUR', 'MESSIEURS', 'NOUS VOUS PRIONS', 'COMPTE RENDU', 'COMPTE PERSONNEL',
    'AGENCE', 'PERIODE', 'IDENTIFIANT', 'MOT DE PASSE', 'TEL', 'FAX', 'WWW', 'CAPITAL',
    'CARTE PROFESSIONNELLE', 'GARANTIE', 'SIRET', 'RCS', 'CODE APE', 'TVA', 'IMMEUBLE',
    'SITUATION', 'RECAPITULATIF', 'DETAIL', 'SOLDE', 'LOCATAIRE', 'DEBIT', 'CREDIT',
    'RELEVE', 'TRIMESTRE', 'BONJOUR', 'OBJET',
];

/** Les raisons sociales de nos agences : les voir, c'est avoir attrapé le mauvais bloc. */
const NOS_AGENCES = ['EMERY', 'LOCA IMMO', 'DE GASPERIS', 'FNAIM', 'GALIAN'];

function texteDeLaPiece(string $chemin, int $pieceId): array
{
    @mkdir(CACHE, 0775, true);
    $cache = CACHE . '/p' . $pieceId . '.txt';
    if (!is_file($cache)) {
        if (!is_file($chemin)) return [];
        // ⚠️ `-layout` EST INDISPENSABLE : c'est la position en colonne qui distingue le bloc
        //    du propriétaire de tout ce qui s'imprime à sa gauche sur la même ligne.
        $cmd = 'pdftotext -layout -enc UTF-8 ' . escapeshellarg($chemin) . ' ' . escapeshellarg($cache);
        exec($cmd . ' 2>&1', $o, $rc);
        if ($rc !== 0 || !is_file($cache)) return [];
    }
    // Le saut de page sépare les pages : c'est ce qui permet d'aller à la page du CRG.
    return explode("\f", (string)file_get_contents($cache));
}

/**
 * Le morceau de texte d'une ligne qui tombe dans une colonne donnée, ou null.
 *
 * Une sortie `-layout` sépare les colonnes par des suites d'espaces : trois et plus font
 * une frontière. Découper là rend chaque colonne indépendamment lisible, avec sa position.
 */
function colonneSous(string $ligne, int $colonne, int $tolerance = 8): ?string
{
    $parts = preg_split('/\s{3,}/u', $ligne, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_OFFSET_CAPTURE);
    if (!$parts) return null;
    foreach ($parts as $p) {
        $debut = mb_strlen(substr($ligne, 0, $p[1]));
        if (abs($debut - $colonne) <= $tolerance) return trim($p[0]);
    }
    return null;
}

/**
 * Le bloc d'adressage qui suit un nom, dans SA colonne.
 *
 * ⚠️ LA COLONNE EST LE CRITÈRE, PAS LA PROXIMITÉ. Sur le format septeo, les lignes
 *    « Agence: … » et « Période du … » s'intercalent À GAUCHE entre deux lignes de
 *    l'adresse : les prendre parce qu'elles suivent donnerait « 37 Chemin DE MORAND,
 *    Agence A3, 38670 CHASSE-SUR-RHONE ».
 */
function blocApres(string $page, string $nom): array
{
    $lignes = explode("\n", $page);
    $cible  = plat($nom);
    if ($cible === '') return [];

    // Le mot le plus distinctif du nom : c'est lui qui donne la colonne. Prendre le PREMIER
    // mot ferait chercher « M. » ou « Monsieur », qui s'impriment aussi ailleurs sur la page.
    $ancre = '';
    foreach (explode(' ', trim($nom)) as $mot) {
        $mot = trim($mot, ".,-'");
        if (mb_strlen($mot) >= 4 && !in_array(plat($mot), ['MONSIEUR','MADAME','MADEMOISELLE'], true)) {
            $ancre = $mot;
            break;
        }
    }
    if ($ancre === '') $ancre = trim(explode(' ', trim($nom))[0]);

    foreach ($lignes as $i => $l) {
        if (mb_strpos(plat($l), $cible) === false) continue;
        // ⚠️ LA COLONNE CHERCHÉE EST CELLE DU DÉBUT DU NOM, PAS CELLE DE L'ANCRE. L'ancre sert
        //    à trouver la bonne occurrence sur la ligne ; il faut ensuite RECULER de sa
        //    position dans le nom. Sans ce recul, « XERRI » (colonne 76) était comparé à
        //    « 37 Chemin DE MORAND » (colonne 67) : 9 caractères d'écart, tout rejeté.
        $posAncre = mb_strpos($l, $ancre);
        if ($posAncre !== false) {
            $decalage = mb_strpos($nom, $ancre);
            $colNom   = max(0, $posAncre - ($decalage === false ? 0 : $decalage));
        } else {
            if (!preg_match('/\S/u', $l, $mm, PREG_OFFSET_CAPTURE)) continue;
            $colNom = mb_strlen(substr($l, 0, $mm[0][1]));
        }

        $bloc = [];
        $vides = 0;
        // ⚠️ UNE LIGNE D'UNE AUTRE COLONNE NE FERME PAS LE BLOC, ELLE SE SAUTE. Sur le format
        //    septeo, « Agence: … » et « Période du … » s'impriment À GAUCHE ENTRE deux lignes
        //    de l'adresse. En m'arrêtant à la première, je ne retenais que la rue : plus de
        //    code postal, donc plus d'adresse du tout — **511 CRG sur 512 perdus**, et je les
        //    avais comptés « bloc illisible » comme si le document était en cause.
        for ($j = $i + 1; $j < count($lignes) && count($bloc) < 4; $j++) {
            $ligne = rtrim($lignes[$j]);
            if (trim($ligne) === '') {
                // Le bloc d'adressage est compact : trois lignes vides d'affilée après avoir
                // commencé, c'est qu'il est fini.
                if ($bloc && ++$vides >= 3) break;
                continue;
            }
            // ⚠️ UNE LIGNE PORTE PLUSIEURS COLONNES, ET C'EST TOUT LE PROBLÈME. Sur le format
            //    septeo, le code postal du propriétaire s'imprime SUR LA MÊME LIGNE que
            //    « Agence: A3 - REGIE EMERY - VIENNE ». En jugeant la ligne sur son premier
            //    mot, je la classais « colonne de gauche » et la jetais — avec l'adresse
            //    dedans. **511 CRG sur 512** : 0 % de couverture annoncée comme si le
            //    document était muet. On découpe donc la ligne en colonnes, et on prend
            //    celle qui tombe sous le nom.
            $t = colonneSous($ligne, $colNom);
            if ($t === null) continue;

            $vides = 0;
            $p = plat($t);
            foreach (ARRETS as $a) if (str_starts_with($p, $a)) break 2;
            foreach (NOS_AGENCES as $a) if (str_contains($p, $a)) break 2;
            $bloc[] = $t;
        }
        if ($bloc) return $bloc;
    }
    return [];
}

/**
 * Un bloc de lignes → une adresse postale, ou rien.
 *
 * ⚠️ RIEN, ET PAS « CE QU'ON A ». `chk_tiers_adresse` de la V2 est tout-ou-rien : voie, code
 *    postal, commune et pays ensemble, ou les quatre à NULL. Une adresse à moitié remplie
 *    n'est pas une demi-information, c'est une adresse fausse — on ne poste pas dessus.
 */
function adresseDuBloc(array $bloc): ?array
{
    $iCp = null;
    foreach ($bloc as $i => $l) {
        if (preg_match('/^(\d{5})\s+(\S.*)$/u', trim($l), $m)) { $iCp = $i; break; }
    }
    if ($iCp === null || $iCp === 0) return null;   // sans ligne « CP VILLE », on ne conclut pas

    preg_match('/^(\d{5})\s+(\S.*)$/u', trim($bloc[$iCp]), $m);
    $voie = trim(implode(' ', array_slice($bloc, 0, $iCp)));
    if ($voie === '') return null;

    $apres = array_slice($bloc, $iCp + 1);
    $pays  = 'FRANCE';
    foreach ($apres as $l) {
        $p = plat($l);
        // Un pays est une ligne courte, sans chiffre — sinon c'est un complément de voie.
        if ($p !== '' && !preg_match('/\d/', $p) && mb_strlen($p) <= 30) { $pays = trim($l); break; }
    }

    return ['adr_ligne_voie'  => mb_substr($voie, 0, 255),
            'adr_code_postal' => $m[1],
            'adr_commune'     => mb_substr(trim($m[2]), 0, 150),
            'adr_pays'        => mb_substr($pays, 0, 100)];
}

// ═══════════════════════════════════════════════════════════════════════════════════════════

$mdp = trim((string)file_get_contents('C:/Users/emery/.mbi/mbi_agent.pass'));
$pdo = new PDO('mysql:host=127.0.0.1;dbname=mbi_bis;charset=utf8mb4', 'mbi_agent', $mdp,
               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$pieces = [];
foreach ($pdo->query('SELECT id, chemin FROM crgi_piece') as $r) $pieces[(int)$r['id']] = $r['chemin'];

$crgs = $pdo->query("SELECT c.id, c.import_id, c.piece_id, c.page_debut, c.proprietaire, c.format
                       FROM crgi_crg c WHERE c.proprietaire <> '' ORDER BY c.piece_id, c.page_debut")
            ->fetchAll(PDO::FETCH_ASSOC);

$trouvees = [];
$stat = [];
$pageActuelle = ['piece' => 0, 'pages' => []];

foreach ($crgs as $c) {
    $pid = (int)$c['piece_id'];
    if ($pageActuelle['piece'] !== $pid) {
        $pageActuelle = ['piece' => $pid, 'pages' => texteDeLaPiece((string)($pieces[$pid] ?? ''), $pid)];
    }
    $f = $c['format'];
    $stat[$f]['crg'] = ($stat[$f]['crg'] ?? 0) + 1;

    $page = $pageActuelle['pages'][(int)$c['page_debut'] - 1] ?? '';
    if ($page === '') { $stat[$f]['page_absente'] = ($stat[$f]['page_absente'] ?? 0) + 1; continue; }

    $bloc = blocApres($page, (string)$c['proprietaire']);
    if (!$bloc) { $stat[$f]['bloc_absent'] = ($stat[$f]['bloc_absent'] ?? 0) + 1; continue; }

    $adr = adresseDuBloc($bloc);
    if (!$adr) { $stat[$f]['bloc_illisible'] = ($stat[$f]['bloc_illisible'] ?? 0) + 1; continue; }

    $stat[$f]['lue'] = ($stat[$f]['lue'] ?? 0) + 1;
    $trouvees[(int)$c['id']] = $adr + ['proprietaire' => $c['proprietaire']];
}

@mkdir(SORTIE, 0775, true);
file_put_contents(SORTIE . '/adresses.json',
                  json_encode($trouvees, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo "adresses de propriétaire lues sur la 1re page de chaque CRG\n\n";
foreach ($stat as $f => $s) {
    printf("  %-14s %4d CRG → %4d lues (%d%%)  %s\n", $f, $s['crg'], $s['lue'] ?? 0,
           $s['crg'] ? round(100 * ($s['lue'] ?? 0) / $s['crg']) : 0,
           json_encode(array_diff_key($s, ['crg' => 1, 'lue' => 1])));
}
echo "\n  TOTAL : " . count($trouvees) . " adresses sur " . count($crgs) . " CRG\n";
foreach (array_slice($trouvees, 0, 6, true) as $id => $a) {
    printf("   %-32s | %-34s %s %s (%s)\n", mb_substr($a['proprietaire'], 0, 32),
           mb_substr($a['adr_ligne_voie'], 0, 34), $a['adr_code_postal'], $a['adr_commune'], $a['adr_pays']);
}
