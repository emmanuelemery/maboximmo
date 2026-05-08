<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Template : Affiche vitrine — A3 LANDSCAPE (V3 — cinéma overlay coin)
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Format : A3 paysage (420 × 297 mm) — non négociable (validé user 2026-05-08).
 * Police : dejavusans (Unicode complet, gère «, », ★, ✓, m², €, etc.)
 *
 * Layout "cinéma overlay coin" :
 *   1. Photo héro PLEIN CADRE (full bleed) en fond
 *   2. Bandeau réf / ville en haut-gauche (blanc + ombre)
 *   3. Carte info translucide à droite (blanc 92% + watermark puzzle filigrane)
 *      contenant : badge angle, accroche, prix, caracs, étiquettes DPE/GES, atouts
 *   4. Mosaïque thumbs en bas-gauche (0 à 4 selon nb_photos)
 *   5. Pied navy fin avec mentions agence (cartepro, garant, rcpro, contact)
 *
 * Watermark puzzle attendu à : public_html/images/mbi_affiche_watermark.jpg
 * (si absent, la carte info reste propre — pas d'erreur).
 * ═══════════════════════════════════════════════════════════════════════
 */

if (!function_exists('mbi_supports_tpl_affiche_vitrine_build')) {

    function mbi_supports_tpl_affiche_vitrine_build(array $ctx): TCPDF
    {
        // Force UTF-8 pour mb_strtoupper / mb_substr sur tous les serveurs
        // (sans ça, certaines configs PHP cassent les accents en "??")
        if (function_exists('mb_internal_encoding')) {
            @mb_internal_encoding('UTF-8');
        }

        $bien        = $ctx['bien']        ?? [];
        $photos      = $ctx['photos']      ?? [];
        $agence      = $ctx['agence']      ?? [];
        $negociateur = $ctx['negociateur'] ?? [];
        $style       = $ctx['style']       ?? [];
        $score       = $ctx['score']       ?? null;
        $critique    = $ctx['critique']    ?? [];
        $mentionsTextes = $critique['mentions_textes'] ?? [];

        // Rédaction IA — accroche / paragraphe / atouts
        $iaRed       = is_array($ctx['ia_redaction'] ?? null) ? ($ctx['ia_redaction']['data'] ?? []) : [];
        $iaAccroche  = trim((string)($iaRed['accroche']   ?? ''));
        $iaAtouts    = is_array($iaRed['atouts'] ?? null) ? $iaRed['atouts'] : [];

        // Couleurs charte (fallback navy + or MaBoxImmo)
        $cP = $style['rgb_primaire']   ?? [36, 59, 92];   // navy
        $cS = $style['rgb_secondaire'] ?? [212, 160, 71]; // or
        $cT = $style['rgb_texte']      ?? [31, 41, 55];

        // Palette par angle marketing
        $angle    = (string)($ctx['angle'] ?? 'generique');
        $anglePal = mbi_supports_tpl_angle_palette($angle, $cS);
        $cAngle   = $anglePal['rgb'];
        $libAngle = $anglePal['libelle'];

        // Nombre TOTAL de photos sur l'affiche : 1 (cinéma pur) à 3 max (héro + 2 thumbs)
        $nbPhotos = (int)($ctx['nb_photos'] ?? 3);
        if ($nbPhotos < 1) $nbPhotos = 1;
        if ($nbPhotos > 3) $nbPhotos = 3;

        // ─── A3 LANDSCAPE (420 × 297 mm) ────────────────────────────────
        $pdf = new TCPDF('L', 'mm', 'A3', true, 'UTF-8');
        $pdf->SetCreator('MaBoxImmo - Ma Box Communication');
        $pdf->SetTitle('Affiche vitrine A3 H');
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetFont('dejavusans', '', 11);
        $pdf->AddPage();

        $pageW = 420;
        $pageH = 297;
        $piedH = 18;
        $photoH = $pageH - $piedH; // 279 mm

        // ─────────────────────────────────────────────────────────────
        // 1. PHOTO HÉRO PLEIN CADRE (fond cinéma)
        // ─────────────────────────────────────────────────────────────
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

        // Gradient sombre subtil sur la moitié droite + le bas (lisibilité carte info + thumbs)
        // Voile gauche-droite très léger pour faire ressortir la carte info
        $pdf->SetAlpha(0.15);
        $pdf->SetFillColor(0, 0, 0);
        $pdf->Rect(220, 0, $pageW - 220, $photoH, 'F');
        $pdf->SetAlpha(1.0);

        // Voile bas (40 mm) pour lisibilité du bandeau réf + thumbs
        $pdf->SetAlpha(0.45);
        $pdf->SetFillColor(0, 0, 0);
        $pdf->Rect(0, $photoH - 50, 230, 50, 'F');
        $pdf->SetAlpha(1.0);

        // ─────────────────────────────────────────────────────────────
        // 2. RÉFÉRENCE + VILLE en haut-gauche (blanc avec halo)
        // ─────────────────────────────────────────────────────────────
        $ref = (string)($bien['reference_bien'] ?? ('#' . ($bien['id'] ?? '')));
        $loc = trim((string)($bien['code_postal'] ?? '') . ' ' . ($bien['ville'] ?? ''));
        $refTxt = mb_strtoupper('Réf ' . $ref . ($loc !== '' ? '   ·   ' . $loc : ''), 'UTF-8');

        // Halo sombre derrière le texte pour lisibilité quoi qu'il arrive sur la photo
        $pdf->SetAlpha(0.55);
        $pdf->SetFillColor(0, 0, 0);
        $pdf->Rect(0, 0, 230, 32, 'F');
        $pdf->SetAlpha(1.0);

        $pdf->SetFont('dejavusans', 'B', 14);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(20, 12);
        $pdf->Cell(200, 8, $refTxt, 0, 0, 'L');

        // Type bien sous-titre (Appartement / Maison...)
        $typeBien = trim((string)($bien['type_bien_libelle'] ?? $bien['type'] ?? ''));
        if ($typeBien !== '') {
            $pdf->SetFont('dejavusans', '', 10);
            $pdf->SetTextColor(220, 222, 230);
            $pdf->SetXY(20, 21);
            $pdf->Cell(200, 5, mb_strtoupper($typeBien, 'UTF-8'), 0, 0, 'L');
        }

        // ─────────────────────────────────────────────────────────────
        // 3. CARTE INFO TRANSLUCIDE (overlay coin droit)
        // ─────────────────────────────────────────────────────────────
        $cx = 237;
        $cy = 24;
        $cw = 167;
        $ch = 240;

        // Fond blanc semi-opaque
        $pdf->SetAlpha(0.93);
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($cx, $cy, $cw, $ch, 'F');
        $pdf->SetAlpha(1.0);

        // Watermark puzzle (filigrane discret) — si fichier dispo
        $watermarkPath = __DIR__ . '/../images/mbi_affiche_watermark.jpg';
        if (is_file($watermarkPath) && is_readable($watermarkPath)) {
            try {
                $pdf->SetAlpha(0.12);
                $pdf->Image($watermarkPath, $cx, $cy, $cw, $ch, '', '', '', false, 250, '', false, false, 0, 'CM', false, false);
                $pdf->SetAlpha(1.0);
            } catch (Throwable) {
                $pdf->SetAlpha(1.0);
            }
        }

        // Trait or à gauche (signature de marque)
        $pdf->SetFillColor($cS[0], $cS[1], $cS[2]);
        $pdf->Rect($cx, $cy, 2, $ch, 'F');

        // Padding intérieur de la carte
        $px = $cx + 10;
        $pw = $cw - 18;
        $py = $cy + 8;

        // Badge angle marketing (haut de la carte)
        if ($angle !== 'generique' && $libAngle !== '') {
            $bw = $pw;
            $bh = 9;
            $pdf->SetFillColor($cAngle[0], $cAngle[1], $cAngle[2]);
            $pdf->Rect($px, $py, $bw, $bh, 'F');
            $pdf->SetFont('dejavusans', 'B', 9);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY($px, $py);
            $pdf->Cell($bw, $bh, mb_strtoupper($libAngle, 'UTF-8'), 0, 0, 'C');
            $py += $bh + 6;
        }

        // Ruban "★ Coup de cœur" si score IA ≥ 80
        $scoreNum = $score ? (int)($score['score'] ?? 0) : 0;
        if ($scoreNum >= 80) {
            $bh = 8;
            $pdf->SetFillColor($cS[0], $cS[1], $cS[2]);
            $pdf->Rect($px, $py, $pw, $bh, 'F');
            $pdf->SetFont('dejavusans', 'B', 9);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY($px, $py);
            $pdf->Cell($pw, $bh, '★  COUP DE CŒUR', 0, 0, 'C');
            $py += $bh + 5;
        }

        // ── Accroche italique navy ────────────────────────────────────
        $accroche = trim((string)($bien['_accroche'] ?? ''));
        if ($accroche === '' && $iaAccroche !== '') {
            $accroche = $iaAccroche;
        }
        if ($accroche === '') {
            $accroche = mb_substr((string)($bien['designation'] ?? 'Bien à découvrir'), 0, 110, 'UTF-8');
        }
        $pdf->SetFont('dejavusans', 'BI', 16);
        $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
        $pdf->SetXY($px, $py);
        $pdf->MultiCell($pw, 7, '« ' . $accroche . ' »', 0, 'L');
        $py = $pdf->GetY() + 4;

        // ── Prix XL doré ──────────────────────────────────────────────
        $prix = mbi_supports_get_prix($bien);
        $pdf->SetFont('dejavusans', 'B', 36);
        $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
        $pdf->SetXY($px, $py);
        $pdf->Cell($pw, 14, mbi_supports_format_prix($prix), 0, 1, 'L');
        $py += 14;

        $charge = mbi_supports_tpl_charge_honoraires($bien);
        if ($charge !== '') {
            $pdf->SetFont('dejavusans', '', 9);
            $pdf->SetTextColor(110, 116, 130);
            $pdf->SetXY($px, $py);
            $pdf->Cell($pw, 4, $charge, 0, 1, 'L');
            $py += 4;
        }
        $py += 4;

        // Trait or fin séparateur
        $pdf->SetDrawColor($cS[0], $cS[1], $cS[2]);
        $pdf->SetLineWidth(0.8);
        $pdf->Line($px, $py, $px + 35, $py);
        $pdf->SetLineWidth(0.2);
        $py += 6;

        // ── Caracs principales : ligne 1 (4 mini-blocs) ───────────────
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
            $cellW = ($pw - (($nbC - 1) * 3)) / $nbC;
            foreach ($caracs as $i => $it) {
                $x = $px + ($i * ($cellW + 3));
                mbi_supports_tpl_carac_mini($pdf, $x, $py, $cellW, 16, $it[0], $it[1], $cP, $cT);
            }
            $py += 16 + 5;
        }

        // ── Étiquettes DPE / GES (cercles colorés officiels) ──────────
        $dpe = strtoupper(trim((string)($bien['dpe_classe'] ?? $bien['dpe'] ?? '')));
        $ges = strtoupper(trim((string)($bien['ges_classe'] ?? $bien['ges'] ?? '')));
        if ($dpe !== '' || $ges !== '') {
            mbi_supports_tpl_dpe_ges($pdf, $px, $py, $pw, $dpe, $ges);
            $py += 22 + 4;
        }

        // ── Atouts ✓ (3 max) ──────────────────────────────────────────
        $pointsForts = $iaAtouts;
        if (empty($pointsForts) && $score && !empty($score['points_forts_json'])) {
            $pointsForts = json_decode((string)$score['points_forts_json'], true) ?: [];
        }
        if (!empty($pointsForts)) {
            $i = 0;
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
                $i++;
            }
        }

        // Mention DPE en cours / non soumis si applicable (en bas de la carte)
        $mentionDpe = '';
        if (!empty($mentionsTextes['dpe_en_cours']))    $mentionDpe = $mentionsTextes['dpe_en_cours'];
        if (!empty($mentionsTextes['dpe_non_soumis']))  $mentionDpe = $mentionsTextes['dpe_non_soumis'];
        if ($mentionDpe !== '' && $py < $cy + $ch - 10) {
            $pdf->SetFont('dejavusans', 'I', 7.5);
            $pdf->SetTextColor(120, 80, 30);
            $pdf->SetXY($px, $cy + $ch - 9);
            $pdf->MultiCell($pw, 3.5, $mentionDpe, 0, 'L');
        }

        // ─────────────────────────────────────────────────────────────
        // 4. MOSAÏQUE THUMBS en bas-gauche (selon nb_photos)
        // ─────────────────────────────────────────────────────────────
        $heroId = (int)($hero['id'] ?? 0);
        // Sélection des thumbs : sélection manuelle (photos_ids_secondaires) > auto (ordre BDD)
        $idsManuel = $ctx['photos_ids_secondaires'] ?? null;
        $autres = [];
        if (is_array($idsManuel) && !empty($idsManuel)) {
            // Ordre = ordre fourni par l'utilisateur, exclut le héro, limite nb_photos-1
            $byId = [];
            foreach ($photos as $p) {
                $byId[(int)($p['id'] ?? 0)] = $p;
            }
            foreach ($idsManuel as $idP) {
                $idP = (int)$idP;
                if ($idP <= 0 || $idP === $heroId) continue;
                if (!isset($byId[$idP])) continue;
                $autres[] = $byId[$idP];
                if (count($autres) >= ($nbPhotos - 1)) break;
            }
        } else {
            // Auto : ordre BDD (ordre, id), exclut héro, limite nb_photos-1
            foreach ($photos as $p) {
                if ((int)($p['id'] ?? 0) === $heroId) continue;
                $autres[] = $p;
                if (count($autres) >= ($nbPhotos - 1)) break;
            }
        }

        if ($nbPhotos > 1 && !empty($autres)) {
            $tw = 42;
            $th = 30;
            $tg = 5;
            $tx0 = 20;
            $ty  = $photoH - $th - 10;

            // Ligne fine or au-dessus pour signature
            $pdf->SetFillColor($cS[0], $cS[1], $cS[2]);
            $pdf->Rect($tx0, $ty - 4, ($tw + $tg) * count($autres) - $tg, 0.8, 'F');

            foreach ($autres as $i => $p) {
                $tpath = mbi_supports_resoudre_photo_path($p);
                $tx = $tx0 + $i * ($tw + $tg);
                if ($tpath !== null) {
                    try {
                        $pdf->Image($tpath, $tx, $ty, $tw, $th, '', '', '', false, 250, '', false, false, 0, 'CM', false, false);
                    } catch (Throwable) {
                        $pdf->SetFillColor(220, 224, 232);
                        $pdf->Rect($tx, $ty, $tw, $th, 'F');
                    }
                } else {
                    $pdf->SetFillColor(220, 224, 232);
                    $pdf->Rect($tx, $ty, $tw, $th, 'F');
                }
                // Cadre or fin autour
                $pdf->SetDrawColor($cS[0], $cS[1], $cS[2]);
                $pdf->SetLineWidth(0.4);
                $pdf->Rect($tx, $ty, $tw, $th, 'D');
                $pdf->SetLineWidth(0.2);
            }
        }

        // ─────────────────────────────────────────────────────────────
        // 5. PIED NAVY fin (mentions agence)
        // ─────────────────────────────────────────────────────────────
        mbi_supports_tpl_pied_landscape($pdf, $ctx, $cP, $cS, $pageW, $pageH, $piedH);

        return $pdf;
    }
}

