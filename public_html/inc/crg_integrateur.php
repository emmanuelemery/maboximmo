<?php
declare(strict_types=1);
/**
 * L'INTÉGRATEUR — DU STAGING SCELLÉ VERS LES TABLES MÉTIER, ET RETOUR.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ C'EST LE MAILLON QUI MANQUAIT. Les phases 0 à 3 lisent, qualifient, scellent — et
 *    s'arrêtent au staging `crgi_*`. Rien n'écrivait dans `tiers`, `immeubles`, `biens`,
 *    `bien_baux`, `locataires_statuts`. Un module qui lit parfaitement et n'intègre rien n'a
 *    pas de valeur ; c'est ce que ce fichier corrige.
 *
 * ⚠️ TOUT EST ANNULABLE, ET C'EST LA PREMIÈRE EXIGENCE, PAS LA DERNIÈRE. Le moteur historique
 *    ne savait revenir que sur `biens` et `immeubles` ; les propriétaires, baux et locataires
 *    qu'il créait restaient. Ici, CHAQUE écriture passe par `crgi_ecrire()`, qui journalise la
 *    table, l'identifiant, l'action et — pour une modification — L'ÉTAT D'AVANT. `crgi_defaire()`
 *    rejoue le journal à l'envers : une création est supprimée, une modification restaurée,
 *    une ligne qu'on n'a pas touchée n'est jamais approchée.
 *
 * ⚠️ SIMULATION PAR DÉFAUT. `$ecrire = false` compte ce qui se passerait sans rien faire. Il
 *    faut le demander explicitement pour écrire — comme le moteur historique, et pour la même
 *    raison : un import lancé par inadvertance ne se voit qu'après.
 *
 * ⚠️ ON N'INTÈGRE QUE CE QUI EST SCELLÉ, ET JAMAIS CE QUI EST À ARBITRER. Un objet
 *    `A ARBITRER` est une question posée : l'écrire serait répondre à la place d'Emmanuel.
 *
 * ⚠️ UNE PERSONNE N'EST JAMAIS DU TEXTE. Propriétaires et locataires deviennent des `tiers`
 *    avec un rôle dans `tiers_roles`. La V1 stocke 879 locataires en chaîne de caractères,
 *    non dédoublonnables, non joignables, impossibles à suivre d'un bien à l'autre. On ne
 *    reproduit pas ça.
 */
require_once __DIR__ . '/crg_integration.php';

/** Compteur des identifiants fictifs de simulation — toujours négatifs. */
$GLOBALS['crgi_sim_seq'] = 0;

/** Les familles intégrées, dans l'ordre où les dépendances l'exigent. */
const CRGI_FAMILLES = ['TIERS', 'IMMEUBLE', 'BIEN', 'BAIL', 'OCCUPATION'];

/**
 * ÉCRIRE UNE LIGNE MÉTIER, EN LA JOURNALISANT — le seul point d'écriture.
 *
 * ⚠️ AUCUNE AUTRE FONCTION DE CE FICHIER NE TOUCHE UNE TABLE MÉTIER. Un import annulable
 *    n'est pas un import « qu'on pense pouvoir annuler » : c'est un import dont TOUTE écriture
 *    passe par un seul endroit qui la note. Dès qu'une écriture s'échappe, l'annulation ment.
 *
 * @param array $valeurs colonnes → valeurs
 * @param ?int  $id      NULL pour créer, sinon l'identifiant à modifier
 * @return ?int l'identifiant écrit, ou null en simulation
 */
