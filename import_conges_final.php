<?php
/**
 * Final Import of Congés Data from SQL File
 * Maps old field names to new conges table schema
 * Handles missing users gracefully
 */

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Paris');

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'maboximmo');
define('DB_USER', 'root');
define('DB_PASS', '');

// Connect to database
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    echo "[✓] Database connected successfully\n";
} catch (PDOException $e) {
    die("[✗] Connection failed: " . $e->getMessage() . "\n");
}

// Get existing user IDs
$result = $pdo->query("SELECT id FROM users");
$existingUsers = array_column($result->fetchAll(), 'id');
$existingUsersSet = array_flip($existingUsers);

echo "[✓] Found " . count($existingUsers) . " users in database\n\n";

// Field mapping configuration
$motifMapping = [
    'CP'      => 'conges_payes',
    'RTT'     => 'rtt',
    'Maladie' => 'maladie_justifiee_deduite',
    'Absent'  => 'absence_justifiee_deduite_heures',
    'Autre'   => 'autre_legal_non_deduit'
];

$statutMapping = [
    'En attente' => 'en_attente',
    'Validé'     => 'validé',
    'Refusé'     => 'refusé'
];

// Read SQL file
$sqlFile = './conges_data.sql';
if (!file_exists($sqlFile)) {
    die("[✗] File not found: $sqlFile\n");
}

$sqlContent = file_get_contents($sqlFile);
echo "[✓] SQL file loaded: " . filesize($sqlFile) . " bytes\n";

// Parse INSERT statements
if (!preg_match('/INSERT INTO `conges`.*?VALUES\s*(.+?)(?=;|\n\nALTER)/s', $sqlContent, $matches)) {
    die("[✗] Could not parse INSERT statements from SQL file\n");
}

$valuesStr = $matches[1];

// Extract all value rows
if (!preg_match_all('/\(([^)]+)\)/s', $valuesStr, $rows)) {
    die("[✗] Could not extract value rows\n");
}

echo "[✓] Found " . count($rows[1]) . " records in SQL file\n\n";

