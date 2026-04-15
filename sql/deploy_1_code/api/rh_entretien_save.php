<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/rh_entretien_v3.php';
require_once __DIR__ . '/../inc/rh_entretien_v4.php';
require_once __DIR__ . '/../inc/rh_entretien_v5.php';
require_once __DIR__ . '/../inc/rh_entretien_v6.php';
require_login();
verify_csrf_any();
$pdo    = $GLOBALS['pdo'];
$roleId = current_role_id();
$userId = current_user_id();

header('Content-Type: application/json; charset=utf-8');

// --- Lire le body JSON ---
$rawBody = file_get_contents('php://input');
$data    = json_decode($rawBody, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Corps JSON invalide.']);
    exit;
}

$entretienId = isset($data['entretien_id']) ? (int)$data['entretien_id'] : 0;
if ($entretienId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'entretien_id manquant.']);
    exit;
}

// --- Charger l'entretien ---
try {
    $stmt = $pdo->prepare("SELECT id, manager_id, collaborateur_id, statut FROM rh_entretiens WHERE id = ?");
    $stmt->execute([$entretienId]);
    $entretien = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur base de données.']);
    exit;
}

if (!$entretien) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Entretien introuvable.']);
    exit;
}

// --- Vérifier ownership ---
if ($roleId !== 1 && (int)$entretien['manager_id'] !== $userId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accès refusé.']);
    exit;
}

