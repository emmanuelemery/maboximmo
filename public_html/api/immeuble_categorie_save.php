<?php
/**
 * api/immeuble_categorie_save.php — Enregistre la catégorie métier manuelle d'un immeuble.
 * POST : id_immeuble, categorie (SYNDIC|GESTION|TRANSACTION), csrf_token (form 'immeuble_cat').
 * Sécurité : login + manager (1,2,3,7) ou super admin.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
verify_csrf_any('immeuble_cat');
$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isMgr  = in_array($roleId, [1,2,3,7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isMgr) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'manager+ requis']); exit; }

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'];
$id  = isset($_POST['id_immeuble']) && ctype_digit((string)$_POST['id_immeuble']) ? (int)$_POST['id_immeuble'] : 0;
$cat = strtoupper(trim((string)($_POST['categorie'] ?? '')));
if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'id_immeuble invalide']); exit; }
if (!in_array($cat, ['SYNDIC','GESTION','TRANSACTION'], true)) { echo json_encode(['ok'=>false,'error'=>'catégorie invalide']); exit; }

try {
    $st = $pdo->prepare("UPDATE immeubles SET categorie_mbi = ? WHERE id = ?");
    $st->execute([$cat, $id]);
    echo json_encode(['ok'=>true, 'id_immeuble'=>$id, 'categorie'=>$cat], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok'=>false, 'error'=>'SQL : '.$e->getMessage()]);
}
