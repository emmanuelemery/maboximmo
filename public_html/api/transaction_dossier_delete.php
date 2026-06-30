<?php
// api/transaction_dossier_delete.php — Annule un dossier de vente.
//   - vide (estimation sans mandat) → suppression physique
//   - engagé                        → passage en 'sans_suite'
// POST : id_dossier  →  { ok, mode: 'deleted'|'sans_suite', redirect }
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/dossier_vente.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$idDossier = (int)(post('id_dossier') ?? 0);
if ($idDossier <= 0) { echo json_encode(['ok'=>false,'error'=>'paramètres manquants']); exit; }

$dossier = dv_get($pdo, $idDossier);
if (!$dossier) { echo json_encode(['ok'=>false,'error'=>'dossier introuvable']); exit; }

$role      = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isManager = ($role === 1 || $role === 2);
$idSoc     = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
if (!$isManager && $idSoc !== null && (int)$dossier['id_societe'] !== $idSoc) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'hors scope']); exit;
}

try {
    $res = dv_cancel($pdo, $idDossier);
    if (!empty($res['ok'])) {
        // Repli si pas de page précédente exploitable (le front privilégie le referrer).
        $res['redirect'] = $res['mode'] === 'deleted'
            ? app_url('/agency_biens.php')
            : app_url('/transaction_dossier.php?id=' . $idDossier);
    }
    echo json_encode($res);
} catch (Throwable $e) {
    error_log('[transaction_dossier_delete] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
