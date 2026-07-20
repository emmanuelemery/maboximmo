<?php
// api/bien_desarchiver.php — Désarchive un bien (statut_bien 'archive' → 'actif'), TRACÉ.
// Sécurité : login + CSRF (form 'archiver_bien') + rôles staff [1,2,3,7].
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/bien_statut.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

/** @var PDO $pdo */
$pdo = $GLOBALS['pdo'] ?? db();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'message' => 'Méthode non autorisée.']));
}
verify_csrf_any('archiver_bien');

$roleId = (int)current_role_id();
if (!in_array($roleId, [1, 2, 3, 7], true)) {
    http_response_code(403);
    exit(json_encode(['success' => false, 'message' => 'Accès refusé.']));
}

$idBien = (int)($_POST['id_bien'] ?? 0);
if ($idBien <= 0) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'message' => 'Bien invalide.']));
}

$chk = $pdo->prepare("SELECT id, statut_bien FROM biens WHERE id = ? LIMIT 1");
$chk->execute([$idBien]);
$bien = $chk->fetch(PDO::FETCH_ASSOC);
if (!$bien) {
    http_response_code(404);
    exit(json_encode(['success' => false, 'message' => 'Bien introuvable.']));
}
if ((string)$bien['statut_bien'] !== 'archive') {
    $msg = "Ce bien n'est pas archivé (statut : " . (string)$bien['statut_bien'] . ").";
    exit(json_encode(['success' => false, 'message' => $msg]));
}

// Cible : 'actif' par défaut ; 'brouillon' si demandé (repasse en édition).
$cible = (($_POST['cible'] ?? 'actif') === 'brouillon') ? 'brouillon' : 'actif';
$motif = trim((string)($_POST['motif'] ?? 'Désarchivage'));

$res = bien_set_statut($pdo, $idBien, $cible, ['motif' => $motif, 'source' => 'bien_desarchiver', 'cascade_annonces' => false]);
if (!$res['ok']) {
    http_response_code(500);
    exit(json_encode(['success' => false, 'message' => 'Erreur : ' . ($res['error'] ?? 'inconnue')]));
}

// Option : ré-ouvrir les annonces archivées par la cascade (→ brouillon).
$reopened = 0;
if (($_POST['reopen_annonces'] ?? '') === '1') {
    try {
        $stmt = $pdo->prepare("UPDATE annonces SET statut='brouillon', etat_publication='brouillon', date_modification=NOW()
                               WHERE id_bien = ? AND (statut='archive' OR etat_publication='archive')");
        $stmt->execute([$idBien]);
        $reopened = $stmt->rowCount();
    } catch (Throwable $e) { /* colonnes variables → non bloquant */ }
}

echo json_encode(['success' => true, 'id_bien' => $idBien, 'statut_bien' => $cible, 'annonces_reouvertes' => $reopened]);
