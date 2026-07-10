<?php
/**
 * api/tiers_contact_add.php — Rattache un tiers (contact/représentant) à un tiers entité.
 *
 * Réutilise le tiers sélectionné/créé via le composant tiers_selector (acteur_modal).
 * Crée le lien tiers_contacts (id_tiers_entite ← id_tiers_contact, qualité choisie).
 *
 * POST : id_tiers_entite, id_tiers_contact, qualite, csrf_token (form 'tiers_contact').
 * Manager + ACL.
 */
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
verify_csrf_any('tiers_contact');

$roleId = function_exists('current_role_id') ? (int)current_role_id() : 0;
$isMgr  = in_array($roleId, [1,2,3,7], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isMgr) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'manager+ requis']); exit; }

$pdo = $GLOBALS['pdo'];
$idEntite  = (int)($_POST['id_tiers_entite'] ?? 0);
$idContact = (int)($_POST['id_tiers_contact'] ?? 0);
$qualite   = strtolower(trim((string)($_POST['qualite'] ?? 'contact')));
if ($idEntite <= 0 || $idContact <= 0) { echo json_encode(['ok'=>false,'error'=>'id_tiers_entite et id_tiers_contact requis']); exit; }
if ($idEntite === $idContact) { echo json_encode(['ok'=>false,'error'=>'Un tiers ne peut pas être son propre contact']); exit; }

// Whitelist douce des qualités.
$ALLOWED = ['gerant','contact','representant','associe','indivisaire','conjoint','enfant','parent','proche','comptable','avocat','notaire','expert_comptable','conseil','syndic','autre'];
if (!in_array($qualite, $ALLOWED, true)) $qualite = 'contact';

// Vérifie les deux tiers.
$st = $pdo->prepare("SELECT COUNT(*) FROM tiers WHERE id IN (?, ?)");
$st->execute([$idEntite, $idContact]);
if ((int)$st->fetchColumn() < 2) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Tiers introuvable']); exit; }

try {
    $ins = $pdo->prepare("INSERT IGNORE INTO tiers_contacts
        (id_tiers_entite, id_tiers_contact, qualite, priorite, canal_principal, actif, date_creation)
        VALUES (?, ?, ?, 0, 'email', 1, NOW())");
    $ins->execute([$idEntite, $idContact, $qualite]);
} catch (Throwable $e) {
    error_log('[tiers_contact_add] ' . $e->getMessage());
    echo json_encode(['ok'=>false,'error'=>'Erreur enregistrement']); exit;
}

echo json_encode(['ok'=>true, 'id_tiers_entite'=>$idEntite, 'id_tiers_contact'=>$idContact, 'qualite'=>$qualite]);
