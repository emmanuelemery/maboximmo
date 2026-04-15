<?php
declare(strict_types=1);

/**
 * MAPPING BASE DE DONNÉES → FLUX UBIFLOW (Le Bon Coin, SeLoger, etc.)
 *
 * Ce fichier centralise :
 *   1. La correspondance entre les codes internes (types_bien.code, type_transaction)
 *      et les valeurs attendues par Ubiflow (code_type numérique, prestation.type).
 *   2. Une fonction build_ubiflow_annonce() qui, à partir d'une ligne SQL jointe
 *      (annonces + biens + dpe_diags), produit un tableau associatif représentant
 *      la structure d'une <annonce> Ubiflow, prêt à être sérialisé en XML.
 *
 * Les clés du tableau retourné correspondent EXACTEMENT aux noms de balises
 * Ubiflow (cf. dictionnaire de données). Les valeurs null/chaîne vide sont
 * filtrées au moment de la sérialisation pour garder un XML propre.
 *
 * @see Dictionnaire_donnees_immo (Ubiflow)
 * @see Format_Integration_Standard_Immo_XML (Ubiflow)
 */

// =====================================================================
// 1. TYPE DE BIEN : code interne → code_type Ubiflow
// =====================================================================
// Référence : https://sw.ubiflow.net/types_objets.php?univers=IMMO&filiation=O
const UBIFLOW_CODE_TYPE = [
    // Habitation particuliers
    'appartement'      => 1100,
    'maison'           => 1200,
    'loft'             => 1100, // Loft : code appartement (sous-catégorie ensuite)
    'duplex'           => 1100,
    'triplex'           => 1100,
    'studio'           => 1100,
    'chateau'          => 1200, // Château : code maison
    'ferme'            => 1200,
    'chalet'           => 1200,
    'villa'            => 1200,
    'mas'              => 1200,
    'peniche'          => 1200, // Atypique : code maison par défaut
    'terrain'          => 1300,
    'terrain_agricole' => 1300,
    'immeuble'         => 1500,

    // Professionnel / Commerce
    'local_commercial' => 2300, // Local commercial
    'commerce'         => 2300,
    'bureau'           => 2400, // Bureaux
    'bureaux'          => 2400,
    'entrepot'         => 2100, // Entrepôt / Local d'activité
    'atelier'          => 2200, // Local d'activité
    'local_activite'   => 2200, // Local d'activité
    'fonds_commerce'   => 2000, // Bien professionnel (la nature "fonds" vient de prestation.type=F)
    'droit_bail'       => 2000, // idem (prestation.type=B)

    // Annexes
    'garage'           => 3200, // Garage / box
    'parking'          => 3100, // Parking
    'box'              => 3200,
    'cave'             => 3300, // Cave
];

// =====================================================================
// 2. TYPE DE TRANSACTION : code interne → prestation.type Ubiflow
// =====================================================================
// V=Vente, L=Location, S=Saisonnière, F=Fonds commerce, B=Cession bail,
// W=Viager, G=Vente de neuf
const UBIFLOW_PRESTATION_TYPE = [
    'vente'            => 'V',
    'achat'            => 'V',
    'location'         => 'L',
    'location_annuelle'=> 'L',
    'saisonniere'      => 'S',
    'location_saisonniere' => 'S',
    'vacances'         => 'S',
    'viager'           => 'W',
    'cession_bail'     => 'B',
    'fonds_commerce'   => 'F',
    'vente_fonds'      => 'F',
    'neuf'             => 'G',
    'vefa'             => 'G',
];

// =====================================================================
// 3. HELPERS DE FORMATAGE
// =====================================================================

/**
 * Booléen PHP/SQL → Ubiflow : 'O' (oui), 'N' (non), null (ignoré).
 * Une valeur null ou chaîne vide retourne null (balise non émise).
 */
