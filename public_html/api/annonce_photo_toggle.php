<?php
// api/annonce_photo_toggle.php — Ajoute/retire une photo du bien dans l'annonce
declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_login();

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST requis']));
}

verify_csrf_any('ajouter_bien');

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);

$annonceId = isset($_POST['id_annonce']) && ctype_digit((string)$_POST['id_annonce']) ? (int)$_POST['id_annonce'] : 0;
$photoId   = isset($_POST['id_biens_photo']) && ctype_digit((string)$_POST['id_biens_photo']) ? (int)$_POST['id_biens_photo'] : 0;

if ($annonceId <= 0 || $photoId <= 0) {
    exit(json_encode(['ok' => false, 'error' => 'Paramètres manquants']));
}

// Scope : verifier que l'annonce et la photo appartiennent au meme bien + societe
try {
    $st = $pdo->prepare("
        SELECT a.id_bien AS annonce_bien, b.id_societe, bp.id_bien AS photo_bien
        FROM annonces a
        JOIN biens b ON b.id = a.id_bien
        JOIN biens_photos bp ON bp.id = :photo
        WHERE a.id = :annonce
        LIMIT 1
    ");
    $st->execute([':annonce' => $annonceId, ':photo' => $photoId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) exit(json_encode(['ok' => false, 'error' => 'Annonce ou photo introuvable']));
    if ((int)$row['annonce_bien'] !== (int)$row['photo_bien']) {
        exit(json_encode(['ok' => false, 'error' => 'Photo non rattachée au même bien']));
    }
    if ($societeId > 0 && (int)$row['id_societe'] !== $societeId) {
        http_response_code(403);
        exit(json_encode(['ok' => false, 'error' => 'Hors scope société']));
    }
} catch (Throwable $e) {
    exit(json_encode(['ok' => false, 'error' => 'Erreur vérif scope']));
}

// Toggle : si déjà présent → DELETE, sinon INSERT avec ordre max+1
try {
    $st = $pdo->prepare("SELECT id FROM annonces_photos WHERE id_annonce = ? AND id_biens_photo = ? LIMIT 1");
    $st->execute([$annonceId, $photoId]);
    $existingId = (int)($st->fetchColumn() ?: 0);

    if ($existingId > 0) {
        $pdo->prepare("DELETE FROM annonces_photos WHERE id = ?")->execute([$existingId]);
        $action = 'removed';
    } else {
        // Ordre max actuel + 1
        $st = $pdo->prepare("SELECT COALESCE(MAX(ordre), -1) + 1 FROM annonces_photos WHERE id_annonce = ?");
        $st->execute([$annonceId]);
        $nextOrder = (int)$st->fetchColumn();
        $pdo->prepare("INSERT INTO annonces_photos (id_annonce, id_biens_photo, ordre) VALUES (?, ?, ?)")
            ->execute([$annonceId, $photoId, $nextOrder]);
        $action = 'added';
    }

    // Nouveau count
    $st = $pdo->prepare("SELECT COUNT(*) FROM annonces_photos WHERE id_annonce = ?");
    $st->execute([$annonceId]);
    $count = (int)$st->fetchColumn();

    exit(json_encode(['ok' => true, 'action' => $action, 'count' => $count]));
} catch (Throwable $e) {
    error_log('[annonce_photo_toggle] ' . $e->getMessage());
    http_response_code(500);
    exit(json_encode(['ok' => false, 'error' => $e->getMessage()]));
}
