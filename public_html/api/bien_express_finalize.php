<?php
declare(strict_types=1);

/**
 * POST /api/bien_express_finalize.php
 *
 * Finalise le flow Express : met à jour toutes les valeurs du bien + bailleur,
 * calcule la complétude, détermine le statut (actif / brouillon), et si mode
 * 'with_annonce' crée une annonce avec les contenus IA générés.
 *
 * Règles :
 *   - STATUT ACTIF si :
 *       * Obligations OK : type_bien + adresse_1 + surface_habitable + id_proprietaire
 *       * Complétude >= 80 %
 *   - Sinon : reste/passe en 'brouillon'
 *
 * Paramètres POST :
 *   - csrf_token
 *   - id_bien
 *   - mode : 'bien_only' | 'with_annonce'
 *   - tous les champs du form Express (adresse_1, adresse_2, code_postal, ville,
 *     type_bien_code, surface, pièces, DPE, GES, chauffage, prix/loyer,
 *     id_proprietaire ou proprio_*, latitude, longitude, transaction,
 *     ia_generated (JSON), environnement[])
 *
 * Réponse :
 *   { ok: true, id_bien, statut_bien, completude_pct, id_annonce? }
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/ref_generator.php';
require_login();

// API JSON : ne JAMAIS afficher les warnings/notices PHP dans la réponse
// (ils cassent le parsing JSON côté client). On les log dans error.log.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit(json_encode(['ok' => false, 'error' => 'Méthode non autorisée']));
    }
    verify_csrf_any('ajouter_bien');

    $pdo       = $GLOBALS['pdo'];
    $societeId = (int)($_SESSION['id_societe'] ?? 0);
    $agenceId  = (int)($_SESSION['id_agence']  ?? 0);
    $userId    = (int)($_SESSION['user_id']    ?? 0);
    $roleId    = (int)($_SESSION['id_role']    ?? 0);

    $str  = static fn(string $k): string => trim((string)($_POST[$k] ?? ''));
    $int  = static fn(string $k) => ($_POST[$k] ?? '') !== '' ? (int)$_POST[$k] : null;
    $flt  = static fn(string $k) => ($_POST[$k] ?? '') !== '' ? (float)$_POST[$k] : null;

    $idBien = (int)($_POST['id_bien'] ?? 0);
    $mode   = $str('mode') === 'with_annonce' ? 'with_annonce' : 'bien_only';

    if ($idBien <= 0) exit(json_encode(['ok' => false, 'error' => 'id_bien manquant']));

    // ─── Scope société ──
    $bStmt = $pdo->prepare("SELECT id_societe, id_agence, id_proprietaire FROM biens WHERE id = ? LIMIT 1");
    $bStmt->execute([$idBien]);
    $bien = $bStmt->fetch(PDO::FETCH_ASSOC);
    if (!$bien) exit(json_encode(['ok' => false, 'error' => 'Bien introuvable']));
    if ($societeId > 0 && $roleId !== 7 && (int)($bien['id_societe'] ?? 0) !== $societeId) {
        exit(json_encode(['ok' => false, 'error' => 'Accès refusé']));
    }

    // ─── 1. Gestion bailleur (création si nouveau) ──
    $proprioId = $int('id_proprietaire') ?: (int)($bien['id_proprietaire'] ?? 0);
    if (!$proprioId) {
        $pNom = $str('proprio_nom');
        if ($pNom !== '') {
            $pdo->prepare("
                INSERT INTO proprietaires (nom, prenom, societe, email, telephone, adresse_1,
                    type_personne, id_agence, actif, date_creation, date_modification)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
            ")->execute([
                $pNom,
                $str('proprio_prenom') ?: null,
                $str('proprio_societe') ?: null,
                $str('proprio_email') ?: null,
                $str('proprio_telephone') ?: null,
                $str('proprio_adresse') ?: null,
                $str('proprio_societe') !== '' ? 'morale' : 'physique',
                $agenceId ?: null,
            ]);
            $proprioId = (int)$pdo->lastInsertId();
        }
    }

    // ─── 2. Résolution des 2 ids (nouveau + legacy) ──
    require_once dirname(__DIR__) . '/inc/bien_type_helper.php';
    $typeCode   = $str('type_bien_code') ?: $str('type_bien');
    $idTypeBien = null;
    $idBienType = null;
    if ($typeCode !== '') {
        $resolved   = bien_type_resolve($pdo, $typeCode);
        $idBienType = $resolved['id_bien_type'];
        $idTypeBien = $resolved['id_type_bien'];
    }

    // ─── 3. Environnement flat (pour biens.exposition etc.) ──
    $env = is_array($_POST['environnement'] ?? null) ? $_POST['environnement'] : [];
    $exposition = is_array($env['exposition'] ?? null) ? implode(',', $env['exposition']) : (string)($env['exposition'] ?? '');
    $vue        = is_array($env['vue'] ?? null) ? implode(',', $env['vue']) : (string)($env['vue'] ?? '');
    $accesT     = (string)($env['acces_transports']   ?? '');
    $distC      = (string)($env['distance_commerces'] ?? '');
    $ambiance   = is_array($env['ambiance'] ?? null) ? implode(',', $env['ambiance']) : '';
    $nuisances  = is_array($env['nuisances'] ?? null) ? implode(',', $env['nuisances']) : '';

    // ─── 4. Évaluation des obligations + complétude ──
    $mandatory = [
        'type_bien'        => $idTypeBien !== null,
        'adresse_1'        => $str('adresse_1') !== '',
        'surface_habitable'=> $flt('surface_habitable') !== null && $flt('surface_habitable') > 0,
        'id_proprietaire'  => $proprioId > 0,
    ];
    $additional = [
        'code_postal'        => $str('code_postal') !== '',
        'ville'              => $str('ville') !== '',
        'transaction'        => $str('transaction') !== '',
        'dpe_classe'         => $str('dpe_classe') !== '',
        'ges_classe'         => $str('ges_classe') !== '',
        'annee_construction' => $int('annee_construction') !== null,
        'nb_pieces'          => $int('nb_pieces') !== null,
        'prix_ou_loyer'      => ($flt('prix_vente_estime') !== null) || ($flt('loyer_hc') !== null),
        'etage'              => $str('etage') !== '',
    ];
    $totalFields = count($mandatory) + count($additional);
    $okFields    = count(array_filter($mandatory)) + count(array_filter($additional));
    $completude  = $totalFields > 0 ? (int)round($okFields / $totalFields * 100) : 0;
    $mandatoryOk = !in_array(false, $mandatory, true);
    // Seuil réaliste : 65% (obligations + majorité des champs utiles) = actif
    // Sous ce seuil, reste en brouillon pour éviter publication avec données trop incomplètes
    $newStatut   = ($mandatoryOk && $completude >= 65) ? 'actif' : 'brouillon';

    // ─── 5. UPDATE bien ──
    // Normalisation DPE/GES classe en uppercase (la BDD historique a parfois 'c'
    // minuscule, bien_detail compare contre 'C' majuscule)
    $dpeRaw = $str('dpe_classe');
    $gesRaw = $str('ges_classe');
    $dpeClasseNorm = $dpeRaw !== '' ? strtoupper($dpeRaw) : null;
    $gesClasseNorm = $gesRaw !== '' ? strtoupper($gesRaw) : null;

    $pdo->prepare("
        UPDATE biens SET
            id_proprietaire = :pro,
            id_type_bien    = COALESCE(:tb, id_type_bien),
            id_bien_type    = COALESCE(:tb_new, id_bien_type),
            adresse_1       = :a1,
            adresse_2       = :a2,
            code_postal     = :cp,
            ville           = :v,
            quartier        = :quartier,
            latitude        = :lat,
            longitude       = :lng,
            etage           = :etg,
            lot_principal   = :lot,
            surface_habitable = :sh,
            nb_pieces       = :np,
            nb_chambres     = :nc,
            nb_salles_bain  = :nsb,
            nb_wc           = :nwc,
            annee_construction = :annee,
            dpe_classe      = :dpeC,
            ges_classe      = :gesC,
            dpe_valeur      = :dpeV,
            ges_valeur      = :gesV,
            dpe_date_realisation = :dpeDate,
            chauffage_type  = :chT,
            chauffage_energie = :chE,
            eau_chaude_type = :ecT,
            dpe_reference_certificat = :ademe,
            dpe_version     = :dpeVer,
            dpe_valeur_conso_primaire = :consoP,
            dpe_valeur_conso_finale = :consoF,
            montant_estime_depenses_min = :depMin,
            montant_estime_depenses_max = :depMax,
            annee_reference_depenses = :anneeRef,
            altitude        = :alt,
            prix_vente_estime = :pxV,
            loyer_hc        = :loyer,
            exposition      = :exp,
            vue             = :vue,
            ambiance        = :ambiance,
            nuisances       = :nuisances,
            acces_transports = :accesT,
            distance_commerces = :distC,
            points_interet  = :poi,
            argument_phare  = :argument,
            statut_bien     = :statut,
            date_modification = NOW()
        WHERE id = :id
    ")->execute([
        ':pro'      => $proprioId ?: null,
        ':tb'       => $idTypeBien,
        ':tb_new'   => $idBienType,
        ':a1'       => $str('adresse_1') ?: null,
        ':a2'       => $str('adresse_2') ?: null,
        ':cp'       => $str('code_postal') ?: null,
        ':v'        => $str('ville') ?: null,
        ':quartier' => $str('quartier') ?: null,
        ':lat'      => $flt('latitude'),
        ':lng'      => $flt('longitude'),
        ':etg'      => $str('etage') ?: null,
        ':lot'      => $str('lot_principal') ?: null,
        ':sh'       => $flt('surface_habitable'),
        ':np'       => $int('nb_pieces'),
        ':nc'       => $int('nb_chambres'),
        ':nsb'      => $int('nb_salles_bain'),
        ':nwc'      => $int('nb_wc'),
        ':annee'    => $int('annee_construction'),
        ':dpeC'     => $dpeClasseNorm,
        ':gesC'     => $gesClasseNorm,
        ':dpeV'     => $flt('dpe_valeur'),
        ':gesV'     => $flt('ges_valeur'),
        ':dpeDate'  => $str('dpe_date_realisation') ?: null,
        ':chT'      => $str('chauffage_type') ?: null,
        ':chE'      => $str('chauffage_energie') ?: null,
        ':ecT'      => $str('eau_chaude_type') ?: null,
        ':ademe'    => $str('dpe_reference_certificat') ?: null,
        ':dpeVer'   => $str('dpe_version') ?: null,
        ':consoP'   => $flt('dpe_valeur_conso_primaire'),
        ':consoF'   => $flt('dpe_valeur_conso_finale'),
        ':depMin'   => $flt('montant_estime_depenses_min'),
        ':depMax'   => $flt('montant_estime_depenses_max'),
        ':anneeRef' => $int('annee_reference_depenses'),
        ':alt'      => $int('altitude'),
        ':pxV'      => $flt('prix_vente_estime'),
        ':loyer'    => $flt('loyer_hc'),
        ':exp'      => $exposition ?: null,
        ':vue'      => $vue ?: null,
        ':ambiance' => $ambiance ?: null,
        ':nuisances'=> $nuisances ?: null,
        ':accesT'   => $accesT ?: null,
        ':distC'    => $distC ?: null,
        ':poi'      => $str('points_interet') ?: null,
        ':argument' => $str('argument_phare') ?: null,
        ':statut'   => $newStatut,
        ':id'       => $idBien,
    ]);

    // ─── 5b. Synchronisation bien_vues depuis le CSV biens.vue ──
    // bien_detail affiche les chips Vue depuis la table `bien_vues`
    // (table de jointure id_bien ↔ id_societe_vue), pas depuis le CSV.
    // On traduit donc le CSV en lignes dans bien_vues pour que les chips
    // soient pré-cochées au chargement de bien_detail.
    if ($idSociete > 0) {
        try {
            $pdo->prepare("DELETE FROM bien_vues WHERE id_bien = ?")->execute([$idBien]);
            if ($vue !== '') {
                $codes = array_values(array_filter(array_map('trim', explode(',', $vue))));
                if (!empty($codes)) {
                    $phCodes = implode(',', array_fill(0, count($codes), '?'));
                    $stVid = $pdo->prepare("SELECT id FROM societe_vues WHERE id_societe = ? AND code IN ($phCodes) AND actif = 1");
                    $stVid->execute(array_merge([$idSociete], $codes));
                    $vueIds = $stVid->fetchAll(PDO::FETCH_COLUMN);
                    if (!empty($vueIds)) {
                        $insBv = $pdo->prepare("INSERT IGNORE INTO bien_vues (id_bien, id_societe_vue) VALUES (?, ?)");
                        foreach ($vueIds as $vid) {
                            $insBv->execute([$idBien, (int)$vid]);
                        }
                    }
                }
            }
        } catch (Throwable $exBv) {
            error_log('[bien_express_finalize] sync bien_vues failed: ' . $exBv->getMessage());
        }
    }

    // ─── 6. Création annonce si mode with_annonce ──
    $idAnnonce = null;
    if ($mode === 'with_annonce') {
        $transaction = $str('transaction');
        // estimation / mandat simple : pas de création d'annonce ici
        if (in_array($transaction, ['location','vente','mandat_gestion'], true)) {
            // Mapping transaction → type_transaction annonce
            $typeTx = ($transaction === 'vente') ? 'vente' : 'location';
            // Vérifie qu'il n'y a pas déjà une annonce active pour ce bien
            $chk = $pdo->prepare("SELECT id FROM annonces WHERE id_bien = ? AND (statut = 'actif' OR statut = 'active' OR statut = 'publie' OR statut = 'publiee') LIMIT 1");
            $chk->execute([$idBien]);
            $existingActive = (int)$chk->fetchColumn();
            if ($existingActive > 0) {
                $idAnnonce = $existingActive; // on réutilise l'annonce active existante
            } else {
                // Génère la référence annonce
                $refAnn = ref_generate_annonce($pdo, [
                    'id_agence'      => $agenceId,
                    'bien_ref'       => (string)($pdo->query("SELECT reference_bien FROM biens WHERE id=" . (int)$idBien)->fetchColumn() ?: ''),
                    'transaction'    => $typeTx,
                    'type_bien_code' => $typeCode,
                    'ville'          => $str('ville'),
                    'user'           => [
                        'nom'    => (string)($_SESSION['nom'] ?? ''),
                        'prenom' => (string)($_SESSION['prenom'] ?? ''),
                    ],
                ]);

                // Récupère le contenu IA (priorité aux valeurs éditées dans la preview)
                $iaRaw = $str('ia_generated');
                $iaJson = $iaRaw !== '' ? json_decode($iaRaw, true) : [];
                $titreSeo  = $str('ia_titre_seo')  ?: (string)($iaJson['titre_seo']        ?? '');
                $titreLbc  = $str('ia_titre_lbc')  ?: (string)($iaJson['titre_lbc']        ?? '');
                $h1        = $str('ia_h1')         ?: (string)($iaJson['h1']               ?? '');
                $metaDesc  = $str('ia_meta')       ?: (string)($iaJson['meta_description'] ?? '');
                $slugAnn   = $str('ia_slug')       ?: (string)($iaJson['slug']             ?? '');
                $descAnn   = $str('ia_desc')       ?: (string)($iaJson['description']      ?? '');
                $motsCles  = $str('ia_kw')         ?: (is_array($iaJson['mots_cles'] ?? null) ? implode(', ', $iaJson['mots_cles']) : '');

                $pdo->prepare("
                    INSERT INTO annonces (
                        id_bien, id_societe, id_agence, id_user,
                        reference_annonce, slug, type_transaction,
                        prix, loyer, loyer_cc,
                        titre_seo, titre_lbc, h1_public, meta_description, mots_cles, description,
                        statut, date_creation, date_modification
                    ) VALUES (
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        'brouillon', NOW(), NOW()
                    )
                ")->execute([
                    $idBien, $societeId ?: null, $agenceId ?: null, $userId ?: null,
                    $refAnn, $slugAnn ?: null, $typeTx,
                    $typeTx === 'vente' ? $flt('prix_vente_estime') : null,
                    $typeTx === 'location' ? $flt('loyer_hc') : null,
                    $typeTx === 'location' ? $flt('loyer_hc') : null,
                    $titreSeo ?: null, $titreLbc ?: null, $h1 ?: null, $metaDesc ?: null, $motsCles ?: null, $descAnn ?: null,
                ]);
                $idAnnonce = (int)$pdo->lastInsertId();

                // Remplit annonces_photos avec toutes les photos du bien (ordre actuel)
                try {
                    $pdo->prepare("
                        INSERT IGNORE INTO annonces_photos (id_annonce, id_biens_photo, ordre, alt_text, date_creation)
                        SELECT ?, id, ordre, description_ia, NOW() FROM biens_photos WHERE id_bien = ?
                    ")->execute([$idAnnonce, $idBien]);
                } catch (Throwable) { /* alt_text colonne optionnelle */ }
            }
        }
    }

    // Récupère la vraie référence + ville depuis BDD (après UPDATE)
    $refRow = $pdo->prepare("SELECT reference_bien, ville FROM biens WHERE id = ? LIMIT 1");
    $refRow->execute([$idBien]);
    $refData = $refRow->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'ok'                => true,
        'id_bien'           => $idBien,
        'id_annonce'        => $idAnnonce,
        'reference_bien'    => (string)($refData['reference_bien'] ?? ''),
        'ville'             => (string)($refData['ville'] ?? ''),
        'statut_bien'       => $newStatut,
        'completude_pct'    => $completude,
        'mandatory_ok'      => $mandatoryOk,
        'missing_mandatory' => array_keys(array_filter($mandatory, fn($v) => !$v)),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
