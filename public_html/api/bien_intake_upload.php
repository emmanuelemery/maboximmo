<?php
declare(strict_types=1);
set_time_limit(120);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/bien_import_parser.php';
require_once dirname(__DIR__) . '/inc/bien_intake_ia.php';
require_once dirname(__DIR__) . '/inc/bien_intake_ocr.php';
require_once dirname(__DIR__) . '/inc/bien_intake_search.php';
require_once dirname(__DIR__) . '/inc/bien_form_loader.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$agenceId  = (int)($_SESSION['id_agence']  ?? 0);
$userId    = (int)($_SESSION['user_id']    ?? 0);
function bien_intake_google_geocode(string $query, string $apiKey): array
{
    $query = trim($query);
    $apiKey = trim($apiKey);
    if ($query === '' || $apiKey === '') {
        return ['ok' => false, 'error' => 'missing_query_or_key'];
    }

    $params = [
        'address' => $query,
        'key' => $apiKey,
    ];

    $url = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => $err];
    }

    $data = json_decode($response, true);
    if (!is_array($data) || ($data['status'] ?? '') !== 'OK' || empty($data['results'][0])) {
        return ['ok' => false, 'error' => (string)($data['status'] ?? 'unknown')];
    }

    $result = $data['results'][0];
    $components = $result['address_components'] ?? [];

    $getComponent = static function (string $type) use ($components): string {
        foreach ($components as $c) {
            if (!empty($c['types']) && in_array($type, $c['types'], true)) {
                return (string)($c['long_name'] ?? '');
            }
        }
        return '';
    };

    $streetNumber = $getComponent('street_number');
    $route = $getComponent('route');
    $line1 = trim($streetNumber . ' ' . $route);
    if ($line1 === '') {
        $line1 = (string)($result['formatted_address'] ?? '');
    }

    $postal = $getComponent('postal_code');
    $city = $getComponent('locality');
    if ($city === '') {
        $city = $getComponent('postal_town');
    }
    if ($city === '') {
        $city = $getComponent('administrative_area_level_2');
    }

    $lat = $result['geometry']['location']['lat'] ?? null;
    $lng = $result['geometry']['location']['lng'] ?? null;

    return [
        'ok' => true,
        'adresse_1' => $line1,
        'adresse_2' => '',
        'code_postal' => $postal,
        'ville' => $city,
        'latitude' => $lat,
        'longitude' => $lng,
        'adresse_formatee' => $result['formatted_address'] ?? '',
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Méthode non autorisée']));
}
verify_csrf_any('ajouter_bien');

if (empty($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
    exit(json_encode(['ok' => false, 'error' => 'Aucun fichier reçu']));
}
$file = $_FILES['fichier'];
if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'pdf') {
    exit(json_encode(['ok' => false, 'error' => 'Format non supporté (PDF uniquement)']));
}
// Validation MIME réelle (pas seulement l'extension — un .exe renommé en .pdf serait détecté)
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$realMime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
if ($realMime !== 'application/pdf') {
    exit(json_encode(['ok' => false, 'error' => "Type MIME invalide ({$realMime}). PDF requis."]));
}
if ($file['size'] > 20 * 1024 * 1024) {
    exit(json_encode(['ok' => false, 'error' => 'Fichier trop volumineux (max 20 Mo)']));
}

// ── (1) Si pas de bien fourni, on crée un brouillon à la volée ────
$bienId = isset($_POST['id_bien']) && ctype_digit((string)$_POST['id_bien']) ? (int)$_POST['id_bien'] : 0;
$createdNow = false;
if ($bienId === 0) {
    try {
        $bienId = bien_form_create_draft($pdo, $societeId ?: null, $agenceId ?: null);
        $createdNow = true;
    } catch (Throwable $e) {
        exit(json_encode(['ok' => false, 'error' => 'Création brouillon : ' . $e->getMessage()]));
    }
}

