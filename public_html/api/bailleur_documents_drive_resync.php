<?php
declare(strict_types=1);

/**
 * GED Bailleur — Retry sync Google Drive pour un document bailleur_documents.
 * Fichier : public_html/api/bailleur_documents_drive_resync.php
 *
 * POST : id=<bailleur_documents.id>
 * Sécurité : user doit avoir accès au propriétaire du document (ou admin).
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$roleId = (int)current_role_id();
$isAdmin = in_array($roleId, [1, 7, 8], true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('POST requis');
}

verify_csrf_any();

$docId = (int)($_POST['id'] ?? 0);
if ($docId <= 0) {
    http_response_code(400);
    exit('id manquant');
}

// Charge le doc
$st = $pdo->prepare("SELECT * FROM bailleur_documents WHERE id = ?");
$st->execute([$docId]);
$d = $st->fetch(PDO::FETCH_ASSOC);
if (!$d) {
    http_response_code(404);
    exit('Document introuvable');
}

$propId = (int)($d['id_proprietaire'] ?? 0);
if ($propId <= 0) {
    http_response_code(400);
    exit('Document sans propriétaire');
}

// Contrôle d'accès (owner)
if (!$isAdmin) {
    $chk = $pdo->prepare("SELECT 1 FROM user_proprietaires WHERE id_user = ? AND id_proprietaire = ? LIMIT 1");
    $chk->execute([$userId, $propId]);
    if (!$chk->fetchColumn()) {
        http_response_code(403);
        exit('Accès refusé');
    }
}

// Vérifie que les colonnes Drive existent (sinon, pas de retry possible)
$hasDriveCols = false;
try {
    $col = $pdo->query("SHOW COLUMNS FROM bailleur_documents LIKE 'drive_sync_status'")->fetch();
    $hasDriveCols = ($col !== false);
} catch (Throwable) { $hasDriveCols = false; }
if (!$hasDriveCols) {
    http_response_code(500);
    exit('Colonnes Drive absentes (applique sql/ged/004_extend_bailleur_documents_drive_sync.sql).');
}

// Chemin local
$rel = (string)($d['chemin_fichier'] ?? '');
$rel = ltrim($rel, '/');
if ($rel === '' || !str_starts_with($rel, 'uploads/bailleur_docs/')) {
    http_response_code(400);
    exit('Chemin local invalide');
}

$abs = dirname(__DIR__) . '/' . $rel;
if (!is_file($abs)) {
    http_response_code(404);
    exit('Fichier local introuvable : ' . htmlspecialchars($rel, ENT_QUOTES, 'UTF-8'));
}

// Sync Drive
require_once dirname(__DIR__) . '/modules/ged/ged_storage.php';
require_once dirname(__DIR__) . '/modules/ged/ged_logger.php';

$pdo->prepare("
    UPDATE bailleur_documents
    SET drive_sync_status = 'pending',
        drive_sync_error = NULL,
        drive_sync_attempts = COALESCE(drive_sync_attempts, 0) + 1,
        drive_sync_last_attempt_at = NOW()
    WHERE id = ?
")->execute([$docId]);

try {
    $drv = ged_storage_default();
    if ($drv->getName() !== 'google_drive') {
        throw new RuntimeException("Driver actif = {$drv->getName()} (Drive non configuré ou désactivé).");
    }

    $year = !empty($d['annee']) ? (int)$d['annee'] : (int)date('Y');
    $folderId = $drv->ensureFolder('03_GESTION_LOCATIVE');
    $folderId = $drv->ensureFolder('PROPRIETAIRE_' . $propId, $folderId);
    $folderId = $drv->ensureFolder((string)$year, $folderId);

    $origName = (string)($d['nom_fichier'] ?? ('doc_' . $docId));
    $mime = (string)($d['mime_type'] ?? '');
    $remoteName = 'BAILLEUR_' . $propId . '_' . date('Y-m-d') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);

    $up = $drv->upload($abs, $remoteName, $folderId, $mime !== '' ? $mime : null);
    $fileId = (string)($up['file_id'] ?? '');
    if ($fileId === '') {
        throw new RuntimeException('Upload Drive OK mais file_id manquant.');
    }
    $url = ged_drive_file_url($fileId);

    $pdo->prepare("
        UPDATE bailleur_documents
        SET drive_sync_status = 'success',
            drive_sync_error = NULL,
            drive_sync_success_at = NOW(),
            google_drive_file_id = ?,
            google_drive_folder_id = ?,
            google_drive_url = ?
        WHERE id = ?
    ")->execute([$fileId, (string)($up['folder_id'] ?? $folderId), $url, $docId]);

    gedLog('drive_sync', 'bailleur_documents: resync ok', [
        'bailleur_document_id' => $docId,
        'id_proprietaire'      => $propId,
        'drive_file_id'        => $fileId,
    ]);

    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../bailleur_ged.php'));
    exit;

} catch (Throwable $e) {
    $err = $e->getMessage();
    $pdo->prepare("
        UPDATE bailleur_documents
        SET drive_sync_status = 'error',
            drive_sync_error = ?,
            drive_sync_last_attempt_at = NOW()
        WHERE id = ?
    ")->execute([mb_substr($err, 0, 2000), $docId]);

    gedLog('drive_sync', 'bailleur_documents: resync error', [
        'bailleur_document_id' => $docId,
        'id_proprietaire'      => $propId,
        'error'                => $err,
    ]);

    http_response_code(500);
    exit('Erreur sync Drive : ' . htmlspecialchars($err, ENT_QUOTES, 'UTF-8'));
}

