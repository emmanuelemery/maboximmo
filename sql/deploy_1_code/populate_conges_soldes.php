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
    'messages' => [],
    'by_year' => []
];

try {
    // First, ensure migrations are run
    $migrationFile = __DIR__ . '/../sql/conges_migration.sql';
    if (file_exists($migrationFile)) {
        $sql = file_get_contents($migrationFile);
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => !empty($s) && !str_starts_with($s, '--')
        );
        foreach ($statements as $statement) {
            if (!empty(trim($statement))) {
                try {
                    $pdo->exec($statement . ';');
                } catch (Exception $e) {
                    // Ignore duplicate table errors
                    if (strpos($e->getMessage(), 'already exists') === false) {
                        throw $e;
                    }
                }
            }
        }
        $result['messages'][] = '✅ Ensured conges_soldes table exists';
    }

    // Add commentaire_admin column if missing
    $migrationFile = __DIR__ . '/../sql/add_commentaire_admin_conges.sql';
    if (file_exists($migrationFile)) {
        $sql = file_get_contents($migrationFile);
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn($s) => !empty($s) && !str_starts_with($s, '--')
        );
        foreach ($statements as $statement) {
            if (!empty(trim($statement))) {
                try {
                    $pdo->exec($statement . ';');
                } catch (Exception $e) {
                    // Ignore column already exists errors
                    if (strpos($e->getMessage(), 'Duplicate') === false) {
                        throw $e;
                    }
                }
            }
        }
        $result['messages'][] = '✅ Ensured commentaire_admin column exists in conges';
    }

    // Get all leave records
    $stmt = $pdo->query("
        SELECT
            id_user,
            CASE
                WHEN MONTH(date_debut) >= 6 THEN YEAR(date_debut)
                ELSE YEAR(date_debut) - 1
            END as cycle_year,
            COUNT(*) as leave_count,
            SUM(
                DATEDIFF(date_fin, date_debut) + 1 -
                (CASE WHEN demi_journee_debut = 'apres-midi' THEN 0.5 ELSE 0 END) -
                (CASE WHEN demi_journee_fin = 'matin' THEN 0.5 ELSE 0 END)
            ) as total_days
        FROM conges
        WHERE statut != 'archivé'
        GROUP BY id_user, cycle_year
    ");

    $leaveData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // For each user and cycle year, ensure conges_soldes entry
    foreach ($leaveData as $data) {
        $userId = (int)$data['id_user'];
        $cycleYear = (int)$data['cycle_year'];
        $daysTaken = (float)($data['total_days'] ?? 0);

        // Check if entry exists
        $checkStmt = $pdo->prepare("
            SELECT id FROM conges_soldes
            WHERE id_user = ? AND annee = ?
        ");
        $checkStmt->execute([$userId, $cycleYear]);

        if ($checkStmt->rowCount() === 0) {
            // Insert new entry
            $insertStmt = $pdo->prepare("
                INSERT INTO conges_soldes (id_user, annee, jours_acquis, jours_pris)
                VALUES (?, ?, 25.0, ?)
            ");
            $insertStmt->execute([$userId, $cycleYear, $daysTaken]);
        } else {
            // Update existing entry
            $updateStmt = $pdo->prepare("
                UPDATE conges_soldes
                SET jours_pris = ?
                WHERE id_user = ? AND annee = ?
            ");
            $updateStmt->execute([$daysTaken, $userId, $cycleYear]);
        }

        if (!isset($result['by_year'][$cycleYear])) {
            $result['by_year'][$cycleYear] = 0;
        }
        $result['by_year'][$cycleYear]++;
    }

    // Get final counts
    $stmt = $pdo->query("SELECT annee, COUNT(*) as cnt FROM conges_soldes GROUP BY annee");
    $counts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($counts as $count) {
        $result['messages'][] = "📊 Year {$count['annee']}: {$count['cnt']} records in conges_soldes";
    }

    $result['messages'][] = '✅ Successfully populated conges_soldes from leave data';

} catch (Exception $e) {
    $result['success'] = false;
    $result['error'] = $e->getMessage();
    $result['trace'] = $e->getTraceAsString();
}

header('Content-Type: application/json');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>
