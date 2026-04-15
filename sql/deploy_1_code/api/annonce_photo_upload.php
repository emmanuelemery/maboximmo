<?php
declare(strict_types=1);

/**
 * POST /api/annonce_photo_upload.php
 *
 * Upload une photo pour une annonce et génère toutes les variantes
 * (original JPEG + 3 WebP) via AnnoncePhotosManager.
 *
 * Paramètres :
 *   - photo        : fichier (multipart/form-data)
 *   - id_annonce   : ID de l'annonce cible
 *   - csrf_token   : jeton CSRF du formulaire annonce_nouvelle
 *
 * Réponse JSON :
 *   { ok: true, id_annonce, ordre, dir, photos: [{variante, id, fichier}, ...] }
 *   { ok: false, error: "..." }
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
    $idAnnonce = (int)($_POST['id_annonce'] ?? 0);

    if ($idAnnonce <= 0) {
        throw new RuntimeException('id_annonce manquant');
    }

    // Vérification : l'annonce appartient à la société de l'utilisateur
    $st = $pdo->prepare("SELECT id, id_societe FROM annonces WHERE id = ? LIMIT 1");
    $st->execute([$idAnnonce]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['id_societe'] !== $societeId) {
        throw new RuntimeException('Annonce introuvable ou accès refusé');
    }

    // Fichier reçu ?
    if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Fichier non reçu ou erreur upload');
    }
    $file = $_FILES['photo'];
    if ($file['size'] > 10 * 1024 * 1024) {
        throw new RuntimeException('Fichier trop volumineux (max 10 Mo)');
    }
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']) ?: '';
    finfo_close($finfo);
    if (!in_array($mime, $allowed, true)) {
        throw new RuntimeException('Format non supporté (JPEG/PNG/WebP uniquement)');
    }

    // Copie dans un tmp stable (move_uploaded_file pour respecter la sandbox)
    $tmpDir = dirname(__DIR__) . '/uploads/_tmp/';
    if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0755, true); }
    $tmpPath = $tmpDir . 'upload_' . uniqid('', true) . '.tmp';
    if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
        throw new RuntimeException('Déplacement du fichier impossible');
    }

    // Ordre suivant = max(ordre) + 1
    $st2 = $pdo->prepare("SELECT COALESCE(MAX(ordre_affichage),0) FROM annonces_photos WHERE id_annonce = ?");
    $st2->execute([$idAnnonce]);
    $nextOrdre = ((int)$st2->fetchColumn()) + 1;
    $principale = ($nextOrdre === 1);

    // Import
    $manager = new AnnoncePhotosManager($pdo);
    $result = $manager->importerDepuisFichier($idAnnonce, $tmpPath, $nextOrdre, $principale);

    // Nettoyage tmp
    @unlink($tmpPath);

    if (!$result['ok']) {
        throw new RuntimeException($result['error'] ?? 'Échec import');
    }

    // Réponse avec le chemin public des photos
    $dirRel = 'uploads/annonces/' . $societeId . '/' . $idAnnonce . '/';
    echo json_encode([
        'ok'         => true,
        'id_annonce' => $idAnnonce,
        'ordre'      => $nextOrdre,
        'principale' => $principale,
        'dir'        => $dirRel,
        'photos'     => $result['photos'],
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
