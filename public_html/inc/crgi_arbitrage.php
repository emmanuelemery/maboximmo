<?php
declare(strict_types=1);
/**
 * LA FILE D'ARBITRAGE — UNE BOÎTE DE RÉCEPTION, PAS UN RAPPORT TECHNIQUE.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CE FICHIER EXISTE PARCE QU'UN ARBITRAGE COÛTAIT UNE HEURE. Les questions étaient
 *    posées — 90 lignes, 7 groupes — mais pour décider il fallait ouvrir le PDF, chercher la
 *    page, retrouver le compte, comprendre un motif technique. Le temps d'Emmanuel partait
 *    dans la recherche de la preuve, pas dans la décision.
 *
 * ⚠️ L'AGENT PROPOSE, IL NE DEVINE PAS. La confiance affichée est un COMPTAGE : la part des
 *    lignes comparables — même section, même colonne, même maille — que le moteur a déjà
 *    qualifiées ainsi DANS CE DÉPÔT. Elle n'est jamais tirée d'un vocabulaire : « Assurance »
 *    dans un libellé n'est pas une preuve, et une proposition qui s'appuierait sur le mot
 *    ferait exactement ce que la doctrine interdit depuis P4 — déduire une nature d'un nom.
 *    Là où aucune ligne comparable n'existe, l'agent le DIT et ne propose rien de mieux que
 *    « indéterminable ».
 *
 * ⚠️ UNE DÉCISION N'EST PAS UNE RÈGLE. `portee` distingue `CAS` (cet événement seulement),
 *    `GROUPE` (étendue à un ensemble explicitement décrit et compté) et `APPRENTISSAGE`
 *    (proposée comme phénomène). Rien ne se propage en silence : une extension nomme son
 *    critère et son nombre de lignes, et Emmanuel la voit avant de valider.
 *
 * ⚠️ ET LA PREUVE VOYAGE AVEC LA DÉCISION. Le PDF de staging disparaît à l'annulation de
 *    l'import ; `preuve_pdf`, `preuve_page` et `preuve_sha` restent. Une décision qui survit
 *    à sa preuve n'est pas auditable.
 */

require_once __DIR__ . '/crg_integration.php';

/** Les natures que le moteur sait poser — l'ordre n'a pas de sens, la liste en a un. */
const CRGI_NATURES = [
    'LOYER APPELE', 'CHARGE APPELEE AU LOCATAIRE', 'AUTRE APPELE AU LOCATAIRE',
    'ENCAISSEMENT', 'ENCOURS', 'CHARGE', 'FRAIS ET ASSURANCES',
    'VERSEMENT PROPRIETAIRE', 'SOLDE', 'AGREGAT (NON ADDITIONNABLE)',
    'DETAIL (NON ADDITIONNABLE)',
];

/**
 * L'IDENTITÉ DU MOTEUR AU MOMENT OÙ LA QUESTION EST POSÉE.
 *
 * ⚠️ UNE RÉPONSE JUSTE SOUS UN MOTEUR DONNÉ PEUT NE PLUS L'ÊTRE SOUS UN AUTRE. On enregistre
 *    donc avec quelles règles Emmanuel a tranché — le commit et l'empreinte du registre —
 *    pour pouvoir le savoir plus tard plutôt que le supposer.
 */
function crgi_version_moteur(): array
{
    static $v = null;
    if ($v !== null) {
        return $v;
    }
    $racine = dirname(__DIR__, 2);
    $commit = trim((string)@shell_exec('git -C ' . escapeshellarg($racine) . ' rev-parse HEAD 2>&1'));
    $registre = $racine . '/docs/reference_crg_apprentissages.md';
    $v = [
        'commit'   => preg_match('~^[0-9a-f]{40}$~', $commit) ? $commit : null,
        'registre' => is_file($registre) ? hash_file('sha256', $registre) : null,
    ];
    return $v;
}

/**
 * LA FILE, À PLAT : UNE LIGNE = UNE DÉCISION À PRENDRE.
 *
 * ⚠️ ON APLATIT LES GROUPES, PARCE QU'ON DÉCIDE LIGNE PAR LIGNE. La vue par phénomène sert à
 *    COMPRENDRE ; celle-ci sert à DÉCIDER, et elle porte tout le contexte nécessaire pour
 *    trancher sans ouvrir autre chose — jusqu'au numéro de page et à l'empreinte du document.
 */
