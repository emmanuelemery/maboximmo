<?php
/**
 * QUALITÉ DES DONNÉES — LE CATALOGUE DES DÉTECTEURS ET LE MOTEUR QUI LES JOUE.
 * ═══════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ CE MODULE EXISTE PARCE QUE L'INTÉGRATION CRG BUTAIT SUR MBI, PAS SUR LES DOCUMENTS. Sur
 *    un dépôt, 17 des 18 « immeubles homonymes » n'opposaient pas deux bâtiments : ils
 *    opposaient un bâtiment à ses propres copies dans MBI. Le compte rendu était compris ;
 *    c'est la base qui ne savait pas répondre. Tant que ces deux causes remontaient sous le
 *    même mot — « arbitrage » — le travail humain grossissait sans qu'on sache pourquoi, et
 *    le taux de compréhension du lecteur baissait pour une faute qui n'était pas la sienne.
 *
 * ⚠️ TROIS NATURES DE PROBLÈMES, JAMAIS MÉLANGÉES (Emmanuel, 06/09/2026) :
 *      ARBITRAGE CRG   — le document est réellement ambigu. Il faut le relire.
 *      ANOMALIE MBI    — le document est compris ; c'est la base qui se contredit.
 *      ANOMALIE INFRA  — ni l'un ni l'autre : l'environnement technique cède.
 *    Elles restent distinctes dans le registre, dans les écrans, dans les KPI et dans le score.
 *
 * ⚠️ LES DÉTECTEURS SONT DÉCLARÉS ICI, PAS CODÉS DANS LA PAGE. Ajouter une famille d'anomalie
 *    = ajouter une entrée à ce catalogue. Ce n'est PAS = modifier l'écran. La page ne connaît
 *    aucune famille : elle affiche ce que le moteur lui rend, quelles que soient les familles
 *    déclarées demain.
 *
 * ⚠️ UN DÉTECTEUR QUI REND « 27 » EST INCOMPLET. Il rend 27 ET les identifiants exacts des
 *    faits qui les ont produits. `TOUT AGRÉGAT QUALITÉ DOIT ÊTRE DÉPLIABLE JUSQU'À SA PREUVE` :
 *    un compteur qu'on ne peut pas ouvrir n'est pas opposable, et personne ne devrait le croire.
 *
 * ⚠️ AUCUNE ÉCRITURE MÉTIER. Ce module lit `immeubles`, `biens`, `bien_baux`, `tiers`,
 *    `proprietaires` — il n'y écrit JAMAIS. Il écrit dans `crgq_occurrence`,
 *    `crgq_decision` et `crgq_infra_reglage`, qui sont des tables techniques.
 *    ÉCRITURE TECHNIQUE ≠ ÉCRITURE MÉTIER : la première est la mémoire de l'agent, la
 *    seconde reste interdite sans une autorisation explicite d'Emmanuel.
 *
 * ⚠️ ET DÉTECTER N'EST PAS CORRIGER. Aucun détecteur ne porte de correction exécutable. Il
 *    porte une correction PROPOSÉE, en texte, et le risque qu'elle emporte.
 */
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** Les collations de MBI sont mixtes : toute comparaison de texte doit la forcer. */
const CRGQ_COLLATE = ' COLLATE utf8mb4_unicode_ci ';

/** Les seuls degrés de preuve. Une heuristique ne devient jamais une certitude. */
const CRGQ_CERTITUDES = ['CERTAIN', 'PROBABLE', 'A EXAMINER'];

/** Les décisions qu'un humain peut poser sur une occurrence. */
const CRGQ_DECISIONS = ['LEGITIME', 'A CORRIGER', 'ORPHELIN PROBABLE', 'DOUBLON PROBABLE',
                        'A EXAMINER'];

