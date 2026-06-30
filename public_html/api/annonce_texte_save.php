<?php
declare(strict_types=1);
/**
 * api/annonce_texte_save.php — Enregistre le titre + le texte d'une annonce
 * (modal d'édition rapide depuis bien_360). Repris automatiquement dans l'annonce.
 *
 * POST JSON : { annonce_id, csrf, titre, description }
 * Réponse   : { ok, error }
 *
 * Accès : manager / admin / super admin.
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
verify_csrf('annonce_texte');

$pdo = $GLOBALS['pdo'];
$annonceId   = (int)($body['annonce_id'] ?? 0);
$titre       = trim((string)($body['titre'] ?? ''));
$description = trim((string)($body['description'] ?? ''));
if ($annonceId <= 0) exit(json_encode(['ok'=>false,'error'=>'annonce_id requis']));

$chk = $pdo->prepare("SELECT id FROM annonces WHERE id = ?");
$chk->execute([$annonceId]);
if (!$chk->fetchColumn()) exit(json_encode(['ok'=>false,'error'=>'Annonce introuvable']));

try {
    $pdo->prepare("UPDATE annonces SET titre = ?, description = ?, date_modification = NOW() WHERE id = ?")
        ->execute([mb_substr($titre,0,255), $description, $annonceId]);
} catch (Throwable $e) {
    http_response_code(500);
    exit(json_encode(['ok'=>false,'error'=>$e->getMessage()]));
}

echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE);
