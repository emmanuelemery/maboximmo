<?php
declare(strict_types=1);
set_time_limit(60);

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/auth.php';
require_once dirname(__DIR__) . '/inc/bien_photos_manager.php';
require_once dirname(__DIR__) . '/inc/bien_form_loader.php';
require_once dirname(__DIR__) . '/inc/bien_photo_analyser.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$pdo       = $GLOBALS['pdo'];
$societeId = (int)($_SESSION['id_societe'] ?? 0);
$agenceId  = (int)($_SESSION['id_agence']  ?? 0);
$userId    = (int)($_SESSION['user_id']    ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'Méthode non autorisée']));
}
verify_csrf_any('ajouter_bien');

if (empty($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
    exit(json_encode(['ok' => false, 'error' => 'Aucun fichier reçu']));
}
$file = $_FILES['fichier'];
$ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) {
    exit(json_encode(['ok' => false, 'error' => 'Format non supporté (JPG/PNG/WebP uniquement)']));
}
// Validation MIME réelle (pas seulement extension)
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$realMime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
$allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($realMime, $allowedMimes, true)) {
    exit(json_encode(['ok' => false, 'error' => "Type MIME invalide ({$realMime}). Image JPG/PNG/WebP requis."]));
}
if ($file['size'] > 15 * 1024 * 1024) {
    exit(json_encode(['ok' => false, 'error' => 'Photo trop volumineuse (max 15 Mo)']));
}

// Si pas de bien fourni, on crée un brouillon à la volée (mêmecomportement que l'upload PDF)
$bienId = isset($_POST['id_bien']) && ctype_digit((string)$_POST['id_bien']) ? (int)$_POST['id_bien'] : 0;
if ($bienId === 0) {
    try {
        $bienId = bien_form_create_draft($pdo, $societeId ?: null, $agenceId ?: null);
    } catch (Throwable $e) {
        exit(json_encode(['ok' => false, 'error' => 'Création brouillon : ' . $e->getMessage()]));
    }
}

try {
    $manager = new BienPhotosManager($pdo);
    $res = $manager->ajouterPhoto(
        $bienId,
        $societeId ?: 0,
        $file['tmp_name'],
        $file['name'],
        $userId ?: null
    );
    if (!$res['ok']) {
        exit(json_encode(['ok' => false, 'error' => $res['error'] ?? 'Échec ajout photo', 'bien_id' => $bienId]));
    }

    // ── Analyse IA Vision : description courte + catégorie ──
    $analyse = ['ok' => false];
    $photoAbs = dirname(__DIR__) . '/' . $res['url'];
    if (is_file($photoAbs) && empty($res['duplicate'])) {
        try {
            $analyse = analyserPhotoBien($photoAbs);
            if ($analyse['ok']) {
                $pdo->prepare("
                    UPDATE biens_photos
                    SET categorie = ?, description_ia = ?, description_ia_date = NOW()
                    WHERE id = ?
                ")->execute([
                    $analyse['categorie'] ?? null,
                    $analyse['description'] ?? null,
                    (int)$res['id'],
                ]);
            }
        } catch (Throwable $e) {
            error_log('[bien_intake_photo] vision: ' . $e->getMessage());
        }
    }

    echo json_encode([
        'ok'        => true,
        'bien_id'   => $bienId,
        'id'        => $res['id'],
        'url'       => app_url('/' . $res['url']),
        'ordre'     => $res['ordre'] ?? null,
        'duplicate' => $res['duplicate'] ?? false,
        'categorie' => $analyse['ok'] ? ($analyse['categorie'] ?? null) : null,
        'description' => $analyse['ok'] ? ($analyse['description'] ?? null) : null,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