function crgi_ecrire(PDO $pdo, array $ctx, string $famille, string $table, array $valeurs,
                     ?int $id, string $cleCrg, string $motif = ''): ?int
{
    $action = $id === null ? 'CREER' : 'MODIFIER';
    $avant = null;
    if ($id !== null) {
        // ⚠️ L'ÉTAT D'AVANT NE SE RELIT PAS APRÈS COUP : on le capture MAINTENANT, et
        //    uniquement sur les colonnes qu'on s'apprête à changer. Journaliser la ligne
        //    entière ferait revenir, à l'annulation, des champs qu'un humain a modifiés
        //    entre-temps.
        $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($valeurs)));
        $st = $pdo->prepare("SELECT $cols FROM `$table` WHERE id = ?");
        $st->execute([$id]);
        $avant = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($avant !== null && crgi_rien_ne_change($avant, $valeurs)) {
            return $id;          // rien à écrire, rien à journaliser
        }
    }
    if (!$ctx['ecrire']) {
        $ctx['compteur'][$famille][$action] = ($ctx['compteur'][$famille][$action] ?? 0) + 1;
        // ⚠️ UNE SIMULATION QUI NE REND PAS D'IDENTIFIANT MENT SUR SES PROPRES CHIFFRES. En
        //    rendant `null` pour une création, la carte des identifiants restait vide : les
        //    baux ne trouvaient plus leur bien et se comptaient « ignorés » par CENTAINES,
        //    et les rôles ne se créaient jamais. On rend donc un identifiant NÉGATIF —
        //    reconnaissable, impossible à confondre avec une vraie ligne, et qui laisse la
        //    chaîne se dérouler jusqu'au bout. Une simulation doit compter ce qu'un import
        //    ferait, pas ce qu'elle-même arrive à faire.
        return $id ?? -(++$GLOBALS['crgi_sim_seq']);
    }

    if ($id !== null && $id < 0) {
        // ⚠️ UN IDENTIFIANT FICTIF NE DOIT JAMAIS ATTEINDRE UNE ÉCRITURE RÉELLE. Il vient
        //    d'une simulation ; le voir ici signifierait qu'on a mélangé les deux modes.
        throw new RuntimeException('IDENTIFIANT DE SIMULATION EN ÉCRITURE RÉELLE : ' . $id
                                 . ' sur ' . $table . '. Les deux modes se sont mélangés.');
    }
    if ($id === null) {
        $cols = implode(',', array_map(fn($c) => "`$c`", array_keys($valeurs)));
        $marq = implode(',', array_fill(0, count($valeurs), '?'));
        $pdo->prepare("INSERT INTO `$table` ($cols) VALUES ($marq)")
            ->execute(array_values($valeurs));
        $id = (int)$pdo->lastInsertId();
    } else {
        $set = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($valeurs)));
        $pdo->prepare("UPDATE `$table` SET $set WHERE id = ?")
            ->execute([...array_values($valeurs), $id]);
    }
    $pdo->prepare(
        'INSERT INTO crgi_journal (import_id, passe, famille, table_cible, id_objet, action,
                                   cle_crg, avant, motif, ecrit_par)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    )->execute([$ctx['import'], $ctx['passe'], $famille, $table, $id, $action,
                mb_substr($cleCrg, 0, 190),
                $avant === null ? null : json_encode($avant, JSON_UNESCAPED_UNICODE),
                mb_substr($motif, 0, 400), $ctx['user'] ?: null]);
    return $id;
}

/** Une modification qui ne modifie rien n'est pas une modification. */
function crgi_rien_ne_change(array $avant, array $apres): bool
{
    foreach ($apres as $k => $v) {
        if ((string)($avant[$k] ?? '') !== (string)($v ?? '')) {
            return false;
        }
    }
    return true;
}

/**
 * DÉFAIRE UN IMPORT — le journal rejoué à l'envers.
 *
 * ⚠️ L'ORDRE INVERSE EST OBLIGATOIRE. Un bail référence un bien qui référence un immeuble :
 *    supprimer l'immeuble d'abord ferait échouer la contrainte, ou pire, laisserait des
 *    orphelins si les clés étrangères ne sont pas déclarées.
 *
 * ⚠️ ON NE SUPPRIME QUE CE QUE CET IMPORT A CRÉÉ. Une ligne `MODIFIER` revient à son état
 *    d'avant ; une ligne qui existait déjà et qu'on n'a pas touchée n'apparaît pas au journal,
 *    donc n'est jamais approchée. C'est ce qui empêche une annulation de détruire du
 *    patrimoine saisi à la main.
 */
