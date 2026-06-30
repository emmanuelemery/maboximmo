<?php
declare(strict_types=1);

/**
 * =======================================================================
 * Layout : mosaique_haute (A3 LANDSCAPE - V3 rounded+shadow+titreXL)
 * =======================================================================
 *
 *   1. Grille de 3 photos en HAUT (cellules larges, format paysage)
 *   2. Carte INFO en BAS (blanche, rounded + shadow), repartie :
 *      - 2/3 GAUCHE : fond watermark + titre + accroche + prix + conditions
 *      - 1/3 DROITE : cards (Pieces + Etage sur une ligne, Surface dessous, DPE/GES)
 *      - L'annonce : bandeau pleine largeur en bas (sous le prix ET sous le DPE)
 *   3. Pied navy fin
 *
 * Ideal : biens avec plusieurs belles photos a montrer en vitrine.
 * =======================================================================
 */

if (!function_exists('mbi_supports_layout_mosaique_haute_build')) {

    function mbi_supports_layout_mosaique_haute_build(array $ctx): TCPDF
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

        $cP = $style['rgb_primaire']   ?? [36, 59, 92];
        $cS = $style['rgb_secondaire'] ?? [212, 160, 71];
        $cT = $style['rgb_texte']      ?? [31, 41, 55];

        $angle    = (string)($ctx['angle'] ?? 'generique');
        $anglePal = mbi_supports_tpl_angle_palette($angle, $cS);
        $cAngle   = $anglePal['rgb'];
        $libAngle = $anglePal['libelle'];
        $cTitre   = ($angle !== 'generique' && $libAngle !== '') ? $cAngle : $cP;

        $pdf = new TCPDF('L', 'mm', 'A3', true, 'UTF-8');
        $pdf->SetCreator('MaBoxImmo - Ma Box Communication');
        $pdf->SetTitle('Affiche vitrine A3 H - mosaique haute');
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

        // Fond creme leger derriere toute la page
        $pdf->SetFillColor(247, 244, 237);
        $pdf->Rect(0, 0, $pageW, $photoH, 'F');

        // --- 1. GRILLE DE 3 PHOTOS HAUT (cellules larges, paysage) ---
        $hero   = mbi_supports_photo_hero($photos, $ctx['photo_hero_id_suggestion'] ?? null);
        $heroId = (int)($hero['id'] ?? 0);

        $thumbs   = mbi_supports_tpl_select_thumbs($photos, $ctx, $heroId, 3);
        $cellules = [];
        if ($hero !== null) $cellules[] = $hero;
        foreach ($thumbs as $t) {
            $cellules[] = $t;
            if (count($cellules) >= 3) break;
        }
        $nCells = max(1, count($cellules));

        $padX   = 14;
        $padTop = 14;
        $gap    = 6;
        $gridH  = 78; // cellules nettement en longueur (paysage) + place au bandeau annonce
        $gridW  = $pageW - 2 * $padX;
        $cellW  = ($gridW - ($nCells - 1) * $gap) / $nCells;

        for ($i = 0; $i < $nCells; $i++) {
            $x = $padX + $i * ($cellW + $gap);
            $cell  = $cellules[$i] ?? null;
            $tpath = is_array($cell) ? mbi_supports_resoudre_photo_path($cell) : null;
            mbi_supports_tpl_image_round($pdf, $tpath, $x, $padTop, $cellW, $gridH, 7.0, true, $cS, 0.6);
        }

        // --- 2. CARTE INFO en bas ---
        $cardX = $padX;
        $cardY = $padTop + $gridH + 8;
        $cardW = $gridW;
        $cardH = $photoH - $cardY - 6;
        mbi_supports_tpl_card_round_shadow($pdf, $cardX, $cardY, $cardW, $cardH, 8.0, [255, 255, 255], 0.97, true, $cS);

        // Geometrie : 2/3 gauche (texte + fond watermark) / 1/3 droite (cards)
        $pad    = 16;
        $iy     = $cardY + 12;
        $leftW  = $cardW * 0.66;
        $rightX = $cardX + $leftW;
        $rightW = $cardW - $leftW;
        $ix     = $cardX + $pad;
        $lw     = $leftW - $pad - 10;
        $rx     = $rightX + 8;
        $rw     = $rightW - 8 - $pad;
        $iw     = $cardW - 2 * $pad;

        $infoPrix = mbi_supports_get_prix_ou_loyer($bien);

        // -- LEFT 2/3 : eyebrow + titre + accroche + prix + conditions --
        $ref   = (string)($bien['reference_bien'] ?? ('#' . ($bien['id'] ?? '')));
        $ville = trim((string)($bien['ville'] ?? ''));
        $ly = $iy;
        // Bandeau pleine largeur (2/3 gauche) A VENDRE / A LOUER, ref · ville dessous
        mbi_supports_tpl_badge_transaction($pdf, $ix, $ly, $lw, 18, $bien, $cP, $cS);
        $ly += 26; // plus d'espace sous le bandeau
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
        $pdf->SetXY($ix, $ly);
        $pdf->Cell($lw, 5, 'RÉF ' . mb_strtoupper((string)$ref, 'UTF-8') . ($ville !== '' ? '  ·  ' . mb_strtoupper($ville, 'UTF-8') : ''), 0, 0, 'L');
        $ly += 9;

        $titreAnnonce = trim((string)($bien['_annonce_titre'] ?? ''));
        $titrePrincipal = $titreAnnonce !== ''
            ? mbi_supports_couper_2_lignes(mb_substr($titreAnnonce, 0, 90, 'UTF-8'))
            : mbi_supports_tpl_titre_headline($bien);
        $pdf->SetFont('dejavusans', 'B', 22);
        $pdf->SetTextColor($cTitre[0], $cTitre[1], $cTitre[2]);
        $pdf->SetXY($ix, $ly);
        $pdf->MultiCell($lw, 8.5, $titrePrincipal, 0, 'L');
        $ly = $pdf->GetY() + 2;

        $accroche = trim((string)($bien['_accroche'] ?? ''));
        if ($accroche === '' && $iaAccroche !== '') $accroche = $iaAccroche;
        if ($accroche !== '' && !str_starts_with($accroche, 'Bien créé')) {
            $pdf->SetFont('dejavusans', 'I', 12);
            $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
            $pdf->SetXY($ix, $ly);
            $pdf->MultiCell($lw, 5.5, '« ' . $accroche . ' »', 0, 'L');
            $ly = $pdf->GetY() + 3;
        }

        // Air au-dessus du prix - l'isole et le fait ressortir
        $ly += 8;
        mbi_supports_tpl_text_shadow(
            $pdf, $ix, $ly, $lw, 21,
            mbi_supports_format_prix_complet($infoPrix),
            [38, 96, 110], 'dejavusans', 'B', 44, 'L', false, 1.3, 1.7, null, 0.30
        );
        $ly += 23;

        // Conditions financieres (dont honoraires) - TOUJOURS sous le prix
        $lignesCF = mbi_supports_get_conditions_financieres($bien);
        if (!empty($lignesCF)) {
            $pdf->SetFont('dejavusans', 'B', 8.5);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY($ix, $ly);
            $pdf->Cell($lw, 4, mb_strtoupper(($infoPrix['type'] ?? '') === 'location' ? 'Conditions ALUR' : 'Conditions', 'UTF-8'), 0, 1, 'L');
            $ly += 5.5;
            foreach ($lignesCF as $cf) {
                [$lab, $val] = $cf;
                $pdf->SetFont('dejavusans', '', 9.5);
                $pdf->SetTextColor(110, 116, 130);
                $pdf->SetXY($ix, $ly);
                $pdf->Cell(44, 4.6, $lab . ' :', 0, 0, 'L');
                $pdf->SetFont('dejavusans', 'B', 9.5);
                $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
                $pdf->SetXY($ix + 44, $ly);
                $pdf->Cell(44, 4.6, $val, 0, 0, 'L');
                $ly += 5;
            }
        }
        $leftBottom = $ly;

        // -- RIGHT 1/3 : cards (caracs + DPE/GES) --
        $surf  = mbi_supports_get_surface($bien);
        $nbPcs = $bien['nb_pieces'] ?? $bien['nombre_pieces'] ?? null;
        $etage = $bien['etage'] ?? null;
        $surfTxt = $surf !== null
            ? rtrim(rtrim(number_format($surf, 2, ',', ' '), '0'), ',')
            : null;

        $ry   = $iy;
        $rowH = 30;

        // Ligne 1 : Pieces + Etage cote a cote
        $duo = [];
        if ($nbPcs)                                   $duo[] = ['Pces', (string)$nbPcs];
        if ($etage !== null && (string)$etage !== '') $duo[] = ['Étage', (string)$etage];
        if (!empty($duo)) {
            $n  = count($duo);
            $cw = ($rw - (($n - 1) * 5)) / $n;
            foreach ($duo as $i => $it) {
                $x = $rx + $i * ($cw + 5);
                mbi_supports_tpl_carac_mini(
                    $pdf, $x, $ry, $cw, $rowH, $it[0], $it[1], $cP, $cT,
                    [247, 249, 252], null, $cP, true, 12.0, 40.0, 4.0, false
                );
            }
            $ry += $rowH + 5;
        }

        // Ligne 2 : Surface (pleine largeur du 1/3) - nombre + « m² » en gros
        if ($surfTxt !== null) {
            mbi_supports_tpl_carac_mini(
                $pdf, $rx, $ry, $rw, $rowH, 'Surface', $surfTxt . ' m²', $cP, $cT,
                [247, 249, 252], null, $cP, true, 12.0, 40.0, 4.0, false
            );
            $ry += $rowH + 7;
        }

        // Annexes du bien sous la carte Surface (liste - sur 2 colonnes)
        $annexes = mbi_supports_get_annexes($bien);
        if (!empty($annexes)) {
            $pdf->SetFont('dejavusans', 'B', 8.5);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY($rx, $ry);
            $pdf->Cell($rw, 4, mb_strtoupper('Annexes', 'UTF-8'), 0, 1, 'L');
            $ry += 6;

            $colW2  = $rw / 2;
            $startY = $ry;
            foreach ($annexes as $i => $an) {
                $col = $i % 2;
                $row = intdiv($i, 2);
                $ax  = $rx + $col * $colW2;
                $ayv = $startY + $row * 5.2;
                if ($ayv > $cardY + $cardH - 28) break;
                $pdf->SetFont('dejavusans', 'B', 9);
                $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
                $pdf->SetXY($ax, $ayv);
                $pdf->Cell(5, 4.6, '✓', 0, 0, 'L');
                $pdf->SetFont('dejavusans', '', 10);
                $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
                $pdf->SetXY($ax + 5, $ayv);
                $pdf->Cell($colW2 - 6, 4.6, $an, 0, 0, 'L');
            }
            $ry = $startY + intdiv(count($annexes) + 1, 2) * 5.2 + 2;
        }

        // DPE + GES sur la MEME LIGNE, alignes EN BAS de la carte
        $dpe = strtoupper(trim((string)($bien['dpe_classe'] ?? $bien['dpe'] ?? '')));
        $ges = strtoupper(trim((string)($bien['ges_classe'] ?? $bien['ges'] ?? '')));
        $dpeVal = (float)($bien['dpe_valeur'] ?? 0);
        $gesVal = (float)($bien['ges_valeur'] ?? 0);
        $dpeN = mbi_supports_classe_normalise($dpe);
        if ($dpeN === '' && $dpeVal > 0) $dpeN = mbi_supports_dpe_classe_depuis_valeur($dpeVal);
        $gesN = mbi_supports_classe_normalise($ges);
        if ($gesN === '' && $gesVal > 0) $gesN = mbi_supports_ges_classe_depuis_valeur($gesVal);

        $dpeRowY = $cardY + $cardH - 22;
        mbi_supports_tpl_dpe_ges_pastille($pdf, $rx, $dpeRowY, 'DPE', $dpeN, null, '', [90, 96, 110]);
        mbi_supports_tpl_dpe_ges_pastille($pdf, $rx + $rw * 0.52, $dpeRowY, 'GES', $gesN, null, '', [90, 96, 110]);

        // -- L'ANNONCE - bandeau pleine largeur, sous le prix ET sous le DPE --
        $mentionDpe = '';
        if (!empty($mentionsTextes['dpe_en_cours']))   $mentionDpe = $mentionsTextes['dpe_en_cours'];
        if (!empty($mentionsTextes['dpe_non_soumis'])) $mentionDpe = $mentionsTextes['dpe_non_soumis'];

        // Annonce confinee aux 2/3 GAUCHE (sous le prix), pas en pleine largeur
        $bandY       = $leftBottom + 6;
        $bandBottom  = $cardY + $cardH - ($mentionDpe !== '' ? 9 : 5);
        $descAnnonce = mbi_supports_get_description_annonce($bien);
        if ($descAnnonce !== '' && $bandBottom - $bandY > 12) {
            mbi_supports_tpl_bloc_annonce($pdf, $ix, $bandY, $lw, $bandBottom - $bandY, $descAnnonce, $cS, [70, 76, 90]);
        }

        // Mention DPE en cours / non soumis (pied de carte)
        if ($mentionDpe !== '') {
            $pdf->SetFont('dejavusans', 'I', 7);
            $pdf->SetTextColor(120, 80, 30);
            $pdf->SetXY($ix, $cardY + $cardH - 7);
            $pdf->Cell($iw, 3.5, $mentionDpe, 0, 0, 'L');
        }

        // --- 3. PIED NAVY fin ---
        mbi_supports_tpl_pied_landscape($pdf, $ctx, $cP, $cS, $pageW, $pageH, $piedH);

        return $pdf;
    }
}
