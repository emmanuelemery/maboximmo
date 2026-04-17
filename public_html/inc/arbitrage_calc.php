<?php
declare(strict_types=1);

/**
 * Calculs arbitrage (v1)
 * - Conçus pour être rapides, déterministes et explicables (mode client / expert).
 * - Les montants sont en euros.
 */

function arb_f(mixed $v): float
{
    if ($v === null) return 0.0;
    if (is_int($v) || is_float($v)) return (float)$v;
    $s = trim((string)$v);
    if ($s === '') return 0.0;
    // Autoriser virgule française
    $s = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $s);
    return is_numeric($s) ? (float)$s : 0.0;
}

function arb_i(mixed $v): int
{
    if ($v === null) return 0;
    if (is_int($v)) return $v;
    $s = trim((string)$v);
    return ctype_digit($s) ? (int)$s : 0;
}

function arb_pct(float $ratio): float
{
    return round($ratio * 100.0, 2);
}

function arb_safe_div(float $num, float $den): float
{
    if (abs($den) < 1e-9) return 0.0;
    return $num / $den;
}

function arb_months_between(?string $startDate, ?string $endDate = null): int
{
    if (!$startDate) return 0;
    $start = date_create($startDate);
    if (!$start) return 0;
    $end = $endDate ? date_create($endDate) : new DateTimeImmutable('now');
    if (!$end) return 0;
    $diff = $start->diff($end);
    $months = ((int)$diff->y * 12) + (int)$diff->m;
    // Si on est au-delà de 15 jours → arrondir au mois supérieur
    if ((int)$diff->d >= 15) $months++;
    return max(0, $months);
}

function arb_compute_costs_annual(array $arb): float
{
    return
        arb_f($arb['taxe_fonciere'] ?? null) +
        arb_f($arb['charges_non_recup'] ?? null) +
        arb_f($arb['assurance'] ?? null) +
        arb_f($arb['entretien'] ?? null) +
        arb_f($arb['frais_gestion'] ?? null) +
        arb_f($arb['autres_couts_annuels'] ?? null);
}

function arb_compute_travaux_total(array $arb): float
{
    $t1 = arb_f($arb['travaux_1an'] ?? null);
    $t3 = arb_f($arb['travaux_3ans'] ?? null);
    $t5 = arb_f($arb['travaux_5ans'] ?? null);
    return max($t1, $t3, $t5);
}

function arb_pick_price_realiste(array $arb, array $bien): float
{
    $p = arb_f($arb['prix_vente_realiste'] ?? null);
    if ($p > 0) return $p;

    $p = arb_f($arb['prix_estime'] ?? null);
    if ($p > 0) return $p;

    $p = arb_f($bien['prix_vente_estime'] ?? null);
    return $p;
}

function arb_pick_loyer_actuel_mensuel(array $arb, array $bien, array $crg): float
{
    $v = arb_f($arb['loyer_actuel_mensuel'] ?? null);
    if ($v > 0) return $v;

    // CRG : loyer appelé (mensuel) du dernier trimestre
    $v = arb_f($crg['loyer_appele_mensuel'] ?? null);
    if ($v > 0) return $v;

    // Bien (saisie/estimation)
    $v = arb_f($bien['loyer_hc'] ?? null);
    return $v;
}

function arb_pick_loyer_potentiel_mensuel(array $arb, array $bien, array $crg): float
{
    $v = arb_f($arb['loyer_potentiel_mensuel'] ?? null);
    if ($v > 0) return $v;

    // Fallback : au moins le loyer actuel
    return arb_pick_loyer_actuel_mensuel($arb, $bien, $crg);
}

