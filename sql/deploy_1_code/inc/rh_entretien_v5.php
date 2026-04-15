<?php
declare(strict_types=1);

/**
 * V5 automation engine: jobs + recalculation pipeline.
 */
function rh_entretien_v5_enqueue_job(PDO $pdo, int $entretienId, string $jobType, ?string $message = null): void
{
    if ($entretienId <= 0) return;
    if (!rh_entretien_v5_table_exists($pdo, 'rh_entretien_jobs_calcul')) return;

    try {
        $stmt = $pdo->prepare("SELECT id FROM rh_entretien_jobs_calcul WHERE entretien_id = ? AND job_type = ? AND statut = 'a_faire' LIMIT 1");
        $stmt->execute([$entretienId, $jobType]);
        if ($stmt->fetchColumn()) return;
    } catch (PDOException $e) {
        // ignore
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO rh_entretien_jobs_calcul (entretien_id, job_type, statut, message) VALUES (?, ?, 'a_faire', ?)");
        $stmt->execute([$entretienId, $jobType, $message]);
    } catch (PDOException $e) {
        // ignore
    }
}

/**
 * Process pending jobs for one entretien.
 * Returns: ['scores' => array, 'new_alerts' => array]
 */
function rh_entretien_v5_process_queue(PDO $pdo, int $entretienId, int $limit = 20): array
{
    $result = ['scores' => [], 'new_alerts' => []];
    if ($entretienId <= 0) return $result;
    if (!rh_entretien_v5_table_exists($pdo, 'rh_entretien_jobs_calcul')) return $result;

    try {
        $stmt = $pdo->prepare("SELECT * FROM rh_entretien_jobs_calcul WHERE entretien_id = ? AND statut = 'a_faire' ORDER BY id ASC LIMIT ?");
        $stmt->execute([$entretienId, $limit]);
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return $result;
    }

    if (empty($jobs)) {
        return $result;
    }

    $hasGlobal = false;
    foreach ($jobs as $job) {
        if ($job['job_type'] === 'recalcul_global') { $hasGlobal = true; break; }
    }

    if ($hasGlobal) {
        rh_entretien_v5_mark_jobs($pdo, $entretienId, 'en_cours');
        rh_entretien_v5_recalcul_global($pdo, $entretienId, $result);
        rh_entretien_v5_mark_jobs($pdo, $entretienId, 'termine');
        return $result;
    }

    foreach ($jobs as $job) {
        $jobId = (int)$job['id'];
        rh_entretien_v5_mark_job($pdo, $jobId, 'en_cours');
        $ok = true;
        try {
            switch ($job['job_type']) {
                case 'recalcul_scores':
                    $result['scores'] = rh_entretien_v5_recalcul_scores($pdo, $entretienId);
                    break;
                case 'recalcul_incoherences':
                    if (function_exists('rh_entretien_v3_compute')) {
                        rh_entretien_v3_compute($pdo, $entretienId, ['preserve_scores' => true]);
                    }
                    break;
                case 'recalcul_alertes':
                    $result['new_alerts'] = rh_entretien_v5_recalcul_alertes($pdo, $entretienId, $result['scores']);
                    break;
                case 'recalcul_profils':
                case 'recalcul_recommandations':
                    if (function_exists('rh_entretien_v3_compute')) {
                        rh_entretien_v3_compute($pdo, $entretienId, ['preserve_scores' => true]);
                    }
                    break;
                case 'recalcul_trajectoire':
                    rh_entretien_v5_recalcul_comparaison($pdo, $entretienId);
                    if (function_exists('rh_entretien_v4_compute')) {
                        rh_entretien_v4_compute($pdo, $entretienId);
                    }
                    break;
                case 'recalcul_decisions':
                    if (function_exists('rh_entretien_v4_compute')) {
                        rh_entretien_v4_compute($pdo, $entretienId);
                    }
                    break;
                case 'recalcul_dashboard':
                    rh_entretien_v5_recalcul_dashboard_cache($pdo, $entretienId);
                    if (function_exists('rh_entretien_v6_compute')) {
                        rh_entretien_v6_compute($pdo, $entretienId);
                    }
                    break;
                default:
                    break;
            }
        } catch (Throwable $e) {
            $ok = false;
        }
        rh_entretien_v5_mark_job($pdo, $jobId, $ok ? 'termine' : 'erreur');
    }

    if (function_exists('rh_entretien_v6_compute')) {
        rh_entretien_v6_compute($pdo, $entretienId);
    }
    return $result;
}

