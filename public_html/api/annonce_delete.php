<?php
// api/annonce_delete.php — Suppression d'une annonce par admin
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}

$pdo       = $GLOBALS['pdo'];
$roleId    = function_exists('current_role_id') ? (int)current_role_id() : (int)($_SESSION['id_role'] ?? 0);
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$isSuperAdmin = ($roleId === 1);
$isAdmin      = in_array($roleId, [1, 2, 3], true); // SA + admin société + admin agence

if (!$isAdmin) {
    http_response_code(403);
    exit(json_encode(['ok' => false, 'error' => 'Accès réservé aux administrateurs']));
}

// CSRF
if (function_exists('verify_csrf_any')) {
    try { verify_csrf_any('annonce_delete'); }
    catch (Throwable $e) { http_response_code(419); exit(json_encode(['ok' => false, 'error' => 'CSRF invalide'])); }
}

$idAnnonce = isset($_POST['id_annonce']) && ctype_digit((string)$_POST['id_annonce']) ? (int)$_POST['id_annonce'] : 0;
if ($idAnnonce <= 0) {
    exit(json_encode(['ok' => false, 'error' => 'id_annonce manquant']));
}

try {
    // Scope société : admin non-SA ne peut supprimer que dans sa société
    $stSoc = $pdo->prepare("SELECT id_societe, id_bien FROM annonces WHERE id = ?");
    $stSoc->execute([$idAnnonce]);
    $row = $stSoc->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(404);
        exit(json_encode(['ok' => false, 'error' => 'Annonce introuvable']));
    }
    if (!$isSuperAdmin && $societeId > 0 && (int)($row['id_societe'] ?? 0) !== $societeId) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Annonce hors de votre société']));
    }

    // Suppression : les tables liées (annonces_photos, annonces_complement_loyer_lignes)
    // ont normalement ON DELETE CASCADE. Si pas le cas, cleanup manuel.
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM annonces_photos WHERE id_annonce = ?")->execute([$idAnnonce]);
        $pdo->prepare("DELETE FROM annonces_complement_loyer_lignes WHERE id_annonce = ?")->execute([$idAnnonce]);
    } catch (Throwable $e) {
        // Ces tables peuvent ne pas exister / ou CASCADE déjà géré — on ignore
    }
    $pdo->prepare("DELETE FROM annonces WHERE id = ?")->execute([$idAnnonce]);
    $pdo->commit();

    error_log(sprintf('[annonce_delete] annonce #%d supprimée par user #%d (role %d)', $idAnnonce, (int)($_SESSION['user_id'] ?? 0), $roleId));
    exit(json_encode(['ok' => true, 'id_annonce' => $idAnnonce]));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[annonce_delete] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
