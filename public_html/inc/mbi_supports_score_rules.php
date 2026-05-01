<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * mbi_supports_score_rules.php — Grille déterministe du score commercial
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Module : Ma Box Communication (mbi_supports)
 *
 * Fonction PURE (pas d'I/O, pas de DB, pas de réseau) — testable isolément.
 *
 * Pondération V1 (validée 2026-05-01) :
 *   Photos          25  — nb exploitables (10) + héro (5) + couverture (5) + virtuel (5)
 *   Description     15  — longueur (5) + spécificité (10, fallback déterministe)
 *   Énergie         20  — DPE (13) + GES (7)
 *   Complétude       15 — surface, étage, expo, état, équipements, charges
 *   Prix vs marché  10  — V1 = 5/10 neutre (comparatif marché en V4)
 *   Documents       10  — ERP + plans + dossier copro
 *   Fraîcheur        5  — date_modification ≤ 30j
 *   ─────────────
 *   Total           100
 *
 * API :
 *   mbi_supports_rules_calcul(array $bien, array $photos): array
 *     → { total:int, breakdown:array, signaux:array }
 *
 * Le breakdown est conçu pour être stocké tel quel dans
 * bien_score_commercial.score_breakdown_json afin d'expliquer le score.
 * ═══════════════════════════════════════════════════════════════════════
 */

if (!function_exists('mbi_supports_rules_calcul')) {

    /**
     * Calcule le score déterministe à partir des données brutes du bien et
     * de ses photos. Aucun appel externe.
     *
     * @param array $bien    Ligne SELECT * FROM biens (clés tolérantes)
     * @param array $photos  Liste SELECT * FROM bien_photos WHERE id_bien=…
     * @return array{
     *   total:int,
     *   breakdown: array<string, array{libelle:string, max:int, points:float, details:array<string,mixed>}>,
     *   signaux: array<string, mixed>
     * }
     */
    function mbi_supports_rules_calcul(array $bien, array $photos): array
    {
        $bd = [
            'photos'        => mbi_supports_score_bloc_photos($bien, $photos),
            'description'   => mbi_supports_score_bloc_description($bien),
            'energie'       => mbi_supports_score_bloc_energie($bien),
            'completude'    => mbi_supports_score_bloc_completude($bien),
            'prix_marche'   => mbi_supports_score_bloc_prix($bien),
            'documents'     => mbi_supports_score_bloc_documents($bien),
            'fraicheur'     => mbi_supports_score_bloc_fraicheur($bien),
        ];

        $total = 0.0;
        foreach ($bd as $b) { $total += (float)$b['points']; }
        $total = (int)round(min(100.0, max(0.0, $total)));

        return [
            'total'      => $total,
            'breakdown'  => $bd,
            'signaux'    => mbi_supports_score_signaux($bien, $photos, $bd),
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Bloc 1 : PHOTOS (25 pts)
// ─────────────────────────────────────────────────────────────────────────
if (!function_exists('mbi_supports_score_bloc_photos')) {
    function mbi_supports_score_bloc_photos(array $bien, array $photos): array
    {
        $nbExploit = 0;
        $nbHero    = 0;
        $couverturePieces = 0;
        $hasVirtuel = false;

        foreach ($photos as $p) {
            $isExploit = (bool)($p['exploitable'] ?? true); // par défaut exploitable
            if ($isExploit) $nbExploit++;
            if ((int)($p['is_hero'] ?? $p['hero'] ?? 0) === 1) $nbHero++;
        }

        // Couverture pièces : approximation V1 — on compte les types distincts
        $types = [];
        foreach ($photos as $p) {
            $t = strtolower(trim((string)($p['type_piece'] ?? $p['piece'] ?? $p['categorie'] ?? '')));
            if ($t !== '') $types[$t] = true;
        }
        $couverturePieces = count($types);

        $hasVirtuel = !empty($bien['url_visite_virtuelle'] ?? $bien['visite_virtuelle'] ?? $bien['matterport_url'] ?? '');

        // Pondérations
        $ptsNb         = min(10.0, $nbExploit * 1.0);                // 10 pts pour 10+ photos
        $ptsHero       = $nbHero >= 1 ? 5.0 : 0.0;                   // 5 pts si héro défini
        $ptsCouverture = min(5.0, $couverturePieces * 1.0);          // 5 pts pour 5+ types pièces
        $ptsVirtuel    = $hasVirtuel ? 5.0 : 0.0;                    // 5 pts si virtuel/360°

        $total = $ptsNb + $ptsHero + $ptsCouverture + $ptsVirtuel;

        return [
            'libelle'  => 'Photos',
            'max'      => 25,
            'points'   => round($total, 1),
            'details'  => [
                'nb_exploitables'    => $nbExploit,
                'nb_hero'            => $nbHero,
                'couverture_pieces'  => $couverturePieces,
                'has_virtuel'        => $hasVirtuel,
                'sous_totaux'        => [
                    'nb'        => round($ptsNb, 1),
                    'hero'      => round($ptsHero, 1),
                    'couverture'=> round($ptsCouverture, 1),
                    'virtuel'   => round($ptsVirtuel, 1),
                ],
            ],
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Bloc 2 : DESCRIPTION (15 pts)
// ─────────────────────────────────────────────────────────────────────────
if (!function_exists('mbi_supports_score_bloc_description')) {
    function mbi_supports_score_bloc_description(array $bien): array
    {
        $desc = (string)($bien['description'] ?? $bien['descriptif'] ?? $bien['descriptif_long'] ?? '');
        $longueur = mb_strlen(trim($desc));

        // Longueur (5 pts) — palier à 200 chars
        $ptsLongueur = match (true) {
            $longueur >= 600 => 5.0,
            $longueur >= 400 => 4.0,
            $longueur >= 250 => 3.0,
            $longueur >= 150 => 2.0,
            $longueur >= 50  => 1.0,
            default          => 0.0,
        };

        // Spécificité (10 pts) — heuristique déterministe
        // Présence de mots-clés concrets vs description générique vague.
        $motsConcrets = ['cuisine','salon','chambre','salle','jardin','terrasse','balcon',
                         'parking','cave','grenier','exposition','sud','nord','vue',
                         'lumineux','calme','rénové','neuf','récent','ancien','charme',
                         'parquet','moulures','cheminée','climatisation','double vitrage'];
        $descLower = mb_strtolower($desc);
        $hits = 0;
        foreach ($motsConcrets as $m) {
            if (str_contains($descLower, $m)) $hits++;
        }
        // 10 pts à 12 mots-clés concrets identifiés
        $ptsSpecif = min(10.0, $hits * (10.0 / 12.0));

        $total = $ptsLongueur + $ptsSpecif;

        return [
            'libelle' => 'Description',
            'max'     => 15,
            'points'  => round($total, 1),
            'details' => [
                'longueur_chars'      => $longueur,
                'mots_concrets_hits'  => $hits,
                'sous_totaux' => [
                    'longueur'    => round($ptsLongueur, 1),
                    'specificite' => round($ptsSpecif, 1),
                ],
            ],
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Bloc 3 : ÉNERGIE (20 pts) — ajustement V1 de 15→20 (loi Climat)
// ─────────────────────────────────────────────────────────────────────────
if (!function_exists('mbi_supports_score_bloc_energie')) {
    function mbi_supports_score_bloc_energie(array $bien): array
    {
        $dpe    = strtoupper(trim((string)($bien['dpe_classe'] ?? $bien['dpe'] ?? $bien['etiquette_dpe'] ?? '')));
        $ges    = strtoupper(trim((string)($bien['ges_classe'] ?? $bien['ges'] ?? $bien['etiquette_ges'] ?? '')));
        $statut = strtolower(trim((string)($bien['dpe_statut'] ?? '')));

        // Si statut = en_cours / non_soumis → score neutre médian
        if ($statut === 'en_cours' || $statut === 'non_soumis') {
            return [
                'libelle' => 'Énergie',
                'max'     => 20,
                'points'  => 12.0,
                'details' => [
                    'dpe_classe' => $dpe ?: null,
                    'ges_classe' => $ges ?: null,
                    'dpe_statut' => $statut,
                    'note'       => 'Statut DPE structuré → score médian appliqué',
                ],
            ];
        }

        // Barème DPE (13 pts max)
        $ptsDpe = match ($dpe) {
            'A' => 13.0,
            'B' => 11.5,
            'C' => 10.0,
            'D' => 7.5,
            'E' => 4.5,
            'F' => 2.0,
            'G' => 0.5,
            default => 0.0,
        };

        // Barème GES (7 pts max)
        $ptsGes = match ($ges) {
            'A' => 7.0,
            'B' => 6.0,
            'C' => 5.0,
            'D' => 3.5,
            'E' => 2.0,
            'F' => 1.0,
            'G' => 0.0,
            default => 0.0,
        };

        $total = $ptsDpe + $ptsGes;

        return [
            'libelle' => 'Énergie',
            'max'     => 20,
            'points'  => round($total, 1),
            'details' => [
                'dpe_classe' => $dpe ?: null,
                'ges_classe' => $ges ?: null,
                'dpe_statut' => $statut ?: null,
                'sous_totaux' => [
                    'dpe' => round($ptsDpe, 1),
                    'ges' => round($ptsGes, 1),
                ],
            ],
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Bloc 4 : COMPLÉTUDE FICHE (15 pts)
// ─────────────────────────────────────────────────────────────────────────
if (!function_exists('mbi_supports_score_bloc_completude')) {
    function mbi_supports_score_bloc_completude(array $bien): array
    {
        $champsCles = [
            'surface_habitable' => ['surface_habitable','surface'],
            'nb_pieces'         => ['nb_pieces','nombre_pieces','pieces'],
            'etage'             => ['etage'],
            'exposition'        => ['exposition','orientation'],
            'etat'              => ['etat','etat_general','etat_bien'],
            'chauffage_type'    => ['chauffage_type','type_chauffage','chauffage'],
            'annee_construction'=> ['annee_construction','annee_construction_estimee'],
            'charges_mois'      => ['charges_copro','charges_mensuelles','charges_mois'],
        ];

        $remplis = 0;
        $detail  = [];
        foreach ($champsCles as $libelle => $candidats) {
            $val = null;
            foreach ($candidats as $c) {
                if (isset($bien[$c]) && $bien[$c] !== null && $bien[$c] !== '' && $bien[$c] !== '0') {
                    $val = $bien[$c]; break;
                }
            }
            $present = $val !== null;
            if ($present) $remplis++;
            $detail[$libelle] = $present;
        }

        $totalChamps = count($champsCles);
        $points = $totalChamps > 0 ? ($remplis / $totalChamps) * 15.0 : 0.0;

        return [
            'libelle' => 'Complétude fiche',
            'max'     => 15,
            'points'  => round($points, 1),
            'details' => [
                'nb_champs_remplis' => $remplis,
                'nb_champs_total'   => $totalChamps,
                'champs_remplis'    => $detail,
            ],
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Bloc 5 : PRIX vs MARCHÉ (10 pts) — V1 neutre 5/10
// ─────────────────────────────────────────────────────────────────────────
if (!function_exists('mbi_supports_score_bloc_prix')) {
    function mbi_supports_score_bloc_prix(array $bien): array
    {
        $prix    = (float)($bien['prix_vente'] ?? $bien['prix'] ?? $bien['prix_total'] ?? 0);
        $surface = (float)($bien['surface_habitable'] ?? $bien['surface'] ?? 0);
        $prixM2  = ($prix > 0 && $surface > 0) ? round($prix / $surface, 0) : null;

        // V1 : pas de comparatif marché disponible → note neutre 5/10
        // (le module comparatif arrivera en V4)
        $points = ($prix > 0 && $surface > 0) ? 5.0 : 0.0;

        return [
            'libelle' => 'Prix vs marché',
            'max'     => 10,
            'points'  => round($points, 1),
            'details' => [
                'prix_vente' => $prix > 0 ? $prix : null,
                'surface'    => $surface > 0 ? $surface : null,
                'prix_m2'    => $prixM2,
                'note'       => 'V1 : note neutre — module comparatif marché prévu V4',
            ],
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Bloc 6 : DOCUMENTS (10 pts)
// ─────────────────────────────────────────────────────────────────────────
if (!function_exists('mbi_supports_score_bloc_documents')) {
    function mbi_supports_score_bloc_documents(array $bien): array
    {
        $hasErp        = !empty($bien['erp_present'] ?? $bien['erp'] ?? $bien['etat_risques_url'] ?? '');
        $hasPlan       = !empty($bien['plan_url'] ?? $bien['plan_path'] ?? $bien['has_plan'] ?? '');
        $hasDossierCopro = !empty($bien['dossier_copro_url'] ?? $bien['has_dossier_copro'] ?? '');

        // Si non copro, on neutralise le 3e item (3 pts répartis)
        $estCopro = (int)($bien['copropriete'] ?? $bien['est_copro'] ?? $bien['en_copropriete'] ?? 0) === 1;

        $ptsErp  = $hasErp  ? 4.0 : 0.0;  // 4 pts (le plus critique)
        $ptsPlan = $hasPlan ? 3.0 : 0.0;
        $ptsCopro = $estCopro ? ($hasDossierCopro ? 3.0 : 0.0) : 3.0; // hors copro = 3 pts auto

        $total = $ptsErp + $ptsPlan + $ptsCopro;

        return [
            'libelle' => 'Documents',
            'max'     => 10,
            'points'  => round($total, 1),
            'details' => [
                'erp'             => $hasErp,
                'plan'            => $hasPlan,
                'est_copro'       => $estCopro,
                'dossier_copro'   => $hasDossierCopro,
                'sous_totaux' => [
                    'erp'   => round($ptsErp, 1),
                    'plan'  => round($ptsPlan, 1),
                    'copro' => round($ptsCopro, 1),
                ],
            ],
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Bloc 7 : FRAÎCHEUR (5 pts)
// ─────────────────────────────────────────────────────────────────────────
if (!function_exists('mbi_supports_score_bloc_fraicheur')) {
    function mbi_supports_score_bloc_fraicheur(array $bien): array
    {
        $dateModif = (string)($bien['date_modification'] ?? $bien['updated_at'] ?? '');
        $points = 0.0;
        $jours = null;
        if ($dateModif !== '') {
            try {
                $diff = (new DateTimeImmutable())->getTimestamp() - (new DateTimeImmutable($dateModif))->getTimestamp();
                $jours = (int)floor($diff / 86400);
                $points = match (true) {
                    $jours <= 7   => 5.0,
                    $jours <= 30  => 4.0,
                    $jours <= 60  => 2.5,
                    $jours <= 120 => 1.0,
                    default       => 0.0,
                };
            } catch (Throwable) {
                $points = 0.0;
            }
        }

        return [
            'libelle' => 'Fraîcheur',
            'max'     => 5,
            'points'  => round($points, 1),
            'details' => [
                'date_modification' => $dateModif ?: null,
                'jours_depuis_maj'  => $jours,
            ],
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Signaux (alertes complémentaires utilisées par l'IA pour l'angle marketing)
// ─────────────────────────────────────────────────────────────────────────
if (!function_exists('mbi_supports_score_signaux')) {
    function mbi_supports_score_signaux(array $bien, array $photos, array $breakdown): array
    {
        $signaux = [];
        if (($breakdown['photos']['details']['nb_exploitables'] ?? 0) < 5) $signaux[] = 'photos_peu_nombreuses';
        if (($breakdown['photos']['details']['nb_hero'] ?? 0) === 0)        $signaux[] = 'pas_de_photo_hero';
        if (($breakdown['description']['details']['longueur_chars'] ?? 0) < 200) $signaux[] = 'description_courte';
        $dpe = strtoupper(trim((string)($bien['dpe_classe'] ?? $bien['dpe'] ?? '')));
        if (in_array($dpe, ['E','F','G'], true)) $signaux[] = 'dpe_defavorable';
        if (($breakdown['fraicheur']['details']['jours_depuis_maj'] ?? 999) > 60) $signaux[] = 'fiche_pas_a_jour';
        return $signaux;
    }
}
