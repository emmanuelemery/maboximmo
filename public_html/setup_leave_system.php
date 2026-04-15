<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';

require_login();

$roleId = current_role_id();
if ($roleId !== 1) {
    http_response_code(403);
    die('Admin only');
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    die('Database not available');
}

// Determine which step to execute
$step = (int)($_POST['step'] ?? 1);
$output = [];

switch ($step) {
    case 1:
        // Check current state
        $output = checkSystemState($pdo);
        break;
    case 2:
        // Run migrations
        $output = runMigrations($pdo);
        break;
    case 3:
        // Populate data
        $output = populateData($pdo);
        break;
    case 4:
        // Final verification
        $output = verifySetup($pdo);
        break;
    default:
        $output[] = ['type' => 'error', 'message' => 'Unknown step'];
}

if ($_POST['step'] ?? false) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'output' => $output]);
    exit;
}

function checkSystemState($pdo) {
    $output = [];

    // Check conges table
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'conges'");
        if ($stmt->rowCount() > 0) {
            $output[] = ['type' => 'success', 'message' => 'conges table exists'];

            // Check columns
            $stmt = $pdo->query("SHOW COLUMNS FROM conges");
            $columns = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $columns[] = $row['Field'];
            }

            if (in_array('commentaire_admin', $columns)) {
                $output[] = ['type' => 'success', 'message' => 'commentaire_admin column exists'];
            } else {
                $output[] = ['type' => 'warning', 'message' => 'commentaire_admin column MISSING'];
            }
        } else {
            $output[] = ['type' => 'error', 'message' => 'conges table NOT found'];
        }
    } catch (Exception $e) {
        $output[] = ['type' => 'error', 'message' => 'Error checking conges: ' . $e->getMessage()];
    }

    // Check conges_soldes table
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'conges_soldes'");
        if ($stmt->rowCount() > 0) {
            $output[] = ['type' => 'success', 'message' => 'conges_soldes table exists'];

            $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM conges_soldes");
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

            if ($count > 0) {
                $output[] = ['type' => 'success', 'message' => "conges_soldes has $count records"];

                // Show breakdown by year
                $stmt = $pdo->query("SELECT annee, COUNT(*) as cnt FROM conges_soldes GROUP BY annee ORDER BY annee DESC");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $output[] = ['type' => 'info', 'message' => "  Year {$row['annee']}: {$row['cnt']} records"];
                }
            } else {
                $output[] = ['type' => 'warning', 'message' => 'conges_soldes is EMPTY - needs population'];
            }
        } else {
            $output[] = ['type' => 'warning', 'message' => 'conges_soldes table NOT found - needs migration'];
        }
    } catch (Exception $e) {
        $output[] = ['type' => 'warning', 'message' => 'conges_soldes table NOT found: ' . $e->getMessage()];
    }

    // Check existing leaves
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM conges WHERE statut != 'archivé'");
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
        $output[] = ['type' => 'info', 'message' => "Active leaves in system: $count"];
    } catch (Exception $e) {
        $output[] = ['type' => 'warning', 'message' => 'Could not count leaves: ' . $e->getMessage()];
    }

    return $output;
}

function runMigrations($pdo) {
    $output = [];

    try {
        // Run conges migration
        $file = __DIR__ . '/../sql/conges_migration.sql';
        if (file_exists($file)) {
            $sql = file_get_contents($file);
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                fn($s) => !empty($s) && !str_starts_with($s, '--') && !str_starts_with($s, '/*')
            );

            foreach ($statements as $statement) {
                if (!empty(trim($statement))) {
                    try {
                        $pdo->exec($statement . ';');
                    } catch (PDOException $e) {
                        // Ignore already exists errors
                        if (strpos($e->getMessage(), 'already exists') === false &&
                            strpos($e->getMessage(), 'Duplicate') === false) {
                            throw $e;
                        }
                    }
                }
            }
            $output[] = ['type' => 'success', 'message' => 'conges_migration.sql applied'];
        }
    } catch (Exception $e) {
        $output[] = ['type' => 'error', 'message' => 'Migration failed: ' . $e->getMessage()];
        return $output;
    }

    try {
        // Run commentaire_admin migration
        $file = __DIR__ . '/../sql/add_commentaire_admin_conges.sql';
        if (file_exists($file)) {
            $sql = file_get_contents($file);
            $statements = array_filter(
                array_map('trim', explode(';', $sql)),
                fn($s) => !empty($s) && !str_starts_with($s, '--')
            );

            foreach ($statements as $statement) {
                if (!empty(trim($statement))) {
                    try {
                        $pdo->exec($statement . ';');
                    } catch (PDOException $e) {
                        if (strpos($e->getMessage(), 'Duplicate') === false) {
                            throw $e;
                        }
                    }
                }
            }
            $output[] = ['type' => 'success', 'message' => 'add_commentaire_admin_conges.sql applied'];
        }
    } catch (Exception $e) {
        $output[] = ['type' => 'error', 'message' => 'commentaire_admin migration failed: ' . $e->getMessage()];
    }

    return $output;
}

