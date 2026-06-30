<?php
declare(strict_types=1);
header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
verify_csrf_any();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) { http_response_code(500); echo json_encode(['success'=>false,'message'=>'Database error']); exit; }

// Accès : admin (rôle 1) ou gestionnaire d'agence (limité à son agence).
$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$agenceScope = function_exists('can_manage_salaires_agence') ? (int)can_manage_salaires_agence() : 0;
if ($roleId !== 1 && $agenceScope === 0) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'Accès refusé']); exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$idUser  = (int)($data['id_user'] ?? 0);
$service = (string)($data['service'] ?? '');
$on      = !empty($data['on']);

$SERVICES = ['gestion','syndic','transaction','comptabilite'];
if ($idUser <= 0 || !in_array($service, $SERVICES, true)) {
    echo json_encode(['success'=>false,'message'=>'Paramètres invalides']); exit;
}

// Scope manager : le collaborateur doit être dans son agence.
if ($roleId !== 1 && $agenceScope > 0) {
    $chk = $pdo->prepare("SELECT id_agence FROM users WHERE id = ? LIMIT 1");
    $chk->execute([$idUser]);
    $ag = $chk->fetchColumn();
    if ((int)$ag !== $agenceScope) {
        http_response_code(403); echo json_encode(['success'=>false,'message'=>'Hors périmètre']); exit;
    }
}

try {
    // Table de secours si la migration n'a pas encore tourné.
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_services (id_user INT NOT NULL, service VARCHAR(20) NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(id_user,service), INDEX idx_service(service))");

    if ($on) {
        $pdo->prepare("INSERT IGNORE INTO user_services (id_user, service) VALUES (?,?)")->execute([$idUser, $service]);
    } else {
        $pdo->prepare("DELETE FROM user_services WHERE id_user = ? AND service = ?")->execute([$idUser, $service]);
    }

    // Compat legacy : users.service = service principal (ordre canonique), 'gestion' par défaut.
    $rows = $pdo->prepare("SELECT service FROM user_services WHERE id_user = ?");
    $rows->execute([$idUser]);
    $cur = $rows->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $primary = 'gestion';
    foreach ($SERVICES as $s) { if (in_array($s, $cur, true)) { $primary = $s; break; } }
    try { $pdo->prepare("UPDATE users SET service = ? WHERE id = ?")->execute([$primary, $idUser]); } catch (Throwable $e) {}

    echo json_encode(['success'=>true, 'services'=>$cur]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
