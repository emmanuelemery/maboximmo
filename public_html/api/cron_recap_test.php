<?php
// api/cron_recap_test.php
// Diagnostic : tente d'envoyer un seul mail test avec PHPMailer et retourne
// l'erreur exacte si échec. Supprimer ce fichier après debug.
declare(strict_types=1);

set_exception_handler(function (Throwable $e): void {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
    exit;
});

require_once dirname(__DIR__) . '/inc/bootstrap.php';

// Config cron
$cronConfig = dirname(__DIR__) . '/config/cron.php';
if (file_exists($cronConfig)) require_once $cronConfig;

$token = (string)($_GET['token'] ?? '');
$expected = defined('CRON_RECAP_TOKEN') ? (string)CRON_RECAP_TOKEN : '';
if ($expected === '' || !hash_equals($expected, $token)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['ok' => false, 'error' => 'Token invalide']));
}

// Load PHPMailer directement (pas via send_mail) pour capturer l'erreur exacte
$phpmailerPath = dirname(__DIR__) . '/lib/phpmailer/';
require_once $phpmailerPath . 'PHPMailer.php';
require_once $phpmailerPath . 'SMTP.php';
require_once $phpmailerPath . 'Exception.php';
require_once dirname(__DIR__) . '/config/smtp.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json; charset=utf-8');

$to       = 'emmanuel.emery@regie-emery.com';
$from     = defined('CRON_RECAP_FROM_EMAIL') ? (string)CRON_RECAP_FROM_EMAIL : 'ne-pas-repondre@maboximmo.fr';
$fromName = defined('CRON_RECAP_FROM_NAME')  ? (string)CRON_RECAP_FROM_NAME  : 'MaBoxImmo';

$smtpConfig = smtp_config();
$diag = [
    'smtp_host'     => $smtpConfig['host']     ?? '(absent)',
    'smtp_port'     => $smtpConfig['port']     ?? '(absent)',
    'smtp_user'     => $smtpConfig['username'] ?? '(absent)',
    'smtp_secure'   => $smtpConfig['secure']   ?? '(absent)',
    'smtp_from_cfg' => $smtpConfig['from']     ?? '(absent)',
    'from_used'     => $from,
    'from_name'     => $fromName,
    'to'            => $to,
];

try {
    $mail = new PHPMailer(true);
    $mail->SMTPDebug = 2; // verbose
    $mail->Debugoutput = function ($str, $level) use (&$diag) {
        $diag['smtp_log'][] = trim($str);
    };
    $mail->isSMTP();
    $mail->Host = $smtpConfig['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $smtpConfig['username'];
    $mail->Password = $smtpConfig['password'];
    $mail->SMTPSecure = ($smtpConfig['secure'] === 'tls') ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = (int)$smtpConfig['port'];
    $mail->CharSet = 'UTF-8';

    $mail->setFrom($from, $fromName);
    $mail->addAddress($to);
    $mail->isHTML(true);
    $mail->Subject = '🧪 Test cron recap — diagnostic SMTP';
    $mail->Body = '<p>Test envoi PHPMailer depuis cron_recap_test.php à ' . date('H:i:s') . '.</p>';

    $mail->send();
    $diag['result'] = 'SENT OK';
    echo json_encode(['ok' => true, 'diag' => $diag], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Exception $e) {
    $diag['phpmailer_error'] = $mail->ErrorInfo ?: $e->getMessage();
    $diag['exception']       = $e->getMessage();
    echo json_encode(['ok' => false, 'diag' => $diag], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    $diag['throwable'] = $e->getMessage();
    echo json_encode(['ok' => false, 'diag' => $diag], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