/**
 * Global recalculation pipeline.
 */
function rh_entretien_v5_recalcul_global(PDO $pdo, int $entretienId, array &$result): void
{
    $result['scores'] = rh_entretien_v5_recalcul_scores($pdo, $entretienId);
    $result['new_alerts'] = rh_entretien_v5_recalcul_alertes($pdo, $entretienId, $result['scores']);

    if (function_exists('rh_entretien_v3_compute')) {
        rh_entretien_v3_compute($pdo, $entretienId, ['preserve_scores' => true]);
    }

    rh_entretien_v5_recalcul_comparaison($pdo, $entretienId);

    if (function_exists('rh_entretien_v4_compute')) {
        rh_entretien_v4_compute($pdo, $entretienId);
    }

    rh_entretien_v5_recalcul_diagnostic($pdo, $entretienId);
    rh_entretien_v5_recalcul_dashboard_cache($pdo, $entretienId);
    if (function_exists('rh_entretien_v6_compute')) {
        rh_entretien_v6_compute($pdo, $entretienId);
    }
}

/**
 * Alias requested by V5 specs.
 */
function recalcul_entretien_complet(PDO $pdo, int $entretienId): void
{
    $result = ['scores' => [], 'new_alerts' => []];
    rh_entretien_v5_recalcul_global($pdo, $entretienId, $result);
}

