<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    exit('Database error');
}

$docId         = (int)($_GET['id'] ?? 0);
$currentUserId = current_user_id();
$roleId        = current_role_id();
$agenceScope   = can_manage_salaires_agence();

if ($docId <= 0) {
    http_response_code(400);
    exit('Invalid document ID');
}

// Get document
$stmt = $pdo->prepare("SELECT * FROM salaires_documents WHERE id = ?");
$stmt->execute([$docId]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    http_response_code(404);
    exit('Document not found');
}

// Check access: admin, own user, ou gestionnaire agence pour son agence
if ($roleId !== 1 && $doc['id_user'] !== $currentUserId) {
    if ($agenceScope > 0) {
        $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? AND id_agence = ?");
        $chk->execute([$doc['id_user'], $agenceScope]);
        if (!$chk->fetch()) {
            http_response_code(403);
            exit('Access denied');
        }
    } else {
        http_response_code(403);
        exit('Access denied');
    }
}

// Construct file path — basename() prevents directory traversal
$filePath = __DIR__ . '/../uploads/rh_docs/' . (int)$doc['id_user'] . '/' . basename($doc['filename']);
// Validate the resolved path stays within the uploads directory
$uploadsBase = realpath(__DIR__ . '/../uploads');
$resolvedPath = realpath($filePath);
if (!$resolvedPath || !$uploadsBase || strpos($resolvedPath, $uploadsBase . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(403);
    exit('Access denied');
}
$filePath = $resolvedPath;

if (!file_exists($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    exit('File not found');
}

// Determine MIME type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $filePath);
finfo_close($finfo);

if (!$mimeType) {
    $mimeType = 'application/octet-stream';
}

// Serve file
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: inline; filename="' . htmlspecialchars($doc['original_name'], ENT_QUOTES, 'UTF-8') . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($filePath);
?>
