<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'PDO non disponible']);
    exit;
}

$roleId = current_role_id();
if ($roleId !== 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin only']);
    exit;
}

try {
    $migrationFile = __DIR__ . '/../../sql/conges_migration.sql';
    if (!file_exists($migrationFile)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Migration file not found']);
        exit;
    }

    $sql = file_get_contents($migrationFile);

    // Split by semicolon and execute each statement
    $statements = array_filter(array_map('trim', explode(';', $sql)), fn($s) => !empty($s) && substr($s, 0, 2) !== '--');

    foreach ($statements as $statement) {
        if (!empty($statement)) {
            $pdo->exec($statement);
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Migration exécutée avec succès',
        'statements_run' => count($statements)
    ]);
} catch (Exception $e) {
    error_log("Migration error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur: ' . $e->getMessage()]);
    exit;
}
?>
