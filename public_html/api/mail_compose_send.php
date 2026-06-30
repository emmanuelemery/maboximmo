<?php
// api/mail_compose_send.php — Envoi d'un mail GÉNÉRIQUE avec pièces jointes.
// POST : ctx, id, sujet, corps, recipients[] (emails), doc_uids[] (uid renvoyés par mail_context).
// Les PJ sont RÉ-AUTORISÉES côté serveur via mail_context() (jamais de chemin venant du client).
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/mail_context.php';
require_once __DIR__ . '/../inc/mailer.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$ctxType = strtoupper(trim((string)(post('ctx') ?? '')));
$ctxId   = (int)(post('id') ?? 0);
$sujet   = trim((string)(post('sujet') ?? ''));
$corps   = trim((string)(post('corps') ?? ''));
$recips  = $_POST['recipients'] ?? [];
$uids    = $_POST['doc_uids'] ?? [];
if (!is_array($recips)) $recips = [];
if (!is_array($uids))   $uids = [];

if ($ctxType === '' || $ctxId <= 0) { echo json_encode(['ok'=>false,'error'=>'contexte invalide']); exit; }

$C = mail_context($pdo, $ctxType, $ctxId);
if (empty($C['ok'])) { echo json_encode(['ok'=>false,'error'=>'contexte introuvable']); exit; }

// Validation destinataires
$emails = [];
foreach ($recips as $e) { $e = trim((string)$e); if (filter_var($e, FILTER_VALIDATE_EMAIL)) $emails[] = $e; }
$emails = array_values(array_unique($emails));
if (!$emails)      { echo json_encode(['ok'=>false,'error'=>'aucun destinataire valide']); exit; }
if ($sujet === '') { echo json_encode(['ok'=>false,'error'=>'sujet requis']); exit; }
if ($corps === '') { echo json_encode(['ok'=>false,'error'=>'corps requis']); exit; }

// Pièces jointes : on ne garde que les uid AUTORISÉS par le contexte (et dont le fichier existe).
$allowed = mail_context_paths_by_uid($C);
$uids = array_values(array_unique(array_map('strval', $uids)));
$attachments = [];
foreach ($uids as $uid) { if (isset($allowed[$uid])) $attachments[] = $allowed[$uid]; }

// Expéditeur = utilisateur connecté → Reply-To
$senderEmail = ''; $senderNom = '';
try {
    $stE = $pdo->prepare("SELECT email, TRIM(CONCAT(COALESCE(prenom,''),' ',COALESCE(nom,''))) AS nom FROM users WHERE id = ? LIMIT 1");
    $stE->execute([(int)current_user_id()]);
    if ($u = $stE->fetch(PDO::FETCH_ASSOC)) { $senderEmail = (string)$u['email']; $senderNom = trim((string)$u['nom']); }
} catch (Throwable $e) {}
$replyTo = filter_var($senderEmail, FILTER_VALIDATE_EMAIL) ? $senderEmail : '';

// Envoi
$bodyHtml = nl2br(htmlspecialchars($corps, ENT_QUOTES, 'UTF-8'));
$okCount = 0; $fail = [];
foreach ($emails as $to) {
    $sent = send_mail($to, $sujet, $bodyHtml, $attachments, true, '', $replyTo, '', $senderNom);
    if ($sent) $okCount++; else $fail[] = $to;
}

// Trace historique (toujours, même si SMTP échoue)
$histId = 0;
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS mail_history (
        id INT AUTO_INCREMENT PRIMARY KEY, sent_by INT NOT NULL, subject VARCHAR(255) NOT NULL,
        body TEXT NOT NULL, recipient_type VARCHAR(50), recipients_count INT DEFAULT 0,
        recipients_json TEXT, sent_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX(sent_by), INDEX(sent_at))");
    $payload = json_encode([
        'from' => $senderEmail, 'to' => $emails, 'failed' => $fail,
        'attachments' => count($attachments), 'doc_uids' => $uids, 'sent_ok' => $okCount,
    ], JSON_UNESCAPED_UNICODE);
    $stH = $pdo->prepare("INSERT INTO mail_history (sent_by, subject, body, recipient_type, recipients_count, recipients_json)
                          VALUES (?,?,?,?,?,?)");
    $stH->execute([(int)current_user_id() ?: 0, $sujet, $corps, $C['history_key'], $okCount, $payload]);
    $histId = (int)$pdo->lastInsertId();
} catch (Throwable $e) { error_log('[mail_compose_send history] ' . $e->getMessage()); }

echo json_encode([
    'ok' => $okCount > 0, 'sent' => $okCount, 'failed' => $fail,
    'nb_attachments' => count($attachments), 'history_id' => $histId,
], JSON_UNESCAPED_UNICODE);
