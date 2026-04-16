<?php
declare(strict_types=1);

/**
 * Autosave AJAX endpoint for bien_ajouter.php
 * Receives the form fields (no files) and updates the bien + annonce rows.
 * Returns JSON { ok: true, saved_at: "HH:MM" } or { ok: false, error: "..." }
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}

verify_csrf_any('ajouter_bien');

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);

$bienId = isset($_POST['_edit_id']) && ctype_digit((string)$_POST['_edit_id']) ? (int)$_POST['_edit_id'] : 0;
if ($bienId <= 0) {
    exit(json_encode(['ok' => false, 'error' => 'Aucun bien à sauvegarder']));
}

// Verify ownership
$stmt = $pdo->prepare("SELECT id FROM biens WHERE id = ? AND id_societe = ?");
$stmt->execute([$bienId, $societeId]);
if (!$stmt->fetchColumn()) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Bien introuvable']));
}

// Helpers
$str  = static fn(string $k, string $d = ''): string => trim((string)($_POST[$k] ?? $d));
$int  = static fn(string $k) => ($_POST[$k] ?? '') !== '' ? (int)$_POST[$k] : null;
$flt  = static fn(string $k) => ($_POST[$k] ?? '') !== '' ? (float)$_POST[$k] : null;
$bool = static fn(string $k) => ($_POST[$k] ?? '') !== '' ? (int)(bool)(int)$_POST[$k] : 0;

// ══════════════════════════════════════════════════════════════
// Map POST field → biens column
// ══════════════════════════════════════════════════════════════
$data = [
    // ── Identification ──
    'designation'             => $str('designation'),
    'reference_bien'          => $str('reference_bien'),
    'reference_externe'       => $str('reference_externe'),
    'sous_type_bien'          => $str('sous_type_bien'),
    'usage_bien'              => $str('usage_bien'),
    'statut_bien'             => $str('statut_bien'),
    'type_commercialisation'  => $str('type_commercialisation'),
    'lot_principal'           => $str('lot_principal'),
    'lot_secondaire'          => $str('lot_secondaire'),
    'standing'                => $str('standing'),
    'etat_bien'               => $str('etat_bien'),
    'disponibilite_bien'      => $str('disponibilite_bien'),
    'disponible_le'           => $str('disponibilite_date') ?: null,
    'occupation_bien'         => $str('occupation_bien'),
    'louable_immediatement'   => $bool('louable_immediatement'),
    'adresse_visible_public'  => $bool('adresse_visible_public'),

    // ── Environnement ──
    'exposition'              => $str('exposition'),
    'vue'                     => $str('vue'),
    'nuisances'               => $str('nuisances'),
    'altitude'                => $int('altitude'),

    // ── Surfaces ──
    'surface_habitable'       => $flt('surface_habitable'),
    'surface_carrez'          => $flt('surface_carrez'),
    'surface_sejour'          => $flt('surface_sejour'),
    'surface_totale'          => $flt('surface_totale'),
    'surface_terrain'         => $flt('surface_terrain'),
    'surface_balcon'          => $flt('surface_balcon'),
    'surface_terrasse'        => $flt('surface_terrasse'),
    'surface_jardin'          => $flt('surface_jardin'),
    'surface_cave'            => $flt('surface_cave'),
    'surface_garage'          => $flt('surface_garage'),
    'surface_box'             => $flt('surface_box'),
    'surface_veranda'         => $flt('surface_veranda'),
    'surface_annexe'          => $flt('surface_annexe'),
    'hauteur_sous_plafond'    => $flt('hauteur_sous_plafond'),

    // ── Composition ──
    'annee_construction'      => $int('annee_construction'),
    'etage'                   => $int('etage'),
    'nb_niveaux'              => $int('nb_niveaux'),
    'nb_pieces'               => $int('nb_pieces'),
    'nb_chambres'             => $int('nb_chambres'),
    'nb_salles_bain'          => $int('nb_salles_bain'),
    'nb_salles_eau'           => $int('nb_salles_eau'),
    'nb_wc'                   => $int('nb_wc'),
    'parking_nb'              => $int('parking_nb'),
    'numero_porte'            => $str('numero_porte'),
    'dernier_etage'           => $bool('dernier_etage'),

    // ── Équipements ──
    'cuisine_type'            => $str('cuisine_type'),
    'cuisine_equipee'         => $bool('cuisine_equipee'),
    'ascenseur'               => $bool('ascenseur'),
    'interphone'              => $bool('interphone'),
    'digicode'                => $bool('digicode'),
    'alarme'                  => $bool('alarme'),
    'climatisation'           => $bool('climatisation'),
    'fibre'                   => $bool('fibre'),
    'double_vitrage'          => $bool('double_vitrage'),
    'volets_roulants'         => $bool('volets_roulants'),
    'cheminee'                => $bool('cheminee'),
    'balcon'                  => $bool('balcon'),
    'terrasse'                => $bool('terrasse'),
    'jardin'                  => $bool('jardin'),
    'cour'                    => $bool('cour'),
    'cave'                    => $bool('cave'),
    'grenier'                 => $bool('grenier'),
    'garage'                  => $bool('garage'),
    'box'                     => $bool('box'),
    'piscine'                 => $bool('piscine'),
    'dependances'             => $bool('dependances'),
    'acces_camion'            => $bool('acces_camion'),
    'vitrine'                 => $bool('vitrine'),
    'animaux_acceptes'        => $bool('animaux_acceptes'),
    'fumeur_accepte'          => $bool('fumeur_accepte'),

    // ── Chauffage / Énergie ──
    'chauffage_type'          => $str('chauffage_type'),
    'chauffage_energie'       => $str('chauffage_energie'),
    'eau_chaude_type'         => $str('eau_chaude_type'),
    'menuiseries'             => $str('menuiseries'),
    'isolation'               => $str('isolation'),

    // ── DPE / GES ──
    'dpe_classe'              => $str('dpe_classe'),
    'ges_classe'              => $str('ges_classe'),
    'dpe_valeur'              => $flt('dpe_valeur'),
    'ges_valeur'              => $flt('ges_valeur'),
    'dpe_valeur_conso_primaire' => $flt('dpe_valeur_conso_primaire'),
    'dpe_valeur_conso_finale'   => $flt('dpe_valeur_conso_finale'),
    'date_indice_prix_energies' => $str('date_indice_prix_energies') ?: null,
    'montant_estime_depenses_min' => $flt('montant_estime_depenses_min'),
    'montant_estime_depenses_max' => $flt('montant_estime_depenses_max'),
    'dpe_date_realisation'    => $str('dpe_date_realisation'),
    'dpe_version'             => $str('dpe_version'),
    'dpe_vierge'              => $bool('dpe_vierge'),
    'dpe_reference_certificat'=> $str('dpe_reference_certificat'),

    // ── ERP / Géorisques ──
    'obligation_debroussaillement' => $bool('obligation_debroussaillement'),
    'erp_date_realisation'    => $str('erp_date_realisation') ?: null,
    'risque_inondation_g_score' => $str('risque_inondation_g_score'),
    'risque_inondation_p_score' => $str('risque_inondation_p_score'),

    // ── Prix / Loyers (table biens) ──
    'loyer_hc'                => $flt('loyer_hc'),
    'charges_locatives'       => $flt('charges'),
    'depot_garantie'          => $flt('depot_garantie'),
    'loyer_meuble'            => $flt('loyer_meuble'),
    'honoraires_locataire'    => $flt('honoraires_locataire'),
    'zone_tendue'             => $str('zone_tendue'),
    'prix_vente_estime'       => $flt('prix_vente_estime'),
    'rentabilite_brute_estimee' => $flt('rentabilite_brute_estimee'),
    'montant_travaux_estime'  => $flt('montant_travaux_estime'),

    // ── Estimation agence ──
    'estimation_agence_vente'    => $flt('estimation_agence_vente'),
    'estimation_agence_location' => $flt('estimation_agence_location'),
    'estimation_agence_date'     => $str('estimation_agence_date') ?: null,
    'estimation_agence_notes'    => $str('estimation_agence_notes'),

    // ── Copropriété ──
    'bien_en_copropriete'     => $bool('bien_en_copropriete'),
    'copro_nb_lots'           => $int('copro_nb_lots'),
    'copro_quote_part_charges'=> $flt('copro_quote_part_charges'),
    'syndic_type'             => $str('syndic_type'),
    'alur_syndicat_statut'    => $str('alur_syndicat_statut'),
    'copro_travaux_nature'    => $str('copro_travaux_nature'),

    // ── Encadrement loyers (stocké côté biens) ──
    'enc_zone'                => $str('enc_zone'),
    'enc_loyer_ref'           => $flt('enc_loyer_ref'),
    'enc_loyer_min'           => $flt('enc_loyer_min'),
    'enc_loyer_max'           => $flt('enc_loyer_max'),
    'enc_complement'          => $flt('enc_complement'),

    // ── Description / SEO ──
    'description'             => $str('description'),
    'reprise_descriptif'      => $str('reprise_descriptif'),
    'points_forts'            => $str('points_forts'),
    'mots_cles'               => $str('mots_cles'),
    'commentaire'             => $str('commentaire'),
    'titre_seo'               => $str('titre_seo'),
    'meta_description'        => $str('meta_description'),
    'accroche_commerciale'    => $str('accroche_commerciale'),
];

// Also handle type_bien → id_type_bien
$typeBienCode = $str('type_bien');
if ($typeBienCode !== '') {
    $stmtT = $pdo->prepare("SELECT id FROM types_bien WHERE code = ? LIMIT 1");
    $stmtT->execute([$typeBienCode]);
    $tbId = (int)$stmtT->fetchColumn();
    if ($tbId > 0) {
        $data['id_type_bien'] = $tbId;
    }
}

// id_proprietaire
$proprioId = $int('id_proprietaire');
if ($proprioId !== null && $proprioId > 0) {
    $data['id_proprietaire'] = $proprioId;
}

// ══════════════════════════════════════════════════════════════
// Address fields — also update immeuble
// ══════════════════════════════════════════════════════════════
$adresse1    = $str('adresse_1');
$adresse2    = $str('adresse_2');
$codePostal  = $str('code_postal');
$ville       = $str('ville');
$pays        = $str('pays', 'France');
$latitude    = $str('latitude');
$longitude   = $str('longitude');

// Update immeuble if bien has one and address changed
if ($adresse1 !== '') {
    $stmtImm = $pdo->prepare("SELECT id_immeuble FROM biens WHERE id = ?");
    $stmtImm->execute([$bienId]);
    $immId = (int)$stmtImm->fetchColumn();

    if ($immId > 0) {
        $pdo->prepare("
            UPDATE immeubles SET
                adresse_1 = ?, adresse_2 = ?, code_postal = ?, ville = ?, pays = ?,
                latitude = ?, longitude = ?,
                adresse_cle = ?
            WHERE id = ?
        ")->execute([
            $adresse1, $adresse2 ?: null, $codePostal ?: null, $ville ?: null, $pays,
            $latitude ?: null, $longitude ?: null,
            mb_strtolower(trim("$adresse1 $adresse2 $codePostal $ville")),
            $immId,
        ]);
    }
}

// ══════════════════════════════════════════════════════════════
// Build & execute UPDATE biens
// ══════════════════════════════════════════════════════════════
$sets = [];
$params = [];
foreach ($data as $col => $val) {
    $ph = ':' . $col;
    $sets[] = "`{$col}` = {$ph}";
    $params[$ph] = $val;
}
$params[':_id'] = $bienId;

try {
    $pdo->prepare("UPDATE biens SET " . implode(', ', $sets) . ", date_modification = NOW() WHERE id = :_id")
        ->execute($params);

    // ══════════════════════════════════════════════════════════════
    // Update annonces table (honoraires, mandats, locataire précédent, taxes…)
    // ══════════════════════════════════════════════════════════════
    $annonceTransaction = $str('annonce_transaction');
    $stmtAnn = $pdo->prepare("SELECT id FROM annonces WHERE id_bien = ? ORDER BY id DESC LIMIT 1");
    $stmtAnn->execute([$bienId]);
    $annonceId = (int)$stmtAnn->fetchColumn();

    // Create annonce if needed
    if ($annonceId <= 0 && $annonceTransaction !== '') {
        $pdo->prepare("INSERT INTO annonces (id_bien, id_societe, type_transaction, date_creation, date_modification) VALUES (?, ?, ?, NOW(), NOW())")
            ->execute([$bienId, $societeId, $annonceTransaction]);
        $annonceId = (int)$pdo->lastInsertId();
    }

    if ($annonceId > 0) {
        $annData = [
            'type_transaction'       => $annonceTransaction ?: null,
            'id_user'                => $int('annonce_commercial_id'),
            'prix'                   => $flt('annonce_prix_vente'),
            'loyer'                  => $flt('annonce_loyer'),
            'loyer_cc'               => $flt('annonce_loyer'),
            'description'            => $str('description'),
            'texte_ia'               => $str('texte_ia') ?: null,
            // Honoraires ALUR
            'honoraires_charge_acquereur'  => $bool('honoraires_charge_acquereur'),
            'honoraires_charge_vendeur'    => $bool('honoraires_charge_vendeur'),
            'alur_pourcentage_honoraires_ttc' => $flt('alur_pourcentage_honoraires_ttc'),
            'pourcentage_honoraires_vendeur'  => $flt('pourcentage_honoraires_vendeur'),
            'honoraires_negociation_cumules'  => $flt('honoraires_negociation_cumules'),
            'url_tarifs_publics'     => $str('url_tarifs_publics') ?: null,
            // Encadrement loyers
            'zone_encadrement_loyer' => $bool('zone_encadrement_loyer'),
            'loyer_de_base'          => $flt('loyer_de_base'),
            'loyer_est_cc'           => $bool('loyer_est_cc'),
            'loyer_reference_majore' => $flt('loyer_reference_majore'),
            'complement_loyer'       => $flt('complement_loyer'),
            'modalite_recuperation_charges_locatives' => $str('modalite_recuperation_charges_locatives') ?: null,
            'honoraires_etat_des_lieux' => $flt('honoraires_etat_des_lieux'),
            // Mandat
            'mandat_numero'          => $str('mandat_numero') ?: null,
            'mandat_type'            => $str('mandat_type') ?: null,
            'date_mandat'            => $str('date_mandat') ?: null,
            'mandat_echeance'        => $str('mandat_echeance') ?: null,
            // Locataire précédent
            'ancien_loyer_montant'   => $flt('ancien_loyer_montant'),
            'ancien_loyer_charges'   => $flt('ancien_loyer_charges'),
            'ancien_loyer_date_revision'    => $str('ancien_loyer_date_revision') ?: null,
            'ancien_locataire_date_sortie'  => $str('ancien_locataire_date_sortie') ?: null,
            'ancien_loyer_communique'       => $bool('ancien_loyer_communique'),
            // Taxes
            'taxe_fonciere'          => $flt('taxe_fonciere'),
            'taxe_habitation'        => $flt('taxe_habitation'),
        ];

        $annSets = [];
        $annParams = [];
        foreach ($annData as $col => $val) {
            $ph = ':a_' . $col;
            $annSets[] = "`{$col}` = {$ph}";
            $annParams[$ph] = $val;
        }
        $annParams[':a_id'] = $annonceId;
        $pdo->prepare("UPDATE annonces SET " . implode(', ', $annSets) . ", date_modification = NOW() WHERE id = :a_id")
            ->execute($annParams);
    }

    echo json_encode([
        'ok'       => true,
        'saved_at' => date('H:i'),
    ]);
} catch (Throwable $e) {
    error_log('[bien_autosave] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