function populateData($pdo) {
    $output = [];

    try {
        // Get all users with leaves
        $stmt = $pdo->query("
            SELECT DISTINCT
                c.id_user,
                CASE
                    WHEN MONTH(c.date_debut) >= 6 THEN YEAR(c.date_debut)
                    ELSE YEAR(c.date_debut) - 1
                END as cycle_year
            FROM conges c
            WHERE c.statut != 'archivé'
            ORDER BY c.id_user, cycle_year
        ");

        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $created = 0;
        $updated = 0;

        foreach ($entries as $row) {
            $userId = (int)$row['id_user'];
            $cycleYear = (int)$row['cycle_year'];

            $cycleStart = $cycleYear . '-06-01';
            $cycleEnd = ($cycleYear + 1) . '-05-31';

            // Calculate days taken in cycle
            $daysStmt = $pdo->prepare("
                SELECT SUM(
                    DATEDIFF(
                        LEAST(c.date_fin, ?),
                        GREATEST(c.date_debut, ?)
                    ) + 1 -
                    (CASE WHEN c.date_debut = GREATEST(c.date_debut, ?) AND c.demi_journee_debut = 'apres-midi' THEN 0.5 ELSE 0 END) -
                    (CASE WHEN c.date_fin = LEAST(c.date_fin, ?) AND c.demi_journee_fin = 'matin' THEN 0.5 ELSE 0 END)
                ) as total_days
                FROM conges c
                WHERE c.id_user = ?
                AND c.statut != 'archivé'
                AND c.date_debut <= ?
                AND c.date_fin >= ?
            ");
            $daysStmt->execute([$cycleEnd, $cycleStart, $cycleStart, $cycleEnd, $userId, $cycleEnd, $cycleStart]);
            $daysRow = $daysStmt->fetch(PDO::FETCH_ASSOC);
            $daysTaken = max(0, (float)($daysRow['total_days'] ?? 0));

            // Upsert
            $checkStmt = $pdo->prepare("SELECT id FROM conges_soldes WHERE id_user = ? AND annee = ?");
            $checkStmt->execute([$userId, $cycleYear]);

            if ($checkStmt->rowCount() === 0) {
                $insertStmt = $pdo->prepare("
                    INSERT INTO conges_soldes (id_user, annee, jours_acquis, jours_pris)
                    VALUES (?, ?, 25.0, ?)
                ");
                $insertStmt->execute([$userId, $cycleYear, $daysTaken]);
                $created++;
            } else {
                $updateStmt = $pdo->prepare("UPDATE conges_soldes SET jours_pris = ? WHERE id_user = ? AND annee = ?");
                $updateStmt->execute([$daysTaken, $userId, $cycleYear]);
                $updated++;
            }
        }

        $output[] = ['type' => 'success', 'message' => "Created: $created entries, Updated: $updated entries"];

    } catch (Exception $e) {
        $output[] = ['type' => 'error', 'message' => 'Population failed: ' . $e->getMessage()];
    }

    return $output;
}

function verifySetup($pdo) {
    $output = [];

    try {
        $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM conges_soldes");
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];

        $output[] = ['type' => 'info', 'message' => "Total conges_soldes records: $count"];

        $stmt = $pdo->query("
            SELECT annee, COUNT(*) as cnt, SUM(jours_pris) as total_days
            FROM conges_soldes
            GROUP BY annee
            ORDER BY annee DESC
        ");

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $output[] = ['type' => 'info', 'message' => "Year {$row['annee']}: {$row['cnt']} users, {$row['total_days']} days taken"];
        }

        $output[] = ['type' => 'success', 'message' => '✅ System setup complete! You can now export PDFs with decompte.'];

    } catch (Exception $e) {
        $output[] = ['type' => 'error', 'message' => 'Verification failed: ' . $e->getMessage()];
    }

    return $output;
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Setup - Leave System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #1a2a3a; color: #d0e4ff; padding: 40px 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        h1 { margin-bottom: 30px; font-size: 28px; }
        .status-box { background: #ffffff; border: 1px solid #ffffff; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        .message { padding: 10px 15px; margin-bottom: 10px; border-radius: 4px; border-left: 4px solid; }
        .message.success { background: rgba(16,185,129,0.1); border-color: #10b981; }
        .message.error { background: rgba(239,68,68,0.1); border-color: #ef4444; }
        .message.warning { background: rgba(245,158,11,0.1); border-color: #f59e0b; }
        .message.info { background: rgba(59,130,246,0.1); border-color: #3b82f6; }
        .button-group { display: flex; gap: 10px; margin-top: 20px; }
        button { flex: 1; padding: 12px; border: none; border-radius: 6px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s; }
        button.primary { background: rgba(16,185,129,0.2); border: 1px solid rgba(16,185,129,0.4); color: #4a6038; }
        button.primary:hover { background: rgba(16,185,129,0.3); }
        button.primary:disabled { opacity: 0.5; cursor: not-allowed; }
        .output { background: #ffffff; border: 1px solid #ffffff; border-radius: 6px; padding: 15px; margin-top: 15px; max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 12px; }
    </style>
</head>
<body>
<div class="container">
    <h1>🔧 Leave System Setup</h1>

    <div class="status-box">
        <h2>Step 1: Check Current State</h2>
        <button class="primary" onclick="runStep(1)">Check System State</button>
        <div id="output1" class="output" style="display:none;"></div>
    </div>

    <div class="status-box">
        <h2>Step 2: Apply Migrations</h2>
        <p style="margin-bottom: 10px; font-size: 13px; color: #7a91a8;">Creates conges_soldes table and adds commentaire_admin column</p>
        <button class="primary" id="btn-step2" onclick="runStep(2)" disabled>Apply Migrations</button>
        <div id="output2" class="output" style="display:none;"></div>
    </div>

    <div class="status-box">
        <h2>Step 3: Populate Leave Balances</h2>
        <p style="margin-bottom: 10px; font-size: 13px; color: #7a91a8;">Calculates and stores annual leave balances based on existing leaves</p>
        <button class="primary" id="btn-step3" onclick="runStep(3)" disabled>Populate Data</button>
        <div id="output3" class="output" style="display:none;"></div>
    </div>

    <div class="status-box">
        <h2>Step 4: Verify Setup</h2>
        <p style="margin-bottom: 10px; font-size: 13px; color: #7a91a8;">Confirms everything is working correctly</p>
        <button class="primary" id="btn-step4" onclick="runStep(4)" disabled>Verify Setup</button>
        <div id="output4" class="output" style="display:none;"></div>
    </div>

    <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ffffff; font-size: 12px; color: #7a91a8;">
        <p>After completing all steps, visit <a href="rh_conges.php" style="color: #4878a6;">rh_conges.php</a> and export a monthly PDF to see the decompte.</p>
    </div>
</div>

<script>
async function runStep(step) {
    const btn = document.getElementById(`btn-step${step}`);
    const output = document.getElementById(`output${step}`);

    btn.disabled = true;
    output.style.display = 'block';
    output.innerHTML = '<span style="color: #f59e0b;">⏳ Running...</span>';

    try {
        const response = await fetch('setup_leave_system.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `step=${step}`
        });

        const data = await response.json();
        let html = '';

        data.output.forEach(msg => {
            const cls = msg.type || 'info';
            html += `<div class="message ${cls}">${msg.message}</div>`;
        });

        output.innerHTML = html;

        // Re-enable next button
        if (step < 4) {
            const nextBtn = document.getElementById(`btn-step${step+1}`);
            if (nextBtn) nextBtn.disabled = false;
        }
    } catch (error) {
        output.innerHTML = `<div class="message error">Error: ${error.message}</div>`;
    }

    btn.disabled = false;
}

// Start with step 1
window.addEventListener('load', () => runStep(1));
</script>
</body>
</html>
