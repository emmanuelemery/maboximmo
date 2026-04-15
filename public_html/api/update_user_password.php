<?php
declare(strict_types=1);
header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
verify_csrf_any();

// Admin only
$roleId = current_role_id();
if ($roleId !== 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['userId']) || !isset($data['password'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

$userId = (int)$data['userId'];
$password = $data['password'];

if (strlen($password) < 6) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
    exit;
}

try {
    // Verify user exists
    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $checkStmt->execute([$userId]);
    if (!$checkStmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    // Hash the password using bcrypt
    $hash = password_hash($password, PASSWORD_DEFAULT);

    // Update the password
    $stmt = $pdo->prepare("UPDATE users SET mot_de_passe = ? WHERE id = ?");
    $result = $stmt->execute([$hash, $userId]);

    if ($result && $stmt->rowCount() > 0) {
        error_log("Password updated for user ID: " . $userId);
        echo json_encode(['success' => true, 'message' => 'Password updated']);
    } else {
        error_log("Failed to update password for user ID: " . $userId);
        echo json_encode(['success' => false, 'message' => 'Update failed - no rows affected']);
    }
} catch (Exception $e) {
    error_log("Error updating password for user ID " . $userId . ": " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}
?>

