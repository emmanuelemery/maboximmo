<?php
// api/tiers_archive.php — Archive (soft-delete) ou restaure un tiers
// POST JSON : { id_tiers, action: 'archive' | 'restore' }
// Disponible à tous les users authentifiés, scopé par société.
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$roleId    = (int)($_SESSION['id_role']    ?? 0);
$isAdmin   = $roleId === 1;

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) {
    exit(json_encode(['ok' => false, 'error' => 'JSON invalide']));
}

$idTiers = isset($body['id_tiers']) && ctype_digit((string)$body['id_tiers']) ? (int)$body['id_tiers'] : 0;
$action  = (string)($body['action'] ?? 'archive');
if ($idTiers <= 0 || !in_array($action, ['archive','restore'], true)) {
    exit(json_encode(['ok' => false, 'error' => 'Paramètres invalides']));
}

try {
    // Scope : tiers doit appartenir à la société de l'user (sauf super admin)
    $st = $pdo->prepare("SELECT id, id_societe, actif FROM tiers WHERE id = ? LIMIT 1");
    $st->execute([$idTiers]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) exit(json_encode(['ok' => false, 'error' => 'Tiers introuvable']));
    if (!$isAdmin && $societeId > 0 && (int)$row['id_societe'] !== $societeId && $row['id_societe'] !== null) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Hors scope société']));
    }

    $newActif = $action === 'archive' ? 0 : 1;
    $pdo->prepare("UPDATE tiers SET actif = ?, date_modification = NOW() WHERE id = ?")
        ->execute([$newActif, $idTiers]);

    exit(json_encode([
        'ok'        => true,
        'id_tiers'  => $idTiers,
        'action'    => $action,
        'actif'     => $newActif,
    ]));
} catch (Throwable $e) {
    error_log('[tiers_archive] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