/**
 * LE CATALOGUE.
 *
 * ⚠️ CHAQUE DÉTECTEUR NE REMONTE QUE CE QUE LE TRAVAIL COURANT A RENCONTRÉ. Cette capacité
 *    n'est pas un mandat pour auditer spontanément les cinq cents tables de MBI : une anomalie
 *    remonte parce que l'intégration CRG l'a heurtée, ou parce qu'elle met en doute sa
 *    fiabilité. Rien d'autre.
 *
 * Chaque entrée déclare : ce qu'elle cherche, avec quelle certitude, ce que ça coûte, ce
 * qu'on propose, et le risque de la correction proposée. Le `sql` rend une ligne par
 * occurrence, avec `cle` (le périmètre stable), `ids` (les faits) et la preuve.
 */
function crgq_detecteurs(): array
{
    $C = CRGQ_COLLATE;
    $TYPES_LOT = "'" . implode("','", CRGI_TYPES_DE_LOT) . "'";

    return [
        // ── CERTAIN : MBI le dit lui-même ────────────────────────────────────────────────
        'MBI-IMM-CODE-DUP' => [
            'version'    => '1.0',
            'libelle'    => 'Le même code de gestion porté par plusieurs immeubles',
            'famille'    => 'MBI',
            'certitude'  => 'CERTAIN',
            'objet'      => 'immeubles',
            'perimetre'  => 'immeubles dont code_crg est renseigné',
            'description' => 'Le code de gestion identifie un immeuble chez le gestionnaire. '
                           . 'Deux enregistrements qui le partagent prétendent être le même '
                           . 'immeuble : ce n’est pas une ressemblance, c’est MBI qui se '
                           . 'contredit.',
            'impact'     => 'L’intégration CRG ne peut pas rattacher le compte rendu : elle '
                          . 'ouvre une question d’identité que le document ne pose pas.',
            'risque'     => 'Les loyers et charges d’un même immeuble peuvent être répartis '
                          . 'sur plusieurs fiches selon l’import.',
            'correction' => 'Désigner l’enregistrement survivant, y déplacer les biens et les '
                          . 'baux, archiver les autres. Jamais de suppression.',
            'automatisable' => false,
            'sql' => "SELECT TRIM(code_crg) AS cle,
                             GROUP_CONCAT(id ORDER BY id) AS ids,
                             COUNT(*) AS n,
                             GROUP_CONCAT(CONCAT(id, ' · ', COALESCE(NULLIF(TRIM(adresse_1),''),
                                          COALESCE(nom_immeuble,'?')), ' · ',
                                          COALESCE(NULLIF(TRIM(type_immeuble),''),'(sans type)'))
                                          ORDER BY id SEPARATOR ' | ') AS preuve
                        FROM immeubles
                       WHERE TRIM(COALESCE(code_crg,'')) <> ''
                       GROUP BY TRIM(code_crg) $C
                      HAVING COUNT(*) > 1",
        ],

        'MBI-IMM-ADRESSE-CONTAMINEE' => [
            'version'    => '1.0',
            'libelle'    => 'Une adresse qui est un fragment de compte rendu',
            'famille'    => 'MBI',
            'certitude'  => 'CERTAIN',
            'objet'      => 'immeubles',
            'perimetre'  => 'immeubles dont adresse_1 est renseignée',
            'description' => 'L’adresse porte des mots qui n’appartiennent qu’à un tableau de '
                           . 'CRG — « RECAPITULATIF DES OPERATIONS », « SITUATION DES '
                           . 'LOCATAIRES ». Un import ancien a pris une bande de tableau pour '
                           . 'une adresse.',
            'impact'     => 'L’immeuble ne se retrouve plus par son adresse : il devient '
                          . 'invisible au rapprochement, et un doublon sera créé à sa place.',
            'risque'     => 'Une adresse fausse voyage dans les courriers et les documents '
                          . 'officiels.',
            'correction' => 'L’adresse réelle est le plus souvent reconstructible : le code '
                          . 'postal et la ville précèdent le fragment. Correction ligne à '
                          . 'ligne, avant/après affiché, validée à la main.',
            'automatisable' => false,
            'sql' => "SELECT CAST(id AS CHAR) AS cle, CAST(id AS CHAR) AS ids, 1 AS n,
                             CONCAT(id, ' · « ', LEFT(adresse_1, 90), ' » · ',
                                    COALESCE(nom_immeuble,'?'), ' · ',
                                    COALESCE(code_postal,'?'), ' ', COALESCE(ville,'?')) AS preuve
                        FROM immeubles
                       WHERE adresse_1 $C REGEXP
                             'RECAPITULATIF|SITUATION DES|LOCATAIRES|TRIMESTRE|DEBITS|CREDITS'",
        ],

        // ── PROBABLE : la structure le suggère, elle ne le démontre pas ──────────────────
        'MBI-IMM-ADRESSE-DUP' => [
            'version'    => '1.0',
            'libelle'    => 'Plusieurs immeubles à la même adresse et au même code postal',
            'famille'    => 'MBI',
            'certitude'  => 'PROBABLE',
            'objet'      => 'immeubles',
            'perimetre'  => 'immeubles dont adresse_1 et code_postal sont renseignés',
            'description' => 'Deux enregistrements décrivent la même adresse. C’est souvent le '
                           . 'même immeuble saisi deux fois — mais deux bâtiments peuvent '
                           . 'légitimement partager une adresse (A et B, cour et rue).',
            'impact'     => 'Le rapprochement CRG hésite et pose une question d’identité.',
            'risque'     => 'Fusionner à tort deux bâtiments réellement distincts mélangerait '
                          . 'leurs lots et leurs comptes. À examiner, jamais à fusionner d’office.',
            'correction' => 'Comparer les deux fiches — lots, baux, propriétaires, historique — '
                          . 'et conclure à la main. Le doute reste au dossier.',
            'automatisable' => false,
            'sql' => "SELECT CONCAT(UPPER(TRIM(adresse_1)), '|', COALESCE(code_postal,'')) AS cle,
                             GROUP_CONCAT(id ORDER BY id) AS ids,
                             COUNT(*) AS n,
                             GROUP_CONCAT(CONCAT(id, ' · ', COALESCE(nom_immeuble,'?'), ' · ',
                                          COALESCE(NULLIF(TRIM(type_immeuble),''),'(sans type)'),
                                          ' · ', COALESCE(NULLIF(TRIM(code_crg),''),'(sans code)'))
                                          ORDER BY id SEPARATOR ' | ') AS preuve
                        FROM immeubles
                       WHERE TRIM(COALESCE(adresse_1,'')) <> ''
                       GROUP BY UPPER(TRIM(adresse_1)) $C, code_postal $C
                      HAVING COUNT(*) > 1",
        ],

        'MBI-IMM-TYPE-LOT' => [
            'version'    => '1.0',
            'libelle'    => 'Un LOT rangé dans la table des immeubles, sous un immeuble existant',
            'famille'    => 'MBI',
            'certitude'  => 'PROBABLE',
            'objet'      => 'immeubles',
            'perimetre'  => 'immeubles typés Appartement, Local, Garage… ET dont un immeuble '
                          . 'de même adresse existe',
            'description' => 'La table des immeubles porte des appartements, des locaux et des '
                           . 'garages. Ceux-là ont, à la même adresse, un enregistrement typé '
                           . 'immeuble : ce sont vraisemblablement des LOTS de ce bâtiment.',
            // ⚠️ ON NE REMONTE QUE CEUX QUI ONT UN PARENT. Un appartement seul à son adresse
            //    n'est pas une erreur : c'est un patrimoine isolé, que le modèle tolère.
            //    `MODÈLE INHABITUEL ≠ ANOMALIE CERTAINE` — sur 277 lignes typées lot, 236
            //    n'ont aucun parent et ne sont donc PAS signalées ici.
            'impact'     => 'Ils entrent comme candidats au rapprochement d’un immeuble et '
                          . 'fabriquent des homonymes qui n’existent pas.',
            'risque'     => 'Les déplacer suppose de connaître leur immeuble parent avec '
                          . 'certitude ; une erreur déplacerait des baux sous le mauvais toit.',
            'correction' => 'Examiner le couple lot/parent proposé, un par un, et rattacher '
                          . 'quand la preuve tient.',
            'automatisable' => false,
            'sql' => "SELECT CAST(l.id AS CHAR) AS cle,
                             CONCAT(l.id, ',', p.id) AS ids, 1 AS n,
                             CONCAT('lot #', l.id, ' (', l.type_immeuble, ') → immeuble #',
                                    p.id, ' (', COALESCE(p.type_immeuble,'?'), ') · ',
                                    LEFT(l.adresse_1, 60)) AS preuve
                        FROM immeubles l
                        JOIN immeubles p
                          ON p.id <> l.id
                         AND UPPER(TRIM(p.adresse_1)) $C = UPPER(TRIM(l.adresse_1)) $C
                         AND p.code_postal $C = l.code_postal $C
                         AND UPPER(TRIM(COALESCE(p.type_immeuble,''))) $C NOT IN ($TYPES_LOT)
                       WHERE UPPER(TRIM(l.type_immeuble)) $C IN ($TYPES_LOT)
                         AND TRIM(COALESCE(l.adresse_1,'')) <> ''",
        ],

        'MBI-IMM-REF-EGALE-ID' => [
            'version'    => '1.0',
            'libelle'    => 'La référence de l’immeuble est son propre identifiant',
            'famille'    => 'MBI',
            'certitude'  => 'PROBABLE',
            'objet'      => 'immeubles',
            'perimetre'  => 'immeubles dont reference_immeuble est renseignée',
            'description' => 'Une reprise ancienne, faute de référence, a recopié l’identifiant '
                           . 'technique dans le champ référence. Ce n’est la référence de '
                           . 'personne.',
            'impact'     => 'Cette fausse référence indexe l’immeuble au rapprochement CRG et '
                          . 'peut le faire apparaître comme candidat d’un code qui ne le '
                          . 'désigne pas.',
            'risque'     => 'Faible à corriger, mais il faut vérifier qu’aucun écran ne '
                          . 's’appuie sur cette valeur.',
            'correction' => 'Vider la référence quand aucune référence réelle n’existe, ou y '
                          . 'porter celle du gestionnaire.',
            'automatisable' => false,
            'sql' => "SELECT CAST(id AS CHAR) AS cle, CAST(id AS CHAR) AS ids, 1 AS n,
                             CONCAT(id, ' · référence « ', reference_immeuble, ' » · ',
                                    COALESCE(NULLIF(TRIM(adresse_1),''),
                                             COALESCE(nom_immeuble,'?'))) AS preuve
                        FROM immeubles
                       WHERE reference_immeuble $C = CAST(id AS CHAR) $C",
        ],

        // ── INFRASTRUCTURE : ni les documents, ni la base — l'environnement ─────────────
        // ⚠️ UNE FAMILLE À PART, ET C'EST TOUT LE POINT. Un moteur qui tombe parce que MariaDB
        //    n'a pas de mémoire ne dit RIEN de la qualité des données ni de celle du lecteur.
        //    Les mélanger ferait accuser le mauvais coupable — et c'est arrivé : deux phases
        //    de LYON se sont arrêtées sur « MySQL server has gone away » après avoir lu
        //    correctement 2 560 pages.
        'INFRA-MARIADB-DIMENSION' => [
            'version'    => '1.0',
            'libelle'    => 'Un paramètre MariaDB en deçà de ce que la charge constatée exige',
            'famille'    => 'INFRASTRUCTURE',
            'certitude'  => 'CERTAIN',
            'objet'      => 'parametre',
            'perimetre'  => 'variables du serveur MariaDB de cet environnement',
            'description' => 'Le paramètre est mesuré, comparé au besoin CONSTATÉ — pas à une '
                           . 'bonne pratique générale. Un réglage n’est signalé que s’il a '
                           . 'réellement provoqué un blocage ou s’il en approche.',
            'impact'     => 'Une phase d’analyse s’interrompt sur une perte de connexion. Le '
                          . 'moteur refuse alors de sceller — le résultat n’est pas faux, il '
                          . 'est absent, et il faut tout rejouer.',
            'risque'     => 'Aucun en local : le réglage est réversible et tracé. Sur le VPS, '
                          . 'toute modification demande une mesure des ressources, une '
                          . 'sauvegarde et un plan de retour.',
            'correction' => 'Relever la valeur en local, mesurer l’effet, consigner avant/après. '
                          . 'Ne rien toucher sur le VPS à ce stade.',
            'automatisable' => false,
            'fn' => 'crgq_detecter_mariadb',
        ],

        // ── À EXAMINER : un signal, pas une faute ────────────────────────────────────────
        'MBI-IMM-SANS-BIEN' => [
            'version'    => '1.0',
            'libelle'    => 'Un immeuble qui ne porte aucun bien',
            'famille'    => 'MBI',
            'certitude'  => 'A EXAMINER',
            'objet'      => 'immeubles',
            'perimetre'  => 'tous les immeubles',
            'description' => 'L’immeuble existe et aucun bien ne s’y rattache. Ce peut être un '
                           . 'immeuble en cours de saisie, un immeuble vendu, ou le résidu '
                           . 'd’un import — le seul comptage ne le dit pas.',
            'impact'     => 'Aucun sur la lecture des CRG. Il encombre les listes et les '
                          . 'recherches d’identité.',
            'risque'     => 'Archiver à tort un immeuble en cours de constitution.',
            'correction' => 'Examiner l’historique et le mandat, puis conclure : légitime, '
                          . 'orphelin probable, ou doublon probable.',
            'automatisable' => false,
            'sql' => "SELECT CAST(i.id AS CHAR) AS cle, CAST(i.id AS CHAR) AS ids, 1 AS n,
                             CONCAT(i.id, ' · ', COALESCE(NULLIF(TRIM(i.adresse_1),''),
                                    COALESCE(i.nom_immeuble,'?')),
                                    ' · code ', COALESCE(NULLIF(TRIM(i.code_crg),''),'(aucun)'),
                                    ' · réf ', COALESCE(NULLIF(TRIM(i.reference_immeuble),''),'(aucune)'),
                                    ' · créé ', COALESCE(LEFT(i.date_creation,10),'?')) AS preuve
                        FROM immeubles i
                       WHERE NOT EXISTS (SELECT 1 FROM biens b WHERE b.id_immeuble = i.id)",
        ],
    ];
}

