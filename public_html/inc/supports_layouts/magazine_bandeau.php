<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Layout : magazine_bandeau (A3 LANDSCAPE — V3 rounded+shadow+titreXL)
 * ═══════════════════════════════════════════════════════════════════════
 *
 *   1. Mosaïque photos en haut (héro grand gauche + thumbs droite empilées,
 *      toutes avec coins arrondis + bordure or + ombres portées)
 *   2. Bandeau bas navy avec titre XL coloré ombre portée + prix XL or
 *      + mini-cards caracs rounded + DPE/GES rounded + atouts
 *   3. Pied navy fin
 *
 * Idéal : description longue/riche, plusieurs photos à valoriser,
 *   biens famille / primo-accédant.
 * ═══════════════════════════════════════════════════════════════════════
 */

if (!function_exists('mbi_supports_layout_magazine_bandeau_build')) {

    function mbi_supports_layout_magazine_bandeau_build(array $ctx): TCPDF
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
        $iaAccroche  = trim((string)($iaRed['accroche']   ?? ''));
        $iaParagraph = trim((string)($iaRed['paragraphe'] ?? ''));
        $iaAtouts    = is_array($iaRed['atouts'] ?? null) ? $iaRed['atouts'] : [];

        $cP = $style['rgb_primaire']   ?? [36, 59, 92];
        $cS = $style['rgb_secondaire'] ?? [212, 160, 71];
        $cT = $style['rgb_texte']      ?? [31, 41, 55];

        $angle    = (string)($ctx['angle'] ?? 'generique');
        $anglePal = mbi_supports_tpl_angle_palette($angle, $cS);
        $cAngle   = $anglePal['rgb'];
        $libAngle = $anglePal['libelle'];

        // Sur fond navy : titre en or par défaut, ou couleur d'accent angle (claire) si non générique
        // On force une teinte claire pour tous les angles (sur fond navy il faut du contraste)
        $cTitre = $cS; // or pour bonne lisibilité sur navy

        $nbPhotos = (int)($ctx['nb_photos'] ?? 3);
        if ($nbPhotos < 1) $nbPhotos = 1;
        if ($nbPhotos > 3) $nbPhotos = 3;

        $pdf = new TCPDF('L', 'mm', 'A3', true, 'UTF-8');
        $pdf->SetCreator('MaBoxImmo - Ma Box Communication');
        $pdf->SetTitle('Affiche vitrine A3 H — magazine bandeau');
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->AddPage();

        $pageW   = 420;
        $pageH   = 297;
        $piedH   = 18;
        $photoZH = 162;
        $bandY   = $photoZH + 2;
        $bandH   = $pageH - $piedH - $bandY;

        // ─── 1. ZONE PHOTOS HAUT (mosaïque rounded) ───
        $hero = mbi_supports_photo_hero($photos, $ctx['photo_hero_id_suggestion'] ?? null);
        $heroPath = $hero ? mbi_supports_resoudre_photo_path($hero) : null;

        $heroId = (int)($hero['id'] ?? 0);
        $idsManuel = $ctx['photos_ids_secondaires'] ?? null;
        $autres = [];
        if (is_array($idsManuel) && !empty($idsManuel)) {
            $byId = [];
            foreach ($photos as $p) $byId[(int)($p['id'] ?? 0)] = $p;
            foreach ($idsManuel as $idP) {
                $idP = (int)$idP;
                if ($idP <= 0 || $idP === $heroId || !isset($byId[$idP])) continue;
                $autres[] = $byId[$idP];
                if (count($autres) >= ($nbPhotos - 1)) break;
            }
        } else {
            foreach ($photos as $p) {
                if ((int)($p['id'] ?? 0) === $heroId) continue;
                $autres[] = $p;
                if (count($autres) >= ($nbPhotos - 1)) break;
            }
        }

        $nbThumbs = count($autres);

        // Padding photos zone
        $padX = 14;
        $padTop = 14;
        $gap = 6;

        if ($nbPhotos === 1 || $nbThumbs === 0) {
            // Mode héro plein large rounded
            $hpW = $pageW - 2 * $padX;
            $hpH = $photoZH - $padTop - 4;
            mbi_supports_tpl_image_round($pdf, $heroPath, $padX, $padTop, $hpW, $hpH, 8.0, true, $cS, 0.6);
        } else {
            // Mode mosaïque
            $hpW = 268;
            $hpH = $photoZH - $padTop - 4;
            mbi_supports_tpl_image_round($pdf, $heroPath, $padX, $padTop, $hpW, $hpH, 8.0, true, $cS, 0.6);

            // Thumbs colonne droite
            $tx = $padX + $hpW + $gap;
            $tw = $pageW - $padX - $tx;
            if ($nbThumbs === 1) {
                $tpath = mbi_supports_resoudre_photo_path($autres[0]);
                mbi_supports_tpl_image_round($pdf, $tpath, $tx, $padTop, $tw, $hpH, 6.0, true, $cS, 0.5);
            } else {
                // 2 thumbs empilés
                $thH = ($hpH - $gap) / 2;
                foreach ($autres as $i => $p) {
                    $ty = $padTop + $i * ($thH + $gap);
                    $tpath = mbi_supports_resoudre_photo_path($p);
                    mbi_supports_tpl_image_round($pdf, $tpath, $tx, $ty, $tw, $thH, 6.0, true, $cS, 0.5);
                }
            }
        }

        // Carte titre annonce haut-gauche en overlay (rounded translucide noir)
        // Priorité : titre annonce > référence bien
        $titreAnnonce = trim((string)($bien['_annonce_titre'] ?? ''));
        $ref = (string)($bien['reference_bien'] ?? ('#' . ($bien['id'] ?? '')));
        $loc = trim((string)($bien['code_postal'] ?? '') . ' ' . ($bien['ville'] ?? ''));
        $refSous = trim('Réf ' . $ref . ($loc !== '' ? '  ·  ' . $loc : ''));

        $cardW = 260;
        $cardH = $titreAnnonce !== '' ? 32 : 28;
        mbi_supports_tpl_card_round_shadow($pdf, $padX + 6, $padTop + 6, $cardW, $cardH, 5.0, [10, 18, 32], 0.65, true, $cS);

        if ($titreAnnonce !== '') {
            // Titre annonce en grand
            $pdf->SetFont('dejavusans', 'B', 14);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY($padX + 14, $padTop + 10);
            $pdf->MultiCell($cardW - 16, 6, mb_substr($titreAnnonce, 0, 70, 'UTF-8'), 0, 'L');
            // Réf en sous-titre or
            $pdf->SetFont('dejavusans', '', 8.5);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY($padX + 14, $padTop + 6 + $cardH - 7);
            $pdf->Cell($cardW - 16, 4, mb_strtoupper($refSous, 'UTF-8'), 0, 0, 'L');
        } else {
            // Fallback : réf en grand
            $pdf->SetFont('dejavusans', 'B', 13);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY($padX + 14, $padTop + 12);
            $pdf->Cell($cardW - 16, 5, mb_strtoupper($refSous, 'UTF-8'), 0, 0, 'L');

            $typeBien = trim((string)($bien['type_bien_libelle'] ?? $bien['type'] ?? ''));
            if ($typeBien !== '') {
                $pdf->SetFont('dejavusans', '', 9);
                $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
                $pdf->SetXY($padX + 14, $padTop + 20);
                $pdf->Cell($cardW - 16, 5, mb_strtoupper($typeBien, 'UTF-8'), 0, 0, 'L');
            }
        }

        // Badge angle : positionné sous la carte titre/réf (jamais dans les thumbs)
        if ($angle !== 'generique' && $libAngle !== '') {
            $bw = 78;
            $bh = 12;
            $bx = $padX + 6;
            $by = $padTop + 6 + $cardH + 4;
            mbi_supports_tpl_card_round_shadow($pdf, $bx, $by, $bw, $bh, 5.0, $cAngle, 1.0, true);
            $pdf->SetFont('dejavusans', 'B', 10);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY($bx, $by);
            $pdf->Cell($bw, $bh, mb_strtoupper($libAngle, 'UTF-8'), 0, 0, 'C');
        }

        // ─── 2. BANDEAU BAS NAVY ───
        $pdf->SetFillColor($cP[0], $cP[1], $cP[2]);
        $pdf->Rect(0, $bandY, $pageW, $bandH, 'F');

        // Watermark filigrane sur le bandeau (alpha bas)
        $watermarkPath = __DIR__ . '/../../images/mbi_affiche_watermark.jpg';
        if (is_file($watermarkPath) && is_readable($watermarkPath)) {
            try {
                $pdf->SetAlpha(0.07);
                $pdf->Image($watermarkPath, 0, $bandY, $pageW, $bandH, '', '', '', false, 250, '', false, false, 0, 'CM', false, false);
                $pdf->SetAlpha(1.0);
            } catch (Throwable) {
                $pdf->SetAlpha(1.0);
            }
        }

        // Trait or fin tout en haut du bandeau
        $pdf->SetFillColor($cS[0], $cS[1], $cS[2]);
        $pdf->Rect(0, $bandY, $pageW, 1.5, 'F');

        // Padding bandeau
        $bpx = 22;
        $bpw = $pageW - 2 * $bpx;
        $bpy = $bandY + 12;

        $colLW = $bpw * 0.60;
        $colRW = $bpw * 0.36;
        $colRX = $bpx + $colLW + ($bpw * 0.04);

        // ── Colonne GAUCHE : TITRE XL + accroche sous-titre + prix + descriptif ──

        // TITRE XL HEADLINE (type · pièces · ville) — gros, ombre portée, or vif
        $titreH = mbi_supports_tpl_titre_headline($bien);
        $pdf->SetAlpha(0.55);
        $pdf->SetFont('dejavusans', 'B', 30);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY($bpx + 2.0, $bpy + 2.5);
        $pdf->MultiCell($colLW, 11, $titreH, 0, 'L');
        $pdf->SetAlpha(1.0);
        $pdf->SetFont('dejavusans', 'B', 30);
        $pdf->SetTextColor($cTitre[0], $cTitre[1], $cTitre[2]);
        $pdf->SetXY($bpx, $bpy);
        $pdf->MultiCell($colLW, 11, $titreH, 0, 'L');
        $cy = $pdf->GetY() + 3;

        // ACCROCHE en italique sous-titre (taille intermédiaire blanche)
        $accroche = trim((string)($bien['_accroche'] ?? ''));
        if ($accroche === '' && $iaAccroche !== '') $accroche = $iaAccroche;
        if ($accroche !== '' && !str_starts_with($accroche, 'Bien créé')) {
            $pdf->SetFont('dejavusans', 'I', 14);
            $pdf->SetTextColor(232, 234, 245);
            $pdf->SetXY($bpx, $cy);
            $pdf->MultiCell($colLW, 6.5, '« ' . $accroche . ' »', 0, 'L');
            $cy = $pdf->GetY() + 3;
        }

        // Prix XL or avec ombre
        $prix = mbi_supports_get_prix($bien);
        mbi_supports_tpl_text_shadow(
            $pdf, $bpx, $cy, $colLW, 17,
            mbi_supports_format_prix($prix),
            [255, 232, 168], 'dejavusans', 'B', 42, 'L', false, 1.8, 2.4
        );
        $cy += 17;

        $charge = mbi_supports_tpl_charge_honoraires($bien);
        if ($charge !== '') {
            $pdf->SetFont('dejavusans', '', 9);
            $pdf->SetTextColor(220, 222, 230);
            $pdf->SetXY($bpx, $cy);
            $pdf->Cell($colLW, 4, $charge, 0, 1, 'L');
            $cy += 4;
        }
        $cy += 5;

        // Descriptif L'ANNONCE
        $descCommerciale = (string)($bien['_annonce_description'] ?? '');
        if ($descCommerciale === '' && $iaParagraph !== '') $descCommerciale = $iaParagraph;
        if ($descCommerciale === '') {
            $descCommerciale = (string)($bien['description'] ?? $bien['descriptif'] ?? '');
        }
        if ($descCommerciale !== '') {
            $pdf->SetFont('dejavusans', 'B', 10);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY($bpx, $cy);
            $pdf->Cell($colLW, 5, mb_strtoupper("L'annonce", 'UTF-8'), 0, 1, 'L');
            $cy += 5.5;

            // Texte de l'annonce : 13.5pt pour grosse lisibilité vitrine
            $pdf->SetFont('dejavusans', '', 13.5);
            $pdf->SetTextColor(232, 234, 245);
            $pdf->SetXY($bpx, $cy);
            $extrait = mb_substr($descCommerciale, 0, 420, 'UTF-8');
            if (mb_strlen($descCommerciale, 'UTF-8') > 420) $extrait .= '...';
            $pdf->MultiCell($colLW, 6.2, $extrait, 0, 'J');
        }

        // ── Colonne DROITE : caracs + DPE + atouts ────────────────────
        $ry = $bpy;

        $surf  = mbi_supports_get_surface($bien);
        $nbPcs = $bien['nb_pieces'] ?? $bien['nombre_pieces'] ?? null;
        $etage = $bien['etage'] ?? null;
        $expo  = $bien['exposition'] ?? $bien['orientation'] ?? null;

        $caracs = [];
        if ($surf !== null)            $caracs[] = ['M²', mbi_supports_format_surface($surf)];
        if ($nbPcs)                    $caracs[] = ['Pces', (string)$nbPcs];
        if ($etage !== null && (string)$etage !== '') $caracs[] = ['Étage', (string)$etage];
        if ($expo)                     $caracs[] = ['Expo', mb_strtoupper(mb_substr((string)$expo, 0, 5, 'UTF-8'), 'UTF-8')];

        if (!empty($caracs)) {
            $nbC = count($caracs);
            $cellW = ($colRW - (($nbC - 1) * 4)) / $nbC;
            $cellH = 30;
            foreach ($caracs as $i => $it) {
                $x = $colRX + ($i * ($cellW + 4));
                // Mini bloc bg blanc 14% rounded + ombre légère
                $pdf->SetAlpha(0.18);
                $pdf->SetFillColor(15, 23, 42);
                $pdf->RoundedRect($x + 1.0, $ry + 1.5, $cellW, $cellH, 4.0, '1111', 'F');
                $pdf->SetAlpha(0.14);
                $pdf->SetFillColor(255, 255, 255);
                $pdf->RoundedRect($x, $ry, $cellW, $cellH, 4.0, '1111', 'F');
                $pdf->SetAlpha(1.0);

                $pdf->SetFont('dejavusans', '', 9);
                $pdf->SetTextColor(200, 206, 220);
                $pdf->SetXY($x + 5, $ry + 3);
                $pdf->Cell($cellW - 10, 5, mb_strtoupper($it[0], 'UTF-8'), 0, 0, 'L');
                $pdf->SetFont('dejavusans', 'B', 22);
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetXY($x + 5, $ry + 9);
                $pdf->Cell($cellW - 10, $cellH - 10, $it[1], 0, 0, 'L');
            }
            $ry += $cellH + 6;
        }

        // DPE / GES rounded shadow (couleurs claires sur fond navy via header)
        $dpe = strtoupper(trim((string)($bien['dpe_classe'] ?? $bien['dpe'] ?? '')));
        $ges = strtoupper(trim((string)($bien['ges_classe'] ?? $bien['ges'] ?? '')));
        if ($dpe !== '' || $ges !== '') {
            mbi_supports_tpl_dpe_ges($pdf, $colRX, $ry, $colRW, $dpe, $ges, [200, 206, 220]);
            $ry += 26 + 4;
        }

        // Atouts ✓
        $pointsForts = $iaAtouts;
        if (empty($pointsForts) && $score && !empty($score['points_forts_json'])) {
            $pointsForts = json_decode((string)$score['points_forts_json'], true) ?: [];
        }
        if (!empty($pointsForts)) {
            foreach (array_slice($pointsForts, 0, 3) as $pf) {
                if ($ry > $bandY + $bandH - 14) break;
                $pdf->SetXY($colRX, $ry);
                $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
                $pdf->SetFont('dejavusans', 'B', 11);
                $pdf->Cell(6, 5, '✓', 0, 0, 'L');
                $pdf->SetTextColor(255, 255, 255);
                $pdf->SetFont('dejavusans', '', 9);
                $pdf->SetXY($colRX + 6, $ry);
                $pdf->MultiCell($colRW - 6, 4.2, mb_substr((string)$pf, 0, 95, 'UTF-8'), 0, 'L');
                $ry = $pdf->GetY() + 1.5;
            }
        }

        // ─── 3. PIED NAVY fin ───
        mbi_supports_tpl_pied_landscape($pdf, $ctx, $cP, $cS, $pageW, $pageH, $piedH);

        return $pdf;
    }
}
