<?php
declare(strict_types=1);

/**
 * GED MaBoxImmo — Preview iframe du document en quarantaine.
 * Fichier : public_html/api/ged_inbox_preview.php
 *
 * Pourquoi dans /api/ et pas /modules/ged/ :
 *   mod_security Hostinger bloque les réponses PDF servies depuis /modules/.
 *   Le path /api/ est whitelisté (cf. api/ged_inbox_upload.php).
 *
 * Streame inline un PDF/image stocké en quarantaine local par l'inbox.
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();
require_once dirname(__DIR__) . '/modules/ged/ged_storage.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('id manquant');
}

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$isAdmin   = in_array((int)current_role_id(), [1, 7, 8], true);

$where  = "id = :id";
$params = ['id' => $id];
if (!$isAdmin && $societeId > 0) {
    $where .= " AND id_societe = :sid";
    $params['sid'] = $societeId;
}
$st = $pdo->prepare("SELECT storage_driver, storage_file_id, storage_mime, nom_original, extension FROM ged_analyses WHERE {$where}");
$st->execute($params);
$r = $st->fetch(PDO::FETCH_ASSOC);

if (!$r || empty($r['storage_file_id'])) {
    http_response_code(404);
    exit('document introuvable');
}
if ($r['storage_driver'] !== 'local') {
    http_response_code(404);
    exit('preview disponible uniquement en quarantaine locale (avant validation)');
}

$base = dirname(__DIR__, 2) . '/storage/ged';
$abs  = $base . '/' . ltrim((string)$r['storage_file_id'], '/');

if (!is_file($abs)) {
    http_response_code(404);
    exit('fichier physique introuvable');
}

// Sécurité path : confirme qu'on est bien dans le base storage (anti-path-traversal)
$absReal  = realpath($abs);
$baseReal = realpath($base);
if ($absReal === false || $baseReal === false || strpos($absReal, $baseReal) !== 0) {
    http_response_code(403);
    exit('chemin invalide');
}

$mime = $r['storage_mime'] ?: GedStorageDriver::detectMime($abs);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($abs));
header('Content-Disposition: inline; filename="' . basename($abs) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=0, must-revalidate');
readfile($abs);
exit;