/**
 * LES RÉGLAGES MARIADB MESURÉS CONTRE LE BESOIN CONSTATÉ.
 *
 * ⚠️ LE SEUIL VIENT D'UN INCIDENT, PAS D'UN GUIDE. On ne signale pas « la valeur recommandée
 *    par la documentation » : on signale la valeur qui a laissé tomber une phase, et on dit
 *    laquelle. Un détecteur qui recopierait les bonnes pratiques du monde entier remonterait
 *    du bruit à chaque passage, et on cesserait de le lire.
 */
function crgq_detecter_mariadb(PDO $pdo): array
{
    // Le besoin est mesuré : la plus grosse table de staging du module, et le fait que deux
    // phases de LYON se soient arrêtées sur une perte de connexion.
    $attendus = [
        'innodb_buffer_pool_size' => [268435456, '256 Mo',
            'Le cache de pages InnoDB. À 16 Mo, un dépôt de 14 000 mouvements relit le disque '
            . 'à chaque requête : la phase 2 de LYON est passée de 708 s à 1 455 s d’un '
            . 'passage à l’autre, sans qu’une ligne de code ait changé.'],
        'max_allowed_packet' => [16777216, '16 Mo',
            'La taille maximale d’un échange client/serveur. À 1 Mo, un dépassement ferme la '
            . 'connexion sans erreur lisible : « MySQL server has gone away ». Deux phases de '
            . 'LYON s’y sont arrêtées.'],
    ];
    $lignes = [];
    foreach ($attendus as $nom => [$mini, $lisible, $pourquoi]) {
        // ⚠️ `SHOW VARIABLES` N'ACCEPTE PAS DE PARAMÈTRE LIÉ. Le nom vient de la table
        //    ci-dessus, jamais d'une saisie ; on le vérifie quand même avant de l'écrire dans
        //    la requête — une constante d'aujourd'hui peut devenir une variable demain.
        if (!preg_match('~^[a-z_]{3,64}$~', $nom)) {
            continue;
        }
        $v = $pdo->query("SHOW VARIABLES LIKE '" . $nom . "'")->fetch(PDO::FETCH_NUM);
        if (!$v || (int)$v[1] >= $mini) {
            continue;                       // au niveau : rien à signaler
        }
        $lignes[] = [
            'cle'    => $nom,
            'ids'    => '',                 // un paramètre n'a pas d'identifiant de ligne
            'n'      => 1,
            'preuve' => sprintf('%s = %s (soit %s) · attendu au moins %s · %s',
                                $nom, $v[1], crgq_octets_lisibles((int)$v[1]), $lisible,
                                $pourquoi),
        ];
    }
    return $lignes;
}

