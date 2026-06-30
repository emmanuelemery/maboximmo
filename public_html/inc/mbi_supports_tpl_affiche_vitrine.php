<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * mbi_supports_tpl_affiche_vitrine.php — Routeur multi-layouts A3 H + helpers
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Format imposé : A3 LANDSCAPE (420 × 297 mm).
 * Le builder principal délègue à un layout choisi par scoring conditionnel
 * + tirage pondéré reproductible (seed = id_bien + version).
 *
 * Architecture :
 *   - mbi_supports_tpl_affiche_vitrine_build()  : wrapper qui appelle le router
 *   - mbi_supports_layouts_router.php           : choix + render layout
 *   - inc/supports_layouts/<code>.php           : 1 fichier par layout
 *
 * Style global :
 *   - Toutes les cartes/badges/étiquettes ont des coins arrondis + ombres portées
 *   - Helper unique : mbi_supports_tpl_card_round_shadow()
 *   - Charte MaBoxImmo : navy #243B5C + or #D4A047
 * ═══════════════════════════════════════════════════════════════════════
 */

if (!function_exists('mbi_supports_tpl_affiche_vitrine_build')) {

    function mbi_supports_tpl_affiche_vitrine_build(array $ctx): TCPDF
    {
        if (function_exists('mb_internal_encoding')) {
            @mb_internal_encoding('UTF-8');
        }
        require_once __DIR__ . '/mbi_supports_layouts_router.php';

        $code = mbi_supports_layout_choisir($ctx);
        return mbi_supports_layout_render($code, $ctx);
    }
}

// ═════════════════════════════════════════════════════════════════════════
// HELPERS COMMUNS aux layouts (chargés via require_once en amont)
// ═════════════════════════════════════════════════════════════════════════

if (!function_exists('mbi_supports_tpl_card_round_shadow')) {
    /**
     * Carte rectangulaire arrondie avec ombre portée — helper central V3.
     *
     * @param TCPDF  $pdf
     * @param float  $x       coin haut-gauche
     * @param float  $y
     * @param float  $w       largeur
     * @param float  $h       hauteur
     * @param float  $r       rayon des coins (mm)
     * @param array  $rgbFill couleur de fond [r,g,b]
     * @param float  $alpha   opacité du fond (0..1)
     * @param bool   $shadow  true pour ombre portée
     * @param array|null $rgbBorderL  trait gauche (signature or par exemple), [r,g,b] ou null
     */
    function mbi_supports_tpl_card_round_shadow(
        TCPDF $pdf,
        float $x, float $y, float $w, float $h, float $r,
        array $rgbFill, float $alpha = 1.0,
        bool $shadow = true,
        ?array $rgbBorderL = null
    ): void {
        // Ombre portée : rect arrondi noir alpha décalé
        if ($shadow) {
            $pdf->SetAlpha(0.22);
            $pdf->SetFillColor(15, 23, 42);
            $pdf->RoundedRect($x + 1.5, $y + 2.5, $w, $h, $r, '1111', 'F');
            $pdf->SetAlpha(1.0);
        }
        // Carte
        $pdf->SetAlpha($alpha);
        $pdf->SetFillColor($rgbFill[0], $rgbFill[1], $rgbFill[2]);
        $pdf->RoundedRect($x, $y, $w, $h, $r, '1111', 'F');
        $pdf->SetAlpha(1.0);
        // Trait signature gauche (or ou couleur d'accent) — coins arrondis suivent la carte
        if ($rgbBorderL !== null) {
            $pdf->SetFillColor($rgbBorderL[0], $rgbBorderL[1], $rgbBorderL[2]);
            $pdf->RoundedRect($x, $y, 2, $h, $r, '1001', 'F');
        }
    }
}

if (!function_exists('mbi_supports_couper_2_lignes')) {
    /**
     * Coupe un texte en 2 lignes équilibrées par nombre de caractères.
     * Trouve le mot dont la position est la plus proche du milieu de la chaîne
     * et insère un saut de ligne juste avant.
     * Retourne le texte avec un \n inséré (ou le texte original si trop court).
     */
    function mbi_supports_couper_2_lignes(string $s): string
    {
        $s = trim($s);
        $mots = preg_split('/\s+/u', $s) ?: [];
        if (count($mots) < 3) return $s;

        $totalLen = mb_strlen($s, 'UTF-8');
        $cur = 0;
        $bestIdx = 1;
        $bestDiff = $totalLen;
        for ($i = 1; $i < count($mots); $i++) {
            $cur += mb_strlen($mots[$i - 1], 'UTF-8') + 1;
            $diff = abs($cur - ($totalLen - $cur));
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $bestIdx = $i;
            }
        }
        $l1 = implode(' ', array_slice($mots, 0, $bestIdx));
        $l2 = implode(' ', array_slice($mots, $bestIdx));
        return $l1 . "\n" . $l2;
    }
}

if (!function_exists('mbi_supports_filtre_photos_reelles')) {
    /**
     * Exclut du pool les photos placeholder système (bien_default.jpg, etc.)
     * qui sont auto-attachées à la création d'un bien et n'ont aucun intérêt
     * marketing. Si après filtrage il ne reste rien, on retourne le pool
     * d'origine (mieux vaut afficher le placeholder que rien).
     */
    function mbi_supports_filtre_photos_reelles(array $photos): array
    {
        $patterns = ['bien_default', 'placeholder', 'no_photo', 'default.jpg'];
        $filtre = [];
        foreach ($photos as $p) {
            $nom  = strtolower((string)($p['nom_original'] ?? ''));
            $url  = strtolower((string)($p['url_photo']    ?? ''));
            $skip = false;
            foreach ($patterns as $pat) {
                if (str_contains($nom, $pat) || str_contains($url, $pat)) {
                    $skip = true;
                    break;
                }
            }
            if (!$skip) $filtre[] = $p;
        }
        return $filtre ?: $photos;
    }
}

if (!function_exists('mbi_supports_tpl_titre_headline')) {
    /**
     * Construit un titre headline auto pour les affiches A3 H.
     * Format : TYPE · NB_PIÈCES · VILLE  (ex: "APPARTEMENT · 3 PIÈCES · LYON 7")
     * Tombe sur la designation si pas assez de données.
     */
    function mbi_supports_tpl_titre_headline(array $bien): string
    {
        $parts = [];
        $typeBien = trim((string)($bien['type_bien_libelle'] ?? $bien['type'] ?? ''));
        if ($typeBien !== '') $parts[] = mb_strtoupper($typeBien, 'UTF-8');

        $nbPcs = $bien['nb_pieces'] ?? $bien['nombre_pieces'] ?? null;
        if ($nbPcs) {
            $n = (int)$nbPcs;
            $parts[] = $n . ' PIÈCE' . ($n > 1 ? 'S' : '');
        }

        $ville = trim((string)($bien['ville'] ?? ''));
        if ($ville !== '') $parts[] = mb_strtoupper($ville, 'UTF-8');

        if (count($parts) >= 2) {
            return implode('  ·  ', $parts);
        }
        // Fallback designation
        $des = trim((string)($bien['designation'] ?? ''));
        if ($des !== '' && !str_starts_with($des, 'Bien créé')) {
            return mb_strtoupper(mb_substr($des, 0, 60, 'UTF-8'), 'UTF-8');
        }
        return mb_strtoupper('Bien à découvrir', 'UTF-8');
    }
}

