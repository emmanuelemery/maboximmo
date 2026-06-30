<?php
declare(strict_types=1);

/**
 * inc/dpe_service.php — SERVICE DPE CENTRAL (source de vérité unique).
 *
 * Tout canal qui charge un diagnostic DPE appelle CE service :
 *   - api/dpe_import_upload.php (upload manuel bien_detail)
 *   - api/graph_doc_commit.php  (classement OneDrive)
 *   - (tout futur point d'entrée)
 *
 * Deux étapes :
 *   1. dpe_analyser()    — extrait les champs (DpeImportParser + analyseDpeIA, OCR si scanné)
 *   2. dpe_enregistrer() — stocke : dpe_diags (historisé) + sync biens + chips chauffage/énergie
 *
 * Le code d'extraction et de stockage est la reprise EXACTE de ce qui fonctionnait
 * en dur dans dpe_import_upload.php — comportement identique, mais centralisé.
 */

require_once __DIR__ . '/bien_import_parser.php';   // BienImportParser::extractText
require_once __DIR__ . '/dpe_import_parser.php';    // DpeImportParser::parse
require_once __DIR__ . '/dpe_ia_analyse.php';       // analyseDpeIA
require_once __DIR__ . '/bien_intake_ocr.php';      // BienIntakeOCR (OCR Vision)

/** Score de couverture pondéré (0-100). */
function dpe_score(array $fields): int
{
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
    return $totalW > 0 ? min(100, (int) round(($reachedW / $totalW) * 100)) : 0;
}

/**
 * Étape 1 — ANALYSE / EXTRACTION.
 *
 * @return array{ok:bool, fields:array, method:string, score:int, error:?string,
 *               used_ocr:bool, text:string, text_length:int, ia_error:?string}
 */
function dpe_analyser(string $pdfPath, int $bienId = 0): array
{
    $out = ['ok'=>false,'fields'=>[],'method'=>'','score'=>0,'error'=>null,
            'used_ocr'=>false,'text'=>'','text_length'=>0,'ia_error'=>null];

    if (!is_file($pdfPath)) { $out['error'] = 'Fichier introuvable.'; return $out; }

    try {
        $texte   = (string)BienImportParser::extractText($pdfPath);
        $textLen = mb_strlen(trim($texte));
        $out['text'] = $texte; $out['text_length'] = $textLen;

        if ($textLen < 200) {
            // PATH A : PDF scanné → OCR Vision
            $out['used_ocr'] = true;
            if (!class_exists('BienIntakeOCR')) { $out['error'] = 'OCR indisponible (module absent).'; return $out; }
            $tmp = dirname(__DIR__) . '/uploads/_ocr_tmp/dpe_' . ($bienId > 0 ? $bienId . '_' : '') . bin2hex(random_bytes(3));
            try {
                $images = BienIntakeOCR::pdfToImages($pdfPath, $tmp, 8);
                if (empty($images)) { $out['error'] = 'PDF scanné non analysable (Poppler/Imagick absent sur ce serveur).'; return $out; }
                $ocr = BienIntakeOCR::analyseImagesIA($images);
                BienIntakeOCR::cleanupTmpDir($tmp);
                if (empty($ocr['ok'])) { $out['error'] = 'OCR Vision : ' . ($ocr['error'] ?? 'inconnue'); return $out; }
                $out['ok'] = true; $out['fields'] = (array)($ocr['fields'] ?? []);
                $out['method'] = 'ocr_vision';
                $out['score']  = count($out['fields']) >= 3 ? 85 : 30;
                return $out;
            } catch (Throwable $ex) {
                @BienIntakeOCR::cleanupTmpDir($tmp);
                $out['error'] = 'PDF scanné non analysable : ' . $ex->getMessage();
                return $out;
            }
        }

        // PATH B : PDF texte → regex DPE + IA complément
        $rx     = DpeImportParser::parse($texte);
        $fields = (array)($rx['fields'] ?? []);
        $score  = (int)($rx['score'] ?? 0);
        $method = 'regex';
        if (($score < 80 || count($fields) < 6) && function_exists('analyseDpeIA')) {
            $ia = analyseDpeIA($texte);
            if (!empty($ia['ok']) && !empty($ia['fields'])) {
                foreach ($ia['fields'] as $k => $v) {
                    if (!isset($fields[$k]) || $fields[$k] === null || $fields[$k] === '') $fields[$k] = $v;
                }
                $method = 'regex+ia';
            } else {
                $out['ia_error'] = $ia['error'] ?? 'Erreur IA inconnue';
            }
        }
        $out['ok'] = true; $out['fields'] = $fields; $out['method'] = $method;
        $out['score'] = dpe_score($fields);
        return $out;

    } catch (Throwable $e) { $out['error'] = $e->getMessage(); return $out; }
}

/**
 * Étape 2 — STOCKAGE.
 * Insère le diagnostic dans dpe_diags (nouveau = principal, anciens historisés),
 * synchronise les chips chauffage/énergie, et remonte les valeurs sur la fiche bien
 * (champs DPE en overwrite, champs descriptifs en fill-if-empty).
 *
 * @param array $meta ['url','nom_orig','taille','mime','method','score','texte']
 * @return array{ok:bool, diag_id:int, error:?string}
 */
