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
require_once dirname(__DIR__) . '/inc/ged_document_links.php';  // Sprint 7D : dual-write GED centrale
require_once dirname(__DIR__) . '/inc/dpe_service.php';         // SERVICE DPE CENTRAL (analyse + stockage)
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
    // ── Extraction via le SERVICE DPE CENTRAL (inc/dpe_service.php) ──
    $resAna = dpe_analyser($destPath, $bienId);
    $texteSource = (string)($resAna['text'] ?? '');
    $textLen     = (int)($resAna['text_length'] ?? 0);
    $usedOcr     = !empty($resAna['used_ocr']);
    $method      = (string)($resAna['method'] ?? 'regex');
    $score       = (int)($resAna['score'] ?? 0);
    $iaError     = $resAna['ia_error'] ?? null;

    if (empty($resAna['ok'])) {
        // Extraction impossible (ex. PDF scanné sans OCR dispo) → erreur explicite
        exit(json_encode([
            'ok'      => false,
            'error'   => $resAna['error'] ?? 'Extraction DPE impossible.',
            'fichier' => $publicUrl,
            'nom'     => $file['name'],
            'used_ocr'=> $usedOcr,
            'method'  => $method ?: 'failed',
        ], JSON_UNESCAPED_UNICODE));
    }
    $fields = (array)$resAna['fields'];

    // ── Enregistrement complet en dpe_diags (table dédiée) ───────
    // Reconnexion MySQL si la connexion a expiré pendant l'appel OpenAI
    // (wait_timeout court sur Hostinger → "MySQL server has gone away")
    // Après l'OCR/IA qui peut durer 30-120s, on force un reconnect PDO frais
    // avant les INSERT critiques. Un simple SELECT 1 (db_keepalive) peut passer
    // puis le vrai INSERT tomber sur une connexion morte entre les deux.
    $diagId = 0;
    if ($bienId > 0) {
        // ── Stockage via le SERVICE DPE CENTRAL (inc/dpe_service.php) ──
        $stStore = dpe_enregistrer($pdo, $bienId, $fields, [
            'url'      => $publicUrl,
            'nom_orig' => $file['name'],
            'taille'   => (int)$file['size'],
            'mime'     => $file['type'] ?? 'application/pdf',
            'method'   => $method,
            'score'    => $score,
            'texte'    => $texteSource,
        ]);
        $diagId = (int)($stStore['diag_id'] ?? 0);
        if (empty($stStore['ok']) && !empty($stStore['error'])) {
            $iaError = ($iaError ? $iaError . ' | ' : '') . 'BDD: ' . $stStore['error'];
        }
        if (false) { // ancien stockage inline neutralisé — désormais dans dpe_service.php
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

            // ── Sync bien_chauffages / bien_energies (tables de jointure pour chips) ──
            try {
                $stSoc = $pdo->prepare("SELECT id_societe FROM biens WHERE id = ?");
                $stSoc->execute([$bienId]);
                $idSocieteBien = (int)($stSoc->fetchColumn() ?: 0);
                if ($idSocieteBien > 0) {
                    $chCode = (string)($fields['chauffage_type'] ?? '');
                    if ($chCode !== '') {
                        $st = $pdo->prepare("SELECT id FROM societe_types_chauffage WHERE id_societe = ? AND code = ? AND actif = 1 LIMIT 1");
                        $st->execute([$idSocieteBien, $chCode]);
                        $chId = (int)($st->fetchColumn() ?: 0);
                        if ($chId > 0) {
                            $pdo->prepare("INSERT IGNORE INTO bien_chauffages (id_bien, id_societe_chauffage) VALUES (?, ?)")
                                ->execute([$bienId, $chId]);
                        }
                    }
                    $enCode = (string)($fields['chauffage_energie'] ?? '');
                    if ($enCode !== '') {
                        $st = $pdo->prepare("SELECT id FROM societe_energies WHERE id_societe = ? AND code = ? AND actif = 1 LIMIT 1");
                        $st->execute([$idSocieteBien, $enCode]);
                        $enId = (int)($st->fetchColumn() ?: 0);
                        if ($enId > 0) {
                            $pdo->prepare("INSERT IGNORE INTO bien_energies (id_bien, id_societe_energie) VALUES (?, ?)")
                                ->execute([$bienId, $enId]);
                        }
                    }
                }
            } catch (Throwable $exSync) {
                error_log('[dpe_import] sync bien_chauffages/energies failed: ' . $exSync->getMessage());
            }

            // ── Sync : on remonte les valeurs sur la fiche bien ──
            // 2 catégories de champs (correctif 2026-05-06) :
            //
            // A) ALWAYS_OVERWRITE — champs propres au DPE/diagnostic. Le dernier
            //    DPE chargé fait foi. Si on charge un nouveau DPE après travaux,
            //    les classes/conso/dépenses sont actualisées. L'historique reste
            //    accessible via la table dpe_diags (est_diag_principal=0 pour
            //    les anciens).
            //
            // B) FILL_IF_EMPTY — champs descriptifs du bien (surface, n° lot,
            //    nb_pieces, étage, menuiseries…). Ne pas écraser une éventuelle
            //    saisie manuelle de l'agent qui peut différer du DPE (mesure
            //    Carrez vs estimée, etc.).
            $always_overwrite = [
                'dpe_classe'                 => $fields['dpe_classe'] ?? null,
                'ges_classe'                 => $fields['ges_classe'] ?? null,
                'dpe_valeur'                 => $fields['dpe_valeur'] ?? null,
                'ges_valeur'                 => $fields['ges_valeur'] ?? null,
                'dpe_date_realisation'       => $fields['dpe_date_realisation'] ?? null,
                'dpe_version'                => $fields['dpe_version'] ?? null,
                'dpe_vierge'                 => !empty($fields['dpe_vierge']) ? 1 : (isset($fields['dpe_vierge']) ? 0 : null),
                'dpe_valeur_conso_primaire'  => $fields['dpe_valeur_conso_primaire'] ?? null,
                'dpe_valeur_conso_finale'    => $fields['dpe_valeur_conso_finale'] ?? null,
                'dpe_reference_certificat'   => $fields['dpe_reference_certificat'] ?? null,
                'date_indice_prix_energies'  => $fields['date_indice_prix_energies'] ?? null,
                'montant_estime_depenses_min'=> $fields['montant_estime_depenses_min'] ?? null,
                'montant_estime_depenses_max'=> $fields['montant_estime_depenses_max'] ?? null,
            ];

            $fill_if_empty = [
                'altitude'                   => $fields['altitude'] ?? null,
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

            $setParts  = [];
            $setParams = [];

            // A) Overwrite systématique (champs DPE → dernier diag fait foi)
            foreach ($always_overwrite as $col => $val) {
                if ($val === null || $val === '') continue;
                $setParts[] = "`$col` = :v_$col";
                $setParams[":v_$col"] = $val;
            }

            // B) Remplit uniquement si vide (champs descriptifs)
            foreach ($fill_if_empty as $col => $val) {
                if ($val === null || $val === '') continue;
                $setParts[] = "`$col` = COALESCE(NULLIF(`$col`, ''), :v_$col)";
                $setParams[":v_$col"] = $val;
            }
            // type_bien : double-écriture (legacy + nouveau référentiel)
            if (!empty($fields['type_bien'])) {
                try {
                    require_once dirname(__DIR__) . '/inc/bien_type_helper.php';
                    $resolved = bien_type_resolve($pdo, (string)$fields['type_bien']);
                    if (!empty($resolved['id_type_bien'])) {
                        $setParts[] = "`id_type_bien` = COALESCE(NULLIF(`id_type_bien`, 1), :v_idtb)";
                        $setParams[':v_idtb'] = $resolved['id_type_bien'];
                    }
                    if (!empty($resolved['id_bien_type'])) {
                        $setParts[] = "`id_bien_type` = COALESCE(`id_bien_type`, :v_idbt)";
                        $setParams[':v_idbt'] = $resolved['id_bien_type'];
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
        } // fin if(false) — ancien stockage neutralisé (cf. inc/dpe_service.php)

        // ── Archivage PDF dans biens_documents (HORS try/catch dpe_diags) ──
        // CRITIQUE : cet INSERT doit s'exécuter MÊME si dpe_diags échoue.
        try {
            $pdo = db_reconnect_fresh();
            $uidUp = function_exists('current_user_id') ? (int)current_user_id() : null;
            $pdo->prepare("
                INSERT INTO biens_documents
                    (id_bien, type_document, libelle, url_fichier, nom_original,
                     mime_type, taille_octets, date_document, id_user_upload,
                     visible_proprietaire, date_upload)
                VALUES
                    (:id_bien, 'dpe', 'DPE', :url, :nom_orig, 'application/pdf',
                     :taille, :date_doc, :uid, 1, NOW())
            ")->execute([
                ':id_bien'  => $bienId,
                ':url'      => $publicUrl,
                ':nom_orig' => $file['name'],
                ':taille'   => (int)$file['size'],
                ':date_doc' => $fields['dpe_date_realisation'] ?? null,
                ':uid'      => $uidUp ?: null,
            ]);
        } catch (Throwable $exDoc) {
            error_log('[dpe_import] INSERT biens_documents failed: ' . $exDoc->getMessage());
        }

        // ─── Sprint 7D (2026-05-25) : dual-write GED CENTRALE UNIQUE ───
        try {
            $stB = $pdo->prepare("SELECT b.id_societe, b.id_agence, b.id_proprietaire, b.id_immeuble,
                                          p.id_tiers AS proprio_tiers_id,
                                          s.raison_sociale AS soc_raison,
                                          a.code_agence, a.nom_agence
                                    FROM biens b
                                    LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
                                    LEFT JOIN societes      s ON s.id = b.id_societe
                                    LEFT JOIN agences       a ON a.id = b.id_agence
                                    WHERE b.id = ? LIMIT 1");
            $stB->execute([$bienId]);
            $bienCtx = $stB->fetch(PDO::FETCH_ASSOC) ?: [];
            $socIdGed = (int)($bienCtx['id_societe'] ?? 0) ?: 1;

            $links = [['entity_type' => 'BIEN', 'entity_id' => $bienId, 'relation_type' => 'main']];
            if (!empty($bienCtx['proprio_tiers_id'])) {
                $links[] = ['entity_type' => 'TIERS', 'entity_id' => (int)$bienCtx['proprio_tiers_id'], 'relation_type' => 'annexe'];
            }
            if (!empty($bienCtx['id_immeuble'])) {
                $links[] = ['entity_type' => 'IMB', 'entity_id' => (int)$bienCtx['id_immeuble'], 'relation_type' => 'annexe'];
            }

            gus_commit_document(
                $pdo,
                [
                    'path_on_disk'  => $destPath,
                    'name_original' => $file['name'],
                    'mime_type'     => 'application/pdf',
                    'size_bytes'    => (int)$file['size'],
                    'public_url'    => $publicUrl,
                ],
                [
                    'document_type'  => 'DIAG_DPE',
                    'source_module'  => '05_TRANSACTION',
                    'security_level' => 'interne',
                    'societe_id'     => $socIdGed,
                    'agence_id'      => (int)($bienCtx['id_agence'] ?? 0) ?: 3,
                    'tenant_id'      => $socIdGed,
                    'created_by'     => $uidUp,
                    'storage_provider' => 'local',
                    'metadata_extra' => [
                        'classement'    => ['bien_id_bdd' => $bienId, 'date_doc' => $fields['dpe_date_realisation'] ?? null],
                        'legacy_source' => 'dpe_import_upload',
                    ],
                    'naming_ctx' => [
                        'societe_raison' => $bienCtx['soc_raison'] ?? 'Régie EMERY',
                        'agence_code'    => $bienCtx['code_agence'] ?? 'RE69-2',
                        'agence_nom'     => $bienCtx['nom_agence']  ?? 'LYON',
                        'user_id'        => $uidUp,
                        'n1_slug'        => '05_gestion_locative',
                        'n2_slug'        => 'biens',
                        'n3_slug'        => 'diag_dpe',
                        'type_doc'       => 'DIAG_DPE',
                        'entity_type'    => 'BIEN',
                        'entity_id'      => $bienId,
                        'date_doc'       => $fields['dpe_date_realisation'] ?? null,
                        'source_filename'=> $file['name'],
                    ],
                ],
                $links
            );
        } catch (Throwable $exGed) {
            error_log('[dpe_import] dual-write GED failed: ' . $exGed->getMessage());
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