// ═════════════════════════════════════════════════════════════════════════
// Helpers spécifiques landscape
// ═════════════════════════════════════════════════════════════════════════

if (!function_exists('mbi_supports_tpl_carac_mini')) {
    /**
     * Mini-bloc caractéristique : label tiny gris en haut + valeur bold navy en bas.
     * Pas de fond — la carte info translucide assure la lisibilité.
     */
    function mbi_supports_tpl_carac_mini(TCPDF $pdf, float $x, float $y, float $w, float $h, string $label, string $value, array $cP, array $cT): void
    {
        // Trait or fin à gauche
        // (volontairement minimaliste pour la densité de la carte)
        $pdf->SetFont('dejavusans', '', 7.5);
        $pdf->SetTextColor(120, 126, 140);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, 4, mb_strtoupper($label, 'UTF-8'), 0, 0, 'L');

        $pdf->SetFont('dejavusans', 'B', 14);
        $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
        $pdf->SetXY($x, $y + 5);
        $pdf->Cell($w, 9, $value, 0, 0, 'L');
    }
}

if (!function_exists('mbi_supports_tpl_dpe_ges')) {
    /**
     * Étiquettes DPE et GES côte à côte sous forme de pastilles colorées
     * avec la lettre officielle. Couleurs ADEME.
     */
    function mbi_supports_tpl_dpe_ges(TCPDF $pdf, float $x, float $y, float $w, string $dpe, string $ges): void
    {
        $couleurs = [
            'A' => [0, 159, 58],    // vert foncé
            'B' => [80, 183, 62],   // vert clair
            'C' => [196, 216, 61],  // jaune-vert
            'D' => [255, 240, 53],  // jaune
            'E' => [245, 181, 61],  // orange clair
            'F' => [232, 90, 58],   // orange foncé
            'G' => [210, 44, 46],   // rouge
        ];
        $cellW = 28;
        $cellH = 22;
        $gap   = 6;

        // Wrapper "DPE | GES"
        $pdf->SetFont('dejavusans', 'B', 7);
        $pdf->SetTextColor(120, 126, 140);
        $pdf->SetXY($x, $y);
        $pdf->Cell($cellW + $gap + $cellW, 4, 'DPE  ·  GES', 0, 0, 'L');

        $py = $y + 4;

        // DPE
        $cDPE = $couleurs[$dpe] ?? [200, 200, 200];
        $pdf->SetFillColor($cDPE[0], $cDPE[1], $cDPE[2]);
        $pdf->Rect($x, $py, $cellW, $cellH, 'F');
        $pdf->SetFont('dejavusans', 'B', 16);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY($x, $py + 2);
        $pdf->Cell($cellW, 12, $dpe !== '' ? $dpe : '—', 0, 0, 'C');
        $pdf->SetFont('dejavusans', 'B', 6);
        $pdf->SetXY($x, $py + 14);
        $pdf->Cell($cellW, 5, 'DPE', 0, 0, 'C');

        // GES
        $cGES = $couleurs[$ges] ?? [200, 200, 200];
        $pdf->SetFillColor($cGES[0], $cGES[1], $cGES[2]);
        $pdf->Rect($x + $cellW + $gap, $py, $cellW, $cellH, 'F');
        $pdf->SetFont('dejavusans', 'B', 16);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY($x + $cellW + $gap, $py + 2);
        $pdf->Cell($cellW, 12, $ges !== '' ? $ges : '—', 0, 0, 'C');
        $pdf->SetFont('dejavusans', 'B', 6);
        $pdf->SetXY($x + $cellW + $gap, $py + 14);
        $pdf->Cell($cellW, 5, 'GES', 0, 0, 'C');
    }
}