/** Une taille en octets, dite comme un humain la dit. */
function crgq_octets_lisibles(int $o): string
{
    foreach ([['Go', 1073741824], ['Mo', 1048576], ['Ko', 1024]] as [$u, $d]) {
        if ($o >= $d) {
            return rtrim(rtrim(number_format($o / $d, 1, ',', ' '), '0'), ',') . ' ' . $u;
        }
    }
    return $o . ' o';
}

/**
 * L'EMPREINTE D'UNE OCCURRENCE — le phénomène, et rien d'autre.
 *
 * ⚠️ ELLE NE DOIT DÉPENDRE NI D'UN `AUTO_INCREMENT` DU REGISTRE, NI D'UN `import_id`, NI D'UNE
 *    DATE, NI DE L'ORDRE D'EXÉCUTION. Sinon la même anomalie, revue demain, porterait une
 *    autre identité : la décision d'hier ne se retrouverait pas, et le registre ne servirait
 *    à rien — il ne ferait qu'empiler des doublons de lui-même.
 *
 * ⚠️ LES IDENTIFIANTS SONT TRIÉS, ET NUMÉRIQUEMENT. « 10,9 » et « 9,10 » désignent le même
 *    groupe ; un tri de chaînes les rendrait différents.
 */
function crgq_empreinte(string $detecteur, string $perimetre, array $ids): string
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    sort($ids, SORT_NUMERIC);
    return hash('sha256', $detecteur . "\x1f" . $perimetre . "\x1f" . implode(',', $ids));
}

