<?php
declare(strict_types=1);

// Load PHPMailer classes
$phpmailerPath = __DIR__ . '/../lib/phpmailer/';
require_once $phpmailerPath . 'PHPMailer.php';
require_once $phpmailerPath . 'SMTP.php';
require_once $phpmailerPath . 'Exception.php';
require_once __DIR__ . '/../config/smtp.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!function_exists('send_mail')) {
    /**
     * Send email via SMTP with optional attachments and HTML content
     *
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $message Email body (HTML or plain text)
     * @param array $attachments Optional file paths to attach: ['path/to/file.pdf', ...]
     * @param bool $isHtml Whether message is HTML (default: false)
     * @param string $cc CC recipients (comma-separated)
     * @param string $replyTo Reply-To address
     * @param string $fromEmail Optional override From address
     * @param string $fromName Optional override From name
     * @return bool True if sent successfully, false otherwise
     */
    function send_mail(
        string $to,
        string $subject,
        string $message,
        array $attachments = [],
        bool $isHtml = false,
        string $cc = '',
        string $replyTo = '',
        string $fromEmail = '',
        string $fromName = ''
    ): bool {
        try {
            $config = smtp_config();
            $mail = new PHPMailer(true);

            // Server settings
            $mail->isSMTP();
            $mail->Host = $config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['username'];
            $mail->Password = $config['password'];
            $mail->SMTPSecure = $config['secure'] === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = $config['port'];
            $mail->CharSet = 'UTF-8';

            // Recipients
            $from = $fromEmail !== '' ? $fromEmail : $config['from'];
            $fromLabel = $fromName !== '' ? $fromName : $config['fromName'];
            $mail->setFrom($from, $fromLabel);
            $mail->addAddress($to);

            // Reply-To
            if (!empty($replyTo) && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $mail->addReplyTo($replyTo);
            }

            // Add CC recipients if provided
            if (!empty($cc)) {
                $ccEmails = array_filter(array_map('trim', explode(',', $cc)));
                foreach ($ccEmails as $ccEmail) {
                    if (!empty($ccEmail) && filter_var($ccEmail, FILTER_VALIDATE_EMAIL)) {
                        $mail->addCC($ccEmail);
                    }
                }
            }

            // Content
            $mail->isHTML($isHtml);
            $mail->Subject = $subject;
            $mail->Body = $message;

            // Add attachments
            foreach ($attachments as $filePath) {
                if (is_file($filePath) && is_readable($filePath)) {
                    $fileName = basename($filePath);
                    $mail->addAttachment($filePath, $fileName);
                }
            }

            return $mail->send();
        } catch (Exception $e) {
            error_log('[Mailer] SMTP error: ' . $e->getMessage());
            return false;
        }
    }
}