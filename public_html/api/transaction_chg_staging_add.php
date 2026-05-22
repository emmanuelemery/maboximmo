<?php
// api/transaction_chg_staging_add.php — Upload immédiat d'un fichier en staging
declare(strict_types=1);
@ini_set('display_errors', '0');
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/transaction_chg_staging.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

tr_staging_ensure_table($pdo);

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
if (!isset($_FILES['fichier']) || ($_FILES['fichier']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    echo json_encode(['ok'=>false,'error'=>'fichier manquant']); exit;
}

$userId  = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);
$detected= trim((string)(post('detected_type') ?? 'AUTRE'));

try {
    $orig    = (string)$_FILES['fichier']['name'];
    $safeFn  = tr_staging_safe_name($orig);
    $size    = (int)($_FILES['fichier']['size'] ?? 0);
    $mime    = function_exists('mime_content_type') ? (mime_content_type($_FILES['fichier']['tmp_name']) ?: 'application/octet-stream') : 'application/octet-stream';

    // 1. INSERT row pour obtenir un id
    $meta = [
        'detected_type'    => $detected,
        'type'             => $detected,    // type courant (peut être changé par l'user)
        'ia_data'          => null,
        'match_biens'      => [],
        'selected_bien_id' => null,
        'confidence'       => 0,
        'status'           => 'pending',
        'error'            => null,
    ];
    $ins = $pdo->prepare('INSERT INTO transaction_chargement_staging
        (user_id, filename, stored_path, mime_type, size_bytes, metadata)
        VALUES (?, ?, "", ?, ?, ?)');
    $ins->execute([$userId, $orig, $mime, $size, json_encode($meta, JSON_UNESCAPED_UNICODE)]);
    $stagingId = (int)$pdo->lastInsertId();

    // 2. Move file vers staging dir avec l'id pour unicité
    $dir = tr_staging_dir($userId);
    $finalName = $stagingId . '_' . $safeFn;
    $finalPath = $dir . '/' . $finalName;
    if (!move_uploaded_file($_FILES['fichier']['tmp_name'], $finalPath)) {
        // rollback row
        $pdo->prepare('DELETE FROM transaction_chargement_staging WHERE id = ?')->execute([$stagingId]);
        throw new RuntimeException('move_uploaded_file failed');
    }

    // 3. Update row avec le chemin (relatif au webroot pour portabilité)
    $relPath = 'uploads/transaction_staging/' . $userId . '/' . $finalName;
    $pdo->prepare('UPDATE transaction_chargement_staging SET stored_path = ? WHERE id = ?')
        ->execute([$relPath, $stagingId]);

    echo json_encode([
        'ok'         => true,
        'staging_id' => $stagingId,
        'filename'   => $orig,
        'mime_type'  => $mime,
        'size_bytes' => $size,
        'metadata'   => $meta,
    ]);
} catch (Throwable $e) {
    error_log('[staging_add] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
