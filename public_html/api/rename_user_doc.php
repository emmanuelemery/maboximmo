<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();
verify_csrf_any();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'PDO not available']);
    exit;
}

$currentUserId = current_user_id();
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['doc_id'], $data['new_name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

$docId = (int)$data['doc_id'];
$newName = trim((string)$data['new_name']);

if (empty($newName)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Name cannot be empty']);
    exit;
}

// Verify document belongs to current user
$stmt = $pdo->prepare("SELECT id FROM salaires_documents WHERE id = ? AND id_user = ?");
$stmt->execute([$docId, $currentUserId]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Document not found or unauthorized']);
    exit;
}

// Update document name
try {
    $stmt = $pdo->prepare("UPDATE salaires_documents SET original_name = ? WHERE id = ?");
    $stmt->execute([$newName, $docId]);

    echo json_encode(['success' => true, 'message' => 'Document renamed successfully']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
