<?php
// api/transaction_chg_staging_view.php — Sert le fichier staging en stream (pour iframe PDF preview)
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/transaction_chg_staging.php';
require_login();

tr_staging_ensure_table($pdo);

$stagingId = (int)($_GET['id'] ?? 0);
$userId    = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);

if ($stagingId <= 0) { http_response_code(400); exit('id requis'); }

$row = tr_staging_check_owner($pdo, $stagingId, $userId);
if (!$row) { http_response_code(404); exit('introuvable'); }

$abs = __DIR__ . '/../' . $row['stored_path'];
if (!is_file($abs)) { http_response_code(404); exit('fichier absent'); }

$mime = $row['mime_type'] ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: inline; filename="' . addslashes((string)$row['filename']) . '"');
header('Cache-Control: private, max-age=600');
header('X-Frame-Options: SAMEORIGIN');
readfile($abs);