/** L'empreinte de ce qu'on a VU — si elle change, la preuve a changé. */
function crgq_preuve_sha(string $preuve): string
{
    return hash('sha256', trim($preuve));
}

/**
 * JOUER LES DÉTECTEURS ET TENIR LE REGISTRE À JOUR.
 *
 * ⚠️ ON N'EFFACE JAMAIS UNE OCCURRENCE QU'ON NE VOIT PLUS. Elle reste, avec sa date de
 *    dernière vue : c'est ainsi qu'on sait qu'une anomalie a DISPARU, et quand. L'effacer
 *    ferait perdre la seule preuve qu'elle a été corrigée.
 */
function crgq_detecter(PDO $pdo, ?string $seulement = null): array
{
    $debut = date('Y-m-d H:i:s');
    $vus = 0;
    $ins = $pdo->prepare(
        'INSERT INTO crgq_occurrence
            (empreinte, detecteur, detecteur_version, famille, certitude, objet_type,
             objet_ids, preuve_sha, preuve, contexte, vue_le_premier, vue_le_dernier, vues)
         VALUES (:e,:d,:v,:f,:c,:o,:i,:ps,:p,:ctx,NOW(),NOW(),1)
         ON DUPLICATE KEY UPDATE
            vue_le_dernier = NOW(), vues = vues + 1,
            detecteur_version = VALUES(detecteur_version),
            certitude = VALUES(certitude),
            preuve_sha = VALUES(preuve_sha), preuve = VALUES(preuve),
            contexte = VALUES(contexte)'
    );
    foreach (crgq_detecteurs() as $code => $d) {
        if ($seulement !== null && $seulement !== $code) {
            continue;
        }
        // ⚠️ UN DÉTECTEUR N'EST PAS FORCÉMENT UNE REQUÊTE. Ceux qui portent sur
        //    l'infrastructure interrogent le serveur, pas les tables. La forme change ; le
        //    contrat — rendre des lignes avec leur preuve — ne change pas.
        $lignes = isset($d['fn'])
            ? ($d['fn'])($pdo)
            : $pdo->query($d['sql'], PDO::FETCH_ASSOC);
        foreach ($lignes as $r) {
            $ids = array_filter(explode(',', (string)($r['ids'] ?? '')), 'strlen');
            $preuve = (string)($r['preuve'] ?? '');
            $ins->execute([
                ':e' => crgq_empreinte($code, (string)$r['cle'], $ids),
                ':d' => $code, ':v' => $d['version'], ':f' => $d['famille'],
                ':c' => $d['certitude'], ':o' => $d['objet'],
                ':i' => implode(',', $ids),
                ':ps' => crgq_preuve_sha($preuve), ':p' => $preuve,
                ':ctx' => json_encode(['cle' => $r['cle'], 'n' => (int)($r['n'] ?? 1)],
                                      JSON_UNESCAPED_UNICODE),
            ]);
            $vus++;
        }
    }
    return ['debut' => $debut, 'occurrences' => $vus];
}

