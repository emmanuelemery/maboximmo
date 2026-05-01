<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Template : Affiche vitrine — A4 portrait
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Layout :
 *   - Bandeau supérieur agence (logo + nom + ville)
 *   - Photo héro (≈ 50 % hauteur)
 *   - Désignation + prix (bandeau navy)
 *   - Caractéristiques (3 colonnes : surface / pièces / DPE)
 *   - Description courte (extrait 4 lignes)
 *   - Pied de page : mentions légales (carte pro, honoraires, copro)
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
        $critique    = $ctx['critique']    ?? [];
        $mentionsTextes = $critique['mentions_textes'] ?? [];

        $cP  = $style['rgb_primaire']   ?? [36, 59, 92];   // navy
        $cS  = $style['rgb_secondaire'] ?? [212, 160, 71]; // or
        $cT  = $style['rgb_texte']      ?? [31, 41, 55];

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('MaBoxImmo — Ma Box Communication');
        $pdf->SetTitle('Affiche vitrine');
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        // ── Bandeau agence ─────────────────────────────────────────────
        $pdf->SetFillColor($cP[0], $cP[1], $cP[2]);
        $pdf->Rect(0, 0, 210, 16, 'F');
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(12, 4);
        $nomAg = (string)($agence['nom_agence'] ?? $agence['nom'] ?? 'Agence');
        $pdf->Cell(186, 6, $nomAg, 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 8);
        $sousLigne = trim((string)($agence['adresse'] ?? '') . ' · ' . ($agence['ville'] ?? '') . ' · ' . ($agence['telephone'] ?? ''), ' ·');
        $pdf->SetXY(12, 10);
        $pdf->Cell(186, 4, $sousLigne, 0, 1, 'L');

        // ── Photo héro ─────────────────────────────────────────────────
        $hero = mbi_supports_photo_hero($photos, $ctx['photo_hero_id_suggestion'] ?? null);
        $heroPath = $hero ? mbi_supports_resoudre_photo_path($hero) : null;

        $imgY = 20;
        $imgH = 110;
        if ($heroPath !== null) {
            try {
                $pdf->Image($heroPath, 12, $imgY, 186, $imgH, '', '', '', false, 200, '', false, false, 1, 'CM', false, false);
            } catch (Throwable) {
                mbi_supports_tpl_placeholder($pdf, 12, $imgY, 186, $imgH, $cP);
            }
        } else {
            mbi_supports_tpl_placeholder($pdf, 12, $imgY, 186, $imgH, $cP);
        }

        // ── Bandeau désignation + prix ─────────────────────────────────
        $bandY = $imgY + $imgH + 4;
        $designation = mb_substr((string)($bien['designation'] ?? $bien['reference_bien'] ?? 'Bien'), 0, 80);
        $prix = mbi_supports_get_prix($bien);

        $pdf->SetFillColor($cP[0], $cP[1], $cP[2]);
        $pdf->Rect(12, $bandY, 186, 22, 'F');
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(16, $bandY + 2);
        $pdf->Cell(150, 8, $designation, 0, 1, 'L');

        $pdf->SetFont('helvetica', 'B', 22);
        $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
        $pdf->SetXY(16, $bandY + 11);
        $pdf->Cell(150, 9, mbi_supports_format_prix($prix), 0, 0, 'L');

        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(255, 255, 255);
        $charge = mbi_supports_tpl_charge_honoraires($bien);
        if ($charge !== '') {
            $pdf->SetXY(120, $bandY + 16);
            $pdf->Cell(74, 4, $charge, 0, 0, 'R');
        }

        // ── Caractéristiques (3 mini-cards) ────────────────────────────
        $caracY = $bandY + 28;
        $surf   = mbi_supports_get_surface($bien);
        $nbPcs  = $bien['nb_pieces'] ?? $bien['nombre_pieces'] ?? null;
        $dpe    = strtoupper(trim((string)($bien['dpe_classe'] ?? $bien['dpe'] ?? '—')));
        $ges    = strtoupper(trim((string)($bien['ges_classe'] ?? $bien['ges'] ?? '—')));

        mbi_supports_tpl_card($pdf, 12,  $caracY, 60, 22, 'Surface',  mbi_supports_format_surface($surf), $cP, $cT);
        mbi_supports_tpl_card($pdf, 75,  $caracY, 60, 22, 'Pièces',   $nbPcs ? (string)$nbPcs : '—', $cP, $cT);
        mbi_supports_tpl_card($pdf, 138, $caracY, 60, 22, 'DPE / GES', $dpe . ' / ' . $ges, $cP, $cT);

        // ── Mention DPE en cours / non soumis si applicable ────────────
        $mentionDpe = '';
        if (!empty($mentionsTextes['dpe_en_cours']))    $mentionDpe = $mentionsTextes['dpe_en_cours'];
        if (!empty($mentionsTextes['dpe_non_soumis']))  $mentionDpe = $mentionsTextes['dpe_non_soumis'];
        if ($mentionDpe !== '') {
            $pdf->SetFont('helvetica', 'I', 8);
            $pdf->SetTextColor(120, 80, 30);
            $pdf->SetXY(12, $caracY + 24);
            $pdf->MultiCell(186, 4, $mentionDpe, 0, 'C');
        }

        // ── Description courte ─────────────────────────────────────────
        $descY = $caracY + 30;
        $desc = (string)($bien['description'] ?? $bien['descriptif'] ?? '');
        if ($desc !== '') {
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
            $pdf->SetXY(12, $descY);
            $pdf->MultiCell(186, 4.5, mb_substr($desc, 0, 700), 0, 'L');
        }

        // ── Pied : mentions légales (toujours en bas) ──────────────────
        mbi_supports_tpl_pied_mentions($pdf, $ctx, $cP, $cT);

        return $pdf;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Helpers visuels (placés ici car simples, partagés dans les templates)
