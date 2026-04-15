<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/bien_form_loader.php';
require_once __DIR__ . '/inc/ubiflow_validator.php';
require_login();

$appLayout = true;
$pageTitle = 'Ajouter un bien';
$bodyClass = '';
$robots = 'noindex, nofollow';
$includeGooglePlaces = true;
$includeGoogleMapsJs = true;

$errors = [];
$success = '';

// ════════════════════════════════════════════════════════════
// MODE BROUILLON / ÉDITION — handlers en amont du POST principal
// ════════════════════════════════════════════════════════════

// (1) Action « Créer un brouillon » — déclenchée par bien_liste.php
//     POST minimaliste qui crée la ligne en BDD et redirige vers ?edit=ID
if (is_post() && (string)post('_action', '') === 'new_draft') {
    verify_csrf('ajouter_bien');
    try {
        $newId = bien_form_create_draft(
            $pdo,
            isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null,
            isset($_SESSION['id_agence'])  ? (int)$_SESSION['id_agence']  : null
        );
        header('Location: ' . app_url('/bien_ajouter.php?edit=' . $newId . '&new=1'));
        exit;
    } catch (Throwable $e) {
        $errors[] = 'Impossible de créer le brouillon : ' . $e->getMessage();
    }
}

// (2) Mode édition — détection du paramètre ?edit=ID
//     Charge le bien et pré-remplit $_POST avec ses valeurs (sauf si POST en cours)
$editingBienId = isset($_GET['edit']) && ctype_digit((string)$_GET['edit']) ? (int)$_GET['edit'] : 0;
$bienLoaded    = null;
$annonceIdLoaded = 0;
if ($editingBienId > 0) {
    $bienLoaded = bien_form_load_record(
        $pdo,
        $editingBienId,
        isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null
    );
    if ($bienLoaded === null) {
        // Bien introuvable ou hors-périmètre → redirection liste
        header('Location: ' . app_url('/bien_liste.php?err=bien_introuvable'));
        exit;
    }
    $annonceIdLoaded = (int)($bienLoaded['_annonce_id'] ?? 0);
    // Pré-remplissage UNIQUEMENT en GET (en POST, on garde la saisie utilisateur)
    if (!is_post()) {
        bien_form_populate_post($bienLoaded);
    }
    $pageTitle = 'Modifier un bien';
}
$isEditing = $editingBienId > 0;
$isDraft   = $isEditing && (($bienLoaded['statut_bien'] ?? '') === 'brouillon');

