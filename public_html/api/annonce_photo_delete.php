<?php
declare(strict_types=1);

/**
 * POST /api/annonce_photo_delete.php
 *
 * Supprime une photo (et toutes ses variantes) d'une annonce.
 * Paramètres : id_photo, csrf_token
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/annonce_photos_manager.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']);
        exit;
    }
    verify_csrf_any('annonce_nouvelle');

    $pdo       = db();
    $societeId = (int)($_SESSION['id_societe'] ?? 0);
    $idPhoto   = (int)($_POST['id_photo'] ?? 0);

    if ($idPhoto <= 0) throw new RuntimeException('id_photo manquant');

    // Récupérer la photo + son annonce + vérifier la société
    $st = $pdo->prepare("
        SELECT p.id_annonce, p.ordre_affichage, a.id_societe
        FROM annonces_photos p
        JOIN annonces a ON a.id = p.id_annonce
        WHERE p.id = ? LIMIT 1
    ");
    $st->execute([$idPhoto]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['id_societe'] !== $societeId) {
        throw new RuntimeException('Photo introuvable ou accès refusé');
    }

    $manager = new AnnoncePhotosManager($pdo);
    $ok = $manager->supprimer((int)$row['id_annonce'], (int)$row['ordre_affichage']);

    echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