/**
 * L'ÉTAT D'UNE OCCURRENCE — DÉDUIT, JAMAIS STOCKÉ.
 *
 * ⚠️ AUCUNE COLONNE `statut`. Un état écrit se désynchronise au premier changement de la base :
 *    une anomalie marquée « fermée » que le détecteur voit toujours reste fermée, et personne
 *    ne le sait. On calcule donc à partir de deux séries de faits — ce que le détecteur a vu,
 *    ce qu'Emmanuel a décidé — et il n'y a rien à maintenir.
 *
 * ⚠️ UNE DÉCISION N'EST PAS UNE VÉRITÉ ÉTERNELLE. Si la preuve d'aujourd'hui diffère de celle
 *    qui a été examinée, la décision ne s'applique plus telle quelle : l'occurrence revient,
 *    marquée comme contredite. Masquer la situation nouvelle sous une réponse ancienne serait
 *    le pire des deux mondes — on aurait l'air d'avoir appris tout en écrasant une preuve.
 */
function crgq_etat(array $occ, ?array $derniere, string $vuDepuis): array
{
    if ((string)$occ['vue_le_dernier'] < $vuDepuis) {
        return ['etat' => 'DISPARUE',
                'motif' => 'Le détecteur ne la voit plus depuis le '
                         . substr((string)$occ['vue_le_dernier'], 0, 10) . '.'];
    }
    if ($derniere === null) {
        $neuve = (string)$occ['vue_le_premier'] >= $vuDepuis;
        return ['etat' => $neuve ? 'NOUVELLE' : 'A EXAMINER',
                'motif' => $neuve
                    ? 'Vue pour la première fois à ce passage.'
                    : 'Vue depuis le ' . substr((string)$occ['vue_le_premier'], 0, 10)
                      . ', aucune décision.'];
    }
    if ((string)$derniere['preuve_sha'] !== (string)$occ['preuve_sha']) {
        return ['etat' => 'DECISION CONTREDITE',
                'motif' => 'Décidée « ' . $derniere['decision'] . ' » le '
                         . substr((string)$derniere['decide_le'], 0, 10)
                         . ', mais la preuve a changé depuis : à réexaminer.'];
    }
    return ['etat' => (string)$derniere['decision'],
            'motif' => 'Décidée le ' . substr((string)$derniere['decide_le'], 0, 10)
                     . ($derniere['commentaire'] ? ' — ' . $derniere['commentaire'] : '')];
}

