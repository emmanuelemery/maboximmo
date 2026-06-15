<?php
// api/transaction_dossier_mail_send.php — Envoi d'un mail du dossier de vente avec
// pièces jointes GED. POST : id_dossier, sujet, corps, recipients[] (emails),
// doc_ids[] (ged_documents.id). Réutilise send_mail (inc/mailer.php).
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/dossier_vente.php';
require_once __DIR__ . '/../inc/ged_file_path.php';
require_once __DIR__ . '/../inc/mailer.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$idDossier = (int)(post('id_dossier') ?? 0);
$sujet     = trim((string)(post('sujet') ?? ''));
$corps     = trim((string)(post('corps') ?? ''));
$recips    = $_POST['recipients'] ?? [];
$docIds    = $_POST['doc_ids'] ?? [];
if (!is_array($recips)) $recips = [];
if (!is_array($docIds)) $docIds = [];

$dossier = dv_get($pdo, $idDossier);
if (!$dossier) { echo json_encode(['ok'=>false,'error'=>'dossier introuvable']); exit; }

// Scope société (super admin / manager bypass).
$roleId    = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isManager = ($roleId === 1 || $roleId === 2);
$idSoc     = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
if (!$isManager && $idSoc !== null && (int)$dossier['id_societe'] !== $idSoc) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'hors scope']); exit;
}

// Validation
$emails = [];
foreach ($recips as $e) {
    $e = trim((string)$e);
    if (filter_var($e, FILTER_VALIDATE_EMAIL)) $emails[] = $e;
}
$emails = array_values(array_unique($emails));
if (!$emails)            { echo json_encode(['ok'=>false,'error'=>'aucun destinataire valide']); exit; }
if ($sujet === '')       { echo json_encode(['ok'=>false,'error'=>'sujet requis']); exit; }
if ($corps === '')       { echo json_encode(['ok'=>false,'error'=>'corps requis']); exit; }

// Pièces jointes : seulement des docs réellement liés au dossier ou à son bien.
$docIds = array_values(array_unique(array_map('intval', $docIds)));
$attachments = [];
if ($docIds) {
    $autorises = [];
    foreach (dv_documents($pdo, $idDossier) as $d) $autorises[(int)$d['id']] = 1;
    foreach (gdl_documents_for_entity($pdo, 'BIEN', (int)$dossier['id_bien'], ['limit'=>300]) as $d) $autorises[(int)$d['id']] = 1;
    foreach ($docIds as $did) {
        if (!isset($autorises[$did])) continue;
        $p = ged_file_path($pdo, $did);
        if ($p) $attachments[] = $p;
    }
}

// Expéditeur = utilisateur connecté → Reply-To (les réponses lui reviennent).
$senderEmail = ''; $senderNom = '';
try {
    $stE = $pdo->prepare("SELECT email, TRIM(CONCAT(COALESCE(prenom,''),' ',COALESCE(nom,''))) AS nom FROM users WHERE id = ? LIMIT 1");
    $stE->execute([(int)current_user_id()]);
    if ($u = $stE->fetch(PDO::FETCH_ASSOC)) { $senderEmail = (string)$u['email']; $senderNom = trim((string)$u['nom']); }
} catch (Throwable $e) {}
$replyTo = filter_var($senderEmail, FILTER_VALIDATE_EMAIL) ? $senderEmail : '';

// Envoi (HTML : on convertit les sauts de ligne)
$bodyHtml = nl2br(htmlspecialchars($corps, ENT_QUOTES, 'UTF-8'));
$okCount = 0; $fail = [];
foreach ($emails as $to) {
    $sent = send_mail($to, $sujet, $bodyHtml, $attachments, true, '', $replyTo, '', $senderNom);
    if ($sent) $okCount++; else $fail[] = $to;
}

// Trace historique — IMPÉRATIF : on enregistre toujours, même si l'envoi SMTP échoue.
$histId = 0;
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS mail_history (
        id INT AUTO_INCREMENT PRIMARY KEY, sent_by INT NOT NULL, subject VARCHAR(255) NOT NULL,
        body TEXT NOT NULL, recipient_type VARCHAR(50), recipients_count INT DEFAULT 0,
        recipients_json TEXT, sent_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX(sent_by), INDEX(sent_at))");
    $payload = json_encode([
        'from'        => $senderEmail,
        'to'          => $emails,
        'failed'      => $fail,
        'attachments' => count($attachments),
        'doc_ids'     => $docIds,
        'sent_ok'     => $okCount,
    ], JSON_UNESCAPED_UNICODE);
    $stH = $pdo->prepare("INSERT INTO mail_history (sent_by, subject, body, recipient_type, recipients_count, recipients_json)
                          VALUES (?,?,?,?,?,?)");
    $stH->execute([(int)current_user_id() ?: 0, $sujet, $corps, 'dossier_vente:' . $idDossier, $okCount, $payload]);
    $histId = (int)$pdo->lastInsertId();
} catch (Throwable $e) { error_log('[mail_send history] ' . $e->getMessage()); }

echo json_encode([
    'ok'             => $okCount > 0,
    'sent'           => $okCount,
    'failed'         => $fail,
    'nb_attachments' => count($attachments),
    'history_id'     => $histId,
], JSON_UNESCAPED_UNICODE);
