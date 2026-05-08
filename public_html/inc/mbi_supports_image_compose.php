<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * mbi_supports_image_compose.php — Composition finale option B (hybride)
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Phase 2 du pivot affiche IA : prend l'image générée par gpt-image-1
 * (ambiance + accroche, zone gauche réservée vide) et y compose :
 *   1. La photo RÉELLE du bien dans la zone gauche (35% left)
 *   2. Le bandeau MENTIONS LÉGALES en bas (carte pro, garant, RC pro,
 *      DPE/GES, prix, mentions copro, agence) — rendu en GD pixel-parfait
 *      (l'IA peut altérer du texte légal, donc on prend la main).
 *
 * Utilise GD natif (XAMPP/Hostinger compatible). Imagick optionnel.
 *
 * API publique :
 *   mbi_supports_image_composer(
 *     string $imageIaPath,    Chemin absolu de l'image IA (PNG)
 *     ?string $photoBienPath, Chemin de la photo principale du bien (PNG/JPG, peut être null)
 *     array $context          Bien + agence + critic + textes (mentions, prix, etc.)
 *   ): array
 *
 *   Renvoie : { ok, fichier_path, taille_octets, erreur }
 * ═══════════════════════════════════════════════════════════════════════
 */

if (!function_exists('mbi_supports_image_composer')) {

    function mbi_supports_image_composer(string $imageIaPath, ?string $photoBienPath, array $context): array
    {
        if (!is_file($imageIaPath) || !is_readable($imageIaPath)) {
            return ['ok'=>false,'fichier_path'=>'','taille_octets'=>0,'erreur'=>'image_ia_introuvable'];
        }
        if (!extension_loaded('gd')) {
            return ['ok'=>false,'fichier_path'=>'','taille_octets'=>0,'erreur'=>'gd_extension_manquante'];
        }

        // Charge l'image IA (PNG)
        $base = @imagecreatefrompng($imageIaPath);
        if (!$base) {
            return ['ok'=>false,'fichier_path'=>'','taille_octets'=>0,'erreur'=>'imagecreatefrompng_fail'];
        }
        $W = imagesx($base);
        $H = imagesy($base);

        imagealphablending($base, true);
        imagesavealpha($base, true);

        // ─── 1. Insertion de la photo réelle dans la zone gauche (35% left) ───
        if ($photoBienPath !== null && is_file($photoBienPath) && is_readable($photoBienPath)) {
            $photo = mbi_supports_image_load_any($photoBienPath);
            if ($photo) {
                // Zone cible : 35% gauche, marges 5% sur chaque côté + 8% top + 12% bottom (pour bandeau)
                $zoneX = (int) round($W * 0.05);
                $zoneY = (int) round($H * 0.08);
                $zoneW = (int) round($W * 0.30);   // ~30% width (35% - 2×5% margin)
                $zoneH = (int) round($H * 0.78);   // ~78% height (laisse 12% pour bandeau bas + 8% top)

                // Adapte la photo à la zone en gardant le ratio (cover)
                $pw = imagesx($photo);
                $ph = imagesy($photo);
                $scale = max($zoneW / $pw, $zoneH / $ph);
                $sw = (int) round($pw * $scale);
                $sh = (int) round($ph * $scale);
                $sx = (int) round(($sw - $zoneW) / 2);
                $sy = (int) round(($sh - $zoneH) / 2);

                // Crée une image redimensionnée
                $resized = imagecreatetruecolor($sw, $sh);
                imagecopyresampled($resized, $photo, 0, 0, 0, 0, $sw, $sh, $pw, $ph);

                // Copie la zone centrée dans l'image principale
                imagecopy($base, $resized, $zoneX, $zoneY, $sx, $sy, $zoneW, $zoneH);

                imagedestroy($photo);
                imagedestroy($resized);
            }
        }

        // ─── 2. Bandeau mentions légales en bas (12% de la hauteur) ───
        $bandeauH = (int) round($H * 0.12);
        $bandeauY = $H - $bandeauH;

        // Fond du bandeau : navy semi-opaque pour contraste avec texte blanc
        $bandeauColor = imagecolorallocatealpha($base, 0x24, 0x3B, 0x5C, 0); // #243B5C plein
        imagefilledrectangle($base, 0, $bandeauY, $W, $H, $bandeauColor);

        // Trait or fin en haut du bandeau
        $orColor = imagecolorallocate($base, 0xD4, 0xA0, 0x47);
        imagefilledrectangle($base, 0, $bandeauY, $W, $bandeauY + 4, $orColor);

        // Texte du bandeau (mentions légales obligatoires)
        $textBlanc  = imagecolorallocate($base, 255, 255, 255);
        $textBeige  = imagecolorallocate($base, 220, 220, 230);

        // Trouver une police TTF (XAMPP n'en a pas par défaut, on utilise imagestring fallback)
        $fontPath = mbi_supports_image_find_font();

        $padX     = (int) round($W * 0.03);
        $lineY1   = $bandeauY + (int) round($bandeauH * 0.20);
        $lineY2   = $bandeauY + (int) round($bandeauH * 0.50);
        $lineY3   = $bandeauY + (int) round($bandeauH * 0.78);

        $agence    = $context['agence'] ?? [];
        $bien      = $context['bien']   ?? [];
        $negoNom   = trim((string)(($context['negociateur']['prenom'] ?? '') . ' ' . ($context['negociateur']['nom'] ?? '')));
        $negoTel   = (string)($context['negociateur']['telephone_pro'] ?? '');

        // Ligne 1 : Agence + ville
        $l1 = mb_strtoupper(trim((string)($agence['nom_agence'] ?? $agence['nom'] ?? 'Agence Immobilier')));
        if (!empty($agence['ville'])) $l1 .= ' · ' . $agence['ville'];
        if (!empty($agence['telephone'])) $l1 .= ' · ' . $agence['telephone'];

        // Ligne 2 : carte pro + garant + RC
        // Refactor 2026-05-08 : RCP/GF par activité (T pour vente, G pour location).
        $typeTrIc = strtolower((string)($bien['type_transaction'] ?? ''));
        $activiteCibleIc = match (true) {
            str_contains($typeTrIc, 'vente'),
            str_contains($typeTrIc, 'cession') => 'transaction',
            str_contains($typeTrIc, 'location') => 'gestion',
            default => null,
        };
        $rcpActIc = ($activiteCibleIc && !empty($agence['activites'][$activiteCibleIc]['rc_pro_assureur']))
            ? (string)$agence['activites'][$activiteCibleIc]['rc_pro_assureur'] : '';
        $gfActIc  = ($activiteCibleIc && !empty($agence['activites'][$activiteCibleIc]['garant_nom']))
            ? (string)$agence['activites'][$activiteCibleIc]['garant_nom'] : '';

        $parts2 = [];
        if (!empty($agence['carte_pro_numero'])) $parts2[] = 'Carte pro ' . $agence['carte_pro_numero'];
        $garantDisp = $gfActIc ?: (string)($agence['garant_financier'] ?? '');
        $rcProDisp  = $rcpActIc ?: (string)($agence['rc_pro'] ?? '');
        if ($garantDisp !== '') $parts2[] = 'Garant ' . $garantDisp;
        if ($rcProDisp  !== '') $parts2[] = 'RC Pro ' . $rcProDisp;
        $l2 = implode('  ·  ', $parts2);

        // Ligne 3 : DPE/GES + Prix (si dispo) + négociateur
        $parts3 = [];
        $prix = $bien['prix_vente_estime'] ?? $bien['prix_vente'] ?? $bien['prix'] ?? 0;
        if ($prix > 0) {
            $parts3[] = 'PRIX ' . number_format((float)$prix, 0, ',', ' ') . ' €';
        }
        $dpe = strtoupper(trim((string)($bien['dpe_classe'] ?? '')));
        $ges = strtoupper(trim((string)($bien['ges_classe'] ?? '')));
        if ($dpe || $ges) $parts3[] = 'DPE ' . ($dpe ?: '—') . ' / GES ' . ($ges ?: '—');
        if ($negoNom !== '') $parts3[] = 'Contact : ' . $negoNom . ($negoTel ? ' · ' . $negoTel : '');
        $l3 = implode('  ·  ', $parts3);

        // Rendu texte
        if ($fontPath) {
            // Avec TTF (rendu propre)
            imagettftext($base, max(14, (int)round($bandeauH * 0.18)), 0, $padX, $lineY1, $textBlanc, $fontPath, $l1);
            imagettftext($base, max(11, (int)round($bandeauH * 0.13)), 0, $padX, $lineY2, $textBeige, $fontPath, $l2);
            imagettftext($base, max(11, (int)round($bandeauH * 0.13)), 0, $padX, $lineY3, $textBeige, $fontPath, $l3);
        } else {
            // Fallback bitmap (basique)
            imagestring($base, 5, $padX, $lineY1 - 12, $l1, $textBlanc);
            imagestring($base, 4, $padX, $lineY2 - 10, $l2, $textBeige);
            imagestring($base, 4, $padX, $lineY3 - 10, $l3, $textBeige);
        }

        // ─── 3. Sauvegarde du PNG composé ───
        $outDir = dirname($imageIaPath);
        $outName = preg_replace('/(\.png)$/i', '_compose.png', basename($imageIaPath));
        $outPath = $outDir . '/' . $outName;

        $ok = @imagepng($base, $outPath);
        imagedestroy($base);

        if (!$ok || !is_file($outPath)) {
            return ['ok'=>false,'fichier_path'=>'','taille_octets'=>0,'erreur'=>'imagepng_fail'];
        }

        return [
            'ok'            => true,
            'fichier_path'  => $outPath,
            'taille_octets' => filesize($outPath),
            'erreur'        => null,
        ];
    }
}

if (!function_exists('mbi_supports_image_load_any')) {
    /**
     * Charge une image PNG / JPEG / WebP / GIF en GD.
     */
    function mbi_supports_image_load_any(string $path)
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'png'        => @imagecreatefrompng($path),
            'jpg','jpeg' => @imagecreatefromjpeg($path),
            'webp'       => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            'gif'        => @imagecreatefromgif($path),
            default      => false,
        };
    }
}

if (!function_exists('mbi_supports_image_find_font')) {
    /**
     * Cherche une police TTF pour imagettftext. Retourne le chemin ou null.
     */
    function mbi_supports_image_find_font(): ?string
    {
        $candidates = [
            // Polices TCPDF déjà présentes dans le projet
            __DIR__ . '/../tcpdf/fonts/dejavusans.ttf',
            __DIR__ . '/../tcpdf/fonts/dejavusansb.ttf',
            // Windows
            'C:/Windows/Fonts/arial.ttf',
            'C:/Windows/Fonts/calibri.ttf',
            // Linux
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        ];
        foreach ($candidates as $p) {
            if (is_file($p) && is_readable($p)) return $p;
        }
        return null;
    }
}