function rh_entretien_v5_recalcul_scores(PDO $pdo, int $entretienId): array
{
    if ($entretienId <= 0) return [];

    // Stored procedure for resultats_metier
    if (rh_entretien_v5_table_exists($pdo, 'rh_entretien_resultats_metier')) {
        try {
            $pdo->exec("CALL sp_rh_entretien_recalcul_scores(" . (int)$entretienId . ")");
        } catch (PDOException $e) {
            // ignore
        }
    }

    // Compute radar scores (0-1) and upsert into rh_entretien_scores
    $scores = rh_entretien_v5_calc_scores_axes($pdo, $entretienId);
    if (!empty($scores) && rh_entretien_v5_table_exists($pdo, 'rh_entretien_scores')) {
        try {
            $stmt = $pdo->prepare("INSERT INTO rh_entretien_scores (entretien_id, axe, score, updated_at)
                VALUES (:entretien_id, :axe, :score, NOW())
                ON DUPLICATE KEY UPDATE score = VALUES(score), updated_at = NOW()
            ");
            foreach ($scores as $axe => $score) {
                $stmt->execute([
                    ':entretien_id' => $entretienId,
                    ':axe' => $axe,
                    ':score' => $score,
                ]);
            }
        } catch (PDOException $e) {
            // ignore
        }
    }

    return $scores;
}

function rh_entretien_v5_recalcul_alertes(PDO $pdo, int $entretienId, array $scores): array
{
    if ($entretienId <= 0) return [];
    if (!rh_entretien_v5_table_exists($pdo, 'rh_entretien_alertes')) return [];

    if (empty($scores)) {
        $scores = rh_entretien_v5_load_scores($pdo, $entretienId);
    }

    $regles = [
        [
            'condition' => fn($s) => ($s['motivation'] ?? 1) < 0.40,
            'type'      => 'demotivation',
            'niveau'    => 'danger',
            'message'   => 'Score motivation faible - risque de demotivation.',
        ],
        [
            'condition' => fn($s) => ($s['stabilite'] ?? 1) < 0.30,
            'type'      => 'risque_depart',
            'niveau'    => 'danger',
            'message'   => 'Indicateurs de stabilite bas - risque de depart.',
        ],
        [
            'condition' => fn($s) => ($s['performance'] ?? 1) < 0.35 && ($s['implication'] ?? 0) > 0.70,
            'type'      => 'surcharge',
            'niveau'    => 'warning',
            'message'   => 'Performance en baisse malgre forte implication - possible surcharge.',
        ],
        [
            'condition' => fn($s) => ($s['competences'] ?? 1) < 0.40,
            'type'      => 'besoin_formation',
            'niveau'    => 'warning',
            'message'   => 'Score competences faible - besoin en formation identifie.',
        ],
        [
            'condition' => fn($s) => ($s['comportement'] ?? 1) < 0.35,
            'type'      => 'probleme_relationnel',
            'niveau'    => 'warning',
            'message'   => 'Score comportement/relationnel faible - point d attention.',
        ],
    ];

    $stmtEx = $pdo->prepare("SELECT type_alerte FROM rh_entretien_alertes WHERE entretien_id = ? AND COALESCE(resolu,traitee,0) = 0");
    $stmtEx->execute([$entretienId]);
    $existants = array_column($stmtEx->fetchAll(PDO::FETCH_ASSOC), 'type_alerte');

    $nouvelles = [];
    $stmtIns = $pdo->prepare("INSERT INTO rh_entretien_alertes (entretien_id, type_alerte, niveau, message, traitee, created_at)
        VALUES (:entretien_id, :type_alerte, :niveau, :message, 0, NOW())
    ");

    foreach ($regles as $regle) {
        if (!in_array($regle['type'], $existants, true) && ($regle['condition'])($scores)) {
            try {
                $stmtIns->execute([
                    ':entretien_id' => $entretienId,
                    ':type_alerte'  => $regle['type'],
                    ':niveau'       => $regle['niveau'],
                    ':message'      => $regle['message'],
                ]);
                $nouvelles[] = [
                    'type'    => $regle['type'],
                    'niveau'  => $regle['niveau'],
                    'message' => $regle['message'],
                ];
            } catch (PDOException $e) {
                // ignore
            }
        }
    }

    return $nouvelles;
}

function rh_entretien_v5_recalcul_comparaison(PDO $pdo, int $entretienId): void
{
    if ($entretienId <= 0) return;
    if (!rh_entretien_v5_table_exists($pdo, 'rh_entretien_comparaisons')) return;

    $prevId = rh_entretien_v5_find_previous_entretien($pdo, $entretienId);
    if (!$prevId) return;

    try {
        $pdo->exec("CALL sp_rh_entretien_recalcul_comparaison(" . (int)$entretienId . ", " . (int)$prevId . ")");
    } catch (PDOException $e) {
        // ignore
    }
}

function rh_entretien_v5_recalcul_diagnostic(PDO $pdo, int $entretienId): void
{
    if ($entretienId <= 0) return;
    if (!rh_entretien_v5_table_exists($pdo, 'rh_entretien_diagnostics_auto')) return;

    try {
        $pdo->exec("CALL sp_rh_entretien_recalcul_diagnostic_auto(" . (int)$entretienId . ")");
    } catch (PDOException $e) {
        // ignore
    }
}

function rh_entretien_v5_recalcul_dashboard_cache(PDO $pdo, int $entretienId): void
{
    if (!rh_entretien_v5_table_exists($pdo, 'rh_entretien_dashboard_cache')) return;
    if (!rh_entretien_v5_table_exists($pdo, 'rh_entretien_resultats_metier')) return;

    $entretien = null;
    try {
        $stmt = $pdo->prepare("SELECT id, manager_id, societe_id, agence_id FROM rh_entretiens WHERE id = ?");
        $stmt->execute([$entretienId]);
        $entretien = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $entretien = null;
    }

    rh_entretien_v5_compute_dashboard_scope($pdo, 'global', null);
    if ($entretien) {
        if (!empty($entretien['societe_id'])) {
            rh_entretien_v5_compute_dashboard_scope($pdo, 'societe', (int)$entretien['societe_id']);
        }
        if (!empty($entretien['agence_id'])) {
            rh_entretien_v5_compute_dashboard_scope($pdo, 'etablissement', (int)$entretien['agence_id']);
        }
        if (!empty($entretien['manager_id'])) {
            rh_entretien_v5_compute_dashboard_scope($pdo, 'manager', (int)$entretien['manager_id']);
        }
    }
}

function rh_entretien_v5_compute_dashboard_scope(PDO $pdo, string $scopeType, ?int $scopeId): void
{
    [$where, $params] = rh_entretien_v5_scope_where($scopeType, $scopeId);

    $avgGlobal = rh_entretien_v5_fetch_value($pdo,
        "SELECT AVG(rm.score_global) FROM rh_entretien_resultats_metier rm JOIN rh_entretiens e ON e.id = rm.entretien_id WHERE $where",
        $params
    );
    $avgMotivation = rh_entretien_v5_fetch_value($pdo,
        "SELECT AVG(rm.score_motivation) FROM rh_entretien_resultats_metier rm JOIN rh_entretiens e ON e.id = rm.entretien_id WHERE $where",
        $params
    );

    $nbPotentiel = rh_entretien_v5_fetch_value($pdo,
        "SELECT COUNT(*) FROM rh_entretien_resultats_metier rm JOIN rh_entretiens e ON e.id = rm.entretien_id
         WHERE $where AND (
           rm.profil_principal_code IN ('potentiel_consolidation','managerial_emergent','sous_exploite')
           OR e.profil_auto = 'potentiel'
         )",
        $params
    );

    $nbRisque = rh_entretien_v5_fetch_value($pdo,
        "SELECT COUNT(*) FROM rh_entretien_resultats_metier rm JOIN rh_entretiens e ON e.id = rm.entretien_id
         WHERE $where AND (
           rm.risque_depart = 'eleve' OR rm.risque_usure = 'eleve'
           OR rm.profil_principal_code IN ('engage_sature','competent_demobilise','risque_relationnel','risque_depart_silencieux')
           OR e.profil_auto = 'a_risque'
         )",
        $params
    );

    $nbUsure = rh_entretien_v5_fetch_value($pdo,
        "SELECT COUNT(*) FROM rh_entretien_resultats_metier rm JOIN rh_entretiens e ON e.id = rm.entretien_id
         WHERE $where AND (
           rm.risque_usure = 'eleve'
           OR rm.profil_principal_code = 'engage_sature'
         )",
        $params
    );

    $actions = rh_entretien_v5_fetch_actions_metrics($pdo, $where, $params);
    $nbActionsRetard = $actions['overdue'] ?? null;
    $tauxActions = null;
    if (!empty($actions['total'])) {
        $tauxActions = round(((float)$actions['done'] / (float)$actions['total']) * 100, 2);
    }

    rh_entretien_v5_upsert_metric($pdo, $scopeType, $scopeId, 'moyenne_score_global', 'Moyenne score global', $avgGlobal, null);
    rh_entretien_v5_upsert_metric($pdo, $scopeType, $scopeId, 'moyenne_motivation', 'Moyenne motivation', $avgMotivation, null);
    rh_entretien_v5_upsert_metric($pdo, $scopeType, $scopeId, 'nb_profils_potentiel', 'Nb profils a potentiel', null, $nbPotentiel);
    rh_entretien_v5_upsert_metric($pdo, $scopeType, $scopeId, 'nb_profils_a_risque', 'Nb profils a risque', null, $nbRisque);
    rh_entretien_v5_upsert_metric($pdo, $scopeType, $scopeId, 'nb_profils_en_usure', 'Nb profils en usure', null, $nbUsure);
    rh_entretien_v5_upsert_metric($pdo, $scopeType, $scopeId, 'nb_actions_en_retard', 'Nb actions en retard', null, $nbActionsRetard);
    rh_entretien_v5_upsert_metric($pdo, $scopeType, $scopeId, 'taux_realisation_actions', 'Taux realisation actions', $tauxActions, null);
}

function rh_entretien_v5_fetch_actions_metrics(PDO $pdo, string $where, array $params): array
{
    if (rh_entretien_v5_table_exists($pdo, 'rh_entretien_actions_suivi')) {
        $sql = "SELECT
            SUM(CASE WHEN a.statut <> 'abandonne' THEN 1 ELSE 0 END) AS total_actions,
            SUM(CASE WHEN a.statut = 'fait' THEN 1 ELSE 0 END) AS done_actions,
            SUM(CASE WHEN a.date_cible IS NOT NULL AND a.date_cible < CURDATE() AND a.statut NOT IN ('fait','abandonne') THEN 1 ELSE 0 END) AS overdue_actions
        FROM rh_entretien_actions_suivi a
        JOIN rh_entretiens e ON e.id = a.entretien_id
        WHERE $where";

        $row = rh_entretien_v5_fetch_row($pdo, $sql, $params);
        return [
            'total' => $row['total_actions'] ?? 0,
            'done' => $row['done_actions'] ?? 0,
            'overdue' => $row['overdue_actions'] ?? 0,
        ];
    }

    if (rh_entretien_v5_table_exists($pdo, 'rh_entretien_actions')) {
        $sql = "SELECT
            SUM(CASE WHEN a.statut <> 'annule' THEN 1 ELSE 0 END) AS total_actions,
            SUM(CASE WHEN a.statut = 'realise' THEN 1 ELSE 0 END) AS done_actions,
            SUM(CASE WHEN a.echeance IS NOT NULL AND a.echeance < CURDATE() AND a.statut NOT IN ('realise','annule') THEN 1 ELSE 0 END) AS overdue_actions
        FROM rh_entretien_actions a
        JOIN rh_entretiens e ON e.id = a.entretien_id
        WHERE $where";

        $row = rh_entretien_v5_fetch_row($pdo, $sql, $params);
        return [
            'total' => $row['total_actions'] ?? 0,
            'done' => $row['done_actions'] ?? 0,
            'overdue' => $row['overdue_actions'] ?? 0,
        ];
    }

    return ['total' => 0, 'done' => 0, 'overdue' => 0];
}

function rh_entretien_v5_upsert_metric(PDO $pdo, string $scopeType, ?int $scopeId, string $code, string $label, $value, $count): void
{
    try {
        $stmt = $pdo->prepare("INSERT INTO rh_entretien_dashboard_cache
            (scope_type, scope_id, metric_code, metric_label, metric_value, metric_count, extra_json, generated_at)
            VALUES (:scope_type, :scope_id, :metric_code, :metric_label, :metric_value, :metric_count, NULL, NOW())
            ON DUPLICATE KEY UPDATE
                metric_label = VALUES(metric_label),
                metric_value = VALUES(metric_value),
                metric_count = VALUES(metric_count),
                generated_at = VALUES(generated_at)
        ");
        $stmt->execute([
            ':scope_type' => $scopeType,
            ':scope_id' => $scopeId,
            ':metric_code' => $code,
            ':metric_label' => $label,
            ':metric_value' => $value,
            ':metric_count' => $count,
        ]);
    } catch (PDOException $e) {
        // ignore
    }
}

function rh_entretien_v5_scope_where(string $scopeType, ?int $scopeId): array
{
    if ($scopeType === 'manager') return ["e.manager_id = :scope_id", [':scope_id' => $scopeId]];
    if ($scopeType === 'societe') return ["e.societe_id = :scope_id", [':scope_id' => $scopeId]];
    if ($scopeType === 'etablissement') return ["e.agence_id = :scope_id", [':scope_id' => $scopeId]];
    return ['1=1', []];
}

function rh_entretien_v5_fetch_value(PDO $pdo, string $sql, array $params)
{
    $row = rh_entretien_v5_fetch_row($pdo, $sql, $params);
    if (!$row) return null;
    $val = array_values($row)[0] ?? null;
    return $val !== null ? (float)$val : null;
}

function rh_entretien_v5_fetch_row(PDO $pdo, string $sql, array $params): array
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : [];
    } catch (PDOException $e) {
        return [];
    }
}

function rh_entretien_v5_calc_scores_axes(PDO $pdo, int $entretienId): array
{
    $critereAxe = [];
    try {
        $stmtC = $pdo->prepare("SELECT id, axe_radar, poids_score FROM rh_entretien_criteres WHERE actif = 1 AND axe_radar IS NOT NULL");
        $stmtC->execute();
        foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $critereAxe[(int)$row['id']] = ['axe' => $row['axe_radar'], 'poids' => (float)$row['poids_score']];
        }
    } catch (PDOException $e) {
        return [];
    }

    $stmtN = $pdo->prepare("SELECT critere_id, note FROM rh_entretien_reponses WHERE entretien_id = ? AND note IS NOT NULL");
    $stmtN->execute([$entretienId]);
    $rows = $stmtN->fetchAll(PDO::FETCH_ASSOC);

    $axeSumsPond = [];
    $axePoids = [];
    foreach ($rows as $row) {
        $cId = (int)$row['critere_id'];
        $info = $critereAxe[$cId] ?? null;
        if (!$info) continue;
        $axe = $info['axe'];
        $poids = $info['poids'] > 0 ? $info['poids'] : 1.0;
        $axeSumsPond[$axe] = ($axeSumsPond[$axe] ?? 0) + ((int)$row['note'] * $poids);
        $axePoids[$axe] = ($axePoids[$axe] ?? 0) + (5 * $poids);
    }

    $scores = [];
    foreach ($axeSumsPond as $axe => $sum) {
        $scores[$axe] = round($sum / $axePoids[$axe], 4); // 0-1
    }

    if (isset($scores['motivation'], $scores['engagement'])) {
        $scores['stabilite'] = round(($scores['motivation'] + $scores['engagement']) / 2, 4);
    }
    if (isset($scores['performance'], $scores['adaptabilite'])) {
        $scores['implication'] = round(($scores['performance'] + $scores['adaptabilite']) / 2, 4);
    }

    return $scores;
}