function ubi_bool($v): ?string
{
    if ($v === null || $v === '' ) return null;
    if (is_string($v)) {
        $v = strtolower(trim($v));
        if (in_array($v, ['o', 'oui', '1', 'true', 'y', 'yes'], true)) return 'O';
        if (in_array($v, ['n', 'non', '0', 'false'], true)) return 'N';
        return null;
    }
    return ((int) $v) === 1 ? 'O' : 'N';
}

/**
 * Date MySQL (YYYY-MM-DD ou DATETIME) → format Ubiflow jj/mm/aaaa.
 * Retourne null si la date est vide/invalide.
 */
function ubi_date($v): ?string
{
    if (empty($v) || $v === '0000-00-00' || str_starts_with((string) $v, '0000-00-00')) {
        return null;
    }
    $ts = strtotime((string) $v);
    return $ts ? date('d/m/Y', $ts) : null;
}

/**
 * Valeur numérique → string sans décimale parasite. null/0 → null par défaut
 * (Ubiflow ignore les 0). Passer $allowZero=true pour forcer.
 */
function ubi_num($v, bool $allowZero = false): ?string
{
    if ($v === null || $v === '') return null;
    $f = (float) $v;
    if (!$allowZero && $f == 0.0) return null;
    // Entier si pas de décimale, sinon 2 décimales max
    return (floor($f) == $f) ? (string) (int) $f : number_format($f, 2, '.', '');
}

/**
 * Chaîne nettoyée (trim + suppression caractères de contrôle + strip HTML).
 *
 * CORRECTION #1 (audit V2 2026-04-11) : strip_tags + html_entity_decode
 * pour respecter la contrainte Ubiflow qui REJETTE tout balisage HTML
 * dans les champs texte libres (<texte>, <titre>, etc.). Les descriptions
 * saisies via WYSIWYG dans le back-office contiennent <p>, <br>, <strong>,
 * &nbsp;, etc. — tout est aplati en texte brut.
 */
function ubi_str($v): ?string
{
    if ($v === null) return null;
    $s = trim((string) $v);
    if ($s === '') return null;
    // Strip HTML + décode entités (&nbsp; → espace, &amp; → &, …)
    $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Normalise les fins de ligne et limite les sauts consécutifs à 2
    $s = preg_replace("/\r\n|\r/", "\n", $s);
    $s = preg_replace("/\n{3,}/", "\n\n", $s);
    // Re-trim après strip (un <p> vide laisse parfois du whitespace)
    return trim($s) ?: null;
}

// =====================================================================
// 4. CONSTRUCTION DE L'ANNONCE UBIFLOW
// =====================================================================

/**
 * Transforme une ligne SQL jointe (annonces + biens + type_bien) en
 * tableau associatif structuré pour la génération XML Ubiflow.
 *
 * Structure attendue en entrée ($row) : toutes les colonnes de `annonces`
 * préfixées `a_` et celles de `biens` préfixées `b_` + `t_code` (type_bien.code),
 * `dpe_date_diagnostic`, `dpe_conso_primaire`, `dpe_conso_finale`,
 * `dpe_depenses_min`, `dpe_depenses_max`, `dpe_date_indice_prix` (issues de
 * dpe_diags sur le diag principal, via LEFT JOIN).
 *
 * Retourne un tableau structuré :
 *   [
 *     'annonce'      => [...],  // balises racine <annonce>
 *     'bien'         => [...],  // sous-noeud <bien>
 *     'prestation'   => [...],  // sous-noeud <prestation>
 *     'diagnostiques'=> [...],  // sous-noeud <diagnostiques>
 *     'photos'       => [...],  // liste d'URLs
 *   ]
 *
 * @param array  $row    Ligne SQL jointe
 * @param array  $photos Liste d'URLs ou noms de fichiers photo (ordonnés)
 * @return array
 */
