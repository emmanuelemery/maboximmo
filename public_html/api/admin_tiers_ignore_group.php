<?php
// api/admin_tiers_ignore_group.php — Marquer un groupe de tiers comme distincts intentionnels
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/admin_tiers_scope.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

// Auto-create table si absente
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `tiers_non_doublons` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `criteria_type` VARCHAR(20) NOT NULL COMMENT 'siren|email|raison|nom',
        `criteria_key`  VARCHAR(255) NOT NULL,
        `tiers_ids_csv` VARCHAR(500) NOT NULL COMMENT 'CSV des ids du groupe (triés)',
        `notes`         TEXT NULL,
        `marked_by`     INT UNSIGNED NULL,
        `marked_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_group` (`criteria_type`, `criteria_key`, `tiers_ids_csv`),
        INDEX `idx_type_key` (`criteria_type`, `criteria_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

$action       = (string)(post('action') ?? 'add');
$criteriaType = trim((string)(post('criteria_type') ?? ''));
$criteriaKey  = trim((string)(post('criteria_key') ?? ''));
$tiersIds     = post('tiers_ids') ?? [];

if (!in_array($criteriaType, ['siren','email','raison','nom'], true)) {
    echo json_encode(['ok'=>false,'error'=>'criteria_type invalide']); exit;
}

if (is_string($tiersIds)) $tiersIds = explode(',', $tiersIds);
$tiersIds = array_values(array_unique(array_filter(array_map('intval', (array)$tiersIds))));
sort($tiersIds); // canonique
if (count($tiersIds) < 2) { echo json_encode(['ok'=>false,'error'=>'au moins 2 ids requis']); exit; }
$idsCsv = implode(',', $tiersIds);

// Scope check
$scope = tiers_check_scope($pdo, $tiersIds);
if (!$scope['ok']) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>$scope['error']]); exit; }

$userId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['id_user'] ?? $_SESSION['id'] ?? 0);

try {
    if ($action === 'add') {
        $notes = trim((string)(post('notes') ?? ''));
        $st = $pdo->prepare('INSERT IGNORE INTO tiers_non_doublons
            (criteria_type, criteria_key, tiers_ids_csv, notes, marked_by)
            VALUES (?, ?, ?, ?, ?)');
        $st->execute([$criteriaType, $criteriaKey, $idsCsv, $notes ?: null, $userId ?: null]);
        echo json_encode(['ok'=>true, 'inserted'=>$st->rowCount() > 0]);
    } elseif ($action === 'remove') {
        // Re-marquer comme doublon potentiel = supprimer l'enregistrement d'ignore
        $st = $pdo->prepare('DELETE FROM tiers_non_doublons WHERE criteria_type = ? AND criteria_key = ? AND tiers_ids_csv = ?');
        $st->execute([$criteriaType, $criteriaKey, $idsCsv]);
        echo json_encode(['ok'=>true, 'deleted'=>$st->rowCount()]);
    } else {
        echo json_encode(['ok'=>false,'error'=>'action inconnue']);
    }
} catch (Throwable $e) {
    error_log('[admin_tiers_ignore_group] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
