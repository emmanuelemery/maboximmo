<?php
// inc/mailer_tache.php — Notification e-mail d'une tâche (PHPMailer)
// Utilisé par agency_tache_detail.php (POST send_notif)

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/**
 * Envoie une notification aux assignés de la tâche.
 * @param PDO    $pdo
 * @param int    $tacheId    ID de la tâche
 * @param int    $senderId   Utilisateur déclencheur
 * @param string $customMsg  Message personnalisé optionnel
 * @return bool
 */
function sendTacheNotification(PDO $pdo, int $tacheId, int $senderId, string $customMsg = ''): bool
{
    $smtp_host      = defined('SMTP_HOST')      ? SMTP_HOST      : 'smtp.maboximmo.fr';
    $smtp_port      = defined('SMTP_PORT')       ? (int)SMTP_PORT : 587;
    $smtp_user      = defined('SMTP_USER')       ? SMTP_USER      : '';
    $smtp_pass      = defined('SMTP_PASS')       ? SMTP_PASS      : '';
    $smtp_from      = defined('SMTP_FROM')       ? SMTP_FROM      : $smtp_user;
    $smtp_from_name = defined('SMTP_FROM_NAME')  ? SMTP_FROM_NAME : 'MaBoxImmo Syndic';

    // ── Données tâche ─────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT t.*,
               i.nom AS imm_nom, i.reference AS imm_ref,
               u.nom AS auteur_nom, u.prenom AS auteur_prenom
        FROM agency_tache t
        LEFT JOIN agency_immeuble i ON i.id = t.id_immeuble
        LEFT JOIN users u ON u.id = t.id_createur
        WHERE t.id = ?
    ");
    $stmt->execute([$tacheId]);
    $t = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$t) return false;

    // Expéditeur
    $sender = $pdo->prepare("SELECT nom, prenom, email FROM users WHERE id=?");
    $sender->execute([$senderId]);
    $sender = $sender->fetch(PDO::FETCH_ASSOC);
    $senderName = trim(($sender['prenom']??'').' '.($sender['nom']??'')) ?: 'Un collaborateur';

    // Assignés avec e-mail
    $stmt = $pdo->prepare("
        SELECT u.nom, u.prenom, u.email
        FROM agency_tache_assignee ta
        JOIN users u ON u.id = ta.id_user
        WHERE ta.id_tache = ? AND u.email IS NOT NULL AND u.email != '' AND u.id != ?
    ");
    $stmt->execute([$tacheId, $senderId]);
    $assignes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($assignes)) return true;

    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (!file_exists($autoload)) { error_log('[mailer_tache] vendor/autoload.php introuvable'); return false; }
    require_once $autoload;

    // ── Priorité couleur ──────────────────────────────────────────────
    $pColors = ['critique'=>'#c84040','haute'=>'#c87030','normale'=>'#4878a6','basse'=>'#808080'];
    $pLabels = ['critique'=>'Critique','haute'=>'Haute','normale'=>'Normale','basse'=>'Basse'];
    $sLabels = ['à faire'=>'À faire','en cours'=>'En cours','en attente'=>'En attente','terminee'=>'Terminée','archivee'=>'Archivée'];
    $pc = $pColors[$t['priorite']??'normale'] ?? '#4878a6';
    $pl = $pLabels[$t['priorite']??'normale'] ?? '';
    $sl = $sLabels[$t['statut']??''] ?? $t['statut'];

    $dtEch = $t['date_echeance'] ? date('d/m/Y', strtotime($t['date_echeance'])) : 'Non définie';
    $appUrl = defined('APP_URL') ? APP_URL : 'http://localhost/MaBoxImmo2026/public_html';

    $body = <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f5f3ef;margin:0;padding:0">
<div style="max-width:560px;margin:30px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08)">
  <div style="background:linear-gradient(135deg,#6898bf,#4878a6);padding:20px 28px">
    <h1 style="color:#fff;margin:0;font-size:18px;font-weight:700">MaBoxImmo · Syndic</h1>
    <p style="color:rgba(255,255,255,.7);margin:4px 0 0;font-size:11px">Notification de tâche</p>
  </div>
  <div style="padding:24px 28px">
    <p style="font-size:13px;color:#4a4844;margin:0 0 14px"><b>{$senderName}</b> vous a adressé une notification concernant la tâche :</p>
    <div style="background:#f5f3ef;border-radius:10px;padding:14px 18px;margin-bottom:16px;border-left:4px solid {$pc}">
      <div style="font-weight:700;font-size:16px;color:#1a1816;margin-bottom:6px">{$t['titre']}</div>
      <table style="font-size:12px;color:#6a6864">
        <tr><td style="padding:2px 12px 2px 0"><b>Statut</b></td><td>{$sl}</td></tr>
        <tr><td style="padding:2px 12px 2px 0"><b>Priorité</b></td><td style="color:{$pc};font-weight:700">{$pl}</td></tr>
        <tr><td style="padding:2px 12px 2px 0"><b>Échéance</b></td><td>{$dtEch}</td></tr>
HTML;
    if ($t['imm_nom']) $body .= "<tr><td style='padding:2px 12px 2px 0'><b>Immeuble</b></td><td>".htmlspecialchars($t['imm_nom'])."</td></tr>\n";
    $body .= "</table>\n";
    if ($t['description']) {
        $body .= '<div style="margin-top:10px;font-size:12px;color:#6a6864;border-top:1px solid #e0dbd4;padding-top:8px">'.nl2br(htmlspecialchars($t['description']))."</div>\n";
    }
    $body .= '</div>';
    if ($customMsg) {
        $body .= '<div style="background:#fffbe8;border-radius:8px;padding:10px 14px;font-size:12px;color:#856404;margin-bottom:14px"><b>Message :</b> '.nl2br(htmlspecialchars($customMsg))."</div>\n";
    }
    $body .= <<<HTML
    <a href="{$appUrl}/agency_tache_detail.php?id={$tacheId}" style="display:inline-block;background:linear-gradient(135deg,#6898bf,#4878a6);color:#fff;text-decoration:none;padding:10px 22px;border-radius:8px;font-weight:700;font-size:13px">
      Voir la tâche →
    </a>
  </div>
  <div style="background:#f5f3ef;padding:12px 28px;font-size:10px;color:#9a9690;border-top:1px solid #ffffff">
    Message envoyé automatiquement par MaBoxImmo — ne pas répondre directement.
  </div>
</div>
</body></html>
HTML;

    $success = true;
    foreach ($assignes as $a) {
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
            $mail->addAddress($a['email'], trim(($a['prenom']??'').' '.($a['nom']??'')));
            $mail->isHTML(true);
            $mail->Subject = '[Tâche] ' . $t['titre'];
            $mail->Body    = $body;
            $mail->AltBody = "Tâche : {$t['titre']}\nStatut : $sl\nPriorité : $pl\nÉchéance : $dtEch".
                             ($customMsg ? "\nMessage : $customMsg" : '').
                             "\n\nVoir : {$appUrl}/agency_tache_detail.php?id={$tacheId}";
            $mail->send();
        } catch (MailException $e) {
            error_log('[mailer_tache] Erreur envoi à '.$a['email'].': '.$e->getMessage());
            $success = false;
        }
    }
    return $success;
}