if (!function_exists('mbi_supports_tpl_text_shadow')) {
    /**
     * Texte avec ombre portée + couleur — pour titres XL (accroche, prix géant, etc).
     * Effet : couche grise/noire décalée alpha 0.4, puis texte en couleur par-dessus.
     *
     * @param TCPDF $pdf
     * @param float $x, $y    coin haut-gauche du bloc
     * @param float $w, $h    dimensions de la cellule (MultiCell si multiline)
     * @param string $text
     * @param array $rgb       couleur principale du texte [r,g,b]
     * @param string $font     ex: 'dejavusans'
     * @param string $style    '', 'B', 'I', 'BI'
     * @param float $size      taille en pt
     * @param string $align    'L'|'C'|'R'
     * @param bool $multiline  true → MultiCell
     * @param float $shadowDx  décalage X de l'ombre (mm)
     * @param float $shadowDy  décalage Y de l'ombre (mm)
     * @param array $shadowRgb couleur ombre (default noir)
     * @param float $shadowAlpha alpha ombre (default 0.40)
     */
    function mbi_supports_tpl_text_shadow(
        TCPDF $pdf,
        float $x, float $y, float $w, float $h,
        string $text,
        array $rgb,
        string $font = 'dejavusans',
        string $style = 'B',
        float $size = 24,
        string $align = 'L',
        bool $multiline = false,
        float $shadowDx = 1.6,
        float $shadowDy = 2.0,
        ?array $shadowRgb = null,
        float $shadowAlpha = 0.40
    ): void {
        $shadowRgb = $shadowRgb ?? [10, 18, 32];

        // Couche ombre (alpha)
        $pdf->SetAlpha($shadowAlpha);
        $pdf->SetFont($font, $style, $size);
        $pdf->SetTextColor($shadowRgb[0], $shadowRgb[1], $shadowRgb[2]);
        $pdf->SetXY($x + $shadowDx, $y + $shadowDy);
        if ($multiline) {
            $pdf->MultiCell($w, $h, $text, 0, $align);
        } else {
            $pdf->Cell($w, $h, $text, 0, 0, $align);
        }
        $pdf->SetAlpha(1.0);

        // Texte en couleur par-dessus
        $pdf->SetFont($font, $style, $size);
        $pdf->SetTextColor($rgb[0], $rgb[1], $rgb[2]);
        $pdf->SetXY($x, $y);
        if ($multiline) {
            $pdf->MultiCell($w, $h, $text, 0, $align);
        } else {
            $pdf->Cell($w, $h, $text, 0, 0, $align);
        }
    }
}

if (!function_exists('mbi_supports_tpl_image_round')) {
    /**
     * Affiche une image avec coins arrondis (via clipping path) + ombre portée optionnelle.
     * Si l'image n'existe pas, dessine un placeholder grisé arrondi.
     *
     * RECADRAGE « COVER » (2026-06-26) : l'image REMPLIT toute la boîte en
     * conservant son ratio (débordement rogné par le clipping arrondi). Une
     * photo verticale dans une vignette paysage est donc centrée + recadrée,
     * au lieu d'être réduite façon « contain » (qui laissait des bandes
     * transparentes laissant voir la photo héro derrière la vignette).
     *
     * Note TCPDF : on utilise SetClippingPath() pour clipper rounded.
     */
    function mbi_supports_tpl_image_round(
        TCPDF $pdf,
        ?string $imgPath,
        float $x, float $y, float $w, float $h, float $r = 4.0,
        bool $shadow = true,
        ?array $borderRgb = null,
        float $borderWidth = 0.4
    ): void {
        // Ombre portée
        if ($shadow) {
            $pdf->SetAlpha(0.25);
            $pdf->SetFillColor(15, 23, 42);
            $pdf->RoundedRect($x + 1.5, $y + 2.5, $w, $h, $r, '1111', 'F');
            $pdf->SetAlpha(1.0);
        }

        // Pas d'image → fond gris arrondi
        if ($imgPath === null) {
            $pdf->SetFillColor(220, 224, 232);
            $pdf->RoundedRect($x, $y, $w, $h, $r, '1111', 'F');
        } else {
            // Clipping rounded + image recadrée « cover »
            try {
                // Dimensions de tirage « cover » : on agrandit l'image jusqu'à
                // recouvrir toute la boîte, le surplus étant rogné par le clip.
                $dx = $x; $dy = $y; $dW = $w; $dH = $h; $fit = 'CM';
                $info = @getimagesize($imgPath);
                if (is_array($info) && (int)$info[0] > 0 && (int)$info[1] > 0) {
                    $iw = (int)$info[0]; $ih = (int)$info[1];
                    $boxRatio = $w / $h;
                    $imgRatio = $iw / $ih;
                    if ($imgRatio > $boxRatio) { // image plus large → cale sur la hauteur
                        $dH = $h; $dW = $h * $imgRatio;
                    } else {                     // image plus haute → cale sur la largeur
                        $dW = $w; $dH = $w / $imgRatio;
                    }
                    $dx = $x - ($dW - $w) / 2;
                    $dy = $y - ($dH - $h) / 2;
                    $fit = ''; // ratio déjà exact → pas de re-fit TCPDF
                }
                $pdf->StartTransform();
                $pdf->RoundedRect($x, $y, $w, $h, $r, '1111', 'CNZ');
                $pdf->Image($imgPath, $dx, $dy, $dW, $dH, '', '', '', false, 250, '', false, false, 0, $fit, false, false);
                $pdf->StopTransform();
            } catch (Throwable) {
                $pdf->SetFillColor(220, 224, 232);
                $pdf->RoundedRect($x, $y, $w, $h, $r, '1111', 'F');
            }
        }

        // Bordure arrondie facultative
        if ($borderRgb !== null) {
            $pdf->SetDrawColor($borderRgb[0], $borderRgb[1], $borderRgb[2]);
            $pdf->SetLineWidth($borderWidth);
            $pdf->RoundedRect($x, $y, $w, $h, $r, '1111', 'D');
            $pdf->SetLineWidth(0.2);
        }
    }
}

if (!function_exists('mbi_supports_tpl_angle_palette')) {
    function mbi_supports_tpl_angle_palette(string $angle, array $cSDefault): array
    {
        $palettes = [
            'famille'        => ['rgb' => [42, 122, 95],  'libelle' => 'Pour la famille'],
            'investisseur'   => ['rgb' => [66, 96, 140],  'libelle' => 'Investisseur'],
            'premium'        => ['rgb' => [165, 124, 50], 'libelle' => 'Premium'],
            'premier_achat'  => ['rgb' => [192, 102, 70], 'libelle' => 'Primo-accédant'],
        ];
        if (isset($palettes[$angle])) return $palettes[$angle];
        return ['rgb' => $cSDefault, 'libelle' => ''];
    }
}

if (!function_exists('mbi_supports_tpl_v2_placeholder')) {
    function mbi_supports_tpl_v2_placeholder(TCPDF $pdf, float $x, float $y, float $w, float $h, array $color): void
    {
        // Placeholder arrondi pour cohérence avec le reste
        $pdf->SetFillColor(225, 230, 240);
        $pdf->RoundedRect($x, $y, $w, $h, 4, '1111', 'F');
        $pdf->SetDrawColor(200, 208, 220);
        $pdf->SetLineWidth(0.4);
        // Hachures à l'ancienne (visuelles)
        for ($i = -50; $i < $w + $h; $i += 14) {
            $pdf->Line($x + $i, $y, $x + $i + $h, $y + $h);
        }
        $pdf->SetFont('dejavusans', 'B', 18);
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
        $pdf->SetXY($x, $y + ($h / 2) - 5);
        $pdf->Cell($w, 10, 'PHOTO À AJOUTER', 0, 0, 'C');
    }
}

