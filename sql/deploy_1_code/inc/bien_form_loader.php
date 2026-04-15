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

    $sql = "
        SELECT
            b.*,
            tb.code AS _type_bien_code,
            i.adresse_1 AS _imm_adresse_1,
            i.adresse_2 AS _imm_adresse_2,
            i.code_postal AS _imm_code_postal,
            i.ville AS _imm_ville,
            i.pays AS _imm_pays,
            i.latitude AS _imm_latitude,
            i.longitude AS _imm_longitude
        FROM biens b
        LEFT JOIN types_bien tb ON tb.id = b.id_type_bien
        LEFT JOIN immeubles  i  ON i.id  = b.id_immeuble
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
        'travaux_a_prevoir','loyer_meuble','disponible_de_suite',
        // Ubiflow
        'dpe_vierge','alur_copropriete_plan_sauvegarde','alur_copropriete_etat_carence',
        'zone_georisque','obligation_debroussaillement',
        'honoraires_charge_acquereur','honoraires_charge_vendeur',
        'zone_encadrement_loyer','loyer_est_cc',
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
        // Annonce
        'type_transaction'     => 'annonce_transaction',
        'prix'                 => 'annonce_prix_vente',
        'loyer'                => 'annonce_loyer',
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
}

/**
 * Crée immédiatement un bien minimal en statut "brouillon" et renvoie son id.
 * Utilisé par l'action "Créer un bien" depuis bien_liste.php.
 */
function bien_form_create_draft(PDO $pdo, ?int $idSociete, ?int $idAgence, ?int $idTypeBienDefault = null): int
{
    if ($idTypeBienDefault === null || $idTypeBienDefault <= 0) {
        // Récupère le premier type actif (en général : appartement)
        $idTypeBienDefault = (int)($pdo->query("SELECT id FROM types_bien WHERE actif = 1 ORDER BY ordre_affichage, id LIMIT 1")->fetchColumn() ?: 1);
    }

    // Référence temporaire unique (modifiable ensuite par l'utilisateur)
    $tempRef = 'TMP-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

    $stmt = $pdo->prepare("
        INSERT INTO biens (id_societe, id_agence, id_type_bien, statut_bien, reference_bien, designation, date_creation)
        VALUES (:soc, :ag, :type, 'brouillon', :ref, :des, NOW())
    ");
    $stmt->execute([
        ':soc'  => $idSociete,
        ':ag'   => $idAgence,
        ':type' => $idTypeBienDefault,
        ':ref'  => $tempRef,
        ':des'  => 'Brouillon créé le ' . date('d/m/Y H:i'),
    ]);
    $bienId = (int)$pdo->lastInsertId();

    // Annonce minimale par défaut (type_transaction = 'location' par défaut, modifiable)
    try {
        $pdo->prepare("
            INSERT INTO annonces (id_bien, type_transaction, statut, date_creation, date_modification)
            VALUES (?, 'location', 'brouillon', NOW(), NOW())
        ")->execute([$bienId]);
    } catch (Throwable) {}

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
