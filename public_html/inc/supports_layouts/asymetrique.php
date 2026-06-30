<?php
declare(strict_types=1);

/**
 * =======================================================================
 * Layout : asymetrique (A3 LANDSCAPE - V3 premium diagonale)
 * =======================================================================
 *
 *   1. Photo hero PLEIN CADRE (full bleed)
 *   2. Panneau navy en DIAGONALE (polygone) sur la droite, opacite ~0.9,
 *      arete gauche soulignee d'un trait or - effet magazine premium
 *   3. Carte titre annonce / ref en overlay haut-gauche sur la photo
 *   4. Bloc info dans le panneau : titre headline + accroche + prix XL
 *      + mini-cards caracs (translucides) + DPE/GES + atouts
 *   5. Pied navy fin
 *
 * Ideal : biens premium, 1 photo forte, mise en scene magazine.
 * =======================================================================
 */

if (!function_exists('mbi_supports_layout_asymetrique_build')) {

    function mbi_supports_layout_asymetrique_build(array $ctx): TCPDF
    {
        if (function_exists('mb_internal_encoding')) {
            @mb_internal_encoding('UTF-8');
        }

        $bien        = $ctx['bien']        ?? [];
        $photos      = mbi_supports_filtre_photos_reelles($ctx['photos'] ?? []);
        $style       = $ctx['style']       ?? [];
        $score       = $ctx['score']       ?? null;
        $critique    = $ctx['critique']    ?? [];
        $mentionsTextes = $critique['mentions_textes'] ?? [];

        $iaRed       = is_array($ctx['ia_redaction'] ?? null) ? ($ctx['ia_redaction']['data'] ?? []) : [];
        $iaAccroche  = trim((string)($iaRed['accroche'] ?? ''));
        $iaAtouts    = is_array($iaRed['atouts'] ?? null) ? $iaRed['atouts'] : [];

        $cP = $style['rgb_primaire']   ?? [36, 59, 92];
        $cS = $style['rgb_secondaire'] ?? [212, 160, 71];
        $cT = $style['rgb_texte']      ?? [31, 41, 55];

        $angle    = (string)($ctx['angle'] ?? 'generique');
        $anglePal = mbi_supports_tpl_angle_palette($angle, $cS);
        $cAngle   = $anglePal['rgb'];
        $libAngle = $anglePal['libelle'];
        // Sur fond navy : titre en or pour le contraste
        $cTitre   = $cS;

        $nbPhotos = (int)($ctx['nb_photos'] ?? 3);
        if ($nbPhotos < 1) $nbPhotos = 1;
        if ($nbPhotos > 3) $nbPhotos = 3;

        $pdf = new TCPDF('L', 'mm', 'A3', true, 'UTF-8');
        $pdf->SetCreator('MaBoxImmo - Ma Box Communication');
        $pdf->SetTitle('Affiche vitrine A3 H - asymetrique');
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->AddPage();

        $pageW  = 420;
        $pageH  = 297;
        $piedH  = 22;
        $photoH = $pageH - $piedH;

        // Geometrie diagonale : arete decalee a DROITE pour reduire le panneau
        // navy de ~12 % (250→270 en haut, 190→218 en bas).
        $topX = 270.0;
        $botX = 218.0;
        $polyLeft  = [0, 0, $topX, 0, $botX, $photoH, 0, $photoH];          // zone photo
        $polyRight = [$topX, 0, $pageW, 0, $pageW, $photoH, $botX, $photoH]; // panneau navy

        // --- 1. PHOTO HERO cantonnee a la zone GAUCHE (decoupe diagonale) ---
        $hero     = mbi_supports_photo_hero($photos, $ctx['photo_hero_id_suggestion'] ?? null);
        $heroPath = $hero ? mbi_supports_resoudre_photo_path($hero) : null;
        if ($heroPath !== null) {
            try {
                $pdf->StartTransform();
                $pdf->Polygon($polyLeft, 'CNZ');
                // box 0..topX, 'CM' = remplit la zone en recadrant (cover)
                $pdf->Image($heroPath, 0, 0, $topX, $photoH, '', '', '', false, 300, '', false, false, 0, 'CM', false, false);
                $pdf->StopTransform();
            } catch (Throwable) {
                $pdf->StopTransform();
                mbi_supports_tpl_v2_placeholder($pdf, 0, 0, $botX, $photoH, $cP);
            }
        } else {
            mbi_supports_tpl_v2_placeholder($pdf, 0, 0, $botX, $photoH, $cP);
        }

        // --- 2. PANNEAU NAVY OPAQUE (zone droite) ---
        $pdf->SetFillColor($cP[0], $cP[1], $cP[2]);
        $pdf->Polygon($polyRight, 'F');
        // Watermark filigrane dans le panneau
        $watermarkPath = __DIR__ . '/../../images/mbi_affiche_watermark.jpg';
        if (is_file($watermarkPath) && is_readable($watermarkPath)) {
            try {
                $pdf->StartTransform();
                $pdf->Polygon($polyRight, 'CNZ');
                $pdf->SetAlpha(0.13); // filigrane visible sous le navy, sans gener le texte
                $pdf->Image($watermarkPath, $botX, 0, $pageW - $botX, $photoH, '', '', '', false, 250, '', false, false, 0, 'CM', false, false);
                $pdf->SetAlpha(1.0);
                $pdf->StopTransform();
            } catch (Throwable) {
                $pdf->SetAlpha(1.0);
            }
        }
        // Voile sombre derriere la carte titre (sur la photo, coin haut-gauche)
        $pdf->SetAlpha(0.18);
        $pdf->SetFillColor(0, 0, 0);
        $pdf->Rect(0, 0, 232, 64, 'F');
        $pdf->SetAlpha(1.0);
        // Trait or sur l'arete diagonale
        $pdf->SetDrawColor($cS[0], $cS[1], $cS[2]);
        $pdf->SetLineWidth(1.6);
        $pdf->Line($topX, 0, $botX, $photoH);
        $pdf->SetLineWidth(0.2);

        // --- 2b. MINI-CARDS : 2 autres photos sous la photo hero (bas-gauche) ---
        $heroId = (int)($hero['id'] ?? 0);
        $autres = mbi_supports_tpl_select_thumbs($photos, $ctx, $heroId, 3); // jusqu'a 2 thumbs
        if (!empty($autres)) {
            $tw = 98; $th = 70; $tg = 5; // photos secondaires plus grosses ; 14 + 98 + 5 + 98 = 215 < botX(218)
            $tx0 = 14;
            $ty  = $photoH - $th - 16;
            foreach ($autres as $i => $p) {
                if ($i >= 2) break;
                $tx    = $tx0 + $i * ($tw + $tg);
                $tpath = mbi_supports_resoudre_photo_path($p);
                mbi_supports_tpl_image_round($pdf, $tpath, $tx, $ty, $tw, $th, 4.0, true, $cS, 0.8);
            }
        }

        // --- 3. CARTE TITRE annonce overlay haut-gauche ---
        $titreAnnonce = trim((string)($bien['_annonce_titre'] ?? ''));
        $ref     = (string)($bien['reference_bien'] ?? ('#' . ($bien['id'] ?? '')));
        $loc     = trim((string)($bien['code_postal'] ?? '') . ' ' . ($bien['ville'] ?? ''));
        $refSous = trim('Réf ' . $ref . ($loc !== '' ? '  ·  ' . $loc : ''));

        // ═══ EN-TÊTE 3 ZONES : logo (gauche) | card titre (centre) | À VENDRE + prix (droite) ═══
        $logoAffiche = mbi_supports_resoudre_logo_entite($ctx['agence'] ?? []);

        // Panneau droit (navy)
        $px = 276;
        $pw = $pageW - $px - 16;          // = 128

        // ZONE DROITE : badge « À VENDRE » RÉDUIT + prix CENTRÉ dessous
        $avH = 14; $avY = 16;
        mbi_supports_tpl_badge_transaction($pdf, $px, $avY, $pw, $avH, $bien, $cP, $cS, null);
        $infoPrix = mbi_supports_get_prix_ou_loyer($bien);
        $priceY = $avY + $avH + 5;
        mbi_supports_tpl_text_shadow(
            $pdf, $px, $priceY, $pw, 18,
            mbi_supports_format_prix_complet($infoPrix),
            [255, 255, 255], 'dejavusans', 'B', 40, 'C', false, 1.4, 1.8, null, 0.40
        );

        // ZONE GAUCHE : logo PNG seul, collé À GAUCHE
        $logoBoxX = 14; $logoBoxY = 12; $logoBoxH = 42;
        mbi_supports_tpl_logo_card($pdf, $logoBoxX, $logoBoxY, 130, $logoBoxH, $logoAffiche, false, 'L');
        $logoRight = $logoBoxX + $logoBoxH * 1.55;   // largeur logo ≈ hauteur × ratio

        // ZONE CENTRE : card titre centrée ENTRE le logo et le badge (espaces de clarté)
        $gapH  = 14;
        $tcX   = $logoRight + $gapH;
        $tcW   = ($px - $gapH) - $tcX;
        $cardH = $titreAnnonce !== '' ? 32 : 20;   // card titre réduite (moins de vide)
        $cardY = 18;
        mbi_supports_tpl_card_round_shadow($pdf, $tcX, $cardY, $tcW, $cardH, 6.0, [10, 18, 32], 0.62, true, $cS);
        $tcTX = $tcX + 6; $tcTW = $tcW - 12;
        if ($titreAnnonce !== '') {
            $titre2L = mbi_supports_couper_2_lignes(mb_substr($titreAnnonce, 0, 90, 'UTF-8'));
            $pdf->SetFont('dejavusans', 'B', 19);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY($tcTX, $cardY + 7);
            $pdf->MultiCell($tcTW, 8.0, $titre2L, 0, 'C');
            $pdf->SetFont('dejavusans', '', 9);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY($tcTX, $cardY + $cardH - 8);
            $pdf->Cell($tcTW, 4, mb_strtoupper($refSous, 'UTF-8'), 0, 0, 'C');
        } else {
            $pdf->SetFont('dejavusans', 'B', 13);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY($tcTX, $cardY + 9);
            $pdf->Cell($tcTW, 6, mb_strtoupper($refSous, 'UTF-8'), 0, 0, 'C');
        }

        // Le bloc info du panneau démarre sous le prix
        $py = $priceY + 22;

        // --- CONDITIONS (col gauche) + COPROPRIÉTÉ (col droite) alignées (panneau navy) ---
        $lignesCF    = mbi_supports_get_conditions_financieres($bien);
        $lignesCopro = mbi_supports_get_copropriete_lignes($bien);
        $rowH   = 4.7;
        $gapCol = 6;
        $colW   = ($pw - $gapCol) / 2;

        $renderListe = function (string $titre, array $lignes, float $x0, float $yTop)
                       use ($pdf, $colW, $rowH, $cS): float {
            if (empty($lignes)) return $yTop;
            $pdf->SetFont('dejavusans', 'B', 8);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY($x0, $yTop);
            $pdf->Cell($colW, 4, mb_strtoupper($titre, 'UTF-8'), 0, 1, 'L');
            $y = $yTop + 5;
            foreach ($lignes as $i => $cf) {
                [$lab, $val] = $cf;
                $yl = $y + $i * $rowH;
                $pdf->SetFont('dejavusans', '', 9);
                $pdf->SetTextColor(200, 206, 220);
                $pdf->SetXY($x0, $yl);
                $pdf->Cell($colW * 0.55, 4.4, $lab . ' :', 0, 0, 'L');
                $pdf->SetFont('dejavusans', 'B', 9);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetXY($x0 + $colW * 0.55, $yl);
                $pdf->Cell($colW * 0.45, 4.4, $val, 0, 0, 'R');
            }
            return $y + count($lignes) * $rowH;
        };

        $titreCF = (($infoPrix['type'] ?? '') === 'location') ? 'Conditions ALUR' : 'Conditions';
        $yG = $renderListe($titreCF,      $lignesCF,    $px,                   $py);
        $yD = $renderListe('Copropriété', $lignesCopro, $px + $colW + $gapCol, $py);
        $py = max($yG, $yD) + 4;

        // --- MINI-CARDS caracs (translucides sur navy) ---
        $surf  = mbi_supports_get_surface($bien);
        $nbPcs = $bien['nb_pieces'] ?? $bien['nombre_pieces'] ?? null;
        $etage = $bien['etage'] ?? null;
        $surfTxt = $surf !== null
            ? rtrim(rtrim(number_format($surf, 2, ',', ' '), '0'), ',')
            : null;
        $caracs = [];
        if ($surfTxt !== null)                        $caracs[] = ['Surface', $surfTxt];
        if ($nbPcs)                                   $caracs[] = ['Pièces', (string)$nbPcs];
        if ($etage !== null && (string)$etage !== '') $caracs[] = ['Étage', (string)$etage];

        if (!empty($caracs) && $py < $photoH - 70) {
            $nbC = count($caracs);
            $cellW = ($pw - (($nbC - 1) * 4)) / $nbC;
            $cellH = 34;
            $padInt = 6;
            foreach ($caracs as $i => $it) {
                $x = $px + ($i * ($cellW + 4));
                $pdf->SetAlpha(0.22);
                $pdf->SetFillColor(15, 23, 42);
                $pdf->RoundedRect($x + 1.2, $py + 1.8, $cellW, $cellH, 5.0, '1111', 'F');
                $pdf->SetAlpha(0.16);
                $pdf->SetFillColor(255, 255, 255);
                $pdf->RoundedRect($x, $py, $cellW, $cellH, 5.0, '1111', 'F');
                $pdf->SetAlpha(1.0);
                $pdf->SetFont('dejavusans', 'B', 10);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetXY($x + $padInt, $py + 3.2);
                $pdf->Cell($cellW - $padInt * 2, 5, mb_strtoupper($it[0], 'UTF-8'), 0, 0, 'L');
                $val = (string)$it[1];
                $valLen = mb_strlen($val, 'UTF-8');
                $valSize = $valLen <= 2 ? 50 : ($valLen <= 3 ? 42 : ($valLen <= 4 ? 34 : ($valLen <= 5 ? 29 : 24)));
                $pdf->SetFont('dejavusans', 'B', $valSize);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetXY($x + $padInt, $py);
                $pdf->Cell($cellW - $padInt * 2, $cellH, $val, 0, 0, 'R');
            }
            $py += $cellH + 6;
        }

        // --- ANNEXES ---
        $annexes = mbi_supports_get_annexes($bien);
        if (!empty($annexes) && $py < $photoH - 60) {
            $py = mbi_supports_tpl_bloc_annexes($pdf, $px, $py, $pw, $annexes, $cS, [223, 227, 238], $photoH - 50) + 2;
        }

        // --- L'ANNONCE (descriptif, parfois tres long) - remplit l'espace ---
        $dpe = strtoupper(trim((string)($bien['dpe_classe'] ?? $bien['dpe'] ?? '')));
        $ges = strtoupper(trim((string)($bien['ges_classe'] ?? $bien['ges'] ?? '')));
        $dpeVal = (float)($bien['dpe_valeur'] ?? 0);
        $gesVal = (float)($bien['ges_valeur'] ?? 0);

        $dpeH = 40;
        $dpeY = $photoH - $dpeH - 8;
        $descAnnonce = mbi_supports_get_description_annonce($bien);
        if ($descAnnonce !== '' && $py < $dpeY - 14) {
            mbi_supports_tpl_bloc_annonce($pdf, $px, $py, $pw, ($dpeY - 4) - $py, $descAnnonce, $cS, [223, 227, 238]);
        } elseif ($descAnnonce === '' && $py < $dpeY) {
            $dpeY = $py; // pas d'annonce : DPE remonte (pas de trou)
        }

        // --- DPE / GES (ancre bas) ---
        mbi_supports_tpl_dpe_ges(
            $pdf, $px, $dpeY, $pw, $dpe, $ges, [200, 206, 220],
            $dpeVal > 0 ? $dpeVal : null,
            $gesVal > 0 ? $gesVal : null
        );

        // --- PHOTO DU NÉGOCIATEUR : avatar rond, bas-droite du panneau (au-dessus du nom au pied) ---
        $nego = $ctx['negociateur'] ?? [];
        $negoPhotoRel = trim((string)($nego['photo_url'] ?? $nego['avatar_url'] ?? ''));
        if ($negoPhotoRel !== '') {
            $rootA = realpath(__DIR__ . '/../..');
            $negoPhoto = null;
            if (preg_match('#^https?://#i', $negoPhotoRel))                         $negoPhoto = $negoPhotoRel;
            elseif (is_file($negoPhotoRel))                                          $negoPhoto = $negoPhotoRel;
            elseif ($rootA && is_file($rootA . '/' . ltrim($negoPhotoRel, '/')))     $negoPhoto = $rootA . '/' . ltrim($negoPhotoRel, '/');
            if ($negoPhoto !== null) {
                $pd = 26.0; $pxp = $pageW - 16 - $pd; $pyp = $photoH - $pd - 3;
                try {
                    $pdf->SetFillColor(255, 255, 255);
                    $pdf->Circle($pxp + $pd / 2, $pyp + $pd / 2, $pd / 2 + 1.2, 0, 360, 'F');
                    $pdf->StartTransform();
                    $pdf->Circle($pxp + $pd / 2, $pyp + $pd / 2, $pd / 2, 0, 360, 'CNZ');
                    $pdf->Image($negoPhoto, $pxp, $pyp, $pd, $pd, '', '', '', false, 300, '', false, false, 0, 'CM', false, false);
                    $pdf->StopTransform();
                    $pdf->SetDrawColor($cS[0], $cS[1], $cS[2]);
                    $pdf->SetLineWidth(0.8);
                    $pdf->Circle($pxp + $pd / 2, $pyp + $pd / 2, $pd / 2 + 1.2, 0, 360, 'D');
                    $pdf->SetLineWidth(0.2);
                } catch (Throwable) { $pdf->StopTransform(); }
            }
        }

        // --- 5. PIED NAVY fin ---
        mbi_supports_tpl_pied_landscape($pdf, $ctx, $cP, $cS, $pageW, $pageH, $piedH);

        return $pdf;
    }
}