if (!function_exists('mbi_supports_tpl_carac_mini')) {
    /**
     * Mini-bloc caracteristique avec coins arrondis + ombre légère.
     * @param array|null $bgRgb fond du mini bloc (null = pas de fond, juste texte)
     * @param array|null $textRgb couleur du label (null = gris standard)
     * @param array|null $valRgb couleur de la valeur (null = navy/cT)
     */
    function mbi_supports_tpl_carac_mini(
        TCPDF $pdf, float $x, float $y, float $w, float $h,
        string $label, string $value,
        array $cP, array $cT,
        ?array $bgRgb = null, ?array $textRgb = null, ?array $valRgb = null,
        bool $shadow = false,
        float $labelSize = 11.0,
        float $valueSize = 44.0,
        float $rad = 4.0,
        bool $adaptValue = true
    ): void {
        if ($bgRgb !== null) {
            mbi_supports_tpl_card_round_shadow($pdf, $x, $y, $w, $h, $rad, $bgRgb, 1.0, $shadow);
        }
        $padInt = 6;

        // Label en haut-gauche
        $pdf->SetFont('dejavusans', 'B', $labelSize);
        $pdf->SetTextColor(...($textRgb ?? [120, 126, 140]));
        $pdf->SetXY($x + $padInt, $y + 3);
        $pdf->Cell($w - $padInt * 2, 5.5, mb_strtoupper($label, 'UTF-8'), 0, 0, 'L');

        // Valeur : taille adaptative selon longueur, OU fixe si $adaptValue = false
        // (permet d'uniformiser la taille entre plusieurs cartes : surface = pièces = étage)
        $valLen = mb_strlen($value, 'UTF-8');
        $valSize = !$adaptValue ? $valueSize
                 : ($valLen <= 2 ? $valueSize
                 : ($valLen <= 3 ? $valueSize * 0.78
                 : ($valLen <= 4 ? $valueSize * 0.62
                 : $valueSize * 0.50)));
        $pdf->SetFont('dejavusans', 'B', $valSize);
        $pdf->SetTextColor(...($valRgb ?? $cP));
        $pdf->SetXY($x + $padInt, $y);
        $pdf->Cell($w - $padInt * 2, $h, $value, 0, 0, 'R');
    }
}

if (!function_exists('mbi_supports_dpe_classe_depuis_valeur')) {
    /**
     * Déduit la classe DPE (kWh EP/m²/an) selon le barème ADEME logement.
     * @return string 'A'..'G' ou '' si valeur invalide
     */
    function mbi_supports_dpe_classe_depuis_valeur(float $v): string
    {
        if ($v <= 0) return '';
        if ($v <=  70) return 'A';
        if ($v <= 110) return 'B';
        if ($v <= 180) return 'C';
        if ($v <= 250) return 'D';
        if ($v <= 330) return 'E';
        if ($v <= 420) return 'F';
        return 'G';
    }
}

if (!function_exists('mbi_supports_ges_classe_depuis_valeur')) {
    /**
     * Déduit la classe GES (kg CO2/m²/an) selon le barème ADEME.
     */
    function mbi_supports_ges_classe_depuis_valeur(float $v): string
    {
        if ($v <= 0)  return '';
        if ($v <=   6) return 'A';
        if ($v <=  11) return 'B';
        if ($v <=  30) return 'C';
        if ($v <=  50) return 'D';
        if ($v <=  70) return 'E';
        if ($v <= 100) return 'F';
        return 'G';
    }
}

if (!function_exists('mbi_supports_classe_normalise')) {
    /**
     * Renvoie la classe normalisée (A..G) ou '' si invalide.
     * "VIERGE", "N/A", "—", null, etc. → ''
     */
    function mbi_supports_classe_normalise(?string $c): string
    {
        $u = strtoupper(trim((string)$c));
        return in_array($u, ['A','B','C','D','E','F','G'], true) ? $u : '';
    }
}

if (!function_exists('mbi_supports_tpl_dpe_ges_pastille')) {
    /**
     * Affiche UNE pastille DPE ou GES : carré rounded 16x16mm couleur ADEME
     * avec la lettre du bien en gros (28pt bold blanc) + bordure or.
     * Si classe vide → G (pire scénario par défaut).
     * Si une valeur est fournie ET la classe est saisie : affiche "180 kWh/m²/an"
     * à droite de la pastille.
     */
    function mbi_supports_tpl_dpe_ges_pastille(
        TCPDF $pdf, float $x, float $y,
        string $type, string $classe,
        ?float $valeur = null,
        string $unite = '',
        ?array $textRgbHeader = null,
        ?array $valRgb = null
    ): void {
        $couleurs = [
            'A' => [0, 159, 58],   'B' => [80, 183, 62],  'C' => [196, 216, 61],
            'D' => [255, 240, 53], 'E' => [245, 181, 61], 'F' => [232, 90, 58], 'G' => [210, 44, 46],
        ];

        // Normalise : "VIERGE", "N/A", null → vide. Puis fallback G si vraiment rien.
        $classeNorm = mbi_supports_classe_normalise($classe);
        $classeVide = ($classeNorm === '');
        $classActive = $classeVide ? 'G' : $classeNorm;
        $cRGB = $couleurs[$classActive];

        // Label "DPE" / "GES" à gauche (collé à la pastille)
        $labW = 10;
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->SetTextColor(...($textRgbHeader ?? [200, 206, 220]));
        $pdf->SetXY($x, $y + 5);
        $pdf->Cell($labW, 6, $type, 0, 0, 'L');

        // Pastille avec la lettre
        $pw = 16; $ph = 16;
        $px = $x + $labW;
        mbi_supports_tpl_card_round_shadow($pdf, $px, $y, $pw, $ph, 3.0, $cRGB, 1.0, true);

        // Bordure or pour mise en valeur
        $pdf->SetDrawColor(212, 160, 71);
        $pdf->SetLineWidth(0.8);
        $pdf->RoundedRect($px, $y, $pw, $ph, 3.0, '1111', 'D');
        $pdf->SetLineWidth(0.2);

        // Lettre en gros
        $pdf->SetFont('dejavusans', 'B', 28);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY($px, $y);
        $pdf->Cell($pw, $ph, $classActive, 0, 0, 'C');

        // Valeur + unité à droite (seulement si classe vraiment saisie + valeur > 0)
        $rx = $px + $pw + 5;
        if (!$classeVide && $valeur !== null && $valeur > 0) {
            $pdf->SetFont('dejavusans', 'B', 16);
            $pdf->SetTextColor(...($valRgb ?? [255, 255, 255]));
            $pdf->SetXY($rx, $y + 1);
            $pdf->Cell(36, 8, number_format($valeur, 0, ',', ' '), 0, 0, 'L');

            $pdf->SetFont('dejavusans', 'I', 7.5);
            $pdf->SetTextColor(...($textRgbHeader ?? [180, 186, 200]));
            $pdf->SetXY($rx, $y + 10);
            $pdf->Cell(40, 4, $unite, 0, 0, 'L');
        } elseif ($classeVide) {
            $pdf->SetFont('dejavusans', 'I', 8);
            $pdf->SetTextColor(...($textRgbHeader ?? [180, 186, 200]));
            $pdf->SetXY($rx, $y + 5);
            $pdf->Cell(40, 5, 'non renseigné', 0, 0, 'L');
        }
    }
}

