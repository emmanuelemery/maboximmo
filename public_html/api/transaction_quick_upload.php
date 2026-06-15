<?php
/**
 * api/transaction_quick_upload.php
 *
 * MVP Transaction E2E V0 + Sprint 5 — Upload multi-fichiers / ZIP.
 *
 * Reçoit POST avec :
 *   - bien_id (requis)
 *   - document[] (1 ou N fichiers ; .zip est auto-extrait)
 *
 * Workflow par fichier :
 *   1. Validation taille / mime
 *   2. Si .zip → extract → boucle récursive sur chaque fichier interne
 *   3. Sinon : SHA-256 + INSERT fluxbox_documents (dedup) + INSERT fluxbox_cartes
 *   4. Si 1 seul doc à la fin → redirect transaction_upload_review.php?doc_id=X
 *   5. Si N docs → redirect bien_documents_list.php?id=X avec flash récap
 *
 * Sécurité : super admin ou agence du bien (scope id_societe).
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

$pdo = $GLOBALS['pdo'];
$userId  = (int)($_SESSION['id_user'] ?? 0);
$roleId  = (int)($_SESSION['id_role'] ?? 0);
$isAdmin = ($roleId === 1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('POST only'); }

$bienId = (int)($_POST['bien_id'] ?? 0);
if ($bienId <= 0) { http_response_code(400); exit('bien_id requis'); }

// Vérif bien + scope
$st = $pdo->prepare("SELECT id, id_societe, id_agence FROM biens WHERE id = ?");
$st->execute([$bienId]);
$bien = $st->fetch(PDO::FETCH_ASSOC);
if (!$bien) { http_response_code(404); exit('Bien introuvable'); }
if (!$isAdmin && (int)$bien['id_societe'] !== (int)($_SESSION['id_societe'] ?? 0)) {
    http_response_code(403); exit('Hors société');
}

// ─── Helpers ──
function tqu_redirect_list(int $bienId, string $status, string $msg = ''): void {
    $loc = "/MaBoxImmo2026/public_html/bien_documents_list.php?id=$bienId"
         . "&commit=" . rawurlencode($status)
         . ($msg !== '' ? '&msg=' . rawurlencode($msg) : '');
    header("Location: $loc");
    exit;
}
function tqu_redirect_review(int $bienId, int $docId, int $cardId): void {
    header("Location: /MaBoxImmo2026/public_html/transaction_upload_review.php?bien_id=$bienId&doc_id=$docId&card_id=$cardId");
    exit;
}

// ─── Collecter les fichiers PHP $_FILES (multi) ──
$files = [];
if (!empty($_FILES['document'])) {
    $f = $_FILES['document'];
    if (is_array($f['name'])) {
        // Format multi-file array
        for ($i = 0; $i < count($f['name']); $i++) {
            if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $files[] = [
                    'name'     => (string)$f['name'][$i],
                    'tmp_name' => (string)$f['tmp_name'][$i],
                    'size'     => (int)$f['size'][$i],
                ];
            }
        }
    } else {
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $files[] = [
                'name'     => (string)$f['name'],
                'tmp_name' => (string)$f['tmp_name'],
                'size'     => (int)$f['size'],
            ];
        }
    }
}
if (empty($files)) { tqu_redirect_list($bienId, 'ko', 'Aucun fichier reçu'); }

// ─── Storage path setup ──
$societeId = (int)$bien['id_societe'] ?: 0;
$tenantId  = $societeId;
$storageBase = realpath(dirname(__DIR__)) . DIRECTORY_SEPARATOR . 'storage_fluxbox';
if (!is_dir($storageBase)) @mkdir($storageBase, 0775, true);
$year = date('Y'); $month = date('m');
$storageDir = $storageBase . DIRECTORY_SEPARATOR . $societeId . DIRECTORY_SEPARATOR . $year . DIRECTORY_SEPARATOR . $month;
if (!is_dir($storageDir)) @mkdir($storageDir, 0775, true);

// ─── Itérateur "process one file" (peut être appelé récursivement depuis ZIP) ──
$results = ['ok' => [], 'skip' => [], 'err' => []];

$processOne = function(string $origName, string $srcPath, int $size, string $mime) use (
    $pdo, $bienId, $tenantId, $userId, $storageDir, &$results
) {
    if ($size > 50 * 1024 * 1024) {
        $results['err'][] = "$origName : > 50 Mo";
        return null;
    }
    $hash = hash_file('sha256', $srcPath);
    if (!$hash) { $results['err'][] = "$origName : hash échec"; return null; }

    // Storage
    $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $origName) ?: 'doc';
    $destFilename = date('Ymd_His') . '_' . substr($hash, 0, 8) . '_' . $safeName;
    $destPath = $storageDir . DIRECTORY_SEPARATOR . $destFilename;

    // Si srcPath est un upload PHP, utiliser move_uploaded_file ; sinon copy (ZIP extracted)
    $movedOk = is_uploaded_file($srcPath)
             ? move_uploaded_file($srcPath, $destPath)
             : copy($srcPath, $destPath);
    if (!$movedOk) { $results['err'][] = "$origName : move échec"; return null; }

    // INSERT idempotent
    try {
        $st = $pdo->prepare("SELECT id FROM fluxbox_documents WHERE tenant_id = ? AND hash_sha256 = ? LIMIT 1");
        $st->execute([$tenantId, $hash]);
        $existing = (int)$st->fetchColumn();

        if ($existing) {
            $pdo->prepare("UPDATE fluxbox_documents SET last_seen_at = NOW(), seen_count = seen_count + 1 WHERE id = ?")
                ->execute([$existing]);
            $docId = $existing;
            $results['skip'][] = ['name' => $origName, 'doc_id' => $docId, 'hash' => substr($hash, 0, 12)];
        } else {
            $st = $pdo->prepare("
                INSERT INTO fluxbox_documents
                    (tenant_id, hash_sha256, source_type, source_meta,
                     fichier_nom, fichier_chemin, taille_octets, mime_type,
                     ocr_status, first_seen_at, last_seen_at, seen_count, created_by, created_at)
                VALUES (?, ?, 'manual', ?, ?, ?, ?, ?, 'pending', NOW(), NOW(), 1, ?, NOW())
            ");
            $st->execute([
                $tenantId, $hash,
                json_encode(['bien_id' => $bienId, 'origin' => 'transaction_quick_upload_multi'], JSON_UNESCAPED_UNICODE),
                $origName, $destPath, $size, $mime, $userId ?: null,
            ]);
            $docId = (int)$pdo->lastInsertId();
            $results['ok'][] = ['name' => $origName, 'doc_id' => $docId, 'hash' => substr($hash, 0, 12)];
        }

        // Carte associée
        $st = $pdo->prepare("SELECT id FROM fluxbox_cartes WHERE document_id = ? LIMIT 1");
        $st->execute([$docId]);
        $cardId = (int)$st->fetchColumn();
        if ($cardId === 0) {
            $st = $pdo->prepare("
                INSERT INTO fluxbox_cartes
                    (tenant_id, document_id, titre, sous_titre, priorite, statut, proposition_json, created_by, created_at)
                VALUES (?, ?, ?, ?, 'normal', 'pending', ?, ?, NOW())
            ");
            $st->execute([
                $tenantId, $docId,
                mb_substr("Doc Transaction · bien #$bienId · $origName", 0, 255),
                'En attente de validation MVP-T',
                json_encode(['mvp_transaction_v0_pending' => true, 'bien_id' => $bienId], JSON_UNESCAPED_UNICODE),
                $userId ?: null,
            ]);
            $cardId = (int)$pdo->lastInsertId();
        }
        return ['doc_id' => $docId, 'card_id' => $cardId];
    } catch (Throwable $e) {
        $results['err'][] = "$origName : BDD " . $e->getMessage();
        return null;
    }
};

// ─── Boucle sur fichiers reçus ──
$firstResult = null;
$totalProcessed = 0;
foreach ($files as $file) {
    $ext = strtolower((string)pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($ext === 'zip') {
        // Extraction ZIP
        $zip = new ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) {
            $results['err'][] = $file['name'] . " : ouverture ZIP échec";
            continue;
        }
        $tmpExtract = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mvpt_zip_' . uniqid();
        @mkdir($tmpExtract, 0775, true);
        $zip->extractTo($tmpExtract);
        $zip->close();

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpExtract, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $name = $f->getFilename();
            // Filtrer mac metadata
            if (str_starts_with($name, '._') || str_starts_with($name, '.DS_Store')) continue;
            $subExt = strtolower($f->getExtension());
            if (!in_array($subExt, ['pdf','jpg','jpeg','png','tiff','tif','docx','xlsx','msg'], true)) continue;

            $subMime = function_exists('mime_content_type') ? (mime_content_type($f->getPathname()) ?: 'application/octet-stream') : 'application/octet-stream';
            $res = $processOne($name, $f->getPathname(), $f->getSize(), $subMime);
            if ($res && !$firstResult) $firstResult = $res;
            $totalProcessed++;
        }
        // Cleanup tmp
        @system('rmdir /s /q "' . $tmpExtract . '"');
        continue;
    }

    // Fichier simple
    $mime = function_exists('mime_content_type') ? (mime_content_type($file['tmp_name']) ?: 'application/octet-stream') : 'application/octet-stream';
    $res = $processOne($file['name'], $file['tmp_name'], $file['size'], $mime);
    if ($res && !$firstResult) $firstResult = $res;
    $totalProcessed++;
}

// ─── Redirection ──
$nbOk   = count($results['ok']);
$nbSkip = count($results['skip']);
$nbErr  = count($results['err']);

// Si 1 seul nouveau doc → review direct
if ($nbOk === 1 && $nbSkip === 0 && $nbErr === 0 && $firstResult) {
    tqu_redirect_review($bienId, $firstResult['doc_id'], $firstResult['card_id']);
}

// Sinon : retour liste avec récap
$msg = "$nbOk nouveau(x), $nbSkip déjà connu(s), $nbErr erreur(s)";
tqu_redirect_list($bienId, $nbErr === 0 ? 'ok' : 'partiel', $msg);
