<?php
declare(strict_types=1);
/**
 * INTÉGRATION CRG — LE CŒUR DU MODULE DE STAGING.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CE MODULE N'ÉCRIT JAMAIS DANS LES DONNÉES MÉTIER DE MBI. Tout ce qu'il produit vit dans
 *    les cinq tables `crgi_*` et dans un dossier de staging. Ni `biens`, ni `bien_baux`, ni
 *    `crg_liens`, ni la GED ne sont touchés tant qu'Emmanuel n'a pas validé les six phases.
 *    C'est ce qui rend `ANNULER L'IMPORT` réellement possible : il n'y a rien à défaire
 *    ailleurs.
 *
 * ⚠️ `FICHIER PHYSIQUE ≠ CRG MÉTIER`. Un dépôt peut contenir un CRG, deux cents CRG, plusieurs
 *    agences et plusieurs mois. Aucune fonction d'ici ne suppose le contraire.
 *
 * ⚠️ UNE PHASE NE S'OUVRE QU'APRÈS VALIDATION DE LA PRÉCÉDENTE, et une validation porte
 *    l'empreinte du résultat validé. Si l'analyse est rejouée et que le résultat change, la
 *    validation ne vaut plus : `phase_validee()` le détecte au lieu de faire confiance à un
 *    drapeau posé la veille.
 *
 * ⚠️ FAIL CLOSED. Une analyse qui échoue laisse la phase en `BLOQUEE` avec son message. Elle
 *    ne rend jamais un bilan vide qui ressemblerait à « ce PDF ne contient aucun CRG ».
 */

require_once __DIR__ . '/bootstrap.php';

const CRGI_STAGING = __DIR__ . '/../data/crg_integration';
const CRGI_PHASES = [
    0 => 'DOCUMENTS / AGENCES / PÉRIODES',
    1 => 'INVENTAIRE CRG',
    2 => 'PATRIMOINE',
    3 => 'LOCATAIRES / OCCUPATION',
    4 => 'FINANCES',
    5 => 'BILAN AVANT INTÉGRATION',
];
const CRGI_STATUTS_VIVANTS = ['ANALYSE EN COURS', 'A VALIDER', 'VALIDE PARTIELLEMENT',
                              'PRET A INTEGRER'];

/**
 * LES CRG QUI PORTENT L'INFORMATION — condition SQL, écrite une seule fois.
 *
 * ⚠️ TOUTES LES PHASES FILTRAIENT SUR `= "UNIQUE"`, ET C'ÉTAIT UNE PERTE MUETTE. Un compte
 *    rendu marqué « MEME CLE CONTENU DIFFERENT » n'est PAS un doublon : c'est le cas
 *    FOCH/SABY, deux documents de même clé qui ne partagent qu'une page de contenu sur trois —
 *    donc COMPLÉMENTAIRES. Le filtre les écartait de l'inventaire, du patrimoine, des
 *    occupations et de l'argent : le CRG entier disparaissait, et seul un écart de couverture
 *    de deux unités le signalait, tout au bout de la chaîne.
 *
 * ⚠️ SEULE LA RÉÉNONCIATION S'EXCLUT. `RÉIMPRESSION ≠ NOUVEL ÉVÉNEMENT` : celle-là énonce deux
 *    fois le même fait, et la compter doublerait l'argent. Les autres portent de l'information
 *    que personne d'autre ne porte.
 */
const CRGI_CRG_PORTEURS = 'doublon_statut <> "REENONCIATION"';

/**
 * L'ÉTAT D'UNE PIÈCE DÉPOSÉE — quatre états, parce qu'ils appellent quatre ACTIONS.
 *
 * ⚠️ « ILLISIBLE » LES CONFONDAIT TOUS, ET ALARMAIT À TORT. Sur un corpus réel, cinq pièces
 *    l'ont porté : trois numérisations sans couche texte, une lettre parfaitement lue, et
 *    aucune vraiment illisible. Le mot envoyait chercher une panne du moteur là où il fallait
 *    lancer un OCR ou simplement ranger un document. Ce qu'un état doit dire, c'est QUOI FAIRE.
 */
const CRGI_ETATS_PIECE = [
    'ANALYSEE'           => 'pieces',              // texte exploitable, CRG reconstruits
    'OCR REQUIS'         => 'ocr_requis',          // lisible par l'œil, pas par la machine
    'HORS CRG'           => 'hors_crg',            // lu et nommé — ce n'est pas un CRG
    'STRUCTURE INCONNUE' => 'structure_inconnue',  // lisible, grammaire inconnue : à analyser
    'ILLISIBLE'          => 'illisibles',          // la source elle-même ne se lit pas
];

/**
 * CE QUE LE MOTEUR DIT QUAND IL N'A PAS PU LIRE — traduit en état, donc en ACTION.
 *
 * ⚠️ UNE CAUSE ABSENTE OU INCONNUE RETOMBE SUR `ILLISIBLE`, le plus alarmant. On ne minimise
 *    jamais ce qu'on ne comprend pas : `NOUVEAUTÉ ≠ EXCLUSION` vaut aussi dans ce sens-là.
 */
const CRGI_CAUSES_LECTURE = [
    'SANS COUCHE TEXTE' => 'OCR REQUIS',   // le fichier s'ouvre, ses pages sont des images
];

/** En deçà, la page ne porte pas de texte exploitable : c'est une image. */
const CRGI_SEUIL_TEXTE = 40;

/**
 * LE VOCABULAIRE DÉCLARÉ DU STAGING — `NOUVEAUTÉ ≠ EXCLUSION`.
 *
 * ⚠️ CETTE TABLE EXISTE PARCE QU'UN FILTRE ÉCRIT EN POSITIF DÉFINIT, SANS LE DIRE, TOUT
 *    L'UNIVERS AUTORISÉ. `doublon_statut = "UNIQUE"` a fait disparaître des comptes rendus
 *    entiers le jour où un troisième statut est apparu : ils n'étaient ni traités, ni exclus,
 *    ni arbitrés — ils n'étaient nulle part. Le défaut ne vient pas du filtre lui-même, mais
 *    de ce que personne ne remarque une valeur qu'aucun code ne nomme.
 *
 * ⚠️ CE N'EST PAS UNE CONTRAINTE, C'EST UN RÉVÉLATEUR. On n'interdit pas une valeur nouvelle :
 *    on exige qu'elle SE VOIE. Une valeur hors de cette table fait rougir le contrôle de
 *    cohérence, et c'est tout ce qu'on lui demande — être remarquée avant d'avoir coûté un
 *    document. La table s'AJOUTE, comme toutes les autres du module.
 */
const CRGI_VOCABULAIRE = [
    'crgi_piece.etat' => ['ANALYSEE', 'OCR REQUIS', 'HORS CRG', 'STRUCTURE INCONNUE',
                          'ILLISIBLE', 'DEPOSEE'],
    'crgi_crg.doublon_statut' => ['UNIQUE', 'REENONCIATION', 'MEME CLE CONTENU DIFFERENT'],
    // ⚠️ « COMPTE INCONNU » NOMMAIT À LA FOIS UN FAIT NORMAL ET UNE QUESTION. Emmanuel,
    //    07/09/2026 : « un compte non connu dans MBI n'est pas une erreur, c'est un
    //    nouveau ». Mesuré : 233 mandants nouveaux pour 6 homonymes — et je présentais les
    //    239 comme autant de questions. Le motif distinguait les deux ; le STATUT les
    //    fondait, et c'est le statut que lisent les écrans, les compteurs et les files.
    //    `NOUVEAU MANDANT` est un RÉSULTAT — reconnu à 100 %, reconnu comme nouveau, il ne
    //    va pas en arbitrage. `CODE PARTAGE` est une QUESTION.
    //
    // ⚠️ ET CE N'EST PAS UN « HOMONYME » — Emmanuel, 07/09/2026 : « c'est le N° de compte
    //    qui est identique, pas le nom ». Un homonyme partage un NOM ; ici deux mandants
    //    sans rapport portent le même NUMÉRO, chacun dans son espace de nommage, et dans
    //    son agence il est parfaitement connu. Nommer le phénomène par le nom faisait
    //    chercher une ressemblance de libellé là où il n'y a qu'une collision de code.
    'crgi_crg.inventaire_statut' => ['DEJA CONNUE', 'NOUVELLE', 'NOUVEAU MANDANT',
                                     'CODE PARTAGE', 'A VERIFIER'],
    'crgi_immeuble.statut' => ['IDENTIQUE', 'MODIFIE', 'NOUVEAU', 'A ARBITRER'],
    'crgi_lot.statut' => ['IDENTIQUE', 'MODIFIE', 'NOUVEAU', 'A ARBITRER'],
    'crgi_occupation.statut' => ['IDENTIQUE', 'NOUVEL ENTRANT', 'CHANGEMENT DE LOCATAIRE',
                                 'PARTI DEMONTRE', 'ANCIEN LOCATAIRE AVEC DETTE', 'A ARBITRER'],
    'crgi_mouvement.categorie' => ['LOYER APPELE', 'CHARGE APPELEE AU LOCATAIRE',
                                   'AUTRE APPELE AU LOCATAIRE', 'ENCAISSEMENT', 'ENCOURS',
                                   'CHARGE', 'FRAIS ET ASSURANCES', 'IMPOTS ET TAXES',
                                   'VERSEMENT PROPRIETAIRE', 'SOLDE',
                                   // Un mouvement de trésorerie INTERNE : un mandat crédité de
                                   // ce qu'un autre est débité, sans qu'un euro sorte de
                                   // l'agence. Ce n'est pas un versement au propriétaire.
                                   'COMPENSATION ENTRE MANDATS',
                                   'AGREGAT (NON ADDITIONNABLE)', 'DETAIL (NON ADDITIONNABLE)',
                                   'INDETERMINABLE'],
    // ⚠️ `NON RAPPROCHABLE` N'EST PAS `CANDIDAT NON DEMONTRABLE`. Le premier dit « aucune
    //    information n'existe, ni ici ni ailleurs » — une limite, qu'on nomme et qu'on ne pose
    //    à personne. Le second dit « il faut regarder la page pour trancher » — une question,
    //    qui va dans la file. Les confondre, c'était demander à Emmanuel de deviner.
    'crgi_mouvement.rapprochement' => ['DEJA PRESENT', 'NOUVEAU', 'CONTRADICTION',
                                       'CANDIDAT NON DEMONTRABLE', 'NON RAPPROCHABLE',
                                       'HORS PERIMETRE'],
    'crgi_plan.action' => ['CREER', 'METTRE A JOUR', 'ARCHIVER', 'INCHANGE', 'A ARBITRER',
                           'NON INTEGRABLE'],
];

/**
 * LES VALEURS QUE LE STAGING PORTE ET QUE PERSONNE N'A DÉCLARÉES.
 *
 * ⚠️ ON LES CHERCHE PARTOUT, PAS SEULEMENT LÀ OÙ ON LES ATTEND. Une valeur inconnue n'est pas
 *    forcément une erreur — c'est peut-être un phénomène nouveau, et c'est bien. Ce qui est
 *    inacceptable, c'est qu'elle passe inaperçue.
 */
function crgi_valeurs_non_declarees(PDO $pdo, int $importId): array
{
    $trouve = [];
    foreach (CRGI_VOCABULAIRE as $ou => $connues) {
        [$table, $colonne] = explode('.', $ou);
        $st = $pdo->prepare('SELECT DISTINCT `' . $colonne . '` FROM `' . $table . '`
                              WHERE import_id = ? AND `' . $colonne . '` IS NOT NULL
                                AND `' . $colonne . '` <> ""');
        $st->execute([$importId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) {
            if (!in_array($v, $connues, true)) {
                $trouve[] = ['ou' => $ou, 'valeur' => (string)$v];
            }
        }
    }
    return $trouve;
}

/** Le dossier de staging d'un import. Hors GED, hors arborescence métier. */
function crgi_dossier(int $importId): string
{
    return CRGI_STAGING . '/import_' . $importId;
}

/**
 * Ouvre un import. C'est le seul point d'entrée : rien n'existe hors d'un `import_id`.
 */
function crgi_creer_import(PDO $pdo, string $libelle, int $userId): int
{
    $st = $pdo->prepare(
        'INSERT INTO crgi_import (libelle, statut, cree_le, cree_par)
         VALUES (:libelle, :statut, NOW(), :user)'
    );
    $st->execute([
        ':libelle' => mb_substr(trim($libelle), 0, 200),
        ':statut'  => 'ANALYSE EN COURS',
        ':user'    => $userId ?: null,
    ]);
    $id = (int)$pdo->lastInsertId();
    @mkdir(crgi_dossier($id), 0775, true);
    foreach (array_keys(CRGI_PHASES) as $phase) {
        $pdo->prepare('INSERT IGNORE INTO crgi_phase (import_id, phase, statut)
                       VALUES (?, ?, ?)')->execute([$id, $phase, 'EN ATTENTE']);
    }
    return $id;
}

/**
 * Rattache un fichier déposé à l'import et compte ses pages.
 *
 * ⚠️ LE NOMBRE DE PAGES EST LU MAINTENANT, pas au moment de l'analyse. Sans lui, « 906 pages
 *    analysées » ne serait comparable à rien : on ne pourrait pas démontrer qu'aucune page
 *    n'a été perdue en route.
 */
function crgi_ajouter_piece(PDO $pdo, int $importId, string $chemin, string $nomOriginal): int
{
    if (!is_file($chemin)) {
        throw new RuntimeException('FICHIER ABSENT / DÉPÔT IMPOSSIBLE : ' . $nomOriginal);
    }
    $st = $pdo->prepare(
        'INSERT INTO crgi_piece
            (import_id, nom_original, sha256, taille_octets, nb_pages, chemin, etat, cree_le)
         VALUES (:import, :nom, :sha, :taille, :pages, :chemin, :etat, NOW())'
    );
    $st->execute([
        ':import' => $importId,
        ':nom'    => mb_substr($nomOriginal, 0, 255),
        ':sha'    => hash_file('sha256', $chemin),
        ':taille' => filesize($chemin) ?: 0,
        ':pages'  => crgi_compter_pages($chemin),
        ':chemin' => $chemin,
        ':etat'   => 'DEPOSEE',
    ]);
    $pieceId = (int)$pdo->lastInsertId();
    crgi_recompter($pdo, $importId);
    return $pieceId;
}

/**
 * Le nombre de pages d'un PDF, sans le charger en mémoire.
 *
 * ⚠️ ZÉRO N'EST PAS UNE RÉPONSE ACCEPTABLE : un PDF illisible doit se voir. On lève plutôt que
 *    de laisser un dépôt vide passer pour un document sans page.
 */
function crgi_compter_pages(string $chemin): int
{
    $exe = crgi_binaire('pdfinfo');
    if ($exe) {
        $sortie = @shell_exec(escapeshellarg($exe) . ' ' . escapeshellarg($chemin) . ' 2>&1');
        if ($sortie && preg_match('/^Pages:\s+(\d+)/mi', $sortie, $m)) {
            return (int)$m[1];
        }
    }
    // Repli : compter les objets /Type /Page du fichier brut.
    $contenu = (string)@file_get_contents($chemin);
    $n = preg_match_all('#/Type\s*/Page[^s]#', $contenu);
    if ($n > 0) {
        return $n;
    }
    throw new RuntimeException('PDF ILLISIBLE / NOMBRE DE PAGES INDÉTERMINABLE : '
                             . basename($chemin));
}

/** Retrouve un binaire poppler, sur Windows comme sur Linux. */
/**
 * LE CONTRAT DE LECTURE, LU À LA SOURCE — jamais recopié.
 *
 * ⚠️ DEUX EXEMPLAIRES D'UNE MÊME RÈGLE DIVERGENT TOUJOURS, ET C'EST L'EXEMPLAIRE OUBLIÉ QUI
 *    PARLE À L'ÉCRAN. PHP portait sa propre idée du bon lecteur — « poppler » — et a continué
 *    à l'afficher pendant que le moteur Python travaillait sous un autre contrat. On demande
 *    donc au moteur ce qu'il exige, on ne le redéclare pas ici.
 */
function crgi_lecteur_contrat(): array
{
    static $contrat = null;
    if ($contrat !== null) {
        return $contrat;
    }
    $py = getenv('CRG_PYTHON') ?: (crgi_binaire('python') ?: 'python');
    // ⚠️ PAS UN SEUL GUILLEMET DOUBLE DANS CE CODE. Sous Windows, `escapeshellarg` SUPPRIME
    //    les guillemets doubles au lieu de les échapper : le `r"…"` du chemin partait, Python
    //    recevait une syntaxe cassée, et le contrat revenait « illisible » — l'écran affichant
    //    alors un avertissement encore plus faux que celui qu'on venait de corriger.
    $code = "import sys,json;sys.path.insert(0,'" . str_replace('\\', '/', dirname(__DIR__))
          . "/scripts');import crg_integration_phase0 as P;"
          . 'sys.stdout.write(json.dumps(P.CRG_LECTEUR_CONTRAT))';
    $out = @shell_exec(escapeshellarg($py) . ' -c ' . escapeshellarg($code) . ' 2>&1');
    $lu = json_decode(trim((string)$out), true);
    // Un contrat illisible ne doit pas rendre l'écran muet : on le dit, et on n'invente rien.
    $contrat = is_array($lu) && isset($lu['produit'])
        ? $lu
        : ['produit' => '(contrat illisible)', 'mode' => '(inconnu)', 'version' => ''];
    return $contrat;
}

function crgi_binaire(string $nom): ?string
{
    $cmd = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
    $out = @shell_exec($cmd . ' ' . escapeshellarg($nom) . ' 2>&1');
    if (!$out) {
        return null;
    }
    $premier = trim(explode("\n", trim($out))[0]);
    return is_file($premier) ? $premier : null;
}

/** Tient à jour les compteurs de l'import : ils servent de contrôle, pas de décoration. */
function crgi_recompter(PDO $pdo, int $importId): void
{
    $pdo->prepare(
        'UPDATE crgi_import i SET
            i.nb_pieces = (SELECT COUNT(*) FROM crgi_piece p WHERE p.import_id = i.id),
            i.nb_pages  = (SELECT COALESCE(SUM(p.nb_pages),0) FROM crgi_piece p
                           WHERE p.import_id = i.id)
         WHERE i.id = ?'
    )->execute([$importId]);
}

/**
 * PHASE 0 — reconstruire les CRG logiques de chaque pièce.
 *
 * ⚠️ ON EFFACE LE RÉSULTAT PRÉCÉDENT DE CETTE PHASE AVANT DE REJOUER. Sans cela, deux analyses
 *    successives empileraient deux découpages du même PDF et « 1 812 pages » apparaîtrait sur
 *    un document qui en compte 906.
 */
function crgi_phase0(PDO $pdo, int $importId): array
{
    crgi_verrouiller_import($pdo, $importId, 0);
    crgi_marquer_phase($pdo, $importId, 0, 'EN ANALYSE', null);
    $pdo->prepare('DELETE FROM crgi_page WHERE import_id = ?')->execute([$importId]);
    $pdo->prepare('DELETE FROM crgi_crg  WHERE import_id = ?')->execute([$importId]);

    $pieces = $pdo->prepare('SELECT * FROM crgi_piece WHERE import_id = ? ORDER BY id');
    $pieces->execute([$importId]);
    $pieces = $pieces->fetchAll(PDO::FETCH_ASSOC);
    if (!$pieces) {
        crgi_marquer_phase($pdo, $importId, 0, 'BLOQUEE', 'Aucune pièce déposée.');
        throw new RuntimeException('AUCUNE PIÈCE / ANALYSE IMPOSSIBLE');
    }

    $insCrg = $pdo->prepare(
        'INSERT INTO crgi_crg (import_id, piece_id, page_debut, page_fin, agence, format,
                               periode_cle, periode_debut, periode_fin, date_arrete,
                               proprietaire, compte, immeuble, certitude, motif,
                               empreinte, caracteres_lus, cree_le)
         VALUES (:import,:piece,:pd,:pf,:agence,:format,:cle,:debut,:fin,:arrete,
                 :proprio,:compte,:immeuble,:certitude,:motif,:emp,:car,NOW())'
    );
    $insPage = $pdo->prepare(
        'INSERT INTO crgi_page (import_id, piece_id, page_no, crg_id, signal_page, cree_le)
         VALUES (?,?,?,?,?,NOW())'
    );

    // ⚠️ L'OUTIL DE LECTURE FAIT PARTIE DU RÉSULTAT. Le moteur le rend depuis toujours ; on le
    //    jetait. Le 02/09/2026, Apache n'avait pas `pdftotext` sur son PATH : la lecture est
    //    tombée sur le repli pdfplumber, qui APLATIT LES COLONNES. Le découpage est resté
    //    parfait — 325 CRG, 0 écart — mais 288 propriétaires sur 325 ont pris l'en-tête de
    //    l'agence pour un nom, et l'écran affichait « ANALYSÉE · à valider » sans un mot.
    //    Une lecture dégradée qui ne se voit pas est pire qu'une lecture impossible.
    $bilan = ['pieces' => 0, 'pages' => 0, 'affectees' => 0, 'crgs' => 0, 'illisibles' => [],
              'ocr_requis' => [], 'hors_crg' => [], 'structure_inconnue' => [],
              'lecteurs' => [], 'lecture_degradee' => null];
    foreach ($pieces as $p) {
        $res = crgi_lancer_phase0((string)$p['chemin']);
        if (isset($res['erreur'])) {
            // ⚠️ LA CAUSE COMMANDE L'ÉTAT, ET DONC L'ACTION. Quatre numérisations d'un dépôt
            //    portaient « ILLISIBLE — la source elle-même ne se lit pas » alors que le
            //    moteur avait parfaitement ouvert le fichier et compté ses pages : elles
            //    n'ont simplement pas de couche texte. Le mot envoyait chercher une panne du
            //    moteur là où il fallait lancer un OCR. Une cause inconnue retombe sur
            //    `ILLISIBLE` — le plus alarmant — parce qu'on ne minimise jamais ce qu'on ne
            //    comprend pas.
            $etat = CRGI_CAUSES_LECTURE[(string)($res['cause'] ?? '')] ?? 'ILLISIBLE';
            $pdo->prepare('UPDATE crgi_piece SET etat = ?, message = ? WHERE id = ?')
                ->execute([$etat, mb_substr($res['erreur'], 0, 500), (int)$p['id']]);
            $bilan[CRGI_ETATS_PIECE[$etat] ?? 'illisibles'][] = $p['nom_original'];
            continue;
        }
        $idsCrg = [];
        foreach ($res['crgs'] as $c) {
            $insCrg->execute([
                ':import' => $importId, ':piece' => (int)$p['id'],
                ':pd' => (int)$c['page_debut'], ':pf' => (int)$c['page_fin'],
                ':agence' => $c['agence'], ':format' => $c['format'],
                ':cle' => $c['periode_cle'], ':debut' => $c['periode_debut'],
                ':fin' => $c['periode_fin'], ':arrete' => $c['date_arrete'],
                ':proprio' => $c['proprietaire'], ':compte' => $c['compte'],
                ':immeuble' => $c['immeuble'] ?? null,
                ':certitude' => $c['certitude'], ':motif' => mb_substr((string)$c['motif'], 0, 500),
                ':emp' => $c['empreinte'] ?? null, ':car' => (int)($c['caracteres_lus'] ?? 0),
            ]);
            $idsCrg[] = (int)$pdo->lastInsertId();
        }
        foreach ($res['pages'] as $pg) {
            $idx = $pg['crg_index'];
            $insPage->execute([
                $importId, (int)$p['id'], (int)$pg['page_no'],
                $idx === null ? null : ($idsCrg[$idx] ?? null),
                mb_substr((string)$pg['signal'], 0, 120),
            ]);
        }
        // ⚠️ ZÉRO CRG SUR UN DOCUMENT NON VIDE EST UNE PANNE, PAS UN CONSTAT. Le vrai document
        //    de 906 pages a été présenté « ANALYSÉE · à valider » avec zéro CRG détecté :
        //    l'écran montrait un import sain là où la lecture avait totalement échoué. C'est
        //    la faute que la doctrine FAIL CLOSED interdit depuis P7, et je l'ai commise ici.
        // ⚠️ ET « ILLISIBLE » CONFONDAIT TROIS ÉTATS SOUS UN SEUL MOT ALARMANT. Sur le corpus
        //    LYON, cinq pièces l'ont porté : trois n'ont AUCUNE COUCHE TEXTE (des scans, à
        //    océriser), une était une lettre d'acompte parfaitement lue, et aucune n'était
        //    illisible au sens propre. Le mot envoyait chercher une panne là où il fallait
        //    lancer un OCR ou simplement classer un document. Trois états, trois noms.
        if ((int)$res['stats']['crg_detectes'] === 0 && (int)$res['nb_pages'] > 0) {
            $caracteres = (int)($res['stats']['caracteres'] ?? 0);
            $joints = [];
            foreach ($res['pages'] as $pg) {
                if (str_contains((string)($pg['signal'] ?? ''), '—')) {
                    $joints[explode(' —', (string)$pg['signal'])[0]] = true;
                }
            }
            // ⚠️ QUATRE ÉTATS, PARCE QU'ILS APPELLENT QUATRE ACTIONS DIFFÉRENTES : océriser,
            //    classer, analyser une structure neuve, ou réparer une source. Les confondre
            //    sous le mot le plus alarmant fait perdre le seul qui devait alarmer.
            if ($caracteres < CRGI_SEUIL_TEXTE) {
                $etat = 'OCR REQUIS';
                $msg = 'AUCUNE COUCHE TEXTE — ' . (int)$res['nb_pages'] . ' page(s) parcourue(s), '
                     . $caracteres . ' caractère(s) extractible(s). Ce n’est pas un document '
                     . 'illisible : c’est une NUMÉRISATION. Elle demande un OCR, pas une '
                     . 'correction du moteur. Le document reste compté, jamais perdu.';
            } elseif ($joints) {
                $etat = 'HORS CRG';
                $msg = 'DOCUMENT HORS CRG — ' . implode(', ', array_keys($joints)) . '. Le document '
                     . 'a été lu entièrement ; il n’est simplement pas un compte rendu de gestion. '
                     . '`LISIBLE ≠ CRG`, et un document joint n’est pas un document à ignorer.';
            } else {
                $etat = 'STRUCTURE INCONNUE';
                $msg = 'STRUCTURE NON RECONNUE — ' . $caracteres . ' caractères lus sur '
                     . (int)$res['nb_pages'] . ' page(s), et aucun signal connu. Le document est '
                     . 'techniquement lisible : c’est sa GRAMMAIRE que le moteur ne connaît pas '
                     . 'encore. Cela relève de l’analyse d’une structure neuve, pas d’une panne.';
            }
            $pdo->prepare('UPDATE crgi_piece SET etat = ?, nb_pages = ?, message = ? WHERE id = ?')
                ->execute([$etat, (int)$res['nb_pages'], $msg, (int)$p['id']]);
            $bilan[CRGI_ETATS_PIECE[$etat]][] = $p['nom_original'];
            continue;
        }
        $pdo->prepare('UPDATE crgi_piece SET etat = ?, nb_pages = ?, message = NULL WHERE id = ?')
            ->execute(['ANALYSEE', (int)$res['nb_pages'], (int)$p['id']]);
        $bilan['pieces']++;
        $bilan['pages'] += (int)$res['stats']['pages_analysees'];
        $bilan['affectees'] += (int)$res['stats']['pages_affectees'];
        $bilan['crgs'] += (int)$res['stats']['crg_detectes'];
        $bilan['lecteurs'][(string)($res['outil'] ?? 'inconnu')] = true;
    }
    crgi_recompter($pdo, $importId);

    // ⚠️ SEUL `pdftotext -layout` PRÉSERVE LES COLONNES, et le nom du propriétaire est
    //    précisément dans la colonne de droite. Tout autre lecteur donne un document
    //    LISIBLE MAIS APLATI : les phases suivantes tourneront, les chiffres seront justes,
    //    et les identités seront muettes. On le dit ici, en toutes lettres, plutôt que de
    //    laisser Emmanuel le découvrir au rapprochement.
    // ⚠️ « pdftotext » NE DÉSIGNE PAS UN PROGRAMME, MAIS AU MOINS DEUX. Poppler et Xpdf
    //    portent ce nom, ne rendent pas les colonnes de la même façon, et seul poppler
    //    connaît `-table` — dont la phase 3 tire ses rattachements. Tant que l'étiquette
    //    n'était pas remontée, la même analyse lancée depuis la page et depuis le harnais
    //    donnait deux empreintes, et personne ne pouvait dire pourquoi.
    // ⚠️ CE CONTRÔLE COMPARE AU CONTRAT, PLUS À UN PRODUIT PRÉFÉRÉ. Il exigeait « poppler » —
    //    une préférence héritée de l'époque où le corpus certifié avait été lu avec lui. Le
    //    03/09/2026, la mesure a établi que les deux extracteurs lisent identiquement en
    //    `-layout`, que la vraie différence était le MODE, et le contrat a été porté sur
    //    Xpdf. L'avertissement continuait pourtant à réclamer poppler : il annonçait une
    //    « lecture dégradée » sur le lecteur officiel, et envoyait chercher un défaut qui
    //    n'existe pas. Un avertissement faux use plus vite qu'il n'alerte.
    $attendu = crgi_lecteur_contrat();
    $degrades = array_filter(
        array_keys($bilan['lecteurs']),
        static fn($l) => stripos($l, (string)$attendu['produit']) === false
    );
    if ($degrades) {
        $bilan['lecture_degradee'] =
            'LECTEUR HORS CONTRAT — ' . implode(', ', $degrades) . '. Le contrat de lecture '
            . 'exige « ' . $attendu['produit'] . ' » en mode « ' . $attendu['mode'] . ' ». Un '
            . 'autre extracteur ne rend pas les mêmes colonnes : l’IDENTIFICATION (nom du '
            . 'propriétaire) et les RATTACHEMENTS peuvent en dépendre. Installer le lecteur '
            . 'déclaré, ou désigner son binaire par `CRG_PDFTOTEXT`, puis relancer la phase 0.';
    }

    if ($bilan['pieces'] === 0) {
        // ⚠️ ON DIT CE QUI MANQUE, ÉTAT PAR ÉTAT. « Aucune pièce lisible » sur un dépôt de
        //    scans envoyait chercher un défaut du moteur ; il fallait lancer un OCR.
        $detail = array_filter([
            $bilan['illisibles'] ? 'illisibles : ' . implode(', ', $bilan['illisibles']) : '',
            $bilan['ocr_requis'] ? 'OCR requis : ' . implode(', ', $bilan['ocr_requis']) : '',
            $bilan['hors_crg'] ? 'hors CRG : ' . implode(', ', $bilan['hors_crg']) : '',
            $bilan['structure_inconnue'] ? 'structure inconnue : '
                                           . implode(', ', $bilan['structure_inconnue']) : '',
        ]);
        crgi_marquer_phase($pdo, $importId, 0, 'BLOQUEE',
            'Aucun compte rendu de gestion dans ce dépôt — ' . implode(' · ', $detail));
        throw new RuntimeException('AUCUN CRG DANS LE DÉPÔT / ANALYSE IMPOSSIBLE');
    }
    // ⚠️ LA PHASE 0 DOIT RÉPONDRE ENTIÈREMENT : quel CRG, quelle agence, quelle période, quel
    //    compte, quelles pages. Renvoyer l'agence à la phase 1 reviendrait à valider un
    //    découpage documentaire sans avoir terminé l'identification documentaire.
    crgi_rapprocher_agences($pdo, $importId);
    crgi_qualifier_doublons($pdo, $importId);
    // ⚠️ CETTE QUALIFICATION N'AVAIT AUCUN APPELANT. Elle n'avait jamais tourné qu'à la main :
    //    le premier replay de la phase 0 l'a donc silencieusement perdue, et 18 CRG sont
    //    restés bloqués sur « MÊME CLÉ, CONTENU DIFFÉRENT » — ni uniques, ni réénonciations,
    //    donc invisibles pour toutes les phases suivantes. Une étape qui ne tourne qu'à la
    //    main n'existe pas : elle appartient au moteur.
    crgi_qualifier_collisions($pdo, $importId);
    // Le message de la phase porte l'alerte de lecture dégradée quand il y en a une : c'est
    // lui que l'écran affiche sous le bilan de la phase 0.
    crgi_marquer_phase($pdo, $importId, 0, 'A VALIDER', $bilan['lecture_degradee']);
    $pdo->prepare("UPDATE crgi_import SET statut = 'A VALIDER' WHERE id = ? AND statut = 'ANALYSE EN COURS'")
        ->execute([$importId]);
    return $bilan;
}

/**
 * Appelle le moteur de découpe. Python fait le travail lourd, PHP ne le refait pas.
 *
 * ⚠️ UN SEUL PIPELINE. Le même script servira depuis l'administration MBI et depuis le VPS :
 *    construire deux découpeurs, c'est se garantir qu'ils divergeront.
 */
function crgi_lancer_phase0(string $pdf): array
{
    $python = getenv('CRG_PYTHON') ?: (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
    $script = realpath(__DIR__ . '/../scripts/crg_integration_phase0.py');
    if (!$script) {
        return ['erreur' => 'MOTEUR ABSENT : scripts/crg_integration_phase0.py'];
    }
    $cmd = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($pdf);
    $sortie = @shell_exec($cmd . ' 2>&1');
    if (!is_string($sortie) || trim($sortie) === '') {
        return ['erreur' => 'MOTEUR MUET — Python introuvable ou script en échec. '
                          . 'Définir CRG_PYTHON si nécessaire.'];
    }
    $json = json_decode(trim($sortie), true);
    if (!is_array($json)) {
        // ⚠️ ON REND LA SORTIE BRUTE. Un « erreur d'analyse » sans le message du moteur oblige
        //    à relancer à la main pour comprendre : autant le dire tout de suite.
        return ['erreur' => 'SORTIE MOTEUR ILLISIBLE : ' . mb_substr(trim($sortie), 0, 400)];
    }
    return $json;
}

/**
 * Écrit l'état d'une phase. Le message dit toujours pourquoi, quand il y a un pourquoi.
 *
 * ⚠️ LA DURÉE EST CELLE DU CALCUL, PAS CELLE DE LA JOURNÉE. Le tableau de bord déduisait le
 *    « temps machine » de l'écart entre le dépôt et la dernière validation : il annonçait
 *    9 h 21 pour quelques minutes d'analyse et des heures de relecture humaine. On mesure donc
 *    ici, et `NULL` reste `NULL` quand personne n'a mesuré — `ABSENCE ≠ ZÉRO`.
 */
function crgi_marquer_phase(PDO $pdo, int $importId, int $phase, string $statut, ?string $msg,
                            ?int $secondes = null): void
{
    // ⚠️ LE CHRONOMÈTRE VIT ICI, PAS DANS QUATORZE APPELANTS. Chaque phase passe par « EN
    //    ANALYSE » puis par son verdict, dans le même processus : c'est le seul endroit qui
    //    voit les deux bouts. Le mesurer aux appelants aurait demandé quatorze modifications
    //    et en aurait oublié une — celle-là précisément n'aurait jamais eu de durée.
    static $depart = [];
    $cle = $importId . ':' . $phase;
    if ($statut === 'EN ANALYSE') {
        $depart[$cle] = microtime(true);
    } elseif ($secondes === null && isset($depart[$cle])) {
        $secondes = (int)round(microtime(true) - $depart[$cle]);
        unset($depart[$cle]);
    }
    $pdo->prepare(
        'INSERT INTO crgi_phase (import_id, phase, statut, message, analyse_le, secondes_machine)
         VALUES (:i,:p,:s,:m,NOW(),:sec)
         ON DUPLICATE KEY UPDATE statut = :s2, message = :m2, analyse_le = NOW(),
                                 secondes_machine = COALESCE(:sec2, secondes_machine)'
    )->execute([':i' => $importId, ':p' => $phase, ':s' => $statut, ':m' => $msg,
                ':s2' => $statut, ':m2' => $msg, ':sec' => $secondes, ':sec2' => $secondes]);
}

/**
 * UN SEUL TRAITEMENT À LA FOIS SUR UN IMPORT.
 *
 * ⚠️ CE VERROU EXISTE À CAUSE D'UNE MESURE FAUSSE. Le 04/09/2026, deux exécutions ont travaillé
 *    sur le même dépôt : la seconde a purgé et recommencé la phase 4 pendant qu'on lisait le
 *    résultat de la première. **14 624 mouvements sont devenus 1 917** — et la phase affichait
 *    toujours « VALIDÉE », avec une empreinte calculée sur l'état complet. Rien, nulle part, ne
 *    disait que le chiffre lu n'était pas le chiffre produit.
 *
 * ⚠️ CE N'EST PAS UN ACCIDENT D'OUTILLAGE : deux administrateurs qui relancent la même phase
 *    depuis l'écran produisent exactement cela. Une phase efface avant d'écrire ; deux phases
 *    qui s'entrelacent laissent un état qu'aucune des deux n'a produit, et que le sceau
 *    couvre sans le savoir.
 *
 * Le verrou est tenu par la CONNEXION : il se relâche seul si le processus meurt, et il est
 * réentrant — les cinq phases d'un même processus le reprennent sans se bloquer elles-mêmes.
 */
function crgi_verrouiller_import(PDO $pdo, int $importId, int $phase): void
{
    $st = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $st->execute(['crgi_import_' . $importId]);
    if ((int)$st->fetchColumn() !== 1) {
        throw new RuntimeException(
            "IMPORT {$importId} DÉJÀ EN TRAITEMENT — la phase {$phase} n'a pas été lancée. "
            . "Un autre traitement écrit en ce moment sur ce dépôt ; deux exécutions "
            . "simultanées produisent un état qu'aucune des deux n'a voulu."
        );
    }
}

/**
 * L'empreinte du résultat d'une phase — ce sur quoi porte la validation humaine.
 *
 * ⚠️ SANS ELLE, UNE VALIDATION SERAIT UN DRAPEAU, PAS UN ENGAGEMENT. Rejouer l'analyse après
 *    coup changerait le découpage sans que la mention « VALIDÉE » ne bouge d'un pixel.
 */
function crgi_empreinte_phase0(PDO $pdo, int $importId): string
{
    // ⚠️ LE NOM DU PROPRIÉTAIRE FAIT PARTIE DE L'IDENTIFICATION, DONC DU SCEAU. Il n'y était
    //    pas : le 02/09/2026, 288 noms faux sont devenus 288 noms justes sans que l'empreinte
    //    de la phase 0 bouge d'un caractère. Une phase validée sur une identification fausse
    //    serait restée « VALIDÉE » après correction, et l'inverse tout autant. La phase 0
    //    répond « quel CRG, quelle agence, quelle période, quel compte, QUI » : son sceau
    //    doit couvrir les cinq réponses, pas quatre.
    $st = $pdo->prepare(
        'SELECT piece_id, page_debut, page_fin, COALESCE(agence,""), COALESCE(compte,""),
                COALESCE(periode_cle,""), certitude, COALESCE(proprietaire,"")
           FROM crgi_crg WHERE import_id = ? ORDER BY piece_id, page_debut'
    );
    $st->execute([$importId]);
    $lignes = [];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) {
        $lignes[] = implode('|', $r);
    }
    return hash('sha256', implode("\n", $lignes));
}

/** Une phase est-elle validée, et sa validation vaut-elle encore pour le résultat actuel ? */
function crgi_phase_validee(PDO $pdo, int $importId, int $phase): array
{
    $st = $pdo->prepare('SELECT * FROM crgi_phase WHERE import_id = ? AND phase = ?');
    $st->execute([$importId, $phase]);
    $ligne = $st->fetch(PDO::FETCH_ASSOC) ?: ['statut' => 'EN ATTENTE', 'resultat_sha' => null];
    if (($ligne['statut'] ?? '') !== 'VALIDEE') {
        return ['validee' => false, 'perimee' => false, 'ligne' => $ligne];
    }
    $actuelle = match ($phase) {
        0 => crgi_empreinte_phase0($pdo, $importId),
        1 => crgi_empreinte_phase1($pdo, $importId),
        2 => crgi_empreinte_phase2($pdo, $importId),
        3 => crgi_empreinte_phase3($pdo, $importId),
        4 => crgi_empreinte_phase4($pdo, $importId),
        5 => crgi_empreinte_phase5($pdo, $importId),
        default => (string)$ligne['resultat_sha'],
    };
    return [
        'validee' => true,
        'perimee' => $actuelle !== (string)$ligne['resultat_sha'],
        'ligne'   => $ligne,
    ];
}

/** Valide une phase, en scellant l'empreinte du résultat examiné. */
/**
 * L'empreinte du résultat de la phase 1 — l'inventaire tel qu'il a été examiné.
 *
 * ⚠️ UNE VALIDATION PORTE SUR UN RÉSULTAT, PAS SUR UNE PHASE. Si l'inventaire est rejoué et
 *    qu'un verdict change, la validation ne vaut plus : `phase_validee()` le détecte, au lieu
 *    de faire confiance à un drapeau posé la veille.
 */
function crgi_empreinte_phase1(PDO $pdo, int $importId): string
{
    $st = $pdo->prepare(
        'SELECT id, COALESCE(inventaire_statut,""), COALESCE(mbi_trimestre_id,0),
                COALESCE(doublon_statut,""), COALESCE(doublon_qualification,"")
           FROM crgi_crg WHERE import_id = ? ORDER BY id'
    );
    $st->execute([$importId]);
    $lignes = [];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) {
        $lignes[] = implode('|', $r);
    }
    return hash('sha256', implode("
", $lignes));
}

function crgi_valider_phase(PDO $pdo, int $importId, int $phase, int $userId): void
{
    // ⚠️ AUCUNE PHASE 0 VALIDABLE AVEC UNE AGENCE OU UNE PÉRIODE INDÉTERMINABLE. La règle est
    //    d'Emmanuel, et elle est juste : signer un découpage dont on ne sait pas de quelle
    //    agence ni de quel mois il relève, c'est signer une page à moitié écrite. Le refus
    //    porte le décompte, pour qu'on sache tout de suite ce qui bloque.
    if ($phase === 0) {
        $b = crgi_bilan_phase0($pdo, $importId);
        if ($b['bloquants'] > 0) {
            throw new RuntimeException(sprintf(
                'IDENTIFICATION INCOMPLÈTE — %d CRG sans agence établie et %d sans période. '
                . 'La phase 0 doit répondre à « quelle agence, quelle période » avant '
                . 'validation : ces lignes demandent un arbitrage.',
                (int)($b['agence_source']['INDETERMINABLE'] ?? 0),
                (int)($b['periode_source']['INDETERMINABLE'] ?? 0)
            ));
        }
    }
    $sha = match ($phase) {
        0 => crgi_empreinte_phase0($pdo, $importId),
        1 => crgi_empreinte_phase1($pdo, $importId),
        2 => crgi_empreinte_phase2($pdo, $importId),
        3 => crgi_empreinte_phase3($pdo, $importId),
        4 => crgi_empreinte_phase4($pdo, $importId),
        5 => crgi_empreinte_phase5($pdo, $importId),
        default => '',
    };
    $pdo->prepare(
        'UPDATE crgi_phase SET statut = "VALIDEE", resultat_sha = ?, valide_le = NOW(),
                valide_par = ? WHERE import_id = ? AND phase = ?'
    )->execute([$sha, $userId ?: null, $importId, $phase]);
    $pdo->prepare("UPDATE crgi_import SET statut = 'VALIDE PARTIELLEMENT'
                   WHERE id = ? AND statut IN ('ANALYSE EN COURS','A VALIDER')")
        ->execute([$importId]);
}

/**
 * LE SYSTÈME MBI QUE DÉSIGNE LA FAMILLE LUE SUR LE DOCUMENT.
 *
 * ⚠️ DEUX ÉDITEURS, QUATRE AGENCES. **ICS** imprime les CRG de LOCA IMMO LYON et d'EMERY IMMO ;
 *    **SPI** ceux de VIENNE, et de CHAPONOST le jour où ses CRG existeront. `proprietaire_comptes_crg.systeme`
 *    nomme l'agence, pas l'éditeur : la correspondance est donc explicite, et non devinée.
 */
const CRGI_SYSTEME_DU_FORMAT = [
    'lyon'       => 'loca_immo_lyon',
    'emery_immo' => 'emery_immo',
    'septeo_spi' => 'septeo_spi',
];

/**
 * LES TABLES DE STAGING QUI PORTENT UN IMPORT — DÉDUITES DU SCHÉMA, JAMAIS ÉNUMÉRÉES.
 *
 * ⚠️ UNE LISTE ÉCRITE À LA MAIN NE SURVIT PAS À LA MIGRATION SUIVANTE. `crgi_annuler` en
 *    tenait une, arrêtée aux quatre tables du premier jour ; six migrations ont ajouté
 *    `crgi_immeuble`, `crgi_lot`, `crgi_occupation`, `crgi_mouvement`, `crgi_plan` et
 *    `crgi_arbitrage` sans que personne ne pense à l'allonger. Un import « annulé » laissait
 *    donc derrière lui des milliers de lignes d'analyse — et l'écran affichait ANNULÉ.
 *
 * ⚠️ IL N'Y A AUCUNE CLÉ ÉTRANGÈRE ENTRE LES TABLES `crgi_*` : rien ne cascade, rien ne
 *    rattrape un oubli. La seule règle sûre est « toute table `crgi_` qui porte `import_id`
 *    appartient à un import et part avec lui ». `crgi_import` n'en porte pas : elle survit,
 *    c'est elle qui garde la trace de l'annulation.
 */
function crgi_tables_de_staging(PDO $pdo): array
{
    $tables = $pdo->query(
        "SELECT TABLE_NAME
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND COLUMN_NAME  = 'import_id'
            AND TABLE_NAME LIKE 'crgi\\_%'
          ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);

    return array_values(array_filter($tables, static fn($t) => $t !== 'crgi_import'));
}

/**
 * L'IMPORT SUR LEQUEL LES TESTS TRAVAILLENT, QUAND ON NE LEUR EN DÉSIGNE AUCUN.
 *
 * ⚠️ LES SUITES ÉCRIVAIENT `?? 5` EN DUR. Le jour où l'import 5 a été annulé, elles ont
 *    continué à s'exécuter — sur un import vide, donc sans rien contrôler du tout. Un
 *    harnais qui verdit sur du néant est pire qu'un harnais rouge.
 */
function crgi_import_courant(PDO $pdo): int
{
    return (int)$pdo->query(
        "SELECT id FROM crgi_import WHERE statut <> 'ANNULE' ORDER BY id DESC LIMIT 1"
    )->fetchColumn();
}

/**
 * L'IMPORT DE RÉFÉRENCE POUR LES CONTRÔLES — le plus AVANCÉ, pas le plus RÉCENT.
 *
 * ⚠️ LE HARNAIS SUIVAIT « L'IMPORT COURANT », C'EST-À-DIRE LE DERNIER DÉPOSÉ. Tant qu'un seul
 *    dépôt vivait à la fois, cela revenait au même. Dès qu'une passe d'apprentissage a créé un
 *    nouvel import — analysé jusqu'à la phase 0 et pas au-delà —, huit contrôles ont viré au
 *    rouge : ils cherchaient des mouvements, des arbitrages et des sceaux dans un dépôt qui
 *    n'en a pas encore. Le moteur n'avait rien fait de mal ; l'instrument regardait ailleurs.
 *
 * ⚠️ « AVANCÉ » SE MESURE, IL NE SE SUPPOSE PAS : c'est le nombre de phases validées, puis, à
 *    égalité, l'import le plus récent. Un contrôle a besoin d'un dépôt COMPLET pour dire quoi
 *    que ce soit ; déposer n'est pas analyser.
 */
function crgi_import_reference(PDO $pdo): int
{
    $id = (int)$pdo->query(
        "SELECT i.id
           FROM crgi_import i
           LEFT JOIN crgi_phase p ON p.import_id = i.id AND p.statut = 'VALIDEE'
          WHERE i.statut <> 'ANNULE'
          GROUP BY i.id
          ORDER BY COUNT(p.id) DESC, i.id DESC
          LIMIT 1"
    )->fetchColumn();
    return $id ?: crgi_import_courant($pdo);
}

/**
 * ANNULER L'IMPORT — la promesse de réversibilité, tenue.
 *
 * ⚠️ ON NE SUPPRIME PAS LA LIGNE D'IMPORT. Elle passe à `ANNULE` avec sa date, son auteur et
 *    son motif : savoir qu'un dépôt a eu lieu et qu'il a été abandonné vaut mieux que de faire
 *    disparaître la trace. Ce sont les DONNÉES d'analyse qui partent, et les fichiers déposés.
 *
 * ⚠️ ET UN IMPORT DÉJÀ INTÉGRÉ NE S'ANNULE PAS ICI. À ce stade des écritures métier existent :
 *    les défaire est une autre opération, qui ne se déclenche pas d'un bouton de la même page.
 *
 * ⚠️ ANNULER DOIT RENDRE LE PDF INCONNU. Si une seule table d'analyse survit, le dépôt suivant
 *    du même document ne repart pas de zéro : il repart d'un demi-souvenir. C'est pour cela
 *    que le balayage est exhaustif et déduit du schéma, et non recopié dans cette fonction.
 */
function crgi_annuler(PDO $pdo, int $importId, int $userId, string $motif): void
{
    $st = $pdo->prepare('SELECT statut FROM crgi_import WHERE id = ?');
    $st->execute([$importId]);
    $statut = (string)$st->fetchColumn();
    if ($statut === 'INTEGRE') {
        throw new RuntimeException("IMPORT DÉJÀ INTÉGRÉ — l'annulation ne se fait pas ici.");
    }

    // ⚠️ LES FICHIERS SE LISENT AVANT D'EFFACER `crgi_piece` : c'est elle qui dit où ils sont.
    $pieces = $pdo->prepare('SELECT chemin FROM crgi_piece WHERE import_id = ?');
    $pieces->execute([$importId]);
    foreach ($pieces->fetchAll(PDO::FETCH_COLUMN) as $chemin) {
        // ⚠️ ON NE SUPPRIME QUE DANS LE STAGING. Un chemin hors de ce dossier appartient à
        //    quelqu'un d'autre — l'effacer serait exactement le genre de dégât que ce module
        //    promet de ne jamais causer.
        $reel = realpath((string)$chemin);
        $racine = realpath(CRGI_STAGING);
        if ($reel && $racine && str_starts_with($reel, $racine)) {
            @unlink($reel);
        }
    }

    // Toute trace de l'analyse part, table par table, sans en oublier une seule.
    foreach (crgi_tables_de_staging($pdo) as $table) {
        $pdo->prepare("DELETE FROM `$table` WHERE import_id = ?")->execute([$importId]);
    }
    @rmdir(crgi_dossier($importId));

    $pdo->prepare(
        'UPDATE crgi_import SET statut = "ANNULE", annule_le = NOW(), annule_par = ?,
                annule_motif = ?, nb_pieces = 0, nb_pages = 0 WHERE id = ?'
    )->execute([$userId ?: null, mb_substr($motif, 0, 255), $importId]);
}

/**
 * L'AGENCE ET LA PÉRIODE DE CHAQUE CRG, ET D'OÙ ELLES VIENNENT.
 *
 * ⚠️ TROIS PROVENANCES, JAMAIS CONFONDUES. `LUE` = le PDF l'imprime. `RAPPROCHEE` = MBI la
 *    désigne sans ambiguïté à partir du compte mandant. `INDETERMINABLE` = ni l'un ni l'autre.
 *    Afficher une déduction comme une lecture ferait exactement ce que la doctrine CRG
 *    interdit depuis P1 : présenter une conclusion comme un fait du document.
 *
 * ⚠️ ON NE DEVINE JAMAIS. Deux agences possibles, ou un compte absent de MBI, donnent
 *    `INDETERMINABLE` — pas « la plus probable ». Un rapprochement faux se propage à tout ce
 *    que la phase 1 construira dessus.
 *
 * ⚠️ ET LE CODE DE COMPTE N'EST PAS UNIQUE. Trois codes existent à la fois chez
 *    `loca_immo_lyon` et `emery_immo`, que le format PDF `lyon` ne distingue pas : ils sont
 *    donc ambigus par construction, et déclarés tels quels.
 */
function crgi_rapprocher_agences(PDO $pdo, int $importId): void
{
    $agences = $pdo->query('SELECT id, nom_agence FROM agences')->fetchAll(PDO::FETCH_KEY_PAIR);

    // Les agences que le compte mandant désigne, par (code, système), sans ambiguïté.
    // ⚠️ ON PASSE PAR LES IMMEUBLES, PAS PAR `proprietaires.id_agence` : la doctrine de
    //    `proprietaire_comptes_crg` dit que son agence n'est « JAMAIS déduite » de la fiche
    //    propriétaire. L'agence d'un immeuble, elle, est une donnée de gestion assumée.
    $route = $pdo->query(
        'SELECT c.code_compte, c.systeme,
                COUNT(DISTINCT i.id_agence) AS nb,
                MIN(i.id_agence) AS id_agence
           FROM proprietaire_comptes_crg c
           JOIN immeubles i ON i.id_proprietaire = c.id_proprietaire
          WHERE i.id_agence IS NOT NULL
          GROUP BY c.code_compte, c.systeme'
    )->fetchAll(PDO::FETCH_ASSOC);

    $parCode = [];
    foreach ($route as $r) {
        $parCode[(string)$r['code_compte']][] = $r;
    }

    // L'identité légale de chaque agence : SIRET (l'établissement), SIREN (la société),
    // code postal (l'implantation). Les trois servent, dans cet ordre de certitude.
    $refAgences = $pdo->query(
        'SELECT id, code_postal, siret, siren_siret, id_societe FROM agences WHERE actif = 1'
    )->fetchAll(PDO::FETCH_ASSOC);
    $entete = $pdo->prepare(
        'SELECT pc.chemin, c.page_debut FROM crgi_crg c
           JOIN crgi_page pg ON pg.crg_id = c.id AND pg.page_no = c.page_debut
           JOIN crgi_piece pc ON pc.id = pg.piece_id
          WHERE c.id = ? LIMIT 1'
    );
    $lecteur = crgi_binaire('pdftotext');
    // L'en-tête d'un CRG, lu une seule fois, et seulement si on en a besoin.
    $enTete = $lecteur === null ? null : function (array $c) use ($entete, $lecteur, $refAgences) {
        $entete->execute([(int)$c['id']]);
        $p = $entete->fetch(PDO::FETCH_ASSOC);
        if (!$p) {
            return null;
        }
        $page = (int)$p['page_debut'];
        $txt = (string)shell_exec(
            escapeshellarg($lecteur) . ' -layout -f ' . $page . ' -l ' . $page . ' '
            . escapeshellarg((string)$p['chemin']) . ' - 2>'
            . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null')
        );
        return crgi_agence_par_entete($refAgences, $txt);
    };

    $crgs = $pdo->prepare('SELECT id, agence, compte, format, periode_cle FROM crgi_crg
                            WHERE import_id = ?');
    $crgs->execute([$importId]);
    $maj = $pdo->prepare(
        'UPDATE crgi_crg SET agence_id = ?, agence_source = ?, agence_motif = ?,
                periode_source = ? WHERE id = ?'
    );

    foreach ($crgs->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $periodeSource = $c['periode_cle'] ? 'LUE' : 'INDETERMINABLE';
        $id = null;
        $source = 'INDETERMINABLE';
        $motif = null;

        if ($c['agence']) {
            // Le PDF l'imprime : on la rattache à MBI si le nom y correspond, mais la
            // provenance reste LUE — c'est le document qui l'affirme.
            $source = 'LUE';
            $id = crgi_agence_par_nom($agences, (string)$c['agence']);
            $motif = $id
                ? 'Imprimée dans le CRG et reconnue dans MBI.'
                : 'Imprimée dans le CRG (« ' . $c['agence'] . ' ») mais aucune agence MBI '
                . 'ne porte ce nom : le rattachement reste à faire.';
        }
        // ⚠️ UN NOM COMMERCIAL INCONNU N'EST PAS UNE FIN DE NON-RECEVOIR. « A1 - DE GASPERIS
        //    IMMOBILIER » ne figure dans aucune agence MBI — et c'est pourtant une enseigne
        //    de la maison, ce que son PIED DE PAGE dit en toutes lettres. J'avais fermé cette
        //    voie parce que le recours d'alors se contentait du PREMIER code postal venu et
        //    dispersait 56 comptes rendus sur cinq agences. Ce n'est plus le même recours :
        //    il exige désormais le SIRET ou le RCS de la société, et n'accepte un code postal
        //    que parmi les agences de CETTE société. La voie se rouvre parce que la preuve
        //    exigée a changé, pas parce qu'on a relâché le contrôle.
        if ($id === null && $enTete !== null && ($trouve = $enTete($c)) !== null) {
            // ── LE DOCUMENT AVANT LA DÉDUCTION ────────────────────────────────────────────
            // ⚠️ UN NUMÉRO DE COMPTE MANDANT N'EST PAS UNIQUE : IL L'EST PAR AGENCE. Trois
            //    codes de ce référentiel désignent DEUX propriétaires chacun, dans deux
            //    agences différentes — `02200000` est KIBLEPI à Lyon ET CREMER à Riom,
            //    `02320000` est DUMONT à Lyon ET BLAUDY à Riom. Déduire l'agence du compte
            //    revient donc à tirer à pile ou face, et le moteur a perdu : quatre comptes
            //    rendus imprimant « LOCA IMMO, 69007 LYON » sont partis à Riom.
            //
            // ⚠️ ET LA DÉDUCTION PEUT ÊTRE EMPOISONNÉE PAR UNE SEULE FICHE. Sur `01510000`,
            //    le concurrent du vrai propriétaire s'appelle « - 1er Trimestre 2026 - » :
            //    un libellé de période enregistré comme nom de personne par une extraction
            //    ratée. Il est le seul des deux à porter une agence — c'est donc lui qui
            //    gagnait. Une donnée fausse pèse toujours plus lourd qu'une donnée absente.
            //
            // ⚠️ L'EN-TÊTE, LUI, EST ÉNONCÉ PAR CELUI QUI A ÉMIS LE DOCUMENT. On le croit
            //    donc AVANT toute déduction faite sur MBI. Le compte mandant ne sert plus
            //    qu'en dernier recours, quand le document ne dit ni son nom ni son adresse.
            $id = $trouve;
            $source = 'EN-TETE';
            $motif = 'Agence établie par l’identité légale imprimée en tête ou en pied du '
                   . 'document (SIRET, RCS, puis code postal de la société) — le document '
                   . 'nomme son émetteur, on ne le déduit pas de MBI.';
        } elseif ($c['compte']) {
            $candidats = $parCode[(string)$c['compte']] ?? [];
            // ⚠️ LE FORMAT NE DÉSIGNE PAS UN SYSTÈME UNIQUE : `lyon` couvre `loca_immo_lyon`
            //    ET `emery_immo`. On ne filtre donc pas, on constate l'ambiguïté.
            $ids = [];
            foreach ($candidats as $x) {
                if ((int)$x['nb'] === 1) {
                    $ids[(int)$x['id_agence']] = true;
                }
            }
            if (!$candidats) {
                $motif = 'Compte ' . $c['compte'] . ' inconnu de MBI : aucun rapprochement '
                       . 'possible.';
            } elseif (count($ids) === 1) {
                $id = (int)array_key_first($ids);
                $source = 'RAPPROCHEE';
                $motif = 'Agence déduite par rapprochement du compte mandant ' . $c['compte']
                       . ' dans MBI — une seule agence possible.';
            } else {
                $noms = [];
                foreach ($candidats as $x) {
                    $noms[] = $x['systeme'] . ' → ' . ((int)$x['nb']) . ' agence(s)';
                }
                $motif = 'Compte ' . $c['compte'] . ' : plusieurs agences possibles ('
                       . implode(' ; ', array_unique($noms)) . ') — arbitrage nécessaire.';
            }
        } else {
            $motif = 'Ni agence imprimée, ni compte mandant lu : rien à rapprocher.';
        }

        // ⚠️ AUCUN RECOURS APRÈS COUP. Un nom imprimé que MBI ne connaît pas reste un
        //    rattachement À FAIRE — « MBI ne connaît pas cette agence » n'est PAS « le
        //    document ne la dit pas ». Confondre les deux a rattaché 56 comptes rendus de
        //    « A1 - DE GASPERIS IMMOBILIER », un professionnel EXTERNE, à cinq agences Emery.
        $maj->execute([$id, $source, mb_substr((string)$motif, 0, 300), $periodeSource,
                       (int)$c['id']]);
    }
}

/** Reconnaît « A3 - REGIE EMERY - VIENNE » comme « REGIE EMERY VIENNE », sans plus. */
function crgi_agence_par_nom(array $agences, string $imprime): ?int
{
    $normaliser = static function (string $s): string {
        $s = strtr($s, ['É' => 'E', 'È' => 'E', 'Ê' => 'E', 'À' => 'A', 'Î' => 'I', 'Ô' => 'O']);
        $s = preg_replace('/[^A-Za-z]+/', ' ', mb_strtoupper($s)) ?? $s;
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    };
    $cible = $normaliser($imprime);
    foreach ($agences as $id => $nom) {
        $n = $normaliser((string)$nom);
        // ⚠️ INCLUSION, PAS ÉGALITÉ : le PDF préfixe « A3 - ». Mais on exige que le nom MBI
        //    tienne ENTIER dans le libellé imprimé — une correspondance partielle ferait
        //    passer « REGIE EMERY LYON » pour « REGIE EMERY ».
        if ($n !== '' && str_contains($cible, $n)) {
            return (int)$id;
        }
    }
    return null;
}

/**
 * L'AGENCE PAR LE CODE POSTAL DE SON EN-TÊTE — le dernier recours, et le plus solide.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ DÉDUIRE L'AGENCE D'UN CRG DES IMMEUBLES DE SON PROPRIÉTAIRE EST FAUX PAR CONSTRUCTION.
 *    Un mandant peut être géré par DEUX agences à la fois — Emmanuel, 06/09/2026, à propos
 *    d'un groupe présent à Lyon et à Chaponost. Aucune donnée, si propre soit-elle, ne
 *    permettra alors de trancher : la question « de quelle agence relève CE compte rendu »
 *    n'a pas de réponse dans le portefeuille du propriétaire. Elle en a une, imprimée, dans
 *    l'en-tête du document.
 *
 * ⚠️ MESURE : **103 CRG sur 947** restaient sans agence après le rapprochement par les
 *    immeubles — 86 pour un mandant présent dans trois agences, 13 pour un propriétaire sans
 *    aucun immeuble en base, 4 pour un compte absent de MBI. Le code postal de l'en-tête les
 *    résout **103 sur 103**, sans exception.
 *
 * ⚠️ LE CODE POSTAL, PAS LE NOM COMMERCIAL. Les documents portent encore « LOCA IMMO », une
 *    enseigne abandonnée depuis. Un nom change ; l'adresse de l'agence, non. C'est la règle
 *    que `crg_detect_agence()` applique déjà dans l'ancien module — elle est reprise ici, et
 *    non appelée, pour que le module d'intégration ne dépende pas du module qu'il remplace.
 *
 * ⚠️ ET C'EST UN DERNIER RECOURS, JAMAIS UNE PRIORITÉ. Il ne s'ouvre que si le nom imprimé
 *    n'a rien donné ET que le compte mandant n'a pas tranché : un document qui nomme son
 *    agence reste cru sur parole. On borne la recherche à l'en-tête, sinon le premier code
 *    postal rencontré serait celui du PROPRIÉTAIRE, à qui le courrier est adressé.
 */
// ⚠️ LES ZONES D'ÉMETTEUR SE COMPTENT EN LIGNES, JAMAIS EN CARACTÈRES. Une extraction qui
//    préserve les colonnes remplit chaque ligne d'espaces jusqu'à la marge : un en-tête de
//    dix lignes pèse 2 000 caractères. Bornée à 900 caractères, la zone s'arrêtait avant le
//    SIRET — imprimé en neuvième ligne — et **17 comptes rendus ont perdu leur agence** en
//    passant d'un recours faible à un recours fort. La longueur d'une ligne dépend de la mise
//    en page ; le nombre de lignes de l'en-tête, non.
const CRGI_ENTETE_LIGNES = 18;
// Le papier à en-tête proprement dit : le bloc de l'émetteur, AVANT l'adresse du destinataire.
const CRGI_ENTETE_HAUT = 8;

/**
 * ⚠️ L'IDENTITÉ LÉGALE ET LE NOM COMMERCIAL SONT DEUX CHOSES, ET ILS NE VIVENT PAS AU MÊME
 *    ENDROIT DE LA PAGE. Un éditeur imprime son émetteur en TÊTE, l'autre en PIED. 194
 *    comptes rendus portant l'enseigne « A1 - DE GASPERIS IMMOBILIER » ont été déclarés
 *    étrangers à la maison — alors que leur PIED DE PAGE disait « SARL REGIE EMERY, siège
 *    social 10 place Maréchal Foch, RCS 398912766 », le même RCS et le même siège que le
 *    dépôt voisin dont l'en-tête, lui, était reconnu. On lit donc les deux bouts.
 *
 * ⚠️ ET L'IDENTIFICATION SE FAIT À DEUX NIVEAUX, PARCE QUE LE DOCUMENT PARLE À DEUX NIVEAUX.
 *      ❶ le SIRET complet — 14 chiffres — désigne UN établissement : c'est l'agence, exactement.
 *      ❷ le SIREN — 9 chiffres — ne désigne que la SOCIÉTÉ. Ici il en couvre quatre agences.
 *        Le code postal tranche alors À L'INTÉRIEUR de cette société, et nulle part ailleurs.
 *    Cette restriction est ce qui rend le code postal sûr : on n'accepte que celui d'une
 *    agence de la société déjà identifiée, jamais un code postal trouvé au hasard de la page.
 *
 * ⚠️ LE CODE POSTAL SEUL RESTE LE DERNIER RECOURS, et il est le plus faible : la page porte
 *    aussi l'adresse du PROPRIÉTAIRE, à qui le courrier est adressé. On le borne donc aux
 *    zones d'en-tête et de pied, où l'émetteur s'imprime, jamais au corps de la lettre.
 */
function crgi_agence_par_entete(array $refAgences, string $texte): ?int
{
    $lignes = preg_split('/\R/', $texte) ?: [];
    $zones  = implode("\n", array_merge(
        array_slice($lignes, 0, CRGI_ENTETE_LIGNES),
        array_slice($lignes, -CRGI_ENTETE_LIGNES)
    ));
    $nu = preg_replace('/[^0-9A-Za-z]+/', ' ', $zones) ?? $zones;
    $chiffres = preg_replace('/\D+/', ' ', $nu) ?? '';

    // ❶ Le SIRET complet désigne l'établissement, donc l'agence — sans ambiguïté possible.
    foreach ($refAgences as $a) {
        $s = preg_replace('/\D+/', '', (string)($a['siret'] ?? ''));
        if (strlen((string)$s) === 14 && str_contains($chiffres, (string)$s)) {
            return (int)$a['id'];
        }
    }
    // ❷ Le SIREN désigne la société ; le code postal choisit l'agence DANS cette société.
    $societes = [];
    foreach ($refAgences as $a) {
        $n = preg_replace('/\D+/', '', (string)($a['siren_siret'] ?? ''));
        if (strlen((string)$n) === 9 && str_contains($chiffres, (string)$n)) {
            $societes[(string)$a['id_societe']] = true;
        }
    }
    if ($societes) {
        // ⚠️ LE CODE POSTAL NE SE CHERCHE PAS N'IMPORTE OÙ DANS LA ZONE — L'ADRESSE DU
        //    DESTINATAIRE Y EST AUSSI. Un compte rendu est un COURRIER : le propriétaire y
        //    figure, et une mise en page en colonnes le pose sur la MÊME LIGNE que l'agence.
        //    Mesuré : « Agence: A1 - DE GASPERIS IMMOBILIER    69780 TOUSSIEU » — 69780 est
        //    le code postal du propriétaire, et c'est celui d'une autre agence de la même
        //    société. Un CRG de Chaponost est parti à Mions.
        //
        // ⚠️ ON REGARDE DONC LE PIED D'ABORD, PUIS LE HAUT DE L'EN-TÊTE — jamais le milieu.
        //    Les deux éditeurs impriment leur bloc légal à des endroits opposés : l'un en
        //    pied (« Agence … 69630 CHAPONOST — SARL REGIE EMERY … RCS »), l'autre dans les
        //    toutes premières lignes du papier à en-tête. Le bloc du DESTINATAIRE, lui, vit
        //    entre les deux. Chercher dans l'ordre pied → tête haute le laisse dehors.
        $pied = implode("\n", array_slice($lignes, -CRGI_ENTETE_LIGNES));
        $haut = implode("\n", array_slice($lignes, 0, CRGI_ENTETE_HAUT));
        foreach ([$pied, $haut] as $zone) {
            foreach ($refAgences as $a) {
                $cp = trim((string)($a['code_postal'] ?? ''));
                if ($cp !== '' && isset($societes[(string)$a['id_societe']])
                    && preg_match('/\b' . preg_quote($cp, '/') . '\b/', $zone)) {
                    return (int)$a['id'];
                }
            }
        }
    }
    // ⚠️ PAS DE TROISIÈME RECOURS SUR LE SEUL CODE POSTAL. Il a existé une journée, et une
    //    fixture l'a mis à terre : sur un document sans identité légale, il rattachait
    //    l'agence à l'adresse du PROPRIÉTAIRE, imprimée elle aussi dans la zone d'en-tête —
    //    c'est un courrier, le destinataire y figure. Il ne tenait que par l'ordre
    //    d'impression, l'émetteur venant avant le destinataire : une convention de mise en
    //    page, pas une preuve. Les deux voies ci-dessus suffisent, tous les gabarits du
    //    corpus imprimant leur SIRET ou leur RCS. Sans identité légale, on ne devine pas.
    return null;
}


/**
 * RÉÉNONCIATION DÉMONTRÉE, OU SIMPLE COLLISION DE CLÉ ?
 *
 * ⚠️ UNE CLÉ NE PROUVE PAS UNE RÉÉDITION — mise en garde d'Emmanuel, et le document l'a
 *    confirmée dans l'heure : sur 72 CRG partageant `compte × période × arrêté`, **54 ont
 *    le même contenu** et **18 un contenu DIFFÉRENT**. Fondre les 72 aurait effacé 18
 *    comptes rendus complémentaires sans que rien ne le signale.
 *
 * ⚠️ ON NE SUPPRIME RIEN. Les deux occurrences restent en base avec leurs pages ; seule la
 *    SITUATION est comptée une fois. `document ≠ situation métier ≠ événement métier`.
 */
function crgi_qualifier_doublons(PDO $pdo, int $importId): void
{
    $st = $pdo->prepare(
        'SELECT id, compte, periode_cle, date_arrete, empreinte, caracteres_lus
           FROM crgi_crg WHERE import_id = ? ORDER BY page_debut'
    );
    $st->execute([$importId]);
    $maj = $pdo->prepare('UPDATE crgi_crg SET doublon_de = ?, doublon_statut = ? WHERE id = ?');

    $premier = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cle = implode('|', [(string)$c['compte'], (string)$c['periode_cle'],
                             (string)$c['date_arrete']]);
        if ($cle === '||' || !$c['compte']) {
            $maj->execute([null, 'UNIQUE', (int)$c['id']]);
            continue;
        }
        if (!isset($premier[$cle])) {
            $premier[$cle] = $c;
            $maj->execute([null, 'UNIQUE', (int)$c['id']]);
            continue;
        }
        $ref = $premier[$cle];
        // ⚠️ UNE EMPREINTE CALCULÉE SUR TROIS CARACTÈRES NE DÉMONTRE RIEN. On exige un volume
        //    de texte réel avant de parler de contenu concordant.
        $concordant = $c['empreinte'] && $ref['empreinte']
                   && $c['empreinte'] === $ref['empreinte']
                   && (int)$c['caracteres_lus'] > 200;
        $maj->execute([(int)$ref['id'],
                       $concordant ? 'REENONCIATION' : 'MEME CLE CONTENU DIFFERENT',
                       (int)$c['id']]);
    }
}


/**
 * QUALIFIER LES COLLISIONS DE CLÉ — A, B ou C, par les MONTANTS.
 *
 * ⚠️ UNE CLÉ SIGNALE, ELLE NE QUALIFIE PAS. `compte × période × arrêté` a levé 18 collisions
 *    sur le document réel ; la comparaison des montants a montré que **13 portaient des lots
 *    différents du même immeuble** — des situations COMPLÉMENTAIRES, pas des rééditions. Les
 *    fondre aurait détruit treize comptes rendus.
 *
 * ⚠️ ET LA TAILLE DU TEXTE NE QUALIFIE RIEN : deux OCR du même document ne rendent jamais le
 *    même nombre de caractères. Seules les sommes imprimées font la situation.
 *
 * ⚠️ AUCUNE ÉCRITURE DANS L'EMPREINTE DE LA PHASE 0 : le sceau porte sur le découpage, pas sur
 *    les annotations. La validation d'Emmanuel survit à cette qualification, et c'est voulu.
 */
function crgi_qualifier_collisions(PDO $pdo, int $importId): array
{
    $st = $pdo->prepare(
        'SELECT c.id, c.doublon_de, c.page_debut, c.page_fin,
                r.page_debut AS rd, r.page_fin AS rf, p.chemin, q.chemin AS chemin_ref
           FROM crgi_crg c
           JOIN crgi_crg r ON r.id = c.doublon_de
           JOIN crgi_piece p ON p.id = c.piece_id
           JOIN crgi_piece q ON q.id = r.piece_id
          WHERE c.import_id = ? AND c.doublon_statut = "MEME CLE CONTENU DIFFERENT"
          ORDER BY c.page_debut'
    );
    $st->execute([$importId]);
    $paires = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$paires) {
        return ['A' => 0, 'B' => 0, 'C' => 0];
    }

    $python = getenv('CRG_PYTHON') ?: (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
    $script = realpath(__DIR__ . '/../scripts/crg_integration_doublons.py');
    if (!$script) {
        throw new RuntimeException('MOTEUR ABSENT : scripts/crg_integration_doublons.py');
    }
    $maj = $pdo->prepare(
        'UPDATE crgi_crg SET doublon_qualification = ?, doublon_qualif_motif = ?,
                doublon_statut = ?, doublon_de = ? WHERE id = ?'
    );
    // ⚠️ UNE SEULE LECTURE PAR DOCUMENT (`INTEG-PERF-01`, comme la phase 4). Un processus
    //    Python par paire relisait les 906 pages du PDF de 616 Mo pour n'en découper que deux
    //    extraits : 18 paires × 5,7 s = 103 s des 118 s de la phase 0, pour 6 s de lecture
    //    utile. La règle de qualification, elle, n'a pas changé — c'est le même
    //    `qualifier_paire`, sur les mêmes pages, dans le même ordre.
    //
    // ⚠️ MAIS CHAQUE CÔTÉ DIT DE QUEL DOCUMENT IL VIENT. Grouper par la pièce de la SECONDE
    //    occurrence supposait que la première était dans le même PDF. Sur LYON, le même CRG
    //    est classé dans deux dossiers (`GPE IMMO DR` et `GPE SIR`) : on découpait alors les
    //    pages 1-10 de la première occurrence dans le document de la seconde, et on comparait
    //    un extrait avec lui-même. Le cache Python garde l'unicité de lecture ; le chemin,
    //    lui, redevient une donnée de la paire.
    $bilan = ['A' => 0, 'B' => 0, 'C' => 0];
    $verdicts = [];
    $lot = [];
    foreach ($paires as $p) {
        $lot[] = [
            'id' => (int)$p['id'],
            'ad' => (int)$p['page_debut'], 'af' => (int)$p['page_fin'],
            'bd' => (int)$p['rd'], 'bf' => (int)$p['rf'],
            'a_pdf' => (string)$p['chemin'], 'b_pdf' => (string)$p['chemin_ref'],
        ];
    }
    $fichier = tempnam(sys_get_temp_dir(), 'crgid_');
    file_put_contents($fichier, json_encode($lot));
    $sortie = @shell_exec(
        escapeshellarg($python) . ' ' . escapeshellarg($script) . ' '
        . escapeshellarg((string)$paires[0]['chemin']) . ' ' . escapeshellarg($fichier) . ' 2>&1'
    );
    @unlink($fichier);
    foreach ((array)json_decode(trim((string)$sortie), true) as $v) {
        if (is_array($v) && isset($v['id'], $v['verdict'])) {
            $verdicts[(int)$v['id']] = $v;
        }
    }
    // ⚠️ ON GARDE LA SORTIE BRUTE POUR LES PAIRES SANS VERDICT. Un moteur muet doit dire
    //    pourquoi il l'est, sinon le cas C devient un cul-de-sac de diagnostic.
    foreach ($lot as $l) {
        if (!isset($verdicts[$l['id']])) {
            $verdicts[$l['id']] = ['muet' => mb_substr(trim((string)$sortie), 0, 200)];
        }
    }
    foreach ($paires as $p) {
        $r = $verdicts[(int)$p['id']] ?? null;
        $sortie = $r['muet'] ?? '';
        if (!is_array($r) || !isset($r['verdict'])) {
            // ⚠️ UN MOTEUR MUET NE VAUT PAS UN VERDICT. On laisse la ligne à examiner plutôt
            //    que de la classer par défaut — un classement par défaut serait une décision.
            $maj->execute(['C', 'Qualification impossible : ' . mb_substr((string)$sortie, 0, 200),
                           'MEME CLE CONTENU DIFFERENT', $p['doublon_de'], (int)$p['id']]);
            $bilan['C']++;
            continue;
        }
        $bilan[$r['verdict']]++;
        // B = situation complémentaire : elle redevient une situation à part entière, et
        // cesse d'être présentée comme le doublon de quoi que ce soit.
        $statut = ['A' => 'REENONCIATION', 'B' => 'UNIQUE',
                   'C' => 'MEME CLE CONTENU DIFFERENT'][$r['verdict']];
        // ⚠️ UN « B » CESSE D'ÊTRE LE DOUBLON DE QUOI QUE CE SOIT. Le laisser pointer vers
        //    l'autre occurrence entretiendrait l'idée d'une réédition là où il y a deux
        //    situations : le lien est coupé, la trace vit dans le motif.
        $maj->execute([$r['verdict'], mb_substr((string)$r['motif'], 0, 400), $statut,
                       $r['verdict'] === 'B' ? null : $p['doublon_de'],
                       (int)$p['id']]);
    }
    return $bilan;
}


/**
 * PHASE 1 — INVENTAIRE : ce que MBI connaît déjà de ces situations.
 *
 * ⚠️ AUCUNE ÉCRITURE MÉTIER. Cette fonction LIT `proprietaire_comptes_crg` et
 *    `crg_trimestres` ; elle n'écrit que dans `crgi_crg`. Rien n'est créé, modifié, archivé
 *    ni supprimé dans MBI, et l'import reste annulable jusqu'au bout.
 *
 * ⚠️ ELLE NE TRAVAILLE QUE SUR LES SITUATIONS. Les réénonciations qualifiées en phase 0 sont
 *    exclues : inventorier deux fois la même situation gonflerait l'inventaire de doublons
 *    qu'on vient précisément d'écarter.
 *
 * ⚠️ ET ELLE NE TRANCHE PAS À LA PLACE D'EMMANUEL. Plusieurs candidates MBI pour une même
 *    situation donnent `A VERIFIER`, jamais « la plus probable ».
 */
function crgi_phase1(PDO $pdo, int $importId): array
{
    crgi_verrouiller_import($pdo, $importId, 1);
    $etat0 = crgi_phase_validee($pdo, $importId, 0);
    if (!$etat0['validee'] || $etat0['perimee']) {
        throw new RuntimeException(
            'PHASE 0 NON VALIDÉE — la phase 1 ne s’ouvre pas. Une confrontation menée sur un '
            . 'découpage non scellé comparerait MBI à un résultat qui peut encore changer.'
        );
    }
    crgi_marquer_phase($pdo, $importId, 1, 'EN ANALYSE', null);

    // ⚠️ UNE PHASE EFFACE CE QU'ELLE NE COUVRE PLUS. Deux CRG stampés « NOUVELLE » lors d'un
    //    premier passage sont devenus des réénonciations quand la qualification des collisions
    //    a su les lire : la phase 1 ne les regarde plus, mais leur inventaire d'avant est
    //    resté collé. Le contrôle de couverture les comptait alors DEUX FOIS — examinés ET
    //    exclus — et annonçait « -2 objets inexpliqués » sur une chaîne pourtant complète.
    //    Une trace qui survit à la règle qui l'a produite est un mensonge, pas un souvenir.
    $pdo->prepare('UPDATE crgi_crg SET inventaire_statut = NULL, inventaire_motif = NULL,
                          mbi_trimestre_id = NULL
                    WHERE import_id = ?')->execute([$importId]);

    $st = $pdo->prepare(
        'SELECT id, compte, format, agence_id, proprietaire, periode_debut, periode_fin,
                date_arrete, periode_cle
           FROM crgi_crg
          WHERE import_id = ? AND ' . CRGI_CRG_PORTEURS . '
          ORDER BY page_debut'
    );
    $st->execute([$importId]);
    $situations = $st->fetchAll(PDO::FETCH_ASSOC);

    // Les comptes que MBI connaît, par code. ⚠️ Un même code peut exister dans deux systèmes :
    // on garde TOUS les identifiants, et l'ambiguïté devient un « à vérifier », pas un choix.
    $comptes = [];
    foreach ($pdo->query('SELECT c.id, c.code_compte, c.systeme, c.id_proprietaire,
                                 p.nom AS nom_proprietaire
                            FROM proprietaire_comptes_crg c
                            LEFT JOIN proprietaires p ON p.id = c.id_proprietaire',
                         PDO::FETCH_ASSOC) as $c) {
        $comptes[(string)$c['code_compte']][] = $c;
    }

    // ⚠️ QUEL ESPACE DE NOMMAGE POUR QUELLE AGENCE ? ON LE CONSTATE, ON NE L'ÉCRIT PAS À LA
    //    MAIN. Une correspondance codée en dur serait fausse dès la prochaine agence, et
    //    surtout elle porterait MON hypothèse sur le progiciel de chacune — hypothèse que je
    //    me suis déjà faite, et qui était fausse. On lit donc dans MBI où vivent réellement
    //    les comptes des propriétaires de chaque agence, et l'espace majoritaire l'emporte.
    $systemeDeLAgence = [];
    foreach ($pdo->query(
        'SELECT i.id_agence, c.systeme, COUNT(*) n
           FROM proprietaire_comptes_crg c
           JOIN immeubles i ON i.id_proprietaire = c.id_proprietaire
          WHERE i.id_agence IS NOT NULL AND c.systeme IS NOT NULL AND c.systeme <> ""
          GROUP BY i.id_agence, c.systeme
          ORDER BY n DESC', PDO::FETCH_ASSOC) as $r) {
        $systemeDeLAgence[(int)$r['id_agence']] ??= (string)$r['systeme'];
    }

    $trimestres = $pdo->prepare(
        'SELECT id, periode_debut, periode_fin, date_arrete, periode_type
           FROM crg_trimestres WHERE id_compte_mandant = ?'
    );
    $maj = $pdo->prepare(
        'UPDATE crgi_crg SET inventaire_statut = ?, inventaire_motif = ?, mbi_trimestre_id = ?
          WHERE id = ?'
    );

    $bilan = ['DEJA CONNUE' => 0, 'NOUVELLE' => 0, 'NOUVEAU MANDANT' => 0,
              'CODE PARTAGE' => 0, 'A VERIFIER' => 0];
    foreach ($situations as $s) {
        $cands = $comptes[(string)$s['compte']] ?? [];

        // ⚠️ UN CODE DE COMPTE N'EST JAMAIS GLOBAL — IL N'EXISTE QUE DANS SON SYSTÈME.
        //    `01600000` est SCI JOURNET chez `loca_immo_lyon` ; le CRG EMERY IMMO qui porte
        //    ce même code appartient à Madame GERMAIN. Chercher par le code seul rattachait
        //    silencieusement un CRG de RIOM au mandant lyonnais — un rapprochement faux se
        //    propage ensuite à tout ce que les phases suivantes construisent dessus.
        //    L'identité est le couple `(code, système)`, jamais le code (`P3A-COMPTE-03`).
        // ⚠️ LE SYSTÈME SE PREND SUR L'AGENCE ÉTABLIE, PAS SUR LE FORMAT LU. Emmanuel,
        //    07/09/2026 : « tu veux toujours comparer le n° de compte des propriétaires alors
        //    que nous avons plusieurs sociétés et que les codes peuvent être identiques :
        //    dans ce cas il faut regarder l'en-tête ou le pied de page pour voir l'agence ou
        //    la société qui donne le CRG ». Le format ne désigne qu'un LOGICIEL, et un même
        //    logiciel sert PLUSIEURS SOCIÉTÉS : ICS édite pour Lyon comme pour Riom, SPI pour
        //    Vienne comme pour Chaponost. Le `systeme` de MBI n'est donc pas le progiciel,
        //    c'est l'ESPACE DE NOMMAGE des comptes — et un code n'est unique que dedans.
        //    La phase 0, elle, établit l'agence sur l'identité légale imprimée : c'est elle
        //    qui sait de quelle maison vient le document.
        //    Mesuré : **6 homonymes sur 6** venaient d'un format mal reconnu ; l'agence, elle,
        //    était juste dans les six cas.
        $systeme = $systemeDeLAgence[(int)($s['agence_id'] ?? 0)] ?? null;
        if ($systeme === null) {
            $systeme = CRGI_SYSTEME_DU_FORMAT[(string)$s['format']] ?? null;
        }
        if ($systeme !== null && $cands) {
            $memeSysteme = array_values(array_filter(
                $cands, fn($c) => (string)$c['systeme'] === $systeme
            ));
            // ⚠️ ET SI L'AGENCE NE SUFFIT PAS, ON VA VOIR L'IMMEUBLE. Emmanuel, 07/09/2026 :
            //    « et si cela ne suffit pas, il faut aller voir l'immeuble ». Un candidat d'un
            //    autre espace de nommage dont le propriétaire possède des immeubles DANS
            //    L'AGENCE QUI A ÉMIS LE DOCUMENT n'est pas un homonyme : c'est le même
            //    mandant, enregistré sous un autre espace lors d'une reprise ancienne. Le
            //    départage se fait sur un fait — la possession d'un immeuble dans cette
            //    agence — jamais sur une ressemblance de nom.
            if (!$memeSysteme && $s['agence_id']) {
                $ici = $pdo->prepare(
                    'SELECT COUNT(*) FROM immeubles WHERE id_proprietaire = ? AND id_agence = ?'
                );
                $memeSysteme = array_values(array_filter($cands, function ($c) use ($ici, $s) {
                    $ici->execute([(int)$c['id_proprietaire'], (int)$s['agence_id']]);
                    return (int)$ici->fetchColumn() > 0;
                }));
            }
            // ⚠️ TROISIÈME ET DERNIER CRITÈRE : LE NOM. Emmanuel, 07/09/2026 — « dans une
            //    agence, un nouveau n° de compte ET un nouveau nom de propriétaire = nouveau
            //    mandat ». Si le numéro est absent de l'espace de l'agence, qu'aucun candidat
            //    n'y possède d'immeuble, ET que le nom imprimé ne ressemble à aucun d'eux,
            //    alors plus rien ne relie ce compte rendu au candidat d'à côté : c'est un
            //    mandat nouveau, DÉMONTRÉ, et il ne se décide pas.
            //
            // ⚠️ LE NOM NE SERT QU'À DISQUALIFIER, JAMAIS À RAPPROCHER. Un nom qui diffère
            //    prouve qu'on n'a pas affaire au même mandant ; un nom qui se ressemble ne
            //    prouve rien — deux SCI peuvent porter le même patronyme. C'est pourquoi il
            //    vient APRÈS l'agence et l'immeuble, et pourquoi une ressemblance laisse la
            //    question ouverte au lieu de la fermer.
            if (!$memeSysteme && $cands) {
                $nomLu = crgi_cle_nom((string)($s['proprietaire'] ?? ''));
                $memeNom = $nomLu !== '' && array_filter(
                    $cands,
                    fn($c) => crgi_cle_nom((string)($c['nom_proprietaire'] ?? '')) === $nomLu
                );
                if (!$memeNom) {
                    $ailleurs = implode(', ', array_unique(array_map(
                        fn($c) => (string)$c['systeme'], $cands)));
                    $maj->execute(['NOUVEAU MANDANT',
                        'Mandat nouveau pour cette agence : le compte ' . $s['compte']
                        . ' n’existe pas dans l’espace ' . $systeme . ', aucun candidat n’y '
                        . 'possède d’immeuble, et le nom imprimé ne correspond à aucun d’eux. '
                        . 'Le même NUMÉRO vit dans ' . $ailleurs . ' — un code n’est unique '
                        . 'que dans son espace de nommage, et rien d’autre ne les relie.',
                        null, (int)$s['id']]);
                    $bilan['NOUVEAU MANDANT']++;
                    continue;
                }
            }
            if (!$memeSysteme) {
                // ⚠️ UN CODE PARTAGÉ N'EST PAS UNE ABSENCE ANODINE : on le NOMME. Sans cela,
                //    « compte inconnu » laisserait croire à un simple manque, alors qu'un
                //    code identique vit à côté, dans un autre espace, prêt à être confondu.
                $ailleurs = implode(', ', array_map(fn($c) => $c['systeme'], $cands));
                $maj->execute(['CODE PARTAGE',
                    'Le compte ' . $s['compte'] . ' n’existe pas dans le système ' . $systeme
                    . '. Le MÊME NUMÉRO existe dans ' . $ailleurs . ' — ce '
                    . 'n’est pas le même mandant, c’est le même CODE réutilisé dans un '
                    . 'autre espace de nommage. `UN CODE DE COMPTE N’EST JAMAIS GLOBAL`.', null, (int)$s['id']]);
                $bilan['CODE PARTAGE']++;
                continue;
            }
            $cands = $memeSysteme;
        }

        if (!$cands) {
            $maj->execute(['NOUVEAU MANDANT',
                'Le compte ' . $s['compte'] . ' n’existe dans aucun système de MBI : la '
                . 'situation est nouvelle, et son mandant aussi.', null, (int)$s['id']]);
            $bilan['NOUVEAU MANDANT']++;
            continue;
        }
        if (count($cands) > 1) {
            $maj->execute(['A VERIFIER',
                'Le code ' . $s['compte'] . ' existe dans ' . count($cands) . ' systèmes de '
                . 'MBI : le rapprochement ne peut pas être établi sans arbitrage.',
                null, (int)$s['id']]);
            $bilan['A VERIFIER']++;
            continue;
        }
        $trimestres->execute([(int)$cands[0]['id']]);
        $connues = $trimestres->fetchAll(PDO::FETCH_ASSOC);

        // ⚠️ UN CRG TRIMESTRIEL N'IMPRIME PAS SES BORNES, ET LA COMPARAISON LES EXIGEAIT.
        //    Le document écrit « - 2e Trimestre 2026 - », jamais « du 01/04 au 30/06 » : ses
        //    colonnes `periode_debut` et `periode_fin` restent vides. La comparaison portait
        //    donc `NULL = "2026-04-01"` — jamais vraie. Mesuré : **235 CRG sur 235 et 199 sur
        //    200 sans bornes** sur les deux dépôts trimestriels, face à **444 situations
        //    trimestrielles présentes dans MBI**. Aucune n'était atteignable : tout ressortait
        //    « NOUVELLE », et réintégrer un trimestre déjà intégré l'aurait recréé.
        //
        // ⚠️ QUAND LE DOCUMENT NOMME SON TRIMESTRE, ON LE CROIT — Emmanuel, 07/09/2026 :
        //    « pour les périodes non données mais avec un nom clair, on dit que c'est le
        //    trimestre ». Le trimestre nommé vaut alors identité, et se compare au trimestre
        //    de la situation MBI.
        //
        // ⚠️ ET CE TRIMESTRE SE PREND SUR LA CLÔTURE, JAMAIS SUR LE DÉBUT. Une situation qui
        //    court du 25/06 au 30/09 est un T3 : c'est sa fin qui la date. La règle existe
        //    déjà pour la lecture des documents ; elle vaut aussi pour les comparer.
        $trimestreDe = static function (?string $fin): ?string {
            if (!$fin || !preg_match('/^(\d{4})-(\d{2})/', $fin, $m)) {
                return null;
            }
            return $m[1] . '-T' . intdiv((int)$m[2] + 2, 3);
        };
        $cleTrim = preg_match('/^\d{4}-T[1-4]$/', (string)$s['periode_cle'])
            ? (string)$s['periode_cle']
            : $trimestreDe($s['date_arrete'] ?? null);

        if ($s['periode_debut'] && $s['periode_fin']) {
            // Le document borne sa période : on compare les bornes, à l'identique.
            $exactes = array_values(array_filter($connues, fn($t) =>
                (string)$t['periode_debut'] === (string)$s['periode_debut']
                && (string)$t['periode_fin'] === (string)$s['periode_fin']));
        } elseif ($cleTrim !== null) {
            // ⚠️ ON NE COMPARE QU'À DES SITUATIONS DE MÊME GRANULARITÉ. Trois relevés
            //    mensuels couvrent le même trimestre sans être ce trimestre : les confondre
            //    ferait passer un trimestre pour « déjà connu » sur la foi d'un mois.
            $exactes = array_values(array_filter($connues, fn($t) =>
                (string)($t['periode_type'] ?? '') === 'trimestre'
                && $trimestreDe($t['periode_fin']) === $cleTrim));
        } else {
            $exactes = [];
        }

        if (count($exactes) === 1) {
            $maj->execute(['DEJA CONNUE',
                'Rapprochée de la situation MBI n°' . $exactes[0]['id'] . ' — même compte, '
                . 'période ' . $exactes[0]['periode_debut'] . ' → ' . $exactes[0]['periode_fin']
                . ' (' . $exactes[0]['periode_type'] . ').',
                (int)$exactes[0]['id'], (int)$s['id']]);
            $bilan['DEJA CONNUE']++;
        } elseif (count($exactes) > 1) {
            $maj->execute(['A VERIFIER',
                count($exactes) . ' situations MBI portent ce compte et cette période : '
                . implode(', ', array_map(fn($t) => 'n°' . $t['id'], $exactes))
                . '. On ne choisit pas à votre place.', null, (int)$s['id']]);
            $bilan['A VERIFIER']++;
        } else {
            $maj->execute(['NOUVELLE',
                'Compte connu de MBI (' . $cands[0]['systeme'] . '), mais aucune situation '
                . 'n’y couvre ' . $s['periode_debut'] . ' → ' . $s['periode_fin'] . '.',
                null, (int)$s['id']]);
            $bilan['NOUVELLE']++;
        }
    }

    crgi_marquer_phase($pdo, $importId, 1, 'A VALIDER', null);
    return $bilan;
}

/**
 * Les situations que MBI connaît et que l'import ne rapporte pas.
 *
 * ⚠️ `ABSENT DU NOUVEAU CORPUS ≠ À SUPPRIMER DE MBI.` Un CRG qui n'a pas été redéposé ne dit
 *    rien du mandat : il peut manquer au dépôt, avoir été édité ailleurs, ou ne plus avoir
 *    lieu d'être. Cette liste informe, elle ne propose aucune action.
 *
 * ⚠️ ON NE COMPARE QUE LES PÉRIODES RÉELLEMENT COUVERTES PAR L'IMPORT. Sans cette borne,
 *    « absentes » vaudrait pour toute l'histoire de MBI, et le chiffre n'aurait aucun sens.
 */
function crgi_absentes_du_corpus(PDO $pdo, int $importId): array
{
    $st = $pdo->prepare(
        'SELECT tr.id, tr.periode_debut, tr.periode_fin, c.code_compte, p.nom AS proprietaire
           FROM crg_trimestres tr
           JOIN proprietaire_comptes_crg c ON c.id = tr.id_compte_mandant
           LEFT JOIN proprietaires p ON p.id = c.id_proprietaire
          WHERE (tr.periode_debut, tr.periode_fin) IN (
                    SELECT DISTINCT g.periode_debut, g.periode_fin FROM crgi_crg g
                     WHERE g.import_id = :i AND g.periode_debut IS NOT NULL)
            AND tr.id NOT IN (
                    SELECT COALESCE(g2.mbi_trimestre_id, 0) FROM crgi_crg g2
                     WHERE g2.import_id = :i2)
          ORDER BY tr.periode_debut, c.code_compte'
    );
    $st->execute([':i' => $importId, ':i2' => $importId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Le bilan de la phase 1, organisé AGENCE → PÉRIODE comme l'écran le demande. */
function crgi_bilan_phase1(PDO $pdo, int $importId): array
{
    $st = $pdo->prepare(
        'SELECT COALESCE(a.nom_agence, g.agence, "(agence indéterminable)") AS agence_vue,
                COALESCE(g.periode_cle, "(période indéterminable)") AS periode,
                COUNT(*) AS situations,
                SUM(g.inventaire_statut = "DEJA CONNUE")    AS connues,
                SUM(g.inventaire_statut = "NOUVELLE")       AS nouvelles,
                SUM(g.inventaire_statut = "NOUVEAU MANDANT") AS mandants_nouveaux,
                SUM(g.inventaire_statut = "CODE PARTAGE")    AS codes_partages,
                SUM(g.inventaire_statut = "A VERIFIER")     AS a_verifier,
                SUM(g.doublon_qualification = "B")          AS complementaires
           FROM crgi_crg g LEFT JOIN agences a ON a.id = g.agence_id
          WHERE g.import_id = ? AND g.doublon_statut <> "REENONCIATION"
          GROUP BY COALESCE(a.nom_agence, g.agence, "(agence indéterminable)"),
                   COALESCE(g.periode_cle, "(période indéterminable)")
          ORDER BY agence_vue, periode'
    );
    $st->execute([$importId]);
    $lignes = $st->fetchAll(PDO::FETCH_ASSOC);

    $tot = $pdo->prepare(
        'SELECT inventaire_statut, COUNT(*) n FROM crgi_crg
          WHERE import_id = ? AND doublon_statut <> "REENONCIATION" GROUP BY inventaire_statut'
    );
    $tot->execute([$importId]);
    $reed = $pdo->prepare('SELECT COUNT(*) FROM crgi_crg WHERE import_id = ?
                           AND doublon_statut = "REENONCIATION"');
    $reed->execute([$importId]);
    $compl = $pdo->prepare('SELECT COUNT(*) FROM crgi_crg WHERE import_id = ?
                            AND doublon_qualification = "B"');
    $compl->execute([$importId]);

    return [
        'par_agence'      => $lignes,
        'totaux'          => $tot->fetchAll(PDO::FETCH_KEY_PAIR),
        'reenonciations'  => (int)$reed->fetchColumn(),
        'complementaires' => (int)$compl->fetchColumn(),
        'absentes'        => crgi_absentes_du_corpus($pdo, $importId),
    ];
}

/** Normalise un libellé pour comparer deux écritures d'une même chose — jamais pour créer une identité. */
function crgi_plat(?string $s): string
{
    $s = strtr((string)$s, ['É'=>'E','È'=>'E','Ê'=>'E','À'=>'A','Â'=>'A','Î'=>'I','Ô'=>'O',
                            'Û'=>'U','Ç'=>'C','é'=>'e','è'=>'e','ê'=>'e','à'=>'a','â'=>'a',
                            'î'=>'i','ô'=>'o','û'=>'u','ç'=>'c']);
    $s = preg_replace('/[^A-Za-z0-9]+/', ' ', mb_strtoupper($s)) ?? $s;
    return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
}

/**
 * PHASE 2 — PATRIMOINE : propriétaire → compte → immeuble → lot, MBI face au nouveau corpus.
 *
 * ⚠️ AUCUNE ÉCRITURE MÉTIER. On lit `proprietaires`, `proprietaire_comptes_crg`, `immeubles`
 *    et `biens` ; on n'écrit que dans `crgi_*`. Rien n'est créé, modifié, archivé ni supprimé.
 *
 * ⚠️ AUCUN FUZZY MATCHING NE CRÉE UNE IDENTITÉ. Une correspondance n'est retenue que si elle
 *    est EXACTE après normalisation. Une ressemblance produit `A ARBITRER` avec le candidat
 *    nommé — jamais un rapprochement d'office.
 *
 * ⚠️ ET UN CHANGEMENT DE LIBELLÉ N'EST PAS UN CHANGEMENT D'IDENTITÉ. Un immeuble retrouvé par
 *    son code mais dont le nom diffère est `MODIFIÉ`, avec son avant/après — pas `NOUVEAU`.
 */
function crgi_phase2(PDO $pdo, int $importId): array
{
    crgi_verrouiller_import($pdo, $importId, 2);
    $etat1 = crgi_phase_validee($pdo, $importId, 1);
    if (!$etat1['validee'] || $etat1['perimee']) {
        throw new RuntimeException(
            'PHASE 1 NON VALIDÉE — la phase 2 ne s’ouvre pas. Confronter le patrimoine sur un '
            . 'inventaire non scellé comparerait MBI à un résultat qui peut encore changer.'
        );
    }
    crgi_marquer_phase($pdo, $importId, 2, 'EN ANALYSE', null);
    $pdo->prepare('DELETE FROM crgi_immeuble WHERE import_id = ?')->execute([$importId]);
    $pdo->prepare('DELETE FROM crgi_lot WHERE import_id = ?')->execute([$importId]);

    crgi_qualifier_comptes($pdo, $importId);
    crgi_extraire_patrimoine($pdo, $importId);
    crgi_confronter_patrimoine($pdo, $importId);

    crgi_marquer_phase($pdo, $importId, 2, 'A VALIDER', null);
    return crgi_bilan_phase2($pdo, $importId)['totaux'];
}

/**
 * Les comptes que MBI ne connaît pas : que représentent-ils ?
 *
 *   A — nouveau compte d'un propriétaire DÉJÀ connu (nom exactement retrouvé) ;
 *   B — nouveau propriétaire ET nouveau compte ;
 *   C — compte déjà présent, non rapproché faute d'écriture identique du code ;
 *   D — indéterminable.
 *
 * ⚠️ LE CANDIDAT EST NOMMÉ, PAS RETENU. `mbi_proprietaire_id` sert à montrer de qui il
 *    s'agirait ; aucune identité n'est créée, et la phase 2 n'écrit rien dans MBI.
 */
function crgi_qualifier_comptes(PDO $pdo, int $importId): void
{
    $st = $pdo->prepare(
        'SELECT id, compte, proprietaire FROM crgi_crg
          WHERE import_id = ? AND inventaire_statut IN ("NOUVEAU MANDANT", "CODE PARTAGE")'
    );
    $st->execute([$importId]);
    $inconnus = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$inconnus) {
        return;
    }
    // Les propriétaires de MBI, par nom normalisé. Une collision de nom rend le rapprochement
    // ambigu : deux personnes différentes peuvent porter le même libellé.
    $parNom = [];
    foreach ($pdo->query('SELECT id, nom FROM proprietaires', PDO::FETCH_ASSOC) as $p) {
        $parNom[crgi_plat((string)$p['nom'])][] = (int)$p['id'];
    }
    // Les codes de compte connus, comparés SANS leurs zéros de tête : c'est la seule
    // différence d'écriture qu'on tolère, et elle produit un « C », pas un rapprochement.
    $parCodeNu = [];
    foreach ($pdo->query('SELECT id, code_compte, systeme FROM proprietaire_comptes_crg',
                         PDO::FETCH_ASSOC) as $c) {
        $parCodeNu[ltrim((string)$c['code_compte'], '0')][] = $c;
    }
    $maj = $pdo->prepare(
        'UPDATE crgi_crg SET compte_qualification = ?, compte_qualif_motif = ?,
                mbi_proprietaire_id = ? WHERE id = ?'
    );
    foreach ($inconnus as $c) {
        $nu = ltrim((string)$c['compte'], '0');
        if (!empty($parCodeNu[$nu])) {
            $codes = implode(', ', array_map(fn($x) => $x['code_compte'] . ' (' . $x['systeme'] . ')',
                                             $parCodeNu[$nu]));
            $maj->execute(['C', 'Le compte ' . $c['compte'] . ' existe dans MBI sous une autre '
                . 'écriture : ' . $codes . '. Le rapprochement demande votre décision.',
                null, (int)$c['id']]);
            continue;
        }
        $nom = crgi_plat((string)$c['proprietaire']);
        $cands = $parNom[$nom] ?? [];
        if (count($cands) === 1) {
            $maj->execute(['A', 'Nouveau compte d’un propriétaire que MBI connaît déjà : « '
                . $c['proprietaire'] . ' » (propriétaire n°' . $cands[0] . '). '
                . 'Candidat NOMMÉ, aucune identité créée.', $cands[0], (int)$c['id']]);
        } elseif (count($cands) > 1) {
            $maj->execute(['D', count($cands) . ' propriétaires de MBI portent le nom « '
                . $c['proprietaire'] . ' » : on ne choisit pas à votre place.',
                null, (int)$c['id']]);
        } elseif ($nom === '') {
            $maj->execute(['D', 'Aucun nom de propriétaire lu sur ce CRG : rien à confronter.',
                null, (int)$c['id']]);
        } else {
            $maj->execute(['B', 'Ni le compte ' . $c['compte'] . ' ni le propriétaire « '
                . $c['proprietaire'] . ' » ne sont connus de MBI.', null, (int)$c['id']]);
        }
    }
}

/** Lit les immeubles et les lots de chaque CRG — une seule lecture du PDF par pièce. */
/**
 * LA COMMANDE DE LECTURE D'UNE PIÈCE — le moteur dépend de la FAMILLE, jamais de l'agence.
 *
 * ⚠️ `ÉDITEUR → MOTEUR → VARIANTE`. Les phases 2, 3 et 4 lisaient toutes par le même
 *    extracteur, taillé pour la grammaire SPI. Un document ICS n'y était pas « mal lu » : il
 *    n'était pas lu du tout, et le bilan affichait de vrais zéros sans le moindre signal. La
 *    famille ICS passe donc par son moteur certifié, `crg_integration_ics.py`, qui appelle
 *    `crg_ics_core` — le lecteur des variantes `lyon` et `emery_immo`. On ne recopie aucune
 *    de ses règles ici : une seconde autorité divergerait à la première correction.
 */
// ⚠️ UN SÉPARATEUR SE NOMME. Écrit en caractère brut dans le source, il devenait invisible :
//    on lisait `. "" .` et personne ne pouvait deviner ce qui séparait le chemin de la famille.
const CRGI_SEP_FAMILLE = "";

function crgi_commande_lecture(string $python, string $scriptSpi, string $chemin,
                               ?string $format, string $plages, string $quoi): string
{
    $ics = ['lyon', 'emery_immo'];
    if (in_array((string)$format, $ics, true)) {
        $pont = realpath(__DIR__ . '/../scripts/crg_integration_ics.py');
        if (!$pont) {
            throw new RuntimeException('MOTEUR ABSENT : scripts/crg_integration_ics.py');
        }
        return escapeshellarg($python) . ' ' . escapeshellarg($pont) . ' '
             . escapeshellarg($chemin) . ' ' . escapeshellarg((string)$format) . ' '
             . escapeshellarg($plages) . ' ' . escapeshellarg($quoi);
    }
    return escapeshellarg($python) . ' ' . escapeshellarg($scriptSpi) . ' '
         . escapeshellarg($chemin) . ' ' . escapeshellarg($plages);
}

// ⚠️ CE QUI N'EST PAS UN BÂTIMENT NE PEUT PAS ÊTRE LE BÂTIMENT QU'ON CHERCHE. La table
//    `immeubles` de MBI porte 245 « Appartement », 22 « Local commercial » et 10 « Garage » —
//    des LOTS rangés là par une reprise ancienne, à la même adresse et sous le même nom que
//    leur immeuble. Ils devenaient des « homonymes » : 33 des 41 questions d'identité d'un
//    corpus n'opposaient pas deux bâtiments, elles opposaient un bâtiment à ses propres lots.
//
// ⚠️ ON EXCLUT, ON N'ÉNUMÈRE PAS. La liste dit ce qui est DÉMONTRÉ non contributif ; un type
//    inconnu — ou absent — reste candidat (`NOUVEAUTÉ ≠ EXCLUSION`). « Maison » n'y figure
//    pas : une maison EST un immeuble au sens du compte rendu.
const CRGI_TYPES_DE_LOT = ['APPARTEMENT', 'LOCAL COMMERCIAL', 'GARAGE', 'PARKING', 'CAVE',
                           'BUREAU', 'BOX', 'STUDIO'];

/**
 * LE MOTIF QUI DIT « CE N'EST PAS LE DOCUMENT QUI EST AMBIGU, C'EST MBI QUI SE RÉPÈTE ».
 *
 * ⚠️ UNE CONSTANTE, PARCE QUE DEUX ENDROITS S'EN SERVENT. La confrontation l'écrit, la file
 *    d'arbitrage le lit pour séparer les deux questions. Recopier la phrase des deux côtés
 *    aurait produit, au premier mot changé, une famille d'arbitrage vide sans que rien ne le
 *    dise — le genre de panne qui a l'air d'une amélioration.
 */
const CRGI_MOTIF_MBI_REPETE = 'MBI porte ';

/**
 * LE TYPE DÉCLARÉ EST-IL CONTREDIT PAR LA STRUCTURE ?
 *
 * ⚠️ `TYPE STOCKÉ ≠ NATURE DÉMONTRÉE.` Emmanuel, 06/09/2026. Exclure un candidat sur le seul
 *    champ `type_immeuble` dans une base qui contient justement des typages faux, c'est
 *    refaire la faute qu'on prétend éviter — en pire, puisque la disparition est silencieuse.
 *    Mesure sur le corpus : **18 objets typés « Appartement » ou « Local commercial » portent
 *    PLUSIEURS biens**. Un lot ne contient pas de lots : leur type est faux, et les écarter
 *    aurait perdu 18 candidats sans une trace.
 *
 * ⚠️ LA PREUVE EST STRUCTURELLE, PAS DÉCLARATIVE. Elle ne lit aucun libellé : elle compte ce
 *    que l'objet PORTE. Plusieurs biens rattachés, ou plusieurs lots nommés, ou un nombre de
 *    lots déclaré supérieur à un — l'objet se comporte comme un bâtiment, quoi qu'annonce son
 *    étiquette.
 */
function crgi_type_contredit(array $c): bool
{
    return (int)($c['biens_portes'] ?? 0) > 1
        || (int)($c['lots_nommes'] ?? 0) > 1
        || (int)($c['nb_lots'] ?? 0) > 1;
}

/**
 * LA NATURE DE LOT EST-ELLE DÉMONTRÉE PAR UN CORROBORANT INDÉPENDANT ?
 *
 * ⚠️ DEUX VERSIONS DE CETTE FONCTION ONT ÉTÉ FAUSSES, ET DE LA MÊME FAÇON.
 *    ① « rien ne contredit le type » — une certitude obtenue par DÉFAUT : 259 objets
 *      « confirmés » sans qu'on ait rien démontré.
 *    ② « l'objet porte UN bien avec un numéro de lot » — une cardinalité, pas une nature :
 *      un immeuble réel composé d'un seul lot présente exactement cette structure. Elle
 *      prouve « pas de structure multi-lots observée », rien de plus. 256 « confirmés ».
 *
 * ⚠️ ET LE CORROBORANT DOIT ÊTRE INDÉPENDANT DE LA SOURCE QU'IL CORROBORE. Emmanuel,
 *    06/09/2026. `type_immeuble` et `biens.numero_lot` viennent vraisemblablement de la MÊME
 *    reprise ancienne : deux manifestations d'une seule donnée ne font pas deux preuves, elles
 *    reproduisent la même erreur deux fois.
 *
 * ⚠️ DEUX CORROBORANTS RÉELLEMENT INDÉPENDANTS SONT ADMIS :
 *      ❶ un BÂTIMENT DÉMONTRÉ — qui porte plusieurs biens, donc dont la nature ne repose sur
 *        aucun champ déclaratif — existe à la même adresse. C'est l'existence d'un AUTRE objet
 *        qui parle, pas une étiquette du nôtre.
 *      ❷ un BAIL attaché au bien : une source métier distincte de la reprise du patrimoine.
 *    Mesure sur le corpus : **4 objets sur 277** ont un corroborant. 18 sont contredits.
 *    **255 n'ont aucune preuve structurelle suffisante — et restent candidats.**
 *    Le nombre s'effondre, et c'est le résultat juste : il n'a jamais été démontré.
 */
function crgi_type_confirme(array $c): bool
{
    return (int)($c['parent_demontre'] ?? 0) > 0 || (int)($c['baux_portes'] ?? 0) > 0;
}

/**
 * Les candidats qui peuvent être le bâtiment cherché.
 *
 * ⚠️ TROIS ÉTATS, ET UN SEUL ÉCARTE :
 *      CONFIRMÉ  — type de lot ET preuve positive de la nature de lot → écarté.
 *      CONTREDIT — type de lot mais structure de bâtiment → reste candidat, et le contredit
 *                  devient une anomalie de qualité de la base.
 *      SANS STRUCTURE EXPLOITABLE — ni preuve, ni contradiction → RESTE CANDIDAT.
 *    Un type inconnu reste candidat lui aussi (`NOUVEAUTÉ ≠ EXCLUSION`). Aucune exclusion ne
 *    se fait sur une absence : c'est le seul moyen de ne pas perdre un candidat en silence.
 */
function crgi_candidats_batiments(array $cands): array
{
    // Une liste vide EST une réponse : MBI porte des lots à cette adresse, pas de bâtiment.
    return array_values(array_filter($cands, function ($c) {
        $typeDeLot = in_array(mb_strtoupper(trim((string)($c['type_immeuble'] ?? ''))),
                              CRGI_TYPES_DE_LOT, true);
        return !$typeDeLot || crgi_type_contredit($c) || !crgi_type_confirme($c);
    }));
}

/**
 * LE CODE IMPRIMÉ SUR LE CRG, RETROUVÉ DANS `code_crg` — une preuve, pas une ressemblance.
 *
 * ⚠️ MBI ÉCRIT PARFOIS LE CODE COMPLET, LE CRG N'EN IMPRIME QUE LA FIN. « 01S01-0067 » côté
 *    MBI, « 0067 » côté document : le segment final est le code de l'immeuble chez le
 *    gestionnaire, ce qui précède est le préfixe d'agence et d'activité. On exige donc le
 *    segment ENTIER après le tiret — jamais une inclusion libre, qui confondrait « 67 » et
 *    « 0067 ».
 *
 * ⚠️ ET PARFOIS IL L'ÉCRIT SANS PRÉFIXE : « 01040087 » des deux côtés. Exiger le tiret rendait
 *    la preuve AVEUGLE sur tout un corpus — 14 questions d'identité posées alors que MBI
 *    portait le code, à l'identique, dans la colonne faite pour lui. La forme du code
 *    appartient au gestionnaire ; l'égalité, elle, ne change pas.
 *
 * ⚠️ `code_crg` PRIME SUR `reference_immeuble`, ET C'EST DÉLIBÉRÉ. Les deux colonnes indexent
 *    les candidats, mais une seule est faite pour porter le code du gestionnaire. Quand un
 *    seul candidat porte le code LÀ, c'est MBI lui-même qui désigne — pas une ressemblance.
 *    Le motif le dit, pour que la décision reste auditable.
 */
function crgi_candidat_par_code_crg(array $cands, string $code): ?array
{
    $code = ltrim(trim($code), '0');
    if ($code === '') {
        return null;
    }
    $trouves = [];
    foreach ($cands as $c) {
        $plein = trim((string)($c['code_crg'] ?? ''));
        if ($plein === '') {
            continue;
        }
        $segment = str_contains($plein, '-') ? substr($plein, strrpos($plein, '-') + 1) : $plein;
        if (ltrim($segment, '0') === $code) {
            $trouves[] = $c;
        }
    }
    return count($trouves) === 1 ? $trouves[0] : null;
}

/**
 * TOUS CES CANDIDATS SONT-ILS LE MÊME IMMEUBLE, ENREGISTRÉ PLUSIEURS FOIS ?
 *
 * ⚠️ « PLUSIEURS CANDIDATS » RECOUVRE DEUX SITUATIONS OPPOSÉES. Ou bien MBI porte deux
 *    BÂTIMENTS DIFFÉRENTS de même nom — seul un humain sait lequel le document désigne. Ou
 *    bien MBI porte le MÊME immeuble plusieurs fois, sous le même code de gestion : ce n'est
 *    plus le document qui est ambigu, c'est la base qui se contredit, et la réponse est un
 *    ménage à faire dans MBI, pas une relecture du PDF. `238 Route de Vienne` y existe TROIS
 *    fois sous le code `01040087`, créé par trois reprises successives.
 *
 * ⚠️ ET LA CONDITION SE TESTE, PARCE QU'ELLE EST FACILE À ÉCRIRE FAUSSE. La première version
 *    comparait le nombre de codes DISTINCTS au nombre de candidats : `array_unique` ramenant
 *    trois codes identiques à un seul, la branche n'a jamais pu se déclencher — un code mort
 *    qui avait l'air de fonctionner. On exige donc DEUX choses, séparément : tous les
 *    candidats portent un code, et ce code est le même.
 */
function crgi_meme_immeuble_repete(array $cands): ?string
{
    if (count($cands) < 2) {
        return null;
    }
    // ⚠️ DEUX PREUVES, ET CHACUNE SUFFIT — parce que MBI ne remplit pas toujours les deux
    //    colonnes. Le code de gestion est sa clé propre ; l'adresse est ce que le bâtiment
    //    EST. Sur un corpus, 10 groupes se démontrent par le code et 7 par l'adresse seule ;
    //    n'en garder qu'une laissait 7 contradictions de la base passer pour des ambiguïtés
    //    du document. On exige que TOUS les candidats portent la valeur, et la même.
    foreach ([
        ['code de gestion', fn($c) => trim((string)($c['code_crg'] ?? ''))],
        ['adresse',         fn($c) => crgi_plat((string)($c['adresse_1'] ?? ''))],
    ] as [$quoi, $lire]) {
        $vals = array_map($lire, $cands);
        $portees = array_filter($vals, fn($v) => $v !== '');
        if (count($portees) !== count($cands)) {
            continue;                   // au moins un candidat ne porte pas cette valeur
        }
        $distincts = array_unique($portees);
        if (count($distincts) === 1) {
            return $quoi . ' « ' . (string)reset($distincts) . ' »';
        }
    }
    return null;
}

/**
 * LA CLÉ D'IDENTITÉ D'UN IMMEUBLE, telle que le document la donne.
 *
 * ⚠️ LE CODE D'ABORD, L'ADRESSE ENSUITE — et jamais les deux mélangés. C'est la même clé qui
 *    sert à grouper les questions et à retrouver une décision : si les deux divergeaient, une
 *    réponse enregistrée ne serait jamais relue.
 */
function crgi_cle_immeuble(array $imm): string
{
    return (string)($imm['code'] ?: (crgi_plat((string)$imm['nom']) . '|' . (string)$imm['code_postal']));
}

/**
 * CE QU'EMMANUEL A DÉJÀ TRANCHÉ POUR CETTE IDENTITÉ — ou rien.
 *
 * ⚠️ BORNÉE PAR L'AGENCE. `UN CODE DE COMPTE N'EST JAMAIS GLOBAL` vaut aussi pour un code
 *    d'immeuble : « 0081 » chez une régie n'est pas « 0081 » chez une autre. Une mémoire non
 *    bornée ferait pire que pas de mémoire — elle rattacherait des immeubles étrangers.
 */
function crgi_identite_apprise(PDO $pdo, string $type, string $agence, string $cle): ?array
{
    static $cache = [];
    $k = $type . '|' . $agence . '|' . $cle;
    if (array_key_exists($k, $cache)) {
        return $cache[$k];
    }
    $st = $pdo->prepare('SELECT * FROM crgi_identite WHERE type = ? AND agence = ? AND cle = ?');
    $st->execute([$type, $agence, $cle]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($r) {
        $pdo->prepare('UPDATE crgi_identite SET reutilisations = reutilisations + 1 WHERE id = ?')
            ->execute([(int)$r['id']]);
    }
    return $cache[$k] = $r;
}

/**
 * UNE PREUVE NOUVELLE CONTREDIT-ELLE LA DÉCISION MÉMORISÉE ?
 *
 * ⚠️ UNE MÉMOIRE QUI NE SAIT PAS SE TAIRE EST PIRE QU'UNE ABSENCE DE MÉMOIRE. Elle donne
 *    l'apparence de l'apprentissage tout en écrasant, en silence, une information que le
 *    document apporte pour la première fois. On compare donc ce que la décision affirme à ce
 *    que le document imprime AUJOURD'HUI ; à la moindre divergence, la question se rouvre —
 *    avec l'ancienne décision citée, pour qu'Emmanuel arbitre en connaissance de cause.
 *
 * ⚠️ ON NE COMPARE QUE CE QUI EST COMPARABLE. Le nom et le code postal sont imprimés par le
 *    document ; la ville, elle, est souvent tronquée ou absente selon le gabarit. Comparer un
 *    champ que le document ne donne pas toujours ferait rouvrir des questions déjà réglées —
 *    et l'agent redeviendrait bavard pour de mauvaises raisons.
 */
function crgi_contredit_la_memoire(PDO $pdo, array $appris, array $imm): ?string
{
    if (!$appris['mbi_id']) {
        return null;                          // « créer un immeuble distinct » : rien à confronter
    }
    $st = $pdo->prepare('SELECT nom_immeuble, adresse_1, code_postal FROM immeubles WHERE id = ?');
    $st->execute([(int)$appris['mbi_id']]);
    $mbi = $st->fetch(PDO::FETCH_ASSOC);
    if (!$mbi) {
        return 'La décision du ' . substr((string)$appris['decide_le'], 0, 10) . ' désignait '
             . 'l’immeuble MBI n°' . (int)$appris['mbi_id'] . ', qui n’existe plus. La question '
             . 'est rouverte : une décision mémorisée ne s’applique pas à un objet disparu.';
    }
    $cpDoc = (string)($imm['code_postal'] ?? '');
    $cpMbi = (string)($mbi['code_postal'] ?? '');
    if ($cpDoc !== '' && $cpMbi !== '' && $cpDoc !== $cpMbi) {
        return 'CONTRADICTION AVEC UNE DÉCISION MÉMORISÉE — le '
             . substr((string)$appris['decide_le'], 0, 10) . ' cette identité a été rattachée à '
             . 'l’immeuble MBI n°' . (int)$appris['mbi_id'] . ' (' . $cpMbi . '), mais le '
             . 'document imprime aujourd’hui le code postal ' . $cpDoc . '. La décision n’est '
             . 'pas appliquée en silence : elle est remise en arbitrage avec sa preuve.';
    }
    return null;
}

function crgi_extraire_patrimoine(PDO $pdo, int $importId): void
{
    $python = getenv('CRG_PYTHON') ?: (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
    $script = realpath(__DIR__ . '/../scripts/crg_integration_phase2.py');
    if (!$script) {
        throw new RuntimeException('MOTEUR ABSENT : scripts/crg_integration_phase2.py');
    }
    // ⚠️ LE FORMAT DÉCIDE DU MOTEUR. Les primitives d'intégration ne connaissent qu'une seule
    //    grammaire — celle de SPI. Mesuré le 03/09/2026 sur un corpus ICS complet : la phase 0
    //    identifiait 235 comptes rendus, et les phases 2 et 3 rendaient **0 immeuble, 0 lot,
    //    0 occupation**, l'empreinte de la phase 3 étant celle de la chaîne vide. Rien n'était
    //    en panne : personne n'avait jamais lu ce document. Chaque famille passe donc par SON
    //    moteur certifié — voir `crg_integration_ics.py`.
    $st = $pdo->prepare(
        'SELECT c.id, c.page_debut, c.page_fin, c.format, p.chemin
           FROM crgi_crg c JOIN crgi_piece p ON p.id = c.piece_id
          WHERE c.import_id = ? AND c.doublon_statut <> "REENONCIATION" ORDER BY c.page_debut'
    );
    $st->execute([$importId]);
    $insImm = $pdo->prepare(
        'INSERT INTO crgi_immeuble (import_id, crg_id, code, nom, code_postal, ville, page)
         VALUES (?,?,?,?,?,?,?)'
    );
    $insLot = $pdo->prepare(
        'INSERT INTO crgi_lot (import_id, crg_id, reference, code_immeuble, numero, libelle,
                               type_bien, vendu, locataire, page)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    );
    // ⚠️ UNE PIÈCE, UNE LECTURE. La première version appelait le moteur CRG par CRG : sur un
    //    document de 587 Mo et 266 comptes rendus, cela faisait 266 lectures complètes du PDF
    //    et l'analyse ne finissait jamais. On envoie toutes les plages d'un coup.
    $parPiece = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $crg) {
        // La famille voyage avec la plage : une pièce ne mélange pas deux éditeurs.
        $parPiece[(string)$crg['chemin'] . CRGI_SEP_FAMILLE . (string)$crg['format']][] =
            ['id' => (int)$crg['id'], 'debut' => (int)$crg['page_debut'],
             'fin' => (int)$crg['page_fin']];
    }
    foreach ($parPiece as $clef => $plages) {
        [$chemin, $format] = explode(CRGI_SEP_FAMILLE, $clef, 2);
        $fichier = tempnam(sys_get_temp_dir(), 'crgi_');
        file_put_contents($fichier, json_encode($plages));
        $cmd = crgi_commande_lecture($python, $script, $chemin, $format, $fichier, 'patrimoine');
        $sortie = trim((string)@shell_exec($cmd . ' 2>&1'));
        @unlink($fichier);
        $r = json_decode($sortie, true);
        if (!is_array($r)) {
            throw new RuntimeException('MOTEUR PATRIMOINE MUET OU ILLISIBLE : '
                                     . mb_substr($sortie, 0, 300));
        }
        // ⚠️ LA PAGE RENDUE EST DÉJÀ ABSOLUE — ON NE RAJOUTE PAS L'OFFSET. Le lecteur reçoit
        //    la plage du CRG et sa page de départ (`extraire(textes[debut-1:fin], debut)`) :
        //    il rend donc la page du DOCUMENT, pas un rang dans le bloc. Ce code ajoutait
        //    `page_debut - 1` par-dessus, et la page doublait : sur un dépôt de 906 pages,
        //    `crgi_immeuble.page` montait à 1809 — soit 905 + 905 - 1. Mesuré le 03/09/2026 :
        //    117 immeubles et 172 lots hors bornes sur VIENNE, 122 et 184 sur CHAPONOST.
        //
        // ⚠️ CE N'EST PAS UN DÉTAIL D'AFFICHAGE. Toute la promesse « 1 CLIC = PREUVE » repose
        //    sur ce numéro : un arbitrage d'immeuble sur deux renvoyait vers une page qui
        //    n'existe pas. `crgi_occupation` et `crgi_mouvement`, eux, étaient justes — d'où
        //    l'incohérence entre deux phases lisant le même document.
        foreach ($r as $bloc) {
            $crgId = (int)$bloc['id'];
            foreach ($bloc['immeubles'] ?? [] as $i) {
                $insImm->execute([$importId, $crgId, $i['code'], $i['nom'],
                                  $i['code_postal'], $i['ville'], (int)$i['page']]);
            }
            foreach ($bloc['lots'] ?? [] as $l) {
                // ⚠️ LA CATÉGORIE S'AJOUTE, LE LIBELLÉ BRUT RESTE. Voir crgi_type_de_bien() :
                //    plus de cinquante graphies pour huit natures, et « VENDU » qui est un
                //    ÉTAT écrit à la place du type.
                $tb = crgi_type_de_bien($l['libelle'] ?? null);
                $insLot->execute([$importId, $crgId, $l['reference'], $l['code_immeuble'],
                                  $l['numero'], $l['libelle'], $tb['type'], $tb['vendu'] ? 1 : 0,
                                  $l['locataire'], (int)$l['page']]);
            }
        }
    }
}

/** Confronte chaque immeuble et chaque lot lu à ce que MBI enregistre. */
function crgi_confronter_patrimoine(PDO $pdo, int $importId): void
{
    // Les immeubles de MBI, par référence et par adresse normalisée.
    $parRef = $parAdresse = [];
    // ⚠️ LE CANDIDAT PORTE SA STRUCTURE, PAS SEULEMENT SON ÉTIQUETTE. Sans ces deux comptes,
    //    l'exclusion par type serait aveugle aux 18 objets dont le type est démontré faux.
    foreach ($pdo->query(
        'SELECT i.id, i.reference_immeuble, i.code_crg, i.nom_immeuble, i.adresse_1,
                i.code_postal, i.ville, i.type_immeuble, COALESCE(i.nb_lots, 0) AS nb_lots,
                (SELECT COUNT(*) FROM biens b WHERE b.id_immeuble = i.id) AS biens_portes,
                (SELECT COUNT(DISTINCT b.numero_lot) FROM biens b
                  WHERE b.id_immeuble = i.id AND TRIM(COALESCE(b.numero_lot, "")) <> "")
                  AS lots_nommes,
                -- ⚠️ LES DEUX CORROBORANTS INDÉPENDANTS DU CHAMP `type`. L existence d un
                --    bâtiment DÉMONTRÉ (plusieurs biens) à la même adresse, et un bail — une
                --    source métier distincte de la reprise du patrimoine. Sans eux, la nature
                --    de lot n est pas démontrée, et l objet reste candidat.
                (SELECT COUNT(*) FROM immeubles p
                  WHERE p.id <> i.id AND TRIM(COALESCE(i.adresse_1, "")) <> ""
                    AND UPPER(TRIM(p.adresse_1)) = UPPER(TRIM(i.adresse_1))
                    AND p.code_postal = i.code_postal
                    AND (SELECT COUNT(*) FROM biens b2 WHERE b2.id_immeuble = p.id) > 1)
                  AS parent_demontre,
                (SELECT COUNT(*) FROM bien_baux bb JOIN biens b3 ON b3.id = bb.id_bien
                  WHERE b3.id_immeuble = i.id) AS baux_portes
           FROM immeubles i', PDO::FETCH_ASSOC) as $i) {
        foreach ([$i['reference_immeuble'], $i['code_crg']] as $ref) {
            if ($ref !== null && $ref !== '') {
                $parRef[ltrim((string)$ref, '0')][] = $i;
            }
        }
        $cle = crgi_plat((string)$i['nom_immeuble']) . '|' . (string)$i['code_postal'];
        $parAdresse[$cle][] = $i;
        $cle2 = crgi_plat((string)$i['adresse_1']) . '|' . (string)$i['code_postal'];
        $parAdresse[$cle2][] = $i;
    }

    $st = $pdo->prepare('SELECT i.*, c.agence FROM crgi_immeuble i
                           JOIN crgi_crg c ON c.id = i.crg_id WHERE i.import_id = ?');
    $st->execute([$importId]);
    $maj = $pdo->prepare(
        'UPDATE crgi_immeuble SET statut = ?, mbi_immeuble_id = ?, avant_apres = ?, motif = ?
          WHERE id = ?'
    );
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $imm) {
        // ⚠️ ON DEMANDE À LA MÉMOIRE AVANT DE POSER LA QUESTION. Sans cela, le même homonyme
        //    revient à chaque trimestre : 33 questions sur un dépôt, et 33 de plus au suivant,
        //    indéfiniment. Une décision d'identité vaut jusqu'à ce qu'on la retire.
        $appris = crgi_identite_apprise($pdo, 'IMMEUBLE', (string)$imm['agence'],
                                        crgi_cle_immeuble($imm));
        if ($appris) {
            // ⚠️ UNE MÉMOIRE N'EST PAS UNE VÉRITÉ ÉTERNELLE. Si le document désigne aujourd'hui
            //    un objet MBI que la décision d'hier écartait, appliquer l'ancienne réponse en
            //    silence serait le pire des deux mondes : on aurait l'air d'avoir appris tout
            //    en écrasant une preuve nouvelle. `MÊME SITUATION → RÉUTILISER ; PREUVE NOUVELLE
            //    CONTRADICTOIRE → RÉARBITRER` — Emmanuel, 04/09/2026.
            $contredit = crgi_contredit_la_memoire($pdo, $appris, $imm);
            if ($contredit) {
                $maj->execute(['A ARBITRER', null, null, $contredit, (int)$imm['id']]);
                continue;
            }
            $maj->execute([$appris['mbi_id'] ? 'IDENTIQUE' : 'NOUVEAU',
                           $appris['mbi_id'] ?: null, null,
                           'Identité déjà tranchée le ' . substr((string)$appris['decide_le'], 0, 10)
                           . ' : « ' . $appris['choix'] . ' ». Décision réutilisée, la question '
                           . 'n’est pas reposée.',
                           (int)$imm['id']]);
            continue;
        }
        $cands = $imm['code'] ? ($parRef[ltrim((string)$imm['code'], '0')] ?? []) : [];
        $par = 'sa référence ' . $imm['code'];
        if (!$cands) {
            $cle = crgi_plat((string)$imm['nom']) . '|' . (string)$imm['code_postal'];
            $cands = $parAdresse[$cle] ?? [];
            $par = 'son nom et son code postal';
        }
        // ⚠️ UN MÊME IMMEUBLE INDEXÉ DEUX FOIS N'EST PAS UNE AMBIGUÏTÉ. J'indexais chaque
        //    immeuble sous sa référence ET son code CRG, puis sous son nom ET son adresse :
        //    quand deux de ces valeurs coïncidaient, il apparaissait en double et le
        //    rapprochement devenait « 2 candidats — à arbitrer » alors qu'il n'y en avait
        //    qu'un. Soixante et un immeubles ont été envoyés à l'arbitrage pour ce défaut.
        $vus = [];
        foreach ($cands as $c) {
            $vus[(int)$c['id']] = $c;
        }
        $cands = array_values($vus);
        if (!$cands) {
            $maj->execute(['NOUVEAU', null, null,
                'Aucun immeuble de MBI ne porte cette référence ni cette adresse.',
                (int)$imm['id']]);
            continue;
        }
        // ⚠️ AVANT DE POSER LA QUESTION, ON REGARDE CE QU'ON SAIT DÉJÀ. Deux preuves, dans cet
        //    ordre, et l'une n'a JAMAIS contredit l'autre sur les 41 homonymes mesurés :
        //    ❶ les candidats qui ne sont pas des bâtiments ne peuvent pas être ce bâtiment ;
        //    ❷ le code imprimé sur le CRG se retrouve tel quel dans `code_crg`.
        //    Sans elles, 41 questions ; avec elles, 3 — les trois où deux vrais bâtiments
        //    portent le même nom à la même adresse, et là seul un humain peut trancher.
        $preuve = '';
        if (count($cands) > 1) {
            $batiments = crgi_candidats_batiments($cands);
            if (count($batiments) < count($cands)) {
                // ⚠️ ON DIT AUSSI CE QU'ON A GARDÉ MALGRÉ SON TYPE. Un candidat typé lot mais
                //    qui porte plusieurs biens reste en lice : son étiquette est démentie par
                //    sa structure, et la taire ferait disparaître un candidat en silence.
                $malgre = array_filter($batiments, fn($c) => in_array(
                    mb_strtoupper(trim((string)($c['type_immeuble'] ?? ''))),
                    CRGI_TYPES_DE_LOT, true));
                $preuve = 'les autres candidats sont des LOTS de MBI (appartement, local, '
                        . 'garage), pas des bâtiments'
                        . ($malgre ? ' — n°' . implode(', n°', array_column($malgre, 'id'))
                                   . ' porte un type de lot mais PLUSIEURS biens : son type est '
                                   . 'démenti par sa structure, il reste candidat' : '');
                $cands = $batiments;
            }
        }
        if (!$cands) {
            // Tous les homonymes étaient des lots : ce bâtiment-là, MBI ne le porte pas.
            $maj->execute(['NOUVEAU', null, null,
                'Les seuls objets de MBI portant ce nom et ce code postal sont des LOTS '
                . '(appartement, local, garage) — aucun bâtiment. L’immeuble serait créé.',
                (int)$imm['id']]);
            continue;
        }
        if (count($cands) > 1) {
            $exact = crgi_candidat_par_code_crg($cands, (string)$imm['code']);
            if ($exact) {
                $preuve = 'son code « ' . $imm['code'] .' » est celui de `code_crg` n°'
                        . $exact['id'] . ' (« ' . $exact['code_crg'] . ' »)';
                $cands = [$exact];
            }
        }
        if (count($cands) > 1) {
            // ⚠️ « PLUSIEURS CANDIDATS » RECOUVRE DEUX SITUATIONS OPPOSÉES, ET LA QUESTION
            //    N'EST PAS LA MÊME. Ou bien MBI porte deux BÂTIMENTS DIFFÉRENTS de même nom —
            //    seul un humain sait lequel le document désigne. Ou bien MBI porte le MÊME
            //    immeuble PLUSIEURS FOIS, sous le même code de gestion : ce n'est plus le
            //    document qui est ambigu, c'est MBI qui se contredit, et la décision porte sur
            //    le ménage à faire dans MBI. Dire « 3 immeubles correspondent » dans les deux
            //    cas envoie chercher une réponse dans le mauvais document.
            $repete = crgi_meme_immeuble_repete($cands);
            $motif = $repete !== null
                ? CRGI_MOTIF_MBI_REPETE . count($cands) . ' FOIS le même immeuble — même '
                  . $repete . ' : n°' . implode(', n°', array_column($cands, 'id'))
                  . '. Le document n’est pas ambigu ; c’est MBI qui se répète. Auquel '
                  . 'rattacher, et lequel dédoublonner ?'
                : count($cands) . ' immeubles de MBI correspondent par ' . $par
                  . ' : n°' . implode(', n°', array_column($cands, 'id'))
                  . '. Aucune identité n’est retenue d’office.';
            $maj->execute(['A ARBITRER', null, null, $motif, (int)$imm['id']]);
            continue;
        }
        $m = $cands[0];
        // ⚠️ UN LIBELLÉ QUI CHANGE N'EST PAS UNE IDENTITÉ QUI CHANGE. On dit CE QUI diffère.
        $ecarts = [];
        foreach ([['nom', 'nom_immeuble'], ['code_postal', 'code_postal'], ['ville', 'ville']]
                 as [$notre, $leur]) {
            if (crgi_plat((string)$imm[$notre]) !== crgi_plat((string)$m[$leur])) {
                $ecarts[] = $notre . ' : « ' . $m[$leur] . ' » → « ' . $imm[$notre] . ' »';
            }
        }
        $maj->execute([$ecarts ? 'MODIFIE' : 'IDENTIQUE', (int)$m['id'],
                       $ecarts ? implode("\n", $ecarts) : null,
                       'Rapproché de l’immeuble MBI n°' . $m['id'] . ' par ' . $par
                       . ($preuve ? ' — ' . $preuve . '.' : '.'),
                       (int)$imm['id']]);
    }

    // Les biens de MBI, par référence.
    $biens = [];
    foreach ($pdo->query('SELECT id, reference_bien, reference_externe, id_immeuble FROM biens',
                         PDO::FETCH_ASSOC) as $b) {
        foreach ([$b['reference_bien'], $b['reference_externe']] as $ref) {
            if ($ref !== null && $ref !== '') {
                $biens[crgi_plat((string)$ref)][] = $b;
            }
        }
    }
    $st = $pdo->prepare('SELECT * FROM crgi_lot WHERE import_id = ?');
    $st->execute([$importId]);
    $majL = $pdo->prepare(
        'UPDATE crgi_lot SET statut = ?, mbi_bien_id = ?, motif = ? WHERE id = ?'
    );
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $lot) {
        $cands = $biens[crgi_plat((string)$lot['reference'])] ?? [];
        if (!$cands && $lot['numero']) {
            $cands = $biens[crgi_plat((string)$lot['numero'])] ?? [];
        }
        $vus = [];
        foreach ($cands as $c) {
            $vus[(int)$c['id']] = $c;
        }
        $cands = array_values($vus);
        if (!$cands) {
            $majL->execute(['NOUVEAU', null,
                'Aucun bien de MBI ne porte la référence ' . $lot['reference'] . '.',
                (int)$lot['id']]);
        } elseif (count($cands) > 1) {
            $majL->execute(['A ARBITRER', null,
                count($cands) . ' biens de MBI portent cette référence : n°'
                . implode(', n°', array_column($cands, 'id')) . '.', (int)$lot['id']]);
        } else {
            $majL->execute(['IDENTIQUE', (int)$cands[0]['id'],
                'Rapproché du bien MBI n°' . $cands[0]['id'] . ' par sa référence.',
                (int)$lot['id']]);
        }
    }
}

/**
 * L'empreinte du résultat de la phase 2 — le patrimoine tel qu'il a été examiné.
 *
 * ⚠️ CHAQUE TABLE A SA COLONNE DE RAPPROCHEMENT, ET UNE SEULE. `crgi_immeuble` porte
 *    `mbi_immeuble_id`, `crgi_lot` porte `mbi_bien_id` : les interroger toutes deux par un
 *    `COALESCE` commun échouait sur « colonne inconnue », et le `try/catch` qui l'entourait
 *    faisait retomber en silence sur une empreinte appauvrie. Un repli muet sur un calcul de
 *    sceau est exactement ce qu'il ne faut pas : il produit une empreinte plausible et fausse.
 *
 * ⚠️ ET LA QUALIFICATION DES COMPTES EN FAIT PARTIE. Elle est le résultat de la phase 2 au
 *    même titre que les rapprochements : l'omettre laisserait un A/B/C/D changer sans que la
 *    validation ne s'en aperçoive.
 */
/**
 * L'empreinte du résultat de la phase 2.
 *
 * ⚠️ UNE EMPREINTE DE CERTIFICATION SCELLE LE CONTENU MÉTIER, JAMAIS L'IDENTITÉ TECHNIQUE DE
 *    SES LIGNES DE STAGING. Elle portait l'`id` d'`AUTO_INCREMENT` ; or la phase 2 supprime et
 *    réinsère `crgi_immeuble` et `crgi_lot` à chaque analyse. Une relecture STRICTEMENT
 *    IDENTIQUE décalait donc les identifiants et faisait varier l'empreinte : la phase se
 *    serait déclarée périmée sans que le moindre fait métier ait changé. Un sceau qui crie au
 *    loup ne vaut pas mieux qu'un sceau muet.
 *
 * ⚠️ ON SCELLE DONC CE QUE LE DOCUMENT ET LA CONFRONTATION DISENT — la référence de l'objet,
 *    son verdict, l'objet MBI auquel il est rattaché — et on trie les lignes, pour que l'ordre
 *    de lecture n'ait lui non plus aucune influence.
 */
function crgi_empreinte_phase2(PDO $pdo, int $importId): string
{
    $l = [];
    $st = $pdo->prepare(
        'SELECT COALESCE(i.code, ""), COALESCE(i.nom, ""), COALESCE(i.code_postal, ""),
                COALESCE(i.ville, ""), i.page, i.statut, COALESCE(i.mbi_immeuble_id, 0),
                COALESCE(i.avant_apres, ""), c.compte
           FROM crgi_immeuble i JOIN crgi_crg c ON c.id = i.crg_id WHERE i.import_id = ?'
    );
    $st->execute([$importId]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) {
        $l[] = 'immeuble|' . implode('|', $r);
    }
    $st = $pdo->prepare(
        'SELECT o.reference, COALESCE(o.code_immeuble, ""), COALESCE(o.numero, ""),
                COALESCE(o.libelle, ""), COALESCE(o.locataire, ""), o.page, o.statut,
                COALESCE(o.mbi_bien_id, 0), COALESCE(o.avant_apres, ""), c.compte
           FROM crgi_lot o JOIN crgi_crg c ON c.id = o.crg_id WHERE o.import_id = ?'
    );
    $st->execute([$importId]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) {
        $l[] = 'lot|' . implode('|', $r);
    }
    // Le compte est ici son propre identifiant métier : il ne doit rien à l'AUTO_INCREMENT.
    $st = $pdo->prepare(
        'SELECT compte, COALESCE(periode_cle, ""), COALESCE(date_arrete, ""),
                COALESCE(compte_qualification, ""), COALESCE(compte_qualif_motif, ""),
                COALESCE(mbi_proprietaire_id, 0), page_debut
           FROM crgi_crg WHERE import_id = ?'
    );
    $st->execute([$importId]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) {
        $l[] = 'compte|' . implode('|', $r);
    }
    sort($l, SORT_STRING);
    return hash('sha256', implode("\n", $l));
}

/** Le bilan de la phase 2 : quatre niveaux, par agence. */
function crgi_bilan_phase2(PDO $pdo, int $importId): array
{
    $q = function (string $sql) use ($pdo, $importId) {
        $st = $pdo->prepare($sql);
        $st->execute([$importId]);
        return $st;
    };
    // ⚠️ `OCCURRENCE ≠ OBJET`, ET LA CONFUSION EST COÛTEUSE. Un même lot est réénoncé à chaque
    //    période : 472 occurrences sur ce dépôt pour **97 lots**. Annoncer 472 lots là où
    //    VIENNE en gère une centaine, c'est exactement ce que `P3C-LOT-03` interdit depuis la
    //    phase 3 du référentiel. On compte donc les objets DISTINCTS, et on montre les deux.
    $imm = $q('SELECT statut, COUNT(DISTINCT COALESCE(code, CONCAT(nom, "|", code_postal))) n
                 FROM crgi_immeuble WHERE import_id = ? GROUP BY statut')
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    $lot = $q('SELECT statut, COUNT(DISTINCT reference) n
                 FROM crgi_lot WHERE import_id = ? GROUP BY statut')
        ->fetchAll(PDO::FETCH_KEY_PAIR);
    $occ = [
        'immeubles' => (int)$q('SELECT COUNT(*) FROM crgi_immeuble WHERE import_id = ?')
            ->fetchColumn(),
        'lots' => (int)$q('SELECT COUNT(*) FROM crgi_lot WHERE import_id = ?')->fetchColumn(),
    ];
    $distincts = [
        'immeubles' => (int)$q('SELECT COUNT(DISTINCT COALESCE(code, CONCAT(nom,"|",code_postal)))
                                  FROM crgi_immeuble WHERE import_id = ?')->fetchColumn(),
        'lots' => (int)$q('SELECT COUNT(DISTINCT reference) FROM crgi_lot WHERE import_id = ?')
            ->fetchColumn(),
    ];
    $cpt = $q('SELECT compte_qualification, COUNT(*) n FROM crgi_crg
                WHERE import_id = ? AND compte_qualification IS NOT NULL
                GROUP BY compte_qualification')->fetchAll(PDO::FETCH_KEY_PAIR);
    $inv = $q('SELECT inventaire_statut, COUNT(*) n FROM crgi_crg
                WHERE import_id = ? AND doublon_statut <> "REENONCIATION"
                GROUP BY inventaire_statut')->fetchAll(PDO::FETCH_KEY_PAIR);

    $proprios = $q('SELECT COUNT(DISTINCT compte) FROM crgi_crg
                     WHERE import_id = ? AND doublon_statut <> "REENONCIATION"')->fetchColumn();
    return [
        'totaux' => ['immeubles' => $imm, 'lots' => $lot, 'comptes' => $cpt,
                     'inventaire' => $inv, 'comptes_distincts' => (int)$proprios],
        'immeubles' => $imm,
        'lots'      => $lot,
        'occurrences' => $occ,
        'distincts'   => $distincts,
        'comptes'   => $cpt,
        'inventaire' => $inv,
        'comptes_distincts' => (int)$proprios,
    ];
}

/**
 * PHASE 3 — LOCATAIRES / OCCUPATION : la suite des occupants d'un lot, et ce qu'elle démontre.
 *
 * ⚠️ LA CHRONOLOGIE SE LIT SUR TOUTES LES PÉRIODES DU DÉPÔT, PAS SUR LA DERNIÈRE. Comparer MBI
 *    à la seule observation la plus récente dirait « qui est là aujourd'hui » ; la suite
 *    `AVRIL : DUPONT · MAI : DUPONT · JUIN : MARTIN · JUILLET : MARTIN` démontre QUAND le
 *    titulaire a changé. C'est la succession documentaire qui fait la preuve, pas l'état final.
 *
 * ⚠️ UN ANCIEN LOCATAIRE N'EST JAMAIS SUPPRIMÉ, et sa dette lui reste attachée
 *    (`P6A-CREANCE-07`, certifiée : la créance d'un ancien locataire ne passe jamais au
 *    suivant).
 *
 * ⚠️ `ABSENCE DANS UN NOUVEAU CRG ≠ DÉPART.` Un départ n'est `DÉMONTRÉ` que si le lot est
 *    RÉÉNONCÉ à une période ultérieure sans cet occupant. Si le lot cesse simplement
 *    d'apparaître, c'est `À ARBITRER` : le CRG peut ne pas avoir été déposé.
 *
 * ⚠️ `STOCK ≠ FLUX.` Les encours de chaque période sont des PHOTOGRAPHIES : on les affiche
 *    côte à côte, on n'en additionne jamais deux. La « variation » est une différence entre
 *    deux photographies nommées, jamais un cumul.
 *
 * ⚠️ AUCUNE ÉCRITURE MÉTIER.
 */
function crgi_phase3(PDO $pdo, int $importId): array
{
    crgi_verrouiller_import($pdo, $importId, 3);
    $etat2 = crgi_phase_validee($pdo, $importId, 2);
    if (!$etat2['validee'] || $etat2['perimee']) {
        throw new RuntimeException(
            'PHASE 2 NON VALIDÉE — la phase 3 ne s’ouvre pas. Lire l’occupation sur un '
            . 'patrimoine non scellé ferait reposer une chronologie sur un état mouvant.'
        );
    }
    crgi_marquer_phase($pdo, $importId, 3, 'EN ANALYSE', null);
    $pdo->prepare('DELETE FROM crgi_occupation WHERE import_id = ?')->execute([$importId]);
    crgi_lire_occupation($pdo, $importId);
    crgi_qualifier_occupation($pdo, $importId);
    crgi_controle_departs($pdo, $importId);
    crgi_marquer_phase($pdo, $importId, 3, 'A VALIDER', null);
    return crgi_bilan_phase3($pdo, $importId)['statuts'];
}

/** Relève une observation par lot et par CRG — une seule lecture du PDF par pièce. */
/**
 * LE BILAN MÉTIER DE LA PHASE 3 — DES OBJETS, JAMAIS DES LIGNES.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CETTE FONCTION EXISTE PARCE QUE J'AI ANNONCÉ TROIS FOIS DES CHIFFRES FAUX, DE LA MÊME
 *    FAÇON. Compter les lignes de `crgi_occupation` et les présenter comme des locataires
 *    multiplie tout par le nombre de PÉRIODES du dépôt. Emmanuel, 08/09/2026 : « pour Vienne tu
 *    as encore triplé les chiffres car il y a 3 CRG par trimestre !!!!! » — et il avait déjà dû
 *    le dire : « il y a 122 biens et 480 d'occupation ». La règle était en mémoire ; elle n'a
 *    pas tenu, parce que rien dans le code ne l'appliquait. **Un piège consigné mais non gardé
 *    se reproduit.**
 *
 * ⚠️ LA CADENCE N'EST PAS LA MÊME PARTOUT, ET C'EST ELLE QUI FIXE LE FACTEUR. Un dépôt
 *    MENSUEL observe chaque lot 4 fois là où un trimestriel l'observe 1 fois : additionner
 *    les observations gonfle l'un quatre fois plus que l'autre, et les rend incomparables.
 *
 * ⚠️ ET UN ANCIEN NOM PORTÉ N'EST PAS UN DÉPART. Un compte rendu réimprime un locataire sorti
 *    tant que son solde n'est pas apuré — parfois des années. Une locataire est lue avec
 *    « Du 01.01.09 Au 24.01.09 », montants à 0,00, dans un rapport de 2026 : elle est partie il
 *    y a seize ans. Comptée comme un départ, elle gonflait un dépôt de 23 à 93.
 *
 * Toute unité rendue ici est un OBJET : un lot, un bail, un occupant. Jamais une ligne.
 */
function crgi_bilan_metier(PDO $pdo, int $importId): array
{
    // ⚠️ L'IDENTIFIANT EST INTERPOLÉ, PAS LIÉ — ET C'EST DÉLIBÉRÉ. Ces requêtes portent le
    //    même import à trois ou quatre endroits ; compter les points d'interrogation à la main
    //    est exactement la faute qui a déjà cassé une passe entière (« Invalid parameter
    //    number »). Un entier casté n'ouvre aucune injection, et le nombre de paramètres cesse
    //    d'être une chose à tenir juste.
    $i = (int)$importId;
    $un = function (string $sql) use ($pdo): int {
        return (int)$pdo->query($sql)->fetchColumn();
    };
    // L'état de chaque lot À SA DERNIÈRE PÉRIODE — une photographie, jamais une addition.
    $dernier = 'SELECT c.compte cpt, o.lot_reference lot, o.locataire loc, o.statut st
                  FROM crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
                 WHERE c.import_id = ' . $i . ' AND o.date_arrete = (
                       SELECT MAX(o2.date_arrete) FROM crgi_occupation o2
                        JOIN crgi_crg c2 ON c2.id = o2.crg_id
                       WHERE c2.import_id = ' . $i . ' AND c2.compte = c.compte
                         AND o2.lot_reference = o.lot_reference)';
    $occupe = '"IDENTIQUE","CHANGEMENT DE LOCATAIRE","NOUVEL ENTRANT"';
    $sortie = '"PARTI DEMONTRE","ANCIEN LOCATAIRE AVEC DETTE"';
    $debut = '(SELECT MIN(o4.date_arrete) FROM crgi_occupation o4 WHERE o4.import_id = ' . $i . ')';
    $baux = 'SELECT MAX(o.dernier_appel_au) fin
               FROM crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
              WHERE c.import_id = ' . $i . ' AND o.locataire IS NOT NULL
                AND o.statut IN (' . $sortie . ')
              GROUP BY c.compte, o.lot_reference, o.locataire';

    return [
        'periodes'   => $un('SELECT COUNT(DISTINCT date_arrete) FROM crgi_occupation
                              WHERE import_id = ' . $i),
        'lots'       => $un('SELECT COUNT(*) FROM (' . $dernier . ' GROUP BY 1,2) x'),
        'occupes'    => $un('SELECT COUNT(*) FROM (' . $dernier . ' GROUP BY 1,2
                              HAVING MAX(st IN (' . $occupe . ')) = 1) x'),
        'vides'      => $un('SELECT COUNT(*) FROM (' . $dernier . ' GROUP BY 1,2
                              HAVING MAX(st IN (' . $occupe . ')) = 0
                                 AND MAX(st = "A ARBITRER") = 0) x'),
        'arbitrages' => $un('SELECT COUNT(*) FROM (' . $dernier . ' GROUP BY 1,2
                              HAVING MAX(st IN (' . $occupe . ')) = 0
                                 AND MAX(st = "A ARBITRER") = 1) x'),
        'occupants'  => $un('SELECT COUNT(*) FROM (' . $dernier . ' GROUP BY 1,2,3
                              HAVING loc IS NOT NULL
                                 AND MAX(st IN (' . $occupe . ')) = 1) x'),
        // ⚠️ UN BAIL TERMINÉ PENDANT LE DÉPÔT ≠ UN NOM PORTÉ DEPUIS DES ANNÉES.
        'partis'       => $un('SELECT COUNT(*) FROM (' . $baux . ') b
                                WHERE b.fin >= ' . $debut),
        'noms_anciens' => $un('SELECT COUNT(*) FROM (' . $baux . ') b
                                WHERE b.fin IS NULL OR b.fin < ' . $debut),
    ];
}

/**
 * LE MOTEUR SE CONTREDIT-IL LUI-MÊME ? — le contrôle qui a sauvé la phase 3.
 *
 * ⚠️ UNE RÈGLE JUSTE APPLIQUÉE À UNE LECTURE INCOMPLÈTE PRODUIT DES FAITS FAUX. « Aucun appel
 *    de loyer ⇒ le locataire est parti » est exact. Codé sans garde de contraste, il a prononcé
 *    **871 départs**, dont **518 que le moteur démentait lui-même** : le même titulaire, sur le
 *    même lot, réimprimé à une période POSTÉRIEURE. Un zéro mesuré et un zéro non lu s'écrivent
 *    pareil, et la couverture du lecteur d'appels allait de 48 % à 95 % selon le dépôt.
 *
 * ⚠️ CE CONTRÔLE NE RELIT RIEN — IL CHERCHE UNE CONTRADICTION INTERNE. C'est ce qui le rend
 *    increvable : il n'a besoin d'aucune source extérieure, seulement de ce que le moteur vient
 *    d'affirmer.
 *
 * ⚠️ ET « RÉIMPRIMÉ PLUS TARD » N'EST PAS UNE CONTRADICTION — C'EST LA NORME. Un ancien
 *    locataire reste au compte rendu tant que sa dette n'est pas apurée : une locataire dont
 *    le bail finit le 01/01/2026 reparaît en avril, mai, juin et juillet, sans un appel, avec
 *    son encours. C'est `INTEG-P2-SOLDE-REPORTE` au niveau du locataire. Défini naïvement, ce
 *    contrôle comptait **486 contradictions** là où il n'y en avait que 9 : la contradiction
 *    n'est pas la réapparition du NOM, c'est la réapparition d'un APPEL. Un partant ne
 *    redemande pas son loyer.
 *
 * ⚠️ ISOLÉ, ON CORRIGE ; SYSTÉMATIQUE, ON REFUSE DE SCELLER. Quelques cas sont des accidents de
 *    lecture qu'on redresse en disant pourquoi. Au-delà du seuil, ce n'est plus un accident :
 *    c'est une règle fausse, et la faire passer sous couvert de correction automatique
 *    masquerait exactement ce que ce contrôle existe pour attraper.
 */
/**
 * ⚠️ CE SEUIL EST CALIBRÉ, PAS CHOISI. Je l'avais d'abord fixé à 2 % — un chiffre inventé, qui
 *    faisait échouer des dépôts sains. Les deux points de mesure qu'il doit séparer :
 *      · règle fausse (« aucun appel ⇒ parti », sans contraste) → **518 sur 871, soit 59 %** ;
 *      · dépôts corrigés, contradictions résiduelles → **0 %** sur l'un, **8,6 %** sur le plus
 *        petit, et ce sont des cas nommés, non une famille.
 *    Un seuil qui n'a pas ses deux points de mesure n'est pas un seuil, c'est une superstition.
 */
const CRGI_DEPARTS_CONTREDITS_MAX = 0.10;

/** Sept jours : en deçà, l'écart entre le dernier appel et l'arrêté est un artefact de bornes. */
const CRGI_ECART_APPEL_MIN = 7 * 86400;

function crgi_controle_departs(PDO $pdo, int $importId): int
{
    $ou = 'o.import_id = ? AND o.locataire IS NOT NULL
             AND o.statut IN ("PARTI DEMONTRE", "ANCIEN LOCATAIRE AVEC DETTE")
             AND EXISTS (SELECT 1 FROM crgi_occupation o2 JOIN crgi_crg c2 ON c2.id = o2.crg_id
                          WHERE c2.import_id = c.import_id AND c2.compte = c.compte
                            AND o2.lot_reference = o.lot_reference
                            AND o2.date_arrete > o.date_arrete
                            AND o2.locataire = o.locataire AND o2.appels > 0)';
    $st = $pdo->prepare('SELECT COUNT(*) FROM crgi_occupation o
                           JOIN crgi_crg c ON c.id = o.crg_id WHERE ' . $ou);
    $st->execute([$importId]);
    $n = (int)$st->fetchColumn();
    if ($n === 0) {
        return 0;
    }
    $tot = $pdo->prepare('SELECT COUNT(*) FROM crgi_occupation
                           WHERE import_id = ? AND statut IN ("PARTI DEMONTRE",
                                                              "ANCIEN LOCATAIRE AVEC DETTE")');
    $tot->execute([$importId]);
    $departs = max(1, (int)$tot->fetchColumn());
    if ($n / $departs > CRGI_DEPARTS_CONTREDITS_MAX) {
        throw new RuntimeException(
            'PHASE 3 CONTRADICTOIRE — ' . $n . ' départ(s) sur ' . $departs . ' sont démentis '
            . 'par le document lui-même : le MÊME titulaire APPELLE encore son loyer, sur le '
            . 'MÊME lot, à une période POSTÉRIEURE. Au-delà de '
            . (int)(CRGI_DEPARTS_CONTREDITS_MAX * 100) . ' %, ce n’est pas un accident de '
            . 'lecture, c’est une règle fausse. La phase ne se scelle pas.'
        );
    }
    // ⚠️ LE DOCUMENT A LE DERNIER MOT. Il réénonce un appel de ce titulaire après la date où
    //    on le disait sorti : il n'était donc pas sorti. On redresse, et le motif dit pourquoi
    //    — jamais une correction muette.
    $pdo->prepare(
        'UPDATE crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
            SET o.statut = "IDENTIQUE",
                o.statut_motif = CONCAT(
                    "DÉPART RETIRÉ PAR LE DOCUMENT — ce titulaire APPELLE encore son loyer sur ",
                    "ce lot à une période postérieure au ", o.date_arrete,
                    " : il n’était pas sorti. Verdict initial : ", LEFT(o.statut_motif, 180))
          WHERE ' . $ou
    )->execute([$importId]);
    return $n;
}

/**
 * LES BORNES D'UN COMPTE RENDU — DÉPLIÉES DEPUIS SON NOM QUAND IL NE LES ÉCRIT PAS.
 *
 * ⚠️ 435 COMPTES RENDUS SUR 947 N'IMPRIMENT AUCUNE DATE DE PÉRIODE. Ils écrivent
 *    « - 1er Trimestre 2026 - » et rien d'autre : `periode_debut` et `periode_fin` sont NULL,
 *    et c'est `INTEG-P1-TRIMESTRE` — le trimestre NOMMÉ est l'identité, il ne se déduit pas de
 *    bornes absentes. Se rabattre sur la date d'arrêté ramène la période à UNE JOURNÉE : tout
 *    appel antérieur au 31/03 est alors jugé hors période, et un locataire qui appelle son
 *    loyer en janvier et février se retrouve avec ZÉRO appel. Mesuré : les cinq occupations
 *    lyonnaises encore en arbitrage affichaient 2, 4, 5 appels, puis 0 après ce repli.
 *
 * ⚠️ ON DÉPLIE LE NOM, ON N'INVENTE RIEN. « 2026-T1 » vaut 01/01 → 31/03 parce que c'est ce que
 *    le nom SIGNIFIE, pas parce que c'est probable.
 *
 * @return array{0:string,1:string} début et fin en ISO ; chaînes vides si indéterminables.
 */
function crgi_bornes_de_periode(array $crg): array
{
    $debut = (string)($crg['periode_debut'] ?? '');
    $fin   = (string)($crg['periode_fin'] ?? '');
    if ($debut !== '' && $fin !== '') {
        return [$debut, $fin];
    }
    $cle = (string)($crg['periode_cle'] ?? '');
    if (preg_match('/^(\d{4})-T([1-4])$/', $cle, $m)) {
        $an = (int)$m[1];
        $t = (int)$m[2];
        $moisFin = $t * 3;
        return [sprintf('%04d-%02d-01', $an, $moisFin - 2),
                sprintf('%04d-%02d-%02d', $an, $moisFin,
                        (int)date('t', mktime(0, 0, 0, $moisFin, 1, $an)))];
    }
    if (preg_match('/^(\d{4})-(\d{2})$/', $cle, $m)) {
        $an = (int)$m[1];
        $mo = (int)$m[2];
        return [sprintf('%04d-%02d-01', $an, $mo),
                sprintf('%04d-%02d-%02d', $an, $mo,
                        (int)date('t', mktime(0, 0, 0, $mo, 1, $an)))];
    }
    if (preg_match('/^(\d{4}-\d{2}-\d{2})_(\d{4}-\d{2}-\d{2})$/', $cle, $m)) {
        return [$m[1], $m[2]];
    }
    // ⚠️ INDÉTERMINABLE SE DIT, IL NE SE DEVINE PAS. Sans bornes lisibles, on ne filtre plus :
    //    mieux vaut compter un appel de trop que d'en effacer tous.
    return ['', ''];
}

/**
 * LES APPELS QUI RECOUPENT LA PÉRIODE DU COMPTE RENDU, ET JUSQU'OÙ ILS VONT.
 *
 * ⚠️ UN APPEL DE L'AN PASSÉ N'EST PAS UN APPEL DE LA PÉRIODE. SEMACO (LYON, lot 01980159-0004)
 *    portait trois lignes « Du 01.01.25 Au 31.12.25 » dans un compte rendu du 1er trimestre
 *    2026 : des régularisations annuelles. Comptées comme trois appels, elles faisaient passer
 *    pour présent un locataire qui n'appelait plus rien. On ne retient donc que ce qui
 *    RECOUPE [période_début, période_fin] — et à défaut de bornes lues, la date d'arrêté.
 *
 * ⚠️ ET UN DÉPÔT DE GARANTIE COMPTE SANS PORTER DE DATE DE FIN. Il prouve qu'on a appelé
 *    quelque chose — MEYNADE Carole entre avec un loyer gratuit mais un dépôt de 1 070 € —
 *    sans dire jusqu'à quand. Il augmente le nombre d'appels ; il ne fixe jamais la fin.
 *
 * @return array{0:int,1:?string} le nombre d'appels, et la fin du dernier (NULL si aucune).
 */
function crgi_appels_de_la_periode(array $o, array $crg): array
{
    [$debut, $fin] = crgi_bornes_de_periode($crg);
    $n = (int)($o['appels_sans_periode'] ?? 0);
    $dernier = null;
    foreach ((array)($o['appels_periodes'] ?? []) as $p) {
        $du = (string)($p[0] ?? '');
        $au = (string)($p[1] ?? '');
        if ($du === '' || $au === '') {
            continue;
        }
        // Deux intervalles se recoupent si chacun commence avant que l'autre ne finisse.
        if ($debut !== '' && $fin !== '' && ($au < $debut || $du > $fin)) {
            continue;
        }
        $n++;
        if ($dernier === null || $au > $dernier) {
            $dernier = $au;
        }
    }
    // ⚠️ REPLI SUR L'ANCIEN COMPTEUR, ET JAMAIS L'INVERSE. Tant qu'un lecteur ne rend pas
    //    encore ses périodes, son compte brut vaut mieux que zéro — mais dès qu'il les rend,
    //    ce sont elles qui font foi : un compte sans bornes ne saurait pas écarter une
    //    régularisation de l'an passé.
    if (!isset($o['appels_periodes']) && isset($o['appels'])) {
        $n = (int)$o['appels'];
    }
    return [$n, $dernier];
}

function crgi_lire_occupation(PDO $pdo, int $importId): void
{
    $python = getenv('CRG_PYTHON') ?: (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
    $script = realpath(__DIR__ . '/../scripts/crg_integration_phase3.py');
    if (!$script) {
        throw new RuntimeException('MOTEUR ABSENT : scripts/crg_integration_phase3.py');
    }
    $st = $pdo->prepare(
        'SELECT c.id, c.page_debut, c.page_fin, c.periode_cle, c.periode_debut, c.periode_fin,
                c.date_arrete, c.format, p.chemin
           FROM crgi_crg c JOIN crgi_piece p ON p.id = c.piece_id
          WHERE c.import_id = ? AND c.doublon_statut <> "REENONCIATION" ORDER BY c.page_debut'
    );
    $st->execute([$importId]);
    $meta = $parPiece = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $meta[(int)$c['id']] = $c;
        // La famille voyage avec la plage — voir `crgi_commande_lecture`.
        $parPiece[(string)$c['chemin'] . CRGI_SEP_FAMILLE . (string)$c['format']][] =
            ['id' => (int)$c['id'], 'debut' => (int)$c['page_debut'],
             'fin' => (int)$c['page_fin']];
    }
    $ins = $pdo->prepare(
        'INSERT INTO crgi_occupation
            (import_id, crg_id, lot_reference, code_immeuble, periode_cle, date_arrete,
             locataire, bail_du, bail_au, rang, solde, solde_source, appels,
             dernier_appel_au, honoraires, page)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    foreach ($parPiece as $clef => $plages) {
        [$chemin, $format] = explode(CRGI_SEP_FAMILLE, $clef, 2);
        $fichier = tempnam(sys_get_temp_dir(), 'crgi3_');
        file_put_contents($fichier, json_encode($plages));
        $cmd = crgi_commande_lecture($python, $script, $chemin, $format, $fichier, 'occupations');
        $sortie = trim((string)@shell_exec($cmd . ' 2>&1'));
        @unlink($fichier);
        $r = json_decode($sortie, true);
        if (!is_array($r)) {
            throw new RuntimeException('MOTEUR OCCUPATION MUET OU ILLISIBLE : '
                                     . mb_substr($sortie, 0, 300));
        }
        foreach ($r as $bloc) {
            $c = $meta[(int)$bloc['id']] ?? null;
            if (!$c) {
                continue;
            }
            foreach ($bloc['observations'] ?? [] as $o) {
                $parts = explode('-', (string)$o['lot']);
                $codeImm = count($parts) === 3 ? $parts[1]
                         : (count($parts) === 2 ? $parts[0] : null);
                [$nbAppels, $finAppel] = crgi_appels_de_la_periode($o, $c);
                // ⚠️ LES HONORAIRES APPARTIENNENT AU COMPTE RENDU, PAS AU LOT. Ils vivent
                //    dans une section à part ; on les porte sur chaque occupation du même
                //    document, parce que c'est là que la question se posera.
                $ins->execute([$importId, (int)$c['id'], $o['lot'], $codeImm,
                               $c['periode_cle'], $c['date_arrete'], $o['locataire'],
                               $o['bail_du'], $o['bail_au'] ?? null, (int)($o['rang'] ?? 0),
                               $o['solde'], $o['solde_source'],
                               $nbAppels, $finAppel,
                               max(0, (int)($bloc['honoraires'] ?? 0)),
                               (int)$o['page']]);
            }
        }
    }
}

/**
 * Le verdict de chaque observation, lu sur la SUITE des périodes d'un même lot.
 *
 * ⚠️ ON TRIE SUR LA DATE D'ARRÊTÉ, PAS SUR L'ÉTIQUETTE DE PÉRIODE. `P8B-SOLDE-03` l'a
 *    certifié : trier sur la clé plaçait `2026-05-13_2026-06-30` avant `2026-T1` et fabriquait
 *    de fausses ruptures. Ici, une fausse rupture inventerait un déménagement.
 */
function crgi_qualifier_occupation(PDO $pdo, int $importId): void
{
    // ⚠️ UNE RÉFÉRENCE LOCALE N'EST JAMAIS UNE IDENTITÉ GLOBALE. Le document imprime
    //    « - Lot 01 - Mandat N/A - » : ce numéro appartient à SON immeuble, et le CRG ne
    //    prétend nulle part qu'il soit unique. Grouper sur la seule référence a fusionné
    //    l'appartement 01 de RAYNAL (occupant SANCHEZ) et l'appartement 01 de MARTINEZ
    //    (occupant CHAYNARD) : deux chronologies parfaitement stables, entrelacées par date,
    //    d'où UN DÉPART ET DEUX CHANGEMENTS ENTIÈREMENT FABRIQUÉS. L'identité du lot porte
    //    donc son périmètre de portée — LE COMPTE.
    $st = $pdo->prepare(
        'SELECT o.id, o.crg_id, o.lot_reference, o.date_arrete, o.periode_cle, o.locataire,
                o.bail_du, o.bail_au, o.rang, o.solde, o.solde_source, o.appels,
                o.dernier_appel_au, c.compte, COALESCE(a.nom_agence, c.agence, "") AS agence
           FROM crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
           LEFT JOIN agences a ON a.id = c.agence_id
          WHERE o.import_id = ?
          ORDER BY c.compte, o.lot_reference, o.date_arrete, o.rang, o.id'
    );
    $st->execute([$importId]);
    $parLot = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $parLot[crgi_cle_lot((string)$o['compte'], (string)$o['lot_reference'])][] = $o;
    }
    $maj = $pdo->prepare(
        'UPDATE crgi_occupation SET statut = ?, statut_motif = ?, precedent = ? WHERE id = ?'
    );
    // ⚠️ LE SEUIL EST CELUI DU COMPTE, PAS DU DÉPÔT. Trois arrêtés isolés — 2026-06-03,
    //    2026-07-01, 2026-08-01, un seul lot chacun — portaient le maximum global au
    //    01/08/2026 : tout lot suivi jusqu'au 31/07 se retrouvait « absent ensuite », donc à
    //    arbitrer. Un lot n'est réputé cesser d'apparaître que si SON PROPRE compte continue
    //    d'être rendu après lui.
    $st2 = $pdo->prepare(
        'SELECT c.compte, MAX(o.date_arrete) AS fin
           FROM crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
          WHERE o.import_id = ? GROUP BY c.compte'
    );
    $st2->execute([$importId]);
    $finDuCompte = $st2->fetchAll(PDO::FETCH_KEY_PAIR);

    // ⚠️ UN COMPTE RENDU OÙ PERSONNE N'APPELLE RIEN N'EST PAS UN IMMEUBLE VIDE : C'EST UN
    //    DOCUMENT MAL LU. Le compteur d'appels ne connaissait que la forme ICS « Du … Au … » ;
    //    SPI écrit « TERME Mai 2026 » et « Loyer du … au … ». Résultat mesuré le 07/09/2026 :
    //    CHAPONOST 402/402 et VIENNE 480/480 à zéro — 882 occupations sur 2 839. Conclure
    //    « parti » sur cette base aurait vidé deux agences entières en silence.
    //    « Un contrôle qui échoue à 100 % ne signale pas un document irrégulier : il signale
    //    qu'on l'a mal lu. » On n'applique donc la règle des appels que là où le lecteur a
    //    DÉMONTRÉ qu'il sait les lire — c'est-à-dire dans un CRG qui en porte au moins un.
    $st3 = $pdo->prepare(
        'SELECT crg_id, MAX(appels) FROM crgi_occupation WHERE import_id = ? GROUP BY crg_id'
    );
    $st3->execute([$importId]);
    $lecteurSait = $st3->fetchAll(PDO::FETCH_KEY_PAIR);

    // ⚠️ UNE DÉCISION DÉJÀ DONNÉE NE SE REDEMANDE PAS À LA PHASE SUIVANTE. Le compte VIENNE
    //    `1105404745` (ROSIER) a été tranché BIEN VENDU en phase 2 ; la phase 3 posait quand
    //    même deux questions sur son lot 190. Emmanuel, 07/09/2026 : « il y a des documents
    //    qui sont exclus de l'analyse dans les phases précédentes que tu donnes en erreur à
    //    arbitrer ». `crgi_identite` n'était consultée que par la phase qui l'avait écrite :
    //    une mémoire qu'une seule phase interroge n'est pas une mémoire, c'est une note.
    // ⚠️ LA JOINTURE PORTE SON `COLLATE`, ET CE N'EST PAS UNE PRÉCAUTION DE STYLE.
    //    `crgi_identite` est en `utf8mb4_general_ci`, `crgi_crg` en `utf8mb4_unicode_ci` :
    //    comparer leurs colonnes de texte lève « Illegal mix of collations » et fait tomber
    //    la phase entière. Piège déjà connu du projet — deux tables voisines, deux collations.
    $st4 = $pdo->prepare(
        'SELECT DISTINCT c.compte, i.choix
           FROM crgi_crg c
           JOIN crgi_identite i
             ON i.cle COLLATE utf8mb4_unicode_ci = c.compte
            AND i.type COLLATE utf8mb4_unicode_ci = ?
            AND i.agence COLLATE utf8mb4_unicode_ci
                = COALESCE((SELECT a.nom_agence FROM agences a WHERE a.id = c.agence_id),
                           c.agence, "")
          WHERE c.import_id = ?'
    );
    $st4->execute([CRGI_IDENTITE_COMPTE_SANS_PATRIMOINE, $importId]);
    $trancheEnP2 = $st4->fetchAll(PDO::FETCH_KEY_PAIR);
    // ⚠️ LE COMPTE DU LOT NE SE RETROUVE PLUS PAR UNE SECONDE REQUÊTE. Elle indexait
    //    `lot_reference => compte` : quand deux comptes portaient le même numéro de lot, la
    //    clé se collisionnait et un seul compte survivait — le lot héritait alors de la date
    //    de fin d'un compte qui n'était pas le sien. Le compte est désormais DANS la clé.
    foreach ($parLot as $cle => $suite) {
        $n = count($suite);
        $derniere = (string)($finDuCompte[(string)$suite[0]['compte']] ?? '');
        foreach ($suite as $i => $o) {
            $loc = $o['locataire'];
            $prec = $i > 0 ? $suite[$i - 1]['locataire'] : null;
            $suiv = $i + 1 < $n ? $suite[$i + 1] : null;
            $statut = null;
            $motif = '';

            // ⚠️ UNE FIN DE BAIL IMPRIMÉE EST UNE PREUVE, PAS UNE ABSENCE. Quand le document
            //    écrit « Bail du … AU … », le départ n'est plus déduit : il est DIT. C'est la
            //    seule façon de qualifier un départ sans attendre la période suivante, et elle
            //    ne contredit pas `ABSENCE ≠ DÉPART` — elle s'y ajoute.
            // ⚠️ UNE FIN DE BAIL POSTÉRIEURE À L'ARRÊTÉ NE DÉMONTRE AUCUN DÉPART. Treize
            //    observations de ce dépôt portent un congé daté APRÈS la date d'arrêté — un
            //    bail qui s'achève le 31/08 alors que le rapport est arrêté au 31/07 décrit un
            //    occupant TOUJOURS EN PLACE. Les compter comme partis fabriquait quarante-deux
            //    anciens locataires qui n'avaient pas bougé.
            // ⚠️ ET UN APPEL QUI CONTINUE BAT UNE FIN DE BAIL IMPRIMÉE. Emmanuel : « dans tous
            //    les cas, les appels de loyer déterminent qu'il est en place. » Mesuré : SEPT
            //    des neuf départs qu'un dépôt démentait lui-même venaient de cette règle-ci —
            //    un bail imprimé « au 07/04/2026 » pour une locataire qui appelle son loyer
            //    jusqu'au 30/04 et les mois suivants. La date imprimée n'est alors pas une
            //    sortie : c'est un terme de bail reconduit, ou un congé qui n'a pas eu lieu.
            //    LA PREUVE LA PLUS RÉCENTE L'EMPORTE, et un appel est plus récent qu'une date
            //    de bail écrite une fois pour toutes en tête de bloc.
            $appelleJusquAuBout = (int)$o['appels'] > 0
                && ((string)($o['dernier_appel_au'] ?? '') === ''
                    || (string)$o['dernier_appel_au'] >= (string)$o['date_arrete']);
            $congeAtteint = $loc !== null && !empty($o['bail_au'])
                         && (string)$o['bail_au'] <= (string)$o['date_arrete']
                         && !$appelleJusquAuBout;
            if ($congeAtteint) {
                $dette = $o['solde_source'] === 'LUE' && (float)$o['solde'] > 0.005;
                $statut = $dette ? 'ANCIEN LOCATAIRE AVEC DETTE' : 'PARTI DEMONTRE';
                $motif = 'Le document imprime la FIN DU BAIL au ' . $o['bail_au']
                       . ', atteinte à la date d’arrêté du ' . $o['date_arrete'] . ' : le '
                       . 'départ est écrit, pas déduit.'
                       . ($suiv && $suiv['locataire'] !== null
                          && crgi_plat((string)$suiv['locataire']) !== crgi_plat((string)$loc)
                          ? ' Le lot est réénoncé avec « ' . $suiv['locataire'] . ' ».' : '')
                       . ($dette
                          ? ' Encours de ' . number_format((float)$o['solde'], 2, ',', ' ')
                            . ' € : la dette reste attachée à CE locataire '
                            . '(P6A-CREANCE-07).'
                          : ' Aucune dette lue.')
                       . ' L’observation est CONSERVÉE.';
                $maj->execute([$statut, mb_substr($motif, 0, 400), $prec, (int)$o['id']]);
                continue;
            }
            // ═══════════════════════════════════════════════════════════════════════════════
            // LES APPELS DE LOYER — LE FAIT QUI DIT QUI EST EN PLACE
            // ═══════════════════════════════════════════════════════════════════════════════
            //
            // ⚠️ RÈGLE D'EMMANUEL, 07/09/2026, DONNÉE COMME UNE ÉVIDENCE : « pas de loyer ou
            //    charge appelé c'est qu'il est parti, c'est la base de nos discussions ! » et
            //    « je ne devrais pas avoir à te le dire ». Ce n'est pas une déduction tirée
            //    d'une absence — c'est une lecture : le document IMPRIME ce qu'il appelle, et
            //    ne rien appeler est une information écrite, pas un silence.
            //
            // ⚠️ ET LA FIN DU DERNIER APPEL DATE LE DÉPART. « Il y a une date de fin de période
            //    qui signifie que le mois n'est pas complet donc fin du bail ; sinon ce doit
            //    être écrit un motif de réduction du loyer, sinon c'est fin de bail. » CHILLA
            //    (LYON, 192 Cuvier) appelle « Du 01.02.26 Au 09.02.26 » dans un rapport arrêté
            //    au 31/03/2026, et la même page porte « Rembt D G reversé -842,00 » et
            //    « honoraires état des lieux SORTIE CHILLA ». HAMIED (VIENNE) appelle son
            //    loyer « du 01/06/2026 au 15/06/2026 » pour un arrêté au 30/06.
            //
            // ⚠️ LA RÉCIPROQUE COMPTE AUTANT, ET C'EST ELLE QUI FERME LES QUESTIONS. Un bloc
            //    qui appelle JUSQU'À la date d'arrêté démontre une présence : aucun rapport
            //    ultérieur ne la contredit, et le contrôle d'absence plus bas ne doit pas se
            //    déclencher. Les six lots CHAPONOST portaient « TERME Avril / Mai / Juin
            //    2026 » — ils n'ont jamais bougé.
            //
            // ⚠️ ON N'APPLIQUE RIEN LÀ OÙ LE LECTEUR N'A RIEN LU. Voir la garde `$lecteurSait`.
            // ⚠️ L'ABSENCE D'APPEL NE DÉMONTRE UN DÉPART QUE PAR CONTRASTE, SUR LE MÊME LOT ET
            //    AU MÊME ARRÊTÉ. Emmanuel l'avait dit exactement ainsi : « il y a un locataire
            //    qui n'a aucun appel de loyer en ligne, mais un solde débiteur ou créditeur, et
            //    l'autre a des lignes d'appel de loyer ! c'est la différence. » C'est le
            //    CONTRASTE qui prouve, jamais le vide seul — et pour le vide seul il avait
            //    tranché autrement : « soit appartement vacant, soit perte de gestion, et tu ne
            //    peux pas le voir, IL FAUT POSER LA QUESTION ».
            //
            // ⚠️ J'AI D'ABORD CODÉ « aucun appel ⇒ parti » SANS CE CONTRASTE, ET ÇA A FABRIQUÉ
            //    871 DÉPARTS. Le contrôle qui l'a démontré : **518 locataires déclarés partis
            //    réapparaissaient sous le MÊME nom, sur le MÊME lot, à une période POSTÉRIEURE**
            //    — une contradiction que le moteur produisait contre lui-même. La cause : la
            //    couverture du lecteur d'appels n'est pas uniforme (48 % sur un dépôt), et la
            //    garde par compte rendu était trop grossière — un CRG où 40 lots sur 100
            //    portent des appels lus la franchit, et les 60 autres se voyaient déclarés
            //    vides. UNE RÈGLE JUSTE APPLIQUÉE À UNE LECTURE INCOMPLÈTE PRODUIT DES FAITS
            //    FAUX : le contraste, lui, se lit sur deux blocs du même document.
            $voisinAppelle = false;
            foreach ($suite as $frere) {
                if ((string)$frere['date_arrete'] === (string)$o['date_arrete']
                    && (int)$frere['id'] !== (int)$o['id'] && (int)$frere['appels'] > 0) {
                    $voisinAppelle = true;
                    break;
                }
            }
            $saitLire = (int)($lecteurSait[(int)$o['crg_id']] ?? 0) > 0;
            $enPlaceParAppel = false;
            // ⚠️ UN VERDICT TIRÉ DES APPELS NE SE FAIT PAS ÉCRASER PAR UNE ABSENCE. Le contrôle
            //    d'horizon, en bas de boucle, remettait « A ARBITRER » sur des départs que les
            //    appels venaient de DÉMONTRER : quatre occupations dont le dernier loyer était
            //    lu — « Au 09.02.26 » pour un arrêté au 31/03 — ressortaient en question. Une
            //    preuve lue ne redevient pas une question parce que la suite se tait.
            $verdictParAppel = false;
            if ($loc !== null && $saitLire) {
                $dette = $o['solde_source'] === 'LUE' && (float)$o['solde'] > 0.005;
                $finAppel = (string)($o['dernier_appel_au'] ?? '');
                if ((int)$o['appels'] === 0) {
                    // ⚠️ UN NOM SANS APPEL EST UN PARTI DÉBITEUR — le contraste n'est plus exigé.
                    //    Emmanuel, 08/09/2026, sur un bloc qui ne porte qu'un « Solde Antérieur
                    //    1 198,74 » : « quand il n'y a pas de loyer appelé c'est qu'il est parti
                    //    débiteur ». Et sur un lot portant à la fois un occupant qui appelle ses
                    //    trois mois et d'autres noms sans loyer : « ce sont des locataires partis
                    //    débiteurs ». Le voisinage aide à COMPRENDRE, il ne conditionne rien :
                    //    c'est l'ABSENCE D'APPEL SOUS UN NOM qui démontre le départ.
                    //
                    // ⚠️ J'AI DÉJÀ POSÉ CETTE RÈGLE SANS GARDE, ET ELLE A FABRIQUÉ 871 DÉPARTS,
                    //    dont 518 que le moteur démentait lui-même. Ce qui a changé depuis n'est
                    //    pas la règle : c'est la LECTURE — six graphies d'appel au lieu d'une, et
                    //    les blocs coupés par une page enfin recollés. La garde `$saitLire`
                    //    reste, et le contrôle de contradiction juge le résultat.
                    $verdictParAppel = true;
                    $statut = $dette ? 'ANCIEN LOCATAIRE AVEC DETTE' : 'PARTI DEMONTRE';
                    $motif = 'Ce bloc n’appelle RIEN sous un nom d’occupant — ni loyer, ni '
                           . 'charge, ni dépôt de garantie : il est PARTI.'
                           . ($voisinAppelle
                              ? ' Un AUTRE bloc du même lot, au même arrêté, appelle : la '
                                . 'succession se voit sur la page.' : '')
                           . ($dette
                              ? ' Il reste DÉBITEUR de '
                                . number_format((float)$o['solde'], 2, ',', ' ') . ' € : la '
                                . 'dette reste attachée à CE locataire (P6A-CREANCE-07).'
                              : ' Aucune dette lue.');
                // ⚠️ UN ÉCART DE QUELQUES JOURS N'EST PAS UN TERME MANQUANT. Un compte rendu de
                //    DEUX JOURS (30/06 → 01/07) faisait « s'arrêter » un appel couvrant tout
                //    juin : l'écart était d'un jour, et le moteur y lisait un départ. Un appel
                //    est au minimum une quinzaine ; en deçà d'une semaine, l'écart est un
                //    artefact de bornes, jamais une absence de loyer.
                } elseif ($finAppel !== ''
                          && $finAppel < (string)$o['date_arrete']
                          && (strtotime((string)$o['date_arrete']) - strtotime($finAppel))
                             > CRGI_ECART_APPEL_MIN) {
                    $verdictParAppel = true;
                    $statut = $dette ? 'ANCIEN LOCATAIRE AVEC DETTE' : 'PARTI DEMONTRE';
                    $motif = 'Le dernier appel s’arrête au ' . $finAppel . ', avant l’arrêté du '
                           . $o['date_arrete'] . ' : la période n’est pas complète, le bail est '
                           . 'fini. Le départ est LU, pas déduit d’une absence.'
                           . ($dette
                              ? ' Encours de ' . number_format((float)$o['solde'], 2, ',', ' ')
                                . ' € : la dette reste attachée à CE locataire '
                                . '(P6A-CREANCE-07).'
                              : ' Aucune dette lue.');
                } else {
                    $enPlaceParAppel = true;
                    $verdictParAppel = true;
                }
            }

            if ($statut !== null) {
                // Le verdict est déjà rendu par les appels : on n'y superpose rien.
            } elseif ($loc === null) {
                // ⚠️ UN LOT SANS LIGNE LOCATAIRE N'EST PAS UN LOT VACANT : c'est un lot dont
                //    le document ne dit rien. Le vide ne se lit pas comme un départ.
                // ⚠️ SAUF QUAND LA QUESTION A DÉJÀ ÉTÉ TRANCHÉE PLUS TÔT. Voir `$trancheEnP2`.
                $vu = $trancheEnP2[(string)$o['compte']] ?? null;
                if ($vu !== null) {
                    $statut = 'PARTI DEMONTRE';
                    $motif = 'Aucune ligne « Locataire: » sur cette période — et ce compte a '
                           . 'déjà été tranché en phase 2 : « ' . $vu . ' ». La question ne se '
                           . 'repose pas d’une phase à l’autre.';
                } else {
                    $statut = 'A ARBITRER';
                    $motif = 'Aucune ligne « Locataire: » imprimée sur cette période : le '
                           . 'document ne dit rien de l’occupation. Ce n’est pas une vacance '
                           . 'démontrée.';
                }
            } elseif ($suiv && $suiv['locataire'] !== null
                      && crgi_plat((string)$suiv['locataire']) !== crgi_plat((string)$loc)
                      && !crgi_voisin_de_bloc($o, $suiv)) {
                // Le lot est RÉÉNONCÉ plus tard avec un autre occupant : le départ est démontré
                // par le document lui-même, pas par une absence.
                $dette = $o['solde_source'] === 'LUE' && (float)$o['solde'] > 0.005;
                $statut = $dette ? 'ANCIEN LOCATAIRE AVEC DETTE' : 'PARTI DEMONTRE';
                $motif = 'Le lot est réénoncé au ' . $suiv['date_arrete'] . ' avec « '
                       . $suiv['locataire'] . ' » : le départ est DÉMONTRÉ par le document.'
                       . ($dette
                          ? ' Encours de ' . number_format((float)$o['solde'], 2, ',', ' ')
                            . ' € à sa dernière période : la dette reste attachée à CE '
                            . 'locataire, elle ne passe pas au suivant (P6A-CREANCE-07).'
                          : ' Aucune dette lue à sa dernière période.')
                       . ' L’observation est CONSERVÉE.';
            } elseif ($prec === null) {
                if ($i === 0 && $n === 1) {
                    // ⚠️ CETTE BRANCHE POSAIT UNE QUESTION QUE LA SUIVANTE NE POSE PAS, SUR LA
                    //    MÊME PREUVE. Un lot vu à plusieurs périodes voyait sa PREMIÈRE période
                    //    acceptée d'office — « l'occupant y est déjà en place ». Le même lot vu
                    //    à une seule période devenait un arbitrage. Or le document démontre
                    //    exactement la même chose dans les deux cas : cette personne est
                    //    l'occupant de ce lot à cet arrêté. Le nombre de périodes qui SUIVENT
                    //    ne change rien à ce que la première énonce.
                    //
                    // ⚠️ ET LA DOCTRINE EST RESPECTÉE, PAS ASSOUPLIE. `ABSENCE ≠ DÉPART
                    //    DÉMONTRÉ` interdit de conclure à un départ ou à une entrée : on n'en
                    //    écrit aucun. On écrit l'occupation à sa date d'arrêté, qui est
                    //    imprimée. La chronologie, elle, reste indéterminée — et le motif le
                    //    dit, pour que personne ne lise « maintien démontré ».
                    //
                    // ⚠️ CE QUE CELA COÛTAIT : 254 questions sur un dépôt, 302 sur un autre —
                    //    la plus grosse famille d'arbitrage du projet, pour une information
                    //    que le document donne en clair. Emmanuel, 04/09/2026 : « tu ne
                    //    reconnais même pas un locataire parti d'un présent ? »
                    $statut = 'IDENTIQUE';
                    $motif = 'Seule période où ce lot apparaît dans ce dépôt : l’occupant y est '
                           . 'nommé et son occupation est écrite à cet arrêté. Ni entrée ni '
                           . 'départ ne sont démontrés — la chronologie reste indéterminée, '
                           . 'elle n’est pas déduite.';
                } elseif ($i === 0) {
                    $statut = 'IDENTIQUE';
                    $motif = 'Première période où ce lot apparaît : l’occupant y est déjà en '
                           . 'place, rien ne démontre une entrée.';
                } else {
                    $statut = 'NOUVEL ENTRANT';
                    $motif = 'Aucun titulaire lisible sur ce lot avant cette période'
                           . ($o['bail_du'] ? ', bail du ' . $o['bail_du'] . '.' : '.');
                }
            } elseif (crgi_plat((string)$loc) === crgi_plat((string)$prec)) {
                $statut = 'IDENTIQUE';
                $motif = 'Même titulaire qu’à la période précédente (' . $prec . ').'
                       . (!empty($o['bail_au'])
                          ? ' Un congé est imprimé au ' . $o['bail_au'] . ', POSTÉRIEUR à '
                            . 'l’arrêté : l’occupant est encore en place à cette date.' : '');
            } else {
                $statut = 'CHANGEMENT DE LOCATAIRE';
                $motif = 'Le titulaire des appels change sur le même lot entre deux périodes '
                       . 'consécutives : SUCCESSION LOCATIVE DÉMONTRÉE. « ' . $prec . ' » → « '
                       . $loc . ' »'
                       . ($o['bail_du'] ? ', bail du ' . $o['bail_du'] . '.' : '.');
            }

            // ⚠️ LE DÉPART NE SE DÉDUIT JAMAIS D'UNE ABSENCE. Si le lot cesse d'apparaître
            //    alors que le dépôt continue, on ne conclut pas : le CRG peut manquer.
            //
            // ⚠️ MAIS UNE ABSENCE NE CONTREDIT PAS UN APPEL LU. Ce contrôle transformait en
            //    question six lots CHAPONOST qui portaient « TERME Avril / Mai / Juin 2026 » —
            //    ils appelaient jusqu'au 30/06, date de l'arrêté. Ce qui les faisait
            //    « disparaître » était un compte rendu de DEUX JOURS (30/06 → 01/07) émis pour
            //    enregistrer le dépôt de garantie d'une entrante, MEYNADE Carole, sur un AUTRE
            //    lot. Un rapport ultérieur qui ne parle pas d'eux ne dit rien contre eux :
            //    `ABSENCE ≠ DÉPART` protège dans les deux sens.
            if ($statut !== 'A ARBITRER' && $loc !== null && $suiv === null
                && !$verdictParAppel && (string)$o['date_arrete'] < $derniere) {
                $statut = 'A ARBITRER';
                $motif = 'Dernière période où ce lot apparaît (' . $o['date_arrete'] . '), '
                       . 'alors que le dépôt va jusqu’au ' . $derniere . ' : le lot n’est pas '
                       . 'réénoncé ensuite. ABSENCE ≠ DÉPART DÉMONTRÉ.';
            }
            $maj->execute([$statut, mb_substr($motif, 0, 400), $prec, (int)$o['id']]);
        }
    }
}

/**
 * La chronologie d'un lot : qui l'occupait, période par période, et quel encours il portait.
 *
 * ⚠️ ON N'ADDITIONNE JAMAIS DEUX ENCOURS. Chaque période porte une PHOTOGRAPHIE du stock ; la
 *    variation est une différence entre deux photographies NOMMÉES, jamais un cumul.
 *    `STOCK ≠ FLUX`, certifié depuis P6.
 */
/**
 * L'IDENTITÉ D'UN LOT DANS CE MODULE : son compte ET sa référence.
 *
 * ⚠️ UNE RÉFÉRENCE LOCALE N'EST JAMAIS UNE IDENTITÉ GLOBALE. Le numéro de lot imprimé par le
 *    CRG vaut à l'intérieur de son immeuble ; deux comptes peuvent porter « Lot 01 » sans que
 *    ce soit le même appartement. Toute lecture qui regroupe des observations de lot passe
 *    par cette clé — jamais par la seule référence.
 */
/**
 * DEUX BLOCS DU MÊME LOT AU MÊME ARRÊTÉ : SUCCESSION, OU ANCIEN LOCATAIRE AFFICHÉ À CÔTÉ ?
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ L'ORDRE D'UNE PAGE NE PROUVE RIEN SUR LE TEMPS. Le document réimprime le bloc du lot pour
 *    chaque occupant, et le second n'est pas forcément le suivant : il peut être l'ANCIEN
 *    locataire, affiché à côté du titulaire parce qu'il traîne une dette. Le moteur y lisait
 *    une succession — **616 des 623 « départs » d'un dépôt** reposaient sur un occupant lu au
 *    MÊME arrêté, jamais sur une période ultérieure. Un locataire y était déclaré sorti six
 *    trimestres de suite pendant que sa dette passait de 6,28 € à 4 712,78 €.
 *
 * ⚠️ CE QUI TRANCHE, C'EST L'APPEL DE LOYER — Emmanuel, 07/09/2026 : « les appels de loyer
 *    déterminent qu'il est en place ». Le titulaire appelle son loyer mois par mois ; l'ancien
 *    ne porte qu'un solde figé, sans une seule ligne « Du … Au … ».
 *
 * ⚠️ ET SI LES DEUX APPELLENT, C'EST UNE VRAIE SUCCESSION DANS LE TRIMESTRE — « en cours de
 *    trimestre il peut y avoir un changement de locataire, et alors le dernier qui arrive dans
 *    le CRG est celui qui est devenu actif, et l'autre parti ». L'ordre d'impression retrouve
 *    alors son sens, mais SEULEMENT là : quand les deux ont prouvé qu'ils ont occupé.
 *
 * ⚠️ ON NE STATUE QUE SUR LE MÊME ARRÊTÉ. Deux observations de PÉRIODES différentes gardent la
 *    règle d'origine : une réénonciation ultérieure avec un autre nom démontre bien un départ.
 */
function crgi_voisin_de_bloc(array $o, array $suivant): bool
{
    if ((string)$o['date_arrete'] === '' || (string)$o['date_arrete'] !== (string)$suivant['date_arrete']) {
        return false;   // Périodes différentes : la règle d'origine s'applique.
    }
    // Les deux appellent : succession réelle dans le trimestre, l'ordre d'impression tranche.
    if ((int)($o['appels'] ?? 0) > 0 && (int)($suivant['appels'] ?? 0) > 0) {
        return false;
    }
    // Celui qui appelle est en place : le voisin n'est pas son successeur.
    return (int)($o['appels'] ?? 0) > 0;
}

function crgi_cle_lot(string $compte, string $lot): string
{
    return $compte . '§' . $lot;
}

function crgi_chronologie_lot(PDO $pdo, int $importId, string $compte, string $lot): array
{
    $st = $pdo->prepare(
        'SELECT o.periode_cle, o.date_arrete, o.locataire, o.bail_du, o.solde, o.solde_source,
                o.statut, o.page
           FROM crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
          WHERE o.import_id = ? AND c.compte = ? AND o.lot_reference = ?
          ORDER BY o.date_arrete, o.id'
    );
    $st->execute([$importId, $compte, $lot]);
    $suite = $st->fetchAll(PDO::FETCH_ASSOC);
    $avec = array_values(array_filter($suite, fn($o) => $o['solde_source'] === 'LUE'));
    $variation = null;
    if (count($avec) >= 2) {
        $variation = round((float)end($avec)['solde'] - (float)$avec[0]['solde'], 2);
    }
    return [
        'suite'       => $suite,
        'premier'     => $avec[0] ?? null,
        'dernier'     => $avec ? end($avec) : null,
        'variation'   => $variation,
        'periodes'    => count($suite),
        'sans_solde'  => count($suite) - count($avec),
    ];
}

/** Le bilan de la phase 3. */
function crgi_bilan_phase3(PDO $pdo, int $importId): array
{
    $q = function (string $sql) use ($pdo, $importId) {
        $st = $pdo->prepare($sql);
        $st->execute([$importId]);
        return $st;
    };
    $statuts = $q('SELECT statut, COUNT(*) n FROM crgi_occupation WHERE import_id = ?
                    GROUP BY statut')->fetchAll(PDO::FETCH_KEY_PAIR);
    // Les lots dont la chronologie porte un changement : ce sont eux qu'Emmanuel veut voir.
    $lotsChanges = $q(
        'SELECT DISTINCT c.compte, o.lot_reference
           FROM crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
          WHERE o.import_id = ? AND o.statut IN ("CHANGEMENT DE LOCATAIRE",
                "ANCIEN LOCATAIRE AVEC DETTE", "PARTI DEMONTRE", "NOUVEL ENTRANT")
          ORDER BY c.compte, o.lot_reference')->fetchAll(PDO::FETCH_ASSOC);
    // ⚠️ UNE LISTE, PLUS UNE TABLE INDEXÉE PAR LA RÉFÉRENCE. Indexer sur `lot_reference`
    //    écrasait la chronologie d'un compte par celle d'un autre portant le même numéro.
    $chronos = [];
    foreach (array_slice($lotsChanges, 0, 60) as $l) {
        $chronos[] = ['compte' => (string)$l['compte'], 'lot' => (string)$l['lot_reference']]
            + crgi_chronologie_lot($pdo, $importId, (string)$l['compte'],
                                   (string)$l['lot_reference']);
    }
    return [
        'statuts'      => $statuts,
        'observations' => (int)$q('SELECT COUNT(*) FROM crgi_occupation WHERE import_id = ?')
            ->fetchColumn(),
        // Un lot se compte sur son IDENTITÉ — compte × référence — pas sur son numéro.
        'lots'         => (int)$q('SELECT COUNT(DISTINCT c.compte, o.lot_reference)
                                     FROM crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
                                    WHERE o.import_id = ?')->fetchColumn(),
        'locataires'   => (int)$q('SELECT COUNT(DISTINCT locataire) FROM crgi_occupation
                                    WHERE import_id = ? AND locataire IS NOT NULL')
            ->fetchColumn(),
        'periodes'     => (int)$q('SELECT COUNT(DISTINCT date_arrete) FROM crgi_occupation
                                    WHERE import_id = ?')->fetchColumn(),
        'solde'        => $q('SELECT solde_source, COUNT(*) n FROM crgi_occupation
                               WHERE import_id = ? GROUP BY solde_source')
            ->fetchAll(PDO::FETCH_KEY_PAIR),
        'lots_changes' => count($lotsChanges),
        'chronos'      => $chronos,
    ];
}

/**
 * L'empreinte du résultat de la phase 3.
 *
 * ⚠️ ELLE COUVRE AUSSI LA QUALIFICATION D'ARBITRAGE. Sans le motif, un « À ARBITRER » pouvait
 *    changer de raison — passer de « aucun locataire lu » à « absence non concluante » — sans
 *    que la validation s'en aperçoive. Or c'est précisément sur ces quinze lignes que la
 *    limite de la phase est reconnue : elles doivent être scellées comme le reste.
 *
 * ⚠️ ET L'ENCOURS EN FAIT PARTIE, AVEC SA PROVENANCE. Un solde qui passerait de
 *    `NON DEMONTRABLE` à une valeur lue changerait la lecture financière du lot : le sceau
 *    doit le voir.
 *
 * ⚠️ ELLE COUVRE LE COMPTE, PARCE QUE LE COMPTE FAIT PARTIE DE L'IDENTITÉ DU LOT. Sceller
 *    « lot 01 » sans son compte, c'est sceller une identité qui n'existe pas : la même
 *    référence rattachée à un autre mandant ne changerait pas l'empreinte, alors qu'elle
 *    désignerait un autre appartement.
 */
function crgi_empreinte_phase3(PDO $pdo, int $importId): string
{
    // ⚠️ L'EMPREINTE NE DOIT RIEN DEVOIR À L'`AUTO_INCREMENT`. Elle portait `o.id`, or la
    //    phase supprime et réinsère ses observations : une relecture SANS AUCUN CHANGEMENT
    //    décalait les identifiants et faisait varier l'empreinte — la phase se serait déclarée
    //    périmée alors que son contenu était rigoureusement identique. Un sceau qui crie au
    //    loup ne vaut pas mieux qu'un sceau muet. On scelle le CONTENU, et on le trie.
    $st = $pdo->prepare(
        'SELECT c.compte, o.lot_reference, COALESCE(o.periode_cle,""),
                COALESCE(o.date_arrete,""), COALESCE(o.locataire,""), COALESCE(o.bail_du,""),
                COALESCE(o.statut,""), COALESCE(o.solde,""), o.solde_source,
                COALESCE(o.statut_motif,""), COALESCE(o.precedent,""), o.page
           FROM crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
          WHERE o.import_id = ?'
    );
    $st->execute([$importId]);
    $l = [];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) {
        $l[] = implode('|', $r);
    }
    // L'ordre de lecture ne doit pas peser : on scelle le CONTENU, trié.
    sort($l, SORT_STRING);
    return hash('sha256', implode("\n", $l));
}

/** Le bilan de la phase 0, tel que l'écran doit le montrer. */
function crgi_bilan_phase0(PDO $pdo, int $importId): array
{
    $q = function (string $sql) use ($pdo, $importId) {
        $st = $pdo->prepare($sql);
        $st->execute([$importId]);
        return $st;
    };
    $pages = (int)$q('SELECT COUNT(*) FROM crgi_page WHERE import_id = ?')->fetchColumn();
    $affect = (int)$q('SELECT COUNT(*) FROM crgi_page WHERE import_id = ? AND crg_id IS NOT NULL')
        ->fetchColumn();
    $declare = (int)$q('SELECT COALESCE(SUM(nb_pages),0) FROM crgi_piece WHERE import_id = ?')
        ->fetchColumn();
    // ⚠️ ON GROUPE SUR L'AGENCE ÉTABLIE, PAS SUR CELLE IMPRIMÉE. Sinon les CRG `lyon`
    //    tomberaient tous dans « (agence non lue) » alors que MBI les a rapprochés.
    $parAgence = $q(
        'SELECT COALESCE(a.nom_agence, c.agence, "(agence indéterminable)") AS agence_vue,
                c.agence_source,
                COALESCE(c.periode_cle, "(période indéterminable)") AS periode,
                COUNT(*) AS n,
                SUM(c.certitude = "CERTAIN") AS certains
           FROM crgi_crg c LEFT JOIN agences a ON a.id = c.agence_id
          WHERE c.import_id = ?
          GROUP BY COALESCE(a.nom_agence, c.agence, "(agence indéterminable)"),
                   c.agence_source, c.periode_cle
          ORDER BY agence_vue, periode'
    )->fetchAll(PDO::FETCH_ASSOC);
    $certitudes = $q('SELECT certitude, COUNT(*) n FROM crgi_crg WHERE import_id = ?
                      GROUP BY certitude')->fetchAll(PDO::FETCH_KEY_PAIR);
    // ⚠️ LES DEUX COMPTAGES QUI DÉCIDENT SI LA PHASE 0 EST COMPLÈTE. Tant qu'un CRG n'a ni
    //    agence ni période établie, l'identification documentaire n'est pas terminée — et une
    //    phase 0 validée sur une identification inachevée n'aurait rien validé du tout.
    $agenceSrc = $q('SELECT agence_source, COUNT(*) n FROM crgi_crg WHERE import_id = ?
                     GROUP BY agence_source')->fetchAll(PDO::FETCH_KEY_PAIR);
    $periodeSrc = $q('SELECT periode_source, COUNT(*) n FROM crgi_crg WHERE import_id = ?
                      GROUP BY periode_source')->fetchAll(PDO::FETCH_KEY_PAIR);
    $horsCrg = (int)$q('SELECT COUNT(*) FROM crgi_page WHERE import_id = ? AND crg_id IS NULL
                        AND signal_page LIKE "%appel de fonds%"')->fetchColumn();
    $doublons = $q('SELECT doublon_statut, COUNT(*) n FROM crgi_crg WHERE import_id = ?
                    GROUP BY doublon_statut')->fetchAll(PDO::FETCH_KEY_PAIR);
    $chevauche = (int)$q(
        'SELECT COUNT(*) FROM (
            SELECT a.id FROM crgi_crg a JOIN crgi_crg b
              ON a.import_id = b.import_id AND a.piece_id = b.piece_id AND a.id < b.id
             AND a.page_debut <= b.page_fin AND b.page_debut <= a.page_fin
           WHERE a.import_id = ?) x'
    )->fetchColumn();

    return [
        'pages_declarees'  => $declare,
        'pages_analysees'  => $pages,
        'pages_affectees'  => $affect,
        'pages_hors_crg'   => $horsCrg,
        'pages_non_affect' => $pages - $affect - $horsCrg,
        'doublons'         => $doublons,
        'situations'       => (int)($doublons['UNIQUE'] ?? 0),
        'pages_perdues'    => $declare - $pages,
        'crg_detectes'     => (int)$q('SELECT COUNT(*) FROM crgi_crg WHERE import_id = ?')
                                ->fetchColumn(),
        'certitudes'       => $certitudes,
        'agence_source'    => $agenceSrc,
        'periode_source'   => $periodeSrc,
        'par_agence'       => $parAgence,
        'chevauchements'   => $chevauche,
        'bloquants'        => (int)($agenceSrc['INDETERMINABLE'] ?? 0)
                            + (int)($periodeSrc['INDETERMINABLE'] ?? 0),
    ];
}

/* ═════════════════════════════════════════════════════════════════════════════════════════
 *  PHASE 4 — FINANCES
 *  ═══════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CHAQUE EURO PORTE SA NATURE, SA MAILLE ET SA PROVENANCE. La phase 4 ne produit aucun
 *    total qu'on ne puisse rouvrir jusqu'à sa page : `crgi_mouvement` ne stocke que des
 *    mouvements élémentaires, et tout agrégat se recalcule à partir d'eux.
 *
 * ⚠️ `APPEL ≠ ENCAISSEMENT ≠ AFFECTATION ≠ SOLDE.` Un encaissement peut solder une période
 *    ANTÉRIEURE — sur le premier CRG lu, 104,00 € et 474,49 € encaissés portent sur décembre
 *    et janvier, hors de la période du rapport. L'écart `appelé − encaissé` n'est donc JAMAIS
 *    « l'impayé de la période », et la phase 4 ne le calcule nulle part.
 *
 * ⚠️ `STOCK ≠ FLUX.` Encours et soldes sont des photographies : `flux = 0`, jamais cumulées.
 *
 * ⚠️ `AGRÉGAT ≠ MOUVEMENT ÉLÉMENTAIRE.` Le « Récapitulatif des immeubles » rejoue ce que les
 *    blocs ont déjà dit. Conservé parce qu'il sert de contrôle, `additionnable = 0`.
 *
 * ⚠️ AUCUNE ÉCRITURE MÉTIER.
 */
function crgi_phase4(PDO $pdo, int $importId): array
{
    crgi_verrouiller_import($pdo, $importId, 4);
    $etat3 = crgi_phase_validee($pdo, $importId, 3);
    if (!$etat3['validee'] || $etat3['perimee']) {
        throw new RuntimeException(
            'PHASE 3 NON VALIDÉE — la phase 4 ne s’ouvre pas. Rattacher de l’argent à des '
            . 'lots et à des locataires non scellés ferait reposer les montants sur une '
            . 'occupation mouvante.'
        );
    }
    crgi_marquer_phase($pdo, $importId, 4, 'EN ANALYSE', null);
    $pdo->prepare('DELETE FROM crgi_mouvement WHERE import_id = ?')->execute([$importId]);
    crgi_lire_finances($pdo, $importId);
    crgi_qualifier_compensations($pdo, $importId);
    crgi_marquer_phase($pdo, $importId, 4, 'A VALIDER', null);
    return crgi_bilan_phase4($pdo, $importId)['categories'];
}

/**
 * LES COMPENSATIONS ENTRE MANDATS NE SORTENT PAS DE L'AGENCE.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ UNE RÉGIE SOLDE PARFOIS DES MANDATS ENTRE EUX. Le compte A est crédité de ce que le
 *    compte B est débité, et pas un euro ne quitte la maison. Ces écritures s'impriment en
 *    colonne CRÉDIT dans la section des soldes, exactement comme un vrai reversement — et
 *    elles étaient comptées comme tel : **534 847,60 € sur 2 790 132,12 €**, soit 19,2 % des
 *    « versements » d'un dépôt. C'est ce qui faisait reverser 253 % de ce qui était encaissé.
 *
 * ⚠️ ELLE SE PROUVE PAR SA STRUCTURE, JAMAIS PAR SON LIBELLÉ. Le mot est d'ailleurs écrit
 *    « COMPENDSATION » sur trois de ces lignes, et `NE JAMAIS DÉDUIRE UNE NATURE D'UN
 *    LIBELLÉ` l'interdirait de toute façon. Ce qui la démontre est l'APPARIEMENT : le même
 *    libellé, le même montant, en crédit ici et en débit là, dans le même dépôt. Un fait de
 *    structure, insensible à l'orthographe.
 *
 * ⚠️ ON EXIGE LE MÊME MONTANT, ET C'EST LA CONDITION QUI ÉVITE LE FAUX POSITIF. Deux
 *    opérations réelles de sens opposés peuvent partager un libellé ; elles partagent
 *    rarement le centime. Sans cette exigence, un versement légitime disparaîtrait.
 *
 * ⚠️ ON NE SUPPRIME RIEN. La ligne reste, avec sa page et sa preuve ; seule sa NATURE change,
 *    et son motif dit pourquoi. Un mouvement de trésorerie interne reste un mouvement.
 */
function crgi_qualifier_compensations(PDO $pdo, int $importId): void
{
    // ⚠️ DEUX MARQUEURS DISTINCTS POUR LA MÊME VALEUR. Un placeholder nommé réutilisé dans
    //    une requête PDO lève « Invalid parameter number » — piège déjà consigné, et dans
    //    lequel cette fonction est tombée à sa première exécution.
    $st = $pdo->prepare(
        'UPDATE crgi_mouvement m
            JOIN (SELECT libelle, ROUND(montant, 2) v FROM crgi_mouvement
                   WHERE import_id = :i1 AND colonne = "debit" AND montant <> 0
                   GROUP BY libelle, v) d
              ON d.libelle = m.libelle AND d.v = ROUND(m.montant, 2)
            SET m.categorie = "COMPENSATION ENTRE MANDATS",
                m.motif = CONCAT("Compensation démontrée par appariement : le même libellé "
                                 "et le même montant figurent en DÉBIT sur un autre compte "
                                 "du dépôt. L’argent ne sort pas de l’agence — ce n’est pas "
                                 "un versement au propriétaire. Nature précédente : ",
                                 COALESCE(m.categorie, "?"))
          WHERE m.import_id = :i2 AND m.colonne = "credit"
            AND m.categorie = "VERSEMENT PROPRIETAIRE"'
    );
    $st->execute([':i1' => $importId, ':i2' => $importId]);
}

/**
 * Lit l'argent de chaque CRG — une seule lecture du PDF par pièce (`INTEG-PERF-01`).
 *
 * ⚠️ LA LECTURE EST GÉOMÉTRIQUE, PARCE QUE LA COLONNE EST LA NATURE. `-layout` fait dériver
 *    les montants d'une ligne à l'autre et `-table` ne dit plus de quelle colonne ils
 *    viennent. La phase 4 lit donc les coordonnées — 0,07 s la page — et affecte chaque
 *    montant par son BORD DROIT. Chaque phase lit dans le mode qui démontre SA donnée.
 */
function crgi_lire_finances(PDO $pdo, int $importId): void
{
    $python = getenv('CRG_PYTHON') ?: (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
    $script = realpath(__DIR__ . '/../scripts/crg_integration_phase4.py');
    if (!$script) {
        throw new RuntimeException('MOTEUR ABSENT : scripts/crg_integration_phase4.py');
    }
    $st = $pdo->prepare(
        'SELECT c.id, c.page_debut, c.page_fin, c.periode_cle, c.date_arrete, c.format, p.chemin
           FROM crgi_crg c JOIN crgi_piece p ON p.id = c.piece_id
          WHERE c.import_id = ? AND c.doublon_statut <> "REENONCIATION" ORDER BY c.page_debut'
    );
    $st->execute([$importId]);
    $meta = $parPiece = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $meta[(int)$c['id']] = $c;
        // La famille voyage avec la plage — voir `crgi_commande_lecture`.
        $parPiece[(string)$c['chemin'] . CRGI_SEP_FAMILLE . (string)$c['format']][] =
            ['id' => (int)$c['id'], 'debut' => (int)$c['page_debut'],
             'fin' => (int)$c['page_fin']];
    }
    $ins = $pdo->prepare(
        'INSERT INTO crgi_mouvement
            (import_id, crg_id, page, section, immeuble, lot_reference, locataire, date_piece,
             periode_cle, date_arrete, libelle, colonne, montant, categorie, maille, flux,
             additionnable, reimpression, provenance, motif, x1)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    // Les catégories qui sont des PHOTOGRAPHIES, et celles qu'on ne somme jamais.
    $stocks = ['ENCOURS' => 1, 'SOLDE' => 1];
    $jamaisSommees = ['AGREGAT (NON ADDITIONNABLE)' => 1, 'DETAIL (NON ADDITIONNABLE)' => 1,
                      'INDETERMINABLE' => 1];
    foreach ($parPiece as $clef => $plages) {
        [$chemin, $format] = explode(CRGI_SEP_FAMILLE, $clef, 2);
        $fichier = tempnam(sys_get_temp_dir(), 'crgi4_');
        file_put_contents($fichier, json_encode($plages));
        $cmd = crgi_commande_lecture($python, $script, $chemin, $format, $fichier, 'mouvements');
        $sortie = trim((string)@shell_exec($cmd . ' 2>&1'));
        @unlink($fichier);
        $r = json_decode($sortie, true);
        if (!is_array($r)) {
            // ⚠️ FAIL CLOSED : un moteur muet n'est pas un dépôt sans argent.
            throw new RuntimeException('MOTEUR FINANCES MUET OU ILLISIBLE : '
                                     . mb_substr($sortie, 0, 300));
        }
        foreach ($r as $bloc) {
            $c = $meta[(int)$bloc['id']] ?? null;
            if (!$c) {
                continue;
            }
            foreach ($bloc['mouvements'] ?? [] as $m) {
                $cat = (string)$m['categorie'];
                $rei = !empty($m['reimpression']);
                $ins->execute([
                    $importId, (int)$c['id'], (int)$m['page'], $m['section'], $m['immeuble'],
                    $m['lot'], $m['locataire'],
                    $m['date_piece'] ? crgi_jour((string)$m['date_piece']) : null,
                    $c['periode_cle'], $c['date_arrete'],
                    (string)$m['libelle'], (string)$m['colonne'], (float)$m['montant'], $cat,
                    (string)$m['maille'],
                    isset($stocks[$cat]) ? 0 : 1,
                    ($rei || isset($jamaisSommees[$cat])) ? 0 : 1,
                    $rei ? 1 : 0,
                    // ⚠️ LA PROVENANCE DIT AUSSI CE QUI NE COMPTE PAS. Une ligne réimprimée
                    //    reste LUE sur le document ; c'est son statut qui la met hors des
                    //    sommes. L'écrire explicitement évite qu'on la reprenne un jour pour
                    //    une ligne ordinaire.
                    $rei ? 'REIMPRESSION' : 'LUE',
                    mb_substr((string)$m['motif'], 0, 400), (float)$m['x1'],
                ]);
            }
        }
    }
}

/** « 31/05/2026 » -> « 2026-05-31 ». */
function crgi_jour(string $fr): ?string
{
    return preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', trim($fr), $m)
        ? $m[3] . '-' . $m[2] . '-' . $m[1] : null;
}

/**
 * LES QUATRE POPULATIONS DE LA TABLE `crgi_mouvement` — parce qu'un `COUNT(*)` n'en nomme
 * aucune.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ « MOUVEMENT » DÉSIGNAIT QUATRE CHOSES À LA FOIS, ET LE KPI DIVISAIT PAR LA PLUS GRANDE.
 *    Emmanuel, 06/09/2026 : nommer les populations AVANT de corriger le ratio. La table porte
 *    38 461 lignes sur les quatre dépôts, et seules 28 056 sont des mouvements au sens
 *    comptable. Diviser par 38 461 flattait le score de 27 % — sans qu'aucune ligne ne soit
 *    fausse : elles ne répondaient simplement pas à la même question.
 *
 * ⚠️ LES QUATRE, DU PLUS LARGE AU PLUS ÉTROIT, ET CE QUE CHACUNE SERT :
 *      ❶ `lignes`      — TOUT ce qui a été lu. Sert à mesurer le LECTEUR : une ligne muette
 *                        est un défaut de lecture, même si c'est une réimpression.
 *      ❷ `uniques`     — sans les réimpressions. `RÉIMPRESSION ≠ NOUVEL ÉVÉNEMENT` : un CRG
 *                        réédité réimprime des écritures déjà connues.
 *      ❸ `additionnables` — sans les agrégats ni les détails. `AGRÉGAT ≠ MOUVEMENT` : un
 *                        total de colonne n'est pas une écriture de plus.
 *      ❹ `mouvements`  — et sans les stocks. `STOCK ≠ FLUX` : un encours est une photographie
 *                        au dernier arrêté, pas un événement de la période. C'est CELLE-CI
 *                        que dénombre un ratio « par mouvement ».
 *
 * ⚠️ AUCUNE NE SE DÉDUIT D'UNE AUTRE PAR RÈGLE DE TROIS. Le rapport varie d'un dépôt à
 *    l'autre — LYON réimprime, EMERY non — et une population estimée serait un chiffre
 *    inventé. On les compte, toutes les quatre, sur la même requête.
 */
function crgi_population_mouvements(PDO $pdo, ?int $importId = null): array
{
    $ou = $importId === null ? '1' : 'import_id = ' . (int)$importId;
    $r  = $pdo->query(
        "SELECT COUNT(*) lignes,
                SUM(reimpression = 0) uniques,
                SUM(reimpression = 0 AND additionnable = 1) additionnables,
                SUM(reimpression = 0 AND additionnable = 1 AND flux = 1) mouvements
           FROM crgi_mouvement WHERE $ou"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    return array_map('intval', [
        'lignes'         => $r['lignes']         ?? 0,
        'uniques'        => $r['uniques']        ?? 0,
        'additionnables' => $r['additionnables'] ?? 0,
        'mouvements'     => $r['mouvements']     ?? 0,
    ]);
}

/**
 * UN COMPTE RENDU QUI NE PRODUIT AUCUN PATRIMOINE — ET QU'ON NE DEVINE PAS.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ LE SILENCE EST LA FAUTE. Un CRG qui ne rend ni immeuble ni lot n'est pas un résultat
 *    vide : c'est une QUESTION. Aujourd'hui il disparaissait sans trace — aucun contrôle ne
 *    comparait « comptes rendus porteurs » à « comptes rendus ayant produit un objet ».
 *    Mesuré : **19 sur 947**, dont 10 chez une agence et 8 chez une autre.
 *
 * ⚠️ ET LE DOCUMENT NE PERMET PAS DE CHOISIR — Emmanuel, 07/09/2026 : « pas d'appel de loyer,
 *    alors il n'y a plus de locataire actif : soit appartement vacant, soit perte de gestion,
 *    et tu ne peux pas le voir, il faut poser la question ». Le cas réel qu'il a tranché —
 *    Indivision MARTIN, un trimestre entier tenant dans un report de 33,60 € — était un
 *    **BIEN VENDU**. Quatre causes possibles, et le CRG n'en désigne aucune :
 *      · BIEN VENDU          — le propriétaire ne le possède plus ;
 *      · BIEN VACANT         — il le possède, personne ne l'occupe, rien n'est appelé ;
 *      · GESTION TERMINEE    — il le possède et l'occupe, mais confie la gestion ailleurs ;
 *      · COMPTE TECHNIQUE    — une COQUILLE VIDE, sans patrimoine par construction, qui sert
 *        à payer des factures HORS GESTION (Emmanuel, 07/09/2026, à propos de « GPE SIR STE »).
 *    Les trois premières sont des ÉVÉNEMENTS COMMERCIAUX que seul Emmanuel connaît. La
 *    quatrième n'est pas un événement du tout : c'est une NATURE DE COMPTE, permanente, et
 *    elle ne devrait jamais être reposée une fois dite. Elle se reconnaît à ce que le compte
 *    n'a JAMAIS porté d'immeuble — mais seul Emmanuel peut confirmer qu'elle est voulue.
 *
 * ⚠️ ON DONNE DONC LE SEUL FAIT QUI ORIENTE : CE COMPTE A-T-IL DÉJÀ PORTÉ DU PATRIMOINE ?
 *    S'il en portait avant et n'en porte plus, quelque chose s'est terminé — vente, congé,
 *    mandat perdu. S'il n'en a jamais porté, ce n'est pas une fin, c'est une autre nature de
 *    compte. Le moteur ne conclut pas : il pose la question avec ce qu'il sait.
 */
// ⚠️ LES QUATRE RÉPONSES, DÉCLARÉES ICI ET NULLE PART AILLEURS. Une liste écrite dans l'écran
//    dériverait du jour où un second écran la recopierait. Les trois premières sont des
//    ÉVÉNEMENTS — elles reviendront à chaque trimestre ; la quatrième est une NATURE DE
//    COMPTE, permanente : dite une fois, elle ne se redemande plus.
// Le type sous lequel la mémoire du moteur range ces décisions, dans `crgi_identite`.
const CRGI_IDENTITE_COMPTE_SANS_PATRIMOINE = 'COMPTE-SANS-PATRIMOINE';

const CRGI_CHOIX_SANS_PATRIMOINE = [
    'BIEN VENDU'       => 'Le propriétaire ne le possède plus.',
    'BIEN VACANT'      => 'Il le possède, personne ne l’occupe, rien n’est appelé.',
    'GESTION TERMINEE' => 'Il le possède et il est occupé, mais la gestion est ailleurs.',
    'COMPTE TECHNIQUE' => 'Coquille sans patrimoine, qui sert à payer hors gestion.',
];

/**
 * ENREGISTRER UNE DÉCISION PORTANT SUR UN COMPTE RENDU ENTIER.
 *
 * ⚠️ LA FILE D'ARBITRAGE NE CONNAISSAIT QUE QUATRE CIBLES — immeuble, lot, occupation,
 *    mouvement — toutes nées des phases 2 à 5. Les questions des phases 0 et 1 portent sur le
 *    COMPTE RENDU lui-même et n'avaient donc aucun guichet : le moteur posait des questions
 *    sans avoir prévu où l'on répond. Une question sans guichet n'est pas une question, c'est
 *    une perte.
 *
 * ⚠️ ÉCRITURE TECHNIQUE, JAMAIS MÉTIER. La décision vit dans `crgi_arbitrage` ; rien n'est
 *    créé, modifié ni supprimé dans MBI. Décider n'est pas intégrer.
 *
 * ⚠️ ET UN CHOIX VIDE EFFACE, il ne stocke pas du vide : retirer sa décision est une décision.
 */
function crgi_decider_crg(PDO $pdo, int $importId, int $crgId, string $groupe,
                          array $choixPossibles, string $choix, int $userId): void
{
    if ($choix !== '' && !isset($choixPossibles[$choix])) {
        throw new RuntimeException(
            'CHOIX INCONNU POUR CETTE QUESTION : « ' . $choix . ' ». Les réponses possibles '
            . 'sont : ' . implode(' · ', array_keys($choixPossibles))
        );
    }
    // ⚠️ LA DÉCISION PORTE SUR LE COMPTE MANDANT, PAS SUR LE DOCUMENT. Un compte rendu
    //    continue d'être édité tant qu'un solde n'est pas apuré : le même compte revient
    //    trimestre après trimestre avec le même report — 22,00 € au T1, 22,00 € au T2, sans
    //    un mouvement. Stockée par document, la réponse « vendu » a déjà dû être donnée DEUX
    //    FOIS pour un seul bien, et l'aurait été à chaque trimestre suivant, indéfiniment.
    //
    // ⚠️ ET LA CLÉ EST (AGENCE, COMPTE), JAMAIS LE COMPTE SEUL. Un numéro n'est unique que
    //    dans son espace de nommage — `INTEG-P1-ESPACE-DE-NOMMAGE`. Mémoriser « 02200000 =
    //    vendu » sans l'agence classerait vendu un mandant de l'autre société.
    $ctx = $pdo->prepare('SELECT c.compte, COALESCE(a.nom_agence, c.agence, "") agence
                            FROM crgi_crg c LEFT JOIN agences a ON a.id = c.agence_id
                           WHERE c.id = ?');
    $ctx->execute([$crgId]);
    $ou = $ctx->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($choix === '') {
        $pdo->prepare('DELETE FROM crgi_arbitrage
                        WHERE import_id = ? AND cible_type = "CRG" AND cible_id = ?')
            ->execute([$importId, $crgId]);
        if ($ou && $ou['compte'] !== null) {
            $pdo->prepare('DELETE FROM crgi_identite
                            WHERE type = ? AND agence = ? AND cle = ?')
                ->execute([CRGI_IDENTITE_COMPTE_SANS_PATRIMOINE,
                           (string)$ou['agence'], (string)$ou['compte']]);
        }
        return;
    }
    $pdo->prepare(
        'INSERT INTO crgi_arbitrage (import_id, groupe, cible_type, cible_id, choix, decide_par)
         VALUES (?,?,"CRG",?,?,?)
         ON DUPLICATE KEY UPDATE groupe = VALUES(groupe), choix = VALUES(choix),
                                 decide_par = VALUES(decide_par)'
    )->execute([$importId, $groupe, $crgId, $choix, $userId ?: null]);

    // ⚠️ ET ELLE DEVIENT UNE MÉMOIRE. `crgi_identite` est déjà le registre des décisions
    //    durables : une réponse donnée une fois vaut pour tous les comptes rendus du même
    //    compte, dans ce dépôt comme dans les suivants. C'est ce qui fait qu'une question
    //    posée une fois ne se repose pas.
    if ($ou && (string)$ou['compte'] !== '') {
        $pdo->prepare(
            'INSERT INTO crgi_identite (type, agence, cle, choix, import_origine, decide_par)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE choix = VALUES(choix), decide_par = VALUES(decide_par),
                                     decide_le = NOW()'
        )->execute([CRGI_IDENTITE_COMPTE_SANS_PATRIMOINE, (string)$ou['agence'],
                    (string)$ou['compte'], $choix, $importId, $userId ?: null]);
    }
}

function crgi_crg_sans_patrimoine(PDO $pdo, int $importId): array
{
    $st = $pdo->prepare(
        'SELECT c.id, c.compte, c.proprietaire, c.periode_cle, c.date_arrete, c.page_debut,
                (SELECT COUNT(*) FROM crgi_crg a
                   JOIN crgi_immeuble m ON m.crg_id = a.id
                  WHERE a.import_id = c.import_id AND a.compte = c.compte
                    AND a.date_arrete < c.date_arrete) AS patrimoine_avant,
                (SELECT COUNT(*) FROM crgi_crg a
                   JOIN crgi_immeuble m ON m.crg_id = a.id
                  WHERE a.import_id = c.import_id AND a.compte = c.compte) AS patrimoine_jamais
           FROM crgi_crg c
          WHERE c.import_id = ? AND ' . CRGI_CRG_PORTEURS . '
            AND NOT EXISTS (SELECT 1 FROM crgi_immeuble m WHERE m.crg_id = c.id)
          ORDER BY c.compte, c.date_arrete'
    );
    $st->execute([$importId]);
    $lignes = $st->fetchAll(PDO::FETCH_ASSOC);
    // Ce qui a DÉJÀ été décidé sur CE document.
    $dejaDit = $pdo->prepare('SELECT cible_id, choix FROM crgi_arbitrage
                               WHERE import_id = ? AND cible_type = "CRG"');
    $dejaDit->execute([$importId]);
    $decisions = $dejaDit->fetchAll(PDO::FETCH_KEY_PAIR);

    // ⚠️ ET CE QUI A ÉTÉ APPRIS SUR LE COMPTE — la réponse donnée un trimestre vaut pour
    //    tous les suivants. C'est la différence entre un moteur qui retient et un moteur qui
    //    repose la même question indéfiniment.
    $agenceDe = $pdo->prepare('SELECT COALESCE(a.nom_agence, c.agence, "")
                                 FROM crgi_crg c LEFT JOIN agences a ON a.id = c.agence_id
                                WHERE c.id = ?');
    $lecteur = crgi_binaire('pdftotext');
    $page = $pdo->prepare('SELECT pc.chemin, c.page_debut FROM crgi_crg c
                             JOIN crgi_page pg ON pg.crg_id = c.id AND pg.page_no = c.page_debut
                             JOIN crgi_piece pc ON pc.id = pg.piece_id
                            WHERE c.id = ? LIMIT 1');
    // Les indivisions du dépôt qui portent RÉELLEMENT du patrimoine — la seconde condition
    // de la quote-part, celle qui empêche de croire une ligne sur parole.
    $porteurs = $pdo->prepare(
        'SELECT DISTINCT c.proprietaire FROM crgi_crg c
           JOIN crgi_immeuble m ON m.crg_id = c.id
          WHERE c.import_id = ? AND c.proprietaire <> ""'
    );
    $porteurs->execute([$importId]);
    $indivisions = [];
    foreach ($porteurs->fetchAll(PDO::FETCH_COLUMN) as $nom) {
        $indivisions[crgi_cle_nom((string)$nom)] = (string)$nom;
    }

    foreach ($lignes as &$l) {
        $l['decision'] = $decisions[(int)$l['id']] ?? null;
        $l['apprise']  = null;
        if ($l['decision'] === null && (string)$l['compte'] !== '') {
            $agenceDe->execute([(int)$l['id']]);
            $appris = crgi_identite_apprise($pdo, CRGI_IDENTITE_COMPTE_SANS_PATRIMOINE,
                (string)$agenceDe->fetchColumn(), (string)$l['compte']);
            if ($appris) {
                $l['decision'] = (string)$appris['choix'];
                $l['apprise']  = 'Réponse déjà donnée sur ce compte — elle ne se redemande pas.';
            }
        }
        // ⚠️ LA QUOTE-PART SE DÉMONTRE, ELLE NE SE DEMANDE PAS. Deux conditions, jamais une :
        //    la ligne imprimée « N/100 de … » ET l'existence de l'indivision nommée comme
        //    mandant PORTEUR d'immeubles dans le même dépôt. Une ligne seule pourrait citer
        //    une indivision qui n'existe pas ; une indivision seule ne dit rien du taux.
        if ($l['decision'] === null && $lecteur) {
            $page->execute([(int)$l['id']]);
            if ($p = $page->fetch(PDO::FETCH_ASSOC)) {
                $n = (int)$p['page_debut'];
                $txt = (string)shell_exec(escapeshellarg($lecteur) . ' -layout -f ' . $n
                     . ' -l ' . $n . ' ' . escapeshellarg((string)$p['chemin']) . ' - 2>'
                     . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'));
                if (preg_match('~(\d{1,3})\s*/\s*(\d{1,4})\s+(?:de|du)\s+(.{3,60})~i', $txt, $m)) {
                    $cible = crgi_cle_nom($m[3]);
                    foreach ($indivisions as $cle => $nom) {
                        if ($cle !== '' && str_starts_with($cible, $cle)) {
                            $l['decision'] = 'QUOTE-PART D’INDIVISION';
                            $l['apprise'] = 'Démontré par le document : « ' . trim($m[1])
                                . '/' . trim($m[2]) . ' de ' . $nom . ' », et cette indivision '
                                . 'porte réellement du patrimoine dans ce dépôt.';
                            break 1;
                        }
                    }
                }
            }
        }
        // ⚠️ « JAMAIS » N'EST PAS « PLUS » — et c'est toute la différence entre une nature de
        //    compte et un événement commercial.
        // ⚠️ UNE PROPOSITION N'EST PAS UNE DÉCISION, MAIS ELLE FAIT GAGNER LE TEMPS. Elle dit
        //    ce que le FAIT observé rend le plus probable, et le fait est écrit à côté pour
        //    qu'on puisse la refuser en un coup d'œil. Sans elle, il faut tout reconstruire
        //    de tête, ligne après ligne.
        if ((int)$l['patrimoine_jamais'] === 0) {
            $l['proposition'] = 'COMPTE TECHNIQUE';
            $l['parce_que']   = 'n’a JAMAIS porté d’immeuble dans ce dépôt — coquille servant '
                              . 'à payer hors gestion. Nature de compte, pas fin de gestion : '
                              . 'dite une fois, elle ne se redemande plus.';
        } elseif ((int)$l['patrimoine_avant'] > 0) {
            $l['proposition'] = 'VENDU ou GESTION TERMINÉE';
            $l['parce_que']   = 'portait du patrimoine sur une période ANTÉRIEURE et n’en '
                              . 'porte plus : quelque chose s’est terminé. Le document ne dit '
                              . 'pas quoi — vous seul le savez.';
        } else {
            $l['proposition'] = 'BIEN VACANT';
            $l['parce_que']   = 'porte du patrimoine sur une AUTRE période du dépôt, mais pas '
                              . 'sur celle-ci : l’interruption est temporaire, pas une fin.';
        }
    }
    return $lignes;
}

/* ═══════════════════════════════════════════════════════════════════════════════════════════
 * PHASE 3 — LES OCCUPATIONS QUE LE DOCUMENT NE TRANCHE PAS, ET OÙ L'ON Y RÉPOND.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CES QUESTIONS ÉTAIENT AFFICHÉES SANS GUICHET. L'écran listait dix-sept occupations « A
 *    ARBITRER » en lecture seule : Emmanuel, 07/09/2026 — « JE NE PEUX PAS RÉPONDRE SUR TES
 *    QUESTIONS ». C'est la MÊME faute que la phase 1 avait commise, refaite un cran plus loin :
 *    poser une question sans avoir prévu où l'on répond n'est pas poser une question, c'est
 *    perdre le travail de lecture. Toute question affichée porte désormais son formulaire.
 *
 * ⚠️ ET ELLES NE SONT PAS D'UNE SEULE ESPÈCE — deux familles, deux propositions :
 *      · LOT DISPARU  le lot était lu, puis il cesse d'apparaître avant la fin du dépôt ;
 *      · LOT MUET     le lot est lu, mais aucune ligne « Locataire » n'est imprimée.
 *    Les confondre ferait proposer « vacant » là où c'est un mandat qui s'arrête.
 *
 * ⚠️ TOUTES LES RÉPONSES NE SE MÉMORISENT PAS, ET C'EST LE POINT DÉLICAT. « Vacant » et
 *    « toujours en place » décrivent UNE PÉRIODE : les mémoriser condamnerait le lot à rester
 *    vide au trimestre suivant, alors que le document, lui, dira le contraire — c'est
 *    exactement `ABSENCE ≠ DÉPART DÉMONTRÉ` retourné contre nous. « Gestion terminée » et
 *    « lot vendu » décrivent le LOT : elles valent pour toujours et ne se redemandent plus.
 */
const CRGI_IDENTITE_LOT_HORS_GESTION = 'LOT-HORS-GESTION';

const CRGI_CHOIX_OCCUPATION = [
    'LOCATAIRE TOUJOURS EN PLACE' => 'Il occupe toujours ; le document ne l’a pas réimprimé.',
    'LOGEMENT VACANT'             => 'Personne ne l’occupe sur cette période : rien n’est appelé.',
    'LOCATAIRE PARTI'             => 'Il est sorti ; le lot reste en gestion et se reloue.',
    'GESTION TERMINEE'            => 'Le lot sort de la gestion : il ne reviendra plus.',
    'LOT VENDU'                   => 'Le propriétaire ne le possède plus : il ne reviendra plus.',
];

/** Les seules réponses qui décrivent le LOT, et non une période : elles seules se mémorisent. */
const CRGI_OCCUPATION_DEFINITIF = ['GESTION TERMINEE', 'LOT VENDU'];

/**
 * ENREGISTRER UNE DÉCISION PORTANT SUR UNE OCCUPATION.
 *
 * ⚠️ ÉCRITURE TECHNIQUE, JAMAIS MÉTIER — comme `crgi_decider_crg()`. Rien n'est créé ni modifié
 *    dans MBI : décider n'est pas intégrer.
 *
 * ⚠️ LA CLÉ DURABLE EST (AGENCE, COMPTE + LOT), JAMAIS LE LOT SEUL. Une référence de lot
 *    n'existe que dans son espace de nommage — `INTEG-P1-ESPACE-DE-NOMMAGE` — et « 190 » est
 *    un numéro que deux sociétés portent sans se connaître.
 */
function crgi_decider_occupation(PDO $pdo, int $importId, int $occId, string $groupe,
                                 string $choix, int $userId): void
{
    if ($choix !== '' && !isset(CRGI_CHOIX_OCCUPATION[$choix])) {
        throw new RuntimeException(
            'CHOIX INCONNU POUR CETTE QUESTION : « ' . $choix . ' ». Les réponses possibles '
            . 'sont : ' . implode(' · ', array_keys(CRGI_CHOIX_OCCUPATION))
        );
    }
    $ctx = $pdo->prepare('SELECT o.lot_reference, c.compte,
                                 COALESCE(a.nom_agence, c.agence, "") agence
                            FROM crgi_occupation o
                            JOIN crgi_crg c ON c.id = o.crg_id
                            LEFT JOIN agences a ON a.id = c.agence_id
                           WHERE o.id = ?');
    $ctx->execute([$occId]);
    $ou = $ctx->fetch(PDO::FETCH_ASSOC) ?: null;
    $cle = $ou ? (string)$ou['compte'] . '/' . (string)$ou['lot_reference'] : '';

    if ($choix === '') {
        $pdo->prepare('DELETE FROM crgi_arbitrage
                        WHERE import_id = ? AND cible_type = "OCCUPATION" AND cible_id = ?')
            ->execute([$importId, $occId]);
        if ($cle !== '/') {
            $pdo->prepare('DELETE FROM crgi_identite WHERE type = ? AND agence = ? AND cle = ?')
                ->execute([CRGI_IDENTITE_LOT_HORS_GESTION, (string)$ou['agence'], $cle]);
        }
        return;
    }
    $pdo->prepare(
        'INSERT INTO crgi_arbitrage (import_id, groupe, cible_type, cible_id, choix, decide_par)
         VALUES (?,?,"OCCUPATION",?,?,?)
         ON DUPLICATE KEY UPDATE groupe = VALUES(groupe), choix = VALUES(choix),
                                 decide_par = VALUES(decide_par)'
    )->execute([$importId, $groupe, $occId, $choix, $userId ?: null]);

    // ⚠️ ON NE MÉMORISE QUE CE QUI DÉCRIT LE LOT. Mémoriser « vacant » ferait taire, au
    //    trimestre suivant, un document qui imprime un locataire.
    if ($cle !== '/' && in_array($choix, CRGI_OCCUPATION_DEFINITIF, true)) {
        $pdo->prepare(
            'INSERT INTO crgi_identite (type, agence, cle, choix, import_origine, decide_par)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE choix = VALUES(choix), decide_par = VALUES(decide_par),
                                     decide_le = NOW()'
        )->execute([CRGI_IDENTITE_LOT_HORS_GESTION, (string)$ou['agence'], $cle,
                    $choix, $importId, $userId ?: null]);
    }
}

/**
 * LES OCCUPATIONS À TRANCHER, AVEC CE QUI REND UNE RÉPONSE PLUS PROBABLE QU'UNE AUTRE.
 *
 * ⚠️ UNE PROPOSITION N'EST PAS UNE CONCLUSION : c'est le fait observé, dit à voix haute, pour
 *    que la réponse se donne d'un coup d'œil ou se refuse aussi vite. On ne propose donc jamais
 *    sans écrire le « parce que » à côté.
 *
 * ⚠️ LE FAIT QUI TRANCHE EST : « LES AUTRES LOTS DU MÊME COMPTE CONTINUENT-ILS ? » Si le compte
 *    entier s'arrête à la même date, ce n'est pas le locataire qui part — c'est le MANDAT qui
 *    finit. Si le compte continue sans ce lot, alors c'est ce LOT-LÀ qui sort : vendu.
 */
/* ═══════════════════════════════════════════════════════════════════════════════════════════
 * CE QUI N'APPELLE PLUS RIEN — ET LA QUESTION POSÉE À L'ÉCHELLE OÙ LA RÉPONSE SE DONNE.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ UN LOT QUI NE PORTE PLUS QU'UN SOLDE N'EST PLUS EN GESTION. Le compte rendu continue de
 *    l'imprimer tant que le montant n'est pas apuré : ligne « Solde Antérieur », aucun appel de
 *    loyer, un chiffre qui ne bouge plus. Mesuré : **99 lots sur 332** au dernier arrêté d'un
 *    dépôt — ce qui expliquait l'écart entre les 332 lots lus et les ~220 attendus. Il en
 *    appelle 233 ; les 99 autres sont de l'histoire portée.
 *
 * ⚠️ ON NE VEND PAS UN LOT — ON VEND UN IMMEUBLE, OU ON PERD UN MANDAT. Posée au lot, la
 *    question sortait 99 fois. Emmanuel, 07/09/2026, en deux phrases : « la SCI FAVRE est
 *    vendue intégralement, Oyonnax aussi ». Une réponse, six lots ; une autre, trois lots.
 *    Regroupée par périmètre : **8 mandants** entièrement muets, **31 immeubles** couvrant
 *    64 lots, **35 lots** isolés. `UNE FILE TROP LONGUE NE SIGNALE PAS UN CORPUS DIFFICILE,
 *    ELLE SIGNALE QU'ON INTERROGE AU MAUVAIS NIVEAU.`
 *
 * ⚠️ ET LE DOCUMENT NE DIT JAMAIS « VENDU ». Il dit « plus rien n'est appelé ». Un lot vendu et
 *    un lot en contentieux s'impriment à l'identique — RONAX GIE porte 9 017,12 € d'impayés,
 *    ça peut être l'un ou l'autre. C'est un arbitrage, jamais une déduction.
 */
/* ═════════════════════════════════════════════════════════════════════════════════════════
 * LE TYPE DE BIEN — LE LIBELLÉ BRUT RESTE, LA CATÉGORIE S'AJOUTE.
 * ═════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ « LOCAL » N'EST PAS UN SILENCE, C'EST UN COMMERCE. Emmanuel, 08/09/2026 : « local =
 *    commerce ». J'allais faire arbitrer comme indéterminés des lots que le métier lit sans
 *    hésiter. `UN MOT DU MÉTIER N'EST PAS UN MOT INCOMPLET` — confondre « pas écrit » et
 *    « écrit brièvement » fabrique des questions. Seul le SILENCE vaut `HABITATION`.
 *
 * ⚠️ L'ORDRE DES RÈGLES EST LE RÉFÉRENTIEL. « Local commercial 1 Pièce » contient à la fois
 *    « local » et « pièce » : testé dans le mauvais ordre, un commerce devient un appartement.
 *    Le commerce est donc cherché AVANT l'habitation, toujours.
 *
 * ⚠️ ET LE DOCUMENT ÉCRIT PARFOIS « VENDU » À LA PLACE DU TYPE. Ce n'est pas une nature de
 *    bien, c'est un ÉTAT — et il est LU, pas déduit : ces lots-là n'ont pas à passer par la
 *    file d'arbitrage « vendu ou gestion terminée ? », la réponse est imprimée.
 */
const CRGI_TYPES_BIEN = [
    // Le commerce D'ABORD — voir l'avertissement sur l'ordre.
    'LOCAL COMMERCIAL' => ['LOCAL', 'COMMERC', 'BOUTIQUE', 'MAGASIN', 'ENTREPOT', 'RESERVE'],
    'BUREAU'           => ['BUREAU'],
    'MAISON'           => ['MAISON', 'VILLA', 'PAVILLON'],
    'GARAGE'           => ['GARAGE', 'PARKING', 'BOX', 'CAVE', 'CELLIER', 'REMISE'],
    'TERRAIN'          => ['TERRAIN', 'JARDIN'],
    'PANNEAU'          => ['PANNEAU', 'PUBLICIT', 'ANTENNE'],
    'PARTIES COMMUNES' => ['COMMUN'],
    'APPARTEMENT'      => ['APPART', 'STUDIO', 'PIECE', 'DUPLEX', 'LOFT', 'T1', 'T2', 'T3',
                           'T4', 'T5', 'T6', 'F1', 'F2', 'F3', 'F4', 'F5'],
];

/**
 * LA CATÉGORIE D'UN LOT, ET CE QUE SON LIBELLÉ RÉVÈLE D'AUTRE.
 *
 * ⚠️ LE LIBELLÉ BRUT N'EST JAMAIS REMPLACÉ. On ajoute une catégorie à côté : le jour où la
 *    normalisation se trompe, ce que le document dit est encore là pour le prouver.
 *
 * @return array{type:string, vendu:bool, libelle:string}
 */
function crgi_type_de_bien(?string $libelle): array
{
    // ⚠️ LE LECTEUR DOUBLE PARFOIS LE LIBELLÉ — « Local commercial Local commercial »,
    //    « Garage Garage », « Terrain Terrain ». On dédoublonne pour la lecture, sans toucher
    //    à ce qui est conservé en base.
    $brut = trim((string)$libelle);
    $mots = preg_split('/\s+/u', $brut) ?: [];
    $n = count($mots);
    if ($n >= 2 && $n % 2 === 0) {
        $moitie = (int)($n / 2);
        if (implode(' ', array_slice($mots, 0, $moitie))
            === implode(' ', array_slice($mots, $moitie))) {
            $brut = implode(' ', array_slice($mots, 0, $moitie));
        }
    }
    // ⚠️  REND DES MAJUSCULES — les repères du référentiel sont donc comparés en
    //    majuscules. Écrits en minuscules, ils ne trouvaient RIEN : les 1 144 lots sortaient
    //    « indéterminé », et le référentiel avait l air de fonctionner puisqu il ne plantait pas.
    $plat = crgi_plat($brut);

    // ⚠️ « VENDU » EST UN ÉTAT, PAS UNE NATURE — et il est LU.
    $vendu = $plat !== '' && str_contains($plat, 'VENDU');

    $type = 'HABITATION';           // le SILENCE, et lui seul
    if ($plat !== '' && !$vendu) {
        $type = 'INDETERMINE';
        foreach (CRGI_TYPES_BIEN as $categorie => $indices) {
            foreach ($indices as $mot) {
                if (str_contains($plat, $mot)) {
                    $type = $categorie;
                    break 2;
                }
            }
        }
    }
    return ['type' => $type, 'vendu' => $vendu, 'libelle' => $brut];
}

const CRGI_IDENTITE_PERIMETRE = 'PERIMETRE-SANS-APPEL';

/**
 * ⚠️ DEUX RÉPONSES, ET DEUX SEULEMENT. Emmanuel, 08/09/2026 : « si le lot disparaît et que le
 *    dernier nom n'a pas de loyer, alors c'est soit un lot vendu soit un vacant, et je dois
 *    UNIQUEMENT arbitrer entre vendu et vacant ».
 *
 * ⚠️ `GESTION TERMINEE` ET `CONTENTIEUX` ONT DISPARU DE LA LISTE, ET C'EST VOULU. Le
 *    contentieux se lit désormais comme ce qu'il est — un PARTI DÉBITEUR, qualifié par le
 *    moteur, plus une question. Et la fin de gestion ne se distingue pas d'une vente sur le
 *    document : l'offrir revenait à demander de deviner. **Une file d'arbitrage ne doit offrir
 *    que les réponses que celui qui répond peut réellement départager.**
 */
const CRGI_CHOIX_SANS_APPEL = [
    'VENDU'  => 'Le propriétaire ne le possède plus.',
    'VACANT' => 'Il le possède encore ; personne ne l’occupe.',
];

/** Les trois échelles, de la plus large à la plus fine. L'ordre est celui de la lecture. */
const CRGI_NIVEAUX_PERIMETRE = ['MANDANT', 'IMMEUBLE', 'LOT'];

/**
 * LES PÉRIMÈTRES QUI N'APPELLENT PLUS RIEN, du mandat entier au lot isolé.
 *
 * ⚠️ CHAQUE LOT N'APPARAÎT QU'UNE FOIS, AU NIVEAU LE PLUS LARGE QUI LE COUVRE. Un lot d'un
 *    mandat entièrement muet ne se redemande pas au niveau de son immeuble : ce serait poser
 *    trois fois la même question et compter trois fois la même réponse.
 */
function crgi_perimetres_sans_appel(PDO $pdo, int $importId): array
{
    $i = (int)$importId;
    // Le dernier arrêté de CHAQUE lot, et ce que son bloc appelle à cette date.
    $sql = 'SELECT c.compte, o.code_immeuble AS imm, o.lot_reference AS lot,
                   COALESCE(a.nom_agence, c.agence, "") AS agence,
                   MIN(c.proprietaire) AS proprietaire,
                   MAX(o.appels) AS appelle, MAX(o.honoraires) AS honoraires,
                   MIN(c.format) AS format,
                   MAX(o.bail_au) AS bail_au, MAX(o.date_arrete) AS arrete,
                   MAX(CASE WHEN o.solde_source = "LUE" THEN o.solde END) AS solde,
                   MIN(o.locataire) AS locataire, MIN(o.page) AS page, MIN(c.id) AS crg_id
              FROM crgi_occupation o
              JOIN crgi_crg c ON c.id = o.crg_id
              LEFT JOIN agences a ON a.id = c.agence_id
             WHERE c.import_id = ' . $i . ' AND o.date_arrete = (
                   SELECT MAX(o2.date_arrete) FROM crgi_occupation o2
                    JOIN crgi_crg c2 ON c2.id = o2.crg_id
                   WHERE c2.import_id = ' . $i . ' AND c2.compte = c.compte
                     AND o2.lot_reference = o.lot_reference)
             GROUP BY c.compte, o.code_immeuble, o.lot_reference';
    $lots = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // ⚠️ UN CODE D'IMMEUBLE NE SE RECONNAÎT PAS, UNE ADRESSE SI. « Immeuble 01040247 » ne dit
    //    rien à personne ; « RUE PAUL CHEVRET — 01100 OYONNAX » se reconnaît d'un coup d'œil.
    //    Emmanuel, 08/09/2026 : « il faut indiquer l'immeuble ! ». Faire trancher sur un code
    //    oblige à aller chercher à quoi il correspond — exactement ce que la file doit éviter.
    $imm = [];
    foreach ($pdo->query(
        'SELECT o.code, MIN(o.nom) AS nom, MIN(o.code_postal) AS cp, MIN(o.ville) AS ville
           FROM crgi_immeuble o WHERE o.import_id = ' . $i . ' AND o.code IS NOT NULL
          GROUP BY o.code')->fetchAll(PDO::FETCH_ASSOC) as $x) {
        $imm[(string)$x['code']] = trim((string)$x['nom'])
            . (($x['cp'] || $x['ville']) ? ' — ' . trim(($x['cp'] ?? '') . ' ' . ($x['ville'] ?? '')) : '');
    }

    // ⚠️ UN ENCOURS QUI BAISSE EST UN ENCAISSEMENT, MÊME SANS LOYER APPELÉ. Les règlements
    //    s'imputent sur les loyers LES PLUS ANCIENS — pour éviter la forclusion, qui éteindrait
    //    les créances les plus vieilles. Emmanuel, 08/09/2026 : « nous avons bien des
    //    encaissements même s'ils sont imputés sur les anciens loyers ». Conséquence : un lot
    //    qui n'appelle plus rien PEUT tout de même encaisser, et son occupant n'est ni parti
    //    ni inactif — il rembourse. Le silence des appels ne dit rien des règlements.
    $var = $pdo->query(
        'SELECT c.compte AS cpt, o.lot_reference AS lot,
                SUBSTRING_INDEX(GROUP_CONCAT(o.solde ORDER BY o.date_arrete),  ",", 1) AS premier,
                SUBSTRING_INDEX(GROUP_CONCAT(o.solde ORDER BY o.date_arrete), ",", -1) AS dernier
           FROM crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
          WHERE c.import_id = ' . $i . ' AND o.solde_source = "LUE"
          GROUP BY c.compte, o.lot_reference'
    )->fetchAll(PDO::FETCH_ASSOC);
    $variation = [];
    foreach ($var as $v) {
        $variation[$v['cpt'] . '/' . $v['lot']] = (float)$v['dernier'] - (float)$v['premier'];
    }

    // Qui appelle encore, par mandant et par immeuble : c'est ce qui décide de l'échelle.
    $appelleMandant = $appelleImmeuble = [];
    foreach ($lots as $l) {
        $cm = (string)$l['compte'];
        $ci = $cm . '/' . (string)$l['imm'];
        $appelleMandant[$cm] = ($appelleMandant[$cm] ?? 0) + (int)$l['appelle'];
        $appelleImmeuble[$ci] = ($appelleImmeuble[$ci] ?? 0) + (int)$l['appelle'];
    }

    $groupes = [];
    foreach ($lots as $l) {
        if ((int)$l['appelle'] > 0) {
            continue;   // il appelle : rien à trancher.
        }
        // ⚠️ UN SILENCE EXPLIQUÉ N'EST PAS UNE QUESTION. Quand le document imprime la fin du
        //    bail — « Locataire: AGOSTINO David …, Bail du 10/05/2023 AU 22/03/2026 » — et
        //    qu'elle est atteinte, on SAIT pourquoi ce lot n'appelle plus : l'occupant est
        //    parti, le lot est vacant. Demander « vendu ou gestion terminée ? » là-dessus,
        //    c'est faire arbitrer ce que la page dit en toutes lettres. Emmanuel, 08/09/2026 :
        //    « on voit très bien une date de départ dans l'appel de loyer ! ».
        $l['explique'] = !empty($l['bail_au'])
                      && (string)$l['bail_au'] <= (string)$l['arrete'];
        $cm = (string)$l['compte'];
        $ci = $cm . '/' . (string)$l['imm'];
        if (($appelleMandant[$cm] ?? 0) === 0) {
            $niveau = 'MANDANT';
            $cle = $cm;
            $quoi = (string)$l['proprietaire'];
        } elseif (($appelleImmeuble[$ci] ?? 0) === 0) {
            $niveau = 'IMMEUBLE';
            $cle = $ci;
            $quoi = $imm[(string)$l['imm']] ?? ('Immeuble ' . (string)$l['imm']);
        } else {
            $niveau = 'LOT';
            $cle = $cm . '/' . (string)$l['lot'];
            $quoi = 'Lot ' . (string)$l['lot']
                  . (isset($imm[(string)$l['imm']]) ? ' — ' . $imm[(string)$l['imm']] : '');
        }
        $k = $niveau . '|' . $cle;
        if (!isset($groupes[$k])) {
            $groupes[$k] = ['niveau' => $niveau, 'cle' => $cle, 'quoi' => $quoi,
                            'agence' => (string)$l['agence'], 'compte' => $cm,
                            'proprietaire' => (string)$l['proprietaire'], 'lots' => 0,
                            'expliques' => 0, 'fin_bail' => null, 'honoraires' => 0,
                            'format' => (string)$l['format'], 'faits' => [],
                            'solde' => 0.0, 'exemples' => [], 'crg_id' => (int)$l['crg_id'],
                            'page' => (int)$l['page']];
        }
        $groupes[$k]['lots']++;
        $groupes[$k]['honoraires'] += (int)$l['honoraires'];
        $groupes[$k]['variation'] = ($groupes[$k]['variation'] ?? 0.0)
                                  + (float)($variation[$cm . '/' . (string)$l['lot']] ?? 0.0);
        // ⚠️ CE QUI SERVIRA À DÉCIDER, GARDÉ LIGNE PAR LIGNE. Voir `crgi_analyse_perimetre()`.
        $groupes[$k]['faits'][] = [
            'lot'        => (string)$l['lot'],
            'immeuble'   => $imm[(string)$l['imm']] ?? (string)$l['imm'],
            'locataire'  => $l['locataire'] !== null ? (string)$l['locataire'] : null,
            'bail_au'    => $l['bail_au'] ? (string)$l['bail_au'] : null,
            'arrete'     => (string)$l['arrete'],
            'solde'      => $l['solde'] !== null ? (float)$l['solde'] : null,
            'explique'   => (bool)$l['explique'],
            'variation'  => $variation[$cm . '/' . (string)$l['lot']] ?? null,
        ];
        if ($l['explique']) {
            $groupes[$k]['expliques']++;
            $groupes[$k]['fin_bail'] = max((string)$groupes[$k]['fin_bail'],
                                           (string)$l['bail_au']);
        }
        $groupes[$k]['solde'] += (float)($l['solde'] ?? 0);
        if (count($groupes[$k]['exemples']) < 4 && $l['locataire'] !== null) {
            $groupes[$k]['exemples'][] = (string)$l['locataire'];
        }
    }

    // ⚠️ ON RETIRE DE LA FILE CE QUE LE DOCUMENT EXPLIQUE ENTIÈREMENT. Un périmètre dont TOUS
    //    les lots portent une fin de bail imprimée et atteinte n'a rien d'indéterminé : il est
    //    vacant depuis une date lue. Le laisser dans la file ferait passer une lecture pour
    //    une lacune — et userait la file avec des questions dont la réponse est sur la page.
    $groupes = array_filter($groupes, fn($g) => $g['expliques'] < $g['lots']);

    // Ce qui a déjà été tranché, ici ou dans un dépôt précédent.
    $deja = $pdo->prepare('SELECT choix, precision_h FROM crgi_identite
                            WHERE type = ? AND agence = ? AND cle = ?');
    foreach ($groupes as &$g) {
        $deja->execute([CRGI_IDENTITE_PERIMETRE, $g['agence'], $g['niveau'] . '|' . $g['cle']]);
        $vu = $deja->fetch(PDO::FETCH_ASSOC) ?: [];
        $g['decision'] = (string)($vu['choix'] ?? '');
        $g['commentaire'] = (string)($vu['precision_h'] ?? '');

        // ⚠️ LA PROPOSITION SE PREND SUR L'ÉCHELLE, PARCE QUE C'EST LE SEUL FAIT DISPONIBLE.
        //    Le document ne dit jamais « vendu » ; il dit « plus rien n'est appelé ». Mais
        //    l'ÉTENDUE du silence, elle, est lue : un mandat qui s'éteint en entier n'a pas la
        //    même cause qu'un immeuble qui s'éteint pendant que son mandant continue. On dit
        //    donc ce que le fait rend le plus probable, avec le « parce que » à côté — et ça
        //    se refuse d'un coup d'œil, puisque le fait est écrit.
        // ⚠️ LES HONORAIRES DE GESTION TRANCHENT ENTRE UN BIEN VIDE ET UN MANDAT PERDU.
        //    Les deux cessent d'appeler un loyer ; seule la facturation les distingue. Tant
        //    que la régie prélève ses honoraires sur ce compte, le mandat VIT — on gère un
        //    bien vide, on n'a rien perdu. Proposer « gestion terminée » sur un compte qui
        //    paie encore ses honoraires, c'est ignorer une ligne imprimée du document.
        // Les honoraires sortent-ils du lecteur de ce format ?
        // ⚠️ LES HONORAIRES SONT DÉSORMAIS LUS SUR LES DEUX ÉDITEURS. Ils l'étaient déjà chez
        //    l'un ; chez l'autre, le moteur PARCOURAIT ces lignes sans les retenir, et je
        //    rendais « non lisible sur ce format » sur les deux plus gros dépôts — en faisant
        //    trancher un fait imprimé. Un fait qu'on ne retient pas n'est pas un fait
        //    illisible : c'est un fait JETÉ.
        $honoLisibles = true;
        if ((int)$g['honoraires'] > 0) {
            $g['proposition'] = 'VACANT';
            $g['parce_que'] = 'plus aucun loyer n’est appelé, MAIS la régie facture encore ses '
                            . 'HONORAIRES DE GESTION sur ce compte rendu (' . (int)$g['honoraires']
                            . ' ligne(s)) : le mandat n’est pas perdu, le bien est vide.';
        } elseif ($g['niveau'] === 'MANDANT' && !$honoLisibles) {
            // ⚠️ ON NE CONCLUT PAS SUR UN FAIT QU'ON NE LIT PAS. Sur ce format, les honoraires
            //    ne sortent pas du lecteur : leur absence ne prouve rien.
            $g['proposition'] = 'VENDU';
            $g['parce_que'] = 'AUCUN des ' . $g['lots'] . ' lot(s) de ce mandant n’appelle plus '
                            . 'rien : c’est le mandat entier qui s’éteint, pas un bien. '
                            . '⚠️ Les honoraires de gestion — qui diraient si le mandat vit '
                            . 'encore — ne sont pas lisibles sur ce format : à vérifier sur la '
                            . 'page.';
        } elseif ($g['niveau'] === 'MANDANT') {
            $g['proposition'] = 'VENDU';
            $g['parce_que'] = 'AUCUN des ' . $g['lots'] . ' lot(s) de ce mandant n’appelle plus '
                            . 'rien, et le compte rendu ne facture AUCUN honoraire de gestion : '
                            . 'c’est le mandat entier qui s’éteint, pas un bien.';
        } elseif ($g['niveau'] === 'IMMEUBLE') {
            $g['proposition'] = 'VENDU';
            $g['parce_que'] = 'aucun des ' . $g['lots'] . ' lot(s) de cet immeuble n’appelle, '
                            . 'ALORS QUE le mandant continue d’en appeler ailleurs : c’est cet '
                            . 'immeuble-là qui sort.';
        } else {
            $g['proposition'] = 'VENDU';
            $g['parce_que'] = 'ce lot n’appelle plus rien, alors que les autres lots de son '
                            . 'immeuble appellent : c’est ce lot-là qui sort.';
        }
        $g['analyse'] = crgi_analyse_perimetre($g);

        // ⚠️ ET QUAND UNE PARTIE SEULEMENT EST EXPLIQUÉE, ON LE DIT. Le reste garde la
        //    question, mais on ne fait pas semblant d'ignorer ce que la page imprime.
        if ($g['expliques'] > 0) {
            $g['parce_que'] .= ' ⚠️ ' . $g['expliques'] . ' de ces lots portent une FIN DE BAIL '
                             . 'imprimée (au ' . $g['fin_bail'] . ') : leur silence est déjà '
                             . 'expliqué, ils sont vacants depuis cette date.';
        }
    }
    unset($g);

    // Du plus large au plus fin, et du plus lourd au plus léger : on tranche ce qui couvre.
    uasort($groupes, function ($a, $b) {
        $o = array_flip(CRGI_NIVEAUX_PERIMETRE);
        return [$o[$a['niveau']], -$a['lots']] <=> [$o[$b['niveau']], -$b['lots']];
    });
    return array_values($groupes);
}

/**
 * L'ANALYSE FINE D'UN PÉRIMÈTRE — CE QUE LE DOCUMENT DONNE À LIRE POUR DÉCIDER.
 *
 * ⚠️ QUAND LA RÉPONSE N'EST PAS CERTAINE, ON NE POSE PAS LA QUESTION TOUTE NUE. Emmanuel,
 *    08/09/2026 : « si tu n'as pas la réponse certaine, alors tu dois nous donner une analyse
 *    fine du CRG pour nous permettre de décider ». Une question sans les faits oblige à rouvrir
 *    le PDF — et une file qui coûte un aller-retour par ligne ne se traite pas.
 *
 * ⚠️ ON N'ÉCRIT QUE CE QUI EST LU. Pas de déduction, pas de vraisemblance : le dernier
 *    locataire nommé, la fin de bail imprimée, l'encours porté, les honoraires facturés. Ce
 *    sont ces quatre faits qui séparent un bien vide d'un mandat perdu, et ils tiennent en
 *    trois lignes.
 */
function crgi_analyse_perimetre(array $g): array
{
    $faits = $g['faits'] ?? [];
    $lignes = [];

    $nommes = array_values(array_filter($faits, fn($f) => $f['locataire'] !== null));
    $lignes[] = count($nommes) . ' des ' . count($faits) . ' lot(s) portent un occupant nommé'
              . ($nommes ? ' — dernier lu : « '
                 . mb_substr((string)$nommes[0]['locataire'], 0, 34) . ' ».' : '.');

    $congés = array_values(array_filter($faits, fn($f) => $f['bail_au'] !== null));
    if ($congés) {
        $d = max(array_column($congés, 'bail_au'));
        $lignes[] = count($congés) . ' bail(s) portent une FIN imprimée, la plus récente au '
                  . $d . ' — arrêté du compte rendu : ' . (string)$faits[0]['arrete'] . '.';
    } else {
        $lignes[] = 'AUCUNE fin de bail n’est imprimée : le document ne dit pas pourquoi le '
                  . 'loyer cesse.';
    }

    $encours = array_sum(array_map(fn($f) => (float)($f['solde'] ?? 0), $faits));
    $lignes[] = abs($encours) > 0.005
        ? 'Encours porté : ' . number_format($encours, 2, ',', ' ') . ' € — c’est ce qui fait '
          . 'rééditer le compte rendu tant qu’il n’est pas apuré.'
        : 'Aucun encours lu : rien ne reste à apurer.';

    // ⚠️ LES RÈGLEMENTS S'IMPUTENT SUR LES LOYERS LES PLUS ANCIENS, POUR ÉVITER LA
    //    FORCLUSION. Un encours qui BAISSE prouve donc des encaissements — même quand plus
    //    aucun loyer n'est appelé. Ne pas le dire ferait passer pour inactif un occupant qui
    //    rembourse, et pour vide un lot dont le locataire paie encore.
    $v = (float)($g['variation'] ?? 0.0);
    if ($v < -0.005) {
        $lignes[] = 'L’encours a BAISSÉ de ' . number_format(abs($v), 2, ',', ' ') . ' € sur la '
                  . 'période lue : il y a donc eu des ENCAISSEMENTS, imputés sur les loyers les '
                  . 'plus anciens (règle anti-forclusion). Le silence des appels ne dit rien des '
                  . 'règlements.';
    } elseif ($v > 0.005) {
        $lignes[] = 'L’encours a AUGMENTÉ de ' . number_format($v, 2, ',', ' ') . ' € sur la '
                  . 'période lue : aucun règlement ne le résorbe.';
    }

    $honoLisibles = true;   // lus sur les deux editeurs desormais
    if (!$honoLisibles) {
        $lignes[] = '⚠️ Honoraires de gestion NON LISIBLES sur ce format — c’est pourtant eux '
                  . 'qui diraient si le mandat vit encore. À vérifier sur la page.';
    } elseif ((int)($g['honoraires'] ?? 0) > 0) {
        $lignes[] = 'La régie facture encore ' . (int)$g['honoraires'] . ' ligne(s) d’HONORAIRES '
                  . 'DE GESTION sur ce compte rendu : le mandat n’est pas perdu.';
    } else {
        $lignes[] = 'AUCUN honoraire de gestion sur ce compte rendu : plus rien n’est facturé à '
                  . 'ce mandant.';
    }
    return $lignes;
}

/**
 * ENREGISTRER UNE DÉCISION DE PÉRIMÈTRE — mandat, immeuble ou lot.
 *
 * ⚠️ ELLE EST DURABLE PAR NATURE. « Vendu » et « gestion terminée » ne se redemandent pas au
 *    trimestre suivant : le bien ne revient pas. « Vacant » et « contentieux » décrivent une
 *    situation qui peut changer — mais à l'échelle d'un immeuble entier, elles sont assez
 *    lourdes pour qu'on les garde et qu'on les corrige plutôt que de les reposer chaque fois.
 */
function crgi_decider_perimetre(PDO $pdo, int $importId, string $agence, string $niveau,
                                string $cle, string $choix, int $userId,
                                string $commentaire = ''): void
{
    if (!in_array($niveau, CRGI_NIVEAUX_PERIMETRE, true)) {
        throw new RuntimeException('NIVEAU INCONNU : « ' . $niveau . ' ».');
    }
    if ($choix !== '' && !isset(CRGI_CHOIX_SANS_APPEL[$choix])) {
        throw new RuntimeException(
            'CHOIX INCONNU : « ' . $choix . ' ». Réponses possibles : '
            . implode(' · ', array_keys(CRGI_CHOIX_SANS_APPEL))
        );
    }
    $k = $niveau . '|' . $cle;
    if ($choix === '') {
        $pdo->prepare('DELETE FROM crgi_identite WHERE type = ? AND agence = ? AND cle = ?')
            ->execute([CRGI_IDENTITE_PERIMETRE, $agence, $k]);
        return;
    }
    // ⚠️ LE COMMENTAIRE EST UNE DONNÉE, PAS UN ORNEMENT. Une ligne de périmètre couvre
    //    plusieurs lots et plusieurs locataires « qui n'ont pas la même histoire » (Emmanuel,
    //    08/09/2026) : le choix ne dit que la nature commune, le reste doit pouvoir s'écrire.
    //    Sans lui, il faudrait éclater la ligne — et l'on retrouverait les 99 questions.
    $pdo->prepare(
        'INSERT INTO crgi_identite (type, agence, cle, choix, precision_h, import_origine,
                                    decide_par)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE choix = VALUES(choix), precision_h = VALUES(precision_h),
                                 decide_par = VALUES(decide_par), decide_le = NOW()'
    )->execute([CRGI_IDENTITE_PERIMETRE, $agence, $k, $choix,
                mb_substr(trim($commentaire), 0, 1000) ?: null, $importId, $userId ?: null]);
}

function crgi_occupations_a_trancher(PDO $pdo, int $importId): array
{
    $st = $pdo->prepare(
        'SELECT o.id, o.lot_reference, o.periode_cle, o.page, o.statut_motif, o.appels,
                o.date_arrete, c.id AS crg_id, c.compte, c.proprietaire,
                COALESCE(a.nom_agence, c.agence, "") AS agence,
                (SELECT COUNT(DISTINCT o2.lot_reference) FROM crgi_occupation o2
                   JOIN crgi_crg c2 ON c2.id = o2.crg_id
                  WHERE c2.import_id = c.import_id AND c2.compte = c.compte
                    AND o2.date_arrete > o.date_arrete) AS lots_apres,
                (SELECT COUNT(*) FROM crgi_occupation o3
                   JOIN crgi_crg c3 ON c3.id = o3.crg_id
                  WHERE c3.import_id = c.import_id AND c3.compte = c.compte
                    AND o3.lot_reference = o.lot_reference
                    AND o3.date_arrete > o.date_arrete) AS ce_lot_apres
           FROM crgi_occupation o
           JOIN crgi_crg c ON c.id = o.crg_id
           LEFT JOIN agences a ON a.id = c.agence_id
          WHERE o.import_id = ? AND o.statut = "A ARBITRER"
          ORDER BY c.compte, o.lot_reference, o.date_arrete'
    );
    $st->execute([$importId]);
    $lignes = $st->fetchAll(PDO::FETCH_ASSOC);

    $deja = $pdo->prepare('SELECT choix FROM crgi_arbitrage
                            WHERE import_id = ? AND cible_type = "OCCUPATION" AND cible_id = ?');
    foreach ($lignes as &$l) {
        $deja->execute([$importId, (int)$l['id']]);
        $l['decision'] = (string)($deja->fetchColumn() ?: '');
        $appris = crgi_identite_apprise($pdo, CRGI_IDENTITE_LOT_HORS_GESTION,
                                        (string)$l['agence'],
                                        (string)$l['compte'] . '/' . (string)$l['lot_reference']);
        $l['apprise'] = $appris ? (string)$appris['choix'] : '';

        if ((int)$l['ce_lot_apres'] > 0) {
            // Le lot revient plus tard : ce n'est ni une vente ni une fin de gestion.
            $l['proposition'] = 'LOGEMENT VACANT';
            $l['parce_que']   = 'ce lot RÉAPPARAÎT plus tard dans le dépôt : il n’est ni vendu '
                              . 'ni sorti de la gestion, il est seulement muet ici.';
        } elseif ((int)$l['lots_apres'] === 0) {
            $l['proposition'] = 'GESTION TERMINEE';
            $l['parce_que']   = 'TOUT le compte s’arrête à cette date — aucun autre lot n’est '
                              . 'lu après : ce n’est pas un locataire qui part, c’est le '
                              . 'mandat qui finit.';
        } elseif ((int)$l['appels'] > 0) {
            $l['proposition'] = 'LOT VENDU';
            $l['parce_que']   = 'le locataire appelait encore son loyer (' . (int)$l['appels']
                              . ' appel(s)) quand ce lot a cessé d’être lu, alors que les '
                              . 'autres lots du compte continuent : c’est CE lot qui sort.';
        } else {
            $l['proposition'] = 'LOGEMENT VACANT';
            $l['parce_que']   = 'aucun appel de loyer sur ce lot, alors que le compte continue '
                              . 'par ailleurs : rien n’est dû, personne n’occupe.';
        }
    }
    unset($l);
    return $lignes;
}

/**
 * Le bilan de la phase 4 : agence → période → compte, avec les catégories financières.
 *
 * ⚠️ LES STOCKS NE SONT JAMAIS SOMMÉS AVEC LES FLUX, NI ENTRE EUX. Pour l'encours, on ne rend
 *    que la DERNIÈRE SITUATION CONNUE de chaque compte — la photographie la plus récente —
 *    jamais l'addition des photographies successives.
 */
function crgi_bilan_phase4(PDO $pdo, int $importId): array
{
    $q = function (string $sql, array $a = []) use ($pdo, $importId) {
        $st = $pdo->prepare($sql);
        $st->execute(array_merge([$importId], $a));
        return $st;
    };
    // Les FLUX : additionnables, et seulement eux.
    $categories = $q(
        'SELECT categorie, COUNT(*) n, SUM(montant) total
           FROM crgi_mouvement WHERE import_id = ? AND additionnable = 1 AND flux = 1
          GROUP BY categorie ORDER BY categorie'
    )->fetchAll(PDO::FETCH_ASSOC);
    // Les STOCKS : la dernière photographie de chaque compte, jamais leur somme.
    $encours = $q(
        'SELECT COALESCE(SUM(m.montant),0) FROM crgi_mouvement m
           JOIN crgi_crg c ON c.id = m.crg_id
           JOIN (SELECT c2.compte cpt, MAX(c2.date_arrete) fin FROM crgi_crg c2
                  WHERE c2.import_id = ? GROUP BY c2.compte) d
             ON d.cpt = c.compte AND d.fin = c.date_arrete
          WHERE m.import_id = ? AND m.categorie = "ENCOURS" AND m.reimpression = 0
            AND m.colonne = "reste_du"',
        [$importId]
    )->fetchColumn();
    $nonSommes = $q(
        'SELECT categorie, COUNT(*) n, SUM(montant) total FROM crgi_mouvement
          WHERE import_id = ? AND (additionnable = 0 OR flux = 0)
          GROUP BY categorie ORDER BY categorie'
    )->fetchAll(PDO::FETCH_ASSOC);
    $parMaille = $q(
        'SELECT categorie, maille, COUNT(*) n FROM crgi_mouvement
          WHERE import_id = ? AND additionnable = 1 GROUP BY categorie, maille'
    )->fetchAll(PDO::FETCH_ASSOC);
    // ⚠️ PAR DATE D'ARRÊTÉ, ET JAMAIS EN UN SEUL TOTAL. Sur ce dépôt, 60 comptes sur 72
    //    portent des CRG dont les périodes SE CHEVAUCHENT — le loyer d'avril est énoncé dans le
    //    relevé d'avril ET dans celui d'avril-mai. Un « total du dépôt » compterait avril deux
    //    fois : le bilan ne le produit nulle part.
    $parArrete = [];
    foreach ($q('SELECT date_arrete, categorie, COUNT(*) n, SUM(montant) t
                   FROM crgi_mouvement WHERE import_id = ? AND additionnable = 1 AND flux = 1
                  GROUP BY date_arrete, categorie ORDER BY date_arrete')->fetchAll(PDO::FETCH_ASSOC)
             as $r) {
        $parArrete[(string)$r['date_arrete']][(string)$r['categorie']] = $r;
    }
    $arbre = $q(
        'SELECT c.agence, c.periode_cle, c.compte, c.proprietaire, m.categorie,
                COUNT(*) n, SUM(m.montant) total, MIN(m.page) page
           FROM crgi_mouvement m JOIN crgi_crg c ON c.id = m.crg_id
          WHERE m.import_id = ? AND m.additionnable = 1
          GROUP BY c.agence, c.periode_cle, c.compte, c.proprietaire, m.categorie
          ORDER BY c.agence, c.periode_cle, c.compte, m.categorie'
    )->fetchAll(PDO::FETCH_ASSOC);
    return [
        'categories'    => $categories,
        'encours'       => (float)$encours,
        'non_sommes'    => $nonSommes,
        'par_maille'    => $parMaille,
        'arbre'         => $arbre,
        'par_arrete'    => $parArrete,
        'mouvements'    => (int)$q('SELECT COUNT(*) FROM crgi_mouvement WHERE import_id = ?')
            ->fetchColumn(),
        'crg'           => (int)$q('SELECT COUNT(DISTINCT crg_id) FROM crgi_mouvement
                                     WHERE import_id = ?')->fetchColumn(),
        'pages'         => (int)$q('SELECT COUNT(DISTINCT page) FROM crgi_mouvement
                                     WHERE import_id = ?')->fetchColumn(),
        'indetermines'  => (int)$q('SELECT COUNT(*) FROM crgi_mouvement
                                     WHERE import_id = ? AND categorie = "INDETERMINABLE"')
            ->fetchColumn(),
        'reimpressions' => (int)$q('SELECT COUNT(*) FROM crgi_mouvement
                                     WHERE import_id = ? AND reimpression = 1')->fetchColumn(),
        'versements_impossibles' => crgi_controle_versements($pdo, $importId),
    ];
}

/**
 * ON NE REVERSE PAS PLUS QU'ON N'A ENCAISSÉ.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ RÈGLE D'EMMANUEL, 07/09/2026 — et elle a trouvé en une passe ce qu'un harnais
 *    entièrement vert ne voyait pas. Une régie encaisse les loyers, prélève ses honoraires et
 *    les charges, puis reverse le solde : le reversement ne peut pas excéder l'encaissement.
 *    Appliquée telle quelle : **quatre périodes en violation**, dont une à **7 392 %** —
 *    304 247 € reversés pour 4 116 € encaissés. Ce chiffre dénonçait un défaut de lecture
 *    vieux de plusieurs heures, que tous les contrôles techniques avaient laissé passer.
 *
 * ⚠️ `UN INVARIANT MÉTIER TROUVE CE QU'AUCUN TEST TECHNIQUE NE CHERCHE.` Les contrôles du
 *    moteur vérifiaient la cohérence interne — populations, empreintes, couverture, plan —
 *    tous verts. Il manquait la question que le métier pose en premier : ces deux nombres
 *    peuvent-ils coexister ? Elle ne demande pas de connaître le code, mais le métier.
 *
 * ⚠️ ALERTE, JAMAIS CORRECTION. Un dépassement a trois causes possibles — une lecture
 *    incomplète, une qualification fausse, ou un reversement qui suit légitimement
 *    l'encaissement d'une période ANTÉRIEURE — et ce sont trois remèdes différents. Le
 *    contrôle nomme, il ne tranche pas.
 *
 * ⚠️ ET IL SE PREND PAR PÉRIODE, JAMAIS SUR LE DÉPÔT. Les périodes d'un dépôt se
 *    chevauchent : un total de dépôt compterait deux fois le même mois et rendrait le ratio
 *    insignifiant — `INTEG-MESURE-02`.
 */
function crgi_controle_versements(PDO $pdo, int $importId): array
{
    $st = $pdo->prepare(
        'SELECT c.periode_cle, c.compte,
                ROUND(SUM(CASE WHEN m.categorie = "ENCAISSEMENT" THEN m.montant END), 2) enc,
                ROUND(SUM(CASE WHEN m.categorie = "VERSEMENT PROPRIETAIRE"
                               THEN m.montant END), 2) vers
           FROM crgi_mouvement m
           JOIN crgi_crg c ON c.id = m.crg_id
          WHERE m.import_id = ? AND m.additionnable = 1 AND m.flux = 1 AND m.reimpression = 0
          GROUP BY c.periode_cle, c.compte
         -- ⚠️ ON RÉPÈTE LES AGRÉGATS DANS `HAVING`. MariaDB refuse d’y référencer l’alias
         --    d’une fonction de groupe — « Reference not supported » — et la passe s’arrête.
         HAVING SUM(CASE WHEN m.categorie = "VERSEMENT PROPRIETAIRE" THEN m.montant END)
                > COALESCE(SUM(CASE WHEN m.categorie = "ENCAISSEMENT" THEN m.montant END), 0)
            AND SUM(CASE WHEN m.categorie = "VERSEMENT PROPRIETAIRE" THEN m.montant END) > 0
          ORDER BY SUM(CASE WHEN m.categorie = "VERSEMENT PROPRIETAIRE" THEN m.montant END)
                 - COALESCE(SUM(CASE WHEN m.categorie = "ENCAISSEMENT" THEN m.montant END), 0)
                 DESC'
    );
    $st->execute([$importId]);
    $lignes = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($lignes as &$l) {
        $l['ecart'] = round((float)$l['vers'] - (float)($l['enc'] ?? 0), 2);
        $l['part']  = $l['enc'] ? round(100 * (float)$l['vers'] / (float)$l['enc'], 1) : null;
    }
    return $lignes;
}

/**
 * L'empreinte du résultat de la phase 4.
 *
 * ⚠️ ELLE COUVRE LE MONTANT, SA NATURE, SA MAILLE ET SA PAGE. Un euro qui changerait de
 *    catégorie — un encaissement requalifié en appel — ne changerait aucun total global mais
 *    changerait tout le sens du résultat : le sceau doit le voir.
 */
function crgi_empreinte_phase4(PDO $pdo, int $importId): string
{
    // ⚠️ SANS `id`, POUR LA MÊME RAISON QU'EN PHASE 3 : la phase 4 réinsère ses mouvements à
    //    chaque lecture, et une empreinte qui dépend de l'`AUTO_INCREMENT` périmerait la phase
    //    sur une relecture pourtant identique.
    $st = $pdo->prepare(
        'SELECT c.compte, COALESCE(m.lot_reference,""), COALESCE(m.locataire,""),
                COALESCE(m.periode_cle,""), COALESCE(m.date_arrete,""), m.page,
                COALESCE(m.section,""), m.libelle, m.colonne, m.montant, m.categorie,
                m.maille, m.flux, m.additionnable, m.reimpression, m.provenance
           FROM crgi_mouvement m JOIN crgi_crg c ON c.id = m.crg_id
          WHERE m.import_id = ?'
    );
    $st->execute([$importId]);
    $l = [];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) {
        $l[] = implode('|', $r);
    }
    // L'ordre de lecture ne doit pas peser : on scelle le CONTENU, trié.
    sort($l, SORT_STRING);
    return hash('sha256', implode("\n", $l));
}

/* ═════════════════════════════════════════════════════════════════════════════════════════
 *  PHASE 5 — BILAN AVANT INTÉGRATION
 *  ═══════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ LA PHASE 5 NE LIT PLUS RIEN. Pas un PDF, pas une page, pas un libellé. Tout ce qu'elle
 *    affirme est DÉRIVÉ des phases 0 à 4, scellées. Si une question de lecture réapparaît
 *    ici, elle appartient à la phase qui l'a produite : on la lui renvoie, on ne l'absorbe
 *    pas — sinon la règle finirait enterrée dans un écran de synthèse.
 *
 * ⚠️ ELLE RÉPOND À UNE SEULE QUESTION : si l'intégration était validée, qu'est-ce qui serait
 *    créé, mis à jour, archivé, laissé inchangé, arbitré ou refusé ?
 *
 * ⚠️ `SUPPRIMER` N'EXISTE PAS DANS SON VOCABULAIRE. `ABSENT DU NOUVEAU CORPUS ≠ SUPPRIMER`,
 *    et `ANCIEN LOCATAIRE ≠ SUPPRIMER`. Le maximum est `ARCHIVER`, et il se démontre.
 *
 * ⚠️ AUCUNE ÉCRITURE MÉTIER. La phase 5 décrit ; elle n'exécute rien.
 */
function crgi_phase5(PDO $pdo, int $importId): array
{
    crgi_verrouiller_import($pdo, $importId, 5);
    $etat4 = crgi_phase_validee($pdo, $importId, 4);
    if (!$etat4['validee'] || $etat4['perimee']) {
        throw new RuntimeException(
            'PHASE 4 NON VALIDÉE — la phase 5 ne s’ouvre pas. Un bilan d’intégration bâti sur '
            . 'des montants non scellés annoncerait des écritures qui peuvent encore changer.'
        );
    }
    crgi_marquer_phase($pdo, $importId, 5, 'EN ANALYSE', null);
    $pdo->prepare('DELETE FROM crgi_plan WHERE import_id = ?')->execute([$importId]);
    // ⚠️ LE RAPPROCHEMENT D'ABORD, LE PLAN ENSUITE. Sans lui, la phase 5 renvoyait 1 330
    //    mouvements « à arbitrer » qui n'étaient pas 1 330 décisions humaines, mais 1 330
    //    confrontations que le moteur n'avait pas faites. On ne délègue pas à un humain le
    //    travail que le logiciel peut démontrer.
    crgi_rapprocher_finances($pdo, $importId);
    crgi_batir_plan($pdo, $importId);
    crgi_marquer_phase($pdo, $importId, 5, 'A VALIDER', null);
    return crgi_bilan_phase5($pdo, $importId)['par_famille'];
}

/** Bâtit le plan, famille par famille, à partir du seul staging scellé. */
function crgi_batir_plan(PDO $pdo, int $importId): void
{
    $rang = 0;
    $ins = $pdo->prepare(
        'INSERT INTO crgi_plan (import_id, famille, action, nombre, maille, motif, source,
                                bloque, rang)
         VALUES (?,?,?,?,?,?,?,?,?)'
    );
    $poser = function (string $famille, string $action, int $n, string $maille, string $motif,
                       string $source, ?string $bloque = null) use ($ins, $importId, &$rang) {
        // Une action à zéro se pose quand même : « rien à créer » est une réponse, et son
        // absence se lirait comme un oubli.
        $ins->execute([$importId, $famille, $action, $n, $maille, mb_substr($motif, 0, 500),
                       $source, $bloque !== null ? mb_substr($bloque, 0, 300) : null, ++$rang]);
    };
    $un = function (string $sql) use ($pdo, $importId) {
        $st = $pdo->prepare($sql);
        $st->execute([$importId]);
        return (int)$st->fetchColumn();
    };

    // ── PROPRIÉTAIRES ─────────────────────────────────────────────────────────────────────
    // ⚠️ UN COMPTE N'EST PAS UN PROPRIÉTAIRE (`TIERS ≠ PROPRIÉTAIRE ≠ COMPTE MANDANT`). Ce que
    //    la phase 2 a qualifié, c'est le rattachement d'un compte inconnu à un propriétaire
    //    que MBI connaît déjà — jamais la création d'une identité.
    $q = [];
    foreach ($pdo->query('SELECT COALESCE(compte_qualification, "-") q,
                                 COUNT(DISTINCT compte) n
                            FROM crgi_crg WHERE import_id = ' . (int)$importId
                       . ' GROUP BY q') as $r) {
        $q[(string)$r['q']] = (int)$r['n'];
    }
    // ⚠️ LA PHASE 2 N'A CONFRONTÉ QUE LES COMPTES INCONNUS. Elle qualifie A/B/C/D les seuls
    //    CRG dont l'inventaire dit « COMPTE INCONNU » — 15 sur 72. Reprendre son compteur
    //    revenait à annoncer « 15 propriétaires à créer » sans avoir jamais regardé les 57
    //    autres, et sans jamais dire combien MBI en porte déjà. La phase 5 confronte donc les
    //    72 noms lus, avec la même clé insensible aux espaces que pour les locataires.
    $cleNom = fn($v) => preg_replace('~[^A-Z0-9]~', '', crgi_plat((string)$v));
    $propMbi = [];
    foreach ($pdo->query('SELECT id, nom FROM proprietaires') as $pr) {
        $propMbi[$cleNom($pr['nom'])][(int)$pr['id']] = 1;
    }
    $st = $pdo->prepare('SELECT DISTINCT proprietaire FROM crgi_crg
                          WHERE import_id = ? AND proprietaire IS NOT NULL AND proprietaire <> ""');
    $st->execute([$importId]);
    $pDeja = $pCreer = $pAmbigu = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $nom) {
        $k = $cleNom($nom);
        if (!isset($propMbi[$k])) {
            $pCreer++;
        } elseif (count($propMbi[$k]) === 1) {
            $pDeja++;
        } else {
            $pAmbigu++;
        }
    }
    $poser('PROPRIETAIRES', 'INCHANGE', $pDeja, 'propriétaire',
        'Propriétaire que MBI porte déjà, retrouvé sur le nom LU au CRG (comparaison '
        . 'insensible aux espaces). Aucune écriture proposée.',
        'phase 0 · noms lus × proprietaires');
    $poser('PROPRIETAIRES', 'CREER', $pCreer, 'propriétaire',
        'Nom lu sur les CRG qu’aucun propriétaire de MBI ne porte : une identité serait créée. '
        . '`OBSERVÉ DANS LE CORPUS ≠ NOUVEAU DANS MBI` — seuls ceux-ci sont réellement absents.',
        'phase 0 · noms lus × proprietaires');
    // ⚠️ UN COMPTE AMBIGU N'EST PAS UN PROPRIÉTAIRE AMBIGU. Les qualifications C et D de la
    //    phase 2 portent sur des COMPTES MANDANTS — « ce compte existe dans MBI sous une autre
    //    écriture », « plusieurs candidats ». Les additionner ici mêlait deux mailles dans une
    //    seule famille : 199 propriétaires lus, 200 verdicts rendus. L'écart d'un seul objet
    //    est passé sous le total général pendant tout le corpus EMERY ; il n'apparaît qu'au
    //    contrôle famille par famille. Chaque qualification est désormais comptée là où son
    //    objet existe — et une seule fois.
    $poser('PROPRIETAIRES', 'A ARBITRER', $pAmbigu, 'propriétaire',
        'Plusieurs propriétaires de MBI portent ce nom. `AUCUN RAPPROCHEMENT APPROXIMATIF NE '
        . 'CRÉE UNE IDENTITÉ`.',
        'phase 0 × proprietaires',
        'Bloque la création ou le rattachement de CE propriétaire. N’empêche aucune autre '
        . 'famille.');
    $poser('PROPRIETAIRES', 'ARCHIVER', 0, 'propriétaire',
        'AUCUN. `ABSENT DU NOUVEAU CORPUS ≠ SUPPRIMER` : un propriétaire que ce dépôt ne '
        . 'mentionne pas n’est pas un propriétaire perdu.', 'doctrine · INTEG-CONFRONT-03');

    // ── COMPTES MANDANTS ──────────────────────────────────────────────────────────────────
    $comptes = $un('SELECT COUNT(DISTINCT compte) FROM crgi_crg WHERE import_id = ?');
    $connus = $un('SELECT COUNT(DISTINCT compte) FROM crgi_crg
                    WHERE import_id = ? AND mbi_trimestre_id IS NOT NULL');
    // Les qualifications C et D de la phase 2 sont des comptes, et c'est ici qu'elles comptent.
    $cptAmbigus = min($comptes - $connus, ($q['C'] ?? 0) + ($q['D'] ?? 0));
    $poser('COMPTES MANDANTS', 'INCHANGE', $connus, 'compte',
        'Comptes que MBI rapproche déjà d’une situation connue.', 'phase 1 · inventaire');
    $poser('COMPTES MANDANTS', 'CREER', $comptes - $connus - $cptAmbigus, 'compte',
        'Comptes lus sur les CRG et qu’aucune situation de MBI ne porte encore.',
        'phase 1 · inventaire');
    $poser('COMPTES MANDANTS', 'A ARBITRER', $cptAmbigus, 'compte',
        'Le compte existe dans MBI sous une autre écriture, ou plusieurs situations y '
        . 'répondent. `AUCUN RAPPROCHEMENT APPROXIMATIF NE CRÉE UNE IDENTITÉ`.',
        'phase 2 · qualification C/D',
        'Bloque le rattachement de CE compte à une situation de MBI. N’empêche ni les '
        . 'occupations ni l’argent, démontrés au lot.');
    $poser('COMPTES MANDANTS', 'ARCHIVER', 0, 'compte',
        'AUCUN. Les situations que MBI connaît et que ce dépôt ne rapporte pas restent '
        . 'intactes : elles ne sont pas supprimées, elles ne sont pas dans ce dépôt.',
        'doctrine · INTEG-CONFRONT-03');

    // ── IMMEUBLES ─────────────────────────────────────────────────────────────────────────
    // ⚠️ PAR OBJET, PAS PAR OCCURRENCE. Le même immeuble est réénoncé à chaque période.
    $i = [];
    foreach ($pdo->query('SELECT statut, COUNT(*) n FROM (
                            SELECT COALESCE(code, CONCAT(nom, "|", code_postal)) k,
                                   MIN(statut) statut
                              FROM crgi_immeuble WHERE import_id = ' . (int)$importId . '
                             GROUP BY k) t GROUP BY statut') as $r) {
        $i[(string)$r['statut']] = (int)$r['n'];
    }
    $poser('IMMEUBLES', 'INCHANGE', $i['IDENTIQUE'] ?? 0, 'immeuble',
        'Immeuble retrouvé dans MBI, identique après normalisation. Rien à écrire.',
        'phase 2 · confrontation');
    $poser('IMMEUBLES', 'CREER', $i['NOUVEAU'] ?? 0, 'immeuble',
        'Immeuble absent de MBI : il serait créé avec les données LUES sur le CRG.',
        'phase 2 · confrontation');
    $poser('IMMEUBLES', 'METTRE A JOUR', $i['MODIFIE'] ?? 0, 'immeuble',
        'Immeuble retrouvé, dont le CRG porte une donnée différente. La mise à jour serait '
        . 'proposée champ par champ, jamais appliquée en bloc.', 'phase 2 · confrontation');
    $poser('IMMEUBLES', 'A ARBITRER', $i['A ARBITRER'] ?? 0, 'immeuble',
        'Plusieurs candidats MBI, ou aucun rapprochement exact. `AUCUN RAPPROCHEMENT '
        . 'APPROXIMATIF NE CRÉE UNE IDENTITÉ`.', 'phase 2 · confrontation',
        'Bloque la création ou la mise à jour de CET immeuble, et le rattachement de ses lots '
        . 'à un immeuble MBI. N’empêche ni les occupations ni l’argent, démontrés au lot.');

    // ── LOTS ──────────────────────────────────────────────────────────────────────────────
    // ⚠️ IDENTITÉ = `compte × référence` (INTEG-IDENT-03).
    $lo = [];
    foreach ($pdo->query('SELECT statut, COUNT(*) n FROM (
                            SELECT CONCAT(c.compte, "§", o.reference) k, MIN(o.statut) statut
                              FROM crgi_lot o JOIN crgi_crg c ON c.id = o.crg_id
                             WHERE o.import_id = ' . (int)$importId . '
                             GROUP BY k) t GROUP BY statut') as $r) {
        $lo[(string)$r['statut']] = (int)$r['n'];
    }
    // ⚠️ LA PHASE 2 NE VOIT QUE 98 LOTS, LES PHASES 3 ET 4 EN DÉMONTRENT 124. Son extracteur
    //    est antérieur aux corrections de la phase 3 : il ne recolle pas les blocs « Suite » et
    //    ignorait les références courtes. Reprendre son compteur revenait à annoncer un
    //    patrimoine amputé de 26 lots que le document imprime pourtant. On bâtit donc la
    //    famille sur les 124 identités démontrées, et on dit explicitement lesquelles la
    //    phase 2 n'a jamais confrontées à MBI plutôt que de les passer sous silence.
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM (SELECT DISTINCT c.compte, o.lot_reference
                                 FROM crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
                                WHERE o.import_id = ?) t'
    );
    $st->execute([$importId]);
    $lotsDemontres = (int)$st->fetchColumn();
    $lotsPhase2 = (int)(($lo['IDENTIQUE'] ?? 0) + ($lo['NOUVEAU'] ?? 0));
    $poser('LOTS', 'INCHANGE', $lo['IDENTIQUE'] ?? 0, 'lot',
        'Lot retrouvé dans MBI sous la même identité `compte × référence`.',
        'phase 2 · confrontation');
    $poser('LOTS', 'CREER', $lo['NOUVEAU'] ?? 0, 'lot',
        'Lot que MBI ne porte pas encore. `UNE RÉFÉRENCE LOCALE N’EST JAMAIS UNE IDENTITÉ '
        . 'GLOBALE` : il serait créé sous `compte × référence`.',
        'phase 2 · confrontation · INTEG-IDENT-03');
    // ⚠️ LE MOTIF DOIT DIRE CE QUE LE NOMBRE DIT. Il annonçait « DÉMONTRENT (124) … n'a jamais
    //    vus (124) » alors que le second nombre était ce que la phase 2 AVAIT vu : sur un
    //    écart nul, l'écran affichait « 0 lot » sous une phrase qui en accusait 124. Une page
    //    qui se contredit elle-même ne se relit pas, elle se croit sur parole.
    $nonConfrontes = max(0, $lotsDemontres - $lotsPhase2);
    $poser('LOTS', 'A ARBITRER', $nonConfrontes, 'lot',
        $nonConfrontes === 0
            ? 'AUCUN. Les phases 3 et 4 démontrent ' . $lotsDemontres . ' identités de lot, et '
              . 'la confrontation de la phase 2 les a toutes vues (' . $lotsPhase2 . ') : rien '
              . 'n’échappe au rapprochement.'
            : 'Lots que les phases 3 et 4 DÉMONTRENT (' . $lotsDemontres . ' identités) et que '
              . 'la confrontation de la phase 2 n’a pas vus — elle n’en a confronté que '
              . $lotsPhase2 . ' : son extracteur est antérieur aux corrections « Suite » et aux '
              . 'références courtes. Ils ne sont ni inchangés ni nouveaux — ils ne sont PAS '
              . 'CONFRONTÉS.',
        'phase 3/4 × phase 2',
        $nonConfrontes === 0
            ? null
            : 'Bloque la décision sur ces lots. Exige de rejouer la phase 2, donc de la '
              . 'revalider.');
    $poser('LOTS', 'ARCHIVER', 0, 'lot',
        'AUCUN. Un lot que ce dépôt ne mentionne pas n’est pas un lot vendu.',
        'doctrine · INTEG-CONFRONT-03');

    // ── LOCATAIRES ────────────────────────────────────────────────────────────────────────
    // ⚠️ `OBSERVÉ DANS LE CORPUS ≠ NOUVEAU DANS MBI.` Les 119 occupants de la phase 3 étaient
    //    tous annoncés « à créer » : c'était le nombre d'observés, pas le nombre d'absents.
    //    Écrire cela aurait fabriqué des doublons de locataires que MBI porte déjà.
    //
    // ⚠️ ET LA COMPARAISON DES NOMS DOIT IGNORER LES ESPACES. MBI enregistre les occupants de
    //    ce périmètre SOUDÉS — « ALOUILotfi », « BERRUYERThierry » : l'OCR de l'import
    //    historique a collé les mots (`INTEG-LIRE-03`, cette fois du côté de MBI). Comparer
    //    « ALOUI LOTFI » à « ALOUILOTFI » ne rapprochait plus qu'UN locataire sur 119.
    $cleNom = fn($v) => preg_replace('~[^A-Z0-9]~', '', crgi_plat((string)$v));
    $connusMbi = [];
    foreach ($pdo->query('SELECT DISTINCT locataire_nom FROM crg_situations_locataires
                           WHERE locataire_nom IS NOT NULL AND locataire_nom <> ""') as $r) {
        $connusMbi[$cleNom($r['locataire_nom'])][crgi_plat((string)$r['locataire_nom'])] = 1;
    }
    $st = $pdo->prepare('SELECT DISTINCT locataire FROM crgi_occupation
                          WHERE import_id = ? AND locataire IS NOT NULL');
    $st->execute([$importId]);
    $dejaLa = $aCreer = $ambigus = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $nom) {
        $k = $cleNom($nom);
        if (!isset($connusMbi[$k])) {
            $aCreer++;
        } elseif (count($connusMbi[$k]) === 1) {
            $dejaLa++;
        } else {
            $ambigus++;
        }
    }
    $poser('LOCATAIRES', 'INCHANGE', $dejaLa, 'locataire',
        'Occupant que MBI porte déjà, retrouvé par comparaison de nom INSENSIBLE AUX ESPACES — '
        . 'MBI enregistre ces noms soudés. Aucune écriture proposée.',
        'phase 3 × crg_situations_locataires');
    $poser('LOCATAIRES', 'CREER', $aCreer, 'locataire',
        'Occupant qu’aucun nom de MBI ne porte, même en ignorant les espaces : il serait créé. '
        . '`OBSERVÉ DANS LE CORPUS ≠ NOUVEAU DANS MBI` — seuls ceux-ci sont réellement absents.',
        'phase 3 × crg_situations_locataires');
    $poser('LOCATAIRES', 'A ARBITRER', $ambigus, 'locataire',
        'Le nom du CRG correspond à PLUSIEURS orthographes distinctes dans MBI : un '
        . 'rapprochement approximatif ne crée jamais une identité.',
        'phase 3 × crg_situations_locataires',
        'Bloque le rattachement de CE locataire. N’empêche aucune autre écriture.');
    $poser('LOCATAIRES', 'ARCHIVER', 0, 'locataire',
        'AUCUN. `ANCIEN LOCATAIRE ≠ SUPPRIMER` : un ancien occupant reste, avec sa période et '
        . 'sa dette (`P6A-CREANCE-07`).', 'doctrine · phase 3');

    // ── OCCUPATIONS ───────────────────────────────────────────────────────────────────────
    $o = [];
    foreach ($pdo->query('SELECT statut, COUNT(*) n FROM crgi_occupation
                           WHERE import_id = ' . (int)$importId . ' GROUP BY statut') as $r) {
        $o[(string)$r['statut']] = (int)$r['n'];
    }
    // ⚠️ DANS UN PLAN DE MUTATION, `INCHANGÉ` VEUT DIRE « AUCUNE ÉCRITURE PROPOSÉE », et rien
    //    d'autre. Il ne prétend pas que MBI porte déjà l'objet : ici, il dit que le corpus ne
    //    démontre AUCUN mouvement à écrire. La nuance compte — c'est elle qui distingue les
    //    452 occupations sans mouvement des locataires, où `CRÉER` affirmait une écriture et
    //    devait donc être confronté à MBI.
    $poser('OCCUPATIONS', 'INCHANGE', ($o['IDENTIQUE'] ?? 0), 'observation',
        'Même titulaire qu’à la période précédente : la suite ne démontre aucun mouvement, donc '
        . 'AUCUNE ÉCRITURE N’EST PROPOSÉE. Cela ne dit pas que MBI porte déjà cette observation '
        . '— c’est une autre question, et aucune phase scellée ne la tranche.',
        'phase 3 · chronologie');
    // ⚠️ UNE OBSERVATION, UN VERDICT — ET UN SEUL. La succession était comptée DEUX FOIS : en
    //    CRÉER pour l'occupation entrante et en ARCHIVER pour la sortante, alors que ces deux
    //    actions portent sur des OBSERVATIONS DIFFÉRENTES. Le plan annonçait 483 verdicts pour
    //    478 observations : cinq de trop, invisibles dans le total général.
    $poser('OCCUPATIONS', 'CREER', ($o['CHANGEMENT DE LOCATAIRE'] ?? 0)
        + ($o['NOUVEL ENTRANT'] ?? 0), 'occupation',
        'Succession locative DÉMONTRÉE : le lot est réénoncé avec un autre occupant. Cette '
        . 'observation est celle de l’ENTRANT — une occupation serait ouverte.',
        'phase 3 · chronologie');
    // ⚠️ ET UN ANCIEN LOCATAIRE AVEC DETTE EST UNE OCCUPATION À CLÔTURER, PAS À LAISSER. Le
    //    marquer « inchangé » laissait croire qu’il n’y avait rien à écrire. Ce qui ne bouge
    //    pas, c’est sa DETTE : elle reste attachée à lui (`P6A-CREANCE-07`). La clôture, elle,
    //    est bien une écriture.
    $poser('OCCUPATIONS', 'ARCHIVER', ($o['PARTI DEMONTRE'] ?? 0)
        + ($o['ANCIEN LOCATAIRE AVEC DETTE'] ?? 0), 'occupation',
        'Observation du SORTANT : son occupation serait CLÔTURÉE à la date démontrée — jamais '
        . 'supprimée. Sa dette éventuelle reste attachée à lui et ne passe jamais au suivant '
        . '(`P6A-CREANCE-07`).', 'phase 3 · chronologie');
    $arb = (int)($o['A ARBITRER'] ?? 0);
    $lotsArb = $un('SELECT COUNT(*) FROM (SELECT DISTINCT c.compte, o.lot_reference
                      FROM crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
                     WHERE o.import_id = ? AND o.statut = "A ARBITRER") t');
    $poser('OCCUPATIONS', 'A ARBITRER', $arb, 'observation',
        'Le document ne démontre ni maintien, ni entrée, ni départ : lot vu à une seule '
        . 'période, ou aucune ligne « Locataire » imprimée, ou lot cessant d’apparaître alors '
        . 'que son compte continue. `ABSENCE ≠ DÉPART DÉMONTRÉ`.', 'phase 3 · chronologie',
        'Bloque l’écriture d’occupation de ' . $lotsArb . ' lots. N’empêche NI la création de '
        . 'ces lots, NI leurs mouvements financiers, qui sont démontrés au lot sans dépendre '
        . 'de l’occupant.');

    // ── LES FAMILLES FINANCIÈRES ──────────────────────────────────────────────────────────
    // ⚠️ CHAQUE FAMILLE GARDE SA NATURE. `APPEL ≠ ENCAISSEMENT ≠ AFFECTATION ≠ SOLDE`, et
    //    `DÉPENSE ≠ APPEL LOCATAIRE` : la provision appelée au locataire n'est pas une charge
    //    du propriétaire, elle a sa propre ligne.
    $familles = [
        ['APPELS', ['LOYER APPELE', 'CHARGE APPELEE AU LOCATAIRE', 'AUTRE APPELE AU LOCATAIRE'],
         'Appels au locataire, lus dans les colonnes « Loyers », « Charges » et « Autres » du '
         . 'tableau du lot. `DÉPENSE ≠ APPEL LOCATAIRE`.'],
        ['ENCAISSEMENTS', ['ENCAISSEMENT'],
         'Colonne « Crédit ». `APPEL ≠ ENCAISSEMENT` : un encaissement peut solder une période '
         . 'ANTÉRIEURE, et aucun écart `appelé − encaissé` n’est calculé.'],
        ['ENCOURS', ['ENCOURS'],
         'Photographies de « Reste dû » et des soldes de lot. `STOCK ≠ FLUX` : jamais '
         . 'additionnées entre deux périodes.'],
        ['CHARGES', ['CHARGE'],
         'Sections « Factures dues », « Charges de syndic », « Charges Propriétaire ».'],
        ['FRAIS ET ASSURANCES', ['FRAIS ET ASSURANCES'],
         'Sections « Honoraires de Gestion », « GLI » et « GU Assurance ».'],
        // ⚠️ AJOUTÉE APRÈS COUP, ET C'EST BIEN LE PROBLÈME. La catégorie était déclarée au
        //    vocabulaire et produite par le moteur ICS, mais aucune famille du plan ne la
        //    reprenait : 340 mouvements — une taxe foncière entière — n'étaient ni intégrés,
        //    ni exclus, ni arbitrés. Ils n'étaient nulle part. Seul le contrôle « le plan rend
        //    compte de TOUS les mouvements » l'a vu, et il a fallu un corpus de 14 370 lignes
        //    pour que l'écart devienne visible. Une famille de plan qui ne suit pas le
        //    vocabulaire déclaré rouvre exactement la faille de `NOUVEAUTÉ ≠ EXCLUSION`.
        ['IMPOTS ET TAXES', ['IMPOTS ET TAXES'],
         'Taxe foncière, taxe d’ordures ménagères et contributions assimilées, refacturées ou '
         . 'supportées. `IMPÔT ≠ CHARGE COURANTE` : leur périodicité et leur redevable ne sont '
         . 'pas les mêmes.'],
        ['FLUX PROPRIETAIRE', ['VERSEMENT PROPRIETAIRE'],
         'Lignes « Virement : … € », écrites en clair hors de toute colonne.'],
        ['SOLDES', ['SOLDE'],
         'Soldes de compte, d’immeuble et d’indivision. `STOCK ≠ FLUX`.'],
    ];
    foreach ($familles as [$nom, $cats, $quoi]) {
        $in = implode(',', array_map(fn($c) => $pdo->quote($c), $cats));
        // ⚠️ `CONTRIBUTIF ≠ NOUVEAU.` Une ligne contributive contribue à la situation
        //    financière démontrée ; elle ne prouve pas que MBI ne la porte pas déjà. Or
        //    **60 situations de ce dépôt sont rapprochées à un trimestre que MBI porte AVEC
        //    ses écritures** (26 711 lignes dans `crg_ecritures`). Les mouvements qui les
        //    concernent ne peuvent pas être annoncés en création : leur confrontation ligne à
        //    ligne n'est démontrée par AUCUNE phase scellée, et la phase 5 n'a pas le droit de
        //    l'inventer. Ils sont donc À ARBITRER, pas CRÉER.
        // ⚠️ LE VERDICT VIENT DU RAPPROCHEMENT, PLUS D'UNE PRÉSOMPTION. `HORS PERIMETRE` =
        //    MBI n'a pas cette situation ; `NOUVEAU` = MBI l'a mais ne porte pas cette ligne.
        //    Les deux sont des créations démontrées. Seuls les cas réellement indécidables
        //    remontent à un humain.
        $f = fn(string $v) => $un("SELECT COUNT(*) FROM crgi_mouvement WHERE import_id = ?
                                    AND categorie IN ({$in}) AND additionnable = 1
                                    AND rapprochement = " . $pdo->quote($v));
        $ok = $f('HORS PERIMETRE') + $f('NOUVEAU');
        $deja = $f('DEJA PRESENT');
        $dejaMbi = $f('CANDIDAT NON DEMONTRABLE') + $f('CONTRADICTION');
        $muet = $f('NON RAPPROCHABLE');
        $ko = $un("SELECT COUNT(*) FROM crgi_mouvement WHERE import_id = ?
                    AND categorie IN ({$in}) AND additionnable = 0");
        $sansLot = $un("SELECT COUNT(*) FROM crgi_mouvement WHERE import_id = ?
                         AND categorie IN ({$in}) AND additionnable = 1 AND maille <> 'LOT'");
        $poser($nom, 'CREER', $ok, 'mouvement',
            $quoi . ' Chaque ligne porte sa page, sa colonne et sa maille : '
            . ($ok - $sansLot) . ' démontrées au LOT, ' . $sansLot . ' au compte ou à '
            . 'l’immeuble. `LA MAILLE D’AFFICHAGE NE PEUT JAMAIS ÊTRE PLUS FINE QUE LA MAILLE '
            . 'DE LA PREUVE` : aucun prorata, aucun rattachement forcé.',
            'phase 4 · rapprochement');
        if ($deja > 0) {
            $poser($nom, 'INCHANGE', $deja, 'mouvement',
                'Écriture que MBI porte DÉJÀ : même situation, même libellé, même lot et même '
                . 'montant au même sens. Aucune écriture ne serait créée — les annoncer en '
                . 'création aurait doublé des écritures existantes.',
                'rapprochement × crg_ecritures');
        }
        if ($dejaMbi > 0) {
            $poser($nom, 'A ARBITRER', $dejaMbi, 'mouvement',
                'Reliquat réellement indécidable après rapprochement : soit plusieurs écritures '
                . 'MBI portent le même libellé et le même montant (`MÊME MONTANT ≠ MÊME '
                . 'ÉCRITURE`), soit MBI porte la même ligne avec un AUTRE montant. Aucun '
                . 'rapprochement n’a été forcé.', 'rapprochement × crg_ecritures',
                'Bloque l’écriture de ces seuls mouvements. N’empêche ni les objets, ni les '
                . 'mouvements démontrés nouveaux.');
        }
        if ($muet > 0) {
            // ⚠️ COMPTÉ, NOMMÉ, JAMAIS POSÉ EN QUESTION. Ces lignes existent et se voient ;
            //    simplement, aucune information au monde ne dit à quelle écriture de MBI elles
            //    correspondent. Les faire remonter en arbitrage gonflait la file de questions
            //    auxquelles personne — pas même le moteur — ne pouvait répondre.
            $poser($nom, 'NON INTEGRABLE', $muet, 'mouvement',
                'Rapprochement impossible pour tout le monde : libellé générique (« Solde ») '
                . 'ou plusieurs écritures MBI strictement indiscernables. `MÊME MONTANT ≠ MÊME '
                . 'ÉCRITURE`. Lignes lues et conservées, jamais écrites — et AUCUNE question '
                . 'posée, faute de réponse possible.', 'rapprochement × crg_ecritures');
        }
        if ($ko > 0) {
            $poser($nom, 'NON INTEGRABLE', $ko, 'mouvement',
                'Lignes conservées et traçables mais jamais sommées : réimpressions d’une page '
                . 'à l’identique (`RÉIMPRESSION ≠ NOUVEL ÉVÉNEMENT`), agrégats du '
                . '« Récapitulatif » (`AGRÉGAT ≠ MOUVEMENT ÉLÉMENTAIRE`) et détails de calcul.',
                'phase 4 · mouvements');
        }
    }
    // ⚠️ LE PLAN DOIT SE REFERMER SUR LES 7 099 MOUVEMENTS. Les agrégats du « Récapitulatif »
    //    et les détails de calcul n'appartiennent à aucune famille d'écriture — mais les
    //    passer sous silence laisserait 628 lignes hors du bilan, et un bilan qui ne totalise
    //    pas son propre matériau ne prouve rien.
    $horsFamille = $un('SELECT COUNT(*) FROM crgi_mouvement WHERE import_id = ?
                         AND categorie IN ("AGREGAT (NON ADDITIONNABLE)",
                                           "DETAIL (NON ADDITIONNABLE)")');
    $poser('AGREGATS ET DETAILS', 'NON INTEGRABLE', $horsFamille, 'mouvement',
        'Lignes du « Récapitulatif des immeubles », qui rejoue par immeuble ce que les blocs '
        . 'ont déjà dit (`AGRÉGAT ≠ MOUVEMENT ÉLÉMENTAIRE`), et détails de calcul — « dont TVA », '
        . 'assiette « base: » d’un honoraire. Lues et conservées pour servir de CONTRÔLE, '
        . 'jamais écrites, jamais additionnées.', 'phase 4 · mouvements');
    $ind = $un('SELECT COUNT(*) FROM crgi_mouvement WHERE import_id = ?
                 AND categorie = "INDETERMINABLE"');
    $poser('APPELS', 'A ARBITRER', $ind, 'mouvement',
        'Montant que le document n’attribue à aucune colonne ni section connue. Il n’est pas '
        . 'rangé dans la colonne d’à côté : il attend votre décision.', 'phase 4 · anomalies',
        'Bloque l’écriture de CE seul montant. N’empêche aucune autre écriture.');
    $poser('APPELS', 'ARCHIVER', 0, 'mouvement',
        'AUCUN. Un mouvement lu n’efface jamais un mouvement déjà enregistré dans MBI.',
        'doctrine');
}

/** Le bilan de la phase 5, tel que l'écran doit le montrer. */
function crgi_bilan_phase5(PDO $pdo, int $importId): array
{
    $st = $pdo->prepare('SELECT * FROM crgi_plan WHERE import_id = ? ORDER BY rang');
    $st->execute([$importId]);
    $lignes = $st->fetchAll(PDO::FETCH_ASSOC);
    $parFamille = $parAction = [];
    foreach ($lignes as $l) {
        $parFamille[(string)$l['famille']][] = $l;
        $parAction[(string)$l['action']] = ($parAction[(string)$l['action']] ?? 0)
                                         + (int)$l['nombre'];
    }
    $st = $pdo->prepare(
        'SELECT c.compte, o.lot_reference, COUNT(*) n, MIN(o.page) page,
                LEFT(MIN(o.statut_motif), 200) motif
           FROM crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
          WHERE o.import_id = ? AND o.statut = "A ARBITRER"
          GROUP BY c.compte, o.lot_reference ORDER BY c.compte, o.lot_reference'
    );
    $st->execute([$importId]);
    // Le plan doit rendre compte de CHAQUE mouvement de la phase 4, sans exception.
    $mv = $pdo->prepare('SELECT COUNT(*) FROM crgi_mouvement WHERE import_id = ?');
    $mv->execute([$importId]);
    $total = (int)$mv->fetchColumn();
    $couvert = 0;
    foreach ($lignes as $l) {
        if ($l['maille'] === 'mouvement') {
            $couvert += (int)$l['nombre'];
        }
    }
    return [
        'lignes'      => $lignes,
        'par_famille' => $parFamille,
        'par_action'  => $parAction,
        'arbitrages'  => $st->fetchAll(PDO::FETCH_ASSOC),
        'mouvements'  => $total,
        'couverts'    => $couvert,
        'boucle'      => $couvert === $total,
    ];
}

/**
 * L'empreinte du résultat de la phase 5.
 *
 * ⚠️ SANS `id`, ET TRIÉE (`INTEG-SCEAU-01`). Elle couvre chaque décision : la famille,
 *    l'action, le dénombrement, le motif et ce que l'action bloque. Un plan qui passerait
 *    « À ARBITRER » à « CRÉER » sans changer aucun total doit périmer le sceau.
 */
function crgi_empreinte_phase5(PDO $pdo, int $importId): string
{
    $st = $pdo->prepare(
        'SELECT famille, action, nombre, maille, motif, source, COALESCE(bloque, "")
           FROM crgi_plan WHERE import_id = ?'
    );
    $st->execute([$importId]);
    $l = [];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) {
        $l[] = implode('|', $r);
    }
    sort($l, SORT_STRING);
    return hash('sha256', implode("\n", $l));
}

/**
 * RAPPROCHEMENT FINANCIER — staging scellé ↔ `crg_ecritures` de MBI.
 *
 * ⚠️ CE N'EST PAS UNE PHASE DE LECTURE. Aucun PDF n'est rouvert, aucune phase certifiée n'est
 *    modifiée. Elle répond à une seule question, mouvement par mouvement : MBI porte-t-il DÉJÀ
 *    cette écriture ?
 *
 * ⚠️ `MÊME MONTANT ≠ MÊME ÉCRITURE`, et `MÊME COMPTE + MÊME MONTANT ≠ MÊME ÉCRITURE`. Le
 *    montant n'intervient qu'en DERNIER, pour départager des candidats déjà retenus sur leur
 *    identité. Il ne désigne jamais un candidat à lui seul.
 *
 * ⚠️ LES DEUX CHAÎNES N'ÉCRIVENT PAS PAREIL, ET C'EST LA DIFFICULTÉ RÉELLE :
 *    — MBI recopie la ligne ENTIÈRE dans `libelle`, montant compris (« EDF 5 PL FUTERIE
 *      8458251472 244,93 »), là où le staging en extrait le montant. Le libellé du staging est
 *      donc comparé comme PRÉFIXE, jamais par égalité.
 *    — MBI écrit le lot COURT (« 01 ») là où le document imprime « 276-01 ». Le lot MBI est
 *      donc accepté comme SUFFIXE du lot du staging — jamais une inclusion quelconque, qui
 *      confondrait « 01 » et « 101 ».
 *
 * ⚠️ ET MBI NE PEUT PAS PORTER LES APPELS. `crg_ecritures` n'a que `debit` et `credit` : les
 *    colonnes `Loyers`, `Charges`, `Autres` et `Reste dû` n'y ont AUCUN équivalent. Les
 *    montants qui en viennent sont nouveaux par construction du modèle, pas par échec de
 *    rapprochement — et le motif le dit, pour qu'on ne le relise pas comme une lacune.
 */
function crgi_rapprocher_finances(PDO $pdo, int $importId): array
{
    $cle = fn($v) => preg_replace('~[^A-Z0-9]~', '', crgi_plat((string)$v));

    // Les écritures de MBI, groupées par situation. On ne les modifie jamais.
    $st = $pdo->prepare(
        'SELECT e.id, e.id_crg, e.numero_lot, e.libelle, e.debit, e.credit
           FROM crg_ecritures e
          WHERE e.id_crg IN (SELECT mbi_trimestre_id FROM crgi_crg
                              WHERE import_id = ? AND mbi_trimestre_id IS NOT NULL)'
    );
    $st->execute([$importId]);
    $parSituation = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $e['_lot'] = $cle($e['numero_lot']);
        $e['_lib'] = $cle($e['libelle']);
        $parSituation[(int)$e['id_crg']][] = $e;
    }

    // Hors périmètre : les situations que MBI ne possède pas ne se rapprochent à rien.
    $pdo->prepare(
        'UPDATE crgi_mouvement m JOIN crgi_crg c ON c.id = m.crg_id
            SET m.rapprochement = "HORS PERIMETRE", m.mbi_ecriture_id = NULL,
                m.rappro_motif = "Situation absente de MBI : il n’y a rien à confronter. Le "
                               "mouvement est proposé en création par la phase 5."
          WHERE m.import_id = ?
            AND NOT EXISTS (SELECT 1 FROM crg_ecritures e WHERE e.id_crg = c.mbi_trimestre_id)'
    )->execute([$importId]);

    $st = $pdo->prepare(
        'SELECT m.id, c.mbi_trimestre_id t, m.lot_reference, m.libelle, m.colonne, m.montant
           FROM crgi_mouvement m JOIN crgi_crg c ON c.id = m.crg_id
          WHERE m.import_id = ? AND m.additionnable = 1
            AND EXISTS (SELECT 1 FROM crg_ecritures e WHERE e.id_crg = c.mbi_trimestre_id)'
    );
    $st->execute([$importId]);
    $maj = $pdo->prepare(
        'UPDATE crgi_mouvement SET rapprochement = ?, mbi_ecriture_id = ?, rappro_motif = ?
          WHERE id = ?'
    );
    // ⚠️ AUCUN MOUVEMENT NE RESTE SANS VERDICT. Les lignes non additionnables des situations
    //    connues de MBI ne se confrontent pas — mais le taire laisserait 104 mouvements muets.
    $pdo->prepare(
        'UPDATE crgi_mouvement SET rapprochement = "HORS PERIMETRE",
                rappro_motif = "Ligne non additionnable (agrégat, détail, réimpression ou "
                             "indéterminable) : elle n’entre dans aucun total et ne se "
                             "confronte donc à aucune écriture."
          WHERE import_id = ? AND additionnable = 0 AND rapprochement IS NULL'
    )->execute([$importId]);
    $bilan = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $lib = $cle($m['libelle']);
        $lot = $cle($m['lot_reference']);
        // 1. le libellé : le staging doit être le libellé MBI ou son préfixe.
        $cands = [];
        foreach ($parSituation[(int)$m['t']] ?? [] as $e) {
            if ($lib !== '' && ($e['_lib'] === $lib || str_starts_with($e['_lib'], $lib))) {
                $cands[] = $e;
            }
        }
        // 2. le lot, quand MBI le renseigne : lot MBI = lot staging, ou son suffixe.
        if ($lot !== '' && $cands) {
            $etroits = array_values(array_filter($cands, fn($e) => $e['_lot'] !== ''
                && ($e['_lot'] === $lot || str_ends_with($lot, $e['_lot']))));
            if ($etroits) {
                $cands = $etroits;
            }
        }
        [$verdict, $ecriture, $motif] = crgi_verdict_rapprochement($m, $cands);
        $maj->execute([$verdict, $ecriture, mb_substr($motif, 0, 400), (int)$m['id']]);
        $bilan[$verdict] = ($bilan[$verdict] ?? 0) + 1;
    }
    return $bilan;
}

/** Le verdict d'un mouvement face à ses candidats MBI. Aucun rapprochement forcé. */
function crgi_verdict_rapprochement(array $m, array $cands): array
{
    // ⚠️ UN LIBELLÉ GÉNÉRIQUE N'EST PAS UNE IDENTITÉ. « Solde » fait cinq caractères et
    //    s'imprime à chaque bloc : s'en servir comme clé faisait pointer six mouvements
    //    différents vers LA MÊME écriture MBI, et produisait de fausses contradictions. En
    //    deçà de huit caractères significatifs, le libellé ne démontre plus rien tout seul.
    $lib = preg_replace('~[^A-Z0-9]~', '', crgi_plat((string)$m['libelle']));
    $appel = !in_array($m['colonne'], ['debit', 'credit'], true);
    if (!$appel && $cands && mb_strlen($lib) < 8) {
        // ⚠️ CE N'EST PAS UN ARBITRAGE : PERSONNE NE PEUT RÉPONDRE. Emmanuel voit exactement
        //    ce que le moteur voit — douze lignes « Solde » identiques dans la même situation.
        //    Lui demander LAQUELLE, c'est lui demander de deviner. Une question sans réponse
        //    possible n'est pas une question, c'est une limite : on la NOMME, on la compte, on
        //    ne l'écrit pas, et on ne la met pas dans la file. Emmanuel, 04/09/2026 : « je ne
        //    veux décider que sur 20 à 30 points au total ».
        return ['NON RAPPROCHABLE', null,
            'Le libellé « ' . $m['libelle'] . ' » est trop générique pour désigner une '
            . 'écriture : ' . count($cands) . ' candidates dans cette situation. Aucune '
            . 'information, ni dans le document ni dans MBI, ne permet de trancher — '
            . 'l’humain n’en a pas plus que le moteur. Ligne lue, conservée, non écrite.'];
    }
    if ($appel) {
        // ⚠️ CE N'EST PAS UN ÉCHEC DE RAPPROCHEMENT, C'EST UNE LIMITE DU MODÈLE DE MBI.
        return ['NOUVEAU', null,
            'Montant lu dans la colonne « ' . $m['colonne'] .' » : `crg_ecritures` n’a que '
            . '`debit` et `credit` et ne peut porter aucun APPEL. Nouveau par construction du '
            . 'modèle, pas par échec de confrontation.'];
    }
    if (!$cands) {
        return ['NOUVEAU', null,
            'Aucune écriture de cette situation ne porte ce libellé. MBI ne possède pas cette '
            . 'ligne : elle serait créée.'];
    }
    $exacts = array_values(array_filter($cands,
        fn($e) => abs((float)$e[$m['colonne']] - (float)$m['montant']) < 0.005));
    if (count($exacts) === 1) {
        return ['DEJA PRESENT', (int)$exacts[0]['id'],
            'Écriture MBI n°' . $exacts[0]['id'] . ' : même situation, même libellé, même lot '
            . 'et même montant au sens « ' . $m['colonne'] . ' ». Aucune écriture à créer.'];
    }
    if (count($exacts) > 1) {
        // ⚠️ PLUSIEURS ÉCRITURES IDENTIQUES : les départager au montant serait exactement le
        //    rapprochement forcé qu'on s'interdit — et un humain n'a rien de plus pour choisir,
        //    puisque les candidates sont indiscernables. Limite nommée, pas question posée.
        return ['NON RAPPROCHABLE', null,
            count($exacts) . ' écritures de MBI portent le même libellé ET le même montant '
            . 'dans cette situation : elles sont indiscernables. Rien ne dit LAQUELLE '
            . 'correspond (`MÊME MONTANT ≠ MÊME ÉCRITURE`), et rien ne le dira. Ligne lue, '
            . 'conservée, non écrite.'];
    }
    if (count($cands) === 1 && (float)$cands[0][$m['colonne']] == 0.0) {
        return ['CANDIDAT NON DEMONTRABLE', (int)$cands[0]['id'],
            'MBI porte la ligne (écriture n°' . $cands[0]['id'] . ') mais avec un montant nul '
            . 'au sens « ' . $m['colonne'] . ' », là où le document imprime '
            . number_format((float)$m['montant'], 2, ',', ' ') . ' €. La ligne existe, le '
            . 'montant n’y est pas : ce n’est ni un doublon, ni une contradiction.'];
    }
    if (count($cands) === 1) {
        return ['CONTRADICTION', (int)$cands[0]['id'],
            'MBI porte la même ligne (écriture n°' . $cands[0]['id'] . ') avec '
            . number_format((float)$cands[0][$m['colonne']], 2, ',', ' ') . ' € là où le '
            . 'document imprime ' . number_format((float)$m['montant'], 2, ',', ' ')
            . ' €. Les deux ne peuvent pas être vrais.'];
    }
    return ['CANDIDAT NON DEMONTRABLE', null,
        count($cands) . ' écritures de MBI portent ce libellé dans cette situation, aucune au '
        . 'montant du document. Le rapprochement demande une décision.'];
}

/* ═════════════════════════════════════════════════════════════════════════════════════════
 *  COUVERTURE ET FRONTIÈRES — le contrôle qui manquait
 *  ═══════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ `EXACTITUDE ≠ EXHAUSTIVITÉ.` La phase 2 était JUSTE sur les 98 lots qu'elle traitait, et
 *    FAUSSE sur la population : le document en imprime 124. Elle a été scellée, validée, et le
 *    bilan d'intégration s'est construit sur son chiffre amputé. Rien, nulle part, ne l'a
 *    signalé — il a fallu qu'Emmanuel compte ses lots à la main.
 *
 * ⚠️ UNE PHASE AVAL NE DOIT JAMAIS DÉCOUVRIR SILENCIEUSEMENT PLUS D'OBJETS QU'UNE PHASE AMONT
 *    CENSÉE COUVRIR LA MÊME POPULATION. Quand la phase 3 a trouvé 124 lots là où la phase 2 en
 *    voyait 98, le système devait crier. Il attendait la phase 5, et il ne criait même pas.
 *
 * Deux mesures, et une seule règle : `ATTENDUE = EXAMINÉE + EXCLUE`, `INEXPLIQUÉE = 0`.
 */

/**
 * La couverture d'une phase : ce qu'elle devait regarder, ce qu'elle a regardé, ce qu'elle a
 * écarté en le disant, et ce qui reste inexpliqué.
 *
 * ⚠️ UNE POPULATION INEXPLIQUÉE, MÊME D'UN SEUL OBJET, INTERDIT LA VALIDATION. C'est le seul
 *    moyen d'empêcher qu'une phase soit exacte et incomplète à la fois.
 */
function crgi_couverture(PDO $pdo, int $importId): array
{
    $q = function (string $sql) use ($pdo, $importId) {
        $st = $pdo->prepare($sql);
        $st->execute([$importId]);
        return (int)$st->fetchColumn();
    };
    $c = [];

    // ── PHASE 0 : les pages du dépôt ──────────────────────────────────────────────────────
    $pages = $q('SELECT COUNT(*) FROM crgi_page WHERE import_id = ?');
    $rattachees = $q('SELECT COUNT(*) FROM crgi_page WHERE import_id = ? AND crg_id IS NOT NULL');
    $hors = $q('SELECT COUNT(*) FROM crgi_page WHERE import_id = ? AND crg_id IS NULL
                 AND signal_page IS NOT NULL AND signal_page <> ""');
    $c[] = ['phase' => 0, 'population' => 'pages du dépôt', 'attendue' => $pages,
            'examinee' => $rattachees, 'exclue' => $hors,
            'motif_exclusion' => 'pages hors périmètre CRG (appels de fonds) et versos, '
                               . 'chacune portant son signal'];

    // ── PHASE 0 bis : LES PIÈCES DÉPOSÉES ─────────────────────────────────────────────────
    // ⚠️ AUCUN DOCUMENT NE PEUT RESTER DANS UNE CINQUIÈME CATÉGORIE INVISIBLE. Un fichier
    //    déposé est soit analysé, soit écarté AVEC SON MOTIF — OCR requis, hors CRG, structure
    //    inconnue, illisible. S'il n'est ni l'un ni l'autre, il a disparu avant même d'entrer
    //    dans les phases métier, et aucun contrôle aval ne peut le voir : les contrôles aval
    //    comparent des populations qui, elles, ne l'ont jamais reçu.
    $pieces = $q('SELECT COUNT(*) FROM crgi_piece WHERE import_id = ?');
    $etats = implode(',', array_map(fn($e) => '"' . $e . '"', array_keys(CRGI_ETATS_PIECE)));
    $nommees = $q('SELECT COUNT(*) FROM crgi_piece WHERE import_id = ?
                    AND etat IN (' . $etats . ')');
    $ecartees = $q('SELECT COUNT(*) FROM crgi_piece WHERE import_id = ?
                     AND etat IN (' . $etats . ') AND etat <> "ANALYSEE"');
    $c[] = ['phase' => 0, 'population' => 'pièces déposées', 'attendue' => $pieces,
            'examinee' => $nommees - $ecartees, 'exclue' => $ecartees,
            'motif_exclusion' => 'OCR requis, hors CRG, structure inconnue ou illisible — '
                               . 'chacune portant son état et son message'];

    // ── PHASE 1 : les CRG documentaires ───────────────────────────────────────────────────
    $crg = $q('SELECT COUNT(*) FROM crgi_crg WHERE import_id = ?');
    $inv = $q('SELECT COUNT(*) FROM crgi_crg WHERE import_id = ?
                AND inventaire_statut IS NOT NULL AND inventaire_statut <> ""');
    $reen = $q('SELECT COUNT(*) FROM crgi_crg WHERE import_id = ?
                 AND doublon_statut = "REENONCIATION"');
    // ⚠️ `ATTENDUE = EXAMINÉE + EXCLUE`, ET RIEN D'AUTRE. Un document de même clé mais de
    //    contenu différent n'était ni examiné ni exclu : il tombait dans une quatrième
    //    catégorie que personne ne regardait. C'est la définition même d'une perte silencieuse.
    $c[] = ['phase' => 1, 'population' => 'CRG documentaires', 'attendue' => $crg,
            'examinee' => $inv, 'exclue' => $reen,
            'motif_exclusion' => 'réénonciations démontrées : le même événement, énoncé deux '
                               . 'fois. Un document COMPLÉMENTAIRE, lui, est contributif.'];

    // ── PHASE 2 : les lots du patrimoine ──────────────────────────────────────────────────
    // ⚠️ L'ATTENDU N'EST PAS CE QUE LA PHASE 2 A LU. C'est ce que le document imprime, mesuré
    //    par les phases qui lisent la même population. Se comparer à soi-même ne prouve rien.
    $lotsP2 = $q('SELECT COUNT(*) FROM (SELECT DISTINCT c.compte, o.reference
                    FROM crgi_lot o JOIN crgi_crg c ON c.id = o.crg_id
                   WHERE o.import_id = ?) t');
    $lotsP3 = $q('SELECT COUNT(*) FROM (SELECT DISTINCT c.compte, o.lot_reference
                    FROM crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
                   WHERE o.import_id = ?) t');
    $c[] = ['phase' => 2, 'population' => 'lots (identité compte × référence)',
            'attendue' => max($lotsP2, $lotsP3), 'examinee' => $lotsP2, 'exclue' => 0,
            'motif_exclusion' => 'aucune : tout lot imprimé doit être confronté'];

    // ── PHASE 3 : les observations d'occupation ───────────────────────────────────────────
    $obs = $q('SELECT COUNT(*) FROM crgi_occupation WHERE import_id = ?');
    $qual = $q('SELECT COUNT(*) FROM crgi_occupation WHERE import_id = ?
                 AND statut IS NOT NULL AND statut <> ""');
    $c[] = ['phase' => 3, 'population' => 'observations d’occupation', 'attendue' => $obs,
            'examinee' => $qual, 'exclue' => 0,
            'motif_exclusion' => 'aucune : toute observation reçoit un verdict'];

    // ── PHASE 4 : les mouvements financiers ───────────────────────────────────────────────
    $mvt = $q('SELECT COUNT(*) FROM crgi_mouvement WHERE import_id = ?');
    $verdict = $q('SELECT COUNT(*) FROM crgi_mouvement WHERE import_id = ?
                    AND categorie IS NOT NULL AND categorie <> ""');
    $c[] = ['phase' => 4, 'population' => 'mouvements financiers', 'attendue' => $mvt,
            'examinee' => $verdict, 'exclue' => 0,
            'motif_exclusion' => 'aucune : tout montant imprimé reçoit une nature'];

    // ── RAPPROCHEMENT : les mouvements confrontés à MBI ───────────────────────────────────
    $rap = $q('SELECT COUNT(*) FROM crgi_mouvement WHERE import_id = ?
                AND rapprochement IS NOT NULL AND rapprochement <> ""');
    $c[] = ['phase' => 4, 'population' => 'mouvements confrontés à MBI', 'attendue' => $mvt,
            'examinee' => $rap, 'exclue' => 0,
            'motif_exclusion' => 'aucune : aucun mouvement ne reste muet'];

    foreach ($c as &$l) {
        $l['inexpliquee'] = (int)$l['attendue'] - (int)$l['examinee'] - (int)$l['exclue'];
        $l['ok'] = $l['inexpliquee'] === 0;
    }
    return $c;
}

/**
 * Les frontières entre phases : deux phases qui parlent du MÊME objet doivent en compter
 * autant.
 *
 * ⚠️ C'EST LE CONTRÔLE QUI AURAIT DÛ EXISTER DEPUIS LE DÉBUT. Il ne compare pas des totaux
 *    globaux mais les IDENTITÉS elles-mêmes, et il nomme celles qui manquent : un écart de
 *    nombre se discute, une liste d'identités absentes ne se discute pas.
 */
/**
 * La clé d'un nom de personne ou de société, pour CONFRONTER — jamais pour fusionner.
 *
 * ⚠️ NORMALISATION N'EST PAS RAPPROCHEMENT APPROXIMATIF. On retire les accents, la casse et
 *    les séparateurs, parce que les deux chaînes d'extraction ne les écrivent pas pareil :
 *    MBI enregistre « ALOUILotfi » soudé, pdfplumber coupe « LYANT » en « LY ANT ». On ne
 *    tolère AUCUNE autre différence : deux noms qui diffèrent d'une lettre restent deux noms.
 */
function crgi_cle_nom(?string $nom): string
{
    return preg_replace('~[^A-Z0-9]~', '', crgi_plat((string)$nom));
}

function crgi_frontieres(PDO $pdo, int $importId): array
{
    $sorties = [];
    // ⚠️ TOUTE FRONTIÈRE N'EST PAS UNE ÉGALITÉ. Certaines le sont — un lot connu de la phase 3
    //    DOIT l'être de la phase 2. D'autres sont des INCLUSIONS démontrées : la phase 4
    //    refuse de nommer un occupant quand le bloc en porte deux, elle en nomme donc moins
    //    que la phase 3. Ce qui reste interdit dans les deux cas, c'est qu'une phase AVAL
    //    connaisse un objet que l'amont ignore — c'est exactement ainsi que 26 lots avaient
    //    disparu.
    $comparer = function (string $objet, string $amont, string $sqlA, string $aval,
                          string $sqlB, string $sens = 'egalite',
                          string $pourquoi = '') use ($pdo, $importId, &$sorties) {
        $ex = function (string $sql) use ($pdo, $importId) {
            $st = $pdo->prepare($sql);
            $st->execute([$importId]);
            return $st->fetchAll(PDO::FETCH_COLUMN);
        };
        $a = $ex($sqlA);
        $b = $ex($sqlB);
        $manquants = array_values(array_diff($b, $a));
        $enTrop = array_values(array_diff($a, $b));
        $sorties[] = [
            'objet' => $objet, 'amont' => $amont, 'aval' => $aval, 'sens' => $sens,
            'n_amont' => count($a), 'n_aval' => count($b),
            'manquants_amont' => $manquants, 'manquants_aval' => $enTrop,
            'pourquoi' => $pourquoi,
            'ok' => !$manquants && ($sens === 'inclusion' || !$enTrop),
        ];
    };
    $comparer(
        'lots (compte × référence)', 'phase 2',
        'SELECT DISTINCT CONCAT(c.compte, "§", o.reference) FROM crgi_lot o
           JOIN crgi_crg c ON c.id = o.crg_id WHERE o.import_id = ?',
        'phase 3',
        'SELECT DISTINCT CONCAT(c.compte, "§", o.lot_reference) FROM crgi_occupation o
           JOIN crgi_crg c ON c.id = o.crg_id WHERE o.import_id = ?'
    );
    $comparer(
        'lots (compte × référence)', 'phase 3',
        'SELECT DISTINCT CONCAT(c.compte, "§", o.lot_reference) FROM crgi_occupation o
           JOIN crgi_crg c ON c.id = o.crg_id WHERE o.import_id = ?',
        'phase 4',
        'SELECT DISTINCT CONCAT(c.compte, "§", m.lot_reference) FROM crgi_mouvement m
           JOIN crgi_crg c ON c.id = m.crg_id
          WHERE m.import_id = ? AND m.lot_reference IS NOT NULL AND m.lot_reference <> ""'
    );
    // ⚠️ UN COMPTE RENDU PEUT N'AVOIR AUCUN TABLEAU, ET CE N'EST PAS UNE PERTE. Neuf comptes
    //    de ce dépôt tiennent sur UNE page : une lettre qui énonce le solde EN TOUTES LETTRES
    //    — « votre relevé présentant un solde débiteur d'un montant de … que nous portons au
    //    débit du prochain relevé » — sans le moindre tableau. La doctrine interdit de lire un
    //    montant dans la prose (`la prose ment par hasard`) : ne rien lire est donc la bonne
    //    réponse, et exiger l'égalité accusait le moteur de la sobriété du document.
    //    La frontière devient une INCLUSION : la phase 4 ne peut pas connaître un compte que
    //    la phase 0 ignore, mais elle peut légitimement en connaître moins.
    $comparer(
        'comptes', 'phase 0',
        'SELECT DISTINCT compte FROM crgi_crg WHERE import_id = ? AND compte IS NOT NULL',
        'phase 4',
        'SELECT DISTINCT c.compte FROM crgi_mouvement m JOIN crgi_crg c ON c.id = m.crg_id
          WHERE m.import_id = ? AND c.compte IS NOT NULL',
        'inclusion',
        'Un compte rendu sans tableau — le solde énoncé en toutes lettres — ne produit aucun '
        . 'mouvement. Le lire dans la prose serait pire que ne pas le lire.'
    );
    // ⚠️ SEULES LES SITUATIONS QUI PORTENT UN LOT PEUVENT PORTER UNE OCCUPATION. Dix CRG de
    //    ce dépôt n'impriment aucun bloc de lot — que des honoraires, des factures ou un
    //    récapitulatif. Les compter dans l'attendu produisait un faux échec de couverture :
    //    la frontière doit comparer des populations RÉELLEMENT comparables, sinon elle crie
    //    au loup et on finit par ne plus l'écouter.
    $comparer(
        'situations portant au moins un lot', 'phase 2',
        'SELECT DISTINCT l.crg_id FROM crgi_lot l WHERE l.import_id = ?',
        'phase 3',
        'SELECT DISTINCT crg_id FROM crgi_occupation WHERE import_id = ?'
    );
    // ⚠️ LES DEUX PHASES NE LISENT PAS AVEC LE MÊME OUTIL, DONC PAS AVEC LES MÊMES ESPACES.
    //    `pdftotext -table` rend « LYANT Leo », pdfplumber « LY ANT Leo » : c'est le MÊME
    //    locataire. La frontière compare donc les clés de nom. Elle n'excuse aucun écart de
    //    lettres — seulement d'espacement, et seulement parce qu'il est démontré.
    $comparer(
        'locataires (clé de nom)', 'phase 3',
        'SELECT DISTINCT UPPER(REGEXP_REPLACE(locataire, "[^A-Za-z0-9]", ""))
           FROM crgi_occupation WHERE import_id = ? AND locataire IS NOT NULL',
        'phase 4',
        'SELECT DISTINCT UPPER(REGEXP_REPLACE(locataire, "[^A-Za-z0-9]", ""))
           FROM crgi_mouvement WHERE import_id = ? AND locataire IS NOT NULL',
        'inclusion',
        'La phase 4 REFUSE de nommer un occupant quand le bloc en porte plusieurs : le '
        . 'document ne dit pas à qui revient chaque montant. Elle en nomme donc moins que la '
        . 'phase 3 — mais jamais un que la phase 3 ignore.'
    );
    return $sorties;
}

/**
 * LE TEST DE COMPATIBILITÉ D'UN DÉPÔT — à passer AVANT toute analyse.
 *
 * ⚠️ `CE QUI N'EST PAS SUR LA PAGE N'EXISTE PAS` (INTEG-ECRAN-01). Un lanceur qui ne vit qu'au
 *    terminal ne sera pas passé le jour où un nouveau CRG arrivera — et c'est précisément ce
 *    jour-là qu'il sert.
 *
 * ⚠️ IL N'ÉCRIT RIEN, NI EN BASE NI EN STAGING. Il lit le document et rend un verdict :
 *    COMPATIBLE, COMPATIBLE AVEC EXCEPTIONS ISOLÉES, ou NOUVELLE STRUCTURE — ANALYSE
 *    NÉCESSAIRE. Une structure inconnue est ISOLÉE et nommée, jamais interprétée.
 */
function crgi_compatibilite(string $chemin): array
{
    if (!is_file($chemin)) {
        throw new RuntimeException('FICHIER INTROUVABLE : ' . $chemin);
    }
    $python = getenv('CRG_PYTHON') ?: (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
    $script = realpath(__DIR__ . '/../scripts/crg_compatibilite.py');
    if (!$script) {
        throw new RuntimeException('MOTEUR ABSENT : scripts/crg_compatibilite.py');
    }
    $sortie = trim((string)@shell_exec(
        escapeshellarg($python) . ' ' . escapeshellarg($script) . ' '
        . escapeshellarg($chemin) . ' --json 2>&1'
    ));
    $r = json_decode($sortie, true);
    if (!is_array($r) || !isset($r['verdict'])) {
        // ⚠️ FAIL CLOSED : un lanceur muet n'est pas un document compatible.
        throw new RuntimeException('TEST DE COMPATIBILITÉ MUET OU ILLISIBLE : '
                                 . mb_substr($sortie, 0, 300));
    }
    return $r;
}

/**
 * LES ARBITRAGES, REGROUPÉS PAR LA RÈGLE QUI LES PRODUIT.
 *
 * ⚠️ `DOUTE = ARBITRAGE TRAÇABLE + ON CONTINUE.` Une incertitude locale ne doit jamais arrêter
 *    tout le corpus. Mais un arbitrage n'est utile que s'il se DÉCIDE : posé ligne par ligne,
 *    il devient une liste de 86 questions dispersées que personne ne tranchera.
 *
 * ⚠️ ON REGROUPE PAR RÈGLE, PAS PAR OBJET. Trente-trois immeubles à arbitrer, c'est en réalité
 *    UNE question — « quand plusieurs immeubles de MBI portent le même nom et le même code
 *    postal, lequel ? » — posée trente-trois fois. Chaque groupe porte donc ses choix FERMÉS
 *    et l'impact exact de chacun sur l'intégration.
 *
 * ⚠️ ET AUCUN CHOIX NE S'APPLIQUE TOUT SEUL. Cette fonction DÉCRIT ce qu'il y a à décider ;
 *    elle n'écrit rien et ne présélectionne rien.
 */
/**
 * LES POPULATIONS D'IDENTITÉ, ET CE QUI FAIT CONFLIT DANS CHACUNE.
 *
 * ⚠️ UNE TABLE, PAS UNE CASCADE DE `if`. Le jour où le dépôt apportera une identité de plus —
 *    un tiers, un mandataire —, elle s'AJOUTE ici : la détection, l'arbitrage, l'écran et le
 *    contrôle d'invariant la prennent en charge sans une ligne de code supplémentaire.
 *
 * ⚠️ LA CLÉ EST CE QUE LE DOCUMENT DÉMONTRE, LA VALEUR EST CE QU'IL IMPRIME. Deux valeurs pour
 *    une même clé, dans un même dépôt, c'est un conflit — jamais une occasion de choisir.
 */
const CRGI_IDENTITES = [
    'CONFLIT_OCCUPANT' => [
        'libelle' => 'l’occupant d’un lot, à une même date d’arrêté',
        'table'   => 'crgi_occupation o',
        'valeur'  => 'o.locataire',
        'cle'     => "CONCAT(c.compte, ' · lot ', o.lot_reference, ' · ', o.date_arrete)",
        'groupe'  => 'c.compte, o.lot_reference, o.date_arrete, o.rang',
    ],
    'CONFLIT_PROPRIO' => [
        'libelle' => 'le propriétaire d’un compte mandant',
        'table'   => 'crgi_crg o',
        'valeur'  => 'o.proprietaire',
        'cle'     => "CONCAT('compte ', c.compte)",
        'groupe'  => 'c.compte',
        // Le CRG n'a pas de colonne `page` : sa preuve est sa page de début.
        'page'    => 'o.page_debut',
    ],
    'CONFLIT_IMMEUBLE' => [
        'libelle' => 'le nom d’un immeuble, sous un même code',
        'table'   => 'crgi_immeuble o',
        'valeur'  => 'o.nom',
        'cle'     => "CONCAT(c.compte, ' · immeuble ', o.code)",
        'groupe'  => 'c.compte, o.code',
    ],
    'CONFLIT_LOT' => [
        'libelle' => 'le libellé d’un lot, sous une même référence',
        'table'   => 'crgi_lot o',
        'valeur'  => 'o.libelle',
        'cle'     => "CONCAT(c.compte, ' · lot ', o.reference)",
        'groupe'  => 'c.compte, o.reference',
    ],
];

/**
 * LES CONFLITS D'IDENTITÉ DU DÉPÔT — DÉTECTÉS, JAMAIS TRANCHÉS.
 *
 * ⚠️ UNE RESSEMBLANCE NE PROUVE JAMAIS UNE IDENTITÉ. Deux lectures proches d'un même nom
 *    peuvent être une personne lue deux fois, ou deux personnes réellement distinctes. Le
 *    moteur n'a aucun moyen de le démontrer : rapprocher par ressemblance créerait une
 *    identité par approximation, ce que la doctrine refuse depuis P2.
 *
 * ⚠️ MAIS UN CONFLIT DÉTECTÉ ET INVISIBLE EST PIRE QUE PAS DE DÉTECTION. Un contrôle qui vire
 *    au rouge sans ouvrir de question laisse Emmanuel devant un défaut qu'il ne peut pas
 *    trancher. `CONFLITS DÉTECTÉS = RÉSOLUS AVEC PREUVE + ARBITRABLES` : c'est l'invariant, et
 *    `crgi_coherence` le vérifie.
 *
 * ⚠️ CHAQUE VALEUR PORTE SA PREUVE. On rend, pour chacune, son CRG et sa page : l'écran peut
 *    ouvrir les deux côtés du conflit sans que personne n'ait à chercher.
 */
function crgi_conflits_identite(PDO $pdo, int $importId, ?string $type = null): array
{
    $sortie = [];
    foreach (CRGI_IDENTITES as $cle => $p) {
        if ($type !== null && $type !== $cle) {
            continue;
        }
        $jointure = str_starts_with($p['table'], 'crgi_crg ')
            ? 'crgi_crg o'                       // le CRG est son propre contexte
            : $p['table'] . ' JOIN crgi_crg c ON c.id = o.crg_id';
        $alias = str_starts_with($p['table'], 'crgi_crg ') ? 'o' : 'c';
        $jointure .= ' JOIN crgi_piece pi ON pi.id = ' . $alias . '.piece_id';
        $sql = sprintf(
            'SELECT MIN(o.id) cible_id, %s cle, COUNT(DISTINCT %s) valeurs,
                    GROUP_CONCAT(DISTINCT CONCAT(%s, CHAR(31), %s.id, CHAR(31), COALESCE(%s, 0))
                                 ORDER BY %s SEPARATOR "\n") lectures,
                    MIN(%s.compte) compte, MIN(%s.agence) agence, MIN(%s.proprietaire) proprietaire,
                    MIN(pi.nom_original) nom_original, MIN(pi.sha256) sha256
               FROM %s
              WHERE o.import_id = ? AND %s IS NOT NULL AND %s <> ""
              GROUP BY %s
             HAVING valeurs > 1
              ORDER BY cle',
            str_replace('c.', $alias . '.', $p['cle']), $p['valeur'],
            $p['valeur'], $alias, $p['page'] ?? 'o.page', $p['valeur'],
            $alias, $alias, $alias,
            $jointure, $p['valeur'], $p['valeur'],
            str_replace('c.', $alias . '.', $p['groupe'])
        );
        // ⚠️ CHAR(31) POUR LES CHAMPS, UN SAUT DE LIGNE POUR LES LECTURES. MySQL ne connaît pas
        //    l'échappée « \037 » : il y lit `\0` (NUL) suivi de « 37 », et les valeurs
        //    revenaient soudées par des chiffres parasites. Et `SEPARATOR` n'accepte qu'un
        //    littéral, jamais un appel de fonction. Un séparateur se nomme, il ne se devine pas.
        $st = $pdo->prepare($sql);
        $st->execute([$importId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lectures = [];
            foreach (explode("\n", (string)$r['lectures']) as $bloc) {
                [$valeur, $crgId, $page] = array_pad(explode("\x1f", $bloc), 3, null);
                $lectures[] = ['valeur' => $valeur, 'crg_id' => (int)$crgId, 'page' => (int)$page];
            }
            // ⚠️ UNE VIRGULE ET UNE MAJUSCULE NE FONT PAS DEUX IDENTITÉS. « 15 rue Siméon
            //    Gouet » et « 15, Rue Siméon Gouet » sont le MÊME immeuble : le document
            //    l'imprime deux fois, avec deux typographies. Le SQL compte les valeurs
            //    brutes — c'est un filet grossier, et c'est bien : il ne doit rien rater.
            //    Mais poser la question sur un écart purement typographique, c'est demander à
            //    Emmanuel de trancher ce que la doctrine tranche déjà (`INTEG-RAPPRO-02` :
            //    on ignore accents, casse et séparateurs, RIEN d'autre). La confirmation se
            //    fait donc APRÈS lecture, sur les valeurs normalisées.
            $distinctes = array_unique(array_map(
                fn($l) => crgi_plat((string)$l['valeur']), $lectures));
            if (count($distinctes) < 2) {
                continue;
            }
            $sortie[] = [
                'type'     => $cle,
                'libelle'  => $p['libelle'],
                'cible_id' => (int)$r['cible_id'],
                'cle'      => (string)$r['cle'],
                'compte'   => (string)$r['compte'],
                'agence'   => (string)$r['agence'],
                'proprietaire' => (string)$r['proprietaire'],
                'nom_original' => (string)$r['nom_original'],
                'sha256'   => (string)$r['sha256'],
                'lectures' => $lectures,
            ];
        }
    }
    return $sortie;
}

function crgi_arbitrages(PDO $pdo, int $importId): array
{
    $groupes = [];
    $q = function (string $sql) use ($pdo, $importId) {
        $st = $pdo->prepare($sql);
        $st->execute([$importId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };

    // ── IDENTITÉS : le document nomme deux fois la même chose, de deux façons ─────────────
    // ⚠️ EN TÊTE DE FILE, ET C'EST VOULU. Une identité mal tranchée contamine tout ce qui s'y
    //    rattache — occupations, argent, patrimoine. On la pose avant le reste.
    $conflits = [];
    foreach (crgi_conflits_identite($pdo, $importId) as $c) {
        $conflits[$c['type']][] = $c;
    }
    foreach ($conflits as $type => $lignes) {
        $libelle = CRGI_IDENTITES[$type]['libelle'];
        $groupes[] = [
            'groupe'   => str_replace('CONFLIT_', 'IDENTITE-', $type),
            'cause'    => 'DOCUMENT',
            'cible'    => $type,
            'famille'  => 'IDENTITÉS',
            'question' => 'Le dépôt désigne ' . $libelle . ' de deux façons différentes. '
                        . 'S’agit-il de la même identité ?',
            'regle'    => '`UNE RESSEMBLANCE NE PROUVE JAMAIS UNE IDENTITÉ` — le moteur détecte '
                        . 'le conflit, il ne le tranche pas.',
            'choix'    => [
                'Même identité'         => 'les deux lectures désignent la même chose ; MBI '
                                         . 'n’en crée qu’une.',
                'Identités différentes' => 'MBI en porte deux, distinctes et assumées.',
                'Indéterminable'        => 'le document ne permet pas de trancher ; rien n’est '
                                         . 'écrit pour cette identité.',
            ],
            'impact'   => 'Bloque l’écriture de CETTE identité et de ce qui en dépend '
                        . 'directement. N’empêche aucune autre écriture du dépôt.',
            'lignes'   => $lignes,
        ];
    }

    // ── PATRIMOINE : plusieurs immeubles MBI portent le même nom ──────────────────────────
    // ⚠️ ON GROUPE PAR AGENCE, PAS PAR COMPTE MANDANT. Le code d'immeuble est celui du syndic :
    //    le même code sous trois mandats désigne LE MÊME immeuble, et posait trois fois la
    //    même question — 37 là où il y en avait 33, 14 là où il y en avait 9. Mais
    //    `UN CODE DE COMPTE N'EST JAMAIS GLOBAL` vaut aussi pour un code d'immeuble : il est
    //    borné par l'agence qui l'attribue, jamais par le dépôt. C'est donc l'agence qui borne
    //    le groupe — on réunit ce qui est le même, sans confondre deux référentiels.
    $imm = $q(
        // ⚠️ UNE QUESTION GROUPÉE DOIT DIRE QUI ELLE COUVRE. Sans `couvre`, le tableau de
        //    bord comptait comme PERTE SILENCIEUSE chaque ligne du groupe sauf la première :
        //    89 immeubles annoncés perdus sur un dépôt où ils étaient tous dans la file, sous
        //    37 questions. Un indicateur qui crie au loup se fait ignorer aussi sûrement qu'un
        //    indicateur muet — et celui-là est le KPI central du pilotage.
        'SELECT MIN(i.id) cible_id, GROUP_CONCAT(i.id) couvre,
                COALESCE(i.code, CONCAT(i.nom, "|", i.code_postal)) cle,
                i.nom, i.code_postal, i.ville, MIN(i.page) page, LEFT(MIN(i.motif), 220) motif,
                c.agence, MIN(c.compte) compte, COUNT(DISTINCT c.compte) comptes
           FROM crgi_immeuble i JOIN crgi_crg c ON c.id = i.crg_id
          WHERE i.import_id = ? AND i.statut = "A ARBITRER"
            AND i.motif NOT LIKE ' . $pdo->quote(CRGI_MOTIF_MBI_REPETE . '%') . '
          GROUP BY c.agence, cle, i.nom, i.code_postal, i.ville'
    );
    if ($imm) {
        $groupes[] = [
            'groupe'   => 'IMMEUBLE-HOMONYME',
            'cause'    => 'MBI',
            'cible'    => 'IMMEUBLE',
            'famille'  => 'IMMEUBLES',
            'question' => 'Plusieurs immeubles de MBI portent le même nom et le même code '
                        . 'postal. Lequel le CRG désigne-t-il ?',
            'regle'    => '`AUCUN RAPPROCHEMENT APPROXIMATIF NE CRÉE UNE IDENTITÉ` — le moteur '
                        . 'ne tranche pas entre deux homonymes.',
            'choix'    => [
                'Désigner l’immeuble MBI existant' => 'le CRG s’y rattache ; aucun immeuble créé.',
                'Créer un immeuble distinct'       => 'MBI porte alors un homonyme de plus, '
                                                    . 'assumé.',
                'Laisser en attente'               => 'ses lots restent non rattachés ; leurs '
                                                    . 'occupations et leurs montants, eux, '
                                                    . 'restent intégrables.',
            ],
            'impact'   => 'Bloque la création ou la mise à jour de CET immeuble et le '
                        . 'rattachement de ses lots. N’empêche ni les occupations ni l’argent.',
            'lignes'   => $imm,
        ];
    }

    // ── PATRIMOINE : plusieurs BIENS de MBI portent la même référence ─────────────────────
    // ⚠️ IL MANQUAIT LA MÊME QUESTION POUR LES LOTS. Les immeubles homonymes ouvraient une
    //    question ; les lots homonymes, non — trente d'entre eux attendaient un arbitrage que
    //    personne ne pouvait rendre, et le KPI des pertes silencieuses les a signalés. Un
    //    objet en attente sans question est une donnée perdue : c'est la définition même.
    // ── PATRIMOINE : MBI porte PLUSIEURS FOIS le même immeuble ───────────────────────────
    // ⚠️ CE N'EST PAS LA MÊME QUESTION, DONC PAS LA MÊME FAMILLE. Sur un corpus, 17 des 18
    //    « homonymes » réunissaient des enregistrements que MBI lui-même déclare identiques —
    //    même code de gestion, ou même adresse. Le document n'a jamais été ambigu ; c'est la
    //    base qui se répète, et la réponse est la MÊME pour les 17 : quelle règle appliquer
    //    quand MBI porte le même immeuble deux ou trois fois. Une réponse commune fait une
    //    question commune — c'est la seule condition du repli, et elle est remplie ici, alors
    //    qu'elle ne l'est jamais entre deux bâtiments réellement différents.
    $repetes = $q(
        'SELECT MIN(i.id) cible_id, GROUP_CONCAT(i.id) couvre, i.code, i.nom, i.code_postal,
                i.ville, MIN(i.page) page, LEFT(MIN(i.motif), 240) motif, c.agence,
                MIN(c.compte) compte
           FROM crgi_immeuble i JOIN crgi_crg c ON c.id = i.crg_id
          WHERE i.import_id = ? AND i.statut = "A ARBITRER"
            AND i.motif LIKE ' . $pdo->quote(CRGI_MOTIF_MBI_REPETE . '%') . '
          GROUP BY c.agence, COALESCE(i.code, CONCAT(i.nom, "|", i.code_postal))'
    );
    if ($repetes) {
        $groupes[] = [
            'groupe'   => 'IMMEUBLE-REPETE-DANS-MBI',
            'cause'    => 'MBI',
            'cible'    => 'IMMEUBLE',
            'famille'  => 'IMMEUBLES',
            'question' => 'MBI porte plusieurs fois le même immeuble, sous le même code de '
                        . 'gestion ou à la même adresse. Auquel rattacher les comptes rendus ?',
            'regle'    => '`AMBIGUÏTÉ DU DOCUMENT ≠ CONTRADICTION DE LA BASE` — le document '
                        . 'désigne un immeuble et un seul ; c’est MBI qui l’enregistre '
                        . 'plusieurs fois. La réponse est un ménage à faire dans MBI.',
            'choix'    => [
                'Rattacher à l’enregistrement le plus complet' => 'celui qui porte déjà des '
                    . 'lots ; les autres restent à dédoublonner, hors de ce dépôt.',
                'Rattacher au plus ancien enregistrement' => 'le premier créé fait référence ; '
                    . 'les suivants sont des reprises à nettoyer.',
                'Laisser en attente'  => 'aucun rattachement au patrimoine MBI. Les '
                    . 'occupations et les montants restent lus et intégrables.',
            ],
            'impact'   => 'Bloque le rattachement de CES immeubles à MBI et celui de leurs '
                        . 'lots. N’empêche ni les occupations ni l’argent, démontrés au lot.',
            'lignes'   => $repetes,
        ];
    }

    $lots = $q(
        'SELECT MIN(l.id) cible_id, GROUP_CONCAT(l.id) couvre,
                l.reference, l.libelle, l.locataire, MIN(l.page) page,
                LEFT(MIN(l.motif), 220) motif, c.agence, MIN(c.compte) compte
           FROM crgi_lot l JOIN crgi_crg c ON c.id = l.crg_id
          WHERE l.import_id = ? AND l.statut = "A ARBITRER"
          GROUP BY c.agence, l.reference'
    );
    if ($lots) {
        $groupes[] = [
            'groupe'   => 'LOT-HOMONYME',
            'cause'    => 'MBI',
            'cible'    => 'LOT',
            'famille'  => 'LOTS',
            'question' => 'Plusieurs biens de MBI portent la même référence de lot. Lequel le '
                        . 'CRG désigne-t-il ?',
            'regle'    => '`AUCUN RAPPROCHEMENT APPROXIMATIF NE CRÉE UNE IDENTITÉ` — le moteur '
                        . 'ne tranche pas entre deux biens de même référence.',
            'choix'    => [
                'Désigner le bien MBI existant' => 'le lot s’y rattache ; aucun bien créé.',
                'Créer un bien distinct'        => 'MBI porte alors un homonyme de plus, assumé.',
                'Laisser en attente'            => 'ses occupations et ses montants restent lus, '
                                                 . 'sans rattachement au patrimoine MBI.',
            ],
            'impact'   => 'Bloque le rattachement de CE lot à un bien de MBI. N’empêche ni ses '
                        . 'occupations ni son argent, démontrés au lot sans dépendre du bien.',
            'lignes'   => $lots,
        ];
    }

    // ── OCCUPATION : ce que la suite des périodes ne démontre pas ─────────────────────────
    $familles = [
        ['une seule période',
         'Le lot n’apparaît qu’à UNE période du dépôt : la suite ne démontre ni maintien, ni '
         . 'entrée, ni départ.',
         '`ABSENCE ≠ DÉPART DÉMONTRÉ` — une seule photographie ne fait pas une chronologie.',
         ['Considérer l’occupant en place'  => 'l’occupation est écrite telle que lue.',
          'Attendre un dépôt complémentaire' => 'rien n’est écrit pour ce lot.']],
        ['Aucune ligne',
         'Aucune ligne « Locataire: » n’est imprimée sur cette période : le document ne dit '
         . 'rien de l’occupation.',
         '`UN LOT SANS LIGNE LOCATAIRE N’EST PAS UN LOT VACANT` — le vide ne se lit pas comme '
         . 'un départ.',
         ['Déclarer le lot vacant'   => 'une vacance est écrite, que le document ne démontre pas.',
          'Laisser l’occupation en attente' => 'le lot et son argent restent intégrables, sans '
                                             . 'occupant.']],
        ['Dernière période',
         'Le lot cesse d’apparaître alors que son compte continue d’être rendu.',
         '`ABSENCE ≠ DÉPART DÉMONTRÉ` — le CRG suivant peut simplement ne pas avoir été déposé.',
         ['Clôturer l’occupation' => 'un départ est écrit sans que le document l’ait dit.',
          'Conserver l’occupation' => 'l’occupant reste en place jusqu’à preuve contraire.']],
    ];
    foreach ($familles as [$motifCle, $question, $regle, $choix]) {
        $lignes = $q(
            'SELECT o.id cible_id, c.agence, c.compte, o.lot_reference, o.periode_cle,
                    o.date_arrete, o.page, COALESCE(o.locataire, "— aucun —") locataire,
                    o.solde, o.solde_source
               FROM crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
              WHERE o.import_id = ? AND o.statut = "A ARBITRER"
                AND o.statut_motif LIKE ' . $pdo->quote('%' . $motifCle . '%') . '
              ORDER BY c.compte, o.lot_reference'
        );
        if ($lignes) {
            $groupes[] = [
                'groupe'   => 'OCCUPATION-' . strtoupper(preg_replace('~[^A-Za-z]~', '',
                                                                     $motifCle)),
                // C'est le DOCUMENT qui ne démontre pas la chronologie : MBI n'y est pour rien.
                'cause'    => 'DOCUMENT',
                'cible'    => 'OCCUPATION',
                'famille'  => 'OCCUPATIONS',
                'question' => $question,
                'regle'    => $regle,
                'choix'    => $choix,
                'impact'   => 'Bloque l’écriture d’occupation de ces lots. N’empêche NI leur '
                            . 'création, NI leurs mouvements financiers, démontrés au lot sans '
                            . 'dépendre de l’occupant.',
                'lignes'   => $lignes,
            ];
        }
    }

    // ── FINANCES : ce que le rapprochement n'a pas pu trancher ───────────────────────────
    $fin = [
        ['CONTRADICTION',
         'MBI porte la MÊME ligne avec un AUTRE montant. Les deux ne peuvent pas être vrais.',
         '`MÊME MONTANT ≠ MÊME ÉCRITURE` — et deux montants différents sur la même ligne sont '
         . 'une contradiction, pas un doublon.',
         ['Retenir le montant du CRG'  => 'l’écriture MBI serait corrigée à la valeur lue.',
          'Retenir le montant de MBI'  => 'le montant du CRG est écarté, et tracé.',
          'Laisser en attente'         => 'ce seul mouvement n’est pas écrit.']],
        ['CANDIDAT NON DEMONTRABLE',
         'Plusieurs écritures de MBI portent le même libellé et le même montant, ou MBI porte '
         . 'la ligne sans son montant : rien ne dit LAQUELLE correspond.',
         '`MÊME MONTANT ≠ MÊME ÉCRITURE` — les départager au montant serait le rapprochement '
         . 'forcé qu’on s’interdit.',
         ['Désigner l’écriture MBI'    => 'le mouvement est réputé déjà présent.',
          'Créer le mouvement'         => 'MBI porte alors deux lignes très proches, assumées.',
          'Laisser en attente'         => 'ce seul mouvement n’est pas écrit.']],
    ];
    foreach ($fin as [$verdict, $question, $regle, $choix]) {
        $lignes = $q(
            'SELECT m.id cible_id, c.agence, c.compte, m.lot_reference, m.periode_cle,
                    m.date_arrete, m.page, m.libelle, m.colonne, m.montant, m.categorie,
                    m.mbi_ecriture_id, LEFT(m.rappro_motif, 220) motif
               FROM crgi_mouvement m JOIN crgi_crg c ON c.id = m.crg_id
              WHERE m.import_id = ? AND m.rapprochement = ' . $pdo->quote($verdict) . '
              ORDER BY c.compte, m.page'
        );
        if ($lignes) {
            $groupes[] = [
                'groupe'   => 'MOUVEMENT-' . str_replace(' ', '-', $verdict),
                // ⚠️ CE N'EST PAS LE DOCUMENT QUI HÉSITE. Le montant est lu, sa nature est
                //    connue : c'est la confrontation à `crg_ecritures` qui n'aboutit pas —
                //    MBI porte un autre montant, ou plusieurs lignes indiscernables.
                'cause'    => 'MBI',
                'cible'    => 'MOUVEMENT',
                'famille'  => 'MOUVEMENTS FINANCIERS',
                'question' => $question,
                'regle'    => $regle,
                'choix'    => $choix,
                'impact'   => 'Bloque l’écriture de ces seuls mouvements. N’empêche ni les '
                            . 'objets, ni les mouvements démontrés nouveaux.',
                'lignes'   => $lignes,
            ];
        }
    }

    // ── CE QUE LE DOCUMENT N'ATTRIBUE À RIEN ─────────────────────────────────────────────
    $ind = $q(
        'SELECT m.id cible_id, c.agence, c.compte, m.page, m.libelle, m.montant, m.x1,
                m.section
           FROM crgi_mouvement m JOIN crgi_crg c ON c.id = m.crg_id
          WHERE m.import_id = ? AND m.categorie = "INDETERMINABLE" ORDER BY m.page'
    );
    if ($ind) {
        $groupes[] = [
            'groupe'   => 'MOUVEMENT-INDETERMINABLE',
            'cause'    => 'DOCUMENT',
            'cible'    => 'MOUVEMENT',
            'famille'  => 'MOUVEMENTS FINANCIERS',
            'question' => 'Le document imprime un montant qu’il n’attribue à aucune colonne ni '
                        . 'section connue. Quelle est sa nature ?',
            'regle'    => 'Aucune affectation « au plus proche » sans borne : un montant qu’on '
                        . 'ne sait pas placer n’est jamais rangé dans la colonne d’à côté.',
            'choix'    => ['Lui donner une nature' => 'le mouvement devient intégrable.',
                           'Laisser indéterminable' => 'il reste lu, conservé et non écrit.'],
            'impact'   => 'Bloque l’écriture de CE seul montant. N’empêche aucune autre '
                        . 'écriture.',
            'lignes'   => $ind,
        ];
    }
    // ⚠️ ON RATTACHE LES DÉCISIONS DÉJÀ PRISES. Un écran qui repose la même question à
    //    quelqu'un qui y a déjà répondu lui fait croire que sa réponse s'est perdue.
    $st = $pdo->prepare('SELECT cible_type, cible_id, choix, precision_h, decide_le
                           FROM crgi_arbitrage WHERE import_id = ?');
    $st->execute([$importId]);
    $prises = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $prises[$d['cible_type'] . '#' . $d['cible_id']] = $d;
    }
    foreach ($groupes as &$g) {
        $g['tranches'] = 0;
        foreach ($g['lignes'] as &$l) {
            $cle = $g['cible'] . '#' . (int)($l['cible_id'] ?? 0);
            $l['decision'] = $prises[$cle] ?? null;
            if ($l['decision']) {
                $g['tranches']++;
            }
        }
        unset($l);
    }
    unset($g);
    return $groupes;
}

/**
 * Enregistre UNE décision d'arbitrage.
 *
 * ⚠️ ON DEMANDAIT D'ARBITRER SANS DONNER OÙ RÉPONDRE. L'écran posait sept décisions, listait
 *    leurs choix et leurs conséquences — et n'offrait aucun champ. Un arbitrage qu'on ne peut
 *    pas enregistrer n'est pas un arbitrage : c'est un constat qu'on relit indéfiniment.
 *
 * ⚠️ DÉCIDER N'EST PAS INTÉGRER. Cette fonction n'écrit que dans le staging : elle date et
 *    signe un choix, elle n'exécute rien. Aucune donnée métier de MBI n'est touchée.
 *
 * ⚠️ ET UN CHOIX DOIT ÊTRE L'UN DE CEUX QUE LA RÈGLE PROPOSE. Accepter n'importe quel texte
 *    laisserait entrer une décision que l'intégration ne saurait pas exécuter. La précision
 *    libre, elle, est là précisément pour ce que les choix fermés ne disent pas.
 */
function crgi_arbitrer(PDO $pdo, int $importId, string $cibleType, int $cibleId,
                       string $choix, ?string $precision, int $userId): void
{
    $connus = [];
    $groupe = '';
    foreach (crgi_arbitrages($pdo, $importId) as $g) {
        if ($g['cible'] !== $cibleType) {
            continue;
        }
        foreach ($g['lignes'] as $l) {
            if ((int)($l['cible_id'] ?? 0) === $cibleId) {
                $connus = array_keys($g['choix']);
                $groupe = (string)$g['groupe'];
                break 2;
            }
        }
    }
    if (!$connus) {
        throw new RuntimeException(
            'CET OBJET N’EST PAS EN ARBITRAGE : ' . $cibleType . ' n°' . $cibleId
            . '. Une décision ne se pose que sur une question réellement ouverte.'
        );
    }
    if ($choix !== '' && !in_array($choix, $connus, true)) {
        throw new RuntimeException(
            'CHOIX INCONNU POUR CETTE RÈGLE : « ' . $choix . ' ». Les choix possibles sont : '
            . implode(' · ', $connus)
        );
    }
    if ($choix === '') {
        // Retirer sa décision EST une décision : on efface, on ne garde pas un choix vide.
        $pdo->prepare('DELETE FROM crgi_arbitrage
                        WHERE import_id = ? AND cible_type = ? AND cible_id = ?')
            ->execute([$importId, $cibleType, $cibleId]);
        return;
    }
    $pdo->prepare(
        'INSERT INTO crgi_arbitrage (import_id, groupe, cible_type, cible_id, choix,
                                     precision_h, decide_par)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE groupe = VALUES(groupe), choix = VALUES(choix),
                                 precision_h = VALUES(precision_h),
                                 decide_par = VALUES(decide_par)'
    )->execute([$importId, $groupe, $cibleType, $cibleId, $choix,
                ($precision !== null && $precision !== '')
                    ? mb_substr($precision, 0, 1000) : null,
                $userId ?: null]);
}

