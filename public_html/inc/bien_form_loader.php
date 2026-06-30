<?php
declare(strict_types=1);

/**
 * BIEN FORM LOADER — pré-chargement d'un bien existant pour édition
 *
 * Stratégie : plutôt que de modifier les ~217 appels `post()` du formulaire
 * bien_ajouter.php pour qu'ils tombent en fallback sur les valeurs chargées
 * depuis la BDD, on **pré-remplit `$_POST` directement** avec les colonnes
 * de `biens` et `annonces` quand on est en mode édition (GET ?edit=ID, pas
 * de soumission POST en cours). Tous les `post('xxx')` retournent alors
 * naturellement la valeur stockée.
 *
 * Ce module est strictement no-op si aucun edit n'est demandé.
 */

/**
 * Charge un bien complet (joint avec son annonce principale et le type)
 * en vue de pré-remplir le formulaire de modification.
 *
 * @return array|null Tableau associatif fusionné (colonnes biens + annonces),
 *                    ou null si introuvable / hors-périmètre société.
 */
function bien_form_load_record(PDO $pdo, int $idBien, ?int $idSociete): ?array
{
    if ($idBien <= 0) return null;

    // Migration 20260430_bien_types : la nouvelle table `bien_types` est
    // la source de vérité (via biens.id_bien_type). Fallback gracieux sur
    // la table legacy pour les biens dont id_bien_type ne serait pas
    // encore backfillé (cas résiduel post-migration).
    //
    // CRITIQUE : le JOIN legacy doit utiliser la MÊME table que celle où
    // bien_type_helper.bien_type_resolve() écrit id_type_bien — sinon les
    // ids ne correspondent pas (constaté : base_types_bien.appartement=1
    // alors que types_bien.appartement=2 → JOIN faux → bouton type incohérent
    // après autosave). Source de vérité legacy = types_bien_legacy (ou
    // types_bien si la migration de rename n'est pas encore appliquée).
    $legacyTable = 'types_bien_legacy';
    $legacyLabelCol = 'libelle';
    try {
        $exists = (bool)$pdo->query("SHOW TABLES LIKE 'types_bien_legacy'")->fetchColumn();
        if (!$exists) {
            $existsOld = (bool)$pdo->query("SHOW TABLES LIKE 'types_bien'")->fetchColumn();
            if ($existsOld) {
                $legacyTable = 'types_bien';
            } else {
                // Dernier fallback : base_types_bien (col label, pas libelle)
                $legacyTable = 'base_types_bien';
                $legacyLabelCol = 'label';
            }
        }
    } catch (Throwable) {}

    $sql = "
        SELECT
            b.*,
            COALESCE(bt.code,    btb.code)    AS _type_bien_code,
            COALESCE(bt.libelle, btb.{$legacyLabelCol})   AS _type_bien_libelle,
            i.adresse_1 AS _imm_adresse_1,
            i.adresse_2 AS _imm_adresse_2,
            i.code_postal AS _imm_code_postal,
            i.ville AS _imm_ville,
            i.pays AS _imm_pays,
            i.latitude AS _imm_latitude,
            i.longitude AS _imm_longitude,
            i.nb_lots AS _imm_nb_lots,
            -- Champs ALUR statut juridique copropriété (info commune à l'immeuble)
            i.copro_procedure                  AS _imm_copro_procedure,
            i.alur_copropriete_plan_sauvegarde AS _imm_alur_copropriete_plan_sauvegarde,
            i.alur_copropriete_etat_carence    AS _imm_alur_copropriete_etat_carence,
            -- Infos communes de la copropriété (niveau immeuble, migration 2026-06-17)
            i.copro_nb_lots                    AS _imm_copro_nb_lots,
            i.copro_budget_previsionnel_annuel AS _imm_copro_budget_previsionnel_annuel,
            i.copro_tantiemes_total            AS _imm_copro_tantiemes_total
        FROM biens b
        LEFT JOIN bien_types       bt  ON bt.id  = b.id_bien_type
        LEFT JOIN {$legacyTable}   btb ON btb.id = b.id_type_bien
        LEFT JOIN immeubles        i   ON i.id   = b.id_immeuble
        WHERE b.id = :id
        " . ($idSociete !== null ? " AND (b.id_societe = :soc OR b.id_societe IS NULL)" : "") . "
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $idBien, PDO::PARAM_INT);
    if ($idSociete !== null) {
        $stmt->bindValue(':soc', $idSociete, PDO::PARAM_INT);
    }
    $stmt->execute();
    $bien = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$bien) return null;

    // Annonce principale (s'il y en a une) — la plus récente
    $stmtAnn = $pdo->prepare("
        SELECT * FROM annonces
        WHERE id_bien = :id
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmtAnn->execute([':id' => $idBien]);
    $annonce = $stmtAnn->fetch(PDO::FETCH_ASSOC) ?: [];

    // Fusion : les colonnes annonces ne doivent jamais écraser celles de biens
    // si elles portent le même nom (très peu probable, mais sécurité).
    foreach ($annonce as $k => $v) {
        if (!array_key_exists($k, $bien)) {
            $bien[$k] = $v;
        } else {
            $bien['_ann_' . $k] = $v;
        }
    }

    // Garde la trace de l'id annonce séparément
    $bien['_annonce_id'] = (int)($annonce['id'] ?? 0);

    return $bien;
}

