<?php
declare(strict_types=1);
// 120s : l'OCR Vision multi-pages peut prendre 30-60s selon le PDF
set_time_limit(120);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/bien_import_parser.php';
require_once dirname(__DIR__) . '/inc/dpe_import_parser.php';
require_once dirname(__DIR__) . '/inc/dpe_ia_analyse.php';
require_once dirname(__DIR__) . '/inc/bien_intake_ocr.php'; // fallback OCR Vision GPT-4o pour PDF scannés
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$userId    = (int)($_SESSION['user_id']    ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Méthode non autorisée']));
}

verify_csrf_any('ajouter_bien');

if (empty($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
    exit(json_encode(['ok' => false, 'error' => 'Aucun fichier reçu']));
}

$file = $_FILES['fichier'];
$ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($ext !== 'pdf') {
    exit(json_encode(['ok' => false, 'error' => 'Format non supporté (PDF uniquement)']));
}
// Validation MIME réelle (pas seulement extension)
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$realMime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
if ($realMime !== 'application/pdf') {
    exit(json_encode(['ok' => false, 'error' => "Type MIME invalide ({$realMime}). PDF requis."]));
}
if ($file['size'] > 20 * 1024 * 1024) {
    exit(json_encode(['ok' => false, 'error' => 'Fichier trop volumineux (max 20 Mo)']));
}

// ── Stockage du fichier ───────────────────────────────────────
$bienId = isset($_POST['id_bien']) && ctype_digit((string)$_POST['id_bien']) ? (int)$_POST['id_bien'] : 0;
$uploadDir = dirname(__DIR__) . '/uploads/biens_docs/';
if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);

$safeName = 'dpe_' . ($bienId > 0 ? $bienId . '_' : '') . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
$destPath = $uploadDir . $safeName;
$publicUrl = '/uploads/biens_docs/' . $safeName;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    exit(json_encode(['ok' => false, 'error' => 'Déplacement du fichier impossible']));
}

