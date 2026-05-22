<?php
// api/transaction_chg_staging_list.php — Liste les fichiers staging de l'user
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/transaction_chg_staging.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

tr_staging_ensure_table($pdo);

$userId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);

try {
    $st = $pdo->prepare('SELECT id, filename, mime_type, size_bytes, metadata, created_at
                         FROM transaction_chargement_staging
                         WHERE user_id = ?
                         ORDER BY id ASC LIMIT 200');
    $st->execute([$userId]);
    $items = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $meta = is_string($r['metadata']) ? (json_decode($r['metadata'], true) ?: []) : [];
        $items[] = [
            'staging_id' => (int)$r['id'],
            'filename'   => $r['filename'],
            'mime_type'  => $r['mime_type'],
            'size_bytes' => (int)$r['size_bytes'],
            'metadata'   => $meta,
            'created_at' => $r['created_at'],
        ];
    }
    echo json_encode(['ok'=>true, 'items'=>$items]);
} catch (Throwable $e) {
    error_log('[staging_list] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'items'=>[], 'error'=>$e->getMessage()]);
}
