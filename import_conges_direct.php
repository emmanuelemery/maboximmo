<?php
/**
 * Direct Import of Congés Data from SQL File
 * Maps old field names to new conges table schema
 * Uses PDO with ON DUPLICATE KEY UPDATE
 */

declare(strict_types=1);

// Enable error reporting
ini_set('display_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Europe/Paris');

// Database configuration
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
$sqlFile = __DIR__ . '/conges_data.sql';
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

// Prepare insert statement with ON DUPLICATE KEY UPDATE
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
$updated = 0;
$errors = [];

// Process each row
foreach ($rows[1] as $index => $rowStr) {
    try {
        // Parse CSV values carefully
        $values = str_getcsv($rowStr, ',', "'");

        if (count($values) < 10) {
            $errors[] = "Row " . ($index + 1) . ": insufficient fields";
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

        // Map field values
        $motif = $motifMapping[$type_conge] ?? null;
        if ($motif === null) {
            $errors[] = "Row $id: Unknown type_conge value: '$type_conge'";
            continue;
        }

        $new_statut = $statutMapping[$statut] ?? null;
        if ($new_statut === null) {
            $errors[] = "Row $id: Unknown statut value: '$statut'";
            continue;
        }

        $id_validateur = !empty($valide_par_raw) && $valide_par_raw !== 'NULL' ? (int)$valide_par_raw : null;
        $date_validation_final = !empty($date_validation) && $date_validation !== 'NULL' ? $date_validation : null;

        // Validate dates
        if (!DateTime::createFromFormat('Y-m-d', $date_debut)) {
            $errors[] = "Row $id: Invalid date_debut: '$date_debut'";
            continue;
        }
        if (!DateTime::createFromFormat('Y-m-d', $date_fin)) {
            $errors[] = "Row $id: Invalid date_fin: '$date_fin'";
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
    }
}

// Summary
echo "\n=== IMPORT SUMMARY ===\n";
echo "Records processed: " . count($rows[1]) . "\n";
echo "Records imported: $imported\n";
echo "Errors encountered: " . count($errors) . "\n";

if (!empty($errors)) {
    echo "\n=== ERRORS ===\n";
    foreach ($errors as $error) {
        echo "  - $error\n";
    }
}

// Verify import
$result = $pdo->query("SELECT COUNT(*) as count FROM conges");
$totalCount = $result->fetch()['count'];
echo "\nTotal records in conges table: $totalCount\n";

echo "\n[✓] Import completed successfully!\n";
?>
