<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?? [];
if (!isset($_POST['csrf_token']) && isset($data['csrf_token'])) {
    $_POST['csrf_token'] = (string)$data['csrf_token'];
}
verify_csrf_any();

$pdo = $GLOBALS['pdo'];
$userId = current_user_id();

$questionId = (int)($data['question_id'] ?? 0);
$rubriqueId = (int)($data['rubrique_id'] ?? 0);
$note = isset($data['note']) && $data['note'] !== null ? max(1, min(5, (int)$data['note'])) : null;
$commentaire = isset($data['commentaire']) ? trim((string)$data['commentaire']) : null;

if ($questionId <= 0 || $rubriqueId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'question_id et rubrique_id requis']);
    exit;
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS rh_entretien_prep_user (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_user INT NOT NULL,
        rubrique_id INT NOT NULL,
        question_id INT NOT NULL,
        note TINYINT NULL,
        commentaire TEXT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_question (id_user, question_id),
        INDEX(id_user),
        INDEX(rubrique_id),
        INDEX(updated_at)
    )");
} catch (Exception $e) {}

try {
    $pdo->prepare("INSERT INTO rh_entretien_prep_user
        (id_user, rubrique_id, question_id, note, commentaire, updated_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            note = VALUES(note),
            commentaire = VALUES(commentaire),
            updated_at = NOW()
    ")->execute([$userId, $rubriqueId, $questionId, $note, $commentaire]);

    $ts = time();
    echo json_encode(['ok' => true, 'timestamp' => $ts]);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'error' => 'Erreur BDD']);
}