if (!function_exists('mbi_supports_tpl_dpe_ges')) {
    /**
     * Affiche les 2 pastilles DPE + GES empilées (compact, sans barre 7 segments).
     * Hauteur totale : 16 (DPE) + 4 (gap) + 16 (GES) = 36mm
     */
    function mbi_supports_tpl_dpe_ges(
        TCPDF $pdf, float $x, float $y, float $w, string $dpe, string $ges,
        ?array $textRgbHeader = null,
        ?float $dpeValeur = null,
        ?float $gesValeur = null
    ): void {
        // Si la classe est invalide ("VIERGE" etc.) mais qu'on a une valeur,
        // on déduit la classe depuis le barème ADEME officiel.
        $dpeNorm = mbi_supports_classe_normalise($dpe);
        $gesNorm = mbi_supports_classe_normalise($ges);
        if ($dpeNorm === '' && $dpeValeur !== null && $dpeValeur > 0) {
            $dpeNorm = mbi_supports_dpe_classe_depuis_valeur($dpeValeur);
        }
        if ($gesNorm === '' && $gesValeur !== null && $gesValeur > 0) {
            $gesNorm = mbi_supports_ges_classe_depuis_valeur($gesValeur);
        }

        $rowH = 20;
        mbi_supports_tpl_dpe_ges_pastille($pdf, $x, $y,          'DPE', $dpeNorm, $dpeValeur, 'kWh/m²/an',    $textRgbHeader);
        mbi_supports_tpl_dpe_ges_pastille($pdf, $x, $y + $rowH,  'GES', $gesNorm, $gesValeur, 'kg CO₂/m²/an', $textRgbHeader);
    }
}

if (!function_exists('mbi_supports_tpl_pied_landscape')) {
    /**
     * Pied navy fin pour A3 landscape (18 mm) avec coin top arrondi.
     */
    function mbi_supports_tpl_pied_landscape(TCPDF $pdf, array $ctx, array $cP, array $cS, float $pageW, float $pageH, float $piedH): void
    {
        // Pied UNIFIE (tous les layouts) : bleu petrole, agence a gauche /
        // contact commercial a droite, chaque bloc centre verticalement, ecriture grosse.
        $agence = $ctx['agence']      ?? [];
        $bien   = $ctx['bien']        ?? [];
        $nego   = $ctx['negociateur'] ?? [];
        $piedY  = $pageH - $piedH;
        $cPetrole = [38, 96, 110];

        $pdf->SetFillColor($cPetrole[0], $cPetrole[1], $cPetrole[2]);
        $pdf->Rect(0, $piedY, $pageW, $piedH, 'F');
        $pdf->SetFillColor($cS[0], $cS[1], $cS[2]);
        $pdf->Rect(0, $piedY, $pageW, 1.2, 'F');

        // Donnees legales (carte pro / garant / RC pro selon l'activite)
        $typeTr = strtolower((string)($bien['type_transaction'] ?? ''));
        $activiteCible = match (true) {
            str_contains($typeTr, 'vente'),
            str_contains($typeTr, 'cession') => 'transaction',
            str_contains($typeTr, 'location') => 'gestion',
            default => null,
        };
        $rcpAct = ($activiteCible && !empty($agence['activites'][$activiteCible]['rc_pro_assureur']))
            ? (string)$agence['activites'][$activiteCible]['rc_pro_assureur'] : '';
        $gfAct  = ($activiteCible && !empty($agence['activites'][$activiteCible]['garant_nom']))
            ? (string)$agence['activites'][$activiteCible]['garant_nom'] : '';
        $cartePro = trim((string)($agence['carte_pro_numero'] ?? $agence['carte_pro'] ?? ''));
        $garant   = trim($gfAct  ?: (string)($agence['garant_financier'] ?? $agence['garantie_financiere'] ?? ''));
        $rcPro    = trim($rcpAct ?: (string)($agence['rc_pro']           ?? $agence['assurance_rc_pro'] ?? ''));

        // ===== BLOC GAUCHE : agence (nom + coords + mentions), centre verticalement =====
        $nomAg = (string)($agence['nom_agence'] ?? $agence['nom'] ?? 'Agence');
        $coord = trim(
            (string)($agence['adresse'] ?? '') . ' · ' .
            trim((string)($agence['code_postal'] ?? '') . ' ' . ($agence['ville'] ?? '')) . ' · ' .
            ($agence['telephone'] ?? '') . ' · ' . ($agence['email'] ?? ''),
            ' ·'
        );
        $infos = array_filter([
            $cartePro !== '' ? 'Carte pro ' . $cartePro : '',
            $garant   !== '' ? 'Garant ' . $garant : '',
            $rcPro    !== '' ? 'RC Pro ' . $rcPro : '',
        ]);
        $infosTxt = implode('  ·  ', $infos);

        $lx = 20;
        $lw = $pageW / 2 - 30;
        $nbL = 1 + ($coord !== '' ? 1 : 0) + ($infosTxt !== '' ? 1 : 0);
        $lBlockH = ($nbL === 3) ? 16.0 : ($nbL === 2 ? 11.0 : 6.0);
        $ly = $piedY + ($piedH - $lBlockH) / 2;

        $pdf->SetFont('dejavusans', 'B', 16);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY($lx, $ly);
        $pdf->Cell($lw, 6, $nomAg, 0, 0, 'L');
        $ly += 6;
        if ($coord !== '') {
            $pdf->SetFont('dejavusans', '', 9.5);
            $pdf->SetTextColor(225, 228, 234);
            $pdf->SetXY($lx, $ly);
            $pdf->Cell($lw, 5, $coord, 0, 0, 'L');
            $ly += 5.5;
        }
        if ($infosTxt !== '') {
            $pdf->SetFont('dejavusans', '', 8);
            $pdf->SetTextColor(188, 194, 206);
            $pdf->SetXY($lx, $ly);
            $pdf->Cell($lw, 4, $infosTxt, 0, 0, 'L');
        }

        // ===== BLOC DROIT : contact commercial, centre verticalement, aligne a droite =====
        if ($nego) {
            $negoNom = trim(($nego['prenom'] ?? '') . ' ' . ($nego['nom'] ?? ''));
            $tel     = trim((string)($nego['telephone_pro'] ?? ''));
            $mail    = trim((string)($nego['email'] ?? ''));
            $contact = trim(implode('   ·   ', array_filter([$tel, $mail])));

            $rx = $pageW / 2;
            $rw = $pageW / 2 - 20;
            $nbR = ($negoNom !== '' ? 1 : 0) + ($contact !== '' ? 1 : 0);
            $rBlockH = $nbR === 2 ? 12.0 : 6.0;
            $ry = $piedY + ($piedH - $rBlockH) / 2;

            if ($negoNom !== '') {
                $pdf->SetFont('dejavusans', 'B', 14);
                $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
                $pdf->SetXY($rx, $ry);
                $pdf->Cell($rw, 6, $negoNom, 0, 0, 'R');
                $ry += 6.5;
            }
            if ($contact !== '') {
                $pdf->SetFont('dejavusans', 'B', 12);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetXY($rx, $ry);
                $pdf->Cell($rw, 5, $contact, 0, 0, 'R');
            }
        }
    }
}

