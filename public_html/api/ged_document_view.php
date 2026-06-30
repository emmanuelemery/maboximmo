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
// Admin (role 1) / super-admin : accès transverse à tous les tenants (visualisation GED globale,
// même logique que api/ged_doc_serve.php).
$bypassTenant = ((int)($_SESSION['id_role'] ?? 0) === 1)
             || (function_exists('is_super_admin') && is_super_admin());
if ($tenantId <= 0 && !$bypassTenant) {
    http_response_code(403);
    exit('Tenant non identifié');
}

try {
    // Document GED + chemin physique via fluxbox_documents (si origine FluxBox)
    // FIX 2026-05-25 : ajout final_destination + metadata pour fallback ged_docs
    // créés par gus_commit_document (Sprint 7) qui n'ont pas de fluxbox_source_id
    $st = $pdo->prepare("
        SELECT d.id, d.name_display, d.name_canonical, d.name_file, d.mime_type,
               d.size_bytes, d.storage_provider, d.status,
               d.final_destination, d.metadata,
               f.fichier_chemin AS flux_path, f.mime_type AS flux_mime
        FROM ged_documents d
        LEFT JOIN fluxbox_documents f ON f.id = d.fluxbox_source_id
        WHERE d.id = ? " . ($bypassTenant ? "" : "AND d.tenant_id = ?") . "
        LIMIT 1
    ");
    $st->execute($bypassTenant ? [$docId] : [$docId, $tenantId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        http_response_code(404);
        exit('Document introuvable ou hors périmètre');
    }
    if ($doc['status'] === 'deleted') {
        http_response_code(410); // Gone
        exit('Document supprimé');
    }

    // Cascade de résolution path : 3 sources possibles
    $publicHtml = dirname(__DIR__);
    $path = '';

    // Source 1 : fluxbox_documents.fichier_chemin (path absolu legacy)
    $candidat = (string)($doc['flux_path'] ?? '');
    if ($candidat !== '' && is_file($candidat) && is_readable($candidat)) { $path = $candidat; }

    // Source 2 : ged_documents.final_destination (path relatif)
    if ($path === '' && !empty($doc['final_destination'])) {
        $candidat = $publicHtml . '/' . ltrim((string)$doc['final_destination'], '/');
        if (is_file($candidat) && is_readable($candidat)) { $path = $candidat; }
    }

    // Source 3 : metadata.public_url (fallback pipeline gus_commit_document)
    if ($path === '' && !empty($doc['metadata'])) {
        $meta = json_decode((string)$doc['metadata'], true) ?: [];
        $publicUrl = (string)($meta['public_url'] ?? '');
        if ($publicUrl !== '') {
            $candidat = $publicHtml . '/' . ltrim($publicUrl, '/');
            if (is_file($candidat) && is_readable($candidat)) { $path = $candidat; }
        }
    }

    // Source 4 : metadata.source_path (chemin ABSOLU posé par gus_commit_document,
    // utilisé par les imports OneDrive → storage_fluxbox). Idem api/ged_doc_serve.php.
    if ($path === '' && !empty($doc['metadata'])) {
        $meta = json_decode((string)$doc['metadata'], true) ?: [];
        $sp = (string)($meta['source_path'] ?? '');
        if ($sp !== '' && is_file($sp) && is_readable($sp)) { $path = $sp; }
    }

    if ($path === '') {
        http_response_code(404);
        exit('Fichier physique introuvable. (Aucune source path/final_destination/metadata n\'a abouti.)');
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
