<?php
declare(strict_types=1);

/**
 * Autosave AJAX endpoint for bien_ajouter.php
 * Receives the form fields (no files) and updates the bien row.
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

// Helper
$str  = static fn(string $k, string $d = ''): string => trim((string)($_POST[$k] ?? $d));
$int  = static fn(string $k) => ($_POST[$k] ?? '') !== '' ? (int)$_POST[$k] : null;
$flt  = static fn(string $k) => ($_POST[$k] ?? '') !== '' ? (float)$_POST[$k] : null;
$bool = static fn(string $k) => ($_POST[$k] ?? '') !== '' ? (int)(bool)(int)$_POST[$k] : 0;

// Map POST field → column (only safe text/number fields, no files)
$data = [
    'designation'             => $str('designation'),
    'reference_bien'          => $str('reference_bien'),
    'reference_externe'       => $str('reference_externe'),
    'sous_type_bien'          => $str('sous_type_bien'),
    'usage_bien'              => $str('usage_bien'),
    'type_commercialisation'  => $str('type_commercialisation'),
    'lot_principal'           => $str('lot_principal'),
    'lot_secondaire'          => $str('lot_secondaire'),
    'standing'                => $str('standing'),
    'etat_bien'               => $str('etat_bien'),
    'disponibilite_bien'      => $str('disponibilite_bien'),
    'occupation_bien'         => $str('occupation_bien'),
    'louable_immediatement'   => $bool('louable_immediatement'),
    'adresse_visible_public'  => $bool('adresse_visible_public'),
    'exposition'              => $str('exposition'),
    'vue'                     => $str('vue'),
    'nuisances'               => $str('nuisances'),
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
    'chauffage_type'          => $str('chauffage_type'),
    'chauffage_energie'       => $str('chauffage_energie'),
    'eau_chaude_type'         => $str('eau_chaude_type'),
    'menuiseries'             => $str('menuiseries'),
    'isolation'               => $str('isolation'),
    'dpe_classe'              => $str('dpe_classe'),
    'ges_classe'              => $str('ges_classe'),
    'dpe_valeur'              => $flt('dpe_valeur'),
    'ges_valeur'              => $flt('ges_valeur'),
    'montant_estime_depenses_min' => $flt('montant_estime_depenses_min'),
    'montant_estime_depenses_max' => $flt('montant_estime_depenses_max'),
    'loyer_hc'                => $flt('loyer_hc'),
    'charges_locatives'       => $flt('charges_locatives'),
    'depot_garantie'          => $flt('depot_garantie'),
    'loyer_meuble'            => $flt('loyer_meuble'),
    'prix_vente_estime'       => $flt('prix_vente_estime'),
    'rentabilite_brute_estimee' => $flt('rentabilite_brute_estimee'),
    'montant_travaux_estime'  => $flt('montant_travaux_estime'),
    'bien_en_copropriete'     => $bool('bien_en_copropriete'),
    'copro_nb_lots'           => $int('copro_nb_lots'),
    'copro_quote_part_charges'=> $flt('copro_quote_part_charges'),
    'dpe_date_realisation'    => $str('dpe_date_realisation'),
    'dpe_version'             => $str('dpe_version'),
    'dpe_vierge'              => $bool('dpe_vierge'),
    'dpe_reference_certificat'=> $str('dpe_reference_certificat'),
    'description'             => $str('description'),
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

// Address fields — also update immeuble if needed
$adresse1    = $str('adresse_1');
$adresse2    = $str('adresse_2');
$codePostal  = $str('code_postal');
$ville       = $str('ville');
$pays        = $str('pays', 'France');
$latitude    = $str('latitude');
$longitude   = $str('longitude');

// Build UPDATE
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

    echo json_encode([
        'ok'       => true,
        'saved_at' => date('H:i'),
    ]);
} catch (Throwable $e) {
    error_log('[bien_autosave] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
