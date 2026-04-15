<?php
declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();

$input = json_decode(file_get_contents('php://input'), true);
$id    = (string)($input['id'] ?? '');

if (!$id || !preg_match('/^[0-9a-f]{16}$/', $id)) {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}

$attachments = $_SESSION['mail_attachments'] ?? [];
if (isset($attachments[$id])) {
    $path = $attachments[$id]['path'];
    if (is_file($path)) {
        unlink($path);
    }
    unset($_SESSION['mail_attachments'][$id]);
}

echo json_encode(['success' => true]);
