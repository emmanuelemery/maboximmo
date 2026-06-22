<?php
/**
 * api/creancier_contact_add.php — Lie un tiers (contact) à un dossier créancier.
 *
 * Réutilise le tiers sélectionné/créé via le composant tiers_selector. Crée le lien
 * creancier_dossier_lien (rôle choisi) + le rôle catalogue tiers_roles si pertinent.
 *
 * POST : id_dossier, id_tiers, role_dossier, csrf_token (form 'creancier_contact').
 * Manager + ACL.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/creancier_urgence_data.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
verify_csrf_any('creancier_contact');

$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isMgr  = in_array($roleId, [1,2,3,7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isMgr) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'manager+ requis']); exit; }

$pdo = $GLOBALS['pdo'];
$userId = (int)current_user_id();
$idDossier = (int)($_POST['id_dossier'] ?? 0);
$idTiers   = (int)($_POST['id_tiers'] ?? 0);
$role      = strtolower(trim((string)($_POST['role_dossier'] ?? 'contact')));
if ($idDossier <= 0 || $idTiers <= 0) { echo json_encode(['ok'=>false,'error'=>'id_dossier et id_tiers requis']); exit; }
if (!creancier_user_can_access_dossier($pdo, $idDossier, $userId)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Accès refusé']); exit; }

// Rôle libre côté dossier ; whitelist douce.
$ALLOWED = ['avocat','commissaire_justice','expert_comptable','notaire','creancier','heritier','associe','gerant','gestionnaire','contact','debiteur'];
if (!in_array($role, $ALLOWED, true)) $role = 'contact';

// Vérifie le tiers.
$st = $pdo->prepare("SELECT 1 FROM tiers WHERE id = ? LIMIT 1");
$st->execute([$idTiers]);
if (!$st->fetchColumn()) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Tiers introuvable']); exit; }

// Rôle catalogue si pertinent.
$CATALOG = ['avocat','commissaire_justice','expert_comptable','notaire','creancier','heritier','associe'];
if (in_array($role, $CATALOG, true)) {
    $pdo->prepare("INSERT IGNORE INTO tiers_roles (id_tiers, role_code, objet_type, actif) VALUES (?, ?, NULL, 1)")->execute([$idTiers, $role]);
}

// Lien dossier (idempotent via clé unique).
$pdo->prepare("INSERT IGNORE INTO creancier_dossier_lien (id_dossier, entity_type, entity_id, role_dossier, created_by) VALUES (?, 'TIERS', ?, ?, ?)")
    ->execute([$idDossier, $idTiers, $role, $userId]);

echo json_encode(['ok'=>true, 'id_dossier'=>$idDossier, 'id_tiers'=>$idTiers, 'role'=>$role]);
