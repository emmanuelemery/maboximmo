<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expirée, veuillez vous reconnecter.']);
    exit;
}

if (!is_super_admin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès réservé au Super Admin.']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['password'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Mot de passe requis']);
        exit;
    }

    $userId = current_user_id();
    $pdo = db();

    // Récupérer le hash du mot de passe de l'utilisateur
    $stmt = $pdo->prepare("SELECT mot_de_passe FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Utilisateur non trouvé']);
        exit;
    }

    // Vérifier le mot de passe
    if (password_verify($data['password'], $user['mot_de_passe'])) {
        // Mot de passe correct, marquer comme authentifié
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['superadmin_verified'] = time();

        echo json_encode(['success' => true, 'message' => 'Authentification réussie']);
    } else {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Mot de passe incorrect']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()]);
}