function crgi_defaire(PDO $pdo, int $importId, ?int $passe = null, bool $ecrire = false): array
{
    $sql = 'SELECT * FROM crgi_journal WHERE import_id = ? AND annule_le IS NULL'
         . ($passe !== null ? ' AND passe = ' . (int)$passe : '')
         . ' ORDER BY id DESC';
    $st = $pdo->prepare($sql);
    $st->execute([$importId]);
    $stat = ['supprimees' => 0, 'restaurees' => 0, 'echecs' => []];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $j) {
        try {
            if ($j['action'] === 'CREER') {
                if ($ecrire) {
                    $pdo->prepare("DELETE FROM `{$j['table_cible']}` WHERE id = ?")
                        ->execute([(int)$j['id_objet']]);
                }
                $stat['supprimees']++;
            } else {
                $avant = json_decode((string)$j['avant'], true) ?: [];
                if ($avant && $ecrire) {
                    $set = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($avant)));
                    $pdo->prepare("UPDATE `{$j['table_cible']}` SET $set WHERE id = ?")
                        ->execute([...array_values($avant), (int)$j['id_objet']]);
                }
                $stat['restaurees']++;
            }
            if ($ecrire) {
                $pdo->prepare('UPDATE crgi_journal SET annule_le = NOW() WHERE id = ?')
                    ->execute([(int)$j['id']]);
            }
        } catch (Throwable $e) {
            // ⚠️ UN ÉCHEC SE NOMME. Une ligne référencée ailleurs ne se supprime pas : il faut
            //    le dire, pas l'avaler — sinon « annulé » serait faux sur une partie du lot.
            $stat['echecs'][] = $j['table_cible'] . '#' . $j['id_objet'] . ' — ' . $e->getMessage();
        }
    }
    return $stat;
}

/**
 * INTÉGRER UN DÉPÔT. Simulation par défaut.
 *
 * @return array le compte par famille et par action
 */
function crgi_integrer(PDO $pdo, int $importId, bool $ecrire = false, int $userId = 0): array
{
    // ⚠️ ON N'INTÈGRE QUE SUR DU SCELLÉ. Les phases 2 et 3 portent le patrimoine et
    //    l'occupation ; les écrire depuis un staging non validé ferait entrer en base des
    //    objets qui peuvent encore changer.
    foreach ([2, 3] as $ph) {
        $e = crgi_phase_validee($pdo, $importId, $ph);
        if (!$e['validee'] || $e['perimee']) {
            throw new RuntimeException(
                'PHASE ' . $ph . ' NON SCELLÉE — l’intégration ne s’ouvre pas. Écrire depuis un '
                . 'staging qui peut encore changer ferait entrer des objets provisoires.'
            );
        }
    }
    $passe = 1 + (int)$pdo->query(
        'SELECT COALESCE(MAX(passe),0) FROM crgi_journal WHERE import_id = ' . (int)$importId
    )->fetchColumn();

    $ctx = ['import' => $importId, 'passe' => $passe, 'ecrire' => $ecrire, 'user' => $userId,
            'compteur' => []];
    // Les familles s'intègrent dans l'ordre des dépendances — voir CRGI_FAMILLES.
    $ctx['map'] = ['immeuble' => [], 'tiers' => [], 'bien' => []];
    $ctx = crgi_integrer_immeubles($pdo, $ctx);
    $ctx = crgi_integrer_tiers($pdo, $ctx);
    $ctx = crgi_integrer_biens($pdo, $ctx);
    $ctx = crgi_integrer_baux($pdo, $ctx);
    return ['passe' => $passe, 'ecrire' => $ecrire, 'compteur' => $ctx['compteur']];
}

