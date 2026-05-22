<?php
// api/transaction_chg_staging_delete.php — Supprime une ligne staging (et son fichier)
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/transaction_chg_staging.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

tr_staging_ensure_table($pdo);

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$stagingId = (int)(post('staging_id') ?? 0);
$wipeAll   = (int)(post('wipe_all') ?? 0) === 1;

$userId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);

try {
    if ($wipeAll) {
        // Tout vider pour cet user
        $st = $pdo->prepare('SELECT id, stored_path FROM transaction_chargement_staging WHERE user_id = ?');
        $st->execute([$userId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $abs = __DIR__ . '/../' . $r['stored_path'];
            if (is_file($abs)) @unlink($abs);
        }
        $pdo->prepare('DELETE FROM transaction_chargement_staging WHERE user_id = ?')->execute([$userId]);
        echo json_encode(['ok'=>true, 'wiped'=>count($rows)]);
        exit;
    }

    if ($stagingId <= 0) { echo json_encode(['ok'=>false,'error'=>'staging_id manquant']); exit; }
    $row = tr_staging_check_owner($pdo, $stagingId, $userId);
    if (!$row) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'staging introuvable']); exit; }

    $abs = __DIR__ . '/../' . $row['stored_path'];
    if (is_file($abs)) @unlink($abs);
    $pdo->prepare('DELETE FROM transaction_chargement_staging WHERE id = ?')->execute([$stagingId]);

    echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
    error_log('[staging_delete] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
