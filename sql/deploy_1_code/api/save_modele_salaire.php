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
$field = (string)($data['field'] ?? $_POST['field'] ?? '');
$value = $_POST['value'] ?? $data['value'] ?? '';

if (!$userId || !$field) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Paramètres manquants']));
}

// Whitelist of allowed fields
$allowedFields = [
    'salaire_brut_base',
    'treizieme_mois',
    'anciennete',
    'avantage_nature',
    'heures_supp',
    'commission_ca',
    'commission_ca_nouvelles_affaires',
    'vehicule_nom',
    'vehicule_puissance_fiscale',
    'prix_km',
    'vehicule_utilise',
    'frais_professionnels',
    'prime_admin',
    'prime_exceptionnelle',
    'stationnement',
    'commentaire_admin'
];

if (!in_array($field, $allowedFields)) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Champ non autorisé']));
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
        $sql = "UPDATE salaires SET `$field` = ? WHERE (id_user = ? OR (id_user = ? AND ? IS NOT NULL)) AND mois_reference = '0000-00-00'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$value, $userId, $user['id_legacy'], $user['id_legacy']]);
    } else {
        // INSERT new model record with minimal fields
        $stmt = $pdo->prepare("
            INSERT INTO salaires (
                id_user, mois_reference, salaire_modele, `$field`
            ) VALUES (?, '0000-00-00', 1, ?)
        ");
        $stmt->execute([$userId, $value]);
    }

    exit(json_encode(['success' => true, 'message' => 'Champ enregistré']));

} catch (Exception $e) {
    error_log("save_modele_salaire.php: " . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['success' => false, 'message' => 'Erreur serveur']));
}

