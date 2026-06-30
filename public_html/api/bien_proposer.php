<?php
/**
 * api/bien_proposer.php — Marque un bien « à proposer » (ou non) à un investisseur.
 * Écrit dans biens.a_proposer (1/0) — champ DÉDIÉ, ne touche pas type_commercialisation.
 *
 * Propriété vérifiée comme le Patrimoine actif : biens.id_proprietaire dans le périmètre
 * OU bien lié via CRG à un propriétaire du périmètre (les biens SIR/SABY/FOCH ont souvent
 * id_proprietaire NULL/désaligné → la vérif sur id_proprietaire seule échouait).
 *
 * POST : id_bien, proposer (1|0), csrf_token (form 'portefeuille_statut').
 * Sécurité : login + CSRF + rôle gestionnaire (1,2,3,7) / super admin.
 */
declare(strict_types=1);
ini_set('display_errors', '0');
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/portefeuille_scope.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit;
}
verify_csrf_any('portefeuille_statut');

$pdo    = $GLOBALS['pdo'];
$roleId = (int)current_role_id();
// Gestionnaires (1,2,3,7), bailleurs (9,10) et super admin. Le périmètre ci-dessous
// borne le bailleur à SES biens (module Bailleur en libre-service).
$isManager = in_array($roleId, [1,2,3,7,9,10], true) || (function_exists('is_super_admin') && is_super_admin());
if (!$isManager) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'accès refusé']); exit; }

$idBien   = isset($_POST['id_bien']) && ctype_digit((string)$_POST['id_bien']) ? (int)$_POST['id_bien'] : 0;
$proposer = ((string)($_POST['proposer'] ?? '') === '1') ? 1 : 0;
if ($idBien <= 0) { echo json_encode(['ok'=>false,'error'=>'id_bien requis']); exit; }

// Périmètre visible (même borne que le patrimoine / portefeuille)
$perimetreIds = pf_scope($pdo)['ids'];
$in = !empty($perimetreIds) ? implode(',', array_map('intval', $perimetreIds)) : '0';

// Le bien doit appartenir au périmètre : par id_proprietaire OU via CRG.
$chk = $pdo->prepare("
    SELECT 1 FROM biens b
     WHERE b.id = ?
       AND (b.statut_bien IS NULL OR b.statut_bien NOT IN ('supprime','archive'))
       AND (
            b.id_proprietaire IN ($in)
            OR b.id IN (SELECT cs.id_bien FROM crg_situations_locataires cs
                        JOIN crg_trimestres ct ON ct.id = cs.id_crg
                        WHERE ct.id_proprietaire IN ($in))
       )
     LIMIT 1");
$chk->execute([$idBien]);
if (!$chk->fetchColumn()) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Bien hors périmètre.']); exit;
}

try {
    $pdo->prepare("UPDATE biens SET a_proposer = ?, date_modification = NOW() WHERE id = ?")
        ->execute([$proposer, $idBien]);
    echo json_encode(['ok'=>true, 'id_bien'=>$idBien, 'a_proposer'=>$proposer]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