/**
 * LE REGISTRE, TEL QUE L'ÉCRAN DOIT LE LIRE : occurrences + état déduit, par famille.
 */
function crgq_registre(PDO $pdo, string $vuDepuis): array
{
    $decisions = [];
    foreach ($pdo->query('SELECT * FROM crgq_decision ORDER BY decide_le, id',
                         PDO::FETCH_ASSOC) as $d) {
        $decisions[(string)$d['empreinte']] = $d;      // la DERNIÈRE l'emporte
    }
    $cat = crgq_detecteurs();
    $sortie = [];
    foreach ($pdo->query('SELECT * FROM crgq_occurrence ORDER BY detecteur, id',
                         PDO::FETCH_ASSOC) as $o) {
        $e = crgq_etat($o, $decisions[(string)$o['empreinte']] ?? null, $vuDepuis);
        $sortie[] = $o + $e + [
            'catalogue' => $cat[(string)$o['detecteur']] ?? null,
            'decision'  => $decisions[(string)$o['empreinte']] ?? null,
        ];
    }
    return $sortie;
}

/**
 * ENREGISTRER UNE DÉCISION HUMAINE — dans le journal, jamais dans la donnée métier.
 *
 * ⚠️ APPEND-ONLY. On ne corrige pas une décision : on en pose une autre, et l'historique dit
 *    ce qui a été pensé, quand, et sur quelle preuve. C'est ce qui permet, plus tard, de
 *    savoir si une réponse a été donnée sous une règle qui n'existe plus.
 */