if (!function_exists('mbi_supports_tpl_select_thumbs')) {
    /**
     * Sélectionne les photos secondaires (hors héro) à afficher en mosaïque.
     * Respecte l'ordre manuel de l'éditeur ($ctx['photos_ids_secondaires'])
     * s'il est fourni, sinon prend les premières photos disponibles.
     * Plafonné à ($nbPhotos - 1) thumbs.
     */
    function mbi_supports_tpl_select_thumbs(array $photos, array $ctx, int $heroId, int $nbPhotos): array
    {
        $max = max(0, $nbPhotos - 1);
        if ($max === 0) return [];

        $autres    = [];
        $idsManuel = $ctx['photos_ids_secondaires'] ?? null;

        if (is_array($idsManuel) && !empty($idsManuel)) {
            $byId = [];
            foreach ($photos as $p) $byId[(int)($p['id'] ?? 0)] = $p;
            foreach ($idsManuel as $idP) {
                $idP = (int)$idP;
                if ($idP <= 0 || $idP === $heroId || !isset($byId[$idP])) continue;
                $autres[] = $byId[$idP];
                if (count($autres) >= $max) break;
            }
        } else {
            foreach ($photos as $p) {
                if ((int)($p['id'] ?? 0) === $heroId) continue;
                $autres[] = $p;
                if (count($autres) >= $max) break;
            }
        }
        return $autres;
    }
}

if (!function_exists('mbi_supports_get_description_annonce')) {
    /**
     * Texte commercial de l'annonce à afficher sur l'affiche (le « descriptif »).
     * Priorité : texte SYNTHÉTISÉ pour l'affiche (biens.bien_annonce_affiche, court) >
     *            texte d'annonce > description bien > descriptif bien.
     * → garantit que VENTE comme LOCATION affichent la version synthétisée courte
     *   dès qu'elle existe (sinon la location retombait sur le texte brut, trop long).
     * Filtre le placeholder système « Bien créé… ».
     */
    function mbi_supports_get_description_annonce(array $bien): string
    {
        $d = trim((string)($bien['bien_annonce_affiche'] ?? ''));
        if ($d === '') $d = trim((string)($bien['_annonce_description'] ?? ''));
        if ($d === '') $d = trim((string)($bien['description'] ?? $bien['descriptif'] ?? ''));
        if ($d !== '' && str_starts_with($d, 'Bien créé')) return '';
        return $d;
    }
}

if (!function_exists('mbi_supports_get_annexes')) {
    /**
     * Construit la liste des annexes du bien (colonnes booléennes = 1).
     * @return array<string> Libellés des annexes présentes
     */
    function mbi_supports_get_annexes(array $bien): array
    {
        $map = [
            'cave'        => 'Cave',
            'parking'     => 'Parking',
            'garage'      => 'Garage',
            'box'         => 'Box',
            'terrasse'    => 'Terrasse',
            'balcon'      => 'Balcon',
            'jardin'      => 'Jardin',
            'cour'        => 'Cour',
            'grenier'     => 'Grenier',
            'piscine'     => 'Piscine',
            'ascenseur'   => 'Ascenseur',
            'dependances' => 'Dépendances',
        ];
        $out = [];
        foreach ($map as $field => $libelle) {
            if ((int)($bien[$field] ?? 0) === 1) {
                $out[] = $libelle;
            }
        }
        return $out;
    }
}

if (!function_exists('mbi_supports_tpl_bloc_annexes')) {
    /**
     * Affiche un bloc « ANNEXES » : libellé + liste sur 2 colonnes.
     * @return float Y après le bloc
     */
    function mbi_supports_tpl_bloc_annexes(
        TCPDF $pdf, float $x, float $y, float $w, array $annexes,
        array $cAccent, array $cText, float $maxY
    ): float {
        if (empty($annexes)) return $y;
        $pdf->SetFont('dejavusans', 'B', 8.5);
        $pdf->SetTextColor($cAccent[0], $cAccent[1], $cAccent[2]);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, 4, mb_strtoupper('Annexes', 'UTF-8'), 0, 1, 'L');
        $y += 6;

        $colW   = $w / 2;
        $startY = $y;
        foreach ($annexes as $i => $an) {
            $col = $i % 2;
            $row = intdiv($i, 2);
            $ax  = $x + $col * $colW;
            $ay  = $startY + $row * 5.2;
            if ($ay > $maxY) break;
            $pdf->SetFont('dejavusans', 'B', 9);
            $pdf->SetTextColor($cAccent[0], $cAccent[1], $cAccent[2]);
            $pdf->SetXY($ax, $ay);
            $pdf->Cell(5, 4.6, '✓', 0, 0, 'L');
            $pdf->SetFont('dejavusans', '', 9.5);
            $pdf->SetTextColor($cText[0], $cText[1], $cText[2]);
            $pdf->SetXY($ax + 5, $ay);
            $pdf->Cell($colW - 6, 4.6, $an, 0, 0, 'L');
        }
        return $startY + intdiv(count($annexes) + 1, 2) * 5.2 + 2;
    }
}

if (!function_exists('mbi_supports_tpl_bloc_annonce')) {
    /**
     * Bloc « L'annonce » : titre de section + texte commercial qui s'adapte.
     * - taille de police réduite selon la longueur du texte ;
     * - si le texte ne tient pas dans la hauteur dispo ($maxH), tronqué proprement
     *   au dernier mot + « … » (jamais de débordement).
     * @return float Y après le bloc
     */
    function mbi_supports_tpl_bloc_annonce(
        TCPDF $pdf, float $x, float $y, float $w, float $maxH,
        string $text, array $cLabel, array $cText
    ): float {
        $text = trim($text);
        if ($text === '' || $maxH < 11) return $y;

        // Libellé de section
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->SetTextColor($cLabel[0], $cLabel[1], $cLabel[2]);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, 5, mb_strtoupper("L'annonce", 'UTF-8'), 0, 1, 'L');
        $yTxt  = $y + 6;
        $avail = $maxH - 6;
        if ($avail < 5) return $yTxt;

        // Police adaptative selon la longueur
        $len = mb_strlen($text, 'UTF-8');
        $fs  = $len > 900 ? 8.5 : ($len > 650 ? 9.5 : ($len > 400 ? 10.5 : ($len > 220 ? 11.5 : 12.5)));
        $lh  = $fs * 0.46; // hauteur de ligne approx (mm)

        // Capacité conservatrice (largeur de char surestimée -> on tronque plutôt que déborder)
        $charsPerLine = max(12, (int)floor($w / ($fs * 0.205)));
        $maxLines     = max(1, (int)floor($avail / $lh));
        $budget       = (int)floor($charsPerLine * $maxLines * 0.97);

        $fit = $text;
        if ($len > $budget) {
            $cut = max(1, $budget - 1);
            $fit = mb_substr($text, 0, $cut, 'UTF-8');
            $sp  = mb_strrpos($fit, ' ', 0, 'UTF-8');
            if ($sp !== false && $sp > $cut * 0.6) $fit = mb_substr($fit, 0, $sp, 'UTF-8');
            $fit = rtrim($fit, " \t\n\r\0\x0B,;:.") . '…';
        }

        $pdf->SetFont('dejavusans', '', $fs);
        $pdf->SetTextColor($cText[0], $cText[1], $cText[2]);
        $pdf->SetXY($x, $yTxt);
        $pdf->MultiCell($w, $lh, $fit, 0, 'L');
        return $pdf->GetY();
    }
}

