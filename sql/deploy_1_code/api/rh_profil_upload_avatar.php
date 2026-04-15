<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();
verify_csrf_any();
header('Content-Type: application/json; charset=utf-8');

$pdo    = $GLOBALS['pdo'];
$meId   = current_user_id();
$roleId = current_role_id();

$targetId = (int)($_POST['user_id'] ?? 0);
if ($targetId <= 0) $targetId = $meId;

if ($targetId !== $meId && $roleId !== 1) {
    http_response_code(403);
    echo json_encode(['success'=>false,'error'=>'Accès refusé']);
    exit;
}

if (empty($_FILES['avatar']) || !isset($_FILES['avatar']['tmp_name'])) {
    echo json_encode(['success'=>false,'error'=>'Fichier manquant']);
    exit;
}

$file = $_FILES['avatar'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success'=>false,'error'=>'Erreur upload']);
    exit;
}

$maxSize = 4 * 1024 * 1024;
if ((int)$file['size'] > $maxSize) {
    echo json_encode(['success'=>false,'error'=>'Fichier trop volumineux']);
    exit;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);
$allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp'
];
if (!isset($allowed[$mime])) {
    echo json_encode(['success'=>false,'error'=>'Format non supporté']);
    exit;
}

$baseDir = __DIR__ . '/../uploads/avatars/' . $targetId;
if (!is_dir($baseDir) && !mkdir($baseDir, 0755, true)) {
    echo json_encode(['success'=>false,'error'=>'Impossible de créer le dossier']);
    exit;
}

$ext = $allowed[$mime];
$filename = 'avatar_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$dest = $baseDir . '/' . $filename;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    echo json_encode(['success'=>false,'error'=>'Échec de l\'enregistrement']);
    exit;
}

// Nettoyage ancien avatar si possible
$stmt = $pdo->prepare('SELECT avatar_url FROM users WHERE id = ?');
$stmt->execute([$targetId]);
$old = $stmt->fetchColumn();
if ($old) {
    $oldRel = ltrim((string)$old, './');
    $oldPath = realpath(__DIR__ . '/../' . $oldRel);
    $baseReal = realpath($baseDir);
    if ($oldPath && $baseReal && strpos($oldPath, $baseReal) === 0 && is_file($oldPath)) {
        @unlink($oldPath);
    }
}

$url = './uploads/avatars/' . $targetId . '/' . $filename;
$pdo->prepare('UPDATE users SET avatar_url = ?, date_modification = NOW() WHERE id = ?')->execute([$url, $targetId]);

echo json_encode(['success' => true, 'url' => $url]);