function arb_score_strategique(array $metrics): int
{
    $rendement = (float)($metrics['rendement_reel_pct'] ?? 0.0);
    $liquidite = (int)($metrics['liquidite_niveau'] ?? 0);
    $risque    = (int)($metrics['risque_niveau'] ?? 0);
    $travaux   = (float)($metrics['travaux_total'] ?? 0.0);
    $prix      = (float)($metrics['prix_vente_realiste'] ?? 0.0);

    // Rendement : 0..10% → 0..45 pts (cap 12%)
    $rendPts = (int)round(min(12.0, max(0.0, $rendement)) / 12.0 * 45.0);
    // Liquidité : 1..5 → 5..25 pts
    $liqPts  = $liquidite > 0 ? (int)round(($liquidite / 5.0) * 25.0) : 10;
    // Risque : 1..5 → -0..-20 pts (plus c'est risqué, plus on pénalise)
    $riskPts = $risque > 0 ? (int)round((($risque - 1) / 4.0) * 20.0) : 6;
    // Travaux : pénalité relative (si travaux > 4% du prix réaliste → -10 à -20)
    $travRatio = ($prix > 0) ? ($travaux / $prix) : 0.0;
    $travPts   = (int)round(min(1.0, $travRatio / 0.08) * 20.0);

    $score = $rendPts + $liqPts - $riskPts - $travPts;
    return max(0, min(100, $score));
}

/**
 * Calcule toutes les métriques affichées à droite.
 *
 * @return array{
 *   prix_vente_realiste: float,
 *   cout_acte_en_main: float,
 *   cout_annuel: float,
 *   cout_mensuel: float,
 *   loyer_actuel_mensuel: float,
 *   loyer_potentiel_mensuel: float,
 *   rendement_brut_pct: float,
 *   rendement_net_pct: float,
 *   rendement_reel_pct: float,
 *   resultat_annuel: float,
 *   manque_a_gagner_mensuel: float,
 *   cout_supporte_vide_mensuel: float,
 *   cout_du_vide_mensuel: float,
 *   cout_cumule_vacance: float,
 *   vacance_mois: int,
 *   travaux_total: float,
 *   perte_immediate_decote: float,
 *   delai_vente_mois: int,
 *   liquidite_niveau: int,
 *   risque_niveau: int,
 *   score_strategique: int,
 *   projection_18m: array<string,mixed>
 * }
 */