if (!function_exists('mbi_supports_tpl_pied_landscape')) {
    /**
     * Pied navy fin pour A3 landscape (18 mm).
     * Densité optimisée : agence sur 1 ligne + mentions sur 1 ligne + contact négo.
     */
    function mbi_supports_tpl_pied_landscape(TCPDF $pdf, array $ctx, array $cP, array $cS, float $pageW, float $pageH, float $piedH): void
    {
        $agence      = $ctx['agence']      ?? [];
        $bien        = $ctx['bien']        ?? [];
        $negociateur = $ctx['negociateur'] ?? [];

        $piedY = $pageH - $piedH;
        $pdf->SetFillColor($cP[0], $cP[1], $cP[2]);
        $pdf->Rect(0, $piedY, $pageW, $piedH, 'F');

        // Trait or fin haut du pied
        $pdf->SetFillColor($cS[0], $cS[1], $cS[2]);
        $pdf->Rect(0, $piedY, $pageW, 1.2, 'F');

        // Pioche RCP/GF selon activité du bien (vente=T, location=G)
        $typeTr = strtolower((string)($bien['type_transaction'] ?? ''));
        $activiteCible = match (true) {
            str_contains($typeTr, 'vente'),
            str_contains($typeTr, 'cession') => 'transaction',
            str_contains($typeTr, 'location') => 'gestion',
            default => null,
        };
        $rcpAct = ($activiteCible && !empty($agence['activites'][$activiteCible]['rc_pro_assureur']))
            ? (string)$agence['activites'][$activiteCible]['rc_pro_assureur'] : '';
        $gfAct  = ($activiteCible && !empty($agence['activites'][$activiteCible]['garant_nom']))
            ? (string)$agence['activites'][$activiteCible]['garant_nom'] : '';

        $cartePro = trim((string)($agence['carte_pro_numero'] ?? $agence['carte_pro'] ?? ''));
        $garant   = trim($gfAct  ?: (string)($agence['garant_financier'] ?? $agence['garantie_financiere'] ?? ''));
        $rcPro    = trim($rcpAct ?: (string)($agence['rc_pro']           ?? $agence['assurance_rc_pro'] ?? ''));

        // ── Ligne 1 : Nom agence (gauche) + Contact négociateur (droite)
        $nomAg = (string)($agence['nom_agence'] ?? $agence['nom'] ?? 'Agence');
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(20, $piedY + 3.5);
        $pdf->Cell($pageW / 2 - 24, 5, $nomAg, 0, 0, 'L');

        if ($negociateur) {
            $nego = trim(($negociateur['prenom'] ?? '') . ' ' . ($negociateur['nom'] ?? ''));
            $tel  = trim((string)($negociateur['telephone_pro'] ?? ''));
            $mail = trim((string)($negociateur['email'] ?? ''));
            $partsNego = array_filter([$nego, $tel, $mail]);
            $pdf->SetFont('dejavusans', 'B', 10);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY($pageW / 2, $piedY + 3.8);
            $pdf->Cell($pageW / 2 - 20, 5, '✦ ' . implode('  ·  ', $partsNego), 0, 0, 'R');
        }

        // ── Ligne 2 : adresse agence + tel + email (gauche)
        $coord = trim(
            (string)($agence['adresse'] ?? '') . ' · ' .
            trim((string)($agence['code_postal'] ?? '') . ' ' . ($agence['ville'] ?? '')) . ' · ' .
            ($agence['telephone'] ?? '') . ' · ' .
            ($agence['email'] ?? ''),
            ' ·'
        );
        $pdf->SetFont('dejavusans', '', 8.5);
        $pdf->SetTextColor(220, 222, 230);
        $pdf->SetXY(20, $piedY + 9);
        $pdf->Cell($pageW - 40, 4, $coord, 0, 0, 'L');

        // ── Ligne 3 : carte pro + garant + RC pro (gauche)
        $infos = array_filter([
            $cartePro !== '' ? 'Carte pro ' . $cartePro : '',
            $garant   !== '' ? 'Garant ' . $garant : '',
            $rcPro    !== '' ? 'RC Pro ' . $rcPro : '',
        ]);
        if (!empty($infos)) {
            $pdf->SetFont('dejavusans', '', 7.5);
            $pdf->SetTextColor(180, 186, 200);
            $pdf->SetXY(20, $piedY + 13);
            $pdf->Cell($pageW - 130, 4, implode('  ·  ', $infos), 0, 0, 'L');
        }

        // Copro mention (droite ligne 3) si applicable
        if ((int)($bien['bien_en_copropriete'] ?? 0) === 1) {
            $copLots = $bien['copro_nb_lots'] ?? null;
            $copChg  = $bien['copro_quote_part_charges'] ?? $bien['copro_charges_annuelles'] ?? null;
            $copProc = (int)($bien['copro_procedure'] ?? 0) === 1;
            $partsCopro = ['Copro ' . ($copLots ?: '?') . ' lots'];
            if ($copChg) $partsCopro[] = '~' . number_format((float)$copChg, 0, ',', ' ') . ' €/an';
            $partsCopro[] = $copProc ? 'L611-1 en cours' : 'sans L611-1';
            $pdf->SetFont('dejavusans', '', 7.5);
            $pdf->SetTextColor(180, 186, 200);
            $pdf->SetXY($pageW - 110, $piedY + 13);
            $pdf->Cell(90, 4, implode(' · ', $partsCopro), 0, 0, 'R');
        }

        // "Partenaire MaBoxImmo" tout en bas (italique or)
        $pdf->SetFont('dejavusans', 'I', 6.5);
        $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
        $pdf->SetXY($pageW - 60, $piedY + $piedH - 4);
        $pdf->Cell(40, 3, 'Partenaire MaBoxImmo', 0, 0, 'R');
    }
}

