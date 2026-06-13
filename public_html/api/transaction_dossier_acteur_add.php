<?php
// api/transaction_dossier_acteur_add.php — Rattache un tiers existant au dossier avec un rôle.
// POST : id_dossier, id_tiers, role_code  →  { ok, acteur }
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/dossier_vente.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }

$idDossier = (int)(post('id_dossier') ?? 0);
$idTiers   = (int)(post('id_tiers') ?? 0);
$roleCode  = trim((string)(post('role_code') ?? ''));

if ($idDossier <= 0 || $idTiers <= 0 || $roleCode === '') {
    echo json_encode(['ok'=>false,'error'=>'paramètres manquants']); exit;
}
if (!array_key_exists($roleCode, dv_roles_autorises())) {
    echo json_encode(['ok'=>false,'error'=>'rôle non autorisé']); exit;
}

$dossier = dv_get($pdo, $idDossier);
if (!$dossier) { echo json_encode(['ok'=>false,'error'=>'dossier introuvable']); exit; }

// Scope société (super admin / manager bypass).
$roleId    = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isManager = ($roleId === 1 || $roleId === 2);
$idSoc     = isset($_SESSION['id_societe']) ? (int)$_SESSION['id_societe'] : null;
if (!$isManager && $idSoc !== null && (int)$dossier['id_societe'] !== $idSoc) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'hors scope']); exit;
}

try {
    $row = dv_attach_acteur($pdo, $idDossier, $idTiers, $roleCode);
    if (!$row) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'rattachement impossible']); exit; }

    $nom = $row['nom_affichage'] ?: ($row['raison_sociale'] ?: trim(($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? '')));
    echo json_encode([
        'ok'     => true,
        'acteur' => [
            'role_id'    => (int)$row['role_id'],
            'id_tiers'   => (int)$row['id_tiers'],
            'role_code'  => $row['role_code'],
            'role_label' => dv_roles_autorises()[$row['role_code']] ?? $row['role_code'],
            'nom'        => $nom !== '' ? $nom : ('Tiers #' . $row['id_tiers']),
            'email'      => $row['email'] ?: null,
            'telephone'  => $row['telephone'] ?: null,
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[transaction_dossier_acteur_add] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
