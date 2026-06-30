<?php
declare(strict_types=1);
set_time_limit(60);

/**
 * POST /api/bien_photo_edit.php
 *
 * Remplace une photo existante (biens_photos) par une version recadrée /
 * éclaircie côté navigateur. Le binaire reçu est déjà transformé : on se
 * contente de regénérer les variantes disque (original + LBC) AUX MÊMES
 * chemins (url_photo / url_lbc inchangés → pas de lien cassé), tout en
 * conservant une copie de l'original en *.orig-bak.jpg pour rollback.
 *
 * L'analyse IA est réinitialisée (la photo a changé → critique obsolète).
 *
 * Paramètres : id_photo, fichier (blob image), csrf_token
 * Retour JSON : { ok, url, width, height }
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/bien_photos_manager.php'; // tire image_tools.php
require_login();

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit(json_encode(['ok' => false, 'error' => 'Méthode non autorisée']));
    }
    verify_csrf_any('ajouter_bien');

    $pdo       = db();
    $societeId = (int)($_SESSION['id_societe'] ?? 0);
    $idPhoto   = (int)($_POST['id_photo'] ?? 0);

    if ($idPhoto <= 0) {
        throw new RuntimeException('id_photo manquant');
    }
    if (empty($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Aucune image reçue');
    }
    $tmp = $_FILES['fichier']['tmp_name'];

    // Validation MIME réelle
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $realMime = finfo_file($finfo, $tmp);
    finfo_close($finfo);
    if (!in_array($realMime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new RuntimeException("Type MIME invalide ({$realMime})");
    }
    if (filesize($tmp) > 20 * 1024 * 1024) {
        throw new RuntimeException('Image trop volumineuse (max 20 Mo)');
    }

    // Photo + scope société (via le bien)
    $st = $pdo->prepare("
        SELECT bp.id, bp.id_bien, bp.url_photo, bp.url_lbc, b.id_societe
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
    // Super-admin (role=1) bypass le filtre société (cohérent avec bien_photo_delete.php)
    $isSuperAdmin = ((int)($_SESSION['id_role'] ?? 0) === 1);
    if (!$isSuperAdmin && $societeId > 0 && (int)$row['id_societe'] !== $societeId) {
        throw new RuntimeException('Accès refusé');
    }

    $root    = dirname(__DIR__) . '/';
    $origRel = (string)$row['url_photo'];
    $lbcRel  = (string)($row['url_lbc'] ?? '');
    $origAbs = $root . $origRel;

    if ($origRel === '' || !is_file($origAbs)) {
        throw new RuntimeException('Fichier original introuvable sur disque');
    }

    // Backup de l'original (une seule fois : on ne veut JAMAIS écraser la vraie source)
    $bakAbs = preg_replace('/\.jpe?g$/i', '', $origAbs) . '.orig-bak.jpg';
    if (!is_file($bakAbs)) {
        if (!@copy($origAbs, $bakAbs)) {
            throw new RuntimeException('Impossible de sauvegarder l\'original');
        }
    }

    // Charge l'image éditée (redressement EXIF inclus)
    $loaded = it_load_and_orient($tmp);
    if (!$loaded) {
        throw new RuntimeException('Image éditée illisible');
    }
    [$img] = $loaded;

    // Regénère l'original (downscale + JPEG qualité) AU MÊME chemin
    $origRes = it_resize_max_width($img, BienPhotosManager::ORIGINAL_MAX_WIDTH);
    $origIsNew = ($origRes !== $img);
    if (!it_save_jpeg($origRes, $origAbs, BienPhotosManager::ORIGINAL_QUALITY)) {
        if ($origIsNew) imagedestroy($origRes);
        imagedestroy($img);
        throw new RuntimeException('Échec écriture original');
    }
    if ($origIsNew) imagedestroy($origRes);

    // Regénère la variante LBC si elle existait
    if ($lbcRel !== '') {
        $lbcAbs = $root . $lbcRel;
        $lbcRes = it_resize_max_width($img, BienPhotosManager::LBC_MAX_WIDTH);
        $lbcIsNew = ($lbcRes !== $img);
        it_save_jpeg_under_size($lbcRes, $lbcAbs, BienPhotosManager::LBC_MAX_BYTES);
        if ($lbcIsNew) imagedestroy($lbcRes);
    }
    imagedestroy($img);

    // Mesures + nouveau hash
    $info    = it_measure($origAbs);
    $largeur = $info['largeur'] ?: null;
    $hauteur = $info['hauteur'] ?: null;
    $poids   = $info['poids']   ?: null;
    $newHash = md5_file($origAbs) ?: null;

    // MAJ mesures + hash
    $pdo->prepare(
        "UPDATE biens_photos
         SET largeur = ?, hauteur = ?, poids_octets = ?, hash_md5 = ?
         WHERE id = ?"
    )->execute([$largeur, $hauteur, $poids, $newHash, $idPhoto]);

    // Réinitialise l'analyse IA (best-effort : colonnes selon schéma)
    foreach ([
        "UPDATE biens_photos SET analyse_statut='pending', analyse_erreur=NULL WHERE id=?",
        "UPDATE biens_photos SET categorie=NULL, description_ia=NULL WHERE id=?",
        "UPDATE biens_photos SET critique_niveau=NULL, critique_points_forts=NULL, critique_points_faibles=NULL, critique_conseil=NULL WHERE id=?",
    ] as $sql) {
        try { $pdo->prepare($sql)->execute([$idPhoto]); } catch (Throwable) { /* colonne absente */ }
    }

    echo json_encode([
        'ok'     => true,
        'url'    => app_url('/' . $origRel),
        'width'  => $largeur,
        'height' => $hauteur,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
