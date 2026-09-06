<?php
declare(strict_types=1);
/**
 * LE PILOTAGE D'UNE INTÉGRATION — CE QU'EMMANUEL DOIT SAVOIR EN CINQ SECONDES.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CE FICHIER EXISTE PARCE QUE L'AVANCEMENT ÉTAIT LISIBLE, MAIS PAS VISIBLE. La frise des
 *    phases disait où on en était ; il fallait ensuite ouvrir le diagnostic pour savoir si
 *    ça se passait bien, et la file d'arbitrage pour savoir s'il fallait intervenir. Trois
 *    écrans pour répondre à une seule question : « dois-je m'en occuper ? ».
 *
 * ⚠️ LE CHIFFRE QUI COMPTE N'EST PAS LE NOMBRE DE TESTS VERTS. C'est la PERTE SILENCIEUSE :
 *    un objet que le moteur a vu, qu'il n'a pas su traiter, et sur lequel il ne pose AUCUNE
 *    question. Un objet en attente qui ouvre une question est un travail en cours ; le même
 *    objet sans question est une donnée perdue que personne ne cherchera jamais. Objectif : 0.
 *
 * ⚠️ IL NE CALCULE QUE CE QU'IL PEUT COMPTER. Aucun pourcentage d'ambiance : chaque taux ici
 *    est un quotient de deux populations réelles, et son dénominateur est affiché à l'écran.
 *    `INDÉTERMINABLE ≠ ERREUR` — un montant sans nature n'est pas une faute du moteur, mais
 *    il ne doit jamais être compté comme « compris ».
 *
 * ⚠️ LECTURE SEULE, TOTALE. Pas un INSERT, pas un UPDATE.
 */

require_once __DIR__ . '/crg_integration.php';
require_once __DIR__ . '/crgi_arbitrage.php';

/**
 * LES NEUF ÉTAPES DU PARCOURS, TELLES QU'EMMANUEL LES VIT.
 *
 * ⚠️ CE N'EST PAS LA LISTE DES PHASES TECHNIQUES. Les six phases `CRGI_PHASES` sont le
 *    découpage du moteur, avec ses sceaux et ses validations ; ce parcours-ci est ce qu'un
 *    humain reconnaît — des documents, des identités, du patrimoine, de l'argent. Les deux
 *    doivent rester distincts : confondre l'organisation interne et le récit rend l'écran
 *    illisible pour qui n'a pas écrit le moteur.
 */
const CRGI_PARCOURS = [
    ['DOCUMENTS',   0, 'Les pièces déposées, leurs pages, leur empreinte.'],
    ['LECTURE',     0, 'Chaque page lue, chaque CRG délimité.'],
    ['IDENTITÉS',   1, 'Comptes mandants, propriétaires, agences.'],
    ['PATRIMOINE',  2, 'Immeubles et lots, rattachés à MBI ou distingués.'],
    ['OCCUPATIONS', 3, 'Locataires et périodes, telles que le document les démontre.'],
    ['FINANCES',    4, 'Appels, encaissements, dépenses, soldes.'],
    ['CONTRÔLES',   5, 'Ce qui doit retomber juste, et qui retombe juste.'],
    ['ARBITRAGES',  5, 'Ce que le moteur ne peut pas trancher seul.'],
    ['PRÊT',        5, 'Plus rien ne bloque l’écriture dans MBI.'],
];

/**
 * L'ÉTAT COMPLET D'UN IMPORT, EN UNE SEULE LECTURE.
 */
