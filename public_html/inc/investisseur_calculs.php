<?php
declare(strict_types=1);

/**
 * inc/investisseur_calculs.php
 *
 * Moteur de calculs du module Analyse Investisseur.
 *
 * Principes :
 *   - Aucune fonction ne doit jeter si une donnée manque → retour neutre (0.0).
 *   - Les hypothèses prudentes par défaut sont DOCUMENTÉES à côté du cas absent.
 *   - Les fonctions sont indépendantes et recombinables.
 *   - Unité : les montants sont en €, les taux en % (pas fractions).
 */

if (!function_exists('inv_f')) {
    /** Cast sûr vers float, gère virgule FR. */
    function inv_f($v): float {
        if ($v === null || $v === '') return 0.0;
        return (float)str_replace([' ', ','], ['', '.'], (string)$v);
    }
}
if (!function_exists('inv_i')) {
    function inv_i($v): int { return (int)inv_f($v); }
}

if (!function_exists('calcul_cout_total')) {
    /**
     * Coût total d'acquisition = prix + notaire + agence + travaux + ameublement.
     * Si frais_notaire vide : hypothèse ancien 8% sur prix.
     */
    function calcul_cout_total(array $a): float {
        $prix      = inv_f($a['prix_achat'] ?? 0);
        $notaire   = inv_f($a['frais_notaire'] ?? 0);
        $agence    = inv_f($a['frais_agence'] ?? 0);
        $travaux   = inv_f($a['travaux'] ?? 0);
        $meubles   = inv_f($a['ameublement'] ?? 0);
        if ($notaire <= 0 && $prix > 0) $notaire = round($prix * 0.08, 2);
        return round($prix + $notaire + $agence + $travaux + $meubles, 2);
    }
}

if (!function_exists('calcul_montant_finance')) {
    /** Montant emprunté = coût total - apport. */
    function calcul_montant_finance(array $a): float {
        $total  = calcul_cout_total($a);
        $apport = inv_f($a['apport'] ?? 0);
        return max(0.0, round($total - $apport, 2));
    }
}

if (!function_exists('calcul_mensualite_credit')) {
    /**
     * Mensualité crédit — formule standard amortissable :
     *   M = K * (t * (1+t)^n) / ((1+t)^n - 1)  avec t = taux mensuel, n = mois
     * Hypothèse taux/durée absents : 3.5 % sur 20 ans (valeurs 2025 prudentes).
     */
    function calcul_mensualite_credit(array $a): float {
        $K = inv_f($a['montant_finance'] ?? 0);
        if ($K <= 0) $K = calcul_montant_finance($a);
        if ($K <= 0) return 0.0;
        $tauxAnnuel = inv_f($a['taux_credit'] ?? 0);
        if ($tauxAnnuel <= 0) $tauxAnnuel = 3.5; // hypothèse prudente
        $duree = (int)inv_f($a['duree_credit'] ?? 0);
        if ($duree <= 0) $duree = 20;
        $n = $duree * 12;
        $t = ($tauxAnnuel / 100) / 12;
        if ($t == 0) return round($K / $n, 2);
        $m = $K * ($t * pow(1 + $t, $n)) / (pow(1 + $t, $n) - 1);
        return round($m, 2);
    }
}

if (!function_exists('calcul_revenu_annuel')) {
    /** Revenus = loyer HC × 12 × (1 - vacance%). */
    function calcul_revenu_annuel(array $a): float {
        $loyer   = inv_f($a['loyer_estime'] ?? 0);
        if ($loyer <= 0) return 0.0;
        $vacancePct = inv_f($a['vacance_locative'] ?? 0);
        if ($vacancePct < 0) $vacancePct = 0;
        if ($vacancePct > 50) $vacancePct = 50;
        $brut = $loyer * 12;
        return round($brut * (1 - $vacancePct / 100), 2);
    }
}

