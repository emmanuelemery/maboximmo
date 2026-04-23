<?php
declare(strict_types=1);

/**
 * inc/investisseur_projection.php
 *
 * Moteur de projection multi-années + arbitrage "vendre maintenant vs garder N ans".
 *
 * Principes de calcul (tous en € / an, cashflow "brut" avant impôt sauf si taux_imposition_pct renseigné) :
 *   - Année N : loyer_brut = loyer_0 × (1 + index_loyer)^N
 *   - Charges annuelles idem indexées (2 % par défaut si pas renseigné)
 *   - Mensualités crédit payées si durée restante > 0 cette année-là
 *   - Amortissement capital calculé à partir du CRD + mensualité + taux
 *   - Valeur du bien = prix_vente × (1 + revalo)^N
 *   - Vente future : cash_net = valeur − honoraires − CRD_futur − IRA_futur
 *
 * Les calculs se basent sur `prix_achat` (négocié) pour les rendements, mais
 * utilisent `prix_vente_catalogue` (ou prix_achat si non renseigné) comme base
 * de revalorisation pour estimer le prix de revente futur — c'est le prix qu'on
 * espère encaisser à la revente.
 */

require_once __DIR__ . '/investisseur_calculs.php';

if (!function_exists('inv_proj_mensualite_from_crd')) {
    /**
     * Calcule la mensualité théorique d'un crédit à partir de CRD + taux + durée.
     * Formule amortissable standard.
     */
    function inv_proj_mensualite_from_crd(float $crd, float $tauxAnnuelPct, int $dureeMois): float {
        if ($crd <= 0 || $dureeMois <= 0) return 0.0;
        $t = ($tauxAnnuelPct / 100) / 12;
        if ($t == 0) return round($crd / $dureeMois, 2);
        $m = $crd * ($t * pow(1 + $t, $dureeMois)) / (pow(1 + $t, $dureeMois) - 1);
        return round($m, 2);
    }
}

if (!function_exists('inv_proj_crd_apres_n_mois')) {
    /**
     * Calcule le CRD après N mois de remboursement, à partir du CRD initial,
     * de la mensualité et du taux annuel.
     */
    function inv_proj_crd_apres_n_mois(float $crd0, float $mensualite, float $tauxAnnuelPct, int $nMois): float {
        if ($crd0 <= 0) return 0.0;
        if ($mensualite <= 0 || $nMois <= 0) return $crd0;
        $t = ($tauxAnnuelPct / 100) / 12;
        $crd = $crd0;
        for ($i = 0; $i < $nMois; $i++) {
            $interets = $crd * $t;
            $capital  = $mensualite - $interets;
            if ($capital <= 0) break;   // mensualité insuffisante
            $crd = max(0.0, $crd - $capital);
            if ($crd <= 0) return 0.0;
        }
        return round($crd, 2);
    }
}

if (!function_exists('inv_proj_interets_payes')) {
    /**
     * Somme des intérêts payés sur N mois à partir du CRD + mensualité + taux.
     */
    function inv_proj_interets_payes(float $crd0, float $mensualite, float $tauxAnnuelPct, int $nMois): float {
        if ($crd0 <= 0 || $mensualite <= 0 || $nMois <= 0) return 0.0;
        $t = ($tauxAnnuelPct / 100) / 12;
        $crd = $crd0;
        $tot = 0.0;
        for ($i = 0; $i < $nMois; $i++) {
            $interets = $crd * $t;
            $capital  = $mensualite - $interets;
            if ($capital <= 0) break;
            $tot += $interets;
            $crd -= $capital;
            if ($crd <= 0) break;
        }
        return round($tot, 2);
    }
}

