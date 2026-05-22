<?php
// api/transaction_priorite_save.php — Met à jour biens.priorite_vente
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$idBien = (int)(post('id_bien') ?? 0);
$prio   = trim((string)(post('priorite_vente') ?? ''));

if ($idBien <= 0) { echo json_encode(['ok'=>false,'error'=>'id_bien manquant']); exit; }
if ($prio !== '' && !in_array($prio, ['haute','normale','differee'], true)) {
    echo json_encode(['ok'=>false,'error'=>'priorite invalide']); exit;
}

$roleId    = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isManager = ($roleId === 1 || $roleId === 2);
$idSoc     = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;

try {
    $st = $pdo->prepare('SELECT id_societe FROM biens WHERE id = ? LIMIT 1');
    $st->execute([$idBien]);
    $bSoc = $st->fetchColumn();
    if ($bSoc === false) { echo json_encode(['ok'=>false,'error'=>'bien introuvable']); exit; }
    if (!$isManager && $idSoc !== null && $bSoc !== null && (int)$bSoc !== $idSoc) {
        http_response_code(403); echo json_encode(['ok'=>false,'error'=>'hors scope']); exit;
    }

    $upd = $pdo->prepare('UPDATE biens SET priorite_vente = :p, date_modification = NOW() WHERE id = :id');
    $upd->execute([':p' => ($prio === '' ? null : $prio), ':id' => $idBien]);

    echo json_encode(['ok'=>true, 'id_bien'=>$idBien, 'priorite'=>$prio]);
} catch (Throwable $e) {
    error_log('[transaction_priorite_save] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
