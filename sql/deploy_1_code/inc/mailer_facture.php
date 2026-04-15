<?php
// inc/mailer_facture.php — Envoi e-mail d'une facture (PHPMailer)
// Utilisé par agency_facture_form.php (POST send_mail)

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/**
 * Envoie la facture par e-mail au destinataire défini sur la facture.
 * @param PDO $pdo
 * @param int $factureId
 * @return array ['ok'=>bool, 'msg'=>string]
 */
function sendFactureMail(PDO $pdo, int $factureId): array
{
    $smtp_host      = defined('SMTP_HOST')      ? SMTP_HOST      : 'smtp.maboximmo.fr';
    $smtp_port      = defined('SMTP_PORT')       ? (int)SMTP_PORT : 587;
    $smtp_user      = defined('SMTP_USER')       ? SMTP_USER      : '';
    $smtp_pass      = defined('SMTP_PASS')       ? SMTP_PASS      : '';
    $smtp_from      = defined('SMTP_FROM')       ? SMTP_FROM      : $smtp_user;
    $smtp_from_name = defined('SMTP_FROM_NAME')  ? SMTP_FROM_NAME : 'MaBoxImmo Syndic';

    // ── Données facture ───────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT f.*,
               e.raison_sociale AS etab_nom,
               e.adresse        AS etab_adresse,
               e.code_postal    AS etab_cp,
               e.ville          AS etab_ville,
               e.telephone      AS etab_tel,
               e.email          AS etab_email,
               e.siret          AS etab_siret,
               e.logo           AS etab_logo
        FROM agency_facture f
        LEFT JOIN etablissements e ON e.id = f.id_etablissement
        WHERE f.id = ?
    ");
    $stmt->execute([$factureId]);
    $f = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$f) return ['ok' => false, 'msg' => 'Facture introuvable'];

    $dest = trim($f['mail_destinataire'] ?? '');
    if (!$dest || !filter_var($dest, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'msg' => 'Adresse e-mail destinataire invalide ou manquante'];
    }

    // ── Lignes ────────────────────────────────────────────────────────────
    $stmtL = $pdo->prepare("SELECT * FROM agency_facture_ligne WHERE id_facture = ? ORDER BY ordre");
    $stmtL->execute([$factureId]);
    $lignes = $stmtL->fetchAll(PDO::FETCH_ASSOC);

    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (!file_exists($autoload)) {
        error_log('[mailer_facture] vendor/autoload.php introuvable');
        return ['ok' => false, 'msg' => 'PHPMailer non disponible (vendor manquant)'];
    }
    require_once $autoload;

    // ── Calculs totaux ────────────────────────────────────────────────────
    $totalHT  = 0; $totalTTC = 0;
    foreach ($lignes as $l) {
        $ht  = (float)$l['quantite'] * (float)$l['pu_ht'];
        $ttc = $ht * (1 + (float)$l['tva_pct'] / 100);
        $totalHT  += $ht;
        $totalTTC += $ttc;
    }
    $totalTVA = $totalTTC - $totalHT;

    $fmtEur = fn($v) => number_format($v, 2, ',', ' ') . ' €';
    $appUrl  = defined('APP_URL') ? APP_URL : 'http://localhost/MaBoxImmo2026/public_html';

    $dateEm  = $f['date_emission']  ? date('d/m/Y', strtotime($f['date_emission']))  : '—';
    $dateEch = $f['date_echeance']  ? date('d/m/Y', strtotime($f['date_echeance']))  : '—';

    $statutLabels = [
        'brouillon' => 'Brouillon', 'validee' => 'Validée', 'envoyee' => 'Envoyée',
        'payee' => 'Payée', 'annulee' => 'Annulée',
    ];
    $sl = $statutLabels[$f['statut'] ?? ''] ?? $f['statut'];

    // ── Corps HTML ────────────────────────────────────────────────────────
    $lignesHtml = '';
    foreach ($lignes as $l) {
        $ht  = (float)$l['quantite'] * (float)$l['pu_ht'];
        $ttc = $ht * (1 + (float)$l['tva_pct'] / 100);
        $lignesHtml .= '<tr>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #ffffff">' . htmlspecialchars($l['designation']) . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #ffffff;text-align:center">' . $l['quantite'] . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #ffffff;text-align:right">' . $fmtEur((float)$l['pu_ht']) . '</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #ffffff;text-align:center">' . $l['tva_pct'] . '%</td>'
            . '<td style="padding:6px 10px;border-bottom:1px solid #ffffff;text-align:right">' . $fmtEur($ttc) . '</td>'
            . '</tr>';
    }

    $etabBlock = '';
    if ($f['etab_nom']) {
        $etabBlock = '<p style="margin:0;font-size:13px;font-weight:700;color:#1a1816">' . htmlspecialchars($f['etab_nom']) . '</p>';
        if ($f['etab_adresse']) $etabBlock .= '<p style="margin:2px 0;font-size:11px;color:#6a6864">' . htmlspecialchars($f['etab_adresse']) . '</p>';
        if ($f['etab_cp'] || $f['etab_ville']) $etabBlock .= '<p style="margin:2px 0;font-size:11px;color:#6a6864">' . htmlspecialchars(trim($f['etab_cp'] . ' ' . $f['etab_ville'])) . '</p>';
        if ($f['etab_tel']) $etabBlock .= '<p style="margin:2px 0;font-size:11px;color:#6a6864">Tél : ' . htmlspecialchars($f['etab_tel']) . '</p>';
        if ($f['etab_siret']) $etabBlock .= '<p style="margin:2px 0;font-size:11px;color:#6a6864">SIRET : ' . htmlspecialchars($f['etab_siret']) . '</p>';
    }

    $notesBlock = '';
    if (!empty($f['notes'])) {
        $notesBlock = '<div style="background:#f5f3ef;border-radius:8px;padding:10px 14px;font-size:11px;color:#6a6864;margin-top:10px">'
            . '<b>Notes :</b> ' . nl2br(htmlspecialchars($f['notes']))
            . '</div>';
    }

    $body = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f5f3ef;margin:0;padding:0">