function build_ubiflow_annonce(array $row, array $photos = []): array
{
    // -----------------------------------------------------------------
    // ANNONCE (racine)
    // -----------------------------------------------------------------
    $annonce = [
        'reference'        => ubi_str($row['a_reference_annonce'] ?? $row['a_id'] ?? null),
        'titre'            => ubi_str($row['a_titre'] ?? null),
        'texte'            => ubi_str($row['a_description'] ?? null),
        'date_saisie'      => ubi_date($row['a_date_creation'] ?? null),
        'visite_virtuelle' => ubi_str($row['a_visite_virtuelle_url'] ?? null),
        'mandat_numero'    => ubi_str($row['a_mandat_numero'] ?? null),
        'mandat_type'      => ubi_str($row['a_mandat_type'] ?? null),
        'date_mandat'      => ubi_date($row['a_date_mandat'] ?? null),
        'mandat_echeance'  => ubi_date($row['a_mandat_echeance'] ?? null),
        'url_tarifs_publics' => ubi_str($row['a_url_tarifs_publics'] ?? null),
    ];

    // -----------------------------------------------------------------
    // BIEN
    // -----------------------------------------------------------------
    // CORRECTION #2 (audit V2) : un type inconnu ne doit PAS être émis avec
    // code_type=0 (Ubiflow ignore cette valeur et rejette silencieusement).
    // Le helper retourne null → l'exporter doit sauter la ligne.
    $typeCodeInterne = strtolower(trim((string) ($row['t_code'] ?? '')));
    if ($typeCodeInterne === '' || !isset(UBIFLOW_CODE_TYPE[$typeCodeInterne])) {
        error_log(sprintf(
            '[ubiflow] Type bien inconnu, annonce ignorée — id_annonce=%s ref=%s t_code=%s',
            $row['a_id'] ?? '?',
            $row['a_reference_annonce'] ?? '?',
            $typeCodeInterne ?: '(vide)'
        ));
        return ['_skipped' => true, '_reason' => 'type_inconnu', 'annonce' => [], 'bien' => [], 'prestation' => [], 'diagnostiques' => [], 'photos' => []];
    }
    $codeType = UBIFLOW_CODE_TYPE[$typeCodeInterne];

    $bien = [
        'code_type'           => (string) $codeType,
        'libelle_type'        => ubi_str($row['t_libelle'] ?? null),
        'code_postal'         => ubi_str($row['b_code_postal'] ?? null),
        'ville'               => ubi_str($row['b_ville'] ?? null),
        'pays'                => 'FRA',
        'adresse'             => ubi_str($row['b_adresse_1'] ?? null),
        'latitude'            => ubi_num($row['b_latitude'] ?? null),
        'longitude'           => ubi_num($row['b_longitude'] ?? null),
        'precision_coordonnees' => ubi_str($row['b_precision_geoloc'] ?? null),
        'altitude'            => ubi_num($row['b_altitude'] ?? null),

        // Surfaces
        'surface'             => ubi_num($row['b_surface_habitable'] ?? $row['b_surface_totale'] ?? null),
        'surface_habitable'   => ubi_num($row['b_surface_habitable'] ?? null),
        'surface_carrez'      => ubi_num($row['b_surface_carrez'] ?? null),
        'surface_sejour'      => ubi_num($row['b_surface_sejour'] ?? null),
        'surface_terrain'     => ubi_num($row['b_surface_terrain'] ?? null),
        'surface_balcon'      => ubi_num($row['b_surface_balcon'] ?? null),
        'surface_terrasse'    => ubi_num($row['b_surface_terrasse'] ?? null),
        'surface_cave'        => ubi_num($row['b_surface_cave'] ?? null),
        'surface_jardin'      => ubi_num($row['b_surface_jardin'] ?? null),
        'surface_garage'      => ubi_num($row['b_surface_garage'] ?? null),

        // Pièces
        'nb_pieces_logement'  => ubi_num($row['b_nb_pieces'] ?? null),
        'nombre_de_chambres'  => ubi_num($row['b_nb_chambres'] ?? null),
        'nb_salles_de_bain'   => ubi_num($row['b_nb_salles_bain'] ?? null),
        'nb_salles_d_eau'     => ubi_num($row['b_nb_salles_eau'] ?? null),
        'nb_wc'               => ubi_num($row['b_nb_wc'] ?? null),
        'nombre_niveaux'      => ubi_num($row['b_nb_niveaux'] ?? null),
        'etage'               => ubi_num($row['b_etage'] ?? null, true),

        // Construction
        'annee_construction'  => ubi_num($row['b_annee_construction'] ?? null),
        'hauteur_plafond'     => ubi_num($row['b_hauteur_sous_plafond'] ?? null),
        'exposition'          => ubi_str($row['b_exposition'] ?? null),
        'standing'            => ubi_str($row['b_standing'] ?? null),
        'etat_general'        => ubi_str($row['b_etat_bien'] ?? null),

        // Chauffage
        'chauffage_type'      => ubi_str($row['b_chauffage_type'] ?? null),
        'chauffage_energie'   => ubi_str($row['b_chauffage_energie'] ?? null),
        'type_cuisine'        => ubi_str($row['b_cuisine_type'] ?? null),
        'cuisine_equipee'     => ubi_bool($row['b_cuisine_equipee'] ?? null),

        // Équipements booléens
        'ascenseur'           => ubi_bool($row['b_ascenseur'] ?? null),
        'interphone'          => ubi_bool($row['b_interphone'] ?? null),
        'digicode'            => ubi_bool($row['b_digicode'] ?? null),
        'alarme_habitation'   => ubi_bool($row['b_alarme'] ?? null),
        'climatise'           => ubi_bool($row['b_climatisation'] ?? null),
        'fibre_optique'       => ubi_bool($row['b_fibre'] ?? null),
        'cheminee'            => ubi_bool($row['b_cheminee'] ?? null),
        'dernier_etage'       => ubi_bool($row['b_dernier_etage'] ?? null),
        'balcon'              => ubi_bool($row['b_balcon'] ?? null),
        'terrasse'            => ubi_bool($row['b_terrasse'] ?? null),
        'avec_jardin'         => ubi_bool($row['b_jardin'] ?? null),
        'cave'                => ubi_bool($row['b_cave'] ?? null),
        'grenier'             => ubi_str($row['b_grenier'] ?? null),
        'garage'              => ubi_bool($row['b_garage'] ?? null),
        'piscine'             => ubi_bool($row['b_piscine'] ?? null),
        'dependances'         => ubi_bool($row['b_dependances'] ?? null),
        'nb_parkings'         => ubi_num($row['b_parking_nb'] ?? null),

        // Copropriété / ALUR
        'copropriete'         => ubi_bool($row['b_bien_en_copropriete'] ?? null),
        'alur_nb_lots'        => ubi_num($row['b_copro_nb_lots'] ?? null),
        'charges_copropriete_annuelle' => ubi_num($row['b_copro_quote_part_charges'] ?? null),
        'alur_syndic_en_procedure'     => ubi_bool($row['b_copro_procedure'] ?? null),
        'alur_syndicat_statut'         => ubi_str($row['b_alur_syndicat_statut'] ?? null),
        'alur_copropriete_plan_de_sauvegarde' => ubi_bool($row['b_alur_copropriete_plan_sauvegarde'] ?? null),
        'copropriete_etat_carence'     => ubi_bool($row['b_alur_copropriete_etat_carence'] ?? null),

        // Travaux
        'montant_travaux'     => ubi_num($row['b_montant_travaux_estime'] ?? null),

        // Fiscalité
        'taxe_fonciere'       => ubi_num($row['a_taxe_fonciere'] ?? null),
        'taxe_habitation'     => ubi_num($row['a_taxe_habitation'] ?? null),

        // Location meublée
        'meuble'              => ubi_bool($row['a_meuble'] ?? null),
    ];

    // -----------------------------------------------------------------
    // PRESTATION
    // -----------------------------------------------------------------
    $typeTransInterne = strtolower(trim((string) ($row['a_type_transaction'] ?? '')));
    $prestationType = UBIFLOW_PRESTATION_TYPE[$typeTransInterne] ?? 'V';

    // CORRECTION #4 (audit V2) : Ubiflow attend <honoraires_payeurs> comme
    // obligation légale ALUR (arrêté 10/01/2017). Valeurs : 'acquereur' |
    // 'vendeur' | 'acquereur et vendeur'. Dérivé des deux booléens.
    $hca = !empty($row['a_honoraires_charge_acquereur']);
    $hcv = !empty($row['a_honoraires_charge_vendeur']);
    $honorairesPayeurs = null;
    if ($hca && $hcv)       $honorairesPayeurs = 'acquereur et vendeur';
    elseif ($hca)           $honorairesPayeurs = 'acquereur';
    elseif ($hcv)           $honorairesPayeurs = 'vendeur';

    $prestation = [
        'type'                       => $prestationType,
        'prix'                       => ubi_num($row['a_prix'] ?? null),
        'prix_hors_honoraires'       => ubi_num($row['a_prix_net_vendeur'] ?? null),
        'honoraires_negociation'     => ubi_num($row['a_honoraires'] ?? null),
        'honoraires_payeurs'         => $honorairesPayeurs,
        'honoraires_charge_acquereur'=> ubi_bool($row['a_honoraires_charge_acquereur'] ?? null),
        'honoraires_charge_vendeur'  => ubi_bool($row['a_honoraires_charge_vendeur'] ?? null),
        'alur_pourcentage_honoraires_ttc' => ubi_num($row['a_alur_pourcentage_honoraires_ttc'] ?? null),
        'pourcentage_honoraires_vendeur'  => ubi_num($row['a_pourcentage_honoraires_vendeur'] ?? null),
        'honoraires_negociation_cumules'  => ubi_num($row['a_honoraires_negociation_cumules'] ?? null),

        // Location
        'loyer_mensuel'              => ubi_num($row['a_loyer'] ?? null),
        'loyer_mensuel_cc'           => ubi_num($row['a_loyer_cc'] ?? null),
        'loyer_est_cc'               => ubi_bool($row['a_loyer_est_cc'] ?? null),
        'charges_locatives'          => ubi_num($row['a_charges'] ?? null),
        'complement_loyer'           => ubi_num($row['a_complement_loyer'] ?? null),
        'loyer_reference_majore'     => ubi_num($row['a_loyer_reference_majore'] ?? null),
        'loyer_de_base'              => ubi_num($row['a_loyer_de_base'] ?? null),
        'zone_encadrement_loyer'     => ubi_bool($row['a_zone_encadrement_loyer'] ?? null),
        // CORRECTION #6 (audit V2) : Ubiflow attend 'modalite_' (sans "s")
        'modalite_recuperation_charges_locatives' => ubi_str($row['a_modalite_recuperation_charges_locatives'] ?? null),
        'depot_garantie'             => ubi_num($row['a_depot_garantie'] ?? null),
        'honoraires_location'        => ubi_num($row['a_honoraires'] ?? null),
        'honoraires_etat_des_lieux'  => ubi_num($row['a_honoraires_etat_des_lieux'] ?? null),
        'duree_du_bail'              => ubi_num($row['a_duree_bail_mois'] ?? null),
        'date_disponibilite'         => ubi_date($row['a_date_disponibilite'] ?? null),
        'disponible_immediatement'   => ubi_bool($row['a_disponible_de_suite'] ?? null),
    ];

    // -----------------------------------------------------------------
    // DIAGNOSTIQUES (DPE, GES, ERP)
    // -----------------------------------------------------------------
    // On privilégie dpe_diags (diag principal) si disponible, sinon fallback sur biens
    $dpeDate   = $row['dpe_date_diagnostic'] ?? $row['b_dpe_date_realisation'] ?? null;
    $consoVal  = $row['dpe_conso_primaire'] ?? $row['b_dpe_valeur_conso_primaire'] ?? $row['b_dpe_valeur'] ?? null;
    $consoFin  = $row['dpe_conso_finale']   ?? $row['b_dpe_valeur_conso_finale']   ?? null;
    $depMin    = $row['dpe_depenses_min']   ?? $row['b_montant_estime_depenses_min'] ?? null;
    $depMax    = $row['dpe_depenses_max']   ?? $row['b_montant_estime_depenses_max'] ?? null;
    $dpeVers   = $row['dpe_version_diag']   ?? $row['b_dpe_version'] ?? null;
    $dpeVierge = $row['dpe_vierge_diag']    ?? $row['b_dpe_vierge'] ?? null;

    $diagnostiques = [
        'dpe_etiquette_conso'      => ubi_str($row['b_dpe_classe'] ?? null),
        'dpe_valeur_conso'         => ubi_num($consoVal),
        'dpe_valeur_conso_primaire'=> ubi_num($row['b_dpe_valeur_conso_primaire'] ?? null),
        'dpe_valeur_conso_finale'  => ubi_num($consoFin),
        'dpe_etiquette_ges'        => ubi_str($row['b_ges_classe'] ?? null),
        'dpe_valeur_ges'           => ubi_num($row['b_ges_valeur'] ?? null),
        'dpe_date_realisation'     => ubi_date($dpeDate),
        'dpe_version'              => ubi_str($dpeVers),
        'dpe_vierge'               => ubi_bool($dpeVierge),
        'montant_depenses_energies_min' => ubi_num($depMin),
        'montant_depenses_energies_max' => ubi_num($depMax),
        'date_indice_prix_energies'     => ubi_date($row['dpe_date_indice_prix'] ?? $row['b_date_indice_prix_energies'] ?? null),

        // ERP / Géorisques
        'zone_georisque'              => ubi_bool($row['b_zone_georisque'] ?? null),
        'obligation_debroussaillement'=> ubi_bool($row['b_obligation_debroussaillement'] ?? null),
        'erp_date_realisation'        => ubi_date($row['b_erp_date_realisation'] ?? null),
        'risque_inondation_g_score'   => ubi_str($row['b_risque_inondation_g_score'] ?? null),
        'risque_inondation_p_score'   => ubi_str($row['b_risque_inondation_p_score'] ?? null),
    ];

    // -----------------------------------------------------------------
    // PHOTOS (liste ordonnée d'URLs)
    // -----------------------------------------------------------------
    $photosClean = [];
    foreach ($photos as $p) {
        $p = trim((string) $p);
        if ($p !== '') $photosClean[] = $p;
    }

    return [
        'annonce'       => array_filter($annonce,       static fn($v) => $v !== null && $v !== ''),
        'bien'          => array_filter($bien,          static fn($v) => $v !== null && $v !== ''),
        'prestation'    => array_filter($prestation,    static fn($v) => $v !== null && $v !== ''),
        'diagnostiques' => array_filter($diagnostiques, static fn($v) => $v !== null && $v !== ''),
        'photos'        => $photosClean,
    ];
}

