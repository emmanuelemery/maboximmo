<?php
declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';

require_login();

$roleId = current_role_id();
if ($roleId !== 1 && $roleId !== 2) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès non autorisé']);
    exit;
}

// CSRF — token sent as POST field (multipart form)
$sessionToken = $_SESSION['_csrf_mail_team'] ?? '';
$postedToken  = (string)($_POST['csrf_token'] ?? '');
if (!$sessionToken || !$postedToken || !hash_equals($sessionToken, $postedToken)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Requête invalide (CSRF)']);
    exit;
}

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errCode = $_FILES['file']['error'] ?? -1;
    $msg = $errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE
        ? 'Fichier trop volumineux (max 10 Mo)'
        : 'Erreur lors de l\'upload';
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

$file = $_FILES['file'];

// Size check — 10 MB
if ($file['size'] > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Fichier trop volumineux (max 10 Mo)']);
    exit;
}

// Allowed MIME types
$allowedMimes = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'image/jpeg',
    'image/png',
    'text/plain',
];

$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$realMime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($realMime, $allowedMimes, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Type de fichier non autorisé']);
    exit;
}

// Allowed extensions mapped to expected MIME
$mimeExtensions = [
    'application/pdf'   => ['pdf'],
    'application/msword' => ['doc'],
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
    'application/vnd.ms-excel' => ['xls'],
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
    'image/jpeg' => ['jpg', 'jpeg'],
    'image/png'  => ['png'],
    'text/plain' => ['txt'],
];

$originalName = $file['name'];
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$allowedExts  = $mimeExtensions[$realMime] ?? [];
if (!in_array($ext, $allowedExts, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Extension incohérente avec le type de fichier']);
    exit;
}

// Store in session-keyed temp dir
$userId  = current_user_id();
$tempDir = sys_get_temp_dir() . '/mbi_mail_' . session_id();
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0700, true);
}

$id       = bin2hex(random_bytes(8));
$safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($originalName));
$destPath = $tempDir . '/' . $id . '_' . $safeName;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Impossible de stocker le fichier']);
    exit;
}

// Register in session
if (!isset($_SESSION['mail_attachments'])) {
    $_SESSION['mail_attachments'] = [];
}
$_SESSION['mail_attachments'][$id] = [
    'path' => $destPath,
    'name' => $originalName,
];

echo json_encode(['success' => true, 'id' => $id, 'name' => $originalName]);
