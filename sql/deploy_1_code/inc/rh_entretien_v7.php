<?php
declare(strict_types=1);

/**
 * V7 synthese intelligente: textes auto, conclusions, priorisations, signaux direction,
 * suggestions manager, segments strategiques.
 */
function rh_entretien_v7_compute(PDO $pdo, int $entretienId): void
{
    if ($entretienId <= 0) return;
    if (!rh_entretien_v7_table_exists($pdo, 'rh_entretien_textes_auto')) return;

    // Ordre recommande: conclusion -> priorisation -> signaux -> suggestions -> segments -> textes
    rh_entretien_v7_call_proc($pdo, 'sp_rh_entretien_generer_conclusion_auto', $entretienId);
    rh_entretien_v7_call_proc($pdo, 'sp_rh_entretien_generer_priorisation_auto', $entretienId);
    rh_entretien_v7_call_proc($pdo, 'sp_rh_entretien_generer_signaux_direction', $entretienId);
    rh_entretien_v7_call_proc($pdo, 'sp_rh_entretien_generer_suggestions_manager', $entretienId);
    rh_entretien_v7_call_proc($pdo, 'sp_rh_entretien_generer_segments_strategiques', $entretienId);
    rh_entretien_v7_call_proc($pdo, 'sp_rh_entretien_generer_textes_auto', $entretienId);
}

/**
 * Global chain V7: V5 + V6 + V7.
 */
function recalcul_entretien_v7(PDO $pdo, int $entretienId): void
{
    if ($entretienId <= 0) return;

    if (function_exists('rh_entretien_v5_recalcul_global')) {
        $result = ['scores' => [], 'new_alerts' => []];
        rh_entretien_v5_recalcul_global($pdo, $entretienId, $result);
    }

    if (function_exists('rh_entretien_v6_compute')) {
        rh_entretien_v6_compute($pdo, $entretienId);
    } else {
        rh_entretien_v7_compute($pdo, $entretienId);
    }
}

function rh_entretien_v7_call_proc(PDO $pdo, string $proc, int $entretienId): void
{
    try {
        $pdo->exec("CALL $proc(" . (int)$entretienId . ")");
    } catch (PDOException $e) {
        // ignore
    }
}

function rh_entretien_v7_table_exists(PDO $pdo, string $table): bool
{
    try {
        $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}