/**
 * LES IMMEUBLES — la première famille, celle dont tout le reste dépend.
 *
 * ⚠️ ON NE CRÉE QUE CE QUE LA PHASE 2 A DÉCLARÉ `NOUVEAU`. `IDENTIQUE` désigne un immeuble
 *    déjà en base : le réécrire ne servirait à rien. `MODIFIE` porte un changement d'adresse
 *    ou de nom, qu'on applique en journalisant l'état d'avant. `A ARBITRER` est une question
 *    posée — l'écrire serait y répondre à la place d'Emmanuel.
 *
 * ⚠️ `adresse_1` EST NOT NULL ET PEUT ÊTRE VIDE. C'est la convention déjà en usage dans la
 *    base : 27 immeubles y portent leur rue dans `nom_immeuble` et une `adresse_1` vide. Le
 *    vide n'est pas un accident, c'est le marqueur d'une adresse à compléter — par les avis
 *    de taxe foncière, qui portent l'adresse exacte et la parcelle.
 */
function crgi_integrer_immeubles(PDO $pdo, array $ctx): array
{
    $st = $pdo->prepare(
        'SELECT m.nom, m.adresse, m.adresse_source, m.code_postal, m.ville,
                MIN(m.code) AS code, MIN(m.statut) AS statut, MIN(m.mbi_immeuble_id) AS mbi_id,
                MIN(c.agence_id) AS id_agence, MIN(m.crg_id) AS crg_id
           FROM crgi_immeuble m JOIN crgi_crg c ON c.id = m.crg_id
          WHERE m.import_id = ?
          GROUP BY m.nom, m.code_postal, m.ville'
    );
    $st->execute([$ctx['import']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i) {
        if ($i['statut'] === 'A ARBITRER') {
            $ctx['compteur']['IMMEUBLE']['IGNORE (a arbitrer)'] =
                ($ctx['compteur']['IMMEUBLE']['IGNORE (a arbitrer)'] ?? 0) + 1;
            continue;
        }
        $cle = 'IMM|' . $i['code'] . '|' . $i['nom'] . '|' . $i['code_postal'];
        $valeurs = [
            'nom_immeuble' => mb_substr((string)$i['nom'], 0, 255),
            'adresse_1'    => mb_substr((string)($i['adresse'] ?? ''), 0, 255),
            'code_postal'  => mb_substr((string)($i['code_postal'] ?? ''), 0, 10),
            'ville'        => mb_substr((string)($i['ville'] ?? ''), 0, 150),
            'code_crg'     => mb_substr((string)($i['code'] ?? ''), 0, 50),
        ];
        if (!empty($i['id_agence'])) {
            $valeurs['id_agence'] = (int)$i['id_agence'];
        }
        $idImm = crgi_ecrire($pdo, $ctx, 'IMMEUBLE', 'immeubles', $valeurs,
                    $i['mbi_id'] ? (int)$i['mbi_id'] : null, $cle,
                    'phase 2 · ' . $i['statut'] . ' · adresse ' . $i['adresse_source']);
        // ⚠️ LA CARTE DES IDENTIFIANTS EST LE LIEN ENTRE LES FAMILLES. Sans elle, un bien
        //    créé juste après ne saurait pas à quel immeuble se rattacher.
        if ($idImm !== null && $i['code'] !== null) {
            $ctx['map']['immeuble'][(string)$i['code']] = (int)$idImm;
        }
        $a = $i['mbi_id'] ? 'MODIFIER' : 'CREER';
        $ctx['compteur']['IMMEUBLE'][$a] = ($ctx['compteur']['IMMEUBLE'][$a] ?? 0) + 1;
    }
    return $ctx;
}

/**
 * LES TIERS — PROPRIÉTAIRES ET LOCATAIRES, UNE PERSONNE UNE FOIS.
 *
 * ⚠️ UNE PERSONNE N'EST JAMAIS DU TEXTE. La V1 stocke **879 locataires en chaîne de
 *    caractères** dans trois tables différentes : non dédoublonnables, non joignables,
 *    impossibles à suivre d'un bien à l'autre. Le modèle `tiers` + `tiers_roles` existe
 *    pourtant — il ne porte que **5 locataires sur 884**. On passe enfin par lui.
 *
 * ⚠️ LA CLÉ EST LE NOM NORMALISÉ, ET ELLE ÉVITE LE DOUBLON À L'ÉCRITURE. La V1 porte
 *    **85 tiers en double** faute de contrainte. Ici, un nom déjà vu rend son identifiant : on
 *    ne crée pas deux fois la même personne dans la même passe, et on retrouve celle qui
 *    existe déjà en base.
 *
 * ⚠️ LE RÔLE EST SÉPARÉ DE LA PERSONNE, et c'est ce qui permet qu'un propriétaire soit
 *    locataire ailleurs — 23 tiers portent déjà deux rôles en V1.
 */
function crgi_integrer_tiers(PDO $pdo, array $ctx): array
{
    // Les propriétaires, tels que le compte rendu les nomme.
    $st = $pdo->prepare(
        'SELECT c.proprietaire AS nom, c.compte, MIN(c.agence_id) AS id_agence, COUNT(*) AS crg
           FROM crgi_crg c
          WHERE c.import_id = ? AND c.proprietaire IS NOT NULL AND c.proprietaire <> ""
          GROUP BY c.proprietaire, c.compte'
    );
    $st->execute([$ctx['import']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $ctx = crgi_poser_tiers($pdo, $ctx, (string)$t['nom'], 'proprietaire',
                                $t['id_agence'] ? (int)$t['id_agence'] : null,
                                'compte mandant ' . $t['compte'] . ' · ' . $t['crg'] . ' CRG');
    }
    // Les locataires, tels que la phase 3 les a lus — en place comme partis.
    $st = $pdo->prepare(
        'SELECT o.locataire AS nom, MIN(c.agence_id) AS id_agence, COUNT(*) AS obs
           FROM crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
          WHERE o.import_id = ? AND o.locataire IS NOT NULL AND o.locataire <> ""
          GROUP BY o.locataire'
    );
    $st->execute([$ctx['import']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $ctx = crgi_poser_tiers($pdo, $ctx, (string)$t['nom'], 'locataire',
                                $t['id_agence'] ? (int)$t['id_agence'] : null,
                                $t['obs'] . ' observation(s) d\'occupation');
    }
    return $ctx;
}

/**
 * POSER UN TIERS ET SON RÔLE, SANS JAMAIS LE CRÉER DEUX FOIS.
 *
 * ⚠️ ON CHERCHE D'ABORD EN BASE, ENSUITE DANS LA PASSE. Un propriétaire déjà saisi ne doit
 *    pas être recréé ; et le même nom rencontré deux fois dans le même import ne doit pas
 *    produire deux lignes. C'est exactement ce qui manque à la V1.
 *
 * ⚠️ UN NOM DE SOCIÉTÉ N'EST PAS UN NOM DE PERSONNE. « SCI FAVRE », « SARL GROUPE SIR »
 *    vont dans `raison_sociale` avec `type_tiers` moral ; le reste dans `nom`.
 */
function crgi_poser_tiers(PDO $pdo, array $ctx, string $nom, string $role,
                          ?int $idAgence, string $motif): array
{
    $plat = crgi_plat($nom);
    if ($plat === '') {
        return $ctx;
    }
    $cle = $role . '|' . $plat;
    if (isset($ctx['map']['tiers'][$plat])) {
        return $ctx;                       // déjà posé dans cette passe
    }
    // ⚠️ LA RECHERCHE EN BASE SE FAIT SUR LE NOM NORMALISÉ, pas sur le texte brut : « SCI
    //    FAVRE » et « Sci Favre » sont la même société.
    // ⚠️ LA COMPARAISON SE FAIT SUR LA MÊME NORMALISATION DES DEUX CÔTÉS, SANS QUOI ELLE NE
    //    MATCHE JAMAIS. Ma première version comparait `crgi_plat()` — majuscules, ponctuation
    //    retirée — à un simple `UPPER(TRIM())` qui garde les points et les apostrophes :
    //    « M. ET MME X » ne rejoignait jamais « M ET MME X », et la simulation annonçait
    //    **1 808 créations de tiers** là où la base en portait déjà beaucoup. Un rapprochement
    //    qui ne rapproche rien ne se voit pas : il crée des doublons en silence.
    //
    // ⚠️ ET ON NE CONCATÈNE PAS raison_sociale AVEC nom. Les deux portent la même valeur
    //    pour une personne morale : les coller donnait « SCI FAVRESCI FAVRE ».
    $st = $pdo->prepare(
        'SELECT id, raison_sociale, nom, prenom FROM tiers
          WHERE nom IS NOT NULL OR raison_sociale IS NOT NULL'
    );
    $st->execute();
    $id = null;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $candidat = crgi_plat(trim((string)($t['raison_sociale'] ?: $t['nom'])
                                   . ' ' . (string)($t['prenom'] ?? '')));
        if ($candidat !== '' && $candidat === $plat) {
            $id = (int)$t['id'];
            break;
        }
    }
    $morale = crgi_est_morale($nom);
    $valeurs = $morale
        ? ['type_tiers' => 'morale', 'raison_sociale' => mb_substr($nom, 0, 190),
           'nom' => mb_substr($nom, 0, 190)]
        : ['type_tiers' => 'physique', 'nom' => mb_substr($nom, 0, 190)];
    $valeurs['source_creation'] = 'CRG';
    if ($idAgence) {
        $valeurs['id_agence'] = $idAgence;
    }
    $idTiers = crgi_ecrire($pdo, $ctx, 'TIERS', 'tiers', $valeurs,
                           $id ? (int)$id : null, $cle, $motif);
    $a = $id ? 'MODIFIER' : 'CREER';
    $ctx['compteur']['TIERS'][$a] = ($ctx['compteur']['TIERS'][$a] ?? 0) + 1;
    if ($idTiers !== null) {
        $ctx['map']['tiers'][$plat] = (int)$idTiers;
        // Le rôle, séparément : une personne peut être propriétaire ici et locataire ailleurs.
        $d = $pdo->prepare('SELECT id FROM tiers_roles
                             WHERE id_tiers = ? AND role_code = ? LIMIT 1');
        $d->execute([(int)$idTiers, $role]);
        $idRole = $d->fetchColumn();
        if (!$idRole) {
            crgi_ecrire($pdo, $ctx, 'ROLE', 'tiers_roles',
                        ['id_tiers' => (int)$idTiers, 'role_code' => $role, 'actif' => 1],
                        null, $cle, $motif);
            $ctx['compteur']['ROLE']['CREER'] = ($ctx['compteur']['ROLE']['CREER'] ?? 0) + 1;
        }
    }
    return $ctx;
}

/** Une raison sociale se reconnaît à sa forme juridique, jamais à une majuscule. */
function crgi_est_morale(string $nom): bool
{
    return (bool)preg_match(
        '/\b(SCI|SARL|SAS|SASU|SA|SNC|SCP|SCM|EURL|SCCV|GIE|ASSOCIATION|SYNDICAT|INDIVISION|'
        . 'GROUPE|STE|SOCIETE|COMMUNE|MAIRIE|OFFICE|HOLDING)\b/u', crgi_plat($nom));
}

/**
 * LES BIENS — UN LOT, DANS SON IMMEUBLE.
 *
 * ⚠️ `id_type_bien` EST NOT NULL, ET NOS CATÉGORIES CORRESPONDENT DÉJÀ À `types_bien` :
 *    Maison 1 · Appartement 2 · Terrain 3 · Local commercial 5 · Bureau 6 · Garage 9. Le
 *    référentiel de type qu'on a construit en phase 2 s'y branche sans traduction inventée.
 *
 * ⚠️ UN LOT SANS IMMEUBLE N'EST PAS RATTACHÉ AU HASARD. 136 lots n'ont pas de code
 *    d'immeuble — leur référence courte ne le porte pas. Ils entrent avec `id_immeuble` NULL,
 *    et le rapprochement se fera avec les taxes foncières et les propriétaires.
 */
const CRGI_TYPE_VERS_MBI = [
    'MAISON' => 1, 'APPARTEMENT' => 2, 'TERRAIN' => 3, 'LOCAL COMMERCIAL' => 5,
    'BUREAU' => 6, 'GARAGE' => 9, 'PANNEAU' => 3, 'PARTIES COMMUNES' => 4,
    'HABITATION' => 2, 'INDETERMINE' => 2,
];

function crgi_integrer_biens(PDO $pdo, array $ctx): array
{
    $st = $pdo->prepare(
        'SELECT l.reference, MIN(l.code_immeuble) AS code_immeuble, MIN(l.numero) AS numero,
                MIN(l.libelle) AS libelle, MIN(l.type_bien) AS type_bien, MAX(l.vendu) AS vendu,
                MIN(l.statut) AS statut, MIN(l.mbi_bien_id) AS mbi_id,
                MIN(c.agence_id) AS id_agence, c.compte
           FROM crgi_lot l JOIN crgi_crg c ON c.id = l.crg_id
          WHERE l.import_id = ?
          GROUP BY c.compte, l.reference'
    );
    $st->execute([$ctx['import']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        if ($l['statut'] === 'A ARBITRER') {
            $ctx['compteur']['BIEN']['IGNORE (a arbitrer)'] =
                ($ctx['compteur']['BIEN']['IGNORE (a arbitrer)'] ?? 0) + 1;
            continue;
        }
        $cle = 'LOT|' . $l['compte'] . '|' . $l['reference'];
        $valeurs = [
            'reference_bien' => mb_substr((string)$l['reference'], 0, 50),
            'numero_lot'     => mb_substr((string)($l['numero'] ?? ''), 0, 20),
            'designation'    => mb_substr((string)($l['libelle'] ?? ''), 0, 190),
            'id_type_bien'   => CRGI_TYPE_VERS_MBI[(string)$l['type_bien']] ?? 2,
            'code_crg'       => mb_substr((string)$l['reference'], 0, 50),
        ];
        if (!empty($l['id_agence'])) {
            $valeurs['id_agence'] = (int)$l['id_agence'];
        }
        $idImm = $ctx['map']['immeuble'][(string)$l['code_immeuble']] ?? null;
        if ($idImm) {
            $valeurs['id_immeuble'] = $idImm;
        }
        $idBien = crgi_ecrire($pdo, $ctx, 'BIEN', 'biens', $valeurs,
                              $l['mbi_id'] ? (int)$l['mbi_id'] : null, $cle,
                              'phase 2 · ' . $l['statut'] . ' · ' . $l['type_bien']
                              . ($l['vendu'] ? ' · VENDU (lu au document)' : ''));
        if ($idBien !== null) {
            $ctx['map']['bien'][$l['compte'] . '|' . $l['reference']] = (int)$idBien;
        }
        $a = $l['mbi_id'] ? 'MODIFIER' : 'CREER';
        $ctx['compteur']['BIEN'][$a] = ($ctx['compteur']['BIEN'][$a] ?? 0) + 1;
    }
    return $ctx;
}

/**
 * LES BAUX ET LES OCCUPATIONS — QUI OCCUPE QUOI, ET DEPUIS QUAND.
 *
 * ⚠️ UN BAIL EST UN COUPLE (LOT, LOCATAIRE), PAS UNE LIGNE PAR TRIMESTRE. Le compte rendu
 *    réimprime la même occupation à chaque période ; en faire autant de baux multiplierait
 *    tout par le nombre d'arrêtés. C'est la faute que j'ai commise trois fois sur les
 *    comptages — elle ne doit pas se propager à l'écriture.
 *
 * ⚠️ LE STATUT VIENT DE LA PHASE 3, ET IL DISTINGUE TROIS ÉTATS que la V1 confond : en
 *    place, parti, et **parti débiteur** — celui dont la dette suit et qui fait rééditer le
 *    compte rendu trimestre après trimestre.
 */
function crgi_integrer_baux(PDO $pdo, array $ctx): array
{
    $st = $pdo->prepare(
        'SELECT c.compte, o.lot_reference, o.locataire,
                MIN(o.bail_du) AS bail_du, MAX(o.bail_au) AS bail_au,
                MAX(o.date_arrete) AS dernier_arrete, MAX(o.solde) AS solde,
                SUBSTRING_INDEX(GROUP_CONCAT(o.statut ORDER BY o.date_arrete DESC), ",", 1) AS statut
           FROM crgi_occupation o JOIN crgi_crg c ON c.id = o.crg_id
          WHERE o.import_id = ? AND o.locataire IS NOT NULL AND o.locataire <> ""
          GROUP BY c.compte, o.lot_reference, o.locataire'
    );
    $st->execute([$ctx['import']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $idBien = $ctx['map']['bien'][$b['compte'] . '|' . $b['lot_reference']] ?? null;
        if (!$idBien) {
            // ⚠️ PAS DE BAIL SANS BIEN. Un bail orphelin ne se rattache à rien et ne se
            //    retrouve jamais : on le compte et on le dit, on ne l'écrit pas.
            $ctx['compteur']['BAIL']['IGNORE (bien non integre)'] =
                ($ctx['compteur']['BAIL']['IGNORE (bien non integre)'] ?? 0) + 1;
            continue;
        }
        $idTiers = $ctx['map']['tiers'][crgi_plat((string)$b['locataire'])] ?? null;
        $enPlace = in_array((string)$b['statut'],
                            ['IDENTIQUE', 'CHANGEMENT DE LOCATAIRE', 'NOUVEL ENTRANT'], true);
        $cle = 'BAIL|' . $b['compte'] . '|' . $b['lot_reference'] . '|'
             . crgi_plat((string)$b['locataire']);

        $d = $pdo->prepare('SELECT id FROM bien_baux WHERE id_bien = ?
                             AND UPPER(TRIM(locataire_nom)) = ? LIMIT 1');
        $d->execute([$idBien, crgi_plat((string)$b['locataire'])]);
        $idBail = $d->fetchColumn();
        $valeurs = [
            'id_bien'          => $idBien,
            'locataire_nom'    => mb_substr((string)$b['locataire'], 0, 190),
            'date_prise_effet' => $b['bail_du'] ?: null,
            'date_fin'         => $b['bail_au'] ?: null,
            'statut'           => $enPlace ? 'actif' : 'termine',
            'source_crg_id'    => (int)$ctx['import'],
        ];
        crgi_ecrire($pdo, $ctx, 'BAIL', 'bien_baux', $valeurs,
                    $idBail ? (int)$idBail : null, $cle,
                    'phase 3 · ' . $b['statut'] . ' · dernier arrêté ' . $b['dernier_arrete']);
        $a = $idBail ? 'MODIFIER' : 'CREER';
        $ctx['compteur']['BAIL'][$a] = ($ctx['compteur']['BAIL'][$a] ?? 0) + 1;

        // L'état d'occupation, avec sa créance quand le document la porte.
        $d = $pdo->prepare('SELECT id FROM locataires_statuts WHERE id_bien = ?
                             AND UPPER(TRIM(locataire_nom)) = ? LIMIT 1');
        $d->execute([$idBien, crgi_plat((string)$b['locataire'])]);
        $idOcc = $d->fetchColumn();
        $statut = $enPlace ? 'actif'
                : ((string)$b['statut'] === 'ANCIEN LOCATAIRE AVEC DETTE' ? 'parti_debiteur' : 'parti');
        crgi_ecrire($pdo, $ctx, 'OCCUPATION', 'locataires_statuts',
                    ['id_bien' => $idBien,
                     'locataire_nom' => mb_substr((string)$b['locataire'], 0, 190),
                     'statut' => $statut,
                     'montant_creance' => $enPlace ? null : ($b['solde'] ?: null),
                     'id_proprietaire' => 0],
                    $idOcc ? (int)$idOcc : null, $cle, 'phase 3 · ' . $b['statut']);
        $a = $idOcc ? 'MODIFIER' : 'CREER';
        $ctx['compteur']['OCCUPATION'][$a] = ($ctx['compteur']['OCCUPATION'][$a] ?? 0) + 1;
    }
    return $ctx;
}