function arb_compute_metrics(array $bien, array $arb, array $crg, array $settings): array
{
    $prixVenteRealiste = arb_pick_price_realiste($arb, $bien);
    $fraisAgence       = arb_f($arb['frais_agence'] ?? null);
    $fraisNotaire      = arb_f($arb['frais_notaire'] ?? null);
    $coutActeEnMain    = arb_f($arb['cout_acte_en_main'] ?? null);
    if ($coutActeEnMain <= 0) {
        // Approche pragmatique : coût acte en main = prix + notaire + agence (si renseignés)
        $coutActeEnMain = $prixVenteRealiste + $fraisAgence + $fraisNotaire;
    }

    $coutAnnuel = arb_compute_costs_annual($arb);
    $coutMensuel = $coutAnnuel / 12.0;

    $loyerActuelMensuel    = arb_pick_loyer_actuel_mensuel($arb, $bien, $crg);
    $loyerPotentielMensuel = arb_pick_loyer_potentiel_mensuel($arb, $bien, $crg);

    $loyerAnnuelActuel     = $loyerActuelMensuel * 12.0;
    $loyerAnnuelPotentiel  = $loyerPotentielMensuel * 12.0;

    $travauxTotal = arb_compute_travaux_total($arb);

    $statutLocatif = (string)($arb['statut_locatif'] ?? '');
    $isVide = ($statutLocatif === 'vide');
    $manqueAGagnerMensuel = max(0.0, $loyerPotentielMensuel - ($isVide ? 0.0 : $loyerActuelMensuel));
    $coutSupporteVideMensuel = $coutMensuel;
    $coutDuVideMensuel = $coutSupporteVideMensuel + $manqueAGagnerMensuel;

    $vacanceMois = arb_i($arb['vacance_mois'] ?? null);
    if ($vacanceMois <= 0) {
        $vacanceMois = arb_months_between($arb['vacance_debut'] ?? null);
    }
    $coutCumuleVacance = $vacanceMois * $coutDuVideMensuel;

    $prixEstime = arb_f($arb['prix_estime'] ?? $bien['prix_vente_estime'] ?? null);
    $perteImmediateDecote = 0.0;
    if ($prixEstime > 0 && $prixVenteRealiste > 0) {
        $perteImmediateDecote = max(0.0, $prixEstime - $prixVenteRealiste);
    }

    // Rendements
    $rendementBrut = arb_safe_div($loyerAnnuelPotentiel, $prixVenteRealiste);
    $rendementNet  = arb_safe_div(($loyerAnnuelPotentiel - $coutAnnuel), $prixVenteRealiste);
    // Rendement réel "acte en main" : base = coût acte en main, et loyer actuel si occupé, sinon potentiel moins coût du vide
    $resultatAnnuel = ($isVide ? 0.0 : $loyerAnnuelActuel) - $coutAnnuel;
    $rendementReel = arb_safe_div($resultatAnnuel, ($coutActeEnMain > 0 ? $coutActeEnMain : $prixVenteRealiste));

    $delaiVenteMois = arb_i($arb['delai_vente_mois'] ?? null);
    if ($delaiVenteMois <= 0) $delaiVenteMois = 6;

    $liquiditeNiveau = arb_i($arb['liquidite_niveau'] ?? null);
    if ($liquiditeNiveau <= 0) $liquiditeNiveau = 3;

    $risqueNiveau = arb_i($arb['risque_niveau'] ?? null);
    if ($risqueNiveau <= 0) {
        // Heuristique v1 : impayés + travaux lourds + vacance longue
        $baseRisk = 2;
        if (!empty($crg['impaye_latest']) && (float)$crg['impaye_latest'] > 0) $baseRisk++;
        if (($arb['travaux_niveau'] ?? '') === 'lourds') $baseRisk += 2;
        if ($vacanceMois >= 9) $baseRisk++;
        $risqueNiveau = max(1, min(5, $baseRisk));
    }

    $projection = arb_projection_18m([
        'prix_vente_realiste' => $prixVenteRealiste,
        'frais_agence' => $fraisAgence,
        'frais_notaire' => $fraisNotaire,
        'cout_mensuel' => $coutMensuel,
        'loyer_mensuel' => ($isVide ? 0.0 : $loyerActuelMensuel),
        'travaux_1an' => arb_f($arb['travaux_1an'] ?? null),
        'delai_vente_mois' => $delaiVenteMois,
        'horizon_mois' => (int)($settings['horizon_mois'] ?? 18),
        'rendement_reinvest_cible_pct' => arb_f($settings['rendement_reinvest_cible_pct'] ?? 6.0),
    ]);

    $metrics = [
        'prix_vente_realiste' => $prixVenteRealiste,
        'cout_acte_en_main' => $coutActeEnMain,
        'cout_annuel' => $coutAnnuel,
        'cout_mensuel' => $coutMensuel,
        'loyer_actuel_mensuel' => $loyerActuelMensuel,
        'loyer_potentiel_mensuel' => $loyerPotentielMensuel,
        'rendement_brut_pct' => arb_pct($rendementBrut),
        'rendement_net_pct' => arb_pct($rendementNet),
        'rendement_reel_pct' => arb_pct($rendementReel),
        'resultat_annuel' => $resultatAnnuel,
        'manque_a_gagner_mensuel' => $manqueAGagnerMensuel,
        'cout_supporte_vide_mensuel' => $coutSupporteVideMensuel,
        'cout_du_vide_mensuel' => $coutDuVideMensuel,
        'cout_cumule_vacance' => $coutCumuleVacance,
        'vacance_mois' => $vacanceMois,
        'travaux_total' => $travauxTotal,
        'perte_immediate_decote' => $perteImmediateDecote,
        'delai_vente_mois' => $delaiVenteMois,
        'liquidite_niveau' => $liquiditeNiveau,
        'risque_niveau' => $risqueNiveau,
        'projection_18m' => $projection,
    ];

    $metrics['score_strategique'] = arb_score_strategique($metrics);
    return $metrics;
}

