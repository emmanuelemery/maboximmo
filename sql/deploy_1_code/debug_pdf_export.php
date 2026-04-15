<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';

require_login();
require_super_admin();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    die('PDO not available');
}

$mois = (int)($_GET['mois'] ?? date('n'));
$annee = (int)($_GET['annee'] ?? date('Y'));

$output = [];
$output[] = "=== PDF Export Debug ===\n";
$output[] = "Mois: $mois, Année: $annee";

// Test 1: Check leaves
$output[] = "\n[1] Checking leaves...";
try {
    $sql = "SELECT COUNT(*) as cnt FROM conges c
            WHERE c.statut != 'archivé' AND (
                (YEAR(c.date_debut) = ? AND MONTH(c.date_debut) = ?)
                OR (YEAR(c.date_fin) = ? AND MONTH(c.date_fin) = ?)
            )";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$annee, $mois, $annee, $mois]);
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    $output[] = "✅ Found $count leaves for $annee-$mois";
} catch (Exception $e) {
    $output[] = "❌ Error: " . $e->getMessage();
}

// Test 2: Check conges_soldes table
$output[] = "\n[2] Checking conges_soldes...";
try {
    $stmt = $pdo->query("DESCRIBE conges_soldes");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $output[] = "✅ Table exists with columns:";
    foreach ($columns as $col) {
        $output[] = "   - " . $col['Field'];
    }
} catch (Exception $e) {
    $output[] = "❌ Error: " . $e->getMessage();
}

// Test 3: Check balance data for the month
$output[] = "\n[3] Checking balance data...";
try {
    $mois_annee_format = sprintf('%04d-%02d', $annee, $mois);
    $sql = "SELECT COUNT(*) as cnt FROM conges_soldes WHERE mois_annee = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$mois_annee_format]);
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    $output[] = "✅ Found $count balance records for $mois_annee_format";

    if ($count > 0) {
        $sql = "SELECT id_user, conge_a_prendre, conge_en_acquisition, conge_pris_n, solde_restant
                FROM conges_soldes WHERE mois_annee = ? LIMIT 3";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$mois_annee_format]);
        $samples = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $output[] = "Sample data:";
        foreach ($samples as $sample) {
            $output[] = "  User " . $sample['id_user'] . ": à_prendre=" . $sample['conge_a_prendre'] .
                        ", en_acq=" . $sample['conge_en_acquisition'] .
                        ", pris=" . $sample['conge_pris_n'] .
                        ", restant=" . $sample['solde_restant'];
        }
    }
} catch (Exception $e) {
    $output[] = "❌ Error: " . $e->getMessage();
}

// Test 4: Try to include TCPDF
$output[] = "\n[4] Checking TCPDF...";
try {
    $tcpdfIncluded = false;
    foreach ([__DIR__ . '/tcpdf/tcpdf.php', __DIR__ . '/tcpdf_min/tcpdf.php'] as $p) {
        if (is_file($p)) {
            $output[] = "✅ TCPDF found at: $p";
            require_once $p;
            $tcpdfIncluded = true;
            break;
        }
    }
    if (!$tcpdfIncluded) {
        $output[] = "❌ TCPDF not found";
    } else {
        if (class_exists('TCPDF')) {
            $output[] = "✅ TCPDF class loaded";
        } else {
            $output[] = "❌ TCPDF class not found";
        }
    }
} catch (Exception $e) {
    $output[] = "❌ Error: " . $e->getMessage();
}

// Test 5: Test cycle calculations
$output[] = "\n[5] Cycle calculations...";
$cycleYear = ($mois >= 6) ? $annee : $annee - 1;
$cycleYearEnd = $cycleYear + 1;
$output[] = "Cycle: Juin $cycleYear - Mai $cycleYearEnd";

header('Content-Type: text/plain; charset=utf-8');
echo implode("\n", $output);
?>