function crgi_file_arbitrages(PDO $pdo, int $importId, array $filtres = []): array
{
    $file = [];
    foreach (crgi_arbitrages($pdo, $importId) as $g) {
        foreach ($g['lignes'] as $l) {
            $file[] = [
                'groupe'   => $g['groupe'],
                // ⚠️ LA CAUSE VOYAGE AVEC LA QUESTION. Sans elle, une ambiguïté née des
                //    doublons de MBI se compte comme une difficulté de lecture, et le taux de
                //    compréhension du lecteur baisse pour une faute qui n'est pas la sienne.
                //    Elle est DÉCLARÉE par la famille, jamais devinée d'après son nom.
                'cause'    => $g['cause'] ?? 'DOCUMENT',
                'cible'    => $g['cible'],
                'famille'  => $g['famille'],
                'question' => $g['question'],
                'regle'    => $g['regle'],
                'impact'   => $g['impact'] ?? '',
                'choix'    => $g['choix'],
                'cible_id' => (int)($l['cible_id'] ?? 0),
                'ligne'    => $l,
                'decision' => $l['decision'] ?? null,
            ];
        }
    }

    // ── LE CONTEXTE ET LA PREUVE, EN UNE SEULE REQUÊTE PAR TYPE DE CIBLE ─────────────────
    $ctx = ['MOUVEMENT' => [], 'IMMEUBLE' => [], 'OCCUPATION' => [], 'LOT' => []];
    // ⚠️ UN CONFLIT D'IDENTITÉ N'A PAS DE LIGNE À LUI : il EST la comparaison de deux lectures.
    //    Son contexte se recalcule donc à la source, avec la preuve de CHAQUE côté — sinon
    //    l'écran ne pourrait montrer qu'une moitié du désaccord, et demanderait de trancher à
    //    l'aveugle ce qu'il prétend éclairer.
    foreach (array_keys(CRGI_IDENTITES) as $t) {
        $ctx[$t] = [];
        foreach (crgi_conflits_identite($pdo, $importId, $t) as $c) {
            $ctx[$t][$c['cible_id']] = $c + [
                'page'   => $c['lectures'][0]['page'] ?? 0,
                'crg_id' => $c['lectures'][0]['crg_id'] ?? 0,
            ];
        }
    }
    $st = $pdo->prepare(
        'SELECT m.id, m.page, m.section, m.libelle, m.colonne, m.montant, m.maille,
                m.lot_reference, m.locataire, m.immeuble, m.categorie, m.motif,
                c.compte, c.proprietaire, c.agence, c.format, c.periode_cle, c.date_arrete,
                c.id AS crg_id, p.nom_original, p.sha256
           FROM crgi_mouvement m
           JOIN crgi_crg c ON c.id = m.crg_id
           JOIN crgi_piece p ON p.id = c.piece_id
          WHERE m.import_id = ?'
    );
    $st->execute([$importId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ctx['MOUVEMENT'][(int)$r['id']] = $r;
    }
    $st = $pdo->prepare(
        'SELECT i.id, i.page, i.code, i.nom, i.code_postal, i.ville, i.motif,
                c.compte, c.proprietaire, c.agence, c.format, c.periode_cle, c.date_arrete,
                c.id AS crg_id, p.nom_original, p.sha256
           FROM crgi_immeuble i
           JOIN crgi_crg c ON c.id = i.crg_id
           JOIN crgi_piece p ON p.id = c.piece_id
          WHERE i.import_id = ?'
    );
    $st->execute([$importId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ctx['IMMEUBLE'][(int)$r['id']] = $r;
    }
    $st = $pdo->prepare(
        'SELECT l.id, l.page, l.reference AS lot_reference, l.libelle, l.locataire, l.motif,
                c.compte, c.proprietaire, c.agence, c.format, c.periode_cle, c.date_arrete,
                c.id AS crg_id, p.nom_original, p.sha256
           FROM crgi_lot l
           JOIN crgi_crg c ON c.id = l.crg_id
           JOIN crgi_piece p ON p.id = c.piece_id
          WHERE l.import_id = ?'
    );
    $st->execute([$importId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ctx['LOT'][(int)$r['id']] = $r;
    }
    $st = $pdo->prepare(
        'SELECT o.id, o.page, o.lot_reference, o.locataire, o.precedent, o.statut,
                o.statut_motif AS motif, o.date_arrete,
                c.compte, c.proprietaire, c.agence, c.format, c.periode_cle,
                c.id AS crg_id, p.nom_original, p.sha256
           FROM crgi_occupation o
           JOIN crgi_crg c ON c.id = o.crg_id
           JOIN crgi_piece p ON p.id = c.piece_id
          WHERE o.import_id = ?'
    );
    $st->execute([$importId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ctx['OCCUPATION'][(int)$r['id']] = $r;
    }

    // Les décisions déjà prises, pour que la file sache ce qui reste.
    $prises = [];
    $st = $pdo->prepare('SELECT * FROM crgi_arbitrage WHERE import_id = ?');
    $st->execute([$importId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $prises[$d['cible_type'] . ':' . $d['cible_id']] = $d;
    }

    $sortie = [];
    foreach ($file as $a) {
        $c = $ctx[$a['cible']][$a['cible_id']] ?? [];
        $a['contexte'] = $c;
        $a['prise'] = $prises[$a['cible'] . ':' . $a['cible_id']] ?? null;
        $a['statut'] = $a['prise']['statut'] ?? 'A TRAITER';
        if (!empty($filtres['statut']) && $a['statut'] !== $filtres['statut']) {
            continue;
        }
        if (!empty($filtres['groupe']) && $a['groupe'] !== $filtres['groupe']) {
            continue;
        }
        $sortie[] = $a;
    }
    return empty($filtres['detail']) ? crgi_regrouper_file($sortie) : $sortie;
}

/**
 * LES FAMILLES QUI SE DÉCIDENT EN BLOC, ET CE QUI FAIT LEUR PHÉNOMÈNE.
 *
 * ⚠️ UNE FILE EST UNE SUITE DE QUESTIONS, PAS UNE SUITE DE LIGNES. Sur un dépôt réel elle en
 *    affichait **397**, dont 337 posaient rigoureusement la même : « quelle est la nature des
 *    montants de cette colonne ? ». Emmanuel, 04/09/2026 : « les arbitrages doivent se limiter
 *    à une trentaine environ à chaque fois ». Une question répétée trois cent fois n'est pas
 *    trois cents questions — c'est une question et un défaut de présentation.
 *
 * ⚠️ ON NE REGROUPE QUE CE QUI A LA MÊME RÉPONSE. Un homonyme d'immeuble et un conflit
 *    d'identité sont des décisions INDIVIDUELLES : chacune désigne un objet différent, et les
 *    fondre dans un lot ferait exactement l'erreur des « quatre homonymes sous un seul
 *    intitulé ». Ces familles restent ligne par ligne, et c'est délibéré.
 */
// ⚠️ UNE RÈGLE SUR LE PHÉNOMÈNE L'EMPORTE SUR UNE RÈGLE SUR L'OBJET. Deux immeubles
//    réellement homonymes sont deux décisions INDIVIDUELLES — chacune désigne un bâtiment
//    différent. Mais dix-sept enregistrements que MBI lui-même déclare identiques posent UNE
//    seule question, et elle a UNE seule réponse : quelle règle appliquer quand la base se
//    répète. Le repli se déclare donc par famille de phénomène, pas par type d'objet.
const CRGI_REGROUPEMENT = [
    'IMMEUBLE-REPETE-DANS-MBI' => [],   // même question, même réponse : une seule ligne
    // famille de cible => les champs du contexte qui font le phénomène
    'MOUVEMENT'  => ['section', 'colonne', 'maille'],
    'OCCUPATION' => [],          // le motif suffit : la réponse est la même pour tout le groupe
];

/**
 * Replie la file : une entrée par PHÉNOMÈNE, avec ce qu'elle couvre.
 *
 * ⚠️ LA REPRÉSENTANTE GARDE SA PREUVE. On ne fabrique pas une ligne synthétique : on prend la
 *    première du phénomène, avec sa page et son document, pour que « voir dans le CRG » reste
 *    vrai. Le nombre et le total, eux, disent l'ampleur de ce qu'on décide.
 */
function crgi_regrouper_file(array $file): array
{
    $vues = [];
    foreach ($file as $a) {
        $champs = CRGI_REGROUPEMENT[$a['groupe']] ?? CRGI_REGROUPEMENT[$a['cible']] ?? null;
        if ($champs === null) {
            $vues[] = $a;                       // décision individuelle : rien à replier
            continue;
        }
        $cle = $a['groupe'];
        foreach ($champs as $c) {
            $v = (string)($a['contexte'][$c] ?? '');
            // ⚠️ UNE SECTION NON RECONNUE EST UN PHÉNOMÈNE, PAS UN INTITULÉ. Le moteur préfixe
            //    « INCONNUE: » le titre qu'il n'a pas su classer — et ce titre est différent à
            //    chaque fois, par construction. Grouper dessus revenait à ne jamais grouper :
            //    quatre crédits sous quatre intitulés inconnus faisaient quatre questions là
            //    où il n'y en a qu'une — « quelle est la nature d'un crédit dont le document
            //    ne nomme pas la section ? ». Le repli tient sur ce qui EST commun : la
            //    colonne et la maille. Et comme aucun repli n'est jamais coché d'avance,
            //    Emmanuel garde la main pour répondre ligne à ligne si les natures diffèrent.
            if ($c === 'section' && str_starts_with($v, 'INCONNUE:')) {
                $v = 'INCONNUE';
            }
            $cle .= '|' . $v;
        }
        if (!isset($vues[$cle])) {
            $a['couvre_ids'] = [];
            $a['nombre'] = 0;
            $a['total'] = 0.0;
            $vues[$cle] = $a;
        }
        $vues[$cle]['couvre_ids'][] = (int)$a['cible_id'];
        // ⚠️ UNE LIGNE REPLIÉE PEUT DÉJÀ EN COUVRIR D'AUTRES, ET ON PERDAIT CELLES-LÀ. Les
        //    familles d'identité groupent déjà leurs objets à la source (`GROUP_CONCAT(id)`) :
        //    ne retenir que la cible principale de chaque ligne repliée laissait 43 immeubles
        //    en attente SANS question — la définition même d'une perte silencieuse, et le
        //    troisième défaut de ce genre que ce KPI attrape. Le repli additionne donc les
        //    couvertures, il ne les remplace pas.
        foreach (explode(',', (string)($a['ligne']['couvre'] ?? '')) as $id) {
            if ($id !== '') {
                $vues[$cle]['couvre_ids'][] = (int)$id;
            }
        }
        $vues[$cle]['nombre']++;
        $vues[$cle]['total'] += (float)($a['contexte']['montant'] ?? 0);
        // ⚠️ UNE QUESTION DÉJÀ TRANCHÉE POUR UNE PARTIE DU GROUPE RESTE À TRAITER TANT QU'IL
        //    EN RESTE. Sinon une décision partielle ferait disparaître le reste de l'écran.
        if (($a['statut'] ?? 'A TRAITER') === 'A TRAITER') {
            $vues[$cle]['statut'] = 'A TRAITER';
        }
    }
    return array_values($vues);
}

/**
 * CE QUE L'AGENT PROPOSE, ET POURQUOI — DEUX À QUATRE PISTES, JAMAIS PLUS.
 *
 * ⚠️ LA CONFIANCE EST UNE PART, PAS UNE IMPRESSION. Pour un montant sans nature, on compte
 *    les lignes du MÊME dépôt qui partagent sa section, sa colonne et sa maille et que le
 *    moteur a su qualifier : la répartition de leurs natures EST la proposition. Si aucune
 *    ligne comparable n'existe — le cas d'une section entièrement nouvelle — l'agent ne
 *    propose rien qu'il ne puisse démontrer, et le dit.
 *
 * ⚠️ ON NE LIT JAMAIS LE LIBELLÉ POUR PROPOSER. Le mot est affiché à Emmanuel, parce que
 *    c'est LUI qui sait ce que « GLI » ou « PNO » veut dire dans son métier. L'agent qui
 *    s'en servirait pour bâtir un pourcentage rendrait une intuition sous l'apparence d'une
 *    mesure — et c'est précisément ce que la doctrine interdit.
 */
function crgi_propositions(PDO $pdo, int $importId, array $a): array
{
    $c = $a['contexte'] ?? [];
    $props = [];

    if ($a['cible'] === 'MOUVEMENT') {
        // 1) les lignes comparables au sens le plus strict : section × colonne × maille
        foreach ([
            ['section, colonne et maille identiques', 'section = ? AND colonne = ? AND maille = ?',
             [$c['section'] ?? '', $c['colonne'] ?? '', $c['maille'] ?? '']],
            ['même colonne et même maille', 'colonne = ? AND maille = ?',
             [$c['colonne'] ?? '', $c['maille'] ?? '']],
        ] as [$libelle, $where, $args]) {
            $st = $pdo->prepare(
                'SELECT categorie, COUNT(*) n FROM crgi_mouvement
                  WHERE import_id = ? AND categorie <> "INDETERMINABLE" AND ' . $where . '
                  GROUP BY categorie ORDER BY n DESC LIMIT 3'
            );
            $st->execute(array_merge([$importId], $args));
            $lignes = $st->fetchAll(PDO::FETCH_ASSOC);
            $total = array_sum(array_column($lignes, 'n'));
            if ($total < 5) {
                continue;   // trop peu de matière : ce ne serait pas une mesure
            }
            foreach ($lignes as $r) {
                $props[] = [
                    'choix'     => (string)$r['categorie'],
                    'confiance' => (int)round(100 * (int)$r['n'] / $total),
                    'raison'    => sprintf('%d lignes de ce dépôt à %s sont qualifiées ainsi.',
                                           (int)$r['n'], $libelle),
                ];
            }
            break;   // on s'arrête au niveau le plus strict qui ait de la matière
        }
    }

    if ($a['cible'] === 'IMMEUBLE') {
        // Les candidats que MBI porte réellement, sur l'adresse imprimée : on les NOMME.
        $st = $pdo->prepare(
            'SELECT id, reference_immeuble, adresse_1, code_postal, ville, id_agence
               FROM immeubles
              WHERE code_postal = ? AND (adresse_1 LIKE ? OR ? LIKE CONCAT("%", adresse_1, "%"))
              LIMIT 4'
        );
        $st->execute([$c['code_postal'] ?? '', '%' . ($c['nom'] ?? '') . '%', $c['nom'] ?? '']);
        $cands = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cands as $x) {
            // ⚠️ LE CHOIX PORTE L'IDENTITÉ, PAS L'INTENTION. Quatre homonymes proposés sous le
            //    même libellé « Désigner l'immeuble MBI existant » produiraient quatre décisions
            //    indiscernables en base : on saurait qu'Emmanuel a tranché, jamais POUR LEQUEL.
            //    L'audit d'un arbitrage vaut ce que vaut la précision de ce qu'il enregistre.
            $props[] = [
                'choix'     => sprintf('Désigner l’immeuble MBI #%d', (int)$x['id']),
                // Le choix de la règle que cette piste instancie : l'écran n'a donc pas à
                // réafficher l'option générique, qui rouvrirait l'ambiguïté qu'on vient de fermer.
                'regle'     => 'Désigner l’immeuble MBI existant',
                'confiance' => count($cands) === 1 ? 70 : (int)round(70 / count($cands)),
                'raison'    => sprintf('MBI #%d — %s %s %s (agence %s)', (int)$x['id'],
                                       $x['adresse_1'], $x['code_postal'], $x['ville'],
                                       (string)$x['id_agence']),
            ];
        }
        if (!$cands) {
            $props[] = ['choix' => 'Créer un immeuble distinct', 'confiance' => 60,
                        'raison' => 'Aucun immeuble MBI ne porte cette adresse et ce code postal.'];
        }
    }

    // ── CONFLIT D'IDENTITÉ : une proximité fait un CANDIDAT, jamais une fusion ────────────
    // ⚠️ ICI, LA CONFIANCE EST UNE DISTANCE, ET ON LE DIT. Deux lectures d'un même nom se
    //    comparent caractère à caractère : c'est une mesure, reproductible et vérifiable. Ce
    //    qu'elle ne fait PAS, c'est conclure — 94 % de ressemblance entre deux noms de famille
    //    voisins est exactement la situation où deux personnes réelles se ressemblent. La
    //    mesure ouvre la question ; elle ne la ferme pas.
    if (isset(CRGI_IDENTITES[$a['cible']])) {
        $lect = $c['lectures'] ?? [];
        $valeurs = array_values(array_unique(array_column($lect, 'valeur')));
        if (count($valeurs) >= 2) {
            $a1 = mb_strtoupper($valeurs[0]);
            $b1 = mb_strtoupper($valeurs[1]);
            $d = levenshtein(substr($a1, 0, 255), substr($b1, 0, 255));
            $long = max(mb_strlen($a1), mb_strlen($b1)) ?: 1;
            $proche = (int)round(100 * max(0, 1 - $d / $long));
            $props[] = [
                'choix'     => 'Même identité',
                'confiance' => $proche,
                'raison'    => sprintf('%d %% des caractères coïncident (%d correction%s '
                                     . 'sépare%s les deux lectures) — une mesure, pas une preuve.',
                                       $proche, $d, $d > 1 ? 's' : '', $d > 1 ? 'nt' : ''),
            ];
            $props[] = [
                'choix'     => 'Identités différentes',
                'confiance' => 100 - $proche,
                'raison'    => sprintf('%d %% des caractères diffèrent ; le document ne dit '
                                     . 'nulle part que ces deux lectures se rapportent au '
                                     . 'même objet.', 100 - $proche),
            ];
        }
    }

    if ($a['cible'] === 'OCCUPATION') {
        // ⚠️ LE PRÉCÉDENT OCCUPANT EST UNE PREUVE, PAS UNE SUPPOSITION : il vient de la
        //    période antérieure du même lot, lue sur le document.
        // ⚠️ LE CHOIX PORTE LE NOM, PAS L'INTENTION — exactement comme pour les immeubles
        //    homonymes. Deux pistes intitulées « Considérer l'occupant en place » désignent
        //    deux personnes différentes : la période précédente en nomme une, les mouvements du
        //    lot en nomment une autre. Enregistrées sous le même libellé, les deux décisions
        //    seraient indiscernables en base — on saurait qu'Emmanuel a tranché, jamais POUR QUI.
        $proposes = [];
        if (!empty($c['precedent'])) {
            $proposes[(string)$c['precedent']] = [65,
                'La période précédente du même lot porte « ' . $c['precedent'] . ' ».'];
        }
        $st = $pdo->prepare(
            'SELECT DISTINCT locataire FROM crgi_mouvement
              WHERE import_id = ? AND lot_reference = ? AND locataire IS NOT NULL
                AND locataire <> "" LIMIT 2'
        );
        $st->execute([$importId, $c['lot_reference'] ?? '']);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $nom) {
            // Deux sources qui nomment la MÊME personne ne font pas deux pistes : elles se
            // renforcent. La plus démontrée l'emporte, et le motif dit les deux.
            $vu = $proposes[(string)$nom] ?? null;
            $proposes[(string)$nom] = [max(75, $vu[0] ?? 0),
                ($vu ? $vu[1] . ' ' : '') . 'Les mouvements de ce lot nomment « ' . $nom . ' ».'];
        }
        foreach ($proposes as $nom => [$confiance, $raison]) {
            $props[] = [
                'choix'     => 'Considérer « ' . $nom . ' » en place',
                'regle'     => 'Considérer l’occupant en place',
                'confiance' => $confiance,
                'raison'    => $raison,
            ];
        }
    }

    // ⚠️ « INDÉTERMINABLE » EST TOUJOURS OFFERT, ET CE N'EST PAS UN AVEU D'ÉCHEC.
    //    `INDÉTERMINABLE ≠ ERREUR` : c'est la réponse juste quand le document ne dit rien.
    $props[] = ['choix' => 'Indéterminable', 'confiance' => 0,
                'raison' => 'Le document ne permet pas de trancher — et le dire est une réponse.'];

    // On garde les quatre premières : au-delà, on fait perdre du temps au lieu d'en gagner.
    $vu = [];
    $final = [];
    foreach ($props as $p) {
        $cle = $p['choix'] . '|' . $p['raison'];
        if (isset($vu[$cle])) {
            continue;
        }
        $vu[$cle] = true;
        $final[] = $p;
        if (count($final) >= 4) {
            break;
        }
    }
    return $final;
}

/**
 * L'ENSEMBLE DES LIGNES QUI RELÈVENT EXACTEMENT DU MÊME PHÉNOMÈNE.
 *
 * ⚠️ « EXACTEMENT » EST LA CONDITION, ET ELLE EST ÉCRITE. Le critère est rendu en clair pour
 *    qu'Emmanuel voie ce qu'il étend avant de l'étendre. Une propagation dont on ne peut
 *    plus dire ce qu'elle a couvert est irrattrapable — d'où le critère ET le compte.
 */
function crgi_groupe_semblable(PDO $pdo, int $importId, array $a): ?array
{
    $c = $a['contexte'] ?? [];
    if ($a['cible'] !== 'MOUVEMENT' || ($c['section'] ?? '') === '') {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT COUNT(*) n, ROUND(SUM(montant), 2) total, MIN(page) p1, MAX(page) p2
           FROM crgi_mouvement
          WHERE import_id = ? AND categorie = "INDETERMINABLE"
            AND section = ? AND colonne = ? AND maille = ?'
    );
    $st->execute([$importId, $c['section'], $c['colonne'], $c['maille']]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || (int)$r['n'] < 2) {
        return null;
    }
    return [
        'critere' => sprintf('section « %s » · colonne « %s » · maille %s · encore sans nature',
                             $c['section'], $c['colonne'], $c['maille']),
        'nombre'  => (int)$r['n'],
        'total'   => (float)$r['total'],
        'pages'   => (int)$r['p1'] === (int)$r['p2']
                     ? 'page ' . (int)$r['p1']
                     : 'pages ' . (int)$r['p1'] . '–' . (int)$r['p2'],
        'sql'     => ['section' => $c['section'], 'colonne' => $c['colonne'],
                      'maille' => $c['maille']],
    ];
}

/**
 * ENREGISTRER UNE DÉCISION — AVEC SA PREUVE, SA PORTÉE ET LA VERSION QUI L'A POSÉE.
 *
 * ⚠️ ELLE N'ÉCRIT RIEN DANS LES DONNÉES MÉTIER. Décider n'est pas intégrer : la décision vit
 *    en staging, datée et signée, et c'est la phase d'intégration qui l'exécutera.
 */
/**
 * UNE DÉCISION D'IDENTITÉ SE GRAVE, LES AUTRES NON.
 *
 * ⚠️ POURQUOI CELLES-LÀ SEULEMENT. « Cet immeuble du document est le n°563 de MBI » est une
 *    identité : elle reste vraie au dépôt suivant, et la reposer serait absurde. « Ce montant
 *    est une charge » est une qualification de LIGNE : elle appartient à sa ligne, et la
 *    gaver dans une mémoire durable ferait appliquer une réponse à des faits qu'on n'a pas lus.
 *    La règle, elle, s'apprend autrement — par la table des sections, qui est additive.
 *
 * ⚠️ ET ELLE PORTE SA PREUVE. Date, auteur, page, document, commit du moteur : une décision
 *    qu'on ne peut pas relire est un souvenir, pas une identité.
 */
function crgi_graver_identite(PDO $pdo, int $importId, array $d): void
{
    $types = ['IMMEUBLE' => 'IMMEUBLE', 'CONFLIT_IMMEUBLE' => 'IMMEUBLE',
              'CONFLIT_OCCUPANT' => 'OCCUPANT', 'CONFLIT_PROPRIO' => 'PROPRIETAIRE'];
    $type = $types[(string)$d['cible_type']] ?? null;
    if (!$type || ($d['statut'] ?? 'VALIDE') !== 'VALIDE') {
        return;
    }
    $ctx = crgi_contexte_identite($pdo, (string)$d['cible_type'], (int)$d['cible_id']);
    if (!$ctx) {
        return;
    }
    $v = crgi_version_moteur();
    // Le numéro MBI, quand la décision en désigne un : « Désigner l’immeuble MBI #563 ».
    $mbi = preg_match('~#(\d+)~', (string)$d['choix'], $m) ? (int)$m[1] : null;
    $pdo->prepare(
        'INSERT INTO crgi_identite
            (type, agence, cle, choix, mbi_id, precision_h, preuve_pdf, preuve_page,
             import_origine, moteur_commit, decide_par)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE choix = VALUES(choix), mbi_id = VALUES(mbi_id),
             precision_h = VALUES(precision_h), decide_par = VALUES(decide_par),
             decide_le = NOW()'
    )->execute([$type, $ctx['agence'], $ctx['cle'], mb_substr((string)$d['choix'], 0, 120),
                $mbi, $d['precision'] ?? null, $d['preuve_pdf'] ?? null,
                isset($d['preuve_page']) ? (int)$d['preuve_page'] : null,
                $importId, $v['commit'], (int)($d['user'] ?? 0) ?: null]);
}

/** L'agence et la clé métier d'une cible d'arbitrage — ce qui fait son identité durable. */
function crgi_contexte_identite(PDO $pdo, string $type, int $cibleId): ?array
{
    if ($type === 'IMMEUBLE' || $type === 'CONFLIT_IMMEUBLE') {
        $st = $pdo->prepare('SELECT i.code, i.nom, i.code_postal, c.agence
                               FROM crgi_immeuble i JOIN crgi_crg c ON c.id = i.crg_id
                              WHERE i.id = ?');
        $st->execute([$cibleId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['agence' => (string)$r['agence'], 'cle' => crgi_cle_immeuble($r)] : null;
    }
    return null;
}

function crgi_decider(PDO $pdo, int $importId, array $d): int
{
    $v = crgi_version_moteur();
    $st = $pdo->prepare(
        'INSERT INTO crgi_arbitrage
            (import_id, groupe, cible_type, cible_id, choix, statut, portee,
             agent_proposition, agent_confiance, agent_suivi,
             preuve_page, preuve_pdf, preuve_sha, moteur_commit, registre_sha,
             groupe_applique, groupe_taille, secondes_humain, precision_h, decide_par)
         VALUES (:i,:g,:ct,:ci,:ch,:st,:po,:ap,:ac,:as_,:pp,:pf,:ps,:mc,:rs,:ga,:gt,:sh,:pr,:u)
         ON DUPLICATE KEY UPDATE
            choix = VALUES(choix), statut = VALUES(statut), portee = VALUES(portee),
            agent_proposition = VALUES(agent_proposition),
            agent_confiance = VALUES(agent_confiance), agent_suivi = VALUES(agent_suivi),
            preuve_page = VALUES(preuve_page), preuve_pdf = VALUES(preuve_pdf),
            preuve_sha = VALUES(preuve_sha), moteur_commit = VALUES(moteur_commit),
            registre_sha = VALUES(registre_sha), groupe_applique = VALUES(groupe_applique),
            groupe_taille = VALUES(groupe_taille), secondes_humain = VALUES(secondes_humain),
            precision_h = VALUES(precision_h), decide_par = VALUES(decide_par)'
    );
    $st->execute([
        ':i' => $importId, ':g' => $d['groupe'], ':ct' => $d['cible_type'],
        ':ci' => (int)$d['cible_id'], ':ch' => mb_substr((string)$d['choix'], 0, 120),
        ':st' => $d['statut'] ?? 'VALIDE', ':po' => $d['portee'] ?? 'CAS',
        ':ap' => $d['agent_proposition'] ?? null,
        ':ac' => isset($d['agent_confiance']) ? (int)$d['agent_confiance'] : null,
        ':as_' => !empty($d['agent_suivi']) ? 1 : 0,
        ':pp' => isset($d['preuve_page']) ? (int)$d['preuve_page'] : null,
        ':pf' => $d['preuve_pdf'] ?? null, ':ps' => $d['preuve_sha'] ?? null,
        ':mc' => $v['commit'], ':rs' => $v['registre'],
        ':ga' => isset($d['groupe_applique']) ? mb_substr((string)$d['groupe_applique'], 0, 400) : null,
        ':gt' => isset($d['groupe_taille']) ? (int)$d['groupe_taille'] : null,
        ':sh' => isset($d['secondes_humain']) ? (int)$d['secondes_humain'] : null,
        ':pr' => isset($d['precision']) && $d['precision'] !== ''
                 ? mb_substr((string)$d['precision'], 0, 1000) : null,
        ':u' => (int)($d['user'] ?? 0) ?: null,
    ]);
    crgi_graver_identite($pdo, $importId, $d);
    return (int)$pdo->lastInsertId();
}

/**
 * LES CHIFFRES DE LA FILE — dont celui qu'Emmanuel ressent.
 *
 * ⚠️ LE KPI N'EST PAS UN POURCENTAGE DE TESTS VERTS. C'est `interventions / 100 CRG` : le
 *    seul qui baisse quand l'agent apprend vraiment, et le seul qu'on ressent en travaillant.
 */
function crgi_kpi_arbitrage(PDO $pdo, int $importId): array
{
    $un = function (string $sql) use ($pdo, $importId) {
        $st = $pdo->prepare($sql);
        $st->execute([$importId]);
        return (int)$st->fetchColumn();
    };
    $crg   = $un('SELECT COUNT(*) FROM crgi_crg WHERE import_id = ?');
    $mvt   = $un('SELECT COUNT(*) FROM crgi_mouvement WHERE import_id = ?');
    $muets = $un('SELECT COUNT(*) FROM crgi_mouvement WHERE import_id = ? '
               . 'AND categorie = "INDETERMINABLE"');
    $file  = crgi_file_arbitrages($pdo, $importId);
    $par = ['A TRAITER' => 0, 'VALIDE' => 0, 'REPORTE' => 0, 'INDETERMINABLE' => 0];
    $secondes = 0;
    foreach ($file as $a) {
        $par[$a['statut']] = ($par[$a['statut']] ?? 0) + 1;
        $secondes += (int)($a['prise']['secondes_humain'] ?? 0);
    }
    $traites = $par['VALIDE'] + $par['INDETERMINABLE'];
    return [
        'crg'            => $crg,
        'mouvements'     => $mvt,
        'auto'           => $mvt ? round(100 * ($mvt - $muets) / $mvt, 1) : 0.0,
        'total'          => count($file),
        'a_traiter'      => $par['A TRAITER'],
        'valides'        => $par['VALIDE'],
        'reportes'       => $par['REPORTE'],
        'indeterminable' => $par['INDETERMINABLE'],
        'arb_100crg'     => $crg ? round(100 * count($file) / $crg, 2) : 0.0,
        'interv_100crg'  => $crg ? round(100 * $traites / $crg, 2) : 0.0,
        'secondes'       => $secondes,
        'sec_moyen'      => $traites ? (int)round($secondes / $traites) : 0,
    ];
}