if (!function_exists('mbi_supports_tpl_bloc_copropriete')) {
    /**
     * Bloc « Copropriété » — même présentation que les conditions financières :
     * un libellé de section doré + paires label/valeur sur 2 colonnes.
     * À placer entre les conditions financières et le bloc « L'annonce ».
     * N'affiche rien (retourne $y inchangé) si $lignes est vide.
     *
     * @param array $lignes Paires [label, valeur] (cf. mbi_supports_get_copropriete_lignes)
     * @param array $cLabel Couleur du titre de section (doré)
     * @param array $cKey   Couleur des libellés (gris)
     * @param array $cVal   Couleur des valeurs (navy/primaire)
     * @return float Y après le bloc
     */
    function mbi_supports_tpl_bloc_copropriete(
        TCPDF $pdf, float $x, float $y, float $w,
        array $lignes, array $cLabel, array $cKey, array $cVal
    ): float {
        if (empty($lignes)) return $y;

        $pdf->SetFont('dejavusans', 'B', 8.5);
        $pdf->SetTextColor($cLabel[0], $cLabel[1], $cLabel[2]);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, 4, mb_strtoupper('Copropriété', 'UTF-8'), 0, 1, 'L');
        $y += 5;

        // 2 colonnes, identique au bloc conditions. Largeur de colonne PLAFONNÉE
        // pour resserrer le tableau (sinon les valeurs partent à l'extrême droite
        // sur les panneaux larges : mosaïque, magazine).
        $colW = min($w / 2 - 4, 72.0);
        $half = (int)ceil(count($lignes) / 2);
        $cols = [array_slice($lignes, 0, $half), array_slice($lignes, $half)];
        $maxRows = max(count($cols[0]), count($cols[1]));
        foreach ($cols as $ci => $col) {
            $x0 = $x + $ci * ($colW + 8);
            foreach ($col as $ri => $cf) {
                [$lab, $val] = $cf;
                $ly = $y + $ri * 5.2;
                $pdf->SetFont('dejavusans', '', 10);
                $pdf->SetTextColor($cKey[0], $cKey[1], $cKey[2]);
                $pdf->SetXY($x0, $ly);
                $pdf->Cell($colW * 0.55, 4.5, $lab . ' :', 0, 0, 'L');
                $pdf->SetFont('dejavusans', 'B', 10);
                $pdf->SetTextColor($cVal[0], $cVal[1], $cVal[2]);
                $pdf->SetXY($x0 + $colW * 0.55, $ly);
                $pdf->Cell($colW * 0.45, 4.5, $val, 0, 0, 'R');
            }
        }
        return $y + $maxRows * 5.2 + 5;
    }
}

if (!function_exists('mbi_supports_resoudre_logo_entite')) {
    /**
     * Résout le chemin fichier du logo à afficher sur l'affiche.
     * Priorité : logo AGENCE (agences.logo_url / logo_path) puis fallback
     * logo SOCIÉTÉ (societes.logo_url, exposé via societe_logo_url par
     * agence_load_with_societe_docs). Les valeurs sont des chemins relatifs
     * à public_html (ex. images/logos/logo-emery-servjean.png).
     *
     * @param array $agence Tableau agence enrichi (contexte affiche)
     * @return string|null Chemin absolu lisible, ou null si aucun logo
     */
    function mbi_supports_resoudre_logo_entite(array $agence): ?string
    {
        $root = realpath(__DIR__ . '/..');
        if ($root === false) $root = __DIR__ . '/..';
        foreach (['logo_url', 'logo_path', 'societe_logo_url'] as $k) {
            $rel = trim((string)($agence[$k] ?? ''));
            if ($rel === '') continue;
            if (is_file($rel) && is_readable($rel)) return $rel;            // déjà absolu
            $p = $root . '/' . ltrim($rel, '/');
            if (is_file($p) && is_readable($p)) return $p;
        }
        return null;
    }
}

if (!function_exists('mbi_supports_logo_card_width')) {
    /**
     * Largeur de la carte blanche du logo dans le bandeau transaction (0 si pas
     * de logo). Permet à un layout de connaître où commence la pastille
     * « À LOUER/À VENDRE » ($x + largeur + gap) pour y aligner d'autres éléments
     * (ex. réf centrée sous le bandeau). DOIT rester synchro avec le calcul
     * interne de mbi_supports_tpl_badge_transaction().
     */
    function mbi_supports_logo_card_width(?string $logoPath, float $w, float $h, ?float $logoCardH = null): float
    {
        if ($logoPath === null || !is_file($logoPath)) return 0.0;
        $pad = 1.6;
        $cardH = ($logoCardH !== null && $logoCardH > $h) ? $logoCardH : $h;
        $ratio = 3.0;
        $info = @getimagesize($logoPath);
        if (is_array($info) && (int)$info[1] > 0) {
            $ratio = (int)$info[0] / (int)$info[1];
        }
        $logoW = ($cardH - 2 * $pad) * $ratio;
        return min($logoW + 2 * $pad, $w * 0.5);
    }
}

if (!defined('MBI_SUPPORTS_BADGE_LOGO_GAP')) {
    define('MBI_SUPPORTS_BADGE_LOGO_GAP', 6.0);
}

if (!function_exists('mbi_supports_tpl_logo_card')) {
    /**
     * Dessine une carte blanche autonome contenant le logo (agence/société), le
     * logo occupant toute la hauteur de la carte, la carte épousant la largeur du
     * logo et centrée horizontalement dans la box ($boxX..$boxW). Utilisé quand un
     * layout place le logo HORS du bandeau transaction (ex. mosaïque : logo à droite).
     *
     * @return float Hauteur réellement occupée par la carte (0 si pas de logo).
     */
    function mbi_supports_tpl_logo_card(
        TCPDF $pdf, float $boxX, float $boxY, float $boxW, float $boxH, ?string $logoPath,
        bool $withCard = true, string $halign = 'C'
    ): float {
        if ($logoPath === null || !is_file($logoPath)) return 0.0;
        $pad = $withCard ? 1.6 : 0.0;
        $ratio = 3.0;
        $info = @getimagesize($logoPath);
        if (is_array($info) && (int)$info[1] > 0) {
            $ratio = (int)$info[0] / (int)$info[1];
        }
        $cardH = $boxH;
        $logoH = $cardH - 2 * $pad;
        $logoW = $logoH * $ratio;
        $cardW = $logoW + 2 * $pad;
        if ($cardW > $boxW) { // logo trop large → limité par la largeur de la box
            $cardW = $boxW;
            $logoW = $cardW - 2 * $pad;
            $logoH = $logoW / $ratio;
            $cardH = $logoH + 2 * $pad;
        }
        $cardX = $halign === 'L' ? $boxX
               : ($halign === 'R' ? $boxX + ($boxW - $cardW)
               : $boxX + ($boxW - $cardW) / 2);
        if ($withCard) {
            mbi_supports_tpl_card_round_shadow($pdf, $cardX, $boxY, $cardW, $cardH, 4.5, [255, 255, 255], 1.0, true);
        }
        try {
            $pdf->Image(
                $logoPath,
                $cardX + ($cardW - $logoW) / 2, $boxY + ($cardH - $logoH) / 2, $logoW, $logoH,
                '', '', '', false, 300, '', false, false, 0, '', false, false
            );
        } catch (Throwable) { /* logo illisible → carte blanche seule */ }
        return $cardH;
    }
}

