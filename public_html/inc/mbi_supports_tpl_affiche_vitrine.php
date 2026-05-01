<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Template : Affiche vitrine — A4 portrait (V2 — refonte commerciale)
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Layout :
 *   1. Photo héro pleine largeur (130 mm) avec gradient overlay bas
 *   2. Ruban "✨ COUP DE CŒUR" doré si score IA ≥ 80
 *   3. Accroche commerciale en grand italique (surcharge éditeur)
 *   4. Prix XL doré + mention honoraires
 *   5. Caractéristiques avec badges colorés
 *   6. Section "Vos atouts" alimentée par les points forts du score IA
 *   7. Pied mentions légales discret
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

        $cP = $style['rgb_primaire']   ?? [36, 59, 92];
        $cS = $style['rgb_secondaire'] ?? [212, 160, 71];
        $cT = $style['rgb_texte']      ?? [31, 41, 55];

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetCreator('MaBoxImmo — Ma Box Communication');
        $pdf->SetTitle('Affiche vitrine');
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        $pageW = 210;
        $pageH = 297;

        // ─────────────────────────────────────────────────────────────
        // 1. PHOTO HÉRO PLEINE LARGEUR (0 → 130 mm)
        // ─────────────────────────────────────────────────────────────
        $heroH = 130;
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
        $pdf->Rect(0, $heroH - 30, $pageW, 30, 'F');
        $pdf->SetAlpha(1.0);

        // Ref + ville en blanc (sur le bas du gradient)
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(12, $heroH - 14);
        $ref = (string)($bien['reference_bien'] ?? ('#' . ($bien['id'] ?? '')));
        $loc = trim((string)($bien['code_postal'] ?? '') . ' ' . ($bien['ville'] ?? ''));
        $pdf->Cell(180, 6, mb_strtoupper('Réf ' . $ref . ($loc !== '' ? '   ·   ' . $loc : '')), 0, 0, 'L');

        // ─────────────────────────────────────────────────────────────
        // 2. RUBAN "COUP DE CŒUR" si score ≥ 80
        // ─────────────────────────────────────────────────────────────
        $scoreNum = $score ? (int)($score['score'] ?? 0) : 0;
        if ($scoreNum >= 80) {
            $rubanW = 56;
            $rubanH = 14;
            $rubanX = $pageW - $rubanW - 8;
            $rubanY = 12;
            $pdf->SetFillColor($cS[0], $cS[1], $cS[2]);
            $pdf->Rect($rubanX, $rubanY, $rubanW, $rubanH, 'F');
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY($rubanX, $rubanY);
            $pdf->Cell($rubanW, $rubanH, '★ COUP DE CŒUR', 0, 0, 'C');
        }

        // ─────────────────────────────────────────────────────────────
        // 3. ACCROCHE COMMERCIALE (grande italique navy)
        // ─────────────────────────────────────────────────────────────
        $blockY = $heroH + 8;
        $accroche = trim((string)($bien['_accroche'] ?? ''));
        if ($accroche === '') {
            // Fallback : on dérive une accroche courte de la désignation si rien
            $accroche = mb_substr((string)($bien['designation'] ?? 'Bien à découvrir'), 0, 90);
        }
        $pdf->SetFont('helvetica', 'BI', 17);
        $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
        $pdf->SetXY(15, $blockY);
        $pdf->MultiCell(180, 8, '« ' . $accroche . ' »', 0, 'L', false, 1, '', '', true, 0, false, true, 28, 'T');
        $blockY = $pdf->GetY() + 4;

        // ─────────────────────────────────────────────────────────────
        // 4. PRIX XL DORÉ + mention honoraires
        // ─────────────────────────────────────────────────────────────
        $prix = mbi_supports_get_prix($bien);
        $pdf->SetFont('helvetica', 'B', 38);
        $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
        $pdf->SetXY(15, $blockY);
        $pdf->Cell(180, 14, mbi_supports_format_prix($prix), 0, 1, 'L');

        $charge = mbi_supports_tpl_charge_honoraires($bien);
        if ($charge !== '') {
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetTextColor(110, 116, 130);
            $pdf->SetX(15);
            $pdf->Cell(180, 5, $charge, 0, 1, 'L');
        }
        $blockY = $pdf->GetY() + 4;

        // Trait or fin séparateur
        $pdf->SetDrawColor($cS[0], $cS[1], $cS[2]);
        $pdf->SetLineWidth(0.8);
        $pdf->Line(15, $blockY, 65, $blockY);
        $pdf->SetLineWidth(0.2);
        $blockY += 6;

        // ─────────────────────────────────────────────────────────────
        // 5. CARACTÉRISTIQUES — badges colorés (2 lignes de 3)
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
        if ($surf !== null)            $items[] = ['M²',     mbi_supports_format_surface($surf)];
        if ($nbPcs)                    $items[] = ['Pièces', (string)$nbPcs];
        if ($dpe !== '' || $ges !== '')$items[] = ['Énergie', ($dpe ?: '—') . ' / ' . ($ges ?: '—')];
        if ($etage !== null && $etage !== '') $items[] = ['Étage', (string)$etage . 'ᵉ'];
        if ($expo)                     $items[] = ['Expo',   (string)$expo];
        if ($estCopro && $coproLots)   $items[] = ['Copro',  $coproLots . ' lots'];

        // Affiche en 3 colonnes, multi-lignes
        $cellW = 60;
        $cellH = 16;
        $col = 0; $row = 0;
        foreach ($items as $it) {
            $x = 15 + ($col * $cellW);
            $y = $blockY + ($row * ($cellH + 2));
            mbi_supports_tpl_v2_badge($pdf, $x, $y, $cellW - 4, $cellH, $it[0], $it[1], $cP, $cS, $cT);
            $col++;
            if ($col === 3) { $col = 0; $row++; }
        }
        $blockY += (($row + ($col > 0 ? 1 : 0)) * ($cellH + 2)) + 6;

        // ─────────────────────────────────────────────────────────────
        // 6. VOS ATOUTS — points forts du score IA
        // ─────────────────────────────────────────────────────────────
        $pointsForts = $score && !empty($score['points_forts_json'])
            ? (json_decode((string)$score['points_forts_json'], true) ?: [])
            : [];

        if (!empty($pointsForts)) {
            // Encadré subtil avec fond très clair or
            $boxY = $blockY;
            $boxH = 6 + (min(3, count($pointsForts)) * 6) + 2;
            $pdf->SetFillColor(252, 248, 240);
            $pdf->Rect(15, $boxY, 180, $boxH, 'F');
            $pdf->SetDrawColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetLineWidth(0.4);
            $pdf->Line(15, $boxY, 15, $boxY + $boxH);
            $pdf->SetLineWidth(0.2);

            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY(20, $boxY + 2);
            $pdf->Cell(170, 5, mb_strtoupper('Vos atouts'), 0, 1, 'L');

            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
            $i = 0;
            foreach (array_slice($pointsForts, 0, 3) as $pf) {
                $pdf->SetXY(20, $boxY + 7 + ($i * 6));
                // checkmark or
                $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
                $pdf->SetFont('helvetica', 'B', 11);
                $pdf->Cell(5, 5, '✓', 0, 0, 'L');
                // texte
                $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
                $pdf->SetFont('helvetica', '', 10);
                $pdf->Cell(165, 5, mb_substr((string)$pf, 0, 90), 0, 0, 'L');
                $i++;
            }
            $blockY = $boxY + $boxH + 6;
        } else {
            // Fallback : utilise la description courte si pas de points forts IA
            $desc = (string)($bien['description'] ?? $bien['descriptif'] ?? '');
            if ($desc !== '') {
                $pdf->SetFont('helvetica', '', 9.5);
                $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
                $pdf->SetXY(15, $blockY);
                $pdf->MultiCell(180, 5, mb_substr($desc, 0, 350), 0, 'L');
                $blockY = $pdf->GetY() + 4;
            }
        }

        // ─────────────────────────────────────────────────────────────
        // 7. Mention DPE en cours / non soumis si applicable
        // ─────────────────────────────────────────────────────────────
        $mentionDpe = '';
        if (!empty($mentionsTextes['dpe_en_cours']))    $mentionDpe = $mentionsTextes['dpe_en_cours'];
        if (!empty($mentionsTextes['dpe_non_soumis']))  $mentionDpe = $mentionsTextes['dpe_non_soumis'];
        if ($mentionDpe !== '') {
            $pdf->SetFont('helvetica', 'I', 8);
            $pdf->SetTextColor(120, 80, 30);
            $pdf->SetXY(15, $blockY);
            $pdf->MultiCell(180, 4, $mentionDpe, 0, 'L');
        }

        // ─────────────────────────────────────────────────────────────
        // 8. PIED mentions légales (toujours en bas, fond navy clair)
        // ─────────────────────────────────────────────────────────────
        mbi_supports_tpl_v2_pied($pdf, $ctx, $cP, $cS);

        return $pdf;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Helpers V2 (placeholder, badge, pied)
