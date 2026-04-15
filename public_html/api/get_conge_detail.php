<?php
declare(strict_types=1);
header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur base de données']);
    exit;
}

$congeId = (int)($_GET['id'] ?? 0);
if (!$congeId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID manquant']);
    exit;
}

$stmt = $pdo->prepare("SELECT c.*, u.prenom, u.nom FROM conges c JOIN users u ON c.id_user = u.id WHERE c.id = ?");
$stmt->execute([$congeId]);
$conge = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$conge) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Congé non trouvé']);
    exit;
}

echo json_encode(['success' => true, 'conge' => $conge]);
?>
