<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Layout : cezane (A3 LANDSCAPE — V4 TCPDF pur, propre, déterministe)
 * ═══════════════════════════════════════════════════════════════════════
 *
 * RÈGLES :
 *   - AUCUNE IA. Modèle 100 % déterministe.
 *   - Description = annonces.description || annonces.descriptif || biens.description || biens.descriptif (brute)
 *   - Pas de QR. Pas de watermark. Pas de writeHTML (incompatible env XAMPP local).
 *   - Pas de Unicode picto bizarre.
 *
 * Layout A3 LANDSCAPE 420 × 297 :
 *   ┌────────────────────────────┬─────────────────┐
 *   │                            │  LOGO           │ Y=6-36
 *   │                            ├─────────────────┤
 *   │                            │  TITRE          │ Y=42-78
 *   │      PHOTO HÉRO            │  VILLE          │
 *   │                            ├─────────────────┤
 *   │  (Y=6-206)                 │  4 PICTOS       │ Y=82-122
 *   │                            ├─────────────────┤
 *   │                            │  DESCRIPTION    │ Y=126-195
 *   │                            ├─────────────────┤
 *   │                            │  DPE / GES      │ Y=200-247
 *   ├────────────────────────────┴─────────────────┤
 *   │ 4 MINI-PHOTOS                                │ Y=213-247 (sous photo héro)
 *   ├──────────────────────────────────────────────┤
 *   │ MENTION LÉGALE (ligne)                       │ Y=251-258
 *   ├──────────────────────────────────────────────┤
 *   │ BANDEAU NAVY : À LOUER | RÉF | PRIX          │ Y=262-297
 *   └──────────────────────────────────────────────┘
 * ═══════════════════════════════════════════════════════════════════════
 */

