<?php
declare(strict_types=1);

/**
 * GED — Stream / Download d'un document classé.
 *
 * GET /api/ged_document_view.php?id=N&mode=inline|download
 *
 * Sécurité :
 *  - require_login
 *  - filtre par tenant_id (impossible d'accéder à un doc d'un autre tenant)
 *  - le fichier physique est stocké dans storage_fluxbox/ (hors web root)
 *
 * Récupère le ged_documents.id, retrouve le fichier physique via
 * fluxbox_documents.fichier_chemin (lié par fluxbox_source_id),
 * et stream le binaire avec les bons headers.
 */

@ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/ged_functions.php';
require_login();

$pdo = ged_pdo();
$tenantId = (int)(ged_current_tenant_id() ?? 0);
$docId = (int)($_GET['id'] ?? 0);
$mode  = (string)($_GET['mode'] ?? 'inline'); // inline | download
if (!in_array($mode, ['inline', 'download'], true)) $mode = 'inline';

if ($docId <= 0) {
    http_response_code(400);
    exit('Paramètre id manquant');
}
if ($tenantId <= 0) {
    http_response_code(403);
    exit('Tenant non identifié');
}

try {
    // Document GED + chemin physique via fluxbox_documents (si origine FluxBox)
    $st = $pdo->prepare("
        SELECT d.id, d.name_display, d.name_canonical, d.name_file, d.mime_type,
               d.size_bytes, d.storage_provider, d.status,
               f.fichier_chemin AS flux_path, f.mime_type AS flux_mime
        FROM ged_documents d
        LEFT JOIN fluxbox_documents f ON f.id = d.fluxbox_source_id
        WHERE d.id = ? AND d.tenant_id = ?
        LIMIT 1
    ");
    $st->execute([$docId, $tenantId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        http_response_code(404);
        exit('Document introuvable ou hors périmètre');
    }
    if ($doc['status'] === 'deleted') {
        http_response_code(410); // Gone
        exit('Document supprimé');
    }

    $path = (string)($doc['flux_path'] ?? '');
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        http_response_code(404);
        exit('Fichier physique introuvable. (Origine non-FluxBox ou fichier non encore implémenté pour ce mode de stockage.)');
    }

    $mime = (string)($doc['mime_type'] ?? '') ?: (string)($doc['flux_mime'] ?? '') ?: 'application/octet-stream';
    $size = filesize($path) ?: (int)$doc['size_bytes'];

    // Nom utilisé pour le téléchargement : name_canonical si dispo, sinon name_display
    $downloadName = (string)($doc['name_canonical'] ?? '') ?: (string)($doc['name_display'] ?? ('document_' . $docId));
    // Sécurité contre header injection
    $downloadName = preg_replace('/[\r\n"\\\\]/', '_', $downloadName) ?? $downloadName;

    // Headers
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    if ($mode === 'download') {
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    } else {
        // Inline : le browser affiche dans un nouvel onglet (PDF, image natif)
        header('Content-Disposition: inline; filename="' . $downloadName . '"');
    }
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');

    // Stream
    while (ob_get_level() > 0) @ob_end_clean();
    $fp = @fopen($path, 'rb');
    if (!$fp) {
        http_response_code(500);
        exit('Lecture fichier échouée');
    }
    fpassthru($fp);
    fclose($fp);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    exit('Erreur : ' . $e->getMessage());
}
