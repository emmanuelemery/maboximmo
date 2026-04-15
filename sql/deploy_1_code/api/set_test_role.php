<?php
declare(strict_types=1);
/**
 * API de test de rôles - ADMIN ONLY
 *
 * Change TEMPORAIREMENT le rôle visible dans la SESSION.
 * Ne modifie RIEN en base de données.
 * Disparaît au logout ou rechargement complet de session.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

header('Content-Type: application/json');

require_login();

// Vérifier que l'utilisateur est un VRAI admin (pas un test role)
$realRole = (int)($_SESSION['id_role'] ?? 0);
if ($realRole !== 1) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Accès réservé aux admins']));
}

$testRole = (int)($_POST['role'] ?? $_GET['role'] ?? 0);

// Rôles valides : 0=Réel, 1=Admin, 2=Manager, 3=Collaborateur
if (!in_array($testRole, [0, 1, 2, 3], true)) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Rôle invalide']));
}

// ⚠️ IMPORTANT: Ceci stocke UNIQUEMENT en session, JAMAIS en base de données
set_test_role($testRole);

http_response_code(200);
exit(json_encode([
    'success' => true,
    'message' => $testRole === 0 ? 'Rôle réel restauré' : 'Rôle de test défini (session uniquement)',
    'role' => $testRole,
    'isTestMode' => $testRole > 0
]));
