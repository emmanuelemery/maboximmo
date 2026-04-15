<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';

require_login();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    die('PDO not available');
}

$roleId = current_role_id();
if ($roleId !== 1) {
    die('Admin only');
}

// Check conges_soldes table
$result = [];

try {
    // 1. Check if table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'conges_soldes'");
    $tableExists = $stmt->rowCount() > 0;
    $result['table_exists'] = $tableExists;

    if ($tableExists) {
        // 2. Get count
        $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM conges_soldes");
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        $result['record_count'] = $count;

        // 3. Get sample data
        $stmt = $pdo->query("SELECT * FROM conges_soldes LIMIT 5");
        $result['sample_data'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. Check for 2026 data
        $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM conges_soldes WHERE annee = 2026");
        $result['records_2026'] = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

        // 5. Check fields
        $stmt = $pdo->query("DESCRIBE conges_soldes");
        $result['columns'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 6. Check conges table for commentaire_admin
    $stmt = $pdo->query("SHOW COLUMNS FROM conges LIKE 'commentaire_admin'");
    $result['conges_has_commentaire_admin'] = $stmt->rowCount() > 0;

} catch (Exception $e) {
    $result['error'] = $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