<div style="max-width:620px;margin:30px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08)">
  <div style="background:linear-gradient(135deg,#6898bf,#4878a6);padding:20px 28px;display:flex;justify-content:space-between;align-items:flex-start">
    <div>
      <h1 style="color:#fff;margin:0;font-size:18px;font-weight:700">MaBoxImmo · Syndic</h1>
      <p style="color:rgba(255,255,255,.7);margin:4px 0 0;font-size:11px">Facture</p>
    </div>
    <div style="text-align:right">
      <p style="color:#fff;font-size:20px;font-weight:700;margin:0">{$f['numero']}</p>
      <p style="color:rgba(255,255,255,.8);font-size:11px;margin:4px 0 0">Émission : {$dateEm}</p>
    </div>
  </div>
  <div style="padding:24px 28px">
    <div style="display:flex;justify-content:space-between;margin-bottom:18px;gap:20px">
      <div style="flex:1">
        <p style="font-size:10px;text-transform:uppercase;color:#9a9690;letter-spacing:.05em;margin:0 0 4px">De</p>
        {$etabBlock}
      </div>
      <div style="flex:1">
        <p style="font-size:10px;text-transform:uppercase;color:#9a9690;letter-spacing:.05em;margin:0 0 4px">À</p>
        <p style="margin:0;font-size:13px;font-weight:700;color:#1a1816">{$f['client']}</p>
        <p style="margin:2px 0;font-size:11px;color:#6a6864">{$f['immeuble_txt']}</p>
      </div>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:16px">
      <thead>
        <tr style="background:linear-gradient(135deg,#6898bf,#4878a6)">
          <th style="padding:8px 10px;color:#fff;text-align:left;font-weight:600">Désignation</th>
          <th style="padding:8px 10px;color:#fff;text-align:center;font-weight:600">Qté</th>
          <th style="padding:8px 10px;color:#fff;text-align:right;font-weight:600">PU HT</th>
          <th style="padding:8px 10px;color:#fff;text-align:center;font-weight:600">TVA</th>
          <th style="padding:8px 10px;color:#fff;text-align:right;font-weight:600">TTC</th>
        </tr>
      </thead>
      <tbody>
        {$lignesHtml}
      </tbody>
    </table>
    <div style="text-align:right;margin-bottom:16px">
      <table style="margin-left:auto;font-size:12px">
        <tr><td style="padding:3px 20px 3px 0;color:#6a6864">Total HT</td><td style="font-weight:600">{$fmtEur($totalHT)}</td></tr>
        <tr><td style="padding:3px 20px 3px 0;color:#6a6864">TVA</td><td>{$fmtEur($totalTVA)}</td></tr>
        <tr style="background:linear-gradient(135deg,#6898bf,#4878a6)">
          <td style="padding:6px 20px;color:#fff;font-weight:700;border-radius:6px 0 0 6px">TOTAL TTC</td>
          <td style="padding:6px 14px;color:#fff;font-weight:700;font-size:15px;border-radius:0 6px 6px 0">{$fmtEur($totalTTC)}</td>
        </tr>
      </table>
    </div>
    <table style="font-size:11px;color:#6a6864;margin-bottom:14px">
      <tr><td style="padding:2px 16px 2px 0"><b>Échéance</b></td><td>{$dateEch}</td></tr>
      <tr><td style="padding:2px 16px 2px 0"><b>Mode de paiement</b></td><td>{$f['mode_paiement']}</td></tr>
      <tr><td style="padding:2px 16px 2px 0"><b>Statut</b></td><td>{$sl}</td></tr>
    </table>
    {$notesBlock}
    <div style="margin-top:18px">
      <a href="{$appUrl}/agency_facture_form.php?id={$factureId}" style="display:inline-block;background:linear-gradient(135deg,#6898bf,#4878a6);color:#fff;text-decoration:none;padding:10px 22px;border-radius:8px;font-weight:700;font-size:13px">
        Consulter la facture →
      </a>
    </div>
  </div>
  <div style="background:#f5f3ef;padding:12px 28px;font-size:10px;color:#9a9690;border-top:1px solid #ffffff">
    Message envoyé automatiquement par MaBoxImmo — ne pas répondre directement.
  </div>