// Chargement des photos déjà présentes en bibliothèque (mode édition).
// On récupère aussi l'analyse IA Vision (description_ia, categorie) produite
// à l'upload via api/bien_intake_photo_upload.php → analyserPhotoBien().
// Expose une liste JSON vers le JS pour :
//   - pré-afficher la grille photos avec légende analyse
//   - alimenter le récap dans l'onglet Description
//   - enrichir le prompt ChatGPT de génération de descriptif
$existingBienPhotos = [];
if ($isEditing && $bienLoaded !== null) {
    // Première tentative avec les colonnes IA (migration migration_biens_photos_ia.sql)
    // Fallback sur les colonnes de base si la migration n'a pas encore été passée.
    $rows = null;
    try {
        $stmtPh = $pdo->prepare("
            SELECT id, ordre, url_photo, nom_original, largeur, hauteur, poids_octets,
                   categorie, description_ia, description_ia_date
            FROM biens_photos
            WHERE id_bien = ?
            ORDER BY ordre ASC, id ASC
        ");
        $stmtPh->execute([$editingBienId]);
        $rows = $stmtPh->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Colonnes IA absentes — fallback sans analyse
        error_log('[bien_ajouter] fallback photos sans colonnes IA: ' . $e->getMessage());
        try {
            $stmtPh = $pdo->prepare("
                SELECT id, ordre, url_photo, nom_original, largeur, hauteur, poids_octets
                FROM biens_photos
                WHERE id_bien = ?
                ORDER BY ordre ASC, id ASC
            ");
            $stmtPh->execute([$editingBienId]);
            $rows = $stmtPh->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e2) {
            error_log('[bien_ajouter] load existing photos: ' . $e2->getMessage());
            $rows = [];
        }
    }
    foreach (($rows ?? []) as $ph) {
        $existingBienPhotos[] = [
            'id'             => (int)$ph['id'],
            'ordre'          => (int)($ph['ordre'] ?? 0),
            'url'            => app_url('/' . $ph['url_photo']),
            'nom'            => $ph['nom_original'] ?? null,
            'largeur'        => (int)($ph['largeur'] ?? 0),
            'hauteur'        => (int)($ph['hauteur'] ?? 0),
            'poids'          => (int)($ph['poids_octets'] ?? 0),
            'categorie'      => $ph['categorie'] ?? null,
            'description_ia' => $ph['description_ia'] ?? null,
            'analyse_date'   => $ph['description_ia_date'] ?? null,
        ];
    }
}

// Calcul de la complétude Ubiflow + admin (mode édition uniquement)
$ubiCheck = null;
if ($isEditing && $bienLoaded !== null) {
    $annonceForCheck = [];
    if ($annonceIdLoaded > 0) {
        $stmtAnn = $pdo->prepare("SELECT * FROM annonces WHERE id = ? LIMIT 1");
        $stmtAnn->execute([$annonceIdLoaded]);
        $annonceForCheck = $stmtAnn->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    // CORRECTION #5 (audit V2) : le validator de complétude compte les
    // photos de la bibliothèque du bien (biens_photos), pas les photos
    // d'une annonce. L'exporter Ubiflow, lui, compte annonces_photos.
    $photosCount = ubiflow_count_photos_bien($pdo, $editingBienId);
    $ubiCheck = ubiflow_check_completude(
        $bienLoaded,
        $annonceForCheck,
        $photosCount,
        $pdo,
        isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null
    );
}

if (is_post()) {
    verify_csrf('ajouter_bien');

    $normalizeKey = static function (string $adresse1, string $adresse2, string $codePostal, string $ville): string {
        $parts = [
            trim(mb_strtolower($adresse1)),
            trim(mb_strtolower($adresse2)),
            trim(mb_strtolower($codePostal)),
            trim(mb_strtolower($ville)),
        ];
        return implode('|', $parts);
    };

    $immeubleIdPosted = (int)post('immeuble_id', 0);
    $adresse1 = trim((string)post('adresse_1', ''));
    $adresse2 = trim((string)post('adresse_2', ''));
    $codePostal = trim((string)post('code_postal', ''));
    $ville = trim((string)post('ville', ''));
    $pays = trim((string)post('pays', 'France'));
    $latitude = trim((string)post('latitude', ''));
    $longitude = trim((string)post('longitude', ''));
    $googlePlaceId = trim((string)post('google_place_id', ''));
    $adresseFormatee = trim((string)post('adresse_formatee', ''));

    // ── Helpers ──
    $str  = static fn($k, $d='') => trim((string)post($k, $d));
    $int  = static fn($k) => post($k,'') !== '' ? (int)post($k) : null;
    $flt  = static fn($k) => post($k,'') !== '' ? (float)post($k) : null;
    $bool = static fn($k) => post($k,'') !== '' ? (bool)(int)post($k) : 0;

    // ── Identification ──
    $proprietaireId  = post('id_proprietaire', '') !== '' ? (int)post('id_proprietaire') : null;
    $typeBienCode    = $str('type_bien', 'appartement');
    $referenceBien   = $str('reference_bien');
    $designation     = $str('designation');
    $sousType        = $str('sous_type_bien');
    $usageBien       = $str('usage_bien');
    $statutBien      = $str('statut_bien', 'actif');
    $typeComm        = $str('type_commercialisation');
    $referenceExt    = $str('reference_externe');
    $lotPrincipal    = $str('lot_principal');
    $lotSecondaire   = $str('lot_secondaire');
    $standing        = $str('standing');
    $etatBien        = $str('etat_bien');
    $disponibiliteBien = $str('disponibilite_bien');
    $occupationBien  = $str('occupation_bien');
    $louableImm      = post('louable_immediatement','') !== '' ? (int)post('louable_immediatement') : 0;

    // ── Caractéristiques principales ──
    $surfaceHabitable = $flt('surface_habitable');
    $surfaceCarrez    = $flt('surface_carrez');
    $surfaceSejour    = $flt('surface_sejour');
    $surfaceTotale    = $flt('surface_totale');
    $surfaceTerrain   = $flt('surface_terrain');
    $surfaceBalcon    = $flt('surface_balcon');
    $surfaceTerrasse  = $flt('surface_terrasse');
    $surfaceJardin    = $flt('surface_jardin');
    $surfaceCave      = $flt('surface_cave');
    $surfaceGarage    = $flt('surface_garage');
    $surfaceBox       = $flt('surface_box');
    $surfaceVeranda   = $flt('surface_veranda');
    $surfaceAnnexe    = $flt('surface_annexe');
    $hauteurPlafond   = $flt('hauteur_sous_plafond');
    $anneeConstruction= $int('annee_construction');
    $etage            = $int('etage');
    $nbNiveaux        = $int('nb_niveaux');
    $nbPieces         = $int('nb_pieces');
    $nbChambres       = $int('nb_chambres');
    $nbSallesBain     = $int('nb_salles_bain');
    $nbSallesEau      = $int('nb_salles_eau');
    $nbWc             = $int('nb_wc');
    $parkingNb        = $int('parking_nb');
    $numeroPorte      = $str('numero_porte');
    $cuisineType      = $str('cuisine_type');
    $cuisineEquipee   = $bool('cuisine_equipee');
    $dernierEtage     = $bool('dernier_etage');

    // ── Équipements (booleans) ──
    $ascenseur      = $bool('ascenseur');
    $interphone     = $bool('interphone');
    $digicode       = $bool('digicode');
    $alarme         = $bool('alarme');
    $climatisation  = $bool('climatisation');
    $fibre          = $bool('fibre');
    $doubleVitrage  = $bool('double_vitrage');
    $voletsRoulants = $bool('volets_roulants');
    $cheminee       = $bool('cheminee');
    $balcon         = $bool('balcon');
    $terrasse       = $bool('terrasse');
    $jardin         = $bool('jardin');
    $cour           = $bool('cour');
    $cave           = $bool('cave');
    $grenier        = $bool('grenier');
    $garageB        = $bool('garage');
    $box            = $bool('box');
    $piscine        = $bool('piscine');
    $dependances    = $bool('dependances');
    $accesCamion    = $bool('acces_camion');
    $vitrine        = $bool('vitrine');

    // ── Chauffage & énergie ──
    // Accepte tableau (mini-cards) ou valeur unique (fallback)
    $chauffageIds     = array_map('intval', (array)(post('chauffage_ids',   []) ?: []));
    $energieIds       = array_map('intval', (array)(post('energie_ids',     []) ?: []));
    $vueIds           = array_map('intval', (array)(post('vue_ids',         []) ?: []));
    $chauffageType    = $str('chauffage_type');   // premier code sélectionné (compat)
    $chauffageEnergie = $str('chauffage_energie'); // premier code sélectionné (compat)
    $eauChaudeType    = $str('eau_chaude_type');
    $menuiseries      = $str('menuiseries');
    $isolation        = $str('isolation');
    $chauffageVmc     = $bool('chauffage_vmc');
    $chauffageVmcDf   = $bool('chauffage_vmc_df');
    $chauffagePlancher= $bool('chauffage_plancher');
    $chauffageRegulat = $bool('chauffage_regulateur');
    $chauffageThermost= $bool('chauffage_thermostat');
    $eauChaudeSolaire = $bool('eau_chaude_solaire');

    // ── Exposition / environnement ──
    $exposition   = $str('exposition');
    $vue          = $str('vue');
    // compat varchar : résoudre le code depuis le 1er vue_id si vue non posté
    if (empty($vue) && !empty($vueIds)) {
        try {
            $svSt = $pdo->prepare("SELECT code FROM societe_vues WHERE id = ? LIMIT 1");
            $svSt->execute([$vueIds[0]]);
            $vue = (string)($svSt->fetchColumn() ?: '');
        } catch (Throwable) {}
    }
    $nuisances    = $str('nuisances');
    $adresseVisible = $bool('adresse_visible_public');
    // ── Situation pratique (transports + commerces) ──
    $accesTransports   = $str('acces_transports');     // moins_5|moins_10|moins_15|plus_20
    $distanceCommerces = $str('distance_commerces');   // moins_200|moins_400|moins_600|moins_800|plus_1200
    $_validTransports = ['moins_5','moins_10','moins_15','plus_20'];
    $_validCommerces  = ['moins_200','moins_400','moins_600','moins_800','plus_1200'];
    if ($accesTransports !== '' && !in_array($accesTransports, $_validTransports, true))   $accesTransports = '';
    if ($distanceCommerces !== '' && !in_array($distanceCommerces, $_validCommerces, true)) $distanceCommerces = '';

    // ── DPE ──
    $dpeClasse       = $str('dpe_classe');
    $gesClasse       = $str('ges_classe');
    $dpeValeur       = $int('dpe_valeur');
    $gesValeur       = $int('ges_valeur');
    $depensesMin     = $flt('montant_estime_depenses_min');
    $depensesMax     = $flt('montant_estime_depenses_max');
    $anneeRefDep     = $int('annee_reference_depenses');

    // ── Prix / financier ──
    $loyerHc         = $flt('loyer_hc');
    $chargesLoc      = $flt('charges_locatives');
    $depotGarantie   = $flt('depot_garantie');
    $loyerMeuble     = $bool('loyer_meuble');
    $animauxAcceptes = $bool('animaux_acceptes');
    $honorairesLoc   = $flt('honoraires_locataire');
    $prixVente       = $flt('prix_vente');
    $honorairesInclus= $str('honoraires_inclus');
    $honorairesDetail= $str('honoraires_detail');
    $taxeFonciere    = $flt('taxe_fonciere');
    $taxeHabitation  = $flt('taxe_habitation');
    $travauxAPrevoir = $bool('travaux_a_prevoir');
    $montantTravaux  = $flt('montant_travaux_estime');
    $rentabiliteBrute= $flt('rentabilite_brute_estimee');
    $prixVenteEstime         = $flt('prix_vente_estime');
    $estimationAgenceVente   = $flt('estimation_agence_vente');
    $estimationAgenceLocation = $flt('estimation_agence_location');
    $estimationAgenceDate    = $str('estimation_agence_date') ?: null;
    $estimationAgenceNotes   = $str('estimation_agence_notes') ?: null;
    $encZone         = $str('enc_zone');
    $encLoyerRef     = $flt('enc_loyer_ref');
    $encLoyerMin     = $flt('enc_loyer_min');
    $encLoyerMax     = $flt('enc_loyer_max');
    $encComplement   = $flt('enc_complement');

    // ── Copropriété ──
    $bienEnCopro     = $bool('bien_en_copropriete');
    $coproNbLots     = $int('copro_nb_lots');
    $coproCharges    = $flt('copro_quote_part_charges');
    $coproProcedure  = $bool('copro_procedure');
    $syndicType      = $str('syndic_type');
    $coproTravaux    = $str('copro_travaux_nature');

    // ════════════════════════════════════════════════════════════
    // ── DIFFUSION / CONFORMITÉ UBIFLOW (Le Bon Coin, SeLoger…) ──
    // ════════════════════════════════════════════════════════════
    // DPE compléments légaux (arrêtés 2021/2022/2024)
    $dpeDateRealisation       = $str('dpe_date_realisation');
    $dpeVersion               = $str('dpe_version');                  // '2011' | '2021'
    $dpeVierge                = $bool('dpe_vierge');
    $dpeValeurConsoPrimaire   = $flt('dpe_valeur_conso_primaire');
    $dpeValeurConsoFinale     = $flt('dpe_valeur_conso_finale');
    $dateIndicePrixEnergies   = $str('date_indice_prix_energies');
    $altitude                 = $int('altitude');
    $dpeReferenceCertificat   = $str('dpe_reference_certificat');

    // ALUR copropriété compléments
    $alurSyndicatStatut       = $str('alur_syndicat_statut');         // administrateur provisoire | mandataire ad hoc | expert
    $alurCoproPlanSauvegarde  = $bool('alur_copropriete_plan_sauvegarde');
    $alurCoproEtatCarence     = $bool('alur_copropriete_etat_carence');

    // ERP / Géorisques (obligations 2023 et 2025)
    $zoneGeorisque            = $bool('zone_georisque');
    $obligationDebroussaillement = $bool('obligation_debroussaillement');
    $erpDateRealisation       = $str('erp_date_realisation');
    $risqueInondationG        = $str('risque_inondation_g_score');    // A | B | C | D
    $risqueInondationP        = $str('risque_inondation_p_score');    // A | B | C | D

    // Annonce — Honoraires ALUR détaillés
    $honorairesChargeAcq      = $bool('honoraires_charge_acquereur');
    $honorairesChargeVend     = $bool('honoraires_charge_vendeur');
    $alurPctHonorairesTtc     = $flt('alur_pourcentage_honoraires_ttc');
    $pctHonorairesVendeur     = $flt('pourcentage_honoraires_vendeur');
    $honorairesNegoCumules    = $flt('honoraires_negociation_cumules');
    $urlTarifsPublics         = $str('url_tarifs_publics');

    // Annonce — Encadrement loyers (Paris/Lille)
    $zoneEncadrementLoyer     = $bool('zone_encadrement_loyer');
    $loyerDeBase              = $flt('loyer_de_base');
    $loyerEstCc               = $bool('loyer_est_cc');
    $loyerReferenceMajore     = $flt('loyer_reference_majore');
    $modaliteRecupCharges     = $str('modalite_recuperation_charges_locatives'); // forfait | provision annuelle | remboursement sur justificatifs
    $honorairesEtatDesLieux   = $flt('honoraires_etat_des_lieux');
    $complementLoyer          = $flt('complement_loyer');

    // Annonce — Mandat
    $mandatNumero             = $str('mandat_numero');
    $mandatType               = $str('mandat_type');                  // exclusif | simple
    $dateMandat               = $str('date_mandat');
    $mandatEcheance           = $str('mandat_echeance');

    // Annonce — Locataire précédent (Loi Alur)
    $ancienLoyerMontant       = $flt('ancien_loyer_montant');
    $ancienLoyerCharges       = $flt('ancien_loyer_charges');
    $ancienLoyerDateRevision  = $str('ancien_loyer_date_revision');
    $ancienLocataireDateSortie = $str('ancien_locataire_date_sortie');
    $ancienLoyerCommunique    = $bool('ancien_loyer_communique');

    // Annonce — Lignes de complément de loyer (justifications)
    $cplLibelles = isset($_POST['cpl_libelle']) && is_array($_POST['cpl_libelle']) ? $_POST['cpl_libelle'] : [];
    $cplMontants = isset($_POST['cpl_montant']) && is_array($_POST['cpl_montant']) ? $_POST['cpl_montant'] : [];

    // Mandat (table mandats — distinct des champs annonces.mandat_*)
    $mandatsNumero        = $str('mandats_numero');
    $mandatsType          = $str('mandats_type');
    $mandatsNature        = $str('mandats_nature');
    $mandatsDateSignature = $str('mandats_date_signature');
    $mandatsDateDebut     = $str('mandats_date_debut');
    $mandatsDateFin       = $str('mandats_date_fin');
    $mandatsHonoraires    = $flt('mandats_honoraires');

    // ── Description / SEO ──
    $description       = $str('description');
    $repriseDescriptif = $str('reprise_descriptif');
    $titreSeo          = $str('titre_seo');
    $metaDescription   = $str('meta_description');
    $accrocheComm      = $str('accroche_commerciale');
    $pointsForts       = $str('points_forts');
    $motsCles          = $str('mots_cles');
    $commentaire       = $str('commentaire');

    // ── Annonce ──
    $annonceCommercialId = post('annonce_commercial_id', '') !== '' ? (int)post('annonce_commercial_id') : null;
    $annonceTransaction  = $str('annonce_transaction');
    $annoncePrixVente    = post('annonce_prix_vente', '') !== '' ? (float)post('annonce_prix_vente') : null;
    $annonceLoyer        = post('annonce_loyer', '') !== '' ? (float)post('annonce_loyer') : null;
    $texteIa             = $str('texte_ia');  // Texte généré par l'IA (Lot 4)

    // ─────────────────────────────────────────────────────
    // Règle métier : le prix du bien est la source de vérité.
    //   - Si le bien n'a pas de prix renseigné (prix_vente_estime / loyer_hc
    //     vides dans le form) mais que l'annonce en porte un, on recopie
    //     automatiquement dans le bien pour qu'il devienne la référence.
    //   - Si les deux sont renseignés, chacun garde sa valeur (ils peuvent
    //     différer volontairement — voir rôle de l'annonce commerciale).
    //   - À l'inverse, au chargement (GET), le loader remplit
    //     $_POST['annonce_prix_vente'] depuis biens.prix_vente_estime si
    //     l'annonce n'a rien : cf. bien_form_populate_post().
    // ─────────────────────────────────────────────────────
    if (($prixVenteEstime === null || (float)$prixVenteEstime === 0.0) && $annoncePrixVente !== null) {
        $prixVenteEstime = $annoncePrixVente;
    }
    if (($loyerHc === null || (float)$loyerHc === 0.0) && $annonceLoyer !== null) {
        $loyerHc = $annonceLoyer;
    }

    // En mode brouillon, AUCUNE validation bloquante — l'utilisateur sauvegarde
    // librement et corrige progressivement. Les manques sont signalés via le
    // bandeau de conformité Ubiflow plus tard, sans bloquer l'enregistrement.
    if (!$isDraft) {
        if ($adresse1 === '' || $codePostal === '' || $ville === '') {
            $errors[] = 'Adresse, code postal et ville sont obligatoires.';
        }

        if ($designation === '') {
            $errors[] = 'La désignation commerciale est obligatoire. Format attendu : type + atout principal en 50–80 caractères. Ex : "T3 lumineux avec balcon vue dégagée".';
        } elseif (mb_strlen($designation) < 50) {
            $errors[] = 'La désignation commerciale doit faire au moins 50 caractères (actuellement ' . mb_strlen($designation) . '). Ex : "T3 lumineux avec balcon vue dégagée, calme, centre-ville".';
        }
    }

    // L'annonce est optionnelle : on ne crée l'annonce que si un type de transaction est choisi
    // Aucune validation bloquante — on peut créer un bien sans annonce
    $annonceHasAny = in_array($annonceTransaction, ['vente','location'], true);

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $stmtType = $pdo->prepare("SELECT id FROM types_bien WHERE code = :code LIMIT 1");
            $stmtType->execute([':code' => $typeBienCode]);
            $typeRow = $stmtType->fetch(PDO::FETCH_ASSOC);
            $typeBienId = (int)($typeRow['id'] ?? 0);
            if ($typeBienId <= 0) {
                $typeBienId = 1;
            }

            // ── Gestion immeuble ──
            // En mode édition, si le bien a déjà un id_immeuble et que
            // l'adresse n'a pas été touchée, on conserve l'existant.
            $immeubleId = $immeubleIdPosted > 0 ? $immeubleIdPosted : 0;
            if ($immeubleId <= 0 && $isEditing && !empty($bienLoaded['id_immeuble'])) {
                $immeubleId = (int)$bienLoaded['id_immeuble'];
            }
            $adresseCle = $normalizeKey($adresse1, $adresse2, $codePostal, $ville);

            if ($immeubleId <= 0 && $adresse1 !== '') {
                // Tente de retrouver un immeuble existant via la clé d'adresse
                $stmtImmeuble = $pdo->prepare("
                    SELECT id FROM immeubles WHERE adresse_cle = :cle LIMIT 1
                ");
                $stmtImmeuble->execute([':cle' => $adresseCle]);
                $immeubleId = (int)($stmtImmeuble->fetchColumn() ?: 0);
            }

            if ($immeubleId <= 0 && $adresse1 !== '') {
                // Crée un nouvel immeuble UNIQUEMENT si l'adresse minimale est fournie
                $stmtInsertImmeuble = $pdo->prepare("
                    INSERT INTO immeubles
                        (id_societe, id_agence, adresse_1, adresse_2, adresse_cle, code_postal, ville, pays, latitude, longitude)
                    VALUES
                        (:id_societe, :id_agence, :adresse_1, :adresse_2, :adresse_cle, :code_postal, :ville, :pays, :latitude, :longitude)
                ");
                $stmtInsertImmeuble->execute([
                    ':id_societe' => $_SESSION['id_societe'] ?? null,
                    ':id_agence'  => $_SESSION['id_agence']  ?? null,
                    ':adresse_1'  => $adresse1,
                    ':adresse_2'  => $adresse2 !== '' ? $adresse2 : null,
                    ':adresse_cle'=> $adresseCle,
                    ':code_postal'=> $codePostal !== '' ? $codePostal : null,
                    ':ville'      => $ville !== '' ? $ville : null,
                    ':pays'       => $pays !== '' ? $pays : 'France',
                    ':latitude'   => $latitude !== '' ? $latitude : null,
                    ':longitude'  => $longitude !== '' ? $longitude : null,
                ]);
                $immeubleId = (int)$pdo->lastInsertId();
            }
            // Si on n'a toujours pas d'immeuble (brouillon sans adresse), on
            // accepte id_immeuble = NULL. Le bien reste sauvegardable.
            $immeubleIdForBien = $immeubleId > 0 ? $immeubleId : null;

            $stmtInsertBien = $pdo->prepare("
                INSERT INTO biens (
                    id_immeuble, id_societe, id_agence, id_type_bien, id_proprietaire,
                    reference_bien, reference_externe, designation,
                    sous_type_bien, usage_bien, statut_bien, type_commercialisation,
                    lot_principal, lot_secondaire, standing, etat_bien,
                    disponibilite_bien, occupation_bien, louable_immediatement,
                    -- Localisation
                    adresse_visible_public, exposition, vue, nuisances,
                    -- Surfaces
                    surface_habitable, surface_carrez, surface_sejour, surface_totale, surface_terrain,
                    surface_balcon, surface_terrasse, surface_jardin, surface_cave,
                    surface_garage, surface_box, surface_veranda, surface_annexe,
                    hauteur_sous_plafond, annee_construction,
                    -- Pièces
                    etage, nb_niveaux, nb_pieces, nb_chambres, nb_salles_bain,
                    nb_salles_eau, nb_wc, parking_nb, numero_porte, dernier_etage,
                    cuisine_type, cuisine_equipee,
                    -- Équipements
                    ascenseur, interphone, digicode, alarme, climatisation, fibre,
                    double_vitrage, volets_roulants, cheminee,
                    balcon, terrasse, jardin, cour, cave, grenier,
                    garage, box, piscine, dependances, acces_camion, vitrine,
                    -- Chauffage
                    chauffage_type, chauffage_energie, eau_chaude_type,
                    menuiseries, isolation,
                    chauffage_vmc, chauffage_vmc_df, chauffage_plancher,
                    chauffage_regulateur, chauffage_thermostat, eau_chaude_solaire,
                    -- DPE
                    dpe_classe, ges_classe, dpe_valeur, ges_valeur,
                    montant_estime_depenses_min, montant_estime_depenses_max, annee_reference_depenses,
                    -- Prix / location
                    loyer_hc, charges_locatives, depot_garantie, loyer_meuble,
                    animaux_acceptes, honoraires_locataire,
                    -- Encadrement loyers
                    enc_zone, enc_loyer_ref, enc_loyer_min, enc_loyer_max, enc_complement,
                    -- Prix / vente
                    prix_vente_estime, rentabilite_brute_estimee,
                    travaux_a_prevoir, montant_travaux_estime,
                    -- Copropriété
                    bien_en_copropriete, copro_nb_lots, copro_quote_part_charges,
                    copro_procedure, syndic_type, copro_travaux_nature,
                    -- Diffusion / Conformité Ubiflow : DPE
                    dpe_date_realisation, dpe_version, dpe_vierge,
                    dpe_valeur_conso_primaire, dpe_valeur_conso_finale,
                    date_indice_prix_energies, altitude, dpe_reference_certificat,
                    -- Diffusion / Conformité Ubiflow : ALUR copro
                    alur_syndicat_statut, alur_copropriete_plan_sauvegarde, alur_copropriete_etat_carence,
                    -- Diffusion / Conformité Ubiflow : ERP / Géorisques
                    zone_georisque, obligation_debroussaillement, erp_date_realisation,
                    risque_inondation_g_score, risque_inondation_p_score,
                    -- Description / SEO
                    titre_seo, meta_description, accroche_commerciale,
                    description, points_forts, mots_cles, commentaire
                ) VALUES (
                    :id_immeuble, :id_societe, :id_agence, :id_type_bien, :id_proprietaire,
                    :reference_bien, :reference_externe, :designation,
                    :sous_type_bien, :usage_bien, :statut_bien, :type_commercialisation,
                    :lot_principal, :lot_secondaire, :standing, :etat_bien,
                    :disponibilite_bien, :occupation_bien, :louable_immediatement,
                    :adresse_visible_public, :exposition, :vue, :nuisances,
                    :surface_habitable, :surface_carrez, :surface_sejour, :surface_totale, :surface_terrain,
                    :surface_balcon, :surface_terrasse, :surface_jardin, :surface_cave,
                    :surface_garage, :surface_box, :surface_veranda, :surface_annexe,
                    :hauteur_plafond, :annee_construction,
                    :etage, :nb_niveaux, :nb_pieces, :nb_chambres, :nb_salles_bain,
                    :nb_salles_eau, :nb_wc, :parking_nb, :numero_porte, :dernier_etage,
                    :cuisine_type, :cuisine_equipee,
                    :ascenseur, :interphone, :digicode, :alarme, :climatisation, :fibre,
                    :double_vitrage, :volets_roulants, :cheminee,
                    :balcon, :terrasse, :jardin, :cour, :cave, :grenier,
                    :garage, :box, :piscine, :dependances, :acces_camion, :vitrine,
                    :chauffage_type, :chauffage_energie, :eau_chaude_type,
                    :menuiseries, :isolation,
                    :chauffage_vmc, :chauffage_vmc_df, :chauffage_plancher,
                    :chauffage_regulateur, :chauffage_thermostat, :eau_chaude_solaire,
                    :dpe_classe, :ges_classe, :dpe_valeur, :ges_valeur,
                    :depenses_min, :depenses_max, :annee_ref_dep,
                    :loyer_hc, :charges_loc, :depot_garantie, :loyer_meuble,
                    :animaux_acceptes, :honoraires_loc,
                    :enc_zone, :enc_loyer_ref, :enc_loyer_min, :enc_loyer_max, :enc_complement,
                    :prix_vente_estime, :rentabilite_brute,
                    :travaux_a_prevoir, :montant_travaux,
                    :bien_en_copro, :copro_nb_lots, :copro_charges,
                    :copro_procedure, :syndic_type, :copro_travaux,
                    :dpe_date_real, :dpe_version, :dpe_vierge,
                    :dpe_conso_primaire, :dpe_conso_finale,
                    :date_indice_prix, :altitude, :dpe_ref_cert,
                    :alur_synd_statut, :alur_plan_sauv, :alur_etat_carence,
                    :zone_georisque, :obligation_debrouss, :erp_date_real,
                    :risque_inond_g, :risque_inond_p,
                    :titre_seo, :meta_description, :accroche_comm,
                    :description, :points_forts, :mots_cles, :commentaire
                )
            ");
            $bienParams = [
                ':id_immeuble'          => $immeubleIdForBien,
                ':id_societe'           => $_SESSION['id_societe'] ?? null,
                ':id_agence'            => $_SESSION['id_agence'] ?? null,
                ':id_type_bien'         => $typeBienId,
                ':id_proprietaire'      => $proprietaireId,
                ':reference_bien'       => $referenceBien ?: null,
                ':reference_externe'    => $referenceExt ?: null,
                ':designation'          => $designation ?: null,
                ':sous_type_bien'       => $sousType ?: null,
                ':usage_bien'           => $usageBien ?: null,
                ':statut_bien'          => $statutBien ?: 'actif',
                ':type_commercialisation'=> $typeComm ?: null,
                ':lot_principal'        => $lotPrincipal ?: null,
                ':lot_secondaire'       => $lotSecondaire ?: null,
                ':standing'             => $standing ?: null,
                ':etat_bien'            => $etatBien ?: null,
                ':disponibilite_bien'   => $disponibiliteBien ?: null,
                ':occupation_bien'      => $occupationBien ?: null,
                ':louable_immediatement'=> $louableImm,
                ':adresse_visible_public'=> $adresseVisible,
                ':exposition'           => $exposition ?: null,
                ':vue'                  => $vue ?: null,
                ':nuisances'            => $nuisances ?: null,
                ':surface_habitable'    => $surfaceHabitable,
                ':surface_carrez'       => $surfaceCarrez,
                ':surface_sejour'       => $surfaceSejour,
                ':surface_totale'       => $surfaceTotale,
                ':surface_terrain'      => $surfaceTerrain,
                ':surface_balcon'       => $surfaceBalcon,
                ':surface_terrasse'     => $surfaceTerrasse,
                ':surface_jardin'       => $surfaceJardin,
                ':surface_cave'         => $surfaceCave,
                ':surface_garage'       => $surfaceGarage,
                ':surface_box'          => $surfaceBox,
                ':surface_veranda'      => $surfaceVeranda,
                ':surface_annexe'       => $surfaceAnnexe,
                ':hauteur_plafond'      => $hauteurPlafond,
                ':annee_construction'   => $anneeConstruction,
                ':etage'                => $etage,
                ':nb_niveaux'           => $nbNiveaux,
                ':nb_pieces'            => $nbPieces,
                ':nb_chambres'          => $nbChambres,
                ':nb_salles_bain'       => $nbSallesBain,
                ':nb_salles_eau'        => $nbSallesEau,
                ':nb_wc'                => $nbWc,
                ':parking_nb'           => $parkingNb,
                ':numero_porte'         => $numeroPorte ?: null,
                ':dernier_etage'        => $dernierEtage,
                ':cuisine_type'         => $cuisineType ?: null,
                ':cuisine_equipee'      => $cuisineEquipee,
                ':ascenseur'            => $ascenseur,
                ':interphone'           => $interphone,
                ':digicode'             => $digicode,
                ':alarme'               => $alarme,
                ':climatisation'        => $climatisation,
                ':fibre'                => $fibre,
                ':double_vitrage'       => $doubleVitrage,
                ':volets_roulants'      => $voletsRoulants,
                ':cheminee'             => $cheminee,
                ':balcon'               => $balcon,
                ':terrasse'             => $terrasse,
                ':jardin'               => $jardin,
                ':cour'                 => $cour,
                ':cave'                 => $cave,
                ':grenier'              => $grenier,
                ':garage'               => $garageB,
                ':box'                  => $box,
                ':piscine'              => $piscine,
                ':dependances'          => $dependances,
                ':acces_camion'         => $accesCamion,
                ':vitrine'              => $vitrine,
                ':chauffage_type'       => $chauffageType ?: null,
                ':chauffage_energie'    => $chauffageEnergie ?: null,
                ':eau_chaude_type'      => $eauChaudeType ?: null,
                ':menuiseries'          => $menuiseries ?: null,
                ':isolation'            => $isolation ?: null,
                ':chauffage_vmc'        => $chauffageVmc,
                ':chauffage_vmc_df'     => $chauffageVmcDf,
                ':chauffage_plancher'   => $chauffagePlancher,
                ':chauffage_regulateur' => $chauffageRegulat,
                ':chauffage_thermostat' => $chauffageThermost,
                ':eau_chaude_solaire'   => $eauChaudeSolaire,
                ':dpe_classe'           => $dpeClasse ?: null,
                ':ges_classe'           => $gesClasse ?: null,
                ':dpe_valeur'           => $dpeValeur,
                ':ges_valeur'           => $gesValeur,
                ':depenses_min'         => $depensesMin,
                ':depenses_max'         => $depensesMax,
                ':annee_ref_dep'        => $anneeRefDep,
                ':loyer_hc'             => $loyerHc,
                ':charges_loc'          => $chargesLoc,
                ':depot_garantie'       => $depotGarantie,
                ':loyer_meuble'         => $loyerMeuble,
                ':animaux_acceptes'     => $animauxAcceptes,
                ':honoraires_loc'       => $honorairesLoc,
                ':enc_zone'             => $encZone ?: null,
                ':enc_loyer_ref'        => $encLoyerRef,
                ':enc_loyer_min'        => $encLoyerMin,
                ':enc_loyer_max'        => $encLoyerMax,
                ':enc_complement'       => $encComplement,
                ':prix_vente_estime'    => $prixVenteEstime,
                ':rentabilite_brute'    => $rentabiliteBrute,
                ':travaux_a_prevoir'    => $travauxAPrevoir,
                ':montant_travaux'      => $montantTravaux,
                ':bien_en_copro'        => $bienEnCopro,
                ':copro_nb_lots'        => $coproNbLots,
                ':copro_charges'        => $coproCharges,
                ':copro_procedure'      => $coproProcedure,
                ':syndic_type'          => $syndicType ?: null,
                ':copro_travaux'        => $coproTravaux ?: null,
                // ── Conformité Ubiflow ──
                ':dpe_date_real'        => $dpeDateRealisation ?: null,
                ':dpe_version'          => $dpeVersion ?: null,
                ':dpe_vierge'           => $dpeVierge,
                ':dpe_conso_primaire'   => $dpeValeurConsoPrimaire,
                ':dpe_conso_finale'     => $dpeValeurConsoFinale,
                ':date_indice_prix'     => $dateIndicePrixEnergies ?: null,
                ':altitude'             => $altitude,
                ':dpe_ref_cert'         => $dpeReferenceCertificat ?: null,
                ':alur_synd_statut'     => $alurSyndicatStatut ?: null,
                ':alur_plan_sauv'       => $alurCoproPlanSauvegarde,
                ':alur_etat_carence'    => $alurCoproEtatCarence,
                ':zone_georisque'       => $zoneGeorisque,
                ':obligation_debrouss'  => $obligationDebroussaillement,
                ':erp_date_real'        => $erpDateRealisation ?: null,
                ':risque_inond_g'       => $risqueInondationG ?: null,
                ':risque_inond_p'       => $risqueInondationP ?: null,
                ':titre_seo'            => $titreSeo ?: null,
                ':meta_description'     => $metaDescription ?: null,
                ':accroche_comm'        => $accrocheComm ?: null,
                ':description'          => $description ?: null,
                ':points_forts'         => $pointsForts ?: null,
                ':mots_cles'            => $motsCles ?: null,
                ':commentaire'          => $commentaire ?: null,
            ];

            // ── Mode édition : UPDATE ; sinon INSERT ──
            // Mapping :placeholder → colonne (uniquement les divergences ;
            // pour tout le reste, on dérive automatiquement en supprimant le ':')
            $bienColMap = [
                ':hauteur_plafond'      => 'hauteur_sous_plafond',
                ':depenses_min'         => 'montant_estime_depenses_min',
                ':depenses_max'         => 'montant_estime_depenses_max',
                ':annee_ref_dep'        => 'annee_reference_depenses',
                ':charges_loc'          => 'charges_locatives',
                ':honoraires_loc'       => 'honoraires_locataire',
                ':rentabilite_brute'    => 'rentabilite_brute_estimee',
                ':montant_travaux'      => 'montant_travaux_estime',
                ':bien_en_copro'        => 'bien_en_copropriete',
                ':copro_charges'        => 'copro_quote_part_charges',
                ':copro_travaux'        => 'copro_travaux_nature',
                ':accroche_comm'        => 'accroche_commerciale',
                ':dpe_date_real'        => 'dpe_date_realisation',
                ':dpe_conso_primaire'   => 'dpe_valeur_conso_primaire',
                ':dpe_conso_finale'     => 'dpe_valeur_conso_finale',
                ':date_indice_prix'     => 'date_indice_prix_energies',
                ':dpe_ref_cert'         => 'dpe_reference_certificat',
                ':alur_synd_statut'     => 'alur_syndicat_statut',
                ':alur_plan_sauv'       => 'alur_copropriete_plan_sauvegarde',
                ':alur_etat_carence'    => 'alur_copropriete_etat_carence',
                ':obligation_debrouss'  => 'obligation_debroussaillement',
                ':erp_date_real'        => 'erp_date_realisation',
                ':risque_inond_g'       => 'risque_inondation_g_score',
                ':risque_inond_p'       => 'risque_inondation_p_score',
            ];

            if ($isEditing) {
                // Génère un UPDATE à partir du tableau de paramètres
                $setParts = [];
                foreach ($bienParams as $ph => $_) {
                    $col = $bienColMap[$ph] ?? ltrim($ph, ':');
                    $setParts[] = "`{$col}` = {$ph}";
                }
                // En mode brouillon, on conserve le statut 'brouillon' tant que
                // l'utilisateur ne clique pas explicitement sur "Valider et activer"
                if ($isDraft && (string)post('_validate_now', '') !== '1') {
                    // Supprime la modif de statut_bien (on garde brouillon)
                    $setParts = array_filter($setParts, fn($s) => !str_starts_with($s, '`statut_bien`'));
                    unset($bienParams[':statut_bien']);
                } elseif ($isDraft && (string)post('_validate_now', '') === '1') {
                    // Validation explicite : on bascule en actif
                    $bienParams[':statut_bien'] = 'actif';
                }
                $sqlUpdBien = "UPDATE biens SET " . implode(', ', $setParts) . " WHERE id = :_bien_id";
                $bienParams[':_bien_id'] = $editingBienId;
                $stmtUpdBien = $pdo->prepare($sqlUpdBien);
                $stmtUpdBien->execute($bienParams);
                $bienId = $editingBienId;
            } else {
                // Création directe (legacy — sans passer par le brouillon)
                $stmtInsertBien->execute($bienParams);
                $bienId = (int)$pdo->lastInsertId();
            }

            // ── reprise_descriptif (colonne optionnelle — migration requise) ──
            if ($repriseDescriptif !== '') {
                try {
                    $pdo->prepare("UPDATE biens SET reprise_descriptif = ? WHERE id = ?")
                        ->execute([$repriseDescriptif, $bienId]);
                } catch (Throwable) { /* colonne pas encore migrée — silencieux */ }
            }

            // ── Estimations internes agence (migration_biens_estimations_agence.sql)
            // Colonnes optionnelles — sauvegarde silencieuse si non migrées.
            try {
                $pdo->prepare("
                    UPDATE biens
                       SET estimation_agence_vente    = ?,
                           estimation_agence_location = ?,
                           estimation_agence_date     = ?,
                           estimation_agence_notes    = ?
                     WHERE id = ?
                ")->execute([
                    $estimationAgenceVente,
                    $estimationAgenceLocation,
                    $estimationAgenceDate,
                    $estimationAgenceNotes,
                    $bienId,
                ]);
            } catch (Throwable) { /* migration non appliquée — silencieux */ }

            // ── Situation pratique : acces_transports + distance_commerces ──
            // Colonnes optionnelles ajoutées via migration_biens_acces_situation.sql
            if ($accesTransports !== '' || $distanceCommerces !== '') {
                try {
                    $pdo->prepare("UPDATE biens SET acces_transports = ?, distance_commerces = ? WHERE id = ?")
                        ->execute([
                            $accesTransports !== '' ? $accesTransports : null,
                            $distanceCommerces !== '' ? $distanceCommerces : null,
                            $bienId
                        ]);
                } catch (Throwable) { /* migration non appliquée — silencieux */ }
            }

            // ── Liaisons chauffage & énergie (junction tables) ──
            if (!empty($chauffageIds)) {
                $stmtBTC = $pdo->prepare("INSERT IGNORE INTO bien_types_chauffage (id_bien, id_societe_type_chauffage) VALUES (?,?)");
                foreach ($chauffageIds as $tcId) {
                    if ($tcId > 0) $stmtBTC->execute([$bienId, $tcId]);
                }
            }
            if (!empty($energieIds)) {
                $stmtBEN = $pdo->prepare("INSERT IGNORE INTO bien_energies (id_bien, id_societe_energie) VALUES (?,?)");
                foreach ($energieIds as $enId) {
                    if ($enId > 0) $stmtBEN->execute([$bienId, $enId]);
                }
            }
            if (!empty($vueIds)) {
                $stmtBV = $pdo->prepare("INSERT IGNORE INTO bien_vues (id_bien, id_societe_vue) VALUES (?,?)");
                foreach ($vueIds as $vId) {
                    if ($vId > 0) $stmtBV->execute([$bienId, $vId]);
                }
            }

            if ($annonceTransaction !== '') {
                $annoncePrix = $annonceTransaction === 'vente' ? $annoncePrixVente : null;
                $annonceLoyerVal = $annonceTransaction === 'location' ? $annonceLoyer : null;
                $annonceLoyerCc = $annonceLoyerVal !== null ? $annonceLoyerVal : null;
                $referenceAnnonce = $referenceBien !== ''
                    ? $referenceBien . '-A'
                    : 'ANN-' . date('Y') . '-' . str_pad((string)rand(1, 9999), 4, '0', STR_PAD_LEFT);

                // Description publiée : priorité au texte IA si présent, sinon notes brutes
                $descriptionPubliee = $texteIa !== '' ? $texteIa : ($description ?: null);

                if ($isEditing && $annonceIdLoaded > 0) {
                    // Mode édition — UPDATE de l'annonce existante
                    $stmtAnnonce = $pdo->prepare("
                        UPDATE annonces SET
                            id_user = :id_user,
                            type_transaction = :type_transaction,
                            prix = :prix,
                            loyer = :loyer,
                            loyer_cc = :loyer_cc,
                            description = :description,
                            texte_ia = :texte_ia,
                            date_modification = NOW()
                        WHERE id = :id
                    ");
                    $stmtAnnonce->execute([
                        ':id_user' => $annonceCommercialId,
                        ':type_transaction' => $annonceTransaction,
                        ':prix' => $annoncePrix,
                        ':loyer' => $annonceLoyerVal,
                        ':loyer_cc' => $annonceLoyerCc,
                        ':description' => $descriptionPubliee,
                        ':texte_ia' => $texteIa !== '' ? $texteIa : null,
                        ':id' => $annonceIdLoaded,
                    ]);
                    $annonceIdCreated = $annonceIdLoaded;
                } else {
                    // Création de l'annonce
                    $stmtAnnonce = $pdo->prepare("
                        INSERT INTO annonces
                            (id_bien, id_agence, id_societe, id_user, reference_annonce, type_transaction,
                             prix, loyer, loyer_cc, charges, description, texte_ia,
                             date_creation, date_modification)
                        VALUES
                            (:id_bien, :id_agence, :id_societe, :id_user, :reference_annonce, :type_transaction,
                             :prix, :loyer, :loyer_cc, :charges, :description, :texte_ia,
                             NOW(), NOW())
                    ");
                    $stmtAnnonce->execute([
                        ':id_bien' => $bienId,
                        ':id_agence' => $_SESSION['id_agence'] ?? null,
                        ':id_societe' => $_SESSION['id_societe'] ?? null,
                        ':id_user' => $annonceCommercialId,
                        ':reference_annonce' => $referenceAnnonce,
                        ':type_transaction' => $annonceTransaction,
                        ':prix' => $annoncePrix,
                        ':loyer' => $annonceLoyerVal,
                        ':loyer_cc' => $annonceLoyerCc,
                        ':charges' => null,
                        ':description' => $descriptionPubliee,
                        ':texte_ia' => $texteIa !== '' ? $texteIa : null,
                    ]);
                    $annonceIdCreated = (int)$pdo->lastInsertId();
                }

                // ════════════════════════════════════════════════
                // Conformité Ubiflow / Le Bon Coin — champs annonce
                // ════════════════════════════════════════════════
                $stmtUpdAnn = $pdo->prepare("
                    UPDATE annonces SET
                        honoraires_charge_acquereur = :h_acq,
                        honoraires_charge_vendeur   = :h_vend,
                        alur_pourcentage_honoraires_ttc = :alur_pct,
                        pourcentage_honoraires_vendeur  = :pct_vend,
                        honoraires_negociation_cumules  = :h_cumul,
                        url_tarifs_publics = :url_tarifs,
                        zone_encadrement_loyer = :zone_enc,
                        loyer_de_base       = :loyer_base,
                        loyer_est_cc        = :loyer_cc_flag,
                        loyer_reference_majore = :loyer_ref_maj,
                        complement_loyer    = :compl_loyer,
                        modalite_recuperation_charges_locatives = :modalite,
                        honoraires_etat_des_lieux = :hon_edl,
                        mandat_numero       = :mandat_num,
                        mandat_type         = :mandat_typ,
                        date_mandat         = :date_mandat,
                        mandat_echeance     = :mandat_ech,
                        ancien_loyer_montant       = :anc_montant,
                        ancien_loyer_charges       = :anc_charges,
                        ancien_loyer_date_revision = :anc_revision,
                        ancien_locataire_date_sortie = :anc_sortie,
                        ancien_loyer_communique    = :anc_communique
                    WHERE id = :id
                ");
                $stmtUpdAnn->execute([
                    ':h_acq'         => $honorairesChargeAcq,
                    ':h_vend'        => $honorairesChargeVend,
                    ':alur_pct'      => $alurPctHonorairesTtc,
                    ':pct_vend'      => $pctHonorairesVendeur,
                    ':h_cumul'       => $honorairesNegoCumules,
                    ':url_tarifs'    => $urlTarifsPublics ?: null,
                    ':zone_enc'      => $zoneEncadrementLoyer,
                    ':loyer_base'    => $loyerDeBase,
                    ':loyer_cc_flag' => $loyerEstCc,
                    ':loyer_ref_maj' => $loyerReferenceMajore,
                    ':compl_loyer'   => $complementLoyer,
                    ':modalite'      => $modaliteRecupCharges ?: null,
                    ':hon_edl'       => $honorairesEtatDesLieux,
                    ':mandat_num'    => $mandatNumero ?: null,
                    ':mandat_typ'    => $mandatType ?: null,
                    ':date_mandat'   => $dateMandat ?: null,
                    ':mandat_ech'    => $mandatEcheance ?: null,
                    ':anc_montant'   => $ancienLoyerMontant,
                    ':anc_charges'   => $ancienLoyerCharges,
                    ':anc_revision'  => $ancienLoyerDateRevision ?: null,
                    ':anc_sortie'    => $ancienLocataireDateSortie ?: null,
                    ':anc_communique' => $ancienLoyerCommunique,
                    ':id'            => $annonceIdCreated,
                ]);

                // ── Lignes de complément de loyer (justifications) ──
                // Stratégie : on supprime les anciennes et on réinsère
                $pdo->prepare("DELETE FROM annonces_complement_loyer_lignes WHERE id_annonce = ?")
                    ->execute([$annonceIdCreated]);
                if (!empty($cplLibelles)) {
                    $stmtCpl = $pdo->prepare("
                        INSERT INTO annonces_complement_loyer_lignes
                            (id_annonce, libelle, montant, ordre)
                        VALUES (?, ?, ?, ?)
                    ");
                    foreach ($cplLibelles as $i => $lib) {
                        $lib = trim((string)$lib);
                        if ($lib === '') continue;
                        $mt = isset($cplMontants[$i]) ? (float)$cplMontants[$i] : 0.0;
                        $stmtCpl->execute([$annonceIdCreated, $lib, $mt, $i + 1]);
                    }
                }
            } else {
                $annonceIdCreated = 0;
            }

            $pdo->commit();
            $success = $isEditing ? 'Bien mis à jour.' : 'Bien enregistré.';

            // ════════════════════════════════════════════════
            // MANDAT (table mandats) — INSERT ou UPDATE
            // ════════════════════════════════════════════════
            // On crée/met à jour le mandat seulement si un type est choisi
            if ($mandatsType !== '' && $bienId > 0) {
                try {
                    // Existe-t-il déjà un mandat pour ce bien ?
                    $stmtFind = $pdo->prepare("SELECT id FROM mandats WHERE id_bien = ? ORDER BY id DESC LIMIT 1");
                    $stmtFind->execute([$bienId]);
                    $existingMandatId = (int)$stmtFind->fetchColumn();

                    // N° auto si vide
                    $numAuto = $mandatsNumero;
                    if ($numAuto === '') {
                        $numAuto = strtoupper(substr($mandatsType, 0, 3)) . '-' . date('Y') . '-' . str_pad((string)$bienId, 4, '0', STR_PAD_LEFT);
                    }
                    $exclusif = ($mandatsNature === 'exclusif') ? 1 : 0;

                    if ($existingMandatId > 0) {
                        $stmtUpdM = $pdo->prepare("
                            UPDATE mandats SET
                                id_proprietaire = :pro, id_agence = :ag,
                                numero_mandat = :num, type_mandat = :type,
                                nature_mandat = :nature, exclusif = :excl,
                                date_signature = :dsig, date_debut = :ddeb, date_fin = :dfin,
                                honoraires = :hon, statut = COALESCE(NULLIF(statut,''), 'actif'),
                                date_modification = NOW()
                            WHERE id = :id
                        ");
                        $stmtUpdM->execute([
                            ':pro'    => $proprietaireId,
                            ':ag'     => $_SESSION['id_agence'] ?? null,
                            ':num'    => $numAuto,
                            ':type'   => $mandatsType,
                            ':nature' => $mandatsNature ?: null,
                            ':excl'   => $exclusif,
                            ':dsig'   => $mandatsDateSignature ?: null,
                            ':ddeb'   => $mandatsDateDebut ?: null,
                            ':dfin'   => $mandatsDateFin ?: null,
                            ':hon'    => $mandatsHonoraires,
                            ':id'     => $existingMandatId,
                        ]);
                    } else {
                        $stmtInsM = $pdo->prepare("
                            INSERT INTO mandats
                                (id_bien, id_proprietaire, id_agence, id_user,
                                 numero_mandat, type_mandat, nature_mandat, exclusif,
                                 date_signature, date_debut, date_fin, honoraires,
                                 statut, date_creation, date_modification)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'actif', NOW(), NOW())
                        ");
                        $stmtInsM->execute([
                            $bienId,
                            $proprietaireId,
                            $_SESSION['id_agence'] ?? null,
                            $_SESSION['user_id'] ?? null,
                            $numAuto,
                            $mandatsType,
                            $mandatsNature ?: null,
                            $exclusif,
                            $mandatsDateSignature ?: null,
                            $mandatsDateDebut ?: null,
                            $mandatsDateFin ?: null,
                            $mandatsHonoraires,
                        ]);
                    }
                } catch (Throwable $e) {
                    error_log('[bien_ajouter] mandat insert/update failed: ' . $e->getMessage());
                    // Non bloquant : on continue
                }
            }

            // ════════════════════════════════════════════════
            // DOCUMENTS LÉGAUX — DPE + Certificat de surface
            // ════════════════════════════════════════════════
            // Stockés dans biens_documents (table dédiée). Non bloquants.
            $docTypes = [
                'doc_dpe'             => 'dpe',
                'doc_certif_surface'  => 'certificat_surface',
            ];
            $docsDir = __DIR__ . '/uploads/biens_docs/';
            if (!is_dir($docsDir)) { @mkdir($docsDir, 0775, true); }
            foreach ($docTypes as $field => $type) {
                if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                if (($_FILES[$field]['size'] ?? 0) > 20 * 1024 * 1024) continue; // 20 MB max
                $tmpName  = $_FILES[$field]['tmp_name'] ?? '';
                $origName = $_FILES[$field]['name'] ?? null;
                if (!is_uploaded_file($tmpName)) continue;
                $ext = strtolower(pathinfo($origName ?? '', PATHINFO_EXTENSION) ?: 'pdf');
                $finalName = $type . '_' . $bienId . '_' . date('Ymd_His') . '.' . preg_replace('/[^a-z0-9]/', '', $ext);
                $finalPath = $docsDir . $finalName;
                if (!@move_uploaded_file($tmpName, $finalPath)) continue;
                $publicUrl = '/uploads/biens_docs/' . $finalName;
                try {
                    $pdo->prepare("
                        INSERT INTO biens_documents
                            (id_bien, type_document, libelle, url_fichier, nom_original, mime_type, taille_octets, id_user_upload, date_upload)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ")->execute([
                        $bienId,
                        $type,
                        $type === 'dpe' ? 'DPE' : 'Certificat de surface',
                        $publicUrl,
                        $origName,
                        $_FILES[$field]['type'] ?? null,
                        (int)($_FILES[$field]['size'] ?? 0),
                        $userId ?? null,
                    ]);
                } catch (Throwable) { /* table peut manquer si migration non passée */ }
            }

            // ════════════════════════════════════════════════
            // PHOTOS — Bibliothèque du bien (toujours)
            // ════════════════════════════════════════════════
            // Étape A : toutes les photos uploadées sont enregistrées dans
            //           biens_photos, rattachées au bien (pas à l'annonce).
            //           Cela permet de réutiliser la même bibliothèque pour
            //           plusieurs annonces successives.
            $savedBienPhotoIds = []; // index queue (0,1,2…) → id biens_photos
            if (!empty($_FILES['photos']['name'])) {
                require_once __DIR__ . '/inc/bien_photos_manager.php';
                $bpm = new BienPhotosManager($pdo);
                $idSocPhotos = (int)($_SESSION['id_societe'] ?? 0);
                $tmpDir = __DIR__ . '/uploads/_tmp/';
                if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0755, true); }
                $nbPhotos = is_array($_FILES['photos']['name']) ? count($_FILES['photos']['name']) : 0;
                $photoOk = 0; $photoErr = 0;

                for ($i = 0; $i < $nbPhotos; $i++) {
                    if (($_FILES['photos']['error'][$i] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                        $photoErr++; continue;
                    }
                    if (($_FILES['photos']['size'][$i] ?? 0) > 10 * 1024 * 1024) {
                        $photoErr++; continue;
                    }
                    $tmpName = $_FILES['photos']['tmp_name'][$i] ?? '';
                    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
                        $photoErr++; continue;
                    }
                    $tmpPath = $tmpDir . 'upload_' . uniqid('', true) . '.tmp';
                    if (!move_uploaded_file($tmpName, $tmpPath)) {
                        $photoErr++; continue;
                    }
                    $nomOrig = $_FILES['photos']['name'][$i] ?? null;
                    $r = $bpm->ajouterPhoto($bienId, $idSocPhotos, $tmpPath, $nomOrig, $userId ?? null);
                    @unlink($tmpPath);
                    if (!empty($r['ok']) && !empty($r['id'])) {
                        $savedBienPhotoIds[$i] = (int)$r['id'];
                        $photoOk++;
                    } else {
                        $photoErr++;
                    }
                }

                if ($photoOk > 0) {
                    $success .= ' ' . $photoOk . ' photo(s) ajoutée(s) à la bibliothèque du bien.';
                }
                if ($photoErr > 0) {
                    $success .= ' (' . $photoErr . ' photo(s) en erreur)';
                }
            }

            // ════════════════════════════════════════════════
            // PHOTOS — Sélection pour l'annonce (max 7, avec SEO)
            // ════════════════════════════════════════════════
            // Étape B : si une annonce a été créée ET que l'utilisateur a
            //           sélectionné des photos pour celle-ci, on les COPIE +
            //           RENOMME en mode SEO via AnnoncePhotosManager.
            //
            // Le champ posté `photos_annonce_selection` contient les indices
            // de la queue (ex: "0,2,5,1") dans l'ordre choisi par l'utilisateur.
            $sel = trim((string)($_POST['photos_annonce_selection'] ?? ''));
            if ($annonceIdCreated > 0 && $sel !== '' && $savedBienPhotoIds) {
                require_once __DIR__ . '/inc/annonce_photos_manager.php';
                $apm = new AnnoncePhotosManager($pdo);
                $bpmForPath = $bpm ?? null;
                if (!$bpmForPath) {
                    require_once __DIR__ . '/inc/bien_photos_manager.php';
                    $bpmForPath = new BienPhotosManager($pdo);
                }

                $indexes = array_values(array_filter(
                    array_map('intval', explode(',', $sel)),
                    fn($v) => $v >= 0
                ));
                $indexes = array_slice($indexes, 0, 7); // max 7 photos par annonce

                $annoncePhotoOk = 0; $annoncePhotoErr = 0;
                $ordreAnn = 0;
                foreach ($indexes as $idx) {
                    if (!isset($savedBienPhotoIds[$idx])) continue;
                    $idBienPhoto = $savedBienPhotoIds[$idx];
                    $srcAbs = $bpmForPath->getPathAbs($idBienPhoto);
                    if (!$srcAbs) { $annoncePhotoErr++; continue; }
                    $ordreAnn++;
                    try {
                        $rApm = $apm->importerDepuisFichier(
                            $annonceIdCreated,
                            $srcAbs,
                            $ordreAnn,
                            $ordreAnn === 1
                        );
                        if (!empty($rApm['ok'])) $annoncePhotoOk++;
                        else $annoncePhotoErr++;
                    } catch (Throwable) {
                        $annoncePhotoErr++;
                    }
                }
                if ($annoncePhotoOk > 0) {
                    $success .= ' ' . $annoncePhotoOk . ' photo(s) sélectionnée(s) pour l\'annonce (SEO).';
                }
                if ($annoncePhotoErr > 0) {
                    $success .= ' (' . $annoncePhotoErr . ' échec sélection)';
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Affiche le message réel pour debug (à remplacer par un log
            // silencieux en production une fois le workflow stabilisé)
            $errors[] = 'Erreur lors de l’enregistrement du bien : ' . $e->getMessage();
            error_log('[bien_ajouter] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }
}

// Liste des propriétaires (pour le sélecteur dans l'onglet Identification)
$proprietairesList = [];
try {
    $sqlP = "SELECT id, type_personne, civilite, nom, prenom, societe, email, telephone, ville
             FROM proprietaires WHERE actif = 1";
    $paramsP = [];
    if (!empty($_SESSION['id_agence'])) {
        $sqlP .= " AND (id_agence = ? OR id_agence IS NULL)";
        $paramsP[] = (int)$_SESSION['id_agence'];
    }
    $sqlP .= " ORDER BY nom, prenom";
    $stmtP = $pdo->prepare($sqlP);
    $stmtP->execute($paramsP);
    $proprietairesList = $stmtP->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) { $proprietairesList = []; }

// Mandat éventuellement déjà associé au bien
$mandatExistant = null;
if ($isEditing) {
    try {
        $stmtM = $pdo->prepare("
            SELECT id, numero_mandat, type_mandat, nature_mandat, exclusif,
                   date_signature, date_debut, date_fin, honoraires, statut
            FROM mandats WHERE id_bien = ? ORDER BY id DESC LIMIT 1
        ");
        $stmtM->execute([$editingBienId]);
        $mandatExistant = $stmtM->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable) {}
}

$commercials = [];
$pdo = $GLOBALS['pdo'] ?? null;
if ($pdo) {
    try {
        $sql = 'SELECT id, nom, prenom, service, id_agence, id_societe FROM users WHERE actif = 1';
        $params = [];
        if (!empty($_SESSION['id_agence'])) {
            $sql .= ' AND id_agence = ?';
            $params[] = (int)$_SESSION['id_agence'];
        } elseif (!empty($_SESSION['id_societe'])) {
            $sql .= ' AND id_societe = ?';
            $params[] = (int)$_SESSION['id_societe'];
        }
        $sql .= ' ORDER BY nom, prenom';
        $stmtCom = $pdo->prepare($sql);
        $stmtCom->execute($params);
        $commercials = $stmtCom->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $commercials = [];
    }
}

// ── Données société pour mini-cards ───────────────────────────
$_socId = (int)($_SESSION['id_societe'] ?? 0);
$socChauffages = $socEnergies = $socChauffEnLiens = [];
$socTypesBien  = $socVues = [];
if ($_socId > 0 && $pdo) {
    try {
        $s = $pdo->prepare("SELECT id, code, label, description, icone FROM societe_types_chauffage WHERE id_societe = ? AND actif = 1 ORDER BY ordre_affichage ASC, label ASC");
        $s->execute([$_socId]); $socChauffages = $s->fetchAll(PDO::FETCH_ASSOC);

        $s = $pdo->prepare("SELECT id, code, label, description, icone FROM societe_energies WHERE id_societe = ? AND actif = 1 ORDER BY ordre_affichage ASC, label ASC");
        $s->execute([$_socId]); $socEnergies = $s->fetchAll(PDO::FETCH_ASSOC);

        // Liaisons chauffage → énergie : ['id_energie' => [id_ch1, id_ch2, ...]]
        $s = $pdo->prepare("SELECT id_societe_type_chauffage, id_societe_energie FROM societe_chauffage_energie WHERE id_societe = ?");
        $s->execute([$_socId]);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $socChauffEnLiens[(int)$l['id_societe_energie']][] = (int)$l['id_societe_type_chauffage'];
        }

        $s = $pdo->prepare("SELECT id, code, label, description, icone FROM societe_types_bien WHERE id_societe = ? AND actif = 1 ORDER BY ordre_affichage ASC, label ASC");
        $s->execute([$_socId]); $socTypesBien = $s->fetchAll(PDO::FETCH_ASSOC);

        $s = $pdo->prepare("SELECT id, code, label, description, icone FROM societe_vues WHERE id_societe = ? AND actif = 1 ORDER BY ordre_affichage ASC, label ASC");
        $s->execute([$_socId]); $socVues = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable) {}
}
// Si tables vides (société non initialisée), fallback sur listes statiques
$_chauffFallback    = empty($socChauffages);
$_energFallback     = empty($socEnergies);
$_typeBienFallback  = empty($socTypesBien);
$_vueFallback       = empty($socVues);

$username = htmlspecialchars((string)($_SESSION['username'] ?? 'Utilisateur'), ENT_QUOTES, 'UTF-8');
$role     = htmlspecialchars((string)($_SESSION['role']     ?? 'collaborateur'), ENT_QUOTES, 'UTF-8');
$annonceTransactionPost = (string)post('annonce_transaction', '');
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Ajouter un bien — MaBoxImmo</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:ital,wght@0,300;0,400;0,500;1,300&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= asset_url('/css/tokens.css') ?>">
  <style>
    :root {
      --bg:        var(--bg-secondary);
      --card:      var(--bg-primary);
      --ink:       #1a1816;
      --muted:     #8a8680;
      --accent:    #36577d;
      --accent-2:  #f59e0b;
      --accent-3:  #7a9060;
      --stroke:    #d4d0ca;
      --r-lg: 18px; --r-md: 12px; --r-sm: 8px;
      --sidebar-w: 220px;
      --topbar-h:  56px;
      --neu-out:   6px 6px 14px var(--shadow-dark), -6px -6px 14px var(--shadow-light);
      --neu-in:    inset 4px 4px 10px var(--shadow-dark), inset -4px -4px 10px var(--shadow-light);
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { scroll-behavior: smooth; }
    body {
      font-family: 'Sora', system-ui, sans-serif;
      background: var(--bg);
      color: var(--ink);
      display: flex;
      min-height: 100vh;
      overflow-x: hidden;
    }
    a { color: inherit; text-decoration: none; }

    /* ── MAIN ── */
    .mbi-main {
      margin-left: var(--sidebar-w);
      flex: 1;
      display: flex;
      flex-direction: column;
      min-height: 100vh;
    }

    /* ── TOPBAR V2 ── */
    .mbi-topbar {
      position: sticky; top: 0; height: var(--topbar-h);
      background: var(--card);
      box-shadow: 0 2px 8px var(--shadow-dark);
      border-bottom: 1px solid var(--stroke);
      display: flex; align-items: center; gap: 10px;
      padding: 0 24px; z-index: 50;
    }
    .topbar-nav-btn {
      width: 34px; height: 34px; border-radius: 8px;
      background: var(--bg); border: none; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      box-shadow: var(--neu-out); color: var(--muted);
      transition: box-shadow .18s;
      flex-shrink: 0;
    }
    .topbar-nav-btn:hover { box-shadow: var(--neu-in); color: var(--ink); }
    .topbar-gap { width: 50px; flex-shrink: 0; }
    .topbar-breadcrumb {
      display: flex; align-items: center; gap: 6px;
      font-size: 13px; font-weight: 500; color: var(--muted);
    }
    .topbar-breadcrumb a { color: var(--muted); transition: color .15s; }
    .topbar-breadcrumb a:hover { color: var(--ink); }
    .topbar-breadcrumb .sep { color: var(--stroke); }
    .topbar-breadcrumb .active { color: var(--accent); font-weight: 600; }
    .topbar-spacer { flex: 1; }
    .topbar-icon-btn {
      width: 34px; height: 34px; border-radius: 8px;
      background: var(--bg); border: none; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      box-shadow: var(--neu-out); color: var(--muted);
      transition: box-shadow .18s; flex-shrink: 0;
    }
    .topbar-icon-btn:hover { box-shadow: var(--neu-in); color: var(--ink); }
    .topbar-avatar {
      width: 34px; height: 34px; border-radius: 8px;
      background: var(--accent); color: #fff;
      display: flex; align-items: center; justify-content: center;
      font-size: 13px; font-weight: 700;
      box-shadow: var(--neu-out); flex-shrink: 0;
    }

    /* ── PAGE HEAD ── */
    .page-head {
      padding: 18px 28px 14px;
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 20px;
      position: sticky;
      top: var(--topbar-h);
      z-index: 50;
      background: var(--bg);
      box-shadow: 0 4px 10px -8px rgba(0,0,0,.18);
    }
    .page-head-info { flex: 1; min-width: 0; }
    .page-head-actions {
      display: flex; flex-direction: column; align-items: stretch; gap: 8px;
      flex-shrink: 0;
    }
    .ph-save-btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 8px;
      padding: 11px 18px; border-radius: 10px;
      background: linear-gradient(135deg, #f97316, #ea580c);
      color: #fff; font-size: 13px; font-weight: 700;
      border: none; cursor: pointer; font-family: inherit;
      box-shadow: 0 4px 12px rgba(249,115,22,0.35);
      transition: all .15s;
      white-space: nowrap;
    }
    .ph-save-btn:hover { opacity: .9; transform: translateY(-1px); }
    .ph-save-btn.secondary {
      background: #fff;
      color: #4a5562;
      border: 1px solid #d4d0ca;
      box-shadow: 0 2px 6px rgba(0,0,0,.06);
    }
    .ph-save-btn.success {
      background: linear-gradient(135deg, #16a34a, #15803d);
      box-shadow: 0 4px 12px rgba(22,163,74,0.35);
    }
    .page-head-label {
      font-family: 'DM Mono', monospace;
      font-size: 11px; font-weight: 500;
      letter-spacing: 1.2px; text-transform: uppercase;
      color: var(--accent-3); margin-bottom: 4px;
    }
    .page-head-title {
      font-size: 22px; font-weight: 700; color: var(--ink);
      margin-bottom: 4px;
    }
    .page-head-sub {
      font-size: 13px; color: var(--muted);
    }
    /* ─── MINI CARD CONFORMITÉ ─── */
    .conf-mini {
      flex-shrink: 0;
      width: 320px;
      max-width: 35vw;
      padding: 10px 14px;
      background: #fff;
      border-radius: 10px;
      border-left: 3px solid;
      box-shadow: 0 2px 6px rgba(0,0,0,.06);
      font-size: 10px;
    }
    .conf-mini-head {
      display: flex; align-items: center; justify-content: space-between;
      gap: 8px; margin-bottom: 6px;
    }
    .conf-mini-status { font-weight: 700; font-size: 11px; }
    .conf-mini-score { font-family: 'DM Mono', monospace; font-weight: 700; font-size: 11px; }
    .conf-mini-bar { height: 3px; background: #eee; border-radius: 99px; overflow: hidden; margin-bottom: 8px; }
    .conf-mini-bar > span { display: block; height: 100%; background: currentColor; transition: width .3s; }
    .conf-mini-list {
      list-style: none; padding: 0; margin: 0;
      display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 2px 10px;
      max-height: 60px; overflow: hidden;
    }
    .conf-mini-list li {
      font-size: 9px; line-height: 1.3;
      color: #666;
      display: flex; align-items: center; gap: 4px;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .conf-mini-list li::before { content: '•'; flex-shrink: 0; }
    .conf-mini-list li.miss-ubiflow::before { color: #2d8659; }
    .conf-mini-list li.miss-admin::before   { color: #1f6f7a; }
    .conf-mini.ok    { border-left-color: #2d8659; color: #2d8659; }
    .conf-mini.warn  { border-left-color: #d99813; color: #b67c00; }
    .conf-mini.bad   { border-left-color: #c0392b; color: #c0392b; }
    .conf-mini.draft { border-left-color: #6a7a8c; color: #4a5562; }
    .conf-mini-empty { font-size: 10px; color: #999; font-style: italic; padding: 4px 0; }
    @media (max-width: 900px) {
      .page-head { flex-direction: column; }
      .conf-mini { width: 100%; max-width: 100%; }
    }

    /* ── CONTAINER ── */
    .mbi-container {
      padding: 20px 28px 80px;
      position: relative; z-index: 1; flex: 1;
      max-width: 1500px;
    }

    /* ══ LAYOUT 2 COLONNES (form + preview) ════════════════ */
    .ba-layout {
      display: grid;
      grid-template-columns: 1fr 380px;
      gap: 24px;
      align-items: start;
    }
    .ba-form-col { min-width: 0; }
    .ba-preview-col {
      position: sticky;
      top: calc(var(--topbar-h) + 80px);
      max-height: calc(100vh - var(--topbar-h) - 100px);
      overflow-y: auto;
      scrollbar-width: thin;
      scrollbar-color: var(--stroke) transparent;
      /* Aligne le haut de la card avec la 1ère card du form (sous la nav sticky) */
      align-self: start;
    }
    .ba-preview-col::-webkit-scrollbar { width: 6px; }
    .ba-preview-col::-webkit-scrollbar-thumb { background: var(--stroke); border-radius: 3px; }
    @media (max-width: 1180px) {
      .ba-layout { grid-template-columns: 1fr; }
      .ba-preview-col { position: static; max-height: none; }
    }

    /* ══ PREVIEW CARD ══════════════════════════════════════ */
    .ba-preview-card {
      background: var(--card);
      border-radius: var(--r-lg);
      box-shadow: var(--neu-out);
      overflow: hidden;
      margin-bottom: 16px;
    }
    .bp-hero {
      min-height: 150px;
      background: linear-gradient(135deg, var(--accent) 0%, var(--accent-3) 100%);
      display: flex;
      align-items: flex-end;
      padding: 16px;
      position: relative;
    }
    .bp-hero-badge {
      position: absolute;
      top: 12px; right: 12px;
      padding: 5px 12px;
      border-radius: 99px;
      font-size: 11px; font-weight: 700;
      background: rgba(255,255,255,0.95);
      color: var(--accent);
      letter-spacing: .03em;
    }
    .bp-hero-label {
      background: rgba(0,0,0,0.45);
      backdrop-filter: blur(8px);
      border-radius: 10px;
      padding: 9px 13px;
    }
    .bp-hero-type {
      font-size: 10px;
      color: rgba(255,255,255,0.7);
      font-weight: 600;
      letter-spacing: .08em;
      text-transform: uppercase;
      font-family: 'DM Mono', monospace;
    }
    .bp-hero-title {
      font-size: 16px;
      font-weight: 700;
      color: #fff;
      line-height: 1.25;
      margin-top: 3px;
    }
    .bp-body { padding: 16px; }
    .bp-kpis {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 8px;
      margin-bottom: 14px;
    }
    .bp-kbox {
      background: var(--bg);
      border-radius: var(--r-sm);
      padding: 10px 12px;
      box-shadow: var(--neu-in);
    }
    .bp-kval {
      font-size: 18px;
      font-weight: 800;
      color: var(--ink);
      line-height: 1;
      font-family: 'DM Mono', monospace;
    }
    .bp-kval span {
      font-size: 11px;
      font-weight: 500;
      color: var(--muted);
      font-family: 'Sora', sans-serif;
    }
    .bp-klbl {
      font-size: 10px;
      color: var(--muted);
      margin-top: 3px;
      font-family: 'DM Mono', monospace;
      letter-spacing: .04em;
      text-transform: uppercase;
    }
    .bp-section-label {
      font-size: 10px;
      font-weight: 700;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: .1em;
      margin: 12px 0 6px;
      font-family: 'DM Mono', monospace;
    }
    .bp-chips {
      display: flex;
      flex-wrap: wrap;
      gap: 5px;
      margin-bottom: 10px;
    }
    .bp-chip {
      font-size: 11px;
      padding: 3px 9px;
      border-radius: 99px;
      background: var(--bg);
      color: var(--muted);
      box-shadow: var(--neu-in);
      font-weight: 600;
    }
    .bp-chip.on {
      color: var(--accent);
      box-shadow: 1px 1px 3px var(--shadow-dark), -1px -1px 3px var(--shadow-light);
    }
    .bp-desc {
      font-size: 12px;
      color: var(--muted);
      line-height: 1.55;
      background: var(--bg);
      box-shadow: var(--neu-in);
      border-radius: var(--r-sm);
      padding: 10px 12px;
      white-space: pre-wrap;
      word-wrap: break-word;
      max-height: 150px;
      overflow-y: auto;
    }
    .bp-loc {
      font-size: 12px;
      color: var(--muted);
      padding: 8px 0;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .bp-loc strong { color: var(--ink); }
    .bp-empty {
      color: var(--muted);
      font-style: italic;
      font-size: 12px;
    }
    .bp-dpe-line {
      display: flex;
      gap: 8px;
      margin-top: 8px;
      font-size: 11px;
      font-family: 'DM Mono', monospace;
    }
    .bp-dpe-pill {
      padding: 3px 9px;
      border-radius: 6px;
      font-weight: 700;
      letter-spacing: .04em;
    }
    .bp-dpe-pill.empty { background: var(--bg); color: var(--muted); box-shadow: var(--neu-in); }
    .bp-dpe-pill.A { background: #00a651; color: #fff; }
    .bp-dpe-pill.B { background: #50b848; color: #fff; }
    .bp-dpe-pill.C { background: #aed136; color: #1a1816; }
    .bp-dpe-pill.D { background: #fff200; color: #1a1816; }
    .bp-dpe-pill.E { background: #fcb813; color: #1a1816; }
    .bp-dpe-pill.F { background: #f37021; color: #fff; }
    .bp-dpe-pill.G { background: #ed1c24; color: #fff; }

    /* ══ ONGLET PHOTOS ═════════════════════════════════════ */
    .ba-tab-count {
      display: inline-block;
      margin-left: 4px;
      padding: 1px 7px;
      border-radius: 99px;
      background: var(--accent);
      color: #fff;
      font-size: 10px;
      font-weight: 700;
      font-family: 'DM Mono', monospace;
      vertical-align: middle;
    }
    .ba-photo-drop {
      border: 2px dashed var(--stroke);
      border-radius: var(--r-lg);
      padding: 36px 24px;
      text-align: center;
      cursor: pointer;
      transition: border-color .18s, background .18s;
      background: var(--bg);
      box-shadow: var(--neu-in);
    }
    .ba-photo-drop:hover,
    .ba-photo-drop.drag-over {
      border-color: var(--accent);
      background: rgba(54,87,125,0.04);
    }
    .ba-photo-drop-icon { font-size: 48px; margin-bottom: 12px; }
    .ba-photo-drop-title { font-size: 16px; font-weight: 700; color: var(--ink); margin-bottom: 6px; }
    .ba-photo-drop-sub { font-size: 12px; color: var(--muted); }
    .ba-photo-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
      gap: 12px;
      margin-top: 18px;
    }
    .ba-photo-thumb {
      position: relative;
      aspect-ratio: 4/3;
      border-radius: var(--r-md);
      overflow: hidden;
      background: var(--bg);
      box-shadow: var(--neu-out);
    }
    .ba-photo-thumb img {
      width: 100%; height: 100%;
      object-fit: cover;
      display: block;
    }
    .ba-photo-thumb-num {
      position: absolute; top: 6px; left: 6px;
      background: rgba(0,0,0,0.65);
      color: #fff;
      font-size: 10px; font-weight: 700;
      padding: 2px 7px;
      border-radius: 99px;
      font-family: 'DM Mono', monospace;
    }
    .ba-photo-thumb-main {
      position: absolute; top: 6px; right: 6px;
      background: var(--accent-3);
      color: #fff;
      font-size: 9px; font-weight: 700;
      padding: 2px 7px;
      border-radius: 99px;
      letter-spacing: .04em;
    }
    .ba-photo-thumb-del {
      position: absolute; bottom: 6px; right: 6px;
      width: 24px; height: 24px;
      border-radius: 50%;
      background: rgba(204,92,88,0.95);
      color: #fff;
      border: none;
      font-size: 14px; font-weight: 700;
      cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      box-shadow: 0 2px 6px #f7f8fa;
      line-height: 1;
    }
    .ba-photo-thumb-del:hover { background: rgba(204,92,88,1); }
    .ba-photo-info {
      margin-top: 14px;
      padding: 10px 14px;
      background: rgba(245,158,11,0.08);
      border-left: 3px solid var(--accent-2);
      border-radius: 6px;
      font-size: 12px;
      color: var(--muted);
      line-height: 1.5;
    }
    .ba-photo-info strong { color: var(--ink); }

    /* ── Légende IA sous la thumb (catégorie + description Vision) ── */
    .ba-photo-cell { display: flex; flex-direction: column; gap: 6px; }
    .ba-photo-cell .ba-photo-thumb { aspect-ratio: 4/3; }
    .ba-photo-caption {
      font-size: 11px;
      line-height: 1.35;
      color: var(--muted);
      padding: 4px 2px 0;
    }
    .ba-photo-caption-cat {
      display: inline-block;
      font-family: 'DM Mono', monospace;
      font-size: 9px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .05em;
      color: #6a4ca8;
      background: rgba(176,125,255,0.12);
      padding: 2px 6px;
      border-radius: 4px;
      margin-bottom: 3px;
    }
    .ba-photo-caption em { color: var(--muted); font-style: italic; }

    /* ── Récap analyses photos (onglet Description) ── */
    .ba-photo-recap {
      margin-top: 14px;
      padding: 14px 16px;
      background: linear-gradient(180deg, rgba(106,76,168,0.05), var(--card));
      border: 1px solid rgba(106,76,168,0.18);
      border-radius: var(--r-md);
    }
    .ba-photo-recap-title {
      font-size: 12px;
      font-weight: 700;
      color: #6a4ca8;
      text-transform: uppercase;
      letter-spacing: .05em;
      font-family: 'DM Mono', monospace;
      margin-bottom: 10px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .ba-photo-recap-title .count {
      background: #6a4ca8;
      color: #fff;
      padding: 1px 8px;
      border-radius: 99px;
      font-size: 10px;
    }
    .ba-photo-recap-list {
      list-style: none;
      padding: 0;
      margin: 0;
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
      gap: 8px;
    }
    .ba-photo-recap-item {
      display: flex;
      gap: 10px;
      padding: 8px;
      background: var(--card);
      border-radius: 6px;
      box-shadow: var(--neu-out);
      font-size: 11px;
      line-height: 1.4;
    }
    .ba-photo-recap-item img {
      width: 56px; height: 42px;
      object-fit: cover;
      border-radius: 4px;
      flex-shrink: 0;
    }
    .ba-photo-recap-item .cat {
      font-family: 'DM Mono', monospace;
      font-size: 9px;
      font-weight: 700;
      text-transform: uppercase;
      color: #6a4ca8;
      letter-spacing: .05em;
      display: block;
      margin-bottom: 2px;
    }
    .ba-photo-recap-empty {
      font-size: 12px;
      color: var(--muted);
      font-style: italic;
      padding: 8px 2px;
    }

    /* ── BLOC IA — Génération de description (onglet Description) ── */
    .ba-ia-block {
      margin-top: 16px;
      padding: 16px;
      background: linear-gradient(180deg, rgba(176,125,255,0.05), var(--card));
      border: 1px solid rgba(176,125,255,0.2);
      border-radius: var(--r-md);
    }
    .ba-ia-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 12px;
      flex-wrap: wrap;
      gap: 10px;
    }
    .ba-ia-title {
      font-size: 14px;
      font-weight: 700;
      color: #6a4ca8;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .ba-ia-tag {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 99px;
      font-size: 9px;
      font-weight: 700;
      background: rgba(176,125,255,0.15);
      color: #6a4ca8;
      border: 1px solid rgba(176,125,255,0.25);
      letter-spacing: .04em;
      text-transform: uppercase;
      font-family: 'DM Mono', monospace;
    }
    .ba-ia-btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 9px 16px;
      border-radius: 10px;
      background: linear-gradient(135deg, #7640d4, #9d6fff);
      color: #fff;
      font-weight: 700;
      font-size: 13px;
      border: none;
      cursor: pointer;
      box-shadow: 0 4px 12px rgba(118,64,212,0.25);
      transition: transform .15s, box-shadow .15s;
    }
    .ba-ia-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(118,64,212,0.35); }
    .ba-ia-btn:disabled { opacity: .6; cursor: wait; transform: none; }
    .ba-ia-status {
      font-size: 12px;
      color: var(--muted);
      margin-bottom: 10px;
    }
    .ba-ia-status.ok { color: var(--accent-3); font-weight: 600; }
    .ba-ia-status.err { color: #cc5c58; font-weight: 600; }
    .ba-ia-textarea {
      width: 100%;
      min-height: 180px;
      padding: 12px 14px;
      border-radius: var(--r-sm);
      background: var(--bg);
      color: var(--ink);
      font-family: 'Sora', sans-serif;
      font-size: 13px;
      line-height: 1.55;
      box-shadow: var(--neu-in);
      border: none;
      outline: none;
      resize: vertical;
    }
    .ba-ia-points-list {
      list-style: none;
      padding: 0;
      margin: 8px 0 0;
    }
    .ba-ia-points-list li {
      font-size: 12px;
      padding: 5px 0 5px 20px;
      position: relative;
      color: var(--ink);
    }
    .ba-ia-points-list li::before {
      content: "✓";
      position: absolute;
      left: 0; top: 5px;
      color: var(--accent-3);
      font-weight: 700;
    }

    /* ── SÉLECTION PHOTOS POUR ANNONCE (onglet Annonce) ── */
    .ba-photo-selector {
      margin-top: 16px;
    }
    .ba-photo-selector-empty {
      padding: 24px;
      text-align: center;
      background: var(--bg);
      box-shadow: var(--neu-in);
      border-radius: var(--r-md);
      color: var(--muted);
      font-size: 13px;
    }
    .ba-photo-selector-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
      gap: 10px;
    }
    .ba-photo-pick {
      position: relative;
      aspect-ratio: 4/3;
      border-radius: var(--r-sm);
      overflow: hidden;
      box-shadow: var(--neu-out);
      cursor: pointer;
      transition: transform .15s;
      background: var(--bg);
    }
    .ba-photo-pick:hover { transform: translateY(-2px); }
    .ba-photo-pick img {
      width: 100%; height: 100%;
      object-fit: cover; display: block;
    }
    .ba-photo-pick.selected {
      box-shadow: var(--neu-out), 0 0 0 3px var(--accent);
    }
    .ba-photo-pick-order {
      position: absolute; top: 6px; left: 6px;
      width: 26px; height: 26px;
      border-radius: 50%;
      background: var(--accent);
      color: #fff;
      font-size: 12px; font-weight: 700;
      font-family: 'DM Mono', monospace;
      display: none;
      align-items: center; justify-content: center;
      box-shadow: 0 2px 6px #f7f8fa;
    }
    .ba-photo-pick.selected .ba-photo-pick-order { display: flex; }
    .ba-photo-pick.selected[data-rank="1"] .ba-photo-pick-order { background: var(--accent-3); }
    .ba-photo-pick-check {
      position: absolute; top: 6px; right: 6px;
      width: 22px; height: 22px;
      border-radius: 50%;
      background: rgba(255,255,255,0.85);
      color: var(--accent);
      display: flex; align-items: center; justify-content: center;
      font-size: 14px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }
    .ba-photo-pick.selected .ba-photo-pick-check {
      background: var(--accent-3);
      color: #fff;
    }
    .ba-photo-selector-info {
      margin-top: 12px;
      font-size: 12px;
      color: var(--muted);
      display: flex; justify-content: space-between; align-items: center;
      flex-wrap: wrap; gap: 8px;
    }
    .ba-photo-selector-info strong { color: var(--accent); font-family: 'DM Mono', monospace; }
    .ba-photo-selector-info .full { color: var(--accent-2); }

    /* Score completude card */
    .ba-score-card {
      background: var(--card);
      border-radius: var(--r-lg);
      box-shadow: var(--neu-out);
      padding: 14px 16px;
    }
    .ba-score-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 10px;
    }
    .ba-score-title {
      font-size: 11px;
      font-weight: 700;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: .08em;
      font-family: 'DM Mono', monospace;
    }
    .ba-score-pct {
      font-size: 22px;
      font-weight: 800;
      color: var(--accent-3);
      font-family: 'DM Mono', monospace;
      line-height: 1;
    }
    .ba-score-bar {
      height: 6px;
      background: var(--bg);
      box-shadow: var(--neu-in);
      border-radius: 99px;
      overflow: hidden;
      margin-bottom: 12px;
    }
    .ba-score-fill {
      height: 100%;
      border-radius: 99px;
      background: linear-gradient(90deg, var(--accent) 0%, var(--accent-3) 100%);
      transition: width .35s ease;
    }
    .ba-score-row {
      display: flex;
      align-items: center;
      gap: 7px;
      font-size: 11px;
      color: var(--muted);
      padding: 3px 0;
    }
    .ba-score-row .icon { width: 14px; text-align: center; }
    .ba-score-row.ok { color: var(--accent-3); }
    .ba-score-row.warn { color: var(--accent-2); }

    /* ── TABS NAV (sticky sous la topbar) ── */
    .ba-tabs-wrap {
      position: sticky;
      top: calc(var(--topbar-h) + 78px); /* sous le page-head sticky */
      z-index: 40;
      background: var(--bg);
      margin-bottom: 18px;
      box-shadow: 0 6px 12px -8px var(--shadow-dark);
    }
    .ba-tabs-wrap::after {
      content: '';
      position: absolute;
      top: 0; right: 0; bottom: 0;
      width: 50px;
      pointer-events: none;
      background: linear-gradient(90deg, rgba(245,243,238,0), var(--bg));
    }
    .ba-tabs {
      display: flex; flex-wrap: nowrap; gap: 6px;
      overflow-x: auto;
      padding: 12px 32px 12px 0;
      scrollbar-width: none;
    }
    .ba-tabs::-webkit-scrollbar { display: none; }
    .ba-tab {
      flex-shrink: 0;
      padding: 8px 16px; border-radius: 999px;
      background: var(--bg);
      border: none;
      color: var(--muted);
      font-size: 13px; font-weight: 600;
      cursor: pointer; transition: box-shadow .18s, color .18s;
      font-family: inherit; white-space: nowrap;
      box-shadow: var(--neu-out);
    }
    .ba-tab:hover { color: var(--ink); }
    .ba-tab.active {
      box-shadow: var(--neu-in);
      color: var(--accent);
    }

    /* ── PANEL ── */
    .ba-panel { display: none; }
    .ba-panel.active { display: block; }

    /* ── CARD ── */
    .ba-card {
      background: var(--card);
      border: none;
      border-radius: var(--r-lg);
      box-shadow: var(--neu-out);
      margin-bottom: 16px;
      overflow: hidden;
    }
    .ba-card-head {
      padding: 16px 22px 14px;
      border-bottom: 1px solid var(--stroke);
    }
    .ba-card-title {
      font-size: 14px; font-weight: 700; color: var(--ink);
    }
    .ba-card-sub {
      font-size: 12px; color: var(--muted); margin-top: 3px;
    }
    .ba-card-body { padding: 20px 22px; }

    /* ── FORM GRID ── */
    .ba-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 14px;
    }
    .ba-grid.cols-2 { grid-template-columns: repeat(2, 1fr); }
    .ba-grid.cols-1 { grid-template-columns: 1fr; }
    .ba-col-full { grid-column: 1 / -1; }
    @media (max-width: 760px) {
      .ba-grid, .ba-grid.cols-2 { grid-template-columns: 1fr 1fr; }
    }
    @media (max-width: 560px) {
      .ba-grid, .ba-grid.cols-2 { grid-template-columns: 1fr; }
    }

    /* ── FIELD ── */
    .ba-field label {
      display: block; font-size: 11px; font-weight: 600;
      font-family: 'DM Mono', monospace;
      color: var(--muted); margin-bottom: 6px;
      text-transform: uppercase; letter-spacing: .6px;
    }
    .ba-field input[type="text"],
    .ba-field input[type="number"],
    .ba-field input[type="date"],
    .ba-field input[type="email"],
    .ba-field input[type="url"],
    .ba-field input[type="tel"],
    .ba-field select,
    .ba-field textarea {
      width: 100%;
      padding: 10px 14px;
      background: var(--bg);
      border: none;
      border-radius: 10px;
      color: var(--ink);
      font-size: 14px;
      font-family: inherit;
      box-shadow: var(--neu-in);
      transition: box-shadow .18s;
      outline: none;
    }
    .ba-field input:focus,
    .ba-field select:focus,
    .ba-field textarea:focus {
      box-shadow: var(--neu-in), 0 0 0 2px rgba(54,87,125,0.25);
    }
    .ba-field select option { background: var(--card); color: var(--ink); }
    .ba-field textarea { resize: vertical; }
    .ba-field input:-webkit-autofill,
    .ba-field input:-webkit-autofill:hover,
    .ba-field input:-webkit-autofill:focus {
      -webkit-text-fill-color: var(--ink);
      -webkit-box-shadow: 0 0 0 1000px var(--bg-secondary) inset;
      caret-color: var(--ink);
    }
    .ba-hint {
      font-family: 'DM Mono', monospace;
      font-size: 11px; color: var(--muted); margin-top: 5px;
    }

    /* ── RADIO/TOGGLE CHIPS ── */
    .ba-chips { display: flex; flex-wrap: wrap; gap: 8px; }
    .ba-chip {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 7px 14px; border-radius: 999px;
      border: none;
      background: var(--bg); color: var(--muted);
      font-size: 13px; font-weight: 500; cursor: pointer;
      box-shadow: var(--neu-out);
      transition: box-shadow .18s, color .18s; font-family: inherit;
    }
    .ba-chip input { display: none; }
    .ba-chip.checked,
    .ba-chip:has(input:checked) {
      box-shadow: var(--neu-in);
      color: var(--accent); font-weight: 700;
    }

    /* ── DPE PALETTE ── */
    .ba-dpe-row { display: flex; gap: 6px; flex-wrap: wrap; }
    .ba-dpe-btn {
      width: 44px; height: 44px; border-radius: 10px;
      display: grid; place-items: center;
      font-size: 15px; font-weight: 800;
      border: 2px solid transparent; cursor: pointer;
      transition: .15s; font-family: inherit;
      box-shadow: var(--neu-out);
    }
    .ba-dpe-btn[data-dpe="A"] { background: rgba(0,180,90,0.15);  color: #0a7a3e; }
    .ba-dpe-btn[data-dpe="B"] { background: rgba(50,180,50,0.15); color: #2a7a28; }
    .ba-dpe-btn[data-dpe="C"] { background: rgba(150,200,0,0.15); color: #6a8c00; }
    .ba-dpe-btn[data-dpe="D"] { background: rgba(230,180,0,0.15); color: #a07800; }
    .ba-dpe-btn[data-dpe="E"] { background: rgba(230,120,0,0.15); color: #b05800; }
    .ba-dpe-btn[data-dpe="F"] { background: rgba(220,60,0,0.15);  color: #aa2a00; }
    .ba-dpe-btn[data-dpe="G"] { background: rgba(190,0,0,0.15);   color: #8a0000; }
    .ba-dpe-btn.selected { border-color: currentColor; transform: scale(1.1); box-shadow: var(--neu-in); }

    /* ── ALERT / SUCCESS ── */
    .ba-alert {
      padding: 12px 18px; border-radius: 10px;
      font-size: 14px; margin-bottom: 20px;
    }
    .ba-alert.error   { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
    .ba-alert.success { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }

    /* ── PANEL FOOTER ── */
    .ba-panel-footer {
      display: flex; align-items: center; justify-content: space-between;
      gap: 12px; padding-top: 4px;
    }
    .ba-btn-ghost {
      padding: 9px 20px; border-radius: 999px;
      background: var(--bg);
      border: none;
      color: var(--muted); font-size: 13px; font-weight: 600;
      box-shadow: var(--neu-out);
      cursor: pointer; transition: box-shadow .18s, color .18s; font-family: inherit;
    }
    .ba-btn-ghost:hover { box-shadow: var(--neu-in); color: var(--ink); }
    .ba-btn-primary {
      padding: 9px 22px; border-radius: 999px;
      background: linear-gradient(135deg, var(--btn-save-from), var(--btn-save-to));
      color: #fff;
      font-size: 13px; font-weight: 700;
      border: none; cursor: pointer; transition: opacity .18s; font-family: inherit;
      box-shadow: 0 4px 12px rgba(249,115,22,0.35);
    }
    .ba-btn-primary:hover { opacity: .88; }

    /* ── SOUS-ONGLETS "+ DÉTAILS" ── */
    .ba-subtabs-row {
      display: flex; flex-wrap: wrap; gap: 8px;
      margin: 18px 0 0;
      padding: 6px;
      background: var(--bg);
      border-radius: 14px;
      box-shadow: var(--neu-in);
    }
    .ba-more-btn {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 9px 18px; border-radius: 10px;
      background: transparent;
      border: none;
      color: var(--muted); font-size: 12px; font-weight: 700;
      cursor: pointer; transition: all .18s; font-family: inherit;
      text-transform: uppercase; letter-spacing: .5px;
    }
    .ba-subtabs-row .ba-more-btn { margin-top: 0; }
    .ba-more-btn:hover { background: rgba(31,111,122,.08); color: #1f6f7a; }
    .ba-more-btn.open {
      background: #1f6f7a;
      color: #fff;
      box-shadow: 0 2px 8px rgba(31,111,122,.3);
    }
    .ba-more-btn.open .ba-more-arrow { color: #fff; }
    .ba-more-btn .ba-more-arrow {
      font-size: 10px; transition: transform .2s;
    }
    .ba-more-btn.open .ba-more-arrow { transform: rotate(180deg); }

    .ba-details-card {
      display: none;
      background: var(--bg);
      border: none;
      border-radius: 14px;
      box-shadow: var(--neu-in);
      padding: 20px 22px;
      margin-top: 12px;
    }
    .ba-details-card.open { display: block; }
    .ba-details-title {
      font-family: 'DM Mono', monospace;
      font-size: 11px; font-weight: 700; color: var(--muted);
      text-transform: uppercase; letter-spacing: .8px;
      margin-bottom: 16px;
    }

    /* ── BOOL CHIPS (équipements) ── */
    .ba-bool-grid {
      display: flex; flex-wrap: wrap; gap: 8px;
    }
    .ba-bool-chip {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 6px 13px; border-radius: 999px;
      border: none;
      background: var(--bg); color: var(--muted);
      font-size: 12px; font-weight: 600;
      box-shadow: var(--neu-out);
      cursor: pointer; transition: box-shadow .15s, color .15s; font-family: inherit;
      user-select: none;
    }
    .ba-bool-chip input { display: none; }
    .ba-bool-chip:has(input:checked),
    .ba-bool-chip.on {
      box-shadow: var(--neu-in);
      color: var(--accent-3);
    }

    /* ── TOOLTIP ── */
    .ba-tooltip-trigger {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      position: relative;
      margin-left: 6px;
      vertical-align: middle;
      cursor: pointer;
    }
    .ba-tooltip-icon {
      width: 16px; height: 16px;
      border-radius: 50%;
      background: rgba(54,87,125,0.12);
      border: 1px solid rgba(54,87,125,0.30);
      color: var(--accent);
      font-size: 10px;
      font-weight: 800;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      line-height: 1;
      font-style: normal;
      transition: background .15s;
    }
    .ba-tooltip-trigger:hover .ba-tooltip-icon,
    .ba-tooltip-trigger:focus .ba-tooltip-icon {
      background: rgba(54,87,125,0.20);
    }
    .ba-tooltip {
      display: none;
      position: absolute;
      bottom: calc(100% + 10px);
      left: 50%;
      transform: translateX(-50%);
      width: 320px;
      background: var(--card);
      border: 1px solid var(--stroke);
      border-radius: 14px;
      padding: 14px 16px;
      box-shadow: var(--neu-out);
      z-index: 999;
      pointer-events: none;
    }
    .ba-tooltip::after {
      content: '';
      position: absolute;
      top: 100%; left: 50%;
      transform: translateX(-50%);
      border: 6px solid transparent;
      border-top-color: var(--stroke);
    }
    .ba-tooltip-trigger:hover .ba-tooltip,
    .ba-tooltip-trigger:focus .ba-tooltip { display: block; }
    .ba-tooltip-title {
      font-size: 12px; font-weight: 700;
      color: var(--accent); margin-bottom: 10px;
    }
    .ba-tooltip-list {
      list-style: none; padding: 0; margin: 0;
      display: flex; flex-direction: column; gap: 7px;
    }
    .ba-tooltip-list li {
      font-size: 12px; color: var(--muted); line-height: 1.5;
      padding-left: 10px; border-left: 2px solid rgba(54,87,125,0.25);
    }
    .ba-tooltip-list li strong { color: var(--ink); }
    .ba-tooltip-list li em { color: var(--accent-3); font-style: normal; }

    /* Compteur de caractères */
    .ba-char-count {
      font-family: 'DM Mono', monospace;
      font-size: 11px; color: var(--muted); margin-top: 5px;
      transition: color .2s;
    }
    .ba-char-count.warn  { color: #f59e0b; }
    .ba-char-count.ok    { color: #7a9060; }
    .ba-char-count.over  { color: #cc5c58; }

    /* ── TYPE DE BIEN — grid harmonisé avec annonce_nouvelle.php (-15% taille) ── */
    .type-cards {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(85px, 1fr));
      gap: 8px;
      margin-top: 7px;
    }
    .type-card {
      position: relative;
      border: 2px solid #e5e5e5;
      background: #f5f1ee;
      border-radius: 10px;
      padding: 12px 7px;
      cursor: pointer;
      transition: all .18s ease;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 5px;
      text-align: center;
      min-height: 78px;
      justify-content: center;
      font-family: inherit;
      color: var(--ink);
    }
    .type-card:hover {
      border-color: var(--accent-2);
      transform: translateY(-2px);
      box-shadow: 0 3px 12px rgba(245,158,11,0.15);
    }
    .type-card.selected {
      border-color: var(--accent);
      background: rgba(54,87,125,.06);
      box-shadow: 0 0 0 3px rgba(54,87,125,.12), 0 5px 15px rgba(54,87,125,.10);
      color: var(--accent);
    }
    .type-card-icon {
      font-size: 24px;
      line-height: 1;
      display: block;
    }
    .type-card-label {
      font-size: 11px;
      font-weight: 700;
      text-align: center;
      line-height: 1.2;
      color: inherit;
    }

    /* ── PLACES DROPDOWN ── */
    .places-dropdown {
      position: absolute;
      z-index: 9999;
      background: var(--card);
      border: 1px solid var(--stroke);
      border-radius: 12px;
      box-shadow: var(--neu-out);
      overflow: hidden;
      max-height: 280px;
      overflow-y: auto;
    }
    .places-item {
      padding: 11px 16px;
      font-size: 13px;
      color: var(--ink);
      cursor: pointer;
      border-bottom: 1px solid var(--stroke);
      transition: background .12s;
      line-height: 1.4;
    }
    .places-item:last-child { border-bottom: none; }
    .places-item:hover,
    .places-item.active {
      background: rgba(54,87,125,0.08);
      color: var(--accent);
    }

    /* ── BADGE IMMEUBLE ── */
    .places-immeuble-badge {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 6px 14px; border-radius: 20px;
      font-size: 12px; font-weight: 600;
      margin-bottom: 14px; min-height: 0;
      transition: all .2s;
    }
    .places-immeuble-badge:empty { display: none; }
    .places-immeuble-badge.known {
      background: #f0fdf4;
      border: 1px solid #bbf7d0;
      color: #166534;
    }
    .places-immeuble-badge.new {
      background: rgba(54,87,125,.08);
      border: 1px solid rgba(54,87,125,.20);
      color: var(--accent);
    }

    /* ── COMPASS ROSE (exposition) ── */
    .ba-compass { display: flex; gap: 18px; align-items: flex-start; flex-wrap: wrap; margin-top: 4px; }
    .ba-compass-rose {
      position: relative; width: 110px; height: 110px; flex-shrink: 0;
    }
    .ba-compass-btn {
      position: absolute; width: 34px; height: 34px; border-radius: 50%;
      border: none;
      background: var(--bg); color: var(--muted);
      font-size: 11px; font-weight: 700; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      box-shadow: var(--neu-out);
      transition: box-shadow .15s, color .15s; padding: 0;
    }
    .ba-compass-btn:hover { box-shadow: var(--neu-in); color: var(--accent); }
    .ba-compass-btn.active { box-shadow: var(--neu-in); color: var(--accent); }
    .ba-compass-btn[data-dir=nord]  { top:0; left:38px; }
    .ba-compass-btn[data-dir=est]   { top:38px; right:0; }
    .ba-compass-btn[data-dir=sud]   { bottom:0; left:38px; }
    .ba-compass-btn[data-dir=ouest] { top:38px; left:0; }
    .ba-compass-center {
      position: absolute; top:38px; left:38px; width:34px; height:34px;
      border-radius:50%; background: var(--card);
      display:flex; align-items:center; justify-content:center;
      font-size:16px; pointer-events:none;
    }
    .ba-compass-extras { display: flex; flex-direction: column; gap: 6px; justify-content: center; }
    .ba-compass-chip {
      padding: 5px 13px; border-radius: 999px;
      border: none;
      background: var(--bg); color: var(--muted);
      font-size: 11px; font-weight: 600; cursor: pointer;
      box-shadow: var(--neu-out);
      transition: box-shadow .15s, color .15s;
    }
    .ba-compass-chip:hover { box-shadow: var(--neu-in); color: var(--accent); }
    .ba-compass-chip.active { box-shadow: var(--neu-in); color: var(--accent); }

    /* ── VUE MINI CARDS ── */
    .ba-vue-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 7px; }
    .ba-vue-card {
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      padding: 10px 4px; border-radius: 8px; gap: 5px;
      border: none;
      background: var(--bg); cursor: pointer;
      box-shadow: var(--neu-out);
      transition: box-shadow .15s, color .15s; user-select: none;
    }
    .ba-vue-card:hover { box-shadow: var(--neu-in); color: var(--ink); }
    .ba-vue-card.active { box-shadow: var(--neu-in); color: var(--accent); }
    .ba-vue-icon { font-size: 22px; line-height: 1; }
    .ba-vue-label { font-size: 10px; font-weight: 600; text-align: center; }


  /* ── Import intelligent ──────────────────────────────── */
  .bi-trigger-wrap { margin-bottom: 24px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
  .bi-trigger-btn {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 10px 18px; border-radius: 10px; border: 1.5px dashed rgba(54,87,125,.40);
    background: rgba(54,87,125,.06); color: var(--accent);
    font-size: 14px; font-weight: 600; cursor: pointer;
    transition: background .2s, border-color .2s;
  }
  .bi-trigger-btn:hover { background: rgba(54,87,125,.12); border-color: rgba(54,87,125,.60); }
  .bi-trigger-btn .bi-trigger-icon { font-size: 20px; line-height: 1; }
  .bi-trigger-hint { font-family: 'DM Mono', monospace; font-size: 11px; color: var(--muted); }
  #bi-file-input { display: none; }

  #bi-panel {
    display: none;
    background: var(--bg); border: none;
    border-radius: 10px; padding: 14px 16px; margin-bottom: 20px;
    box-shadow: var(--neu-in);
  }
  #bi-panel.bi-panel-open { display: block; }

  .bi-fb-btn {
    background: var(--bg); border: 1px solid var(--stroke); border-radius: 5px;
    cursor: pointer; font-size: 14px; padding: 2px 5px; line-height: 1;
    transition: opacity .15s; opacity: .7;
  }
  .bi-fb-btn:hover { opacity: 1; }
  .bi-fb-corr { font-size:11px; }

  .bi-proprio-card {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 12px; border-radius: 8px;
    border: 1px solid rgba(54,87,125,.20);
    background: rgba(54,87,125,.06);
  }
  .bi-chip { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; background: rgba(54,87,125,.08); color: var(--ink); }
  .bi-chip-info { background: rgba(54,87,125,.14); color: var(--accent); }

  .bi-list-wrap { overflow-x: auto; }
  .bi-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .bi-table th { text-align: left; padding: 8px 10px; color: var(--muted); font-weight: 600; border-bottom: 1px solid var(--stroke); white-space: nowrap; }
  .bi-table td { padding: 8px 10px; border-bottom: 1px solid var(--stroke); vertical-align: middle; }
  .bi-table tr:last-child td { border-bottom: none; }

  .bi-score { display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 50%; font-size: 11px; font-weight: 700; }
  .bi-score.s-high   { background: #f0fdf4; color: #166534; }
  .bi-score.s-med    { background: #fffbeb; color: #92400e; }
  .bi-score.s-low    { background: #fef2f2; color: #991b1b; }

  .bi-badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: 600; }
  .bi-badge.ready      { background: #f0fdf4; color: #166534; }
  .bi-badge.incomplete { background: #fffbeb; color: #92400e; }
  .bi-badge.created    { background: rgba(54,87,125,.12); color: var(--accent); }
  .bi-badge.ignored    { background: var(--bg-secondary); color: var(--muted); }
  .bi-badge.analysing  { background: rgba(122,144,96,.12); color: #7a9060; }
  .bi-badge.error      { background: #fef2f2; color: #991b1b; }

  .bi-action-btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 5px 10px; border-radius: 7px; font-size: 12px; font-weight: 600;
    cursor: pointer; border: none; transition: opacity .15s;
  }
  .bi-action-btn:hover { opacity: .8; }
  .bi-action-btn.primary { background: var(--accent); color: #fff; }
  .bi-action-btn.secondary { background: var(--card); color: var(--ink); box-shadow: var(--neu-out); }
  .bi-action-btn.danger { background: #fef2f2; color: #991b1b; }
  .bi-action-btn.success { background: #f0fdf4; color: #166534; }
  .bi-action-btn:disabled { opacity: .4; cursor: not-allowed; }

  .bi-manquants { display: inline-flex; gap: 4px; flex-wrap: wrap; }
  .bi-chip-missing { padding: 2px 6px; border-radius: 6px; font-size: 11px; background: #fef2f2; color: #991b1b; }

  /* Modal */
  .bi-modal-overlay {
    display: none; position: fixed; inset: 0; z-index: 9000;
    background: rgba(26,24,22,.5); align-items: center; justify-content: center;
  }
  .bi-modal-overlay.bi-modal-open { display: flex; }
  .bi-modal {
    background: var(--card); border-radius: 14px; width: 720px; max-width: 95vw;
    max-height: 90vh; overflow-y: auto; padding: 28px; position: relative;
    border: none; box-shadow: var(--neu-out);
  }
  .bi-modal-close {
    position: absolute; top: 14px; right: 14px; background: var(--bg); border: none;
    font-size: 20px; cursor: pointer; color: var(--muted); line-height: 1;
    box-shadow: var(--neu-out); border-radius: 6px; width: 28px; height: 28px;
    display: flex; align-items: center; justify-content: center;
  }
  .bi-modal-close:hover { color: var(--ink); box-shadow: var(--neu-in); }
  .bi-modal-title { font-size: 16px; font-weight: 700; margin-bottom: 18px; color: var(--ink); }
  .bi-bloc { background: var(--bg); border-radius: 10px; padding: 16px; margin-bottom: 14px; border-left: 3px solid transparent; }
  .bi-bloc.bloc-missing { border-color: #cc5c58; }
  .bi-bloc.bloc-ok      { border-color: #7a9060; }
  .bi-bloc.bloc-check   { border-color: #f59e0b; }
  .bi-bloc.bloc-deduced { border-color: #7a9060; }
  .bi-bloc-title { font-family: 'DM Mono', monospace; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 10px; color: var(--muted); }
  .bi-bloc.bloc-missing .bi-bloc-title { color: #cc5c58; }
  .bi-bloc.bloc-ok .bi-bloc-title      { color: #7a9060; }
  .bi-bloc.bloc-check .bi-bloc-title   { color: #f59e0b; }
  .bi-bloc.bloc-deduced .bi-bloc-title { color: #7a9060; }
  .bi-field-row { display: flex; flex-wrap: wrap; gap: 8px 12px; }
  .bi-field-item { display: flex; flex-direction: column; min-width: 140px; flex: 1; }
  .bi-field-item label { font-size: 11px; color: var(--muted); margin-bottom: 3px; }
  .bi-field-item input, .bi-field-item select { padding: 6px 10px; border-radius: 7px; border: none; background: var(--bg); color: var(--ink); font-size: 13px; width: 100%; box-shadow: var(--neu-in); }
  .bi-field-item input:focus, .bi-field-item select:focus { outline: none; box-shadow: var(--neu-in), 0 0 0 2px rgba(54,87,125,0.25); }
  .bi-desc-textarea { width: 100%; min-height: 80px; padding: 10px; border-radius: 8px; border: none; background: var(--bg); color: var(--ink); font-size: 13px; resize: vertical; box-sizing: border-box; box-shadow: var(--neu-in); }
  .bi-desc-textarea:focus { outline: none; box-shadow: var(--neu-in), 0 0 0 2px rgba(54,87,125,0.25); }
  .bi-modal-footer { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--stroke); }

  /* Toasts */
  #bi-toasts { position: fixed; bottom: 24px; right: 24px; z-index: 9500; display: flex; flex-direction: column; gap: 8px; pointer-events: none; }
  .bi-toast { padding: 10px 16px; border-radius: 10px; font-size: 13px; font-weight: 600; pointer-events: auto; opacity: 1; transition: opacity .4s; }
  .bi-toast.success { background: #7a9060; color: #fff; }
  .bi-toast.error   { background: #cc5c58; color: #fff; }
  .bi-toast.info    { background: var(--accent); color: #fff; }
  .bi-toast.fade-out { opacity: 0; }

  /* Spinner */
  .bi-spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid var(--stroke); border-top-color: var(--accent); border-radius: 50%; animation: bispin .6s linear infinite; }
  @keyframes bispin { to { transform: rotate(360deg); } }

  </style>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="<?= asset_url('/css/minicard.css') ?>">
</head>
<body>

<?php require_once __DIR__ . '/inc/sidebar_agency.php'; ?>

<main class="mbi-main">

  <!-- TOPBAR V2 -->
  <header class="mbi-topbar">
    <button type="button" class="topbar-nav-btn" onclick="history.back()" title="Retour">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>
    </button>
    <button type="button" class="topbar-nav-btn" onclick="history.forward()" title="Avancer">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>
    </button>
    <div class="topbar-gap"></div>
    <nav class="topbar-breadcrumb">
      <a href="bien_liste.php">Biens</a>
      <span class="sep">›</span>
      <span class="active">Ajouter un bien</span>
    </nav>
    <div class="topbar-spacer"></div>
    <button type="button" class="topbar-icon-btn" title="Notifications">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
    </button>
    <div class="topbar-avatar"><?= strtoupper(substr($username, 0, 1)) ?></div>
  </header>

  <!-- PAGE HEAD -->
  <div class="page-head">
    <div class="page-head-info">
      <div class="page-head-label">Gestion des biens</div>
      <h1 class="page-head-title"><?= h($pageTitle) ?></h1>
      <div class="page-head-sub">
        <?php if ($isDraft): ?>
          📝 Brouillon en cours d'édition — vous pouvez sauvegarder à tout moment
        <?php elseif ($isEditing): ?>
          Modification du bien #<?= (int)$editingBienId ?>
        <?php else: ?>
          Renseignez les informations du nouveau bien immobilier
        <?php endif; ?>
      </div>
    </div>

    <?php if ($isEditing && $ubiCheck !== null): ?>
    <?php
      $miniClass = match ($ubiCheck['status']) {
          UBIFLOW_CHECK_OK         => 'ok',
          UBIFLOW_CHECK_WARNING    => 'warn',
          UBIFLOW_CHECK_INCOMPLET  => 'bad',
      };
      if ($isDraft) $miniClass = 'draft';
      $miniLabel = match (true) {
          $isDraft                                  => '📝 Brouillon',
          $ubiCheck['status'] === UBIFLOW_CHECK_OK  => '✅ Diffusable',
          $ubiCheck['status'] === UBIFLOW_CHECK_WARNING => '⚠️ Avertissements',
          default                                   => '🚫 Non diffusable',
      };
    ?>
    <div class="conf-mini <?= $miniClass ?>" title="Conformité Ubiflow">
      <div class="conf-mini-head">
        <span class="conf-mini-status"><?= $miniLabel ?></span>
        <span class="conf-mini-score"><?= (int)$ubiCheck['score'] ?>%</span>
      </div>
      <div class="conf-mini-bar"><span style="width:<?= (int)$ubiCheck['score'] ?>%"></span></div>
      <?php if (!empty($ubiCheck['missing'])): ?>
      <ul class="conf-mini-list">
        <?php foreach ($ubiCheck['missing'] as $m): ?>
          <li class="miss-<?= h($m['source']) ?>" title="<?= h($m['label']) ?><?= !empty($m['aide']) ? ' — ' . h($m['aide']) : '' ?>">
            <?= h($m['label']) ?>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <div class="conf-mini-empty">Tous les champs requis sont remplis ✨</div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Boutons d'action sticky (toujours visibles) ── -->
    <?php if ($isEditing): ?>
    <div class="page-head-actions">
      <?php if ($isDraft): ?>
        <button type="submit" form="bien-create-form" class="ph-save-btn secondary"
                title="Sauvegarde sans changer le statut — vous pourrez revenir plus tard">
          💾 Sauvegarder le brouillon
        </button>
        <button type="submit" form="bien-create-form" class="ph-save-btn success"
                onclick="document.getElementById('_validate_now').value='1';"
                title="Valide le bien et le passe en statut actif">
          ✅ Valider et activer
        </button>
      <?php else: ?>
        <button type="submit" form="bien-create-form" class="ph-save-btn">
          💾 Mettre à jour le bien
        </button>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="page-head-actions">
      <button type="submit" form="bien-create-form" class="ph-save-btn">
        💾 Enregistrer le bien
      </button>
    </div>
    <?php endif; ?>
  </div>

  <div class="mbi-container">

    <?php if (!empty($errors)): ?>
    <div class="ba-alert error"><?= h(implode(' ', $errors)) ?></div>
    <?php elseif ($success !== ''): ?>
    <div class="ba-alert success"><?= h($success) ?></div>
    <?php endif; ?>

    <form method="post" id="bien-create-form" enctype="multipart/form-data">
      <?php if ($isEditing): ?>
      <input type="hidden" name="_edit_id" value="<?= (int)$editingBienId ?>">
      <?php endif; ?>
      <input type="hidden" name="_validate_now" id="_validate_now" value="">

      <?php /* Bandeau de conformité déplacé en page-head (mini-card à droite du titre) */ ?>

      <?= csrf_field('ajouter_bien') ?>

      <!-- ── TABS (full width, au-dessus du layout 2 colonnes) ── -->
      <div class="ba-tabs-wrap">
        <nav class="ba-tabs" role="tablist">
          <button type="button" class="ba-tab active" data-tab="identification">🏠 Identification</button>
          <button type="button" class="ba-tab" data-tab="adresse">📍 Adresse</button>
          <button type="button" class="ba-tab" data-tab="caracteristiques">📐 Caractéristiques</button>
          <button type="button" class="ba-tab" data-tab="prix">💶 Prix</button>
          <button type="button" class="ba-tab" data-tab="dpe">⚡ DPE</button>
          <button type="button" class="ba-tab" data-tab="photos">📸 Photos <span class="ba-tab-count" id="ba-tab-count-photos" style="display:none;"></span></button>
          <button type="button" class="ba-tab" data-tab="description">✏️ Description</button>
          <button type="button" class="ba-tab" data-tab="annonce">📡 Annonce</button>
          <button type="button" class="ba-tab" data-tab="disponibilite">📅 Disponibilité</button>
          <button type="button" class="ba-tab" data-tab="diffusion">🌐 Diffusion</button>
        </nav>
      </div>

      <div class="ba-layout">
        <div class="ba-form-col">

      <?php
      // Helper macro : bouton + détails
      function moreBtn(string $id): string {
        return '<button type="button" class="ba-more-btn" onclick="toggleDetails(\'' . $id . '\',this)"><span class="ba-more-arrow">▼</span> + Détails</button>';
      }
      function detailsCard(string $id, string $title, string $inner): string {
        return '<div class="ba-details-card" id="det-' . $id . '"><div class="ba-details-title">' . $title . '</div>' . $inner . '</div>';
      }
      // Helper bool chip
      function boolChip(string $name, string $label, string $postKey=''): string {
        $pk = $postKey ?: $name;
        $on = post($pk,'') === '1' ? ' on' : '';
        return '<label class="ba-bool-chip' . $on . '"><input type="checkbox" name="' . $name . '" value="1"' . ($on ? ' checked' : '') . '> ' . $label . '</label>';
      }
      ?>

      <!-- ════ ONGLET 1 — IDENTIFICATION ════ -->
      <div class="ba-panel active" data-tab-panel="identification">

        <?php /* Le sélecteur de propriétaire est plus bas, dans la card "Propriétaire & Mandat" */ ?>

        <!-- ── Import PDF intelligent ── -->
        <div class="bi-trigger-wrap">
          <button type="button" id="bi-trigger" class="bi-trigger-btn">
            <span class="bi-trigger-icon">📄</span>
            Importer une fiche PDF
          </button>
          <span class="bi-trigger-hint">PDF — max 20 Mo — analyse automatique par IA</span>
          <input type="file" id="bi-file-input" accept=".pdf" multiple style="display:none">
        </div>

        <div id="bi-panel">
          <div id="bi-list-wrap" class="bi-list-wrap">
            <div id="bi-list"></div>
          </div>
        </div>

        <!-- Modal validation import -->
        <div id="bi-modal" class="bi-modal-overlay">
          <div class="bi-modal">
            <button type="button" id="bi-modal-close" class="bi-modal-close">✕</button>
            <div class="bi-modal-title">Vérification de l'import</div>
            <div id="bi-modal-content"></div>
            <div class="bi-modal-footer">
              <button type="button" class="bi-action-btn secondary" onclick="document.getElementById('bi-modal').classList.remove('bi-modal-open')">Annuler</button>
              <button type="button" id="bi-modal-fill" class="bi-action-btn primary">📝 Compléter le formulaire</button>
              <button type="button" id="bi-modal-create" class="bi-action-btn success">⚡ Créer directement</button>
            </div>
          </div>
        </div>

        <!-- Conteneur toasts -->
        <div id="bi-toasts"></div>

        <div class="ba-card">
          <div class="ba-card-head">
            <div class="ba-card-title">Identification du bien</div>
            <div class="ba-card-sub">Type, référence et désignation commerciale.</div>
          </div>
          <div class="ba-card-body">
            <?php
              $typeBien = (string)post('type_bien', 'appartement');
              // Map emoji par code (synchronisé avec annonce_nouvelle.php)
              $EMOJI_TYPE = [
                  'appartement'      => '🏢',
                  'maison'           => '🏠',
                  'villa'            => '🏡',
                  'terrain'          => '🌳',
                  'local_commercial' => '🏬',
                  'bureau'           => '💼',
                  'immeuble'         => '🏦',
                  'parking'          => '🅿️',
                  'garage'           => '🚗',
                  'entrepot'         => '🏭',
                  'boutique'         => '🛍️',
                  'loft'             => '🛋️',
                  'atelier'          => '🛠️',
                  'fonds_commerce'   => '🏪',
                  'droit_bail'       => '📜',
                  'programme_neuf'   => '🏗️',
                  'chateau'          => '🏰',
                  'chambre'          => '🛏️',
                  'box'              => '📦',
                  'local_activite'   => '🏗️',
              ];
            ?>

            <!-- Type de bien : mini cards (style harmonisé annonce_nouvelle) -->
            <div class="ba-field" style="margin-bottom:20px;">
              <label>Type de bien</label>
              <input type="hidden" name="type_bien" id="type_bien_hidden" value="<?= h($typeBien) ?>">
              <div class="type-cards">
                <?php
                if (!$_typeBienFallback):
                  foreach ($socTypesBien as $t):
                    $emoji = $EMOJI_TYPE[$t['code']] ?? '🏠';
                ?>
                <button
                  type="button"
                  class="type-card <?= $typeBien === $t['code'] ? 'selected' : '' ?>"
                  data-value="<?= h($t['code']) ?>"
                  onclick="selectType(this)"
                  title="<?= h($t['description'] ?? '') ?>"
                >
                  <span class="type-card-icon"><?= $emoji ?></span>
                  <span class="type-card-label"><?= h($t['label']) ?></span>
                </button>
                <?php endforeach; else:
                // Fallback : tous les types Ubiflow standard
                $typesBienFallback = [
                  'appartement'      => 'Appartement',
                  'maison'           => 'Maison',
                  'villa'            => 'Villa',
                  'terrain'          => 'Terrain',
                  'local_commercial' => 'Local commercial',
                  'bureau'           => 'Bureau',
                  'immeuble'         => 'Immeuble',
                  'parking'          => 'Parking',
                  'garage'           => 'Garage',
                  'entrepot'         => 'Entrepôt',
                  'boutique'         => 'Boutique',
                  'loft'             => 'Loft',
                  'atelier'          => 'Atelier',
                  'fonds_commerce'   => 'Fonds de commerce',
                  'programme_neuf'   => 'Programme neuf',
                ];
                foreach ($typesBienFallback as $val => $label):
                  $emoji = $EMOJI_TYPE[$val] ?? '🏠';
                ?>
                <button
                  type="button"
                  class="type-card <?= $typeBien === $val ? 'selected' : '' ?>"
                  data-value="<?= h($val) ?>"
                  onclick="selectType(this)"
                >
                  <span class="type-card-icon"><?= $emoji ?></span>
                  <span class="type-card-label"><?= h($label) ?></span>
                </button>
                <?php endforeach; endif; ?>
              </div>
            </div>

            <div class="ba-grid cols-2" style="margin-bottom:0;">
              <div class="ba-field">
                <label>Référence interne</label>
                <input type="text" name="reference_bien" value="<?= h((string)post('reference_bien', '')) ?>" placeholder="Ex: AG-2024-042">
              </div>
              <div class="ba-field ba-col-full">
                <label>
                  Désignation commerciale <span style="color:#cc5c58;" title="Champ obligatoire">*</span>
                  <span class="ba-tooltip-trigger" tabindex="0" aria-describedby="tip-designation">
                    <span class="ba-tooltip-icon">?</span>
                    <div class="ba-tooltip" id="tip-designation" role="tooltip">
                      <div class="ba-tooltip-title">💡 Bonne désignation = meilleur référencement</div>
                      <ul class="ba-tooltip-list">
                        <li><strong>Type + pièces + atout principal</strong> — Ex : <em>T3 lumineux avec balcon</em></li>
                        <li><strong>Éviter</strong> les générique : "Appartement", "Maison à vendre" sans détail</li>
                        <li><strong>Inclure</strong> un élément distinctif : vue, terrasse, parking, rénové…</li>
                        <li><strong>Longueur idéale</strong> : 50 – 80 caractères</li>
                        <li><strong>Exemples</strong> :<br>
                          ✅ T4 familial avec jardin privatif, calme<br>
                          ✅ Studio refait à neuf proche métro<br>
                          ✅ Maison de caractère 5 pièces avec piscine
                        </li>
                      </ul>
                    </div>
                  </span>
                </label>
                <input type="text" name="designation" id="designation" value="<?= h((string)post('designation', '')) ?>"
                       placeholder="Ex: T3 lumineux avec balcon vue dégagée" required
                       style="<?= (isset($errors) && $designation === '') ? 'box-shadow:var(--neu-in),0 0 0 2px rgba(204,92,88,0.4);' : '' ?>">
                <div class="ba-char-count"><span id="desig-count">0</span> / 80 caractères — <strong>obligatoire</strong>, 50–80 caractères idéal</div>
                <div class="ba-hint" style="color:var(--muted);font-size:11px;margin-top:4px;">
                  Format attendu : <em>Type + nb pièces + atout principal</em> — ex : <strong>T3 lumineux avec balcon vue dégagée</strong>, <strong>Maison 5 pièces avec jardin privatif</strong>
                </div>
              </div>
            </div>

            <?= moreBtn('ident') ?>
            <?= detailsCard('ident', '⚙️ Informations complémentaires', '
            <div class="ba-grid">
              <div class="ba-field">
                <label>Sous-type</label>
                <input type="text" name="sous_type_bien" value="' . h((string)post('sous_type_bien','')) . '" placeholder="Ex: duplex, studio, loft…">
              </div>
              <div class="ba-field">
                <label>Usage</label>
                <select name="usage_bien">
                  <option value="">—</option>
                  <option value="habitation"' . (post('usage_bien','') === 'habitation' ? ' selected' : '') . '>Habitation</option>
                  <option value="commercial"' . (post('usage_bien','') === 'commercial' ? ' selected' : '') . '>Commercial</option>
                  <option value="professionnel"' . (post('usage_bien','') === 'professionnel' ? ' selected' : '') . '>Professionnel</option>
                  <option value="mixte"' . (post('usage_bien','') === 'mixte' ? ' selected' : '') . '>Mixte</option>
                </select>
              </div>
              <div class="ba-field">
                <label>Statut</label>
                <select name="statut_bien">
                  <option value="actif"' . (post('statut_bien','actif') === 'actif' ? ' selected' : '') . '>Actif</option>
                  <option value="brouillon"' . (post('statut_bien','') === 'brouillon' ? ' selected' : '') . '>Brouillon</option>
                  <option value="archive"' . (post('statut_bien','') === 'archive' ? ' selected' : '') . '>Archivé</option>
                </select>
              </div>
              <div class="ba-field">
                <label>Type commercialisation</label>
                <select name="type_commercialisation">
                  <option value="">—</option>
                  <option value="mandat_simple"' . (post('type_commercialisation','') === 'mandat_simple' ? ' selected' : '') . '>Mandat simple</option>
                  <option value="mandat_exclusif"' . (post('type_commercialisation','') === 'mandat_exclusif' ? ' selected' : '') . '>Mandat exclusif</option>
                  <option value="co_exclusivite"' . (post('type_commercialisation','') === 'co_exclusivite' ? ' selected' : '') . '>Co-exclusivité</option>
                </select>
              </div>
              <div class="ba-field">
                <label>Standing</label>
                <select name="standing">
                  <option value="">—</option>
                  <option value="economique"' . (post('standing','') === 'economique' ? ' selected' : '') . '>Économique</option>
                  <option value="standard"' . (post('standing','') === 'standard' ? ' selected' : '') . '>Standard</option>
                  <option value="standing"' . (post('standing','') === 'standing' ? ' selected' : '') . '>Standing</option>
                  <option value="luxe"' . (post('standing','') === 'luxe' ? ' selected' : '') . '>Luxe</option>
                </select>
              </div>
              <div class="ba-field">
                <label>État du bien</label>
                <select name="etat_bien">
                  <option value="">—</option>
                  <option value="neuf"' . (post('etat_bien','') === 'neuf' ? ' selected' : '') . '>Neuf</option>
                  <option value="recent"' . (post('etat_bien','') === 'recent' ? ' selected' : '') . '>Récent (&lt;10 ans)</option>
                  <option value="bon_etat"' . (post('etat_bien','') === 'bon_etat' ? ' selected' : '') . '>Bon état</option>
                  <option value="rafraichir"' . (post('etat_bien','') === 'rafraichir' ? ' selected' : '') . '>À rafraîchir</option>
                  <option value="travaux"' . (post('etat_bien','') === 'travaux' ? ' selected' : '') . '>Travaux importants</option>
                </select>
              </div>
              <div class="ba-field">
                <label>Réf. externe</label>
                <input type="text" name="reference_externe" value="' . h((string)post('reference_externe','')) . '">
              </div>
              <div class="ba-field">
                <label>Lot principal</label>
                <input type="text" name="lot_principal" value="' . h((string)post('lot_principal','')) . '" placeholder="Ex: A123">
              </div>
              <div class="ba-field">
                <label>Lot secondaire</label>
                <input type="text" name="lot_secondaire" value="' . h((string)post('lot_secondaire','')) . '">
              </div>
            </div>') ?>

          </div>
        </div>

        <!-- ════════════════════════════════════════════════
             👤 PROPRIÉTAIRE & MANDAT
             ════════════════════════════════════════════════ -->
        <div class="ba-card" style="margin-top:16px;">
          <div class="ba-card-head">
            <div class="ba-card-title">👤 Propriétaire & Mandat</div>
            <div class="ba-card-sub">Liez ce bien à un propriétaire existant et préparez le mandat de gestion ou de vente.</div>
          </div>
          <div class="ba-card-body">
            <div class="ba-grid cols-2">
              <div class="ba-field ba-col-full">
                <label>Propriétaire du bien</label>
                <select name="id_proprietaire" id="ba-proprietaire-select" style="font-size:13px;">
                  <option value="">— Aucun propriétaire associé —</option>
                  <?php foreach ($proprietairesList as $p):
                    $label = '';
                    if (($p['type_personne'] ?? '') === 'morale' || !empty($p['societe'])) {
                        $label = trim(($p['societe'] ?: '') . ' (' . trim(($p['prenom'] ?? '') . ' ' . ($p['nom'] ?? '')) . ')');
                    } else {
                        $label = trim(($p['civilite'] ?? '') . ' ' . ($p['prenom'] ?? '') . ' ' . ($p['nom'] ?? ''));
                    }
                    if ($p['ville']) $label .= ' — ' . $p['ville'];
                    $sel = ((string)post('id_proprietaire','') === (string)$p['id']) ? 'selected' : '';
                  ?>
                  <option value="<?= (int)$p['id'] ?>" <?= $sel ?>><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
                <div class="ba-hint">
                  <?= count($proprietairesList) ?> propriétaire(s) en base —
                  <a href="<?= h(app_url('/agency_mandant_form.php')) ?>" target="_blank" style="color:#1f6f7a;font-weight:600;">＋ Créer un nouveau propriétaire</a>
                </div>
              </div>
            </div>

            <!-- ── Mandat associé ── -->
            <div style="margin-top:18px;padding-top:18px;border-top:1px dashed var(--stroke);">
              <div style="font-weight:700;font-size:12px;color:#555;margin-bottom:10px;text-transform:uppercase;letter-spacing:.5px;">📜 Mandat de gestion / vente / location</div>
              <?php if ($mandatExistant): ?>
              <div style="padding:12px;background:#f0fdf4;border-left:3px solid #16a34a;border-radius:8px;margin-bottom:12px;">
                <div style="font-size:12px;font-weight:700;color:#15803d;">
                  ✅ Mandat associé : <?= h($mandatExistant['numero_mandat'] ?: '#' . $mandatExistant['id']) ?>
                </div>
                <div style="font-size:11px;color:#555;margin-top:4px;">
                  <?= h(ucfirst((string)$mandatExistant['type_mandat'])) ?>
                  <?= !empty($mandatExistant['nature_mandat']) ? ' • ' . h($mandatExistant['nature_mandat']) : '' ?>
                  <?= !empty($mandatExistant['exclusif']) ? ' • Exclusif' : '' ?>
                  <?= !empty($mandatExistant['date_signature']) ? ' • Signé le ' . h(date('d/m/Y', strtotime((string)$mandatExistant['date_signature']))) : '' ?>
                  • Statut : <strong><?= h($mandatExistant['statut']) ?></strong>
                </div>
                <div style="margin-top:8px;">
                  <a href="<?= h(app_url('/agency_mandats.php?bien=' . $editingBienId)) ?>" target="_blank"
                     style="font-size:11px;color:#15803d;font-weight:600;">Voir / modifier le mandat →</a>
                </div>
              </div>
              <?php endif; ?>
              <div class="ba-grid cols-3">
                <div class="ba-field">
                  <label>N° de mandat</label>
                  <input type="text" name="mandats_numero" value="<?= h((string)post('mandats_numero', $mandatExistant['numero_mandat'] ?? '')) ?>" placeholder="Auto-généré si vide">
                </div>
                <div class="ba-field">
                  <label>Type de mandat</label>
                  <select name="mandats_type">
                    <?php $mtVal = (string)post('mandats_type', $mandatExistant['type_mandat'] ?? ''); ?>
                    <option value="">— Aucun —</option>
                    <option value="vente"     <?= $mtVal === 'vente'     ? 'selected' : '' ?>>Vente</option>
                    <option value="location"  <?= $mtVal === 'location'  ? 'selected' : '' ?>>Location</option>
                    <option value="gestion"   <?= $mtVal === 'gestion'   ? 'selected' : '' ?>>Gestion locative</option>
                    <option value="recherche" <?= $mtVal === 'recherche' ? 'selected' : '' ?>>Recherche</option>
                  </select>
                </div>
                <div class="ba-field">
                  <label>Nature</label>
                  <select name="mandats_nature">
                    <?php $mnVal = (string)post('mandats_nature', $mandatExistant['nature_mandat'] ?? ''); ?>
                    <option value="">—</option>
                    <option value="simple"    <?= $mnVal === 'simple'    ? 'selected' : '' ?>>Simple</option>
                    <option value="exclusif"  <?= $mnVal === 'exclusif'  ? 'selected' : '' ?>>Exclusif</option>
                    <option value="semi"      <?= $mnVal === 'semi'      ? 'selected' : '' ?>>Semi-exclusif</option>
                  </select>
                </div>
                <div class="ba-field">
                  <label>Date de signature</label>
                  <input type="date" name="mandats_date_signature" value="<?= h((string)post('mandats_date_signature', $mandatExistant['date_signature'] ?? '')) ?>">
                </div>
                <div class="ba-field">
                  <label>Date de début</label>
                  <input type="date" name="mandats_date_debut" value="<?= h((string)post('mandats_date_debut', $mandatExistant['date_debut'] ?? '')) ?>">
                </div>
                <div class="ba-field">
                  <label>Date de fin</label>
                  <input type="date" name="mandats_date_fin" value="<?= h((string)post('mandats_date_fin', $mandatExistant['date_fin'] ?? '')) ?>">
                </div>
                <div class="ba-field">
                  <label>Honoraires (€)</label>
                  <input type="number" step="0.01" name="mandats_honoraires" value="<?= h((string)post('mandats_honoraires', $mandatExistant['honoraires'] ?? '')) ?>">
                </div>
              </div>
              <div class="ba-hint" style="margin-top:8px;">
                💡 En remplissant un type de mandat, un mandat sera automatiquement créé/mis à jour à l'enregistrement du bien.
              </div>
            </div>
          </div>
        </div>

        <div class="ba-panel-footer">
          <span></span>
          <button type="button" class="ba-btn-primary" onclick="switchTab('adresse')">Suivant →</button>
        </div>
      </div>

      <!-- ════ ONGLET 2 — ADRESSE ════ -->
      <div class="ba-panel" data-tab-panel="adresse">
        <div class="ba-card">
          <div class="ba-card-head">
            <div class="ba-card-title">Adresse & Immeuble</div>
            <div class="ba-card-sub">Commencez à taper l'adresse — les champs se remplissent automatiquement.</div>
          </div>
          <div class="ba-card-body">
            <div class="ba-grid cols-1" style="margin-bottom:16px;">
              <div class="ba-field">
                <label>Recherche d'adresse</label>
                <input
                  type="text"
                  id="immeuble_recherche"
                  name="immeuble_recherche"
                  placeholder="Ex: 10 rue de la Paix, Paris"
                  data-places-input
                  data-places-endpoint="<?= h(app_url('/api/places_autocomplete.php')) ?>"
                  data-places-details-endpoint="<?= h(app_url('/api/places_details.php')) ?>"
                  data-places-geocode-endpoint="<?= h(app_url('/api/geocode_address.php')) ?>"
                  data-places-street1="immeuble_adresse_1"
                  data-places-street2="immeuble_adresse_2"
                  data-places-postal="immeuble_code_postal"
                  data-places-city="immeuble_ville"
                  data-places-quartier="immeuble_quartier"
                  data-places-country="immeuble_pays"
                  data-places-lat="immeuble_latitude"
                  data-places-lng="immeuble_longitude"
                  data-places-place-id="immeuble_google_place_id"
                  data-places-formatted="immeuble_adresse_formatee"
                  data-places-immeuble-id="immeuble_id"
                  data-places-immeuble-badge="immeuble_badge"
                  data-places-country-code="fr"
                  autocomplete="off"
                >
              </div>
            </div>
            <!-- Badge immeuble -->
            <div id="immeuble_badge" class="places-immeuble-badge"></div>

            <div class="ba-grid">
              <div class="ba-field">
                <label>Adresse ligne 1</label>
                <input type="text" id="immeuble_adresse_1" name="adresse_1" value="<?= h((string)post('adresse_1', '')) ?>">
              </div>
              <div class="ba-field">
                <label>Adresse ligne 2</label>
                <input type="text" id="immeuble_adresse_2" name="adresse_2" value="<?= h((string)post('adresse_2', '')) ?>">
              </div>
              <div class="ba-field">
                <label>Code postal</label>
                <input type="text" id="immeuble_code_postal" name="code_postal" value="<?= h((string)post('code_postal', '')) ?>">
              </div>
              <div class="ba-field">
                <label>Ville</label>
                <input type="text" id="immeuble_ville" name="ville" value="<?= h((string)post('ville', '')) ?>">
              </div>
              <div class="ba-field">
                <label>Quartier <span style="font-size:10px;color:var(--muted);font-weight:400;">(auto Google)</span></label>
                <input type="text" id="immeuble_quartier" name="quartier" value="<?= h((string)post('quartier', '')) ?>" placeholder="Renseigné automatiquement">
              </div>
              <div class="ba-field">
                <label>Pays</label>
                <input type="text" id="immeuble_pays" name="pays" value="<?= h((string)post('pays', 'France')) ?>">
              </div>
            </div>

            <!-- ── EXPOSITION (rosace) ── -->
            <div class="ba-field ba-col-full" style="margin-top:18px;">
              <label style="margin-bottom:10px;display:block;">Exposition</label>
              <input type="hidden" name="exposition" id="exposition_val" value="<?= h((string)post('exposition','')) ?>">
              <div class="ba-compass">
                <div class="ba-compass-rose">
                  <button type="button" class="ba-compass-btn <?= post('exposition','') === 'nord' ? 'active' : '' ?>" data-dir="nord" onclick="setExposition('nord')">N</button>
                  <button type="button" class="ba-compass-btn <?= post('exposition','') === 'est' ? 'active' : '' ?>" data-dir="est" onclick="setExposition('est')">E</button>
                  <button type="button" class="ba-compass-btn <?= post('exposition','') === 'sud' ? 'active' : '' ?>" data-dir="sud" onclick="setExposition('sud')">S</button>
                  <button type="button" class="ba-compass-btn <?= post('exposition','') === 'ouest' ? 'active' : '' ?>" data-dir="ouest" onclick="setExposition('ouest')">O</button>
                  <div class="ba-compass-center">🧭</div>
                </div>
                <div class="ba-compass-extras">
                  <button type="button" class="ba-compass-chip <?= post('exposition','') === 'nord_sud' ? 'active' : '' ?>" onclick="setExposition('nord_sud')">N ↕ S</button>
                  <button type="button" class="ba-compass-chip <?= post('exposition','') === 'est_ouest' ? 'active' : '' ?>" onclick="setExposition('est_ouest')">E ↔ O</button>
                  <button type="button" class="ba-compass-chip <?= post('exposition','') === 'plein_sud' ? 'active' : '' ?>" onclick="setExposition('plein_sud')">☀ Plein Sud</button>
                </div>
              </div>
            </div>

          </div>
        </div>
        <input type="hidden" id="immeuble_latitude"        name="latitude"         value="<?= h((string)post('latitude', '')) ?>">
        <input type="hidden" id="immeuble_longitude"       name="longitude"        value="<?= h((string)post('longitude', '')) ?>">
        <input type="hidden" id="immeuble_google_place_id" name="google_place_id"  value="<?= h((string)post('google_place_id', '')) ?>">
        <input type="hidden" id="immeuble_adresse_formatee" name="adresse_formatee" value="<?= h((string)post('adresse_formatee', '')) ?>">
        <input type="hidden" id="immeuble_id"              name="immeuble_id"      value="<?= h((string)post('immeuble_id', '')) ?>">

        <?php
          // Helper inline pour les chips radio des situations (transports / commerces)
          $accesTransportsPost   = (string)post('acces_transports', '');
          $distanceCommercesPost = (string)post('distance_commerces', '');
          $tOpts = [
            'moins_5'  => '⚡ &lt; 5 min',
            'moins_10' => '🚶 &lt; 10 min',
            'moins_15' => '🚶 &lt; 15 min',
            'plus_20'  => '🚶 &gt; 20 min',
          ];
          $cOpts = [
            'moins_200'  => '🏃 &lt; 200 m',
            'moins_400'  => '🚶 &lt; 400 m',
            'moins_600'  => '🚶 &lt; 600 m',
            'moins_800'  => '🚶 &lt; 800 m',
            'plus_1200'  => '🚗 &gt; 1,2 km',
          ];
          $tChipsHtml = '<div class="ba-chips">';
          foreach ($tOpts as $val => $label) {
            $checked = $accesTransportsPost === $val ? 'checked' : '';
            $cls     = $accesTransportsPost === $val ? ' checked' : '';
            $tChipsHtml .= '<label class="ba-chip' . $cls . '"><input type="radio" name="acces_transports" value="' . $val . '" ' . $checked . '> ' . $label . '</label>';
          }
          $tChipsHtml .= '</div>';
          $cChipsHtml = '<div class="ba-chips">';
          foreach ($cOpts as $val => $label) {
            $checked = $distanceCommercesPost === $val ? 'checked' : '';
            $cls     = $distanceCommercesPost === $val ? ' checked' : '';
            $cChipsHtml .= '<label class="ba-chip' . $cls . '"><input type="radio" name="distance_commerces" value="' . $val . '" ' . $checked . '> ' . $label . '</label>';
          }
          $cChipsHtml .= '</div>';
        ?>
        <?= moreBtn('adresse') ?>
        <?= detailsCard('adresse', '🌍 Environnement & situation', '
        <div class="ba-grid cols-2">
          <div class="ba-field ba-col-full">
            <label style="margin-bottom:8px;display:block;">Vue</label>
            ' . (!$_vueFallback ? '
            <div class="mc-grid" id="mc-vues" data-mc-mode="select" data-mc-multiple="false" data-mc-field="vue_ids" style="--mc-min:90px;">
              ' . implode('', array_map(fn($sv) => '
              <div class="mc-card' . ((string)post('vue','') === $sv['code'] ? ' is-selected' : '') . '"
                   data-mc-value="' . (int)$sv['id'] . '"
                   data-mc-label="' . h($sv['label']) . '"
                   data-mc-desc="' . h($sv['description'] ?? '') . '"
                   data-vue-code="' . h($sv['code']) . '">
                <div class="mc-icon">' . (!empty($sv['icone']) ? '<i class="' . h($sv['icone']) . '"></i>' : '') . '</div>
                <div class="mc-label">' . h($sv['label']) . '</div>
              </div>', $socVues)) . '
            </div>' : '
            <input type="hidden" name="vue" id="vue_val" value="' . h((string)post('vue','')) . '">
            <div class="ba-vue-grid">
              ' . implode('', array_map(fn($v,$l,$i) => '
              <div class="ba-vue-card' . (post('vue','') === $v ? ' active' : '') . '" onclick="setVue(\'' . $v . '\')">
                <div class="ba-vue-icon">' . $i . '</div>
                <div class="ba-vue-label">' . $l . '</div>
              </div>',
                ['degagee','jardin','cour','mer','montagne','parc','urbaine'],
                ['Dégagée','Jardin','Cour','Mer','Montagne','Parc','Urbaine'],
                ['🌅','🌿','🏢','🌊','⛰️','🌳','🏙️']
              )) . '
            </div>') . '
          </div>

          <div class="ba-field ba-col-full">
            <label style="margin-bottom:8px;display:block;">🚇 Accès aux transports en commun <span style="font-size:11px;color:var(--muted);font-weight:400;">(temps à pied)</span></label>
            ' . $tChipsHtml . '
          </div>

          <div class="ba-field ba-col-full">
            <label style="margin-bottom:8px;display:block;">🛍️ Accès aux commerces <span style="font-size:11px;color:var(--muted);font-weight:400;">(distance au centre-ville)</span></label>
            ' . $cChipsHtml . '
          </div>

          <div class="ba-field ba-col-full">
            <label>Nuisances éventuelles</label>
            <input type="text" name="nuisances" value="' . h((string)post('nuisances','')) . '" placeholder="Ex: rue passante, voie ferrée…">
          </div>
          <div class="ba-field">
            <label>Adresse visible au public</label>
            <div class="ba-chips">
              <label class="ba-chip"><input type="radio" name="adresse_visible_public" value="1" ' . (post('adresse_visible_public','') === '1' ? 'checked' : '') . '> Oui</label>
              <label class="ba-chip"><input type="radio" name="adresse_visible_public" value="0" ' . (post('adresse_visible_public','') === '0' ? 'checked' : '') . '> Non</label>
            </div>
          </div>
        </div>') ?>

        <div class="ba-panel-footer">
          <button type="button" class="ba-btn-ghost" onclick="switchTab('identification')">← Précédent</button>
          <button type="button" class="ba-btn-primary" onclick="switchTab('caracteristiques')">Suivant →</button>
        </div>
      </div>

      <!-- ════ ONGLET 3 — CARACTÉRISTIQUES ════ -->
      <div class="ba-panel" data-tab-panel="caracteristiques">
        <div class="ba-card">
          <div class="ba-card-head">
            <div class="ba-card-title">Caractéristiques essentielles</div>
            <div class="ba-card-sub">Surface, pièces et étage.</div>
          </div>
          <div class="ba-card-body">
            <div class="ba-grid">
              <div class="ba-field">
                <label>Surface habitable (m²)</label>
                <input type="number" step="0.01" name="surface_habitable" value="<?= h((string)post('surface_habitable','')) ?>" placeholder="Ex: 65">
              </div>
              <div class="ba-field">
                <label>Nb pièces</label>
                <input type="number" name="nb_pieces" value="<?= h((string)post('nb_pieces','')) ?>" min="1">
              </div>
              <div class="ba-field">
                <label>Nb chambres</label>
                <input type="number" name="nb_chambres" value="<?= h((string)post('nb_chambres','')) ?>" min="0">
              </div>
              <div class="ba-field">
                <label>Nb salles de bain</label>
                <input type="number" name="nb_salles_bain" value="<?= h((string)post('nb_salles_bain','')) ?>" min="0">
              </div>
              <div class="ba-field">
                <label>Nb WC</label>
                <input type="number" name="nb_wc" value="<?= h((string)post('nb_wc','')) ?>" min="0">
              </div>
              <div class="ba-field">
                <label>Étage</label>
                <input type="number" name="etage" value="<?= h((string)post('etage','')) ?>" min="0">
              </div>
              <div class="ba-field">
                <label>Année de construction <span style="color:#c0392b;" title="Obligatoire pour la diffusion (Ubiflow)">*</span></label>
                <input type="number" name="annee_construction" value="<?= h((string)post('annee_construction','')) ?>" min="1800" max="2030" placeholder="Ex: 1978">
                <div class="ba-hint">Obligatoire pour la diffusion portails (le brouillon reste sauvegardable sans).</div>
              </div>
            </div>

            <!-- + Détails surfaces -->
            <?= moreBtn('surfaces') ?>
            <?= detailsCard('surfaces', '📐 Détail des surfaces (m²)', '
            <div class="ba-grid">
              <div class="ba-field"><label>Surface Carrez</label><input type="number" step="0.01" name="surface_carrez" value="' . h((string)post('surface_carrez','')) . '"></div>
              <div class="ba-field"><label>Surface séjour</label><input type="number" step="0.01" name="surface_sejour" value="' . h((string)post('surface_sejour','')) . '"></div>
              <div class="ba-field"><label>Surface totale</label><input type="number" step="0.01" name="surface_totale" value="' . h((string)post('surface_totale','')) . '"></div>
              <div class="ba-field"><label>Surface terrain</label><input type="number" step="0.01" name="surface_terrain" value="' . h((string)post('surface_terrain','')) . '"></div>
              <div class="ba-field"><label>Balcon (m²)</label><input type="number" step="0.01" name="surface_balcon" value="' . h((string)post('surface_balcon','')) . '"></div>
              <div class="ba-field"><label>Terrasse (m²)</label><input type="number" step="0.01" name="surface_terrasse" value="' . h((string)post('surface_terrasse','')) . '"></div>
              <div class="ba-field"><label>Jardin (m²)</label><input type="number" step="0.01" name="surface_jardin" value="' . h((string)post('surface_jardin','')) . '"></div>
              <div class="ba-field"><label>Cave (m²)</label><input type="number" step="0.01" name="surface_cave" value="' . h((string)post('surface_cave','')) . '"></div>
              <div class="ba-field"><label>Garage (m²)</label><input type="number" step="0.01" name="surface_garage" value="' . h((string)post('surface_garage','')) . '"></div>
              <div class="ba-field"><label>Box (m²)</label><input type="number" step="0.01" name="surface_box" value="' . h((string)post('surface_box','')) . '"></div>
              <div class="ba-field"><label>Véranda (m²)</label><input type="number" step="0.01" name="surface_veranda" value="' . h((string)post('surface_veranda','')) . '"></div>
              <div class="ba-field"><label>Annexe (m²)</label><input type="number" step="0.01" name="surface_annexe" value="' . h((string)post('surface_annexe','')) . '"></div>
              <div class="ba-field"><label>Hauteur plafond (m)</label><input type="number" step="0.01" name="hauteur_sous_plafond" value="' . h((string)post('hauteur_sous_plafond','')) . '" placeholder="Ex: 2.70"></div>
              <div class="ba-field"><label>Nb niveaux</label><input type="number" name="nb_niveaux" value="' . h((string)post('nb_niveaux','')) . '" min="1"></div>
            </div>') ?>

            <!-- + Détails pièces -->
            <?= moreBtn('pieces') ?>
            <?= detailsCard('pieces', '🚿 Pièces & équipements intérieurs', '
            <div class="ba-grid">
              <div class="ba-field"><label>Salles d\'eau</label><input type="number" name="nb_salles_eau" value="' . h((string)post('nb_salles_eau','')) . '" min="0"></div>
              <div class="ba-field"><label>N° de porte</label><input type="text" name="numero_porte" value="' . h((string)post('numero_porte','')) . '"></div>
              <div class="ba-field"><label>Type cuisine</label>
                <select name="cuisine_type">
                  <option value="">—</option>
                  <option value="ouverte"' . (post('cuisine_type','') === 'ouverte' ? ' selected' : '') . '>Ouverte</option>
                  <option value="semi_ouverte"' . (post('cuisine_type','') === 'semi_ouverte' ? ' selected' : '') . '>Semi-ouverte</option>
                  <option value="separee"' . (post('cuisine_type','') === 'separee' ? ' selected' : '') . '>Séparée</option>
                  <option value="americaine"' . (post('cuisine_type','') === 'americaine' ? ' selected' : '') . '>Américaine</option>
                </select>
              </div>
              <div class="ba-field"><label>Nb parkings</label><input type="number" name="parking_nb" value="' . h((string)post('parking_nb','')) . '" min="0"></div>
            </div>
            <div class="ba-bool-grid" style="margin-top:14px;">
              ' . boolChip('cuisine_equipee','🍳 Cuisine équipée') . '
              ' . boolChip('dernier_etage','🏠 Dernier étage') . '
              ' . boolChip('ascenseur','🛗 Ascenseur') . '
              ' . boolChip('interphone','📞 Interphone') . '
              ' . boolChip('digicode','🔢 Digicode') . '
              ' . boolChip('alarme','🚨 Alarme') . '
              ' . boolChip('climatisation','❄️ Climatisation') . '
              ' . boolChip('fibre','🌐 Fibre optique') . '
              ' . boolChip('double_vitrage','🪟 Double vitrage') . '
              ' . boolChip('volets_roulants','🪟 Volets roulants') . '
              ' . boolChip('cheminee','🔥 Cheminée') . '
            </div>') ?>

            <!-- + Détails extérieurs -->
            <?= moreBtn('exterieurs') ?>
            <?= detailsCard('exterieurs', '🌳 Extérieurs & dépendances', '
            <div class="ba-bool-grid">
              ' . boolChip('balcon','🌅 Balcon') . '
              ' . boolChip('terrasse','☀️ Terrasse') . '
              ' . boolChip('jardin','🌿 Jardin') . '
              ' . boolChip('cour','🏰 Cour') . '
              ' . boolChip('cave','🗄 Cave') . '
              ' . boolChip('grenier','🏚 Grenier') . '
              ' . boolChip('garage','🚗 Garage') . '
              ' . boolChip('box','📦 Box') . '
              ' . boolChip('piscine','🏊 Piscine') . '
              ' . boolChip('dependances','🏠 Dépendances') . '
              ' . boolChip('acces_camion','🚛 Accès camion') . '
              ' . boolChip('vitrine','🏪 Vitrine') . '
            </div>') ?>

            <!-- + Détails chauffage -->
            <?= moreBtn('chauffage') ?>
            <?= detailsCard('chauffage', '🔥 Chauffage & énergie', '
            <div class="ba-field ba-col-full" style="margin-bottom:18px;">
              <label style="display:block;margin-bottom:10px;">Type de chauffage</label>
              ' . (!$_chauffFallback ? '
              <div class="mc-grid" id="mc-chauffage" data-mc-mode="select" data-mc-multiple="true" style="--mc-min:110px;">
                ' . implode('', array_map(fn($c) => '
                <div class="mc-card" data-mc-value="' . (int)$c['id'] . '" data-mc-label="' . h($c['label']) . '" data-mc-desc="' . h($c['description'] ?? '') . '" data-ch-code="' . h($c['code']) . '">
                  <div class="mc-icon"><i class="' . h($c['icone'] ?? 'fa-solid fa-fire') . '"></i></div>
                  <div class="mc-label">' . h($c['label']) . '</div>
                </div>', $socChauffages)) . '
              </div>' : '
              <select name="chauffage_type">
                <option value="">—</option>
                <option value="individuel"' . (post('chauffage_type','') === 'individuel' ? ' selected' : '') . '>Individuel</option>
                <option value="collectif"' . (post('chauffage_type','') === 'collectif' ? ' selected' : '') . '>Collectif</option>
                <option value="electrique"' . (post('chauffage_type','') === 'electrique' ? ' selected' : '') . '>Électrique</option>
                <option value="pompe_chaleur"' . (post('chauffage_type','') === 'pompe_chaleur' ? ' selected' : '') . '>Pompe à chaleur</option>
              </select>') . '
            </div>

            <div class="ba-field ba-col-full" style="margin-bottom:18px;">
              <label style="display:block;margin-bottom:10px;">Énergie <span id="energie-hint" style="font-size:10px;font-weight:400;color:var(--muted);"></span></label>
              ' . (!$_energFallback ? '
              <div class="mc-grid" id="mc-energie" data-mc-mode="select" data-mc-multiple="true"
                   data-lien=\'' . json_encode($socChauffEnLiens, JSON_HEX_APOS) . '\'>
                ' . implode('', array_map(fn($e) => '
                <div class="mc-card" data-mc-value="' . (int)$e['id'] . '" data-mc-label="' . h($e['label']) . '" data-mc-desc="' . h($e['description'] ?? '') . '">
                  <div class="mc-icon"><i class="' . h($e['icone'] ?? 'fa-solid fa-bolt') . '"></i></div>
                  <div class="mc-label">' . h($e['label']) . '</div>
                </div>', $socEnergies)) . '
              </div>' : '
              <select name="chauffage_energie">
                <option value="">—</option>
                <option value="gaz"' . (post('chauffage_energie','') === 'gaz' ? ' selected' : '') . '>Gaz</option>
                <option value="fioul"' . (post('chauffage_energie','') === 'fioul' ? ' selected' : '') . '>Fioul</option>
                <option value="electricite"' . (post('chauffage_energie','') === 'electricite' ? ' selected' : '') . '>Électricité</option>
                <option value="bois"' . (post('chauffage_energie','') === 'bois' ? ' selected' : '') . '>Bois</option>
                <option value="solaire"' . (post('chauffage_energie','') === 'solaire' ? ' selected' : '') . '>Solaire</option>
              </select>') . '
            </div>

            <div class="ba-grid" style="margin-top:0;">
              <div class="ba-field"><label>Eau chaude</label>
                <select name="eau_chaude_type">
                  <option value="">—</option>
                  <option value="individuelle"' . (post('eau_chaude_type','') === 'individuelle' ? ' selected' : '') . '>Individuelle</option>
                  <option value="collective"' . (post('eau_chaude_type','') === 'collective' ? ' selected' : '') . '>Collective</option>
                  <option value="chauffe_eau"' . (post('eau_chaude_type','') === 'chauffe_eau' ? ' selected' : '') . '>Chauffe-eau élec.</option>
                  <option value="solaire"' . (post('eau_chaude_type','') === 'solaire' ? ' selected' : '') . '>Solaire</option>
                </select>
              </div>
              <div class="ba-field"><label>Menuiseries</label>
                <select name="menuiseries">
                  <option value="">—</option>
                  <option value="pvc"' . (post('menuiseries','') === 'pvc' ? ' selected' : '') . '>PVC</option>
                  <option value="bois"' . (post('menuiseries','') === 'bois' ? ' selected' : '') . '>Bois</option>
                  <option value="aluminium"' . (post('menuiseries','') === 'aluminium' ? ' selected' : '') . '>Aluminium</option>
                  <option value="mixte"' . (post('menuiseries','') === 'mixte' ? ' selected' : '') . '>Mixte</option>
                </select>
              </div>
              <div class="ba-field"><label>Isolation</label>
                <select name="isolation">
                  <option value="">—</option>
                  <option value="thermique"' . (post('isolation','') === 'thermique' ? ' selected' : '') . '>Thermique</option>
                  <option value="thermique_phonique"' . (post('isolation','') === 'thermique_phonique' ? ' selected' : '') . '>Thermique + phonique</option>
                  <option value="faible"' . (post('isolation','') === 'faible' ? ' selected' : '') . '>Faible</option>
                </select>
              </div>
            </div>
            <div class="ba-bool-grid" style="margin-top:14px;">
              ' . boolChip('chauffage_vmc','💨 VMC') . '
              ' . boolChip('chauffage_vmc_df','💨 VMC double flux') . '
              ' . boolChip('chauffage_plancher','🌡 Plancher chauffant') . '
              ' . boolChip('chauffage_regulateur','🎛 Régulateur') . '
              ' . boolChip('chauffage_thermostat','🌡 Thermostat programmable') . '
              ' . boolChip('eau_chaude_solaire','☀️ Chauffe-eau solaire') . '
            </div>') ?>

          </div>
        </div>
        <div class="ba-panel-footer">
          <button type="button" class="ba-btn-ghost" onclick="switchTab('adresse')">← Précédent</button>
          <button type="button" class="ba-btn-primary" onclick="switchTab('annonce')">Suivant →</button>
        </div>
      </div>

      <!-- ════ ONGLET 4 — ANNONCE ════ -->
      <div class="ba-panel" data-tab-panel="annonce">
        <div class="ba-card">
          <div class="ba-card-head" style="display:flex;align-items:flex-start;justify-content:space-between;gap:14px;">
            <div>
              <div class="ba-card-title">Annonce</div>
              <div class="ba-card-sub">Commercial, type de transaction et prix demandé.</div>
            </div>
            <button type="button" class="ba-mentions-help" onclick="document.getElementById('mentions-legales-modal').classList.toggle('open');"
                    title="Afficher les mentions légales obligatoires"
                    style="flex-shrink:0;display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:999px;background:#fff7e6;color:#b67c00;border:1px solid #f59e0b;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;">
              ⚖️ Mentions légales obligatoires
            </button>
          </div>

          <!-- ── Modal mentions légales ── -->
          <div id="mentions-legales-modal" class="ml-modal" style="display:none;">
            <div class="ml-modal-box">
              <div class="ml-modal-head">
                <div class="ml-modal-title">⚖️ Obligations légales d'une annonce immobilière</div>
                <button type="button" class="ml-modal-close" onclick="document.getElementById('mentions-legales-modal').classList.remove('open');">✕</button>
              </div>
              <div class="ml-modal-body">
                <p style="margin-bottom:14px;color:#5a5a5a;font-size:13px;">Toute annonce diffusée doit obligatoirement comporter les mentions ci-dessous (peines d'amendes 3 000 € à 15 000 € en cas d'omission).</p>
                <ul class="ml-list">
                  <li><strong>📊 DPE — Classe énergétique et GES</strong><br><span>Étiquette A→G en couleur, lisible (au moins 180×180 px). Obligatoire pour tous les biens soumis au DPE.</span></li>
                  <li><strong>💰 Montant des dépenses énergétiques estimées</strong><br><span>Fourchette annuelle (€/an) avec date de référence des prix énergies. Obligatoire depuis le 01/01/2022.</span></li>
                  <li><strong>🌍 Mention Géorisques</strong><br><span>« Les informations sur les risques auxquels ce bien est exposé sont disponibles sur le site Géorisques : www.georisques.gouv.fr ». Obligatoire depuis 01/01/2023.</span></li>
                  <li><strong>🌳 Obligation de débroussaillement</strong><br><span>Si le bien est en zone soumise. Obligatoire depuis 01/01/2025.</span></li>
                  <li><strong>💼 Honoraires d'agence (vente)</strong><br><span>Prix FAI (frais d'agence inclus) ET prix hors honoraires. Si à charge acquéreur : indiquer le % TTC sur le prix HH. Mention de la répartition (acquéreur / vendeur).</span></li>
                  <li><strong>📋 Barème des prix du professionnel</strong><br><span>Lien vers la grille tarifaire publique du professionnel (URL accessible en 2 clics depuis le site).</span></li>
                  <li><strong>🏢 Copropriété</strong><br><span>Si applicable : statut copropriété, nombre de lots, montant moyen annuel des charges, mention si syndicat en procédure (mandataire ad hoc, plan de sauvegarde…).</span></li>
                  <li><strong>🏙 Encadrement des loyers (location, Paris/Lille)</strong><br><span>Loyer de base, loyer de référence majoré, complément de loyer (avec justification), commune et n° d'arrondissement, surface habitable.</span></li>
                  <li><strong>💸 Détails location</strong><br><span>Loyer mensuel charges comprises (CC), modalités de récupération des charges (forfait/provision/justificatifs), dépôt de garantie, honoraires intermédiaire, honoraires état des lieux.</span></li>
                  <li><strong>🪪 Identification du professionnel</strong><br><span>N° de carte professionnelle, RCS, garant financier, n° SIRET, mention « Loi Hoguet ».</span></li>
                </ul>
              </div>
            </div>
          </div>
          <style>
            .ml-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 9999; align-items: flex-start; justify-content: center; padding: 40px 20px; overflow-y: auto; }
            .ml-modal.open { display: flex; }
            .ml-modal-box { background: #fff; max-width: 720px; width: 100%; border-radius: 16px; box-shadow: 0 20px 60px #f7f8fa; overflow: hidden; }
            .ml-modal-head { display: flex; justify-content: space-between; align-items: center; padding: 18px 22px; background: #f59e0b; color: #fff; }
            .ml-modal-title { font-size: 16px; font-weight: 700; }
            .ml-modal-close { background: none; border: none; color: #fff; font-size: 22px; cursor: pointer; }
            .ml-modal-body { padding: 22px; max-height: 70vh; overflow-y: auto; }
            .ml-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 14px; }
            .ml-list li { padding: 12px 14px; background: #fff7e6; border-left: 3px solid #f59e0b; border-radius: 8px; font-size: 13px; }
            .ml-list li strong { color: #b67c00; display: block; margin-bottom: 4px; }
            .ml-list li span { color: #5a5a5a; font-size: 12px; }
          </style>
          <div class="ba-card-body">
            <div class="ba-grid cols-2">
              <div class="ba-field ba-col-full">
                <label>Commercial responsable</label>
                <select name="annonce_commercial_id">
                  <option value="">— Sélectionner —</option>
                  <?php foreach ($commercials as $c):
                    $lbl = trim(($c['prenom'] ?? '') . ' ' . ($c['nom'] ?? ''));
                    $lbl = $lbl !== '' ? $lbl : 'Utilisateur #' . (int)$c['id'];
                    $sel = ((string)post('annonce_commercial_id','') === (string)$c['id']) ? 'selected' : '';
                  ?>
                  <option value="<?= (int)$c['id'] ?>" <?= $sel ?>><?= h($lbl) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="ba-field ba-col-full">
                <label>Type de transaction</label>
                <div class="ba-chips">
                  <label class="ba-chip"><input type="radio" name="annonce_transaction" value="location" <?= $annonceTransactionPost === 'location' ? 'checked' : '' ?>> 🔑 Location</label>
                  <label class="ba-chip"><input type="radio" name="annonce_transaction" value="vente"    <?= $annonceTransactionPost === 'vente'    ? 'checked' : '' ?>> 🤝 Vente</label>
                </div>
              </div>
              <div class="ba-field" id="annonce-price-location">
                <label>Loyer demandé (€ / mois)</label>
                <input type="number" name="annonce_loyer" value="<?= h((string)post('annonce_loyer','')) ?>">
              </div>
              <div class="ba-field" id="annonce-price-vente">
                <label>Prix de vente (€)</label>
                <input type="number" name="annonce_prix_vente" value="<?= h((string)post('annonce_prix_vente','')) ?>">
              </div>
            </div>
          </div>
        </div>
        <?= moreBtn('annonce-details') ?>
        <?= detailsCard('annonce-details', '📢 Détails annonce & accroche', '
          <div class="ba-grid cols-2">
            <div class="ba-field ba-col-full">
              <label>Accroche commerciale</label>
              <input type="text" name="accroche_commerciale" value="' . h((string)post('accroche_commerciale','')) . '" placeholder="Ex : Exclusivité – Magnifique T3 lumineux…">
              <div class="ba-hint">Phrase d\'accroche affich&eacute;e en t&ecirc;te d\'annonce.</div>
            </div>
            <div class="ba-field ba-col-full">
              <label>Points forts</label>
              <input type="text" name="points_forts" value="' . h((string)post('points_forts','')) . '" placeholder="Ex : Vue mer, terrasse, garage double">
              <div class="ba-hint">Séparés par des virgules, mis en avant visuellement.</div>
            </div>
            <div class="ba-field">
              <label>Référence externe (portail)</label>
              <input type="text" name="reference_externe" value="' . h((string)post('reference_externe','')) . '">
            </div>
            <div class="ba-field">
              <label>Mandat n°</label>
              <input type="text" name="lot_principal" value="' . h((string)post('lot_principal','')) . '" placeholder="N° de mandat">
            </div>
          </div>
        ') ?>
        <!-- ── Sélection des photos pour l'annonce ── -->
        <div class="ba-card" style="margin-top:14px;">
          <div class="ba-card-head">
            <div class="ba-card-title">📸 Photos sélectionnées pour l'annonce</div>
            <div class="ba-card-sub">Cliquez jusqu'à <strong>7 photos</strong> dans l'ordre d'affichage souhaité. La 1ère sélectionnée sera la photo principale (badge vert).</div>
          </div>
          <div class="ba-card-body">
            <input type="hidden" name="photos_annonce_selection" id="photos-annonce-selection" value="">
            <div class="ba-photo-selector" id="ba-photo-selector">
              <div class="ba-photo-selector-empty" id="ba-photo-selector-empty">
                💡 Ajoutez d'abord des photos dans l'onglet <strong>📸 Photos</strong>, puis revenez ici pour les sélectionner.
              </div>
              <div class="ba-photo-selector-grid" id="ba-photo-selector-grid" style="display:none;"></div>
              <div class="ba-photo-selector-info" id="ba-photo-selector-info" style="display:none;">
                <span><strong id="ba-photo-selector-count">0</strong> photo(s) sélectionnée(s) sur 7 max</span>
                <span style="font-size:11px;">Cliquez à nouveau pour désélectionner</span>
              </div>
            </div>
          </div>
        </div>

        <div class="ba-panel-footer">
          <button type="button" class="ba-btn-ghost" onclick="switchTab('caracteristiques')">← Précédent</button>
          <button type="button" class="ba-btn-primary" onclick="switchTab('photos')">Suivant →</button>
        </div>
      </div>

      <!-- ════ ONGLET PHOTOS ════ -->
      <div class="ba-panel" data-tab-panel="photos">
        <div class="ba-card">
          <div class="ba-card-head">
            <div class="ba-card-title">Photos du bien</div>
            <div class="ba-card-sub">Drag &amp; drop ou clic — JPG / PNG / WEBP — max 10 Mo par photo. La 1ère sera la photo principale.</div>
          </div>
          <div class="ba-card-body">

            <div class="ba-photo-drop" id="ba-photo-drop">
              <div class="ba-photo-drop-icon">📸</div>
              <div class="ba-photo-drop-title">Glissez vos photos ici ou cliquez pour parcourir</div>
              <div class="ba-photo-drop-sub">JPG · PNG · WEBP — max 10 Mo par photo — multi-fichiers OK</div>
              <input type="file" id="ba-photo-input" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple style="display:none;">
            </div>

            <div class="ba-photo-grid"
                 id="ba-photo-grid"
                 data-existing="<?= htmlspecialchars(json_encode($existingBienPhotos ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"></div>

            <?php if ($isEditing && !empty($existingBienPhotos)): ?>
              <?php
                $nbNonAnalysees = count(array_filter($existingBienPhotos, fn($p) => empty($p['description_ia'])));
              ?>
              <div class="ba-photo-analyze-bar" style="margin-top:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:12px 16px;background:linear-gradient(180deg,rgba(106,76,168,0.06),var(--card));border:1px solid rgba(106,76,168,0.18);border-radius:var(--r-md);">
                <div style="flex:1;min-width:200px;font-size:12px;color:var(--muted);">
                  <strong style="color:#6a4ca8;">🤖 Analyse Vision automatique</strong><br>
                  <?php if ($nbNonAnalysees > 0): ?>
                    <?= $nbNonAnalysees ?> photo(s) non encore analysée(s). L'analyse identifie la pièce et décrit ce qui est visible.
                  <?php else: ?>
                    Toutes les photos ont été analysées. Vous pouvez relancer si besoin.
                  <?php endif; ?>
                </div>
                <button type="button" id="ba-photo-analyze-batch" class="ba-ia-btn" style="font-size:12px;padding:8px 14px;">
                  🔎 Analyser les photos non analysées
                </button>
                <button type="button" id="ba-photo-analyze-force" class="ba-btn-ghost" style="font-size:11px;padding:7px 12px;" title="Relance l'analyse même sur les photos déjà analysées">
                  ↻ Tout réanalyser
                </button>
              </div>
            <?php endif; ?>

            <div class="ba-photo-info">
              💡 <strong>Bibliothèque du bien :</strong> toutes les photos uploadées ici sont enregistrées dans la <strong>bibliothèque permanente</strong> du bien — réutilisables pour plusieurs annonces successives.
              <br><br>
              📡 <strong>Pour publier une annonce :</strong> rendez-vous dans l'onglet <strong>📡 Annonce</strong> et sélectionnez jusqu'à <strong>7 photos</strong> dans l'ordre souhaité. Les photos sélectionnées seront automatiquement <strong>copiées</strong>, converties en <strong>WebP</strong> (3 tailles) et nommées avec un <strong>nom SEO</strong> pour les portails.
              <br><br>
              ✅ <strong>Recommandé :</strong> au moins <strong>5-7 photos</strong> par bien pour une bonne mise en valeur.
            </div>

          </div>
        </div>
        <div class="ba-panel-footer">
          <button type="button" class="ba-btn-ghost" onclick="switchTab('annonce')">← Précédent</button>
          <button type="button" class="ba-btn-primary" onclick="switchTab('prix')">Suivant →</button>
        </div>
      </div>

      <!-- ════ ONGLET 5 — PRIX ════ -->
      <div class="ba-panel" data-tab-panel="prix">
        <div class="ba-card" id="ba-card-location">
          <div class="ba-card-head">
            <div class="ba-card-title">Détails financiers — Location</div>
            <div class="ba-card-sub">Le loyer du bien est la <strong>source de vérité</strong>. L'annonce en hérite automatiquement.</div>
          </div>
          <div class="ba-card-body">
            <div class="ba-grid cols-2">
              <div class="ba-field">
                <label>Loyer HC (€ / mois) <span style="color:#c05000;">*</span></label>
                <input type="number" name="loyer_hc" value="<?= h((string)post('loyer_hc','')) ?>" placeholder="Loyer hors charges pratiqué">
                <div class="ba-hint">Loyer effectivement demandé — repris dans l'annonce par défaut.</div>
              </div>
              <div class="ba-field">
                <label>Charges (€ / mois)</label>
                <input type="number" name="charges" value="<?= h((string)post('charges','')) ?>">
              </div>
              <div class="ba-field" style="border-left:3px solid #6a4ca8;padding-left:12px;">
                <label style="color:#6a4ca8;font-weight:700;">🎯 Estimation agence — Location (€ / mois)</label>
                <input type="number" step="0.01" name="estimation_agence_location" value="<?= h((string)post('estimation_agence_location','')) ?>" placeholder="Ce que l'agence aurait préconisé">
                <div class="ba-hint">Usage interne : valeur conseillée par l'agence, peut différer du loyer pratiqué à la demande du client.</div>
              </div>
              <div class="ba-field">
                <label>Dépôt de garantie (€)</label>
                <input type="number" name="depot_garantie" value="<?= h((string)post('depot_garantie','')) ?>">
              </div>
              <div class="ba-field">
                <label>Honoraires locataire (€)</label>
                <input type="number" name="honoraires_locataire" value="<?= h((string)post('honoraires_locataire','')) ?>">
              </div>
            </div>
          </div>
        </div>

        <!-- ── Locataire précédent (obligation Alur en zone encadrement) ── -->
        <div class="ba-card" id="ba-card-ancien-loyer">
          <div class="ba-card-head">
            <div class="ba-card-title">🕒 Locataire précédent</div>
            <div class="ba-card-sub">Obligation Loi Alur en zone d'encadrement des loyers : communiquer le dernier loyer pratiqué.</div>
          </div>
          <div class="ba-card-body">
            <div class="ba-bool-grid" style="margin-bottom:14px;">
              <?= boolChip('ancien_loyer_communique','Le bailleur souhaite communiquer ces informations') ?>
            </div>
            <div class="ba-grid cols-2">
              <div class="ba-field">
                <label>Dernier loyer HC (€/mois)</label>
                <input type="number" step="0.01" name="ancien_loyer_montant" value="<?= h((string)post('ancien_loyer_montant','')) ?>">
              </div>
              <div class="ba-field">
                <label>Charges du précédent locataire (€/mois)</label>
                <input type="number" step="0.01" name="ancien_loyer_charges" value="<?= h((string)post('ancien_loyer_charges','')) ?>">
              </div>
              <div class="ba-field">
                <label>Date de dernière révision</label>
                <input type="date" name="ancien_loyer_date_revision" value="<?= h((string)post('ancien_loyer_date_revision','')) ?>">
              </div>
              <div class="ba-field">
                <label>Date de sortie du locataire</label>
                <input type="date" name="ancien_locataire_date_sortie" value="<?= h((string)post('ancien_locataire_date_sortie','')) ?>">
              </div>
            </div>
          </div>
        </div>

        <!-- ── Complément de loyer + justifications dynamiques ── -->
        <div class="ba-card" id="ba-card-complement-loyer">
          <div class="ba-card-head">
            <div class="ba-card-title">➕ Complément de loyer (zone encadrement)</div>
            <div class="ba-card-sub">Détaillez les caractéristiques exceptionnelles justifiant le dépassement du loyer de référence majoré.</div>
          </div>
          <div class="ba-card-body">
            <div class="ba-grid cols-2">
              <div class="ba-field">
                <label>Complément total (€/mois)</label>
                <input type="number" step="0.01" name="complement_loyer" id="cpl-loyer-total" value="<?= h((string)post('complement_loyer','')) ?>">
                <div class="ba-hint">Calculé automatiquement à partir des lignes ci-dessous (modifiable).</div>
              </div>
            </div>
            <div id="cpl-loyer-lignes" style="margin-top:14px;">
              <div class="cpl-row" style="display:grid;grid-template-columns:1fr 140px 40px;gap:8px;align-items:center;margin-bottom:8px;">
                <input type="text" name="cpl_libelle[]" placeholder="Ex : vue dégagée sur jardin" style="padding:8px;border:1px solid var(--stroke);border-radius:8px;">
                <input type="number" step="0.01" name="cpl_montant[]" placeholder="€/mois" class="cpl-mt" style="padding:8px;border:1px solid var(--stroke);border-radius:8px;">
                <button type="button" class="cpl-del" style="background:none;border:none;color:#c0392b;font-size:18px;cursor:pointer;">✕</button>
              </div>
            </div>
            <button type="button" id="cpl-add-row" style="margin-top:8px;padding:8px 14px;border:1px dashed var(--stroke);border-radius:8px;background:none;color:var(--accent);cursor:pointer;font-family:inherit;">＋ Ajouter une justification</button>
          </div>
        </div>
        <script>
        (function(){
          const wrap = document.getElementById('cpl-loyer-lignes');
          const total = document.getElementById('cpl-loyer-total');
          const addBtn = document.getElementById('cpl-add-row');
          if (!wrap || !addBtn) return;
          function recalc(){
            let s = 0;
            wrap.querySelectorAll('.cpl-mt').forEach(i => { s += parseFloat(i.value || '0') || 0; });
            if (total && (!total.dataset.touched || total.value === '')) total.value = s.toFixed(2);
          }
          function bindRow(row){
            row.querySelector('.cpl-del')?.addEventListener('click', () => { row.remove(); recalc(); });
            row.querySelector('.cpl-mt')?.addEventListener('input', recalc);
          }
          wrap.querySelectorAll('.cpl-row').forEach(bindRow);
          addBtn.addEventListener('click', () => {
            const row = document.createElement('div');
            row.className = 'cpl-row';
            row.style.cssText = 'display:grid;grid-template-columns:1fr 140px 40px;gap:8px;align-items:center;margin-bottom:8px;';
            row.innerHTML = `
              <input type="text" name="cpl_libelle[]" placeholder="Justification" style="padding:8px;border:1px solid var(--stroke);border-radius:8px;">
              <input type="number" step="0.01" name="cpl_montant[]" placeholder="€/mois" class="cpl-mt" style="padding:8px;border:1px solid var(--stroke);border-radius:8px;">
              <button type="button" class="cpl-del" style="background:none;border:none;color:#c0392b;font-size:18px;cursor:pointer;">✕</button>`;
            wrap.appendChild(row);
            bindRow(row);
          });
          if (total) total.addEventListener('input', () => { total.dataset.touched = '1'; });
        })();
        </script>
        <div class="ba-card" id="ba-card-vente">
          <div class="ba-card-head">
            <div class="ba-card-title">Détails financiers — Vente</div>
            <div class="ba-card-sub">Le prix du bien est la <strong>source de vérité</strong>. L'annonce en hérite automatiquement.</div>
          </div>
          <div class="ba-card-body">
            <div class="ba-grid cols-2">
              <div class="ba-field">
                <label>Prix de vente (€) <span style="color:#c05000;">*</span></label>
                <input type="number" name="prix_vente_estime" value="<?= h((string)post('prix_vente_estime','')) ?>" placeholder="Prix pratiqué">
                <div class="ba-hint">Prix effectivement demandé — repris dans l'annonce par défaut.</div>
              </div>
              <div class="ba-field" style="border-left:3px solid #6a4ca8;padding-left:12px;">
                <label style="color:#6a4ca8;font-weight:700;">🎯 Estimation agence — Vente (€)</label>
                <input type="number" step="0.01" name="estimation_agence_vente" value="<?= h((string)post('estimation_agence_vente','')) ?>" placeholder="Ce que l'agence aurait préconisé">
                <div class="ba-hint">Usage interne : valeur conseillée par l'agence, peut différer du prix pratiqué à la demande du client.</div>
              </div>
              <div class="ba-field">
                <label>Honoraires inclus</label>
                <select name="honoraires_inclus">
                  <option value="oui" <?= post('honoraires_inclus','oui') === 'oui' ? 'selected' : '' ?>>Oui (FAI)</option>
                  <option value="non" <?= post('honoraires_inclus','') === 'non' ? 'selected' : '' ?>>Non (HD)</option>
                </select>
              </div>
              <div class="ba-field">
                <label>Détail des honoraires</label>
                <input type="text" name="honoraires_detail" value="<?= h((string)post('honoraires_detail','')) ?>" placeholder="Ex: 3% à charge acheteur">
              </div>
            </div>
            <div class="ba-grid cols-2" style="margin-top:12px;padding-top:12px;border-top:1px dashed var(--stroke);">
              <div class="ba-field">
                <label style="color:#6a4ca8;">📅 Date estimation agence</label>
                <input type="date" name="estimation_agence_date" value="<?= h((string)post('estimation_agence_date','')) ?>">
              </div>
              <div class="ba-field">
                <label style="color:#6a4ca8;">📝 Notes estimation (interne)</label>
                <input type="text" name="estimation_agence_notes" value="<?= h((string)post('estimation_agence_notes','')) ?>" placeholder="Ex: comparaison biens du quartier, marge négociation…">
              </div>
            </div>
          </div>
        </div>
        <?= moreBtn('prix-details') ?>
        <?= detailsCard('prix-details', '🏦 Encadrement des loyers & fiscalité', '
          <div class="ba-grid cols-2">
            <div class="ba-field ba-col-full">
              <label>Zone d\'encadrement</label>
              <input type="text" name="enc_zone" value="' . h((string)post('enc_zone','')) . '" placeholder="Ex : Paris 11e">
            </div>
            <div class="ba-field">
              <label>Loyer de référence (€/m²)</label>
              <input type="number" step="0.01" name="enc_loyer_ref" value="' . h((string)post('enc_loyer_ref','')) . '">
            </div>
            <div class="ba-field">
              <label>Loyer de référence majoré (€/m²)</label>
              <input type="number" step="0.01" name="enc_loyer_max" value="' . h((string)post('enc_loyer_max','')) . '">
            </div>
            <div class="ba-field">
              <label>Loyer de référence minoré (€/m²)</label>
              <input type="number" step="0.01" name="enc_loyer_min" value="' . h((string)post('enc_loyer_min','')) . '">
            </div>
            <div class="ba-field">
              <label>Complément de loyer (€/mois)</label>
              <input type="number" step="0.01" name="enc_complement" value="' . h((string)post('enc_complement','')) . '">
            </div>
            <div class="ba-field">
              <label>Taxe foncière (€/an)</label>
              <input type="number" name="taxe_fonciere" value="' . h((string)post('taxe_fonciere','')) . '">
            </div>
            <div class="ba-field">
              <label>Taxe d\'habitation (&euro;/an)</label>
              <input type="number" name="taxe_habitation" value="' . h((string)post('taxe_habitation','')) . '">
            </div>
            <div class="ba-field">
              <label>Rentabilité brute estimée (%)</label>
              <input type="number" step="0.01" name="rentabilite_brute_estimee" value="' . h((string)post('rentabilite_brute_estimee','')) . '">
            </div>
            <div class="ba-field">
              <label>Montant travaux estimé (€)</label>
              <input type="number" name="montant_travaux_estime" value="' . h((string)post('montant_travaux_estime','')) . '">
            </div>
          </div>
          <div class="ba-bool-grid" style="margin-top:14px">
            ' . boolChip('loyer_meuble','Loyer meublé') . '
            ' . boolChip('animaux_acceptes','Animaux acceptés') . '
            ' . boolChip('travaux_a_prevoir','Travaux à prévoir') . '
          </div>
        ') ?>
        <?= moreBtn('copro-details') ?>
        <?= detailsCard('copro-details', '🏢 Copropriété', '
          <div class="ba-bool-grid" style="margin-bottom:14px">
            ' . boolChip('bien_en_copropriete','En copropriété') . '
            ' . boolChip('copro_procedure','Procédure en cours') . '
          </div>
          <div class="ba-grid cols-2">
            <div class="ba-field">
              <label>Nombre de lots</label>
              <input type="number" name="copro_nb_lots" value="' . h((string)post('copro_nb_lots','')) . '">
            </div>
            <div class="ba-field">
              <label>Quote-part charges (€/an)</label>
              <input type="number" step="0.01" name="copro_quote_part_charges" value="' . h((string)post('copro_quote_part_charges','')) . '">
            </div>
            <div class="ba-field">
              <label>Type de syndic</label>
              <select name="syndic_type">
                <option value="">—</option>
                <option value="professionnel" ' . (post('syndic_type','') === 'professionnel' ? 'selected' : '') . '>Professionnel</option>
                <option value="benevole" ' . (post('syndic_type','') === 'benevole' ? 'selected' : '') . '>Bénévole</option>
                <option value="cooperatif" ' . (post('syndic_type','') === 'cooperatif' ? 'selected' : '') . '>Coopératif</option>
              </select>
            </div>
            <div class="ba-field ba-col-full">
              <label>Nature des travaux (copro)</label>
              <input type="text" name="copro_travaux_nature" value="' . h((string)post('copro_travaux_nature','')) . '" placeholder="Ravalement façade 2025…">
            </div>
          </div>
        ') ?>
        <div class="ba-panel-footer">
          <button type="button" class="ba-btn-ghost" onclick="switchTab('annonce')">← Précédent</button>
          <button type="button" class="ba-btn-primary" onclick="switchTab('dpe')">Suivant →</button>
        </div>
      </div>

      <!-- ════ ONGLET 6 — DPE ════ -->
      <div class="ba-panel" data-tab-panel="dpe">

        <!-- ── Import dossier de diagnostics intelligent ── -->
        <div class="bi-trigger-wrap" style="margin-bottom:20px;">
          <button type="button" id="dpe-import-trigger" class="bi-trigger-btn">
            <span class="bi-trigger-icon">⚡</span>
            Importer un dossier de diagnostics
          </button>
          <span class="bi-trigger-hint">PDF — max 20 Mo — DPE, Loi Boutin, plomb, amiante, électricité, ERP — extraction IA</span>
          <input type="file" id="dpe-import-file" accept=".pdf,application/pdf" style="display:none">
        </div>

        <!-- Zone de feedback de l'import -->
        <div id="dpe-import-status" style="display:none;margin-bottom:18px;padding:14px 18px;border-radius:10px;font-size:13px;"></div>

        <div class="ba-card">
          <div class="ba-card-head">
            <div class="ba-card-title">DPE & Documents</div>
            <div class="ba-card-sub">Classes énergie/GES, valeurs, métadonnées et documents officiels.</div>
          </div>
          <div class="ba-card-body">
            <div class="ba-grid cols-1" style="gap:20px;">
              <div class="ba-field">
                <label>Classe énergie (DPE)</label>
                <div class="ba-dpe-row" id="dpe-row">
                  <?php foreach (['A','B','C','D','E','F','G'] as $l): ?>
                  <button type="button" class="ba-dpe-btn <?= post('dpe_classe','') === $l ? 'selected' : '' ?>" data-dpe="<?= $l ?>" data-field="dpe_classe"><?= $l ?></button>
                  <?php endforeach; ?>
                  <input type="hidden" name="dpe_classe" id="dpe_classe" value="<?= h((string)post('dpe_classe','')) ?>">
                </div>
              </div>
              <div class="ba-field">
                <label>Classe GES</label>
                <div class="ba-dpe-row" id="ges-row">
                  <?php foreach (['A','B','C','D','E','F','G'] as $l): ?>
                  <button type="button" class="ba-dpe-btn <?= post('ges_classe','') === $l ? 'selected' : '' ?>" data-dpe="<?= $l ?>" data-field="ges_classe"><?= $l ?></button>
                  <?php endforeach; ?>
                  <input type="hidden" name="ges_classe" id="ges_classe" value="<?= h((string)post('ges_classe','')) ?>">
                </div>
              </div>

              <!-- ── Valeurs principales ── -->
              <div class="ba-grid cols-2" style="grid-column:1/-1">
                <div class="ba-field">
                  <label>Valeur DPE (kWh EP/m²/an)</label>
                  <input type="number" name="dpe_valeur" value="<?= h((string)post('dpe_valeur','')) ?>">
                </div>
                <div class="ba-field">
                  <label>Valeur GES (kg CO₂ éq/m²/an)</label>
                  <input type="number" name="ges_valeur" value="<?= h((string)post('ges_valeur','')) ?>">
                </div>
              </div>

              <!-- ── Métadonnées DPE (extraites par l'IA) ── -->
              <div class="ba-grid cols-2" style="grid-column:1/-1">
                <div class="ba-field">
                  <label>Date de réalisation du DPE</label>
                  <input type="date" name="dpe_date_realisation" value="<?= h((string)post('dpe_date_realisation','')) ?>">
                  <div class="ba-hint">Détermine la version du DPE (avant/après 01/07/2021).</div>
                </div>
                <div class="ba-field">
                  <label>Version du DPE</label>
                  <select name="dpe_version">
                    <option value="">—</option>
                    <option value="2011" <?= post('dpe_version','') === '2011' ? 'selected' : '' ?>>2011 (ancien)</option>
                    <option value="2021" <?= post('dpe_version','') === '2021' ? 'selected' : '' ?>>2021 (nouveau, opposable)</option>
                  </select>
                </div>
                <div class="ba-field">
                  <label>Numéro ADEME (référence)</label>
                  <input type="text" name="dpe_reference_certificat" value="<?= h((string)post('dpe_reference_certificat','')) ?>" placeholder="Ex: 2024E1234B5678">
                </div>
                <div class="ba-field">
                  <label>Altitude (m)</label>
                  <input type="number" name="altitude" value="<?= h((string)post('altitude','')) ?>" placeholder="Ex: 120">
                  <div class="ba-hint">Requis pour les zones H1b/H1c/H2d > 800m.</div>
                </div>
              </div>

              <!-- ── Conso primaire / finale (DPE 2021 distingue les deux) ── -->
              <div class="ba-grid cols-2" style="grid-column:1/-1">
                <div class="ba-field">
                  <label>Conso énergie primaire (kWh EP/m²/an)</label>
                  <input type="number" step="0.01" name="dpe_valeur_conso_primaire" value="<?= h((string)post('dpe_valeur_conso_primaire','')) ?>">
                </div>
                <div class="ba-field">
                  <label>Conso énergie finale (kWh EF/m²/an)</label>
                  <input type="number" step="0.01" name="dpe_valeur_conso_finale" value="<?= h((string)post('dpe_valeur_conso_finale','')) ?>">
                </div>
              </div>

              <!-- ── Estimations dépenses énergétiques (obligatoires depuis 2022) ── -->
              <div class="ba-grid cols-3" style="grid-column:1/-1">
                <div class="ba-field">
                  <label>Dépenses min (€/an)</label>
                  <input type="number" step="0.01" name="montant_estime_depenses_min" value="<?= h((string)post('montant_estime_depenses_min','')) ?>">
                </div>
                <div class="ba-field">
                  <label>Dépenses max (€/an)</label>
                  <input type="number" step="0.01" name="montant_estime_depenses_max" value="<?= h((string)post('montant_estime_depenses_max','')) ?>">
                </div>
                <div class="ba-field">
                  <label>Date indice prix énergies</label>
                  <input type="date" name="date_indice_prix_energies" value="<?= h((string)post('date_indice_prix_energies','')) ?>">
                </div>
              </div>

              <!-- ── DPE vierge (cas particulier) ── -->
              <div class="ba-bool-grid" style="grid-column:1/-1;margin-top:4px;">
                <?= boolChip('dpe_vierge','DPE vierge (non réalisable — uniquement version 2011)') ?>
              </div>

              <!-- ════ Documents officiels ════ -->
              <div class="ba-field" style="grid-column:1/-1;border-top:1px dashed var(--stroke);padding-top:18px;margin-top:8px;">
                <label style="color:#2d8659;font-weight:700;">📄 DPE — fichier officiel</label>
                <input type="file" name="doc_dpe" accept="application/pdf,image/*" style="color:var(--muted);">
                <div class="ba-hint">Vous pouvez aussi utiliser le bouton "Importer le DPE PDF" en haut pour analyse automatique par IA.</div>
              </div>

              <div class="ba-field" style="grid-column:1/-1;">
                <label style="color:#2d8659;font-weight:700;">📐 Certificat de surface (loi Carrez / Boutin)</label>
                <input type="file" name="doc_certif_surface" accept="application/pdf,image/*" style="color:var(--muted);">
                <div class="ba-hint">Certificat de mesurage. Obligatoire pour les ventes en copropriété (loi Carrez) et certaines locations (loi Boutin).</div>
                <?php if ($isEditing):
                  $existingCs = $pdo->prepare("SELECT id, nom_original, url_fichier, date_document FROM biens_documents WHERE id_bien = ? AND type_document = 'certificat_surface' ORDER BY id DESC LIMIT 1");
                  $existingCs->execute([$editingBienId]);
                  if ($d = $existingCs->fetch(PDO::FETCH_ASSOC)): ?>
                  <div style="margin-top:6px;font-size:11px;color:#2d8659;">
                    ✅ Document chargé : <a href="<?= h($d['url_fichier']) ?>" target="_blank"><?= h($d['nom_original'] ?: 'Certificat') ?></a>
                  </div>
                <?php endif; endif; ?>
              </div>

              <div class="ba-field" style="grid-column:1/-1;">
                <label>Autres diagnostics (PDF)</label>
                <input type="file" name="docs[]" multiple accept="application/pdf" style="color:var(--muted);">
                <div class="ba-hint">Plomb, amiante, électricité, gaz, ERP, etc.</div>
              </div>

            </div>
          </div>
        </div>

        <!-- ════════════════════════════════════════════════
             📎 DOCUMENT DPE ATTACHÉ — aperçu + historique
             ════════════════════════════════════════════════ -->
        <?php if ($isEditing): ?>
        <?php
          // Lecture depuis dpe_diags (table dédiée enrichie)
          $stmtAllDiags = $pdo->prepare("
            SELECT id, type_diag, est_diag_principal, date_diagnostic, date_validite,
                   dpe_classe, ges_classe, dpe_vierge, numero_ademe,
                   fichier_url, nom_fichier_original, taille_fichier_octets,
                   diagnostiqueur_nom, diagnostiqueur_societe,
                   alerte_plomb_present, alerte_plomb_classe_max, alerte_amiante_present,
                   alerte_electricite_anomalies, alerte_gaz_anomalies, alerte_termites,
                   alerte_zone_georisque, alerte_inondation,
                   extraction_method, extraction_score, extraction_date,
                   resume_bailleur, resume_envoye_le,
                   date_creation
            FROM dpe_diags
            WHERE id_bien = ?
            ORDER BY est_diag_principal DESC, id DESC
          ");
          $stmtAllDiags->execute([$editingBienId]);
          $allDiags = $stmtAllDiags->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <?php if (!empty($allDiags)):
          $latestDiag = $allDiags[0];
          $hasAlerts = $latestDiag['alerte_plomb_present'] || $latestDiag['alerte_amiante_present']
                    || $latestDiag['alerte_electricite_anomalies'] || $latestDiag['alerte_gaz_anomalies']
                    || $latestDiag['alerte_termites'] || $latestDiag['alerte_zone_georisque'];
        ?>
        <div class="ba-card" style="margin-top:16px;">
          <div class="ba-card-head">
            <div class="ba-card-title">🔬 Diagnostics du bien</div>
            <div class="ba-card-sub"><?= count($allDiags) ?> diagnostic(s) en base — le plus récent est utilisé en référence</div>
          </div>
          <div class="ba-card-body">

            <!-- ── Bloc principal : dernier diagnostic ── -->
            <div style="margin-bottom:14px;background:#f5f1ee;border-radius:10px;padding:14px;">
              <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:10px;flex-wrap:wrap;">
                <div style="flex:1;min-width:0;">
                  <div style="font-weight:700;color:#1a1816;font-size:14px;">
                    📄 <?= h($latestDiag['nom_fichier_original'] ?: 'Diagnostic') ?>
                    <?php if (!empty($latestDiag['extraction_method']) && $latestDiag['extraction_method'] !== 'manuel'): ?>
                      <span style="display:inline-block;padding:2px 8px;border-radius:99px;background:<?= $latestDiag['extraction_method'] === 'regex+ia' ? '#e0e7ff' : '#f0fdf4' ?>;color:<?= $latestDiag['extraction_method'] === 'regex+ia' ? '#4338ca' : '#15803d' ?>;font-size:10px;font-weight:700;margin-left:6px;">
                        <?= $latestDiag['extraction_method'] === 'regex+ia' ? '🧠 IA GPT-4o' : '⚡ Regex' ?>
                        — <?= (int)$latestDiag['extraction_score'] ?>%
                      </span>
                    <?php endif; ?>
                  </div>
                  <div style="font-size:11px;color:#888;margin-top:4px;">
                    <?= !empty($latestDiag['date_diagnostic']) ? '📅 Diag du ' . h(date('d/m/Y', strtotime((string)$latestDiag['date_diagnostic']))) : '' ?>
                    <?= !empty($latestDiag['numero_ademe']) ? ' • N° ADEME : ' . h($latestDiag['numero_ademe']) : '' ?>
                    <?= !empty($latestDiag['taille_fichier_octets']) ? ' • ' . round($latestDiag['taille_fichier_octets']/1024) . ' Ko' : '' ?>
                  </div>
                  <?php if (!empty($latestDiag['diagnostiqueur_nom'])): ?>
                  <div style="font-size:11px;color:#888;margin-top:2px;">
                    👤 <?= h($latestDiag['diagnostiqueur_nom']) ?>
                    <?= !empty($latestDiag['diagnostiqueur_societe']) ? ' — ' . h($latestDiag['diagnostiqueur_societe']) : '' ?>
                  </div>
                  <?php endif; ?>
                </div>
                <div style="display:flex;gap:6px;flex-shrink:0;">
                  <?php if (!empty($latestDiag['fichier_url'])):
                    $latestDiagUrl = function_exists('app_url') ? app_url($latestDiag['fichier_url']) : $latestDiag['fichier_url'];
                  ?>
                  <a href="<?= h($latestDiagUrl) ?>" target="_blank"
                     style="padding:7px 14px;background:#1f6f7a;color:#fff;text-decoration:none;border-radius:8px;font-size:11px;font-weight:700;">
                    🔍 Ouvrir
                  </a>
                  <a href="<?= h($latestDiagUrl) ?>" download="<?= h($latestDiag['nom_fichier_original'] ?: 'diagnostic.pdf') ?>"
                     style="padding:7px 14px;background:#fff;color:#1f6f7a;text-decoration:none;border-radius:8px;font-size:11px;font-weight:700;border:1px solid #1f6f7a;">
                    💾 Télécharger
                  </a>
                  <?php endif; ?>
                </div>
              </div>

              <!-- Badges DPE/GES -->
              <?php if ($latestDiag['dpe_classe'] || $latestDiag['ges_classe'] || $latestDiag['dpe_vierge']): ?>
              <div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;flex-wrap:wrap;">
                <?php if (!empty($latestDiag['dpe_vierge'])): ?>
                  <span style="padding:5px 12px;background:#fef3c7;color:#92400e;border:1px solid #f59e0b;border-radius:8px;font-size:11px;font-weight:700;">📊 DPE vierge</span>
                <?php endif; ?>
                <?php if ($latestDiag['dpe_classe']): ?>
                  <span style="padding:5px 12px;background:#dcfce7;color:#15803d;border-radius:8px;font-size:11px;font-weight:700;">⚡ DPE : <?= h($latestDiag['dpe_classe']) ?></span>
                <?php endif; ?>
                <?php if ($latestDiag['ges_classe']): ?>
                  <span style="padding:5px 12px;background:#e0e7ff;color:#4338ca;border-radius:8px;font-size:11px;font-weight:700;">🌍 GES : <?= h($latestDiag['ges_classe']) ?></span>
                <?php endif; ?>
              </div>
              <?php endif; ?>

              <!-- Alertes -->
              <?php if ($hasAlerts): ?>
              <div style="padding:10px;background:#fff;border:1px solid #fde68a;border-radius:8px;margin-bottom:10px;">
                <div style="font-weight:700;font-size:11px;color:#92400e;margin-bottom:6px;">⚠️ Alertes diagnostics</div>
                <?php if ($latestDiag['alerte_plomb_present']): ?>
                  <div style="font-size:12px;color:#555;padding:3px 0;">🔴 <strong>Plomb (CREP)</strong> : présence de revêtements contenant du plomb au-delà des seuils
                    <?= $latestDiag['alerte_plomb_classe_max'] ? ' (classe max ' . h($latestDiag['alerte_plomb_classe_max']) . ')' : '' ?>
                  </div>
                <?php endif; ?>
                <?php if ($latestDiag['alerte_amiante_present']): ?>
                  <div style="font-size:12px;color:#555;padding:3px 0;">🟠 <strong>Amiante</strong> : matériaux contenant de l'amiante repérés</div>
                <?php endif; ?>
                <?php if ($latestDiag['alerte_electricite_anomalies']): ?>
                  <div style="font-size:12px;color:#555;padding:3px 0;">🟡 <strong>Électricité</strong> : l'installation comporte des anomalies</div>
                <?php endif; ?>
                <?php if ($latestDiag['alerte_gaz_anomalies']): ?>
                  <div style="font-size:12px;color:#555;padding:3px 0;">🔵 <strong>Gaz</strong> : l'installation comporte des anomalies</div>
                <?php endif; ?>
                <?php if ($latestDiag['alerte_termites']): ?>
                  <div style="font-size:12px;color:#555;padding:3px 0;">🟤 <strong>Termites</strong> : présence détectée</div>
                <?php endif; ?>
                <?php if ($latestDiag['alerte_zone_georisque']): ?>
                  <div style="font-size:12px;color:#555;padding:3px 0;">🌊 <strong>Géorisques</strong> : bien situé en zone à risques (PPRn)</div>
                <?php endif; ?>
              </div>
              <?php endif; ?>

              <!-- Résumé bailleur -->
              <?php if (!empty($latestDiag['resume_bailleur'])): ?>
              <div style="padding:12px;background:#f0f9ff;border-left:3px solid #0ea5e9;border-radius:8px;font-size:12px;color:#0c4a6e;line-height:1.6;">
                <div style="font-weight:700;margin-bottom:6px;display:flex;justify-content:space-between;align-items:center;">
                  <span>📋 Résumé pour le propriétaire / bailleur</span>
                  <?php if (!empty($latestDiag['resume_envoye_le'])): ?>
                    <span style="font-size:10px;color:#0369a1;font-weight:400;">✓ Envoyé le <?= h(date('d/m/Y', strtotime((string)$latestDiag['resume_envoye_le']))) ?></span>
                  <?php endif; ?>
                </div>
                <?= nl2br(h($latestDiag['resume_bailleur'])) ?>
                <div style="margin-top:10px;padding-top:8px;border-top:1px dashed #bae6fd;font-size:10px;color:#0369a1;">
                  💡 Bouton "Envoyer au propriétaire" disponible prochainement depuis la fiche complète du bien.
                </div>
              </div>
              <?php endif; ?>

              <!-- Aperçu PDF embarqué -->
              <?php if (!empty($latestDiag['fichier_url'])): ?>
              <iframe src="<?= h($latestDiagUrl ?? (function_exists('app_url') ? app_url($latestDiag['fichier_url']) : $latestDiag['fichier_url'])) ?>"
                      style="width:100%;height:520px;border:none;border-radius:8px;background:#fff;margin-top:14px;"
                      title="Aperçu diagnostic"></iframe>
              <?php endif; ?>
            </div>

            <!-- ── Historique des diagnostics (anciens) ── -->
            <?php if (count($allDiags) > 1): ?>
            <details style="margin-top:10px;font-size:12px;">
              <summary style="cursor:pointer;font-weight:700;color:#888;">📜 Historique (<?= count($allDiags) - 1 ?> ancien(s) diagnostic(s))</summary>
              <ul style="list-style:none;padding:8px 0 0 0;margin:0;">
                <?php foreach (array_slice($allDiags, 1) as $old):
                  $oldUrl = function_exists('app_url') && !empty($old['fichier_url'])
                            ? app_url($old['fichier_url']) : ($old['fichier_url'] ?? '');
                ?>
                <li style="padding:6px 0;border-bottom:1px solid #f1efe9;display:flex;justify-content:space-between;align-items:center;">
                  <span>
                    <?= h($old['nom_fichier_original'] ?: 'Diagnostic') ?>
                    <?= !empty($old['date_diagnostic']) ? ' — ' . h(date('d/m/Y', strtotime((string)$old['date_diagnostic']))) : '' ?>
                  </span>
                  <?php if ($oldUrl): ?>
                  <a href="<?= h($oldUrl) ?>" target="_blank" style="color:#1f6f7a;font-weight:700;">Voir</a>
                  <?php endif; ?>
                </li>
                <?php endforeach; ?>
              </ul>
            </details>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <div class="ba-panel-footer">
          <button type="button" class="ba-btn-ghost" onclick="switchTab('prix')">← Précédent</button>
          <button type="button" class="ba-btn-primary" onclick="switchTab('description')">Suivant →</button>
        </div>
      </div>

      <!-- ════ ONGLET 7 — DESCRIPTION ════ -->
      <div class="ba-panel" data-tab-panel="description">
        <div class="ba-card">
          <div class="ba-card-head">
            <div class="ba-card-title">Description</div>
            <div class="ba-card-sub">Texte de présentation du bien pour les portails.</div>
          </div>
          <div class="ba-card-body">
            <?php if ((string)post('reprise_descriptif','') !== ''): ?>
            <div class="ba-field" style="margin-bottom:18px;">
              <label style="color:#7a9060;font-weight:600;">📋 Descriptif original (fiche source — lecture seule)</label>
              <textarea name="reprise_descriptif" rows="6" readonly
                        style="background:rgba(122,144,96,.06);border:1px solid rgba(122,144,96,.25);color:var(--muted);cursor:default;resize:vertical;box-shadow:none;"><?= h((string)post('reprise_descriptif','')) ?></textarea>
              <div class="ba-hint">Texte extrait du PDF. Copiez-le dans la description ci-dessous et retravaillez-le.</div>
            </div>
            <?php else: ?>
            <input type="hidden" name="reprise_descriptif" value="">
            <?php endif; ?>
            <div class="ba-field">
              <label>📝 Description brute (notes, points à retenir)</label>
              <textarea name="description" id="ba-desc-main" rows="6" placeholder="Décrivez le bien en quelques phrases : notes brutes, points forts, ambiance, défauts à signaler… L'IA transformera ensuite ces notes en annonce commerciale optimisée."><?= h((string)post('description','')) ?></textarea>
              <div class="ba-hint">Vos notes brutes — l'IA les utilisera comme contexte pour rédiger l'annonce finale.</div>
            </div>

            <!-- ════ RÉCAP ANALYSE PHOTOS ════════════════════════ -->
            <?php
              $photosAnalysees = array_values(array_filter($existingBienPhotos ?? [], static fn($p) => !empty($p['description_ia'])));
              $nbPhotosTotal   = count($existingBienPhotos ?? []);
              $nbPhotosAnalyse = count($photosAnalysees);
            ?>
            <div class="ba-photo-recap" id="ba-photo-recap">
              <div class="ba-photo-recap-title">
                🖼️ Analyse automatique des photos
                <?php if ($nbPhotosAnalyse > 0): ?>
                  <span class="count"><?= $nbPhotosAnalyse ?>/<?= $nbPhotosTotal ?></span>
                <?php endif; ?>
              </div>
              <?php if ($nbPhotosAnalyse === 0): ?>
                <div class="ba-photo-recap-empty">
                  <?php if ($nbPhotosTotal === 0): ?>
                    Aucune photo uploadée. Ajoutez des photos dans l'onglet <strong>📸 Photos</strong> — elles seront automatiquement analysées par l'IA Vision pour enrichir la description.
                  <?php else: ?>
                    Les photos ne sont pas (encore) analysées. Cette analyse s'effectue automatiquement à l'upload via l'outil d'intake.
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <ul class="ba-photo-recap-list">
                  <?php foreach ($photosAnalysees as $pa): ?>
                    <li class="ba-photo-recap-item">
                      <img src="<?= h($pa['url']) ?>" alt="" loading="lazy">
                      <div>
                        <?php if (!empty($pa['categorie'])): ?>
                          <span class="cat"><?= h(str_replace('_', ' ', $pa['categorie'])) ?></span>
                        <?php endif; ?>
                        <?= h($pa['description_ia']) ?>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
              <div class="ba-hint" style="margin-top:10px;">
                Ces analyses sont automatiquement fournies à ChatGPT lors de la génération du descriptif ci-dessous.
              </div>
            </div>

            <!-- ════ BLOC IA — Proposition descriptif ChatGPT ════ -->
            <div class="ba-ia-block">
              <div class="ba-ia-head">
                <div class="ba-ia-title">✨ Proposition de descriptif par ChatGPT <span class="ba-ia-tag">GPT</span></div>
                <button type="button" class="ba-ia-btn" id="ba-ia-btn" onclick="bienGenerateIA()">
                  ✨ Générer le descriptif
                </button>
              </div>
              <div class="ba-ia-status" id="ba-ia-status">ChatGPT compile toutes les caractéristiques du bien + l'analyse des photos pour rédiger un descriptif commercial prêt à publier.</div>
              <div class="ba-field">
                <label style="color:#6a4ca8;font-weight:700;">📄 Proposition de descriptif (éditable)</label>
                <textarea name="texte_ia" id="ba-texte-ia" class="ba-ia-textarea" placeholder="Cliquez sur « Générer le descriptif » ci-dessus. Le texte proposé apparaîtra ici — vous pourrez l'éditer avant d'enregistrer."><?= h((string)post('texte_ia','')) ?></textarea>
                <div class="ba-hint" style="margin-top:6px;">💡 Ce texte est sauvegardé dans le bien et réutilisé pour les annonces. Vous pouvez le modifier librement.</div>
              </div>
              <div id="ba-ia-points-block" style="display:none;margin-top:12px;">
                <div style="font-size:11px;font-weight:700;color:#6a4ca8;text-transform:uppercase;letter-spacing:.06em;font-family:'DM Mono',monospace;">Points forts suggérés</div>
                <ul class="ba-ia-points-list" id="ba-ia-points-list"></ul>
              </div>
            </div>

          </div>
        </div>
        <?= moreBtn('desc-details') ?>
        <?= detailsCard('desc-details', '🔍 SEO & commentaires internes', '
          <div class="ba-grid cols-1" style="gap:16px">
            <div class="ba-field">
              <label>Titre SEO</label>
              <input type="text" name="titre_seo" value="' . h((string)post('titre_seo','')) . '" placeholder="Titre pour Google (50–60 car.)">
              <div class="ba-hint">Utilisé comme balise &lt;title&gt; sur la fiche portail.</div>
            </div>
            <div class="ba-field">
              <label>Meta description</label>
              <textarea name="meta_description" rows="3" placeholder="Résumé pour les moteurs de recherche (150–160 car.)">' . h((string)post('meta_description','')) . '</textarea>
            </div>
            <div class="ba-field">
              <label>Mots-clés</label>
              <input type="text" name="mots_cles" value="' . h((string)post('mots_cles','')) . '" placeholder="appartement, Paris, calme, lumineux…">
              <div class="ba-hint">Séparés par des virgules.</div>
            </div>
            <div class="ba-field">
              <label>Commentaire interne (non publié)</label>
              <textarea name="commentaire" rows="3" placeholder="Notes à usage interne uniquement…">' . h((string)post('commentaire','')) . '</textarea>
            </div>
          </div>
        ') ?>
        <div class="ba-panel-footer">
          <button type="button" class="ba-btn-ghost" onclick="switchTab('dpe')">← Précédent</button>
          <button type="button" class="ba-btn-primary" onclick="switchTab('disponibilite')">Suivant →</button>
        </div>
      </div>

      <!-- ════ ONGLET 8 — DISPONIBILITÉ ════ -->
      <div class="ba-panel" data-tab-panel="disponibilite">
        <div class="ba-card">
          <div class="ba-card-head">
            <div class="ba-card-title">Disponibilité & Options</div>
          </div>
          <div class="ba-card-body">
            <div class="ba-grid cols-2">
              <div class="ba-field">
                <label>Date de disponibilité</label>
                <input type="date" name="disponibilite_date" value="<?= h((string)post('disponibilite_date','')) ?>">
              </div>
              <div class="ba-field">
                <label>Meublé</label>
                <div class="ba-chips">
                  <label class="ba-chip"><input type="radio" name="meuble" value="1" <?= post('meuble','') === '1' ? 'checked' : '' ?>> Oui</label>
                  <label class="ba-chip"><input type="radio" name="meuble" value="0" <?= post('meuble','') === '0' ? 'checked' : '' ?>> Non</label>
                </div>
              </div>
              <div class="ba-field">
                <label>Animaux acceptés</label>
                <div class="ba-chips">
                  <label class="ba-chip"><input type="radio" name="animaux" value="1" <?= post('animaux','') === '1' ? 'checked' : '' ?>> Oui</label>
                  <label class="ba-chip"><input type="radio" name="animaux" value="0" <?= post('animaux','') === '0' ? 'checked' : '' ?>> Non</label>
                </div>
              </div>
              <div class="ba-field">
                <label>Fumeur accepté</label>
                <div class="ba-chips">
                  <label class="ba-chip"><input type="radio" name="fumeur" value="1" <?= post('fumeur','') === '1' ? 'checked' : '' ?>> Oui</label>
                  <label class="ba-chip"><input type="radio" name="fumeur" value="0" <?= post('fumeur','') === '0' ? 'checked' : '' ?>> Non</label>
                </div>
              </div>
            </div>
          </div>
        </div>
        <?= moreBtn('dispo-details') ?>
        <?= detailsCard('dispo-details', '🔑 Occupation & gestion locative', '
          <div class="ba-grid cols-2">
            <div class="ba-field">
              <label>Disponibilité bien</label>
              <select name="disponibilite_bien">
                <option value="">—</option>
                <option value="libre" ' . (post('disponibilite_bien','') === 'libre' ? 'selected' : '') . '>Libre</option>
                <option value="occupe" ' . (post('disponibilite_bien','') === 'occupe' ? 'selected' : '') . '>Occupé</option>
                <option value="a_liberer" ' . (post('disponibilite_bien','') === 'a_liberer' ? 'selected' : '') . '>À libérer</option>
              </select>
            </div>
            <div class="ba-field">
              <label>Occupation du bien</label>
              <select name="occupation_bien">
                <option value="">—</option>
                <option value="vide" ' . (post('occupation_bien','') === 'vide' ? 'selected' : '') . '>Vide</option>
                <option value="meuble" ' . (post('occupation_bien','') === 'meuble' ? 'selected' : '') . '>Meublé</option>
                <option value="en_cours" ' . (post('occupation_bien','') === 'en_cours' ? 'selected' : '') . '>Bail en cours</option>
              </select>
            </div>
          </div>
          <div class="ba-bool-grid" style="margin-top:14px">
            ' . boolChip('louable_immediatement','Louable immédiatement') . '
          </div>
        ') ?>
        <div class="ba-panel-footer">
          <button type="button" class="ba-btn-ghost" onclick="switchTab('description')">← Précédent</button>
          <button type="button" class="ba-btn-primary" onclick="switchTab('diffusion')">Suivant →</button>
        </div>
      </div>

      <!-- ════ ONGLET 9 — DIFFUSION / CONFORMITÉ UBIFLOW ════ -->
      <div class="ba-panel" data-tab-panel="diffusion">
        <div class="ba-card">
          <div class="ba-card-head">
            <div class="ba-card-title">🌐 Diffusion portails (Le Bon Coin, SeLoger…)</div>
            <div class="ba-card-sub">Champs obligatoires pour la diffusion via le flux Ubiflow et la conformité légale (DPE 2021/2022, ALUR 2017/2022, ERP 2023, débroussaillement 2025).</div>
          </div>
          <div class="ba-card-body">

            <!-- ── Mandat ── -->
            <div class="ba-section-label" style="margin-top:0;">📜 Mandat</div>
            <div class="ba-grid cols-2">
              <div class="ba-field">
                <label>N° de mandat</label>
                <input type="text" name="mandat_numero" value="<?= h((string)post('mandat_numero','')) ?>" placeholder="2026-001">
              </div>
              <div class="ba-field">
                <label>Type de mandat</label>
                <select name="mandat_type">
                  <option value="">—</option>
                  <option value="exclusif" <?= post('mandat_type','') === 'exclusif' ? 'selected' : '' ?>>Exclusif</option>
                  <option value="simple"   <?= post('mandat_type','') === 'simple'   ? 'selected' : '' ?>>Simple</option>
                </select>
              </div>
              <div class="ba-field">
                <label>Date de signature</label>
                <input type="date" name="date_mandat" value="<?= h((string)post('date_mandat','')) ?>">
              </div>
              <div class="ba-field">
                <label>Date d'échéance</label>
                <input type="date" name="mandat_echeance" value="<?= h((string)post('mandat_echeance','')) ?>">
              </div>
              <div class="ba-field ba-col-full">
                <label>URL du barème d'honoraires (obligation arrêté 10/01/2017)</label>
                <?php
                  $defaultTarifsUrl = '';
                  if (function_exists('app_url')) {
                    $idSocTarifs = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : 0;
                    $defaultTarifsUrl = app_url('/tarifs.php' . ($idSocTarifs > 0 ? '?societe=' . $idSocTarifs : ''));
                    // Si http(s):// absolu si possible
                    if (!preg_match('#^https?://#', $defaultTarifsUrl) && !empty($_SERVER['HTTP_HOST'])) {
                      $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                      $defaultTarifsUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . $defaultTarifsUrl;
                    }
                  }
                  $valTarifs = (string)post('url_tarifs_publics', $defaultTarifsUrl);
                ?>
                <input type="url" name="url_tarifs_publics" value="<?= h($valTarifs) ?>" placeholder="https://votre-agence.fr/tarifs">
                <div class="ba-hint">
                  Lien public vers la grille tarifaire — obligatoire sur toute annonce dématérialisée.
                  <a href="<?= h($defaultTarifsUrl) ?>" target="_blank" style="color:#1f6f7a;">📋 Voir / éditer le barème</a>
                </div>
              </div>
            </div>

            <!-- ── Honoraires ALUR ── -->
            <div class="ba-section-label">💰 Honoraires ALUR (vente)</div>
            <div class="ba-bool-grid" style="margin-bottom:14px">
              <?= boolChip('honoraires_charge_acquereur','Honoraires à charge acquéreur') ?>
              <?= boolChip('honoraires_charge_vendeur','Honoraires à charge vendeur') ?>
            </div>
            <div class="ba-grid cols-2">
              <div class="ba-field">
                <label>% TTC honoraires acquéreur (sur prix HH)</label>
                <input type="number" step="0.01" name="alur_pourcentage_honoraires_ttc" value="<?= h((string)post('alur_pourcentage_honoraires_ttc','')) ?>" placeholder="5.00">
                <div class="ba-hint">Obligatoire si honoraires à charge acquéreur.</div>
              </div>
              <div class="ba-field">
                <label>% honoraires vendeur</label>
                <input type="number" step="0.01" name="pourcentage_honoraires_vendeur" value="<?= h((string)post('pourcentage_honoraires_vendeur','')) ?>">
              </div>
              <div class="ba-field">
                <label>Honoraires cumulés (acq+vendeur, €)</label>
                <input type="number" step="0.01" name="honoraires_negociation_cumules" value="<?= h((string)post('honoraires_negociation_cumules','')) ?>">
              </div>
            </div>

            <!-- ── Encadrement loyers (Paris/Lille) ── -->
            <div class="ba-section-label">🏙 Encadrement des loyers (Paris, Lille…)</div>
            <div class="ba-bool-grid" style="margin-bottom:14px">
              <?= boolChip('zone_encadrement_loyer','Bien en zone encadrée') ?>
              <?= boolChip('loyer_est_cc','Loyer affiché charges comprises') ?>
            </div>
            <div class="ba-grid cols-2">
              <div class="ba-field">
                <label>Loyer de base (€/mois HC)</label>
                <input type="number" step="0.01" name="loyer_de_base" value="<?= h((string)post('loyer_de_base','')) ?>">
              </div>
              <div class="ba-field">
                <label>Loyer de référence majoré (€/mois)</label>
                <input type="number" step="0.01" name="loyer_reference_majore" value="<?= h((string)post('loyer_reference_majore','')) ?>">
              </div>
              <div class="ba-field">
                <label>Complément de loyer (€/mois)</label>
                <input type="number" step="0.01" name="complement_loyer" value="<?= h((string)post('complement_loyer','')) ?>">
              </div>
              <div class="ba-field">
                <label>Modalités récup. charges</label>
                <select name="modalite_recuperation_charges_locatives">
                  <option value="">—</option>
                  <option value="forfait" <?= post('modalite_recuperation_charges_locatives','') === 'forfait' ? 'selected' : '' ?>>Forfait</option>
                  <option value="provision annuelle" <?= post('modalite_recuperation_charges_locatives','') === 'provision annuelle' ? 'selected' : '' ?>>Provision annuelle</option>
                  <option value="remboursement sur justificatifs" <?= post('modalite_recuperation_charges_locatives','') === 'remboursement sur justificatifs' ? 'selected' : '' ?>>Remboursement sur justificatifs</option>
                </select>
              </div>
              <div class="ba-field">
                <label>Honoraires état des lieux (€)</label>
                <input type="number" step="0.01" name="honoraires_etat_des_lieux" value="<?= h((string)post('honoraires_etat_des_lieux','')) ?>">
              </div>
            </div>

          </div>
        </div>

        <?php /* ── Bloc DPE déplacé dans l'onglet ⚡ DPE pour un workflow unifié ── */ ?>

        <?= moreBtn('diffusion-erp') ?>
        <?= detailsCard('diffusion-erp', '🌍 ERP / Géorisques (obligations 2023 & 2025)', '
          <div class="ba-bool-grid" style="margin-bottom:14px">
            ' . boolChip('zone_georisque','Bien en zone Géorisques (obligation depuis 01/01/2023)') . '
            ' . boolChip('obligation_debroussaillement','Zone soumise à obligation de débroussaillement (01/01/2025)') . '
          </div>
          <div class="ba-grid cols-2">
            <div class="ba-field">
              <label>Date d\'établissement de l\'ERP</label>
              <input type="date" name="erp_date_realisation" value="' . h((string)post('erp_date_realisation','')) . '">
            </div>
            <div class="ba-field">
              <label>Risque inondation — bâti (>25m²)</label>
              <select name="risque_inondation_g_score">
                <option value="">—</option>
                ' . implode("\n", array_map(function($l) {
                  $sel = post('risque_inondation_g_score','') === $l ? ' selected' : '';
                  return "<option value=\"$l\"$sel>$l</option>";
                }, ['A','B','C','D'])) . '
              </select>
            </div>
            <div class="ba-field">
              <label>Risque inondation — terrain</label>
              <select name="risque_inondation_p_score">
                <option value="">—</option>
                ' . implode("\n", array_map(function($l) {
                  $sel = post('risque_inondation_p_score','') === $l ? ' selected' : '';
                  return "<option value=\"$l\"$sel>$l</option>";
                }, ['A','B','C','D'])) . '
              </select>
            </div>
          </div>
        ') ?>

        <?= moreBtn('diffusion-alur-copro') ?>
        <?= detailsCard('diffusion-alur-copro', '🏢 ALUR — copropriété (compléments)', '
          <div class="ba-bool-grid" style="margin-bottom:14px">
            ' . boolChip('alur_copropriete_plan_sauvegarde','Copropriété sous plan de sauvegarde') . '
            ' . boolChip('alur_copropriete_etat_carence','Copropriété en état de carence') . '
          </div>
          <div class="ba-grid cols-2">
            <div class="ba-field">
              <label>Statut provisoire du syndicat</label>
              <select name="alur_syndicat_statut">
                <option value="">—</option>
                <option value="administrateur provisoire" ' . (post('alur_syndicat_statut','') === 'administrateur provisoire' ? 'selected' : '') . '>Administrateur provisoire</option>
                <option value="mandataire ad hoc" ' . (post('alur_syndicat_statut','') === 'mandataire ad hoc' ? 'selected' : '') . '>Mandataire ad hoc</option>
                <option value="expert" ' . (post('alur_syndicat_statut','') === 'expert' ? 'selected' : '') . '>Expert</option>
              </select>
              <div class="ba-hint">À renseigner uniquement si la copropriété fait l\'objet d\'une procédure.</div>
            </div>
          </div>
        ') ?>

        <div class="ba-panel-footer">
          <button type="button" class="ba-btn-ghost" onclick="switchTab('disponibilite')">← Précédent</button>
          <?php if ($isDraft): ?>
            <button type="submit" class="ba-btn-ghost" title="Sauvegarde sans changer le statut — vous pourrez revenir plus tard">💾 Sauvegarder le brouillon</button>
            <button type="submit" class="ba-btn-primary" onclick="document.getElementById('_validate_now').value='1';" title="Valide le bien et le passe en statut actif">✅ Valider et activer</button>
          <?php elseif ($isEditing): ?>
            <button type="submit" class="ba-btn-primary">💾 Mettre à jour le bien</button>
          <?php else: ?>
            <button type="submit" class="ba-btn-primary">💾 Enregistrer le bien</button>
          <?php endif; ?>
        </div>
      </div>

        </div><!-- /.ba-form-col -->

        <!-- ════════════════════════════════════════════════
             COLONNE DROITE — APERÇU LIVE
             ════════════════════════════════════════════════ -->
        <aside class="ba-preview-col">

          <div class="ba-preview-card">
            <div class="bp-hero">
              <span class="bp-hero-badge" id="bp-badge">À renseigner</span>
              <div class="bp-hero-label">
                <div class="bp-hero-type"><span id="bp-type">Type</span> · <span id="bp-ville-cp"><?= h($agence['ville'] ?? 'Ville') ?></span></div>
                <div class="bp-hero-title" id="bp-title">Désignation du bien</div>
              </div>
            </div>
            <div class="bp-body">
              <div class="bp-kpis">
                <div class="bp-kbox"><div class="bp-kval" id="bp-prix">—</div><div class="bp-klbl">Prix</div></div>
                <div class="bp-kbox"><div class="bp-kval" id="bp-surf">—</div><div class="bp-klbl">Surface</div></div>
                <div class="bp-kbox"><div class="bp-kval" id="bp-pieces">—</div><div class="bp-klbl">Pièces</div></div>
                <div class="bp-kbox"><div class="bp-kval" id="bp-etage">—</div><div class="bp-klbl">Étage</div></div>
              </div>

              <div id="bp-loc-block">
                <div class="bp-section-label">📍 Localisation</div>
                <div class="bp-loc"><span id="bp-loc-text" class="bp-empty">Adresse à renseigner</span></div>
              </div>

              <div id="bp-equip-block" style="display:none;">
                <div class="bp-section-label">⚙️ Équipements</div>
                <div class="bp-chips" id="bp-chips"></div>
              </div>

              <div id="bp-dpe-block" style="display:none;">
                <div class="bp-section-label">⚡ Énergie</div>
                <div class="bp-dpe-line">
                  <span>DPE</span>
                  <span class="bp-dpe-pill empty" id="bp-dpe">—</span>
                  <span style="margin-left:8px;">GES</span>
                  <span class="bp-dpe-pill empty" id="bp-ges">—</span>
                </div>
              </div>

              <div id="bp-photos-block" style="display:none;">
                <div class="bp-section-label">📸 Photos</div>
                <div class="bp-loc"><span id="bp-photos-text"><strong>0</strong> photo</span></div>
              </div>

              <div id="bp-ia-block" style="display:none;">
                <div class="bp-section-label" style="color:#6a4ca8;">✨ Annonce IA <span style="display:inline-block;padding:1px 6px;border-radius:99px;font-size:8px;font-weight:700;background:rgba(176,125,255,0.15);color:#6a4ca8;border:1px solid rgba(176,125,255,0.25);letter-spacing:.04em;margin-left:4px;">GPT</span></div>
                <div class="bp-desc" id="bp-ia-text" style="background:linear-gradient(180deg,rgba(176,125,255,0.05),var(--bg));border:1px solid rgba(176,125,255,0.18);box-shadow:none;color:var(--ink);"></div>
                <ul class="ba-ia-points-list" id="bp-ia-points" style="display:none;margin-top:8px;"></ul>
              </div>

              <div class="bp-section-label">📝 Description brute</div>
              <div class="bp-desc" id="bp-desc"><span class="bp-empty">Vos notes brutes apparaîtront ici.</span></div>
            </div>
          </div>

          <div class="ba-score-card">
            <div class="ba-score-head">
              <div class="ba-score-title">Complétude</div>
              <div class="ba-score-pct" id="bp-score-pct">0%</div>
            </div>
            <div class="ba-score-bar"><div class="ba-score-fill" id="bp-score-fill" style="width:0%"></div></div>
            <div class="ba-score-row" id="bp-row-type"><span class="icon">○</span><span>Type & désignation</span></div>
            <div class="ba-score-row" id="bp-row-loc"><span class="icon">○</span><span>Adresse complète</span></div>
            <div class="ba-score-row" id="bp-row-surf"><span class="icon">○</span><span>Surface & pièces</span></div>
            <div class="ba-score-row" id="bp-row-prix"><span class="icon">○</span><span>Prix renseigné</span></div>
            <div class="ba-score-row" id="bp-row-dpe"><span class="icon">○</span><span>DPE / GES</span></div>
            <div class="ba-score-row" id="bp-row-photos"><span class="icon">○</span><span>Photos (≥ 1)</span></div>
            <div class="ba-score-row" id="bp-row-desc"><span class="icon">○</span><span>Description (≥ 100 car.)</span></div>
          </div>

        </aside><!-- /.ba-preview-col -->

      </div><!-- /.ba-layout -->

    </form>
  </div><!-- /.mbi-container -->
</main>

<script>
(function () {
  // ── COMPTEUR DÉSIGNATION ──
  (function () {
    const input = document.querySelector('input[name="designation"]');
    const counter = document.getElementById('desig-count');
    const wrap = counter ? counter.closest('.ba-char-count') : null;
    if (!input || !counter) return;
    function update() {
      const n = input.value.length;
      counter.textContent = n;
      // Bordure : rouge si vide, orange si < 50, neutre si ≥ 50
      input.style.borderColor = n === 0 ? '#cc5c58' : n < 50 ? 'rgba(245,158,11,.7)' : '';
      if (!wrap) return;
      wrap.className = 'ba-char-count' + (n < 50 ? ' warn' : n <= 80 ? ' ok' : ' over');
    }
    input.addEventListener('input', update);
    update();
  })();

  // ── TYPE DE BIEN ──
  window.selectType = function (btn) {
    document.querySelectorAll('.type-card').forEach(b => b.classList.remove('selected'));
    btn.classList.add('selected');
    document.getElementById('type_bien_hidden').value = btn.dataset.value;
  };

  // ── COMPASS EXPOSITION ──
  function setExposition(val) {
    const hidden = document.getElementById('exposition_val');
    if (!hidden) return;
    const prev = hidden.value;
    hidden.value = (prev === val) ? '' : val;  // toggle off on re-click
    document.querySelectorAll('.ba-compass-btn, .ba-compass-chip').forEach(el => {
      el.classList.toggle('active', el.dataset.dir === hidden.value || el.getAttribute('onclick') === "setExposition('" + hidden.value + "')");
    });
  }
  window.setExposition = setExposition;

  // ── VUE MINI CARDS ──
  function setVue(val) {
    const hidden = document.getElementById('vue_val');
    if (!hidden) return;
    hidden.value = (hidden.value === val) ? '' : val;
    document.querySelectorAll('.ba-vue-card').forEach(el => {
      el.classList.toggle('active', el.getAttribute('onclick') === "setVue('" + hidden.value + "')");
    });
  }
  window.setVue = setVue;

  // ── MINI-CARDS CHAUFFAGE & ÉNERGIE ──────────────────────────
  (function () {
    const gridCh = document.getElementById('mc-chauffage');
    const gridEn = document.getElementById('mc-energie');
    if (!gridCh || !gridEn) return;

    // Map des liaisons énergie → liste de IDs chauffage compatibles
    const lienData = JSON.parse(gridEn.dataset.lien || '{}');

    // Inputs cachés ajoutés dynamiquement au formulaire
    function syncHiddens() {
      // Nettoie
      document.querySelectorAll('input[data-ch-sync]').forEach(el => el.remove());

      const selCh = [...gridCh.querySelectorAll('.mc-card.is-selected')].map(c => c.dataset.mcValue);
      const selEn = [...gridEn.querySelectorAll('.mc-card.is-selected')].map(c => c.dataset.mcValue);
      const form  = gridCh.closest('form');
      if (!form) return;

      // chauffage_ids[] → junction table
      selCh.forEach(v => {
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'chauffage_ids[]'; inp.value = v;
        inp.setAttribute('data-ch-sync','1');
        form.appendChild(inp);
      });
      // energie_ids[] → junction table
      selEn.forEach(v => {
        const inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'energie_ids[]'; inp.value = v;
        inp.setAttribute('data-ch-sync','1');
        form.appendChild(inp);
      });
      // Compat varchar : premier code sélectionné
      const firstCh = gridCh.querySelector('.mc-card.is-selected');
      const firstEn = gridEn.querySelector('.mc-card.is-selected');
      ['chauffage_type','chauffage_energie'].forEach(n => {
        let inp = form.querySelector(`input[name="${n}"][data-ch-sync]`);
        if (!inp) { inp = document.createElement('input'); inp.type='hidden'; inp.name=n; inp.setAttribute('data-ch-sync','1'); form.appendChild(inp); }
        inp.value = n === 'chauffage_type'
          ? (firstCh ? firstCh.dataset.chCode || '' : '')
          : (firstEn ? firstEn.dataset.mcLabel?.toLowerCase().replace(/[éèê]/g,'e').replace(/\s+/g,'_') || '' : '');
      });
    }

    // Filtre les énergies selon les chauffages sélectionnés
    function filterEnergies() {
      const selChIds = new Set([...gridCh.querySelectorAll('.mc-card.is-selected')].map(c => parseInt(c.dataset.mcValue)));
      const hint = document.getElementById('energie-hint');

      gridEn.querySelectorAll('.mc-card').forEach(card => {
        const enId = parseInt(card.dataset.mcValue);
        const compatCh = (lienData[enId] || []).map(Number);

        if (selChIds.size === 0) {
          // Aucun chauffage sélectionné : tout visible
          card.style.display = '';
          card.classList.remove('is-inactive');
        } else {
          // Visible si compatible avec au moins un chauffage sélectionné
          const compatible = compatCh.some(id => selChIds.has(id));
          card.style.display = compatible ? '' : 'none';
          if (!compatible) card.classList.remove('is-selected');
        }
      });

      if (hint) {
        hint.textContent = selChIds.size > 0 ? '(filtrées selon le chauffage sélectionné)' : '';
      }
      syncHiddens();
    }

    // Écoute les changements sur la grille chauffage
    gridCh.addEventListener('mc:change', filterEnergies);
    gridEn.addEventListener('mc:change', syncHiddens);

    // Init au chargement
    filterEnergies();
  })();

  // ── TAB SWITCHING ──
  function switchTab(id) {
    document.querySelectorAll('.ba-panel').forEach(p => p.classList.toggle('active', p.dataset.tabPanel === id));
    document.querySelectorAll('.ba-tab').forEach(b  => b.classList.toggle('active', b.dataset.tab === id));
    history.replaceState(null, '', '#' + id);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  window.switchTab = switchTab;

  document.querySelectorAll('.ba-tab').forEach(btn =>
    btn.addEventListener('click', () => switchTab(btn.dataset.tab))
  );
  const init = window.location.hash ? window.location.hash.slice(1) : 'identification';
  switchTab(init);

  // ── RADIO CHIPS ──
  document.querySelectorAll('.ba-chip input').forEach(inp => {
    inp.addEventListener('change', () => {
      const group = inp.name;
      document.querySelectorAll(`.ba-chip input[name="${group}"]`).forEach(i => {
        i.closest('.ba-chip').classList.toggle('checked', i.checked);
      });
      syncTransaction();
    });
    if (inp.checked) inp.closest('.ba-chip').classList.add('checked');
  });

  // ── TRANSACTION SYNC ──
  function syncTransaction() {
    const type = document.querySelector('input[name="annonce_transaction"]:checked')?.value || '';
    const loc  = document.getElementById('annonce-price-location');
    const ven  = document.getElementById('annonce-price-vente');
    const cLoc = document.getElementById('ba-card-location');
    const cVen = document.getElementById('ba-card-vente');
    if (loc) loc.style.display = type === 'location' ? '' : 'none';
    if (ven) ven.style.display = type === 'vente'    ? '' : 'none';
    if (cLoc) cLoc.style.display = type === 'vente'    ? 'none' : '';
    if (cVen) cVen.style.display = type === 'location' ? 'none' : '';
  }
  syncTransaction();

  // ── TOGGLE DÉTAILS (mode sous-onglets : 1 seul ouvert par panel) ──
  window.toggleDetails = function (id, btn) {
    const card = document.getElementById('det-' + id);
    if (!card) return;
    // Identifier le panel parent (pour grouper les details qui s'excluent mutuellement)
    const panel = btn.closest('.ba-panel') || document.body;
    const willOpen = !card.classList.contains('open');

    // Si on ouvre, fermer tous les autres details du même panel
    if (willOpen) {
      panel.querySelectorAll('.ba-details-card.open').forEach(c => c.classList.remove('open'));
      panel.querySelectorAll('.ba-more-btn.open').forEach(b => {
        b.classList.remove('open');
        const a = b.querySelector('.ba-more-arrow');
        if (a) a.style.transform = '';
      });
    }
    card.classList.toggle('open', willOpen);
    btn.classList.toggle('open', willOpen);
    const arrow = btn.querySelector('.ba-more-arrow');
    if (arrow) arrow.style.transform = willOpen ? 'rotate(180deg)' : '';

    // Scroll doux vers la card si on l'ouvre
    if (willOpen) {
      setTimeout(() => card.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 100);
    }
  };

  // ── REGROUPEMENT VISUEL DES "+ DÉTAILS" CONSÉCUTIFS ──
  // À l'init, on enveloppe les .ba-more-btn consécutifs dans un container .ba-subtabs-row
  // pour qu'ils s'affichent comme une vraie barre de sous-onglets.
  document.querySelectorAll('.ba-panel').forEach(panel => {
    const btns = Array.from(panel.querySelectorAll(':scope > .ba-more-btn, :scope > * > .ba-more-btn'));
    // Trouve les groupes (boutons consécutifs ou séparés par detailsCard fermée)
    let lastWrapper = null;
    panel.querySelectorAll(':scope > .ba-more-btn').forEach(btn => {
      const prev = btn.previousElementSibling;
      if (prev && prev.classList.contains('ba-subtabs-row')) {
        prev.appendChild(btn);
        lastWrapper = prev;
      } else if (lastWrapper && btn.previousElementSibling === lastWrapper.lastElementChild?.nextElementSibling) {
        // already in row
      } else {
        const wrap = document.createElement('div');
        wrap.className = 'ba-subtabs-row';
        btn.parentNode.insertBefore(wrap, btn);
        wrap.appendChild(btn);
        lastWrapper = wrap;
      }
    });
  });

  // ── DPE PALETTE ──
  document.querySelectorAll('.ba-dpe-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const field = btn.dataset.field;
      document.querySelectorAll(`.ba-dpe-btn[data-field="${field}"]`)
        .forEach(b => b.classList.remove('selected'));
      btn.classList.add('selected');
      const hidden = document.getElementById(field);
      if (hidden) hidden.value = btn.dataset.dpe;
    });
  });

  // ════════════════════════════════════════════════════════
  // APERÇU LIVE — colonne droite (Lot 2)
  // ════════════════════════════════════════════════════════
  function bpVal(name) {
    const el = document.querySelector('[name="' + name + '"]');
    return el ? (el.value || '').trim() : '';
  }
  function bpChecked(name) {
    const el = document.querySelector('[name="' + name + '"]');
    return !!(el && el.checked);
  }
  function bpRadio(name) {
    const el = document.querySelector('[name="' + name + '"]:checked');
    return el ? el.value : '';
  }
  function setText(id, txt) { const el = document.getElementById(id); if (el) el.textContent = txt; }
  function setHtml(id, html) { const el = document.getElementById(id); if (el) el.innerHTML = html; }

  // Liste des équipements à afficher en chips dans l'aperçu
  const BP_CHIPS = [
    // Pièces & équipements intérieurs
    ['cuisine_equipee', '🍳 Cuisine équipée'],
    ['dernier_etage',   '🏠 Dernier étage'],
    ['ascenseur',       '🛗 Ascenseur'],
    ['interphone',      '📞 Interphone'],
    ['digicode',        '🔢 Digicode'],
    ['alarme',          '🚨 Alarme'],
    ['climatisation',   '❄️ Climatisation'],
    ['fibre',           '🌐 Fibre optique'],
    ['double_vitrage',  '🪟 Double vitrage'],
    ['volets_roulants', '🪟 Volets roulants'],
    ['cheminee',        '🔥 Cheminée'],
    // Extérieurs & dépendances
    ['balcon',          '🌅 Balcon'],
    ['terrasse',        '☀️ Terrasse'],
    ['jardin',          '🌿 Jardin'],
    ['cour',            '🏡 Cour'],
    ['cave',            '📦 Cave'],
    ['grenier',         '📦 Grenier'],
    ['garage',          '🚗 Garage'],
    ['box',             '🚙 Box'],
    ['piscine',         '🏊 Piscine'],
    ['dependances',     '🏚 Dépendances'],
    ['acces_camion',    '🚚 Accès camion'],
    ['vitrine',         '🪟 Vitrine'],
    // Chauffage / énergie
    ['chauffage_vmc',        '💨 VMC'],
    ['chauffage_vmc_df',     '💨 VMC double flux'],
    ['chauffage_plancher',   '🌡 Plancher chauffant'],
    ['chauffage_regulateur', '🎛 Régulateur'],
    ['chauffage_thermostat', '🌡 Thermostat'],
    ['eau_chaude_solaire',   '☀️ Eau chaude solaire'],
  ];

  function bpSync() {
    // ── Type de bien ──
    const typeSel = document.querySelector('.type-card.selected');
    const typeLabel = typeSel ? (typeSel.querySelector('.type-card-label')?.textContent || 'Bien').trim() : 'Type';
    setText('bp-type', typeLabel);

    // ── Désignation ──
    const desig = bpVal('designation');
    setText('bp-title', desig || 'Désignation du bien');

    // ── Localisation ──
    const ville = bpVal('ville');
    const cp    = bpVal('code_postal');
    const adr1  = bpVal('adresse_1');
    const locInline = (ville && cp) ? (ville + ' ' + cp) : (ville || cp || 'Ville');
    setText('bp-ville-cp', locInline);
    const locText = document.getElementById('bp-loc-text');
    if (locText) {
      if (adr1 || ville) {
        const parts = [adr1, [cp, ville].filter(Boolean).join(' ')].filter(Boolean);
        locText.innerHTML = '<strong>' + parts.join(', ') + '</strong>';
        locText.className = '';
      } else {
        locText.textContent = 'Adresse à renseigner';
        locText.className = 'bp-empty';
      }
    }

    // ── Transaction & Prix (badge + KPI) ──
    const trans = bpRadio('annonce_transaction');
    const badge = document.getElementById('bp-badge');
    let prixDisplay = '—';
    if (trans === 'vente') {
      const pv = parseFloat(bpVal('annonce_prix_vente') || bpVal('prix_vente') || 0);
      if (pv > 0) prixDisplay = pv.toLocaleString('fr-FR') + ' <span>€</span>';
      if (badge) { badge.textContent = '🤝 Vente'; badge.style.display = ''; }
    } else if (trans === 'location') {
      const lo = parseFloat(bpVal('annonce_loyer') || bpVal('loyer_hc') || 0);
      if (lo > 0) prixDisplay = lo.toLocaleString('fr-FR') + ' <span>€/mois</span>';
      if (badge) { badge.textContent = '🔑 Location'; badge.style.display = ''; }
    } else {
      if (badge) { badge.textContent = 'À renseigner'; badge.style.display = ''; }
    }
    setHtml('bp-prix', prixDisplay);

    // ── Surface / pièces / étage ──
    const surf   = bpVal('surface_habitable');
    const pieces = bpVal('nb_pieces');
    const etage  = bpVal('etage');
    setHtml('bp-surf',   surf   ? surf   + ' <span>m²</span>'    : '—');
    setHtml('bp-pieces', pieces ? pieces + ' <span>pcs</span>'   : '—');
    setHtml('bp-etage',  etage  ? etage  + '<span>e</span>'      : '—');

    // ── Équipements (chips) ──
    const chipsBox = document.getElementById('bp-chips');
    const chipsBlock = document.getElementById('bp-equip-block');
    if (chipsBox && chipsBlock) {
      const activeChips = BP_CHIPS.filter(([name]) => bpChecked(name));
      if (activeChips.length > 0) {
        chipsBox.innerHTML = activeChips.map(([n, l]) => '<span class="bp-chip on">' + l + '</span>').join('');
        chipsBlock.style.display = '';
      } else {
        chipsBlock.style.display = 'none';
      }
    }

    // ── DPE / GES ──
    const dpe = bpVal('dpe_classe');
    const ges = bpVal('ges_classe');
    const dpeBlock = document.getElementById('bp-dpe-block');
    if (dpeBlock) {
      if (dpe || ges) {
        dpeBlock.style.display = '';
        const dpeEl = document.getElementById('bp-dpe');
        const gesEl = document.getElementById('bp-ges');
        if (dpeEl) { dpeEl.textContent = dpe || '—'; dpeEl.className = 'bp-dpe-pill ' + (dpe || 'empty'); }
        if (gesEl) { gesEl.textContent = ges || '—'; gesEl.className = 'bp-dpe-pill ' + (ges || 'empty'); }
      } else {
        dpeBlock.style.display = 'none';
      }
    }

    // ── Description brute ──
    const desc = bpVal('description');
    const descEl = document.getElementById('bp-desc');
    if (descEl) {
      if (desc) {
        descEl.textContent = desc;
      } else {
        descEl.innerHTML = '<span class="bp-empty">Vos notes brutes apparaîtront ici.</span>';
      }
    }

    // ── Annonce IA (si générée) ──
    const iaText = (document.getElementById('ba-texte-ia') || {}).value || '';
    const iaBlockBp = document.getElementById('bp-ia-block');
    const iaTextBp = document.getElementById('bp-ia-text');
    const iaPointsBp = document.getElementById('bp-ia-points');
    if (iaBlockBp) {
      if (iaText.trim()) {
        iaBlockBp.style.display = '';
        if (iaTextBp) iaTextBp.textContent = iaText;
        const sourceList = document.getElementById('ba-ia-points-list');
        if (sourceList && iaPointsBp && sourceList.children.length > 0) {
          iaPointsBp.innerHTML = sourceList.innerHTML;
          iaPointsBp.style.display = '';
        } else if (iaPointsBp) {
          iaPointsBp.style.display = 'none';
        }
      } else {
        iaBlockBp.style.display = 'none';
      }
    }

    // ── Photos ──
    const photoCount = (typeof window.__baPhotoCount === 'function') ? window.__baPhotoCount() : 0;
    const photosBlock = document.getElementById('bp-photos-block');
    if (photosBlock) {
      if (photoCount > 0) {
        photosBlock.style.display = '';
        const txt = document.getElementById('bp-photos-text');
        if (txt) txt.innerHTML = '<strong>' + photoCount + '</strong> photo' + (photoCount > 1 ? 's' : '') + ' prête' + (photoCount > 1 ? 's' : '') + ' à uploader';
      } else {
        photosBlock.style.display = 'none';
      }
    }

    // ── Score complétude ──
    const checks = {
      type:    !!typeSel && desig.length >= 50,
      loc:     !!(ville && cp && adr1),
      surf:    !!(surf && pieces),
      prix:    !!(trans && (parseFloat(bpVal('annonce_prix_vente') || bpVal('annonce_loyer') || bpVal('prix_vente') || bpVal('loyer_hc') || 0) > 0)),
      dpe:     !!(dpe && ges),
      photos:  photoCount > 0,
      desc:    desc.length >= 100,
    };
    let score = 0;
    Object.values(checks).forEach(ok => { if (ok) score += 100 / 7; });
    score = Math.round(score);
    setText('bp-score-pct', score + '%');
    const fill = document.getElementById('bp-score-fill');
    if (fill) fill.style.width = score + '%';
    Object.entries(checks).forEach(([key, ok]) => {
      const row = document.getElementById('bp-row-' + key);
      if (row) {
        row.className = 'ba-score-row ' + (ok ? 'ok' : '');
        const icon = row.querySelector('.icon');
        if (icon) icon.textContent = ok ? '✓' : '○';
      }
    });
  }

  // ════════════════════════════════════════════════════════
  // CONFORMITÉ UBIFLOW — mini-card live (page-head)
  // ════════════════════════════════════════════════════════
  // Mirroir client des règles PHP de inc/ubiflow_validator.php
  // Donne un feedback instantané pendant la saisie. La validation
  // serveur reste l'autorité finale.
  function getTypeBienCode() {
    const sel = document.querySelector('.type-card.selected');
    return sel ? (sel.dataset.type || sel.dataset.code || '').trim() : '';
  }
  function fieldFilled(name) {
    // Cherche d'abord un champ texte/number/select/textarea
    const els = document.querySelectorAll('[name="' + name + '"]');
    if (!els.length) return false;
    // Si checkbox/radio
    const first = els[0];
    if (first.type === 'checkbox' || first.type === 'radio') {
      for (const el of els) if (el.checked) return true;
      return false;
    }
    const v = (first.value || '').trim();
    return v !== '' && v !== '0';
  }
  function fieldVal(name) {
    const els = document.querySelectorAll('[name="' + name + '"]');
    if (!els.length) return '';
    const first = els[0];
    if (first.type === 'radio') {
      for (const el of els) if (el.checked) return el.value;
      return '';
    }
    return (first.value || '').trim();
  }
  function isCheckedBox(name) {
    const el = document.querySelector('input[name="' + name + '"][type="checkbox"]');
    return !!(el && el.checked);
  }

  // Liste des règles (mirroir de ubiflow_minimum_rules)
  const UBI_RULES = [
    { key: 'reference_bien',      label: 'Référence du bien' },
    { key: 'designation',         label: 'Désignation commerciale' },
    { key: 'description',         label: 'Description', minLen: 100 },
    { key: 'code_postal',         label: 'Code postal' },
    { key: 'ville',               label: 'Ville' },
    { key: 'type_bien_code',      label: 'Type de bien', custom: () => getTypeBienCode() !== '' },
    { key: 'surface_habitable',   label: 'Surface habitable',
      when: () => ['appartement','maison','immeuble'].includes(getTypeBienCode()) },
    { key: 'nb_pieces',           label: 'Nombre de pièces',
      when: () => ['appartement','maison'].includes(getTypeBienCode()) },
    { key: 'annee_construction',  label: 'Année de construction',
      when: () => !['terrain','parking','garage','box'].includes(getTypeBienCode()) },
    { key: 'nb_wc',               label: 'Nombre de WC',
      when: () => ['appartement','maison'].includes(getTypeBienCode()) },
    { key: 'annonce_transaction', label: 'Type de transaction' },
    { key: 'annonce_prix_vente',  label: 'Prix de vente',
      when: () => fieldVal('annonce_transaction') === 'vente' },
    { key: 'annonce_loyer',       label: 'Loyer mensuel',
      when: () => fieldVal('annonce_transaction') === 'location' },
    { key: 'dpe_classe',          label: 'Classe DPE' },
    { key: 'ges_classe',          label: 'Classe GES' },
    { key: 'dpe_valeur',          label: 'Valeur DPE' },
    { key: 'ges_valeur',          label: 'Valeur GES' },
    { key: 'dpe_date_realisation', label: 'Date réalisation DPE' },
    { key: 'copro_nb_lots',       label: 'Nombre de lots copro',
      when: () => isCheckedBox('bien_en_copropriete') },
    { key: 'copro_quote_part_charges', label: 'Quote-part charges copro',
      when: () => isCheckedBox('bien_en_copropriete') },
    { key: 'alur_pourcentage_honoraires_ttc', label: '% TTC honoraires acquéreur',
      when: () => fieldVal('annonce_transaction') === 'vente' && isCheckedBox('honoraires_charge_acquereur') },
    { key: 'url_tarifs_publics',  label: 'URL barème honoraires',
      when: () => fieldVal('annonce_transaction') === 'vente' },
  ];

  function confMiniSync() {
    const card = document.querySelector('.conf-mini');
    if (!card) return; // pas en mode édition

    let total = 0, passed = 0;
    const missing = [];
    UBI_RULES.forEach(rule => {
      if (rule.when && !rule.when()) return;
      total++;
      let ok;
      if (rule.custom) {
        ok = rule.custom();
      } else if (rule.minLen) {
        const v = fieldVal(rule.key);
        ok = v.length >= rule.minLen;
      } else {
        ok = fieldFilled(rule.key);
      }
      if (ok) passed++;
      else missing.push(rule.label);
    });

    const score = total > 0 ? Math.round((passed / total) * 100) : 100;
    const isDraft = card.classList.contains('draft');

    // Score + barre
    const scoreEl = card.querySelector('.conf-mini-score');
    if (scoreEl) scoreEl.textContent = score + '%';
    const barEl = card.querySelector('.conf-mini-bar > span');
    if (barEl) barEl.style.width = score + '%';

    // Statut + couleur (sauf en draft, on conserve la classe draft)
    if (!isDraft) {
      card.classList.remove('ok','warn','bad');
      if (missing.length === 0) card.classList.add('ok');
      else card.classList.add('bad');
    }
    const statusEl = card.querySelector('.conf-mini-status');
    if (statusEl && !isDraft) {
      statusEl.textContent = missing.length === 0 ? '✅ Diffusable' : '🚫 Non diffusable';
    }

    // Liste des manques (ou message vide)
    let listEl = card.querySelector('.conf-mini-list');
    let emptyEl = card.querySelector('.conf-mini-empty');
    if (missing.length > 0) {
      const html = missing.map(m => `<li class="miss-ubiflow" title="${m.replace(/"/g,'&quot;')}">${m}</li>`).join('');
      if (listEl) {
        listEl.innerHTML = html;
      } else {
        if (emptyEl) emptyEl.remove();
        const ul = document.createElement('ul');
        ul.className = 'conf-mini-list';
        ul.innerHTML = html;
        card.appendChild(ul);
      }
    } else {
      if (listEl) listEl.remove();
      if (!emptyEl) {
        const div = document.createElement('div');
        div.className = 'conf-mini-empty';
        div.textContent = 'Tous les champs requis sont remplis ✨';
        card.appendChild(div);
      }
    }
  }

  // Écoute tous les inputs/changes du formulaire
  const form = document.getElementById('bien-create-form');
  if (form) {
    form.addEventListener('input', () => { bpSync(); confMiniSync(); });
    form.addEventListener('change', () => { bpSync(); confMiniSync(); });
    // Hook spécial pour les boutons de type de bien (cliqués via selectType)
    document.querySelectorAll('.type-card').forEach(c => c.addEventListener('click', () => setTimeout(() => { bpSync(); confMiniSync(); }, 50)));
    // Hook pour les boutons DPE/GES (cliqués via dpe-row)
    document.querySelectorAll('.ba-dpe-btn').forEach(b => b.addEventListener('click', () => setTimeout(() => { bpSync(); confMiniSync(); }, 50)));
  }
  // Premier rendu
  bpSync();
  confMiniSync();

  // ════════════════════════════════════════════════════════
  // ONGLET DPE — Import PDF intelligent
  // ════════════════════════════════════════════════════════
  (function() {
    const trigger = document.getElementById('dpe-import-trigger');
    const input   = document.getElementById('dpe-import-file');
    const status  = document.getElementById('dpe-import-status');
    if (!trigger || !input || !status) return;

    function showStatus(html, kind) {
      status.style.display = 'block';
      const colors = {
        loading: { bg: '#f0f9ff', border: '#0ea5e9', color: '#0369a1' },
        success: { bg: '#dcfce7', border: '#16a34a', color: '#14532d' },
        warning: { bg: '#fef3c7', border: '#f59e0b', color: '#92400e' },
        error:   { bg: '#fee2e2', border: '#dc2626', color: '#991b1b' },
      };
      const c = colors[kind] || colors.loading;
      status.style.background = c.bg;
      status.style.borderLeft = '4px solid ' + c.border;
      status.style.color = c.color;
      status.innerHTML = html;
    }

    // Helper : applique une valeur à un champ form (input/select/hidden + DPE buttons + type-card)
    function applyValue(name, value) {
      if (value === null || value === '' || value === undefined) return false;

      // Champs spéciaux internes à ignorer (alertes affichées séparément)
      if (name.startsWith('_alerte_')) return false;

      // Cas spécial : palette DPE/GES
      if (name === 'dpe_classe' || name === 'ges_classe') {
        const hidden = document.getElementById(name);
        if (hidden) hidden.value = value;
        document.querySelectorAll(`.ba-dpe-btn[data-field="${name}"]`).forEach(b => {
          b.classList.toggle('selected', b.dataset.dpe === value);
        });
        return true;
      }

      // Cas spécial : type_bien (cartes cliquables)
      if (name === 'type_bien') {
        const hidden = document.getElementById('type_bien_hidden');
        if (hidden) hidden.value = value;
        document.querySelectorAll('.type-card').forEach(c => {
          c.classList.toggle('selected', (c.dataset.value || '').toLowerCase() === String(value).toLowerCase());
        });
        return true;
      }

      // Cas standard
      const els = document.querySelectorAll(`[name="${name}"]`);
      if (!els.length) return false;
      const first = els[0];
      if (first.type === 'checkbox') {
        first.checked = !!value;
      } else if (first.type === 'radio') {
        for (const el of els) el.checked = (el.value == value);
      } else {
        first.value = value;
      }
      // Effet visuel : highlight vert
      first.style.transition = 'background .3s';
      first.style.background = '#dcfce7';
      setTimeout(() => { first.style.background = ''; }, 1500);
      return true;
    }

    trigger.addEventListener('click', () => input.click());

    input.addEventListener('change', async (e) => {
      const file = e.target.files && e.target.files[0];
      if (!file) return;

      if (file.size > 20 * 1024 * 1024) {
        showStatus('❌ Fichier trop volumineux (max 20 Mo)', 'error');
        return;
      }
      if (!file.name.toLowerCase().endsWith('.pdf')) {
        showStatus('❌ Format non supporté — un PDF est requis', 'error');
        return;
      }

      showStatus('⏳ Analyse du DPE en cours…', 'loading');

      const fd = new FormData();
      fd.append('fichier', file);
      // Si on est en mode édition, on transmet l'ID pour enregistrer le doc
      const editId = document.querySelector('input[name="_edit_id"]');
      if (editId && editId.value) fd.append('id_bien', editId.value);

      try {
        const resp = await fetch('api/dpe_import_upload.php', {
          method: 'POST',
          body: fd,
          headers: { 'X-CSRF-Token': (window.__bi_csrf || '') }
        });
        const data = await resp.json();

        if (!data.ok) {
          showStatus('❌ ' + (data.error || 'Erreur inconnue'), 'error');
          return;
        }

        // Applique les champs détectés
        const fields = data.fields || {};
        const applied = [];
        const skipped = [];
        const alerts = [];
        Object.entries(fields).forEach(([k, v]) => {
          // Alertes diagnostics — affichées séparément
          if (k === '_alerte_plomb')        { alerts.push('🔴 Plomb (CREP) : présence de revêtements contenant du plomb au-delà des seuils'); return; }
          if (k === '_alerte_amiante')      { alerts.push('🟠 Amiante : matériaux/produits contenant de l\'amiante repérés'); return; }
          if (k === '_alerte_electricite')  { alerts.push('🟡 Électricité : l\'installation comporte des anomalies'); return; }
          if (applyValue(k, v)) applied.push(k);
          else skipped.push(k);
        });

        // Synchro aperçu + conformité
        if (typeof bpSync === 'function') bpSync();
        if (typeof confMiniSync === 'function') confMiniSync();

        const score = data.score || 0;
        const kind  = score >= 80 ? 'success' : (score >= 40 ? 'warning' : 'error');
        const icon  = score >= 80 ? '✅' : (score >= 40 ? '⚠️' : '🚫');
        const methodBadge = data.method === 'regex+ia'
          ? '<span style="display:inline-block;padding:2px 8px;border-radius:99px;background:#e0e7ff;color:#4338ca;font-size:10px;font-weight:700;margin-left:6px;">🧠 IA GPT-4o</span>'
          : '<span style="display:inline-block;padding:2px 8px;border-radius:99px;background:#f0fdf4;color:#15803d;font-size:10px;font-weight:700;margin-left:6px;">⚡ Regex</span>';

        // Alertes API (priorité aux flags backend)
        const apiAlerts = data.alertes || {};
        if (apiAlerts.plomb && !alerts.some(a => a.includes('Plomb')))       alerts.push('🔴 Plomb (CREP) : présence de revêtements contenant du plomb au-delà des seuils');
        if (apiAlerts.amiante && !alerts.some(a => a.includes('Amiante')))   alerts.push('🟠 Amiante : matériaux contenant de l\'amiante repérés');
        if (apiAlerts.electricite && !alerts.some(a => a.includes('Élec')))  alerts.push('🟡 Électricité : l\'installation comporte des anomalies');

        const alertsHtml = alerts.length
          ? '<div style="margin-top:10px;padding:10px;background:#fff;border:1px solid #fde68a;border-radius:8px;">'
            + '<div style="font-weight:700;font-size:11px;color:#92400e;margin-bottom:6px;">⚠️ Alertes détectées dans le diagnostic :</div>'
            + alerts.map(a => '<div style="font-size:11px;color:#555;padding:2px 0;">' + a + '</div>').join('')
            + '</div>'
          : '';

        const resumeHtml = data.resume_bailleur
          ? '<div style="margin-top:10px;padding:12px;background:#f0f9ff;border-left:3px solid #0ea5e9;border-radius:8px;font-size:12px;color:#0c4a6e;">'
            + '<div style="font-weight:700;margin-bottom:6px;">📋 Résumé pour le propriétaire / bailleur (généré par IA)</div>'
            + '<div style="line-height:1.5;">' + data.resume_bailleur.replace(/\n/g, '<br>') + '</div>'
            + '<div style="margin-top:8px;font-size:10px;color:#0369a1;">💡 Modifiable et envoyable depuis la fiche du bien.</div>'
            + '</div>'
          : '';

        const iaErrorHtml = data.ia_error
          ? '<div style="margin-top:8px;padding:8px;background:#fef2f2;border-left:3px solid #dc2626;border-radius:6px;font-size:11px;color:#991b1b;">'
            + '⚠️ IA non disponible : ' + data.ia_error
            + '<br><small>Configurez OPENAI_API_KEY pour activer l\'extraction intelligente.</small>'
            + '</div>'
          : '';

        showStatus(
          icon + ' <strong>Diagnostic analysé</strong> — confiance ' + score + '%' + methodBadge
          + '<br><strong>' + applied.length + ' champ(s)</strong> renseigné(s) automatiquement'
          + '<br><small>📄 ' + (data.nom || 'document') + ' enregistré dans dpe_diags (#' + (data.diag_id || '?') + ')</small>'
          + (applied.length ? '<br><small style="color:#666;">' + applied.join(', ') + '</small>' : '')
          + alertsHtml
          + resumeHtml
          + iaErrorHtml
          + (score < 80 && !data.ia_error ? '<br><small>⚠️ Vérifiez les valeurs et complétez celles qui manquent.</small>' : ''),
          kind
        );
      } catch (err) {
        showStatus('❌ Erreur réseau : ' + err.message, 'error');
      }

      // Reset input pour pouvoir re-uploader le même fichier
      input.value = '';
    });
  })();

  // ════════════════════════════════════════════════════════
  // ONGLET PHOTOS — drag & drop + preview local (Lot 3)
  //
  // Deux sources cohabitent dans la grille :
  //   1. `existing` : photos déjà en BDD (biens_photos) — chargées en mode
  //      édition via data-existing sur #ba-photo-grid. Chaque élément a un
  //      id BDD et une URL absolue. Suppression via api/bien_photo_delete.php.
  //   2. `queue` : photos ajoutées dans cette session (File objects),
  //      sérialisées dans input[name=photos[]] pour le submit.
  //
  // Les deux sont rendues dans l'ordre existing puis queue ; la 1ère photo
  // (quelle que soit sa source) porte le badge PRINCIPALE.
  // ════════════════════════════════════════════════════════
  (function() {
    const dropZone = document.getElementById('ba-photo-drop');
    const fileInput = document.getElementById('ba-photo-input');
    const grid = document.getElementById('ba-photo-grid');
    const tabCount = document.getElementById('ba-tab-count-photos');
    if (!dropZone || !fileInput || !grid) return;

    const MAX_SIZE = 10 * 1024 * 1024; // 10 Mo
    const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];

    // Photos déjà en BDD (biens_photos) — injectées par PHP en mode édition
    let existing = [];
    try {
      const raw = grid.getAttribute('data-existing') || '[]';
      existing = JSON.parse(raw) || [];
    } catch (_) { existing = []; }

    // Queue centrale : array de File objects, miroir de l'input.files
    let queue = [];

    // Jeton CSRF pour les suppressions d'existantes
    const csrfToken = (document.querySelector('input[name="csrf_token"]') || {}).value || '';

    function totalCount() { return existing.length + queue.length; }

    function refreshInputFromQueue() {
      // Reconstruit un DataTransfer pour mettre à jour input.files (compatible submit)
      const dt = new DataTransfer();
      queue.forEach(f => dt.items.add(f));
      fileInput.files = dt.files;
      // Mise à jour du compteur sur l'onglet (existing + queue)
      if (tabCount) {
        const n = totalCount();
        if (n > 0) {
          tabCount.textContent = n;
          tabCount.style.display = '';
        } else {
          tabCount.style.display = 'none';
        }
      }
      // Trigger sync de la card preview + refresh du sélecteur Annonce
      bpSync();
      if (typeof window.__baSelectorRefresh === 'function') {
        window.__baSelectorRefresh();
      }
    }

    function makeThumb(globalIdx, imgSrc, { nom, kind, dbId, fileIdx, categorie, descriptionIa } = {}) {
      // Cellule wrapper : thumb + légende analyse IA (si présente)
      const cell = document.createElement('div');
      cell.className = 'ba-photo-cell';

      const div = document.createElement('div');
      div.className = 'ba-photo-thumb';
      div.dataset.idx = globalIdx;
      if (kind === 'existing') div.dataset.kind = 'existing';

      // Numéro
      const num = document.createElement('span');
      num.className = 'ba-photo-thumb-num';
      num.textContent = (globalIdx + 1).toString().padStart(2, '0');
      div.appendChild(num);

      // Badge "Principal" sur la 1ère (quelle que soit la source)
      if (globalIdx === 0) {
        const main = document.createElement('span');
        main.className = 'ba-photo-thumb-main';
        main.textContent = 'PRINCIPALE';
        div.appendChild(main);
      }

      // Badge discret "déjà en bibliothèque" pour les existantes
      if (kind === 'existing') {
        const tag = document.createElement('span');
        tag.className = 'ba-photo-thumb-main';
        tag.textContent = '✓ ENREGISTRÉE';
        tag.style.cssText = 'left:6px;right:auto;top:26px;background:#15803d;';
        div.appendChild(tag);
      }

      // Image — pour 'new', le FileReader remplira le src plus tard
      const img = document.createElement('img');
      img.alt = nom || '';
      if (imgSrc) img.src = imgSrc;
      div.appendChild(img);

      // Bouton suppression
      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'ba-photo-thumb-del';
      del.innerHTML = '×';
      del.title = kind === 'existing'
        ? 'Supprimer cette photo de la bibliothèque'
        : 'Retirer cette photo';

      // Bouton "réanalyser" (uniquement pour les existantes)
      if (kind === 'existing') {
        const reBtn = document.createElement('button');
        reBtn.type = 'button';
        reBtn.className = 'ba-photo-thumb-reanalyze';
        reBtn.innerHTML = '🔎';
        reBtn.title = descriptionIa ? 'Réanalyser cette photo' : 'Analyser cette photo';
        reBtn.style.cssText = 'position:absolute;bottom:6px;left:6px;width:24px;height:24px;border-radius:50%;background:rgba(106,76,168,0.92);color:#fff;border:none;font-size:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 6px #f7f8fa;';
        reBtn.addEventListener('click', (ev) => {
          ev.stopPropagation();
          if (typeof window.__baAnalyzeSinglePhoto === 'function') {
            window.__baAnalyzeSinglePhoto(dbId, reBtn);
          }
        });
        div.appendChild(reBtn);
      }

      del.addEventListener('click', async (ev) => {
        ev.stopPropagation();
        if (kind === 'existing') {
          if (!confirm('Supprimer définitivement cette photo de la bibliothèque du bien ?')) return;
          del.disabled = true;
          try {
            const fd = new FormData();
            fd.append('id_photo', String(dbId));
            fd.append('csrf_token', csrfToken);
            const resp = await fetch('<?= h(app_url("/api/bien_photo_delete.php")) ?>', {
              method: 'POST',
              body: fd,
              credentials: 'same-origin',
            });
            const j = await resp.json();
            if (!j || !j.ok) throw new Error(j && j.error ? j.error : 'Échec suppression');
            // Retire de l'array existing
            existing = existing.filter(p => p.id !== dbId);
            refreshInputFromQueue();
            renderGrid();
            // Rafraîchit aussi le récap dans l'onglet Description
            if (typeof window.__baRenderPhotoRecap === 'function') window.__baRenderPhotoRecap();
          } catch (err) {
            alert('⚠ ' + (err.message || 'Erreur'));
            del.disabled = false;
          }
        } else {
          // Photo nouvelle (queue) — suppression locale
          queue.splice(fileIdx, 1);
          refreshInputFromQueue();
          renderGrid();
        }
      });
      div.appendChild(del);

      cell.appendChild(div);

      // Légende analyse IA sous la thumb (uniquement pour les existantes analysées)
      if (kind === 'existing' && (categorie || descriptionIa)) {
        const cap = document.createElement('div');
        cap.className = 'ba-photo-caption';
        if (categorie) {
          const catEl = document.createElement('span');
          catEl.className = 'ba-photo-caption-cat';
          catEl.textContent = String(categorie).replace(/_/g, ' ');
          cap.appendChild(catEl);
          cap.appendChild(document.createElement('br'));
        }
        if (descriptionIa) {
          const txt = document.createElement('em');
          txt.textContent = descriptionIa;
          cap.appendChild(txt);
        }
        cell.appendChild(cap);
      }

      return cell;
    }

    function renderGrid() {
      grid.innerHTML = '';
      let globalIdx = 0;

      // 1. Photos existantes (BDD) — triées par `ordre` croissant
      existing
        .slice()
        .sort((a, b) => (a.ordre || 0) - (b.ordre || 0))
        .forEach((p) => {
          grid.appendChild(makeThumb(globalIdx++, p.url, {
            nom: p.nom,
            kind: 'existing',
            dbId: p.id,
            categorie: p.categorie,
            descriptionIa: p.description_ia,
          }));
        });

      // 2. Nouvelles photos (queue File)
      queue.forEach((file, fileIdx) => {
        const cell = makeThumb(globalIdx++, '', {
          nom: file.name,
          kind: 'new',
          fileIdx: fileIdx,
        });
        // Remplace la source via FileReader (async) — img est dans la cell
        const img = cell.querySelector('img');
        const reader = new FileReader();
        reader.onload = (e) => { if (img) img.src = e.target.result; };
        reader.readAsDataURL(file);
        grid.appendChild(cell);
      });
    }

    function addFiles(files) {
      const errs = [];
      Array.from(files).forEach(f => {
        if (!ALLOWED.includes(f.type)) {
          errs.push(f.name + ' : format non supporté');
          return;
        }
        if (f.size > MAX_SIZE) {
          errs.push(f.name + ' : trop volumineux (' + Math.round(f.size / 1024 / 1024) + ' Mo > 10 Mo)');
          return;
        }
        // Évite les doublons par nom + taille
        const dup = queue.find(q => q.name === f.name && q.size === f.size);
        if (dup) return;
        queue.push(f);
      });
      if (errs.length) alert('⚠ Photos refusées :\n' + errs.join('\n'));
      refreshInputFromQueue();
      renderGrid();
    }

    // Click sur la drop zone → ouvre le sélecteur de fichiers
    dropZone.addEventListener('click', (e) => {
      // Évite les clicks sur la grid de déclencher l'input
      if (e.target.closest('.ba-photo-thumb')) return;
      fileInput.click();
    });

    // Sélection via dialog
    fileInput.addEventListener('change', (e) => {
      addFiles(e.target.files);
    });

    // Drag & drop
    dropZone.addEventListener('dragover', (e) => {
      e.preventDefault();
      dropZone.classList.add('drag-over');
    });
    dropZone.addEventListener('dragleave', (e) => {
      if (e.target === dropZone) dropZone.classList.remove('drag-over');
    });
    dropZone.addEventListener('drop', (e) => {
      e.preventDefault();
      dropZone.classList.remove('drag-over');
      if (e.dataTransfer && e.dataTransfer.files) addFiles(e.dataTransfer.files);
    });

    // Expose le compte de photos pour bpSync (badge dans la preview)
    window.__baPhotoCount = () => totalCount();
    // Expose la queue pour le sélecteur Annonce (lecture uniquement)
    window.__baPhotoQueue = () => queue.slice();

    // Rendu initial : affiche les photos déjà en BDD (mode édition)
    // + met à jour le badge de l'onglet si existing.length > 0
    if (existing.length > 0) {
      refreshInputFromQueue();
      renderGrid();
    }

    // ════════════════════════════════════════════════════════
    // ANALYSE PHOTOS — batch + per-thumb (Vision GPT-4o)
    // ════════════════════════════════════════════════════════
    const editMatchPA = window.location.search.match(/[?&]edit=(\d+)/);
    const bienIdForAnalyze = editMatchPA ? parseInt(editMatchPA[1], 10) : 0;

    async function analyzePhotos({ force = false } = {}) {
      if (bienIdForAnalyze <= 0) {
        alert('Sauvegardez d\'abord le bien une fois — l\'analyse nécessite un ID de bien.');
        return;
      }
      const batchBtn = document.getElementById('ba-photo-analyze-batch');
      const forceBtn = document.getElementById('ba-photo-analyze-force');
      const target = force ? forceBtn : batchBtn;
      if (target) { target.disabled = true; target.innerHTML = '⏳ Analyse en cours...'; }

      try {
        const fd = new FormData();
        fd.append('id_bien', String(bienIdForAnalyze));
        fd.append('csrf_token', csrfToken);
        if (force) fd.append('force', '1');
        const resp = await fetch('<?= h(app_url("/api/bien_photo_analyze.php")) ?>', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin',
        });
        const j = await resp.json();
        if (!j || !j.ok) throw new Error((j && j.error) || 'Échec de l\'analyse');

        // Merge : met à jour `existing[]` avec les résultats
        (j.results || []).forEach(r => {
          if (!r.ok) return;
          const idx = existing.findIndex(p => p.id === r.id);
          if (idx >= 0) {
            existing[idx].categorie = r.categorie;
            existing[idx].description_ia = r.description;
          }
        });
        renderGrid();
        if (typeof window.__baRenderPhotoRecap === 'function') window.__baRenderPhotoRecap();

        const msg = `✓ ${j.analysed} photo(s) analysée(s)` + (j.skipped ? `, ${j.skipped} ignorée(s)` : '');
        if (target) { target.innerHTML = msg; setTimeout(() => { if (target) target.innerHTML = force ? '↻ Tout réanalyser' : '🔎 Analyser les photos non analysées'; target.disabled = false; }, 2500); }

        if (!j.ia_cols_ok) {
          alert('⚠ Les analyses n\'ont pas été sauvegardées : la migration SQL (migration_biens_photos_ia.sql) n\'est pas encore passée.');
        }
      } catch (err) {
        alert('❌ ' + (err.message || 'inconnue'));
        if (target) { target.disabled = false; target.innerHTML = force ? '↻ Tout réanalyser' : '🔎 Analyser les photos non analysées'; }
      }
    }

    const batchBtn = document.getElementById('ba-photo-analyze-batch');
    const forceBtn = document.getElementById('ba-photo-analyze-force');
    if (batchBtn) batchBtn.addEventListener('click', () => analyzePhotos({ force: false }));
    if (forceBtn) forceBtn.addEventListener('click', () => analyzePhotos({ force: true }));

    // Expose une fonction par-photo réutilisable (bouton 🔎 sur chaque thumb)
    window.__baAnalyzeSinglePhoto = async function (photoId, btnEl) {
      if (!photoId) return;
      const prev = btnEl ? btnEl.innerHTML : null;
      if (btnEl) { btnEl.disabled = true; btnEl.innerHTML = '⏳'; }
      try {
        const fd = new FormData();
        fd.append('id_photo', String(photoId));
        fd.append('csrf_token', csrfToken);
        const resp = await fetch('<?= h(app_url("/api/bien_photo_analyze.php")) ?>', {
          method: 'POST', body: fd, credentials: 'same-origin',
        });
        const j = await resp.json();
        if (!j || !j.ok) throw new Error((j && j.error) || 'Échec');
        const r = (j.results || [])[0] || {};
        if (r.ok) {
          const idx = existing.findIndex(p => p.id === photoId);
          if (idx >= 0) {
            existing[idx].categorie = r.categorie;
            existing[idx].description_ia = r.description;
          }
          renderGrid();
        } else {
          throw new Error(r.error || 'Échec');
        }
      } catch (err) {
        alert('❌ ' + (err.message || 'inconnue'));
        if (btnEl) { btnEl.disabled = false; btnEl.innerHTML = prev || '🔎'; }
      }
    };
  })();

  // ════════════════════════════════════════════════════════
  // SÉLECTEUR PHOTOS POUR ANNONCE — onglet Annonce (Lot 3+)
  // ════════════════════════════════════════════════════════
  (function() {
    const grid = document.getElementById('ba-photo-selector-grid');
    const empty = document.getElementById('ba-photo-selector-empty');
    const info = document.getElementById('ba-photo-selector-info');
    const countEl = document.getElementById('ba-photo-selector-count');
    const hidden = document.getElementById('photos-annonce-selection');
    if (!grid || !empty || !hidden) return;

    const MAX_SEL = 7;
    // Sélection : array d'indices de la queue dans l'ordre choisi
    let selection = [];
    // Cache des dataURL pour éviter de re-lire les fichiers à chaque refresh
    const previewCache = new Map(); // queueIndex (number) → dataURL

    function syncHidden() {
      hidden.value = selection.join(',');
      if (countEl) countEl.textContent = selection.length;
      const infoSpan = info.querySelector('span:first-child');
      if (infoSpan) {
        if (selection.length >= MAX_SEL) {
          infoSpan.innerHTML = '<strong class="full">' + selection.length + '</strong> photo(s) sélectionnée(s) — maximum atteint';
        } else {
          infoSpan.innerHTML = '<strong>' + selection.length + '</strong> photo(s) sélectionnée(s) sur ' + MAX_SEL + ' max';
        }
      }
    }

    function readFileAsDataURL(file, idx) {
      return new Promise((resolve) => {
        if (previewCache.has(idx)) { resolve(previewCache.get(idx)); return; }
        const r = new FileReader();
        r.onload = (e) => { previewCache.set(idx, e.target.result); resolve(e.target.result); };
        r.onerror = () => resolve('');
        r.readAsDataURL(file);
      });
    }

    function refresh() {
      const queue = (typeof window.__baPhotoQueue === 'function') ? window.__baPhotoQueue() : [];

      // Nettoie la sélection des indices qui n'existent plus dans la queue
      selection = selection.filter(idx => idx < queue.length);

      // Nettoie le cache des indices supprimés
      const toDelete = [];
      previewCache.forEach((_, key) => { if (key >= queue.length) toDelete.push(key); });
      toDelete.forEach(k => previewCache.delete(k));

      if (queue.length === 0) {
        grid.style.display = 'none';
        info.style.display = 'none';
        empty.style.display = '';
        syncHidden();
        return;
      }

      empty.style.display = 'none';
      grid.style.display = '';
      info.style.display = '';
      grid.innerHTML = '';

      queue.forEach((file, idx) => {
        const div = document.createElement('div');
        div.className = 'ba-photo-pick';
        div.dataset.idx = idx;

        const img = document.createElement('img');
        img.alt = file.name;
        readFileAsDataURL(file, idx).then(url => { img.src = url; });
        div.appendChild(img);

        const orderBadge = document.createElement('span');
        orderBadge.className = 'ba-photo-pick-order';
        orderBadge.textContent = '';
        div.appendChild(orderBadge);

        const checkBadge = document.createElement('span');
        checkBadge.className = 'ba-photo-pick-check';
        checkBadge.innerHTML = '✓';
        div.appendChild(checkBadge);

        // Application de l'état de sélection
        const rank = selection.indexOf(idx);
        if (rank >= 0) {
          div.classList.add('selected');
          div.dataset.rank = (rank + 1).toString();
          orderBadge.textContent = (rank + 1).toString();
        }

        div.addEventListener('click', () => {
          const r = selection.indexOf(idx);
          if (r >= 0) {
            // Désélectionne
            selection.splice(r, 1);
          } else {
            if (selection.length >= MAX_SEL) {
              alert('⚠ Maximum ' + MAX_SEL + ' photos par annonce. Désélectionnez-en une avant.');
              return;
            }
            selection.push(idx);
          }
          refresh();
        });

        grid.appendChild(div);
      });

      syncHidden();
    }

    // Expose pour rafraîchissement externe (depuis la queue Photos)
    window.__baSelectorRefresh = refresh;

    // Refresh à chaque switch d'onglet vers Annonce (au cas où la queue a changé)
    document.querySelectorAll('.ba-tab').forEach(tab => {
      tab.addEventListener('click', () => {
        if (tab.dataset.tab === 'annonce') setTimeout(refresh, 50);
      });
    });

    // Premier rendu
    refresh();
  })();

  // ════════════════════════════════════════════════════════
  // GÉNÉRATION IA — onglet Description (Lot 4)
  // ════════════════════════════════════════════════════════
  window.bienGenerateIA = async function () {
    const btn = document.getElementById('ba-ia-btn');
    const statusEl = document.getElementById('ba-ia-status');
    const textarea = document.getElementById('ba-texte-ia');
    const pointsBlock = document.getElementById('ba-ia-points-block');
    const pointsList = document.getElementById('ba-ia-points-list');

    // Validation pré-requis
    const typeSel = document.querySelector('.type-card.selected');
    const desigEl = document.querySelector('input[name="designation"]');
    const villeEl = document.querySelector('input[name="ville"]');
    if (!typeSel) {
      alert('Sélectionnez d\'abord un type de bien (onglet Identification).');
      return;
    }
    if (!desigEl || !desigEl.value || desigEl.value.length < 10) {
      alert('Saisissez d\'abord une désignation commerciale (onglet Identification).');
      return;
    }

    // Helpers de lecture
    const v = (name) => {
      const el = document.querySelector('[name="' + name + '"]');
      return el ? el.value : '';
    };
    const checked = (name) => {
      const el = document.querySelector('[name="' + name + '"]');
      return el && el.checked ? 1 : 0;
    };

    // État UI
    btn.disabled = true;
    btn.innerHTML = '⏳ L\'IA rédige...';
    statusEl.className = 'ba-ia-status';
    statusEl.textContent = 'Analyse des données + rédaction en cours (15-30 secondes)...';

    // Construction du payload
    const trans = (document.querySelector('input[name="annonce_transaction"]:checked') || {}).value || '';
    // bien_id : récupéré depuis l'URL en mode édition (?edit=X) ; permet au
    // serveur d'enrichir le contexte avec toutes les colonnes du bien + les
    // analyses Vision déjà calculées sur les photos en bibliothèque.
    const editMatch = window.location.search.match(/[?&]edit=(\d+)/);
    const bienId = editMatch ? parseInt(editMatch[1], 10) : 0;

    const payload = {
      bien_id:     bienId,
      type_bien:   typeSel.dataset.value || '',
      adresse_1:   v('adresse_1'),
      code_postal: v('code_postal'),
      ville:       v('ville'),
      surface:     parseFloat(v('surface_habitable')) || 0,
      nb_pieces:   parseInt(v('nb_pieces')) || 0,
      nb_chambres: parseInt(v('nb_chambres')) || 0,
      nb_sdb:      parseInt(v('nb_salles_bain')) || 0,
      etage:       parseInt(v('etage')) || 0,
      nb_etages:   parseInt(v('nb_niveaux')) || 0,
      loyer_hc:    trans === 'location' ? (parseFloat(v('annonce_loyer') || v('loyer_hc')) || 0) : 0,
      charges:     parseFloat(v('charges_locatives') || v('charges')) || 0,
      prix_vente:  trans === 'vente' ? (parseFloat(v('annonce_prix_vente') || v('prix_vente')) || 0) : 0,
      dpe_classe:  v('dpe_classe'),
      ges_classe:  v('ges_classe'),
      meuble:      checked('meuble'),
      ascenseur:   checked('ascenseur'),
      parking:     checked('garage') || checked('parking'),
      balcon:      checked('balcon'),
      terrasse:    checked('terrasse'),
      cave:        checked('cave'),
      digicode:    checked('digicode'),
      fibre:       checked('fibre'),
      // Description brute fournie comme contexte additionnel
      description_brute: v('description'),
    };

    try {
      const r = await fetch('api/bien_ai_generate.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });
      const data = await r.json();
      if (!data.ok) {
        const err = data.error || 'inconnue';
        throw new Error(err);
      }

      // Remplit le textarea IA
      if (textarea) textarea.value = data.description || '';

      // Points forts (array)
      if (Array.isArray(data.points_forts) && data.points_forts.length && pointsList && pointsBlock) {
        pointsList.innerHTML = '';
        data.points_forts.forEach(pt => {
          const li = document.createElement('li');
          li.textContent = pt;
          pointsList.appendChild(li);
        });
        pointsBlock.style.display = '';
        // Met aussi à jour le champ texte points_forts existant (joint par virgules)
        const pfInput = document.querySelector('input[name="points_forts"]');
        if (pfInput && !pfInput.value) {
          pfInput.value = data.points_forts.join(', ');
        }
      }

      // Pré-remplit titre SEO et meta description s'ils sont vides
      if (data.meta_title) {
        const tsEl = document.querySelector('input[name="titre_seo"]');
        if (tsEl && !tsEl.value) tsEl.value = data.meta_title;
      }
      if (data.meta_description) {
        const mdEl = document.querySelector('textarea[name="meta_description"]');
        if (mdEl && !mdEl.value) mdEl.value = data.meta_description;
      }

      // Statut succès
      statusEl.className = 'ba-ia-status ok';
      const len = (data.description || '').length;
      statusEl.innerHTML = '✓ Annonce générée avec succès — ' + len + ' caractères';

      // Refresh preview card
      if (typeof bpSync === 'function') bpSync();
    } catch (e) {
      statusEl.className = 'ba-ia-status err';
      statusEl.textContent = '❌ Erreur : ' + (e.message || 'inconnue');
    } finally {
      btn.disabled = false;
      btn.innerHTML = '✨ Régénérer l\'annonce';
    }
  };
})();
</script>

<?php
// Récupérer la clé Google Maps
$GOOGLE_MAPS_API_KEY = $GOOGLE_MAPS_API_KEY ?? '';
?>
<script>
window.__bi_csrf = '<?= csrf_token('ajouter_bien') ?>';
window.__bi_base = '<?= rtrim(asset_url('/'), '/') ?>';
</script>
<script src="<?= h(asset_url('/js/bien_import.js')) ?>"></script>
<script src="<?= h(asset_url('/js/minicard.js')) ?>"></script>
<script src="<?= h(asset_url('/js/places.js')) ?>"></script>
<?php if ($GOOGLE_MAPS_API_KEY !== ''): ?>
<script
  src="https://maps.googleapis.com/maps/api/js?key=<?= h($GOOGLE_MAPS_API_KEY) ?>&libraries=places&callback=initPlacesAutocomplete"
  async defer
></script>
<?php else: ?>
<script>
// Pas de clé Google Maps — l'autocomplétion fonctionne uniquement via la BDD locale
document.addEventListener('DOMContentLoaded', function () {
  if (typeof window.initPlacesAutocomplete === 'function') {
    window.initPlacesAutocomplete();
  }
});
</script>
<?php endif; ?>
</body>
</html>
