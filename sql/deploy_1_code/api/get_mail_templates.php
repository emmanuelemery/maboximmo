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

try {
    $stmt = $pdo->query("SELECT id, titre, categorie, sujet FROM mail_templates ORDER BY categorie, titre");
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by category
    $grouped = [];
    foreach ($templates as $t) {
        $cat = $t['categorie'];
        if (!isset($grouped[$cat])) {
            $grouped[$cat] = [];
        }
        $grouped[$cat][] = $t;
    }

    echo json_encode(['success' => true, 'templates' => $grouped]);
} catch (Exception $e) {
    error_log("Error fetching templates: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Fetch failed']);
}
?>
