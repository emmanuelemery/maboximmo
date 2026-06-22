<?php
/**
 * api/proprietaire_parti_gestion.php — Marque/retire un propriétaire « parti de la gestion ».
 *
 * Distinct de l'archivage : le propriétaire perdu est masqué de la liste par défaut,
 * compté dans le KPI « Perdus » et consultable via le filtre dédié.
 *
 * POST : id_proprietaire, etat (1=parti / 0=réintégré), csrf_token (form 'proprio_parti_gestion').
 * Sécurité : login + CSRF + manager (1/2/3/7) ou super admin.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
verify_csrf_any('proprio_parti_gestion');

$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isMgr  = in_array($roleId, [1,2,3,7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isMgr) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'manager+ requis']); exit; }

$pdo = $GLOBALS['pdo'];
$id  = (int)($_POST['id_proprietaire'] ?? 0);
$etat = (int)($_POST['etat'] ?? 0) === 1 ? 1 : 0;
if ($id <= 0) { echo json_encode(['ok'=>false,'error'=>'id_proprietaire requis']); exit; }

$st = $pdo->prepare("SELECT id FROM proprietaires WHERE id = ? LIMIT 1");
$st->execute([$id]);
if (!$st->fetchColumn()) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Propriétaire introuvable']); exit; }

$pdo->prepare("UPDATE proprietaires SET parti_gestion = ?, date_sortie_gestion = " . ($etat ? "COALESCE(date_sortie_gestion, CURDATE())" : "NULL") . " WHERE id = ?")
    ->execute([$etat, $id]);

echo json_encode(['ok'=>true, 'id_proprietaire'=>$id, 'parti_gestion'=>$etat]);