// --- Vérifier que l'entretien n'est pas verrouillé ---
if (in_array($entretien['statut'], ['archive', 'signe', 'termine', 'finalise'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Entretien finalisé — modifications interdites.']);
    exit;
}

// --- Action spéciale : mise à jour rubrique_en_cours ---
if (isset($data['rubrique_en_cours'])) {
    $rubriqueCours = max(1, min(11, (int)$data['rubrique_en_cours']));
    try {
        $pdo->prepare("UPDATE rh_entretiens SET rubrique_en_cours = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$rubriqueCours, $entretienId]);
    } catch (PDOException $e) { /* ignoré */ }
    echo json_encode(['success' => true]);
    exit;
}

// --- Action spéciale : finaliser ---
if (isset($data['action']) && $data['action'] === 'finaliser') {
    try {
        $pdo->prepare("UPDATE rh_entretiens SET statut = 'finalise', updated_at = NOW() WHERE id = ?")
            ->execute([$entretienId]);
        logEntretien($pdo, $entretienId, $userId, 'finalisation', 'Entretien finalisé par le manager.');
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Erreur lors de la finalisation.']);
    }
    exit;
}

// --- Sauvegarde d'une réponse ---
$rubriqueId = isset($data['rubrique_id']) ? (int)$data['rubrique_id'] : 0;
$critereId  = isset($data['critere_id'])  ? (int)$data['critere_id']  : 0;

if ($rubriqueId <= 0 || $critereId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'rubrique_id et critere_id sont requis.']);
    exit;
}

$note                = isset($data['note']) && $data['note'] !== null ? max(1, min(5, (int)$data['note'])) : null;
$texteFinal          = isset($data['texte_final'])          ? trim((string)$data['texte_final'])          : null;
$reponseTypeId       = isset($data['reponse_type_id'])      ? (int)$data['reponse_type_id']               : null;
$visibleCollaborateur = isset($data['visible_collaborateur']) ? ($data['visible_collaborateur'] ? 1 : 0)   : 0;

try {
    $pdo->beginTransaction();

    // UPSERT réponse
    $stmtU = $pdo->prepare("
        INSERT INTO rh_entretien_reponses
            (entretien_id, rubrique_id, critere_id, note, texte_final, reponse_type_id, visible_collaborateur, created_at, updated_at)
        VALUES
            (:entretien_id, :rubrique_id, :critere_id, :note, :texte_final, :reponse_type_id, :visible_collaborateur, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            note                  = VALUES(note),
            texte_final           = VALUES(texte_final),
            reponse_type_id       = VALUES(reponse_type_id),
            visible_collaborateur = VALUES(visible_collaborateur),
            updated_at            = NOW()
    ");
    $stmtU->execute([
        ':entretien_id'          => $entretienId,
        ':rubrique_id'           => $rubriqueId,
        ':critere_id'            => $critereId,
        ':note'                  => $note,
        ':texte_final'           => $texteFinal !== '' ? $texteFinal : null,
        ':reponse_type_id'       => $reponseTypeId ?: null,
        ':visible_collaborateur' => $visibleCollaborateur,
    ]);

    // Mettre à jour statut si planifié / questionnaire envoyé
    if (in_array($entretien['statut'], ['planifie', 'questionnaire_envoye'], true)) {
        $pdo->prepare("UPDATE rh_entretiens SET statut = 'en_cours', updated_at = NOW() WHERE id = ?")
            ->execute([$entretienId]);
    }

    // Log
    logEntretien($pdo, $entretienId, $userId, 'sauvegarde',
        sprintf('Rubrique %d, critère %d — note: %s', $rubriqueId, $critereId, $note ?? 'n/a'));

    $pdo->commit();

} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur sauvegarde: ' . $e->getMessage()]);
    exit;
}

// --- V5 jobs + recalcul automatique ---
$scoresUpdated = [];
$newAlerts = [];
try {
    if (function_exists('rh_entretien_v5_enqueue_job')) {
        rh_entretien_v5_enqueue_job($pdo, $entretienId, 'recalcul_global', 'Recalcul global suite a sauvegarde');
    }
    if (function_exists('rh_entretien_v5_process_queue')) {
        $res = rh_entretien_v5_process_queue($pdo, $entretienId);
        if (is_array($res)) {
            $scoresUpdated = $res['scores'] ?? [];
            $newAlerts = $res['new_alerts'] ?? [];
        }
    }
} catch (Throwable $e) { /* non bloquant */ }
echo json_encode([
    'success'    => true,
    'saved_at'   => date('H:i'),
    'scores'     => $scoresUpdated,
    'new_alerts' => $newAlerts,
]);
exit;

// ============================================================
// FONCTIONS INTERNES
// ============================================================

/**
 * Calcule les scores par axe et les upsert dans rh_entretien_scores.
 * @return array axe => score (0.0–1.0)
 */
function calculerScores(PDO $pdo, int $entretienId): array
{
    // Mapping critere_id → axe depuis la DB (data-driven)
    $critereAxe = [];
    try {
        $stmtC = $pdo->prepare("SELECT id, axe_radar, poids_score FROM rh_entretien_criteres WHERE actif=1 AND axe_radar IS NOT NULL");
        $stmtC->execute();
        foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $critereAxe[(int)$row['id']] = ['axe' => $row['axe_radar'], 'poids' => (float)$row['poids_score']];
        }
    } catch (PDOException $e) {
        // Fallback hardcodé si tables pas encore créées
        $critereAxe = [
            10=>['axe'=>'performance','poids'=>1.5],  11=>['axe'=>'performance','poids'=>1.2],   12=>['axe'=>'organisation','poids'=>1.0],
            16=>['axe'=>'motivation','poids'=>1.5],    17=>['axe'=>'motivation','poids'=>1.0],    18=>['axe'=>'motivation','poids'=>1.2],
            13=>['axe'=>'relationnel','poids'=>1.2],   14=>['axe'=>'engagement','poids'=>1.0],    15=>['axe'=>'relationnel','poids'=>1.0],
            22=>['axe'=>'maitrise_poste','poids'=>1.5],23=>['axe'=>'maitrise_poste','poids'=>1.2],24=>['axe'=>'potentiel','poids'=>1.0],
            19=>['axe'=>'adaptabilite','poids'=>1.2],  20=>['axe'=>'adaptabilite','poids'=>1.0],  21=>['axe'=>'autonomie','poids'=>1.0],
            25=>['axe'=>'potentiel','poids'=>1.5],     26=>['axe'=>'engagement','poids'=>1.2],    27=>['axe'=>'maitrise_poste','poids'=>1.2],
        ];
    }

    // Charger toutes les notes de cet entretien
    $stmtN = $pdo->prepare("SELECT critere_id, note FROM rh_entretien_reponses WHERE entretien_id = ? AND note IS NOT NULL");
    $stmtN->execute([$entretienId]);
    $rows = $stmtN->fetchAll(PDO::FETCH_ASSOC);

    // Regrouper par axe avec pondération
    $axeSumsPond  = [];
    $axePoids     = [];
    foreach ($rows as $row) {
        $cId  = (int)$row['critere_id'];
        $info = $critereAxe[$cId] ?? null;
        if (!$info) continue;
        $axe   = is_array($info) ? $info['axe']   : $info;
        $poids = is_array($info) ? $info['poids']  : 1.0;
        $axeSumsPond[$axe]  = ($axeSumsPond[$axe]  ?? 0) + ((int)$row['note'] * $poids);
        $axePoids[$axe]     = ($axePoids[$axe]      ?? 0) + (5 * $poids);
    }

    $scores = [];
    foreach ($axeSumsPond as $axe => $sum) {
        $scores[$axe] = round($sum / $axePoids[$axe], 4); // normaliser 0-1
    }

    // Axes composés dérivés
    if (isset($scores['motivation'], $scores['engagement'])) {
        $scores['stabilite'] = round(($scores['motivation'] + $scores['engagement']) / 2, 4);
    }
    if (isset($scores['performance'], $scores['adaptabilite'])) {
        $scores['implication'] = round(($scores['performance'] + $scores['adaptabilite']) / 2, 4);
    }

    // UPSERT dans rh_entretien_scores
    $stmtSc = $pdo->prepare("
        INSERT INTO rh_entretien_scores (entretien_id, axe, score, updated_at)
        VALUES (:entretien_id, :axe, :score, NOW())
        ON DUPLICATE KEY UPDATE score = VALUES(score), updated_at = NOW()
    ");
    foreach ($scores as $axe => $score) {
        $stmtSc->execute([
            ':entretien_id' => $entretienId,
            ':axe'          => $axe,
            ':score'        => $score,
        ]);
    }

    return $scores;
}

/**
 * Détecte les alertes RH et les insère si nouvelles.
 * @return array nouvelles alertes ajoutées
 */
function detecterAlertes(PDO $pdo, int $entretienId, array $scores): array
{
    $regles = [
        [
            'condition' => fn($s) => ($s['motivation'] ?? 1) < 0.40,
            'type'      => 'demotivation',
            'niveau'    => 'danger',
            'message'   => 'Score motivation faible — risque de démotivation.',
        ],
        [
            'condition' => fn($s) => ($s['stabilite'] ?? 1) < 0.30,
            'type'      => 'risque_depart',
            'niveau'    => 'danger',
            'message'   => 'Indicateurs de stabilité bas — risque de départ.',
        ],
        [
            'condition' => fn($s) => ($s['performance'] ?? 1) < 0.35 && ($s['implication'] ?? 0) > 0.70,
            'type'      => 'surcharge',
            'niveau'    => 'warning',
            'message'   => 'Performance en baisse malgré forte implication — possible surcharge.',
        ],
        [
            'condition' => fn($s) => ($s['competences'] ?? 1) < 0.40,
            'type'      => 'besoin_formation',
            'niveau'    => 'warning',
            'message'   => 'Score compétences faible — besoin en formation identifié.',
        ],
        [
            'condition' => fn($s) => ($s['comportement'] ?? 1) < 0.35,
            'type'      => 'probleme_relationnel',
            'niveau'    => 'warning',
            'message'   => 'Score comportement/relationnel faible — point d\'attention.',
        ],
    ];

    // Charger alertes existantes (non résolues) pour éviter les doublons
    $stmtEx = $pdo->prepare("SELECT type_alerte FROM rh_entretien_alertes WHERE entretien_id = ? AND COALESCE(resolu,traitee,0) = 0");
    $stmtEx->execute([$entretienId]);
    $existants = array_column($stmtEx->fetchAll(PDO::FETCH_ASSOC), 'type_alerte');

    $nouvelles = [];
    $stmtIns   = $pdo->prepare("
        INSERT INTO rh_entretien_alertes (entretien_id, type_alerte, niveau, message, traitee, created_at)
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
            } catch (PDOException $e) { /* ignoré */ }
        }
    }

    return $nouvelles;
}

/**
 * Insère un log d'entretien.
 */
function logEntretien(PDO $pdo, int $entretienId, int $userId, string $action, string $detail = ''): void
{
    try {
        $pdo->prepare("
            INSERT INTO rh_entretien_logs (entretien_id, user_id, action, detail, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ")->execute([$entretienId, $userId, $action, $detail]);
    } catch (PDOException $e) { /* table peut ne pas exister */ }
}

