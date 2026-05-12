<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Layout : cinema_coin (A3 LANDSCAPE — V3 rounded+shadow+titreXL)
 * ═══════════════════════════════════════════════════════════════════════
 *
 *   1. Photo héro PLEIN CADRE (full bleed) en fond
 *   2. Bandeau réf / ville haut-gauche dans une carte arrondie translucide
 *   3. Carte info à droite (rounded + shadow + watermark + trait or signature)
 *   4. Mosaïque thumbs en bas-gauche (rounded + shadow + cadre or)
 *   5. Pied navy fin avec mentions agence
 *
 * Idéal : 1 photo dominante, biens premium, accroche courte.
 * ═══════════════════════════════════════════════════════════════════════
 */

if (!function_exists('mbi_supports_layout_cinema_coin_build')) {

    function mbi_supports_layout_cinema_coin_build(array $ctx): TCPDF
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
        $iaAtouts    = is_array($iaRed['atouts'] ?? null) ? $iaRed['atouts'] : [];

        $cP = $style['rgb_primaire']   ?? [36, 59, 92];
        $cS = $style['rgb_secondaire'] ?? [212, 160, 71];
        $cT = $style['rgb_texte']      ?? [31, 41, 55];

        $angle    = (string)($ctx['angle'] ?? 'generique');
        $anglePal = mbi_supports_tpl_angle_palette($angle, $cS);
        $cAngle   = $anglePal['rgb'];
        $libAngle = $anglePal['libelle'];

        // Couleur titre XL : accent angle si défini, sinon navy
        $cTitre = ($angle !== 'generique' && $libAngle !== '') ? $cAngle : $cP;

        $nbPhotos = (int)($ctx['nb_photos'] ?? 3);
        if ($nbPhotos < 1) $nbPhotos = 1;
        if ($nbPhotos > 3) $nbPhotos = 3;

        $pdf = new TCPDF('L', 'mm', 'A3', true, 'UTF-8');
        $pdf->SetCreator('MaBoxImmo - Ma Box Communication');
        $pdf->SetTitle('Affiche vitrine A3 H — cinema coin');
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->AddPage();

        $pageW = 420;
        $pageH = 297;
        $piedH = 18;
        $photoH = $pageH - $piedH;

        // ─── 1. PHOTO HÉRO PLEIN CADRE (full bleed, pas de rounded) ───
        $hero = mbi_supports_photo_hero($photos, $ctx['photo_hero_id_suggestion'] ?? null);
        $heroPath = $hero ? mbi_supports_resoudre_photo_path($hero) : null;

        if ($heroPath !== null) {
            try {
                $pdf->Image($heroPath, 0, 0, $pageW, $photoH, '', '', '', false, 250, '', false, false, 0, 'CM', false, false);
            } catch (Throwable) {
                mbi_supports_tpl_v2_placeholder($pdf, 0, 0, $pageW, $photoH, $cP);
            }
        } else {
            mbi_supports_tpl_v2_placeholder($pdf, 0, 0, $pageW, $photoH, $cP);
        }

        // Voile droite (lisibilité carte info)
        $pdf->SetAlpha(0.18);
        $pdf->SetFillColor(0, 0, 0);
        $pdf->Rect(220, 0, $pageW - 220, $photoH, 'F');
        $pdf->SetAlpha(1.0);

        // ─── 2. CARTE TITRE ANNONCE haut-gauche (rounded translucide) ───
        // Priorité : titre annonce > référence bien
        $titreAnnonce = trim((string)($bien['_annonce_titre'] ?? ''));
        $ref = (string)($bien['reference_bien'] ?? ('#' . ($bien['id'] ?? '')));
        $loc = trim((string)($bien['code_postal'] ?? '') . ' ' . ($bien['ville'] ?? ''));
        $refSous = trim('Réf ' . $ref . ($loc !== '' ? '  ·  ' . $loc : ''));

        $cardW = 240;
        $cardH = $titreAnnonce !== '' ? 44 : 28;
        mbi_supports_tpl_card_round_shadow($pdf, 18, 18, $cardW, $cardH, 6.0, [10, 18, 32], 0.65, true, $cS);

        if ($titreAnnonce !== '') {
            // Adapte la hauteur de la carte pour titre 22pt
            // (cardH déjà calculé selon présence titre, mais on agrandit ici)
            $pdf->SetAlpha(0.45);
            $pdf->SetFont('dejavusans', 'B', 22);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY(28 + 1.2, 22 + 1.6);
            $pdf->MultiCell($cardW - 20, 9, mb_substr($titreAnnonce, 0, 90, 'UTF-8'), 0, 'L');
            $pdf->SetAlpha(1.0);
            $pdf->SetFont('dejavusans', 'B', 22);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY(28, 22);
            $pdf->MultiCell($cardW - 20, 9, mb_substr($titreAnnonce, 0, 90, 'UTF-8'), 0, 'L');
            $pdf->SetFont('dejavusans', '', 9);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY(28, 18 + $cardH - 8);
            $pdf->Cell($cardW - 20, 4, mb_strtoupper($refSous, 'UTF-8'), 0, 0, 'L');
        } else {
            $pdf->SetFont('dejavusans', 'B', 13);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY(28, 24);
            $pdf->Cell($cardW - 20, 6, mb_strtoupper($refSous, 'UTF-8'), 0, 0, 'L');

            $typeBien = trim((string)($bien['type_bien_libelle'] ?? $bien['type'] ?? ''));
            if ($typeBien !== '') {
                $pdf->SetFont('dejavusans', '', 9);
                $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
                $pdf->SetXY(28, 32);
                $pdf->Cell($cardW - 20, 5, mb_strtoupper($typeBien, 'UTF-8'), 0, 0, 'L');
            }
        }

        // ─── 3. CARTE INFO TRANSLUCIDE coin droit (rounded + shadow + watermark) ───
        $cx = 235;
        $cy = 24;
        $cw = 170;
        $ch = 240;
        $cr = 8.0;

        mbi_supports_tpl_card_round_shadow($pdf, $cx, $cy, $cw, $ch, $cr, [255, 255, 255], 0.94, true, $cS);

        // Watermark puzzle filigrane (clip arrondi)
        $watermarkPath = __DIR__ . '/../../images/mbi_affiche_watermark.jpg';
        if (is_file($watermarkPath) && is_readable($watermarkPath)) {
            try {
                $pdf->StartTransform();
                $pdf->RoundedRect($cx, $cy, $cw, $ch, $cr, '1111', 'CNZ');
                $pdf->SetAlpha(0.13);
                $pdf->Image($watermarkPath, $cx, $cy, $cw, $ch, '', '', '', false, 250, '', false, false, 0, 'CM', false, false);
                $pdf->SetAlpha(1.0);
                $pdf->StopTransform();
            } catch (Throwable) {
                $pdf->SetAlpha(1.0);
            }
        }

        $px = $cx + 12;
        $pw = $cw - 22;
        $py = $cy + 10;

        // Badge angle (rounded)
        if ($angle !== 'generique' && $libAngle !== '') {
            mbi_supports_tpl_card_round_shadow($pdf, $px, $py, $pw, 9, 4.5, $cAngle, 1.0, true);
            $pdf->SetFont('dejavusans', 'B', 9);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY($px, $py);
            $pdf->Cell($pw, 9, mb_strtoupper($libAngle, 'UTF-8'), 0, 0, 'C');
            $py += 9 + 6;
        }

        // Coup de coeur (rounded)
        $scoreNum = $score ? (int)($score['score'] ?? 0) : 0;
        if ($scoreNum >= 80) {
            mbi_supports_tpl_card_round_shadow($pdf, $px, $py, $pw, 8, 4.0, $cS, 1.0, true);
            $pdf->SetFont('dejavusans', 'B', 9);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY($px, $py);
            $pdf->Cell($pw, 8, '★  COUP DE CŒUR', 0, 0, 'C');
            $py += 8 + 5;
        }

        // ─── TITRE HEADLINE XL (type · pièces · ville) avec ombre + couleur d'accent ───
        $titreH = mbi_supports_tpl_titre_headline($bien);
        $pdf->SetAlpha(0.35);
        $pdf->SetFont('dejavusans', 'B', 19);
        $pdf->SetTextColor(10, 18, 32);
        $pdf->SetXY($px + 1.4, $py + 1.8);
        $pdf->MultiCell($pw, 7.5, $titreH, 0, 'L');
        $pdf->SetAlpha(1.0);
        $pdf->SetFont('dejavusans', 'B', 19);
        $pdf->SetTextColor($cTitre[0], $cTitre[1], $cTitre[2]);
        $pdf->SetXY($px, $py);
        $pdf->MultiCell($pw, 7.5, $titreH, 0, 'L');
        $py = $pdf->GetY() + 2;

        // ─── ACCROCHE italique sous-titre ───
        $accroche = trim((string)($bien['_accroche'] ?? ''));
        if ($accroche === '' && $iaAccroche !== '') $accroche = $iaAccroche;
        if ($accroche !== '' && !str_starts_with($accroche, 'Bien créé')) {
            $pdf->SetFont('dejavusans', 'I', 12);
            $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
            $pdf->SetXY($px, $py);
            $pdf->MultiCell($pw, 5.5, '« ' . $accroche . ' »', 0, 'L');
            $py = $pdf->GetY() + 4;
        } else {
            $py += 2;
        }

        // ─── PRIX/LOYER XL or avec ombre — selon type_transaction ───
        $infoPrix = mbi_supports_get_prix_ou_loyer($bien);
        mbi_supports_tpl_text_shadow(
            $pdf, $px, $py, $pw, 16,
            mbi_supports_format_prix_complet($infoPrix),
            $cS, 'dejavusans', 'B', 30, 'L', false, 1.6, 2.2
        );
        $py += 16;

        // ─── Conditions financières (location : loyer + charges + hono + dépôt) ───
        $lignesCF = mbi_supports_get_conditions_financieres($bien);
        if (!empty($lignesCF)) {
            $pdf->SetFont('dejavusans', 'B', 8);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY($px, $py);
            $pdf->Cell($pw, 4, mb_strtoupper(($infoPrix['type'] ?? '') === 'location' ? 'Conditions ALUR' : 'Conditions', 'UTF-8'), 0, 1, 'L');
            $py += 4.5;

            // 1 colonne dans le coin droit (cinéma) — labels gris + valeurs navy
            $pdf->SetFont('dejavusans', '', 9);
            foreach ($lignesCF as $cf) {
                if ($py > $cy + $ch - 60) break; // garde de la place pour DPE + atouts
                [$lab, $val] = $cf;
                $pdf->SetTextColor(110, 116, 130);
                $pdf->SetXY($px, $py);
                $pdf->Cell($pw * 0.55, 4, $lab . ' :', 0, 0, 'L');
                $pdf->SetFont('dejavusans', 'B', 9);
                $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
                $pdf->SetXY($px + $pw * 0.55, $py);
                $pdf->Cell($pw * 0.45, 4, $val, 0, 0, 'R');
                $pdf->SetFont('dejavusans', '', 9);
                $py += 4.5;
            }
        }
        $py += 4;

        // Trait or fin séparateur
        $pdf->SetDrawColor($cS[0], $cS[1], $cS[2]);
        $pdf->SetLineWidth(0.8);
        $pdf->Line($px, $py, $px + 35, $py);
        $pdf->SetLineWidth(0.2);
        $py += 6;

        // Caracs principales (mini-cards rounded)
        $surf  = mbi_supports_get_surface($bien);
        $nbPcs = $bien['nb_pieces'] ?? $bien['nombre_pieces'] ?? null;
        $etage = $bien['etage'] ?? null;
        $expo  = $bien['exposition'] ?? $bien['orientation'] ?? null;

        // Valeurs SANS unité (label porte déjà l'unité) → permet très gros chiffres
        $surfTxt = $surf !== null
            ? rtrim(rtrim(number_format($surf, 2, ',', ' '), '0'), ',')
            : null;
        $caracs = [];
        if ($surfTxt !== null)         $caracs[] = ['M²', $surfTxt];
        if ($nbPcs)                    $caracs[] = ['Pces', (string)$nbPcs];
        if ($etage !== null && (string)$etage !== '') $caracs[] = ['Étage', (string)$etage];

        if (!empty($caracs)) {
            $nbC = count($caracs);
            $cellW = ($pw - (($nbC - 1) * 3)) / $nbC;
            $cellH = 36;
            foreach ($caracs as $i => $it) {
                $x = $px + ($i * ($cellW + 3));
                mbi_supports_tpl_carac_mini(
                    $pdf, $x, $py, $cellW, $cellH,
                    $it[0], $it[1], $cP, $cT,
                    [248, 250, 252], null, $cP, true,
                    10.0, 44.0
                );
            }
            $py += $cellH + 6;
        }

        // Étiquettes DPE / GES officielles (barre 7 segments A→G + valeur réelle)
        $dpe = strtoupper(trim((string)($bien['dpe_classe'] ?? $bien['dpe'] ?? '')));
        $ges = strtoupper(trim((string)($bien['ges_classe'] ?? $bien['ges'] ?? '')));
        $dpeVal = (float)($bien['dpe_valeur'] ?? 0);
        $gesVal = (float)($bien['ges_valeur'] ?? 0);
        mbi_supports_tpl_dpe_ges(
            $pdf, $px, $py, $pw, $dpe, $ges, null,
            $dpeVal > 0 ? $dpeVal : null,
            $gesVal > 0 ? $gesVal : null
        );
        $py += 36;

        // Atouts
        $pointsForts = $iaAtouts;
        if (empty($pointsForts) && $score && !empty($score['points_forts_json'])) {
            $pointsForts = json_decode((string)$score['points_forts_json'], true) ?: [];
        }
        if (!empty($pointsForts)) {
            foreach (array_slice($pointsForts, 0, 3) as $pf) {
                if ($py > $cy + $ch - 10) break;
                $pdf->SetXY($px, $py);
                $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
                $pdf->SetFont('dejavusans', 'B', 11);
                $pdf->Cell(6, 6, '✓', 0, 0, 'L');
                $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
                $pdf->SetFont('dejavusans', '', 10);
                $pdf->SetXY($px + 6, $py);
                $pdf->MultiCell($pw - 6, 5, mb_substr((string)$pf, 0, 95, 'UTF-8'), 0, 'L');
                $py = $pdf->GetY() + 2;
            }
        }

        $mentionDpe = '';
        if (!empty($mentionsTextes['dpe_en_cours']))    $mentionDpe = $mentionsTextes['dpe_en_cours'];
        if (!empty($mentionsTextes['dpe_non_soumis']))  $mentionDpe = $mentionsTextes['dpe_non_soumis'];
        if ($mentionDpe !== '' && $py < $cy + $ch - 10) {
            $pdf->SetFont('dejavusans', 'I', 7.5);
            $pdf->SetTextColor(120, 80, 30);
            $pdf->SetXY($px, $cy + $ch - 9);
            $pdf->MultiCell($pw, 3.5, $mentionDpe, 0, 'L');
        }

        // ─── 4. MOSAÏQUE THUMBS bas-gauche (rounded + bordure or + shadow) ───
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

        if ($nbPhotos > 1 && !empty($autres)) {
            $tw = 44; $th = 32; $tg = 6;
            $tx0 = 22;
            $ty  = $photoH - $th - 14;
            foreach ($autres as $i => $p) {
                $tpath = mbi_supports_resoudre_photo_path($p);
                $tx = $tx0 + $i * ($tw + $tg);
                mbi_supports_tpl_image_round($pdf, $tpath, $tx, $ty, $tw, $th, 4.0, true, $cS, 0.7);
            }
        }

        // ─── 5. PIED NAVY fin ───
        mbi_supports_tpl_pied_landscape($pdf, $ctx, $cP, $cS, $pageW, $pageH, $piedH);

        return $pdf;
    }
}