if (!function_exists('mbi_supports_layout_cezane_build')) {

    function mbi_supports_layout_cezane_build(array $ctx): TCPDF
    {
        if (function_exists('mb_internal_encoding')) {
            @mb_internal_encoding('UTF-8');
        }

        // ─── Données ───
        $bien    = $ctx['bien']    ?? [];
        $photos  = mbi_supports_filtre_photos_reelles($ctx['photos'] ?? []);
        $style   = $ctx['style']   ?? [];
        $societe = $ctx['societe'] ?? [];
        $agence  = $ctx['agence']  ?? [];

        // Charte
        $cP = $style['rgb_primaire']   ?? [36, 59, 92];   // navy
        $cS = $style['rgb_secondaire'] ?? [212, 160, 71]; // or
        $cT = $style['rgb_texte']      ?? [31, 41, 55];

        // Init TCPDF A3 landscape
        $pdf = new TCPDF('L', 'mm', 'A3', true, 'UTF-8');
        $pdf->SetCreator('MaBoxImmo');
        $pdf->SetTitle('Affiche vitrine — cezane');
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetFont('dejavusans', '', 10);
        $pdf->AddPage();

        $pageW = 420; $pageH = 297;

        // Fond blanc
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect(0, 0, $pageW, $pageH, 'F');

        // ════════════════════════════════════════════════════════════
        // ZONE 1 — PHOTO HÉRO (grande, à gauche)
        // ════════════════════════════════════════════════════════════
        $heroX = 6; $heroY = 6; $heroW = 290; $heroH = 200;
        $hero = mbi_supports_photo_hero($photos, $ctx['photo_hero_id_suggestion'] ?? null);
        $heroPath = $hero ? mbi_supports_resoudre_photo_path($hero) : null;
        if ($heroPath !== null && is_file($heroPath)) {
            try {
                $pdf->Image($heroPath, $heroX, $heroY, $heroW, $heroH, '', '', '', false, 250, '', false, false, 0, 'CM', false, false);
            } catch (Throwable) {
                mbi_supports_tpl_v2_placeholder($pdf, $heroX, $heroY, $heroW, $heroH, $cP);
            }
        } else {
            mbi_supports_tpl_v2_placeholder($pdf, $heroX, $heroY, $heroW, $heroH, $cP);
        }

        // ════════════════════════════════════════════════════════════
        // ZONE 2 — COLONNE DROITE
        // ════════════════════════════════════════════════════════════
        $rX = 302; $rW = 112;

        // 2.1 — Logo société (ou placeholder gris discret si absent)
        $logoPath = mbi_supports_cezane_resoudre_logo($societe, $agence, $style);
        $logoY = 6; $logoH = 32;
        if ($logoPath !== null && is_file($logoPath)) {
            try {
                $pdf->Image($logoPath, $rX, $logoY, $rW, $logoH, '', '', '', false, 250, '', false, false, 0, 'CT', false, false);
            } catch (Throwable) { /* silencieux */ }
        } else {
            // Placeholder visuel discret
            $pdf->SetFillColor(248, 248, 248);
            $pdf->SetDrawColor(220, 220, 220);
            $pdf->SetLineWidth(0.3);
            $pdf->RoundedRect($rX + 10, $logoY + 4, $rW - 20, $logoH - 8, 2, '1111', 'DF');
            $pdf->SetFont('dejavusans', '', 7);
            $pdf->SetTextColor(160, 160, 160);
            $pdf->SetXY($rX, $logoY + 13);
            $pdf->Cell($rW, 4, '[ logo société ]', 0, 0, 'C');
        }

        // 2.2 — Titre + ville (sous le logo, plus gros et plus marqués)
        $titre = trim((string)($bien['_annonce_titre']
                     ?? $bien['titre']
                     ?? $bien['type_bien_libelle']
                     ?? 'Bien immobilier'));
        $ville = trim((string)($bien['ville'] ?? ''));

        $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
        $pdf->SetFont('dejavusans', 'B', 17);
        $pdf->SetXY($rX, 42);
        $pdf->MultiCell($rW, 7, mb_strtoupper(mb_substr($titre, 0, 60, 'UTF-8'), 'UTF-8'), 0, 'C');
        $tY = $pdf->GetY() + 1;

        if ($ville !== '') {
            // Ville en or sur fond très clair
            $pdf->SetFont('dejavusans', 'B', 14);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY($rX, $tY);
            $pdf->Cell($rW, 6, mb_strtoupper($ville, 'UTF-8'), 0, 0, 'C');
        }

        // 2.3 — Pictos colorés (4 cellules carrées colorées, valeur en gros)
        $surf  = (float)($bien['surface_habitable'] ?? $bien['surface'] ?? $bien['surface_totale'] ?? 0);
        $nbPcs = (int)($bien['nb_pieces'] ?? $bien['nombre_pieces'] ?? 0);
        $nbCh  = (int)($bien['nb_chambres'] ?? 0);
        $nbSdb = (int)($bien['nb_salles_bain'] ?? 0);
        $nbSdE = (int)($bien['nb_salles_eau']  ?? 0);
        $eauVal   = $nbSdb > 0 ? $nbSdb : $nbSdE;
        $eauLabel = $nbSdb > 0 ? 'SDB' : ($nbSdE > 0 ? 'SDE' : 'SDB');

        $pictos = [
            [$surf  > 0 ? (int)$surf . ' M²' : '—',   'SURFACE',  [70, 130, 180]],
            [$nbPcs > 0 ? $nbPcs . ' P.'     : '—',   'PIÈCES',   [212, 160, 71]],
            [$nbCh  > 0 ? (string)$nbCh      : '—',   'CHAMBRE(S)', [195, 130, 100]],
            [$eauVal > 0 ? (string)$eauVal   : '—',   $eauLabel,  [95, 138, 110]],
        ];
        $picY = 86;
        $picCellW = ($rW - 6) / 4; // 4 cellules avec 2mm de marge G/D
        $picCellH = 28;
        foreach ($pictos as $i => [$val, $label, $rgb]) {
            $x = $rX + 3 + $i * $picCellW;
            // Carré coloré
            $pdf->SetFillColor($rgb[0], $rgb[1], $rgb[2]);
            $pdf->RoundedRect($x + 2, $picY, $picCellW - 4, 20, 2, '1111', 'F');
            // Valeur en blanc
            $pdf->SetFont('dejavusans', 'B', 9);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY($x + 2, $picY + 6);
            $pdf->Cell($picCellW - 4, 8, $val, 0, 0, 'C');
            // Label sous la cellule
            $pdf->SetFont('dejavusans', 'B', 6);
            $pdf->SetTextColor(80, 80, 80);
            $pdf->SetXY($x + 2, $picY + 21);
            $pdf->Cell($picCellW - 4, 4, $label, 0, 0, 'C');
        }

        // 2.4 — Description (sans IA)
        $description = '';
        foreach (['_annonce_description', 'annonce_description', '_annonce_descriptif'] as $k) {
            if (!empty($bien[$k])) { $description = trim((string)$bien[$k]); break; }
        }
        if ($description === '') {
            foreach (['description', 'descriptif'] as $k) {
                if (!empty($bien[$k])) { $description = trim((string)$bien[$k]); break; }
            }
        }
        $descY = 126;
        if ($description !== '') {
            $pdf->SetFont('dejavusans', '', 8);
            $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
            $pdf->SetXY($rX, $descY);
            $maxChars = 600;
            $tronc = mb_strlen($description, 'UTF-8') > $maxChars
                ? mb_substr($description, 0, $maxChars, 'UTF-8') . '…'
                : $description;
            $pdf->MultiCell($rW, 3.6, $tronc, 0, 'L');
        } else {
            // Placeholder discret quand pas de description
            $pdf->SetFillColor(250, 250, 250);
            $pdf->SetDrawColor(225, 225, 225);
            $pdf->SetLineWidth(0.3);
            $pdf->RoundedRect($rX, $descY, $rW, 50, 2, '1111', 'DF');
            $pdf->SetFont('dejavusans', 'I', 8);
            $pdf->SetTextColor(150, 150, 150);
            $pdf->SetXY($rX, $descY + 22);
            $pdf->Cell($rW, 5, '[ description à compléter ]', 0, 0, 'C');
        }

        // 2.5 — DPE / GES (descendu un peu, position plus basse)
        $dpe = strtoupper(trim((string)($bien['dpe_classe'] ?? $bien['dpe'] ?? '')));
        $ges = strtoupper(trim((string)($bien['ges_classe'] ?? $bien['ges'] ?? '')));
        $dpeVal = (float)($bien['dpe_valeur'] ?? 0);
        $gesVal = (float)($bien['ges_valeur'] ?? 0);
        if (($dpe !== '' || $ges !== '') && function_exists('mbi_supports_tpl_dpe_ges')) {
            mbi_supports_tpl_dpe_ges(
                $pdf, $rX, 213, $rW, $dpe, $ges, null,
                $dpeVal > 0 ? $dpeVal : null,
                $gesVal > 0 ? $gesVal : null
            );
        }

        // ════════════════════════════════════════════════════════════
        // ZONE 3 — 4 MINI-PHOTOS sous la photo héro (ratio 16:9 + cadre)
        // ════════════════════════════════════════════════════════════
        $miniY = 213;
        $miniGap = 4;
        $miniW = ($heroW - 3 * $miniGap) / 4;   // = 69.5mm
        $miniH = round($miniW * 9 / 16, 1);      // = 39.1mm (ratio 16:9 régulier)

        $heroId = (int)($hero['id'] ?? 0);
        $minis = [];
        foreach ($photos as $p) {
            if ((int)($p['id'] ?? 0) !== $heroId) {
                $mp = mbi_supports_resoudre_photo_path($p);
                if ($mp !== null && is_file($mp)) $minis[] = $mp;
                if (count($minis) >= 4) break;
            }
        }
        for ($i = 0; $i < 4; $i++) {
            $mx = $heroX + $i * ($miniW + $miniGap);
            // Image (ou fond gris si manque)
            if (isset($minis[$i])) {
                try {
                    $pdf->Image($minis[$i], $mx, $miniY, $miniW, $miniH, '', '', '', false, 200, '', false, false, 0, 'CM', false, false);
                } catch (Throwable) {
                    $pdf->SetFillColor(245, 245, 245);
                    $pdf->Rect($mx, $miniY, $miniW, $miniH, 'F');
                }
            } else {
                $pdf->SetFillColor(245, 245, 245);
                $pdf->Rect($mx, $miniY, $miniW, $miniH, 'F');
            }
            // Cadre fin gris autour de CHAQUE mini-photo (uniformité visuelle)
            $pdf->SetDrawColor(200, 200, 200);
            $pdf->SetLineWidth(0.3);
            $pdf->Rect($mx, $miniY, $miniW, $miniH, 'D');
        }

        // ════════════════════════════════════════════════════════════
        // ZONE 4 — MENTION LÉGALE (ligne fine)
        // ════════════════════════════════════════════════════════════
        $mentionLegale = mbi_supports_cezane_construire_mention_legale($societe, $agence);
        if ($mentionLegale !== '') {
            // Mention légale sur 2 lignes max, plein largeur, taille adaptative
            // Position : Y=255 (= sous les mini-photos qui finissent à 252)
            $pdf->SetFont('dejavusans', '', 6.2);
            $pdf->SetTextColor(70, 70, 70);
            $pdf->SetXY(6, 255);
            $pdf->MultiCell($pageW - 12, 2.6, $mentionLegale, 0, 'C', false, 1, '', '', true, 0, false, true, 6, 'T');
        }

        // ════════════════════════════════════════════════════════════
        // ZONE 5 — BANDEAU NAVY (À LOUER / RÉF / PRIX)
        // ════════════════════════════════════════════════════════════
        $bandY = 265; $bandH = 32;
        $pdf->SetFillColor($cP[0], $cP[1], $cP[2]);
        $pdf->Rect(0, $bandY, $pageW, $bandH, 'F');

        $typeTrans = strtolower(trim((string)($bien['_annonce_type_transaction'] ?? $bien['type_transaction'] ?? 'location')));
        $isVente   = strpos($typeTrans, 'vente') !== false;
        $libTrans  = $isVente ? 'À VENDRE' : 'À LOUER';
        $typeBien  = trim((string)($bien['type_bien_libelle'] ?? $bien['type'] ?? 'BIEN'));

        // Bandeau 1 : À LOUER + type + ville
        $pdf->SetFont('dejavusans', 'B', 14);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(12, $bandY + 6);
        $pdf->Cell(135, 7, $libTrans . ' - ' . mb_strtoupper($typeBien, 'UTF-8'), 0, 0, 'L');
        if ($ville !== '') {
            $pdf->SetFont('dejavusans', '', 10);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY(12, $bandY + 16);
            $pdf->Cell(135, 5, mb_strtoupper($ville, 'UTF-8'), 0, 0, 'L');
        }

        // Bandeau 2 : Référence + honoraires + DG/charges
        $ref = (string)($bien['reference_bien'] ?? ('#' . ($bien['id'] ?? '')));
        $pdf->SetFont('dejavusans', 'B', 10);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(155, $bandY + 4);
        $pdf->Cell(170, 5, 'RÉFÉRENCE : ' . $ref, 0, 0, 'L');

        $honoTxt = mbi_supports_cezane_construire_honoraires($bien);
        if ($honoTxt !== '') {
            $pdf->SetFont('dejavusans', '', 7);
            $pdf->SetTextColor(220, 220, 220);
            $pdf->SetXY(155, $bandY + 11);
            $pdf->MultiCell(170, 3, $honoTxt, 0, 'L');
        }

        // Bandeau 3 : Prix XL (ou "Prix sur demande" si vide)
        $prixVal = 0;
        if ($isVente) {
            $prixVal = (float)($bien['_annonce_prix'] ?? $bien['prix'] ?? 0);
        } else {
            $prixVal = (float)($bien['_annonce_loyer_cc'] ?? $bien['loyer_cc'] ?? $bien['_annonce_loyer'] ?? $bien['loyer'] ?? 0);
        }
        if ($prixVal > 0) {
            $prixLabel = $isVente ? '€' : '€ CC';
            $pdf->SetFont('dejavusans', 'B', 30);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY(330, $bandY + 7);
            $pdf->Cell(82, 18, number_format($prixVal, 0, ',', ' ') . ' ' . $prixLabel, 0, 0, 'R');
        } else {
            // "Prix sur demande" si pas de prix saisi
            $pdf->SetFont('dejavusans', 'B', 16);
            $pdf->SetTextColor($cS[0], $cS[1], $cS[2]);
            $pdf->SetXY(330, $bandY + 12);
            $pdf->Cell(82, 10, 'PRIX SUR DEMANDE', 0, 0, 'R');
        }

        // ─── Tag "Logement meublé" en surimpression sur photo héro ───
        $isMeuble = !empty($bien['_annonce_meuble']) || !empty($bien['meuble']) || !empty($bien['loyer_meuble']);
        if ($isMeuble) {
            $pdf->SetFillColor($cS[0], $cS[1], $cS[2]);
            $pdf->Rect($heroX, $heroY + $heroH - 10, 50, 10, 'F');
            $pdf->SetFont('dejavusans', 'B', 10);
            $pdf->SetTextColor(255, 255, 255);
            $pdf->SetXY($heroX, $heroY + $heroH - 8.5);
            $pdf->Cell(50, 6, 'LOGEMENT MEUBLÉ', 0, 0, 'C');
        }

        return $pdf;
    }
}

