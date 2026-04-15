<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

try {
    $pdo = db();

    // Get all tables
    $result = $pdo->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'maboximmo' ORDER BY TABLE_NAME");
    $tables = $result->fetchAll(PDO::FETCH_COLUMN);

    $tablesInfo = [];
    foreach ($tables as $table) {
        $stmt = $pdo->prepare("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'maboximmo' AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION");
        $stmt->execute([$table]);
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $tablesInfo[$table] = $columns;
    }

    echo json_encode($tablesInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
