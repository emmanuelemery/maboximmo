<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();

$pdo    = $GLOBALS['pdo'] ?? null;
$userId = current_user_id();

if (!$pdo) { http_response_code(500); exit('Erreur DB'); }

$sessionId = (int)($_GET['session_id'] ?? 0);
if (!$sessionId) { http_response_code(400); exit('session_id manquant'); }

// ── Charger la session ─────────────────────────────────────────────────────────
$stmtSess = $pdo->prepare("
    SELECT s.*, u.nom, u.prenom, u.vehicule_nom, u.vehicule_puissance_fiscale,
           a.nom_agence, a.adresse_1 as agence_adresse, a.ville as agence_ville, a.code_postal as agence_cp
    FROM rh_ik_sessions s
    JOIN users u ON u.id = s.id_user
    LEFT JOIN agences a ON a.id = u.id_agence
    WHERE s.id = ? AND s.id_user = ?
");
$stmtSess->execute([$sessionId, $userId]);
$sess = $stmtSess->fetch(PDO::FETCH_ASSOC);

if (!$sess) { http_response_code(403); exit('Accès refusé ou session introuvable'); }

// ── Charger les lignes ─────────────────────────────────────────────────────────
$stmtLignes = $pdo->prepare("
    SELECT l.*, a.nom_agence as depart_agence_nom
    FROM rh_ik_lignes l
    LEFT JOIN agences a ON a.id = l.id_agence_depart
    WHERE l.id_session = ?
    ORDER BY COALESCE(l.date_deplacement,'9999-12-31'), l.ordre, l.id
");
$stmtLignes->execute([$sessionId]);
$lignes = $stmtLignes->fetchAll(PDO::FETCH_ASSOC);

// Filtrer les lignes non vides
$lignes = array_filter($lignes, fn($l) => $l['destination_label'] || $l['km_aller'] || $l['km_retour']);

$totalKm    = array_sum(array_map(fn($l) => (float)$l['km_aller'] + (float)$l['km_retour'], $lignes));
$nbLignes   = count($lignes);

// ── Noms de mois ───────────────────────────────────────────────────────────────
$moisFr = ['','Janvier','Février','Mars','Avril','Mai','Juin','Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
function moisLabelPdf(string $ym): string {
    global $moisFr;
    [$y, $m] = explode('-', $ym);
    return ($moisFr[(int)$m] ?? $m) . ' ' . $y;
}
function fmtDate(?string $d): string {
    if (!$d) return '';
    [$y, $m, $j] = explode('-', $d);
    return "$j/$m/$y";
}
function fmtKm($v): string {
    return $v !== null && $v !== '' ? number_format((float)$v, 1, ',', ' ') . ' km' : '—';
}

// ── TCPDF ──────────────────────────────────────────────────────────────────────
define('K_PATH_CACHE', __DIR__ . '/tcpdf_cache/');
@mkdir(K_PATH_CACHE, 0777, true);

require_once __DIR__ . '/tcpdf/tcpdf.php';

class IkPDF extends TCPDF
{
    public string $titre = '';
    public string $soustitre = '';

    public function Header(): void
    {
        $this->SetFont('dejavusans', 'B', 14);
        $this->SetTextColor(26, 42, 58);
        $this->Cell(0, 10, $this->titre, 0, 1, 'C');
        if ($this->soustitre) {
            $this->SetFont('dejavusans', '', 10);
            $this->SetTextColor(100, 110, 120);
            $this->Cell(0, 6, $this->soustitre, 0, 1, 'C');
        }
        $this->Ln(2);
        $this->SetDrawColor(200, 210, 220);
        $this->Line($this->GetX(), $this->GetY(), $this->GetX() + $this->getPageWidth() - $this->getMargins()['left'] - $this->getMargins()['right'], $this->GetY());
        $this->Ln(4);
    }

    public function Footer(): void
    {
        $this->SetY(-15);
        $this->SetFont('dejavusans', '', 8);
        $this->SetTextColor(150);
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages() . '  –  MaBoxImmo RH  –  Généré le ' . date('d/m/Y'), 0, 0, 'C');
    }
}

$pdf = new IkPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('MaBoxImmo RH');
$pdf->SetAuthor($sess['prenom'] . ' ' . $sess['nom']);
$pdf->SetTitle('IK ' . $sess['prenom'] . ' ' . $sess['nom'] . ' – ' . moisLabelPdf($sess['mois_deplacements']));
$pdf->titre    = 'Indemnités Kilométriques';
$pdf->soustitre = moisLabelPdf($sess['mois_deplacements']) . '  ·  ' . $sess['prenom'] . ' ' . $sess['nom'];

$pdf->SetMargins(15, 35, 15);
$pdf->SetHeaderMargin(8);
$pdf->SetFooterMargin(10);
$pdf->SetAutoPageBreak(true, 18);
$pdf->AddPage();

// ── Bloc identité ──────────────────────────────────────────────────────────────
$pdf->SetFont('dejavusans', 'B', 11);
$pdf->SetFillColor(245, 248, 252);
$pdf->SetDrawColor(220, 228, 236);
$pdf->SetTextColor(26, 42, 58);
$pdf->RoundedRect($pdf->GetX(), $pdf->GetY(), 180, 28, 3, '1111', 'DF');
$pdf->SetXY(20, $pdf->GetY() + 4);
$pdf->SetFont('dejavusans', 'B', 11);
$pdf->Cell(40, 6, 'Collaborateur :', 0, 0);
$pdf->SetFont('dejavusans', '', 11);
$pdf->Cell(0, 6, $sess['prenom'] . ' ' . $sess['nom'], 0, 1);
$pdf->SetX(20);
$pdf->SetFont('dejavusans', 'B', 10);
$pdf->Cell(40, 5, 'Agence :', 0, 0);
$pdf->SetFont('dejavusans', '', 10);
$pdf->Cell(0, 5, ($sess['nom_agence'] ?? '') . ' – ' . ($sess['agence_adresse'] ?? '') . ' ' . ($sess['agence_cp'] ?? '') . ' ' . ($sess['agence_ville'] ?? ''), 0, 1);
$pdf->SetX(20);
$pdf->SetFont('dejavusans', 'B', 10);
$pdf->Cell(40, 5, 'Véhicule :', 0, 0);
$pdf->SetFont('dejavusans', '', 10);
$vehiculeStr = $sess['vehicule_nom'] ?: ($sess['vehicule_nom'] ?: 'Non renseigné');
if ($sess['vehicule_puissance_fiscale']) $vehiculeStr .= ' (' . $sess['vehicule_puissance_fiscale'] . ' CV)';
$pdf->Cell(0, 5, $vehiculeStr, 0, 1);
$pdf->Ln(6);

// ── Bloc paie TRÈS VISIBLE ────────────────────────────────────────────────────
$pdf->SetFillColor(16, 185, 129);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('dejavusans', 'B', 12);
$pdf->RoundedRect($pdf->GetX(), $pdf->GetY(), 180, 22, 4, '1111', 'F');
$yBlock = $pdf->GetY();
$pdf->SetXY(20, $yBlock + 4);
$pdf->Cell(0, 7, '📋 À intégrer dans la paie de : ' . moisLabelPdf($sess['mois_paie']), 0, 1, 'C');
$pdf->SetXY(20, $pdf->GetY());
$pdf->SetFont('dejavusans', 'B', 11);
$pdf->Cell(0, 7, 'Reporter dans le champ paie « nb de KM » = ' . number_format($totalKm, 1, ',', ' ') . ' km', 0, 1, 'C');
$pdf->Ln(6);

// ── Tableau des déplacements ───────────────────────────────────────────────────
$pdf->SetTextColor(26, 42, 58);
$colW = [22, 35, 42, 28, 24, 14, 14, 0]; // Date, Départ, Destination, Motif, Observation, Aller, Retour, Total
$headers = ['Date', 'Point de départ', 'Destination / Immeuble', 'Motif', 'Observation', 'Aller', 'Retour', 'Total'];
$lastCol = 180 - array_sum(array_slice($colW, 0, 7));
$colW[7] = max($lastCol, 14);

// En-têtes
$pdf->SetFillColor(240, 244, 248);
$pdf->SetDrawColor(200, 210, 220);
$pdf->SetFont('dejavusans', 'B', 8);
foreach ($headers as $i => $h) {
    $align = $i >= 5 ? 'C' : 'L';
    $pdf->Cell($colW[$i], 7, $h, 1, 0, $align, true);
}
$pdf->Ln();

// Lignes
$pdf->SetFont('dejavusans', '', 8);
$rowNum = 0;
foreach ($lignes as $ligne) {
    $rowNum++;
    $fill = ($rowNum % 2 === 0);
    $pdf->SetFillColor($fill ? 248 : 255, $fill ? 250 : 255, $fill ? 253 : 255);

    $kmAller  = $ligne['km_aller']  !== null ? number_format((float)$ligne['km_aller'],  1, ',', '') : '—';
    $kmRetour = $ligne['km_retour'] !== null ? number_format((float)$ligne['km_retour'], 1, ',', '') : '—';
    $kmTotal  = (float)$ligne['km_aller'] + (float)$ligne['km_retour'];
    $kmTotalStr = $kmTotal > 0 ? number_format($kmTotal, 1, ',', '') : '—';

    $depart = $ligne['depart_label'] ?: ($ligne['depart_agence_nom'] ?: '');
    $dest   = $ligne['destination_label'] ?: '';
    if ($ligne['destination_ville']) $dest .= "\n" . $ligne['destination_ville'];

    $maxH = max(
        $pdf->getStringHeight($colW[0], fmtDate($ligne['date_deplacement'])),
        $pdf->getStringHeight($colW[1], $depart),
        $pdf->getStringHeight($colW[2], $dest),
        $pdf->getStringHeight($colW[4], $ligne['observation'] ?? ''),
        6
    );

    $x = $pdf->GetX();
    $y = $pdf->GetY();

    // Vérifier saut de page
    if ($y + $maxH > $pdf->getPageHeight() - 20) {
        $pdf->AddPage();
        // Re-afficher les en-têtes
        $pdf->SetFillColor(240, 244, 248);
        $pdf->SetFont('dejavusans', 'B', 8);
        foreach ($headers as $i => $h2) {
            $pdf->Cell($colW[$i], 7, $h2, 1, 0, $i >= 5 ? 'C' : 'L', true);
        }
        $pdf->Ln();
        $pdf->SetFont('dejavusans', '', 8);
        $y = $pdf->GetY();
    }

    $pdf->MultiCell($colW[0], $maxH, fmtDate($ligne['date_deplacement']), 1, 'L', $fill, 0, '', '', true, 0, false, true, $maxH, 'M');
    $pdf->MultiCell($colW[1], $maxH, $depart,                            1, 'L', $fill, 0, '', '', true, 0, false, true, $maxH, 'M');
    $pdf->MultiCell($colW[2], $maxH, $dest,                              1, 'L', $fill, 0, '', '', true, 0, false, true, $maxH, 'M');
    $pdf->MultiCell($colW[3], $maxH, $ligne['motif'] ?? '',              1, 'L', $fill, 0, '', '', true, 0, false, true, $maxH, 'M');
    $pdf->MultiCell($colW[4], $maxH, $ligne['observation'] ?? '',        1, 'L', $fill, 0, '', '', true, 0, false, true, $maxH, 'M');
    $pdf->MultiCell($colW[5], $maxH, $kmAller,                           1, 'C', $fill, 0, '', '', true, 0, false, true, $maxH, 'M');
    $pdf->MultiCell($colW[6], $maxH, $kmRetour,                          1, 'C', $fill, 0, '', '', true, 0, false, true, $maxH, 'M');
    $pdf->MultiCell($colW[7], $maxH, $kmTotalStr,                        1, 'C', $fill, 1, '', '', true, 0, false, true, $maxH, 'M');
}

// Ligne total
$pdf->SetFillColor(26, 42, 58);
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('dejavusans', 'B', 9);
$sumCols = array_sum(array_slice($colW, 0, 7));
$pdf->Cell($sumCols, 8, 'TOTAL  (' . $nbLignes . ' déplacement' . ($nbLignes > 1 ? 's' : '') . ')', 1, 0, 'R', true);
$pdf->Cell($colW[7], 8, number_format($totalKm, 1, ',', '') . ' km', 1, 1, 'C', true);

$pdf->Ln(8);

// ── Bloc paie répété en bas ────────────────────────────────────────────────────
$pdf->SetFillColor(240, 248, 244);
$pdf->SetDrawColor(16, 185, 129);
$pdf->SetTextColor(26, 42, 58);
$pdf->RoundedRect($pdf->GetX(), $pdf->GetY(), 180, 20, 3, '1111', 'DF');
$y2 = $pdf->GetY();
$pdf->SetXY(20, $y2 + 4);
$pdf->SetFont('dejavusans', 'B', 10);
$pdf->Cell(0, 6, 'À intégrer dans la paie de : ' . moisLabelPdf($sess['mois_paie']), 0, 1, 'C');
$pdf->SetX(20);
$pdf->Cell(0, 6, 'Champ paie à renseigner – nb de KM = ' . number_format($totalKm, 1, ',', ' ') . ' km', 0, 1, 'C');

// ── Nom du fichier ─────────────────────────────────────────────────────────────
$nomUser    = preg_replace('/[^A-Z0-9_]/i', '_', strtoupper($sess['nom']));
$moisDepFn  = str_replace('-', '-', $sess['mois_deplacements']);
$moisPaieFn = str_replace('-', '-', $sess['mois_paie']);
$filename   = "IK_{$nomUser}_{$moisDepFn}_integre_paie_{$moisPaieFn}.pdf";

$pdf->Output($filename, 'D');
