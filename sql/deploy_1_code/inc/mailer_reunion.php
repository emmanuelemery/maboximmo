<?php
// inc/mailer_reunion.php — Envoi de la convocation de réunion par e-mail (PHPMailer)
// Utilisé par agency_reunion_detail.php (POST send_convocation)

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailException;

/**
 * Envoie la convocation à tous les participants de la réunion.
 * Génère le corps HTML + attache le PDF convocation si TCPDF est disponible.
 *
 * @return bool  true si tous les e-mails ont été envoyés sans erreur
 */
function sendConvocationMail(PDO $pdo, int $reunionId): bool
{
    // ── Config SMTP (depuis les paramètres de la société ou constantes) ──
    $smtp_host     = defined('SMTP_HOST')     ? SMTP_HOST     : 'smtp.maboximmo.fr';
    $smtp_port     = defined('SMTP_PORT')     ? (int)SMTP_PORT : 587;
    $smtp_user     = defined('SMTP_USER')     ? SMTP_USER     : '';
    $smtp_pass     = defined('SMTP_PASS')     ? SMTP_PASS     : '';
    $smtp_from     = defined('SMTP_FROM')     ? SMTP_FROM     : $smtp_user;
    $smtp_from_name= defined('SMTP_FROM_NAME')? SMTP_FROM_NAME: 'MaBoxImmo Syndic';

    // ── Données réunion ───────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT r.*,
               i.nom AS imm_nom, i.reference AS imm_ref, i.adresse AS imm_adresse,
               i.code_postal, i.ville AS imm_ville, i.nb_lots,
               e.nom AS etab_nom, e.email AS etab_email,
               u.nom AS auteur_nom, u.prenom AS auteur_prenom
        FROM agency_reunion r
        LEFT JOIN agency_immeuble i ON i.id = r.id_immeuble
        LEFT JOIN etablissements e ON e.id = r.id_etablissement
        LEFT JOIN users u ON u.id = r.cree_par
        WHERE r.id = ?
    ");
    $stmt->execute([$reunionId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return false;

    $odj_stmt = $pdo->prepare("SELECT * FROM agency_reunion_odj WHERE id_reunion = ? ORDER BY ordre, id");
    $odj_stmt->execute([$reunionId]);
    $odj = $odj_stmt->fetchAll(PDO::FETCH_ASSOC);

    $parts_stmt = $pdo->prepare("
        SELECT u.nom, u.prenom, u.email
        FROM agency_reunion_participant rp
        JOIN users u ON u.id = rp.id_user
        WHERE rp.id_reunion = ? AND u.email IS NOT NULL AND u.email != ''
    ");
    $parts_stmt->execute([$reunionId]);
    $participants = $parts_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($participants)) return true; // Rien à envoyer

    // ── PHPMailer check ───────────────────────────────────────────────
    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (!file_exists($autoload)) {
        error_log('[mailer_reunion] vendor/autoload.php introuvable');
        return false;
    }
    require_once $autoload;

    // ── Corps HTML de la convocation ──────────────────────────────────
    $typeLabel = ['AG'=>'Assemblée Générale','CS'=>'Conseil Syndical','autre'=>'Réunion'][$r['type_reunion']??'autre']??'Réunion';
    $dtFr = fn($dt,$h=true) => $dt ? date($h?'d/m/Y à H\hi':'d/m/Y',strtotime($dt)) : '—';

    $odj_html = '';
    foreach ($odj as $i => $pt) {
        $odj_html .= '<li style="margin-bottom:6px">'.htmlspecialchars($pt['intitule']).'</li>';
    }

    $body = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f5f3ef;margin:0;padding:0">
<div style="max-width:600px;margin:30px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08)">
  <!-- Header -->
  <div style="background:linear-gradient(135deg,#6898bf,#4878a6);padding:24px 32px">
    <h1 style="color:#fff;margin:0;font-size:20px;font-weight:700">MaBoxImmo · Syndic</h1>
    <p style="color:rgba(255,255,255,.8);margin:4px 0 0;font-size:12px">{$r['etab_nom']}</p>
  </div>
  <!-- Corps -->
  <div style="padding:28px 32px">
    <h2 style="color:#4878a6;margin:0 0 6px;font-size:18px">Convocation — {$typeLabel}</h2>
    <p style="color:#2c2a28;font-size:20px;font-weight:700;margin:0 0 20px">{$r['titre']}</p>
    <!-- Infos -->
    <table style="width:100%;border-collapse:collapse;margin-bottom:20px">
      <tr><td style="padding:6px 0;color:#888;font-size:12px;width:130px"><b>Date et heure</b></td><td style="font-size:13px">{$dtFr($r['date_reunion'])}</td></tr>
HTML;
    if ($r['lieu'])    $body .= "<tr><td style='padding:6px 0;color:#888;font-size:12px'><b>Lieu</b></td><td style='font-size:13px'>".htmlspecialchars($r['lieu'])."</td></tr>\n";
    if ($r['imm_nom']) $body .= "<tr><td style='padding:6px 0;color:#888;font-size:12px'><b>Immeuble</b></td><td style='font-size:13px'>".htmlspecialchars($r['imm_nom'].' ('.$r['imm_ref'].')')."</td></tr>\n";
    $body .= <<<HTML
    </table>
    <!-- ODJ -->
    <div style="background:#f5f3ef;border-radius:8px;padding:14px 20px;margin-bottom:20px">
      <h3 style="color:#2c2a28;font-size:13px;margin:0 0 10px">Ordre du jour</h3>
      <ol style="margin:0;padding-left:18px;color:#4a4844;font-size:13px">{$odj_html}</ol>
    </div>
HTML;
    if ($r['commentaire']) {
        $body .= '<p style="font-style:italic;color:#666;font-size:12px">'.nl2br(htmlspecialchars($r['commentaire'])).'</p>';
    }
    $body .= <<<HTML
  </div>
  <!-- Footer -->
  <div style="background:#f5f3ef;padding:14px 32px;font-size:11px;color:#9a9690;border-top:1px solid #ffffff">
    Ce message est envoyé automatiquement par MaBoxImmo — veuillez ne pas y répondre directement.<br>
    Document généré le {$dtFr(date('Y-m-d H:i:s'))}
  </div>
</div>
</body></html>
HTML;

    // ── Génération PDF en mémoire (si TCPDF dispo) ────────────────────
    $pdfContent = null;
    $tcpdf_path = __DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf.php';
    if (file_exists($tcpdf_path)) {
        // Appel de la page PDF et capture de la sortie
        ob_start();
        $_GET['id'] = $reunionId; // Hack propre : inclure le générateur PDF dans une capture
        // On utilise directement TCPDF plutôt que d'inclure la page
        require_once $tcpdf_path;
        // (Génération simplifiée inline pour l'attachement)
        $pdf = new \TCPDF('P','mm','A4');
        $pdf->SetCreator('MaBoxImmo');
        $pdf->SetTitle('Convocation — '.$r['titre']);
        $pdf->SetMargins(15,25,15);
        $pdf->SetAutoPageBreak(true,20);
        $pdf->AddPage();
        $pdf->SetFont('helvetica','B',16);
        $pdf->SetTextColor(72,120,166);
        $pdf->MultiCell(0,10,'CONVOCATION — '.$typeLabel,0,'C');
        $pdf->SetFont('helvetica','B',13);
        $pdf->SetTextColor(44,42,40);
        $pdf->MultiCell(0,8,$r['titre'],0,'C');
        $pdf->Ln(4);
        $pdf->SetFont('helvetica','',10);
        $pdf->Cell(0,6,'Date : '.$dtFr($r['date_reunion']),0,1);
        if ($r['lieu']) $pdf->Cell(0,6,'Lieu : '.htmlspecialchars($r['lieu']),0,1);
        if ($r['imm_nom']) $pdf->Cell(0,6,'Immeuble : '.htmlspecialchars($r['imm_nom'].' ('.$r['imm_ref'].')'),0,1);
        $pdf->Ln(4);
        $pdf->SetFont('helvetica','B',11);
        $pdf->Cell(0,7,'Ordre du jour',0,1);
        $pdf->SetFont('helvetica','',10);
        foreach ($odj as $i => $pt) {
            $pdf->Cell(10,6,($i+1).'.',0,'');
            $pdf->MultiCell(0,6,htmlspecialchars($pt['intitule']),0,'L');
        }
        $pdfContent = $pdf->Output('convocation.pdf','S');
        ob_end_clean();
    }

    // ── Envoi ─────────────────────────────────────────────────────────
    $success = true;
    foreach ($participants as $p) {
        if (empty($p['email'])) continue;
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $smtp_host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $smtp_user;
            $mail->Password   = $smtp_pass;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = $smtp_port;
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom($smtp_from, $smtp_from_name);
            $mail->addAddress($p['email'], trim(($p['prenom']??'').' '.($p['nom']??'')));
            $mail->isHTML(true);
            $mail->Subject = 'Convocation — '.$r['titre'].' — '.$dtFr($r['date_reunion'], false);
            $mail->Body    = $body;
            $mail->AltBody = 'Convocation : '.$r['titre']."\nDate : ".$dtFr($r['date_reunion']).
                             ($r['lieu'] ? "\nLieu : ".$r['lieu'] : '').
                             "\n\nOrdre du jour :\n".implode("\n", array_map(fn($i,$pt) => ($i+1).'. '.$pt['intitule'], array_keys($odj), $odj));

            if ($pdfContent) {
                $mail->addStringAttachment($pdfContent, 'convocation_'.$reunionId.'_'.date('Ymd').'.pdf', 'base64', 'application/pdf');
            }
            $mail->send();
        } catch (MailException $e) {
            error_log('[mailer_reunion] Erreur envoi à '.$p['email'].': '.$e->getMessage());
            $success = false;
        }
    }

    return $success;
}