function dpe_enregistrer(PDO $pdo, int $bienId, array $fields, array $meta = []): array
{
    if ($bienId <= 0) return ['ok' => false, 'diag_id' => 0, 'error' => 'bien_id manquant'];

    // Reconnexion fraîche (l'IA a pu durer 30-120s → "MySQL server has gone away")
    if (function_exists('db_reconnect_fresh')) { $pdo = db_reconnect_fresh(); }

    $method = (string)($meta['method'] ?? 'regex+ia');
    $score  = (int)($meta['score'] ?? dpe_score($fields));
    $texte  = (string)($meta['texte'] ?? '');

    try {
        $pdo->prepare("UPDATE dpe_diags SET est_diag_principal = 0 WHERE id_bien = ?")->execute([$bienId]);

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
            ':url' => $meta['url'] ?? null,
            ':nom_orig' => $meta['nom_orig'] ?? null,
            ':taille' => (int)($meta['taille'] ?? 0),
            ':mime' => $meta['mime'] ?? 'application/pdf',
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
            ':texte' => mb_substr($texte, 0, 60000),
            ':champs_json' => json_encode($fields, JSON_UNESCAPED_UNICODE),
            ':resume' => $fields['diag_resume_ia'] ?? null,
        ]);
        $diagId = (int)$pdo->lastInsertId();

        // ── Sync chips chauffage / énergie ──
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
                    if ($chId > 0) $pdo->prepare("INSERT IGNORE INTO bien_chauffages (id_bien, id_societe_chauffage) VALUES (?, ?)")->execute([$bienId, $chId]);
                }
                $enCode = (string)($fields['chauffage_energie'] ?? '');
                if ($enCode !== '') {
                    $st = $pdo->prepare("SELECT id FROM societe_energies WHERE id_societe = ? AND code = ? AND actif = 1 LIMIT 1");
                    $st->execute([$idSocieteBien, $enCode]);
                    $enId = (int)($st->fetchColumn() ?: 0);
                    if ($enId > 0) $pdo->prepare("INSERT IGNORE INTO bien_energies (id_bien, id_societe_energie) VALUES (?, ?)")->execute([$bienId, $enId]);
                }
            }
        } catch (Throwable $exSync) {
            error_log('[dpe_service] sync chauffages/energies : ' . $exSync->getMessage());
        }

        // ── Sync fiche bien : A) DPE overwrite, B) descriptif fill-if-empty ──
        $always_overwrite = [
            'dpe_classe' => $fields['dpe_classe'] ?? null,
            'ges_classe' => $fields['ges_classe'] ?? null,
            'dpe_valeur' => $fields['dpe_valeur'] ?? null,
            'ges_valeur' => $fields['ges_valeur'] ?? null,
            'dpe_date_realisation' => $fields['dpe_date_realisation'] ?? null,
            'dpe_version' => $fields['dpe_version'] ?? null,
            'dpe_vierge' => !empty($fields['dpe_vierge']) ? 1 : (isset($fields['dpe_vierge']) ? 0 : null),
            'dpe_valeur_conso_primaire' => $fields['dpe_valeur_conso_primaire'] ?? null,
            'dpe_valeur_conso_finale' => $fields['dpe_valeur_conso_finale'] ?? null,
            'dpe_reference_certificat' => $fields['dpe_reference_certificat'] ?? null,
            'date_indice_prix_energies' => $fields['date_indice_prix_energies'] ?? null,
            'montant_estime_depenses_min' => $fields['montant_estime_depenses_min'] ?? null,
            'montant_estime_depenses_max' => $fields['montant_estime_depenses_max'] ?? null,
        ];
        $fill_if_empty = [
            'altitude' => $fields['altitude'] ?? null,
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
        ];

        $setParts = []; $setParams = [];
        foreach ($always_overwrite as $col => $val) {
            if ($val === null || $val === '') continue;
            $setParts[] = "`$col` = :v_$col"; $setParams[":v_$col"] = $val;
        }
        foreach ($fill_if_empty as $col => $val) {
            if ($val === null || $val === '') continue;
            $setParts[] = "`$col` = COALESCE(NULLIF(`$col`, ''), :v_$col)"; $setParams[":v_$col"] = $val;
        }
        if (!empty($fields['type_bien'])) {
            try {
                require_once __DIR__ . '/bien_type_helper.php';
                $resolved = bien_type_resolve($pdo, (string)$fields['type_bien']);
                if (!empty($resolved['id_type_bien'])) { $setParts[] = "`id_type_bien` = COALESCE(NULLIF(`id_type_bien`, 1), :v_idtb)"; $setParams[':v_idtb'] = $resolved['id_type_bien']; }
                if (!empty($resolved['id_bien_type'])) { $setParts[] = "`id_bien_type` = COALESCE(`id_bien_type`, :v_idbt)"; $setParams[':v_idbt'] = $resolved['id_bien_type']; }
            } catch (Throwable) {}
        }
        if (!empty($setParts)) {
            $setParams[':_id'] = $bienId;
            try {
                $pdo->prepare("UPDATE biens SET " . implode(', ', $setParts) . " WHERE id = :_id")->execute($setParams);
            } catch (Throwable $e) { error_log('[dpe_service] biens sync : ' . $e->getMessage()); }
        }

        return ['ok' => true, 'diag_id' => $diagId, 'error' => null];

    } catch (Throwable $e) {
        error_log('[dpe_service] dpe_diags insert : ' . $e->getMessage());
        return ['ok' => false, 'diag_id' => 0, 'error' => $e->getMessage()];
    }
}
