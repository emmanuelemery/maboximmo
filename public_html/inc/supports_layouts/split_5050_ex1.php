<?php
declare(strict_types=1);

/**
 * =======================================================================
 * Layout : split_5050 (A3 LANDSCAPE - V3 rounded+shadow+titreXL)
 * =======================================================================
 *
 *   1. Photo hero PLEIN CADRE sur la moitie GAUCHE (full bleed vertical)
 *   2. Carte titre annonce / ref en overlay haut-gauche sur la photo
 *   3. Mosaique thumbs bas-gauche (rounded + cadre or)
 *   4. Colonne INFO claire (creme) sur la moitie DROITE :
 *      titre headline XL + accroche + prix XL + conditions financieres
 *      + mini-cards caracs + DPE/GES + atouts
 *   5. Pied navy fin
 *
 * Ideal : presentation equilibree, lecture confort, investisseur,
 *   bien avec 1-2 belles photos verticales.
 * =======================================================================
 */

if (!function_exists('mbi_supports_layout_split_5050_build')) {

    function mbi_supports_layout_split_5050_build(array $ctx): TCPDF
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
        $cTitre   = ($angle !== 'generique' && $libAngle !== '') ? $cAngle : $cP;

        $nbPhotos = (int)($ctx['nb_photos'] ?? 3);
        if ($nbPhotos < 1) $nbPhotos = 1;
        if ($nbPhotos > 3) $nbPhotos = 3;

        $pdf = new TCPDF('L', 'mm', 'A3', true, 'UTF-8');
        $pdf->SetCreator('MaBoxImmo - Ma Box Communication');
        $pdf->SetTitle('Affiche vitrine A3 H - split 50/50');
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
        $splitX = 206;

        // --- 1. PHOTO HERO moitie gauche (full bleed) ---
        $hero     = mbi_supports_photo_hero($photos, $ctx['photo_hero_id_suggestion'] ?? null);
        $heroPath = $hero ? mbi_supports_resoudre_photo_path($hero) : null;
        if ($heroPath !== null) {
            try {
                $pdf->Image($heroPath, 0, 0, $splitX, $photoH, '', '', '', false, 250, '', false, false, 0, 'CM', false, false);
            } catch (Throwable) {
                mbi_supports_tpl_v2_placeholder($pdf, 0, 0, $splitX, $photoH, $cP);
            }
        } else {
            mbi_supports_tpl_v2_placeholder($pdf, 0, 0, $splitX, $photoH, $cP);
        }

        // Voile haut (lisibilite carte titre)
        $pdf->SetAlpha(0.16);
        $pdf->SetFillColor(0, 0, 0);
        $pdf->Rect(0, 0, $splitX, 80, 'F');
        $pdf->SetAlpha(1.0);

        // --- 2. PANNEAU INFO creme moitie droite ---
        $pdf->SetFillColor(247, 244, 237);
        $pdf->Rect($splitX, 0, $pageW - $splitX, $photoH, 'F');
        // Couture or fine entre photo et panneau
        $pdf->SetFillColor($cS[0], $cS[1], $cS[2]);
        $pdf->Rect($splitX, 0, 1.6, $photoH, 'F');

        // Watermark filigrane sur le panneau (alpha bas)
        $watermarkPath = __DIR__ . '/../../images/mbi_affiche_watermark.jpg';
        if (is_file($watermarkPath) && is_readable($watermarkPath)) {
            try {
                $pdf->SetAlpha(0.06);
                $pdf->Image($watermarkPath, $splitX, 0, $pageW - $splitX, $photoH, '', '', '', false, 250, '', false, false, 0, 'CM', false, false);
                $pdf->SetAlpha(1.0);
            } catch (Throwable) {
                $pdf->SetAlpha(1.0);
            }
        }

        // --- 3. CARTE TITRE annonce overlay haut-gauche ---
        $titreAnnonce = trim((string)($bien['_annonce_titre'] ?? ''));
        $ref     = (string)($bien['reference_bien'] ?? ('#' . ($bien['id'] ?? '')));
        $loc     = trim((string)($bien['code_postal'] ?? '') . ' ' . ($bien['ville'] ?? ''));
        $refSous = trim('Réf ' . $ref . ($loc !== '' ? '  ·  ' . $loc : ''));

        $cardW = $splitX - 32;
        $cardH = $titreAnnonce !== '' ? 46 : 26;
        mbi_supports_tpl_card_round_shadow($pdf, 16, 16, $cardW, $cardH, 6.0, [10, 18, 32], 0.62, true, $cS);

        if ($titreAnnonce !== '') {
            $titre2L = mbi_supports_couper_2_lignes(mb_substr($titreAnnonce, 0, 100, 'UTF-8'));
            // Ombre tres legere (lisibilite sur photo, sans alourdir le texte)
            $pdf->SetAlpha(0.18);
            $pdf->SetFont('dejavusans', 'B', 24);
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetXY(24 + 0.5, 22 + 0.7);
            $pdf->MultiCell($cardW - 16, 9.5, $titre2L, 0, 'L');
            $pdf->SetAlpha(1.0);
            $pdf->SetFont('dejavusans', 'B', 24);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY(24, 22);
            $pdf->MultiCell($cardW - 16, 9.5, $titre2L, 0, 'L');
            $pdf->SetFont('dejavusans', '', 9);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY(24, 16 + $cardH - 8);
            $pdf->Cell($cardW - 16, 4, mb_strtoupper($refSous, 'UTF-8'), 0, 0, 'L');
        } else {
            $pdf->SetFont('dejavusans', 'B', 13);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY(24, 22);
            $pdf->Cell($cardW - 16, 6, mb_strtoupper($refSous, 'UTF-8'), 0, 0, 'L');
        }

        // --- 3b. MOSAIQUE thumbs bas-gauche (jusqu'a 3 mini-photos) ---
        $heroId = (int)($hero['id'] ?? 0);
        $autres = mbi_supports_tpl_select_thumbs($photos, $ctx, $heroId, 4);
        if (!empty($autres)) {
            $tw = 50; $th = 38; $tg = 8; // espacement augmente (+~30 %) entre les mini-photos
            $tx0 = 16;
            $ty  = $photoH - $th - 14;
            foreach ($autres as $i => $p) {
                if ($i >= 3) break;
                $tx = $tx0 + $i * ($tw + $tg);
                if ($tx + $tw > $splitX - 10) break;
                $tpath = mbi_supports_resoudre_photo_path($p);
                mbi_supports_tpl_image_round($pdf, $tpath, $tx, $ty, $tw, $th, 4.0, true, $cS, 0.7);
            }
        }

        // --- COLONNE INFO DROITE ---
        $px = $splitX + 22;
        $pw = $pageW - $px - 20;
        $py = 26;

        // --- Bandeau pleine largeur A VENDRE / A LOUER, ref · ville dessous ---
        $infoPrix = mbi_supports_get_prix_ou_loyer($bien);
        mbi_supports_tpl_badge_transaction($pdf, $px, $py, $pw, 18, $bien, $cP, $cS);
        $py += 26; // plus d'espace sous le bandeau
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
        $pdf->SetXY($px, $py);
        $pdf->Cell($pw, 5, 'RÉF ' . mb_strtoupper((string)$ref, 'UTF-8') . ($loc !== '' ? '  ·  ' . mb_strtoupper($loc, 'UTF-8') : ''), 0, 0, 'L');
        $py += 10;

        // --- TITRE HEADLINE XL (navy sur creme : pas d'ombre, contraste suffisant) ---
        $titreH = mbi_supports_tpl_titre_headline($bien);
        $pdf->SetFont('dejavusans', 'B', 24);
        $pdf->SetTextColor($cTitre[0], $cTitre[1], $cTitre[2]);
        $pdf->SetXY($px, $py);
        $pdf->MultiCell($pw, 9, $titreH, 0, 'L');
        $py = $pdf->GetY() + 2;

        // --- ACCROCHE italique ---
        $accroche = trim((string)($bien['_accroche'] ?? ''));
        if ($accroche === '' && $iaAccroche !== '') $accroche = $iaAccroche;
        if ($accroche !== '' && !str_starts_with($accroche, 'Bien créé')) {
            $pdf->SetFont('dejavusans', 'I', 13);
            $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
            $pdf->SetXY($px, $py);
            $pdf->MultiCell($pw, 6, '« ' . $accroche . ' »', 0, 'L');
            $py = $pdf->GetY() + 4;
        } else {
            $py += 2;
        }

        // --- PRIX / LOYER XL (petrole, plus gros, ombre foncee pour ressortir) ---
        mbi_supports_tpl_text_shadow(
            $pdf, $px, $py, $pw, 20,
            mbi_supports_format_prix_complet($infoPrix),
            [38, 96, 110], 'dejavusans', 'B', 42, 'L', false, 1.3, 1.7, null, 0.30
        );
        $py += 21;

        // Trait or separateur
        $pdf->SetDrawColor($cS[0], $cS[1], $cS[2]);
        $pdf->SetLineWidth(0.8);
        $pdf->Line($px, $py, $px + 40, $py);
        $pdf->SetLineWidth(0.2);
        $py += 7;

        // --- MINI-CARDS caracs ---
        $surf  = mbi_supports_get_surface($bien);
        $nbPcs = $bien['nb_pieces'] ?? $bien['nombre_pieces'] ?? null;
        $etage = $bien['etage'] ?? null;
        $surfTxt = $surf !== null
            ? rtrim(rtrim(number_format($surf, 2, ',', ' '), '0'), ',')
            : null;
        $caracs = [];
        if ($surfTxt !== null)                        $caracs[] = ['M²', $surfTxt];
        if ($nbPcs)                                   $caracs[] = ['Pces', (string)$nbPcs];
        if ($etage !== null && (string)$etage !== '') $caracs[] = ['Étage', (string)$etage];

        if (!empty($caracs)) {
            $nbC = count($caracs);
            $cellW = ($pw - (($nbC - 1) * 4)) / $nbC;
            $cellH = 38;
            // Taille de valeur UNIFORME (calee sur la valeur la plus longue) :
            // la surface s'affiche a la meme taille que le nb de pieces et l'etage.
            $valLenMax = 1;
            foreach ($caracs as $it) { $valLenMax = max($valLenMax, mb_strlen((string)$it[1], 'UTF-8')); }
            // Valeur uniforme mais GRANDE (env. 2x l'ancienne) - "92,5" tient jusqu'a ~48 pt.
            $uniform = $valLenMax <= 2 ? 52.0
                     : ($valLenMax <= 4 ? 48.0
                     : ($valLenMax <= 5 ? 38.0 : 30.0));
            foreach ($caracs as $i => $it) {
                $x = $px + ($i * ($cellW + 4));
                mbi_supports_tpl_carac_mini(
                    $pdf, $x, $py, $cellW, $cellH,
                    $it[0], $it[1], $cP, $cT,
                    [255, 255, 255], null, $cP, true,
                    15.0, $uniform, 4.0, false  // libelle plus gros (15) + valeur fixe et grande
                );
            }
            $py += $cellH + 7;
        }

        // --- CONDITIONS FINANCIERES ---
        $lignesCF = mbi_supports_get_conditions_financieres($bien);
        if (!empty($lignesCF)) {
            $pdf->SetFont('dejavusans', 'B', 8.5);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY($px, $py);
            $pdf->Cell($pw, 4, mb_strtoupper(($infoPrix['type'] ?? '') === 'location' ? 'Conditions ALUR' : 'Conditions', 'UTF-8'), 0, 1, 'L');
            $py += 5;

            // 2 colonnes pour gagner de la hauteur
            $colW = $pw / 2 - 4;
            $half = (int)ceil(count($lignesCF) / 2);
            $cols = [array_slice($lignesCF, 0, $half), array_slice($lignesCF, $half)];
            $maxRows = max(count($cols[0]), count($cols[1]));
            foreach ($cols as $ci => $col) {
                $x0 = $px + $ci * ($colW + 8);
                foreach ($col as $ri => $cf) {
                    [$lab, $val] = $cf;
                    $ly = $py + $ri * 5.2;
                    $pdf->SetFont('dejavusans', '', 10);
                    $pdf->SetTextColor(110, 116, 130);
                    $pdf->SetXY($x0, $ly);
                    $pdf->Cell($colW * 0.55, 4.5, $lab . ' :', 0, 0, 'L');
                    $pdf->SetFont('dejavusans', 'B', 10);
                    $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
                    $pdf->SetXY($x0 + $colW * 0.55, $ly);
                    $pdf->Cell($colW * 0.45, 4.5, $val, 0, 0, 'R');
                }
            }
            $py += $maxRows * 5.2 + 5;
        }

        // --- ANNEXES ---
        $annexes = mbi_supports_get_annexes($bien);
        if (!empty($annexes)) {
            $py = mbi_supports_tpl_bloc_annexes($pdf, $px, $py, $pw, $annexes, $cS, [70, 76, 90], $photoH - 62) + 2;
        }

        // --- L'ANNONCE (descriptif, parfois tres long) - remplit l'espace ---
        // DPE/GES est ancre en bas ; le texte de l'annonce occupe la zone au-dessus.
        $dpe = strtoupper(trim((string)($bien['dpe_classe'] ?? $bien['dpe'] ?? '')));
        $ges = strtoupper(trim((string)($bien['ges_classe'] ?? $bien['ges'] ?? '')));
        $dpeVal = (float)($bien['dpe_valeur'] ?? 0);
        $gesVal = (float)($bien['ges_valeur'] ?? 0);

        $mentionDpe = '';
        if (!empty($mentionsTextes['dpe_en_cours']))   $mentionDpe = $mentionsTextes['dpe_en_cours'];
        if (!empty($mentionsTextes['dpe_non_soumis'])) $mentionDpe = $mentionsTextes['dpe_non_soumis'];

        $dpeH  = 40;
        $mentH = $mentionDpe !== '' ? 7 : 0;
        $dpeY  = $photoH - $dpeH - $mentH - 6;

        $descAnnonce = mbi_supports_get_description_annonce($bien);
        if ($descAnnonce !== '') {
            // Separe l'annonce des conditions (trait or) et la descend un peu pour aerer
            $py += 6;
            $pdf->SetDrawColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetLineWidth(0.6);
            $pdf->Line($px, $py, $px + 40, $py);
            $pdf->SetLineWidth(0.2);
            $py += 8;
            if ($py < $dpeY - 12) {
                mbi_supports_tpl_bloc_annonce($pdf, $px, $py, $pw, ($dpeY - 4) - $py, $descAnnonce, $cS, [70, 76, 90]);
            }
        } elseif ($py < $dpeY) {
            $dpeY = $py; // pas d'annonce : DPE remonte sous le contenu (pas de trou)
        }

        // --- DPE / GES (ancre bas) ---
        mbi_supports_tpl_dpe_ges(
            $pdf, $px, $dpeY, $pw, $dpe, $ges, [90, 96, 110],
            $dpeVal > 0 ? $dpeVal : null,
            $gesVal > 0 ? $gesVal : null
        );

        // Mention DPE en cours / non soumis (sous les pastilles)
        if ($mentionDpe !== '') {
            $pdf->SetFont('dejavusans', 'I', 7.5);
            $pdf->SetTextColor(120, 80, 30);
            $pdf->SetXY($px, $dpeY + $dpeH + 1);
            $pdf->MultiCell($pw, 3.5, $mentionDpe, 0, 'L');
        }

        // --- 5. PIED NAVY fin ---
        mbi_supports_tpl_pied_landscape($pdf, $ctx, $cP, $cS, $pageW, $pageH, $piedH);

        return $pdf;
    }
}