// =====================================================================
// 5. REQUÊTE SQL DE RÉFÉRENCE
// =====================================================================
/**
 * Construit la requête SQL de récupération des annonces publiables.
 * Les alias de colonnes suivent la convention attendue par build_ubiflow_annonce() :
 *   a_* pour `annonces`, b_* pour `biens`, t_* pour `types_bien`,
 *   dpe_* pour le diagnostic principal.
 *
 * @param int|null $idAgence Filtre sur une agence. null = toutes agences
 *                           (mode legacy / siège). Pour la diffusion Ubiflow
 *                           multi-agences, passer OBLIGATOIREMENT l'id.
 *
 * CORRECTION #3 (audit V2) : statut 'brouillon' RETIRÉ du filtre. En mode
 * Annule/Remplace Ubiflow, tout bien présent dans le XML est publié immédiatement
 * sur LeBonCoin/SeLoger/Bien'ici. Les brouillons ne doivent JAMAIS partir.
 */
function ubiflow_sql_select_annonces(?int $idAgence = null): string
{
    $where = "WHERE a.visible_portails = 1\n  AND a.statut IN ('publiee','active','en_ligne')";
    if ($idAgence !== null && $idAgence > 0) {
        $where = "WHERE a.id_agence = " . (int)$idAgence . "\n  AND a.visible_portails = 1\n  AND a.statut IN ('publiee','active','en_ligne')";
    }
    return <<<SQL
SELECT
    a.id                AS a_id,
    a.reference_annonce AS a_reference_annonce,
    a.titre             AS a_titre,
    a.description       AS a_description,
    a.date_creation     AS a_date_creation,
    a.type_transaction  AS a_type_transaction,
    a.prix              AS a_prix,
    a.prix_net_vendeur  AS a_prix_net_vendeur,
    a.honoraires        AS a_honoraires,
    a.honoraires_charge AS a_honoraires_charge,
    a.honoraires_charge_acquereur AS a_honoraires_charge_acquereur,
    a.honoraires_charge_vendeur   AS a_honoraires_charge_vendeur,
    a.alur_pourcentage_honoraires_ttc AS a_alur_pourcentage_honoraires_ttc,
    a.pourcentage_honoraires_vendeur  AS a_pourcentage_honoraires_vendeur,
    a.honoraires_negociation_cumules  AS a_honoraires_negociation_cumules,
    a.url_tarifs_publics AS a_url_tarifs_publics,
    a.charges           AS a_charges,
    a.charges_annuelles AS a_charges_annuelles,
    a.taxe_fonciere     AS a_taxe_fonciere,
    a.taxe_habitation   AS a_taxe_habitation,
    a.loyer             AS a_loyer,
    a.loyer_cc          AS a_loyer_cc,
    a.loyer_est_cc      AS a_loyer_est_cc,
    a.complement_loyer  AS a_complement_loyer,
    a.loyer_reference_majore AS a_loyer_reference_majore,
    a.loyer_de_base     AS a_loyer_de_base,
    a.zone_encadrement_loyer AS a_zone_encadrement_loyer,
    a.modalite_recuperation_charges_locatives AS a_modalite_recuperation_charges_locatives,
    a.depot_garantie    AS a_depot_garantie,
    a.honoraires_etat_des_lieux AS a_honoraires_etat_des_lieux,
    a.duree_bail_mois   AS a_duree_bail_mois,
    a.meuble            AS a_meuble,
    a.disponible_de_suite AS a_disponible_de_suite,
    a.date_disponibilite  AS a_date_disponibilite,
    a.visite_virtuelle_url AS a_visite_virtuelle_url,
    a.mandat_numero     AS a_mandat_numero,
    a.mandat_type       AS a_mandat_type,
    a.date_mandat       AS a_date_mandat,
    a.mandat_echeance   AS a_mandat_echeance,

    b.reference_bien    AS b_reference_bien,
    b.code_postal       AS b_code_postal,
    b.ville             AS b_ville,
    b.adresse_1         AS b_adresse_1,
    b.latitude          AS b_latitude,
    b.longitude         AS b_longitude,
    b.precision_geoloc  AS b_precision_geoloc,
    b.altitude          AS b_altitude,
    b.surface_habitable AS b_surface_habitable,
    b.surface_totale    AS b_surface_totale,
    b.surface_carrez    AS b_surface_carrez,
    b.surface_sejour    AS b_surface_sejour,
    b.surface_terrain   AS b_surface_terrain,
    b.surface_balcon    AS b_surface_balcon,
    b.surface_terrasse  AS b_surface_terrasse,
    b.surface_cave      AS b_surface_cave,
    b.surface_jardin    AS b_surface_jardin,
    b.surface_garage    AS b_surface_garage,
    b.nb_pieces         AS b_nb_pieces,
    b.nb_chambres       AS b_nb_chambres,
    b.nb_salles_bain    AS b_nb_salles_bain,
    b.nb_salles_eau     AS b_nb_salles_eau,
    b.nb_wc             AS b_nb_wc,
    b.nb_niveaux        AS b_nb_niveaux,
    b.etage             AS b_etage,
    b.annee_construction AS b_annee_construction,
    b.hauteur_sous_plafond AS b_hauteur_sous_plafond,
    b.exposition        AS b_exposition,
    b.standing          AS b_standing,
    b.etat_bien         AS b_etat_bien,
    b.chauffage_type    AS b_chauffage_type,
    b.chauffage_energie AS b_chauffage_energie,
    b.cuisine_type      AS b_cuisine_type,
    b.cuisine_equipee   AS b_cuisine_equipee,
    b.ascenseur         AS b_ascenseur,
    b.interphone        AS b_interphone,
    b.digicode          AS b_digicode,
    b.alarme            AS b_alarme,
    b.climatisation     AS b_climatisation,
    b.fibre             AS b_fibre,
    b.cheminee          AS b_cheminee,
    b.dernier_etage     AS b_dernier_etage,
    b.balcon            AS b_balcon,
    b.terrasse          AS b_terrasse,
    b.jardin            AS b_jardin,
    b.cave              AS b_cave,
    b.grenier           AS b_grenier,
    b.garage            AS b_garage,
    b.piscine           AS b_piscine,
    b.dependances       AS b_dependances,
    b.parking_nb        AS b_parking_nb,
    b.montant_travaux_estime AS b_montant_travaux_estime,
    b.dpe_classe        AS b_dpe_classe,
    b.ges_classe        AS b_ges_classe,
    b.dpe_valeur        AS b_dpe_valeur,
    b.ges_valeur        AS b_ges_valeur,
    b.dpe_date_realisation AS b_dpe_date_realisation,
    b.dpe_version       AS b_dpe_version,
    b.dpe_vierge        AS b_dpe_vierge,
    b.dpe_valeur_conso_primaire AS b_dpe_valeur_conso_primaire,
    b.dpe_valeur_conso_finale   AS b_dpe_valeur_conso_finale,
    b.date_indice_prix_energies AS b_date_indice_prix_energies,
    b.montant_estime_depenses_min AS b_montant_estime_depenses_min,
    b.montant_estime_depenses_max AS b_montant_estime_depenses_max,
    b.bien_en_copropriete AS b_bien_en_copropriete,
    b.copro_nb_lots       AS b_copro_nb_lots,
    b.copro_quote_part_charges AS b_copro_quote_part_charges,
    b.copro_procedure     AS b_copro_procedure,
    b.alur_syndicat_statut AS b_alur_syndicat_statut,
    b.alur_copropriete_plan_sauvegarde AS b_alur_copropriete_plan_sauvegarde,
    b.alur_copropriete_etat_carence    AS b_alur_copropriete_etat_carence,
    b.zone_georisque              AS b_zone_georisque,
    b.obligation_debroussaillement AS b_obligation_debroussaillement,
    b.erp_date_realisation        AS b_erp_date_realisation,
    b.risque_inondation_g_score   AS b_risque_inondation_g_score,
    b.risque_inondation_p_score   AS b_risque_inondation_p_score,

    t.code              AS t_code,
    t.libelle           AS t_libelle,

    d.date_diagnostic   AS dpe_date_diagnostic,
    d.conso_energie_primaire AS dpe_conso_primaire,
    d.conso_energie_finale   AS dpe_conso_finale,
    d.montant_depenses_min   AS dpe_depenses_min,
    d.montant_depenses_max   AS dpe_depenses_max,
    d.date_indice_prix       AS dpe_date_indice_prix,
    d.dpe_version            AS dpe_version_diag,
    d.dpe_vierge             AS dpe_vierge_diag

FROM annonces a
INNER JOIN biens      b ON b.id = a.id_bien
LEFT  JOIN types_bien t ON t.id = b.id_type_bien
LEFT  JOIN dpe_diags  d ON d.id_bien = b.id AND d.est_diag_principal = 1
{$where}
ORDER BY a.id
SQL;
}

/**
 * Récupère les photos d'une annonce (ordonnées), sous forme de liste d'URLs.
 */
function ubiflow_get_photos(PDO $pdo, int $idAnnonce): array
{
    $stmt = $pdo->prepare(
        'SELECT url_photo FROM annonces_photos
         WHERE id_annonce = :id
         ORDER BY principale DESC, ordre_affichage ASC, id ASC'
    );
    $stmt->execute([':id' => $idAnnonce]);
    return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'url_photo');
}
