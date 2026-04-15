<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
ob_start();
session_start();

try {
    require_once __DIR__ . '/inc/bootstrap.php';
    require_once __DIR__ . '/inc/auth.php';
} catch (Exception $e) {
    http_response_code(500);
    exit("Erreur include: " . htmlspecialchars($e->getMessage()));
}

require_login();

$roleId = current_role_id();
$userId = current_user_id();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    exit('Erreur: PDO non disponible');
}

// Include TCPDF
$tcpdfIncluded = false;
foreach ([__DIR__ . '/tcpdf/tcpdf.php', __DIR__ . '/tcpdf_min/tcpdf.php'] as $p) {
    if (is_file($p)) {
        require_once $p;
        $tcpdfIncluded = true;
        break;
    }
}
if (!$tcpdfIncluded) {
    http_response_code(500);
    exit("TCPDF introuvable");
}

if (!defined('K_PATH_CACHE')) {
    $cacheDir = __DIR__ . '/tcpdf_cache/';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    define('K_PATH_CACHE', $cacheDir);
}

if (!class_exists('TCPDF')) {
    http_response_code(500);
    exit("ERREUR: Classe TCPDF non disponible");
}

// Params
$userId_param = (int)($_GET['user_id'] ?? $userId);

// Check authorization
if ($roleId !== 1 && $userId_param !== $userId) {
    http_response_code(403);
    exit('Accès refusé');
}

// Get user info
$stmt = $pdo->prepare("SELECT id, prenom, nom FROM users WHERE id = ?");
$stmt->execute([$userId_param]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    http_response_code(404);
    exit('Utilisateur non trouvé');
}

// Get current year for cycle (June of current year to May of next year)
$currentMonth = (int)date('m');
$currentYear = (int)date('Y');
if ($currentMonth < 6) {
    $yearStart = $currentYear - 1;
    $yearEnd = $currentYear;
} else {
    $yearStart = $currentYear;
    $yearEnd = $currentYear + 1;
}

// Get leaves from June of yearStart to May of yearEnd
$sql = "SELECT c.*, u.id as user_id, u.prenom, u.nom
        FROM conges c
        JOIN users u ON c.id_user = u.id
        WHERE c.id_user = ?
        AND c.statut != 'archivé'
        AND (
            (YEAR(c.date_debut) = ? AND MONTH(c.date_debut) >= 6)
            OR (YEAR(c.date_fin) = ? AND MONTH(c.date_fin) <= 5)
            OR (YEAR(c.date_debut) = ? AND MONTH(c.date_debut) <= 5)
        )
        ORDER BY c.date_debut";

$stmt = $pdo->prepare($sql);
$stmt->execute([$userId_param, $yearStart, $yearEnd, $yearEnd]);
$leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function motifLabel($m) {
    $labels = [
        'conges_payes' => 'Congés payés',
        'rtt' => 'RTT',
        'maladie_justifiee_non_deduite' => 'Maladie',
        'maladie_non_justifiee_deduite' => 'Maladie',
        'maladie_justifiee_deduite' => 'Maladie',
        'absence_injustifiee_deduite' => 'Absence',
        'absence_justifiee_non_deduite' => 'Absence',
        'absence_justifiee_deduite_heures' => 'Absence',
        'autre_legal_non_deduit' => 'Autre',
        'autre_legal_deduit' => 'Autre'
    ];
    return $labels[$m] ?? $m;
}

function countDays($dateDebut, $dateFin) {
    $start = new DateTime($dateDebut);
    $end = new DateTime($dateFin);
    $interval = $start->diff($end);
    return $interval->days + 1;
}

function statutLabel($s) {
    $labels = ['en_attente' => 'En attente', 'validé' => 'Validé', 'refusé' => 'Refusé'];
    return $labels[$s] ?? $s;
}

// Create PDF
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetDefaultMonospacedFont('Courier');
$pdf->SetMargins(10, 10, 10);
$pdf->SetAutoPageBreak(TRUE, 10);
$pdf->AddPage();

// Header
$pdf->SetFont('dejavusans', 'B', 14);
$pdf->Cell(0, 10, 'Historique des Congés - Année ' . $yearStart . '-' . $yearEnd, 0, 1, 'C');
$pdf->SetFont('dejavusans', '', 10);
$pdf->Cell(0, 6, h($user['prenom'] . ' ' . $user['nom']), 0, 1, 'C');
$pdf->SetFont('dejavusans', 'I', 9);
$pdf->Cell(0, 5, '(Période réglementaire: Juin ' . $yearStart . ' - Mai ' . $yearEnd . ')', 0, 1, 'C');
$pdf->SetFont('dejavusans', '', 9);
$pdf->Cell(0, 5, 'Généré le ' . date('d/m/Y à H:i'), 0, 1, 'C');
$pdf->Ln(3);

// Table header
$pdf->SetFont('dejavusans', 'B', 9);
$pdf->SetFillColor(220, 220, 220);
$pdf->Cell(35, 6, 'Date début', 1, 0, 'C', true);
$pdf->Cell(35, 6, 'Date fin', 1, 0, 'C', true);
$pdf->Cell(15, 6, 'Jours', 1, 0, 'C', true);
$pdf->Cell(45, 6, 'Type', 1, 0, 'L', true);
$pdf->Cell(30, 6, 'Statut', 1, 1, 'L', true);

// Table rows
$pdf->SetFont('dejavusans', '', 9);
$pdf->SetFillColor(245, 245, 245);
$totalDays = 0;
$fill = false;

foreach ($leaves as $leave) {
    $days = countDays($leave['date_debut'], $leave['date_fin']);
    $totalDays += $days;
    $motif = motifLabel($leave['motif']);
    $statut = statutLabel($leave['statut']);

    $pdf->Cell(35, 5, $leave['date_debut'], 1, 0, 'C', $fill);
    $pdf->Cell(35, 5, $leave['date_fin'], 1, 0, 'C', $fill);
    $pdf->Cell(15, 5, $days, 1, 0, 'C', $fill);
    $pdf->Cell(45, 5, $motif, 1, 0, 'L', $fill);
    $pdf->Cell(30, 5, $statut, 1, 1, 'L', $fill);

    $fill = !$fill;
}

// Total
$pdf->SetFont('dejavusans', 'B', 10);
$pdf->SetFillColor(200, 200, 200);
$pdf->Cell(120, 6, 'TOTAL', 1, 0, 'R', true);
$pdf->Cell(15, 6, $totalDays, 1, 0, 'C', true);
$pdf->Cell(75, 6, '', 1, 1, 'L', true);

// Output
ob_end_clean();
$filename = 'conges_' . $user['nom'] . '_' . $yearStart . '-' . $yearEnd . '.pdf';
$pdf->Output($filename, 'D');
exit;
?>