// ─────────────────────────────────────────────────────────────────────────

if (!function_exists('mbi_supports_tpl_v2_placeholder')) {
    function mbi_supports_tpl_v2_placeholder(TCPDF $pdf, float $x, float $y, float $w, float $h, array $color): void
    {
        $pdf->SetFillColor(225, 230, 240);
        $pdf->Rect($x, $y, $w, $h, 'F');
        // Pattern diagonal subtil
        $pdf->SetDrawColor(200, 208, 220);
        $pdf->SetLineWidth(0.3);
        for ($i = -50; $i < $w + $h; $i += 10) {
            $pdf->Line($x + $i, $y, $x + $i + $h, $y + $h);
        }
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
        $pdf->SetXY($x, $y + ($h/2) - 4);
        $pdf->Cell($w, 8, 'PHOTO À AJOUTER', 0, 0, 'C');
    }
}

if (!function_exists('mbi_supports_tpl_v2_badge')) {
    function mbi_supports_tpl_v2_badge(TCPDF $pdf, float $x, float $y, float $w, float $h, string $label, string $value, array $cP, array $cS, array $cT): void
    {
        // Cercle navy à gauche avec lettre or
        $cR = $h / 2 - 1;
        $pdf->SetFillColor($cP[0], $cP[1], $cP[2]);
        $pdf->Circle($x + $cR + 1, $y + $cR + 1, $cR, 0, 360, 'F');

        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
        $pdf->SetXY($x, $y + 2);
        $pdf->Cell($cR * 2 + 2, $cR * 2 - 2, mb_substr($label, 0, 4), 0, 0, 'C');

        // Texte à droite : label en haut, valeur en bas
        $textX = $x + $cR * 2 + 4;
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColor(110, 116, 130);
        $pdf->SetXY($textX, $y + 1);
        $pdf->Cell($w - $cR * 2 - 4, 4, mb_strtoupper($label), 0, 0, 'L');

        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
        $pdf->SetXY($textX, $y + 6);
        $pdf->Cell($w - $cR * 2 - 4, 6, $value, 0, 0, 'L');
    }
}

