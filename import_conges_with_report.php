<?php
/**
 * Final Import of Congés Data with Detailed Report
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
$result = $pdo->query("SELECT id, email FROM users ORDER BY id");
$users = [];
foreach ($result->fetchAll() as $row) {
    $users[$row['id']] = $row['email'];
}
$existingUsers = array_flip(array_keys($users));

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
echo "[✓] SQL file loaded\n";

// Parse and collect statistics
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
$skipped = [];
$errors = [];
$stats = [
    'motif' => [],
    'statut' => [],
    'users' => [],
];

foreach ($lines as $lineNum => $line) {
    $line = trim($line);

    if (empty($line) || strpos($line, 'INSERT') !== false || strpos($line, '(') === false) {
        continue;
    }

    if (substr($line, -1) === ',') {
        $line = substr($line, 0, -1);
    }

    if (!preg_match('/^\(([^)]+)\)/', $line, $m)) {
        continue;
    }

    $valueStr = $m[1];

    try {
        // Parse SQL values
        $parts = [];
        $current = '';
        $inQuotes = false;
        $quoteChar = null;

        for ($i = 0; $i < strlen($valueStr); $i++) {
            $char = $valueStr[$i];

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
            $errors[] = "Record on line " . ($lineNum + 1) . ": insufficient fields";
            continue;
        }

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

        // Check user exists
        if (!isset($existingUsers[$id_user])) {
            $skipped[] = [
                'id' => $id,
                'reason' => "User ID $id_user not found in database"
            ];
            continue;
        }

        // Map values
        $motif = $motifMapping[$type_conge] ?? null;
        if ($motif === null) {
            $errors[] = "Record $id: Unknown type_conge: $type_conge";
            continue;
        }

        $new_statut = $statutMapping[$statut] ?? null;
        if ($new_statut === null) {
            $errors[] = "Record $id: Unknown statut: $statut";
            continue;
        }

        // Track statistics
        if (!isset($stats['motif'][$motif])) {
            $stats['motif'][$motif] = 0;
        }
        $stats['motif'][$motif]++;

        if (!isset($stats['statut'][$new_statut])) {
            $stats['statut'][$new_statut] = 0;
        }
        $stats['statut'][$new_statut]++;

        if (!isset($stats['users'][$id_user])) {
            $stats['users'][$id_user] = 0;
        }
        $stats['users'][$id_user]++;

        // Handle validator
        $id_validateur = null;
        if (!empty($valide_par_raw) && strtoupper($valide_par_raw) !== 'NULL') {
            $id_validateur = (int)$valide_par_raw;
            if (!isset($existingUsers[$id_validateur])) {
                $id_validateur = null;
            }
        }

        // Validate dates
        if (!DateTime::createFromFormat('Y-m-d', $date_debut) ||
            !DateTime::createFromFormat('Y-m-d', $date_fin)) {
            $errors[] = "Record $id: Invalid dates";
            continue;
        }

        // Insert
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
            'non',
            'non'
        ]);

        $imported++;

    } catch (Exception $e) {
        $errors[] = "Record on line " . ($lineNum + 1) . ": " . $e->getMessage();
    }
}

// Generate report
echo "\n╔════════════════════════════════════════╗\n";
echo "║          IMPORT REPORT SUMMARY          ║\n";
echo "╚════════════════════════════════════════╝\n\n";

echo "STATISTICS:\n";
echo "  Total records in SQL file: " . (count($skipped) + count($errors) + $imported) . "\n";
echo "  Successfully imported: $imported ✓\n";
echo "  Records skipped: " . count($skipped) . "\n";
echo "  Errors encountered: " . count($errors) . "\n";

// Report skipped records
if (!empty($skipped)) {
    echo "\nSKIPPED RECORDS:\n";
    foreach ($skipped as $record) {
        echo "  - Record ID " . $record['id'] . ": " . $record['reason'] . "\n";
    }
}

// Report errors
if (!empty($errors)) {
    echo "\nERRORS:\n";
    foreach (array_slice($errors, 0, 10) as $error) {
        echo "  - $error\n";
    }
    if (count($errors) > 10) {
        echo "  ... and " . (count($errors) - 10) . " more\n";
    }
}

// Field mapping breakdown
echo "\nFIELD MAPPINGS:\n";
echo "\n  Type Conge → Motif:\n";
foreach ($motifMapping as $old => $new) {
    $count = isset($stats['motif'][$new]) ? $stats['motif'][$new] : 0;
    printf("    %-35s %3d records\n", "'$old' → '$new':", $count);
}

echo "\n  Statut Mapping:\n";
foreach ($statutMapping as $old => $new) {
    $count = isset($stats['statut'][$new]) ? $stats['statut'][$new] : 0;
    printf("    %-35s %3d records\n", "'$old' → '$new':", $count);
}

echo "\n  Users affected: " . count($stats['users']) . " unique users\n";
echo "    IDs: " . implode(', ', array_keys($stats['users'])) . "\n";

// Final verification
$result = $pdo->query("SELECT COUNT(*) as count FROM conges");
$totalCount = $result->fetch()['count'];
echo "\nDATABASE VERIFICATION:\n";
echo "  Total records in conges table: $totalCount\n";

echo "\n[✓] Import process completed successfully!\n";
?>
