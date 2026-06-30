<?php
/**
 * AJAX — Marquer/démarquer un immeuble comme vendu
 * POST: id_immeuble, vendu (0|1), date_vente (optionnel)
 * Sécurité : super admin OU bailleur propriétaire de l'immeuble
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

$pdo    = $GLOBALS['pdo'];
$id     = (int)($_POST['id_immeuble'] ?? 0);
$vendu  = (int)($_POST['vendu'] ?? 0) ? 1 : 0;
$date_v = trim($_POST['date_vente'] ?? '');

if (!$id) { echo json_encode(['ok'=>false,'msg'=>'id manquant']); exit; }

// ── Vérifier que l'immeuble existe et que l'utilisateur y a accès ──
$stmt = $pdo->prepare("SELECT id_proprietaire FROM immeubles WHERE id=?");
$stmt->execute([$id]);
$imm = $stmt->fetch(\PDO::FETCH_ASSOC);
if (!$imm) { echo json_encode(['ok'=>false,'msg'=>'Immeuble introuvable']); exit; }

if (!$isSuperAdmin) {
    // Non-super-admin : vérifier qu'il gère ce propriétaire
    $stmtP = $pdo->prepare("SELECT COUNT(*) FROM user_proprietaires WHERE id_user=? AND id_proprietaire=?");
    $stmtP->execute([$userId, $imm['id_proprietaire']]);
    if ($stmtP->fetchColumn() < 1) {
        http_response_code(403);
        echo json_encode(['ok'=>false,'msg'=>'Accès refusé (propriétaire)']);
        exit;
    }
}

// Fonctions de synchro Transaction ↔ Bailleur — DÉFINIES AVANT leur appel.
// (Les définitions conditionnelles `if(!function_exists)` ne sont PAS hoistées :
//  les laisser en bas provoquait un fatal "undefined function" → réponse vide → JS figé.)
if (!function_exists('_sync_biens_vendu')) {
    /** Marque tous les biens de l'immeuble vendu : statut_bien='vendu' + date_retrait. */
    function _sync_biens_vendu(\PDO $pdo, int $immId, ?string $dateVente): void {
        $dateToday = $dateVente ?? date('Y-m-d');
        $pdo->prepare("
            UPDATE biens
            SET statut_bien = 'vendu',
                date_retrait_commercialisation = IFNULL(date_retrait_commercialisation, ?),
                date_modification = NOW()
            WHERE id_immeuble = ?
              AND (statut_bien IS NULL OR statut_bien NOT IN ('vendu','archive','supprime'))
        ")->execute([$dateToday, $immId]);
    }
}
if (!function_exists('_sync_biens_not_vendu')) {
    /** Démarque l'immeuble vendu : ses biens repassent en 'archive'. */
    function _sync_biens_not_vendu(\PDO $pdo, int $immId): void {
        $pdo->prepare("
            UPDATE biens
            SET statut_bien = 'archive',
                date_modification = NOW()
            WHERE id_immeuble = ? AND statut_bien = 'vendu'
        ")->execute([$immId]);
    }
}

if ($vendu && $date_v) {
    $pdo->prepare("UPDATE immeubles SET vendu=1, date_vente=? WHERE id=?")->execute([$date_v, $id]);
    // Synchro Transaction : marquer tous les biens de cet immeuble comme 'vendu'
    _sync_biens_vendu($pdo, $id, $date_v);
} elseif ($vendu) {
    $pdo->prepare("UPDATE immeubles SET vendu=1, date_vente=NULL WHERE id=?")->execute([$id]);
    // Synchro Transaction : marquer tous les biens de cet immeuble comme 'vendu'
    _sync_biens_vendu($pdo, $id, null);
} else {
    $pdo->prepare("UPDATE immeubles SET vendu=0, date_vente=NULL WHERE id=?")->execute([$id]);
    // Synchro Transaction : retirer le statut 'vendu' (marquer 'archive' à la place)
    _sync_biens_not_vendu($pdo, $id);
}

$r = $pdo->prepare("SELECT vendu, date_vente, nom_immeuble, adresse_1 FROM immeubles WHERE id=?");
$r->execute([$id]);
$row = $r->fetch(\PDO::FETCH_ASSOC);

echo json_encode([
    'ok'         => true,
    'vendu'      => (int)($row['vendu'] ?? 0),
    'date_vente' => $row['date_vente'] ?? null,
    'nom'        => $row['nom_immeuble'] ?? '',
    'adresse'    => $row['adresse_1'] ?? '',
]);
