<?php
// api/dpe_diag_update.php
// Met à jour une ligne dpe_diags + les champs biens correspondants depuis le
// sous-onglet "Données extraites" de bien_detail. Edition manuelle directe des
// deux tables sans passer par l'extraction IA.
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    exit(json_encode(['ok' => false, 'error' => 'JSON invalide']));
}

$diagId = (int)($body['diag_id'] ?? 0);
$bienId = (int)($body['id_bien'] ?? 0);
$diagFields = is_array($body['diag_fields'] ?? null) ? $body['diag_fields'] : [];
$biensFields = is_array($body['biens_fields'] ?? null) ? $body['biens_fields'] : [];

if ($diagId <= 0) exit(json_encode(['ok' => false, 'error' => 'diag_id manquant']));

$pdo = $GLOBALS['pdo'];

// ── Whitelist des colonnes dpe_diags éditables ──
$diagAllowedCols = [
    'date_diagnostic', 'date_validite', 'dpe_version', 'dpe_vierge',
    'dpe_classe', 'ges_classe',
    'consommation_energie', 'conso_energie_primaire', 'conso_energie_finale', 'emission_ges',
    'montant_depenses_min', 'montant_depenses_max', 'date_indice_prix',
    'diagnostiqueur_nom', 'diagnostiqueur_societe', 'numero_rapport', 'numero_ademe',
    'type_bien_detecte', 'adresse_detectee', 'code_postal_detecte', 'ville_detectee',
    'etage_detecte', 'lot_detecte', 'annee_construction_detectee',
    'surface_habitable_detectee', 'surface_carrez_detectee', 'surface_sejour_detectee',
    'nb_pieces_detecte', 'nb_chambres_detecte', 'nb_salles_bain_detecte',
    'nb_salles_eau_detecte', 'nb_wc_detecte',
    'chauffage_type_detecte', 'chauffage_energie_detecte', 'eau_chaude_type_detecte',
    'double_vitrage_detecte', 'volets_roulants_detecte', 'menuiseries_detectees',
    'altitude_detectee',
    'alerte_plomb_present', 'alerte_plomb_classe_max', 'alerte_amiante_present',
    'alerte_electricite_anomalies', 'alerte_gaz_anomalies', 'alerte_termites',
    'alerte_zone_georisque', 'alerte_inondation', 'sismicite_zone',
    'resume_bailleur', 'commentaire',
];

// ── Whitelist des colonnes biens éditables depuis ce panneau ──
$biensAllowedCols = [
    'dpe_classe', 'ges_classe', 'dpe_valeur', 'ges_valeur',
    'dpe_valeur_conso_primaire', 'dpe_valeur_conso_finale',
    'dpe_version', 'dpe_vierge', 'dpe_date_realisation', 'dpe_reference_certificat',
    'montant_estime_depenses_min', 'montant_estime_depenses_max',
    'date_indice_prix_energies', 'annee_reference_depenses',
    'adresse_1', 'code_postal', 'ville', 'etage', 'lot_principal',
    'annee_construction', 'altitude',
    'surface_habitable', 'surface_carrez', 'surface_sejour',
    'nb_pieces', 'nb_chambres', 'nb_salles_bain', 'nb_salles_eau', 'nb_wc',
    'chauffage_type', 'chauffage_energie', 'eau_chaude_type',
    'double_vitrage', 'volets_roulants', 'menuiseries',
    'zone_georisque',
];

