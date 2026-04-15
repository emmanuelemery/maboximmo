<?php
declare(strict_types=1);
header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur base de données']);
    exit;
}

$roleId = current_role_id();
$userId = current_user_id();

if ($roleId !== 1 && $roleId !== 2) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès refusé']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$congeId = (int)($data['conge_id'] ?? 0);
$reason = trim($data['reason'] ?? '');

if (!$congeId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID congé manquant']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT c.*, u.id_agence FROM conges c JOIN users u ON c.id_user = u.id WHERE c.id = ?");
    $stmt->execute([$congeId]);
    $conge = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conge) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Congé non trouvé']);
        exit;
    }

    // Check authorization
    if ($roleId === 2) {
        $managerStmt = $pdo->prepare("SELECT id_agence FROM users WHERE id = ?");
        $managerStmt->execute([$userId]);
        $manager = $managerStmt->fetch();
        if ($manager['id_agence'] != $conge['id_agence']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Non autorisé pour cette agence']);
            exit;
        }
    }

    // Update congé
    $updateStmt = $pdo->prepare("
        UPDATE conges
        SET statut = 'refusé', date_validation = NOW(), id_validateur = ?, commentaire = ?
        WHERE id = ?
    ");
    $updateStmt->execute([$userId, $reason, $congeId]);

    echo json_encode(['success' => true, 'message' => 'Congé rejeté avec succès']);
} catch (Exception $e) {
    error_log("Error rejecting conge: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur serveur']);
    exit;
}
?>