/**
 * Pré-remplit $_POST avec les valeurs chargées du bien, sauf si une valeur
 * existe déjà dans $_POST (préserve la re-saisie après erreur de validation).
 *
 * Booléens normalisés : tinyint 1 → '1', tinyint 0 → '' (et non '0', car
 * le helper boolChip teste === '1').
 */
function bien_form_populate_post(array $loaded): void
{
    // Liste des colonnes booléennes connues — celles dont le widget UI
    // dans bien_ajouter.php est un boolChip / checkbox value="1".
    static $boolCols = [
        'adresse_visible_public','dernier_etage','ascenseur','interphone','digicode',
        'alarme','climatisation','fibre','double_vitrage','volets_roulants','cheminee',
        'balcon','terrasse','jardin','cour','cave','grenier','garage','box','piscine',
        'dependances','acces_camion','vitrine','cuisine_equipee','bien_en_copropriete',
        'copro_procedure','louable_immediatement','meuble','animaux_acceptes',
        'travaux_a_prevoir','loyer_meuble','fumeur_accepte','disponible_de_suite',
        // Ubiflow
        'dpe_vierge','alur_copropriete_plan_sauvegarde','alur_copropriete_etat_carence',
        'zone_georisque','obligation_debroussaillement',
        'honoraires_charge_acquereur','honoraires_charge_vendeur',
        'zone_encadrement_loyer','loyer_est_cc',
        // Champs saisis sur la fiche bien mais persistés sur la table immeubles
        // (aliases _imm_*) — normalisation tinyint identique aux booléens classiques.
        '_imm_copro_procedure', '_imm_alur_copropriete_plan_sauvegarde',
        '_imm_alur_copropriete_etat_carence',
    ];

    // Aliases : colonne BDD ≠ nom de champ HTML
    $aliases = [
        'hauteur_sous_plafond' => 'hauteur_plafond',
        '_imm_adresse_1'       => 'adresse_1',
        '_imm_adresse_2'       => 'adresse_2',
        '_imm_code_postal'     => 'code_postal',
        '_imm_ville'           => 'ville',
        '_imm_pays'            => 'pays',
        '_imm_latitude'        => 'latitude',
        '_imm_longitude'       => 'longitude',
        '_type_bien_code'      => 'type_bien',
        // Biens
        'disponible_le'        => 'disponibilite_date',
        // Annonce
        'type_transaction'     => 'annonce_transaction',
        'prix'                 => 'annonce_prix_vente',
        'loyer'                => 'annonce_loyer',
        'id_user'              => 'annonce_commercial_id',
    ];

    foreach ($loaded as $k => $v) {
        if ($v === null) continue;
        $field = $aliases[$k] ?? $k;
        if (str_starts_with($field, '_')) continue; // ignore les helpers techniques
        if (array_key_exists($field, $_POST)) continue; // priorité à la re-saisie

        if (in_array($k, $boolCols, true)) {
            $_POST[$field] = ((int)$v === 1) ? '1' : '';
        } elseif ($v instanceof DateTimeInterface) {
            $_POST[$field] = $v->format('Y-m-d');
        } else {
            $_POST[$field] = (string)$v;
        }
    }

    // ─────────────────────────────────────────────────────────
    // Règle métier : le prix de l'annonce DOIT venir du prix du bien
    // en priorité. Le prix du bien est la source de vérité — l'annonce
    // en hérite (et peut être modifiée ; voir writeback dans bien_ajouter.php
    // pour alimenter le bien à partir de l'annonce si le bien était vide).
    //
    // Fallback :
    //   - `annonce_prix_vente`  ← `biens.prix_vente_estime`  (si annonce vide)
    //   - `annonce_loyer`       ← `biens.loyer_hc`           (si annonce vide)
    //   - `charges_locatives`   ← `biens.charges_locatives`  (direct, déjà géré
    //                                                          par le loop ci-dessus)
    //
    // Ces fallbacks ne remplacent PAS une valeur déjà saisie côté annonce :
    // si l'annonce porte son propre prix, il prime sur celui du bien.
    // ─────────────────────────────────────────────────────────
    if (empty($_POST['annonce_prix_vente']) && !empty($loaded['prix_vente_estime'])) {
        $_POST['annonce_prix_vente'] = (string)$loaded['prix_vente_estime'];
    }
    if (empty($_POST['annonce_loyer']) && !empty($loaded['loyer_hc'])) {
        $_POST['annonce_loyer'] = (string)$loaded['loyer_hc'];
    }

    // ─────────────────────────────────────────────────────────
    // Conversion biens.vue (CSV de codes) → vue_ids[] pour pré-cocher
    // les chips du widget bien_detail. Le stockage unifié est CSV de
    // codes (ex : 'degagee,eau'), les IDs dépendent de la société.
    // ─────────────────────────────────────────────────────────
    if (empty($_POST['vue_ids']) && !empty($loaded['vue']) && !empty($loaded['id_societe'])) {
        try {
            $codes = array_values(array_filter(array_map('trim', explode(',', (string)$loaded['vue']))));
            if (!empty($codes)) {
                /** @var PDO $pdo */
                $pdo = $GLOBALS['pdo'] ?? null;
                if ($pdo instanceof PDO) {
                    $ph = implode(',', array_fill(0, count($codes), '?'));
                    $st = $pdo->prepare("SELECT id FROM societe_vues WHERE id_societe = ? AND code IN ($ph) AND actif = 1");
                    $st->execute(array_merge([(int)$loaded['id_societe']], $codes));
                    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
                    if (!empty($ids)) {
                        $_POST['vue_ids'] = $ids;
                    }
                }
            }
        } catch (Throwable) {}
    }
}

