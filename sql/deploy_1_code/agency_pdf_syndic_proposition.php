<?php
// agency_pdf_syndic_proposition.php — PDF proposition commerciale syndic (ALUR)
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();
$pdo = $GLOBALS['pdo'] ?? db();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: agency_syndic_propositions.php'); exit; }

// ── Data ───────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT p.*,
    e.nom AS etab_nom, e.adresse AS etab_adresse, e.code_postal AS etab_cp,
    e.ville AS etab_ville, e.telephone AS etab_tel, e.email AS etab_email,
    e.siret AS etab_siret, e.tva_intracommunautaire AS etab_tva, e.logo AS etab_logo,
    u.prenom AS gest_prenom, u.nom AS gest_nom
FROM agency_syndic_proposition p
LEFT JOIN etablissements e ON e.id=p.id_etablissement
LEFT JOIN users u ON u.id=p.id_createur
WHERE p.id=?");
$stmt->execute([$id]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$p) { http_response_code(404); exit('Proposition introuvable'); }

$lignes = $pdo->prepare("SELECT * FROM agency_syndic_proposition_ligne WHERE id_proposition=? ORDER BY ordre");
$lignes->execute([$id]);
$allLignes = $lignes->fetchAll(PDO::FETCH_ASSOC);

$forfait = array_filter($allLignes, fn($l) => $l['categorie']==='forfait_base');
$particu = array_filter($allLignes, fn($l) => $l['categorie']==='prestation_particuliere');
$remises = array_filter($allLignes, fn($l) => $l['categorie']==='remise');

$totalForfait = array_sum(array_column(array_values($forfait),'prix_ht'));
$totalParticu = array_sum(array_column(array_values($particu),'prix_ht'));
$totalRemise  = array_sum(array_column(array_values($remises), 'prix_ht'));
$totalHT      = $totalForfait + $totalParticu + $totalRemise;
$tvaAmt       = $totalHT * ($p['tva_pct'] / 100);
$totalTTC     = $totalHT + $tvaAmt;

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function eur($v) { return number_format((float)$v,2,',',' ').' €'; }
function dtFr($d) { return $d ? date('d/m/Y',strtotime($d)) : '—'; }

