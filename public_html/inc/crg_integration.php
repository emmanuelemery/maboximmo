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

    $bilan = ['pieces' => 0, 'pages' => 0, 'affectees' => 0, 'crgs' => 0, 'illisibles' => []];
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
    }
    crgi_recompter($pdo, $importId);

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
    crgi_marquer_phase($pdo, $importId, 0, 'A VALIDER', null);
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
    $st = $pdo->prepare(
        'SELECT piece_id, page_debut, page_fin, COALESCE(agence,""), COALESCE(compte,""),
                COALESCE(periode_cle,""), certitude
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
 * ANNULER L'IMPORT — la promesse de réversibilité, tenue.
 *
 * ⚠️ ON NE SUPPRIME PAS LA LIGNE D'IMPORT. Elle passe à `ANNULE` avec sa date, son auteur et
 *    son motif : savoir qu'un dépôt a eu lieu et qu'il a été abandonné vaut mieux que de faire
 *    disparaître la trace. Ce sont les DONNÉES d'analyse qui partent, et les fichiers déposés.
 *
 * ⚠️ ET UN IMPORT DÉJÀ INTÉGRÉ NE S'ANNULE PAS ICI. À ce stade des écritures métier existent :
 *    les défaire est une autre opération, qui ne se déclenche pas d'un bouton de la même page.
 */
function crgi_annuler(PDO $pdo, int $importId, int $userId, string $motif): void
{
    $st = $pdo->prepare('SELECT statut FROM crgi_import WHERE id = ?');
    $st->execute([$importId]);
    $statut = (string)$st->fetchColumn();
    if ($statut === 'INTEGRE') {
        throw new RuntimeException("IMPORT DÉJÀ INTÉGRÉ — l'annulation ne se fait pas ici.");
    }
    $pdo->prepare('DELETE FROM crgi_page WHERE import_id = ?')->execute([$importId]);
    $pdo->prepare('DELETE FROM crgi_crg  WHERE import_id = ?')->execute([$importId]);
    $pdo->prepare('DELETE FROM crgi_phase WHERE import_id = ?')->execute([$importId]);

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
    $pdo->prepare('DELETE FROM crgi_piece WHERE import_id = ?')->execute([$importId]);
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
    $bilan = ['A' => 0, 'B' => 0, 'C' => 0];
    foreach ($paires as $p) {
        $cmd = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' '
             . escapeshellarg((string)$p['chemin']) . ' '
             . (int)$p['page_debut'] . ' ' . (int)$p['page_fin'] . ' '
             . (int)$p['rd'] . ' ' . (int)$p['rf'];
        $sortie = @shell_exec($cmd . ' 2>&1');
        $r = json_decode(trim((string)$sortie), true);
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
        'SELECT id, compte, periode_debut, periode_fin, date_arrete, periode_cle
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
function crgi_empreinte_phase2(PDO $pdo, int $importId): string
{
    $l = [];
    foreach (['crgi_immeuble' => 'mbi_immeuble_id', 'crgi_lot' => 'mbi_bien_id'] as $table => $col) {
        $st = $pdo->prepare("SELECT id, statut, COALESCE(`{$col}`, 0)
                               FROM `{$table}` WHERE import_id = ? ORDER BY id");
        $st->execute([$importId]);
        foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) {
            $l[] = $table . '|' . implode('|', $r);
        }
    }
    $st = $pdo->prepare('SELECT id, COALESCE(compte_qualification, ""),
                                COALESCE(mbi_proprietaire_id, 0)
                           FROM crgi_crg WHERE import_id = ? ORDER BY id');
    $st->execute([$importId]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) {
        $l[] = 'compte|' . implode('|', $r);
    }
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
