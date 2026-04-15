<?php
declare(strict_types=1);

/**
 * POST /api/bien_photo_delete.php
 *
 * Supprime une photo de la bibliothèque d'un bien (biens_photos).
 * Scope société vérifié. CSRF : ajouter_bien (même que le formulaire).
 *
 * Paramètres : id_photo, csrf_token
 * Retour JSON : { ok: bool, error?: string }
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/bien_photos_manager.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']);
        exit;
    }
    verify_csrf_any('ajouter_bien');

    $pdo       = db();
    $societeId = (int)($_SESSION['id_societe'] ?? 0);
    $idPhoto   = (int)($_POST['id_photo'] ?? 0);

    if ($idPhoto <= 0) {
        throw new RuntimeException('id_photo manquant');
    }

    // Vérifie la photo + scope société (via le bien)
    $st = $pdo->prepare("
        SELECT bp.id, bp.id_bien, b.id_societe
        FROM biens_photos bp
        JOIN biens b ON b.id = bp.id_bien
        WHERE bp.id = ?
        LIMIT 1
    ");
    $st->execute([$idPhoto]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new RuntimeException('Photo introuvable');
    }
    if ($societeId > 0 && (int)$row['id_societe'] !== $societeId) {
        throw new RuntimeException('Accès refusé');
    }

    // Nettoie aussi d'éventuelles photos d'annonce dérivées de ce bien-photo
    // si un lien existe (table annonces_photos peut avoir une colonne id_bien_photo).
    try {
        $pdo->prepare("DELETE FROM annonces_photos WHERE id_bien_photo = ?")
            ->execute([$idPhoto]);
    } catch (Throwable) {
        // Colonne/table absente : ignorer, la bibliothèque reste la source de vérité
    }

    $manager = new BienPhotosManager($pdo);
    $ok = $manager->supprimer($idPhoto);

    echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
