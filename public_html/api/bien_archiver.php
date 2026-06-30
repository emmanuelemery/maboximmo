<?php
// api/bien_archiver.php — Archive un bien (statut_bien='archive') depuis la fiche immeuble.
// GARDE-FOU : refuse si le bien porte une annonce ACTIVE (publiée / diffusée).
// Sécurité : login + CSRF (form 'archiver_bien') + rôles staff.
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
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

// GARDE-FOU : annonce active (publiée ou diffusée) → on bloque.
$an = $pdo->prepare("SELECT COUNT(*) FROM annonces
                     WHERE id_bien = ?
                       AND (statut = 'publiee' OR etat_publication = 'diffusee')");
$an->execute([$idBien]);
if ((int)$an->fetchColumn() > 0) {
    http_response_code(409);
    exit(json_encode([
        'success' => false,
        'code'    => 'ANNONCE_ACTIVE',
        'message' => "Impossible d'archiver : ce bien a une annonce active. Retirez/archivez d'abord l'annonce.",
    ]));
}

try {
    $pdo->prepare("UPDATE biens SET statut_bien = 'archive', date_modification = NOW() WHERE id = ?")
        ->execute([$idBien]);
} catch (Throwable $e) {
    http_response_code(500);
    exit(json_encode(['success' => false, 'message' => 'Erreur : ' . $e->getMessage()]));
}

echo json_encode(['success' => true, 'id_bien' => $idBien, 'statut_bien' => 'archive']);
