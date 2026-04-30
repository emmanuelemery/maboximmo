<?php
declare(strict_types=1);

if (!function_exists('rh_generate_salaires_conges_pdf')) {
    function rh_generate_salaires_conges_pdf(PDO $pdo, int $mois, int $annee, int $agenceScope = 0): string
    {
        if ($mois < 1 || $mois > 12) {
            throw new InvalidArgumentException('Mois invalide');
        }

        // TCPDF
        $tcpdfIncluded = false;
        foreach ([__DIR__ . '/../tcpdf/tcpdf.php', __DIR__ . '/../tcpdf_min/tcpdf.php'] as $p) {
            if (is_file($p)) { require_once $p; $tcpdfIncluded = true; break; }
        }
        if (!$tcpdfIncluded || !class_exists('TCPDF')) {
            throw new RuntimeException('TCPDF introuvable');
        }

        if (!defined('K_PATH_CACHE')) {
            $cacheDir = __DIR__ . '/../tcpdf_cache/';
            if (!is_dir($cacheDir)) @mkdir($cacheDir, 0777, true);
            define('K_PATH_CACHE', $cacheDir);
        }

        $mois_ref = sprintf('%04d-%02d-01', $annee, $mois);

        // Cycle year (Juin N-1 → Mai N)
        $cycleYear    = ($mois >= 6) ? $annee : $annee - 1;
        $cycleYearEnd = $cycleYear + 1;

        if (!function_exists('mois_fr')) {
            function mois_fr($m) {
                $n = [1=>'Janvier',2=>'Février',3=>'Mars',4=>'Avril',5=>'Mai',6=>'Juin',
                      7=>'Juillet',8=>'Août',9=>'Septembre',10=>'Octobre',11=>'Novembre',12=>'Décembre'];
                return $n[(int)$m] ?? '';
            }
        }
        if (!function_exists('euro')) {
            function euro($v) { return number_format((float)$v, 2, ',', ' ') . ' €'; }
        }
        if (!function_exists('motifAbrev')) {
            function motifAbrev($m) {
                $map = [
                    'conges_payes'                     => 'CP',
                    'rtt'                              => 'RTT',
                    'maladie_justifiee_non_deduite'    => 'Maladie',
                    'maladie_non_justifiee_deduite'    => 'Maladie',
                    'maladie_justifiee_deduite'        => 'Maladie',
                    'absence_injustifiee_deduite'      => 'Absence',
                    'absence_justifiee_non_deduite'    => 'Absence',
                    'absence_justifiee_deduite_heures' => 'Absence',
                    'autre_legal_non_deduit'           => 'Autre',
                    'autre_legal_deduit'               => 'Autre',
                ];
                return $map[$m] ?? $m;
            }
        }
        if (!function_exists('countDays')) {
            function countDays($d1, $d2) {
                return (new DateTime($d1))->diff(new DateTime($d2))->days + 1;
            }
        }

        // ── Colonnes salaire ──
        $allFields = [];
        $stmt = $pdo->query("SHOW COLUMNS FROM salaires");
        $excludeFields = ['id','id_user','mois_reference','termine_user','mois_cloture',
                          'commentaire_general','commentaire_admin','date_entree',
                          'numero_securite_sociale','ik_montant','salaire_modele','date_creation'];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $f = $col['Field'];
            if (!in_array($f, $excludeFields, true)) {
                $allFields[$f] = ['label' => ucwords(str_replace('_', ' ', $f)),
                                  'type'  => ($f === 'ik_nb_km') ? 'number' : 'money'];
            }
        }

        $fieldsList = !empty($allFields)
            ? ", " . implode(", ", array_map(fn($f) => "s.`$f`", array_keys($allFields)))
            : "";

        // ── Requête salaires ──
        $agenceFilter = $agenceScope > 0 ? "AND u.id_agence = " . (int)$agenceScope : "";
        $sql = "
            SELECT u.id as id_user, u.actif,
                   CONCAT(IFNULL(u.prenom,''), ' ', IFNULL(u.nom,'')) AS nom_complet,
                   soc.nom as societe_nom,
                   etab.nom_agence as agence_nom,
                   s.id as id_salaire, s.mois_reference, s.termine_user, s.mois_cloture,
                   s.commentaire_general, s.commentaire_admin
                   $fieldsList
            FROM users u
            LEFT JOIN societes soc  ON u.id_societe = soc.id
            LEFT JOIN agences  etab ON u.id_agence  = etab.id
            LEFT JOIN salaires s    ON (s.id_user = u.id OR s.id_user = u.id_legacy)
                                    AND s.mois_reference = :mr
            WHERE u.actif = 1 $agenceFilter
            ORDER BY soc.nom ASC, etab.nom_agence ASC, u.nom ASC, u.prenom ASC
        ";
        $stmtSal = $pdo->prepare($sql);
        $stmtSal->execute([':mr' => $mois_ref]);
        $salaires = $stmtSal->fetchAll(PDO::FETCH_ASSOC);

        if (empty($salaires)) {
            throw new RuntimeException('Aucun utilisateur trouvé pour cette période');
        }

        // ── Requête congés du mois ──
        $agenceFilterConge = $agenceScope > 0 ? "AND u.id_agence = " . (int)$agenceScope : "";
        $stmtConge = $pdo->prepare("
            SELECT c.*, u.id AS real_user_id
            FROM conges c
            JOIN users u ON c.id_user = u.id
            WHERE c.statut != 'archivé'
              AND (YEAR(c.date_debut) = ? AND MONTH(c.date_debut) = ?
                   OR YEAR(c.date_fin) = ? AND MONTH(c.date_fin) = ?)
              $agenceFilterConge
            ORDER BY c.date_debut ASC
        ");
        $stmtConge->execute([$annee, $mois, $annee, $mois]);
        $congesMap = [];
        foreach ($stmtConge->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $congesMap[(int)$row['real_user_id']][] = $row;
        }

        // ── Requête soldes congés ──
        $mois_annee_format = sprintf('%04d-%02d', $annee, $mois);
        $stmtBal = $pdo->prepare("
            SELECT id_user, conge_a_prendre, conge_en_acquisition, conge_pris_n, solde_restant
            FROM conges_soldes WHERE mois_annee = ?
        ");
        $stmtBal->execute([$mois_annee_format]);
        $userBalances = [];
        foreach ($stmtBal->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $userBalances[(int)$b['id_user']] = $b;
        }

        // ── Groupe par société > agence ──
        $grouped = [];
        foreach ($salaires as $row) {
            $soc = $row['societe_nom'] ?? 'Sans société';
            $ag  = $row['agence_nom']  ?? 'Sans agence';
            $grouped[$soc][$ag][] = $row;
        }

        class SalCongesPDF extends TCPDF {
            public function Footer() {
                $this->SetY(-15);
                $this->SetFont('dejavusans', '', 8);
                $this->SetTextColor(120, 120, 120);
                $this->SetDrawColor(210, 210, 210);
                $this->Line(12, $this->GetY(), 198, $this->GetY());
                $this->Ln(2);
                $this->Cell(0, 4, 'Page ' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'R');
            }
        }

        $pdf = new SalCongesPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('MaBoxImmo');
        $pdf->SetTitle('Salaires & Congés — ' . mois_fr($mois) . ' ' . $annee);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 24);
        $pdf->AddPage();

        // ── Titre ──
        $pdf->SetFont('dejavusans', 'B', 16);
        $pdf->SetTextColor(30, 30, 30);
        $pdf->Cell(0, 10, 'REGISTRE DES SALAIRES & CONGÉS', 0, 1, 'C');
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->Cell(0, 6, mois_fr($mois) . ' ' . $annee, 0, 1, 'C');
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->Cell(0, 5, 'Généré le ' . date('d/m/Y à H:i'), 0, 1, 'C');
        $pdf->Ln(6);

        foreach ($grouped as $societe_nom => $agences) {
            $pdf->SetFont('dejavusans', 'B', 13);
            $pdf->SetTextColor(35, 35, 35);
            $pdf->SetFillColor(238, 242, 248);
            $pdf->Cell(0, 8, $societe_nom, 0, 1, 'L', true);
            $pdf->Ln(2);

            foreach ($agences as $agence_nom => $users) {
                $pdf->SetFont('dejavusans', 'B', 11);
                $pdf->SetTextColor(55, 55, 55);
                $pdf->Cell(0, 7, '  › ' . $agence_nom, 0, 1, 'L');
                $pdf->Ln(1);

                foreach ($users as $user) {
                    $isInactive  = (int)$user['actif'] === 0;
                    $isValidated = (int)($user['termine_user'] ?? 0) === 1;
                    $uid         = (int)$user['id_user'];

                    $lockStatus = $isValidated ? '✓' : '✗';
                    if ($isInactive) {
                        $pdf->SetFillColor(230, 230, 230);
                        $pdf->SetTextColor(120, 120, 120);
                    } elseif ($isValidated) {
                        $pdf->SetFillColor(215, 240, 220);
                        $pdf->SetTextColor(25, 100, 50);
                    } else {
                        $pdf->SetFillColor(220, 232, 250);
                        $pdf->SetTextColor(30, 70, 150);
                    }
                    $pdf->SetFont('dejavusans', 'B', 11);
                    $pdf->Cell(0, 7, '  ' . $lockStatus . '  ' . $user['nom_complet'] . ($isInactive ? '  (parti)' : ''), 0, 1, 'L', true);
                    $pdf->SetTextColor(0, 0, 0);

                    $filledFields = [];
                    foreach ($allFields as $fieldName => $fieldMeta) {
                        $value = $user[$fieldName] ?? null;
                        if ($value !== null && $value !== '' && (float)$value !== 0.0) {
                            $filledFields[$fieldName] = $value;
                        }
                    }
                    if (!empty($filledFields)) {
                        $pdf->SetFont('dejavusans', $isInactive ? 'I' : '', 9);
                        $pdf->SetTextColor($isInactive ? 170 : 90, $isInactive ? 170 : 90, $isInactive ? 170 : 90);
                        foreach ($filledFields as $fieldName => $value) {
                            $label = ucwords(str_replace('_', ' ', $fieldName));
                            $formatted = $allFields[$fieldName]['type'] === 'number'
                                ? number_format((float)$value, 2, ',', ' ')
                                : euro($value);
                            // Largeur label élargie (30→70 mm) pour absorber les libellés longs
                            // type "Commission Ca Nouvelles Affaires" qui débordaient sur le montant.
                            // Montant aligné à droite à partir de X=125 → garde une zone de 35 mm pour les chiffres.
                            $pdf->SetX(50); $pdf->Cell(70, 5, '• ' . $label, 0, 0, 'L');
                            $pdf->SetX(125); $pdf->Cell(0, 5, $formatted, 0, 1, 'R');
                        }
                    } else {
                        $pdf->SetFont('dejavusans', $isInactive ? 'I' : '', 9);
                        $pdf->SetTextColor($isInactive ? 170 : 150, $isInactive ? 170 : 150, $isInactive ? 170 : 150);
                        $pdf->Cell(0, 5, '      (Aucun champ salaire renseigné)', 0, 1, 'L');
                    }

                    if (!empty($user['commentaire_general'])) {
                        $pdf->SetFont('dejavusans', 'I', 9);
                        $pdf->SetTextColor(120, 120, 120);
                        $pdf->MultiCell(0, 4, '      Note: ' . trim($user['commentaire_general']), 0, 'L');
                    }

                    $pdf->Ln(1);
                    $userConges = $congesMap[$uid] ?? [];
                    $pdf->SetFillColor(245, 245, 245);
                    $pdf->SetFont('dejavusans', 'B', 9);
                    $pdf->SetTextColor(40, 40, 40);
                    $pdf->Cell(0, 5, '      Congés — ' . mois_fr($mois) . ' ' . $annee, 0, 1, 'L', true);
                    $pdf->SetFillColor(255, 255, 255);

                    if (!empty($userConges)) {
                        $pdf->SetFont('dejavusans', '', 9);
                        $pdf->SetTextColor(0, 0, 0);
                        $totalDays = 0;
                        foreach ($userConges as $leave) {
                            $days       = countDays($leave['date_debut'], $leave['date_fin']);
                            $totalDays += $days;
                            $motif      = motifAbrev($leave['motif']);
                            $line       = '      ' . $leave['date_debut'] . '  →  ' . $leave['date_fin']
                                        . '  [' . $motif . ']  (' . $days . ' j)';
                            $pdf->Cell(0, 5, $line, 0, 1, 'L');
                        }
                        $pdf->SetFont('dejavusans', 'B', 9);
                        $pdf->SetTextColor(60, 60, 60);
                        $pdf->Cell(0, 5, '      Total mois : ' . $totalDays . ' jour' . ($totalDays > 1 ? 's' : ''), 0, 1, 'L');

                        if (isset($userBalances[$uid])) {
                            $bal          = $userBalances[$uid];
                            $basePrev     = (float)$bal['conge_a_prendre'];
                            $acquired     = (float)$bal['conge_en_acquisition'];
                            $totalAcq     = $basePrev + $acquired;
                            $pris         = (float)$bal['conge_pris_n'];
                            $restant      = (float)$bal['solde_restant'];

                            $pdf->SetFillColor(250, 250, 250);
                            $pdf->Ln(1);
                            $pdf->SetFont('dejavusans', 'B', 9);
                            $pdf->SetTextColor(80, 80, 80);
                            $pdf->Cell(0, 5, '      DÉCOMPTE ANNUEL (Juin ' . $cycleYear . ' - Mai ' . $cycleYearEnd . ')', 0, 1, 'L', true);

                            $pdf->SetFont('dejavusans', '', 8.5);
                            $pdf->SetTextColor(50, 50, 50);

                            $pdf->Cell(20, 4.5, '', 0, 0);
                            $pdf->Cell(55, 4.5, 'Année ' . $cycleYear . ' acquis', 0, 0, 'L');
                            $pdf->SetFont('dejavusans', 'B', 8.5);
                            $pdf->Cell(0, 4.5, number_format($basePrev, 2, ',', ' ') . ' j', 0, 1, 'R');

                            $pdf->SetFont('dejavusans', '', 8.5);
                            $pdf->Cell(20, 4.5, '', 0, 0);
                            $pdf->Cell(55, 4.5, 'Année ' . ($cycleYear + 1) . ' acquis (depuis 01/06)', 0, 0, 'L');
                            $pdf->SetFont('dejavusans', 'B', 8.5);
                            $pdf->Cell(0, 4.5, number_format($acquired, 2, ',', ' ') . ' j', 0, 1, 'R');

                            $pdf->SetDrawColor(200, 200, 200);
                            $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
                            $pdf->Ln(1);

                            $pdf->Cell(20, 4.5, '', 0, 0);
                            $pdf->SetFont('dejavusans', 'B', 9);
                            $pdf->SetTextColor(0, 0, 0);
                            $pdf->Cell(55, 4.5, 'Total acquis', 0, 0, 'L');
                            $pdf->Cell(0, 4.5, number_format($totalAcq, 2, ',', ' ') . ' j', 0, 1, 'R');
                            $pdf->Ln(1);

                            $pdf->Cell(20, 4.5, '', 0, 0);
                            $pdf->SetFont('dejavusans', '', 8.5);
                            $pdf->SetTextColor(80, 80, 80);
                            $pdf->Cell(55, 4.5, 'Jours pris', 0, 0, 'L');
                            $pdf->SetFont('dejavusans', 'B', 8.5);
                            $pdf->Cell(0, 4.5, number_format($pris, 2, ',', ' ') . ' j', 0, 1, 'R');

                            $pdf->SetTextColor($restant < 0 ? 200 : 0, $restant < 0 ? 0 : 120, 0);
                            $pdf->Cell(20, 4.5, '', 0, 0);
                            $pdf->SetFont('dejavusans', 'B', 9);
                            $pdf->Cell(55, 4.5, 'Solde restant', 0, 0, 'L');
                            $pdf->Cell(0, 4.5, number_format($restant, 2, ',', ' ') . ' j', 0, 1, 'R');
                            $pdf->SetTextColor(0, 0, 0);
                            $pdf->SetDrawColor(0, 0, 0);
                        }
                    } else {
                        $pdf->SetFont('dejavusans', 'I', 9);
                        $pdf->SetTextColor(150, 150, 150);
                        $pdf->Cell(0, 5, '      (Aucun congé ce mois)', 0, 1, 'L');
                    }

                    $pdf->Ln(3);
                }

                $pdf->Ln(2);
                $pdf->AddPage();
            }
            $pdf->Ln(4);
        }

        return $pdf->Output('', 'S');
    }
}

?>