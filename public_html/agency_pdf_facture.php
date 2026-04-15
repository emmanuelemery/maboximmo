<?php
// agency_pdf_facture.php — PDF Facture V2 MaBoxImmo (TCPDF ou fallback HTML imprimable)
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();
$pdo = $GLOBALS['pdo'] ?? db();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header('Location: agency_factures.php'); exit; }

// ── Data ──────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT f.*,
           e.nom AS etab_nom, e.adresse AS etab_adresse, e.code_postal AS etab_cp,
           e.ville AS etab_ville, e.telephone AS etab_tel, e.email AS etab_email,
           e.siret AS etab_siret, e.tva_intracommunautaire AS etab_tva,
           e.logo AS etab_logo
    FROM agency_facture f
    LEFT JOIN etablissements e ON e.id=f.id_etablissement
    WHERE f.id=?
");
$stmt->execute([$id]);
$f = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$f) { http_response_code(404); exit('Facture introuvable'); }

$lignes_stmt = $pdo->prepare("SELECT * FROM agency_facture_ligne WHERE id_facture=? ORDER BY ordre");
$lignes_stmt->execute([$id]);
$lignes = $lignes_stmt->fetchAll(PDO::FETCH_ASSOC);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function eur(float $v): string { return number_format($v,2,',',' ').' €'; }
function dtFr(?string $d): string { return $d ? date('d/m/Y',strtotime($d)) : '—'; }

$STAT_LBL = ['brouillon'=>'BROUILLON','validee'=>'VALIDÉE','envoyee'=>'ENVOYÉE','payee'=>'PAYÉE','annulee'=>'ANNULÉE'];
$STAT_CLR = ['brouillon'=>[150,150,150],'validee'=>[72,120,166],'envoyee'=>[200,112,48],'payee'=>[74,128,96],'annulee'=>[200,64,64]];