$tcpdf = __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
if (file_exists($tcpdf)) {
    require_once $tcpdf;

    class PDF_Proposition extends TCPDF {
        public array $etab = [];
        public function Header() {
            $this->SetFont('helvetica','B',16);
            $this->SetTextColor(30,58,95);
            $this->SetXY(15,12);
            $this->Cell(0,8,$this->etab['etab_nom']??'',0,1,'L');
            $this->SetFont('helvetica','',8);
            $this->SetTextColor(100,116,139);
            $parts = array_filter([$this->etab['etab_adresse']??'', trim(($this->etab['etab_cp']??'').' '.($this->etab['etab_ville']??''))]);
            if ($parts) { $this->Cell(0,4,implode(' — ',$parts),0,1,'L'); }
            if ($this->etab['etab_tel']??'') $this->Cell(0,4,'Tél. '.($this->etab['etab_tel']??'').($this->etab['etab_email']??''?' — '.($this->etab['etab_email']??''):''),0,1,'L');
            $this->SetDrawColor(37,99,235);
            $this->SetLineWidth(0.8);
            $this->Line(15,34,195,34);
            // PROPOSITION badge
            $this->SetFillColor(37,99,235);
            $this->SetTextColor(255,255,255);
            $this->SetFont('helvetica','B',9);
            $this->SetXY(145,12);
            $this->Cell(50,12,'PROPOSITION COMMERCIALE',0,0,'C',true);
        }
        public function Footer() {
            $this->SetY(-16);
            $this->SetFont('helvetica','I',7);
            $this->SetTextColor(148,163,184);
            $this->Cell(0,5,'Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages().' — Conforme loi ALUR (décret n°2015-342 du 26 mars 2015) — '.(($this->etab['etab_nom']??'')),0,0,'C');
        }
    }

    $pdf = new PDF_Proposition('P','mm','A4');
    $pdf->etab = (array)$p;
    $pdf->SetCreator('MaBoxImmo');
    $pdf->SetTitle('Proposition '.$p['reference']);
    $pdf->SetMargins(15,38,15);
    $pdf->SetAutoPageBreak(true,20);
    $pdf->AddPage();

    // ── Référence + date ──────────────────────────────────────────────────
    $pdf->SetY(38);
    $pdf->SetFont('helvetica','',8);
    $pdf->SetTextColor(100,116,139);
    $pdf->Cell(90,5,'Réf. : '.$p['reference'].' — Date : '.dtFr($p['date_proposition']),0,0,'L');
    $pdf->Cell(0,5,'Validité : '.dtFr($p['date_validite']),0,1,'R');
    $pdf->Ln(2);

    // ── Bloc prospect / immeuble ──────────────────────────────────────────
    $pdf->SetFillColor(248,250,252);
    $pdf->Rect(15,$pdf->GetY(),85,38,'F');
    $pdf->Rect(110,$pdf->GetY(),85,38,'F');

    $yB = $pdf->GetY()+3;
    $pdf->SetXY(18,$yB);
    $pdf->SetFont('helvetica','B',7);
    $pdf->SetTextColor(100,116,139);
    $pdf->Cell(79,4,'DESTINATAIRE',0,1,'L');
    $pdf->SetX(18);
    $pdf->SetFont('helvetica','B',10);
    $pdf->SetTextColor(30,41,59);
    $pdf->MultiCell(79,5,$p['prospect_nom']??'',0,'L');
    if ($p['prospect_fonction']) { $pdf->SetX(18); $pdf->SetFont('helvetica','',8); $pdf->SetTextColor(100,116,139); $pdf->Cell(79,4,$p['prospect_fonction'],0,1,'L'); }
    if ($p['prospect_adresse']) { $pdf->SetX(18); $pdf->Cell(79,4,$p['prospect_adresse'],0,1,'L'); }

    $pdf->SetXY(113,$yB);
    $pdf->SetFont('helvetica','B',7);
    $pdf->SetTextColor(100,116,139);
    $pdf->Cell(79,4,'IMMEUBLE CONCERNÉ',0,1,'L');
    $pdf->SetX(113);
    $pdf->SetFont('helvetica','B',10);
    $pdf->SetTextColor(30,41,59);
    $pdf->MultiCell(79,5,$p['immeuble_nom']??'',0,'L');
    if ($p['immeuble_adresse']) { $pdf->SetX(113); $pdf->SetFont('helvetica','',8); $pdf->SetTextColor(100,116,139); $pdf->Cell(79,4,$p['immeuble_adresse'],0,1,'L'); }
    if ($p['immeuble_ville']) { $pdf->SetX(113); $pdf->Cell(79,4,($p['immeuble_code_postal']??'').' '.($p['immeuble_ville']??''),0,1,'L'); }
    if ($p['immeuble_nb_lots']) { $pdf->SetX(113); $pdf->Cell(79,4,(int)$p['immeuble_nb_lots'].' lot(s) principal(aux)',0,1,'L'); }

    $pdf->SetY(max($pdf->GetY(), $yB+38) + 4);

    // ── Message intro ─────────────────────────────────────────────────────
    if ($p['message_intro']) {
        $pdf->SetFont('helvetica','',9);
        $pdf->SetTextColor(55,65,81);
        $pdf->MultiCell(0,5,$p['message_intro'],0,'L');
        $pdf->Ln(4);
    }

    // ── Fonction tableau lignes ───────────────────────────────────────────
    $drawLignesTable = function(array $rows, string $title, bool $alur=false) use ($pdf) {
        $pdf->SetFont('helvetica','B',9);
        $pdf->SetTextColor(30,41,59);
        $pdf->SetFillColor(240,249,255);
        $pdf->SetDrawColor(186,230,253);
        $pdf->Cell(0,7,$title,0,1,'L',true);
        $pdf->Ln(1);
        if ($alur) {
            $pdf->SetFont('helvetica','I',7);
            $pdf->SetTextColor(180,120,20);
            $pdf->Cell(0,4,'Les prestations ALUR sont facturées séparément du forfait de gestion courante (décret n°2015-342 du 26/03/2015)',0,1,'L');
            $pdf->Ln(1);
        }
        // En-tête
        $pdf->SetFillColor(241,245,249);
        $pdf->SetFont('helvetica','B',8);
        $pdf->SetTextColor(100,116,139);
        $pdf->Cell(100,6,'Désignation',0,0,'L',true);
        $pdf->Cell(35,6,'Unité',0,0,'L',true);
        $pdf->Cell(40,6,'Montant HT',0,1,'R',true);
        $pdf->SetFont('helvetica','',9);
        $pdf->SetTextColor(30,41,59);
        foreach ($rows as $l) {
            $pdf->SetFillColor(255,255,255);
            $desig = $l['designation'] ?? '';
            if (!empty($l['inclus_forfait'])) $desig .= ' (inclus forfait)';
            if (!empty($l['obligatoire']))    $desig .= ' ★ALUR';
            $pdf->MultiCell(100,5,$desig,0,'L',false,0);
            $pdf->Cell(35,5,$l['unite']??'',0,0,'L');
            $pdf->Cell(40,5,eur($l['prix_ht']),0,1,'R');
            $pdf->SetDrawColor(240,244,248);
            $pdf->Line(15,$pdf->GetY(),195,$pdf->GetY());
        }
        $pdf->Ln(3);
    };

    if ($forfait) $drawLignesTable(array_values($forfait), 'Forfait de gestion courante');
    if ($particu) $drawLignesTable(array_values($particu), 'Prestations particulières', true);
    if ($remises) $drawLignesTable(array_values($remises), 'Remises accordées');

    // ── Récap financier ───────────────────────────────────────────────────
    $pdf->Ln(2);
    $pdf->SetFillColor(240,249,255);
    $pdf->SetFont('helvetica','',9);
    $pdf->SetTextColor(71,85,105);
    $xR = 110;
    $wL = 55; $wV = 30;
    if ($totalForfait) { $pdf->SetX($xR); $pdf->Cell($wL,5,'Forfait de base HT',0,0,'R'); $pdf->Cell($wV,5,eur($totalForfait),0,1,'R'); }
    if ($totalParticu) { $pdf->SetX($xR); $pdf->Cell($wL,5,'Prestations particulières HT',0,0,'R'); $pdf->Cell($wV,5,eur($totalParticu),0,1,'R'); }
    if ($totalRemise)  { $pdf->SetX($xR); $pdf->SetTextColor(22,163,74); $pdf->Cell($wL,5,'Remises HT',0,0,'R'); $pdf->Cell($wV,5,eur($totalRemise),0,1,'R'); $pdf->SetTextColor(71,85,105); }
    $pdf->SetX($xR); $pdf->SetFont('helvetica','B',9); $pdf->Cell($wL,6,'Total HT',0,0,'R'); $pdf->Cell($wV,6,eur($totalHT),0,1,'R');
    $pdf->SetX($xR); $pdf->SetFont('helvetica','',9); $pdf->Cell($wL,5,'TVA ('.number_format($p['tva_pct'],1).'%)',0,0,'R'); $pdf->Cell($wV,5,eur($tvaAmt),0,1,'R');
    $pdf->SetDrawColor(37,99,235); $pdf->SetLineWidth(0.5);
    $xLn = $xR+5; $pdf->Line($xLn,$pdf->GetY(),$xLn+$wL+$wV-5,$pdf->GetY());
    $pdf->SetX($xR);
    $pdf->SetFont('helvetica','B',11);
    $pdf->SetTextColor(37,99,235);
    $pdf->SetFillColor(239,246,255);
    $pdf->Cell($wL+$wV,8,'Total TTC / an : '.eur($totalTTC),0,1,'R',true);

    // ── Conditions particulières ──────────────────────────────────────────
    if ($p['conditions_particulieres']) {
        $pdf->Ln(4);
        $pdf->SetFont('helvetica','B',8);
        $pdf->SetTextColor(71,85,105);
        $pdf->Cell(0,5,'Conditions particulières :',0,1,'L');
        $pdf->SetFont('helvetica','',8);
        $pdf->MultiCell(0,5,$p['conditions_particulieres'],0,'L');
    }

    // ── Validité + mention légale ─────────────────────────────────────────
    $pdf->Ln(4);
    $pdf->SetFillColor(254,243,199);
    $pdf->SetFont('helvetica','B',8);
    $pdf->SetTextColor(146,64,14);
    $pdf->Cell(0,7,'Cette proposition est valable jusqu\'au : '.dtFr($p['date_validite']),0,1,'C',true);
    $pdf->Ln(3);
    $pdf->SetFont('helvetica','I',7);
    $pdf->SetTextColor(148,163,184);
    $pdf->MultiCell(0,4,'Mention légale : Conformément au décret n°2015-342 du 26 mars 2015 pris en application de la loi n°2014-366 du 24 mars 2014 (loi ALUR), les honoraires de syndic se décomposent en un forfait de gestion courante et des prestations particulières facturables séparément.',0,'L');

    // ── Zone signature ────────────────────────────────────────────────────
    $pdf->Ln(8);
    $pdf->SetFont('helvetica','',9);
    $pdf->SetTextColor(55,65,81);
    $yS = $pdf->GetY();
    $pdf->SetX(15); $pdf->Cell(80,5,'Le Syndic',0,0,'C'); $pdf->Cell(0,5,'Lu et approuvé — Le Président du Conseil Syndical',0,1,'C');
    $pdf->SetDrawColor(200,200,200); $pdf->SetLineWidth(0.3);
    $pdf->Line(15,$yS+25,90,$yS+25);
    $pdf->Line(110,$yS+25,195,$yS+25);
    $pdf->SetFont('helvetica','',8); $pdf->SetTextColor(148,163,184);
    $pdf->SetX(15); $pdf->Cell(80,5,($p['gest_prenom']??'').' '.($p['gest_nom']??''),0,0,'C');
    $pdf->Cell(0,5,'Nom & Date',0,1,'C');

    $pdf->Output('Proposition_'.$p['reference'].'.pdf','I');
    exit;
}

// ── Fallback HTML ─────────────────────────────────────────────────────────
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Proposition <?= h($p['reference']) ?></title>
<style>
body{font-family:Arial,sans-serif;max-width:800px;margin:0 auto;padding:20px;font-size:13px;color:#ffffff}
.header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;padding-bottom:14px;border-bottom:2px solid #2563eb}
.badge-prop{background:#2563eb;color:#fff;padding:6px 14px;border-radius:6px;font-weight:700;font-size:12px}
.blocs{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px}
.bloc{background:#f8fafc;padding:14px;border-radius:8px}
.bloc h4{font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:#94a3b8;margin-bottom:6px}
table{width:100%;border-collapse:collapse;margin-bottom:16px}
th{background:#f1f5f9;padding:7px 10px;font-size:10px;text-align:left;text-transform:uppercase;letter-spacing:.4px;color:#64748b}
td{padding:7px 10px;border-bottom:1px solid #f1f5f9}
.total-box{background:#eff6ff;padding:14px;border-radius:8px;float:right;width:260px;margin-bottom:16px}
.ttc{font-size:18px;font-weight:800;color:#2563eb}
.legal{font-size:10px;color:#94a3b8;margin-top:20px;padding:10px;border:1px solid #e2e8f0;border-radius:6px}
.validite{background:#fef3c7;color:#92400e;padding:8px 14px;border-radius:6px;font-weight:700;text-align:center;margin:14px 0}
@media print{.no-print{display:none}}
</style>
</head>
<body>
<div class="no-print" style="margin-bottom:16px">
    <button onclick="window.print()" style="padding:8px 20px;background:#2563eb;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px">🖨️ Imprimer / PDF</button>
    <a href="agency_syndic_propositions.php" style="margin-left:12px;font-size:13px;color:#2563eb">← Retour liste</a>
</div>

<div class="header">
    <div>
        <strong style="font-size:16px"><?= h($p['etab_nom']??'') ?></strong><br>
        <span style="font-size:12px;color:#64748b"><?= h(($p['etab_adresse']??'').' '.($p['etab_cp']??'').' '.($p['etab_ville']??'')) ?></span>
    </div>
    <div>
        <div class="badge-prop">PROPOSITION COMMERCIALE</div>
        <div style="font-size:11px;color:#64748b;margin-top:4px;text-align:right">
            Réf. <?= h($p['reference']) ?><br>
            Date : <?= dtFr($p['date_proposition']) ?><br>
            Validité : <?= dtFr($p['date_validite']) ?>
        </div>
    </div>
</div>

<div class="blocs">
    <div class="bloc">
        <h4>Destinataire</h4>
        <strong><?= h($p['prospect_nom']) ?></strong><br>
        <?php if($p['prospect_fonction']): ?><span style="color:#64748b"><?= h($p['prospect_fonction']) ?></span><br><?php endif; ?>
        <?php if($p['prospect_adresse']): ?><?= h($p['prospect_adresse']) ?><br><?php endif; ?>
    </div>
    <div class="bloc">
        <h4>Immeuble concerné</h4>
        <strong><?= h($p['immeuble_nom']) ?></strong><br>
        <?php if($p['immeuble_adresse']): ?><?= h($p['immeuble_adresse']) ?><br><?php endif; ?>
        <?php if($p['immeuble_ville']): ?><?= h(($p['immeuble_code_postal']??'').' '.$p['immeuble_ville']) ?><br><?php endif; ?>
        <?php if($p['immeuble_nb_lots']): ?><em><?= (int)$p['immeuble_nb_lots'] ?> lots principaux</em><?php endif; ?>
    </div>
</div>

<?php if ($p['message_intro']): ?><p style="margin-bottom:16px"><?= nl2br(h($p['message_intro'])) ?></p><?php endif; ?>

<?php if ($forfait): ?>
<h3 style="font-size:13px;font-weight:700;margin-bottom:8px;color:#ffffff">Forfait de gestion courante</h3>
<table><thead><tr><th>Désignation</th><th>Unité</th><th style="text-align:right">Montant HT</th></tr></thead><tbody>
<?php foreach ($forfait as $l): ?>
<tr><td><?= h($l['designation']) ?><?= $l['inclus_forfait']?' <em style="font-size:11px;color:#16a34a">(inclus forfait)</em>':'' ?></td><td><?= h($l['unite']) ?></td><td style="text-align:right;font-weight:600"><?= eur($l['prix_ht']) ?></td></tr>
<?php endforeach; ?>
<tr style="background:#f1f5f9;font-weight:700"><td colspan="2">Sous-total forfait</td><td style="text-align:right"><?= eur($totalForfait) ?></td></tr>
</tbody></table>
<?php endif; ?>

<?php if ($particu): ?>
<h3 style="font-size:13px;font-weight:700;margin-bottom:4px;color:#ffffff">Prestations particulières (loi ALUR)</h3>
<p style="font-size:11px;color:#f59e0b;margin-bottom:8px">Facturables séparément selon le décret n°2015-342 du 26 mars 2015.</p>
<table><thead><tr><th>Désignation</th><th>Unité</th><th style="text-align:right">Tarif HT</th></tr></thead><tbody>
<?php foreach ($particu as $l): ?>
<tr><td><?= h($l['designation']) ?><?= $l['obligatoire']?' <strong style="color:#f59e0b">★ALUR</strong>':'' ?></td><td><?= h($l['unite']) ?></td><td style="text-align:right;font-weight:600"><?= eur($l['prix_ht']) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>

<?php if ($remises): ?>
<h3 style="font-size:13px;font-weight:700;margin-bottom:8px;color:#16a34a">Remises</h3>
<table><thead><tr><th>Motif</th><th>Unité</th><th style="text-align:right">Montant HT</th></tr></thead><tbody>
<?php foreach ($remises as $l): ?>
<tr><td style="color:#16a34a"><?= h($l['designation']) ?></td><td><?= h($l['unite']) ?></td><td style="text-align:right;font-weight:600;color:#16a34a"><?= eur($l['prix_ht']) ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php endif; ?>

<div style="overflow:hidden;margin-bottom:20px">
<div class="total-box">
    <div style="display:flex;justify-content:space-between;margin-bottom:4px"><span>Total HT</span><span style="font-weight:700"><?= eur($totalHT) ?></span></div>
    <div style="display:flex;justify-content:space-between;margin-bottom:8px;color:#64748b"><span>TVA (<?= number_format($p['tva_pct'],1) ?>%)</span><span><?= eur($tvaAmt) ?></span></div>
    <div style="border-top:2px solid #bae6fd;padding-top:8px;display:flex;justify-content:space-between;align-items:center">
        <span style="font-weight:700">Total TTC / an</span><span class="ttc"><?= eur($totalTTC) ?></span>
    </div>
</div>
</div>

<?php if ($p['conditions_particulieres']): ?>
<p style="margin-bottom:14px"><strong>Conditions particulières : </strong><?= nl2br(h($p['conditions_particulieres'])) ?></p>
<?php endif; ?>

<div class="validite">Cette proposition est valable jusqu'au : <?= dtFr($p['date_validite']) ?></div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:40px;text-align:center">
    <div><div style="border-top:1px solid #ccc;padding-top:8px;font-size:12px;color:#64748b">Le Syndic<br><?= h(($p['gest_prenom']??'').' '.($p['gest_nom']??'')) ?></div></div>
    <div><div style="border-top:1px solid #ccc;padding-top:8px;font-size:12px;color:#64748b">Lu et approuvé — Le Président du CS<br>Nom & Date</div></div>
</div>

<div class="legal">Mention légale : Conformément au décret n°2015-342 du 26 mars 2015 pris en application de la loi ALUR n°2014-366 du 24 mars 2014, les honoraires de syndic se décomposent en un forfait de gestion courante et des prestations particulières facturables séparément.</div>
</body>
</html>
