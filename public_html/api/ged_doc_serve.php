<?php
/**
 * api/ged_doc_serve.php
 *
 * Servage binaire d'un document GED par son ID.
 * Résout le chemin physique via fluxbox_documents.fichier_chemin
 * (puisque ged_documents.name_file est juste le nom V3 logique).
 *
 * Sécurité : super admin ou agent agence (scope id_societe).
 * Pas de listing dossier, juste accès direct par doc_id.
 *
 * Sprint 5 — modal viewer universel.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

$pdo = $GLOBALS['pdo'];
$id  = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('id requis'); }

// Lecture ged_documents — inclut final_destination + metadata pour fallback
$st = $pdo->prepare("SELECT id, name_file, mime_type, societe_id, fluxbox_source_id,
                            final_destination, metadata
                     FROM ged_documents WHERE id = ?");
$st->execute([$id]);
$doc = $st->fetch(PDO::FETCH_ASSOC);
if (!$doc) { http_response_code(404); exit('Document introuvable'); }

// Scope check
$isAdmin = ((int)($_SESSION['id_role'] ?? 0) === 1);
if (!$isAdmin && !empty($doc['societe_id']) && (int)$doc['societe_id'] !== (int)($_SESSION['id_societe'] ?? 0)) {
    http_response_code(403); exit('Hors société');
}

// ─── Résolution chemin physique : 3 sources possibles (cascade fallback) ───
// FIX 2026-05-25 : ajout fallback final_destination + metadata.public_url pour
// supporter les ged_docs créés par gus_commit_document (Sprint 7) qui n'ont pas
// de fluxbox_source_id (origine bien_intake, immeuble_doc, bailleur_ged, etc.).
$path = null;
$publicHtml = dirname(__DIR__);

// Source 1 : fluxbox_documents.fichier_chemin (path absolu legacy FluxBox)
if (!empty($doc['fluxbox_source_id'])) {
    $st = $pdo->prepare("SELECT fichier_chemin FROM fluxbox_documents WHERE id = ?");
    $st->execute([(int)$doc['fluxbox_source_id']]);
    $candidat = (string)$st->fetchColumn();
    if ($candidat && is_file($candidat)) { $path = $candidat; }
}

// Source 2 : ged_documents.final_destination (path relatif servable)
if (!$path && !empty($doc['final_destination'])) {
    $rel = '/' . ltrim((string)$doc['final_destination'], '/');
    $candidat = $publicHtml . $rel;
    if (is_file($candidat)) { $path = $candidat; }
}

// Source 3 : metadata.public_url (fallback pour pipeline gus_commit_document)
if (!$path && !empty($doc['metadata'])) {
    $meta = json_decode((string)$doc['metadata'], true) ?: [];
    $publicUrl = (string)($meta['public_url'] ?? '');
    if ($publicUrl !== '') {
        $rel = '/' . ltrim($publicUrl, '/');
        $candidat = $publicHtml . $rel;
        if (is_file($candidat)) { $path = $candidat; }
    }
}

// Source 4 : metadata.source_path (chemin ABSOLU posé par gus_commit_document).
// La vérification de sécurité ci-dessous (anti path-traversal) borne ce chemin.
if (!$path && !empty($doc['metadata'])) {
    $meta = json_decode((string)$doc['metadata'], true) ?: [];
    $sp = (string)($meta['source_path'] ?? '');
    if ($sp !== '' && is_file($sp)) { $path = $sp; }
}

// Source 5 : REPLI OneDrive — le fichier vient d'un import OneDrive et n'a plus de copie
// locale servable → on ouvre directement le fichier sur OneDrive (web_url).
if (!$path && !empty($doc['metadata'])) {
    $meta = json_decode((string)$doc['metadata'], true) ?: [];
    $ex = $meta['extra'] ?? $meta;
    $webUrl = (string)($ex['onedrive_web_url'] ?? $meta['onedrive_web_url'] ?? '');
    if ($webUrl !== '' && preg_match('#^https://#i', $webUrl)) {
        header('Location: ' . $webUrl, true, 302);
        exit;
    }
}

if (!$path || !is_file($path)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Fichier physique introuvable.\n";
    echo "Sources testées :\n";
    echo "  - fluxbox_source_id: " . ($doc['fluxbox_source_id'] ?? 'null') . "\n";
    echo "  - final_destination: " . ($doc['final_destination'] ?? 'null') . "\n";
    $meta = $doc['metadata'] ? json_decode((string)$doc['metadata'], true) : null;
    $ex = $meta['extra'] ?? $meta ?? [];
    echo "  - metadata.public_url: " . ($meta['public_url'] ?? 'null') . "\n";
    echo "  - metadata.source_path: " . ($meta['source_path'] ?? 'null') . "\n";
    echo "  - onedrive_web_url: " . ($ex['onedrive_web_url'] ?? 'null') . "\n";
    exit;
}

// Vérification sécurité : le path doit être SOUS storage_fluxbox ou uploads
// (anti path-traversal). On normalise et on check le préfixe.
$realPath = realpath($path);
$publicHtml = realpath(dirname(__DIR__));
$allowedRoots = [
    $publicHtml . DIRECTORY_SEPARATOR . 'storage_fluxbox',
    $publicHtml . DIRECTORY_SEPARATOR . 'uploads',
];
$allowed = false;
foreach ($allowedRoots as $root) {
    if ($root && str_starts_with((string)$realPath, $root)) { $allowed = true; break; }
}
if (!$allowed) {
    http_response_code(403);
    exit('Chemin hors zone autorisée');
}

// Servage binaire
$mime = $doc['mime_type'] ?: (function_exists('mime_content_type') ? mime_content_type($realPath) : 'application/octet-stream');
$size = filesize($realPath);

header('Content-Type: ' . $mime);
header('Content-Length: ' . $size);
header('Content-Disposition: inline; filename="' . rawurlencode((string)($doc['name_file'] ?? 'doc.pdf')) . '"');
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');

readfile($realPath);
exit;
