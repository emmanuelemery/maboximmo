<?php
/**
 * api/graph_doc_preview.php
 *
 * Aperçu inline d'un fichier OneDrive AVANT classement GED (read-only).
 * Permet à l'utilisateur de VISUALISER une proposition avant de la valider.
 *
 * GET item_id=<id OneDrive>
 * Renvoie le binaire en inline (Content-Disposition: inline).
 *
 * Aucune écriture, aucune copie persistée : flux direct OneDrive → navigateur.
 */

declare(strict_types=1);
set_time_limit(120);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/microsoft_graph.php';
require_login();

$itemId = trim((string)($_GET['item_id'] ?? ''));
if ($itemId === '') {
    http_response_code(400);
    exit('Paramètre item_id manquant.');
}

if (!graph_is_configured()) {
    http_response_code(503);
    exit('Microsoft Graph non configuré.');
}

if (defined('GRAPH_ONEDRIVE_USER') && (string)GRAPH_ONEDRIVE_USER !== '') {
    graph_set_drive_user((string)GRAPH_ONEDRIVE_USER);
}

try {
    $dl = graph_download_file_content($itemId);
} catch (Throwable $e) {
    http_response_code(502);
    exit('Erreur OneDrive : ' . $e->getMessage());
}
if (empty($dl['ok'])) {
    http_response_code(404);
    exit($dl['error'] ?? 'Fichier introuvable.');
}

$mime = (string)($dl['mime_type'] ?? 'application/octet-stream');
$name = (string)($dl['name'] ?? 'apercu');

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . rawurlencode($name) . '"');
header('Content-Length: ' . strlen((string)$dl['content']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=120');
echo $dl['content'];
