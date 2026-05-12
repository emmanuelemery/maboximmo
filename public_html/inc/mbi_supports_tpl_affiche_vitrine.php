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
            // Clipping rounded + image
            try {
                $pdf->StartTransform();
                $pdf->RoundedRect($x, $y, $w, $h, $r, '1111', 'CNZ');
                $pdf->Image($imgPath, $x, $y, $w, $h, '', '', '', false, 250, '', false, false, 0, 'CM', false, false);
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
        float $labelSize = 9.0,
        float $valueSize = 28.0,
        float $rad = 4.0
    ): void {
        if ($bgRgb !== null) {
            mbi_supports_tpl_card_round_shadow($pdf, $x, $y, $w, $h, $rad, $bgRgb, 1.0, $shadow);
        }
        // Label en haut-gauche
        $pdf->SetFont('dejavusans', '', $labelSize);
        $pdf->SetTextColor(...($textRgb ?? [120, 126, 140]));
        $pdf->SetXY($x + 5, $y + 3);
        $pdf->Cell($w - 10, 5, mb_strtoupper($label, 'UTF-8'), 0, 0, 'L');

        // Valeur : chiffre plein hauteur, aligné à droite (taille auto pour remplir)
        $pdf->SetFont('dejavusans', 'B', $valueSize);
        $pdf->SetTextColor(...($valRgb ?? $cP));
        $pdf->SetXY($x + 5, $y);
        $pdf->Cell($w - 10, $h, $value, 0, 0, 'R');
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
        ?array $textRgbHeader = null
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

        // Label "DPE" / "GES" à gauche
        $labW = 14;
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
            $pdf->SetTextColor(255, 255, 255);
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
        $agence      = $ctx['agence']      ?? [];
        $bien        = $ctx['bien']        ?? [];
        $negociateur = $ctx['negociateur'] ?? [];

        $piedY = $pageH - $piedH;

        // Fond navy plein (pas de rounded top — pied au ras du bord, on garde un look pleine largeur propre)
        $pdf->SetFillColor($cP[0], $cP[1], $cP[2]);
        $pdf->Rect(0, $piedY, $pageW, $piedH, 'F');
        // Trait or fin haut
        $pdf->SetFillColor($cS[0], $cS[1], $cS[2]);
        $pdf->Rect(0, $piedY, $pageW, 1.2, 'F');

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

        $nomAg = (string)($agence['nom_agence'] ?? $agence['nom'] ?? 'Agence');
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(20, $piedY + 3.5);
        $pdf->Cell($pageW / 2 - 24, 5, $nomAg, 0, 0, 'L');

        if ($negociateur) {
            $nego = trim(($negociateur['prenom'] ?? '') . ' ' . ($negociateur['nom'] ?? ''));
            $tel  = trim((string)($negociateur['telephone_pro'] ?? ''));
            $mail = trim((string)($negociateur['email'] ?? ''));
            $partsNego = array_filter([$nego, $tel, $mail]);
            $pdf->SetFont('dejavusans', 'B', 10);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY($pageW / 2, $piedY + 3.8);
            $pdf->Cell($pageW / 2 - 20, 5, '✦ ' . implode('  ·  ', $partsNego), 0, 0, 'R');
        }

        $coord = trim(
            (string)($agence['adresse'] ?? '') . ' · ' .
            trim((string)($agence['code_postal'] ?? '') . ' ' . ($agence['ville'] ?? '')) . ' · ' .
            ($agence['telephone'] ?? '') . ' · ' .
            ($agence['email'] ?? ''),
            ' ·'
        );
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetTextColor(220, 222, 230);
        $pdf->SetXY(20, $piedY + 9);
        $pdf->Cell($pageW - 40, 4, $coord, 0, 0, 'L');

        $infos = array_filter([
            $cartePro !== '' ? 'Carte pro ' . $cartePro : '',
            $garant   !== '' ? 'Garant ' . $garant : '',
            $rcPro    !== '' ? 'RC Pro ' . $rcPro : '',
        ]);
        if (!empty($infos)) {
            $pdf->SetFont('dejavusans', '', 7.5);
            $pdf->SetTextColor(180, 186, 200);
            $pdf->SetXY(20, $piedY + 13);
            $pdf->Cell($pageW - 130, 4, implode('  ·  ', $infos), 0, 0, 'L');
        }

        if ((int)($bien['bien_en_copropriete'] ?? 0) === 1) {
            $copLots = $bien['copro_nb_lots'] ?? null;
            $copChg  = $bien['copro_quote_part_charges'] ?? $bien['copro_charges_annuelles'] ?? null;
            $copProc = (int)($bien['copro_procedure'] ?? 0) === 1;
            $partsCopro = ['Copro ' . ($copLots ?: '?') . ' lots'];
            if ($copChg) $partsCopro[] = '~' . number_format((float)$copChg, 0, ',', ' ') . ' €/an';
            $partsCopro[] = $copProc ? 'L611-1 en cours' : 'sans L611-1';
            $pdf->SetFont('dejavusans', '', 7.5);
            $pdf->SetTextColor(180, 186, 200);
            $pdf->SetXY($pageW - 110, $piedY + 13);
            $pdf->Cell(90, 4, implode(' · ', $partsCopro), 0, 0, 'R');
        }

        $pdf->SetFont('dejavusans', 'I', 6.5);
        $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
        $pdf->SetXY($pageW - 60, $piedY + $piedH - 4);
        $pdf->Cell(40, 3, 'Partenaire MaBoxImmo', 0, 0, 'R');
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
