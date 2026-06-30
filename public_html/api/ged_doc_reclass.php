<?php
/**
 * api/ged_doc_reclass.php
 *
 * Re-classer un document GED : modifier document_type, source_module, et/ou
 * relation_type sur ses liens. Régénère automatiquement name_display (V4) et
 * name_file (V3.1) en re-utilisant le naming_ctx stocké en metadata.
 *
 * Garde une trace dans metadata.extra.reclass_history[] (audit).
 *
 * POST JSON :
 *   {
 *     "doc_id": 81,
 *     "document_type": "MANDAT_VENTE",      // optionnel
 *     "source_module": "05_TRANSACTION",    // optionnel
 *     "n1_slug": "06_transaction",          // optionnel (affecte name_file V3.1)
 *     "n2_slug": "biens",                   // optionnel
 *     "n3_slug": "mandat",                  // optionnel
 *     "regenerate_name": true               // default true
 *   }
 *
 * Réservé super admin (id_role=1) pour l'instant. À étendre en role check par doc.
 *
 * Sprint R-RECLASS — 2026-05-25.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/ged_doc_naming_v3.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'];
$roleId      = (int)($_SESSION['id_role']    ?? 0);
$userId      = (int)($_SESSION['user_id']    ?? 0);
$userSocId   = (int)($_SESSION['id_societe'] ?? 0);
$isAdmin     = ($roleId === 1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}

$body = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$docId          = (int)($body['doc_id'] ?? 0);
$newDocType     = isset($body['document_type']) ? trim((string)$body['document_type']) : null;
$newSourceMod   = isset($body['source_module']) ? trim((string)$body['source_module']) : null;
$newN1          = isset($body['n1_slug']) ? trim((string)$body['n1_slug']) : null;
$newN2          = isset($body['n2_slug']) ? trim((string)$body['n2_slug']) : null;
$newN3          = isset($body['n3_slug']) ? trim((string)$body['n3_slug']) : null;
$regenerateName = $body['regenerate_name'] ?? true;

if ($docId <= 0) {
    exit(json_encode(['ok' => false, 'error' => 'doc_id requis']));
}

// Lecture doc actuel + scope check societe (sauf super admin qui voit tout)
$st = $pdo->prepare("SELECT id, document_type, source_module, name_display, name_canonical,
                            name_file, metadata, hash_sha256, societe_id, tenant_id
                     FROM ged_documents WHERE id = ?");
$st->execute([$docId]);
$doc = $st->fetch(PDO::FETCH_ASSOC);
if (!$doc) {
    http_response_code(404);
    exit(json_encode(['ok' => false, 'error' => 'Document introuvable']));
}

// Scope check : un user normal ne peut re-classer que les docs de sa société
if (!$isAdmin && !empty($doc['societe_id']) && (int)$doc['societe_id'] !== $userSocId) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Document hors de votre société']));
}

$metadata = json_decode((string)($doc['metadata'] ?? '{}'), true) ?: [];
$namingCtx = is_array($metadata['naming_ctx'] ?? null) ? $metadata['naming_ctx'] : [];

$changes = [];
$updates = [];
$params  = [];

if ($newDocType !== null && $newDocType !== '' && $newDocType !== (string)$doc['document_type']) {
    $changes['document_type'] = ['from' => $doc['document_type'], 'to' => $newDocType];
    $updates[] = 'document_type = :document_type';
    $params[':document_type'] = $newDocType;
    $namingCtx['type_doc'] = $newDocType;
}

if ($newSourceMod !== null && $newSourceMod !== '' && $newSourceMod !== (string)$doc['source_module']) {
    $changes['source_module'] = ['from' => $doc['source_module'], 'to' => $newSourceMod];
    $updates[] = 'source_module = :source_module';
    $params[':source_module'] = $newSourceMod;
}

if ($newN1 !== null && $newN1 !== '') {
    $changes['n1_slug'] = ['from' => $namingCtx['n1_slug'] ?? null, 'to' => $newN1];
    $namingCtx['n1_slug'] = $newN1;
}
if ($newN2 !== null && $newN2 !== '') {
    $changes['n2_slug'] = ['from' => $namingCtx['n2_slug'] ?? null, 'to' => $newN2];
    $namingCtx['n2_slug'] = $newN2;
}
if ($newN3 !== null && $newN3 !== '') {
    $changes['n3_slug'] = ['from' => $namingCtx['n3_slug'] ?? null, 'to' => $newN3];
    $namingCtx['n3_slug'] = $newN3;
}

if (empty($changes)) {
    exit(json_encode(['ok' => false, 'error' => 'Aucun changement détecté']));
}

// Régénération des names
$newNameFile    = $doc['name_file'];
$newNameDisplay = $doc['name_display'];
$newNameCanon   = $doc['name_canonical'];

if ($regenerateName) {
    try {
        $newNameFile    = gdn_v3_build($namingCtx);
        if (function_exists('gdn_v4_build_display')) {
            $newNameDisplay = gdn_v4_build_display($namingCtx);
            $newNameCanon   = pathinfo($newNameDisplay, PATHINFO_FILENAME);
        }
        $updates[] = 'name_file = :name_file';
        $updates[] = 'name_display = :name_display';
        $updates[] = 'name_canonical = :name_canonical';
        $params[':name_file']      = $newNameFile;
        $params[':name_display']   = $newNameDisplay;
        $params[':name_canonical'] = $newNameCanon;
    } catch (Throwable $e) {
        // Si la régénération échoue, on garde les anciens names
        error_log('[ged_doc_reclass] régénération name échouée : ' . $e->getMessage());
    }
}

// Update naming_ctx + audit reclass_history dans metadata
$metadata['naming_ctx'] = $namingCtx;
if (!isset($metadata['extra']) || !is_array($metadata['extra'])) {
    $metadata['extra'] = [];
}
if (!isset($metadata['extra']['reclass_history']) || !is_array($metadata['extra']['reclass_history'])) {
    $metadata['extra']['reclass_history'] = [];
}
$metadata['extra']['reclass_history'][] = [
    'at'      => date('Y-m-d H:i:s'),
    'by'      => $userId ?: null,
    'changes' => $changes,
    'old_name_file'    => $doc['name_file'],
    'old_name_display' => $doc['name_display'],
];

$updates[] = 'metadata = :metadata';
$updates[] = 'updated_at = NOW()';
$params[':metadata'] = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
$params[':id']       = $docId;

try {
    $sql = "UPDATE ged_documents SET " . implode(', ', $updates) . " WHERE id = :id";
    $pdo->prepare($sql)->execute($params);
} catch (Throwable $e) {
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => 'UPDATE échoué : ' . $e->getMessage()]));
}

echo json_encode([
    'ok' => true,
    'doc_id'              => $docId,
    'changes'             => $changes,
    'new_name_file'       => $newNameFile,
    'new_name_display'    => $newNameDisplay,
    'new_name_canonical'  => $newNameCanon,
    'new_document_type'   => $newDocType ?? $doc['document_type'],
    'new_source_module'   => $newSourceMod ?? $doc['source_module'],
], JSON_UNESCAPED_UNICODE);
