<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Template : Affiche vitrine — A3 portrait (V2.1 — fix Unicode + A3)
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Format : A3 portrait (297×420 mm) par défaut — affichage vitrine grand format
 * Police : dejavusans (Unicode complet, gère «, », ★, ✓, m², €, etc.)
 *
 * Layout :
 *   1. Photo héro pleine largeur (190 mm) + gradient overlay bas
 *   2. Ruban "★ COUP DE CŒUR" doré si score IA ≥ 80
 *   3. Accroche commerciale en grand italique navy
 *   4. Prix XL doré + mention honoraires
 *   5. Caractéristiques : badges colorés cercle navy + lettre or
 *   6. Section "VOS ATOUTS" avec ✓ dorés (puise dans points_forts du score IA)
 *   7. Pied mentions légales fond navy + trait or
 * ═══════════════════════════════════════════════════════════════════════
 */

if (!function_exists('mbi_supports_tpl_affiche_vitrine_build')) {

    function mbi_supports_tpl_affiche_vitrine_build(array $ctx): TCPDF
    {
        $bien        = $ctx['bien']        ?? [];
        $photos      = $ctx['photos']      ?? [];
        $agence      = $ctx['agence']      ?? [];
        $negociateur = $ctx['negociateur'] ?? [];
        $style       = $ctx['style']       ?? [];
        $score       = $ctx['score']       ?? null;
        $critique    = $ctx['critique']    ?? [];
        $mentionsTextes = $critique['mentions_textes'] ?? [];
        // Rédaction IA (Lot 2) — accroche / paragraphe / atouts générés selon l'angle
        $iaRed       = is_array($ctx['ia_redaction'] ?? null) ? ($ctx['ia_redaction']['data'] ?? []) : [];
        $iaAccroche  = trim((string)($iaRed['accroche']   ?? ''));
        $iaParagraph = trim((string)($iaRed['paragraphe'] ?? ''));
        $iaAtouts    = is_array($iaRed['atouts'] ?? null) ? $iaRed['atouts'] : [];

        $cP = $style['rgb_primaire']   ?? [36, 59, 92];
        $cS = $style['rgb_secondaire'] ?? [212, 160, 71];
        $cT = $style['rgb_texte']      ?? [31, 41, 55];

        // Palette par angle marketing (Lot 3) — accent secondaire varié
        // L'angle vient soit de $ctx['angle'], soit du score recommandé.
        $angle = (string)($ctx['angle'] ?? 'generique');
        $anglePal = mbi_supports_tpl_angle_palette($angle, $cS);
        $cAngle   = $anglePal['rgb'];     // RGB pour bandeau / badge
        $libAngle = $anglePal['libelle']; // libellé à imprimer

        // ─── A3 portrait (297 × 420 mm) ─────────────────────────────────
        $pdf = new TCPDF('P', 'mm', 'A3', true, 'UTF-8');
        $pdf->SetCreator('MaBoxImmo - Ma Box Communication');
        $pdf->SetTitle('Affiche vitrine');
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        // Police Unicode obligatoire pour les caractères spéciaux (★, ✓, «, »)
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->AddPage();

        $pageW = 297;
        $pageH = 420;
        $marginX = 18;
        $contentW = $pageW - 2 * $marginX;

        // ─────────────────────────────────────────────────────────────
        // 1. PHOTO HÉRO PLEINE LARGEUR (0 → 190 mm)
        // ─────────────────────────────────────────────────────────────
        $heroH = 190;
        $hero = mbi_supports_photo_hero($photos, $ctx['photo_hero_id_suggestion'] ?? null);
        $heroPath = $hero ? mbi_supports_resoudre_photo_path($hero) : null;

        if ($heroPath !== null) {
            try {
                $pdf->Image($heroPath, 0, 0, $pageW, $heroH, '', '', '', false, 250, '', false, false, 0, 'CM', false, false);
            } catch (Throwable) {
                mbi_supports_tpl_v2_placeholder($pdf, 0, 0, $pageW, $heroH, $cP);
            }
        } else {
            mbi_supports_tpl_v2_placeholder($pdf, 0, 0, $pageW, $heroH, $cP);
        }

        // Gradient overlay bas (effet premium + lisibilité texte)
        $pdf->SetAlpha(0.65);
        $pdf->SetFillColor(0, 0, 0);
        $pdf->Rect(0, $heroH - 42, $pageW, 42, 'F');
        $pdf->SetAlpha(1.0);

        // Ref + ville en blanc (bas du gradient)
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY($marginX, $heroH - 18);
        $ref = (string)($bien['reference_bien'] ?? ('#' . ($bien['id'] ?? '')));
        $loc = trim((string)($bien['code_postal'] ?? '') . ' ' . ($bien['ville'] ?? ''));
        $pdf->Cell($contentW, 7, mb_strtoupper('Réf ' . $ref . ($loc !== '' ? '   ·   ' . $loc : '')), 0, 0, 'L');

        // ─────────────────────────────────────────────────────────────
        // 2. BADGE D'ANGLE (toujours présent — Lot 3) + RUBAN coup de cœur
        // ─────────────────────────────────────────────────────────────
        // Badge d'angle en haut à droite, sur fond couleur de l'angle.
        if ($angle !== 'generique' && $libAngle !== '') {
            $badgeW = 90;
            $badgeH = 16;
            $badgeX = $pageW - $badgeW - 14;
            $badgeY = 18;
            $pdf->SetFillColor($cAngle[0], $cAngle[1], $cAngle[2]);
            $pdf->Rect($badgeX, $badgeY, $badgeW, $badgeH, 'F');
            $pdf->SetFont('dejavusans', 'B', 11);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY($badgeX, $badgeY);
            $pdf->Cell($badgeW, $badgeH, mb_strtoupper($libAngle), 0, 0, 'C');
        }

        // Ruban "★ Coup de cœur" (sous le badge d'angle si les deux présents)
        $scoreNum = $score ? (int)($score['score'] ?? 0) : 0;
        if ($scoreNum >= 80) {
            $rubanW = 80;
            $rubanH = 18;
            $rubanX = $pageW - $rubanW - 14;
            $rubanY = ($angle !== 'generique' && $libAngle !== '') ? 38 : 18;
            $pdf->SetFillColor($cS[0], $cS[1], $cS[2]);
            $pdf->Rect($rubanX, $rubanY, $rubanW, $rubanH, 'F');
            $pdf->SetFont('dejavusans', 'B', 12);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY($rubanX, $rubanY);
            $pdf->Cell($rubanW, $rubanH, '★  COUP DE CŒUR', 0, 0, 'C');
        }

        // ─────────────────────────────────────────────────────────────
        // 3. ACCROCHE COMMERCIALE (grande italique navy)
        // ─────────────────────────────────────────────────────────────
        $blockY = $heroH + 14;
        // Priorité : surcharge éditeur > accroche IA > designation bien
        $accroche = trim((string)($bien['_accroche'] ?? ''));
        if ($accroche === '' && $iaAccroche !== '') {
            $accroche = $iaAccroche;
        }
        if ($accroche === '') {
            $accroche = mb_substr((string)($bien['designation'] ?? 'Bien à découvrir'), 0, 110);
        }
        $pdf->SetFont('dejavusans', 'BI', 24);
        $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
        $pdf->SetXY($marginX, $blockY);
        $pdf->MultiCell($contentW, 11, '« ' . $accroche . ' »', 0, 'L');
        $blockY = $pdf->GetY() + 6;

        // ─────────────────────────────────────────────────────────────
        // 4. PRIX XL DORÉ + mention honoraires
        // ─────────────────────────────────────────────────────────────
        $prix = mbi_supports_get_prix($bien);
        $pdf->SetFont('dejavusans', 'B', 54);
        $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
        $pdf->SetXY($marginX, $blockY);
        $pdf->Cell($contentW, 20, mbi_supports_format_prix($prix), 0, 1, 'L');

        $charge = mbi_supports_tpl_charge_honoraires($bien);
        if ($charge !== '') {
            $pdf->SetFont('dejavusans', '', 12);
            $pdf->SetTextColor(110, 116, 130);
            $pdf->SetX($marginX);
            $pdf->Cell($contentW, 6, $charge, 0, 1, 'L');
        }
        $blockY = $pdf->GetY() + 6;

        // Trait or fin séparateur
        $pdf->SetDrawColor($cS[0], $cS[1], $cS[2]);
        $pdf->SetLineWidth(1.2);
        $pdf->Line($marginX, $blockY, $marginX + 70, $blockY);
        $pdf->SetLineWidth(0.2);
        $blockY += 10;

        // ─────────────────────────────────────────────────────────────
        // 5. CARACTÉRISTIQUES — badges colorés (jusqu'à 6, 3 par ligne)
        // ─────────────────────────────────────────────────────────────
        $surf  = mbi_supports_get_surface($bien);
        $nbPcs = $bien['nb_pieces'] ?? $bien['nombre_pieces'] ?? null;
        $etage = $bien['etage'] ?? null;
        $expo  = $bien['exposition'] ?? $bien['orientation'] ?? null;
        $dpe   = strtoupper(trim((string)($bien['dpe_classe'] ?? $bien['dpe'] ?? '')));
        $ges   = strtoupper(trim((string)($bien['ges_classe'] ?? $bien['ges'] ?? '')));
        $estCopro = (int)($bien['bien_en_copropriete'] ?? 0) === 1;
        $coproLots = $bien['copro_nb_lots'] ?? null;

        $items = [];
        if ($surf !== null)            $items[] = ['M2',     mbi_supports_format_surface($surf)];
        if ($nbPcs)                    $items[] = ['Pces', (string)$nbPcs];
        if ($dpe !== '' || $ges !== '')$items[] = ['DPE/GES', ($dpe ?: '—') . ' / ' . ($ges ?: '—')];
        if ($etage !== null && $etage !== '') $items[] = ['Etage', (string)$etage];
        if ($expo)                     $items[] = ['Expo',   (string)$expo];
        if ($estCopro && $coproLots)   $items[] = ['Copro',  $coproLots . ' lots'];

        $cellW = ($contentW - 8) / 3;
        $cellH = 22;
        $col = 0; $row = 0;
        foreach ($items as $it) {
            $x = $marginX + ($col * ($cellW + 4));
            $y = $blockY + ($row * ($cellH + 4));
            mbi_supports_tpl_v2_badge($pdf, $x, $y, $cellW, $cellH, $it[0], $it[1], $cP, $cS, $cT);
            $col++;
            if ($col === 3) { $col = 0; $row++; }
        }
        $blockY += (($row + ($col > 0 ? 1 : 0)) * ($cellH + 4)) + 10;

        // ─────────────────────────────────────────────────────────────
        // 6. VOS ATOUTS — atouts IA rédaction prioritaires, fallback sur score
        // ─────────────────────────────────────────────────────────────
        $pointsForts = $iaAtouts;
        if (empty($pointsForts) && $score && !empty($score['points_forts_json'])) {
            $pointsForts = json_decode((string)$score['points_forts_json'], true) ?: [];
        }

        if (!empty($pointsForts)) {
            $boxY = $blockY;
            $boxH = 10 + (min(3, count($pointsForts)) * 9) + 4;
            $pdf->SetFillColor(252, 248, 240);
            $pdf->Rect($marginX, $boxY, $contentW, $boxH, 'F');
            $pdf->SetDrawColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetLineWidth(0.8);
            $pdf->Line($marginX, $boxY, $marginX, $boxY + $boxH);
            $pdf->SetLineWidth(0.2);

            $pdf->SetFont('dejavusans', 'B', 13);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY($marginX + 6, $boxY + 4);
            $pdf->Cell($contentW - 12, 6, mb_strtoupper('Vos atouts'), 0, 1, 'L');

            $i = 0;
            foreach (array_slice($pointsForts, 0, 3) as $pf) {
                $pdf->SetXY($marginX + 6, $boxY + 12 + ($i * 9));
                // checkmark or
                $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
                $pdf->SetFont('dejavusans', 'B', 14);
                $pdf->Cell(7, 7, '✓', 0, 0, 'L');
                // texte
                $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
                $pdf->SetFont('dejavusans', '', 13);
                $pdf->Cell($contentW - 18, 7, mb_substr((string)$pf, 0, 110), 0, 0, 'L');
                $i++;
            }
            $blockY = $boxY + $boxH + 10;
        }

        // ── Texte de l'annonce / description du bien ─────────────────────
        // Source en cascade :
        //   1. surcharge éditeur (description_personnalisee)
        //   2. paragraphe IA rédigé pour l'angle courant (Lot 2)
        //   3. annonce.description / annonce.texte_ia
        //   4. bien.description
        $descCommerciale = (string)($bien['_annonce_description'] ?? '');
        if ($descCommerciale === '' && $iaParagraph !== '') {
            $descCommerciale = $iaParagraph;
        }
        if ($descCommerciale === '') {
            $descCommerciale = (string)($bien['description'] ?? $bien['descriptif'] ?? '');
        }
        if ($descCommerciale !== '') {
            $hasAtouts = !empty($pointsForts);
            $hasAnnonce = !empty($bien['_annonce_description']);

            // Titre de section (selon source)
            $pdf->SetFont('dejavusans', 'B', 12);
            $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
            $pdf->SetXY($marginX, $blockY);
            $titreSection = $hasAnnonce ? 'L\'ANNONCE' : 'DESCRIPTION';
            $pdf->Cell($contentW, 6, $titreSection, 0, 1, 'L');
            $blockY = $pdf->GetY() + 1;

            // Trait or court
            $pdf->SetDrawColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetLineWidth(0.6);
            $pdf->Line($marginX, $blockY, $marginX + 30, $blockY);
            $pdf->SetLineWidth(0.2);
            $blockY += 4;

            // Texte
            $pdf->SetFont('dejavusans', '', 11);
            $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
            $pdf->SetXY($marginX, $blockY);
            // Sur A3 on peut se permettre 800 chars (3-4 paragraphes)
            $maxLen = $hasAtouts ? 600 : 900;
            $extrait = mb_substr($descCommerciale, 0, $maxLen);
            if (mb_strlen($descCommerciale) > $maxLen) $extrait .= '...';
            $pdf->MultiCell($contentW, 5.5, $extrait, 0, 'J');
            $blockY = $pdf->GetY() + 6;
        }

        // ─────────────────────────────────────────────────────────────
        // 7. Mention DPE en cours / non soumis si applicable
        // ─────────────────────────────────────────────────────────────
        $mentionDpe = '';
        if (!empty($mentionsTextes['dpe_en_cours']))    $mentionDpe = $mentionsTextes['dpe_en_cours'];
        if (!empty($mentionsTextes['dpe_non_soumis']))  $mentionDpe = $mentionsTextes['dpe_non_soumis'];
        if ($mentionDpe !== '') {
            $pdf->SetFont('dejavusans', 'I', 10);
            $pdf->SetTextColor(120, 80, 30);
            $pdf->SetXY($marginX, $blockY);
            $pdf->MultiCell($contentW, 5, $mentionDpe, 0, 'L');
        }

        // ─────────────────────────────────────────────────────────────
        // 8. PIED mentions légales (toujours en bas, fond navy)
        // ─────────────────────────────────────────────────────────────
        mbi_supports_tpl_v2_pied($pdf, $ctx, $cP, $cS, $pageW, $pageH);

        return $pdf;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Helpers V2 (placeholder, badge, pied) — adaptés A3
// ─────────────────────────────────────────────────────────────────────────

if (!function_exists('mbi_supports_tpl_angle_palette')) {
    /**
     * Palette par angle marketing — accent secondaire et libellé pour le badge.
     * Renvoie ['rgb' => [r,g,b], 'libelle' => 'Pour la famille', …]
     * @param array $cSDefault Couleur secondaire de la charte (fallback)
     */
    function mbi_supports_tpl_angle_palette(string $angle, array $cSDefault): array
    {
        $palettes = [
            'famille'        => ['rgb' => [42, 122, 95],  'libelle' => 'Pour la famille'],   // vert sapin
            'investisseur'   => ['rgb' => [66, 96, 140],  'libelle' => 'Investisseur'],      // bleu acier
            'premium'        => ['rgb' => [165, 124, 50], 'libelle' => 'Premium'],           // or profond
            'premier_achat'  => ['rgb' => [192, 102, 70], 'libelle' => 'Primo-accédant'],    // terracotta
        ];
        if (isset($palettes[$angle])) return $palettes[$angle];
        return ['rgb' => $cSDefault, 'libelle' => ''];
    }
}

if (!function_exists('mbi_supports_tpl_v2_placeholder')) {
    function mbi_supports_tpl_v2_placeholder(TCPDF $pdf, float $x, float $y, float $w, float $h, array $color): void
    {
        $pdf->SetFillColor(225, 230, 240);
        $pdf->Rect($x, $y, $w, $h, 'F');
        $pdf->SetDrawColor(200, 208, 220);
        $pdf->SetLineWidth(0.4);
        for ($i = -50; $i < $w + $h; $i += 14) {
            $pdf->Line($x + $i, $y, $x + $i + $h, $y + $h);
        }
        $pdf->SetFont('dejavusans', 'B', 18);
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
        $pdf->SetXY($x, $y + ($h/2) - 5);
        $pdf->Cell($w, 10, 'PHOTO À AJOUTER', 0, 0, 'C');
    }
}

if (!function_exists('mbi_supports_tpl_v2_badge')) {
    function mbi_supports_tpl_v2_badge(TCPDF $pdf, float $x, float $y, float $w, float $h, string $label, string $value, array $cP, array $cS, array $cT): void
    {
        // Cercle navy à gauche
        $cR = $h / 2 - 1;
        $pdf->SetFillColor($cP[0], $cP[1], $cP[2]);
        $pdf->Circle($x + $cR + 1, $y + $cR + 1, $cR, 0, 360, 'F');

        // Label dans le cercle (or)
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
        $pdf->SetXY($x, $y + 2);
        $pdf->Cell($cR * 2 + 2, $cR * 2 - 2, mb_substr($label, 0, 5), 0, 0, 'C');

        // Texte à droite : caption + valeur
        $textX = $x + $cR * 2 + 5;
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->SetTextColor(110, 116, 130);
        $pdf->SetXY($textX, $y + 2);
        $pdf->Cell($w - $cR * 2 - 6, 5, mb_strtoupper($label), 0, 0, 'L');

        $pdf->SetFont('dejavusans', 'B', 14);
        $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
        $pdf->SetXY($textX, $y + 9);
        $pdf->Cell($w - $cR * 2 - 6, 8, $value, 0, 0, 'L');
    }
}

if (!function_exists('mbi_supports_tpl_v2_pied')) {
    function mbi_supports_tpl_v2_pied(TCPDF $pdf, array $ctx, array $cP, array $cS, float $pageW = 210, float $pageH = 297): void
    {
        $agence = $ctx['agence'] ?? [];
        $bien   = $ctx['bien']   ?? [];
        $negociateur = $ctx['negociateur'] ?? [];

        $piedH = 44;
        $piedY = $pageH - $piedH;
        $pdf->SetFillColor($cP[0], $cP[1], $cP[2]);
        $pdf->Rect(0, $piedY, $pageW, $piedH, 'F');

        // Trait or fin en haut du pied
        $pdf->SetFillColor($cS[0], $cS[1], $cS[2]);
        $pdf->Rect(0, $piedY, $pageW, 1.6, 'F');

        // Nom agence
        $pdf->SetFont('dejavusans', 'B', 14);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(18, $piedY + 5);
        $nomAg = (string)($agence['nom_agence'] ?? $agence['nom'] ?? 'Agence');
        $pdf->Cell(180, 7, $nomAg, 0, 1, 'L');

        // Coordonnées
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetTextColor(220, 222, 230);
        $pdf->SetXY(18, $piedY + 14);
        $coord = trim((string)($agence['adresse'] ?? '') . ' · ' . trim((string)($agence['code_postal'] ?? '') . ' ' . ($agence['ville'] ?? '')) . ' · ' . ($agence['telephone'] ?? '') . ' · ' . ($agence['email'] ?? ''), ' ·');
        $pdf->Cell($pageW - 36, 5, $coord, 0, 1, 'L');

        // Carte pro / garant / RC pro
        // Refactor 2026-05-08 : pioche la bonne RCP/GF selon l'activité du bien.
        // - Vente → activité Transaction (T)
        // - Location → activité Gestion (G)
        // - Fallback : valeur générique au niveau société (helper agence_load_with_societe_docs).
        $typeTr = strtolower((string)($bien['type_transaction'] ?? ''));
        $activiteCible = match (true) {
            str_contains($typeTr, 'vente'),
            str_contains($typeTr, 'cession') => 'transaction',
            str_contains($typeTr, 'location') => 'gestion',
            default => null,
        };
        $rcpAct = ($activiteCible && !empty($agence['activites'][$activiteCible]['rc_pro_assureur']))
            ? (string)$agence['activites'][$activiteCible]['rc_pro_assureur']
            : '';
        $gfAct  = ($activiteCible && !empty($agence['activites'][$activiteCible]['garant_nom']))
            ? (string)$agence['activites'][$activiteCible]['garant_nom']
            : '';

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

        // Copro mention si applicable
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

        // Négociateur en bas (doré discret à droite)
        if ($negociateur) {
            $nego = trim(($negociateur['prenom'] ?? '') . ' ' . ($negociateur['nom'] ?? ''));
            $tel  = trim((string)($negociateur['telephone_pro'] ?? ''));
            $pdf->SetFont('dejavusans', 'B', 10);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY(18, $piedY + 35);
            $pdf->Cell($pageW - 36, 4, 'Votre contact : ' . $nego . ($tel ? ' · ' . $tel : ''), 0, 0, 'R');
        }

        // "Partenaire MaBoxImmo" en doré italique discret (en bas à gauche)
        $pdf->SetFont('dejavusans', 'I', 8);
        $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
        $pdf->SetXY(18, $piedY + 36);
        $pdf->Cell(80, 4, 'Partenaire MaBoxImmo', 0, 0, 'L');
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Helpers conservés (utilisés par fiche_client.php aussi)
// ─────────────────────────────────────────────────────────────────────────

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
        $pdf->Cell($w - 4, 5, mb_strtoupper($label), 0, 0, 'L');
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
        // Pour la fiche client (pied multi-page léger, pas le pied vitrine plein)
        $agence    = $ctx['agence']    ?? [];
        $negociateur = $ctx['negociateur'] ?? [];

        $pdf->SetY(-26);
        $pdf->SetDrawColor(180, 180, 190);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(1);

        // Pied fiche client : pioche RCP/GF selon activité du bien (vente=T, location=G)
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
