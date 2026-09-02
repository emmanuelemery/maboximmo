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
              'lecteurs' => [], 'lecture_degradee' => null];
    foreach ($pieces as $p) {
        $res = crgi_lancer_phase0((string)$p['chemin']);
        if (isset($res['erreur'])) {
            $pdo->prepare('UPDATE crgi_piece SET etat = ?, message = ? WHERE id = ?')
                ->execute(['ILLISIBLE', mb_substr($res['erreur'], 0, 500), (int)$p['id']]);
            $bilan['illisibles'][] = $p['nom_original'];
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
        if ((int)$res['stats']['crg_detectes'] === 0 && (int)$res['nb_pages'] > 0) {
            $pdo->prepare('UPDATE crgi_piece SET etat = ?, nb_pages = ?, message = ? WHERE id = ?')
                ->execute(['ILLISIBLE', (int)$res['nb_pages'],
                           'AUCUN CRG RECONNU sur ' . (int)$res['nb_pages'] . ' pages lues. '
                         . 'Le document a bien été parcouru : ce sont ses signaux qui n’ont pas '
                         . 'été reconnus. Ouvrir quelques pages avant de conclure.',
                           (int)$p['id']]);
            $bilan['illisibles'][] = $p['nom_original'];
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
    $degrades = array_filter(
        array_keys($bilan['lecteurs']),
        static fn($l) => stripos($l, 'poppler') === false
    );
    if ($degrades) {
        $bilan['lecture_degradee'] =
            'LECTURE DÉGRADÉE — ' . implode(', ', $degrades) . '. Ce serveur n’expose pas '
            . '`pdftotext` de poppler : le lecteur employé ne restitue pas les colonnes de la '
            . 'même façon et ignore le mode `-table`. Le découpage, les périodes et les '
            . 'comptes restent justes ; l’IDENTIFICATION (nom du propriétaire) et les '
            . 'RATTACHEMENTS de la phase 3 sont dégradés. Installer poppler, ou désigner le '
            . 'binaire par la variable `CRG_PDFTOTEXT`, puis relancer la phase 0.';
    }

    if ($bilan['pieces'] === 0) {
        crgi_marquer_phase($pdo, $importId, 0, 'BLOQUEE',
            'Aucune pièce lisible : ' . implode(', ', $bilan['illisibles']));
        throw new RuntimeException('AUCUNE PIÈCE LISIBLE / ANALYSE IMPOSSIBLE');
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

/** Écrit l'état d'une phase. Le message dit toujours pourquoi, quand il y a un pourquoi. */
function crgi_marquer_phase(PDO $pdo, int $importId, int $phase, string $statut, ?string $msg): void
{
    $pdo->prepare(
        'INSERT INTO crgi_phase (import_id, phase, statut, message, analyse_le)
         VALUES (:i,:p,:s,:m,NOW())
         ON DUPLICATE KEY UPDATE statut = :s2, message = :m2, analyse_le = NOW()'
    )->execute([':i' => $importId, ':p' => $phase, ':s' => $statut, ':m' => $msg,
                ':s2' => $statut, ':m2' => $msg]);
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
                r.page_debut AS rd, r.page_fin AS rf, p.chemin
           FROM crgi_crg c
           JOIN crgi_crg r ON r.id = c.doublon_de
           JOIN crgi_piece p ON p.id = c.piece_id
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
    // ⚠️ UNE SEULE LECTURE DU DOCUMENT PAR PIÈCE (`INTEG-PERF-01`, comme la phase 4). Un
    //    processus Python par paire relisait les 906 pages du PDF de 616 Mo pour n'en
    //    découper que deux extraits : 18 paires × 5,7 s = 103 s des 118 s de la phase 0,
    //    pour 6 s de lecture utile. La règle de qualification, elle, n'a pas changé — c'est
    //    le même `qualifier_paire`, sur les mêmes pages, dans le même ordre.
    $bilan = ['A' => 0, 'B' => 0, 'C' => 0];
    $verdicts = [];
    $parPiece = [];
    foreach ($paires as $p) {
        $parPiece[(string)$p['chemin']][] = [
            'id' => (int)$p['id'],
            'ad' => (int)$p['page_debut'], 'af' => (int)$p['page_fin'],
            'bd' => (int)$p['rd'], 'bf' => (int)$p['rf'],
        ];
    }
    foreach ($parPiece as $chemin => $lot) {
        $fichier = tempnam(sys_get_temp_dir(), 'crgid_');
        file_put_contents($fichier, json_encode($lot));
        $sortie = @shell_exec(
            escapeshellarg($python) . ' ' . escapeshellarg($script) . ' '
            . escapeshellarg($chemin) . ' ' . escapeshellarg($fichier) . ' 2>&1'
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
    $etat0 = crgi_phase_validee($pdo, $importId, 0);
    if (!$etat0['validee'] || $etat0['perimee']) {
        throw new RuntimeException(
            'PHASE 0 NON VALIDÉE — la phase 1 ne s’ouvre pas. Une confrontation menée sur un '
            . 'découpage non scellé comparerait MBI à un résultat qui peut encore changer.'
        );
    }
    crgi_marquer_phase($pdo, $importId, 1, 'EN ANALYSE', null);

    $st = $pdo->prepare(
        'SELECT id, compte, format, periode_debut, periode_fin, date_arrete, periode_cle
           FROM crgi_crg
          WHERE import_id = ? AND doublon_statut = "UNIQUE"
          ORDER BY page_debut'
    );
    $st->execute([$importId]);
    $situations = $st->fetchAll(PDO::FETCH_ASSOC);

    // Les comptes que MBI connaît, par code. ⚠️ Un même code peut exister dans deux systèmes :
    // on garde TOUS les identifiants, et l'ambiguïté devient un « à vérifier », pas un choix.
    $comptes = [];
    foreach ($pdo->query('SELECT id, code_compte, systeme FROM proprietaire_comptes_crg',
                         PDO::FETCH_ASSOC) as $c) {
        $comptes[(string)$c['code_compte']][] = $c;
    }

    $trimestres = $pdo->prepare(
        'SELECT id, periode_debut, periode_fin, date_arrete, periode_type
           FROM crg_trimestres WHERE id_compte_mandant = ?'
    );
    $maj = $pdo->prepare(
        'UPDATE crgi_crg SET inventaire_statut = ?, inventaire_motif = ?, mbi_trimestre_id = ?
          WHERE id = ?'
    );

    $bilan = ['DEJA CONNUE' => 0, 'NOUVELLE' => 0, 'COMPTE INCONNU' => 0, 'A VERIFIER' => 0];
    foreach ($situations as $s) {
        $cands = $comptes[(string)$s['compte']] ?? [];

        // ⚠️ UN CODE DE COMPTE N'EST JAMAIS GLOBAL — IL N'EXISTE QUE DANS SON SYSTÈME.
        //    `01600000` est SCI JOURNET chez `loca_immo_lyon` ; le CRG EMERY IMMO qui porte
        //    ce même code appartient à Madame GERMAIN. Chercher par le code seul rattachait
        //    silencieusement un CRG de RIOM au mandant lyonnais — un rapprochement faux se
        //    propage ensuite à tout ce que les phases suivantes construisent dessus.
        //    L'identité est le couple `(code, système)`, jamais le code (`P3A-COMPTE-03`).
        $systeme = CRGI_SYSTEME_DU_FORMAT[(string)$s['format']] ?? null;
        if ($systeme !== null && $cands) {
            $memeSysteme = array_values(array_filter(
                $cands, fn($c) => (string)$c['systeme'] === $systeme
            ));
            if (!$memeSysteme) {
                // ⚠️ ET UN HOMONYME N'EST PAS UNE ABSENCE ANODINE : on le NOMME. Sans cela,
                //    « compte inconnu » laisserait croire à un simple manque, alors qu'un
                //    code identique vit à côté, dans un autre système, prêt à être confondu.
                $ailleurs = implode(', ', array_map(fn($c) => $c['systeme'], $cands));
                $maj->execute(['COMPTE INCONNU',
                    'Le compte ' . $s['compte'] . ' n’existe pas dans le système ' . $systeme
                    . '. ATTENTION : ce code existe dans ' . $ailleurs . ' — c’est un '
                    . 'HOMONYME, pas le même mandant. `UN CODE DE COMPTE N’EST JAMAIS '
                    . 'GLOBAL`.', null, (int)$s['id']]);
                $bilan['COMPTE INCONNU']++;
                continue;
            }
            $cands = $memeSysteme;
        }

        if (!$cands) {
            $maj->execute(['COMPTE INCONNU',
                'Le compte ' . $s['compte'] . ' n’existe dans aucun système de MBI : la '
                . 'situation est nouvelle, et son mandant aussi.', null, (int)$s['id']]);
            $bilan['COMPTE INCONNU']++;
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
        $exactes = array_values(array_filter($connues, fn($t) =>
            (string)$t['periode_debut'] === (string)$s['periode_debut']
            && (string)$t['periode_fin'] === (string)$s['periode_fin']));

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
                SUM(g.inventaire_statut = "COMPTE INCONNU") AS comptes_inconnus,
                SUM(g.inventaire_statut = "A VERIFIER")     AS a_verifier,
                SUM(g.doublon_qualification = "B")          AS complementaires
           FROM crgi_crg g LEFT JOIN agences a ON a.id = g.agence_id
          WHERE g.import_id = ? AND g.doublon_statut = "UNIQUE"
          GROUP BY COALESCE(a.nom_agence, g.agence, "(agence indéterminable)"),
                   COALESCE(g.periode_cle, "(période indéterminable)")
          ORDER BY agence_vue, periode'
    );
    $st->execute([$importId]);
    $lignes = $st->fetchAll(PDO::FETCH_ASSOC);

    $tot = $pdo->prepare(
        'SELECT inventaire_statut, COUNT(*) n FROM crgi_crg
          WHERE import_id = ? AND doublon_statut = "UNIQUE" GROUP BY inventaire_statut'
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
          WHERE import_id = ? AND inventaire_statut = "COMPTE INCONNU"'
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
function crgi_extraire_patrimoine(PDO $pdo, int $importId): void
{
    $python = getenv('CRG_PYTHON') ?: (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
    $script = realpath(__DIR__ . '/../scripts/crg_integration_phase2.py');
    if (!$script) {
        throw new RuntimeException('MOTEUR ABSENT : scripts/crg_integration_phase2.py');
    }
    $st = $pdo->prepare(
        'SELECT c.id, c.page_debut, c.page_fin, p.chemin
           FROM crgi_crg c JOIN crgi_piece p ON p.id = c.piece_id
          WHERE c.import_id = ? AND c.doublon_statut = "UNIQUE" ORDER BY c.page_debut'
    );
    $st->execute([$importId]);
    $insImm = $pdo->prepare(
        'INSERT INTO crgi_immeuble (import_id, crg_id, code, nom, code_postal, ville, page)
         VALUES (?,?,?,?,?,?,?)'
    );
    $insLot = $pdo->prepare(
        'INSERT INTO crgi_lot (import_id, crg_id, reference, code_immeuble, numero, libelle,
                               locataire, page)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    // ⚠️ UNE PIÈCE, UNE LECTURE. La première version appelait le moteur CRG par CRG : sur un
    //    document de 587 Mo et 266 comptes rendus, cela faisait 266 lectures complètes du PDF
    //    et l'analyse ne finissait jamais. On envoie toutes les plages d'un coup.
    $parPiece = [];
    $debuts = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $crg) {
        $parPiece[(string)$crg['chemin']][] = ['id' => (int)$crg['id'],
                                               'debut' => (int)$crg['page_debut'],
                                               'fin' => (int)$crg['page_fin']];
        $debuts[(int)$crg['id']] = (int)$crg['page_debut'];
    }
    foreach ($parPiece as $chemin => $plages) {
        $fichier = tempnam(sys_get_temp_dir(), 'crgi_');
        file_put_contents($fichier, json_encode($plages));
        $cmd = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' '
             . escapeshellarg($chemin) . ' ' . escapeshellarg($fichier);
        $sortie = trim((string)@shell_exec($cmd . ' 2>&1'));
        @unlink($fichier);
        $r = json_decode($sortie, true);
        if (!is_array($r)) {
            throw new RuntimeException('MOTEUR PATRIMOINE MUET OU ILLISIBLE : '
                                     . mb_substr($sortie, 0, 300));
        }
        foreach ($r as $bloc) {
            $crgId = (int)$bloc['id'];
            $base = $debuts[$crgId] ?? 1;
            foreach ($bloc['immeubles'] ?? [] as $i) {
                $insImm->execute([$importId, $crgId, $i['code'], $i['nom'],
                                  $i['code_postal'], $i['ville'], $base + (int)$i['page'] - 1]);
            }
            foreach ($bloc['lots'] ?? [] as $l) {
                $insLot->execute([$importId, $crgId, $l['reference'], $l['code_immeuble'],
                                  $l['numero'], $l['libelle'], $l['locataire'],
                                  $base + (int)$l['page'] - 1]);
            }
        }
    }
}

/** Confronte chaque immeuble et chaque lot lu à ce que MBI enregistre. */
function crgi_confronter_patrimoine(PDO $pdo, int $importId): void
{
    // Les immeubles de MBI, par référence et par adresse normalisée.
    $parRef = $parAdresse = [];
    foreach ($pdo->query(
        'SELECT id, reference_immeuble, code_crg, nom_immeuble, adresse_1, code_postal, ville
           FROM immeubles', PDO::FETCH_ASSOC) as $i) {
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

    $st = $pdo->prepare('SELECT * FROM crgi_immeuble WHERE import_id = ?');
    $st->execute([$importId]);
    $maj = $pdo->prepare(
        'UPDATE crgi_immeuble SET statut = ?, mbi_immeuble_id = ?, avant_apres = ?, motif = ?
          WHERE id = ?'
    );
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $imm) {
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
        if (count($cands) > 1) {
            $maj->execute(['A ARBITRER', null, null,
                count($cands) . ' immeubles de MBI correspondent par ' . $par
                . ' : n°' . implode(', n°', array_column($cands, 'id'))
                . '. Aucune identité n’est retenue d’office.', (int)$imm['id']]);
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
                       'Rapproché de l’immeuble MBI n°' . $m['id'] . ' par ' . $par . '.',
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
                WHERE import_id = ? AND doublon_statut = "UNIQUE"
                GROUP BY inventaire_statut')->fetchAll(PDO::FETCH_KEY_PAIR);

    $proprios = $q('SELECT COUNT(DISTINCT compte) FROM crgi_crg
                     WHERE import_id = ? AND doublon_statut = "UNIQUE"')->fetchColumn();
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
    crgi_marquer_phase($pdo, $importId, 3, 'A VALIDER', null);
    return crgi_bilan_phase3($pdo, $importId)['statuts'];
}

/** Relève une observation par lot et par CRG — une seule lecture du PDF par pièce. */
function crgi_lire_occupation(PDO $pdo, int $importId): void
{
    $python = getenv('CRG_PYTHON') ?: (PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3');
    $script = realpath(__DIR__ . '/../scripts/crg_integration_phase3.py');
    if (!$script) {
        throw new RuntimeException('MOTEUR ABSENT : scripts/crg_integration_phase3.py');
    }
    $st = $pdo->prepare(
        'SELECT c.id, c.page_debut, c.page_fin, c.periode_cle, c.date_arrete, p.chemin
           FROM crgi_crg c JOIN crgi_piece p ON p.id = c.piece_id
          WHERE c.import_id = ? AND c.doublon_statut = "UNIQUE" ORDER BY c.page_debut'
    );
    $st->execute([$importId]);
    $meta = $parPiece = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $meta[(int)$c['id']] = $c;
        $parPiece[(string)$c['chemin']][] = ['id' => (int)$c['id'],
                                             'debut' => (int)$c['page_debut'],
                                             'fin' => (int)$c['page_fin']];
    }
    $ins = $pdo->prepare(
        'INSERT INTO crgi_occupation
            (import_id, crg_id, lot_reference, code_immeuble, periode_cle, date_arrete,
             locataire, bail_du, bail_au, rang, solde, solde_source, page)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    foreach ($parPiece as $chemin => $plages) {
        $fichier = tempnam(sys_get_temp_dir(), 'crgi3_');
        file_put_contents($fichier, json_encode($plages));
        $sortie = trim((string)@shell_exec(
            escapeshellarg($python) . ' ' . escapeshellarg($script) . ' '
            . escapeshellarg($chemin) . ' ' . escapeshellarg($fichier) . ' 2>&1'));
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
                $ins->execute([$importId, (int)$c['id'], $o['lot'], $codeImm,
                               $c['periode_cle'], $c['date_arrete'], $o['locataire'],
                               $o['bail_du'], $o['bail_au'] ?? null, (int)($o['rang'] ?? 0),
                               $o['solde'], $o['solde_source'], (int)$o['page']]);
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
        'SELECT o.id, o.lot_reference, o.date_arrete, o.periode_cle, o.locataire, o.bail_du,
                o.bail_au, o.rang, o.solde, o.solde_source, c.compte
           FROM crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
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
            $congeAtteint = $loc !== null && !empty($o['bail_au'])
                         && (string)$o['bail_au'] <= (string)$o['date_arrete'];
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
            if ($loc === null) {
                // ⚠️ UN LOT SANS LIGNE LOCATAIRE N'EST PAS UN LOT VACANT : c'est un lot dont
                //    le document ne dit rien. Le vide ne se lit pas comme un départ.
                $statut = 'A ARBITRER';
                $motif = 'Aucune ligne « Locataire: » imprimée sur cette période : le document '
                       . 'ne dit rien de l’occupation. Ce n’est pas une vacance démontrée.';
            } elseif ($suiv && $suiv['locataire'] !== null
                      && crgi_plat((string)$suiv['locataire']) !== crgi_plat((string)$loc)) {
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
                    $statut = 'A ARBITRER';
                    $motif = 'Le lot n’apparaît qu’à une seule période du dépôt : la suite ne '
                           . 'démontre ni maintien, ni entrée, ni départ.';
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
            if ($statut !== 'A ARBITRER' && $loc !== null && $suiv === null
                && (string)$o['date_arrete'] < $derniere) {
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
    crgi_marquer_phase($pdo, $importId, 4, 'A VALIDER', null);
    return crgi_bilan_phase4($pdo, $importId)['categories'];
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
        'SELECT c.id, c.page_debut, c.page_fin, c.periode_cle, c.date_arrete, p.chemin
           FROM crgi_crg c JOIN crgi_piece p ON p.id = c.piece_id
          WHERE c.import_id = ? AND c.doublon_statut = "UNIQUE" ORDER BY c.page_debut'
    );
    $st->execute([$importId]);
    $meta = $parPiece = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $meta[(int)$c['id']] = $c;
        $parPiece[(string)$c['chemin']][] = ['id' => (int)$c['id'],
                                             'debut' => (int)$c['page_debut'],
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
    foreach ($parPiece as $chemin => $plages) {
        $fichier = tempnam(sys_get_temp_dir(), 'crgi4_');
        file_put_contents($fichier, json_encode($plages));
        $sortie = trim((string)@shell_exec(
            escapeshellarg($python) . ' ' . escapeshellarg($script) . ' '
            . escapeshellarg($chemin) . ' ' . escapeshellarg($fichier) . ' 2>&1'
        ));
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
    ];
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
    $poser('PROPRIETAIRES', 'A ARBITRER', $pAmbigu + ($q['C'] ?? 0) + ($q['D'] ?? 0),
        'propriétaire',
        'Plusieurs propriétaires de MBI portent ce nom, ou le compte y existe sous une autre '
        . 'écriture. `AUCUN RAPPROCHEMENT APPROXIMATIF NE CRÉE UNE IDENTITÉ`.',
        'phase 0 × proprietaires · phase 2 qualification C/D',
        'Bloque la création ou le rattachement de CE propriétaire. N’empêche aucune autre '
        . 'famille.');
    $poser('PROPRIETAIRES', 'ARCHIVER', 0, 'propriétaire',
        'AUCUN. `ABSENT DU NOUVEAU CORPUS ≠ SUPPRIMER` : un propriétaire que ce dépôt ne '
        . 'mentionne pas n’est pas un propriétaire perdu.', 'doctrine · INTEG-CONFRONT-03');

    // ── COMPTES MANDANTS ──────────────────────────────────────────────────────────────────
    $comptes = $un('SELECT COUNT(DISTINCT compte) FROM crgi_crg WHERE import_id = ?');
    $connus = $un('SELECT COUNT(DISTINCT compte) FROM crgi_crg
                    WHERE import_id = ? AND mbi_trimestre_id IS NOT NULL');
    $poser('COMPTES MANDANTS', 'INCHANGE', $connus, 'compte',
        'Comptes que MBI rapproche déjà d’une situation connue.', 'phase 1 · inventaire');
    $poser('COMPTES MANDANTS', 'CREER', $comptes - $connus, 'compte',
        'Comptes lus sur les CRG et qu’aucune situation de MBI ne porte encore.',
        'phase 1 · inventaire');
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
        return ['CANDIDAT NON DEMONTRABLE', null,
            'Le libellé « ' . $m['libelle'] . ' » est trop générique pour désigner une '
            . 'écriture : ' . count($cands) . ' candidates dans cette situation. Le '
            . 'rapprochement demande une décision.'];
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
        //    rapprochement forcé qu'on s'interdit.
        return ['CANDIDAT NON DEMONTRABLE', null,
            count($exacts) . ' écritures de MBI portent le même libellé et le même montant '
            . 'dans cette situation. Rien ne dit LAQUELLE correspond : `MÊME MONTANT ≠ MÊME '
            . 'ÉCRITURE`.'];
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

    // ── PHASE 1 : les CRG documentaires ───────────────────────────────────────────────────
    $crg = $q('SELECT COUNT(*) FROM crgi_crg WHERE import_id = ?');
    $inv = $q('SELECT COUNT(*) FROM crgi_crg WHERE import_id = ?
                AND inventaire_statut IS NOT NULL AND inventaire_statut <> ""');
    $reen = $q('SELECT COUNT(*) FROM crgi_crg WHERE import_id = ?
                 AND doublon_statut = "REENONCIATION"');
    $c[] = ['phase' => 1, 'population' => 'CRG documentaires', 'attendue' => $crg,
            'examinee' => $inv, 'exclue' => $reen,
            'motif_exclusion' => 'réénonciations démontrées : le même événement, énoncé deux '
                               . 'fois'];

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
    $comparer(
        'comptes', 'phase 0',
        'SELECT DISTINCT compte FROM crgi_crg WHERE import_id = ? AND compte IS NOT NULL',
        'phase 4',
        'SELECT DISTINCT c.compte FROM crgi_mouvement m JOIN crgi_crg c ON c.id = m.crg_id
          WHERE m.import_id = ? AND c.compte IS NOT NULL'
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
function crgi_arbitrages(PDO $pdo, int $importId): array
{
    $groupes = [];
    $q = function (string $sql) use ($pdo, $importId) {
        $st = $pdo->prepare($sql);
        $st->execute([$importId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };

    // ── PATRIMOINE : plusieurs immeubles MBI portent le même nom ──────────────────────────
    $imm = $q(
        'SELECT MIN(i.id) cible_id, COALESCE(i.code, CONCAT(i.nom, "|", i.code_postal)) cle,
                i.nom, i.code_postal, i.ville, MIN(i.page) page, LEFT(MIN(i.motif), 220) motif,
                c.compte
           FROM crgi_immeuble i JOIN crgi_crg c ON c.id = i.crg_id
          WHERE i.import_id = ? AND i.statut = "A ARBITRER"
          GROUP BY cle, i.nom, i.code_postal, i.ville, c.compte'
    );
    if ($imm) {
        $groupes[] = [
            'groupe'   => 'IMMEUBLE-HOMONYME',
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