if (!function_exists('mbi_supports_tpl_badge_transaction')) {
    /**
     * Bandeau « À VENDRE » / « À LOUER » selon le mandat (bleu pétrole, blanc).
     *
     * Si $logoPath est fourni : le logo (agence ou société) est posé à GAUCHE
     * sur une carte blanche (contraste garanti quel que soit le fond du panneau)
     * et la pastille « À LOUER » est RÉDUITE et alignée à droite. Sinon, le
     * bandeau occupe toute la largeur comme avant (rétrocompat).
     */
    function mbi_supports_tpl_badge_transaction(
        TCPDF $pdf, float $x, float $y, float $w, float $h, array $bien, array $cP, array $cS,
        ?string $logoPath = null, ?float $logoCardH = null, string $logoGrow = 'up',
        bool $logoCard = true
    ): void {
        $txt = mbi_supports_est_location($bien) ? 'À LOUER' : 'À VENDRE';
        $bg  = [38, 96, 110]; // bleu pétrole

        // Géométrie : avec logo → logo à gauche (PLEINE HAUTEUR), badge réduit à droite.
        // La carte blanche ÉPOUSE la largeur du logo (calculée d'après son ratio natif)
        // pour que le logo occupe toute la hauteur disponible sans blanc superflu.
        // $logoCardH permet d'agrandir la carte logo AU-DELÀ de la hauteur du
        // bandeau (≈2× pour égaler la card titre) ; $logoGrow indique vers où
        // elle déborde : 'up' (au-dessus du bandeau, zone libre), 'down' ou 'center'.
        $bx = $x; $bw = $w;
        if ($logoPath !== null && is_file($logoPath)) {
            $gap = MBI_SUPPORTS_BADGE_LOGO_GAP;
            $pad = $logoCard ? 1.6 : 0.0;   // sans cadre → logo collé (pas de marge blanche)

            // Hauteur de la carte logo (>= hauteur du bandeau)
            $cardH = ($logoCardH !== null && $logoCardH > $h) ? $logoCardH : $h;
            if ($logoGrow === 'down')        $cardY = $y;
            elseif ($logoGrow === 'center')  $cardY = $y + ($h - $cardH) / 2;
            else                             $cardY = $y + $h - $cardH; // 'up'

            $logoH = $cardH - 2 * $pad;

            // Ratio natif du logo (fallback paysage si lecture impossible)
            $ratio = 3.0;
            $info = @getimagesize($logoPath);
            if (is_array($info) && (int)$info[1] > 0) {
                $ratio = (int)$info[0] / (int)$info[1];
            }
            $logoW = $logoH * $ratio;

            // Largeur carte = logo + padding, plafonnée à la moitié du bandeau.
            // Si plafonnée, le logo est alors limité par la largeur (hauteur réduite).
            $cardW  = min($logoW + 2 * $pad, $w * 0.5);
            $innerW = $cardW - 2 * $pad;
            if ($logoW > $innerW) { $logoW = $innerW; $logoH = $innerW / $ratio; }

            $logoX = $x + ($cardW - $logoW) / 2;
            $logoY = $cardY + ($cardH - $logoH) / 2;

            $bx = $x + $cardW + $gap;
            $bw = $w - $cardW - $gap;

            // Carte blanche (lisibilité sur fonds navy/pétrole/crème) — optionnelle
            if ($logoCard) {
                mbi_supports_tpl_card_round_shadow($pdf, $x, $cardY, $cardW, $cardH, 4.5, [255, 255, 255], 1.0, true);
            }
            try {
                $pdf->Image(
                    $logoPath, $logoX, $logoY, $logoW, $logoH,
                    '', '', '', false, 300, '', false, false, 0, '', false, false
                );
            } catch (Throwable) { /* logo illisible → carte blanche seule */ }
        }

        mbi_supports_tpl_card_round_shadow($pdf, $bx, $y, $bw, $h, 4.5, $bg, 1.0, true);

        $targetW  = $bw * 0.86;
        $fontSize = $h * 1.85;                    // remplit la hauteur du bandeau
        $pdf->SetFont('dejavusans', 'B', $fontSize);
        $tw = $pdf->GetStringWidth($txt);
        if ($tw > $targetW) {                     // trop large -> on réduit la police
            $fontSize *= $targetW / $tw;
            $pdf->SetFont('dejavusans', 'B', $fontSize);
            $tw = $pdf->GetStringWidth($txt);
        }
        $nChars  = max(1, mb_strlen($txt, 'UTF-8'));
        $spacing = $nChars > 1 ? min(7.0, max(0.0, ($targetW - $tw) / ($nChars - 1))) : 0.0;
        $pdf->SetFontSpacing($spacing);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY($bx, $y);
        $pdf->Cell($bw, $h, $txt, 0, 0, 'C');
        $pdf->SetFontSpacing(0);                  // reset (ne pas polluer le reste)
    }
}

// ═════════════════════════════════════════════════════════════════════════
// Helpers legacy conservés (utilisés par fiche_client.php — A3 portrait)
// ═════════════════════════════════════════════════════════════════════════

if (!function_exists('mbi_supports_tpl_v2_badge')) {
    function mbi_supports_tpl_v2_badge(TCPDF $pdf, float $x, float $y, float $w, float $h, string $label, string $value, array $cP, array $cS, array $cT): void
    {
        $cR = $h / 2 - 1;
        $pdf->SetFillColor($cP[0], $cP[1], $cP[2]);
        $pdf->Circle($x + $cR + 1, $y + $cR + 1, $cR, 0, 360, 'F');
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
        $pdf->SetXY($x, $y + 2);
        $pdf->Cell($cR * 2 + 2, $cR * 2 - 2, mb_substr($label, 0, 5, 'UTF-8'), 0, 0, 'C');
        $textX = $x + $cR * 2 + 5;
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->SetTextColor(110, 116, 130);
        $pdf->SetXY($textX, $y + 2);
        $pdf->Cell($w - $cR * 2 - 6, 5, mb_strtoupper($label, 'UTF-8'), 0, 0, 'L');
        $pdf->SetFont('dejavusans', 'B', 14);
        $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
        $pdf->SetXY($textX, $y + 9);
        $pdf->Cell($w - $cR * 2 - 6, 8, $value, 0, 0, 'L');
    }
}

