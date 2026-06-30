<?php
declare(strict_types=1);
/**
 * api/bien_commentaire_save.php — Enregistre le commentaire interne d'un bien
 * (textarea autosave depuis bien_360). Persiste dans biens.commentaire (TEXT).
 *
 * POST JSON : { bien_id, csrf, commentaire }
 * Réponse   : { ok, error }
 *
 * Endpoint DÉDIÉ (et non bien_autosave.php) : on ne touche QUE la colonne
 * commentaire, sans risque de nuller les autres champs du bien.
 *
 * Accès : manager / admin / super admin ([1,2,7]).
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok'=>false,'error'=>'POST requis'])); }

$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$canEdit = in_array($roleId, [1,2,7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$canEdit) { http_response_code(403); exit(json_encode(['ok'=>false,'error'=>'Accès refusé'])); }

$body = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($body)) exit(json_encode(['ok'=>false,'error'=>'JSON invalide']));
$_POST['csrf_token'] = (string)($body['csrf'] ?? ''); // verify_csrf lit $_POST['csrf_token']
verify_csrf('bien_commentaire');

$pdo = $GLOBALS['pdo'];
$bienId      = (int)($body['bien_id'] ?? 0);
$commentaire = trim((string)($body['commentaire'] ?? ''));
if ($bienId <= 0) exit(json_encode(['ok'=>false,'error'=>'bien_id requis']));

$chk = $pdo->prepare("SELECT id FROM biens WHERE id = ?");
$chk->execute([$bienId]);
if (!$chk->fetchColumn()) exit(json_encode(['ok'=>false,'error'=>'Bien introuvable']));

try {
    $pdo->prepare("UPDATE biens SET commentaire = ?, date_modification = NOW() WHERE id = ?")
        ->execute([$commentaire, $bienId]);
} catch (Throwable $e) {
    http_response_code(500);
    exit(json_encode(['ok'=>false,'error'=>$e->getMessage()]));
}

echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
