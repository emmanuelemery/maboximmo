<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

require_admin();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database not available']);
    exit;
}

try {
    // Add commentaire_admin column if it doesn't exist
    $pdo->exec("ALTER TABLE `conges` ADD COLUMN IF NOT EXISTS `commentaire_admin` TEXT NULL AFTER `commentaire`");

    echo json_encode([
        'success' => true,
        'message' => 'Colonne commentaire_admin ajoutée avec succès'
    ]);
} catch (Exception $e) {
    error_log("Migration error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