// ═════════════════════════════════════════════════════════════════════════
// Helpers conservés (palette angle, placeholder, badge legacy, pieds legacy)
// — réutilisés par fiche_client.php et autres templates
// ═════════════════════════════════════════════════════════════════════════

if (!function_exists('mbi_supports_tpl_angle_palette')) {
    function mbi_supports_tpl_angle_palette(string $angle, array $cSDefault): array
    {
        $palettes = [
            'famille'        => ['rgb' => [42, 122, 95],  'libelle' => 'Pour la famille'],
            'investisseur'   => ['rgb' => [66, 96, 140],  'libelle' => 'Investisseur'],
            'premium'        => ['rgb' => [165, 124, 50], 'libelle' => 'Premium'],
            'premier_achat'  => ['rgb' => [192, 102, 70], 'libelle' => 'Primo-accédant'],
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
        $pdf->SetXY($x, $y + ($h / 2) - 5);
        $pdf->Cell($w, 10, 'PHOTO À AJOUTER', 0, 0, 'C');
    }
}

if (!function_exists('mbi_supports_tpl_v2_badge')) {
    function mbi_supports_tpl_v2_badge(TCPDF $pdf, float $x, float $y, float $w, float $h, string $label, string $value, array $cP, array $cS, array $cT): void
    {
        $cR = $h / 2 - 1;
        $pdf->SetFillColor($cP[0], $cP[1], $cP[2]);
        $pdf->Circle($x + $cR + 1, $y + $cR + 1, $cR, 0, 360, 'F');
        $pdf->SetFont('dejavusans', 'B', 9);
        $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
        $pdf->SetXY($x, $y + 2);
        $pdf->Cell($cR * 2 + 2, $cR * 2 - 2, mb_substr($label, 0, 5, 'UTF-8'), 0, 0, 'C');
        $textX = $x + $cR * 2 + 5;
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->SetTextColor(110, 116, 130);
        $pdf->SetXY($textX, $y + 2);
        $pdf->Cell($w - $cR * 2 - 6, 5, mb_strtoupper($label, 'UTF-8'), 0, 0, 'L');
        $pdf->SetFont('dejavusans', 'B', 14);
        $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
        $pdf->SetXY($textX, $y + 9);
        $pdf->Cell($w - $cR * 2 - 6, 8, $value, 0, 0, 'L');
    }
}

if (!function_exists('mbi_supports_tpl_v2_pied')) {
    /**
     * Pied legacy A3 portrait (44 mm) — conservé pour fiche_client.php
     * et compatibilité ascendante. L'affiche vitrine A3 H utilise désormais
     * mbi_supports_tpl_pied_landscape() (18 mm).
     */
    function mbi_supports_tpl_v2_pied(TCPDF $pdf, array $ctx, array $cP, array $cS, float $pageW = 210, float $pageH = 297): void
    {
        $agence = $ctx['agence'] ?? [];
        $bien   = $ctx['bien']   ?? [];
        $negociateur = $ctx['negociateur'] ?? [];

        $piedH = 44;
        $piedY = $pageH - $piedH;
        $pdf->SetFillColor($cP[0], $cP[1], $cP[2]);
        $pdf->Rect(0, $piedY, $pageW, $piedH, 'F');
        $pdf->SetFillColor($cS[0], $cS[1], $cS[2]);
        $pdf->Rect(0, $piedY, $pageW, 1.6, 'F');

        $pdf->SetFont('dejavusans', 'B', 14);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(18, $piedY + 5);
        $nomAg = (string)($agence['nom_agence'] ?? $agence['nom'] ?? 'Agence');
        $pdf->Cell(180, 7, $nomAg, 0, 1, 'L');

        $pdf->SetFont('dejavusans', '', 10);
        $pdf->SetTextColor(220, 222, 230);
        $pdf->SetXY(18, $piedY + 14);
        $coord = trim((string)($agence['adresse'] ?? '') . ' · ' . trim((string)($agence['code_postal'] ?? '') . ' ' . ($agence['ville'] ?? '')) . ' · ' . ($agence['telephone'] ?? '') . ' · ' . ($agence['email'] ?? ''), ' ·');
        $pdf->Cell($pageW - 36, 5, $coord, 0, 1, 'L');

        $typeTr = strtolower((string)($bien['type_transaction'] ?? ''));
        $activiteCible = match (true) {
            str_contains($typeTr, 'vente'),
            str_contains($typeTr, 'cession') => 'transaction',
            str_contains($typeTr, 'location') => 'gestion',
            default => null,
        };
        $rcpAct = ($activiteCible && !empty($agence['activites'][$activiteCible]['rc_pro_assureur']))
            ? (string)$agence['activites'][$activiteCible]['rc_pro_assureur'] : '';
        $gfAct  = ($activiteCible && !empty($agence['activites'][$activiteCible]['garant_nom']))
            ? (string)$agence['activites'][$activiteCible]['garant_nom'] : '';

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

        if ($negociateur) {
            $nego = trim(($negociateur['prenom'] ?? '') . ' ' . ($negociateur['nom'] ?? ''));
            $tel  = trim((string)($negociateur['telephone_pro'] ?? ''));
            $pdf->SetFont('dejavusans', 'B', 10);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY(18, $piedY + 35);
            $pdf->Cell($pageW - 36, 4, 'Votre contact : ' . $nego . ($tel ? ' · ' . $tel : ''), 0, 0, 'R');
        }

        $pdf->SetFont('dejavusans', 'I', 8);
        $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
        $pdf->SetXY(18, $piedY + 36);
        $pdf->Cell(80, 4, 'Partenaire MaBoxImmo', 0, 0, 'L');
    }
}

// ═════════════════════════════════════════════════════════════════════════
// Helpers conservés pour fiche_client.php
// ═════════════════════════════════════════════════════════════════════════

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
        $pdf->Cell($w - 4, 5, mb_strtoupper($label, 'UTF-8'), 0, 0, 'L');
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
        $agence    = $ctx['agence']    ?? [];
        $negociateur = $ctx['negociateur'] ?? [];

        $pdf->SetY(-26);
        $pdf->SetDrawColor(180, 180, 190);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(1);

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