function rh_entretien_v5_load_scores(PDO $pdo, int $entretienId): array
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

function rh_entretien_v5_find_previous_entretien(PDO $pdo, int $entretienId): ?int
{
    try {
        $stmt = $pdo->prepare("SELECT collaborateur_id, COALESCE(date_realisation, date_entretien, date_planifiee, created_at) AS d FROM rh_entretiens WHERE id = ?");
        $stmt->execute([$entretienId]);
        $cur = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
    if (!$cur) return null;
    $collabId = (int)$cur['collaborateur_id'];
    if ($collabId <= 0) return null;

    $currentDate = $cur['d'] ?? date('Y-m-d H:i:s');

    try {
        $stmt = $pdo->prepare("SELECT id FROM rh_entretiens WHERE collaborateur_id = ? AND id <> ?
            AND COALESCE(date_realisation, date_entretien, date_planifiee, created_at) < ?
            ORDER BY COALESCE(date_realisation, date_entretien, date_planifiee, created_at) DESC LIMIT 1");
        $stmt->execute([$collabId, $entretienId, $currentDate]);
        $prevId = $stmt->fetchColumn();
        return $prevId ? (int)$prevId : null;
    } catch (PDOException $e) {
        return null;
    }
}

function rh_entretien_v5_mark_job(PDO $pdo, int $jobId, string $status, ?string $message = null): void
{
    try {
        $stmt = $pdo->prepare("UPDATE rh_entretien_jobs_calcul SET statut = ?, message = COALESCE(?, message),
            started_at = CASE WHEN ? = 'en_cours' THEN NOW() ELSE started_at END,
            finished_at = CASE WHEN ? IN ('termine','erreur') THEN NOW() ELSE finished_at END,
            updated_at = NOW()
            WHERE id = ?");
        $stmt->execute([$status, $message, $status, $status, $jobId]);
    } catch (PDOException $e) {
        // ignore
    }
}

function rh_entretien_v5_mark_jobs(PDO $pdo, int $entretienId, string $status): void
{
    try {
        $stmt = $pdo->prepare("UPDATE rh_entretien_jobs_calcul SET statut = ?,
            started_at = CASE WHEN ? = 'en_cours' THEN NOW() ELSE started_at END,
            finished_at = CASE WHEN ? IN ('termine','erreur') THEN NOW() ELSE finished_at END,
            updated_at = NOW()
            WHERE entretien_id = ? AND statut = 'a_faire'");
        $stmt->execute([$status, $status, $status, $entretienId]);
    } catch (PDOException $e) {
        // ignore
    }
}

function rh_entretien_v5_table_exists(PDO $pdo, string $table): bool
{
    try {
        $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}