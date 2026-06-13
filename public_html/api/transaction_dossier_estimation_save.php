<?php
// api/transaction_dossier_estimation_save.php — Saisit/maj le prix d'estimation depuis le dossier.
// Écrit dans bien_prix (source unique, scénario 'courant') via le helper partagé, puis
// garantit le dossier et resynchronise l'étape. POST : id_dossier, prix  →  { ok, prix, etape }
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/bien_prix.php';
require_once __DIR__ . '/../inc/dossier_vente.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$idDossier = (int)(post('id_dossier') ?? 0);
$raw       = (string)(post('prix') ?? '');
$prix      = $raw !== '' ? (float)str_replace([' ', ','], ['', '.'], $raw) : null;

if ($idDossier <= 0) { echo json_encode(['ok'=>false,'error'=>'id_dossier manquant']); exit; }
if ($prix === null || $prix <= 0 || $prix > 1e11) { echo json_encode(['ok'=>false,'error'=>'prix invalide']); exit; }

$dossier = dv_get($pdo, $idDossier);
if (!$dossier) { echo json_encode(['ok'=>false,'error'=>'dossier introuvable']); exit; }
$idBien = (int)$dossier['id_bien'];

// Scope société (super admin / manager bypass).
$roleId    = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isManager = ($roleId === 1 || $roleId === 2);
$idSoc     = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
if (!$isManager && $idSoc !== null && (int)$dossier['id_societe'] !== $idSoc) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'hors scope']); exit;
}

try {
    // Source unique : bien_prix (prix_vente, scénario courant), miroirs annonce/biens gérés par le helper.
    $res = bien_prix_valider($pdo, $idBien, 'prix_vente', $prix, 'transaction',
                             (int)current_user_id(), 'Estimation dossier de vente', true, 'courant', 'Courant');
    if (empty($res['ok'])) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>$res['error'] ?? 'échec validation']); exit; }

    // Garantit le dossier + resynchronise l'étape (au moins 'estimation').
    dv_ensure_for_bien($pdo, $idBien, ['source' => 'estimation', 'id_user' => current_user_id()]);
    $d = dv_get($pdo, $idDossier);

    $courant = bien_prix_courant($pdo, $idBien, 'prix_vente', 'courant');
    echo json_encode([
        'ok'        => true,
        'prix'      => $courant !== null ? (float)$courant : $prix,
        'prix_fmt'  => number_format($courant !== null ? (float)$courant : $prix, 0, ',', ' ') . ' €',
        'etape'     => $d['etape'] ?? null,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[transaction_dossier_estimation_save] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
