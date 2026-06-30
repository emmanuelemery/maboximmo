<?php
/**
 * api/dpe_lyon_pdf_proxy.php — Sert EN INLINE un PDF OneDrive (pour l'aperçu dans le
 * modal). L'URL @microsoft.graph.downloadUrl force le téléchargement (attachment) :
 * on télécharge via Graph et on ré-émet le flux avec Content-Disposition: inline.
 *
 * GET : item_id=<graph item id>
 * Sécurité : admin / super admin (lecture seule).
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_admin_or_super_admin();
require_once dirname(__DIR__) . '/inc/microsoft_graph.php';

$itemId = trim((string)($_GET['item_id'] ?? ''));
if ($itemId === '') { http_response_code(400); exit('item_id manquant'); }
if (!graph_is_configured()) { http_response_code(500); exit('Graph non configuré'); }

graph_set_drive_user(defined('GRAPH_ONEDRIVE_USER') ? (string)GRAPH_ONEDRIVE_USER : '');

try {
    $dl = graph_download_file_content($itemId);
} catch (Throwable $e) {
    http_response_code(502); exit('Téléchargement OneDrive échoué');
}
if (empty($dl['ok']) || !isset($dl['content'])) { http_response_code(404); exit('Fichier introuvable'); }

$name = (string)($dl['name'] ?? 'document.pdf');
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . rawurlencode($name) . '"');
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
echo (string)$dl['content'];
