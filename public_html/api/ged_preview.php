<?php
/**
 * api/ged_preview.php — Serve les fichiers sources GED pour preview dans l'UI
 *
 * Sécurité :
 *   - Auth super admin uniquement (id_role = 1)
 *   - Le path doit matcher un enregistrement dans ged_manifest
 *   - Restreint à des racines whitelistées (anti path traversal)
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

if ((int)current_role_id() !== 1) {
    http_response_code(403);
    exit('403 — Super admin only');
}

$idManifest = (int)($_GET['id'] ?? 0);
if ($idManifest <= 0) {
    http_response_code(400);
    exit('id manquant');
}

$pdo = $GLOBALS['pdo'];
$st = $pdo->prepare("SELECT path_source, filename, extension FROM ged_manifest WHERE id = ? LIMIT 1");
$st->execute([$idManifest]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit('404 — fichier introuvable');
}

$path = $row['path_source'];

// ── Anti path traversal : la racine doit matcher une whitelist ──
$ALLOWED_ROOTS = [
    'C:\\xampp\\htdocs\\01_GROUPE SIR ET SABY',
    'C:\\xampp\\htdocs\\01_DIAG ET DPE',
];
$ok = false;
foreach ($ALLOWED_ROOTS as $root) {
    if (str_starts_with($path, $root)) {
        $ok = true;
        break;
    }
}
if (!$ok) {
    http_response_code(403);
    exit('403 — chemin hors whitelist');
}

if (!is_file($path) || !is_readable($path)) {
    http_response_code(404);
    exit('404 — fichier non lisible sur disque');
}

// ── Headers ──
$ext = strtolower((string)$row['extension']);
$mimes = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'xls' => 'application/vnd.ms-excel',
    'csv' => 'text/csv',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
$mime = $mimes[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . basename($row['filename']) . '"');
header('Cache-Control: private, max-age=60');
readfile($path);
