<?php
// agency_pdf_convocation.php — PDF Convocation de réunion/AG
// Génère un PDF professionnel avec : en-tête, détails réunion, ODJ, liste participants
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();
$pdo = $GLOBALS['pdo'] ?? db();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { header('Location: agency_reunions.php'); exit; }

// ── Data ──────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT r.*,
           i.nom_immeuble AS imm_nom, i.reference_immeuble AS imm_ref, i.adresse_1 AS imm_adresse,
           i.code_postal, i.ville AS imm_ville, i.nb_lots,
           e.nom AS etab_nom, e.adresse AS etab_adresse, e.telephone AS etab_tel,
           e.email AS etab_email, e.siret AS etab_siret
    FROM agency_reunion r
    LEFT JOIN immeubles i ON i.id = r.id_immeuble
    LEFT JOIN etablissements e ON e.id = r.id_etablissement
    WHERE r.id = ?
");
$stmt->execute([$id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$r) { http_response_code(404); exit('Réunion introuvable'); }

$odj_stmt = $pdo->prepare("SELECT * FROM agency_reunion_odj WHERE id_reunion = ? ORDER BY ordre, id");
$odj_stmt->execute([$id]);
$odj = $odj_stmt->fetchAll(PDO::FETCH_ASSOC);

$parts_stmt = $pdo->prepare("
    SELECT u.nom, u.prenom, u.email
    FROM agency_reunion_participant rp
    JOIN users u ON u.id = rp.id_user
    WHERE rp.id_reunion = ?
    ORDER BY u.nom, u.prenom
");
$parts_stmt->execute([$id]);
$participants = $parts_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── TCPDF / FPDF check ────────────────────────────────────────────────
$tcpdf_path = __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
$fpdf_path  = __DIR__ . '/../vendor/fpdf/fpdf.php';
$use_tcpdf  = file_exists($tcpdf_path);
$use_fpdf   = !$use_tcpdf && file_exists($fpdf_path);

if (!$use_tcpdf && !$use_fpdf) {
    // Fallback HTML imprimable
    fallbackHtml($r, $odj, $participants);
    exit;
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function dtFr(?string $dt, bool $heure = true): string {
    if (!$dt) return '—';
    $ts = strtotime($dt);
    return $ts ? date($heure ? 'd/m/Y à H\hi' : 'd/m/Y', $ts) : '—';
}

if ($use_tcpdf) {
    require_once $tcpdf_path;

    class PDF_Convocation extends TCPDF {
        public $etab_nom = '';
        public function Header() {
            $this->SetFont('helvetica','B',16);
            $this->SetTextColor(72,120,166);
            $this->Cell(0,8,'MaBoxImmo — Syndic',0,1,'L');
            $this->SetFont('helvetica','',9);
            $this->SetTextColor(100,100,100);
            $this->Cell(0,5,$this->etab_nom,0,1,'L');
            $this->Line(15,$this->GetY(),195,$this->GetY());
            $this->Ln(3);
        }
        public function Footer() {
            $this->SetY(-15);
            $this->SetFont('helvetica','I',8);
            $this->SetTextColor(150,150,150);
            $this->Cell(0,10,'Page '.$this->getAliasNumPage().' / '.$this->getAliasNbPages().' — Document généré le '.date('d/m/Y à H:i'),0,0,'C');
        }
    }

    $pdf = new PDF_Convocation('P','mm','A4');
    $pdf->etab_nom = $r['etab_nom'] ?? '';
    $pdf->SetCreator('MaBoxImmo');
    $pdf->SetAuthor($r['etab_nom'] ?? 'MaBoxImmo');
    $pdf->SetTitle('Convocation — '.$r['titre']);
    $pdf->SetMargins(15,25,15);
    $pdf->SetAutoPageBreak(true,20);
    $pdf->AddPage();

    // Titre
    $pdf->SetFont('helvetica','B',18);
    $pdf->SetTextColor(44,42,40);
    $typeLabel = ['AG'=>'Assemblée Générale','CS'=>'Conseil Syndical','autre'=>'Réunion'][$r['type_reunion'] ?? 'autre'] ?? 'Réunion';
    $pdf->MultiCell(0,10,'CONVOCATION — '.$typeLabel,0,'C');
    $pdf->Ln(2);

    // Sous-titre réunion
    $pdf->SetFont('helvetica','B',13);
    $pdf->SetTextColor(72,120,166);
    $pdf->MultiCell(0,8,$r['titre'],0,'C');
    $pdf->Ln(4);

    // Bloc info
    $pdf->SetFillColor(232,228,222);
    $pdf->SetFont('helvetica','',10);
    $pdf->SetTextColor(44,42,40);
    $pdf->SetLineWidth(0.3);

    $info_html = '<table border="0" cellpadding="4" style="font-family:helvetica;font-size:10pt;background-color:var(--bg-primary,#e4e8f0)">';
    $info_html .= '<tr><td width="35%"><b>Date et heure :</b></td><td>'.dtFr($r['date_reunion']).'</td></tr>';
    if ($r['lieu']) $info_html .= '<tr><td><b>Lieu :</b></td><td>'.htmlspecialchars($r['lieu']).'</td></tr>';
    if ($r['imm_nom']) {
        $info_html .= '<tr><td><b>Immeuble :</b></td><td>'.htmlspecialchars($r['imm_nom']).' ('.$r['imm_ref'].')</td></tr>';
        if ($r['imm_adresse']) $info_html .= '<tr><td><b>Adresse :</b></td><td>'.htmlspecialchars($r['imm_adresse'].', '.$r['code_postal'].' '.$r['imm_ville']).'</td></tr>';
        $info_html .= '<tr><td><b>Lots :</b></td><td>'.(int)$r['nb_lots'].' lots</td></tr>';
    }
    $info_html .= '</table>';
    $pdf->writeHTML($info_html, true, false, false, false, '');
    $pdf->Ln(6);

    // ODJ
    $pdf->SetFont('helvetica','B',12);
    $pdf->SetTextColor(44,42,40);
    $pdf->Cell(0,8,'ORDRE DU JOUR',0,1,'L');
    $pdf->SetLineWidth(0.5);
    $pdf->SetDrawColor(72,120,166);
    $pdf->Line(15,$pdf->GetY(),195,$pdf->GetY());
    $pdf->Ln(3);
    $pdf->SetFont('helvetica','',10);
    foreach ($odj as $i => $pt) {
        $pdf->SetFillColor($i%2===0?232:237, $i%2===0?228:233, $i%2===0?222:227);
        $pdf->Cell(10,7,($i+1).'.',1,'',true);
        $pdf->Cell(0,7,' '.htmlspecialchars($pt['intitule']),1,1,'',true);
    }
    $pdf->Ln(6);

    // Participants
    if (!empty($participants)) {
        $pdf->SetFont('helvetica','B',12);
        $pdf->SetTextColor(44,42,40);
        $pdf->Cell(0,8,'LISTE DES CONVOQUÉS ('.count($participants).' personnes)',0,1,'L');
        $pdf->Line(15,$pdf->GetY(),195,$pdf->GetY());
        $pdf->Ln(3);
        $pdf->SetFont('helvetica','',9);
        $cols = array_chunk($participants, (int)ceil(count($participants)/2));
        $pdf->Ln(2);
        foreach (array_map(null, $cols[0]??[], $cols[1]??[]) as [$a,$b]) {
            $pdf->Cell(85,6,'• '.htmlspecialchars(trim(($a['prenom']??'').' '.($a['nom']??''))),0,'');
            $pdf->Cell(85,6,$b ? '• '.htmlspecialchars(trim(($b['prenom']??'').' '.($b['nom']??''))) : '',0,1);
        }
    }

    // Commentaire
    if ($r['commentaire']) {
        $pdf->Ln(4);
        $pdf->SetFont('helvetica','BI',10);
        $pdf->SetTextColor(100,100,100);
        $pdf->MultiCell(0,6,htmlspecialchars($r['commentaire']),0,'L');
    }

    // Signature bloc
    $pdf->Ln(10);
    $pdf->SetFont('helvetica','',9);
    $pdf->SetTextColor(44,42,40);
    $pdf->Cell(90,5,'Fait par le Syndic,',0,'');
    $pdf->Cell(90,5,'Le gestionnaire,',0,1);
    $pdf->Ln(14);
    $pdf->Cell(90,5,'_______________________',0,'');
    $pdf->Cell(90,5,'_______________________',0,1);

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="convocation_'.$id.'_'.date('Ymd').'.pdf"');
    echo $pdf->Output('convocation_'.$id.'_'.date('Ymd').'.pdf','S');
    exit;
}

// ── Fallback HTML imprimable ──────────────────────────────────────────
function fallbackHtml(array $r, array $odj, array $participants): void {
    $dtFr = fn($dt,$h=true) => $dt ? date($h?'d/m/Y à H\hi':'d/m/Y',strtotime($dt)) : '—';
    $typeLabel = ['AG'=>'Assemblée Générale','CS'=>'Conseil Syndical','autre'=>'Réunion'][$r['type_reunion']??'autre']??'Réunion';
?>
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">
<title>Convocation — <?= htmlspecialchars($r['titre']) ?></title>
<style>
body{font-family:Georgia,serif;max-width:760px;margin:40px auto;color:#222;font-size:13px}
h1{font-size:22px;color:#4878a6;text-align:center;margin-bottom:4px}
h2{font-size:15px;color:#333;text-align:center;margin-bottom:20px;font-weight:400}
.info-block{background:#f5f3ef;border-radius:8px;padding:14px 18px;margin-bottom:20px}
.info-row{display:flex;gap:16px;margin-bottom:5px}
.info-label{font-weight:700;min-width:130px;color:#555}
h3{font-size:14px;border-bottom:2px solid #4878a6;padding-bottom:4px;color:#2c2a28}
ol li{margin-bottom:6px;line-height:1.6}
.parts{columns:2;gap:20px;margin-top:8px}
.parts p{margin:2px 0}
.sign{display:flex;gap:80px;margin-top:40px}
.sign div{flex:1;border-top:1px solid #999;padding-top:6px;font-size:11px;color:#666}
@media print{body{margin:20px}button{display:none}}
</style>
</head><body>
<div style="text-align:right;margin-bottom:20px"><button onclick="window.print()" style="padding:8px 20px;background:#4878a6;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:12px">🖨 Imprimer / PDF</button></div>
<p style="text-align:center;font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.1em">MaBoxImmo — Syndic · <?= htmlspecialchars($r['etab_nom']??'') ?></p>
<h1>CONVOCATION — <?= htmlspecialchars($typeLabel) ?></h1>
<h2><?= htmlspecialchars($r['titre']) ?></h2>
<div class="info-block">
    <div class="info-row"><span class="info-label">Date et heure :</span><span><?= $dtFr($r['date_reunion']) ?></span></div>
    <?php if ($r['lieu']): ?><div class="info-row"><span class="info-label">Lieu :</span><span><?= htmlspecialchars($r['lieu']) ?></span></div><?php endif; ?>
    <?php if ($r['imm_nom']): ?>
    <div class="info-row"><span class="info-label">Immeuble :</span><span><?= htmlspecialchars($r['imm_nom']) ?> (<?= htmlspecialchars($r['imm_ref']) ?>)</span></div>
    <?php if ($r['imm_adresse']): ?><div class="info-row"><span class="info-label">Adresse :</span><span><?= htmlspecialchars($r['imm_adresse'].', '.$r['code_postal'].' '.$r['imm_ville']) ?></span></div><?php endif; ?>
    <div class="info-row"><span class="info-label">Nombre de lots :</span><span><?= (int)$r['nb_lots'] ?> lots</span></div>
    <?php endif; ?>
</div>
<?php if (!empty($odj)): ?>
<h3>Ordre du jour</h3>
<ol><?php foreach ($odj as $pt): ?><li><?= htmlspecialchars($pt['intitule']) ?></li><?php endforeach; ?></ol>
<?php endif; ?>
<?php if (!empty($participants)): ?>
<h3 style="margin-top:20px">Convoqués (<?= count($participants) ?> personnes)</h3>
<div class="parts"><?php foreach ($participants as $p): ?><p>• <?= htmlspecialchars(trim($p['prenom'].' '.$p['nom'])) ?></p><?php endforeach; ?></div>
<?php endif; ?>
<?php if ($r['commentaire']): ?><p style="margin-top:16px;font-style:italic;color:#555"><?= nl2br(htmlspecialchars($r['commentaire'])) ?></p><?php endif; ?>
<div class="sign"><div>Le Syndic</div><div>Le gestionnaire</div></div>
<p style="margin-top:40px;font-size:10px;color:#aaa;text-align:center">Document généré le <?= date('d/m/Y à H:i') ?> — MaBoxImmo</p>
</body></html>
<?php
}

fallbackHtml($r, $odj, $participants);
