<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Template : Fiche client (PDF remis aux acquéreurs potentiels)
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Multi-page A4 :
 *   1. Couverture : photo héro + désignation + prix + caractéristiques
 *   2. Description complète + énergie (DPE/GES)
 *   3. Galerie photos (jusqu'à 6)
 *   4. Mentions légales complètes + coordonnées agence/négociateur
 * ═══════════════════════════════════════════════════════════════════════
 */

if (!function_exists('mbi_supports_tpl_fiche_client_build')) {

    function mbi_supports_tpl_fiche_client_build(array $ctx): TCPDF
    {
        require_once __DIR__ . '/mbi_supports_tpl_affiche_vitrine.php'; // helpers communs

        $bien        = $ctx['bien']        ?? [];
        $photos      = $ctx['photos']      ?? [];
        $agence      = $ctx['agence']      ?? [];
        $negociateur = $ctx['negociateur'] ?? [];
        $style       = $ctx['style']       ?? [];
        $critique    = $ctx['critique']    ?? [];
        $mentionsTextes = $critique['mentions_textes'] ?? [];

        $cP = $style['rgb_primaire']   ?? [36, 59, 92];
        $cS = $style['rgb_secondaire'] ?? [212, 160, 71];
        $cT = $style['rgb_texte']      ?? [31, 41, 55];

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('MaBoxImmo — Ma Box Communication');
        $pdf->SetTitle('Fiche client');
        $pdf->SetMargins(15, 18, 15);
        $pdf->SetAutoPageBreak(true, 18);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        // ─── PAGE 1 : Couverture ─────────────────────────────────────────
        $pdf->AddPage();

        // En-tête agence
        $pdf->SetFillColor($cP[0], $cP[1], $cP[2]);
        $pdf->Rect(0, 0, 210, 14, 'F');
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetTextColor(255,255,255);
        $pdf->SetXY(15, 3);
        $pdf->Cell(180, 5, (string)($agence['nom_agence'] ?? $agence['nom'] ?? 'Agence'), 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetX(15);
        $pdf->Cell(180, 4, 'Fiche descriptive — ' . date('d/m/Y'), 0, 1, 'L');

        // Photo héro
        $hero = mbi_supports_photo_hero($photos, $ctx['photo_hero_id_suggestion'] ?? null);
        $heroPath = $hero ? mbi_supports_resoudre_photo_path($hero) : null;
        if ($heroPath) {
            try { $pdf->Image($heroPath, 15, 18, 180, 90, '', '', '', false, 200, '', false, false, 1, 'CM', false, false); }
            catch (Throwable) { mbi_supports_tpl_placeholder($pdf, 15, 18, 180, 90, $cP); }
        } else {
            mbi_supports_tpl_placeholder($pdf, 15, 18, 180, 90, $cP);
        }

        // Désignation + prix
        $designation = (string)($bien['designation'] ?? $bien['reference_bien'] ?? 'Bien');
        $prix        = mbi_supports_get_prix($bien);

        $pdf->SetXY(15, 112);
        $pdf->SetFont('helvetica', 'B', 18);
        $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
        $pdf->MultiCell(180, 8, $designation, 0, 'L');

        $pdf->SetFont('helvetica', 'B', 26);
        $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
        $pdf->SetXY(15, $pdf->GetY() + 2);
        $pdf->Cell(180, 12, mbi_supports_format_prix($prix), 0, 1, 'L');

        $charge = mbi_supports_tpl_charge_honoraires($bien);
        if ($charge !== '') {
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetTextColor(110, 116, 130);
            $pdf->SetX(15);
            $pdf->Cell(180, 5, $charge, 0, 1, 'L');
        }

        // Caractéristiques (4 mini-cards)
        $cardY = $pdf->GetY() + 8;
        $surf   = mbi_supports_get_surface($bien);
        $nbPcs  = $bien['nb_pieces'] ?? $bien['nombre_pieces'] ?? null;
        $etage  = $bien['etage'] ?? null;
        $expo   = $bien['exposition'] ?? $bien['orientation'] ?? null;
        mbi_supports_tpl_card($pdf, 15,  $cardY, 42, 22, 'Surface', mbi_supports_format_surface($surf), $cP, $cT);
        mbi_supports_tpl_card($pdf, 60,  $cardY, 42, 22, 'Pièces',  $nbPcs ? (string)$nbPcs : '—', $cP, $cT);
        mbi_supports_tpl_card($pdf, 105, $cardY, 42, 22, 'Étage',   $etage !== null && $etage !== '' ? (string)$etage : '—', $cP, $cT);
        mbi_supports_tpl_card($pdf, 150, $cardY, 45, 22, 'Exposition', (string)($expo ?: '—'), $cP, $cT);

        // Localisation
        $cardY += 28;
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
        $pdf->SetXY(15, $cardY);
        $pdf->Cell(180, 6, 'Localisation', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
        $pdf->SetX(15);
        $loc = trim((string)($bien['code_postal'] ?? '') . ' ' . ($bien['ville'] ?? ''));
        $pdf->Cell(180, 5, $loc !== '' ? $loc : '—', 0, 1, 'L');

        // ─── PAGE 2 : Description + énergie ──────────────────────────────
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
        $pdf->Cell(180, 8, 'Description', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
        $desc = (string)($bien['description'] ?? $bien['descriptif'] ?? '');
        $pdf->MultiCell(180, 5, $desc !== '' ? $desc : '—', 0, 'L');

        $pdf->Ln(4);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
        $pdf->Cell(180, 8, 'Performance énergétique', 0, 1, 'L');

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
        $dpe = (string)($bien['dpe_classe'] ?? $bien['dpe'] ?? '—');
        $ges = (string)($bien['ges_classe'] ?? $bien['ges'] ?? '—');
        $dpeVal = $bien['dpe_valeur'] ?? null;
        $gesVal = $bien['ges_valeur'] ?? null;
        $pdf->MultiCell(180, 5,
            "DPE : classe " . $dpe . ($dpeVal ? " ({$dpeVal} kWh/m²/an)" : '') . "\n" .
            "GES : classe " . $ges . ($gesVal ? " ({$gesVal} kgCO₂/m²/an)" : ''),
            0, 'L');

        if (!empty($mentionsTextes['dpe_en_cours'])) {
            $pdf->Ln(2);
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->SetTextColor(120, 80, 30);
            $pdf->MultiCell(180, 5, $mentionsTextes['dpe_en_cours'], 0, 'L');
        }
        if (!empty($mentionsTextes['dpe_non_soumis'])) {
            $pdf->Ln(2);
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->SetTextColor(120, 80, 30);
            $pdf->MultiCell(180, 5, $mentionsTextes['dpe_non_soumis'], 0, 'L');
        }

        // ─── PAGE 3 : Galerie photos (jusqu'à 6) ─────────────────────────
        $autres = array_slice(array_filter($photos, fn($p) => mbi_supports_resoudre_photo_path($p) !== null), 0, 6);
        if (!empty($autres)) {
            $pdf->AddPage();
            $pdf->SetFont('helvetica', 'B', 14);
            $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
            $pdf->Cell(180, 8, 'Galerie', 0, 1, 'L');
            $pdf->Ln(3);
            $cellW = 88; $cellH = 60;
            $col = 0; $rowY = $pdf->GetY();
            foreach ($autres as $p) {
                $px = 15 + ($col * ($cellW + 4));
                $py = $rowY;
                $absP = mbi_supports_resoudre_photo_path($p);
                if ($absP) {
                    try { $pdf->Image($absP, $px, $py, $cellW, $cellH, '', '', '', false, 150, '', false, false, 1, 'CM'); }
                    catch (Throwable) { mbi_supports_tpl_placeholder($pdf, $px, $py, $cellW, $cellH, $cP); }
                }
                $col++;
                if ($col === 2) { $col = 0; $rowY += $cellH + 4; }
            }
        }

        // ─── PAGE FINALE : Mentions légales + coordonnées ────────────────
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
        $pdf->Cell(180, 8, 'Honoraires', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
        $hDetail = trim((string)($bien['honoraires_detail'] ?? ''));
        $hInclus = trim((string)($bien['honoraires_inclus'] ?? ''));
        $linesH = [];
        if ($hInclus !== '') $linesH[] = 'Honoraires inclus : ' . $hInclus;
        if ($hDetail !== '') $linesH[] = $hDetail;
        $pdf->MultiCell(180, 5, $linesH ? implode("\n", $linesH) : '—', 0, 'L');

        // Copro si applicable
        if ((int)($bien['bien_en_copropriete'] ?? 0) === 1) {
            $pdf->Ln(4);
            $pdf->SetFont('helvetica', 'B', 14);
            $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
            $pdf->Cell(180, 8, 'Copropriété', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
            $copLots = $bien['copro_nb_lots'] ?? '—';
            $copChg  = $bien['copro_quote_part_charges'] ?? null;
            $copProc = (int)($bien['copro_procedure'] ?? 0) === 1;
            $linesC = [
                "Nombre de lots : " . $copLots,
                "Charges courantes annuelles : " . ($copChg !== null ? number_format((float)$copChg, 0, ',', ' ') . ' €' : '—'),
                "Procédures L.611-1 et suivantes : " . ($copProc ? 'OUI' : 'NON'),
            ];
            $pdf->MultiCell(180, 5, implode("\n", $linesC), 0, 'L');
        }

        // ERP
        $pdf->Ln(4);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
        $pdf->Cell(180, 8, 'État des risques (ERP)', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
        $pdf->MultiCell(180, 5, "État des risques disponible en agence sur demande.", 0, 'L');

        // Coordonnées
        $pdf->Ln(6);
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
        $pdf->Cell(180, 8, 'Vos contacts', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
        $blocAg = [];
        $blocAg[] = (string)($agence['nom_agence'] ?? $agence['nom'] ?? '');
        $blocAg[] = trim((string)($agence['adresse'] ?? '') . ' · ' . ($agence['code_postal'] ?? '') . ' ' . ($agence['ville'] ?? ''));
        if (!empty($agence['telephone'])) $blocAg[] = 'Tél. ' . $agence['telephone'];
        if (!empty($agence['email']))     $blocAg[] = $agence['email'];
        $pdf->MultiCell(180, 5, implode("\n", array_filter($blocAg)), 0, 'L');

        if ($negociateur) {
            $pdf->Ln(2);
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(180, 5, 'Votre négociateur', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 10);
            $blocN = [];
            $blocN[] = trim(($negociateur['prenom'] ?? '') . ' ' . ($negociateur['nom'] ?? ''));
            if (!empty($negociateur['telephone'])) $blocN[] = 'Tél. ' . $negociateur['telephone'];
            if (!empty($negociateur['email']))     $blocN[] = $negociateur['email'];
            $pdf->MultiCell(180, 5, implode("\n", array_filter($blocN)), 0, 'L');
        }

        // Pied : mentions légales (carte pro / RC pro / garant)
        mbi_supports_tpl_pied_mentions($pdf, $ctx, $cP, $cT);

        return $pdf;
    }
}
