<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';

require_login();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    die('PDO not available');
}

$roleId = current_role_id();
if ($roleId !== 1) {
    http_response_code(403);
    die('Admin only');
}

$output = [];

try {
    // 1. Create/Drop table first
    $output[] = "Step 1: Creating schema...";

    // Drop old table if exists
    try {
        $pdo->exec("DROP TABLE IF EXISTS `conges_soldes`");
    } catch (Exception $e) {
        // Ignore
    }

    // Create new table WITHOUT foreign key first
    $createTableSQL = "
    CREATE TABLE `conges_soldes` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `id_user` INT NOT NULL,
      `mois_annee` VARCHAR(7) NOT NULL,
      `jour_mois` INT DEFAULT 1,
      `conge_a_prendre` DECIMAL(5,2) DEFAULT 25.0,
      `conge_en_acquisition` DECIMAL(5,2) DEFAULT 0.0,
      `conge_pris_mois` DECIMAL(5,2) DEFAULT 0.0,
      `conge_pris_n` DECIMAL(5,2) DEFAULT 0.0,
      `solde_restant` DECIMAL(5,2) GENERATED ALWAYS AS (
        `conge_a_prendre` + `conge_en_acquisition` - `conge_pris_n`
      ) STORED,
      `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY `unique_user_month` (`id_user`, `mois_annee`),
      KEY `idx_user_id` (`id_user`),
      KEY `idx_mois_annee` (`mois_annee`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";

    $pdo->exec($createTableSQL);

    // Add foreign key constraint separately
    try {
        $pdo->exec("
            ALTER TABLE `conges_soldes`
            ADD CONSTRAINT `fk_conges_soldes_user`
            FOREIGN KEY (`id_user`) REFERENCES `users`(`id`) ON DELETE CASCADE
        ");
    } catch (PDOException $e) {
        // If FK fails, continue anyway (table is still created)
        $output[] = "⚠️  Warning: Could not add foreign key (non-blocking)";
    }

    $output[] = "✅ Schema created";

    // 2. Get all users with leaves
    $output[] = "\nStep 2: Processing users and leaves...";

    $users = $pdo->query("
        SELECT DISTINCT c.id_user
        FROM conges c
        WHERE c.statut != 'archivé'
        ORDER BY c.id_user
    ")->fetchAll(PDO::FETCH_COLUMN);

    $output[] = "Found " . count($users) . " users with leaves";

    // Cycle start: June 1, 2025
    $cycleStart = new DateTime('2025-06-01');
    $today = new DateTime();

    // 3. For each user, process all months from June 2025 to today
    $totalRecords = 0;
    $totalCreated = 0;

    foreach ($users as $userId) {
        $currentMonth = clone $cycleStart;

        while ($currentMonth <= $today) {
            $mois = $currentMonth->format('m');
            $annee = $currentMonth->format('Y');
            $mois_annee = $currentMonth->format('Y-m');

            // Calculate months since cycle start (June 2025)
            $monthsSinceCycleStart = (int)(($annee - 2025) * 12 + ($mois - 6));
            if ($monthsSinceCycleStart < 0) {
                $currentMonth->modify('+1 month');
                continue;
            }

            // Calculate conge_en_acquisition (2.08 × months)
            $conge_en_acquisition = round(2.083333 * $monthsSinceCycleStart, 2);

            // Get leaves for this user in this month
            $daysStmt = $pdo->prepare("
                SELECT SUM(
                    DATEDIFF(c.date_fin, c.date_debut) + 1 -
                    (CASE WHEN c.date_debut = GREATEST(c.date_debut, ?) AND c.demi_journee_debut = 'apres-midi' THEN 0.5 ELSE 0 END) -
                    (CASE WHEN c.date_fin = LEAST(c.date_fin, ?) AND c.demi_journee_fin = 'matin' THEN 0.5 ELSE 0 END)
                ) as total_days
                FROM conges c
                WHERE c.id_user = ?
                AND c.statut != 'archivé'
                AND YEAR(c.date_debut) = ? AND MONTH(c.date_debut) = ?
            ");

            $cycleMonthStart = $currentMonth->format('Y-m-01');
            $cycleMonthEnd = $currentMonth->format('Y-m-t');

            $daysStmt->execute([$cycleMonthStart, $cycleMonthEnd, $userId, $annee, $mois]);
            $result = $daysStmt->fetch(PDO::FETCH_ASSOC);
            $conge_pris_mois = max(0, (float)($result['total_days'] ?? 0));

            // Get cumulative days taken from June 2025 to current month
            $cumulStmt = $pdo->prepare("
                SELECT SUM(
                    DATEDIFF(c.date_fin, c.date_debut) + 1 -
                    (CASE WHEN c.demi_journee_debut = 'apres-midi' THEN 0.5 ELSE 0 END) -
                    (CASE WHEN c.demi_journee_fin = 'matin' THEN 0.5 ELSE 0 END)
                ) as total_days
                FROM conges c
                WHERE c.id_user = ?
                AND c.statut != 'archivé'
                AND c.date_debut >= '2025-06-01'
                AND c.date_debut <= ?
            ");

            $cumulStmt->execute([$userId, $currentMonth->format('Y-m-t')]);
            $cumulResult = $cumulStmt->fetch(PDO::FETCH_ASSOC);
            $conge_pris_n = max(0, (float)($cumulResult['total_days'] ?? 0));

            // Upsert into conges_soldes
            $upsertStmt = $pdo->prepare("
                INSERT INTO conges_soldes (id_user, mois_annee, jour_mois, conge_a_prendre, conge_en_acquisition, conge_pris_mois, conge_pris_n)
                VALUES (?, ?, 1, 25.0, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                  conge_en_acquisition = VALUES(conge_en_acquisition),
                  conge_pris_mois = VALUES(conge_pris_mois),
                  conge_pris_n = VALUES(conge_pris_n),
                  updated_at = CURRENT_TIMESTAMP
            ");

            $upsertStmt->execute([$userId, $mois_annee, $conge_en_acquisition, $conge_pris_mois, $conge_pris_n]);

            if ($upsertStmt->rowCount() > 0) {
                $totalCreated++;
            }
            $totalRecords++;

            $currentMonth->modify('+1 month');
        }
    }

    $output[] = "✅ Processed {$totalRecords} month-user combinations";
    $output[] = "✅ Created/Updated {$totalCreated} records";

    // 4. Verification
    $output[] = "\nStep 3: Verification...";

    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM conges_soldes");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    $output[] = "✅ Total records in conges_soldes: {$count}";

    $stmt = $pdo->query("
        SELECT mois_annee, COUNT(*) as users, AVG(conge_en_acquisition) as avg_acq, AVG(conge_pris_n) as avg_taken
        FROM conges_soldes
        GROUP BY mois_annee
        ORDER BY mois_annee DESC
        LIMIT 5
    ");

    $output[] = "\n📊 Last 5 months summary:";
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $output[] = sprintf(
            "  %s: %d users | Avg Acq: %.2f | Avg Taken: %.2f",
            $row['mois_annee'],
            $row['users'],
            $row['avg_acq'],
            $row['avg_taken']
        );
    }

    // Sample data for verification
    $output[] = "\n📋 Sample data (latest month per user):";
    $stmt = $pdo->query("
        SELECT
            u.id, u.prenom, u.nom, cs.mois_annee,
            cs.conge_a_prendre, cs.conge_en_acquisition, cs.conge_pris_mois, cs.conge_pris_n, cs.solde_restant
        FROM conges_soldes cs
        JOIN users u ON cs.id_user = u.id
        WHERE (cs.id_user, cs.mois_annee) IN (
            SELECT id_user, MAX(mois_annee)
            FROM conges_soldes
            GROUP BY id_user
        )
        ORDER BY u.nom, u.prenom
        LIMIT 10
    ");

    $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($samples as $sample) {
        $output[] = sprintf(
            "  %s %s (%s): À prendre=%.1f, En acq=%.1f, Pris mois=%.1f, Pris total=%.1f, Restant=%.1f",
            $sample['prenom'],
            $sample['nom'],
            $sample['mois_annee'],
            $sample['conge_a_prendre'],
            $sample['conge_en_acquisition'],
            $sample['conge_pris_mois'],
            $sample['conge_pris_n'],
            $sample['solde_restant']
        );
    }

    $output[] = "\n✅ Synchronization complete! Ready for production.";

} catch (Exception $e) {
    $output[] = "\n❌ Error: " . $e->getMessage();
    http_response_code(500);
}

header('Content-Type: text/plain; charset=utf-8');
echo implode("\n", $output);
?>
