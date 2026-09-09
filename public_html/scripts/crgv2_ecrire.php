<?php
declare(strict_types=1);
/**
 * crgv2_ecrire.php — LE PLAN DEVIENT DES LIGNES, ET RESTE ANNULABLE.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ IL NE DÉCIDE DE RIEN. Toute la lecture métier est faite par `crgv2_plan.php`. Ici on
 *    résout des clés temporaires en identifiants réels, on insère, et on note. Un fichier
 *    qui écrit ET qui interprète est un fichier dont on ne peut plus prouver ni l'un ni
 *    l'autre.
 *
 * ⚠️ UN IMPORT N'EST ANNULABLE QUE SI TOUTE ÉCRITURE PASSE PAR UN SEUL ENDROIT QUI LA NOTE.
 *    `ecrire()` est cet endroit. Le moteur historique de la V1 ne savait revenir que sur
 *    DEUX tables : propriétaires, mandats, baux et statuts qu'il créait restaient après une
 *    annulation « réussie ». Dès qu'une écriture s'échappe — une table oubliée, un INSERT
 *    direct « juste pour cette fois » — l'annulation ment, et elle ment silencieusement.
 *
 * ⚠️ ET LA SIMULATION REND DES IDENTIFIANTS. Une simulation qui ne rend rien pour une
 *    création laisse la carte des liens vide : les occupations ne trouvent plus leur bien
 *    et se comptent « ignorées » par centaines. Elle rend donc un identifiant NÉGATIF,
 *    reconnaissable, et une garde refuse qu'il atteigne une écriture réelle.
 *
 * Usage (dans le conteneur `mbi-web`, où vivent les identifiants de la base) :
 *   php crgv2_ecrire.php --etat
 *   php crgv2_ecrire.php plan_1.json                  simulation (défaut)
 *   php crgv2_ecrire.php plan_1.json --pour-de-vrai
 *   php crgv2_ecrire.php --defaire 3 --pour-de-vrai
 */

require_once __DIR__ . '/crgv2_norm.php';

/** L'auteur des lignes. `created_by` a une clé étrangère : ce doit être un utilisateur réel. */
const AUTEUR = 113;

$GLOBALS['sim_seq'] = 0;
$GLOBALS['compte']  = [];
$GLOBALS['erreurs'] = [];