// ─────────────────────────────────────────────────────────────────────────

if (!function_exists('mbi_supports_tpl_placeholder')) {
    function mbi_supports_tpl_placeholder(TCPDF $pdf, float $x, float $y, float $w, float $h, array $color): void
    {
        $pdf->SetFillColor(230, 232, 238);
        $pdf->Rect($x, $y, $w, $h, 'F');
        $pdf->SetFont('helvetica', 'I', 11);
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
        $pdf->SetXY($x, $y + ($h/2) - 3);
        $pdf->Cell($w, 6, 'Photo non disponible', 0, 0, 'C');
    }
}

if (!function_exists('mbi_supports_tpl_card')) {
    function mbi_supports_tpl_card(TCPDF $pdf, float $x, float $y, float $w, float $h, string $label, string $value, array $cP, array $cT): void
    {
        $pdf->SetDrawColor($cP[0], $cP[1], $cP[2]);
        $pdf->SetFillColor(248, 249, 251);
        $pdf->Rect($x, $y, $w, $h, 'DF');
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(110, 116, 130);
        $pdf->SetXY($x + 2, $y + 2);
        $pdf->Cell($w - 4, 5, mb_strtoupper($label), 0, 0, 'L');
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
        $pdf->SetXY($x + 2, $y + 9);
        $pdf->Cell($w - 4, 9, $value, 0, 0, 'L');
    }
}

if (!function_exists('mbi_supports_tpl_charge_honoraires')) {
    function mbi_supports_tpl_charge_honoraires(array $bien): string
    {
        $inclus = trim((string)($bien['honoraires_inclus'] ?? ''));
        if ($inclus !== '') {
            // Cas typique : "oui charge acquéreur"
            return 'Honoraires inclus : ' . $inclus;
        }
        $charge = trim((string)($bien['honoraires_charge'] ?? ''));
        if ($charge !== '') {
            return 'Honoraires charge ' . $charge;
        }
        return '';
    }
}

if (!function_exists('mbi_supports_tpl_pied_mentions')) {
    function mbi_supports_tpl_pied_mentions(TCPDF $pdf, array $ctx, array $cP, array $cT): void
    {
        $agence    = $ctx['agence']    ?? [];
        $bien      = $ctx['bien']      ?? [];
        $negociateur = $ctx['negociateur'] ?? [];

        $pdf->SetY(-26);
        $pdf->SetDrawColor(180, 180, 190);
        $pdf->Line(12, $pdf->GetY(), 198, $pdf->GetY());
        $pdf->Ln(1);

        $cartePro = trim((string)($agence['carte_pro_numero'] ?? $agence['carte_pro'] ?? ''));
        $cci      = trim((string)($agence['carte_pro_cci']    ?? $agence['cci'] ?? ''));
        $garant   = trim((string)($agence['garant_financier'] ?? $agence['garantie_financiere'] ?? ''));
        $rcPro    = trim((string)($agence['rc_pro']           ?? $agence['assurance_rc_pro'] ?? ''));

        $lignes = [];
        if ($cartePro !== '') $lignes[] = 'Carte professionnelle n° ' . $cartePro . ($cci ? ' (' . $cci . ')' : '');
        if ($garant !== '')   $lignes[] = 'Garant financier : ' . $garant;
        if ($rcPro !== '')    $lignes[] = 'Assurance RC Pro : ' . $rcPro;

        // Copro si applicable
        if ((int)($bien['bien_en_copropriete'] ?? 0) === 1) {
            $copLots = $bien['copro_nb_lots'] ?? null;
            $copChg  = $bien['copro_quote_part_charges'] ?? null;
            $copProc = (int)($bien['copro_procedure'] ?? 0) === 1;
            $partsCopro = ['Copropriété'];
            if ($copLots) $partsCopro[] = $copLots . ' lots';
            if ($copChg)  $partsCopro[] = 'charges ~ ' . number_format((float)$copChg, 0, ',', ' ') . ' €/an';
            $partsCopro[] = $copProc ? 'procédures L611-1 en cours' : 'absence de procédures L611-1';
            $lignes[] = implode(' · ', $partsCopro);
        }

        if ($negociateur) {
            $nego = trim(($negociateur['prenom'] ?? '') . ' ' . ($negociateur['nom'] ?? ''));
            $tel  = trim((string)($negociateur['telephone'] ?? ''));
            $mail = trim((string)($negociateur['email']     ?? ''));
            $lignes[] = 'Négociateur : ' . $nego . ($tel ? ' · ' . $tel : '') . ($mail ? ' · ' . $mail : '');
        }

        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColor(80, 84, 95);
        foreach ($lignes as $li) {
            $pdf->SetX(12);
            $pdf->Cell(186, 3.5, $li, 0, 1, 'L');
        }
    }
}