// ── TCPDF ─────────────────────────────────────────────────────────────
$tcpdf_path = __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
if (file_exists($tcpdf_path)) {
    require_once $tcpdf_path;

    class PDF_Facture extends TCPDF {
        public array $etab = [];
        public function Header() {
            // Logo si disponible
            $logo = $this->etab['etab_logo'] ?? '';
            if ($logo && file_exists(__DIR__.'/'.$logo)) {
                $this->Image(__DIR__.'/'.$logo, 15, 10, 35, 0, '', '', 'T', false, 300, '', false, false, 0);
            }
            // Nom établissement
            $this->SetFont('helvetica','B',14);
            $this->SetTextColor(72,120,166);
            $this->SetXY(55, 10);
            $this->Cell(0,7, $this->etab['etab_nom'] ?? '', 0, 1, 'L');
            $this->SetFont('helvetica','',8);
            $this->SetTextColor(120,120,120);
            $addr = implode(' — ', array_filter([$this->etab['etab_adresse']??'', trim(($this->etab['etab_cp']??'').' '.($this->etab['etab_ville']??''))]));
            if ($addr) { $this->SetX(55); $this->Cell(0,5,$addr,0,1,'L'); }
            if ($this->etab['etab_tel']??'') { $this->SetX(55); $this->Cell(0,5,'Tél. '.($this->etab['etab_tel']??'').' — '.($this->etab['etab_email']??''),0,1,'L'); }
            if ($this->etab['etab_siret']??'') { $this->SetX(55); $this->Cell(0,5,'SIRET : '.($this->etab['etab_siret']??'').($this->etab['etab_tva']??'' ? ' — TVA : '.($this->etab['etab_tva']??'') : ''),0,1,'L'); }
            $this->Line(15,38,195,38);
        }
        public function Footer() {
            $this->SetY(-18);
            $this->SetFont('helvetica','I',8);
            $this->SetTextColor(150,150,150);
            $this->Cell(0,5,'Page '.$this->getAliasNumPage().' / '.$this->getAliasNbPages().' — Facture générée par MaBoxImmo le '.date('d/m/Y à H:i'),0,0,'C');
        }
    }

    $pdf = new PDF_Facture('P','mm','A4');
    $pdf->etab = (array)$f;
    $pdf->SetCreator('MaBoxImmo');
    $pdf->SetTitle('Facture '.$f['numero']);
    $pdf->SetMargins(15,42,15);
    $pdf->SetAutoPageBreak(true,22);
    $pdf->AddPage();

    // Titre FACTURE
    $pdf->SetFont('helvetica','B',22);
    $sc = $STAT_CLR[$f['statut']??'brouillon'] ?? [72,120,166];
    $pdf->SetTextColor(...$sc);
    $pdf->Cell(0,10,'FACTURE',0,1,'L');
    $pdf->SetFont('helvetica','B',13);
    $pdf->SetTextColor(44,42,40);
    $pdf->Cell(0,7,$f['numero'].'  — '.($STAT_LBL[$f['statut']??'']??''),0,1,'L');
    $pdf->Ln(2);

    // Bloc client + dates (2 colonnes)
    $pdf->SetFont('helvetica','B',9);
    $pdf->SetFillColor(232,228,222);
    $pdf->SetTextColor(120,120,120);
    $pdf->Cell(90,6,' FACTURER À',0,0,'',false);
    $pdf->Cell(85,6,' INFORMATIONS',0,1,'',false);
    $pdf->SetFont('helvetica','',10);
    $pdf->SetTextColor(44,42,40);

    $clientLines = array_filter([$f['client']??'', $f['immeuble_txt']??'']);
    $infoLines   = ['Date d\'émission : '.dtFr($f['date_emission'])];
    if ($f['date_echeance']) $infoLines[] = 'Date d\'échéance : '.dtFr($f['date_echeance']);
    $infoLines[] = 'Mode de paiement : '.ucfirst($f['mode_paiement']??'');

    $maxRows = max(count($clientLines), count($infoLines));
    for ($i=0; $i<$maxRows; $i++) {
        $pdf->Cell(90,6,' '.($clientLines[$i]??''),'',0);
        $pdf->Cell(85,6,' '.($infoLines[$i]??''),'',1);
    }
    $pdf->Ln(6);

    // Table lignes
    $pdf->SetFillColor(72,120,166);
    $pdf->SetTextColor(255,255,255);
    $pdf->SetFont('helvetica','B',9);
    $pdf->Cell(80,7,' Désignation',1,0,'L',true);
    $pdf->Cell(18,7,'Qté',1,0,'C',true);
    $pdf->Cell(28,7,'PU HT',1,0,'R',true);
    $pdf->Cell(18,7,'TVA %',1,0,'C',true);
    $pdf->Cell(28,7,'Total HT',1,0,'R',true);
    $pdf->Cell(0,7,'Total TTC',1,1,'R',true);

    $pdf->SetFont('helvetica','',10);
    $fill = false;
    foreach ($lignes as $lg) {
        $ht  = round((float)($lg['quantite']??1)*(float)($lg['prix_unitaire_ht']??0),2);
        $tv  = (float)($lg['tva_taux']??20);
        $ttc = round($ht*(1+$tv/100),2);
        $pdf->SetFillColor(232,228,222);
        $pdf->SetTextColor(44,42,40);
        $h = max(7, (int)ceil($pdf->GetStringWidth($lg['designation']??'') / 78) * 6);
        $x=$pdf->GetX(); $y=$pdf->GetY();
        $pdf->MultiCell(80,$h,' '.($lg['designation']??''),'LBR','L',$fill);
        $yAfter = $pdf->GetY();
        $pdf->SetXY($x+80,$y);
        $pdf->Cell(18,$yAfter-$y,number_format((float)($lg['quantite']??1),2,',',''),'LBR','0','C',$fill);
        $pdf->Cell(28,$yAfter-$y,eur((float)($lg['prix_unitaire_ht']??0)),'LBR','0','R',$fill);
        $pdf->Cell(18,$yAfter-$y,number_format($tv,1,',','').' %','LBR','0','C',$fill);
        $pdf->Cell(28,$yAfter-$y,eur($ht),'LBR','0','R',$fill);
        $pdf->Cell(0,$yAfter-$y,eur($ttc),'LBR','1','R',$fill);
        $fill = !$fill;
    }
    $pdf->Ln(4);

    // Totaux
    $pdf->SetFont('helvetica','',10);
    $pdf->SetTextColor(44,42,40);
    $rows = [['Total HT',eur((float)($f['total_ht']??0))],['Total TVA',eur((float)($f['total_tva']??0))]];
    foreach ($rows as [$lbl,$val]) {
        $pdf->SetX(120);
        $pdf->Cell(55,6,$lbl,0,0,'L');
        $pdf->Cell(0,6,$val,0,1,'R');
    }
    // Grand total TTC
    $pdf->SetFont('helvetica','B',12);
    $pdf->SetFillColor(72,120,166);
    $pdf->SetTextColor(255,255,255);
    $pdf->SetX(120);
    $pdf->Cell(55,9,' TOTAL TTC',1,0,'L',true);
    $pdf->Cell(0,9,eur((float)($f['total_ttc']??0)).' ',1,1,'R',true);
    $pdf->Ln(6);

    // Notes
    if ($f['notes']) {
        $pdf->SetFont('helvetica','I',9);
        $pdf->SetTextColor(120,120,120);
        $pdf->MultiCell(0,5,htmlspecialchars((string)$f['notes']),0,'L');
    }

    // Pied IBAN/RIB si disponible
    // (à alimenter depuis la table etablissements ou societes_rib)

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="facture_'.$f['numero'].'_'.date('Ymd').'.pdf"');
    echo $pdf->Output('','S');
    exit;
}

