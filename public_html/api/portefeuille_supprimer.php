<?php
// api/portefeuille_supprimer.php — Supprime un portefeuille de vente (entête + lignes + envois).
// Sécurité : login + rôle gestionnaire/bailleur + CSRF. Mêmes bornes que portefeuille_save.php :
// staff (1,2,3) + super-admin (7) voient tout ; un BAILLEUR (9,10) ne peut supprimer qu'un
// portefeuille contenant au moins un de SES biens (garde-fou périmètre, pas d'accès par id deviné).
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'] ?? db();

$roleId = (int)current_role_id();
if (!in_array($roleId, [1, 2, 3, 7, 9, 10], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès refusé.']);
    exit;
}
if (function_exists('is_readonly_user') && is_readonly_user()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Compte en lecture seule : suppression non autorisée.']);
    exit;
}
verify_csrf_any('portefeuille_supprimer');

$idPf = (int)($_POST['id_portefeuille'] ?? 0);
if ($idPf <= 0) {
    echo json_encode(['success' => false, 'message' => 'Identifiant de portefeuille manquant.']);
    exit;
}

try {
    $chk = $pdo->prepare("SELECT nom FROM portefeuilles WHERE id = ?");
    $chk->execute([$idPf]);
    $nom = $chk->fetchColumn();
    if ($nom === false) { throw new RuntimeException('Portefeuille introuvable.'); }

    // Garde-fou périmètre : un BAILLEUR (9,10) ne peut supprimer qu'un portefeuille contenant
    // au moins un de SES biens. Staff / super-admin : pas de borne d'appartenance.
    if (in_array($roleId, [9, 10], true)) {
        require_once __DIR__ . '/../inc/portefeuille_scope.php';
        $perimetreIds = pf_scope($pdo)['ids'];
        $in = !empty($perimetreIds) ? implode(',', array_map('intval', $perimetreIds)) : '0';
        $chkOwn = $pdo->prepare("SELECT 1 FROM portefeuille_biens pb JOIN biens b ON b.id = pb.id_bien
            WHERE pb.id_portefeuille = ? AND b.id_proprietaire IN ($in) LIMIT 1");
        $chkOwn->execute([$idPf]);
        if (!$chkOwn->fetchColumn()) { throw new RuntimeException('Portefeuille hors de votre périmètre.'); }
    }

    $pdo->beginTransaction();
    // Lignes + envois (best-effort si la table d'envois n'existe pas / pas de FK ON DELETE CASCADE).
    $pdo->prepare("DELETE FROM portefeuille_biens WHERE id_portefeuille = ?")->execute([$idPf]);
    try { $pdo->prepare("DELETE FROM portefeuille_envois WHERE id_portefeuille = ?")->execute([$idPf]); } catch (Throwable $e) {}
    $pdo->prepare("DELETE FROM portefeuilles WHERE id = ?")->execute([$idPf]);
    $pdo->commit();

    echo json_encode(['success' => true, 'id_portefeuille' => $idPf, 'nom' => $nom], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code($e instanceof RuntimeException ? 400 : 500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
