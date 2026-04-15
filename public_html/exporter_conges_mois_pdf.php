<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ob_start();
session_start();

try {
    require_once __DIR__ . '/inc/bootstrap.php';
    require_once __DIR__ . '/inc/auth.php';

    require_login();

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) {
        http_response_code(500);
        exit('Erreur: PDO non disponible');
    }

    // Params
    $mois = (int)($_GET['mois'] ?? date('n'));
    $annee = (int)($_GET['annee'] ?? date('Y'));

    // Calculate cycle year (June N-1 to May N)
    $cycleYear = ($mois >= 6) ? $annee : $annee - 1;
    $cycleYearEnd = $cycleYear + 1;

    // Get all leaves for the month
    $sql = "SELECT c.*, u.id as user_id, u.prenom, u.nom,
                   s.nom as societe_nom, a.nom_agence
            FROM conges c
            JOIN users u ON c.id_user = u.id
            LEFT JOIN societes s ON u.id_societe = s.id
            LEFT JOIN agences a ON u.id_agence = a.id
            WHERE c.statut != 'archivé' AND (
                (YEAR(c.date_debut) = ? AND MONTH(c.date_debut) = ?)
                OR (YEAR(c.date_fin) = ? AND MONTH(c.date_fin) = ?)
            )
            ORDER BY s.nom, a.nom_agence, u.nom, u.prenom, c.date_debut";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$annee, $mois, $annee, $mois]);
    $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get leave balances for the month
    $mois_annee_format = sprintf('%04d-%02d', $annee, $mois);
    $sqlBalances = "SELECT id_user,
                           conge_a_prendre,
                           conge_en_acquisition,
                           conge_pris_n,
                           solde_restant
                    FROM conges_soldes
                    WHERE mois_annee = ?";
    $stmtBalances = $pdo->prepare($sqlBalances);
    $stmtBalances->execute([$mois_annee_format]);
    $allBalances = $stmtBalances->fetchAll(PDO::FETCH_ASSOC);

    // Map user_id => balance data
    $userBalances = [];
    foreach ($allBalances as $balance) {
        $userBalances[$balance['id_user']] = $balance;
    }

    // Organize leaves by societe -> agence -> user
    $organized = [];
    foreach ($leaves as $leave) {
        $societe = $leave['societe_nom'] ?? 'Sans société';
        $agence = $leave['nom_agence'] ?? 'Sans agence';
        $user = $leave['nom'] . ' ' . $leave['prenom'];

        if (!isset($organized[$societe])) {
            $organized[$societe] = [];
        }
        if (!isset($organized[$societe][$agence])) {
            $organized[$societe][$agence] = [];
        }
        if (!isset($organized[$societe][$agence][$user])) {
            $organized[$societe][$agence][$user] = [];
        }
        $organized[$societe][$agence][$user][] = $leave;
    }

    // Helper functions
    function mois_fr($m) {
        $n = [1=>'Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
        return $n[(int)$m] ?? '';
    }
    function motifAbrev($m) {
        $map = [
            'conges_payes' => 'CP',
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
        return $map[$m] ?? $m;
    }
    function countDays($dateDebut, $dateFin) {
        $start = new DateTime($dateDebut);
        $end = new DateTime($dateFin);
        $interval = $start->diff($end);
        return $interval->days + 1;
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

    if (!class_exists('TCPDF')) {
        http_response_code(500);
        exit("ERREUR: Classe TCPDF non disponible");
    }

    if (!defined('K_PATH_CACHE')) {
        $cacheDir = __DIR__ . '/tcpdf_cache/';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }
        define('K_PATH_CACHE', $cacheDir);
    }

    // Create PDF
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetDefaultMonospacedFont('Courier');
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(TRUE, 10);
    $pdf->AddPage();

    // Header
    $pdf->SetFont('dejavusans', 'B', 14);
    $pdf->Cell(0, 10, 'Récapitulatif des congés - Mois de ' . mois_fr($mois) . ' ' . $annee, 0, 1, 'C');
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->Cell(0, 5, 'Généré le ' . date('d/m/Y à H:i'), 0, 1, 'C');
    $pdf->Ln(3);

    // Iterate through organized data
    foreach ($organized as $societe => $agences) {
        foreach ($agences as $agence => $users) {
            foreach ($users as $userName => $userLeaves) {
                // Titre avec fond gris clair
                $pdf->SetFillColor(245, 245, 245);
                $pdf->SetFont('dejavusans', 'B', 11);
                $pdf->SetTextColor(40, 40, 40);
                $societe_display = h($societe);
                $agence_display = h($agence);
                $user_display = h($userName);
                $label = $societe_display . ' / ' . $agence_display . '  •  ' . $user_display;
                $pdf->Cell(0, 7, $label, 0, 1, 'L', true);
                $pdf->SetFillColor(255, 255, 255);

                $pdf->SetFont('dejavusans', '', 9);
                $pdf->SetTextColor(0, 0, 0);

                $totalDays = 0;
                foreach ($userLeaves as $leave) {
                    $days = countDays($leave['date_debut'], $leave['date_fin']);
                    $totalDays += $days;
                    $motif = motifAbrev($leave['motif']);
                    $line = '  ' . $leave['date_debut'] . '  →  ' . $leave['date_fin'] . '  [' . $motif . ']  (' . $days . ' j)';
                    $pdf->Cell(0, 5, $line, 0, 1, 'L');
                }

                $pdf->SetFont('dejavusans', 'B', 10);
                $pdf->SetTextColor(60, 60, 60);
                $pdf->Cell(0, 1, '', 0, 1);  // Petite ligne vide
                $pdf->Cell(0, 5, 'Total mois : ' . $totalDays . ' jour' . ($totalDays > 1 ? 's' : ''), 0, 1, 'L');

                // Get user balance info
                if (isset($userLeaves[0]) && isset($userLeaves[0]['user_id'])) {
                    $userId = (int)$userLeaves[0]['user_id'];
                    if (isset($userBalances[$userId])) {
                        $balance = $userBalances[$userId];

                        $basePrevYear = (float)$balance['conge_a_prendre'];
                        $acquiredCurrentYear = (float)$balance['conge_en_acquisition'];
                        $totalAcquired = $basePrevYear + $acquiredCurrentYear;
                        $priseN = (float)$balance['conge_pris_n'];
                        $restant = (float)$balance['solde_restant'];

                        // Boîte décompte avec fond léger
                        $pdf->SetFillColor(250, 250, 250);
                        $pdf->Cell(0, 1, '', 0, 1);
                        $pdf->SetFont('dejavusans', 'B', 9);
                        $pdf->SetTextColor(80, 80, 80);
                        $pdf->Cell(0, 5, 'DÉCOMPTE ANNUEL (Juin ' . $cycleYear . ' - Mai ' . $cycleYearEnd . ')', 0, 1, 'L', true);

                        $pdf->SetFont('dejavusans', '', 8.5);
                        $pdf->SetTextColor(50, 50, 50);

                        // Détail des jours acquis
                        $pdf->Cell(20, 4.5, '', 0, 0);  // Indentation
                        $pdf->SetFont('dejavusans', '', 8.5);
                        $pdf->Cell(55, 4.5, 'Année ' . $cycleYear . ' acquis', 0, 0, 'L');
                        $pdf->SetFont('dejavusans', 'B', 8.5);
                        $pdf->Cell(0, 4.5, number_format($basePrevYear, 2, ',', ' ') . ' j', 0, 1, 'R');

                        $pdf->Cell(20, 4.5, '', 0, 0);  // Indentation
                        $pdf->SetFont('dejavusans', '', 8.5);
                        $pdf->Cell(55, 4.5, 'Année ' . ($cycleYear + 1) . ' acquis (depuis 01/06)', 0, 0, 'L');
                        $pdf->SetFont('dejavusans', 'B', 8.5);
                        $pdf->Cell(0, 4.5, number_format($acquiredCurrentYear, 2, ',', ' ') . ' j', 0, 1, 'R');

                        // Ligne de séparation
                        $pdf->SetDrawColor(200, 200, 200);
                        $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
                        $pdf->Ln(1);

                        // Total acquis
                        $pdf->Cell(20, 4.5, '', 0, 0);
                        $pdf->SetFont('dejavusans', 'B', 9);
                        $pdf->SetTextColor(0, 0, 0);
                        $pdf->Cell(55, 4.5, 'Total acquis', 0, 0, 'L');
                        $pdf->SetFont('dejavusans', 'B', 9);
                        $pdf->Cell(0, 4.5, number_format($totalAcquired, 2, ',', ' ') . ' j', 0, 1, 'R');

                        $pdf->Ln(1);

                        // Jours pris
                        $pdf->Cell(20, 4.5, '', 0, 0);
                        $pdf->SetFont('dejavusans', '', 8.5);
                        $pdf->SetTextColor(80, 80, 80);
                        $pdf->Cell(55, 4.5, 'Jours pris', 0, 0, 'L');
                        $pdf->SetFont('dejavusans', 'B', 8.5);
                        $pdf->Cell(0, 4.5, number_format($priseN, 2, ',', ' ') . ' j', 0, 1, 'R');

                        // Solde restant (en couleur selon positif/négatif)
                        if ($restant < 0) {
                            $pdf->SetTextColor(200, 0, 0);  // Rouge si négatif
                        } else {
                            $pdf->SetTextColor(0, 120, 0);  // Vert si positif
                        }
                        $pdf->Cell(20, 4.5, '', 0, 0);
                        $pdf->SetFont('dejavusans', 'B', 9);
                        $pdf->Cell(55, 4.5, 'Solde restant', 0, 0, 'L');
                        $pdf->Cell(0, 4.5, number_format($restant, 2, ',', ' ') . ' j', 0, 1, 'R');

                        $pdf->SetTextColor(0, 0, 0);
                        $pdf->SetDrawColor(0, 0, 0);
                    }
                }

                $pdf->Ln(2);
            }
        }
    }

    $pdf->Ln(5);
    $pdf->SetFont('dejavusans', 'I', 8);
    $pdf->Cell(0, 5, 'Powered by TCPDF (www.tcpdf.org)', 0, 1, 'R');

    // Output
    ob_end_clean();
    $filename = 'conges_' . sprintf('%04d-%02d', $annee, $mois) . '.pdf';
    $pdf->Output($filename, 'D');
    exit;

} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "❌ ERREUR FATAL:\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "\nStack:\n" . $e->getTraceAsString();
    exit;
}
?>