if (!function_exists('mbi_supports_tpl_v2_pied')) {
    /**
     * Pied legacy A3 portrait (44 mm) — conservé pour fiche_client.php
     */
    function mbi_supports_tpl_v2_pied(TCPDF $pdf, array $ctx, array $cP, array $cS, float $pageW = 210, float $pageH = 297): void
    {
        $agence = $ctx['agence'] ?? [];
        $bien   = $ctx['bien']   ?? [];
        $negociateur = $ctx['negociateur'] ?? [];

        $piedH = 44;
        $piedY = $pageH - $piedH;
        $pdf->SetFillColor($cP[0], $cP[1], $cP[2]);
        $pdf->Rect(0, $piedY, $pageW, $piedH, 'F');
        $pdf->SetFillColor($cS[0], $cS[1], $cS[2]);
        $pdf->Rect(0, $piedY, $pageW, 1.6, 'F');

        $pdf->SetFont('dejavusans', 'B', 14);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(18, $piedY + 5);
        $nomAg = (string)($agence['nom_agence'] ?? $agence['nom'] ?? 'Agence');
        $pdf->Cell(180, 7, $nomAg, 0, 1, 'L');

        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetTextColor(220, 222, 230);
        $pdf->SetXY(18, $piedY + 14);
        $coord = trim((string)($agence['adresse'] ?? '') . ' · ' . trim((string)($agence['code_postal'] ?? '') . ' ' . ($agence['ville'] ?? '')) . ' · ' . ($agence['telephone'] ?? '') . ' · ' . ($agence['email'] ?? ''), ' ·');
        $pdf->Cell($pageW - 36, 5, $coord, 0, 1, 'L');

        $typeTr = strtolower((string)($bien['type_transaction'] ?? ''));
        $activiteCible = match (true) {
            str_contains($typeTr, 'vente'),
            str_contains($typeTr, 'cession') => 'transaction',
            str_contains($typeTr, 'location') => 'gestion',
            default => null,
        };
        $rcpAct = ($activiteCible && !empty($agence['activites'][$activiteCible]['rc_pro_assureur']))
            ? (string)$agence['activites'][$activiteCible]['rc_pro_assureur'] : '';
        $gfAct  = ($activiteCible && !empty($agence['activites'][$activiteCible]['garant_nom']))
            ? (string)$agence['activites'][$activiteCible]['garant_nom'] : '';

        $cartePro = trim((string)($agence['carte_pro_numero'] ?? $agence['carte_pro'] ?? ''));
        $garant   = trim($gfAct ?: (string)($agence['garant_financier'] ?? $agence['garantie_financiere'] ?? ''));
        $rcPro    = trim($rcpAct ?: (string)($agence['rc_pro']           ?? $agence['assurance_rc_pro'] ?? ''));
        $infos = array_filter([
            $cartePro !== '' ? 'Carte pro ' . $cartePro : '',
            $garant   !== '' ? 'Garant ' . $garant : '',
            $rcPro    !== '' ? 'RC Pro ' . $rcPro : '',
        ]);
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetTextColor(180, 185, 200);
        $pdf->SetXY(18, $piedY + 21);
        $pdf->MultiCell($pageW - 36, 4, implode(' · ', $infos), 0, 'L');

        if ((int)($bien['bien_en_copropriete'] ?? 0) === 1) {
            $copLots = $bien['copro_nb_lots'] ?? null;
            $copChg  = $bien['copro_quote_part_charges'] ?? null;
            $copProc = (int)($bien['copro_procedure'] ?? 0) === 1;
            $partsCopro = ['Copropriété de ' . ($copLots ?: '?') . ' lots'];
            if ($copChg) $partsCopro[] = 'charges ~ ' . number_format((float)$copChg, 0, ',', ' ') . ' €/an';
            $partsCopro[] = $copProc ? 'procédures L611-1 en cours' : 'absence de procédures L611-1';
            $pdf->SetXY(18, $piedY + 28);
            $pdf->MultiCell($pageW - 36, 4, implode(' · ', $partsCopro), 0, 'L');
        }

        if ($negociateur) {
            $nego = trim(($negociateur['prenom'] ?? '') . ' ' . ($negociateur['nom'] ?? ''));
            $tel  = trim((string)($negociateur['telephone_pro'] ?? ''));
            $pdf->SetFont('dejavusans', 'B', 10);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY(18, $piedY + 35);
            $pdf->Cell($pageW - 36, 4, 'Votre contact : ' . $nego . ($tel ? ' · ' . $tel : ''), 0, 0, 'R');
        }

        $pdf->SetFont('dejavusans', 'I', 8);
        $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
        $pdf->SetXY(18, $piedY + 36);
        $pdf->Cell(80, 4, 'Partenaire MaBoxImmo', 0, 0, 'L');
    }
}

if (!function_exists('mbi_supports_tpl_placeholder')) {
    function mbi_supports_tpl_placeholder(TCPDF $pdf, float $x, float $y, float $w, float $h, array $color): void
    {
        mbi_supports_tpl_v2_placeholder($pdf, $x, $y, $w, $h, $color);
    }
}

if (!function_exists('mbi_supports_tpl_card')) {
    function mbi_supports_tpl_card(TCPDF $pdf, float $x, float $y, float $w, float $h, string $label, string $value, array $cP, array $cT): void
    {
        $pdf->SetDrawColor($cP[0], $cP[1], $cP[2]);
        $pdf->SetFillColor(248, 249, 251);
        $pdf->Rect($x, $y, $w, $h, 'DF');
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetTextColor(110, 116, 130);
        $pdf->SetXY($x + 2, $y + 2);
        $pdf->Cell($w - 4, 5, mb_strtoupper($label, 'UTF-8'), 0, 0, 'L');
        $pdf->SetFont('dejavusans', 'B', 14);
        $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
        $pdf->SetXY($x + 2, $y + 9);
        $pdf->Cell($w - 4, 9, $value, 0, 0, 'L');
    }
}

if (!function_exists('mbi_supports_tpl_charge_honoraires')) {
    function mbi_supports_tpl_charge_honoraires(array $bien): string
    {
        $inclus = trim((string)($bien['honoraires_inclus'] ?? ''));
        if ($inclus !== '') return 'Honoraires inclus : ' . $inclus;
        $charge = trim((string)($bien['honoraires_charge'] ?? ''));
        if ($charge !== '') return 'Honoraires charge ' . $charge;
        return '';
    }
}

if (!function_exists('mbi_supports_tpl_pied_mentions')) {
    function mbi_supports_tpl_pied_mentions(TCPDF $pdf, array $ctx, array $cP, array $cT): void
    {
        $agence    = $ctx['agence']    ?? [];
        $negociateur = $ctx['negociateur'] ?? [];

        $pdf->SetY(-26);
        $pdf->SetDrawColor(180, 180, 190);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(1);

        $typeTrFc = strtolower((string)($ctx['bien']['type_transaction'] ?? ''));
        $activiteCibleFc = match (true) {
            str_contains($typeTrFc, 'vente'),
            str_contains($typeTrFc, 'cession') => 'transaction',
            str_contains($typeTrFc, 'location') => 'gestion',
            default => null,
        };
        $rcpActFc = ($activiteCibleFc && !empty($agence['activites'][$activiteCibleFc]['rc_pro_assureur']))
            ? (string)$agence['activites'][$activiteCibleFc]['rc_pro_assureur'] : '';
        $gfActFc  = ($activiteCibleFc && !empty($agence['activites'][$activiteCibleFc]['garant_nom']))
            ? (string)$agence['activites'][$activiteCibleFc]['garant_nom'] : '';

        $cartePro = trim((string)($agence['carte_pro_numero'] ?? $agence['carte_pro'] ?? ''));
        $garant   = trim($gfActFc  ?: (string)($agence['garant_financier'] ?? $agence['garantie_financiere'] ?? ''));
        $rcPro    = trim($rcpActFc ?: (string)($agence['rc_pro']           ?? $agence['assurance_rc_pro'] ?? ''));

        $lignes = [];
        if ($cartePro !== '') $lignes[] = 'Carte professionnelle n° ' . $cartePro;
        if ($garant !== '')   $lignes[] = 'Garant financier : ' . $garant;
        if ($rcPro !== '')    $lignes[] = 'Assurance RC Pro : ' . $rcPro;

        if ($negociateur) {
            $nego = trim(($negociateur['prenom'] ?? '') . ' ' . ($negociateur['nom'] ?? ''));
            $tel  = trim((string)($negociateur['telephone_pro'] ?? ''));
            $mail = trim((string)($negociateur['email']     ?? ''));
            $lignes[] = 'Négociateur : ' . $nego . ($tel ? ' · ' . $tel : '') . ($mail ? ' · ' . $mail : '');
        }

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetTextColor(80, 84, 95);
        foreach ($lignes as $li) {
            $pdf->SetX(15);
            $pdf->Cell(180, 4, $li, 0, 1, 'L');
        }
    }
}
