<?php
/**
 * AJAX — Archiver/restaurer un locataire parti
 * POST: id_bien, locataire_nom, id_proprietaire, archive (0|1)
 * Sécurité : super admin OU bailleur propriétaire du bien
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/roles_services.php';
header('Content-Type: application/json; charset=utf-8');

require_login();
$userId   = (int)current_user_id();
$roleId   = (int)current_role_id();
$isSuperAdmin = is_super_admin();

// ── Accès au service bailleur ──
if (!$isSuperAdmin && !hasServiceAccess($roleId, 'bailleur')) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'msg'=>'Accès refusé (bailleur)']);
    exit;
}

$pdo = $GLOBALS['pdo'];

$id_bien       = (int)($_POST['id_bien'] ?? 0);
$locataire_nom = trim($_POST['locataire_nom'] ?? '');
$id_prop       = (int)($_POST['id_proprietaire'] ?? 0);
$archive       = (int)($_POST['archive'] ?? 0) ? 1 : 0;

if (!$id_bien || !$locataire_nom || !$id_prop) {
    echo json_encode(['ok'=>false,'msg'=>'paramètres manquants']); exit;
}

// ── Vérifier que le bien existe et que l'utilisateur y a accès ──
if (!$isSuperAdmin) {
    // Non-super-admin : vérifier qu'il gère ce propriétaire
    $stmtP = $pdo->prepare("SELECT COUNT(*) FROM user_proprietaires WHERE id_user=? AND id_proprietaire=?");
    $stmtP->execute([$userId, $id_prop]);
    if ($stmtP->fetchColumn() < 1) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'msg'=>'Accès refusé (propriétaire)']);
        exit;
    }
}

$stmt = $pdo->prepare(
    "UPDATE locataires_statuts SET archive=? WHERE id_bien=? AND locataire_nom=? AND id_proprietaire=?"
);
$stmt->execute([$archive, $id_bien, $locataire_nom, $id_prop]);

echo json_encode(['ok' => true, 'archive' => $archive]);
