<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { http_response_code(500); exit('Database error'); }

$docId         = (int)($_GET['id'] ?? 0);
$currentUserId = current_user_id();
$roleId        = current_role_id();
$agenceScope   = can_manage_salaires_agence();

if ($docId <= 0) { http_response_code(400); exit('Invalid document ID'); }

$stmt = $pdo->prepare("SELECT * FROM rh_documents WHERE id = ?");
$stmt->execute([$docId]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) { http_response_code(404); exit('Document not found'); }

// Contrôle d'accès : propriétaire, admin ou gestionnaire agence
if ($roleId !== 1 && (int)$doc['id_user'] !== $currentUserId) {
    if ($agenceScope > 0) {
        $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? AND id_agence = ?");
        $chk->execute([$doc['id_user'], $agenceScope]);
        if (!$chk->fetch()) { http_response_code(403); exit('Access denied'); }
    } else {
        http_response_code(403); exit('Access denied');
    }
}

// Contrôle documents confidentiels : uniquement role_id=1
if (!empty($doc['confidentiel']) && (int)$doc['confidentiel'] === 1 && $roleId !== 1) {
    http_response_code(403); exit('Document confidentiel — accès refusé');
}

$filePath    = __DIR__ . '/../uploads/rh_docs/' . (int)$doc['id_user'] . '/' . basename($doc['filename']);
$uploadsBase = realpath(__DIR__ . '/../uploads');
$resolved    = realpath($filePath);
if (!$resolved || !$uploadsBase || strpos($resolved, $uploadsBase . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(403); exit('Access denied');
}
if (!file_exists($resolved) || !is_readable($resolved)) {
    http_response_code(404); exit('File not found');
}

$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $resolved) ?: 'application/octet-stream';
finfo_close($finfo);

$inline = in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png', 'image/gif']);

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($resolved));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . htmlspecialchars($doc['original_name'] ?? basename($resolved), ENT_QUOTES, 'UTF-8') . '"');
header('Cache-Control: no-store');
readfile($resolved);
