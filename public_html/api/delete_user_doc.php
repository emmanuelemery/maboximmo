<?php
declare(strict_types=1);
header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();
verify_csrf_any();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$docId = (int)($data['doc_id'] ?? 0);

if (!$docId) {
    echo json_encode(['success' => false, 'message' => 'Invalid document ID']);
    exit;
}

try {
    // Cherche dans salaires_documents puis rh_documents
    $doc = null;
    $sourceTable = null;

    $stmt = $pdo->prepare("SELECT id_user, file_path, original_name, filename FROM salaires_documents WHERE id = ?");
    $stmt->execute([$docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($doc) { $sourceTable = 'salaires_documents'; }

    if (!$doc) {
        $stmt = $pdo->prepare("SELECT id_user, file_path, original_name, filename FROM rh_documents WHERE id = ?");
        $stmt->execute([$docId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($doc) { $sourceTable = 'rh_documents'; }
    }

    if (!$doc) {
        echo json_encode(['success' => false, 'message' => 'Document not found']);
        exit;
    }

    $currentUserId = current_user_id();
    $roleId        = current_role_id();
    $agenceScope   = can_manage_salaires_agence();

    // Check access: admin, document owner, ou gestionnaire agence pour son agence
    if ($roleId !== 1 && $currentUserId !== (int)$doc['id_user']) {
        $denied = true;
        if ($agenceScope > 0) {
            $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? AND id_agence = ?");
            $chk->execute([$doc['id_user'], $agenceScope]);
            if ($chk->fetch()) $denied = false;
        }
        if ($denied) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            exit;
        }
    }

    // Delete file — validate path stays within uploads to prevent traversal
    $uploadsBase = realpath(__DIR__ . '/../uploads');
    $filePath = $doc['file_path'] ?? '';
    $resolvedPath = realpath(__DIR__ . '/..' . $filePath);
    if (!$resolvedPath) { $resolvedPath = realpath(__DIR__ . '/../' . $filePath); }
    if ($resolvedPath && $uploadsBase && strpos($resolvedPath, $uploadsBase . DIRECTORY_SEPARATOR) === 0) {
        @unlink($resolvedPath);
    }

    // Delete from source table
    $stmt = $pdo->prepare("DELETE FROM {$sourceTable} WHERE id = ?");
    $stmt->execute([$docId]);

    // Cross-table cleanup
    if ($sourceTable === 'salaires_documents') {
        try {
            $stmt = $pdo->prepare("UPDATE rh_documents SET actif = 0 WHERE id_user = ? AND actif = 1 AND (original_name = ? OR filename = ?)");
            $stmt->execute([$doc['id_user'], $doc['original_name'] ?? '', $doc['filename'] ?? '']);
        } catch (Exception $e) { /* ignore */ }
    }

    echo json_encode(['success' => true, 'message' => 'Document deleted']);
} catch (Exception $e) {
    error_log("Error deleting document: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Delete failed']);
    exit;
}
?>