try {
    // ── Extraction texte ─────────────────────────────────────
    $texteSource = BienImportParser::extractText($destPath);
    $textLen     = mb_strlen(trim($texteSource));

    // ── Détection PDF scanné ─────────────────────────────────
    // Si on a moins de 200 caractères extraits, c'est presque certainement
    // un PDF scanné (image). Bascule directe sur OCR Vision GPT-4o.
    $isProbablyScanned = ($textLen < 200);
    $usedOcr           = false;
    $ocrError          = null;

    $fields = [];
    $score  = 0;
    $method = 'regex';
    $iaError = null;

    if ($isProbablyScanned) {
        // ═══ PATH A : OCR Vision (pdftoppm → images JPEG → GPT-4o Vision) ═══
        // Pattern identique à api/bien_intake_upload.php (module partagé BienIntakeOCR)
        try {
            $tmpOcrDir = dirname(__DIR__) . '/uploads/_ocr_tmp/dpe_' . ($bienId > 0 ? $bienId . '_' : '') . time();
            $images = BienIntakeOCR::pdfToImages($destPath, $tmpOcrDir, 8);
            if (empty($images)) {
                throw new RuntimeException('Aucune image générée par pdftoppm (Poppler manquant ?)');
            }
            $ocrResult = BienIntakeOCR::analyseImagesIA($images);
            BienIntakeOCR::cleanupTmpDir($tmpOcrDir);

            if (!$ocrResult['ok']) {
                throw new RuntimeException('OCR Vision : ' . ($ocrResult['error'] ?? 'inconnue'));
            }

            $fields    = $ocrResult['fields'] ?? [];
            $usedOcr   = true;
            $method    = 'ocr_vision';
            // Score conservateur : 85 si OCR a trouvé des champs, 30 sinon
            $score     = count($fields) >= 3 ? 85 : 30;
        } catch (Throwable $ocrEx) {
            $ocrError = $ocrEx->getMessage();
            error_log('[dpe_import] OCR Vision failed: ' . $ocrError);
            // Si l'OCR échoue, on renvoie une erreur explicite (pas de texte, pas d'OCR → impossible)
            exit(json_encode([
                'ok'      => false,
                'error'   => 'PDF scanné non analysable : ' . $ocrError,
                'fichier' => $publicUrl,
                'nom'     => $file['name'],
                'used_ocr'=> true,
                'method'  => 'ocr_vision_failed',
            ], JSON_UNESCAPED_UNICODE));
        }
    } else {
        // ═══ PATH B : extraction texte native + regex + IA fallback ═══
        $regexResult = DpeImportParser::parse($texteSource);
        $fields      = $regexResult['fields'];
        $score       = $regexResult['score'];
        $method      = 'regex';

        // 2) Si regex insuffisant, on appelle GPT-4o pour compléter
        //    (seuil : score < 80% OU moins de 6 champs détectés)
        $needsAI = ($score < 80 || count($fields) < 6);
        if ($needsAI) {
            $iaResult = analyseDpeIA($texteSource);
            if ($iaResult['ok'] && !empty($iaResult['fields'])) {
                foreach ($iaResult['fields'] as $k => $v) {
                    if (!isset($fields[$k]) || $fields[$k] === null || $fields[$k] === '') {
                        $fields[$k] = $v;
                    }
                }
                // Score recalculé via le validateur étendu (poids cohérent)
                $allKeys = [
                    'type_bien'=>4,'adresse_1'=>3,'code_postal'=>3,'ville'=>3,
                    'annee_construction'=>3,'etage'=>1,
                    'surface_habitable'=>4,'nb_pieces'=>3,'nb_chambres'=>3,
                    'nb_wc'=>2,'nb_salles_bain'=>2,'surface_sejour'=>1,
                    'dpe_classe'=>4,'ges_classe'=>4,'dpe_valeur'=>3,'ges_valeur'=>3,
                    'dpe_date_realisation'=>3,'dpe_reference_certificat'=>2,'dpe_vierge'=>4,
                    'chauffage_energie'=>1,'eau_chaude_type'=>1,'menuiseries'=>1,
                    'double_vitrage'=>1,'volets_roulants'=>1,
                    'montant_estime_depenses_min'=>1,'montant_estime_depenses_max'=>1,
                    'zone_georisque'=>1,
                ];
                $totalW = array_sum($allKeys); $reachedW = 0;
                foreach ($allKeys as $k => $w) if (!empty($fields[$k])) $reachedW += $w;
                $score = $totalW > 0 ? min(100, (int) round(($reachedW / $totalW) * 100)) : 0;
                $method = 'regex+ia';
            } else {
                $iaError = $iaResult['error'] ?? 'Erreur IA inconnue';
                error_log('[dpe_import] IA fallback failed: ' . $iaError);
            }
        }
    } // fin else (PATH B)

    // ── Enregistrement complet en dpe_diags (table dédiée) ───────
    // Reconnexion MySQL si la connexion a expiré pendant l'appel OpenAI
    // (wait_timeout court sur Hostinger → "MySQL server has gone away")
    $pdo = db_keepalive();

    $diagId = 0;
    if ($bienId > 0) {
        try {
            // Nouvelle entrée diagnostic = devient le diag principal,
            // les anciens passent en non-principal (historique)
            $pdo->prepare("UPDATE dpe_diags SET est_diag_principal = 0 WHERE id_bien = ?")
                ->execute([$bienId]);

            $stmtDiag = $pdo->prepare("
                INSERT INTO dpe_diags (
                    id_bien, type_diag, est_diag_principal,
                    date_diagnostic, dpe_version, dpe_vierge,
                    dpe_classe, ges_classe,
                    consommation_energie, conso_energie_primaire, conso_energie_finale,
                    emission_ges, montant_depenses_min, montant_depenses_max,
                    date_indice_prix, numero_ademe, numero_rapport,
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
                    alerte_plomb_present, alerte_plomb_classe_max, alerte_amiante_present,
                    alerte_electricite_anomalies, alerte_gaz_anomalies, alerte_termites,
                    alerte_zone_georisque, alerte_inondation,
                    extraction_method, extraction_score, extraction_date,
                    texte_extrait, champs_extraits_json, resume_bailleur,
                    date_creation, date_modification
                ) VALUES (
                    :id_bien, 'dossier_complet', 1,
                    :date_diag, :dpe_version, :dpe_vierge,
                    :dpe_classe, :ges_classe,
                    :conso_energie, :conso_primaire, :conso_finale,
                    :emission_ges, :dep_min, :dep_max,
                    :date_indice, :num_ademe, :num_rapport,
                    :diag_nom, :diag_societe,
                    :url, :nom_orig, :taille, :mime,
                    :type_bien, :adresse, :cp, :ville,
                    :etage, :lot, :annee,
                    :surf_hab, :surf_carrez, :surf_sejour,
                    :nb_p, :nb_ch, :nb_sdb,
                    :nb_se, :nb_wc,
                    :ch_type, :ch_energie, :ec_type,
                    :dv, :vr, :menui,
                    :altitude,
                    :a_plomb, :plomb_max, :a_amiante,
                    :a_elec, :a_gaz, :a_termites,
                    :a_geo, :a_inond,
                    :method, :score, NOW(),
                    :texte, :champs_json, :resume,
                    NOW(), NOW()
                )
            ");
            $stmtDiag->execute([
                ':id_bien' => $bienId,
                ':date_diag' => $fields['dpe_date_realisation'] ?? null,
                ':dpe_version' => $fields['dpe_version'] ?? null,
                ':dpe_vierge' => !empty($fields['dpe_vierge']) ? 1 : 0,
                ':dpe_classe' => $fields['dpe_classe'] ?? null,
                ':ges_classe' => $fields['ges_classe'] ?? null,
                ':conso_energie' => $fields['dpe_valeur'] ?? null,
                ':conso_primaire' => $fields['dpe_valeur_conso_primaire'] ?? null,
                ':conso_finale' => $fields['dpe_valeur_conso_finale'] ?? null,
                ':emission_ges' => $fields['ges_valeur'] ?? null,
                ':dep_min' => $fields['montant_estime_depenses_min'] ?? null,
                ':dep_max' => $fields['montant_estime_depenses_max'] ?? null,
                ':date_indice' => $fields['date_indice_prix_energies'] ?? null,
                ':num_ademe' => $fields['dpe_reference_certificat'] ?? null,
                ':num_rapport' => $fields['dpe_reference_certificat'] ?? null,
                ':diag_nom' => $fields['diag_operateur_nom'] ?? null,
                ':diag_societe' => $fields['diag_operateur_societe'] ?? null,
                ':url' => $publicUrl,
                ':nom_orig' => $file['name'],
                ':taille' => (int)$file['size'],
                ':mime' => $file['type'] ?? 'application/pdf',
                ':type_bien' => $fields['type_bien'] ?? null,
                ':adresse' => $fields['adresse_1'] ?? null,
                ':cp' => $fields['code_postal'] ?? null,
                ':ville' => $fields['ville'] ?? null,
                ':etage' => $fields['etage'] ?? null,
                ':lot' => $fields['lot_principal'] ?? null,
                ':annee' => $fields['annee_construction'] ?? null,
                ':surf_hab' => $fields['surface_habitable'] ?? null,
                ':surf_carrez' => $fields['surface_carrez'] ?? null,
                ':surf_sejour' => $fields['surface_sejour'] ?? null,
                ':nb_p' => $fields['nb_pieces'] ?? null,
                ':nb_ch' => $fields['nb_chambres'] ?? null,
                ':nb_sdb' => $fields['nb_salles_bain'] ?? null,
                ':nb_se' => $fields['nb_salles_eau'] ?? null,
                ':nb_wc' => $fields['nb_wc'] ?? null,
                ':ch_type' => $fields['chauffage_type'] ?? null,
                ':ch_energie' => $fields['chauffage_energie'] ?? null,
                ':ec_type' => $fields['eau_chaude_type'] ?? null,
                ':dv' => !empty($fields['double_vitrage']) ? 1 : 0,
                ':vr' => !empty($fields['volets_roulants']) ? 1 : 0,
                ':menui' => $fields['menuiseries'] ?? null,
                ':altitude' => $fields['altitude'] ?? null,
                ':a_plomb' => !empty($fields['diag_plomb_present']) ? 1 : 0,
                ':plomb_max' => $fields['diag_plomb_classe_max'] ?? null,
                ':a_amiante' => !empty($fields['diag_amiante_present']) ? 1 : 0,
                ':a_elec' => !empty($fields['diag_electricite_anomalies']) ? 1 : 0,
                ':a_gaz' => !empty($fields['diag_gaz_anomalies']) ? 1 : 0,
                ':a_termites' => !empty($fields['diag_termites']) ? 1 : 0,
                ':a_geo' => !empty($fields['zone_georisque']) ? 1 : 0,
                ':a_inond' => !empty($fields['zone_georisque']) ? 1 : 0,
                ':method' => $method,
                ':score' => $score,
                ':texte' => mb_substr($texteSource, 0, 60000),
                ':champs_json' => json_encode($fields, JSON_UNESCAPED_UNICODE),
                ':resume' => $fields['diag_resume_ia'] ?? null,
            ]);
            $diagId = (int)$pdo->lastInsertId();

            // ── Sync : on remonte les valeurs critiques sur la fiche bien ──
            // Les champs ne sont mis à jour QUE s'ils sont vides (no-overwrite),
            // pour respecter d'éventuelles saisies manuelles antérieures.
            $bienSyncMap = [
                'dpe_classe'                 => $fields['dpe_classe'] ?? null,
                'ges_classe'                 => $fields['ges_classe'] ?? null,
                'dpe_valeur'                 => $fields['dpe_valeur'] ?? null,
                'ges_valeur'                 => $fields['ges_valeur'] ?? null,
                'dpe_date_realisation'       => $fields['dpe_date_realisation'] ?? null,
                'dpe_version'                => $fields['dpe_version'] ?? null,
                'dpe_vierge'                 => !empty($fields['dpe_vierge']) ? 1 : null,
                'dpe_valeur_conso_primaire'  => $fields['dpe_valeur_conso_primaire'] ?? null,
                'dpe_valeur_conso_finale'    => $fields['dpe_valeur_conso_finale'] ?? null,
                'dpe_reference_certificat'   => $fields['dpe_reference_certificat'] ?? null,
                'altitude'                   => $fields['altitude'] ?? null,
                'date_indice_prix_energies'  => $fields['date_indice_prix_energies'] ?? null,
                'montant_estime_depenses_min' => $fields['montant_estime_depenses_min'] ?? null,
                'montant_estime_depenses_max' => $fields['montant_estime_depenses_max'] ?? null,
                'surface_habitable'          => $fields['surface_habitable'] ?? null,
                'surface_sejour'             => $fields['surface_sejour'] ?? null,
                'surface_carrez'             => $fields['surface_carrez'] ?? null,
                'nb_pieces'                  => $fields['nb_pieces'] ?? null,
                'nb_chambres'                => $fields['nb_chambres'] ?? null,
                'nb_salles_bain'             => $fields['nb_salles_bain'] ?? null,
                'nb_salles_eau'              => $fields['nb_salles_eau'] ?? null,
                'nb_wc'                      => $fields['nb_wc'] ?? null,
                'etage'                      => $fields['etage'] ?? null,
                'annee_construction'         => $fields['annee_construction'] ?? null,
                'chauffage_type'             => $fields['chauffage_type'] ?? null,
                'chauffage_energie'          => $fields['chauffage_energie'] ?? null,
                'eau_chaude_type'            => $fields['eau_chaude_type'] ?? null,
                'double_vitrage'             => !empty($fields['double_vitrage']) ? 1 : null,
                'volets_roulants'            => !empty($fields['volets_roulants']) ? 1 : null,
                'menuiseries'                => $fields['menuiseries'] ?? null,
                'zone_georisque'             => !empty($fields['zone_georisque']) ? 1 : null,
            ];

            $setParts = [];
            $setParams = [];
            foreach ($bienSyncMap as $col => $val) {
                if ($val === null || $val === '') continue;
                $setParts[] = "`$col` = COALESCE(NULLIF(`$col`, ''), :v_$col)";
                $setParams[":v_$col"] = $val;
            }
            // type_bien : trouver l'id à partir du code
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
                    $pdo->prepare("UPDATE biens SET " . implode(', ', $setParts) . " WHERE id = :_id")
                        ->execute($setParams);
                } catch (Throwable $e) {
                    error_log('[dpe_import] biens sync failed: ' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log('[dpe_import] dpe_diags insert failed: ' . $e->getMessage());
            $iaError = ($iaError ? $iaError . ' | ' : '') . 'BDD: ' . $e->getMessage();
        }
    }

    // Filtre les champs internes (diag_*) — déjà persistés en BDD,
    // pas besoin de les renvoyer au form HTML
    $fieldsForForm = [];
    foreach ($fields as $k => $v) {
        if (str_starts_with($k, 'diag_')) continue;
        if ($k === 'lot_principal') continue; // pas un champ form direct
        $fieldsForForm[$k] = $v;
    }

    echo json_encode([
        'ok'      => true,
        'fields'  => $fieldsForForm,
        'score'   => $score,
        'method'  => $method,               // 'regex' | 'regex+ia' | 'ocr_vision'
        'used_ocr'=> $usedOcr,              // true si fallback OCR Vision déclenché
        'text_length' => $textLen,          // taille texte natif (debug / UX)
        'ia_error' => $iaError,
        'diag_id' => $diagId,
        'fichier' => $publicUrl,
        'nom'     => $file['name'],
        'detected_count' => count($fieldsForForm),
        'resume_bailleur' => $fields['diag_resume_ia'] ?? null,
        'alertes' => [
            'plomb'      => !empty($fields['_alerte_plomb']),
            'amiante'    => !empty($fields['_alerte_amiante']),
            'electricite'=> !empty($fields['_alerte_electricite']),
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    @unlink($destPath);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
