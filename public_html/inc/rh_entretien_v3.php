<?php
declare(strict_types=1);

/**
 * Compute V3 business metrics for a given entretien.
 * Safe: no-op if V3 tables are missing.
 */
function rh_entretien_v3_compute(PDO $pdo, int $entretienId, array $options = []): void
{
    if ($entretienId <= 0) {
        return;
    }
    if (!rh_entretien_v3_table_exists($pdo, 'rh_entretien_resultats_metier')) {
        return;
    }
    $preserveScores = !empty($options['preserve_scores']);

    // Load entretien
    try {
        $stmt = $pdo->prepare("SELECT * FROM rh_entretiens WHERE id = ?");
        $stmt->execute([$entretienId]);
        $entretien = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return;
    }
    if (!$entretien) {
        return;
    }

    // Scores (0-1)
    $scoresRaw = rh_entretien_v3_load_scores($pdo, $entretienId);
    $scores5   = [];
    foreach ($scoresRaw as $k => $v) {
        $scores5[$k] = round($v * 5, 2);
    }

    // Load responses + criteria
    $responses = [];
    $blocFormation = null;
    $blocMotivation = null;
    $blocEvolution = null;
    $scoreGlobal = null;
    try {
        $stmtR = $pdo->prepare("
            SELECT r.critere_id, r.note, r.texte_final, c.code, c.type_champ, c.axe_radar, c.poids_score
            FROM rh_entretien_reponses r
            LEFT JOIN rh_entretien_criteres c ON c.id = r.critere_id
            WHERE r.entretien_id = ?
        ");
        $stmtR->execute([$entretienId]);
        $rows = $stmtR->fetchAll(PDO::FETCH_ASSOC);

        $sum = 0.0;
        $sumPoids = 0.0;
        foreach ($rows as $row) {
            $responses[] = $row;
            $note = isset($row['note']) ? (int)$row['note'] : null;
            $poids = isset($row['poids_score']) ? (float)$row['poids_score'] : 1.0;
            if ($note !== null && $note > 0) {
                $sum += $note * $poids;
                $sumPoids += $poids;
            }

            $type = $row['type_champ'] ?? '';
            $tf = $row['texte_final'] ?? '';
            $json = rh_entretien_v3_parse_json($tf);
            if ($json && $type === 'bloc_formation') {
                $blocFormation = $json;
            } elseif ($json && $type === 'bloc_motivation') {
                $blocMotivation = $json;
            } elseif ($json && $type === 'bloc_evolution') {
                $blocEvolution = $json;
            }
        }
        if ($sumPoids > 0) {
            $scoreGlobal = round($sum / $sumPoids, 2); // 0-5
        }
    } catch (PDOException $e) {
        // ignore
    }

    // Derive scores (0-5)
    $scorePerformance   = $scores5['performance'] ?? null;
    $scoreRelationnel   = $scores5['relationnel'] ?? ($scores5['comportement'] ?? null);
    $scoreMotivation    = $scores5['motivation'] ?? null;
    $scoreAdaptabilite  = $scores5['adaptabilite'] ?? null;
    $scoreAutonomie     = $scores5['autonomie'] ?? null;
    $scorePotentiel     = $scores5['potentiel'] ?? null;
    $scoreOrganisation  = $scores5['organisation'] ?? null;
    $scoreMaitrisePoste = $scores5['maitrise_poste'] ?? null;
    $scoreDigital       = $scores5['digital'] ?? null;
    $scoreFiabilite     = $scores5['fiabilite'] ?? null;
    $scoreEngagement    = $scores5['engagement'] ?? null;
    $scoreImplication   = $scores5['implication'] ?? null;
    $scoreStabilite     = $scores5['stabilite'] ?? null;

    // If V5 already computed scores, reuse them to keep consistency
    if ($preserveScores) {
        $existing = null;
        try {
            $stmtE = $pdo->prepare("SELECT score_global, score_performance, score_relationnel, score_motivation, score_adaptabilite, score_autonomie, score_potentiel, score_organisation, score_maitrise_poste, score_digital, score_fiabilite, score_engagement FROM rh_entretien_resultats_metier WHERE entretien_id = ?");
            $stmtE->execute([$entretienId]);
            $existing = $stmtE->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $existing = null;
        }
        if ($existing) {
            if ($existing['score_global'] !== null) $scoreGlobal = (float)$existing['score_global'];
            if ($existing['score_performance'] !== null) $scorePerformance = (float)$existing['score_performance'];
            if ($existing['score_relationnel'] !== null) $scoreRelationnel = (float)$existing['score_relationnel'];
            if ($existing['score_motivation'] !== null) $scoreMotivation = (float)$existing['score_motivation'];
            if ($existing['score_adaptabilite'] !== null) $scoreAdaptabilite = (float)$existing['score_adaptabilite'];
            if ($existing['score_autonomie'] !== null) $scoreAutonomie = (float)$existing['score_autonomie'];
            if ($existing['score_potentiel'] !== null) $scorePotentiel = (float)$existing['score_potentiel'];
            if ($existing['score_organisation'] !== null) $scoreOrganisation = (float)$existing['score_organisation'];
            if ($existing['score_maitrise_poste'] !== null) $scoreMaitrisePoste = (float)$existing['score_maitrise_poste'];
            if ($existing['score_digital'] !== null) $scoreDigital = (float)$existing['score_digital'];
            if ($existing['score_fiabilite'] !== null) $scoreFiabilite = (float)$existing['score_fiabilite'];
            if ($existing['score_engagement'] !== null) $scoreEngagement = (float)$existing['score_engagement'];
        }

        if ($scoreStabilite === null && $scoreMotivation !== null && $scoreEngagement !== null) {
            $scoreStabilite = round(($scoreMotivation + $scoreEngagement) / 2, 2);
        }
        if ($scoreImplication === null && $scorePerformance !== null && $scoreAdaptabilite !== null) {
            $scoreImplication = round(($scorePerformance + $scoreAdaptabilite) / 2, 2);
        }

        $scores5['performance'] = $scorePerformance ?? $scores5['performance'] ?? null;
        $scores5['relationnel'] = $scoreRelationnel ?? $scores5['relationnel'] ?? null;
        $scores5['motivation'] = $scoreMotivation ?? $scores5['motivation'] ?? null;
        $scores5['adaptabilite'] = $scoreAdaptabilite ?? $scores5['adaptabilite'] ?? null;
        $scores5['autonomie'] = $scoreAutonomie ?? $scores5['autonomie'] ?? null;
        $scores5['potentiel'] = $scorePotentiel ?? $scores5['potentiel'] ?? null;
        $scores5['organisation'] = $scoreOrganisation ?? $scores5['organisation'] ?? null;
        $scores5['maitrise_poste'] = $scoreMaitrisePoste ?? $scores5['maitrise_poste'] ?? null;
        $scores5['digital'] = $scoreDigital ?? $scores5['digital'] ?? null;
        $scores5['fiabilite'] = $scoreFiabilite ?? $scores5['fiabilite'] ?? null;
        $scores5['engagement'] = $scoreEngagement ?? $scores5['engagement'] ?? null;
        if ($scoreImplication !== null) $scores5['implication'] = $scoreImplication;
        if ($scoreStabilite !== null) $scores5['stabilite'] = $scoreStabilite;
    }

    // Formation needs
    $besoinFormationNiveau = null;
    $besoinFormationOrigine = null;
    $besoinFormationSolution = null;
    $formationRoi = null;
    if (is_array($blocFormation)) {
        $priorite = $blocFormation['priorite'] ?? null;
        $besoinFormationOrigine = $blocFormation['origine'] ?? null;
        $besoinFormationSolution = $blocFormation['solution'] ?? null;
        if ($priorite === 'prioritaire') $besoinFormationNiveau = 'prioritaire';
        elseif ($priorite === 'justifie') $besoinFormationNiveau = 'justifie';
        elseif ($priorite === 'utile') $besoinFormationNiveau = 'utile';
        elseif ($priorite === 'souhait') $besoinFormationNiveau = 'confort';
        elseif ($priorite === 'refuse') $besoinFormationNiveau = 'aucun';

        // ROI attendu (simple)
        if ($priorite === 'prioritaire') $formationRoi = 'indispensable';
        elseif ($priorite === 'justifie') $formationRoi = 'fort';
        elseif ($priorite === 'utile') $formationRoi = 'modere';
        elseif ($priorite === 'souhait') $formationRoi = 'faible';
    }

    // Potentiel managerial
    $potentielManagerial = null;
    $pmScores = [];
    foreach ([$scoreAutonomie, $scoreRelationnel, $scoreFiabilite, $scoreEngagement] as $v) {
        if ($v !== null && $v > 0) $pmScores[] = $v;
    }
    if (!empty($pmScores)) {
        $avg = array_sum($pmScores) / count($pmScores);
        if ($avg >= 4.0) $potentielManagerial = 'fort';
        elseif ($avg >= 3.5) $potentielManagerial = 'credible';
        elseif ($avg >= 3.0) $potentielManagerial = 'emergent';
        else $potentielManagerial = 'faible';
    }

    // Profil fins (heuristiques simples)
    $profiles = [];
    if ($scorePerformance !== null && $scoreMotivation !== null && $scoreRelationnel !== null) {
        if ($scorePerformance >= 3.8 && $scoreMotivation >= 3.5 && $scoreRelationnel >= 3.5) {
            $profiles[] = 'pilier_discret';
        }
        if ($scorePotentiel !== null && $scorePotentiel >= 3.5 && ($scoreStabilite !== null && $scoreStabilite < 3.0)) {
            $profiles[] = 'potentiel_consolidation';
        }
        if ($scorePerformance >= 3.5 && $scoreMotivation < 2.5) {
            $profiles[] = 'competent_demobilise';
        }
        if ($scoreImplication !== null && $scoreImplication >= 4.0 && $scoreMotivation < 3.0) {
            $profiles[] = 'engage_sature';
        }
        if ($scoreOrganisation !== null && $scoreOrganisation < 3.0 && $scoreMotivation >= 3.0) {
            $profiles[] = 'volontaire_non_structure';
        }
        if ($scoreAdaptabilite !== null && $scoreAdaptabilite < 2.8) {
            $profiles[] = 'resistant_changement';
        }
        if ($scoreRelationnel !== null && $scoreRelationnel < 2.5) {
            $profiles[] = 'risque_relationnel';
        }
    }

    // Risks
    $risqueDepart = rh_entretien_v3_eval_risque_depart($scoreMotivation, $scoreEngagement, $scoreRelationnel, $scoreOrganisation, $profiles, rh_entretien_v3_load_alertes($pdo, $entretienId));
    $risqueUsure  = rh_entretien_v3_eval_risque_usure($scoreImplication, $scoreMotivation, $scoreEngagement, $scoreOrganisation, $scoreRelationnel, $blocMotivation);

    if ($risqueDepart === 'eleve') $profiles[] = 'risque_depart_silencieux';

    // Incoherences
    $incoherences = [];
    if (is_array($blocMotivation)) {
        $declaree = (int)($blocMotivation['declaree'] ?? 0);
        $observee = (int)($blocMotivation['observee'] ?? 0);
        if ($declaree >= 4 && $observee > 0 && $observee <= 2) {
            $incoherences[] = rh_entretien_v3_incoherence('motivation_vs_engagement', 'Motivation déclarée forte mais engagement faible', 'fort', 'Écart entre motivation déclarée et engagement observé.', $blocMotivation);
        }
    } elseif ($scoreMotivation !== null && $scoreEngagement !== null && $scoreMotivation >= 4.0 && $scoreEngagement <= 2.0) {
        $incoherences[] = rh_entretien_v3_incoherence('motivation_vs_engagement', 'Motivation déclarée forte mais engagement faible', 'fort', 'Motivation élevée mais engagement faible.', null);
    }

    if (is_array($blocEvolution)) {
        $clarte = $blocEvolution['clarte'] ?? null;
        if (in_array($clarte, ['clair','tres_clair'], true) && ($scoreAutonomie !== null && $scoreAutonomie < 2.5)) {
            $incoherences[] = rh_entretien_v3_incoherence('evolution_vs_autonomie', 'Souhait d\'évolution élevé mais autonomie insuffisante', 'moyen', 'Projet d\'évolution peu cohérent avec l\'autonomie actuelle.', $blocEvolution);
        }
    }

    if (is_array($blocFormation)) {
        $priorite = $blocFormation['priorite'] ?? null;
        $pf = trim((string)($blocFormation['point_faible'] ?? ''));
        $sit = trim((string)($blocFormation['situation'] ?? ''));
        $impact = trim((string)($blocFormation['impact'] ?? ''));
        if ($priorite && $priorite !== 'refuse') {
            if ($pf === '' || $sit === '' || $impact === '') {
                $incoherences[] = rh_entretien_v3_incoherence('formation_non_justifiee', 'Demande de formation non suffisamment justifiée', 'moyen', 'La demande de formation manque d\'éléments factuels (point faible, situation, impact).', $blocFormation);
            }
        }
    }

    if ($scoreGlobal !== null && $scoreGlobal >= 3.5 && $risqueDepart === 'eleve') {
        $incoherences[] = rh_entretien_v3_incoherence('satisfaction_vs_risque_depart', 'Satisfaction déclarée haute mais risque de départ élevé', 'fort', 'Discours globalement positif mais signaux de départ importants.', null);
    }

    if ($scoreMaitrisePoste !== null && $scoreMaitrisePoste < 2.5 && in_array($besoinFormationNiveau, ['aucun','confort',null], true)) {
        $incoherences[] = rh_entretien_v3_incoherence('technique_vs_formation', 'Niveau technique faible sans demande de montée en compétence', 'moyen', 'Besoin de formation possible non perçu.', null);
    }

    if ($scoreImplication !== null && $scoreImplication >= 4.0 && in_array($risqueUsure, ['modere','eleve'], true)) {
        $incoherences[] = rh_entretien_v3_incoherence('engagement_vs_usure', 'Implication forte avec signaux d\'usure', 'fort', 'Profil engagé mais potentiellement en saturation.', null);
    }

    if ($scorePotentiel !== null && $scorePotentiel >= 3.5 && $scoreStabilite !== null && $scoreStabilite < 2.8) {
        $incoherences[] = rh_entretien_v3_incoherence('potentiel_vs_stabilite', 'Potentiel élevé mais stabilité insuffisante', 'moyen', 'Potentiel intéressant mais stabilité à sécuriser.', null);
    }

    // Coherence globale
    $coherence = 'bonne';
    if (!empty($incoherences)) {
        $hasFort = false;
        foreach ($incoherences as $inc) {
            if ($inc['niveau'] === 'fort') { $hasFort = true; break; }
        }
        $coherence = $hasFort ? 'fragile' : 'a_verifier';
    }

    // Alerts (V3 calculees)
    $alertesCalc = [];
    if (in_array($risqueDepart, ['modere','eleve'], true)) {
        $alertesCalc[] = rh_entretien_v3_alerte('risque_depart', 'Risque de départ', $risqueDepart === 'eleve' ? 'fort' : 'moyen', 'risque', 'Signaux de départ détectés.', null);
    }
    if (in_array($risqueUsure, ['modere','eleve'], true)) {
        $alertesCalc[] = rh_entretien_v3_alerte('risque_usure', 'Risque d\'usure', $risqueUsure === 'eleve' ? 'fort' : 'moyen', 'risque', 'Signaux d\'usure détectés.', null);
    }
    if ($scoreMotivation !== null && $scoreMotivation < 2.5) {
        $alertesCalc[] = rh_entretien_v3_alerte('demotivation', 'Motivation faible', 'moyen', 'motivation', 'Score motivation faible.', null);
    }
    if ($scoreRelationnel !== null && $scoreRelationnel < 2.5) {
        $alertesCalc[] = rh_entretien_v3_alerte('relationnel_faible', 'Relationnel fragile', 'moyen', 'relationnel', 'Score relationnel faible.', null);
    }

    // Recommendations (by code)
    $recCodes = [];
    if ($scoreOrganisation !== null && $scoreOrganisation < 3.0) {
        $recCodes[] = 'clarifier_priorites';
        $recCodes[] = 'formaliser_procedure';
    }
    if ($scoreMotivation !== null && $scoreMotivation < 3.0) {
        $recCodes[] = 'engager_remobilisation';
    }
    if ($scoreRelationnel !== null && $scoreRelationnel < 3.0) {
        $recCodes[] = 'travailler_relationnel';
    }
    if (in_array($besoinFormationNiveau, ['justifie','prioritaire'], true)) {
        $recCodes[] = 'lancer_tutorat';
        $recCodes[] = 'former_outils';
    }
    if ($scoreAutonomie !== null && $scoreAutonomie < 3.0) {
        $recCodes[] = 'renforcer_autonomie';
    }
    if ($scorePerformance !== null && $scorePerformance < 3.0) {
        $recCodes[] = 'consolider_methode';
    }
    $recCodes = array_values(array_unique($recCodes));

    // Comparison with previous entretien
    $comparaison = rh_entretien_v3_compare_previous($pdo, $entretien, $scores5, $scoreGlobal);
    if ($comparaison && $comparaison['delta_score_global'] !== null && $comparaison['delta_score_global'] >= 0.3) {
        $profiles[] = 'reprise_positive';
    }

    // Final profiles
    $profiles = array_values(array_unique($profiles));
    $profilPrincipal = $profiles[0] ?? null;
    $profilSecondaire = $profiles[1] ?? null;

    // Persist results
    try {
        $pdo->beginTransaction();

        // Upsert resultats_metier
        $stmtUp = $pdo->prepare("
            INSERT INTO rh_entretien_resultats_metier
                (entretien_id, score_global, score_performance, score_relationnel, score_motivation,
                 score_adaptabilite, score_autonomie, score_potentiel, score_organisation,
                 score_maitrise_poste, score_digital, score_fiabilite, score_engagement,
                 profil_principal_code, profil_secondaire_code, risque_depart, risque_usure,
                 potentiel_managerial, cause_dominante_charge, besoin_formation_niveau,
                 besoin_formation_origine, besoin_formation_solution, formation_roi_attendu,
                 coherence_globale, synthese_metier, updated_at)
            VALUES
                (:entretien_id, :score_global, :score_performance, :score_relationnel, :score_motivation,
                 :score_adaptabilite, :score_autonomie, :score_potentiel, :score_organisation,
                 :score_maitrise_poste, :score_digital, :score_fiabilite, :score_engagement,
                 :profil_principal_code, :profil_secondaire_code, :risque_depart, :risque_usure,
                 :potentiel_managerial, :cause_dominante_charge, :besoin_formation_niveau,
                 :besoin_formation_origine, :besoin_formation_solution, :formation_roi_attendu,
                 :coherence_globale, :synthese_metier, NOW())
            ON DUPLICATE KEY UPDATE
                score_global = VALUES(score_global),
                score_performance = VALUES(score_performance),
                score_relationnel = VALUES(score_relationnel),
                score_motivation = VALUES(score_motivation),
                score_adaptabilite = VALUES(score_adaptabilite),
                score_autonomie = VALUES(score_autonomie),
                score_potentiel = VALUES(score_potentiel),
                score_organisation = VALUES(score_organisation),
                score_maitrise_poste = VALUES(score_maitrise_poste),
                score_digital = VALUES(score_digital),
                score_fiabilite = VALUES(score_fiabilite),
                score_engagement = VALUES(score_engagement),
                profil_principal_code = VALUES(profil_principal_code),
                profil_secondaire_code = VALUES(profil_secondaire_code),
                risque_depart = VALUES(risque_depart),
                risque_usure = VALUES(risque_usure),
                potentiel_managerial = VALUES(potentiel_managerial),
                cause_dominante_charge = VALUES(cause_dominante_charge),
                besoin_formation_niveau = VALUES(besoin_formation_niveau),
                besoin_formation_origine = VALUES(besoin_formation_origine),
                besoin_formation_solution = VALUES(besoin_formation_solution),
                formation_roi_attendu = VALUES(formation_roi_attendu),
                coherence_globale = VALUES(coherence_globale),
                synthese_metier = VALUES(synthese_metier),
                updated_at = NOW()
        ");
        $stmtUp->execute([
            ':entretien_id' => $entretienId,
            ':score_global' => $scoreGlobal,
            ':score_performance' => $scorePerformance,
            ':score_relationnel' => $scoreRelationnel,
            ':score_motivation' => $scoreMotivation,
            ':score_adaptabilite' => $scoreAdaptabilite,
            ':score_autonomie' => $scoreAutonomie,
            ':score_potentiel' => $scorePotentiel,
            ':score_organisation' => $scoreOrganisation,
            ':score_maitrise_poste' => $scoreMaitrisePoste,
            ':score_digital' => $scoreDigital,
            ':score_fiabilite' => $scoreFiabilite,
            ':score_engagement' => $scoreEngagement,
            ':profil_principal_code' => $profilPrincipal,
            ':profil_secondaire_code' => $profilSecondaire,
            ':risque_depart' => $risqueDepart,
            ':risque_usure' => $risqueUsure,
            ':potentiel_managerial' => $potentielManagerial,
            ':cause_dominante_charge' => null,
            ':besoin_formation_niveau' => $besoinFormationNiveau,
            ':besoin_formation_origine' => $besoinFormationOrigine,
            ':besoin_formation_solution' => $besoinFormationSolution,
            ':formation_roi_attendu' => $formationRoi,
            ':coherence_globale' => $coherence,
            ':synthese_metier' => null,
        ]);

        // Cleanup calc tables
        rh_entretien_v3_cleanup($pdo, $entretienId);

        // Insert incoherences
        if (!empty($incoherences)) {
            $stmtInc = $pdo->prepare("
                INSERT INTO rh_entretien_incoherences_calculees
                    (entretien_id, regle_code, label, niveau, message_manager, details_json, visible_manager, visible_admin, created_at)
                VALUES
                    (:entretien_id, :regle_code, :label, :niveau, :message_manager, :details_json, 1, 1, NOW())
            ");
            foreach ($incoherences as $inc) {
                $stmtInc->execute([
                    ':entretien_id' => $entretienId,
                    ':regle_code' => $inc['code'],
                    ':label' => $inc['label'],
                    ':niveau' => $inc['niveau'],
                    ':message_manager' => $inc['message'],
                    ':details_json' => $inc['details'] ? json_encode($inc['details'], JSON_UNESCAPED_UNICODE) : null,
                ]);
            }
        }

        // Insert alerts calculees
        if (!empty($alertesCalc)) {
            $stmtAl = $pdo->prepare("
                INSERT INTO rh_entretien_alertes_calculees
                    (entretien_id, code, label, niveau, categorie, message_manager, recommandation_courte, visible_manager, visible_admin, traite, created_at, updated_at)
                VALUES
                    (:entretien_id, :code, :label, :niveau, :categorie, :message_manager, :recommandation_courte, 1, 1, 0, NOW(), NOW())
            ");
            foreach ($alertesCalc as $al) {
                $stmtAl->execute([
                    ':entretien_id' => $entretienId,
                    ':code' => $al['code'],
                    ':label' => $al['label'],
                    ':niveau' => $al['niveau'],
                    ':categorie' => $al['categorie'],
                    ':message_manager' => $al['message'],
                    ':recommandation_courte' => $al['recommandation'],
                ]);
            }
        }

        // Insert recommandations calculees
        if (!empty($recCodes) && rh_entretien_v3_table_exists($pdo, 'rh_entretien_recommandations_ref')) {
            $refMap = rh_entretien_v3_load_reco_refs($pdo);
            $stmtRec = $pdo->prepare("
                INSERT INTO rh_entretien_recommandations_calculees
                    (entretien_id, recommandation_code, horizon, categorie, label, description, priorite, statut, created_at, updated_at)
                VALUES
                    (:entretien_id, :code, :horizon, :categorie, :label, :description, :priorite, 'a_faire', NOW(), NOW())
            ");
            foreach ($recCodes as $code) {
                if (!isset($refMap[$code])) continue;
                $ref = $refMap[$code];
                $priorite = 'moyenne';
                if ($scorePerformance !== null && $scorePerformance < 2.5) $priorite = 'haute';
                if ($scoreMotivation !== null && $scoreMotivation < 2.5) $priorite = 'haute';
                $stmtRec->execute([
                    ':entretien_id' => $entretienId,
                    ':code' => $code,
                    ':horizon' => $ref['horizon'],
                    ':categorie' => $ref['categorie'],
                    ':label' => $ref['label'],
                    ':description' => $ref['description'],
                    ':priorite' => $priorite,
                ]);
            }
        }

        // Insert comparaison
        if ($comparaison) {
            $stmtCmp = $pdo->prepare("
                INSERT INTO rh_entretien_comparaisons
                    (entretien_id, entretien_precedent_id, evolution_globale, delta_score_global, delta_motivation,
                     delta_relationnel, delta_performance, delta_autonomie, delta_adaptabilite, delta_potentiel,
                     commentaires_comparaison, updated_at)
                VALUES
                    (:entretien_id, :entretien_precedent_id, :evolution_globale, :delta_score_global, :delta_motivation,
                     :delta_relationnel, :delta_performance, :delta_autonomie, :delta_adaptabilite, :delta_potentiel,
                     :commentaires_comparaison, NOW())
                ON DUPLICATE KEY UPDATE
                    entretien_precedent_id = VALUES(entretien_precedent_id),
                    evolution_globale = VALUES(evolution_globale),
                    delta_score_global = VALUES(delta_score_global),
                    delta_motivation = VALUES(delta_motivation),
                    delta_relationnel = VALUES(delta_relationnel),
                    delta_performance = VALUES(delta_performance),
                    delta_autonomie = VALUES(delta_autonomie),
                    delta_adaptabilite = VALUES(delta_adaptabilite),
                    delta_potentiel = VALUES(delta_potentiel),
                    commentaires_comparaison = VALUES(commentaires_comparaison),
                    updated_at = NOW()
            ");
            $stmtCmp->execute($comparaison);
        }

        // Update entretien summary fields
        $profilAuto = rh_entretien_v3_map_profil_auto($profilPrincipal);
        if ($profilAuto !== null || $scoreGlobal !== null) {
            $stmtU = $pdo->prepare("UPDATE rh_entretiens SET profil_auto = COALESCE(?, profil_auto), score_global = COALESCE(?, score_global), updated_at = NOW() WHERE id = ?");
            $stmtU->execute([$profilAuto, $scoreGlobal, $entretienId]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}
function rh_entretien_v3_table_exists(PDO $pdo, string $table): bool
{
    try {
        $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function rh_entretien_v3_parse_json(?string $value): ?array
{
    if ($value === null) return null;
    $value = trim($value);
    if ($value === '' || $value[0] !== '{') return null;
    $data = json_decode($value, true);
    return is_array($data) ? $data : null;
}

function rh_entretien_v3_load_scores(PDO $pdo, int $entretienId): array
{
    $scores = [];
    try {
        $stmt = $pdo->prepare("SELECT axe, score FROM rh_entretien_scores WHERE entretien_id = ?");
        $stmt->execute([$entretienId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $scores[$s['axe']] = (float)$s['score'];
        }
    } catch (PDOException $e) {
        // ignore
    }
    return $scores;
}

function rh_entretien_v3_load_alertes(PDO $pdo, int $entretienId): array
{
    $types = [];
    try {
        $stmt = $pdo->prepare("SELECT type_alerte FROM rh_entretien_alertes WHERE entretien_id = ? AND COALESCE(resolu,traitee,0) = 0");
        $stmt->execute([$entretienId]);
        $types = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'type_alerte');
    } catch (PDOException $e) {
        // ignore
    }
    return $types;
}

function rh_entretien_v3_eval_risque_depart(?float $motivation, ?float $engagement, ?float $relationnel, ?float $organisation, array $profiles, array $alertTypes): string
{
    $c = 0;
    if ($motivation !== null && $motivation < 2.5) $c++;
    if ($engagement !== null && $engagement < 2.5) $c++;
    if ($relationnel !== null && $relationnel < 2.5) $c++;
    if ($organisation !== null && $organisation < 2.5) $c++;
    if (in_array('risque_depart_silencieux', $profiles, true)) $c++;
    if (in_array('risque_depart', $alertTypes, true) || in_array('demotivation', $alertTypes, true)) $c++;

    if ($c >= 3) return 'eleve';
    if ($c >= 2) return 'modere';
    return 'faible';
}

function rh_entretien_v3_eval_risque_usure(?float $implication, ?float $motivation, ?float $engagement, ?float $organisation, ?float $relationnel, ?array $blocMotivation): string
{
    $c = 0;
    if ($implication !== null && $implication >= 4.0) $c++;
    if ($motivation !== null && $motivation < 3.0) $c++;
    if ($engagement !== null && $engagement < 3.0) $c++;
    if ($organisation !== null && $organisation < 2.5) $c++;
    if ($relationnel !== null && $relationnel < 2.5) $c++;
    if (is_array($blocMotivation)) {
        $declaree = (int)($blocMotivation['declaree'] ?? 0);
        $observee = (int)($blocMotivation['observee'] ?? 0);
        if ($declaree >= 4 && $observee > 0 && $observee <= 2) $c++;
    }

    if ($c >= 3) return 'eleve';
    if ($c >= 2) return 'modere';
    return 'faible';
}

function rh_entretien_v3_incoherence(string $code, string $label, string $niveau, string $message, ?array $details): array
{
    return [
        'code' => $code,
        'label' => $label,
        'niveau' => $niveau,
        'message' => $message,
        'details' => $details,
    ];
}

function rh_entretien_v3_alerte(string $code, string $label, string $niveau, string $categorie, string $message, ?string $rec): array
{
    return [
        'code' => $code,
        'label' => $label,
        'niveau' => $niveau,
        'categorie' => $categorie,
        'message' => $message,
        'recommandation' => $rec,
    ];
}

function rh_entretien_v3_load_reco_refs(PDO $pdo): array
{
    $map = [];
    try {
        $stmt = $pdo->query("SELECT code, label, horizon, categorie, description FROM rh_entretien_recommandations_ref WHERE actif = 1");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[$r['code']] = $r;
        }
    } catch (PDOException $e) {
        // ignore
    }
    return $map;
}

function rh_entretien_v3_cleanup(PDO $pdo, int $entretienId): void
{
    $tables = [
        'rh_entretien_alertes_calculees',
        'rh_entretien_incoherences_calculees',
        'rh_entretien_recommandations_calculees',
    ];
    foreach ($tables as $t) {
        try {
            $pdo->prepare("DELETE FROM `$t` WHERE entretien_id = ?")->execute([$entretienId]);
        } catch (PDOException $e) {
            // ignore
        }
    }
}

function rh_entretien_v3_compare_previous(PDO $pdo, array $entretien, array $scores5, ?float $scoreGlobal): ?array
{
    $collabId = (int)($entretien['collaborateur_id'] ?? 0);
    if ($collabId <= 0) return null;

    $currentDate = $entretien['date_realisation'] ?? $entretien['date_entretien'] ?? $entretien['date_planifiee'] ?? $entretien['created_at'] ?? date('Y-m-d H:i:s');

    try {
        $stmt = $pdo->prepare("
            SELECT id, COALESCE(date_realisation, date_entretien, date_planifiee, created_at) AS d
            FROM rh_entretiens
            WHERE collaborateur_id = ? AND id <> ?
              AND COALESCE(date_realisation, date_entretien, date_planifiee, created_at) < ?
            ORDER BY d DESC
            LIMIT 1
        ");
        $stmt->execute([$collabId, (int)$entretien['id'], $currentDate]);
        $prev = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
    if (!$prev || empty($prev['id'])) return null;

    $prevId = (int)$prev['id'];
    $prevScoresRaw = rh_entretien_v3_load_scores($pdo, $prevId);
    $prevScores5 = [];
    foreach ($prevScoresRaw as $k => $v) $prevScores5[$k] = round($v * 5, 2);

    $prevGlobal = null;
    try {
        $stmtG = $pdo->prepare("SELECT score_global FROM rh_entretien_resultats_metier WHERE entretien_id = ?");
        $stmtG->execute([$prevId]);
        $prevGlobal = $stmtG->fetchColumn();
        if ($prevGlobal !== false) $prevGlobal = (float)$prevGlobal;
    } catch (PDOException $e) {
        $prevGlobal = null;
    }

    if ($prevGlobal === null && !empty($prevScores5)) {
        $prevGlobal = rh_entretien_v3_estimate_global($prevScores5);
    }
    if ($scoreGlobal === null && !empty($scores5)) {
        $scoreGlobal = rh_entretien_v3_estimate_global($scores5);
    }

    if ($scoreGlobal === null || $prevGlobal === null) return null;

    $delta = round($scoreGlobal - $prevGlobal, 2);
    $evo = 'stable';
    if ($delta > 0.30) $evo = 'hausse';
    elseif ($delta < -0.30) $evo = 'baisse';

    return [
        ':entretien_id' => (int)$entretien['id'],
        ':entretien_precedent_id' => $prevId,
        ':evolution_globale' => $evo,
        ':delta_score_global' => $delta,
        ':delta_motivation' => rh_entretien_v3_delta($scores5, $prevScores5, 'motivation'),
        ':delta_relationnel' => rh_entretien_v3_delta($scores5, $prevScores5, 'relationnel'),
        ':delta_performance' => rh_entretien_v3_delta($scores5, $prevScores5, 'performance'),
        ':delta_autonomie' => rh_entretien_v3_delta($scores5, $prevScores5, 'autonomie'),
        ':delta_adaptabilite' => rh_entretien_v3_delta($scores5, $prevScores5, 'adaptabilite'),
        ':delta_potentiel' => rh_entretien_v3_delta($scores5, $prevScores5, 'potentiel'),
        ':commentaires_comparaison' => null,
    ];
}

function rh_entretien_v3_delta(array $cur, array $prev, string $key): ?float
{
    if (!isset($cur[$key]) || !isset($prev[$key])) return null;
    return round($cur[$key] - $prev[$key], 2);
}

function rh_entretien_v3_estimate_global(array $scores5): ?float
{
    $vals = array_filter($scores5, fn($v) => is_numeric($v));
    if (empty($vals)) return null;
    return round(array_sum($vals) / count($vals), 2);
}

function rh_entretien_v3_map_profil_auto(?string $profilPrincipal): ?string
{
    if ($profilPrincipal === null) return null;
    $map = [
        'pilier_discret' => 'pilier',
        'potentiel_consolidation' => 'potentiel',
        'managerial_emergent' => 'potentiel',
        'reprise_positive' => 'progression',
        'volontaire_non_structure' => 'a_accompagner',
        'attente_reconnaissance' => 'a_accompagner',
        'resistant_changement' => 'a_accompagner',
        'sous_exploite' => 'a_accompagner',
        'engage_sature' => 'a_risque',
        'competent_demobilise' => 'a_risque',
        'risque_relationnel' => 'a_risque',
        'risque_depart_silencieux' => 'a_risque',
    ];
    return $map[$profilPrincipal] ?? null;
}