// ═════════════════════════════════════════════════════════════════════════
// HELPERS (déterministes, aucune IA)
// ═════════════════════════════════════════════════════════════════════════

if (!function_exists('mbi_supports_cezane_resoudre_logo')) {
    function mbi_supports_cezane_resoudre_logo(array $societe, array $agence, array $style): ?string
    {
        $candidates = [];
        if (!empty($style['logo_path'])) $candidates[] = (string)$style['logo_path'];
        $idSoc = (int)($societe['id'] ?? 0);
        $idAg  = (int)($agence['id'] ?? 0);
        $root  = realpath(__DIR__ . '/../..');
        foreach (['png','jpg','jpeg'] as $ext) {
            if ($idSoc > 0) {
                $candidates[] = $root . "/uploads/societes/{$idSoc}/logo.{$ext}";
                $candidates[] = $root . "/images/logos_societes/{$idSoc}.{$ext}";
            }
            if ($idAg > 0) {
                $candidates[] = $root . "/uploads/agences/{$idAg}/logo.{$ext}";
                $candidates[] = $root . "/images/logos_agences/{$idAg}.{$ext}";
            }
        }
        foreach ($candidates as $p) {
            if ($p && is_file($p) && is_readable($p)) return $p;
        }
        return null;
    }
}

if (!function_exists('mbi_supports_cezane_construire_mention_legale')) {
    function mbi_supports_cezane_construire_mention_legale(array $societe, array $agence): string
    {
        $rs       = trim((string)($societe['raison_sociale'] ?? $societe['nom'] ?? ''));
        $forme    = trim((string)($societe['forme_juridique'] ?? ''));
        $siret    = trim((string)($societe['siret'] ?? ''));
        $capital  = trim((string)($societe['capital_social'] ?? ''));
        $garant   = trim((string)($societe['garantie_financiere'] ?? $societe['garant_nom'] ?? $societe['garant'] ?? ''));
        $rcs      = trim((string)($societe['rcs'] ?? $societe['num_rcs'] ?? $societe['siren'] ?? ''));
        $cartePro = trim((string)($societe['numero_carte_t'] ?? $agence['carte_pro_numero'] ?? ''));
        $cci      = trim((string)($societe['cci_carte_t'] ?? $agence['carte_pro_delivree_par'] ?? ''));
        $cartePDate = trim((string)($societe['carte_t_date_expiration'] ?? $agence['carte_pro_date'] ?? ''));
        $garMnt   = trim((string)($societe['garant_montant'] ?? ''));

        $parts = [];
        if ($rs !== '') {
            $bloc = strtoupper($rs);
            if ($forme !== '')  $bloc .= ' ' . $forme;
            if ($siret !== '')  $bloc .= ', SIRET : ' . $siret . '.';
            $parts[] = $bloc;
        }
        if ($capital !== '')  $parts[] = 'Capital de ' . $capital . ' €,';
        if ($garant !== '')   $parts[] = 'Garant : ' . $garant;
        if ($rcs !== '')      $parts[] = '| RCS : ' . $rcs . '.';
        if ($cartePro !== '') {
            $cpTxt = 'Carte professionnelle N° ' . $cartePro;
            if ($cci !== '')        $cpTxt .= ' délivrée par ' . $cci;
            if ($cartePDate !== '') $cpTxt .= ' le ' . $cartePDate;
            $parts[] = $cpTxt . ',';
        }
        if ($garMnt !== '')   $parts[] = 'Montant garanti de ' . $garMnt . ' €.';
        return implode(' ', $parts);
    }
}

