<?php
/**
 * api/admin_bien_set_statut.php — Change le statut d'un bien depuis la page de triage.
 *
 * Sort un bien de la gestion active : 'vendu' (vendu) ou 'perdu_gestion' (propriétaire parti).
 * 'actif' permet de réintégrer. Écrit biens.statut_bien. Super admin.
 *
 * POST : id_bien, statut (vendu|perdu_gestion|actif), csrf_token ('admin_bien_type').
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/csrf.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
verify_csrf_any('admin_bien_type');

$pdo    = $GLOBALS['pdo'];
$roleId = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$isAdmin = ($roleId === 1 || $roleId === 7) || (function_exists('is_super_admin') && is_super_admin());
if (!$isAdmin) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Réservé aux super administrateurs']); exit; }

$idBien = (int)($_POST['id_bien'] ?? 0);
$statut = (string)($_POST['statut'] ?? '');
$ALLOWED = ['vendu' => 'Vendu', 'perdu_gestion' => 'Perte de gestion', 'actif' => 'Actif'];
if ($idBien <= 0 || !isset($ALLOWED[$statut])) { echo json_encode(['ok'=>false,'error'=>'paramètres invalides']); exit; }

$pdo->prepare("UPDATE biens SET statut_bien = ?, date_modification = NOW() WHERE id = ?")->execute([$statut, $idBien]);

echo json_encode(['ok'=>true, 'id_bien'=>$idBien, 'statut'=>$statut, 'libelle'=>$ALLOWED[$statut]], JSON_UNESCAPED_UNICODE);
