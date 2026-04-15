<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/rh_helpers.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo    = $GLOBALS['pdo'] ?? null;
$userId = current_user_id();

if (!$pdo) { echo json_encode(['ok' => false, 'error' => 'DB indisponible']); exit; }

$data = json_decode(file_get_contents('php://input'), true) ?: [];

$id              = isset($data['id']) ? (int)$data['id'] : 0;
$moisDeplacements = preg_replace('/[^0-9-]/', '', $data['mois_deplacements'] ?? '');
$moisPaie         = preg_replace('/[^0-9-]/', '', $data['mois_paie'] ?? '');
$vehiculeInfo     = trim($data['vehicule_info'] ?? '');

if (!preg_match('/^\d{4}-\d{2}$/', $moisDeplacements) || !preg_match('/^\d{4}-\d{2}$/', $moisPaie)) {
    echo json_encode(['ok' => false, 'error' => 'Mois invalide']); exit;
}

// Vérifier que l'utilisateur est propriétaire si update
if ($id > 0) {
    $check = $pdo->prepare("SELECT id_user, mois_paie FROM rh_ik_sessions WHERE id = ?");
    $check->execute([$id]);
    $row = $check->fetch(PDO::FETCH_ASSOC);
    $roleId = current_role_id();
    if (!$row || ($row['id_user'] != $userId && $roleId > 1 === false)) {
        echo json_encode(['ok' => false, 'error' => 'Accès refusé']); exit;
    }
    if (rh_is_salary_month_closed($pdo, $row['mois_paie'] ?? '')) {
        echo json_encode(['ok' => false, 'error' => 'Mois de paie clôturé']); exit;
    }
    if (rh_is_salary_month_closed($pdo, $moisPaie)) {
        echo json_encode(['ok' => false, 'error' => 'Mois de paie clôturé']); exit;
    }

    $stmt = $pdo->prepare("UPDATE rh_ik_sessions SET mois_paie=?, vehicule_info=? WHERE id=?");
    $stmt->execute([$moisPaie, $vehiculeInfo, $id]);
    echo json_encode(['ok' => true, 'id' => $id]);
} else {
    if (rh_is_salary_month_closed($pdo, $moisPaie)) {
        echo json_encode(['ok' => false, 'error' => 'Mois de paie clôturé']); exit;
    }
    // Créer ou retrouver la session existante pour ce mois
    $stmt = $pdo->prepare("SELECT id FROM rh_ik_sessions WHERE id_user=? AND mois_deplacements=?");
    $stmt->execute([$userId, $moisDeplacements]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $upd = $pdo->prepare("UPDATE rh_ik_sessions SET mois_paie=?, vehicule_info=? WHERE id=?");
        $upd->execute([$moisPaie, $vehiculeInfo, $existing['id']]);
        echo json_encode(['ok' => true, 'id' => $existing['id']]);
    } else {
        $ins = $pdo->prepare("INSERT INTO rh_ik_sessions (id_user, mois_deplacements, mois_paie, vehicule_info) VALUES (?,?,?,?)");
        $ins->execute([$userId, $moisDeplacements, $moisPaie, $vehiculeInfo]);
        echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    }
}