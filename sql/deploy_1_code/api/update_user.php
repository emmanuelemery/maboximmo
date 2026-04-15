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

if (!isset($data['userId']) || !isset($data['field']) || !isset($data['value'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

$userId = (int)$data['userId'];
$field = $data['field'];
$value = $data['value'];

// Allowed fields for update
$allowedFields = ['email', 'telephone', 'id_role', 'id_societe', 'id_agence', 'actif', 'user_color', 'couleur', 'service'];

if (!in_array($field, $allowedFields)) {
    echo json_encode(['success' => false, 'message' => 'Field not allowed']);
    exit;
}

// Create service column if it doesn't exist
if ($field === 'service') {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'service'");
        if ($stmt->rowCount() === 0) {
            $pdo->exec("ALTER TABLE users ADD COLUMN service VARCHAR(20) DEFAULT 'gestion' AFTER id_societe");
        }
    } catch (Exception $e) {
        // Column might already exist, continue
    }
}

try {
    // Handle empty values
    if ($field === 'id_societe' || $field === 'id_agence') {
        if ($value === '' || $value === null) {
            $value = null;
        } else {
            $value = (int)$value;
        }
    } elseif ($field === 'id_role') {
        $value = (int)$value;
    } elseif ($field === 'actif') {
        $value = (int)$value;
    } elseif ($field === 'user_color' || $field === 'couleur') {
        // Validate hex color
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
            echo json_encode(['success' => false, 'message' => 'Invalid color format']);
            exit;
        }
    } elseif ($field === 'service') {
        // Validate service
        if (!in_array($value, ['gestion', 'syndic'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid service']);
            exit;
        }
    }

    $stmt = $pdo->prepare("UPDATE users SET `$field` = ? WHERE id = ?");
    $stmt->execute([$value, $userId]);

    echo json_encode(['success' => true, 'message' => 'Updated successfully']);
} catch (Exception $e) {
    error_log("Error updating user: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Update failed']);
    exit;
}
?>

