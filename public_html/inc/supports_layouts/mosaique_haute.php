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

        // Fond BLANC derriere toute la page (qualité d'impression + économie d'encre)
        $pdf->SetFillColor(255, 255, 255);
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

        // Filet bleu petrole autour de chaque photo
        $cPetrole = [38, 96, 110];
        for ($i = 0; $i < $nCells; $i++) {
            $x = $padX + $i * ($cellW + $gap);
            $cell  = $cellules[$i] ?? null;
            $tpath = is_array($cell) ? mbi_supports_resoudre_photo_path($cell) : null;
            mbi_supports_tpl_image_round($pdf, $tpath, $x, $padTop, $cellW, $gridH, 7.0, true, $cPetrole, 1.0);
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
        $logoAffiche = mbi_supports_resoudre_logo_entite($ctx['agence'] ?? []);

        // --- LOGO déplacé en HAUT À DROITE (zone du 1/3 droit) ---
        $logoBoxH  = 58.0;   // -10 % (64→58) pour réhausser le bloc caracs
        // Sans carte blanche : on affiche juste le PNG (fond transparent) sur la crème.
        // Boîte élargie au 1/3 droit complet pour que le logo puisse grossir.
        $logoBoxX  = $rightX + 2;
        $logoBoxW  = $rightW - 6;
        $logoCardH = mbi_supports_tpl_logo_card($pdf, $logoBoxX, $iy, $logoBoxW, $logoBoxH, $logoAffiche, false);

        // --- RÉF sur UNE SEULE LIGNE, centrée, AU-DESSUS du bandeau ---
        // (libère de la hauteur pour le titre / l'annonce / les annexes)
        // Réf en or + ville en bleu pétrole, regroupées et centrées.
        $pdf->SetFont('dejavusans', 'B', 9);
        $segRef   = 'RÉF ' . mb_strtoupper((string)$ref, 'UTF-8');
        $segSep   = $ville !== '' ? '   ·   ' : '';
        $segVille = $ville !== '' ? mb_strtoupper($ville, 'UTF-8') : '';
        $wRef   = $pdf->GetStringWidth($segRef);
        $wSep   = $segSep !== '' ? $pdf->GetStringWidth($segSep) : 0;
        $wVille = $segVille !== '' ? $pdf->GetStringWidth($segVille) : 0;
        $startX = $ix + ($lw - ($wRef + $wSep + $wVille)) / 2;
        $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
        $pdf->SetXY($startX, $ly);
        $pdf->Cell($wRef + $wSep, 5, $segRef . $segSep, 0, 0, 'L');
        if ($segVille !== '') {
            $pdf->SetTextColor(38, 96, 110); // bleu pétrole
            $pdf->SetXY($startX + $wRef + $wSep, $ly);
            $pdf->Cell($wVille, 5, $segVille, 0, 0, 'L');
        }
        $ly += 7;

        // --- Bandeau « À VENDRE/À LOUER » SANS logo, dans l'espace de GAUCHE ---
        mbi_supports_tpl_badge_transaction($pdf, $ix, $ly, $lw, 18, $bien, $cP, $cS, null);
        $ly += 24; // espace sous le bandeau

        // Titre CENTRÉ dans l'espace de gauche (sous le bandeau).
        $titreAnnonce = trim((string)($bien['_annonce_titre'] ?? ''));
        $titrePrincipal = $titreAnnonce !== ''
            ? mbi_supports_couper_2_lignes(mb_substr($titreAnnonce, 0, 90, 'UTF-8'))
            : mbi_supports_tpl_titre_headline($bien);
        $pdf->SetFont('dejavusans', 'B', 22);
        $pdf->SetTextColor($cTitre[0], $cTitre[1], $cTitre[2]);
        $pdf->SetXY($ix, $ly);
        $pdf->MultiCell($lw, 8.5, $titrePrincipal, 0, 'C');
        $ly = $pdf->GetY() + 2;
        // La colonne caracs (droite) démarre sous la carte logo.
        $yAfterTitre = $iy + $logoCardH + 6;

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
            [38, 96, 110], 'dejavusans', 'B', 44, 'C', false, 1.3, 1.7, null, 0.30
        );
        $ly += 23;

        // --- CONDITIONS (col gauche) + COPROPRIÉTÉ (col droite) alignées ---
        // 2 listes côte à côte dans la zone gauche (gain de hauteur → annonce plus longue).
        $lignesCF    = mbi_supports_get_conditions_financieres($bien);
        $lignesCopro = mbi_supports_get_copropriete_lignes($bien);
        $rowH   = 5.0;
        $gapCol = 8;
        $colW   = ($lw - $gapCol) / 2;

        $renderListe = function (string $titre, array $lignes, float $x0, float $yTop)
                       use ($pdf, $colW, $rowH, $cS, $cP): float {
            if (empty($lignes)) return $yTop;
            $pdf->SetFont('dejavusans', 'B', 8.5);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY($x0, $yTop);
            $pdf->Cell($colW, 4, mb_strtoupper($titre, 'UTF-8'), 0, 1, 'L');
            $y = $yTop + 5.5;
            foreach ($lignes as $i => $cf) {
                [$lab, $val] = $cf;
                $yl = $y + $i * $rowH;
                $pdf->SetFont('dejavusans', '', 9.5);
                $pdf->SetTextColor(110, 116, 130);
                $pdf->SetXY($x0, $yl);
                $pdf->Cell($colW * 0.55, 4.6, $lab . ' :', 0, 0, 'L');
                $pdf->SetFont('dejavusans', 'B', 9.5);
                $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
                $pdf->SetXY($x0 + $colW * 0.55, $yl);
                $pdf->Cell($colW * 0.45, 4.6, $val, 0, 0, 'R');
            }
            return $y + count($lignes) * $rowH;
        };

        $titreCF = (($infoPrix['type'] ?? '') === 'location') ? 'Conditions ALUR' : 'Conditions';
        $yG = $renderListe($titreCF,      $lignesCF,    $ix,                   $ly);
        $yD = $renderListe('Copropriété', $lignesCopro, $ix + $colW + $gapCol, $ly);

        $leftBottom = max($yG, $yD);

        // -- RIGHT 1/3 : cards (caracs + DPE/GES) --
        $surf  = mbi_supports_get_surface($bien);
        $nbPcs = $bien['nb_pieces'] ?? $bien['nombre_pieces'] ?? null;
        $etage = $bien['etage'] ?? null;
        $surfTxt = $surf !== null
            ? rtrim(rtrim(number_format($surf, 2, ',', ' '), '0'), ',')
            : null;

        $ry   = $yAfterTitre; // démarre sous la carte logo (droite)
        $rowH = 26;           // caracs plus compactes → ne dépassent pas sur le DPE

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
            $ry += $rowH + 4;
        }

        // Ligne 2 : Surface (pleine largeur du 1/3) - nombre + « m² » en gros
        if ($surfTxt !== null) {
            mbi_supports_tpl_carac_mini(
                $pdf, $rx, $ry, $rw, $rowH, 'Surface', $surfTxt . ' m²', $cP, $cT,
                [247, 249, 252], null, $cP, true, 12.0, 40.0, 4.0, false
            );
            $ry += $rowH + 5;
        }

        // (Annexes déplacées dans la zone de GAUCHE, sous L'ANNONCE — cf. plus bas)

        // DPE + GES sur la MEME LIGNE, alignes EN BAS de la carte
        $dpe = strtoupper(trim((string)($bien['dpe_classe'] ?? $bien['dpe'] ?? '')));
        $ges = strtoupper(trim((string)($bien['ges_classe'] ?? $bien['ges'] ?? '')));
        $dpeVal = (float)($bien['dpe_valeur'] ?? 0);
        $gesVal = (float)($bien['ges_valeur'] ?? 0);
        $dpeN = mbi_supports_classe_normalise($dpe);
        if ($dpeN === '' && $dpeVal > 0) $dpeN = mbi_supports_dpe_classe_depuis_valeur($dpeVal);
        $gesN = mbi_supports_classe_normalise($ges);
        if ($gesN === '' && $gesVal > 0) $gesN = mbi_supports_ges_classe_depuis_valeur($gesVal);

        // Valeurs de consommation affichées + couleur pétrole (lisible sur fond clair)
        $dpeRowY = $cardY + $cardH - 22;
        mbi_supports_tpl_dpe_ges_pastille(
            $pdf, $rx, $dpeRowY, 'DPE', $dpeN,
            $dpeVal > 0 ? $dpeVal : null, 'kWh/m²/an', $cPetrole, $cPetrole
        );
        mbi_supports_tpl_dpe_ges_pastille(
            $pdf, $rx + $rw * 0.52, $dpeRowY, 'GES', $gesN,
            $gesVal > 0 ? $gesVal : null, 'kg CO₂/m²/an', $cPetrole, $cPetrole
        );

        // -- L'ANNONCE - bandeau pleine largeur, sous le prix ET sous le DPE --
        $mentionDpe = '';
        if (!empty($mentionsTextes['dpe_en_cours']))   $mentionDpe = $mentionsTextes['dpe_en_cours'];
        if (!empty($mentionsTextes['dpe_non_soumis'])) $mentionDpe = $mentionsTextes['dpe_non_soumis'];

        // Annonce confinee aux 2/3 GAUCHE (sous le prix), pas en pleine largeur
        $bandY       = $leftBottom + 6;
        $bandBottom  = $cardY + $cardH - ($mentionDpe !== '' ? 9 : 5);

        // Annexes : réservées sur UNE ligne en bas de la zone GAUCHE (alignées à
        // gauche, limitées à $lw). On retire leur hauteur de l'espace annonce.
        $annexes     = mbi_supports_get_annexes($bien);
        $annexesH    = !empty($annexes) ? 13.0 : 0.0;  // libellé + chips
        $annonceBottom = $bandBottom - $annexesH;

        $descAnnonce = mbi_supports_get_description_annonce($bien);
        if ($descAnnonce !== '' && $annonceBottom - $bandY > 12) {
            mbi_supports_tpl_bloc_annonce($pdf, $ix, $bandY, $lw, $annonceBottom - $bandY, $descAnnonce, $cS, [70, 76, 90]);
        }

        // Annexes alignées à gauche, sur une ligne, dans l'espace de gauche.
        if (!empty($annexes)) {
            $ayL = $bandBottom - $annexesH + 1;
            $pdf->SetFont('dejavusans', 'B', 8);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY($ix, $ayL);
            $pdf->Cell($lw, 4, mb_strtoupper('Annexes', 'UTF-8'), 0, 0, 'L');
            $ayChip = $ayL + 5;
            $chipH = 7.5; $chipGap = 4.0; $axc = $ix;
            $pdf->SetFont('dejavusans', 'B', 8);
            foreach ($annexes as $an) {
                $label = '✓ ' . $an;
                $cw = $pdf->GetStringWidth($label) + 7;
                if ($axc + $cw > $ix + $lw) break;   // limité à l'espace de gauche
                mbi_supports_tpl_card_round_shadow($pdf, $axc, $ayChip, $cw, $chipH, $chipH / 2, $cS, 1.0, false);
                $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
                $pdf->SetXY($axc, $ayChip + 0.2);
                $pdf->Cell($cw, $chipH - 0.4, $label, 0, 0, 'C');
                $axc += $cw + $chipGap;
            }
        }

        // Mention DPE en cours / non soumis (pied de carte)
        if ($mentionDpe !== '') {
            $pdf->SetFont('dejavusans', 'I', 7);
            $pdf->SetTextColor(120, 80, 30);
            $pdf->SetXY($ix, $cardY + $cardH - 7);
            $pdf->Cell($iw, 3.5, $mentionDpe, 0, 0, 'L');
        }

        // --- 3. PIED bleu petrole fin ---
        mbi_supports_tpl_pied_landscape($pdf, $ctx, $cPetrole, $cS, $pageW, $pageH, $piedH);

        return $pdf;
    }
}