/**
 * Crée immédiatement un bien minimal en statut "brouillon" et renvoie son id.
 * Utilisé par l'action "Créer un bien" depuis bien_liste.php.
 *
 * $idUser : commercial attribué automatiquement (créateur du bien).
 * Est propagé sur biens.id_user_actuel ET sur la pré-annonce annonces.id_user,
 * modifiable ensuite via le select "Commercial attribué" de la Card Diffusion.
 */
function bien_form_create_draft(PDO $pdo, ?int $idSociete, ?int $idAgence, ?int $idTypeBienDefault = null, ?int $idUser = null): int
{
    require_once __DIR__ . '/bien_type_helper.php';

    // Migration 20260430_bien_types : on travaille en priorité sur bien_types.
    // Le code par défaut "appartement" est le 1er ordre d'affichage. On
    // résout aussi l'id legacy correspondant pour alimenter id_type_bien
    // (rétrocompat / FK historique préservée).
    $idBienType = bien_type_default_id($pdo);
    $resolved   = bien_type_resolve($pdo, bien_type_code_by_id($pdo, $idBienType));
    $idTypeBienLegacy = $resolved['id_type_bien']; // legacy
    if ($idTypeBienDefault !== null && $idTypeBienDefault > 0) {
        // Override explicite (rare) : conserver l'id legacy passé en argument.
        $idTypeBienLegacy = $idTypeBienDefault;
    }

    // Référence temporaire unique (modifiable ensuite par l'utilisateur)
    $tempRef = 'TMP-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

    // URL tarifs publics par défaut (obligation légale d'afficher le barème
    // d'honoraires sur tous les supports). Surchargeable par bien.
    // Défensif : on n'ajoute la colonne que si elle existe (migration
    // 20260527_biens_url_tarifs_publics_default peut ne pas être passée).
    $defaultTarifUrl = 'https://maboximmo.fr/tarifs.php';
    $hasTarifCol = false;
    try {
        $hasTarifCol = (bool)$pdo->query("SHOW COLUMNS FROM biens LIKE 'url_tarifs_publics'")->fetchColumn();
    } catch (Throwable $e) {}

    if ($hasTarifCol) {
        $stmt = $pdo->prepare("
            INSERT INTO biens (id_societe, id_agence, id_user_actuel, id_type_bien, id_bien_type, statut_bien, reference_bien, designation, url_tarifs_publics, date_creation)
            VALUES (:soc, :ag, :usr, :type, :type_new, 'brouillon', :ref, :des, :url, NOW())
        ");
        $stmt->execute([
            ':soc'      => $idSociete,
            ':ag'       => $idAgence,
            ':usr'      => $idUser ?: null,
            ':type'     => $idTypeBienLegacy,
            ':type_new' => $idBienType ?: null,
            ':ref'      => $tempRef,
            ':des'      => 'Brouillon créé le ' . date('d/m/Y H:i'),
            ':url'      => $defaultTarifUrl,
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO biens (id_societe, id_agence, id_user_actuel, id_type_bien, id_bien_type, statut_bien, reference_bien, designation, date_creation)
            VALUES (:soc, :ag, :usr, :type, :type_new, 'brouillon', :ref, :des, NOW())
        ");
        $stmt->execute([
            ':soc'      => $idSociete,
            ':ag'       => $idAgence,
            ':usr'      => $idUser ?: null,
            ':type'     => $idTypeBienLegacy,
            ':type_new' => $idBienType ?: null,
            ':ref'      => $tempRef,
            ':des'      => 'Brouillon créé le ' . date('d/m/Y H:i'),
        ]);
    }
    $bienId = (int)$pdo->lastInsertId();

    // Annonce minimale par défaut (type_transaction = 'location' par défaut, modifiable)
    // id_user = commercial attribué automatiquement (même que biens.id_user_actuel)
    try {
        $pdo->prepare("
            INSERT INTO annonces (id_bien, id_societe, id_agence, id_user, type_transaction, etat_publication, date_creation, date_modification)
            VALUES (?, ?, ?, ?, 'location', 'brouillon', NOW(), NOW())
        ")->execute([$bienId, $idSociete, $idAgence, $idUser ?: null]);
    } catch (Throwable $e) { error_log('[create_draft annonce] ' . $e->getMessage()); }

    // ─── Mandat GESTION socle automatique (Sprint MANDATS 2026-05-25) ───
    // Directive Emmanuel : tout bien doit avoir au minimum 1 mandat GESTION actif.
    // Le mandat GESTION ne bloque pas l'ajout ultérieur de mandats LOCATION ou VENTE.
    try {
        $numMandatG = 'AUTO-G-' . date('Y') . '-' . str_pad((string)$bienId, 5, '0', STR_PAD_LEFT);
        $pdo->prepare("
            INSERT INTO mandats
                (id_bien, id_agence, numero_mandat, type_mandat, nature_mandat, exclusif,
                 date_debut, statut, id_user, date_creation)
            VALUES (?, ?, ?, 'gestion', NULL, 0, CURDATE(), 'actif', ?, NOW())
        ")->execute([$bienId, $idAgence ?: null, $numMandatG, $idUser ?: null]);
    } catch (Throwable $e) { error_log('[create_draft mandat_gestion] ' . $e->getMessage()); }

    // Photo par défaut (placeholder) si elle existe sur disque
    try {
        $defaultPhoto = dirname(__DIR__) . '/uploads/defaults/bien_default.jpg';
        if (is_file($defaultPhoto)) {
            require_once __DIR__ . '/bien_photos_manager.php';
            $manager = new BienPhotosManager($pdo);
            $manager->ajouterPhoto($bienId, $idSociete ?: 0, $defaultPhoto, 'bien_default.jpg', null);
        }
    } catch (Throwable) {}

    return $bienId;
}
