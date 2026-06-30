<?php
/**
 * bailleur_retirer_vente.php — Retire un bien de la commercialisation « vente »
 * (annule un doublon de mise en vente). NE SUPPRIME JAMAIS le bien :
 *   - biens.type_commercialisation = NULL (+ date_retrait_commercialisation)
 *     → le bien quitte le tableau Transaction (vw_transactions exige type non nul).
 *   - l'annonce vente brouillon est archivée (statut='archivee') → plus de diffusion Ubiflow.
 * POST : id_bien, id_proprietaire.
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/roles_services.php';
require_once __DIR__ . '/inc/bien_missions.php';
require_once __DIR__ . '/inc/dossier_vente.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$pdo          = $GLOBALS['pdo'];
$userId       = (int)current_user_id();
$roleId       = (int)current_role_id();
$isSuperAdmin = is_super_admin();

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
// Accessible depuis le module Bailleur ET le module Transaction (agency).
$canBailleur = hasServiceAccess($roleId, 'bailleur');
$canAgency   = hasServiceAccess($roleId, 'agency');
if (!$isSuperAdmin && !$canBailleur && !$canAgency) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Accès refusé']); exit;
}

$idBien = (int)($_POST['id_bien'] ?? 0);
$idProp = (int)($_POST['id_proprietaire'] ?? 0);
if ($idBien <= 0) { echo json_encode(['ok'=>false,'error'=>'paramètres manquants']); exit; }

try {
    $st = $pdo->prepare("SELECT id, id_proprietaire, id_societe FROM biens WHERE id=? LIMIT 1");
    $st->execute([$idBien]);
    $bien = $st->fetch(\PDO::FETCH_ASSOC);
    if (!$bien) { echo json_encode(['ok'=>false,'error'=>'bien introuvable']); exit; }

    // ── Contrôle d'appartenance selon le profil (super admin = bypass) ──
    if (!$isSuperAdmin) {
        $autorise = false;
        // 1) Bailleur : le bien appartient à un de ses propriétaires (direct ou via CRG)
        if ($canBailleur) {
            $cB = $pdo->prepare("SELECT 1 FROM biens b
                WHERE b.id=? AND (
                    b.id_proprietaire IN (SELECT id_proprietaire FROM user_proprietaires WHERE id_user=?)
                    OR b.id IN (SELECT s.id_bien FROM crg_situations_locataires s JOIN crg_trimestres t ON t.id=s.id_crg
                                WHERE t.id_proprietaire IN (SELECT id_proprietaire FROM user_proprietaires WHERE id_user=?))
                ) LIMIT 1");
            $cB->execute([$idBien, $userId, $userId]);
            if ($cB->fetchColumn()) $autorise = true;
        }
        // 2) Agency : le bien relève de la société de l'utilisateur
        if (!$autorise && $canAgency) {
            $idSoc = (int)($_SESSION['id_societe'] ?? 0);
            if ($idSoc > 0 && (int)($bien['id_societe'] ?? 0) === $idSoc) $autorise = true;
        }
        if (!$autorise) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Bien hors périmètre']); exit; }
    }

    $pdo->beginTransaction();
    // RETRAIT (annulation, pas une vente) : mission vente → 'sans_suite',
    // dossier → 'sans_suite', miroir type_commercialisation re-dérivé (→ NULL).
    // Le bien reste intact (statut_bien inchangé).
    mandat_vente_sans_suite($pdo, $idBien);
    $pdo->prepare("UPDATE biens
        SET date_retrait_commercialisation = IFNULL(date_retrait_commercialisation, CURDATE()),
            date_modification = NOW()
        WHERE id = ?")->execute([$idBien]);
    // Archive les annonces vente non diffusées (brouillon) pour stopper toute diffusion
    $stU = $pdo->prepare("UPDATE annonces SET statut='archivee', date_modification=NOW()
        WHERE id_bien=? AND type_transaction='vente' AND (statut IS NULL OR statut NOT IN ('supprime','archivee'))");
    $stU->execute([$idBien]);
    $nbAnnonces = $stU->rowCount();
    $pdo->commit();

    echo json_encode(['ok'=>true, 'id_bien'=>$idBien, 'annonces_archivees'=>$nbAnnonces]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