if (!function_exists('calcul_charges_annuelles')) {
    /**
     * Charges déductibles annuelles :
     *   + charges non récupérables * 12
     *   + taxe foncière
     *   + PNO
     *   + gestion locative % * loyer annuel brut
     *   + entretien/imprévus % * loyer annuel brut
     * Note : les charges récupérables ne sont PAS déduites (payées par locataire).
     */
    function calcul_charges_annuelles(array $a): float {
        $cNonRec = inv_f($a['charges_non_recuperables'] ?? 0) * 12;
        $tf      = inv_f($a['taxe_fonciere'] ?? 0);
        $pno     = inv_f($a['assurance_pno'] ?? 0);
        $loyerBrut = inv_f($a['loyer_estime'] ?? 0) * 12;
        $gestPct = inv_f($a['gestion_locative'] ?? 0);
        $entPct  = inv_f($a['entretien_imprevus'] ?? 0);
        $gest = $loyerBrut * ($gestPct / 100);
        $ent  = $loyerBrut * ($entPct / 100);
        return round($cNonRec + $tf + $pno + $gest + $ent, 2);
    }
}

if (!function_exists('calcul_rendement_brut')) {
    /** Rendement brut = (loyer annuel brut / coût total) * 100. */
    function calcul_rendement_brut(array $a): float {
        $total = calcul_cout_total($a);
        if ($total <= 0) return 0.0;
        $loyerBrut = inv_f($a['loyer_estime'] ?? 0) * 12;
        return round($loyerBrut / $total * 100, 2);
    }
}

if (!function_exists('calcul_rendement_net')) {
    /** Rendement net = (revenus - charges) / coût total * 100. */
    function calcul_rendement_net(array $a): float {
        $total = calcul_cout_total($a);
        if ($total <= 0) return 0.0;
        $rev = calcul_revenu_annuel($a);
        $chg = calcul_charges_annuelles($a);
        return round(($rev - $chg) / $total * 100, 2);
    }
}

if (!function_exists('calcul_cashflow_mensuel')) {
    /** Cashflow mensuel = (revenus - charges - mensualités) / 12. */
    function calcul_cashflow_mensuel(array $a): float {
        $rev = calcul_revenu_annuel($a);
        $chg = calcul_charges_annuelles($a);
        $mens = calcul_mensualite_credit($a) * 12;
        return round(($rev - $chg - $mens) / 12, 2);
    }
}

if (!function_exists('calcul_effort_epargne')) {
    /** Effort d'épargne = cashflow mensuel (négatif = effort à fournir). */
    function calcul_effort_epargne(array $a): float {
        return calcul_cashflow_mensuel($a);
    }
}

