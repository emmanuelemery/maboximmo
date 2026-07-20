<?php
// api/bien_archiver.php — Archive un bien (statut_bien='archive') depuis la fiche immeuble.
// GARDE-FOU : refuse si le bien porte une annonce ACTIVE (publiée / diffusée).
// Sécurité : login + CSRF (form 'archiver_bien') + rôles staff.
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

// ── DOUBLE VALIDATION : confirmation explicite + motif obligatoire ──
$confirm = (string)($_POST['confirm'] ?? '');
$motif   = trim((string)($_POST['motif'] ?? ''));
if ($confirm !== '1') {
    http_response_code(400);
    exit(json_encode(['success' => false, 'code' => 'CONFIRM_REQUIRED',
        'message' => "Confirmation requise avant archivage."]));
}
if ($motif === '') {
    http_response_code(400);
    exit(json_encode(['success' => false, 'code' => 'MOTIF_REQUIRED',
        'message' => "Un motif d'archivage est obligatoire."]));
}

// Le bien doit exister et ne pas être déjà archivé/supprimé.
$chk = $pdo->prepare("SELECT id, statut_bien FROM biens WHERE id = ? LIMIT 1");
$chk->execute([$idBien]);
$bien = $chk->fetch(PDO::FETCH_ASSOC);
if (!$bien) {
    http_response_code(404);
    exit(json_encode(['success' => false, 'message' => 'Bien introuvable.']));
}
if (in_array((string)$bien['statut_bien'], ['archive', 'supprime'], true)) {
    exit(json_encode(['success' => false, 'message' => 'Bien déjà archivé.']));
}

// GARDE-FOU métier : annonce active (publiée ou diffusée) → on bloque.
$can = bien_statut_can_archive($pdo, $idBien);
if (!$can['ok']) {
    http_response_code(409);
    exit(json_encode(['success' => false, 'code' => $can['code'] ?? 'BLOCKED', 'message' => $can['error']]));
}

// Changement de statut CENTRALISÉ + TRACÉ (AuditLog) + cascade annonces.
$res = bien_set_statut($pdo, $idBien, 'archive', ['motif' => $motif, 'source' => 'bien_archiver']);
if (!$res['ok']) {
    http_response_code(500);
    exit(json_encode(['success' => false, 'message' => 'Erreur : ' . ($res['error'] ?? 'inconnue')]));
}

echo json_encode(['success' => true, 'id_bien' => $idBien, 'statut_bien' => 'archive',
                  'cascaded_annonces' => $res['cascaded_annonces'] ?? 0]);