function arb_projection_18m(array $in): array
{
    $horizon = (int)($in['horizon_mois'] ?? 18);
    if ($horizon <= 0) $horizon = 18;

    $prix = (float)($in['prix_vente_realiste'] ?? 0.0);
    $fraisAgence = (float)($in['frais_agence'] ?? 0.0);
    $fraisNotaire = (float)($in['frais_notaire'] ?? 0.0);
    $cashVente = max(0.0, $prix - $fraisAgence - $fraisNotaire);

    $coutMensuel = (float)($in['cout_mensuel'] ?? 0.0);
    $loyerMensuel = (float)($in['loyer_mensuel'] ?? 0.0);
    $travaux1an = (float)($in['travaux_1an'] ?? 0.0);

    $delaiVente = (int)($in['delai_vente_mois'] ?? 6);
    if ($delaiVente < 1) $delaiVente = 1;
    if ($delaiVente > $horizon) $delaiVente = $horizon;

    // Conserver
    $revenusConserver = $loyerMensuel * $horizon;
    $coutsConserver   = $coutMensuel * $horizon;
    // Travaux sur 18 mois : on prend travaux 1 an en entier (approche prudente)
    $travauxConserver = $travaux1an;
    $resultatConserver = $revenusConserver - $coutsConserver - $travauxConserver;

    // Vendre : revenus/couts pendant le délai de vente uniquement
    $revenusAvantVente = $loyerMensuel * $delaiVente;
    $coutsAvantVente   = $coutMensuel * $delaiVente;
    $travauxAvantVente = 0.0;
    // Si vente < 12 mois : on considère les travaux 1 an évités, sinon réalisés
    if ($delaiVente >= 12) {
        $travauxAvantVente = $travaux1an;
    }

    $revenusPerdus = $loyerMensuel * max(0, $horizon - $delaiVente);
    $coutsEvites   = $coutMensuel * max(0, $horizon - $delaiVente);
    $travauxEvites = ($delaiVente < 12) ? $travaux1an : 0.0;

    $impactVendre = $cashVente + $coutsEvites + $travauxEvites - $revenusPerdus;
    $resultatVendreSurHorizon = ($cashVente + $revenusAvantVente) - $coutsAvantVente - $travauxAvantVente;

    // Vendre + réinvestir
    $rendementCiblePct = (float)($in['rendement_reinvest_cible_pct'] ?? 6.0);
    $rendementCible = max(0.0, $rendementCiblePct / 100.0);
    $revenusReinvest = $cashVente * $rendementCible * ($horizon / 12.0);
    $impactVendreReinvest = $impactVendre + $revenusReinvest;

    return [
        'horizon_mois' => $horizon,
        'conserver' => [
            'revenus' => $revenusConserver,
            'couts' => $coutsConserver,
            'travaux' => $travauxConserver,
            'resultat_net' => $resultatConserver,
        ],
        'vendre' => [
            'cash' => $cashVente,
            'delai_vente_mois' => $delaiVente,
            'revenus_perdus' => $revenusPerdus,
            'couts_evites' => $coutsEvites,
            'travaux_evites' => $travauxEvites,
            'impact_tresorerie' => $impactVendre,
            'resultat_sur_horizon' => $resultatVendreSurHorizon,
        ],
        'vendre_reinvestir' => [
            'cash' => $cashVente,
            'rendement_cible_pct' => $rendementCiblePct,
            'revenus_reinvest' => $revenusReinvest,
            'impact_tresorerie' => $impactVendreReinvest,
        ],
    ];
}
