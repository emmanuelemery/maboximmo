<?php
/**
 * api/creancier_partage_action.php — Crée ou révoque un lien de partage public d'un dossier.
 *
 * Lecture seule côté public (creancier_partage.php?t=TOKEN). Managers uniquement.
 *
 * POST : action=create|revoke, id_dossier, [libelle], [expires_days], [partage_id], csrf_token ('creancier_partage').
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/creancier_urgence_data.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
verify_csrf_any('creancier_partage');

$pdo     = $GLOBALS['pdo'];
$userId  = (int)current_user_id();
$roleId  = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isSuper = function_exists('is_super_admin') && is_super_admin();
$isMgr   = $isSuper || in_array($roleId, [1, 2, 3, 7], true);

$action    = (string)($_POST['action'] ?? '');
$idDossier = (int)($_POST['id_dossier'] ?? 0);

if (!$isMgr) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Réservé aux managers']); exit; }
if ($idDossier <= 0 || !creancier_user_can_access_dossier($pdo, $idDossier, $userId)) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Accès dossier refusé']); exit;
}

if ($action === 'create') {
    $libelle = trim((string)($_POST['libelle'] ?? '')) ?: null;
    $days    = (int)($_POST['expires_days'] ?? 0);
    $expires = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;
    $token   = bin2hex(random_bytes(24)); // 48 hex chars
    $pdo->prepare("INSERT INTO creancier_dossier_partage (id_dossier, token, libelle, expires_at, created_by) VALUES (?, ?, ?, ?, ?)")
        ->execute([$idDossier, $token, $libelle, $expires, $userId]);
    $url = rtrim(app_url('/creancier_partage.php'), '/') . '?t=' . $token;
    echo json_encode(['ok'=>true, 'token'=>$token, 'url'=>$url, 'expires_at'=>$expires], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'revoke') {
    $pid = (int)($_POST['partage_id'] ?? 0);
    if ($pid <= 0) { echo json_encode(['ok'=>false,'error'=>'partage_id requis']); exit; }
    $pdo->prepare("UPDATE creancier_dossier_partage SET revoked_at = NOW() WHERE id = ? AND id_dossier = ? AND revoked_at IS NULL")
        ->execute([$pid, $idDossier]);
    echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok'=>false,'error'=>'action inconnue']);