// Prepare insert statement
$stmt = $pdo->prepare("
    INSERT INTO conges (
        id, id_user, date_demande, date_debut, date_fin,
        motif, statut, id_validateur, commentaire, date_validation,
        demi_journee_debut, demi_journee_fin
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        id_user = VALUES(id_user),
        date_demande = VALUES(date_demande),
        date_debut = VALUES(date_debut),
        date_fin = VALUES(date_fin),
        motif = VALUES(motif),
        statut = VALUES(statut),
        id_validateur = VALUES(id_validateur),
        commentaire = VALUES(commentaire),
        date_validation = VALUES(date_validation)
");

$imported = 0;
$skipped = 0;
$errors = [];
$skippedRecords = [];
$mappingResults = [
    'motif' => [],
    'statut' => [],
    'users' => []
];

// Process each row
foreach ($rows[1] as $index => $rowStr) {
    try {
        // Parse CSV values
        $values = str_getcsv($rowStr, ',', "'");

        if (count($values) < 10) {
            $errors[] = "Row " . ($index + 1) . ": insufficient fields";
            $skipped++;
            continue;
        }

        // Extract and clean values
        $id              = (int)trim($values[0]);
        $id_user         = (int)trim($values[1]);
        $date_debut      = trim($values[2], "'\"");
        $date_fin        = trim($values[3], "'\"");
        $type_conge      = trim($values[4], "'\"");
        $statut          = trim($values[5], "'\"");
        $date_soumission = trim($values[6], "'\"");
        $commentaire     = trim($values[7], "'\"");
        $valide_par_raw  = trim($values[8], "'\"");
        $date_validation = trim($values[9], "'\"");

        // Check if id_user exists
        if (!isset($existingUsersSet[$id_user])) {
            $skipped++;
            $skippedRecords[] = "ID $id (user $id_user not found)";
            continue;
        }

        // Map field values
        $motif = $motifMapping[$type_conge] ?? null;
        if ($motif === null) {
            $errors[] = "Row $id: Unknown type_conge value: '$type_conge'";
            $skipped++;
            continue;
        }

        if (!isset($mappingResults['motif'][$type_conge])) {
            $mappingResults['motif'][$type_conge] = 0;
        }
        $mappingResults['motif'][$type_conge]++;

        $new_statut = $statutMapping[$statut] ?? null;
        if ($new_statut === null) {
            $errors[] = "Row $id: Unknown statut value: '$statut'";
            $skipped++;
            continue;
        }

        if (!isset($mappingResults['statut'][$statut])) {
            $mappingResults['statut'][$statut] = 0;
        }
        $mappingResults['statut'][$statut]++;

        $id_validateur = !empty($valide_par_raw) && $valide_par_raw !== 'NULL' ? (int)$valide_par_raw : null;
        if ($id_validateur !== null && !isset($existingUsersSet[$id_validateur])) {
            // Validator doesn't exist - set to NULL (database allows this with ON DELETE SET NULL)
            $id_validateur = null;
        }

        // Track user mapping
        if (!isset($mappingResults['users'][$id_user])) {
            $mappingResults['users'][$id_user] = 0;
        }
        $mappingResults['users'][$id_user]++;

        $date_validation_final = !empty($date_validation) && $date_validation !== 'NULL' ? $date_validation : null;

        // Validate dates
        if (!DateTime::createFromFormat('Y-m-d', $date_debut)) {
            $errors[] = "Row $id: Invalid date_debut: '$date_debut'";
            $skipped++;
            continue;
        }
        if (!DateTime::createFromFormat('Y-m-d', $date_fin)) {
            $errors[] = "Row $id: Invalid date_fin: '$date_fin'";
            $skipped++;
            continue;
        }

        // Execute insert
        $stmt->execute([
            $id,
            $id_user,
            $date_soumission ?: date('Y-m-d H:i:s'),
            $date_debut,
            $date_fin,
            $motif,
            $new_statut,
            $id_validateur,
            $commentaire ?: null,
            $date_validation_final,
            'non',  // demi_journee_debut
            'non'   // demi_journee_fin
        ]);

        $imported++;

    } catch (Exception $e) {
        $errors[] = "Row " . ($index + 1) . ": " . $e->getMessage();
        $skipped++;
    }
}

// Summary
echo "\n=== IMPORT SUMMARY ===\n";
echo "Total records in SQL file: " . count($rows[1]) . "\n";
echo "Successfully imported: $imported\n";
echo "Skipped/Failed: $skipped\n";

if (!empty($skippedRecords)) {
    echo "\n=== SKIPPED RECORDS (User Not Found) ===\n";
    foreach ($skippedRecords as $record) {
        echo "  - $record\n";
    }
}

if (!empty($errors)) {
    echo "\n=== ERRORS ===\n";
    foreach (array_slice($errors, 0, 10) as $error) {
        echo "  - $error\n";
    }
    if (count($errors) > 10) {
        echo "  ... and " . (count($errors) - 10) . " more errors\n";
    }
}

echo "\n=== FIELD MAPPINGS ===\n";
echo "Type Conge → Motif:\n";
foreach ($mappingResults['motif'] as $old => $count) {
    $new = $motifMapping[$old] ?? 'UNKNOWN';
    echo "  '$old' → '$new': $count records\n";
}

echo "\nStatut Mapping:\n";
foreach ($mappingResults['statut'] as $old => $count) {
    $new = $statutMapping[$old] ?? 'UNKNOWN';
    echo "  '$old' → '$new': $count records\n";
}

echo "\nUsers affected: " . count($mappingResults['users']) . " unique users\n";

// Verify final count
$result = $pdo->query("SELECT COUNT(*) as count FROM conges");
$totalCount = $result->fetch()['count'];
echo "\nTotal records in conges table after import: $totalCount\n";

echo "\n[✓] Import completed successfully!\n";
?>
