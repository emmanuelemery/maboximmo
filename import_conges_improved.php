<?php
/**
 * Improved Import of Congés Data from SQL File
 * Properly parses SQL VALUES with NULL values and quoted strings
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
$existingUsers = array_flip(array_column($result->fetchAll(), 'id'));

echo "[✓] Found " . count($existingUsers) . " users in database\n\n";

// Field mapping
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

$lines = file($sqlFile, FILE_SKIP_EMPTY_LINES);
echo "[✓] SQL file loaded: " . count($lines) . " lines\n";

// Parse INSERT statements by line
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
$processedIds = [];

foreach ($lines as $lineNum => $line) {
    $line = trim($line);

    // Skip non-data lines
    if (empty($line) || strpos($line, 'INSERT') !== false || strpos($line, '(') === false) {
        continue;
    }

    // Remove trailing comma if present
    if (substr($line, -1) === ',') {
        $line = substr($line, 0, -1);
    }

    // Parse the VALUES line: (id, id_user, date_debut, date_fin, type_conge, statut, date_soumission, commentaire, validé_par, date_validation)
    if (!preg_match('/^\(([^)]+)\)/', $line, $m)) {
        continue;
    }

    $valueStr = $m[1];

    try {
        // Manually parse SQL values to handle NULL properly
        $parts = [];
        $current = '';
        $inQuotes = false;
        $quoteChar = null;

        for ($i = 0; $i < strlen($valueStr); $i++) {
            $char = $valueStr[$i];
            $next = $i + 1 < strlen($valueStr) ? $valueStr[$i + 1] : '';

            if (($char === "'" || $char === '"') && !$inQuotes) {
                $inQuotes = true;
                $quoteChar = $char;
            } elseif ($char === $quoteChar && $inQuotes) {
                $inQuotes = false;
                $quoteChar = null;
            } elseif ($char === ',' && !$inQuotes) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if (!empty($current)) {
            $parts[] = trim($current);
        }

        if (count($parts) < 10) {
            $errors[] = "Line " . ($lineNum + 1) . ": insufficient fields (" . count($parts) . ")";
            continue;
        }

        // Extract and clean values
        $id              = (int)$parts[0];
        $id_user         = (int)$parts[1];
        $date_debut      = trim($parts[2], "'\"");
        $date_fin        = trim($parts[3], "'\"");
        $type_conge      = trim($parts[4], "'\"");
        $statut          = trim($parts[5], "'\"");
        $date_soumission = trim($parts[6], "'\"");
        $commentaire     = trim($parts[7], "'\"");
        $valide_par_raw  = trim($parts[8], " '\"");
        $date_validation = trim($parts[9], "'\"");

        // Record processed
        $processedIds[$id] = true;

        // Check if user exists
        if (!isset($existingUsers[$id_user])) {
            $skipped++;
            continue;
        }

        // Map motif
        $motif = $motifMapping[$type_conge] ?? null;
        if ($motif === null) {
            $errors[] = "Record $id: Unknown type_conge: $type_conge";
            $skipped++;
            continue;
        }

        // Map statut
        $new_statut = $statutMapping[$statut] ?? null;
        if ($new_statut === null) {
            $errors[] = "Record $id: Unknown statut: $statut";
            $skipped++;
            continue;
        }

        // Handle validator
        $id_validateur = null;
        if (!empty($valide_par_raw) && strtoupper($valide_par_raw) !== 'NULL') {
            $id_validateur = (int)$valide_par_raw;
            // If validator doesn't exist, set to NULL
            if (!isset($existingUsers[$id_validateur])) {
                $id_validateur = null;
            }
        }

        // Validate dates
        if (!DateTime::createFromFormat('Y-m-d', $date_debut)) {
            $errors[] = "Record $id: Invalid date_debut: $date_debut";
            $skipped++;
            continue;
        }
        if (!DateTime::createFromFormat('Y-m-d', $date_fin)) {
            $errors[] = "Record $id: Invalid date_fin: $date_fin";
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
            !empty($commentaire) ? $commentaire : null,
            !empty($date_validation) && $date_validation !== 'NULL' ? $date_validation : null,
            'non',  // demi_journee_debut
            'non'   // demi_journee_fin
        ]);

        $imported++;

    } catch (Exception $e) {
        $errors[] = "Line " . ($lineNum + 1) . " (ID " . ($id ?? '?') . "): " . $e->getMessage();
        $skipped++;
    }
}

// Summary
echo "\n=== IMPORT SUMMARY ===\n";
echo "Records processed: " . count($processedIds) . "\n";
echo "Successfully imported: $imported\n";
echo "Skipped/Failed: $skipped\n";

if (!empty($errors)) {
    echo "\n=== FIRST 15 ERRORS ===\n";
    foreach (array_slice($errors, 0, 15) as $error) {
        echo "  - $error\n";
    }
    if (count($errors) > 15) {
        echo "  ... and " . (count($errors) - 15) . " more errors\n";
    }
}

// Verify final count
$result = $pdo->query("SELECT COUNT(*) as count FROM conges");
$totalCount = $result->fetch()['count'];
echo "\nFinal count in conges table: $totalCount\n";

echo "\n[✓] Import completed!\n";
?>
