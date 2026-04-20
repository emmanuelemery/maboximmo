<?php
declare(strict_types=1);

/**
 * image_tools.php — Helpers GD pour compression et redimensionnement photos.
 *
 * Utilisé par BienPhotosManager à l'upload et par tools_photos_recompress.php
 * pour le rattrapage des photos existantes.
 *
 * Dépendance : extension GD (jpeg, png, webp).
 */

/**
 * Charge une image JPG/PNG/WebP, redresse selon l'orientation EXIF (JPEG).
 * Retourne [GdImage, 'jpg'|'png'|'webp'] ou null si format invalide.
 */
function it_load_and_orient(string $path): ?array
{
    $info = @getimagesize($path);
    if (!$info) return null;

    switch ($info[2]) {
        case IMAGETYPE_JPEG:
            $img = @imagecreatefromjpeg($path);
            $ext = 'jpg';
            if ($img && function_exists('exif_read_data')) {
                $exif = @exif_read_data($path);
                if (!empty($exif['Orientation'])) {
                    switch ((int)$exif['Orientation']) {
                        case 3: $img = imagerotate($img, 180, 0); break;
                        case 6: $img = imagerotate($img, -90, 0); break;
                        case 8: $img = imagerotate($img,  90, 0); break;
                    }
                }
            }
            return $img ? [$img, $ext] : null;

        case IMAGETYPE_PNG:
            $img = @imagecreatefrompng($path);
            return $img ? [$img, 'png'] : null;

        case IMAGETYPE_WEBP:
            $img = @imagecreatefromwebp($path);
            return $img ? [$img, 'webp'] : null;
    }
    return null;
}

/**
 * Downscale : si la largeur dépasse $maxW, retourne une nouvelle GdImage
 * redimensionnée en conservant le ratio. Sinon retourne $img tel quel.
 *
 * Appelant responsable de imagedestroy() sur la ressource retournée si
 * elle diffère de l'entrée.
 */
function it_resize_max_width($img, int $maxW)
{
    $srcW = imagesx($img);
    $srcH = imagesy($img);
    if ($srcW <= $maxW) return $img;

    $newH = (int)round($srcH * ($maxW / $srcW));
    $dst = imagecreatetruecolor($maxW, $newH);
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $maxW, $newH, $srcW, $srcH);
    return $dst;
}

/**
 * Sauve l'image en JPEG à la qualité donnée. Retourne true/false.
 */
function it_save_jpeg($img, string $path, int $quality = 85): bool
{
    return imagejpeg($img, $path, $quality);
}

/**
 * Sauve en JPEG en baissant la qualité par paliers jusqu'à passer sous
 * $maxBytes. Retourne la qualité effectivement utilisée, ou 0 si échec.
 *
 * Séquence : 85 → 80 → 75 → 70 → 65 → 60 (plancher).
 */
function it_save_jpeg_under_size($img, string $path, int $maxBytes, int $startQuality = 85): int
{
    $ladder = [$startQuality, 80, 75, 70, 65, 60];
    $ladder = array_values(array_unique(array_filter($ladder, fn($q) => $q > 0 && $q <= 95)));

    foreach ($ladder as $q) {
        if (!imagejpeg($img, $path, $q)) continue;
        $size = filesize($path);
        if ($size !== false && $size <= $maxBytes) {
            return $q;
        }
    }
    // Dernier recours : on garde la version la plus basse générée (q=60)
    $size = @filesize($path);
    return $size !== false ? (int)end($ladder) : 0;
}

/**
 * Mesure rapide d'une image (largeur, hauteur, poids) sans la charger.
 */
function it_measure(string $path): array
{
    $info = @getimagesize($path);
    return [
        'largeur' => $info ? (int)$info[0] : 0,
        'hauteur' => $info ? (int)$info[1] : 0,
        'poids'   => (int)(@filesize($path) ?: 0),
        'mime'    => $info['mime'] ?? null,
    ];
}
