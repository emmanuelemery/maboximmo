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
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Missing id']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, titre, categorie, sujet, corps FROM mail_templates WHERE id = ?");
    $stmt->execute([$id]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($template) {
        echo json_encode(['success' => true, 'template' => $template]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Template not found']);
    }
} catch (Exception $e) {
    error_log("Error fetching template: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Fetch failed']);
}
?>
