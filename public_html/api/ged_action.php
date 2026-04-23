<?php
/**
 * api/ged_action.php — Actions de validation sur ged_classification_staging
 *
 * Actions :
 *   validate       → validated = 1 (prêt pour Phase 3)
 *   reject         → validated = -1 (fichier ignoré)
 *   reassign_prop  → id_proprietaire = X
 *   reassign_imm   → id_immeuble = X
 *   reassign_bien  → id_bien = X
 *   comment        → commentaire humain
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$roleId = (int)current_role_id();
if ($roleId !== 1) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Super admin only']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}

try {
    verify_csrf_any();
    $pdo = $GLOBALS['pdo'];
    $userId = (int)current_user_id();

    $id = (int)($_POST['id'] ?? 0);  // id de ged_classification_staging
    $action = (string)($_POST['action'] ?? '');
    if ($id <= 0) throw new RuntimeException('id manquant');

    $st = $pdo->prepare("SELECT id_manifest FROM ged_classification_staging WHERE id = ? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Classification introuvable');

    switch ($action) {
        case 'validate':
            $pdo->prepare("UPDATE ged_classification_staging
                           SET validated = 1, validated_by = ?, validated_at = NOW()
                           WHERE id = ?")
                ->execute([$userId, $id]);
            break;

        case 'reject':
            $pdo->prepare("UPDATE ged_classification_staging
                           SET validated = -1, validated_by = ?, validated_at = NOW()
                           WHERE id = ?")
                ->execute([$userId, $id]);
            break;

        case 'reassign_prop':
            $newId = (int)($_POST['new_id'] ?? 0) ?: null;
            $pdo->prepare("UPDATE ged_classification_staging
                           SET id_proprietaire = ?, confidence = 'probable'
                           WHERE id = ?")
                ->execute([$newId, $id]);
            break;

        case 'reassign_imm':
            $newId = (int)($_POST['new_id'] ?? 0) ?: null;
            $pdo->prepare("UPDATE ged_classification_staging
                           SET id_immeuble = ?, confidence = 'probable'
                           WHERE id = ?")
                ->execute([$newId, $id]);
            break;

        case 'reassign_bien':
            $newId = (int)($_POST['new_id'] ?? 0) ?: null;
            $pdo->prepare("UPDATE ged_classification_staging
                           SET id_bien = ?
                           WHERE id = ?")
                ->execute([$newId, $id]);
            break;

        case 'comment':
            $c = trim((string)($_POST['comment'] ?? ''));
            $pdo->prepare("UPDATE ged_classification_staging SET comment = ? WHERE id = ?")
                ->execute([$c ?: null, $id]);
            break;

        case 'validate_all_certain':
            // Batch : valide tous les "certain" non encore validés
            $batch = (string)($_POST['batch'] ?? '');
            if ($batch === '') throw new RuntimeException('batch manquant');
            $stU = $pdo->prepare("
                UPDATE ged_classification_staging c
                JOIN ged_manifest m ON m.id = c.id_manifest
                SET c.validated = 1, c.validated_by = ?, c.validated_at = NOW()
                WHERE m.batch_id = ? AND c.confidence = 'certain' AND c.validated = 0
            ");
            $stU->execute([$userId, $batch]);
            exit(json_encode(['ok' => true, 'affected' => $stU->rowCount()]));

        default:
            throw new RuntimeException('Action inconnue : ' . $action);
    }

    // Retourne l'état à jour
    $stR = $pdo->prepare("SELECT id, id_proprietaire, id_immeuble, id_bien, confidence, validated FROM ged_classification_staging WHERE id = ?");
    $stR->execute([$id]);
    $refreshed = $stR->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'state' => $refreshed]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