function crgi_pilotage(PDO $pdo, int $importId): array
{
    $un = function (string $sql, array $p = []) use ($pdo, $importId) {
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$importId], $p));
        return $st->fetchColumn();
    };
    $tous = function (string $sql) use ($pdo, $importId) {
        $st = $pdo->prepare($sql);
        $st->execute([$importId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };

    $st = $pdo->prepare('SELECT * FROM crgi_import WHERE id = ?');
    $st->execute([$importId]);
    $import = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $phases = [];
    foreach ($tous('SELECT phase, statut, message, analyse_le, valide_le
                      FROM crgi_phase WHERE import_id = ?') as $p) {
        $phases[(int)$p['phase']] = $p;
    }

    // ── LE VOLUME, ET CE QUI EN A ÉTÉ EXAMINÉ ────────────────────────────────────────────
    $pages   = (int)$un('SELECT COUNT(*) FROM crgi_page WHERE import_id = ?');
    $pagesOk = (int)$un('SELECT COUNT(*) FROM crgi_page WHERE import_id = ? AND crg_id IS NOT NULL');
    $pagesHors = (int)$un('SELECT COUNT(*) FROM crgi_page
                            WHERE import_id = ? AND crg_id IS NULL AND signal_page IS NOT NULL');
    $crg     = (int)$un('SELECT COUNT(*) FROM crgi_crg WHERE import_id = ?');
    $mvt     = (int)$un('SELECT COUNT(*) FROM crgi_mouvement WHERE import_id = ?');
    $mvtMuet = (int)$un('SELECT COUNT(*) FROM crgi_mouvement
                          WHERE import_id = ? AND (categorie IS NULL
                                                   OR categorie = "INDETERMINABLE")');

    // ── LES OBJETS, ET CEUX QUI ATTENDENT UNE DÉCISION ───────────────────────────────────
    $familles = [];
    foreach ([
        ['propriétaires', 'SELECT COUNT(DISTINCT proprietaire) FROM crgi_crg
                            WHERE import_id = ? AND proprietaire IS NOT NULL', null],
        ['comptes mandants', 'SELECT COUNT(DISTINCT compte) FROM crgi_crg
                               WHERE import_id = ? AND compte IS NOT NULL', null],
        ['immeubles', 'SELECT COUNT(*) FROM crgi_immeuble WHERE import_id = ?', 'crgi_immeuble'],
        ['lots',      'SELECT COUNT(*) FROM crgi_lot WHERE import_id = ?',      'crgi_lot'],
        ['occupations', 'SELECT COUNT(*) FROM crgi_occupation WHERE import_id = ?',
                        'crgi_occupation'],
        ['mouvements', 'SELECT COUNT(*) FROM crgi_mouvement WHERE import_id = ?', null],
    ] as [$nom, $sql, $table]) {
        $n = (int)$un($sql);
        $attente = $table
            ? (int)$un('SELECT COUNT(*) FROM `' . $table . '`
                         WHERE import_id = ? AND statut = "A ARBITRER"')
            : ($nom === 'mouvements' ? $mvtMuet : 0);
        $familles[] = ['famille' => $nom, 'detectes' => $n, 'attente' => $attente,
                       'auto' => $n - $attente,
                       'taux' => $n ? round(100 * ($n - $attente) / $n, 1) : 100.0];
    }
    // La population totale du dépôt, toutes familles confondues — la seule assiette qui ne
    // laisse aucune famille hors du compte.
    $objets    = array_sum(array_column($familles, 'detectes'));
    $enAttente = array_sum(array_column($familles, 'attente'));

    // ── LA FILE, ET SA PRIORITÉ ──────────────────────────────────────────────────────────
    // ⚠️ « BLOQUANT » SE LIT DANS L'IMPACT DÉCLARÉ, PAS DANS UNE LISTE À PART. Chaque famille
    //    d'arbitrage dit déjà ce qu'elle empêche ; une seconde liste des priorités aurait
    //    divergé de la première au premier ajout.
    $file = crgi_file_arbitrages($pdo, $importId);
    $priorites = ['BLOQUANT' => 0, 'IMPORTANT' => 0, 'NON BLOQUANT' => 0];
    // ⚠️ TROIS CAUSES, TROIS COMPTEURS, JAMAIS UNE SOMME. Emmanuel, 06/09/2026 : « ne jamais
    //    présenter ARBITRAGES = X + Y + Z, ce serait mélanger trois causes différentes ».
    //    Une question née d'un doublon de MBI ne mesure pas la capacité du lecteur à
    //    comprendre un document — et l'additionner aux vraies ambiguïtés documentaires
    //    faisait exactement cela.
    $causes = ['DOCUMENT' => 0, 'MBI' => 0];
    $bloqueesParMbi = 0;
    $parGroupe = [];
    foreach ($file as $a) {
        if (($a['statut'] ?? 'A TRAITER') !== 'A TRAITER') {
            continue;
        }
        $p = crgi_priorite_arbitrage($a);
        $priorites[$p]++;
        $cause = (string)($a['cause'] ?? 'DOCUMENT');
        $causes[$cause] = ($causes[$cause] ?? 0) + 1;
        // Ce que la qualité de MBI a réellement EMPÊCHÉ : une association bloquée, pas une
        // question de plus.
        if ($cause === 'MBI' && $p === 'BLOQUANT') {
            $bloqueesParMbi++;
        }
        $g = $a['groupe'];
        $parGroupe[$g] = ($parGroupe[$g] ?? ['n' => 0, 'famille' => $a['famille'],
                                             'priorite' => $p]);
        $parGroupe[$g]['n']++;
    }
    arsort($parGroupe);

    // ── LES PERTES SILENCIEUSES ──────────────────────────────────────────────────────────
    // ⚠️ LA DÉFINITION EST STRICTE, PARCE QU'UN KPI FLOU NE SE CORRIGE JAMAIS. Une perte
    //    silencieuse est un objet DÉTECTÉ, NON TRAITÉ, et qui n'ouvre AUCUNE QUESTION. Ni un
    //    objet en attente qui figure dans la file (c'est du travail en cours), ni une page
    //    explicitement écartée (c'est une exclusion documentée) : ceux-là sont visibles.
    $cibles = [];
    foreach ($file as $a) {
        $cibles[$a['cible'] . ':' . (int)$a['cible_id']] = true;
        // ⚠️ UNE QUESTION EN COUVRE PARFOIS PLUSIEURS. Ne compter que la cible principale
        //    faisait passer pour perdues les autres lignes du même groupe : 89 « pertes
        //    silencieuses » sur un dépôt où tout était posé, en 37 questions.
        foreach (explode(',', (string)($a['ligne']['couvre'] ?? '')) as $id) {
            if ($id !== '') {
                $cibles[$a['cible'] . ':' . (int)$id] = true;
            }
        }
        // ⚠️ ET LE REPLI DE LA FILE COUVRE AUSSI. Quand une question a été repliée sur son
        //    phénomène, elle porte `couvre_ids` : les oublier faisait réapparaître 331 pertes
        //    silencieuses le jour même où le repli a divisé la file par six. Un indicateur doit
        //    suivre la présentation qu'on lui donne, sinon il mesure l'écran, pas le moteur.
        foreach ($a['couvre_ids'] ?? [] as $id) {
            $cibles[$a['cible'] . ':' . (int)$id] = true;
        }
    }
    $pertes = [];
    foreach ([['IMMEUBLE', 'crgi_immeuble'], ['OCCUPATION', 'crgi_occupation'],
              ['LOT', 'crgi_lot']] as [$type, $table]) {
        // ⚠️ « A ARBITRER » N'EST PAS LE SEUL ÉTAT SANS VERDICT. Les colonnes `statut` portent
        //    la valeur par défaut `INDETERMINE` tant qu'aucune phase ne les a tranchées : un
        //    objet resté dessus n'a NI verdict NI question — la définition même d'une perte
        //    silencieuse — et il échappait au compteur, qui ne regardait que « A ARBITRER ».
        //    L'assiette se prend donc sur le VOCABULAIRE DÉCLARÉ : est en attente tout ce qui
        //    demande une décision, plus tout ce que personne n'a déclaré.
        $decides = array_values(array_diff(
            CRGI_VOCABULAIRE[$table . '.statut'] ?? [], ['A ARBITRER']
        ));
        $muets = 0;
        $sql = 'SELECT id FROM `' . $table . '` WHERE import_id = ?';
        if ($decides) {
            $sql .= ' AND (statut IS NULL OR statut NOT IN ("'
                  . implode('","', array_map(fn($v) => str_replace('"', '', $v), $decides)) . '"))';
        }
        foreach ($tous($sql) as $r) {
            if (empty($cibles[$type . ':' . (int)$r['id']])) {
                $muets++;
            }
        }
        if ($muets) {
            $pertes[] = ['quoi' => $table . ' sans verdict et sans question', 'n' => $muets];
        }
    }
    $mvtMuetsHorsFile = 0;
    foreach ($tous('SELECT id FROM crgi_mouvement
                     WHERE import_id = ? AND (categorie IS NULL
                                              OR categorie = "INDETERMINABLE")') as $r) {
        if (empty($cibles['MOUVEMENT:' . (int)$r['id']])) {
            $mvtMuetsHorsFile++;
        }
    }
    if ($mvtMuetsHorsFile) {
        $pertes[] = ['quoi' => 'montants sans nature et sans question', 'n' => $mvtMuetsHorsFile];
    }
    $conflitsMuets = 0;
    foreach (crgi_conflits_identite($pdo, $importId) as $c) {
        if (empty($cibles[$c['type'] . ':' . $c['cible_id']])) {
            $conflitsMuets++;
        }
    }
    if ($conflitsMuets) {
        $pertes[] = ['quoi' => 'conflits d’identité sans question', 'n' => $conflitsMuets];
    }
    $pagesOrphelines = $pages - $pagesOk - $pagesHors;
    if ($pagesOrphelines > 0) {
        $pertes[] = ['quoi' => 'pages ni rattachées ni écartées', 'n' => $pagesOrphelines];
    }

    // ── LE TEMPS ─────────────────────────────────────────────────────────────────────────
    // ⚠️ DEUX DURÉES, ET ON NE LES CONFOND PLUS. Le « temps machine » est la somme des durées
    //    d'analyse RÉELLEMENT mesurées ; l'« atelier » est l'horloge murale, du dépôt à la
    //    dernière validation — il contient les pauses, les relectures et les nuits. Les
    //    présenter comme un seul chiffre faisait chercher une lenteur du moteur là où il n'y
    //    avait qu'un humain qui réfléchit. `NULL` reste inconnu, jamais zéro.
    $debut = $import['cree_le'] ?? null;
    $derniere = (string)$un('SELECT MAX(COALESCE(valide_le, analyse_le)) FROM crgi_phase
                              WHERE import_id = ?');
    $atelier = ($debut && $derniere) ? max(0, strtotime($derniere) - strtotime($debut)) : 0;
    $mesurees = (int)$un('SELECT COUNT(secondes_machine) FROM crgi_phase WHERE import_id = ?');
    $machine = $mesurees
        ? (int)$un('SELECT COALESCE(SUM(secondes_machine), 0) FROM crgi_phase WHERE import_id = ?')
        : null;
    $humain = (int)$un('SELECT COALESCE(SUM(secondes_humain), 0) FROM crgi_arbitrage
                         WHERE import_id = ?');

    $kpi = crgi_kpi_arbitrage($pdo, $importId);
    $aTraiter = (int)$kpi['a_traiter'];

    // ── LE STATUT GLOBAL ─────────────────────────────────────────────────────────────────
    $statut = 'EN COURS';
    if (($import['statut'] ?? '') === 'ANNULE') {
        $statut = 'ANNULÉE';
    } elseif (array_filter($phases, fn($p) => $p['statut'] === 'BLOQUEE')) {
        $statut = 'ÉCHEC';
    } elseif ($aTraiter > 0) {
        $statut = 'EN ATTENTE D’ARBITRAGE';
    } elseif (count(array_filter($phases, fn($p) => $p['statut'] === 'VALIDEE')) >= 6
              && !$pertes) {
        $statut = 'PRÊTE';
    }

    // ── LES DOCUMENTS REÇUS, ET CE QU'ILS DEMANDENT ──────────────────────────────────────
    // ⚠️ « 3 ERREURS » ET « 3 DOCUMENTS ATTENDENT UN OCR » NE DEMANDENT PAS LE MÊME GESTE.
    //    Tant que les états sont confondus, l'écran fait chercher une panne du moteur là où il
    //    faut lancer une numérisation ou simplement ranger une lettre. Chaque état porte donc
    //    son nom, et le total doit retomber sur le nombre de pièces déposées — sinon un
    //    document a disparu avant même d'entrer dans les phases métier.
    $documents = [];
    foreach (CRGI_ETATS_PIECE as $etat => $_cle) {
        $documents[$etat] = (int)$un('SELECT COUNT(*) FROM crgi_piece
                                       WHERE import_id = ? AND etat = ?', [$etat]);
    }
    $documents['(sans état)'] = (int)$un('SELECT COUNT(*) FROM crgi_piece
                                          WHERE import_id = ? AND (etat IS NULL OR etat = "")');
    $documents = array_filter($documents);

    // ── CE QUE L'APPRENTISSAGE A ÉVITÉ ───────────────────────────────────────────────────
    // ⚠️ « 20 APPRENTISSAGES ENREGISTRÉS » NE DÉMONTRE RIEN. Ce qui démontre, c'est le nombre
    //    de questions que l'agent N'A PAS POSÉES parce qu'il connaissait déjà la réponse :
    //    « 33 homonymes connus → 33 décisions appliquées → 0 question humaine ». Emmanuel,
    //    04/09/2026. Un compteur de savoir ne vaut rien ; un compteur de travail épargné, si.
    $evitees = (int)$un('SELECT COUNT(*) FROM crgi_immeuble
                          WHERE import_id = ? AND motif LIKE "Identité déjà tranchée%"');
    $contredites = (int)$un('SELECT COUNT(*) FROM crgi_immeuble
                             WHERE import_id = ? AND motif LIKE "CONTRADICTION AVEC UNE%"');

    return [
        'import'   => $import,
        'phases'   => $phases,
        'parcours' => crgi_parcours_etats($phases, $aTraiter, $pertes),
        'statut'   => $statut,
        'agences'  => $tous('SELECT COALESCE(agence, "(non imprimée)") a, COUNT(*) n
                               FROM crgi_crg WHERE import_id = ? GROUP BY a ORDER BY n DESC'),
        'volumes'  => [
            'crg' => $crg, 'pages' => $pages, 'pages_lues' => $pagesOk + $pagesHors,
            'mouvements' => $mvt,
        ],
        // ── COMPRENDRE N'EST PAS DÉCIDER SEUL ────────────────────────────────────────────
        // ⚠️ UN SEUL CHIFFRE DISAIT LES DEUX, ET IL DISAIT FAUX. « Compris automatiquement »
        //    affichait la part des mouvements qui portent une nature — donc **100 %** sur un
        //    dépôt où 126 immeubles attendaient une décision d'identité. Emmanuel, 04/09/2026 :
        //    « Ne confonds jamais TAUX DE COMPRÉHENSION et TAUX D'AUTONOMIE. »
        //
        //    COMPRIS   : l'objet a été LU et NOMMÉ. Un immeuble homonyme est parfaitement
        //                compris — le moteur sait ce que le document dit ; il ne sait pas
        //                lequel des deux immeubles de MBI il désigne.
        //    AUTONOMIE : l'objet n'a demandé AUCUNE décision humaine. C'est ce qui mesure le
        //                travail restant, et c'est toujours le plus bas des deux.
        // ── DEUX TAUX, DEUX QUESTIONS DIFFÉRENTES ────────────────────────────────────────
        // ⚠️ UNE AMBIGUÏTÉ CAUSÉE PAR LES DOUBLONS DE MBI NE MESURE PAS LA CAPACITÉ DE
        //    L'AGENT À COMPRENDRE UN DOCUMENT. Emmanuel, 06/09/2026. Un compte rendu
        //    parfaitement lu mais impossible à rattacher parce que la base porte trois fois
        //    le même immeuble ne doit plus dégrader la note du lecteur.
        //
        //    COMPRÉHENSION — l'agent a-t-il su LIRE et NOMMER ce que le document dit ?
        //                    Elle ne dépend que du document. C'est la note du lecteur.
        //    CONFRONTATION — peut-il raccorder cette lecture à MBI sans ambiguïté ?
        //                    Elle dépend de la qualité de la base. C'est la note de MBI.
        //    AUTONOMIE     — combien d'objets n'ont demandé AUCUNE décision humaine ?
        //                    Toujours le plus bas des trois, parce qu'il les subit tous.
        'taux' => [
            'pages'         => $pages ? round(100 * ($pagesOk + $pagesHors) / $pages, 1) : 0.0,
            'compris'       => $objets ? round(100 * ($objets - $mvtMuet) / $objets, 1) : 100.0,
            'confrontation' => crgi_taux_relies($familles),
            'autonomie'     => $objets ? round(100 * ($objets - $enAttente) / $objets, 1) : 100.0,
            'qualifies'     => $mvt ? round(100 * ($mvt - $mvtMuet) / $mvt, 1) : 100.0,
            'relies'        => crgi_taux_relies($familles),
        ],
        // ⚠️ JAMAIS ADDITIONNÉS. Trois causes, trois compteurs — et le nombre d'associations
        //    que la qualité de MBI a réellement empêchées, qui est le seul chiffre disant ce
        //    que la saleté de la base coûte VRAIMENT.
        'causes' => $causes + ['bloquees_par_mbi' => $bloqueesParMbi],
        'familles'  => $familles,
        'arbitrages' => ['total' => $aTraiter, 'priorites' => $priorites,
                         'groupes' => $parGroupe],
        'pertes'    => $pertes,
        'temps'     => ['machine' => $machine, 'atelier' => $atelier, 'humain' => $humain,
                        'phases_mesurees' => $mesurees],
        'kpi'       => $kpi,
        'moteur'    => crgi_version_moteur(),
        'documents' => $documents,
        // Ce qui a été RETIRÉ de la file parce qu'aucune réponse n'existe : compté et montré.
        'sans_reponse' => (int)$un('SELECT COUNT(*) FROM crgi_mouvement
                                     WHERE import_id = ? AND rapprochement = "NON RAPPROCHABLE"'),
        'valeurs_inconnues' => crgi_valeurs_non_declarees($pdo, $importId),
        'apprentissages' => crgi_apprentissages_du_depot($pdo, $importId, $parGroupe)
                            + ['evitees' => $evitees, 'contredites' => $contredites],
    ];
}

/**
 * CE QU'UN ARBITRAGE EMPÊCHE VRAIMENT — LU DANS SON IMPACT DÉCLARÉ.
 *
 * ⚠️ ON NE CLASSE PAS SUR LE NOM DU GROUPE. Une liste « ces familles sont bloquantes » aurait
 *    vieilli au premier ajout, et un arbitrage nouveau serait arrivé sans priorité. L'impact
 *    est écrit une fois, avec la question ; la priorité s'en déduit.
 */
function crgi_priorite_arbitrage(array $a): string
{
    $impact = mb_strtolower((string)($a['impact'] ?? ''));
    if (str_contains($impact, 'bloque la création') || str_contains($impact, 'bloque l’écriture '
        . 'de cette identité')) {
        return 'BLOQUANT';
    }
    if (str_contains($impact, 'bloque')) {
        return 'IMPORTANT';
    }
    return 'NON BLOQUANT';
}

/** La part des objets qui ont trouvé leur place sans intervention. */
function crgi_taux_relies(array $familles): float
{
    $n = $ok = 0;
    foreach ($familles as $f) {
        if (in_array($f['famille'], ['immeubles', 'lots', 'occupations'], true)) {
            $n += $f['detectes'];
            $ok += $f['auto'];
        }
    }
    return $n ? round(100 * $ok / $n, 1) : 100.0;
}

/**
 * L'ÉTAT DE CHAQUE ÉTAPE DU PARCOURS : ✓ terminé · ● en cours · ! arbitrage · ✕ erreur.
 */
function crgi_parcours_etats(array $phases, int $aTraiter, array $pertes): array
{
    $sortie = [];
    foreach (CRGI_PARCOURS as [$titre, $phase, $quoi]) {
        $p = $phases[$phase] ?? null;
        $etat = 'ATTENTE';
        if ($p) {
            $etat = match ($p['statut']) {
                'VALIDEE'  => 'FINI',
                'BLOQUEE'  => 'ERREUR',
                'A VALIDER', 'ANALYSE' => 'ENCOURS',
                default    => 'ATTENTE',
            };
        }
        if ($titre === 'ARBITRAGES') {
            $etat = $aTraiter > 0 ? 'ARBITRAGE' : ($etat === 'FINI' ? 'FINI' : $etat);
        }
        if ($titre === 'PRÊT') {
            $etat = ($aTraiter === 0 && !$pertes && $etat === 'FINI') ? 'FINI'
                  : ($aTraiter > 0 ? 'ARBITRAGE' : 'ATTENTE');
        }
        if ($titre === 'CONTRÔLES' && $pertes) {
            $etat = 'ERREUR';
        }
        $sortie[] = ['titre' => $titre, 'quoi' => $quoi, 'etat' => $etat, 'phase' => $phase];
    }
    return $sortie;
}

/**
 * CE QUE MBI A APPRIS, ET CE QU'IL A RÉUTILISÉ SUR CE DÉPÔT.
 *
 * ⚠️ ON COMPTE LES RÈGLES DU REGISTRE, PAS LES BONNES INTENTIONS. Une entrée du registre ne
 *    vaut que si un test la porte : le nombre affiché ici est donc celui des apprentissages
 *    RÉELLEMENT enregistrés, et le harnais vérifie séparément qu'ils s'exécutent.
 *
 * ⚠️ « PHÉNOMÈNE NOUVEAU » N'EST PAS « ARBITRAGE ». Un arbitrage attendu — un homonyme, une
 *    période unique — est un doute connu, prévu, outillé. Un phénomène nouveau est un groupe
 *    que le moteur n'a jamais posé auparavant : c'est LUI qui doit attirer l'œil.
 */
function crgi_apprentissages_du_depot(PDO $pdo, int $importId, array $parGroupe): array
{
    $registre = dirname(__DIR__, 2) . '/docs/reference_crg_apprentissages.md';
    $regles = 0;
    if (is_file($registre)) {
        $regles = preg_match_all('~^## APP-\d+~m', (string)file_get_contents($registre));
    }
    // Les groupes déjà rencontrés sur un import antérieur : le moteur savait déjà les poser.
    $st = $pdo->prepare('SELECT DISTINCT groupe FROM crgi_arbitrage WHERE import_id <> ?');
    $st->execute([$importId]);
    $connus = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
    $nouveaux = [];
    foreach (array_keys($parGroupe) as $g) {
        if (!isset($connus[$g])) {
            $nouveaux[] = $g;
        }
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM crgi_arbitrage
                          WHERE import_id = ? AND portee = "APPRENTISSAGE"');
    $st->execute([$importId]);
    return [
        'regles_connues' => $regles,
        'nouveaux'       => $nouveaux,
        'proposes'       => (int)$st->fetchColumn(),
    ];
}

/** « 2 min 14 s » — parce qu'un nombre de secondes ne se lit pas. */
function crgi_duree(int $s): string
{
    if ($s < 60) {
        return $s . ' s';
    }
    if ($s < 3600) {
        return intdiv($s, 60) . ' min ' . str_pad((string)($s % 60), 2, '0', STR_PAD_LEFT) . ' s';
    }
    return intdiv($s, 3600) . ' h ' . str_pad((string)intdiv($s % 3600, 60), 2, '0', STR_PAD_LEFT);
}