if (!function_exists('calcul_projection_10_ans')) {
    /**
     * Projection cashflow cumulé 10 ans (sans revalorisation loyer — prudent).
     * Simple : cashflow mensuel × 120.
     */
    function calcul_projection_10_ans(array $a): float {
        return round(calcul_cashflow_mensuel($a) * 120, 2);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// SCORING — méthode explicite et lisible (0 à 100)
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('calcul_score_risque')) {
    /**
     * Score RISQUE sur 100 — 100 = très peu risqué / 0 = très risqué.
     * Composants (pondérations en commentaire) :
     *   - tension_locative   (1-5)  × 10 pts  max 50  (marché tendu = moins de vacance)
     *   - facilite_revente   (1-5)  ×  6 pts  max 30
     *   - niveau_risque      (1-5) inversé → (6 - n) × 4 pts  max 20
     *   - bonus cashflow positif     +10 pts plafonné
     *   - malus cashflow très négatif (< -300 €/mois) : -15 pts
     */
    function calcul_score_risque(array $a): int {
        $tens = min(5, max(0, (int)($a['tension_locative']   ?? 3)));
        $rev  = min(5, max(0, (int)($a['facilite_revente']   ?? 3)));
        $risq = min(5, max(1, (int)($a['niveau_risque']      ?? 3)));
        $cf   = calcul_cashflow_mensuel($a);

        $score = ($tens * 10) + ($rev * 6) + ((6 - $risq) * 4);
        if ($cf > 0)      $score += 10;
        if ($cf < -300)   $score -= 15;

        return (int)max(0, min(100, $score));
    }
}

if (!function_exists('calcul_score_attractivite')) {
    /**
     * Score ATTRACTIVITÉ sur 100 — combine rendement + emplacement + potentiel.
     *   - rendement_net (% × 8)         max 56  (7%+ = max)
     *   - qualite_emplacement (1-5 × 5) max 25
     *   - potentiel_valorisation (×4)   max 20
     *   - tension_locative (×2)         max 10
     *   (pondéré pour totaliser ~110 puis plafonné à 100)
     */
    function calcul_score_attractivite(array $a): int {
        $rn   = calcul_rendement_net($a);
        $empl = min(5, max(0, (int)($a['qualite_emplacement']    ?? 3)));
        $pot  = min(5, max(0, (int)($a['potentiel_valorisation'] ?? 3)));
        $tens = min(5, max(0, (int)($a['tension_locative']       ?? 3)));

        $score = ($rn * 8) + ($empl * 5) + ($pot * 4) + ($tens * 2);
        return (int)max(0, min(100, $score));
    }
}

if (!function_exists('calcul_score_global')) {
    /**
     * Score GLOBAL sur 100 — pondération claire :
     *   - 45 % attractivité (rentabilité + potentiel)
     *   - 35 % sécurité (risque faible)
     *   - 20 % lisibilité projet (stratégie définie + commentaire humain fourni)
     */
    function calcul_score_global(array $a): int {
        $attr   = calcul_score_attractivite($a);
        $risq   = calcul_score_risque($a);
        $lisib  = 0;
        if (!empty($a['strategie']))           $lisib += 50;
        $comm = trim((string)($a['commentaire_humain'] ?? ''));
        if (mb_strlen($comm) >= 80)            $lisib += 50;
        elseif (mb_strlen($comm) >= 20)        $lisib += 25;

        $score = ($attr * 0.45) + ($risq * 0.35) + ($lisib * 0.20);
        return (int)round(max(0, min(100, $score)));
    }
}

if (!function_exists('calcul_prix_m2')) {
    /** Prix au m² = prix d'achat / surface. 0 si surface absente. */
    function calcul_prix_m2(array $a): float {
        $prix = inv_f($a['prix_achat'] ?? 0);
        $surf = inv_f($a['surface'] ?? 0);
        if ($prix <= 0 || $surf <= 0) return 0.0;
        return round($prix / $surf, 2);
    }
}

if (!function_exists('calcul_multiple_loyer')) {
    /**
     * Multiple loyer = prix d'achat / loyer annuel brut.
     * Indicateur standard en immobilier commercial.
     * Ex : 15 = on récupère la mise en 15 ans de loyers.
     */
    function calcul_multiple_loyer(array $a): float {
        $prix   = inv_f($a['prix_achat'] ?? 0);
        $loyerM = inv_f($a['loyer_estime'] ?? 0);
        if ($prix <= 0 || $loyerM <= 0) return 0.0;
        $loyerAn = $loyerM * 12;
        return round($prix / $loyerAn, 2);
    }
}

if (!function_exists('inv_compute_all')) {
    /**
     * Recalcule tous les indicateurs et retourne un array [clé => valeur]
     * exploitable pour affichage + stockage DB.
     */
    function inv_compute_all(array $a): array {
        $cout        = calcul_cout_total($a);
        $finance     = calcul_montant_finance($a);
        $mens        = calcul_mensualite_credit($a);
        $revAnn      = calcul_revenu_annuel($a);
        $chgAnn      = calcul_charges_annuelles($a);
        $rdBrut      = calcul_rendement_brut($a);
        $rdNet       = calcul_rendement_net($a);
        $cf          = calcul_cashflow_mensuel($a);
        $proj        = calcul_projection_10_ans($a);
        $sR          = calcul_score_risque($a);
        $sA          = calcul_score_attractivite($a);
        $sG          = calcul_score_global($a);
        $pm2         = calcul_prix_m2($a);
        $mult        = calcul_multiple_loyer($a);
        return [
            'cout_total'         => $cout,
            'montant_finance'    => $finance,
            'mensualite_credit'  => $mens,
            'revenu_annuel'      => $revAnn,
            'charges_annuelles'  => $chgAnn,
            'rendement_brut'     => $rdBrut,
            'rendement_net'      => $rdNet,
            'cashflow_mensuel'   => $cf,
            'effort_epargne'     => $cf,
            'projection_10_ans'  => $proj,
            'score_risque'       => $sR,
            'score_attractivite' => $sA,
            'score_global'       => $sG,
            'prix_m2'            => $pm2,
            'multiple_loyer'     => $mult,
        ];
    }
}
