<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'];
$userId = current_user_id();
$ts = isset($_GET['ts']) ? (int)$_GET['ts'] : 0;
$since = $ts > 0 ? date('Y-m-d H:i:s', $ts) : '1970-01-01 00:00:00';

try {
    $stmt = $pdo->prepare("SELECT question_id, note, commentaire, updated_at FROM rh_entretien_prep_user WHERE id_user = ? AND updated_at > ? ORDER BY updated_at ASC");
    $stmt->execute([$userId, $since]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $maxTs = $ts;
    foreach ($items as $it) {
        $t = strtotime((string)$it['updated_at']);
        if ($t > $maxTs) $maxTs = $t;
    }
    if ($maxTs <= 0) $maxTs = time();

    echo json_encode(['ok' => true, 'timestamp' => $maxTs, 'items' => $items]);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'error' => 'Erreur BDD']);
}