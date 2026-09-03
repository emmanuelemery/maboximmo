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
    $ctx = ['MOUVEMENT' => [], 'IMMEUBLE' => [], 'OCCUPATION' => []];
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
    return $sortie;
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

    if ($a['cible'] === 'OCCUPATION') {
        // ⚠️ LE PRÉCÉDENT OCCUPANT EST UNE PREUVE, PAS UNE SUPPOSITION : il vient de la
        //    période antérieure du même lot, lue sur le document.
        if (!empty($c['precedent'])) {
            $props[] = ['choix' => 'Considérer l’occupant en place', 'confiance' => 65,
                        'raison' => 'La période précédente du même lot porte « '
                                  . $c['precedent'] .' ».'];
        }
        $st = $pdo->prepare(
            'SELECT DISTINCT locataire FROM crgi_mouvement
              WHERE import_id = ? AND lot_reference = ? AND locataire IS NOT NULL
                AND locataire <> "" LIMIT 2'
        );
        $st->execute([$importId, $c['lot_reference'] ?? '']);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $nom) {
            $props[] = ['choix' => 'Considérer l’occupant en place', 'confiance' => 75,
                        'raison' => 'Les mouvements de ce lot nomment « ' . $nom . ' ».'];
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
