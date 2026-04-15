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

$result = [
    'success' => true,
    'messages' => []
];

try {
    // 1. Run conges migration
    $migrationFile = __DIR__ . '/../sql/conges_migration.sql';
    if (file_exists($migrationFile)) {
        $sql = file_get_contents($migrationFile);
        // Split by semicolon and execute each statement
        $statements = array_filter(array_map('trim', explode(';', $sql)), fn($s) => !empty($s) && !str_starts_with($s, '--'));
        foreach ($statements as $statement) {
            if (!empty(trim($statement))) {
                $pdo->exec($statement . ';');
            }
        }
        $result['messages'][] = '✅ Migration conges_migration.sql applied';
    }

    // 2. Run commentaire_admin migration
    $migrationFile = __DIR__ . '/../sql/add_commentaire_admin_conges.sql';
    if (file_exists($migrationFile)) {
        $sql = file_get_contents($migrationFile);
        $statements = array_filter(array_map('trim', explode(';', $sql)), fn($s) => !empty($s) && !str_starts_with($s, '--'));
        foreach ($statements as $statement) {
            if (!empty(trim($statement))) {
                $pdo->exec($statement . ';');
            }
        }
        $result['messages'][] = '✅ Migration add_commentaire_admin_conges.sql applied';
    }

    // 3. Populate conges_soldes for 2025 (cycle June 2025 - May 2026)
    // Get all users with leaves
    $stmt = $pdo->query("
        SELECT DISTINCT id_user FROM conges
        WHERE YEAR(date_debut) >= 2025 OR YEAR(date_fin) >= 2025
    ");
    $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $inserted = 0;
    foreach ($userIds as $userId) {
        // Check if entry exists for 2025 cycle
        $checkStmt = $pdo->prepare("SELECT id FROM conges_soldes WHERE id_user = ? AND annee = 2025");
        $checkStmt->execute([$userId]);

        if ($checkStmt->rowCount() === 0) {
            // Insert with default values
            $insertStmt = $pdo->prepare("
                INSERT INTO conges_soldes (id_user, annee, jours_acquis, jours_pris)
                VALUES (?, 2025, 25.0, 0.0)
            ");
            $insertStmt->execute([$userId]);
            $inserted++;
        }
    }
    $result['messages'][] = "✅ Added {$inserted} entries to conges_soldes for 2025";

    // 4. Populate conges_soldes for 2026 (cycle June 2025 - May 2026)
    $inserted = 0;
    foreach ($userIds as $userId) {
        $checkStmt = $pdo->prepare("SELECT id FROM conges_soldes WHERE id_user = ? AND annee = 2026");
        $checkStmt->execute([$userId]);

        if ($checkStmt->rowCount() === 0) {
            $insertStmt = $pdo->prepare("
                INSERT INTO conges_soldes (id_user, annee, jours_acquis, jours_pris)
                VALUES (?, 2026, 25.0, 0.0)
            ");
            $insertStmt->execute([$userId]);
            $inserted++;
        }
    }
    $result['messages'][] = "✅ Added {$inserted} entries to conges_soldes for 2026";

    // 5. Verify final state
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM conges_soldes");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    $result['messages'][] = "📊 Total records in conges_soldes: {$count}";

} catch (Exception $e) {
    $result['success'] = false;
    $result['error'] = $e->getMessage();
    $result['trace'] = $e->getTraceAsString();
}

header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