if (!function_exists('inv_proj_years')) {
    /**
     * Retourne la projection année par année sur $nAnnees.
     *
     * Entrée $a : ligne d'analyse (tableau)
     * Retour : array de [
     *   'annee' => 1..N,
     *   'loyer_annuel', 'charges_annuelles', 'mensualites_an', 'interets_an', 'capital_rembourse_an',
     *   'cashflow_annuel_brut', 'cashflow_annuel_net' (après impôt si configuré),
     *   'cashflow_cumule',
     *   'crd_fin_annee',
     *   'valeur_bien_fin_annee'
     * ]
     */
    function inv_proj_years(array $a, int $nAnnees = 10): array {
        $nAnnees = max(1, min(30, $nAnnees));

        $loyer0        = inv_f($a['loyer_estime'] ?? 0) * 12;
        $charges0      = (function_exists('calcul_charges_annuelles') ? calcul_charges_annuelles($a) : 0.0);
        $indexLoyer    = (float)($a['indexation_loyer_pct_an']    ?? 1.0);
        $revaloBien    = (float)($a['revalorisation_bien_pct_an'] ?? 1.5);
        $tauxImposPct  = $a['taux_imposition_pct'] ?? null;
        $tauxImposPct  = ($tauxImposPct === null || $tauxImposPct === '') ? null : (float)$tauxImposPct;

        $prixBase      = inv_f($a['prix_vente_catalogue'] ?? 0) ?: inv_f($a['prix_achat'] ?? 0);

        // Crédit : si credit_crd > 0, on utilise le crédit en cours; sinon on simule celui calculé par calcul_mensualite_credit
        $crd0 = inv_f($a['credit_crd'] ?? 0);
        $creditActif = $crd0 > 0;
        if ($creditActif) {
            $tauxCredit   = (float)($a['taux_credit'] ?? 3.5);
            $dureeRestMois = (int)($a['credit_duree_restante_mois'] ?? 0);
            $mensualite   = inv_f($a['mensualite_credit'] ?? 0);
            if ($mensualite <= 0) $mensualite = inv_proj_mensualite_from_crd($crd0, $tauxCredit, $dureeRestMois);
        } else {
            // Pas de crédit en cours : on ne compte pas les mensualités dans la projection
            // (on considère que le bien est déjà acquis sans crédit pour l'arbitrage)
            $tauxCredit = 0;
            $dureeRestMois = 0;
            $mensualite = 0;
        }

        // Travaux à charge du bailleur si conservation : ponctuel en année 1
        $travauxBailleur = inv_f($a['travaux_bailleur'] ?? 0);

        $years = [];
        $cumule = 0.0;
        $crd = $crd0;
        for ($y = 1; $y <= $nAnnees; $y++) {
            $loyerAn  = round($loyer0   * pow(1 + $indexLoyer    / 100, $y - 1), 2);
            $chargesAn = round($charges0 * pow(1 + $indexLoyer   / 100, $y - 1), 2);
            // Charges supplémentaires année 1 : travaux bailleur ponctuels
            $travauxAn1 = ($y === 1) ? $travauxBailleur : 0.0;

            $moisCetteAnnee = $mensualite > 0 ? min(12, max(0, $dureeRestMois - 12 * ($y - 1))) : 0;
            $mensualitesAn = round($mensualite * $moisCetteAnnee, 2);

            // Décomposition intérêts/capital pour l'année
            $interetsAn = 0.0;
            $capitalRemb = 0.0;
            if ($moisCetteAnnee > 0 && $crd > 0) {
                $interetsAn = inv_proj_interets_payes($crd, $mensualite, $tauxCredit, $moisCetteAnnee);
                $capitalRemb = round($mensualitesAn - $interetsAn, 2);
                $crd = max(0.0, round($crd - $capitalRemb, 2));
            }

            $cashflowBrut = round($loyerAn - $chargesAn - $mensualitesAn - $travauxAn1, 2);
            $cashflowNet  = $cashflowBrut;
            if ($tauxImposPct !== null) {
                // Base imposable ≈ loyer - charges - intérêts - travaux (déductibles)
                $baseImpos = max(0.0, $loyerAn - $chargesAn - $interetsAn - $travauxAn1);
                $impot = round($baseImpos * ($tauxImposPct / 100), 2);
                $cashflowNet = round($cashflowBrut - $impot, 2);
            }

            $cumule += $cashflowNet;
            $valeur = round($prixBase * pow(1 + $revaloBien / 100, $y), 2);

            $years[] = [
                'annee'                 => $y,
                'loyer_annuel'          => $loyerAn,
                'charges_annuelles'     => $chargesAn,
                'mensualites_an'        => $mensualitesAn,
                'interets_an'           => $interetsAn,
                'capital_rembourse_an'  => $capitalRemb,
                'cashflow_annuel_brut'  => $cashflowBrut,
                'cashflow_annuel_net'   => $cashflowNet,
                'cashflow_cumule'       => round($cumule, 2),
                'crd_fin_annee'         => $crd,
                'valeur_bien_fin_annee' => $valeur,
            ];
        }
        return $years;
    }
}

