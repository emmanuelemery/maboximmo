<?php
/**
 * POST /api/rh_doc_archive.php
 *
 * Actions sur documents RH : archiver, désarchiver, supprimer (soft delete).
 *
 * Body JSON :
 *   { "doc_id": 42, "action": "archive" | "unarchive" | "delete" }
 *
 * Accès :
 *   - archive/delete : user propriétaire OU admin
 *   - unarchive : admin uniquement
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();
verify_csrf_any();
header('Content-Type: application/json; charset=utf-8');

$pdo    = $GLOBALS['pdo'];
$meId   = SecurityGuard::userId();
$roleId = SecurityGuard::roleId();

$body   = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$docId  = (int)($body['doc_id'] ?? 0);
$action = (string)($body['action'] ?? '');

if ($docId <= 0 || !in_array($action, ['archive', 'unarchive', 'delete'], true)) {
    api_error('Paramètres invalides (doc_id + action requis)', 400);
}

// Récupérer le doc avec filtre tenant
$stmt = $pdo->prepare("SELECT * FROM rh_documents WHERE id = ?" . SecurityGuard::sqlAnd('id_user'));
$stmt->execute(array_merge([$docId], SecurityGuard::sqlParams('id_user')));
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    api_error('Document introuvable', 404);
}

switch ($action) {
    case 'archive':
        $pdo->prepare("UPDATE rh_documents SET archived_at = NOW(), archived_by = ? WHERE id = ?" . SecurityGuard::sqlAnd('id_user'))
            ->execute(array_merge([$meId, $docId], SecurityGuard::sqlParams('id_user')));
        AuditLog::log($pdo, 'ARCHIVE', 'rh_documents', $docId, $doc);
        api_success([], 'Document archivé');
        break;

    case 'unarchive':
        // Désarchiver = admin uniquement
        if (!SecurityGuard::isAdmin()) {
            api_error('Seul un administrateur peut désarchiver', 403);
        }
        $pdo->prepare("UPDATE rh_documents SET archived_at = NULL, archived_by = NULL WHERE id = ?")
            ->execute([$docId]);
        AuditLog::log($pdo, 'UNARCHIVE', 'rh_documents', $docId, $doc);
        api_success([], 'Document désarchivé');
        break;

    case 'delete':
        // Soft delete (actif = 0)
        $pdo->prepare("UPDATE rh_documents SET actif = 0 WHERE id = ?" . SecurityGuard::sqlAnd('id_user'))
            ->execute(array_merge([$docId], SecurityGuard::sqlParams('id_user')));
        // Supprimer fichier physique
        $path = !empty($doc['file_path']) ? $doc['file_path']
              : (dirname(__DIR__) . '/uploads/rh_docs/' . $doc['id_user'] . '/' . $doc['filename']);
        if ($path && file_exists($path)) @unlink($path);
        AuditLog::log($pdo, 'DELETE', 'rh_documents', $docId, $doc);
        api_success([], 'Document supprimé');
        break;
}
