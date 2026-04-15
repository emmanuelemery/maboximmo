<?php
/**
 * api/switch_societe.php — Super Admin : changer de société en session
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

$roleId = (int)current_role_id();
if (!in_array($roleId, [1, 7], true)) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Réservé admin/super-admin.']));
}

header('Content-Type: application/json; charset=utf-8');

$newSocId = (int)($_POST['id_societe'] ?? $_GET['id_societe'] ?? 0);
if ($newSocId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'ID société invalide.']);
    exit;
}

$pdo = $GLOBALS['pdo'];
$soc = $pdo->prepare("SELECT id, nom FROM societes WHERE id = ?");
$soc->execute([$newSocId]);
$row = $soc->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'Société introuvable.']);
    exit;
}

// Mettre à jour la session
$_SESSION['id_societe'] = $newSocId;

// Optionnel : mettre à jour l'agence aussi (première agence de la société)
$ag = $pdo->prepare("SELECT id FROM agences WHERE id_societe = ? ORDER BY id LIMIT 1");
$ag->execute([$newSocId]);
$agRow = $ag->fetch(PDO::FETCH_ASSOC);
if ($agRow) {
    $_SESSION['id_agence'] = (int)$agRow['id'];
}

echo json_encode(['ok' => true, 'societe' => $row['nom'], 'id_societe' => $newSocId]);
