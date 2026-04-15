<?php
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    echo json_encode(['error' => 'PDO not available']);
    exit;
}

$roleId = current_role_id();
$userId = current_user_id();

// Test with hardcoded data
$id_user = $userId;
$date_debut = date('Y-m-d');
$date_fin = date('Y-m-d', strtotime('+1 day'));
$motif = 'conges_payes';
$commentaire = 'Test direct';
$demi_journee_debut = 'matin';
$demi_journee_fin = 'apres-midi';

try {
    // Check user exists
    $userStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND actif = 1");
    $userStmt->execute([$id_user]);
    if (!$userStmt->fetch()) {
        echo json_encode(['error' => 'User not found or not active', 'user_id' => $id_user]);
        exit;
    }

    // Insert leave
    $stmt = $pdo->prepare("
        INSERT INTO conges (id_user, date_demande, date_debut, date_fin, demi_journee_debut, demi_journee_fin, motif, commentaire, statut)
        VALUES (?, NOW(), ?, ?, ?, ?, ?, ?, 'validé')
    ");
    $result = $stmt->execute([$id_user, $date_debut, $date_fin, $demi_journee_debut, $demi_journee_fin, $motif, $commentaire]);

    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Leave created successfully',
            'inserted_id' => $pdo->lastInsertId(),
            'data' => [
                'id_user' => $id_user,
                'date_debut' => $date_debut,
                'date_fin' => $date_fin,
                'motif' => $motif,
                'statut' => 'validé'
            ]
        ]);
    } else {
        echo json_encode(['error' => 'Insert failed', 'errorInfo' => $stmt->errorInfo()]);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