// ── Auto-mapping dpe_diags → biens ──
// Quand l'utilisateur renseigne un champ depuis la Card "À compléter"
// DPE, il est naturel qu'il se propage aussi vers la table biens (sinon
// l'user voit sa valeur dans la card Extraits mais pas dans la card
// principale DPE qui lit biens.*).
// Les clés ci-dessous sont les colonnes de dpe_diags ; les valeurs sont
// la colonne biens équivalente (même sémantique).
$autoMapDiagToBien = [
    'dpe_classe'                    => 'dpe_classe',
    'ges_classe'                    => 'ges_classe',
    'consommation_energie'          => 'dpe_valeur',
    'conso_energie_primaire'        => 'dpe_valeur_conso_primaire',
    'conso_energie_finale'          => 'dpe_valeur_conso_finale',
    'emission_ges'                  => 'ges_valeur',
    'montant_depenses_min'          => 'montant_estime_depenses_min',
    'montant_depenses_max'          => 'montant_estime_depenses_max',
    'date_diagnostic'               => 'dpe_date_realisation',
    'date_indice_prix'              => 'date_indice_prix_energies',
    'dpe_version'                   => 'dpe_version',
    'dpe_vierge'                    => 'dpe_vierge',
    'numero_ademe'                  => 'dpe_reference_certificat',
    'adresse_detectee'              => 'adresse_1',
    'code_postal_detecte'           => 'code_postal',
    'ville_detectee'                => 'ville',
    'etage_detecte'                 => 'etage',
    'lot_detecte'                   => 'lot_principal',
    'annee_construction_detectee'   => 'annee_construction',
    'altitude_detectee'             => 'altitude',
    'surface_habitable_detectee'    => 'surface_habitable',
    'surface_carrez_detectee'       => 'surface_carrez',
    'surface_sejour_detectee'       => 'surface_sejour',
    'nb_pieces_detecte'             => 'nb_pieces',
    'nb_chambres_detecte'           => 'nb_chambres',
    'nb_salles_bain_detecte'        => 'nb_salles_bain',
    'nb_salles_eau_detecte'         => 'nb_salles_eau',
    'nb_wc_detecte'                 => 'nb_wc',
    'chauffage_type_detecte'        => 'chauffage_type',
    'chauffage_energie_detecte'     => 'chauffage_energie',
    'eau_chaude_type_detecte'       => 'eau_chaude_type',
    'double_vitrage_detecte'        => 'double_vitrage',
    'volets_roulants_detecte'       => 'volets_roulants',
    'menuiseries_detectees'         => 'menuiseries',
    'alerte_zone_georisque'         => 'zone_georisque',
];

// Propagation auto : pour chaque diag_field, si le mapping existe et
// que la valeur n'est pas déjà explicitement dans biens_fields, on
// l'ajoute. L'utilisateur peut toujours override via biens_fields.
foreach ($diagFields as $diagCol => $diagVal) {
    if (!isset($autoMapDiagToBien[$diagCol])) continue;
    $bienCol = $autoMapDiagToBien[$diagCol];
    if (array_key_exists($bienCol, $biensFields)) continue; // Déjà fourni explicitement
    $biensFields[$bienCol] = $diagVal;
}

try {
    $pdo->beginTransaction();

    // UPDATE dpe_diags
    $diagSet = [];
    $diagParams = [':_id' => $diagId];
    foreach ($diagFields as $col => $val) {
        if (!in_array($col, $diagAllowedCols, true)) continue;
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $col)) continue;
        $diagSet[] = "`$col` = :$col";
        $diagParams[":$col"] = ($val === '' ? null : $val);
    }
    $diagCount = 0;
    if (!empty($diagSet)) {
        $sql = "UPDATE dpe_diags SET " . implode(', ', $diagSet) . ", date_modification = NOW() WHERE id = :_id";
        $st = $pdo->prepare($sql);
        $st->execute($diagParams);
        $diagCount = $st->rowCount();
    }

    // UPDATE biens (si bienId fourni) — inclut désormais les champs
    // auto-mappés depuis diag_fields
    $biensCount = 0;
    $biensSet = [];
    if ($bienId > 0) {
        $biensParams = [':_id' => $bienId];
        foreach ($biensFields as $col => $val) {
            if (!in_array($col, $biensAllowedCols, true)) continue;
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $col)) continue;
            $biensSet[] = "`$col` = :$col";
            $biensParams[":$col"] = ($val === '' ? null : $val);
        }
        if (!empty($biensSet)) {
            $sql = "UPDATE biens SET " . implode(', ', $biensSet) . ", date_modification = NOW() WHERE id = :_id";
            $st = $pdo->prepare($sql);
            $st->execute($biensParams);
            $biensCount = $st->rowCount();
        }
    }

    $pdo->commit();

    // Auto-activation brouillon -> actif si adresse + proprietaire reunis
    $autoActivated = false;
    if ($bienId > 0) {
        require_once __DIR__ . '/../inc/bien_auto_activate.php';
        $autoActivated = bien_maybe_activate($pdo, $bienId);
    }

    echo json_encode([
        'ok' => true,
        'diag_updated'        => count($diagSet),
        'biens_updated'       => count($biensSet),
        'diag_rows_affected'  => $diagCount,
        'biens_rows_affected' => $biensCount,
        'auto_activated'      => $autoActivated,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    error_log('[dpe_diag_update] ' . $e->getMessage());
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
