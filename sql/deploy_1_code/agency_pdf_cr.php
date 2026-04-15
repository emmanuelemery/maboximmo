<?php
// agency_pdf_cr.php — PDF Compte-rendu de réunion/AG avec résolutions et votes
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
           e.email AS etab_email,
           u.nom AS auteur_nom, u.prenom AS auteur_prenom
    FROM agency_reunion r
    LEFT JOIN immeubles i ON i.id = r.id_immeuble
    LEFT JOIN etablissements e ON e.id = r.id_etablissement
    LEFT JOIN users u ON u.id = r.cree_par
    WHERE r.id = ?
");
$stmt->execute([$id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$r) { http_response_code(404); exit('Réunion introuvable'); }

$odj_stmt = $pdo->prepare("SELECT * FROM agency_reunion_odj WHERE id_reunion = ? ORDER BY ordre, id");
$odj_stmt->execute([$id]);
$odj = $odj_stmt->fetchAll(PDO::FETCH_ASSOC);

// Résolutions par id_odj
$res_stmt = $pdo->prepare("SELECT * FROM agency_reunion_resolution WHERE id_reunion = ?");
$res_stmt->execute([$id]);
$resolutions = [];
foreach ($res_stmt->fetchAll(PDO::FETCH_ASSOC) as $rv) {
    $resolutions[$rv['id_odj']] = $rv;
}

$parts_stmt = $pdo->prepare("
    SELECT u.nom, u.prenom, u.email
    FROM agency_reunion_participant rp
    JOIN users u ON u.id = rp.id_user
    WHERE rp.id_reunion = ?
    ORDER BY u.nom, u.prenom
");
$parts_stmt->execute([$id]);
$participants = $parts_stmt->fetchAll(PDO::FETCH_ASSOC);

function dtFr(?string $dt, bool $heure = true): string {
    if (!$dt) return '—';
    $ts = strtotime($dt);
    return $ts ? date($heure ? 'd/m/Y à H\hi' : 'd/m/Y', $ts) : '—';
}

// Durée séance
$duree = '';
if ($r['debut_effectif'] && $r['fin_effective']) {
    $mins = (int)round((strtotime($r['fin_effective']) - strtotime($r['debut_effectif'])) / 60);
    $duree = sprintf('%dh%02d', intdiv($mins,60), $mins%60);
}

$typeLabel = ['AG'=>'Assemblée Générale','CS'=>'Conseil Syndical','autre'=>'Réunion'][$r['type_reunion'] ?? 'autre'] ?? 'Réunion';

// ── TCPDF check ───────────────────────────────────────────────────────
$tcpdf_path = __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
$use_tcpdf  = file_exists($tcpdf_path);

if ($use_tcpdf) {
    require_once $tcpdf_path;

    class PDF_CR extends TCPDF {
        public string $etab_nom = '';
        public function Header() {
            $this->SetFont('helvetica','B',15);
            $this->SetTextColor(72,120,166);
            $this->Cell(0,8,'MaBoxImmo — Syndic',0,1,'L');
            $this->SetFont('helvetica','',9);
            $this->SetTextColor(100,100,100);
            $this->Cell(0,5,$this->etab_nom,0,1,'L');
            $this->Line(15,$this->GetY(),195,$this->GetY());
            $this->Ln(2);
        }
        public function Footer() {
            $this->SetY(-15);
            $this->SetFont('helvetica','I',8);
            $this->SetTextColor(150,150,150);
            $this->Cell(0,10,'Page '.$this->getAliasNumPage().' / '.$this->getAliasNbPages().' — Document généré le '.date('d/m/Y à H:i'),0,0,'C');
        }
    }

    $pdf = new PDF_CR('P','mm','A4');
    $pdf->etab_nom = $r['etab_nom'] ?? '';
    $pdf->SetCreator('MaBoxImmo');
    $pdf->SetTitle('Compte-rendu — '.$r['titre']);
    $pdf->SetMargins(15,25,15);
    $pdf->SetAutoPageBreak(true,20);
    $pdf->AddPage();

    // Titre
    $pdf->SetFont('helvetica','B',18);
    $pdf->SetTextColor(44,42,40);
    $pdf->MultiCell(0,10,'COMPTE-RENDU — '.$typeLabel,0,'C');
    $pdf->Ln(2);
    $pdf->SetFont('helvetica','B',13);
    $pdf->SetTextColor(72,120,166);
    $pdf->MultiCell(0,8,$r['titre'],0,'C');
    $pdf->Ln(4);

    // Infos séance
    $pdf->SetFillColor(232,228,222);
    $pdf->SetFont('helvetica','',10);
    $pdf->SetTextColor(44,42,40);
    $rows = [
        ['Date' , dtFr($r['date_reunion'])],
        ['Début effectif', $r['debut_effectif'] ? dtFr($r['debut_effectif']) : '—'],
        ['Fin effective',  $r['fin_effective']  ? dtFr($r['fin_effective'])  : '—'],
    ];
    if ($duree) $rows[] = ['Durée', $duree];
    if ($r['lieu']) $rows[] = ['Lieu', $r['lieu']];
    if ($r['imm_nom']) $rows[] = ['Immeuble', $r['imm_nom'].' ('.$r['imm_ref'].')'];
    $rows[] = ['Présents', count($participants).' participant(s)'];

    foreach ($rows as [$lbl,$val]) {
        $pdf->SetFillColor(232,228,222);
        $pdf->Cell(55,6,' '.$lbl.' :','','',' ',true);
        $pdf->Cell(0,6,' '.htmlspecialchars($val),'',1,' ',false);
    }
    $pdf->Ln(6);

    // Résolutions
    $pdf->SetFont('helvetica','B',12);
    $pdf->SetTextColor(44,42,40);
    $pdf->Cell(0,8,'RÉSOLUTIONS',0,1,'L');
    $pdf->SetDrawColor(72,120,166);
    $pdf->SetLineWidth(0.5);
    $pdf->Line(15,$pdf->GetY(),195,$pdf->GetY());
    $pdf->Ln(4);

    foreach ($odj as $i => $pt) {
        $res = $resolutions[$pt['id']] ?? null;
        $isDone = (bool)$pt['traite'];
        // Numéro + intitulé
        $pdf->SetFont('helvetica','B',10);
        $pdf->SetTextColor(72,120,166);
        $pdf->Cell(10,6,($i+1).'.',0,'');
        $pdf->SetTextColor(44,42,40);
        $pdf->MultiCell(0,6,htmlspecialchars($pt['intitule']),0,'L');
        $pdf->SetX(25);
        if ($res) {
            // Vote
            $pdf->SetFont('helvetica','',9);
            $pdf->SetTextColor(60,60,60);
            if ($res['texte']) {
                $pdf->SetX(22);
                $pdf->MultiCell(0,5,htmlspecialchars($res['texte']),0,'L');
            }
            $pdf->SetX(22);
            $pdf->SetFont('helvetica','B',9);
            $adopteTxt = $res['adopte'] ? 'ADOPTÉE' : 'REJETÉE';
            $adopteColor = $res['adopte'] ? [42,96,64] : [200,64,64];
            $pdf->SetTextColor(...$adopteColor);
            $pdf->Cell(30,5,$adopteTxt,'','',' ');
            $pdf->SetTextColor(40,128,96);
            $pdf->Cell(28,5,'Pour : '.$res['vote_pour'],'','',' ');
            $pdf->SetTextColor(200,64,64);
            $pdf->Cell(28,5,'Contre : '.$res['vote_contre'],'','',' ');
            $pdf->SetTextColor(128,128,128);
            $pdf->Cell(0,5,'Abstention : '.$res['vote_abstention'],'',1,' ');
            $pdf->SetTextColor(44,42,40);
        } elseif ($isDone) {
            $pdf->SetFont('helvetica','I',9);
            $pdf->SetTextColor(100,100,100);
            $pdf->SetX(22);
            $pdf->Cell(0,5,'(Point traité — pas de vote enregistré)',0,1);
        } else {
            $pdf->SetFont('helvetica','I',9);
            $pdf->SetTextColor(180,180,180);
            $pdf->SetX(22);
            $pdf->Cell(0,5,'(Point non traité)',0,1);
        }
        $pdf->SetTextColor(44,42,40);
        $pdf->Ln(2);
    }

    // Participants
    if (!empty($participants)) {
        $pdf->AddPage();
        $pdf->SetFont('helvetica','B',12);
        $pdf->SetTextColor(44,42,40);
        $pdf->Cell(0,8,'LISTE DES PRÉSENTS ('.count($participants).' personnes)',0,1,'L');
        $pdf->Line(15,$pdf->GetY(),195,$pdf->GetY());
        $pdf->Ln(3);
        $pdf->SetFont('helvetica','',10);
        foreach ($participants as $p) {
            $nom = trim(($p['prenom']??'').' '.($p['nom']??''));
            $pdf->Cell(90,6,'• '.htmlspecialchars($nom),0,'');
            $pdf->Cell(90,6,htmlspecialchars($p['email']??''),0,1);
        }
    }

    // Clôture
    $pdf->Ln(12);
    $pdf->SetFont('helvetica','I',10);
    $pdf->SetTextColor(100,100,100);
    $pdf->MultiCell(0,6,'La séance a été levée '.($r['fin_effective'] ? dtFr($r['fin_effective']) : 'sans heure de clôture enregistrée').($duree ? ' (durée : '.$duree.')' : '').'.',0,'L');
    $pdf->Ln(10);
    $pdf->SetTextColor(44,42,40);
    $pdf->SetFont('helvetica','',9);
    $pdf->Cell(90,5,'Le Président de séance,',0,'');
    $pdf->Cell(90,5,'Le Secrétaire de séance,',0,1);
    $pdf->Ln(14);
    $pdf->Cell(90,5,'_______________________',0,'');
    $pdf->Cell(90,5,'_______________________',0,1);

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="cr_reunion_'.$id.'_'.date('Ymd').'.pdf"');
    echo $pdf->Output('cr_reunion_'.$id.'_'.date('Ymd').'.pdf','S');
    exit;
}

// ── Fallback HTML imprimable ──────────────────────────────────────────
?>
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">
<title>Compte-rendu — <?= htmlspecialchars($r['titre']) ?></title>
<style>
body{font-family:Georgia,serif;max-width:760px;margin:40px auto;color:#222;font-size:13px;line-height:1.6}
h1{font-size:22px;color:#4878a6;text-align:center}
h2{font-size:15px;text-align:center;font-weight:400;color:#555;margin-top:4px}
.info-block{background:#f5f3ef;border-radius:8px;padding:12px 18px;margin:20px 0;display:grid;grid-template-columns:140px 1fr;gap:4px 0}
.info-label{font-weight:700;color:#555}
h3{font-size:14px;border-bottom:2px solid #4878a6;padding-bottom:4px;margin-top:24px;color:#2c2a28}
.res-block{margin:10px 0 16px;padding:10px 14px;border-radius:8px;background:#f8f6f2;border-left:4px solid #4878a6}
.res-block.adopted{border-color:#3a8050}
.res-block.rejected{border-color:#c84040}
.vote-row{display:flex;gap:20px;margin-top:6px;font-size:12px}
.v-pour{color:#3a8050;font-weight:700}
.v-contre{color:#c84040;font-weight:700}
.v-abst{color:#888;font-weight:700}
.verdict{font-weight:700;font-size:13px;margin-bottom:4px}
.adopted .verdict{color:#3a8050}
.rejected .verdict{color:#c84040}
.parts{columns:2;gap:20px;margin-top:8px;font-size:12px}
.parts p{margin:2px 0}
.sign{display:flex;gap:80px;margin-top:40px}
.sign div{flex:1;border-top:1px solid #999;padding-top:6px;font-size:11px;color:#666}
@media print{body{margin:10px}button{display:none}}
</style>
</head><body>
<div style="text-align:right;margin-bottom:20px">
    <button onclick="window.print()" style="padding:8px 20px;background:#4878a6;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:12px">🖨 Imprimer / PDF</button>
</div>
<p style="text-align:center;font-size:11px;color:#888;text-transform:uppercase;letter-spacing:.1em">MaBoxImmo — Syndic · <?= htmlspecialchars($r['etab_nom']??'') ?></p>
<h1>COMPTE-RENDU — <?= htmlspecialchars($typeLabel) ?></h1>
<h2><?= htmlspecialchars($r['titre']) ?></h2>

<div class="info-block">
    <span class="info-label">Date</span><span><?= dtFr($r['date_reunion']) ?></span>
    <?php if ($r['debut_effectif']): ?><span class="info-label">Début effectif</span><span><?= dtFr($r['debut_effectif']) ?></span><?php endif; ?>
    <?php if ($r['fin_effective']): ?><span class="info-label">Fin effective</span><span><?= dtFr($r['fin_effective']) ?></span><?php endif; ?>
    <?php if ($duree): ?><span class="info-label">Durée</span><span><?= htmlspecialchars($duree) ?></span><?php endif; ?>
    <?php if ($r['lieu']): ?><span class="info-label">Lieu</span><span><?= htmlspecialchars($r['lieu']) ?></span><?php endif; ?>
    <?php if ($r['imm_nom']): ?><span class="info-label">Immeuble</span><span><?= htmlspecialchars($r['imm_nom'].' ('.$r['imm_ref'].')') ?></span><?php endif; ?>
    <span class="info-label">Présents</span><span><?= count($participants) ?> participant(s)</span>
</div>

<h3>Résolutions</h3>
<?php foreach ($odj as $i => $pt):
    $res = $resolutions[$pt['id']] ?? null;
    $cls = $res ? ($res['adopte'] ? 'adopted' : 'rejected') : '';
?>
<div class="res-block <?= $cls ?>">
    <div style="font-weight:700;margin-bottom:4px;color:#2c2a28"><?= ($i+1) ?>. <?= htmlspecialchars($pt['intitule']) ?></div>
    <?php if ($res): ?>
        <?php if ($res['texte']): ?><div style="font-size:12px;color:#555;margin-bottom:6px"><?= nl2br(htmlspecialchars($res['texte'])) ?></div><?php endif; ?>
        <div class="verdict"><?= $res['adopte'] ? '✅ RÉSOLUTION ADOPTÉE' : '❌ RÉSOLUTION REJETÉE' ?></div>
        <div class="vote-row">
            <span class="v-pour">✓ Pour : <?= (int)$res['vote_pour'] ?></span>
            <span class="v-contre">✗ Contre : <?= (int)$res['vote_contre'] ?></span>
            <span class="v-abst">○ Abstention : <?= (int)$res['vote_abstention'] ?></span>
        </div>
    <?php elseif ($pt['traite']): ?>
        <div style="font-style:italic;color:#888;font-size:12px">Point traité — pas de vote enregistré</div>
    <?php else: ?>
        <div style="font-style:italic;color:#bbb;font-size:12px">Point non traité</div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php if (!empty($participants)): ?>
<h3>Présents (<?= count($participants) ?> personnes)</h3>
<div class="parts"><?php foreach ($participants as $p): ?><p>• <?= htmlspecialchars(trim($p['prenom'].' '.$p['nom'])) ?></p><?php endforeach; ?></div>
<?php endif; ?>

<p style="margin-top:20px;font-style:italic;color:#666">
    La séance a été levée <?= dtFr($r['fin_effective']) ?><?= $duree ? ' (durée : '.$duree.')' : '' ?>.
</p>
<div class="sign"><div>Le Président de séance</div><div>Le Secrétaire de séance</div></div>
<p style="margin-top:40px;font-size:10px;color:#aaa;text-align:center">Document généré le <?= date('d/m/Y à H:i') ?> — MaBoxImmo</p>
</body></html>
