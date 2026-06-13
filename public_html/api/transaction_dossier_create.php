<?php
// api/transaction_dossier_create.php — Crée (idempotent) le dossier de vente d'un bien.
// POST : id_bien  →  { ok, id_dossier, etape, created }
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/dossier_vente.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$idBien = (int)(post('id_bien') ?? 0);
if ($idBien <= 0) { echo json_encode(['ok'=>false,'error'=>'id_bien manquant']); exit; }

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

    $existait = dv_get_by_bien($pdo, $idBien) !== null;
    $idDossier = dv_ensure_for_bien($pdo, $idBien, ['source' => 'manual', 'id_user' => current_user_id()]);
    if ($idDossier <= 0) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'création impossible']); exit; }

    $d = dv_get($pdo, $idDossier);
    echo json_encode([
        'ok'         => true,
        'id_dossier' => $idDossier,
        'etape'      => $d['etape'] ?? null,
        'created'    => !$existait,
        'url'        => app_url('/transaction_dossier.php?id=' . $idDossier),
    ]);
} catch (Throwable $e) {
    error_log('[transaction_dossier_create] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
