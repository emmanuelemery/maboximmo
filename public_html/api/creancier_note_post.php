<?php
/**
 * api/creancier_note_post.php — Note PERSONNELLE (privée) sur un dossier créancier.
 *
 * Canal 'note_privee' : visible UNIQUEMENT de son auteur (id_user). Distinct du
 * fil partagé ('feed') et du chat IA ('chat'). Jamais inclus dans le partage public.
 *
 * Actions :
 *   - add    : POST id_dossier, message
 *   - delete : POST note_id (l'auteur uniquement)
 * CSRF : form 'creancier_note'. Accès dossier requis.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/creancier_urgence_data.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
verify_csrf_any('creancier_note');

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$action = (string)($_POST['action'] ?? 'add');

if ($action === 'delete') {
    $noteId = (int)($_POST['note_id'] ?? 0);
    if ($noteId <= 0) { echo json_encode(['ok'=>false,'error'=>'note_id requis']); exit; }
    // Seul l'auteur peut supprimer sa note privée.
    $st = $pdo->prepare("DELETE FROM creancier_dossier_message WHERE id = ? AND id_user = ? AND canal = 'note_privee'");
    $st->execute([$noteId, $userId]);
    echo json_encode(['ok'=>true,'deleted'=>$st->rowCount()]); exit;
}

$idDossier = (int)($_POST['id_dossier'] ?? 0);
$message   = trim((string)($_POST['message'] ?? ''));
if ($idDossier <= 0 || $message === '') { echo json_encode(['ok'=>false,'error'=>'id_dossier et message requis']); exit; }
if (mb_strlen($message) > 5000) { $message = mb_substr($message, 0, 5000); }

if (!creancier_user_can_access_dossier($pdo, $idDossier, $userId)) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Accès dossier refusé']); exit;
}

$pdo->prepare("INSERT INTO creancier_dossier_message (id_dossier, role, canal, id_user, message) VALUES (?, 'user', 'note_privee', ?, ?)")
    ->execute([$idDossier, $userId, $message]);

echo json_encode([
    'ok'      => true,
    'id'      => (int)$pdo->lastInsertId(),
    'message' => $message,
    'date'    => date('d/m/Y H:i'),
], JSON_UNESCAPED_UNICODE);
