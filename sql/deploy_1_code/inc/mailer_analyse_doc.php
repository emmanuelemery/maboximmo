<?php
// inc/mailer_analyse_doc.php — Envoi du résumé IA d'un document au propriétaire
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/**
 * Envoie le résumé IA d'un PV/convocation au(x) destinataire(s).
 *
 * @param array  $summary     Tableau structuré retourné par analyseDocumentIA()
 * @param array  $doc         Ligne agency_mandat_document
 * @param array  $mandat      Ligne agency_mandat (avec etab_nom etc.)
 * @param string $dest_email  E-mail(s) séparés par virgule
 * @param string $dest_name   Nom du destinataire (copropriétaire / mandant)
 * @param string $message_perso  Message personnalisé ajouté par le gestionnaire
 * @return array ['ok'=>bool, 'msg'=>string]
 */
function sendAnalyseMail(
    array  $summary,
    array  $doc,
    array  $mandat,
    string $dest_email,
    string $dest_name,
    string $message_perso = ''
): array {
    $smtp_host      = defined('SMTP_HOST')      ? SMTP_HOST      : 'smtp.maboximmo.fr';
    $smtp_port      = defined('SMTP_PORT')       ? (int)SMTP_PORT : 587;
    $smtp_user      = defined('SMTP_USER')       ? SMTP_USER      : '';
    $smtp_pass      = defined('SMTP_PASS')       ? SMTP_PASS      : '';
    $smtp_from      = defined('SMTP_FROM')       ? SMTP_FROM      : $smtp_user;
    $smtp_from_name = defined('SMTP_FROM_NAME')  ? SMTP_FROM_NAME : 'MaBoxImmo Syndic';

    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (!file_exists($autoload)) {
        return ['ok' => false, 'msg' => 'PHPMailer non disponible (vendor manquant)'];
    }
    require_once $autoload;

    $typeLabels = [
        'pv_ag'          => "Procès-verbal d'Assemblée Générale",
        'convocation_ag' => "Convocation à l'Assemblée Générale",
        'budget_previsionnel' => 'Budget prévisionnel',
    ];
    $docLabel   = $typeLabels[$doc['type_doc']] ?? ucfirst(str_replace('_', ' ', $doc['type_doc']));
    $imm        = htmlspecialchars($mandat['imm_nom'] ?? $mandat['immeuble_txt'] ?? '');
    $etabNom    = htmlspecialchars($mandat['etab_nom'] ?? '');
    $dateDoc    = htmlspecialchars($summary['date_document'] ?? '');
    $resume     = nl2br(htmlspecialchars($summary['resume_general'] ?? ''));
    $msgProp    = nl2br(htmlspecialchars($summary['message_proprietaire'] ?? ''));

    // ── Points clés ───────────────────────────────────────────────────────────
    $pointsHtml = '';
    foreach (($summary['points_cles'] ?? []) as $p) {
        $pointsHtml .= '<li style="padding:4px 0;color:#4a4844;font-size:13px">'.htmlspecialchars($p).'</li>';
    }

    // ── Résolutions ───────────────────────────────────────────────────────────
    $resolHtml = '';
    foreach (($summary['resolutions'] ?? []) as $r) {
        $color = match($r['resultat'] ?? '') {
            'ADOPTEE'  => '#2d7a4a', 'REJETEE' => '#c84040',
            'REPORTEE' => '#c87030', default   => '#4878a6',
        };
        $badge = match($r['resultat'] ?? '') {
            'ADOPTEE'  => 'Adoptée', 'REJETEE' => 'Rejetée',
            'REPORTEE' => 'Reportée', default  => 'Information',
        };
        $resolHtml .= '
        <tr>
          <td style="padding:7px 10px;border-bottom:1px solid #ffffff;font-size:12px;color:#6a6864">'
              .htmlspecialchars($r['numero'] ?? '').'</td>
          <td style="padding:7px 10px;border-bottom:1px solid #ffffff;font-size:12px;color:#2c2a28">'
              .htmlspecialchars($r['objet'] ?? '').'</td>
          <td style="padding:7px 10px;border-bottom:1px solid #ffffff;text-align:center">
            <span style="background:'.str_replace('#','#','rgba(0,0,0,.06)').';color:'.$color.';border-radius:20px;padding:2px 10px;font-size:11px;font-weight:700">'.$badge.'</span>
          </td>
        </tr>';
    }

    // ── Montants ──────────────────────────────────────────────────────────────
    $montantsHtml = '';
    foreach (($summary['montants'] ?? []) as $mo) {
        $montantsHtml .= '<tr>
          <td style="padding:6px 10px;border-bottom:1px solid #ffffff;font-size:12px;color:#4a4844">'.htmlspecialchars($mo['libelle'] ?? '').'</td>
          <td style="padding:6px 10px;border-bottom:1px solid #ffffff;font-size:12px;font-weight:700;color:#4878a6;text-align:right">'.htmlspecialchars($mo['montant'] ?? '').'</td>
        </tr>';
    }

    // ── Prochaines échéances ──────────────────────────────────────────────────
    $echeancesHtml = '';
    foreach (($summary['prochaines_echeances'] ?? []) as $e) {
        $echeancesHtml .= '<li style="padding:3px 0;font-size:12px;color:#4a4844">'.htmlspecialchars($e).'</li>';
    }

    // ── Message personnalisé ──────────────────────────────────────────────────
    $msgPersoBlock = '';
    if (trim($message_perso)) {
        $msgPersoBlock = '
        <div style="background:#fffbe8;border-radius:8px;padding:12px 16px;margin:16px 0;border-left:4px solid #c8a020">
          <div style="font-size:11px;font-weight:700;color:#856404;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px">Message de votre gestionnaire</div>
          <div style="font-size:13px;color:#4a4844">'.nl2br(htmlspecialchars($message_perso)).'</div>
        </div>';
    }

    // ── Construction du corps HTML par concaténation ─────────────────────────
    $datePart = $dateDoc ? "du <strong>$dateDoc</strong> " : '';

    $body  = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>';
    $body .= '<body style="font-family:Arial,sans-serif;background:#f5f3ef;margin:0;padding:0">';
    $body .= '<div style="max-width:600px;margin:30px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08)">';

    // En-tête
    $body .= '<div style="background:linear-gradient(135deg,#6898bf,#4878a6);padding:22px 28px">'
           . '<h1 style="color:#fff;margin:0;font-size:17px;font-weight:700">' . $etabNom . '</h1>'
           . '<p style="color:rgba(255,255,255,.75);margin:4px 0 0;font-size:11px">Résumé · ' . $docLabel . '</p>'
           . '</div>';

    // Intro
    $body .= '<div style="padding:22px 28px 0">'
           . '<p style="font-size:13px;color:#4a4844;margin:0 0 6px">Madame, Monsieur,</p>'
           . '<p style="font-size:13px;color:#4a4844;margin:0 0 16px">'
           . 'Veuillez trouver ci-dessous le résumé du document <strong>' . $docLabel . '</strong> '
           . $datePart
           . 'concernant votre immeuble <strong>' . $imm . '</strong>.'
           . '</p>'
           . $msgPersoBlock
           . '</div>';

    // Message propriétaire (IA)
    $body .= '<div style="padding:0 28px 16px">'
           . '<div style="background:#f0f6fb;border-radius:10px;padding:14px 18px;border-left:4px solid #4878a6;font-size:13px;color:#2c2a28;line-height:1.6">'
           . $msgProp
           . '</div></div>';

    // Points clés
    if ($pointsHtml) {
        $body .= '<div style="padding:0 28px 16px">'
               . '<div style="font-size:11px;font-weight:700;color:#9a9690;text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px">Points clés</div>'
               . '<ul style="margin:0;padding-left:18px;list-style:disc">' . $pointsHtml . '</ul>'
               . '</div>';
    }

    // Résolutions
    if ($resolHtml) {
        $body .= '<div style="padding:0 28px 16px">'
               . '<div style="font-size:11px;font-weight:700;color:#9a9690;text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px">Résolutions votées</div>'
               . '<table style="width:100%;border-collapse:collapse;font-size:12px">'
               . '<thead><tr style="background:#f0f0ec">'
               . '<th style="padding:6px 10px;text-align:left;color:#9a9690;font-size:10px;text-transform:uppercase">N°</th>'
               . '<th style="padding:6px 10px;text-align:left;color:#9a9690;font-size:10px;text-transform:uppercase">Objet</th>'
               . '<th style="padding:6px 10px;text-align:center;color:#9a9690;font-size:10px;text-transform:uppercase">Résultat</th>'
               . '</tr></thead>'
               . '<tbody>' . $resolHtml . '</tbody>'
               . '</table></div>';
    }

    // Montants
    if ($montantsHtml) {
        $body .= '<div style="padding:0 28px 16px">'
               . '<div style="font-size:11px;font-weight:700;color:#9a9690;text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px">Montants à retenir</div>'
               . '<table style="width:100%;border-collapse:collapse"><tbody>' . $montantsHtml . '</tbody></table>'
               . '</div>';
    }

    // Échéances
    if ($echeancesHtml) {
        $body .= '<div style="padding:0 28px 16px">'
               . '<div style="font-size:11px;font-weight:700;color:#9a9690;text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px">Prochaines échéances</div>'
               . '<ul style="margin:0;padding-left:18px;list-style:circle">' . $echeancesHtml . '</ul>'
               . '</div>';
    }

    // Signature
    $body .= '<div style="padding:0 28px 22px">'
           . '<p style="font-size:12px;color:#6a6864;margin:0">Cordialement,<br><strong>' . $etabNom . '</strong></p>'
           . '</div>';

    // Footer
    $body .= '<div style="background:#f5f3ef;padding:12px 28px;font-size:10px;color:#9a9690;border-top:1px solid #ffffff">'
           . 'Ce résumé a été généré automatiquement par MaBoxImmo et validé par votre gestionnaire. Le document original fait foi.'
           . '</div>';

    $body .= '</div></body></html>';

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

        foreach (explode(',', $dest_email) as $addr) {
            $addr = trim($addr);
            if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($addr, $dest_name);
            }
        }

        $mail->isHTML(true);
        $mail->Subject = '[' . ($imm ?: 'Copropriété') . '] ' . $docLabel . ($dateDoc ? " du $dateDoc" : '');
        $mail->Body    = $body;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<li>', '</li>'], ["\n", "\n", "• ", "\n"], $body));
        $mail->send();

        return ['ok' => true, 'msg' => 'Résumé envoyé à ' . htmlspecialchars($dest_email)];
    } catch (MailException $e) {
        error_log('[mailer_analyse_doc] ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'Erreur SMTP : ' . $e->getMessage()];
    }
}
