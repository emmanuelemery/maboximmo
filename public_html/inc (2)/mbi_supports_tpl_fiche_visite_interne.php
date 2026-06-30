<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Template : Fiche visite interne (jamais diffusée)
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Anti-fuite (cf. project_supports_commerciaux.md) :
 *   - Filigrane "INTERNE — NE PAS DIFFUSER" diagonal sur chaque page
 *   - Badge rouge en en-tête
 *   - Suffixe _INTERNE.pdf imposé par mbi_supports_nom_fichier()
 *
 * Sections :
 *   1. Bandeau interne + caractéristiques
 *   2. Points forts à mettre en avant (issus du score IA)
 *   3. Objections probables / réponses commerciales
 *   4. Points sensibles à ne pas aborder spontanément
 *   5. Questions à poser au visiteur
 *   6. Documents à préparer
 * ═══════════════════════════════════════════════════════════════════════
 */

if (!function_exists('mbi_supports_tpl_fiche_visite_interne_build')) {

    function mbi_supports_tpl_fiche_visite_interne_build(array $ctx): TCPDF
    {
        require_once __DIR__ . '/mbi_supports_tpl_affiche_vitrine.php'; // helpers communs

        $bien        = $ctx['bien']        ?? [];
        $photos      = $ctx['photos']      ?? [];
        $agence      = $ctx['agence']      ?? [];
        $negociateur = $ctx['negociateur'] ?? [];
        $style       = $ctx['style']       ?? [];
        $score       = $ctx['score']       ?? null;

        $cP = $style['rgb_primaire']   ?? [36, 59, 92];
        $cS = $style['rgb_secondaire'] ?? [212, 160, 71];
        $cT = $style['rgb_texte']      ?? [31, 41, 55];

        // Couleur "interne" rouge sourd
        $cR = [165, 40, 40];

        $pdf = new MbiSupportsTcpdfInterne('P', 'mm', 'A4', true, 'UTF-8');
        $pdf->mbiInterneColor = $cR;
        $pdf->SetCreator('MaBoxImmo — Ma Box Communication (INTERNE)');
        $pdf->SetTitle('Fiche visite interne — NE PAS DIFFUSER');
        $pdf->SetMargins(15, 24, 15);
        $pdf->SetAutoPageBreak(true, 20);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        // ── Bandeau interne ─────────────────────────────────────────────
        $pdf->SetFillColor($cR[0], $cR[1], $cR[2]);
        $pdf->Rect(0, 0, 210, 16, 'F');
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(15, 3);
        $pdf->Cell(180, 5, '⚠ DOCUMENT INTERNE — NE PAS DIFFUSER AU CLIENT', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetX(15);
        $pdf->Cell(180, 4, 'Réservé négociateur · accès restreint agence · journalisation des téléchargements', 0, 1, 'L');

        // ── Désignation + bien ─────────────────────────────────────────
        $designation = (string)($bien['designation'] ?? $bien['reference_bien'] ?? 'Bien');
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
        $pdf->SetXY(15, 22);
        $pdf->MultiCell(180, 7, $designation, 0, 'L');

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
        $line = trim((string)($bien['code_postal'] ?? '') . ' ' . ($bien['ville'] ?? ''));
        $line .= ' · ' . mbi_supports_format_surface(mbi_supports_get_surface($bien));
        if (!empty($bien['nb_pieces'])) $line .= ' · ' . $bien['nb_pieces'] . ' pièces';
        $line .= ' · ' . mbi_supports_format_prix(mbi_supports_get_prix($bien));
        $pdf->SetX(15);
        $pdf->MultiCell(180, 5, $line, 0, 'L');

        // ── Bloc score IA ──────────────────────────────────────────────
        if ($score) {
            $pdf->Ln(3);
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
            $pdf->Cell(180, 7, 'Score commercial : ' . (int)($score['score'] ?? 0) . ' / 100', 0, 1, 'L');
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetTextColor(110, 116, 130);
            $angle = (string)($score['angle_recommande'] ?? '—');
            $urgence = (string)($score['niveau_urgence'] ?? '—');
            $pdf->Cell(180, 5, "Angle recommandé : {$angle} · Niveau d'urgence commerciale : {$urgence}", 0, 1, 'L');
        }

        // ── Points forts (à mettre en avant) ───────────────────────────
        $pointsForts = $score && !empty($score['points_forts_json'])
            ? (json_decode((string)$score['points_forts_json'], true) ?: [])
            : [];
        mbi_supports_tpl_section($pdf, 'Points forts à mettre en avant', $pointsForts, $cP, $cT, [26, 94, 54]);

        // ── Points faibles / objections probables ──────────────────────
        $pointsFaibles = $score && !empty($score['points_faibles_json'])
            ? (json_decode((string)$score['points_faibles_json'], true) ?: [])
            : [];
        mbi_supports_tpl_section($pdf, 'Objections probables — préparer une réponse', $pointsFaibles, $cP, $cT, [120, 60, 30]);

        // ── Points sensibles à ne pas aborder spontanément ─────────────
        $sensibles = mbi_supports_tpl_points_sensibles_par_defaut($bien);
        mbi_supports_tpl_section($pdf, 'Points sensibles — à ne pas aborder spontanément', $sensibles, $cP, $cT, [165, 88, 88]);

        // ── Questions à poser au visiteur ──────────────────────────────
        $questions = mbi_supports_tpl_questions_visite_par_defaut();
        mbi_supports_tpl_section($pdf, 'Questions à poser au visiteur', $questions, $cP, $cT, [90, 110, 138]);

        // ── Documents à préparer avant visite ──────────────────────────
        $docs = mbi_supports_tpl_documents_a_preparer($bien);
        mbi_supports_tpl_section($pdf, 'Documents à préparer avant la visite', $docs, $cP, $cT, [122, 104, 152]);

        return $pdf;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Classe TCPDF étendue : filigrane "INTERNE" diagonal sur chaque page
// ─────────────────────────────────────────────────────────────────────────

if (!class_exists('MbiSupportsTcpdfInterne')) {
    class MbiSupportsTcpdfInterne extends TCPDF
    {
        public array $mbiInterneColor = [165, 40, 40];

        public function Header(): void
        {
            // Filigrane diagonal
            $this->StartTransform();
            $this->SetTextColor($this->mbiInterneColor[0], $this->mbiInterneColor[1], $this->mbiInterneColor[2]);
            $this->SetAlpha(0.10);
            $this->SetFont('helvetica', 'B', 60);
            $this->Rotate(45, 105, 140);
            $this->Text(20, 160, 'INTERNE — NE PAS DIFFUSER');
            $this->SetAlpha(1.0);
            $this->StopTransform();
        }

        public function Footer(): void
        {
            $this->SetY(-15);
            $this->SetFont('helvetica', 'I', 7);
            $this->SetTextColor($this->mbiInterneColor[0], $this->mbiInterneColor[1], $this->mbiInterneColor[2]);
            $this->Cell(0, 4, 'INTERNE — NE PAS DIFFUSER · Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Helpers spécifiques fiche interne
// ─────────────────────────────────────────────────────────────────────────

if (!function_exists('mbi_supports_tpl_section')) {
    function mbi_supports_tpl_section(TCPDF $pdf, string $titre, array $items, array $cP, array $cT, array $accent): void
    {
        $pdf->Ln(4);
        // Titre avec barre verticale d'accent
        $y = $pdf->GetY();
        $pdf->SetFillColor($accent[0], $accent[1], $accent[2]);
        $pdf->Rect(15, $y, 1.5, 7, 'F');
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetTextColor($cP[0], $cP[1], $cP[2]);
        $pdf->SetXY(19, $y);
        $pdf->Cell(176, 7, $titre, 0, 1, 'L');

        if (empty($items)) {
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->SetTextColor(140, 144, 154);
            $pdf->SetX(19);
            $pdf->Cell(176, 5, '— à compléter —', 0, 1, 'L');
            return;
        }

        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetTextColor($cT[0], $cT[1], $cT[2]);
        foreach ($items as $i => $item) {
            $pdf->SetX(19);
            $pdf->MultiCell(176, 5, '• ' . (string)$item, 0, 'L');
        }
    }
}

if (!function_exists('mbi_supports_tpl_points_sensibles_par_defaut')) {
    function mbi_supports_tpl_points_sensibles_par_defaut(array $bien): array
    {
        $points = [];
        $dpe = strtoupper(trim((string)($bien['dpe_classe'] ?? '')));
        if (in_array($dpe, ['F','G'], true)) {
            $points[] = "DPE {$dpe} (passoire thermique). Ne pas le mentionner avant que le visiteur ait validé l'attrait du bien — ensuite, présenter les solutions de rénovation et l'éligibilité aux aides.";
        }
        if ((int)($bien['bien_en_copropriete'] ?? 0) === 1 && (int)($bien['copro_procedure'] ?? 0) === 1) {
            $points[] = "Procédures L.611-1 actives sur la copropriété — laisser le client poser la question, préparer les éléments factuels en interne.";
        }
        $hInclus = strtolower((string)($bien['honoraires_inclus'] ?? ''));
        if (str_contains($hInclus, 'acqu')) {
            $points[] = "Honoraires charge acquéreur : présenter la valeur ajoutée du service avant d'aborder le montant.";
        }
        return $points;
    }
}

if (!function_exists('mbi_supports_tpl_questions_visite_par_defaut')) {
    function mbi_supports_tpl_questions_visite_par_defaut(): array
    {
        return [
            "Pour quel projet visitez-vous ce bien (résidence principale, locatif, secondaire) ?",
            "Quel est votre horizon (rapide, 3-6 mois, 6+ mois) ?",
            "Avez-vous un financement validé ou en cours de constitution ?",
            "Devez-vous vendre un bien actuel pour financer celui-ci ?",
            "Qu'est-ce qui est rédhibitoire pour vous (étage, exposition, travaux, copro) ?",
            "Avez-vous visité d'autres biens ? Qu'avez-vous retenu ?",
            "À quelle échéance souhaiteriez-vous être positionné si ce bien correspond ?",
        ];
    }
}

if (!function_exists('mbi_supports_tpl_documents_a_preparer')) {
    function mbi_supports_tpl_documents_a_preparer(array $bien): array
    {
        $docs = [
            "Mandat / autorisation de diffusion (vérifier validité)",
            "DPE / GES (édition récente)",
            "État des risques (ERP) si demandé",
        ];
        if ((int)($bien['bien_en_copropriete'] ?? 0) === 1) {
            $docs[] = "Règlement de copropriété + 3 derniers PV d'AG";
            $docs[] = "Décompte de charges courantes annuelles";
            $docs[] = "Carnet d'entretien de la copropriété";
        }
        $docs[] = "Plans (cadastre + plan d'étage si dispo)";
        $docs[] = "Photos haute définition (smartphone / tablette)";
        $docs[] = "Carte pro de l'agence (en cas de contrôle)";
        return $docs;
    }
}
