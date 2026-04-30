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

// Export congés réservé à l'admin société (role 1 OU gestion_salaires=1).
$congesExportScope = function_exists('can_manage_salaires_agence') ? can_manage_salaires_agence() : 0;
if ($roleId !== 1 && $congesExportScope <= 0) {
    http_response_code(403);
    exit('Accès refusé : export réservé à l\'admin société.');
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    exit('Erreur: PDO non disponible');
}

// Include TCPDF
$tcpdfIncluded = false;
try {
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
} catch (Exception $e) {
    http_response_code(500);
    exit("Erreur TCPDF: " . htmlspecialchars($e->getMessage()));
}

// Cache TCPDF
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
$leaveId = (int)($_GET['id'] ?? 0);

if (!$leaveId) {
    http_response_code(400);
    exit('ID congé manquant');
}

// Get leave details
$stmt = $pdo->prepare("
    SELECT c.*, u.id as user_id, u.prenom, u.nom, u.email, u.telephone,
           s.nom as societe_nom, a.nom_agence
    FROM conges c
    JOIN users u ON c.id_user = u.id
    LEFT JOIN societes s ON u.id_societe = s.id
    LEFT JOIN agences a ON u.id_agence = a.id
    WHERE c.id = ?
");
$stmt->execute([$leaveId]);
$leave = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$leave) {
    http_response_code(404);
    exit('Congé non trouvé');
}

// Check authorization
if ($roleId !== 1 && $leave['user_id'] !== $userId) {
    http_response_code(403);
    exit('Accès refusé');
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function motifLabel($m) {
    $labels = [
        'conges_payes' => 'Congés payés',
        'rtt' => 'RTT',
        'maladie_justifiee_non_deduite' => 'Maladie (justifiée)',
        'maladie_non_justifiee_deduite' => 'Maladie (non justifiée)',
        'maladie_justifiee_deduite' => 'Maladie (justifiée déduite)',
        'absence_injustifiee_deduite' => 'Absence (injustifiée)',
        'absence_justifiee_non_deduite' => 'Absence (justifiée)',
        'absence_justifiee_deduite_heures' => 'Absence (justifiée en heures)',
        'autre_legal_non_deduit' => 'Autre (légal non déduit)',
        'autre_legal_deduit' => 'Autre (légal déduit)'
    ];
    return $labels[$m] ?? $m;
}

function demiJourneeLabel($d) {
    return $d === 'matin' ? 'Matin' : ($d === 'apres-midi' ? 'Après-midi' : 'Non');
}

function statutLabel($s) {
    $labels = ['en_attente' => 'En attente', 'validé' => 'Validé', 'refusé' => 'Refusé', 'archivé' => 'Archivé'];
    return $labels[$s] ?? $s;
}

// Create PDF
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_PAGE_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(TRUE, 15);
$pdf->AddPage();

// Header
$pdf->SetFont('dejavusans', 'B', 16);
$pdf->Cell(0, 10, 'Récapitulatif de Congé', 0, 1, 'C');
$pdf->SetFont('dejavusans', '', 10);
$pdf->Cell(0, 5, 'Généré le ' . date('d/m/Y à H:i'), 0, 1, 'C');
$pdf->Ln(5);

// Employee section
$pdf->SetFont('dejavusans', 'B', 11);
$pdf->Cell(0, 8, 'Informations Employé', 0, 1, 'L');
$pdf->SetFont('dejavusans', '', 10);
$pdf->Cell(50, 6, 'Nom:', 0, 0, 'L');
$pdf->Cell(0, 6, h($leave['prenom'] . ' ' . $leave['nom']), 0, 1, 'L');
$pdf->Cell(50, 6, 'Email:', 0, 0, 'L');
$pdf->Cell(0, 6, h($leave['email'] ?? 'N/A'), 0, 1, 'L');
$pdf->Cell(50, 6, 'Téléphone:', 0, 0, 'L');
$pdf->Cell(0, 6, h($leave['telephone'] ?? 'N/A'), 0, 1, 'L');
if ($leave['societe_nom']) {
    $pdf->Cell(50, 6, 'Société:', 0, 0, 'L');
    $pdf->Cell(0, 6, h($leave['societe_nom']), 0, 1, 'L');
}
if ($leave['nom_agence']) {
    $pdf->Cell(50, 6, 'Agence:', 0, 0, 'L');
    $pdf->Cell(0, 6, h($leave['nom_agence']), 0, 1, 'L');
}

$pdf->Ln(5);

// Leave details section
$pdf->SetFont('dejavusans', 'B', 11);
$pdf->Cell(0, 8, 'Détails du Congé', 0, 1, 'L');
$pdf->SetFont('dejavusans', '', 10);

$pdf->Cell(50, 6, 'Type:', 0, 0, 'L');
$pdf->Cell(0, 6, motifLabel($leave['motif']), 0, 1, 'L');

$pdf->Cell(50, 6, 'Date début:', 0, 0, 'L');
$pdf->Cell(0, 6, $leave['date_debut'], 0, 1, 'L');

$pdf->Cell(50, 6, 'Demi-journée début:', 0, 0, 'L');
$demiDebut = $leave['demi_journee_debut'] === 'non' ? 'Journée complète' : demiJourneeLabel($leave['demi_journee_debut']);
$pdf->Cell(0, 6, $demiDebut, 0, 1, 'L');

$pdf->Cell(50, 6, 'Date fin:', 0, 0, 'L');
$pdf->Cell(0, 6, $leave['date_fin'], 0, 1, 'L');

$pdf->Cell(50, 6, 'Demi-journée fin:', 0, 0, 'L');
$demiFin = $leave['demi_journee_fin'] === 'non' ? 'Journée complète' : demiJourneeLabel($leave['demi_journee_fin']);
$pdf->Cell(0, 6, $demiFin, 0, 1, 'L');

$pdf->Cell(50, 6, 'Statut:', 0, 0, 'L');
$pdf->Cell(0, 6, statutLabel($leave['statut']), 0, 1, 'L');

$pdf->Cell(50, 6, 'Date de demande:', 0, 0, 'L');
$pdf->Cell(0, 6, $leave['date_demande'], 0, 1, 'L');

$pdf->Ln(5);

// Comments section (visible to user)
if (!empty($leave['commentaire'])) {
    $pdf->SetFont('dejavusans', 'B', 11);
    $pdf->Cell(0, 8, 'Commentaire', 0, 1, 'L');
    $pdf->SetFont('dejavusans', '', 10);
    $pdf->MultiCell(0, 5, h($leave['commentaire']), 0, 'L');
    $pdf->Ln(5);
}

$pdf->Ln(5);
$pdf->SetFont('dejavusans', 'I', 9);
$pdf->Cell(0, 5, 'Nota: Le commentaire administrateur n\'est pas affiché dans ce document', 0, 1, 'L');

// Output
ob_end_clean();
$filename = 'conge_' . $leave['user_id'] . '_' . date('Y-m-d') . '.pdf';
$pdf->Output($filename, 'D'); // D = download
exit;
?>
