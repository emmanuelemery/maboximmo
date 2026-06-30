<?php
// api/doublon_delete.php — Suppression manuelle d'un bien doublon depuis la page de revue.
// Sécurité : login + rôle gestionnaire + CSRF. Garde-fous :
//   - refus si le bien est rattaché au CRG (crg_situations_locataires) → c'est un gardien
//   - refus si le bien a une annonce ou un mandat actif
//   - refus si c'est le dernier bien portant cette référence (on ne vide jamais une réf)
// Nettoie les enfants sans FK (orphelins) puis supprime le bien (CASCADE gère le reste).
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'] ?? db();

$roleId = (int)current_role_id();
if (!in_array($roleId, [1, 2, 3], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès refusé.']);
    exit;
}

verify_csrf_any('doublon_delete');

$idBien = (int)($_POST['id_bien'] ?? 0);
if ($idBien <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Bien invalide.']);
    exit;
}

$b = $pdo->prepare("SELECT id, reference_bien FROM biens WHERE id = ?");
$b->execute([$idBien]);
$bien = $b->fetch(PDO::FETCH_ASSOC);
if (!$bien) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Bien introuvable.']);
    exit;
}

// ── Garde-fous ─────────────────────────────────────────────────────
$cnt = fn(string $t) => (int)$pdo->query("SELECT COUNT(*) FROM `$t` WHERE id_bien = " . (int)$idBien)->fetchColumn();

if ($cnt('crg_situations_locataires') > 0) {
    echo json_encode(['success' => false, 'message' => '⛔ Bien rattaché au CRG (gardien) — suppression refusée. Supprimez plutôt l\'autre.']);
    exit;
}
if ($cnt('annonces') > 0) {
    echo json_encode(['success' => false, 'message' => '⛔ Bien avec une annonce — suppression refusée.']);
    exit;
}
$mandActifs = (int)$pdo->query("SELECT COUNT(*) FROM mandats WHERE id_bien = " . (int)$idBien . " AND statut = 'actif'")->fetchColumn();
if ($mandActifs > 0) {
    echo json_encode(['success' => false, 'message' => '⛔ Bien avec un mandat actif — suppression refusée.']);
    exit;
}
if ($bien['reference_bien'] !== null && $bien['reference_bien'] !== '') {
    $autres = $pdo->prepare("SELECT COUNT(*) FROM biens WHERE reference_bien = ? AND id <> ?");
    $autres->execute([$bien['reference_bien'], $idBien]);
    if ((int)$autres->fetchColumn() === 0) {
        echo json_encode(['success' => false, 'message' => '⛔ Dernier bien de cette référence — non supprimé (on ne vide pas une référence).']);
        exit;
    }
}

// ── Suppression ────────────────────────────────────────────────────
try {
    $pdo->beginTransaction();
    // Enfants sans contrainte FK (sinon orphelins).
    foreach (['bien_prix', 'biens_versions', 'biens_documents', 'bien_chauffages', 'bien_energies',
              'bien_vues', 'crg_link_bak3', 'locataires_statuts'] as $t) {
        try { $pdo->exec("DELETE FROM `$t` WHERE id_bien = " . (int)$idBien); } catch (Throwable $e) {}
    }
    // Le bien (CASCADE : baux, mandats, biens_caracteristiques, biens_photos, biens_tags, dpe_diags, medias).
    $pdo->exec("DELETE FROM biens WHERE id = " . (int)$idBien);
    $pdo->commit();
    echo json_encode(['success' => true, 'id_bien' => $idBien]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
