<?php
declare(strict_types=1);

/**
 * Compute V4 RH decision engine for a given entretien.
 * Safe: no-op if V4 tables are missing.
 */
function rh_entretien_v4_compute(PDO $pdo, int $entretienId): void
{
    if ($entretienId <= 0) {
        return;
    }
    if (!rh_entretien_v4_table_exists($pdo, 'rh_entretien_decisions_calculees')) {
        return;
    }

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

    // Load V3 results
    $metier = null;
    if (rh_entretien_v4_table_exists($pdo, 'rh_entretien_resultats_metier')) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM rh_entretien_resultats_metier WHERE entretien_id = ?");
            $stmt->execute([$entretienId]);
            $metier = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $metier = null;
        }
    }
    if (!$metier) {
        return; // V4 depends on V3
    }

    // Scores (0-5)
    $scoreGlobal      = rh_entretien_v4_float($metier['score_global'] ?? null);
    $scorePerformance = rh_entretien_v4_float($metier['score_performance'] ?? null);
    $scoreRelationnel = rh_entretien_v4_float($metier['score_relationnel'] ?? null);
    $scoreMotivation  = rh_entretien_v4_float($metier['score_motivation'] ?? null);
    $scoreAdapt       = rh_entretien_v4_float($metier['score_adaptabilite'] ?? null);
    $scoreAutonomie   = rh_entretien_v4_float($metier['score_autonomie'] ?? null);
    $scorePotentiel   = rh_entretien_v4_float($metier['score_potentiel'] ?? null);
    $scoreOrganisation= rh_entretien_v4_float($metier['score_organisation'] ?? null);
    $scoreMaitrise    = rh_entretien_v4_float($metier['score_maitrise_poste'] ?? null);
    $scoreDigital     = rh_entretien_v4_float($metier['score_digital'] ?? null);
    $scoreFiabilite   = rh_entretien_v4_float($metier['score_fiabilite'] ?? null);
    $scoreEngagement  = rh_entretien_v4_float($metier['score_engagement'] ?? null);

    $risqueDepart = $metier['risque_depart'] ?? null; // faible/modere/eleve
    $risqueUsure  = $metier['risque_usure'] ?? null;
    $profilPrincipal = $metier['profil_principal_code'] ?? null;
    $profilSecondaire = $metier['profil_secondaire_code'] ?? null;
    $besoinFormationNiveau = $metier['besoin_formation_niveau'] ?? null;

    // Bloc evolution (JSON)
    $blocEvolution = rh_entretien_v4_load_bloc($pdo, $entretienId, 'bloc_evolution');
    $souhaitEvolutionFort = false;
    if (is_array($blocEvolution)) {
        $clarte = $blocEvolution['clarte'] ?? '';
        $coherence = $blocEvolution['coherence'] ?? '';
        $faisabilite = $blocEvolution['faisabilite'] ?? '';
        if (in_array($clarte, ['clair','tres_clair'], true)
            && in_array($coherence, ['coherent','tres_coherent'], true)
            && in_array($faisabilite, ['oui_court','oui_moyen'], true)) {
            $souhaitEvolutionFort = true;
        }
    }

    // Reconnaissance faible (heuristique)
    $reconnaissanceFaible = false;
    if (in_array($profilPrincipal, ['attente_reconnaissance','competent_demobilise','risque_depart_silencieux'], true)) {
        $reconnaissanceFaible = true;
    }
    if (!$reconnaissanceFaible && $scoreMotivation !== null && $scoreMotivation < 3.0 && $scoreEngagement !== null && $scoreEngagement < 3.0) {
        $reconnaissanceFaible = true;
    }

    // Projection incertaine
    $projectionIncertaine = false;
    if (is_array($blocEvolution)) {
        $clarte = $blocEvolution['clarte'] ?? '';
        $faisabilite = $blocEvolution['faisabilite'] ?? '';
        if (in_array($clarte, ['flou','absent'], true) || $faisabilite === 'non') {
            $projectionIncertaine = true;
        }
    }
    if (!$projectionIncertaine && in_array($risqueDepart, ['modere','eleve'], true)) {
        $projectionIncertaine = true;
    }

    // Decisions
    $decisions = [];
    $decisionRefs = rh_entretien_v4_load_decision_refs($pdo);

    $addDecision = function(string $code, string $justif, string $diag = null) use (&$decisions, $decisionRefs) {
        if (!isset($decisionRefs[$code])) return;
        $decisions[] = [
            'code' => $code,
            'label' => $decisionRefs[$code]['label'],
            'niveau_decision' => $decisionRefs[$code]['niveau_decision'],
            'horizon' => $decisionRefs[$code]['horizon'],
            'categorie' => $decisionRefs[$code]['categorie'],
            'priorite' => $decisionRefs[$code]['priorite_defaut'],
            'justification' => $justif,
            'diagnostic_court' => $diag,
        ];
    };

    // Heuristiques V4
    if ($scorePotentiel !== null && $scorePotentiel >= 4.0 && $reconnaissanceFaible && ($projectionIncertaine || in_array($risqueDepart, ['modere','eleve'], true))) {
        $addDecision('securiser_potentiel', 'Fort potentiel, reconnaissance insuffisante et projection incertaine ou risque de depart.', 'Risque de perte d\'un profil a fort potentiel.');
    }
    if ($risqueUsure === 'eleve') {
        $addDecision('prevention_usure', 'Risque d\'usure eleve detecte.', 'Saturation professionnelle potentielle.');
    }
    if (($scoreMotivation !== null && $scoreMotivation < 2.5) && ($scoreEngagement !== null && $scoreEngagement < 3.0) && $projectionIncertaine) {
        $addDecision('plan_remobilisation', 'Motivation et engagement faibles avec projection incertaine.', 'Risque de desengagement.');
    }
    if (($scoreMaitrise !== null && $scoreMaitrise < 2.5) && ($scorePerformance !== null && $scorePerformance < 2.5)) {
        $addDecision('remise_niveau_immediate', 'Maitrise du poste et performance faibles.', 'Besoin de remise a niveau critique.');
    }
    if ($scoreGlobal !== null && $scoreGlobal >= 3.4 && in_array($risqueDepart, ['faible',null], true) && in_array($risqueUsure, ['faible',null], true) && ($scorePotentiel !== null && $scorePotentiel < 3.6)) {
        $addDecision('consolidation_poste', 'Score global solide, risques faibles, potentiel moyen.', 'Consolidation prioritaire.');
    }
    if ($souhaitEvolutionFort && (($scoreAutonomie !== null && $scoreAutonomie < 3.0) || ($scoreOrganisation !== null && $scoreOrganisation < 3.0))) {
        $addDecision('differe_evolution_preparer', 'Souhait d\'evolution fort mais autonomie ou methode insuffisante.', 'Evolution a preparer.');
    }

    // Decisions secondaires
    if ($scoreOrganisation !== null && $scoreOrganisation < 3.0) {
        $addDecision('recentre_methode', 'Organisation et methode insuffisantes.', null);
    }
    if ($risqueDepart === 'eleve') {
        $addDecision('traitement_risque_depart', 'Risque de depart eleve.', 'Risque de rupture d\'engagement.');
    }
    if ($scoreRelationnel !== null && $scoreRelationnel < 2.5) {
        $addDecision('traitement_conflit', 'Relationnel fragile avec risque collectif.', 'Tension relationnelle probable.');
    }

    if (empty($decisions)) {
        $addDecision('clarification_objectifs', 'Aucune decision critique detectee, besoin de clarifier les attendus.', 'Stabiliser les objectifs a court terme.');
    }

    // Managerial needs
    $besoins = [];
    $refBesoins = rh_entretien_v4_load_besoins_refs($pdo);
    $addBesoin = function(string $code, string $intensite, string $justif) use (&$besoins, $refBesoins) {
        if (!isset($refBesoins[$code])) return;
        $besoins[] = [
            'code' => $code,
            'label' => $refBesoins[$code]['label'],
            'intensite' => $intensite,
            'justification' => $justif,
        ];
    };

    if ($scoreAutonomie !== null && $scoreAutonomie < 3.0) $addBesoin('besoin_cadrage', 'forte', 'Autonomie faible, besoin de cadre explicite.');
    if ($scoreAutonomie !== null && $scoreAutonomie >= 3.8) $addBesoin('besoin_autonomie', 'moyenne', 'Autonomie elevee, besoin de responsabilisation.');
    if ($reconnaissanceFaible) $addBesoin('besoin_reconnaissance', 'forte', 'Reconnaissance percue insuffisante.');
    if ($scoreOrganisation !== null && $scoreOrganisation < 3.0) $addBesoin('besoin_methode', 'forte', 'Organisation et priorisation insuffisantes.');
    if ($scoreMaitrise !== null && $scoreMaitrise < 3.0) $addBesoin('besoin_montee_competence', 'forte', 'Maitrise du poste insuffisante.');
    if ($scoreAdapt !== null && $scoreAdapt < 3.0) $addBesoin('besoin_stabilite', 'moyenne', 'Adaptabilite fragile, besoin de stabilite.');
    if ($souhaitEvolutionFort || ($scorePotentiel !== null && $scorePotentiel >= 3.5)) $addBesoin('besoin_cap_evolution', 'moyenne', 'Besoin d\'une trajectoire claire.');
    if (in_array($risqueUsure, ['modere','eleve'], true)) $addBesoin('besoin_traitement_charge', 'forte', 'Risque d\'usure et charge a traiter.');
    if ($scoreRelationnel !== null && $scoreRelationnel < 3.0) $addBesoin('besoin_soutien_relationnel', 'moyenne', 'Relationnel fragile, soutien necessaire.');
    if ($scoreMotivation !== null && $scoreMotivation < 2.8) $addBesoin('besoin_remobilisation', 'forte', 'Motivation faible, remobilisation necessaire.');

    if (empty($besoins)) {
        $addBesoin('besoin_cadrage', 'faible', 'Besoin de cadre standard.');
    }

    // Determine dominant need
    $dominantIndex = rh_entretien_v4_pick_dominant_besoin($besoins);

    // Trajectory
    $traj = rh_entretien_v4_build_trajectory($pdo, $entretien, $metier, $scoreGlobal);

    // Dashboard segments
    $segments = rh_entretien_v4_build_segments($metier, $profilPrincipal, $profilSecondaire, $risqueDepart, $risqueUsure, $decisions);

    // Validations sensibles
    $validations = rh_entretien_v4_build_validations($risqueDepart, $risqueUsure, $decisions);

    // Diagnostic global
    $diagnostic = rh_entretien_v4_build_diagnostic($metier, $decisions, $besoins, $traj);

    // Persist
    try {
        $pdo->beginTransaction();

        rh_entretien_v4_cleanup($pdo, $entretienId);

        // Decisions
        $stmtDec = $pdo->prepare("\n            INSERT INTO rh_entretien_decisions_calculees\n                (entretien_id, decision_code, label, niveau_decision, horizon, categorie, priorite, justification, diagnostic_court, statut, visible_manager, visible_admin, created_at, updated_at)\n            VALUES\n                (:entretien_id, :decision_code, :label, :niveau_decision, :horizon, :categorie, :priorite, :justification, :diagnostic_court, 'proposee', 1, 1, NOW(), NOW())\n        ");
        foreach ($decisions as $d) {
            $stmtDec->execute([
                ':entretien_id' => $entretienId,
                ':decision_code' => $d['code'],
                ':label' => $d['label'],
                ':niveau_decision' => $d['niveau_decision'],
                ':horizon' => $d['horizon'],
                ':categorie' => $d['categorie'],
                ':priorite' => $d['priorite'],
                ':justification' => $d['justification'],
                ':diagnostic_court' => $d['diagnostic_court'] ?? null,
            ]);
        }

        // Besoins manageriaux
        $stmtB = $pdo->prepare("\n            INSERT INTO rh_entretien_besoins_manageriaux_calculees\n                (entretien_id, besoin_code, label, intensite, justification, dominant, visible_manager, visible_admin, created_at, updated_at)\n            VALUES\n                (:entretien_id, :besoin_code, :label, :intensite, :justification, :dominant, 1, 1, NOW(), NOW())\n        ");
        foreach ($besoins as $i => $b) {
            $stmtB->execute([
                ':entretien_id' => $entretienId,
                ':besoin_code' => $b['code'],
                ':label' => $b['label'],
                ':intensite' => $b['intensite'],
                ':justification' => $b['justification'],
                ':dominant' => ($i === $dominantIndex) ? 1 : 0,
            ]);
        }

        // Trajectory
        if ($traj) {
            $stmtT = $pdo->prepare("\n                INSERT INTO rh_entretien_trajectoires\n                    (entretien_id, entretien_precedent_id, trajectoire_code, label, description, tendance_globale, indicateurs_json, visible_manager, visible_admin, created_at, updated_at)\n                VALUES\n                    (:entretien_id, :entretien_precedent_id, :trajectoire_code, :label, :description, :tendance_globale, :indicateurs_json, 1, 1, NOW(), NOW())\n                ON DUPLICATE KEY UPDATE\n                    entretien_precedent_id = VALUES(entretien_precedent_id),\n                    trajectoire_code = VALUES(trajectoire_code),\n                    label = VALUES(label),\n                    description = VALUES(description),\n                    tendance_globale = VALUES(tendance_globale),\n                    indicateurs_json = VALUES(indicateurs_json),\n                    updated_at = NOW()\n            ");
            $stmtT->execute($traj);
        }

        // Segments dashboard
        if (!empty($segments)) {
            $stmtS = $pdo->prepare("\n                INSERT INTO rh_entretien_dashboard_segments_calculees\n                    (entretien_id, segment_code, label, poids_lecture, visible_admin, created_at)\n                VALUES\n                    (:entretien_id, :segment_code, :label, :poids_lecture, 1, NOW())\n            ");
            foreach ($segments as $seg) {
                $stmtS->execute([
                    ':entretien_id' => $entretienId,
                    ':segment_code' => $seg['code'],
                    ':label' => $seg['label'],
                    ':poids_lecture' => $seg['poids'] ?? null,
                ]);
            }
        }

        // Validations sensibles
        if (!empty($validations)) {
            $stmtV = $pdo->prepare("\n                INSERT INTO rh_entretien_validations_sensibles\n                    (entretien_id, code, label, motif, niveau, validation_manager_requise, validation_admin_requise, statut, created_at, updated_at)\n                VALUES\n                    (:entretien_id, :code, :label, :motif, :niveau, :validation_manager_requise, :validation_admin_requise, 'en_attente', NOW(), NOW())\n            ");
            foreach ($validations as $v) {
                $stmtV->execute([
                    ':entretien_id' => $entretienId,
                    ':code' => $v['code'],
                    ':label' => $v['label'],
                    ':motif' => $v['motif'],
                    ':niveau' => $v['niveau'],
                    ':validation_manager_requise' => $v['validation_manager_requise'],
                    ':validation_admin_requise' => $v['validation_admin_requise'],
                ]);
            }
        }

        // Update synthese metier
        if (rh_entretien_v4_table_exists($pdo, 'rh_entretien_resultats_metier')) {
            $stmtU = $pdo->prepare("UPDATE rh_entretien_resultats_metier SET synthese_metier = ?, updated_at = NOW() WHERE entretien_id = ?");
            $stmtU->execute([$diagnostic, $entretienId]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

function rh_entretien_v4_table_exists(PDO $pdo, string $table): bool
{
    try {
        $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function rh_entretien_v4_float($v): ?float
{
    if ($v === null) return null;
    return is_numeric($v) ? (float)$v : null;
}

function rh_entretien_v4_load_decision_refs(PDO $pdo): array
{
    $map = [];
    try {
        $stmt = $pdo->query("SELECT code, label, niveau_decision, horizon, categorie, priorite_defaut FROM rh_entretien_decisions_ref WHERE actif = 1");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[$r['code']] = $r;
        }
    } catch (PDOException $e) {
        // ignore
    }
    return $map;
}

function rh_entretien_v4_load_besoins_refs(PDO $pdo): array
{
    $map = [];
    try {
        $stmt = $pdo->query("SELECT code, label FROM rh_entretien_besoins_manageriaux_ref WHERE actif = 1");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[$r['code']] = $r;
        }
    } catch (PDOException $e) {
        // ignore
    }
    return $map;
}

function rh_entretien_v4_load_segments_refs(PDO $pdo): array
{
    $map = [];
    try {
        $stmt = $pdo->query("SELECT code, label FROM rh_entretien_dashboard_segments_ref WHERE actif = 1");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $map[$r['code']] = $r;
        }
    } catch (PDOException $e) {
        // ignore
    }
    return $map;
}

function rh_entretien_v4_load_bloc(PDO $pdo, int $entretienId, string $typeChamp): ?array
{
    try {
        $stmt = $pdo->prepare("\n            SELECT r.texte_final\n            FROM rh_entretien_reponses r\n            LEFT JOIN rh_entretien_criteres c ON c.id = r.critere_id\n            WHERE r.entretien_id = ? AND c.type_champ = ?\n            ORDER BY r.id DESC LIMIT 1\n        ");
        $stmt->execute([$entretienId, $typeChamp]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $tf = trim((string)($row['texte_final'] ?? ''));
        if ($tf === '' || $tf[0] !== '{') return null;
        $data = json_decode($tf, true);
        return is_array($data) ? $data : null;
    } catch (PDOException $e) {
        return null;
    }
}

function rh_entretien_v4_pick_dominant_besoin(array $besoins): int
{
    $rank = ['faible' => 1, 'moyenne' => 2, 'forte' => 3];
    $bestIdx = 0;
    $bestScore = 0;
    foreach ($besoins as $i => $b) {
        $s = $rank[$b['intensite']] ?? 1;
        if ($s > $bestScore) {
            $bestScore = $s;
            $bestIdx = $i;
        }
    }
    return $bestIdx;
}

function rh_entretien_v4_build_trajectory(PDO $pdo, array $entretien, array $metier, ?float $scoreGlobal): ?array
{
    if (!rh_entretien_v4_table_exists($pdo, 'rh_entretien_comparaisons')) return null;

    try {
        $stmt = $pdo->prepare("SELECT * FROM rh_entretien_comparaisons WHERE entretien_id = ?");
        $stmt->execute([(int)$entretien['id']]);
        $cmp = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
    if (!$cmp) return null;

    $delta = isset($cmp['delta_score_global']) ? (float)$cmp['delta_score_global'] : 0.0;
    $trend = $cmp['evolution_globale'] ?? null;

    $code = 'plateau';
    $label = 'Plateau';
    $desc = 'Evolution stable.';

    if ($trend === 'hausse' && $delta >= 0.30) {
        $code = 'progression';
        $label = 'Progression';
        $desc = 'Progression reguliere sur la periode.';
    } elseif ($trend === 'baisse' && $delta <= -0.30) {
        $code = 'baisse';
        $label = 'Baisse';
        $desc = 'Baisse observee sur la periode.';
    }

    $prevId = (int)($cmp['entretien_precedent_id'] ?? 0);
    if ($prevId > 0 && $delta >= 0.30) {
        $prevProfil = null;
        try {
            $stmtP = $pdo->prepare("SELECT profil_principal_code FROM rh_entretien_resultats_metier WHERE entretien_id = ?");
            $stmtP->execute([$prevId]);
            $prevProfil = $stmtP->fetchColumn();
        } catch (PDOException $e) { $prevProfil = null; }
        if (in_array($prevProfil, ['engage_sature','competent_demobilise','risque_depart_silencieux','potentiel_consolidation','volontaire_non_structure'], true)) {
            $code = 'reprise_positive';
            $label = 'Reprise positive';
            $desc = 'Reprise positive apres une phase fragile.';
        }
    }

    if (in_array(($metier['risque_depart'] ?? ''), ['eleve'], true) || in_array(($metier['risque_usure'] ?? ''), ['eleve'], true)) {
        if ($code === 'plateau') {
            $code = 'instable';
            $label = 'Instable';
            $desc = 'Stabilite fragile malgre une evolution faible.';
        }
    }

    $indics = [
        'delta_score_global' => $delta,
        'delta_motivation' => $cmp['delta_motivation'] ?? null,
        'delta_relationnel' => $cmp['delta_relationnel'] ?? null,
        'delta_performance' => $cmp['delta_performance'] ?? null,
    ];

    return [
        ':entretien_id' => (int)$entretien['id'],
        ':entretien_precedent_id' => $prevId ?: null,
        ':trajectoire_code' => $code,
        ':label' => $label,
        ':description' => $desc,
        ':tendance_globale' => $code,
        ':indicateurs_json' => json_encode($indics, JSON_UNESCAPED_UNICODE),
    ];
}

function rh_entretien_v4_build_segments(array $metier, ?string $profilPrincipal, ?string $profilSecondaire, ?string $risqueDepart, ?string $risqueUsure, array $decisions): array
{
    $segmentsRef = [];
    // Map will be loaded externally if needed, but return codes with labels
    $segments = [];

    $add = function(string $code, string $label, ?float $poids = null) use (&$segments) {
        $segments[] = ['code' => $code, 'label' => $label, 'poids' => $poids];
    };

    if (in_array($profilPrincipal, ['potentiel_consolidation','managerial_emergent','sous_exploite'], true) || ($metier['score_potentiel'] ?? 0) >= 3.5) {
        $add('potentiels', 'Profils a potentiel', 1.0);
    }
    if (in_array($risqueDepart, ['modere','eleve'], true) || rh_entretien_v4_has_decision($decisions, 'securiser_potentiel')) {
        $add('a_securiser', 'Profils a securiser', 1.0);
    }
    if (in_array($metier['profil_principal_code'] ?? '', ['volontaire_non_structure','potentiel_consolidation','attente_reconnaissance'], true)) {
        $add('a_accompagner', 'Profils a accompagner', 0.8);
    }
    if (in_array($metier['profil_principal_code'] ?? '', ['engage_sature','competent_demobilise','risque_relationnel','risque_depart_silencieux'], true)
        || in_array($risqueDepart, ['eleve'], true)) {
        $add('a_risque', 'Profils a risque', 1.0);
    }
    if (in_array($risqueUsure, ['modere','eleve'], true) || rh_entretien_v4_has_decision($decisions, 'prevention_usure')) {
        $add('en_usure', 'Profils en usure', 1.0);
    }
    if (in_array($profilPrincipal, ['sous_exploite'], true)) {
        $add('sous_exploites', 'Profils sous-exploites', 0.7);
    }

    return $segments;
}

function rh_entretien_v4_has_decision(array $decisions, string $code): bool
{
    foreach ($decisions as $d) {
        if ($d['code'] === $code) return true;
    }
    return false;
}

function rh_entretien_v4_build_validations(?string $risqueDepart, ?string $risqueUsure, array $decisions): array
{
    $vals = [];
    $add = function(string $code, string $label, string $motif, string $niveau, int $admin) use (&$vals) {
        $vals[] = [
            'code' => $code,
            'label' => $label,
            'motif' => $motif,
            'niveau' => $niveau,
            'validation_manager_requise' => 1,
            'validation_admin_requise' => $admin,
        ];
    };

    if ($risqueDepart === 'eleve') {
        $add('risque_depart', 'Risque de depart eleve', 'Risque de depart eleve detecte.', 'critique', 1);
    }
    if ($risqueUsure === 'eleve') {
        $add('risque_usure', 'Risque d\'usure eleve', 'Risque d\'usure eleve detecte.', 'fort', 0);
    }

    $sensibles = ['plan_redressement','traitement_conflit','analyse_maintien_poste','remise_niveau_immediate'];
    foreach ($decisions as $d) {
        if (in_array($d['code'], $sensibles, true)) {
            $add('decision_' . $d['code'], $d['label'], 'Decision sensible : ' . $d['label'], 'critique', 1);
        }
    }

    return $vals;
}

function rh_entretien_v4_build_diagnostic(array $metier, array $decisions, array $besoins, ?array $traj): string
{
    $decision = $decisions[0] ?? null;
    $besoin = $besoins[0] ?? null;
    $prio = $decision['priorite'] ?? 'moyenne';
    $horizon = $decision['horizon'] ?? 'quatre_vingt_dix_jours';
    $diag = $decision['diagnostic_court'] ?? 'Diagnostic global etabli.';
    $trajLabel = $traj[':label'] ?? null;

    $parts = [];
    $parts[] = 'Diagnostic: ' . $diag;
    if ($decision) {
        $parts[] = 'Decision proposee: ' . $decision['label'];
    }
    if ($besoin) {
        $parts[] = 'Besoin dominant: ' . $besoin['label'];
    }
    $parts[] = 'Priorite RH: ' . $prio . ', horizon: ' . $horizon . '.';
    if ($trajLabel) {
        $parts[] = 'Trajectoire: ' . $trajLabel . '.';
    }

    return implode(' ', $parts);
}

function rh_entretien_v4_cleanup(PDO $pdo, int $entretienId): void
{
    $tables = [
        'rh_entretien_decisions_calculees',
        'rh_entretien_besoins_manageriaux_calculees',
        'rh_entretien_dashboard_segments_calculees',
        'rh_entretien_validations_sensibles',
    ];
    foreach ($tables as $t) {
        try {
            $pdo->prepare("DELETE FROM `$t` WHERE entretien_id = ?")->execute([$entretienId]);
        } catch (PDOException $e) {
            // ignore
        }
    }
}