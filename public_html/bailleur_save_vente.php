<?php
/**
 * bailleur_save_vente.php — Enregistre les données financières VENTE d'un bien
 * saisies dans le simulateur du tableau Patrimoine actif (prix de vente,
 * prix/m², rentabilité). Persiste en BDD (biens) pour que le module Transaction
 * et l'annonce reprennent automatiquement ces valeurs.
 *
 * POST : id_bien, id_proprietaire, [prix_vente], [rendement], [prix_m2]
 * Cible : biens.prix_demande_initial, prix_vente_estime, rendement_brut
 */
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/roles_services.php';
require_once __DIR__ . '/inc/bien_prix.php';
require_once __DIR__ . '/inc/dossier_vente.php';   // PIVOT vente (Phase 1)
require_login();
header('Content-Type: application/json; charset=utf-8');

$pdo          = $GLOBALS['pdo'];
$userId       = (int)current_user_id();
$roleId       = (int)current_role_id();
$isSuperAdmin = is_super_admin();

if (!is_post()) { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'POST requis']); exit; }
if (!$isSuperAdmin && !hasServiceAccess($roleId, 'bailleur')) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Accès réservé au module Bailleur']); exit;
}

$idBien = (int)($_POST['id_bien'] ?? 0);
$idProp = (int)($_POST['id_proprietaire'] ?? 0);
$num = static fn($k) => array_key_exists($k, $_POST) && $_POST[$k] !== ''
    ? (float)str_replace([' ', ','], ['', '.'], (string)$_POST[$k]) : null;
$prixVente = $num('prix_vente');
$rendement = $num('rendement');
if ($idBien <= 0 || $idProp <= 0) { echo json_encode(['ok'=>false,'error'=>'paramètres manquants']); exit; }
if ($prixVente !== null && ($prixVente < 0 || $prixVente > 1e11)) { echo json_encode(['ok'=>false,'error'=>'prix invalide']); exit; }
if ($rendement !== null && ($rendement < 0 || $rendement > 100))  { echo json_encode(['ok'=>false,'error'=>'rendement invalide']); exit; }

// ── Ownership (périmètre propriétaire, via CRG puis repli biens) ──
if (!$isSuperAdmin) {
    $chk = $pdo->prepare("SELECT 1 FROM user_proprietaires WHERE id_user=? AND id_proprietaire=? LIMIT 1");
    $chk->execute([$userId, $idProp]);
    if (!$chk->fetchColumn()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Propriétaire hors périmètre']); exit; }
}
try {
    $st = $pdo->prepare("SELECT id FROM biens WHERE id=? LIMIT 1");
    $st->execute([$idBien]);
    if (!$st->fetchColumn()) { echo json_encode(['ok'=>false,'error'=>'bien introuvable']); exit; }

    // Lien bien↔propriétaire : non vérifié pour le super admin (accès global).
    if (!$isSuperAdmin) {
        $chkB = $pdo->prepare("SELECT 1 FROM crg_situations_locataires c
            JOIN crg_trimestres t ON c.id_crg = t.id WHERE c.id_bien=? AND t.id_proprietaire=? LIMIT 1");
        $chkB->execute([$idBien, $idProp]);
        $okLien = (bool)$chkB->fetchColumn();
        if (!$okLien) {
            $chkB2 = $pdo->prepare("SELECT 1 FROM biens WHERE id=? AND id_proprietaire=? LIMIT 1");
            $chkB2->execute([$idBien, $idProp]);
            $okLien = (bool)$chkB2->fetchColumn();
        }
        if (!$okLien) { echo json_encode(['ok'=>false,'error'=>'bien hors périmètre propriétaire']); exit; }
    }

    // VALIDATION du prix via le helper unique : historise + miroirs (annonce/biens).
    // Scénario : 'courant' (par défaut, diffusé) ou IFI/mini/maxi/snapshot (interne).
    $commentaire   = trim((string)($_POST['commentaire'] ?? '')) ?: null;
    $scenario      = trim((string)($_POST['scenario_code'] ?? 'courant')) ?: 'courant';
    $scenarioLabel = trim((string)($_POST['scenario_label'] ?? '')) ?: null;
    // Le commentaire reprend par défaut le libellé du scénario (gain de temps côté UI).
    if ($commentaire === null && $scenarioLabel) $commentaire = $scenarioLabel;
    $res = ['ok'=>true, 'changed'=>false, 'montant'=>null];
    if ($prixVente !== null && $prixVente > 0) {
        $res = bien_prix_valider($pdo, $idBien, 'prix_vente', $prixVente, 'patrimoine', $userId, $commentaire, true, $scenario, $scenarioLabel);
        if (!$res['ok']) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>$res['error'] ?? 'échec validation']); exit; }

        // Si AUCUN prix courant n'existe encore, le 1er prix validé (peu importe le scénario)
        // devient aussi le prix COURANT de base par défaut.
        if ($scenario !== 'courant' && function_exists('bien_prix_courant')) {
            $courantExistant = bien_prix_courant($pdo, $idBien, 'prix_vente', 'courant');
            if ($courantExistant === null) {
                bien_prix_valider($pdo, $idBien, 'prix_vente', $prixVente, 'patrimoine', $userId,
                    ($commentaire ?: 'Prix de base (1er validé)'), true, 'courant', 'Courant');
            }
        }
    }
    // Rendement : dérivé (non historisé), écrit dans biens UNIQUEMENT pour le scénario courant.
    if ($rendement !== null && $scenario === 'courant') {
        $pdo->prepare("UPDATE biens SET rendement_brut = :r, date_modification = NOW() WHERE id = :id")
            ->execute([':r' => $rendement > 0 ? $rendement : null, ':id' => $idBien]);
    }

    // ── PIVOT vente : naissance idempotente du dossier de vente à l'estimation ──
    // Le dossier agrège l'existant (prix lu par référence, jamais recopié ici).
    $idDossier = 0;
    if ($prixVente !== null && $prixVente > 0) {
        try {
            $idDossier = dv_ensure_for_bien($pdo, $idBien, ['source' => 'estimation', 'id_user' => $userId]);
        } catch (Throwable $e) {
            error_log('[bailleur_save_vente dossier_vente] ' . $e->getMessage());
        }
    }

    echo json_encode(['ok'=>true, 'id_bien'=>$idBien, 'scenario'=>$scenario, 'changed'=>$res['changed'] ?? false,
                      'prix_courant'=>$res['montant'] ?? null, 'rendement_brut'=>$rendement,
                      'id_dossier_vente'=>$idDossier ?: null]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
