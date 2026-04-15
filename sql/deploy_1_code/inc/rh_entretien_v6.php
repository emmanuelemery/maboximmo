<?php
declare(strict_types=1);
require_once __DIR__ . '/rh_entretien_v7.php';

/**
 * V6 pilotage & industrialisation layer.
 */
function rh_entretien_v6_compute(PDO $pdo, int $entretienId): void
{
    if ($entretienId <= 0) return;
    if (!rh_entretien_v6_table_exists($pdo, 'rh_entretien_modeles_synthese')) return;

    // Load entretien
    $entretien = null;
    try {
        $stmt = $pdo->prepare("SELECT id, manager_id, agence_id, societe_id, collaborateur_id, date_realisation, date_entretien, date_planifiee, created_at FROM rh_entretiens WHERE id = ?");
        $stmt->execute([$entretienId]);
        $entretien = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $entretien = null;
    }
    if (!$entretien) return;

    // Load metier
    $metier = null;
    try {
        $stmt = $pdo->prepare("SELECT * FROM rh_entretien_resultats_metier WHERE entretien_id = ?");
        $stmt->execute([$entretienId]);
        $metier = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $metier = null;
    }
    if (!$metier) return;

    // Apply synthese model
    rh_entretien_v6_apply_synthese($pdo, $entretienId, $metier);

    // Apply decision models + actions queue for decisions
    rh_entretien_v6_apply_decision_models($pdo, $entretienId, $metier);

    // Alimenter file d'attente des actions (actions suivi)
    rh_entretien_v6_alimenter_file_actions($pdo, $entretienId);

    // Pilotage manager / etablissement
    $periode = rh_entretien_v6_periode_code($entretien);
    if (!empty($entretien['manager_id'])) {
        rh_entretien_v6_pilotage_manager($pdo, (int)$entretien['manager_id'], $periode);
    }
    if (!empty($entretien['agence_id'])) {
        rh_entretien_v6_pilotage_etablissement($pdo, (int)$entretien['agence_id'], $periode);
    }

    if (function_exists('rh_entretien_v7_compute')) {
        rh_entretien_v7_compute($pdo, $entretienId);
    }
}

