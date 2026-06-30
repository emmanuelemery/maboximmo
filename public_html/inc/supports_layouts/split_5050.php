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
        $piedH  = 26;
        $photoH = $pageH - $piedH;
        $splitX = 206;
        $headerH = 64;   // bandeau titre (couleur pleine) AU-DESSUS de la photo, cote gauche
        $cPetrole = [38, 96, 110];

        // --- 1. PHOTO HERO moitie gauche (PLEINE dimension, full bleed) ---
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

        // --- Bandeau titre BLANC (fond optimisé impression), jusqu'a la photo ---
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect(0, 0, $splitX, $headerH, 'F');

        // --- 2. PANNEAU INFO BLANC moitie droite (meilleure qualité/coût impression) ---
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($splitX, 0, $pageW - $splitX, $photoH, 'F');
        // Couture or fine entre photo et panneau
        $pdf->SetFillColor($cS[0], $cS[1], $cS[2]);
        $pdf->Rect($splitX, 0, 1.6, $photoH, 'F');

        // Filigrane retiré : fond BLANC pur (qualité d'impression + économie d'encre).

        // --- 3. CARTE TITRE annonce (centree verticalement dans le bandeau) ---
        $titreAnnonce = trim((string)($bien['_annonce_titre'] ?? ''));
        $ref     = (string)($bien['reference_bien'] ?? ('#' . ($bien['id'] ?? '')));
        $loc     = trim((string)($bien['code_postal'] ?? '') . ' ' . ($bien['ville'] ?? ''));
        $refSous = trim('Réf ' . $ref . ($loc !== '' ? '  ·  ' . $loc : ''));

        $cardW = $splitX - 32;
        $cardH = $titreAnnonce !== '' ? 38 : 22;
        $cardY = ($headerH - $cardH) / 2;   // centre vertical entre le haut de la page et la photo
        // Card bleu petrole, meme style/couleur que la card "A LOUER" (pas de liseré or, pas de réf : déjà à droite)
        mbi_supports_tpl_card_round_shadow($pdf, 16, $cardY, $cardW, $cardH, 6.0, $cPetrole, 1.0, true);

        if ($titreAnnonce !== '') {
            $titre2L = mbi_supports_couper_2_lignes(mb_substr($titreAnnonce, 0, 100, 'UTF-8'));
            $pdf->SetFont('dejavusans', 'B', 22);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY(16, $cardY + 8);
            $pdf->MultiCell($cardW, 9.0, $titre2L, 0, 'C');
        } else {
            $typeBienTit = trim((string)($bien['type_bien_libelle'] ?? $bien['type'] ?? 'Bien'));
            $pdf->SetFont('dejavusans', 'B', 14);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY(16, $cardY + ($cardH / 2) - 3);
            $pdf->Cell($cardW, 6, mb_strtoupper($typeBienTit, 'UTF-8'), 0, 0, 'C');
        }

        // --- 3b. MOSAIQUE thumbs bas-gauche (jusqu'a 3 mini-photos) ---
        $heroId = (int)($hero['id'] ?? 0);
        $autres = mbi_supports_tpl_select_thumbs($photos, $ctx, $heroId, 4);
        if (!empty($autres)) {
            // Les 3 miniatures occupent TOUTE la largeur de la moitié gauche, avec une
            // petite marge à gauche (impression) et juste avant la couture crème à droite.
            $tx0       = 9;                       // marge gauche (sécurité impression)
            $tg        = 3;                       // espacement entre vignettes
            $rightEdge = $splitX - 3;             // s'arrête juste avant la couture or/crème
            $tw        = ($rightEdge - $tx0 - 2 * $tg) / 3; // largeur calculée pour remplir (≈ 62,6 mm)
            $th        = $tw * 0.80;              // ratio paysage
            $ty        = $photoH - $th - 14;
            foreach ($autres as $i => $p) {
                if ($i >= 3) break;
                $tx    = $tx0 + $i * ($tw + $tg);
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
        $logoAffiche = mbi_supports_resoudre_logo_entite($ctx['agence'] ?? []);
        // Logo SANS cadre (PNG seul) + 15 % plus gros (44 → 51).
        mbi_supports_tpl_badge_transaction($pdf, $px, $py, $pw, 18, $bien, $cP, $cS, $logoAffiche, 51, 'center', false);
        $py += 26; // plus d'espace sous le bandeau
        // Réf sur 2 lignes, CENTRÉE sous la pastille « À VENDRE/À LOUER »
        // (et non sous le logo). On calcule où commence la pastille = $px + largeur
        // carte logo + gap, pour centrer la réf dans cette zone.
        $logoCardW = mbi_supports_logo_card_width($logoAffiche, $pw, 18, 51);
        $badgeX = $logoCardW > 0 ? $px + $logoCardW + MBI_SUPPORTS_BADGE_LOGO_GAP : $px;
        $badgeW = $logoCardW > 0 ? $pw - $logoCardW - MBI_SUPPORTS_BADGE_LOGO_GAP : $pw;
        // Ligne 1 : RÉF (or) ; Ligne 2 : ville en bleu pétrole, +20 % (centrées).
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
        $pdf->SetXY($badgeX, $py);
        $pdf->Cell($badgeW, 5, 'RÉF ' . mb_strtoupper((string)$ref, 'UTF-8'), 0, 0, 'C');
        $py += 5.5;
        if ($loc !== '') {
            $pdf->SetFont('dejavusans', 'B', 12);   // +20 % (10 → 12)
            $pdf->SetTextColor(38, 96, 110);        // bleu pétrole
            $pdf->SetXY($badgeX, $py);
            $pdf->Cell($badgeW, 6, mb_strtoupper($loc, 'UTF-8'), 0, 0, 'C');
            $py += 7;
        }
        $py += 2;

        // NB : le titre headline (« 3 PIÈCES · VILLE ») est volontairement SUPPRIMÉ ici
        // car déjà présent dans la card titre à gauche → on gagne de la place pour le
        // descriptif « L'annonce » qui s'affiche ainsi en entier.

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
        if ($nbPcs)                                   $caracs[] = ['Pièces', (string)$nbPcs];
        if ($etage !== null && (string)$etage !== '') $caracs[] = ['Étage', (string)$etage];

        if (!empty($caracs)) {
            $nbC = count($caracs);
            // Mini-cards reduites de ~10 % (hauteur + largeur du groupe), centrees
            $groupW = $pw * 0.90;
            $startX = $px + ($pw - $groupW) / 2;
            $cellW = ($groupW - (($nbC - 1) * 8)) / $nbC;
            $cellH = 34;
            // Taille de valeur UNIFORME (calee sur la valeur la plus longue)
            $valLenMax = 1;
            foreach ($caracs as $it) { $valLenMax = max($valLenMax, mb_strlen((string)$it[1], 'UTF-8')); }
            $uniform = $valLenMax <= 2 ? 47.0
                     : ($valLenMax <= 4 ? 43.0
                     : ($valLenMax <= 5 ? 34.0 : 27.0));
            foreach ($caracs as $i => $it) {
                $x = $startX + ($i * ($cellW + 8));
                mbi_supports_tpl_carac_mini(
                    $pdf, $x, $py, $cellW, $cellH,
                    $it[0], $it[1], $cP, $cT,
                    [255, 255, 255], null, $cP, true,
                    13.5, $uniform, 4.0, false
                );
            }
            $py += $cellH + 7;
        }

        // --- CONDITIONS (colonne gauche) + COPROPRIÉTÉ (colonne droite) alignées ---
        // 2 vraies colonnes côte à côte (1 liste par colonne) → plus compact en
        // hauteur, libère de la place pour le descriptif « L'annonce ».
        $lignesCF    = mbi_supports_get_conditions_financieres($bien);
        $lignesCopro = mbi_supports_get_copropriete_lignes($bien);
        $rowH = 5.2;
        $gapCol = 10;
        $colW = ($pw - $gapCol) / 2;

        $renderListe = function (string $titre, array $lignes, float $x0, float $yTop)
                       use ($pdf, $colW, $rowH, $cS, $cP): float {
            if (empty($lignes)) return $yTop;
            $pdf->SetFont('dejavusans', 'B', 8.5);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY($x0, $yTop);
            $pdf->Cell($colW, 4, mb_strtoupper($titre, 'UTF-8'), 0, 1, 'L');
            $y = $yTop + 5;
            foreach ($lignes as $i => $cf) {
                [$lab, $val] = $cf;
                $ly = $y + $i * $rowH;
                $pdf->SetFont('dejavusans', '', 10);
                $pdf->SetTextColor(110, 116, 130);
                $pdf->SetXY($x0, $ly);
                $pdf->Cell($colW * 0.55, 4.5, $lab . ' :', 0, 0, 'L');
                $pdf->SetFont('dejavusans', 'B', 10);
                $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
                $pdf->SetXY($x0 + $colW * 0.55, $ly);
                $pdf->Cell($colW * 0.45, 4.5, $val, 0, 0, 'R');
            }
            return $y + count($lignes) * $rowH;
        };

        $titreCF = (($infoPrix['type'] ?? '') === 'location') ? 'Conditions ALUR' : 'Conditions';
        $yGauche = $renderListe($titreCF,      $lignesCF,    $px,                   $py);
        $yDroite = $renderListe('Copropriété', $lignesCopro, $px + $colW + $gapCol, $py);
        $py = max($yGauche, $yDroite) + 5;

        // --- DPE / GES : classes normalisees ---
        $dpe = strtoupper(trim((string)($bien['dpe_classe'] ?? $bien['dpe'] ?? '')));
        $ges = strtoupper(trim((string)($bien['ges_classe'] ?? $bien['ges'] ?? '')));
        $dpeVal = (float)($bien['dpe_valeur'] ?? 0);
        $gesVal = (float)($bien['ges_valeur'] ?? 0);
        $dpeN = mbi_supports_classe_normalise($dpe);
        if ($dpeN === '' && $dpeVal > 0) $dpeN = mbi_supports_dpe_classe_depuis_valeur($dpeVal);
        $gesN = mbi_supports_classe_normalise($ges);
        if ($gesN === '' && $gesVal > 0) $gesN = mbi_supports_ges_classe_depuis_valeur($gesVal);

        $mentionDpe = '';
        if (!empty($mentionsTextes['dpe_en_cours']))   $mentionDpe = $mentionsTextes['dpe_en_cours'];
        if (!empty($mentionsTextes['dpe_non_soumis'])) $mentionDpe = $mentionsTextes['dpe_non_soumis'];

        // DPE/GES ancres TOUT EN BAS, sur une seule ligne (cote a cote)
        $dpeLineY = $photoH - 20 - ($mentionDpe !== '' ? 6 : 0);

        // --- ANNEXES : chips COMPACTS (largeur = contenu) qui s'enroulent, alignés
        //     à gauche — même style que mosaïque (fini les gros pavés 2 colonnes). ---
        $annexes     = mbi_supports_get_annexes($bien);
        $chipH = 7.0; $chipGap = 3.5; $annexLabelH = 6.0; $chipRowH = $chipH + 3;
        $pdf->SetFont('dejavusans', 'B', 8);
        $annexLines = 0;
        if (!empty($annexes)) {
            $annexLines = 1; $cur = 0.0;
            foreach ($annexes as $an) {
                $cw = $pdf->GetStringWidth('✓ ' . $an) + 7;
                if ($cur > 0 && $cur + $cw > $pw) { $annexLines++; $cur = 0; }
                $cur += $cw + $chipGap;
            }
        }
        $annexBlockH = $annexLines > 0 ? $annexLabelH + $annexLines * $chipRowH : 0;

        // Espace reserve en bas pour annexes + DPE
        $bottomReserve = $dpeLineY - ($annexBlockH > 0 ? $annexBlockH + 6 : 0);

        // --- L'ANNONCE (descriptif) - remplit l'espace disponible ---
        $descAnnonce = mbi_supports_get_description_annonce($bien);
        if ($descAnnonce !== '') {
            $py += 6;
            $pdf->SetDrawColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetLineWidth(0.6);
            $pdf->Line($px, $py, $px + 40, $py);
            $pdf->SetLineWidth(0.2);
            $py += 8;
            if ($py < $bottomReserve - 12) {
                $py = mbi_supports_tpl_bloc_annonce($pdf, $px, $py, $pw, ($bottomReserve - 4) - $py, $descAnnonce, $cS, [70, 76, 90]) + 4;
            }
        }

        // --- ANNEXES compactes sous l'annonce (chips qui s'enroulent) ---
        if (!empty($annexes)) {
            $ay = max($py, $dpeLineY - $annexBlockH);
            $pdf->SetFont('dejavusans', 'B', 8.5);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY($px, $ay);
            $pdf->Cell($pw, 4, mb_strtoupper('Les annexes', 'UTF-8'), 0, 1, 'L');
            $cx = $px; $cyy = $ay + $annexLabelH;
            $pdf->SetFont('dejavusans', 'B', 8);
            foreach ($annexes as $an) {
                $label = '✓ ' . $an;
                $cw = $pdf->GetStringWidth($label) + 7;
                if ($cx > $px && $cx + $cw > $px + $pw) { $cx = $px; $cyy += $chipRowH; }
                mbi_supports_tpl_card_round_shadow($pdf, $cx, $cyy, $cw, $chipH, $chipH / 2, $cS, 1.0, false);
                $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
                $pdf->SetXY($cx, $cyy + 0.2);
                $pdf->Cell($cw, $chipH - 0.4, $label, 0, 0, 'C');
                $cx += $cw + $chipGap;
            }
        }

        // --- DPE / GES sur UNE seule ligne, ancre tout en bas ---
        // Couleur pétrole pour le label + la valeur de consommation (lisible sur crème).
        mbi_supports_tpl_dpe_ges_pastille(
            $pdf, $px, $dpeLineY, 'DPE', $dpeN,
            $dpeVal > 0 ? $dpeVal : null, 'kWh/m²/an', $cPetrole, $cPetrole
        );
        mbi_supports_tpl_dpe_ges_pastille(
            $pdf, $px + $pw * 0.5, $dpeLineY, 'GES', $gesN,
            $gesVal > 0 ? $gesVal : null, 'kg CO₂/m²/an', $cPetrole, $cPetrole
        );

        // Mention DPE en cours / non soumis (sous les pastilles)
        if ($mentionDpe !== '') {
            $pdf->SetFont('dejavusans', 'I', 7.5);
            $pdf->SetTextColor(120, 80, 30);
            $pdf->SetXY($px, $dpeLineY + 18);
            $pdf->MultiCell($pw, 3.5, $mentionDpe, 0, 'L');
        }

        // --- 5. PIED bleu petrole unifie (helper partage par tous les layouts) ---
        mbi_supports_tpl_pied_landscape($pdf, $ctx, $cPetrole, $cS, $pageW, $pageH, $piedH);

        return $pdf;
    }
}