// ── Fallback HTML imprimable ──────────────────────────────────────────
?>
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">
<title>Facture <?= h($f['numero']) ?></title>
<style>
*{box-sizing:border-box}
body{font-family:Arial,sans-serif;max-width:800px;margin:30px auto;color:#222;font-size:13px;line-height:1.5}
.header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;padding-bottom:16px;border-bottom:3px solid #4878a6}
.etab-block{flex:1}
.etab-name{font-size:18px;font-weight:700;color:#4878a6}
.etab-info{font-size:11px;color:#888;margin-top:4px}
.fact-title{text-align:right}
.fact-title h1{font-size:28px;font-weight:900;color:#4878a6;margin:0}
.fact-num{font-size:14px;font-weight:600;color:#2c2a28}
.fact-stat{display:inline-block;padding:2px 12px;border-radius:999px;font-size:11px;font-weight:700;margin-top:4px}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin:20px 0;padding:14px;background:#f5f3ef;border-radius:8px}
.info-block .lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#9a9690;margin-bottom:4px}
.info-block .val{font-size:13px;color:#2c2a28}
table.lignes{width:100%;border-collapse:collapse;margin:20px 0}
table.lignes thead tr{background:#4878a6;color:#fff}
table.lignes th{padding:8px 10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em}
table.lignes tbody tr:nth-child(even){background:#f5f3ef}
table.lignes td{padding:8px 10px;border-bottom:1px solid var(--bg-primary,#e4e8f0)}
.totaux{display:flex;justify-content:flex-end;margin-top:8px}
.totaux table{min-width:260px}
.totaux td{padding:4px 10px}
.totaux .grand td{font-weight:700;font-size:15px;background:#4878a6;color:#fff;padding:8px 10px}
.notes{margin-top:20px;padding:12px;background:#f5f3ef;border-radius:8px;font-size:11px;color:#6a6864;font-style:italic}
@media print{body{margin:10px}button{display:none}}
</style>
</head><body>
<div style="text-align:right;margin-bottom:20px">
    <button onclick="window.print()" style="padding:8px 20px;background:#4878a6;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:12px">🖨 Imprimer / Enregistrer PDF</button>
</div>
<div class="header">
    <div class="etab-block">
        <div class="etab-name"><?= h($f['etab_nom']??'') ?></div>
        <div class="etab-info">
            <?= h(trim(($f['etab_adresse']??'').' '.($f['etab_cp']??'').' '.($f['etab_ville']??''))) ?><br>
            <?php if ($f['etab_tel']??''): ?>Tél. <?= h($f['etab_tel']) ?><?php endif; ?>
            <?php if ($f['etab_email']??''): ?> — <?= h($f['etab_email']) ?><?php endif; ?>
            <?php if ($f['etab_siret']??''): ?><br>SIRET : <?= h($f['etab_siret']) ?><?php endif; ?>
        </div>
    </div>
    <div class="fact-title">
        <h1>FACTURE</h1>
        <div class="fact-num"><?= h($f['numero']) ?></div>
        <?php
        $sc2 = ['brouillon'=>'background:var(--bg-primary,#e4e8f0);color:#9a9690','validee'=>'background:#d8e8f5;color:#4878a6','envoyee'=>'background:#f8eddc;color:#c87030','payee'=>'background:#d8eee3;color:#4a8060','annulee'=>'background:#fce8e8;color:#c84040'];
        ?>
        <span class="fact-stat" style="<?= $sc2[$f['statut']??'brouillon']??'' ?>"><?= $STAT_LBL[$f['statut']??'']??'' ?></span>
    </div>
</div>

<div class="info-grid">
    <div class="info-block">
        <div class="lbl">Facturer à</div>
        <div class="val" style="font-weight:700"><?= h($f['client']??'') ?></div>
        <?php if ($f['immeuble_txt']??''): ?><div class="val"><?= h($f['immeuble_txt']) ?></div><?php endif; ?>
    </div>
    <div class="info-block">
        <div class="lbl">Informations</div>
        <div class="val">Date d'émission : <b><?= dtFr($f['date_emission']) ?></b></div>
        <?php if ($f['date_echeance']??''): ?><div class="val">Échéance : <b><?= dtFr($f['date_echeance']) ?></b></div><?php endif; ?>
        <div class="val">Mode de paiement : <b><?= ucfirst(h($f['mode_paiement']??'')) ?></b></div>
    </div>
</div>

<table class="lignes">
    <thead><tr>
        <th style="text-align:left">Désignation</th>
        <th style="text-align:center">Qté</th>
        <th style="text-align:right">PU HT</th>
        <th style="text-align:center">TVA</th>
        <th style="text-align:right">Total HT</th>
        <th style="text-align:right">Total TTC</th>
    </tr></thead>
    <tbody>
    <?php foreach ($lignes as $lg):
        $ht  = round((float)($lg['quantite']??1)*(float)($lg['prix_unitaire_ht']??0),2);
        $tv  = (float)($lg['tva_taux']??20);
        $ttc = round($ht*(1+$tv/100),2);
    ?>
    <tr>
        <td><?= h($lg['designation']??'') ?></td>
        <td style="text-align:center"><?= number_format((float)($lg['quantite']??1),2,',',' ') ?></td>
        <td style="text-align:right"><?= eur((float)($lg['prix_unitaire_ht']??0)) ?></td>
        <td style="text-align:center"><?= number_format($tv,1,',','') ?> %</td>
        <td style="text-align:right"><?= eur($ht) ?></td>
        <td style="text-align:right;font-weight:600"><?= eur($ttc) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<div class="totaux">
    <table>
        <tr><td>Total HT</td><td style="text-align:right"><?= eur((float)($f['total_ht']??0)) ?></td></tr>
        <tr><td>Total TVA</td><td style="text-align:right"><?= eur((float)($f['total_tva']??0)) ?></td></tr>
        <tr class="grand"><td>Total TTC</td><td style="text-align:right"><?= eur((float)($f['total_ttc']??0)) ?></td></tr>
    </table>
</div>

<?php if ($f['notes']??''): ?><div class="notes"><?= nl2br(h($f['notes'])) ?></div><?php endif; ?>
<p style="margin-top:30px;font-size:10px;color:#aaa;text-align:center">Document généré le <?= date('d/m/Y à H:i') ?> — MaBoxImmo Syndic</p>
</body></html>