// ── (2) Stockage du fichier ───────────────────────────────────────
$uploadDir = dirname(__DIR__) . '/uploads/biens_docs/';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);
$safeName  = 'intake_' . $bienId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
$destPath  = $uploadDir . $safeName;
$publicUrl = '/uploads/biens_docs/' . $safeName;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    exit(json_encode(['ok' => false, 'error' => 'Déplacement fichier impossible']));
}

try {
    // ── (3) Extraction texte ─────────────────────────────────────
    $texteSource = BienImportParser::extractText($destPath);
    $usedOcr = false;
    $iaResult = null;

    // Seuil de détection : moins de 200 caractères = probable PDF scanné
    $isProbablyScanned = (mb_strlen(trim($texteSource)) < 200);

    if (!$isProbablyScanned) {
        // ── (4a) Analyse texte rapide (chemin classique) ──────
        $iaResult = analyseBienIntakeIA($texteSource);
        // Si l'IA n'a quasiment rien trouvé, on bascule en OCR
        if (!$iaResult['ok'] || ($iaResult['count'] ?? 0) < 3) {
            $isProbablyScanned = true;
        }
    }

    if ($isProbablyScanned) {
        // ── (4b) Mode OCR Vision : conversion PDF → images → GPT-4o Vision ──
        try {
            $tmpOcrDir = dirname(__DIR__) . '/uploads/_ocr_tmp/' . $bienId . '_' . time();
            $images = BienIntakeOCR::pdfToImages($destPath, $tmpOcrDir, 8);
            if (empty($images)) {
                throw new RuntimeException('Aucune image générée par pdftoppm');
            }
            $iaResult = BienIntakeOCR::analyseImagesIA($images);
            BienIntakeOCR::cleanupTmpDir($tmpOcrDir);
            $usedOcr = true;
        } catch (Throwable $ocrEx) {
            // Si l'OCR échoue mais qu'on avait un peu de texte, on garde ce qu'on a
            if (!$iaResult || !$iaResult['ok']) {
                exit(json_encode([
                    'ok' => false,
                    'error' => 'OCR échoué : ' . $ocrEx->getMessage()
                        . ' — Le PDF est probablement scanné et la conversion en image n\'a pas fonctionné.',
                    'fichier' => $publicUrl,
                    'bien_id' => $bienId,
                ]));
            }
        }
    }

    if (!$iaResult || !$iaResult['ok']) {
        exit(json_encode([
            'ok' => false,
            'error' => 'IA : ' . ($iaResult['error'] ?? 'inconnue'),
            'fichier' => $publicUrl,
            'bien_id' => $bienId,
        ]));
    }

    $fields   = $iaResult['fields'];
    $adresseDoc = [
        'adresse_1'   => $fields['adresse_1']   ?? null,
        'code_postal' => $fields['code_postal'] ?? null,
        'ville'       => $fields['ville']       ?? null,
    ];


    // Normalisation d'adresse via Google (l'adresse du document sert de requête, Google renvoie l'adresse "propre")
    $gmKey = (string)($GLOBALS['GOOGLE_MAPS_API_KEY'] ?? '');
    if ($gmKey !== '' && (!empty($fields['adresse_1']) || !empty($fields['code_postal']) || !empty($fields['ville']))) {
        $q = trim((string)($fields['adresse_1'] ?? '') . ' ' . (string)($fields['code_postal'] ?? '') . ' ' . (string)($fields['ville'] ?? '') . ' France');
        $geo = bien_intake_google_geocode($q, $gmKey);
        if (!empty($geo['ok'])) {
            if (!empty($geo['adresse_1'])) $fields['adresse_1'] = $geo['adresse_1'];
            if (!empty($geo['code_postal'])) $fields['code_postal'] = $geo['code_postal'];
            if (!empty($geo['ville'])) $fields['ville'] = $geo['ville'];
            if (array_key_exists('latitude', $geo)) $fields['geo_latitude'] = $geo['latitude'];
            if (array_key_exists('longitude', $geo)) $fields['geo_longitude'] = $geo['longitude'];
            if (!empty($geo['adresse_formatee'])) $fields['geo_adresse_formatee'] = $geo['adresse_formatee'];
        }
    }
    $docType  = $iaResult['doc_type'] ?? 'autre';
    $docTitre = $iaResult['doc_titre'] ?? null;
    $resume   = $iaResult['resume'] ?? null;

    // ── DPE vierge : auto-détection
    // Le DPE est considéré "vierge" :
    //   • soit quand l'IA l'a explicitement marqué (`dpe_vierge` = true)
    //   • soit quand le document est un DPE / dossier de diagnostics MAIS qu'aucune
    //     valeur (classe ou consommation) n'a été trouvée → on remplit tout en "vierge"
    $isDpeDoc = in_array($docType, ['dpe', 'dossier_diagnostics'], true);
    $noDpeValues = empty($fields['dpe_classe']) && empty($fields['ges_classe'])
                && empty($fields['dpe_valeur']) && empty($fields['ges_valeur']);
    if (!empty($fields['dpe_vierge']) || ($isDpeDoc && $noDpeValues)) {
        $fields['dpe_vierge'] = 1;
        if (empty($fields['dpe_classe'])) $fields['dpe_classe'] = 'vierge';
        if (empty($fields['ges_classe'])) $fields['ges_classe'] = 'vierge';
        if (!isset($fields['dpe_valeur']) || $fields['dpe_valeur'] === null || $fields['dpe_valeur'] === '') {
            $fields['dpe_valeur'] = 0;
        }
        if (!isset($fields['ges_valeur']) || $fields['ges_valeur'] === null || $fields['ges_valeur'] === '') {
            $fields['ges_valeur'] = 0;
        }
    }

    // ── (5) Persistance différenciée selon le type de document ──
    // Reconnexion MySQL si la connexion a expiré pendant l'appel OpenAI (15-30s)
    // (wait_timeout court sur Hostinger → "MySQL server has gone away")
    $pdo = db_keepalive();

    $diagId = 0;

    // Toujours : on insère une entrée dans dpe_diags pour tracer le doc analysé
    try {
        $stmtDiag = $pdo->prepare("
            INSERT INTO dpe_diags (
                id_bien, type_diag, est_diag_principal,
                date_diagnostic, dpe_classe, ges_classe, dpe_vierge,
                consommation_energie, emission_ges,
                conso_energie_primaire, conso_energie_finale,
                montant_depenses_min, montant_depenses_max,
                date_indice_prix, dpe_version, numero_ademe,
                diagnostiqueur_nom, diagnostiqueur_societe,
                fichier_url, nom_fichier_original, taille_fichier_octets, mime_type,
                type_bien_detecte, adresse_detectee, code_postal_detecte, ville_detectee,
                etage_detecte, lot_detecte, annee_construction_detectee,
                surface_habitable_detectee, surface_carrez_detectee, surface_sejour_detectee,
                nb_pieces_detecte, nb_chambres_detecte, nb_salles_bain_detecte,
                nb_salles_eau_detecte, nb_wc_detecte,
                chauffage_type_detecte, chauffage_energie_detecte, eau_chaude_type_detecte,
                double_vitrage_detecte, volets_roulants_detecte, menuiseries_detectees,
                altitude_detectee,
                alerte_plomb_present, alerte_amiante_present, alerte_electricite_anomalies,
                alerte_gaz_anomalies, alerte_termites, alerte_zone_georisque,
                extraction_method, extraction_score, extraction_date,
                champs_extraits_json, resume_bailleur, commentaire,
                date_creation, date_modification
            ) VALUES (
                :id_bien, :type_diag, 0,
                :date, :dpec, :gesc, :vierge,
                :conso, :ges,
                :primaire, :finale,
                :depmin, :depmax,
                :indice, :ver, :ademe,
                :diag_nom, :diag_soc,
                :url, :nom, :taille, :mime,
                :tb, :adr, :cp, :ville,
                :etage, :lot, :annee,
                :sh, :sc, :ss,
                :np, :nc, :nsb,
                :nse, :nwc,
                :ct, :ce, :ec,
                :dv, :vr, :menui,
                :alt,
                :a_plomb, :a_amiante, :a_elec,
                :a_gaz, :a_termites, :a_geo,
                'ia', :score, NOW(),
                :json, :resume, :titre,
                NOW(), NOW()
            )
        ");
        $stmtDiag->execute([
            ':id_bien' => $bienId,
            ':type_diag' => $docType,
            ':date'    => $fields['dpe_date_realisation'] ?? ($fields['date_signature'] ?? null),
            ':dpec'    => $fields['dpe_classe'] ?? null,
            ':gesc'    => $fields['ges_classe'] ?? null,
            ':vierge'  => !empty($fields['dpe_vierge']) ? 1 : 0,
            ':conso'   => $fields['dpe_valeur'] ?? null,
            ':ges'     => $fields['ges_valeur'] ?? null,
            ':primaire'=> $fields['dpe_valeur_conso_primaire'] ?? null,
            ':finale'  => $fields['dpe_valeur_conso_finale'] ?? null,
            ':depmin'  => $fields['montant_estime_depenses_min'] ?? null,
            ':depmax'  => $fields['montant_estime_depenses_max'] ?? null,
            ':indice'  => $fields['date_indice_prix_energies'] ?? null,
            ':ver'     => $fields['dpe_version'] ?? null,
            ':ademe'   => $fields['dpe_reference_certificat'] ?? null,
            ':diag_nom'=> $fields['operateur_nom'] ?? null,
            ':diag_soc'=> $fields['operateur_societe'] ?? null,
            ':url'     => $publicUrl,
            ':nom'     => $file['name'],
            ':taille'  => (int)$file['size'],
            ':mime'    => $file['type'] ?? 'application/pdf',
            ':tb'      => $fields['type_bien'] ?? null,
            ':adr'     => $adresseDoc['adresse_1'] ?? null,
            ':cp'      => $adresseDoc['code_postal'] ?? null,
            ':ville'   => $adresseDoc['ville'] ?? null,
            ':etage'   => $fields['etage'] ?? null,
            ':lot'     => $fields['lot_principal'] ?? null,
            ':annee'   => $fields['annee_construction'] ?? null,
            ':sh'      => $fields['surface_habitable'] ?? null,
            ':sc'      => $fields['surface_carrez'] ?? null,
            ':ss'      => $fields['surface_sejour'] ?? null,
            ':np'      => $fields['nb_pieces'] ?? null,
            ':nc'      => $fields['nb_chambres'] ?? null,
            ':nsb'     => $fields['nb_salles_bain'] ?? null,
            ':nse'     => $fields['nb_salles_eau'] ?? null,
            ':nwc'     => $fields['nb_wc'] ?? null,
            ':ct'      => $fields['chauffage_type'] ?? null,
            ':ce'      => $fields['chauffage_energie'] ?? null,
            ':ec'      => $fields['eau_chaude_type'] ?? null,
            ':dv'      => !empty($fields['double_vitrage']) ? 1 : 0,
            ':vr'      => !empty($fields['volets_roulants']) ? 1 : 0,
            ':menui'   => $fields['menuiseries'] ?? null,
            ':alt'     => $fields['altitude'] ?? null,
            ':a_plomb' => !empty($fields['plomb_present']) ? 1 : 0,
            ':a_amiante' => !empty($fields['amiante_present']) ? 1 : 0,
            ':a_elec'  => !empty($fields['electricite_anomalies']) ? 1 : 0,
            ':a_gaz'   => !empty($fields['gaz_anomalies']) ? 1 : 0,
            ':a_termites' => !empty($fields['termites']) ? 1 : 0,
            ':a_geo'   => !empty($fields['zone_georisque']) ? 1 : 0,
            ':score'   => min(100, count($fields) * 4),
            ':json'    => json_encode($fields, JSON_UNESCAPED_UNICODE),
            ':resume'  => $resume,
            ':titre'   => $docTitre,
        ]);
        $diagId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('[bien_intake] dpe_diags insert failed: ' . $e->getMessage());
    }

    // ── (6) Sync biens.* (no-overwrite : ne touche pas les valeurs déjà saisies) ──
    $bienSyncMap = [
        // Adresse du BIEN (≠ adresse propriétaire qui est stockée séparément avec préfixe proprio_)
        // NB : `pays` n'existe pas sur biens, on l'ignore — il vit sur immeubles
        'adresse_1'   => $fields['adresse_1']   ?? null,
        'adresse_2'   => $fields['adresse_2']   ?? null,
        'code_postal' => $fields['code_postal'] ?? null,
        'ville'       => $fields['ville']       ?? null,
        'dpe_classe' => $fields['dpe_classe'] ?? null,
        'ges_classe' => $fields['ges_classe'] ?? null,
        'dpe_valeur' => $fields['dpe_valeur'] ?? null,
        'ges_valeur' => $fields['ges_valeur'] ?? null,
        'dpe_valeur_conso_primaire' => $fields['dpe_valeur_conso_primaire'] ?? null,
        'dpe_valeur_conso_finale' => $fields['dpe_valeur_conso_finale'] ?? null,
        'date_indice_prix_energies' => $fields['date_indice_prix_energies'] ?? null,
        'altitude' => $fields['altitude'] ?? null,
        'dpe_date_realisation' => $fields['dpe_date_realisation'] ?? null,
        'dpe_version' => $fields['dpe_version'] ?? null,
        'dpe_vierge' => !empty($fields['dpe_vierge']) ? 1 : null,
        'dpe_reference_certificat' => $fields['dpe_reference_certificat'] ?? null,
        'montant_estime_depenses_min' => $fields['montant_estime_depenses_min'] ?? null,
        'montant_estime_depenses_max' => $fields['montant_estime_depenses_max'] ?? null,
        'surface_habitable' => $fields['surface_habitable'] ?? null,
        'surface_sejour' => $fields['surface_sejour'] ?? null,
        'surface_carrez' => $fields['surface_carrez'] ?? null,
        'nb_pieces' => $fields['nb_pieces'] ?? null,
        'nb_chambres' => $fields['nb_chambres'] ?? null,
        'nb_salles_bain' => $fields['nb_salles_bain'] ?? null,
        'nb_salles_eau' => $fields['nb_salles_eau'] ?? null,
        'nb_wc' => $fields['nb_wc'] ?? null,
        'etage' => $fields['etage'] ?? null,
        'annee_construction' => $fields['annee_construction'] ?? null,
        'chauffage_type' => $fields['chauffage_type'] ?? null,
        'chauffage_energie' => $fields['chauffage_energie'] ?? null,
        'eau_chaude_type' => $fields['eau_chaude_type'] ?? null,
        'double_vitrage' => !empty($fields['double_vitrage']) ? 1 : null,
        'volets_roulants' => !empty($fields['volets_roulants']) ? 1 : null,
        'menuiseries' => $fields['menuiseries'] ?? null,
        'zone_georisque' => !empty($fields['zone_georisque']) ? 1 : null,
        'designation' => $fields['designation'] ?? null,
        'reference_bien' => $fields['reference_bien'] ?? null,
    ];
    $setParts = []; $setParams = [];
    $boolCols = ['dpe_vierge','double_vitrage','volets_roulants','zone_georisque'];
    foreach ($bienSyncMap as $col => $val) {
        if ($val === null || $val === '') continue;
        if (in_array($col, $boolCols, true)) {
            $setParts[] = "`$col` = CASE WHEN `$col` = 0 THEN :v_$col ELSE `$col` END";
        } else {
            $setParts[] = "`$col` = COALESCE(NULLIF(`$col`, ''), :v_$col)";
        }
        $setParams[":v_$col"] = $val;
    }
    if (!empty($fields['type_bien'])) {
        try {
            $tStmt = $pdo->prepare("SELECT id FROM types_bien WHERE code = ? LIMIT 1");
            $tStmt->execute([$fields['type_bien']]);
            $tId = (int)$tStmt->fetchColumn();
            if ($tId > 0) {
                $setParts[] = "`id_type_bien` = COALESCE(NULLIF(`id_type_bien`, 1), :v_idtb)";
                $setParams[':v_idtb'] = $tId;
            }
        } catch (Throwable) {}
    }
    if (!empty($setParts)) {
        $setParams[':_id'] = $bienId;
        try {
            $pdo->prepare("UPDATE biens SET " . implode(', ', $setParts) . " WHERE id = :_id")->execute($setParams);
        } catch (Throwable $e) {
            error_log('[bien_intake] biens sync failed: ' . $e->getMessage());
        }
    }

    // ── (7) IMMEUBLE — recherche fuzzy, JAMAIS de création automatique ──
    // L'utilisateur doit valider explicitement la sélection ou la création.
    // Seule exception : match très fort (≥ 80%) → liaison automatique sans création.
    $immeublesCandidats = [];
    $immeubleAutoLink = 0;
    $immeubleSuggested = null; // Données extraites prêtes à créer (sur clic utilisateur)
    if (!empty($fields['adresse_1']) || !empty($fields['code_postal']) || !empty($fields['ville'])) {
        $stmtCurImm = $pdo->prepare("SELECT id_immeuble FROM biens WHERE id = ?");
        $stmtCurImm->execute([$bienId]);
        $currentImmId = (int)$stmtCurImm->fetchColumn();

        $immeublesCandidats = BienIntakeSearch::searchImmeubles($pdo, $fields, $agenceId ?: null, 8);

        if ($currentImmId > 0) {
            $immeubleAutoLink = $currentImmId;
        } elseif (!empty($immeublesCandidats) && ($immeublesCandidats[0]['score'] ?? 0) >= 80) {
            // Match fort → liaison automatique (zéro doublon car ID existant)
            $immeubleAutoLink = (int)$immeublesCandidats[0]['id'];
            try {
                $pdo->prepare("UPDATE biens SET id_immeuble = ? WHERE id = ?")
                    ->execute([$immeubleAutoLink, $bienId]);
            } catch (Throwable) {}
        }
        // Toujours préparer la suggestion à créer (pour le bouton "Créer ce nouvel immeuble")
        $immeubleSuggested = [
            'adresse_1'   => $fields['adresse_1']  ?? null,
            'adresse_2'   => $fields['adresse_2']  ?? null,
            'code_postal' => $fields['code_postal'] ?? null,
            'ville'       => $fields['ville']       ?? null,
            'pays'        => $fields['pays']        ?? 'France',
        ];
    }

    // ── (8) PROPRIÉTAIRE — recherche fuzzy, JAMAIS de création automatique ──
    // Les champs propriétaire sont maintenant préfixés `proprio_*` dans $fields
    // (pour ne pas écraser l'adresse du bien dans `_adresse`).
    $proprietairesCandidats = [];
    $proprioAutoLink = 0;
    $proprioSuggested = null;
    if (!empty($fields['proprio_nom']) || !empty($fields['proprio_societe']) || !empty($fields['proprio_email']) || !empty($fields['proprio_telephone'])) {
        $stmtCurPro = $pdo->prepare("SELECT id_proprietaire FROM biens WHERE id = ?");
        $stmtCurPro->execute([$bienId]);
        $currentProId = (int)$stmtCurPro->fetchColumn();

        // Pour la recherche fuzzy, on construit un sous-tableau avec les noms attendus par le helper
        $searchData = [
            'nom'       => $fields['proprio_nom']       ?? null,
            'prenom'    => $fields['proprio_prenom']    ?? null,
            'societe'   => $fields['proprio_societe']   ?? null,
            'email'     => $fields['proprio_email']     ?? null,
            'telephone' => $fields['proprio_telephone'] ?? null,
            'ville'     => $fields['proprio_ville']     ?? null,
        ];
        $proprietairesCandidats = BienIntakeSearch::searchProprietaires($pdo, $searchData, $agenceId ?: null, 8);

        if ($currentProId > 0) {
            $proprioAutoLink = $currentProId;
        } elseif (!empty($proprietairesCandidats) && ($proprietairesCandidats[0]['score'] ?? 0) >= 80) {
            $proprioAutoLink = (int)$proprietairesCandidats[0]['id'];
            try {
                $pdo->prepare("UPDATE biens SET id_proprietaire = ? WHERE id = ?")
                    ->execute([$proprioAutoLink, $bienId]);
            } catch (Throwable) {}
        }
        $proprioSuggested = [
            'type_personne' => $fields['proprio_type_personne'] ?? 'physique',
            'civilite'      => $fields['proprio_civilite']      ?? null,
            'nom'           => $fields['proprio_nom']           ?? ($fields['proprio_societe'] ?? null),
            'prenom'        => $fields['proprio_prenom']        ?? null,
            'societe'       => $fields['proprio_societe']       ?? null,
            'email'         => $fields['proprio_email']         ?? null,
            'telephone'     => $fields['proprio_telephone']     ?? null,
            'adresse_1'     => $fields['proprio_adresse_1']     ?? null,
            'code_postal'   => $fields['proprio_code_postal']   ?? null,
            'ville'         => $fields['proprio_ville']         ?? null,
        ];
    }
    $newProprioId = $proprioAutoLink;

    // ── (8.5) ANNONCE — création/sync avec type_transaction et prix ──
    // Déduit le type de transaction depuis :
    //   1. doc_type (mandat_vente, mandat_location, mandat_gestion)
    //   2. fields.type_mandat (vente, location, gestion)
    //   3. fields.prix_vente / fields.loyer_hc
    $deducedTransaction = null;
    if (in_array($docType, ['mandat_vente'], true)) {
        $deducedTransaction = 'vente';
    } elseif (in_array($docType, ['mandat_location','mandat_gestion'], true)) {
        $deducedTransaction = 'location';
    } elseif (!empty($fields['type_mandat'])) {
        if ($fields['type_mandat'] === 'vente') $deducedTransaction = 'vente';
        elseif (in_array($fields['type_mandat'], ['location','gestion'], true)) $deducedTransaction = 'location';
    } elseif (!empty($fields['prix_vente'])) {
        $deducedTransaction = 'vente';
    } elseif (!empty($fields['loyer_hc'])) {
        $deducedTransaction = 'location';
    }

    // Si on a au moins un type ou un prix → créer/mettre à jour l'annonce
    $annoncePrix    = isset($fields['prix_vente'])  ? (float)$fields['prix_vente']  : null;
    $annonceLoyer   = isset($fields['loyer_hc'])    ? (float)$fields['loyer_hc']    : null;
    $annonceCharges = isset($fields['charges_locatives']) ? (float)$fields['charges_locatives'] : null;
    $annonceDepot   = isset($fields['depot_garantie'])    ? (float)$fields['depot_garantie']    : null;

    if ($deducedTransaction !== null || $annoncePrix !== null || $annonceLoyer !== null) {
        try {
            // Cherche une annonce existante pour ce bien
            $stmtFindA = $pdo->prepare("SELECT id FROM annonces WHERE id_bien = ? ORDER BY id DESC LIMIT 1");
            $stmtFindA->execute([$bienId]);
            $existingAnnId = (int)$stmtFindA->fetchColumn();

            if ($existingAnnId > 0) {
                // UPDATE non-destructif (COALESCE) — n'écrase pas les valeurs déjà présentes
                $sets = [];
                $params = [];
                if ($deducedTransaction) {
                    $sets[] = "type_transaction = COALESCE(NULLIF(type_transaction, ''), :tt)";
                    $params[':tt'] = $deducedTransaction;
                }
                if ($annoncePrix !== null) {
                    $sets[] = "prix = COALESCE(prix, :prix)";
                    $params[':prix'] = $annoncePrix;
                }
                if ($annonceLoyer !== null) {
                    $sets[] = "loyer = COALESCE(loyer, :loy)";
                    $params[':loy'] = $annonceLoyer;
                }
                if ($annonceCharges !== null) {
                    $sets[] = "charges = COALESCE(charges, :ch)";
                    $params[':ch'] = $annonceCharges;
                }
                if ($annonceDepot !== null) {
                    $sets[] = "depot_garantie = COALESCE(depot_garantie, :dg)";
                    $params[':dg'] = $annonceDepot;
                }
                if (!empty($sets)) {
                    $sets[] = "date_modification = NOW()";
                    $params[':id'] = $existingAnnId;
                    $pdo->prepare("UPDATE annonces SET " . implode(', ', $sets) . " WHERE id = :id")
                        ->execute($params);
                }
            } else {
                // INSERT nouvelle annonce
                $refAnnonce = 'ANN-' . date('Y') . '-' . str_pad((string)$bienId, 4, '0', STR_PAD_LEFT);
                $pdo->prepare("
                    INSERT INTO annonces
                        (id_bien, id_agence, id_societe, reference_annonce,
                         type_transaction, prix, loyer, charges, depot_garantie,
                         statut, date_creation, date_modification)
                    VALUES
                        (?, ?, ?, ?, ?, ?, ?, ?, ?, 'brouillon', NOW(), NOW())
                ")->execute([
                    $bienId,
                    $agenceId ?: null,
                    $societeId ?: null,
                    $refAnnonce,
                    $deducedTransaction ?: 'vente',
                    $annoncePrix,
                    $annonceLoyer,
                    $annonceCharges,
                    $annonceDepot,
                ]);
            }
        } catch (Throwable $e) {
            error_log('[bien_intake] annonce sync failed: ' . $e->getMessage());
        }
    }

    // ── (9) Mandat — création si extrait ──
    if (!empty($fields['type_mandat'])) {
        try {
            $find = $pdo->prepare("SELECT id FROM mandats WHERE id_bien = ? LIMIT 1");
            $find->execute([$bienId]);
            $existingMandat = (int)$find->fetchColumn();
            if ($existingMandat === 0) {
                $insM = $pdo->prepare("INSERT INTO mandats (id_bien, id_proprietaire, id_agence, numero_mandat, type_mandat, nature_mandat, exclusif, date_signature, date_debut, date_fin, honoraires, statut) VALUES (?,?,?,?,?,?,?,?,?,?,?,'actif')");
                $insM->execute([
                    $bienId,
                    $newProprioId ?: null,
                    $agenceId ?: null,
                    $fields['numero_mandat'] ?? ('AUTO-' . date('Y') . '-' . $bienId),
                    $fields['type_mandat'],
                    $fields['nature_mandat'] ?? null,
                    ($fields['nature_mandat'] ?? '') === 'exclusif' ? 1 : 0,
                    $fields['date_signature'] ?? null,
                    $fields['date_debut'] ?? null,
                    $fields['date_fin'] ?? null,
                    $fields['honoraires'] ?? null,
                ]);
            }
        } catch (Throwable $e) {
            error_log('[bien_intake] mandat create failed: ' . $e->getMessage());
        }
    }

    echo json_encode([
        'ok'         => true,
        'bien_id'    => $bienId,
        'created_now'=> $createdNow,
        'diag_id'    => $diagId,
        'doc_type'   => $docType,
        'doc_titre'  => $docTitre,
        'resume'     => $resume,
        'fields'     => $fields,
        'count'      => count($fields),
        // URL préfixée avec le base path de l'app pour être directement utilisable côté navigateur
        'fichier'    => function_exists('app_url') ? app_url($publicUrl) : $publicUrl,
        'fichier_relatif' => $publicUrl,
        'nom'        => $file['name'],
        'used_ocr'   => $usedOcr,
        'method'     => $usedOcr ? 'ocr_vision' : 'text_extract',
        'proprietaires_candidats' => $proprietairesCandidats,
        'proprietaire_auto_link'  => $proprioAutoLink,
        'proprietaire_suggested'  => $proprioSuggested,
        'immeubles_candidats'     => $immeublesCandidats,
        'immeuble_auto_link'      => $immeubleAutoLink,
        'immeuble_suggested'      => $immeubleSuggested,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    @unlink($destPath);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'bien_id' => $bienId]);
}










