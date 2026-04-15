<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

header('Content-Type: application/json');

require_login();
verify_csrf_any();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    exit(json_encode(['success' => false, 'message' => 'PDO non disponible']));
}

$roleId = current_role_id();

// Admin only
if ($roleId !== 1) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Accès non autorisé']));
}

// Get JSON data
$data = json_decode(file_get_contents('php://input'), true);
$userId = (int)($data['userId'] ?? $_POST['userId'] ?? 0);
$active = (int)($data['active'] ?? $_POST['active'] ?? 0);

if (!$userId) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Paramètres manquants']));
}

try {
    // Check if user exists and get their actual id for salaires table
    $stmtUser = $pdo->prepare("SELECT id, id_legacy FROM users WHERE id = ? AND actif = 1");
    $stmtUser->execute([$userId]);
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new Exception('Utilisateur non trouvé');
    }

    // Check if model record exists (handle both id and id_legacy)
    $stmtCheck = $pdo->prepare("
        SELECT id FROM salaires
        WHERE (id_user = ? OR (id_user = ? AND ? IS NOT NULL)) AND mois_reference = '0000-00-00'
    ");
    $stmtCheck->execute([$userId, $user['id_legacy'], $user['id_legacy']]);
    $modelRecord = $stmtCheck->fetch();

    if ($modelRecord) {
        // UPDATE existing record
        $stmt = $pdo->prepare("
            UPDATE salaires SET salaire_modele = ?
            WHERE (id_user = ? OR (id_user = ? AND ? IS NOT NULL)) AND mois_reference = '0000-00-00'
        ");
        $stmt->execute([$active, $userId, $user['id_legacy'], $user['id_legacy']]);
    } else if ($active === 1) {
        // INSERT new model record if activating
        $stmt = $pdo->prepare("
            INSERT INTO salaires (
                id_user, mois_reference, salaire_modele
            ) VALUES (?, '0000-00-00', 1)
        ");
        $stmt->execute([$userId]);
    }

    exit(json_encode(['success' => true, 'message' => 'Modèle togglé']));

} catch (Exception $e) {
    error_log("toggle_modele_salaire.php: " . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['success' => false, 'message' => 'Erreur serveur']));
}

