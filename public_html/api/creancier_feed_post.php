<?php
/**
 * api/creancier_feed_post.php — Ajoute un commentaire au FIL D'ACTUALITÉ d'un dossier.
 *
 * Le fil (canal='feed') est distinct du chat IA (canal='chat'). Chaque entrée est
 * signée (id_user) et horodatée. Tout intervenant ayant accès au dossier peut poster.
 *
 * POST : id_dossier, message, csrf_token (form 'creancier_feed').
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/creancier_urgence_data.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
verify_csrf_any('creancier_feed');

$pdo    = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$idDossier = (int)($_POST['id_dossier'] ?? 0);
$message   = trim((string)($_POST['message'] ?? ''));
if ($idDossier <= 0 || $message === '') { echo json_encode(['ok'=>false,'error'=>'id_dossier et message requis']); exit; }
if (mb_strlen($message) > 5000) { $message = mb_substr($message, 0, 5000); }

if (!creancier_user_can_access_dossier($pdo, $idDossier, $userId)) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Accès dossier refusé']); exit;
}

$pdo->prepare("INSERT INTO creancier_dossier_message (id_dossier, role, canal, id_user, message) VALUES (?, 'user', 'feed', ?, ?)")
    ->execute([$idDossier, $userId, $message]);
$id = (int)$pdo->lastInsertId();

// Nom auteur pour réponse (affichage immédiat côté client).
$auteur = '';
try {
    $st = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' ',prenom,nom)),''), username, CONCAT('User #',id)) FROM users WHERE id = ?");
    $st->execute([$userId]);
    $auteur = (string)($st->fetchColumn() ?: '');
} catch (Throwable $e) {}

echo json_encode([
    'ok'      => true,
    'id'      => $id,
    'auteur'  => $auteur,
    'message' => $message,
    'date'    => date('d/m/Y H:i'),
], JSON_UNESCAPED_UNICODE);
