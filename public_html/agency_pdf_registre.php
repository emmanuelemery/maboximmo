<?php
// agency_pdf_registre.php — PDF d'un mandat ou du registre complet
require_once __DIR__ . '/inc/init.php';
require_login();

$id       = (int)($_GET['id']      ?? 0);     // PDF d'un seul mandat
$registre = isset($_GET['registre']);          // PDF registre complet
$etab_flt = (int)($_GET['etab']    ?? 0);
$role_id  = (int)current_role_id();
$etab_id  = (int)($_SESSION['etablissement_id'] ?? 0);

// ── Chargement données ────────────────────────────────────────────────────────
function loadMandat(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare("
        SELECT m.*,
               i.nom_immeuble AS imm_nom, i.reference_immeuble AS imm_ref, i.adresse_1 AS imm_adr, i.ville AS imm_ville,
               e.nom AS etab_nom, e.adresse AS etab_adr, e.code_postal AS etab_cp,
               e.ville AS etab_ville, e.telephone AS etab_tel, e.email AS etab_email,
               e.siret AS etab_siret, e.logo AS etab_logo
        FROM agency_mandat m
        LEFT JOIN immeubles i ON i.id = m.id_immeuble
        LEFT JOIN etablissements e ON e.id = m.id_etablissement
        WHERE m.id = ?
    ");
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

function loadRegistre(PDO $pdo, int $etab_id, int $role_id, int $sess_etab): array {
    $where = '1=1';
    $params = [];
    if ($etab_id) { $where .= ' AND m.id_etablissement=?'; $params[] = $etab_id; }
    elseif ($role_id !== 1 && $sess_etab) { $where .= ' AND m.id_etablissement=?'; $params[] = $sess_etab; }
    $s = $pdo->prepare("
        SELECT m.*,
               i.nom_immeuble AS imm_nom,
               e.nom AS etab_nom, NULL AS etab_sigle
        FROM agency_mandat m
        LEFT JOIN immeubles i ON i.id = m.id_immeuble
        LEFT JOIN etablissements e ON e.id = m.id_etablissement
        WHERE $where
        ORDER BY m.numero_registre ASC
    ");
    $s->execute($params);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function loadAvenants(PDO $pdo, int $id): array {
    $s = $pdo->prepare("SELECT * FROM agency_mandat_avenant WHERE id_mandat=? ORDER BY numero");
    $s->execute([$id]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function fmtD(?string $d): string { return $d ? date('d/m/Y', strtotime($d)) : '—'; }
function fmtM(float $v): string { return number_format($v,2,',',' ').' €'; }

// ── Tenter TCPDF ──────────────────────────────────────────────────────────────
$tcpdf_path = __DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf.php';
$use_tcpdf  = file_exists($tcpdf_path);
if ($use_tcpdf) require_once $tcpdf_path;

// ============================================================================
// PDF TCPDF — Un seul mandat
// ============================================================================
if ($use_tcpdf && $id && !$registre) {
    $m = loadMandat($pdo, $id);
    if (!$m) { header('Location: agency_registres.php'); exit; }
    $avenants = loadAvenants($pdo, $id);

    class PDF_Mandat extends TCPDF {
        public array $etab = [];
        public function Header() {
            $this->SetFont('helvetica','B',12);
            $this->SetTextColor(72,120,166);
            $this->Cell(0,8,$this->etab['etab_nom']??'',0,1,'L');
            if ($this->etab['etab_adr']) {
                $this->SetFont('helvetica','',8);
                $this->SetTextColor(106,104,100);
                $this->Cell(0,5,$this->etab['etab_adr'].' — '.($this->etab['etab_cp']??'').' '.($this->etab['etab_ville']??''),0,1,'L');
            }
            $this->SetDrawColor(72,120,166);
            $this->Line(15, $this->GetY()+2, 195, $this->GetY()+2);
            $this->Ln(4);
        }
        public function Footer() {
            $this->SetY(-14);
            $this->SetFont('helvetica','I',8);
            $this->SetTextColor(154,150,144);
            $this->Cell(0,6,'Registre des mandats · Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(),0,0,'C');
        }
    }

    $pdf = new PDF_Mandat('P','mm','A4',true,'UTF-8',false);
    $pdf->etab = $m;
    $pdf->SetCreator('MaBoxImmo');
    $pdf->SetTitle('Mandat '.str_pad($m['numero_registre'],4,'0',STR_PAD_LEFT));
    $pdf->SetMargins(15,30,15);
    $pdf->SetHeaderMargin(10);
    $pdf->SetFooterMargin(10);
    $pdf->AddPage();

    // Titre
    $pdf->SetFont('helvetica','B',16);
    $pdf->SetTextColor(26,24,22);
    $pdf->Cell(0,10,'MANDAT DE SYNDIC',0,1,'C');
    $pdf->SetFont('helvetica','',10);
    $pdf->SetTextColor(106,104,100);
    $pdf->Cell(0,6,'N° '.str_pad($m['numero_registre'],4,'0',STR_PAD_LEFT).' — Inscrit le '.fmtD($m['date_inscription']),0,1,'C');
    $pdf->Ln(4);

    // Bloc mandant/immeuble
    $pdf->SetFillColor(72,120,166);
    $pdf->SetTextColor(255,255,255);
    $pdf->SetFont('helvetica','B',9);
    $pdf->Cell(0,7,' MANDANT & BIEN',0,1,'L',true);
    $pdf->SetFont('helvetica','',9);
    $pdf->SetTextColor(26,24,22);

    $rows = [
        ['Dénomination du mandant', $m['mandant_nom']],
        ['Représentant / Président CS', $m['mandant_representant']??'—'],
        ['Immeuble', ($m['imm_nom']??$m['immeuble_txt']??'—').($m['imm_ref']?' ('.$m['imm_ref'].')':'')],
        ['Adresse immeuble', $m['imm_adr']?trim($m['imm_adr'].', '.$m['imm_ville']):'—'],
    ];
    foreach ($rows as [$lbl,$val]) {
        $pdf->SetFillColor(240,237,232);
        $pdf->Cell(60,6,$lbl,0,0,'L',true);
        $pdf->Cell(0,6,$val,0,1,'L',false);
    }
    $pdf->Ln(3);

    // Durée et conditions
    $pdf->SetFillColor(72,120,166);
    $pdf->SetTextColor(255,255,255);
    $pdf->SetFont('helvetica','B',9);
    $pdf->Cell(0,7,' DURÉE ET CONDITIONS',0,1,'L',true);
    $pdf->SetFont('helvetica','',9);
    $pdf->SetTextColor(26,24,22);

    $renew = ['tacite'=>'Tacite reconduction','express'=>'Exprès','sans'=>'Sans renouvellement'];
    $rows2 = [
        ['Type de mandat', strtoupper($m['type_mandat'])],
        ['Statut', strtoupper($m['statut'])],
        ['Date de début', fmtD($m['date_debut'])],
        ['Date de fin', $m['date_fin']?fmtD($m['date_fin']):'Indéfinie'],
        ['Durée', $m['duree_mois']?$m['duree_mois'].' mois':'—'],
        ['Renouvellement', $renew[$m['renouvellement']??'tacite']??'—'],
        ['Préavis', $m['preavis_mois'].' mois'],
    ];
    $fill = false;
    foreach ($rows2 as [$lbl,$val]) {
        $pdf->SetFillColor($fill?240:248,237,232);
        $pdf->Cell(60,6,$lbl,0,0,'L',$fill);
        $pdf->Cell(0,6,$val,0,1,'L',false);
        $fill = !$fill;
    }
    $pdf->Ln(3);

    // Honoraires
    $ttc = (float)$m['honoraires_ht'] * (1 + (float)$m['tva_pct']/100);
    $pdf->SetFillColor(72,120,166);
    $pdf->SetTextColor(255,255,255);
    $pdf->SetFont('helvetica','B',9);
    $pdf->Cell(0,7,' HONORAIRES ANNUELS',0,1,'L',true);
    $pdf->SetFont('helvetica','',9);
    $pdf->SetTextColor(26,24,22);
    $pdf->Cell(60,6,'Honoraires HT',0,0,'L',false);
    $pdf->Cell(0,6,fmtM((float)$m['honoraires_ht']),0,1,'L',false);
    $pdf->Cell(60,6,'TVA ('.$m['tva_pct'].'%)',0,0,'L',false);
    $pdf->Cell(0,6,fmtM($ttc-(float)$m['honoraires_ht']),0,1,'L',false);
    $pdf->SetFont('helvetica','B',10);
    $pdf->SetFillColor(72,120,166);
    $pdf->SetTextColor(255,255,255);
    $pdf->Cell(60,7,'TOTAL TTC',0,0,'L',true);
    $pdf->Cell(0,7,fmtM($ttc),0,1,'L',true);
    $pdf->Ln(3);

    // Conditions particulières
    if ($m['conditions']) {
        $pdf->SetFont('helvetica','B',9);
        $pdf->SetTextColor(72,120,166);
        $pdf->Cell(0,6,'CONDITIONS PARTICULIÈRES',0,1);
        $pdf->SetFont('helvetica','',9);
        $pdf->SetTextColor(26,24,22);
        $pdf->MultiCell(0,5,$m['conditions'],0,'L');
        $pdf->Ln(2);
    }

    // Avenants
    if ($avenants) {
        $pdf->SetFont('helvetica','B',9);
        $pdf->SetFillColor(72,120,166);
        $pdf->SetTextColor(255,255,255);
        $pdf->Cell(0,7,' AVENANTS',0,1,'L',true);
        $pdf->SetTextColor(26,24,22);
        foreach ($avenants as $av) {
            $pdf->SetFont('helvetica','B',9);
            $pdf->Cell(0,5,'Avenant n°'.$av['numero'].' — '.fmtD($av['date_avenant']).' — '.$av['objet'],0,1);
            if ($av['contenu']) {
                $pdf->SetFont('helvetica','',8);
                $pdf->SetTextColor(106,104,100);
                $pdf->MultiCell(0,5,$av['contenu'],0,'L');
                $pdf->SetTextColor(26,24,22);
            }
            $pdf->Ln(1);
        }
    }

    // Signature
    $pdf->Ln(10);
    $pdf->SetFont('helvetica','',9);
    $pdf->SetTextColor(106,104,100);
    $pdf->Cell(0,5,'Fait le '.fmtD($m['date_inscription']).' — '.($m['etab_nom']??''),0,1,'C');
    $pdf->Ln(10);
    $pdf->Cell(85,5,'Signature du mandant',0,0,'C');
    $pdf->Cell(0,5,'Signature du syndic',0,1,'C');
    $pdf->Ln(15);
    $pdf->Line(20,$pdf->GetY(),85,$pdf->GetY());
    $pdf->Line(110,$pdf->GetY(),190,$pdf->GetY());

    $pdf->Output('mandat_'.str_pad($m['numero_registre'],4,'0',STR_PAD_LEFT).'.pdf','I');
    exit;
}

// ============================================================================
// PDF TCPDF — Registre complet
// ============================================================================
if ($use_tcpdf && $registre) {
    $mandats = loadRegistre($pdo, $etab_flt, $role_id, $etab_id);

    $pdf2 = new TCPDF('L','mm','A4',true,'UTF-8',false);
    $pdf2->SetCreator('MaBoxImmo');
    $pdf2->SetTitle('Registre des mandats');
    $pdf2->setPrintHeader(false);
    $pdf2->setPrintFooter(false);
    $pdf2->SetMargins(12,15,12);
    $pdf2->AddPage();

    $pdf2->SetFont('helvetica','B',16);
    $pdf2->SetTextColor(72,120,166);
    $pdf2->Cell(0,10,'REGISTRE DES MANDATS',0,1,'C');
    $pdf2->SetFont('helvetica','',9);
    $pdf2->SetTextColor(106,104,100);
    $pdf2->Cell(0,6,'Édité le '.date('d/m/Y').' — '.count($mandats).' mandat(s)',0,1,'C');
    $pdf2->Ln(4);

    // En-tête tableau
    $pdf2->SetFillColor(72,120,166);
    $pdf2->SetTextColor(255,255,255);
    $pdf2->SetFont('helvetica','B',8);
    $cols = ['N°'=>12,'Type'=>22,'Mandant'=>55,'Immeuble'=>50,'Début'=>22,'Fin'=>22,'Dur.'=>14,'Honoraires HT'=>28,'Statut'=>22];
    foreach ($cols as $lbl=>$w) $pdf2->Cell($w,7,$lbl,0,0,'C',true);
    $pdf2->Ln();

    $pdf2->SetFont('helvetica','',8);
    $fill = false;
    $sColors = ['actif'=>[45,122,74],'suspendu'=>[200,112,48],'resilie'=>[200,64,64],'expire'=>[138,96,32],'archive'=>[128,128,128]];
    foreach ($mandats as $m2) {
        $pdf2->SetFillColor($fill?240:248,237,232);
        $pdf2->SetTextColor(26,24,22);
        $pdf2->Cell(12,6,str_pad($m2['numero_registre'],4,'0',STR_PAD_LEFT),0,0,'C',$fill);
        $pdf2->Cell(22,6,strtoupper($m2['type_mandat']),0,0,'C',$fill);
        $pdf2->Cell(55,6,mb_strimwidth($m2['mandant_nom'],0,32,'…'),0,0,'L',$fill);
        $imm = mb_strimwidth($m2['imm_nom']??$m2['immeuble_txt']??'—',0,28,'…');
        $pdf2->Cell(50,6,$imm,0,0,'L',$fill);
        $pdf2->Cell(22,6,fmtD($m2['date_debut']),0,0,'C',$fill);
        $pdf2->Cell(22,6,$m2['date_fin']?fmtD($m2['date_fin']):'—',0,0,'C',$fill);
        $pdf2->Cell(14,6,$m2['duree_mois']?$m2['duree_mois'].'m':'—',0,0,'C',$fill);
        $pdf2->Cell(28,6,fmtM((float)$m2['honoraires_ht']),0,0,'R',$fill);
        [$r,$g,$b] = $sColors[$m2['statut']] ?? [128,128,128];
        $pdf2->SetTextColor($r,$g,$b);
        $pdf2->Cell(22,6,strtoupper($m2['statut']),0,0,'C',$fill);
        $pdf2->SetTextColor(26,24,22);
        $pdf2->Ln();
        $fill = !$fill;
    }
    $pdf2->Output('registre_mandats_'.date('Ymd').'.pdf','I');
    exit;
}

// ============================================================================
// FALLBACK HTML
// ============================================================================
if ($id && !$registre) {
    $m = loadMandat($pdo, $id);
    if (!$m) { header('Location: agency_registres.php'); exit; }
    $avenants = loadAvenants($pdo, $id);
    $ttc = (float)$m['honoraires_ht'] * (1 + (float)$m['tva_pct']/100);
    $renew = ['tacite'=>'Tacite reconduction','express'=>'Exprès','sans'=>'Sans renouvellement'];
    $statColors = ['actif'=>'#2d7a4a','suspendu'=>'#c87030','resilie'=>'#c84040','expire'=>'#8a6020','archive'=>'#808080'];
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Mandat <?= str_pad($m['numero_registre'],4,'0',STR_PAD_LEFT) ?></title>
<style>
@media print{.no-print{display:none}body{margin:0}}
body{font-family:Arial,sans-serif;font-size:12px;color:#222;max-width:780px;margin:20px auto;padding:20px}
.print-btn{display:inline-block;padding:8px 20px;background:#4878a6;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:700;margin-bottom:20px}
h1{font-size:22px;color:#4878a6;margin:0 0 4px}
.sub{color:#888;font-size:11px;margin-bottom:20px}
.section-title{background:#4878a6;color:#fff;padding:6px 10px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;margin:16px 0 8px;border-radius:4px}
table.info{width:100%;border-collapse:collapse}
table.info td{padding:5px 8px;border-bottom:1px solid var(--bg-primary,#e4e8f0);font-size:12px}
table.info td:first-child{font-weight:700;color:#666;width:40%;background:#f5f3ef}
.ttc-row{background:#4878a6!important;color:#fff!important;font-weight:700!important}
.ttc-row td{color:#fff!important}
.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700}
.sig-row{display:flex;gap:60px;margin-top:40px}
.sig-box{flex:1;border-top:1px solid #999;padding-top:6px;font-size:11px;color:#888}
.avenant{border:1px solid var(--bg-primary,#e4e8f0);border-radius:6px;padding:10px;margin-bottom:8px}
.av-head{font-weight:700;font-size:12px;color:#4878a6}
.av-content{font-size:11px;color:#666;margin-top:4px;white-space:pre-line}
</style>
</head>
<body>
<button class="print-btn no-print" onclick="window.print()">Imprimer / PDF</button>

<?php if ($m['etab_nom']): ?>
<div style="font-size:14px;font-weight:700;color:#4878a6"><?= htmlspecialchars($m['etab_nom']) ?></div>
<div style="font-size:11px;color:#888"><?= htmlspecialchars(trim(($m['etab_adr']??'').', '.($m['etab_cp']??'').' '.($m['etab_ville']??''))) ?></div>
<hr style="margin:10px 0;border-color:var(--bg-primary,#e4e8f0)">
<?php endif; ?>

<h1>MANDAT DE <?= strtoupper($m['type_mandat']) ?></h1>
<div class="sub">
  N° <strong><?= str_pad($m['numero_registre'],4,'0',STR_PAD_LEFT) ?></strong> —
  Inscrit le <?= fmtD($m['date_inscription']) ?> —
  <span class="badge" style="background:<?= $statColors[$m['statut']]??'#808080' ?>22;color:<?= $statColors[$m['statut']]??'#808080' ?>"><?= strtoupper($m['statut']) ?></span>
</div>

<div class="section-title">Mandant &amp; Bien</div>
<table class="info">
  <tr><td>Dénomination</td><td><?= htmlspecialchars($m['mandant_nom']) ?></td></tr>
  <tr><td>Représentant</td><td><?= htmlspecialchars($m['mandant_representant']??'—') ?></td></tr>
  <tr><td>Immeuble</td><td><?= htmlspecialchars(($m['imm_nom']??$m['immeuble_txt']??'—').($m['imm_ref']?' ('.$m['imm_ref'].')':'')) ?></td></tr>
  <tr><td>Adresse immeuble</td><td><?= htmlspecialchars(trim(($m['imm_adr']??'').', '.($m['imm_ville']??''))) ?></td></tr>
</table>

<div class="section-title">Durée et conditions</div>
<table class="info">
  <tr><td>Date de début</td><td><?= fmtD($m['date_debut']) ?></td></tr>
  <tr><td>Date de fin</td><td><?= $m['date_fin'] ? fmtD($m['date_fin']) : 'Indéfinie' ?></td></tr>
  <tr><td>Durée</td><td><?= $m['duree_mois'] ? $m['duree_mois'].' mois' : '—' ?></td></tr>
  <tr><td>Renouvellement</td><td><?= $renew[$m['renouvellement']??'tacite'] ?></td></tr>
  <tr><td>Préavis</td><td><?= $m['preavis_mois'] ?> mois</td></tr>
</table>

<div class="section-title">Honoraires annuels</div>
<table class="info">
  <tr><td>Honoraires HT</td><td><?= fmtM((float)$m['honoraires_ht']) ?></td></tr>
  <tr><td>TVA (<?= $m['tva_pct'] ?>%)</td><td><?= fmtM($ttc-(float)$m['honoraires_ht']) ?></td></tr>
  <tr class="ttc-row"><td>TOTAL TTC</td><td><?= fmtM($ttc) ?></td></tr>
</table>

<?php if ($m['conditions']): ?>
<div class="section-title">Conditions particulières</div>
<div style="font-size:12px;white-space:pre-line;padding:8px;background:#f5f3ef;border-radius:6px"><?= htmlspecialchars($m['conditions']) ?></div>
<?php endif; ?>

<?php if ($avenants): ?>
<div class="section-title">Avenants (<?= count($avenants) ?>)</div>
<?php foreach ($avenants as $av): ?>
<div class="avenant">
  <div class="av-head">Avenant n°<?= $av['numero'] ?> — <?= fmtD($av['date_avenant']) ?> — <?= htmlspecialchars($av['objet']) ?></div>
  <?php if ($av['contenu']): ?><div class="av-content"><?= htmlspecialchars($av['contenu']) ?></div><?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<div class="sig-row">
  <div class="sig-box">Signature du mandant<br><?= htmlspecialchars($m['mandant_nom']) ?></div>
  <div class="sig-box">Signature du syndic<br><?= htmlspecialchars($m['etab_nom']??'') ?></div>
</div>
<div style="margin-top:30px;text-align:center;font-size:10px;color:#bbb">MaBoxImmo — Registre des mandats</div>
</body></html>
<?php
    exit;
}

// ── Fallback registre complet HTML ────────────────────────────────────────────
$mandats = loadRegistre($pdo, $etab_flt, $role_id, $etab_id);
$statColors = ['actif'=>['#2d7a4a','#e8f5ee'],'suspendu'=>['#c87030','#fff3e0'],'resilie'=>['#c84040','#fdecea'],'expire'=>['#8a6020','#fff8e1'],'archive'=>['#808080','#f0f0f0']];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Registre des mandats</title>
<style>
@media print{.no-print{display:none}body{margin:0}}
body{font-family:Arial,sans-serif;font-size:11px;color:#222;padding:20px;max-width:1100px;margin:auto}
.print-btn{display:inline-block;padding:8px 20px;background:#4878a6;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:700;margin-bottom:20px}
h1{font-size:20px;color:#4878a6;margin:0 0 4px}
.sub{color:#888;font-size:11px;margin-bottom:16px}
table{width:100%;border-collapse:collapse;font-size:11px}
thead th{background:#4878a6;color:#fff;padding:7px 8px;text-align:left;font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:.05em}
tbody tr:nth-child(even){background:#f5f3ef}
tbody td{padding:6px 8px;border-bottom:1px solid var(--bg-primary,#e4e8f0);vertical-align:middle}
.badge{display:inline-block;padding:2px 7px;border-radius:20px;font-size:10px;font-weight:700}
.num{font-weight:700;color:#4878a6;font-family:monospace}
</style>
</head>
<body>
<button class="print-btn no-print" onclick="window.print()">Imprimer / PDF</button>
<h1>REGISTRE DES MANDATS</h1>
<div class="sub">Édité le <?= date('d/m/Y') ?> — <?= count($mandats) ?> mandat(s)</div>
<table>
<thead>
<tr>
  <th>N°</th><th>Type</th><th>Mandant</th><th>Immeuble</th>
  <th>Début</th><th>Fin</th><th>Durée</th><th>Honoraires HT</th><th>Statut</th>
</tr>
</thead>
<tbody>
<?php foreach ($mandats as $m):
  [$tc,$bg] = $statColors[$m['statut']] ?? ['#808080','#f0f0f0'];
?>
<tr>
  <td class="num"><?= str_pad($m['numero_registre'],4,'0',STR_PAD_LEFT) ?></td>
  <td><?= ucfirst($m['type_mandat']) ?></td>
  <td><?= htmlspecialchars($m['mandant_nom']) ?></td>
  <td><?= htmlspecialchars($m['imm_nom']??$m['immeuble_txt']??'—') ?></td>
  <td><?= fmtD($m['date_debut']) ?></td>
  <td><?= $m['date_fin'] ? fmtD($m['date_fin']) : '—' ?></td>
  <td><?= $m['duree_mois'] ? $m['duree_mois'].'m' : '—' ?></td>
  <td style="text-align:right"><?= fmtM((float)$m['honoraires_ht']) ?></td>
  <td><span class="badge" style="background:<?= $bg ?>;color:<?= $tc ?>"><?= ucfirst($m['statut']) ?></span></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<div style="margin-top:20px;text-align:center;font-size:10px;color:#bbb">MaBoxImmo — Registre des mandats</div>
</body></html>
