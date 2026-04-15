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

$output = [];

// Step 1: Ensure migrations are applied
$output[] = "Step 1: Applying migrations...";

try {
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
                    // Ignore existing table/column errors
                    if (strpos($e->getMessage(), 'already exists') === false &&
                        strpos($e->getMessage(), 'Duplicate') === false) {
                        throw $e;
                    }
                }
            }
        }
        $output[] = "  ✅ conges_migration.sql applied";
    }

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
                    // Ignore column already exists
                    if (strpos($e->getMessage(), 'Duplicate') === false) {
                        throw $e;
                    }
                }
            }
        }
        $output[] = "  ✅ add_commentaire_admin_conges.sql applied";
    }
} catch (Exception $e) {
    $output[] = "  ❌ Error: " . $e->getMessage();
    header('Content-Type: text/plain');
    echo implode("\n", $output);
    exit(1);
}

// Step 2: Populate conges_soldes from existing leave data
$output[] = "\nStep 2: Populating conges_soldes from leave data...";

try {
    // Get users and their leaves organized by cycle year
    $stmt = $pdo->query("
        SELECT
            id_user,
            CASE
                WHEN MONTH(date_debut) >= 6 THEN YEAR(date_debut)
                ELSE YEAR(date_debut) - 1
            END as cycle_year
        FROM conges
        WHERE statut != 'archivé'
        GROUP BY id_user, cycle_year
    ");

    $userCycles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $created = 0;
    $updated = 0;

    foreach ($userCycles as $row) {
        $userId = (int)$row['id_user'];
        $cycleYear = (int)$row['cycle_year'];

        // Calculate days taken in this cycle (June cycleYear to May cycleYear+1)
        // A leave counts if it overlaps with this period
        $cycleStart = $cycleYear . '-06-01';
        $cycleEnd = ($cycleYear + 1) . '-05-31';

        $daysStmt = $pdo->prepare("
            SELECT SUM(
                DATEDIFF(
                    LEAST(c.date_fin, ?),
                    GREATEST(c.date_debut, ?)
                ) + 1 -
                (CASE WHEN c.date_debut = ? AND c.demi_journee_debut = 'apres-midi' THEN 0.5 ELSE 0 END) -
                (CASE WHEN c.date_fin = ? AND c.demi_journee_fin = 'matin' THEN 0.5 ELSE 0 END)
            ) as total_days
            FROM conges c
            WHERE c.id_user = ?
            AND c.statut != 'archivé'
            AND c.date_debut <= ?
            AND c.date_fin >= ?
        ");
        $daysStmt->execute([$cycleEnd, $cycleStart, $cycleStart, $cycleEnd, $userId, $cycleEnd, $cycleStart]);
        $daysRow = $daysStmt->fetch(PDO::FETCH_ASSOC);
        $daysTaken = (float)($daysRow['total_days'] ?? 0);

        // Check if entry exists
        $checkStmt = $pdo->prepare("SELECT id FROM conges_soldes WHERE id_user = ? AND annee = ?");
        $checkStmt->execute([$userId, $cycleYear]);

        if ($checkStmt->rowCount() === 0) {
            // Insert
            $insertStmt = $pdo->prepare("
                INSERT INTO conges_soldes (id_user, annee, jours_acquis, jours_pris)
                VALUES (?, ?, 25.0, ?)
            ");
            $insertStmt->execute([$userId, $cycleYear, $daysTaken]);
            $created++;
        } else {
            // Update
            $updateStmt = $pdo->prepare("
                UPDATE conges_soldes SET jours_pris = ? WHERE id_user = ? AND annee = ?
            ");
            $updateStmt->execute([$daysTaken, $userId, $cycleYear]);
            $updated++;
        }
    }

    $output[] = "  ✅ Created: $created, Updated: $updated";
} catch (Exception $e) {
    $output[] = "  ❌ Error: " . $e->getMessage();
    header('Content-Type: text/plain');
    echo implode("\n", $output);
    exit(1);
}

// Step 3: Verify
$output[] = "\nStep 3: Verification...";

try {
    // Check table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'conges_soldes'");
    if ($stmt->rowCount() > 0) {
        $output[] = "  ✅ conges_soldes table exists";
    } else {
        $output[] = "  ❌ conges_soldes table NOT found";
        header('Content-Type: text/plain');
        echo implode("\n", $output);
        exit(1);
    }

    // Check record count
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM conges_soldes");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    $output[] = "  ✅ Total records: $count";

    // Check by year
    $stmt = $pdo->query("SELECT annee, COUNT(*) as cnt FROM conges_soldes GROUP BY annee ORDER BY annee");
    $byYear = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($byYear as $row) {
        $output[] = "    - Year {$row['annee']}: {$row['cnt']} records";
    }

    // Check commentaire_admin column
    $stmt = $pdo->query("SHOW COLUMNS FROM conges LIKE 'commentaire_admin'");
    if ($stmt->rowCount() > 0) {
        $output[] = "  ✅ commentaire_admin column exists";
    } else {
        $output[] = "  ⚠️  commentaire_admin column NOT found (will add)";
        $pdo->exec("ALTER TABLE conges ADD COLUMN IF NOT EXISTS commentaire_admin TEXT NULL");
        $output[] = "     ✅ Added commentaire_admin column";
    }

} catch (Exception $e) {
    $output[] = "  ❌ Error: " . $e->getMessage();
    header('Content-Type: text/plain');
    echo implode("\n", $output);
    exit(1);
}

$output[] = "\n✅ All initialization steps completed successfully!";
$output[] = "You can now access: http://localhost/MaBoxImmo2026/public_html/rh_conges.php";
$output[] = "Try exporting a monthly PDF to see the decompte in action.";

header('Content-Type: text/plain');
echo implode("\n", $output);
?>
