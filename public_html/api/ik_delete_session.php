<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/rh_helpers.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo    = $GLOBALS['pdo'] ?? null;
$userId = current_user_id();
$roleId = current_role_id();

if (!$pdo) { echo json_encode(['ok' => false, 'error' => 'DB indisponible']); exit; }

// Délégation IK : l'admin/manager agit sur un collaborateur via ?id_user= (scope contrôlé).
$userId = rh_ik_resolve_target($pdo, (int)($_GET['id_user'] ?? 0));

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$id   = (int)($data['id'] ?? 0);

if (!$id) { echo json_encode(['ok' => false, 'error' => 'ID manquant']); exit; }

$stmt = $pdo->prepare("SELECT id_user, mois_paie FROM rh_ik_sessions WHERE id = ?");
$stmt->execute([$id]);
$sess = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sess) {
    echo json_encode(['ok' => false, 'error' => 'Session introuvable']); exit;
}
if ($sess['id_user'] != $userId && $roleId !== 1) {
    echo json_encode(['ok' => false, 'error' => 'Accès refusé']); exit;
}
// La clôture se juge sur la société du collaborateur concerné, jamais globalement.
if (rh_is_salary_month_closed($pdo, $sess['mois_paie'] ?? '', (int)$sess['id_user'])) {
    echo json_encode(['ok' => false, 'error' => 'Mois de paie clôturé']); exit;
}

try {
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM rh_ik_lignes WHERE id_session = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM rh_ik_sessions WHERE id = ?")->execute([$id]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['ok' => false, 'error' => 'Erreur suppression']); exit;
}

echo json_encode(['ok' => true]);