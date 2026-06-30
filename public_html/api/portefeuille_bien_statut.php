<?php
// api/portefeuille_bien_statut.php
// Bascule le statut de commercialisation d'un bien depuis la page Portefeuilles.
//   à vendre  → type_commercialisation = 'vente'
//   pas à vendre → type_commercialisation = NULL
// Sécurité : login + CSRF (X-CSRF-Token) + le bien DOIT appartenir au périmètre Portefeuilles.
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/bien_missions.php';
require_once __DIR__ . '/../inc/dossier_vente.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'] ?? db();

// Gestionnaires (1,2,3,7) ET bailleurs (9,10) — le périmètre ci-dessous borne
// déjà le bailleur à SES propres biens (module Bailleur en libre-service).
$roleId = (int)current_role_id();
if (!in_array($roleId, [1, 2, 3, 7, 9, 10], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès refusé.']);
    exit;
}

verify_csrf_any('portefeuille_statut');

$idBien  = (int)($_POST['id_bien'] ?? 0);
$aVendre = (string)($_POST['a_vendre'] ?? '') === '1';   // état CIBLE demandé

if ($idBien <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Bien invalide.']);
    exit;
}

// Périmètre = borne dure : on ne modifie QUE les biens VISIBLES par cet utilisateur
// (staff = tout le périmètre ; bailleur = ses propriétaires assignés).
require_once __DIR__ . '/../inc/portefeuille_scope.php';
$perimetreIds = pf_scope($pdo)['ids'];
$in           = !empty($perimetreIds) ? implode(',', array_map('intval', $perimetreIds)) : '0';

$chk = $pdo->prepare("SELECT id, id_agence, id_proprietaire, type_commercialisation FROM biens
                      WHERE id = ? AND id_proprietaire IN ($in)
                        AND (statut_bien IS NULL OR statut_bien NOT IN ('supprime','archive'))");
$chk->execute([$idBien]);
$bien = $chk->fetch(PDO::FETCH_ASSOC);

if (!$bien) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Bien hors périmètre.']);
    exit;
}

try {
    $pdo->beginTransaction();
    // Mission canonique dans mandats ; type_commercialisation = miroir dérivé.
    if ($aVendre) {
        // Mise en vente → garantir un mandat vente (projet)
        ensure_mandat_vente($pdo, $idBien, [
            'id_agence'       => (int)($bien['id_agence'] ?: 0) ?: null,
            'id_proprietaire' => (int)($bien['id_proprietaire'] ?: 0) ?: null,
            'id_user'         => (int)current_user_id() ?: null,
        ]);
        derive_type_commercialisation($pdo, $idBien);
    } else {
        // Retrait (toggle « pas à vendre ») = annulation → sans suite
        mandat_vente_sans_suite($pdo, $idBien);
    }
    $pdo->commit();
    echo json_encode([
        'success'  => true,
        'id_bien'  => $idBien,
        'a_vendre' => $aVendre,
        'statut'   => $aVendre ? 'vente' : null,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