if (!function_exists('mbi_supports_tpl_v2_pied')) {
    function mbi_supports_tpl_v2_pied(TCPDF $pdf, array $ctx, array $cP, array $cS): void
    {
        $agence = $ctx['agence'] ?? [];
        $bien   = $ctx['bien']   ?? [];
        $negociateur = $ctx['negociateur'] ?? [];

        $piedH = 32;
        $piedY = 297 - $piedH;
        $pdf->SetFillColor($cP[0], $cP[1], $cP[2]);
        $pdf->Rect(0, $piedY, 210, $piedH, 'F');

        // Trait or fin en haut du pied
        $pdf->SetFillColor($cS[0], $cS[1], $cS[2]);
        $pdf->Rect(0, $piedY, 210, 1.2, 'F');

        // Nom agence + coordonnées
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(12, $piedY + 4);
        $nomAg = (string)($agence['nom_agence'] ?? $agence['nom'] ?? 'Agence');
        $pdf->Cell(120, 6, $nomAg, 0, 1, 'L');

        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(220, 222, 230);
        $pdf->SetXY(12, $piedY + 11);
        $coord = trim((string)($agence['adresse'] ?? '') . ' · ' . trim((string)($agence['code_postal'] ?? '') . ' ' . ($agence['ville'] ?? '')) . ' · ' . ($agence['telephone'] ?? '') . ' · ' . ($agence['email'] ?? ''), ' ·');
        $pdf->Cell(180, 4, $coord, 0, 1, 'L');

        // Carte pro / garant (très petit)
        $cartePro = trim((string)($agence['carte_pro_numero'] ?? $agence['carte_pro'] ?? ''));
        $garant   = trim((string)($agence['garant_financier'] ?? $agence['garantie_financiere'] ?? ''));
        $rcPro    = trim((string)($agence['rc_pro']           ?? $agence['assurance_rc_pro'] ?? ''));
        $infos = array_filter([
            $cartePro !== '' ? 'Carte pro ' . $cartePro : '',
            $garant   !== '' ? 'Garant ' . $garant : '',
            $rcPro    !== '' ? 'RC Pro ' . $rcPro : '',
        ]);

        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetTextColor(180, 185, 200);
        $pdf->SetXY(12, $piedY + 16);
        $pdf->MultiCell(196, 3.2, implode(' · ', $infos), 0, 'L');

        // Copro mention si applicable
        if ((int)($bien['bien_en_copropriete'] ?? 0) === 1) {
            $copLots = $bien['copro_nb_lots'] ?? null;
            $copChg  = $bien['copro_quote_part_charges'] ?? null;
            $copProc = (int)($bien['copro_procedure'] ?? 0) === 1;
            $partsCopro = ['Copropriété de ' . ($copLots ?: '?') . ' lots'];
            if ($copChg) $partsCopro[] = 'charges ~ ' . number_format((float)$copChg, 0, ',', ' ') . ' €/an';
            $partsCopro[] = $copProc ? 'procédures L611-1 en cours' : 'absence de procédures L611-1';
            $pdf->SetXY(12, $piedY + 22);
            $pdf->MultiCell(196, 3.2, implode(' · ', $partsCopro), 0, 'L');
        }

        // Négociateur en bas droite
        if ($negociateur) {
            $nego = trim(($negociateur['prenom'] ?? '') . ' ' . ($negociateur['nom'] ?? ''));
            $tel  = trim((string)($negociateur['telephone'] ?? ''));
            $pdf->SetFont('helvetica', 'B', 7.5);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY(12, $piedY + 27);
            $pdf->Cell(196, 3, 'Votre contact : ' . $nego . ($tel ? ' · ' . $tel : ''), 0, 0, 'R');
        }
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
        // Conservé pour fiche_client (carte simple sans badge)
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
        $bien      = $ctx['bien']      ?? [];
        $negociateur = $ctx['negociateur'] ?? [];

        $pdf->SetY(-26);
        $pdf->SetDrawColor(180, 180, 190);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(1);

        $cartePro = trim((string)($agence['carte_pro_numero'] ?? $agence['carte_pro'] ?? ''));
        $garant   = trim((string)($agence['garant_financier'] ?? $agence['garantie_financiere'] ?? ''));
        $rcPro    = trim((string)($agence['rc_pro']           ?? $agence['assurance_rc_pro'] ?? ''));

        $lignes = [];
        if ($cartePro !== '') $lignes[] = 'Carte professionnelle n° ' . $cartePro;
        if ($garant !== '')   $lignes[] = 'Garant financier : ' . $garant;
        if ($rcPro !== '')    $lignes[] = 'Assurance RC Pro : ' . $rcPro;

        if ($negociateur) {
            $nego = trim(($negociateur['prenom'] ?? '') . ' ' . ($negociateur['nom'] ?? ''));
            $tel  = trim((string)($negociateur['telephone'] ?? ''));
            $mail = trim((string)($negociateur['email']     ?? ''));
            $lignes[] = 'Négociateur : ' . $nego . ($tel ? ' · ' . $tel : '') . ($mail ? ' · ' . $mail : '');
        }

        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColor(80, 84, 95);
        foreach ($lignes as $li) {
            $pdf->SetX(15);
            $pdf->Cell(180, 3.5, $li, 0, 1, 'L');
        }
    }
}