</div>
</body></html>
HTML;

    // ── Tenter d'attacher le PDF si TCPDF disponible ───────────────────
    $pdfData   = null;
    $pdfName   = 'facture-' . preg_replace('/[^A-Za-z0-9\-]/', '-', $f['numero']) . '.pdf';
    $tcpdfPath = __DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf.php';
    if (file_exists($tcpdfPath)) {
        try {
            require_once $tcpdfPath;
            ob_start();
            // Inclure le générateur PDF en mode "string"
            // On définit une constante pour que agency_pdf_facture.php sache
            // qu'il doit retourner le PDF en string plutôt qu'afficher
            $_GET['id']        = $factureId;
            $_GET['pdf_output'] = 'S'; // string
            ob_end_clean();
            // Génération directe via la classe PDF inline
            $pdf = buildFacturePdf($pdo, $f, $lignes, $totalHT, $totalTVA, $totalTTC, $fmtEur);
            if ($pdf) $pdfData = $pdf;
        } catch (\Throwable $e) {
            error_log('[mailer_facture] PDF generation failed: ' . $e->getMessage());
        }
    }

    // ── Envoi ─────────────────────────────────────────────────────────────
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
        $mail->addAddress($dest, $f['client'] ?? '');

        $cc = trim($f['mail_cc'] ?? '');
        if ($cc && filter_var($cc, FILTER_VALIDATE_EMAIL)) {
            $mail->addCC($cc);
        }

        if ($pdfData) {
            $mail->addStringAttachment($pdfData, $pdfName, 'base64', 'application/pdf');
        }

        $mail->isHTML(true);
        $mail->Subject = 'Facture ' . $f['numero'] . ($f['etab_nom'] ? ' — ' . $f['etab_nom'] : '');
        $mail->Body    = $body;
        $mail->AltBody = "Facture {$f['numero']}\nClient : {$f['client']}\nTotal TTC : {$fmtEur($totalTTC)}\nÉchéance : {$dateEch}\n\nConsulter : {$appUrl}/agency_facture_form.php?id={$factureId}";
        $mail->send();

        // Marquer statut "envoyee" si brouillon ou validée
        if (in_array($f['statut'], ['brouillon', 'validee'])) {
            $pdo->prepare("UPDATE agency_facture SET statut='envoyee' WHERE id=?")->execute([$factureId]);
        }

        return ['ok' => true, 'msg' => 'Facture envoyée à ' . htmlspecialchars($dest)];
    } catch (MailException $e) {
        error_log('[mailer_facture] Erreur envoi : ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'Erreur SMTP : ' . $e->getMessage()];
    }
}