function pdo(): PDO
{
    static $p = null;
    if ($p === null) {
        $p = new PDO('mysql:host=' . getenv('MBI_DB_HOST') . ';dbname=' . getenv('MBI_DB_NAME')
                     . ';charset=utf8mb4', getenv('MBI_DB_USER'), getenv('MBI_DB_PASSWORD'),
                     [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
    return $p;
}

// ═══════════════════════════════════════════════════════════════════════════════════════════
//  LE POINT D'ÉCRITURE UNIQUE
// ═══════════════════════════════════════════════════════════════════════════════════════════

/**
 * Crée ou modifie une ligne, et la note.
 *
 * @param array $valeurs colonne => valeur. En modification, SEULES ces colonnes sont
 *                       touchées — et seules elles sont journalisées : restaurer la ligne
 *                       entière ferait revenir, à l'annulation, des champs qu'un humain a
 *                       changés depuis.
 * @param ?int  $id      null pour créer, sinon la ligne à modifier.
 * @return ?int          l'identifiant réel, ou un identifiant NÉGATIF en simulation.
 */
function ecrire(array $ctx, string $famille, string $table, array $valeurs, ?int $id = null): ?int
{
    $action = $id === null ? 'CREER' : 'MODIFIER';

    if ($id !== null && $id < 0) {
        // ⚠️ UN IDENTIFIANT FICTIF NE DOIT JAMAIS ATTEINDRE UNE ÉCRITURE RÉELLE. Le voir en
        //    écriture signifierait que les deux modes se sont mélangés.
        if ($ctx['ecrire']) {
            throw new RuntimeException("IDENTIFIANT DE SIMULATION EN ÉCRITURE : $id sur $table.");
        }
        // ⚠️ EN SIMULATION, C'EST AU CONTRAIRE NORMAL ET ATTENDU : l'objet vient d'être
        //    « créé » quelques lignes plus haut, et on le retrouve. La création est déjà
        //    comptée — la recompter en modification gonflerait le chiffre annoncé d'une
        //    écriture qui n'existe pas. Une simulation doit compter ce qu'un import ferait.
        return $id;
    }

    if ($action === 'MODIFIER') {
        // On ne journalise, et on n'écrit, que ce qui CHANGE vraiment.
        $cols  = array_keys($valeurs);
        $st    = pdo()->prepare('SELECT `' . implode('`,`', $cols) . "` FROM `$table` WHERE id = ?");
        $st->execute([$id]);
        $avant = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $delta = [];
        foreach ($valeurs as $c => $v) {
            if ((string)($avant[$c] ?? '') !== (string)($v ?? '')) $delta[$c] = $v;
        }
        if (!$delta) return $id;
        $valeurs = $delta;
        $avant   = array_intersect_key($avant, $delta);
    }

    $GLOBALS['compte'][$famille][$action] = ($GLOBALS['compte'][$famille][$action] ?? 0) + 1;

    if (!$ctx['ecrire']) {
        return $id ?? -(++$GLOBALS['sim_seq']);
    }

    if ($action === 'CREER') {
        $cols = '`' . implode('`,`', array_keys($valeurs)) . '`';
        $marq = implode(',', array_fill(0, count($valeurs), '?'));
        $st   = pdo()->prepare("INSERT INTO `$table` ($cols) VALUES ($marq)");
        $st->execute(array_values($valeurs));
        $id   = (int)pdo()->lastInsertId();
        $avant = null;
    } else {
        $set = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($valeurs)));
        $st  = pdo()->prepare("UPDATE `$table` SET $set WHERE id = ?");
        $st->execute([...array_values($valeurs), $id]);
        $avant = json_encode($avant, JSON_UNESCAPED_UNICODE);
    }

    pdo()->prepare('INSERT INTO imports_journal (id_import, table_cible, id_ligne, action, avant)
                    VALUES (?,?,?,?,?)')
         ->execute([$ctx['import'], $table, $id, $action, $avant]);

    return $id;
}

// ═══════════════════════════════════════════════════════════════════════════════════════════
//  CE QUE LA BASE PORTE DÉJÀ — chargé une fois, comparé avec la MÊME normalisation
// ═══════════════════════════════════════════════════════════════════════════════════════════

function chargerExistant(): array
{
    $x = ['tiers' => [], 'codes' => [], 'principaux' => [], 'immeubles' => [],
          'occupations' => [], 'mandats' => [], 'roles' => [], 'baux' => [],
          'adresses' => [], 'liens_adresse' => []];

    foreach (pdo()->query('SELECT objet_type, id_objet, role_code FROM objet_adresses
                            WHERE date_fin IS NULL') as $r) {
        $x['liens_adresse'][$r['objet_type'] . ':' . $r['id_objet'] . ':' . $r['role_code']] = true;
    }
    foreach (pdo()->query('SELECT id, id_occupation FROM baux WHERE id_occupation IS NOT NULL') as $r) {
        $x['baux'][(int)$r['id_occupation']] = (int)$r['id'];
    }

    foreach (pdo()->query('SELECT id, type, denomination, nom, prenoms FROM tiers') as $r) {
        $k = cleTiers($r['type'], $r['denomination'], $r['nom'], $r['prenoms']);
        if ($k !== '') $x['tiers'][$k] ??= (int)$r['id'];
    }
    foreach (pdo()->query('SELECT objet_type, id_agence, systeme, code, id_objet, principal, date_fin
                             FROM objet_codes') as $r) {
        $x['codes'][$r['objet_type'] . '|' . $r['id_agence'] . '|' . $r['systeme'] . '|' . $r['code']]
            = (int)$r['id_objet'];
        if ((int)$r['principal'] === 1 && $r['date_fin'] === null) {
            $x['principaux'][$r['objet_type'] . '|' . $r['id_objet'] . '|' . $r['systeme']] = true;
        }
    }
    // ⚠️ L'ADRESSE SE LIT DANS LE RÉFÉRENTIEL, PAS DANS LA COLONNE. Depuis la migration 0023
    //    `immeubles.adresse_1` est vidée : chercher un immeuble par son adresse en
    //    interrogeant cette colonne ne trouverait plus rien, et l'import recréerait chaque
    //    bâtiment sans code à chaque dépôt.
    foreach (pdo()->query(
        "SELECT i.id, i.id_agence, i.nom, a.ligne_1, a.code_postal, a.commune
           FROM immeubles i
           LEFT JOIN objet_adresses oa ON oa.objet_type = 'IMMEUBLE' AND oa.id_objet = i.id
                                      AND oa.date_fin IS NULL
           LEFT JOIN adresses a ON a.id = oa.id_adresse") as $r) {
        $x['immeubles'][cleImmeubleSansCode((int)$r['id_agence'], $r['ligne_1'], $r['code_postal'],
                                            $r['commune'], $r['nom'])] ??= (int)$r['id'];
    }
    foreach (pdo()->query('SELECT o.id, o.id_bien, oo.nom_lu FROM occupations o
                             JOIN occupation_occupants oo ON oo.id_occupation = o.id') as $r) {
        $x['occupations'][$r['id_bien'] . '#' . plat($r['nom_lu'])] ??= (int)$r['id'];
    }
    foreach (pdo()->query('SELECT id, id_bien, type_code, date_debut FROM mandats') as $r) {
        $x['mandats'][$r['id_bien'] . '|' . $r['type_code'] . '|' . $r['date_debut']] ??= (int)$r['id'];
    }
    foreach (pdo()->query("SELECT id, id_tiers, role_code, objet_type, id_objet FROM tiers_roles
                            WHERE date_fin IS NULL") as $r) {
        $x['roles'][$r['id_tiers'] . '|' . $r['role_code'] . '|'
                  . ($r['objet_type'] ?? '') . ':' . ($r['id_objet'] ?? 0)] ??= (int)$r['id'];
    }
    return $x;
}

/**
 * Rattache une adresse à un objet — en la CRÉANT une seule fois pour tout le monde.
 *
 * ⚠️ UNE ADRESSE EST LIÉE PAR LES TABLES, JAMAIS RECOPIÉE. Emmanuel, 09/09/2026. La même
 *    notion était modélisée quatre fois — `tiers.adr_ligne_voie`, `immeubles.adresse_1`,
 *    `agences.adresse_1`, `societes.adresse_1` — et « 76 RUE DE VERDUN, 69100 VILLEURBANNE »
 *    s'écrivait **19 fois**. La corriger demandait dix-neuf corrections et dix-huit oublis
 *    possibles.
 *
 * ⚠️ LA CLÉ D'UNICITÉ EST CALCULÉE PAR LA BASE, DES DEUX CÔTÉS DE LA COMPARAISON. On ne la
 *    recalcule pas en PHP : c'est exactement ainsi qu'un rapprochement se met à diverger en
 *    silence. La recherche demande donc à MariaDB d'appliquer À MA VALEUR la même expression
 *    que celle de la colonne générée — une seule autorité, aucune divergence possible.
 */
function poserAdresse(array $ctx, array &$x, string $objetType, ?int $idObjet,
                      array $a, string $role = 'principale'): void
{
    if (!$idObjet || $idObjet < 0) return;
    $v = ['ligne_1' => trim((string)($a['ligne_1'] ?? '')), 'ligne_2' => $a['ligne_2'] ?? null,
          'code_postal' => $a['code_postal'] ?? null, 'commune' => trim((string)($a['commune'] ?? '')),
          'pays' => trim((string)($a['pays'] ?? '')) ?: 'FRANCE'];
    // ⚠️ `ligne_1` ET `commune` SONT NOT NULL : une adresse sans rue ou sans ville n'est pas
    //    une demi-adresse, c'est une adresse sur laquelle on ne poste pas.
    if ($v['ligne_1'] === '' || $v['commune'] === '') return;

    $cle = 'A:' . mb_strtolower(implode('|', $v));
    $idAdresse = $x['adresses'][$cle] ?? null;

    if ($idAdresse === null) {
        $st = pdo()->prepare(
            "SELECT id FROM adresses
              WHERE cle = REGEXP_REPLACE(CONCAT_WS('|', ?, COALESCE(?,''), COALESCE(?,''), ?, ?),
                                         '[^[:alnum:]|]+', '') COLLATE utf8mb4_unicode_ci
              LIMIT 1");
        $st->execute(array_values($v));
        $idAdresse = $st->fetchColumn();
        $idAdresse = $idAdresse === false ? null : (int)$idAdresse;
    }
    if ($idAdresse === null) {
        $idAdresse = ecrire($ctx, 'ADRESSE', 'adresses', $v + ['created_by' => AUTEUR]);
    }
    $x['adresses'][$cle] = $idAdresse;
    if ($idAdresse === null || $idAdresse < 0) return;

    $lien = $objetType . ':' . $idObjet . ':' . $role;
    if (isset($x['liens_adresse'][$lien])) return;
    ecrire($ctx, 'LIEN ADRESSE', 'objet_adresses', [
        'objet_type' => $objetType, 'id_objet' => $idObjet, 'id_adresse' => $idAdresse,
        'role_code' => $role, 'created_by' => AUTEUR,
    ]);
    $x['liens_adresse'][$lien] = true;
}

/**
 * Frappe le code d'un objet, si ce couple (agence, système, code) n'est pas déjà pris.
 *
 * ⚠️ UN SEUL CODE PRINCIPAL PAR OBJET ET PAR RÉFÉRENTIEL — la migration 0021 pose un index
 *    UNIQUE dessus, et il a refusé trois de mes quatre dépôts. La cause n'était pas l'index :
 *    c'était moi. Deux groupes du plan se rejoignent parfois sur le MÊME immeuble — repliés
 *    par leur adresse après coup — et chacun posait son premier code en « principal ».
 *    Le rang dans une liste ne dit rien de ce que porte déjà la base : on le lui demande.
 */
function poserCode(array $ctx, array &$x, string $type, int $idObjet, int $agence,
                   string $systeme, string $code, bool $principal, string $source = 'CRG'): void
{
    if ($idObjet < 0 && $ctx['ecrire']) return;
    $k = "$type|$agence|$systeme|$code";
    if (isset($x['codes'][$k])) return;                 // le code désigne déjà un objet

    $kp = "$type|$idObjet|$systeme";
    if ($principal && isset($x['principaux'][$kp])) $principal = false;

    ecrire($ctx, 'CODE', 'objet_codes', [
        'objet_type' => $type, 'id_objet' => $idObjet, 'id_agence' => $agence,
        'systeme' => $systeme, 'code' => $code, 'principal' => $principal ? 1 : 0,
        'source' => $source, 'created_by' => AUTEUR,
    ]);
    $x['codes'][$k] = $idObjet;
    if ($principal) $x['principaux'][$kp] = true;
}

// ═══════════════════════════════════════════════════════════════════════════════════════════
//  L'IMPORT D'UN PLAN
// ═══════════════════════════════════════════════════════════════════════════════════════════

function importer(array $plan, bool $pourDeVrai): int
{
    $x   = chargerExistant();
    $ctx = ['ecrire' => $pourDeVrai, 'import' => 0];

    if ($pourDeVrai) {
        pdo()->prepare('INSERT INTO imports (source, libelle, id_agence, nb_documents,
                                             periode_debut, periode_fin, fait_par)
                        VALUES (?,?,?,?,?,?,?)')
             ->execute([$plan['source'], $plan['libelle'], $plan['id_agence'], $plan['nb_documents'],
                        $plan['periode_debut'], $plan['periode_fin'], AUTEUR]);
        $ctx['import'] = (int)pdo()->lastInsertId();
    }

    // ── LES TIERS ───────────────────────────────────────────────────────────────────────
    $idTiers = [];
    foreach ($plan['tiers'] as $t) {
        $k = $t['k'];
        // ⚠️ L'ADRESSE EST TOUT-OU-RIEN : `chk_tiers_adresse` exige voie + code postal +
        //    commune + pays ensemble, ou les quatre à NULL. Une adresse à moitié remplie
        //    n'est pas une demi-information — on ne poste pas dessus.
        $adr = $t['adresse'] ?? null;
        if (isset($x['tiers'][$k])) {
            $idTiers[$k] = $x['tiers'][$k];
        } else {
            // ⚠️ `chk_tiers_forme` : MORALE porte `denomination` SEULE, PHYSIQUE porte `nom`
            //    et jamais `denomination`. Une identité mal typée est REFUSÉE, pas avalée.
            $v = $t['type'] === 'MORALE'
               ? ['type' => 'MORALE', 'denomination' => $t['denomination'], 'nom' => null, 'prenoms' => null]
               : ['type' => 'PHYSIQUE', 'denomination' => null, 'nom' => $t['nom'], 'prenoms' => $t['prenoms']];
            $idTiers[$k] = $x['tiers'][$k] = ecrire($ctx, 'TIERS', 'tiers', $v + ['created_by' => AUTEUR]);
        }
        // ⚠️ L'ADRESSE NE VA PLUS DANS `tiers.adr_*` : elle va au référentiel, et le tiers
        //    n'en porte qu'un LIEN. C'est la règle d'Emmanuel — « que des liens ».
        if ($adr) {
            poserAdresse($ctx, $x, 'TIERS', $idTiers[$k], [
                'ligne_1' => $adr['adr_ligne_voie'], 'code_postal' => $adr['adr_code_postal'],
                'commune' => $adr['adr_commune'], 'pays' => $adr['adr_pays'],
            ]);
        }
    }

    // ── LES COMPTES MANDANTS ────────────────────────────────────────────────────────────
    $idMandant = [];
    foreach ($plan['mandants'] as $m) {
        $ag  = (int)$m['id_agence'];
        $cle = 'MANDANT|' . $ag . '|' . $m['systeme'] . '|' . $m['code'];
        $it  = $m['tiers_k'] ? ($idTiers[$m['tiers_k']] ?? null) : null;
        $id  = $x['codes'][$cle] ?? null;
        if ($id !== null) {
            ecrire($ctx, 'MANDANT', 'mandants',
                   ['libelle' => $m['libelle'], 'id_tiers' => $it], $id);
        } else {
            $id = ecrire($ctx, 'MANDANT', 'mandants', [
                'id_agence' => $ag, 'id_tiers' => $it, 'libelle' => $m['libelle'],
                'created_by' => AUTEUR,
            ]);
            poserCode($ctx, $x, 'MANDANT', (int)$id, $ag, $m['systeme'], $m['code'], true);
        }
        $idMandant[$m['k']] = $id;
    }

    // ── LES IMMEUBLES ───────────────────────────────────────────────────────────────────
    $idImmeuble = [];
    foreach ($plan['immeubles'] as $i) {
        $ag = (int)$i['id_agence'];
        $id = null;
        foreach ($i['codes'] as $c) {
            $id = $x['codes']['IMMEUBLE|' . $ag . '|' . $c['systeme'] . '|' . $c['code']] ?? null;
            if ($id !== null) break;
        }
        // Sans code connu, on se rabat sur l'adresse — la même clé des deux côtés.
        $cleSans = cleImmeubleSansCode($ag, $i['adresse_1'], $i['code_postal'], $i['ville'], $i['nom']);
        if ($id === null) $id = $x['immeubles'][$cleSans] ?? null;

        // ⚠️ PLUS D'ADRESSE EN COLONNE : seul le nom reste sur l'immeuble. Sa situation est
        //    une adresse du référentiel, rattachée au titre `situation`.
        if ($id !== null) {
            ecrire($ctx, 'IMMEUBLE', 'immeubles', ['nom' => $i['nom']], $id);
        } else {
            $id = ecrire($ctx, 'IMMEUBLE', 'immeubles',
                         ['id_agence' => $ag, 'nom' => $i['nom'], 'created_by' => AUTEUR]);
            $x['immeubles'][$cleSans] = $id;
        }
        $idImmeuble[$i['k']] = $id;
        if ($i['adresse_1']) {
            poserAdresse($ctx, $x, 'IMMEUBLE', (int)$id, [
                'ligne_1' => $i['adresse_1'], 'ligne_2' => $i['adresse_2'],
                'code_postal' => $i['code_postal'], 'commune' => $i['ville'] ?: '(commune inconnue)',
            ], 'situation');
        }

        foreach ($i['codes'] as $n => $c) {
            poserCode($ctx, $x, 'IMMEUBLE', (int)$id, $ag, $c['systeme'], $c['code'], $n === 0);
        }
        // ⚠️ UN IMMEUBLE SANS CODE D'ÉDITEUR REÇOIT LE NÔTRE. La migration 0017 prévoit le
        //    système `mbi` exactement pour ça : « frappé par nous, faute de code ». Sans lui,
        //    ces 49 bâtiments seraient introuvables par identifiant.
        if (!$i['codes'] && $id !== null && $id > 0) {
            poserCode($ctx, $x, 'IMMEUBLE', (int)$id, $ag, 'mbi', 'IMM' . $id, true, 'MBI');
        }
    }

    // ── LES BIENS ───────────────────────────────────────────────────────────────────────
    $idBien = [];
    foreach ($plan['biens'] as $b) {
        $ag = (int)$b['id_agence'];
        $id = null;
        foreach ($b['codes'] as $c) {
            $id = $x['codes']['BIEN|' . $ag . '|' . $c['systeme'] . '|' . $c['code']] ?? null;
            if ($id !== null) break;
        }
        $v = ['id_immeuble' => $b['immeuble_k'] ? ($idImmeuble[$b['immeuble_k']] ?? null) : null,
              'id_mandant'  => $b['mandant_k']  ? ($idMandant[$b['mandant_k']]  ?? null) : null,
              'designation' => $b['designation'], 'nature_code' => $b['nature_code'],
              // ⚠️ `date_fin` DIT « CE BIEN N'EST PLUS GÉRÉ », et seul le document le dit :
              //    on ne la pose que sur un lot que le CRG déclare VENDU.
              'date_fin'    => $b['vendu'] ? $b['fin'] : null];
        if ($id !== null) {
            ecrire($ctx, 'BIEN', 'biens', $v, $id);
        } else {
            // ⚠️ `provenance` N'EST PAS DÉCORATIF : `chk_biens_mandant` de la migration 0021
            //    exige un compte mandant SAUF si le bien vient d'un document. Un lot importé
            //    sans compte est un SURSIS, pas une permission — et cette colonne est ce qui
            //    permet de le retrouver plus tard.
            $id = ecrire($ctx, 'BIEN', 'biens',
                         $v + ['provenance' => 'IMPORT', 'created_by' => AUTEUR]);
        }
        $idBien[$b['k']] = $id;
        foreach ($b['codes'] as $n => $c) {
            poserCode($ctx, $x, 'BIEN', (int)$id, $ag, $c['systeme'], $c['code'], $n === 0);
        }
    }

    // ── LES RÔLES, SUR LEUR OBJET ───────────────────────────────────────────────────────
    //
    // ⚠️ APRÈS LES IMMEUBLES ET LES BIENS, ET C'EST POUR ÇA QU'ILS ONT DÉMÉNAGÉ ICI. Un rôle
    //    de propriétaire se pose SUR un objet — le registre V2 le dit : `proprietaire` a pour
    //    objets `bien,immeuble`, et `RolesTiers::accepteObjet('proprietaire', null)` répond
    //    NON. Tant que les rôles s'écrivaient juste après les tiers, l'objet n'existait pas
    //    encore et je les posais « globaux ». C'était invalide, et surtout ça ne répondait
    //    pas à « qui possède cet immeuble ».
    foreach ($plan['roles'] as $r) {
        $it = $idTiers[$r['tiers_k']] ?? null;
        if (!$it || !$r['date_debut']) continue;
        $io = match ($r['objet_type']) {
            'immeuble' => $idImmeuble[$r['objet_k']] ?? null,
            'mandant'  => $idMandant[$r['objet_k']]  ?? null,
            default    => $idBien[$r['objet_k']]     ?? null,
        };
        if (!$io) continue;
        // `uk_tiers_roles_ouvert` interdit deux fois le même rôle ouvert sur le même objet.
        $cle = $it . '|' . $r['role_code'] . '|' . $r['objet_type'] . ':' . $io;
        if (isset($x['roles'][$cle])) continue;
        $id = ecrire($ctx, 'ROLE', 'tiers_roles', [
            'id_tiers' => $it, 'role_code' => $r['role_code'], 'attribut' => null,
            'objet_type' => $r['objet_type'], 'id_objet' => $io,
            'date_debut' => $r['date_debut'], 'created_by' => AUTEUR,
        ]);
        $x['roles'][$cle] = $id;
    }

    // ── LES MANDATS DE GESTION ──────────────────────────────────────────────────────────
    foreach ($plan['mandats'] as $m) {
        $ib = $idBien[$m['bien_k']] ?? null;
        $im = $m['mandant_k'] ? ($idMandant[$m['mandant_k']] ?? null) : null;
        if (!$ib || !$im) continue;
        $cle = $ib . '|' . $m['type_code'] . '|' . $m['date_debut'];
        if (isset($x['mandats'][$cle])) continue;
        $id = ecrire($ctx, 'MANDAT', 'mandats', [
            'id_agence' => (int)$m['id_agence'], 'type_code' => $m['type_code'],
            'id_mandant' => $im, 'id_tiers' => null,
            'id_immeuble' => $m['immeuble_k'] ? ($idImmeuble[$m['immeuble_k']] ?? null) : null,
            'id_bien' => $ib, 'date_debut' => $m['date_debut'], 'date_fin' => $m['date_fin'],
            'created_by' => AUTEUR,
        ]);
        $x['mandats'][$cle] = $id;
    }

    // ── LES OCCUPATIONS ET LEURS OCCUPANTS ──────────────────────────────────────────────
    foreach ($plan['occupations'] as $o) {
        $ib = $idBien[$o['bien_k']] ?? null;
        if (!$ib) continue;
        $cle = $ib . '#' . plat($o['nom_lu']);
        $v   = ['date_debut' => $o['date_debut'], 'date_fin' => $o['date_fin']];
        $id  = $x['occupations'][$cle] ?? null;
        if ($id !== null) {
            ecrire($ctx, 'OCCUPATION', 'occupations', $v, $id);
            continue;                         // les occupants sont déjà là
        }
        $id = ecrire($ctx, 'OCCUPATION', 'occupations',
                     ['id_bien' => $ib] + $v + ['created_by' => AUTEUR]);
        $x['occupations'][$cle] = $id;

        foreach ($o['occupants'] as $oc) {
            ecrire($ctx, 'OCCUPANT', 'occupation_occupants', [
                'id_occupation' => $id,
                'id_tiers'      => $oc['tiers_k'] ? ($idTiers[$oc['tiers_k']] ?? null) : null,
                'nom_lu'        => $oc['nom_lu'], 'role_code' => $oc['role_code'],
                'rang'          => $oc['rang'], 'created_by' => AUTEUR,
            ]);
        }

        // ── LE BAIL QUE CETTE OCCUPATION PROUVE ─────────────────────────────────────────
        //
        // ⚠️ UN APPEL DE LOYER PROUVE QU'UN BAIL EXISTE. Emmanuel : « le fait que le CRG
        //    nous donne un occupant tiers avec des appels de loyer, alors le bail doit être
        //    créé ». On le crée donc, avec ce que le document DIT de lui — sa date quand
        //    elle est imprimée — et RIEN d'autre : les montants viennent de la phase 4 des
        //    mouvements, qui n'a pas encore tourné. `type_code` reste `indetermine` : le CRG
        //    ne dit pas la nature juridique, et la déduire du type de lot serait une
        //    supposition rangée dans une colonne qu'on lirait comme un fait.
        if (isset($x['baux'][(int)$id])) continue;
        $idBail = ecrire($ctx, 'BAIL', 'baux', [
            'id_bien' => $ib, 'id_occupation' => $id, 'type_code' => 'indetermine',
            'date_debut' => $o['bail_du'] ?? null, 'date_fin' => $o['bail_au'] ?? null,
            'source' => 'CRG', 'created_by' => AUTEUR,
        ]);
        $x['baux'][(int)$id] = $idBail;

        // ⚠️ ET LE RÔLE `locataire` DEVIENT ENFIN POSABLE. Le registre V2 lui donne pour
        //    objet `bail` : tant que la table n'existait pas, ce rôle ne pouvait aller
        //    nulle part. Il se pose maintenant sur son objet, comme les autres.
        if ($idBail !== null && $idBail > 0) {
            foreach ($o['occupants'] as $oc) {
                $it = $oc['tiers_k'] ? ($idTiers[$oc['tiers_k']] ?? null) : null;
                if (!$it || $it < 0) continue;
                $cleR = $it . '|' . $oc['role_code'] . '|bail:' . $idBail;
                if (isset($x['roles'][$cleR])) continue;
                // ⚠️ UNE PÉRIODE DE RÔLE NE PEUT PAS FINIR AVANT DE COMMENCER, et le corpus
                //    produit ce cas : une date de bail imprimée POSTÉRIEURE au dernier appel
                //    de loyer observé. `chk_tiers_roles_periode` a refusé tout un dépôt pour
                //    cela — la base a eu raison. On borne au lieu d'inventer un ordre : la
                //    fin ne descend jamais sous le début.
                $d = $o['bail_du'] ?: ($o['date_debut'] ?: $plan['periode_debut']);
                $f = $o['date_fin'] ?: null;
                if ($f && $d && $f < $d) $f = $d;
                ecrire($ctx, 'ROLE', 'tiers_roles', [
                    'id_tiers' => $it, 'role_code' => $oc['role_code'], 'attribut' => null,
                    'objet_type' => 'bail', 'id_objet' => $idBail,
                    'date_debut' => $d, 'date_fin' => $f, 'created_by' => AUTEUR,
                ]);
                $x['roles'][$cleR] = true;
            }
        }
    }

    return $ctx['import'];
}

// ═══════════════════════════════════════════════════════════════════════════════════════════
//  L'ANNULATION — le journal rejoué à l'envers
// ═══════════════════════════════════════════════════════════════════════════════════════════

/**
 * ⚠️ À L'ENVERS, ET C'EST LE SEUL ORDRE QUI MARCHE. Rejouer le journal du plus récent au
 *    plus ancien respecte les clés étrangères sans avoir à connaître l'ordre des tables :
 *    ce qui a été créé en dernier dépend de ce qui a été créé avant.
 *
 * ⚠️ UN ÉCHEC EST NOMMÉ, JAMAIS AVALÉ. Une ligne créée par l'import puis référencée depuis
 *    par un humain ne peut plus être supprimée — et il faut le SAVOIR.
 */
function defaire(int $import, bool $pourDeVrai): array
{
    $st = pdo()->prepare('SELECT * FROM imports_journal WHERE id_import = ? ORDER BY id DESC');
    $st->execute([$import]);
    $lignes = $st->fetchAll(PDO::FETCH_ASSOC);
    $stat = ['supprimees' => 0, 'restaurees' => 0, 'conservees_car_partagees' => 0, 'echecs' => []];

    foreach ($lignes as $l) {
        try {
            if (!$pourDeVrai) {
                $stat[$l['action'] === 'CREER' ? 'supprimees' : 'restaurees']++;
                continue;
            }
            if ($l['action'] === 'CREER') {
                pdo()->prepare("DELETE FROM `{$l['table_cible']}` WHERE id = ?")->execute([$l['id_ligne']]);
                $stat['supprimees']++;
            } else {
                $avant = json_decode((string)$l['avant'], true) ?: [];
                if ($avant) {
                    $set = implode(',', array_map(fn($c) => "`$c` = ?", array_keys($avant)));
                    pdo()->prepare("UPDATE `{$l['table_cible']}` SET $set WHERE id = ?")
                         ->execute([...array_values($avant), $l['id_ligne']]);
                }
                $stat['restaurees']++;
            }
        } catch (Throwable $e) {
            // ⚠️ UNE VALEUR PARTAGÉE QU'ON NE PEUT PLUS SUPPRIMER N'EST PAS UN ÉCHEC.
            //    Depuis que l'adresse est un référentiel, une ligne créée par l'import peut
            //    être devenue celle d'une agence ou d'une société. La clé étrangère refuse
            //    la suppression — et elle a RAISON : la valeur sert encore. La compter comme
            //    un échec faisait rester l'import « non annulé » alors qu'il l'était.
            //    Ce qui compte, c'est que le LIEN de l'import ait disparu ; la valeur, elle,
            //    n'appartenait à personne en propre.
            $partagee = str_contains((string)$e->getCode(), '23000')
                     && str_contains($e->getMessage(), 'foreign key constraint')
                     && in_array($l['table_cible'], ['adresses'], true);
            if ($partagee) {
                $stat['conservees_car_partagees']++;
            } else {
                $stat['echecs'][] = $l['table_cible'] . '#' . $l['id_ligne'] . ' : '
                                  . substr($e->getMessage(), 0, 120);
            }
        }
    }
    if ($pourDeVrai && !$stat['echecs']) {
        pdo()->prepare('DELETE FROM imports_journal WHERE id_import = ?')->execute([$import]);
        pdo()->prepare("UPDATE imports SET annule_le = NOW(), annule_motif = 'annulation demandée'
                         WHERE id = ?")->execute([$import]);
    }
    return $stat;
}

// ═══════════════════════════════════════════════════════════════════════════════════════════

function etat(): void
{
    $t = ['tiers','tiers_roles','mandants','immeubles','biens','mandats','objet_codes',
          'occupations','occupation_occupants','imports','imports_journal'];
    echo "ÉTAT DE LA BASE " . getenv('MBI_DB_NAME') . "\n";
    foreach ($t as $n) printf("  %-24s %6d\n", $n, pdo()->query("SELECT COUNT(*) FROM `$n`")->fetchColumn());
    echo "\nIMPORTS\n";
    foreach (pdo()->query('SELECT id, libelle, nb_documents, fait_le, annule_le FROM imports ORDER BY id') as $r)
        printf("  #%-3s %-24s %4s docs  %s%s\n", $r['id'], mb_substr($r['libelle'], 0, 24),
               $r['nb_documents'], $r['fait_le'], $r['annule_le'] ? '  ANNULÉ ' . $r['annule_le'] : '');
}

$args      = array_slice($argv, 1);
$pourDeVrai = in_array('--pour-de-vrai', $args, true);
$args      = array_values(array_filter($args, fn($a) => $a !== '--pour-de-vrai'));

if (($args[0] ?? '') === '--etat') { etat(); exit(0); }

if (($args[0] ?? '') === '--defaire') {
    $stat = defaire((int)($args[1] ?? 0), $pourDeVrai);
    echo ($pourDeVrai ? 'ANNULÉ' : 'SIMULATION') . ' : ' . json_encode($stat, JSON_UNESCAPED_UNICODE) . "\n";
    exit($stat['echecs'] ? 1 : 0);
}

$fichier = $args[0] ?? '';
if (!is_file($fichier)) { fwrite(STDERR, "Plan introuvable : $fichier\n"); exit(1); }
$plan = json_decode((string)file_get_contents($fichier), true);
if (!$plan) { fwrite(STDERR, "Plan illisible.\n"); exit(1); }

// ⚠️ TOUT OU RIEN. Un import à moitié écrit laisse des biens sans mandant et des occupations
//    sans occupant — c'est-à-dire une base qu'il faut nettoyer à la main.
if ($pourDeVrai) pdo()->beginTransaction();
try {
    $id = importer($plan, $pourDeVrai);
    if ($pourDeVrai) pdo()->commit();
} catch (Throwable $e) {
    if ($pourDeVrai && pdo()->inTransaction()) pdo()->rollBack();
    fwrite(STDERR, "ÉCHEC — rien n'a été écrit.\n  " . $e->getMessage() . "\n");
    exit(1);
}

echo ($pourDeVrai ? "IMPORT #$id — " : 'SIMULATION — ') . $plan['libelle'] . "\n";
foreach ($GLOBALS['compte'] as $famille => $actions) {
    printf("  %-12s %s\n", $famille, json_encode($actions));
}
