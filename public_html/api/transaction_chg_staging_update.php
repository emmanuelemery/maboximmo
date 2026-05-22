<?php
// api/transaction_chg_staging_update.php — Met à jour les metadata d'une ligne staging
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/transaction_chg_staging.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$pdo = db_keepalive();
tr_staging_ensure_table($pdo);

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$stagingId = (int)(post('staging_id') ?? 0);
$metaJson  = (string)(post('metadata') ?? '');
if ($stagingId <= 0) { echo json_encode(['ok'=>false,'error'=>'staging_id manquant']); exit; }

$userId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);

try {
    $row = tr_staging_check_owner($pdo, $stagingId, $userId);
    if (!$row) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'staging introuvable']); exit; }

    // Merge metadata existante avec celle reçue (partial update)
    $cur = is_string($row['metadata']) ? (json_decode($row['metadata'], true) ?: []) : [];
    $new = json_decode($metaJson, true);
    if (!is_array($new)) { echo json_encode(['ok'=>false,'error'=>'metadata invalide']); exit; }
    $merged = array_replace($cur, $new);

    $pdo->prepare('UPDATE transaction_chargement_staging SET metadata = ? WHERE id = ?')
        ->execute([json_encode($merged, JSON_UNESCAPED_UNICODE), $stagingId]);

    echo json_encode(['ok'=>true, 'metadata'=>$merged]);
} catch (Throwable $e) {
    error_log('[staging_update] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
