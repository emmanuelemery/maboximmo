<?php
declare(strict_types=1);
header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();
$roleId = current_role_id();
if ($roleId !== 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['titre']) || !isset($data['categorie']) || !isset($data['sujet']) || !isset($data['corps'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

$id = isset($data['id']) ? (int)$data['id'] : null;
$titre = trim($data['titre']);
$categorie = trim($data['categorie']);
$sujet = trim($data['sujet']);
$corps = trim($data['corps']);

if (empty($titre) || empty($categorie) || empty($sujet) || empty($corps)) {
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit;
}

try {
    if ($id) {
        // Update
        $stmt = $pdo->prepare("UPDATE mail_templates SET titre = ?, categorie = ?, sujet = ?, corps = ? WHERE id = ?");
        $stmt->execute([$titre, $categorie, $sujet, $corps, $id]);
    } else {
        // Insert
        $stmt = $pdo->prepare("INSERT INTO mail_templates (titre, categorie, sujet, corps) VALUES (?, ?, ?, ?)");
        $stmt->execute([$titre, $categorie, $sujet, $corps]);
        $id = $pdo->lastInsertId();
    }

    echo json_encode(['success' => true, 'id' => $id]);
} catch (Exception $e) {
    error_log("Error saving template: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Save failed']);
}
?>