function crgq_decider(PDO $pdo, string $empreinte, string $decision, ?string $commentaire,
                      int $userId, string $portee = 'OCCURRENCE'): void
{
    if (!in_array($decision, CRGQ_DECISIONS, true)) {
        throw new RuntimeException(
            'DÉCISION INCONNUE : « ' . $decision . ' ». Les décisions possibles sont : '
            . implode(' · ', CRGQ_DECISIONS)
        );
    }
    $st = $pdo->prepare('SELECT preuve_sha, detecteur_version FROM crgq_occurrence
                          WHERE empreinte = ?');
    $st->execute([$empreinte]);
    $o = $st->fetch(PDO::FETCH_ASSOC);
    if (!$o) {
        throw new RuntimeException(
            'AUCUNE OCCURRENCE SOUS CETTE EMPREINTE. Une décision ne se pose que sur une '
            . 'anomalie réellement détectée.'
        );
    }
    $pdo->prepare(
        'INSERT INTO crgq_decision
            (empreinte, decision, portee, commentaire, preuve_sha, detecteur_version,
             decide_par, decide_le)
         VALUES (?,?,?,?,?,?,?,NOW())'
    )->execute([$empreinte, $decision, $portee, $commentaire,
                $o['preuve_sha'], $o['detecteur_version'], $userId ?: null]);
}

/**
 * LE COMPTE PAR FAMILLE ET PAR ÉTAT — ce que le tableau de bord affiche, et rien de plus.
 *
 * ⚠️ CHAQUE NOMBRE EST OUVRABLE : il correspond exactement aux occurrences rendues par
 *    `crgq_registre()`, filtrées sur les mêmes clés. Aucun compteur ne se calcule à part.
 */
function crgq_synthese(array $registre): array
{
    $par = [];
    foreach ($registre as $o) {
        $d = (string)$o['detecteur'];
        $par[$d] ??= ['detecteur' => $d, 'libelle' => $o['catalogue']['libelle'] ?? $d,
                      'famille' => (string)$o['famille'], 'certitude' => (string)$o['certitude'],
                      'total' => 0, 'objets' => 0, 'etats' => []];
        $par[$d]['total']++;
        $par[$d]['objets'] += count(array_filter(explode(',', (string)$o['objet_ids']), 'strlen'));
        $par[$d]['etats'][(string)$o['etat']] = ($par[$d]['etats'][(string)$o['etat']] ?? 0) + 1;
    }
    return $par;
}
