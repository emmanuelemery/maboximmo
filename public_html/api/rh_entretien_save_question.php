<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();
verify_csrf_any();
$pdo    = $GLOBALS['pdo'];
$roleId = current_role_id();
$userId = current_user_id();

header('Content-Type: application/json; charset=utf-8');

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'JSON invalide.']);
    exit;
}

$entretienId = (int)($data['entretien_id'] ?? 0);
$questionId  = (int)($data['question_id']  ?? 0);

if ($entretienId <= 0 || $questionId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Paramètres manquants.']);
    exit;
}

// Vérifier ownership
try {
    $stmt = $pdo->prepare("SELECT manager_id, statut FROM rh_entretiens WHERE id = ?");
    $stmt->execute([$entretienId]);
    $ent = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur BDD.']);
    exit;
}

if (!$ent) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Entretien introuvable.']);
    exit;
}
if ($roleId !== 1 && (int)$ent['manager_id'] !== $userId) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accès refusé.']);
    exit;
}
if (in_array($ent['statut'], ['archive', 'signe'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Entretien verrouillé.']);
    exit;
}

$optionId         = isset($data['option_id'])         ? (int)$data['option_id']        : null;
$textLibre        = isset($data['texte_libre'])        ? trim((string)$data['texte_libre']) : null;
$commentaireAdmin = isset($data['commentaire_admin'])  ? trim((string)$data['commentaire_admin']) : null;
$note             = isset($data['note'])               ? (int)$data['note']              : null;

// commentaire_admin réservé à l'admin (role 1)
if ($roleId !== 1) {
    $commentaireAdmin = null;
}

try {
    $pdo->prepare("
        INSERT INTO rh_entretien_reponses_questions
            (entretien_id, question_id, option_id, texte_libre, commentaire_admin, note)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            option_id         = VALUES(option_id),
            texte_libre       = VALUES(texte_libre),
            commentaire_admin = COALESCE(VALUES(commentaire_admin), commentaire_admin),
            note              = VALUES(note),
            updated_at        = NOW()
    ")->execute([$entretienId, $questionId, $optionId ?: null, $textLibre ?: null, $commentaireAdmin, $note ?: null]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur sauvegarde.']);
    exit;
}

echo json_encode(['success' => true, 'saved_at' => date('H:i')]);