function rh_entretien_v6_apply_synthese(PDO $pdo, int $entretienId, array $metier): void
{
    if (!rh_entretien_v6_table_exists($pdo, 'rh_entretien_modeles_synthese')) return;
    if (!rh_entretien_v6_table_exists($pdo, 'rh_entretien_diagnostics_auto')) return;

    $scoreGlobal = isset($metier['score_global']) ? (float)$metier['score_global'] : null;
    $scoreMotivation = isset($metier['score_motivation']) ? (float)$metier['score_motivation'] : null;
    $risqueDepart = $metier['risque_depart'] ?? null;
    $risqueUsure = $metier['risque_usure'] ?? null;
    $profil = $metier['profil_principal_code'] ?? null;

    $code = 'syn_equilibree_progression';
    if (in_array($risqueDepart, ['eleve'], true) || in_array($risqueUsure, ['eleve'], true)) {
        $code = 'syn_corrective_renforcee';
    } elseif ($scoreMotivation !== null && $scoreMotivation < 2.8) {
        $code = 'syn_vigilance_motivation';
    } elseif (in_array($profil, ['potentiel_consolidation','managerial_emergent','sous_exploite'], true)) {
        $code = 'syn_evolution_potentiel';
    } elseif ($scoreGlobal !== null && $scoreGlobal >= 3.6) {
        $code = 'syn_positive_solide';
    } elseif ($scoreGlobal !== null && $scoreGlobal >= 3.0) {
        $code = 'syn_equilibree_progression';
    } else {
        $code = 'syn_corrective_renforcee';
    }

    $modele = rh_entretien_v6_fetch_synthese_modele($pdo, $code);
    if (!$modele) return;

    try {
        $stmt = $pdo->prepare("INSERT INTO rh_entretien_diagnostics_auto
            (entretien_id, diagnostic_global, synthese_courte, synthese_manager, synthese_pdf,
             resume_points_forts, resume_points_vigilance, resume_actions, generated_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NULL, NULL, NULL, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                diagnostic_global = VALUES(diagnostic_global),
                synthese_courte = VALUES(synthese_courte),
                synthese_manager = VALUES(synthese_manager),
                synthese_pdf = VALUES(synthese_pdf),
                updated_at = NOW()");
        $stmt->execute([
            $entretienId,
            $modele['template_texte'],
            $modele['label'],
            $modele['template_texte'],
            $modele['template_texte']
        ]);
    } catch (PDOException $e) {
        // ignore
    }

    // Optionnel: synchroniser la synthese metier
    if (rh_entretien_v6_table_exists($pdo, 'rh_entretien_resultats_metier')) {
        try {
            $stmt = $pdo->prepare("UPDATE rh_entretien_resultats_metier SET synthese_metier = ?, updated_at = NOW() WHERE entretien_id = ?");
            $stmt->execute([$modele['template_texte'], $entretienId]);
        } catch (PDOException $e) {
            // ignore
        }
    }
}

function rh_entretien_v6_apply_decision_models(PDO $pdo, int $entretienId, array $metier): void
{
    if (!rh_entretien_v6_table_exists($pdo, 'rh_entretien_modeles_decision')) return;
    if (!rh_entretien_v6_table_exists($pdo, 'rh_entretien_decisions_calculees')) return;

    $profil = $metier['profil_principal_code'] ?? null;
    $dominantBesoin = rh_entretien_v6_get_dominant_besoin($pdo, $entretienId);

    try {
        $stmt = $pdo->prepare("SELECT id, decision_code, justification, priorite FROM rh_entretien_decisions_calculees WHERE entretien_id = ?");
        $stmt->execute([$entretienId]);
        $decisions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $decisions = [];
    }

    foreach ($decisions as $d) {
        $decisionCode = $d['decision_code'] ?? null;
        if (!$decisionCode) continue;

        $modele = rh_entretien_v6_fetch_decision_modele($pdo, $decisionCode, $dominantBesoin, $profil);
        if (!$modele) continue;

        // Update justification only if missing/short
        $justif = trim((string)($d['justification'] ?? ''));
        if ($justif === '' || mb_strlen($justif) < 15) {
            try {
                $stmtU = $pdo->prepare("UPDATE rh_entretien_decisions_calculees SET justification = ?, updated_at = NOW() WHERE id = ?");
                $stmtU->execute([$modele['template_justification'], (int)$d['id']]);
            } catch (PDOException $e) { /* ignore */ }
        }

        // Inject a queue action derived from decision model
        rh_entretien_v6_enqueue_decision_action($pdo, $entretienId, $decisionCode, $modele['template_action'], $d['priorite'] ?? 'moyenne');
    }
}

function rh_entretien_v6_enqueue_decision_action(PDO $pdo, int $entretienId, string $decisionCode, string $libelle, string $priorite): void
{
    if (!rh_entretien_v6_table_exists($pdo, 'rh_entretien_file_attente_actions')) return;

    $libelle = trim($libelle);
    if ($libelle === '') return;

    try {
        $stmt = $pdo->prepare("SELECT id FROM rh_entretien_file_attente_actions WHERE entretien_id = ? AND categorie = 'decision' AND libelle = ? LIMIT 1");
        $stmt->execute([$entretienId, $libelle]);
        if ($stmt->fetchColumn()) return;
    } catch (PDOException $e) {
        // ignore
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO rh_entretien_file_attente_actions
            (entretien_id, action_id, priorite, categorie, libelle, statut, assigned_to, due_date, created_at, updated_at)
            VALUES (?, NULL, ?, 'decision', ?, 'nouveau', NULL, NULL, NOW(), NOW())
        ");
        $stmt->execute([$entretienId, $priorite ?: 'moyenne', $libelle]);
    } catch (PDOException $e) {
        // ignore
    }
}

function rh_entretien_v6_alimenter_file_actions(PDO $pdo, int $entretienId): void
{
    if (!rh_entretien_v6_table_exists($pdo, 'rh_entretien_file_attente_actions')) return;

    try {
        $pdo->exec("CALL sp_rh_entretien_alimenter_file_actions(" . (int)$entretienId . ")");
    } catch (PDOException $e) {
        // ignore
    }
}

function rh_entretien_v6_pilotage_manager(PDO $pdo, int $managerId, string $periodeCode): void
{
    if (!rh_entretien_v6_table_exists($pdo, 'rh_entretien_pilotage_managers')) return;
    try {
        $stmt = $pdo->prepare("CALL sp_rh_entretien_pilotage_manager(?, ?)");
        $stmt->execute([$managerId, $periodeCode]);
    } catch (PDOException $e) {
        // ignore
    }
}

function rh_entretien_v6_pilotage_etablissement(PDO $pdo, int $etablissementId, string $periodeCode): void
{
    if (!rh_entretien_v6_table_exists($pdo, 'rh_entretien_pilotage_etablissements')) return;
    try {
        $stmt = $pdo->prepare("CALL sp_rh_entretien_pilotage_etablissement(?, ?)");
        $stmt->execute([$etablissementId, $periodeCode]);
    } catch (PDOException $e) {
        // ignore
    }
}

function rh_entretien_v6_get_dominant_besoin(PDO $pdo, int $entretienId): ?string
{
    try {
        $stmt = $pdo->prepare("SELECT besoin_code FROM rh_entretien_besoins_manageriaux_calculees WHERE entretien_id = ? AND dominant = 1 LIMIT 1");
        $stmt->execute([$entretienId]);
        $code = $stmt->fetchColumn();
        return $code ? (string)$code : null;
    } catch (PDOException $e) {
        return null;
    }
}

function rh_entretien_v6_fetch_synthese_modele(PDO $pdo, string $code): ?array
{
    try {
        $stmt = $pdo->prepare("SELECT code, label, template_texte FROM rh_entretien_modeles_synthese WHERE code = ? AND actif = 1 LIMIT 1");
        $stmt->execute([$code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

function rh_entretien_v6_fetch_decision_modele(PDO $pdo, string $decisionCode, ?string $besoinCode, ?string $profilCode): ?array
{
    try {
        $stmt = $pdo->prepare("SELECT code, decision_code, besoin_managerial_code, profil_code, template_justification, template_action
            FROM rh_entretien_modeles_decision
            WHERE decision_code = ? AND actif = 1
            ORDER BY ordre_affichage ASC");
        $stmt->execute([$decisionCode]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $rows = [];
    }

    foreach ($rows as $r) {
        $okBesoin = empty($r['besoin_managerial_code']) || ($besoinCode && $r['besoin_managerial_code'] === $besoinCode);
        $okProfil = empty($r['profil_code']) || ($profilCode && $r['profil_code'] === $profilCode);
        if ($okBesoin && $okProfil) return $r;
    }

    return $rows[0] ?? null;
}

function rh_entretien_v6_periode_code(array $entretien): string
{
    $d = $entretien['date_realisation'] ?? $entretien['date_entretien'] ?? $entretien['date_planifiee'] ?? $entretien['created_at'] ?? date('Y-m-d');
    $y = date('Y', strtotime((string)$d));
    return $y ?: date('Y');
}

function rh_entretien_v6_table_exists(PDO $pdo, string $table): bool
{
    try {
        $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
        return true;
    } catch (PDOException $e) {
        return false;
    }
}