if (!function_exists('mbi_supports_cezane_construire_honoraires')) {
    function mbi_supports_cezane_construire_honoraires(array $bien): string
    {
        $typeTrans = strtolower((string)($bien['_annonce_type_transaction'] ?? $bien['type_transaction'] ?? ''));
        $isVente   = strpos($typeTrans, 'vente') !== false;
        if ($isVente) {
            $pct = (float)($bien['_annonce_alur_pct'] ?? $bien['alur_pourcentage_honoraires_ttc'] ?? 0);
            $charge = !empty($bien['_annonce_honoraires_charge_acquereur']) ? 'acquéreur' : 'vendeur';
            if ($pct > 0) {
                return sprintf('Honoraires à charge %s : %s %% TTC.', $charge, number_format($pct, 2, ',', ' '));
            }
            return '';
        }
        $honoLoc = (float)($bien['_annonce_honoraires_location_bail'] ?? $bien['honoraires_location_bail'] ?? 0);
        $honoEdl = (float)($bien['_annonce_honoraires_edl'] ?? $bien['honoraires_etat_des_lieux'] ?? 0);
        $surf    = (float)($bien['surface_habitable'] ?? 0);
        $depot   = (float)($bien['_annonce_depot_garantie'] ?? $bien['depot_garantie'] ?? 0);
        $charges = (float)($bien['_annonce_charges'] ?? $bien['charges_locatives'] ?? 0);
        $total   = $honoLoc + $honoEdl;

        $parts = [];
        if ($total > 0) {
            $txt = sprintf('Honoraires locataire : %s € TTC', number_format($total, 2, ',', ' '));
            if ($surf > 0) $txt .= sprintf(' (%s €/m²)', number_format($total / $surf, 2, ',', ' '));
            $txt .= sprintf(' — EDL : %s €.', number_format($honoEdl, 2, ',', ' '));
            $parts[] = $txt;
        }
        if ($depot > 0)   $parts[] = sprintf('Dépôt : %s €.', number_format($depot, 0, ',', ' '));
        if ($charges > 0) $parts[] = sprintf('Charges : %s €/mois.', number_format($charges, 0, ',', ' '));
        return implode(' ', $parts);
    }
}