if (!function_exists('inv_proj_arbitrage')) {
    /**
     * Compare le cash net encaissé si on vend aujourd'hui vs si on garde N années
     * puis on vend. Retourne un rapport comparatif.
     *
     * $horizons : array d'années à tester (ex: [5, 10])
     */
    function inv_proj_arbitrage(array $a, array $horizons = [5, 10]): array {
        $prixVente   = inv_f($a['prix_vente_catalogue'] ?? 0) ?: inv_f($a['prix_achat'] ?? 0);
        $honoVentePct = 0.0;
        $honoVenteFixe = inv_f($a['honoraires_vente'] ?? 0);
        if ($prixVente > 0 && $honoVenteFixe > 0) {
            $honoVentePct = ($honoVenteFixe / $prixVente) * 100;
        }
        if ($honoVentePct <= 0) $honoVentePct = 4.0;  // défaut 4 %

        $crd0    = inv_f($a['credit_crd'] ?? 0);
        $iraPct  = (float)($a['ira_pct'] ?? 3.0);
        $revalo  = (float)($a['revalorisation_bien_pct_an'] ?? 1.5);

        // ─── Scénario VENTE IMMÉDIATE ──────────────────────────────────
        $honoraires = round($prixVente * $honoVentePct / 100, 2);
        $ira        = round($crd0 * $iraPct / 100, 2);
        $cashNow    = round($prixVente - $honoraires - $crd0 - $ira, 2);

        $scenarios = [
            'vendre_now' => [
                'label'        => 'Vendre aujourd\'hui',
                'prix_vente'   => $prixVente,
                'honoraires'   => $honoraires,
                'crd_rembourse'=> $crd0,
                'ira'          => $ira,
                'cash_net'     => $cashNow,
                'detail'       => [
                    'Prix de vente'   => $prixVente,
                    'Honoraires vente' => -$honoraires,
                    'Remb. crédit (CRD)' => -$crd0,
                    'IRA (' . number_format($iraPct, 1, ',', ' ') . ' %)' => -$ira,
                ],
            ],
        ];

        // ─── Scénarios GARDER N ANS ────────────────────────────────────
        foreach ($horizons as $n) {
            $years = inv_proj_years($a, $n);
            $cashflowCumule = $years[$n - 1]['cashflow_cumule'] ?? 0.0;
            $valeurFuture   = $years[$n - 1]['valeur_bien_fin_annee'] ?? $prixVente;
            $crdFutur       = $years[$n - 1]['crd_fin_annee'] ?? 0.0;
            $honoFutur      = round($valeurFuture * $honoVentePct / 100, 2);
            $iraFutur       = round($crdFutur * $iraPct / 100, 2);
            $cashFutur      = round($valeurFuture - $honoFutur - $crdFutur - $iraFutur, 2);
            $cashTotal      = round($cashflowCumule + $cashFutur, 2);

            $scenarios['garder_' . $n] = [
                'label'             => "Garder $n an" . ($n > 1 ? 's' : '') . ' puis vendre',
                'cashflow_cumule'   => $cashflowCumule,
                'valeur_future'     => $valeurFuture,
                'honoraires_futurs' => $honoFutur,
                'crd_futur'         => $crdFutur,
                'ira_futur'         => $iraFutur,
                'cash_vente_future' => $cashFutur,
                'cash_net'          => $cashTotal,
                'years'             => $years,
                'detail'            => [
                    'Cashflow cumulé ' . $n . ' ans' => $cashflowCumule,
                    'Valeur du bien dans ' . $n . ' ans' => $valeurFuture,
                    'Honoraires vente futurs' => -$honoFutur,
                    'Remb. crédit (CRD futur)' => -$crdFutur,
                    'IRA futur' => -$iraFutur,
                ],
            ];
        }

        // ─── Recommandation : meilleur cash net ────────────────────────
        $best = 'vendre_now';
        $bestVal = $cashNow;
        foreach ($scenarios as $k => $s) {
            if ($s['cash_net'] > $bestVal) { $bestVal = $s['cash_net']; $best = $k; }
        }

        return [
            'scenarios'  => $scenarios,
            'meilleur'   => $best,
            'conseils'   => inv_proj_conseil($scenarios, $best, $a),
            'hono_vente_pct' => $honoVentePct,
            'ira_pct'   => $iraPct,
            'revalo_pct'=> $revalo,
        ];
    }
}

if (!function_exists('inv_proj_conseil')) {
    function inv_proj_conseil(array $scenarios, string $best, array $a): string {
        $labels = [
            'vendre_now' => 'vendre immédiatement',
            'garder_5'   => 'conserver 5 ans puis vendre',
            'garder_10'  => 'conserver 10 ans puis vendre',
        ];
        $lbl = $labels[$best] ?? $best;
        $nowVal = $scenarios['vendre_now']['cash_net'] ?? 0;
        $bestVal = $scenarios[$best]['cash_net'] ?? 0;
        $delta = $bestVal - $nowVal;

        if ($best === 'vendre_now') {
            return "Meilleure option : " . $lbl . ". Le cash net immédiat est supérieur aux scénarios de conservation — vente recommandée.";
        }
        return "Meilleure option : " . $lbl . ". Gain net supplémentaire estimé vs vente immédiate : " . number_format($delta, 0, ',', ' ') . " €. À pondérer avec votre besoin de liquidité et le risque marché.";
    }
}