/**
 * Construit un PDF TCPDF minimal pour la facture et retourne le contenu binaire.
 */
function buildFacturePdf(PDO $pdo, array $f, array $lignes, float $totalHT, float $totalTVA, float $totalTTC, callable $fmtEur): ?string
{
    if (!class_exists('TCPDF')) return null;

    $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('MaBoxImmo');
    $pdf->SetTitle('Facture ' . $f['numero']);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 15, 15);
    $pdf->AddPage();

    // En-tête
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(72, 120, 166);
    $pdf->Cell(0, 8, 'FACTURE', 0, 1, 'R');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetTextColor(26, 24, 22);
    $pdf->Cell(0, 6, $f['numero'], 0, 1, 'R');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(106, 104, 100);
    $pdf->Cell(0, 5, 'Émission : ' . ($f['date_emission'] ? date('d/m/Y', strtotime($f['date_emission'])) : '—'), 0, 1, 'R');
    $pdf->Ln(4);

    // Lignes
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(72, 120, 166);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(85, 7, 'Désignation', 1, 0, 'L', true);
    $pdf->Cell(20, 7, 'Qté', 1, 0, 'C', true);
    $pdf->Cell(30, 7, 'PU HT', 1, 0, 'R', true);
    $pdf->Cell(15, 7, 'TVA', 1, 0, 'C', true);
    $pdf->Cell(30, 7, 'TTC', 1, 1, 'R', true);

    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(26, 24, 22);
    $fill = false;
    foreach ($lignes as $l) {
        $ht  = (float)$l['quantite'] * (float)$l['pu_ht'];
        $ttc = $ht * (1 + (float)$l['tva_pct'] / 100);
        $pdf->SetFillColor(245, 243, 239);
        $pdf->Cell(85, 6, $l['designation'], 1, 0, 'L', $fill);
        $pdf->Cell(20, 6, $l['quantite'], 1, 0, 'C', $fill);
        $pdf->Cell(30, 6, $fmtEur((float)$l['pu_ht']), 1, 0, 'R', $fill);
        $pdf->Cell(15, 6, $l['tva_pct'] . '%', 1, 0, 'C', $fill);
        $pdf->Cell(30, 6, $fmtEur($ttc), 1, 1, 'R', $fill);
        $fill = !$fill;
    }

    $pdf->Ln(3);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(150, 5, 'Total HT', 0, 0, 'R');
    $pdf->Cell(30, 5, $fmtEur($totalHT), 0, 1, 'R');
    $pdf->Cell(150, 5, 'TVA', 0, 0, 'R');
    $pdf->Cell(30, 5, $fmtEur($totalTVA), 0, 1, 'R');
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(72, 120, 166);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(150, 7, 'TOTAL TTC', 0, 0, 'R', false);
    $pdf->Cell(30, 7, $fmtEur($totalTTC), 0, 1, 'R', false);

    return $pdf->Output('', 'S');
}
