<?php
declare(strict_types=1);

/**
 * inc/investisseur_interpretations.php
 *
 * Moteur d'interprétation texte du module Analyse Investisseur.
 *
 * Règles :
 *   - Aucune IA externe : on produit du texte déterministe, lisible,
 *     métier, fondé sur les indicateurs calculés + les champs humains.
 *   - Trois profils d'argumentaire (prudent / équilibré / offensif) :
 *     même fond factuel, ton différent.
 *   - Le "commentaire_humain" (ressenti terrain) est cité tel quel
 *     avec guillemets pour conserver l'authenticité du conseiller.
 */

require_once __DIR__ . '/investisseur_calculs.php';

if (!function_exists('inv_fmt_eur')) {
    function inv_fmt_eur(float $v): string {
        return number_format($v, 0, ',', ' ') . ' €';
    }
}
if (!function_exists('inv_fmt_pct')) {
    function inv_fmt_pct(float $v): string {
        return number_format($v, 2, ',', ' ') . ' %';
    }
}
if (!function_exists('inv_fmt_note')) {
    function inv_fmt_note(int $n): string {
        $n = max(0, min(5, $n));
        return str_repeat('●', $n) . str_repeat('○', 5 - $n);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// SYNTHÈSE — résumé cadré, 4-6 phrases
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('generer_synthese_analyse')) {
    function generer_synthese_analyse(array $a, array $calc): string {
        $ville  = trim((string)($a['ville'] ?? '')) ?: 'la zone étudiée';
        $type   = trim((string)($a['type_bien'] ?? '')) ?: 'bien';
        $surf   = inv_f($a['surface'] ?? 0);
        $px     = inv_f($a['prix_achat'] ?? 0);
        $rdN    = (float)($calc['rendement_net'] ?? 0);
        $cf     = (float)($calc['cashflow_mensuel'] ?? 0);
        $sg     = (int)($calc['score_global'] ?? 0);
        $strat  = trim((string)($a['strategie'] ?? '')) ?: 'non précisée';

        $phrase1 = "Il s'agit d'un {$type}" . ($surf > 0 ? " de " . number_format($surf, 0, ',', ' ') . " m²" : '')
                 . " situé à {$ville}, proposé à " . inv_fmt_eur($px) . ".";

        $phrase2 = "Le rendement net estimé ressort à " . inv_fmt_pct($rdN)
                 . " avec un cashflow mensuel de " . inv_fmt_eur($cf) . ".";

        $appr = match (true) {
            $sg >= 75 => "un dossier solide, équilibré entre rentabilité et sécurité",
            $sg >= 55 => "un dossier correct, à valider selon la stratégie de l'investisseur",
            $sg >= 35 => "un dossier moyen, nécessitant une vigilance particulière",
            default   => "un dossier fragile, à ne pas engager sans arbitrages",
        };
        $phrase3 = "La notation globale atteint {$sg}/100, ce qui traduit {$appr}.";

        $phrase4 = "La stratégie retenue est « {$strat} ».";

        return trim("$phrase1 $phrase2 $phrase3 $phrase4");
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// FORCES / FAIBLESSES / RISQUES / OPPORTUNITÉS — listes à puces
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('generer_forces')) {
    function generer_forces(array $a, array $calc): string {
        $out = [];
        $rn = (float)($calc['rendement_net'] ?? 0);
        if ($rn >= 6)  $out[] = "Rendement net attractif (" . inv_fmt_pct($rn) . ")";
        if ((float)($calc['cashflow_mensuel'] ?? 0) > 0)
            $out[] = "Cashflow mensuel positif dès la première année";
        if ((int)($a['tension_locative'] ?? 0) >= 4)
            $out[] = "Zone sous tension locative — vacance réduite";
        if ((int)($a['qualite_emplacement'] ?? 0) >= 4)
            $out[] = "Emplacement de qualité — atout durable";
        if ((int)($a['potentiel_valorisation'] ?? 0) >= 4)
            $out[] = "Potentiel de valorisation identifié";
        if ((int)($a['facilite_revente'] ?? 0) >= 4)
            $out[] = "Bien facile à revendre — liquidité préservée";
        if (!empty($a['garage']) || !empty($a['parking']))
            $out[] = "Stationnement privatif (valeur ajoutée locative)";
        if (!empty($a['exterieur']))
            $out[] = "Extérieur : " . $a['exterieur'] . " (forte demande en location)";
        if (in_array(strtoupper((string)($a['dpe'] ?? '')), ['A','B','C'], true))
            $out[] = "DPE " . strtoupper((string)$a['dpe']) . " — conforme aux évolutions réglementaires";

        if (empty($out)) $out[] = "Aucun point fort saillant n'a été identifié sur la saisie actuelle.";
        return "• " . implode("\n• ", $out);
    }
}

if (!function_exists('generer_faiblesses')) {
    function generer_faiblesses(array $a, array $calc): string {
        $out = [];
        $rn = (float)($calc['rendement_net'] ?? 0);
        if ($rn < 4 && $rn > 0)
            $out[] = "Rendement net modéré (" . inv_fmt_pct($rn) . ") — effort de long terme";
        if ((float)($calc['cashflow_mensuel'] ?? 0) < -100)
            $out[] = "Cashflow négatif — effort d'épargne à prévoir (" . inv_fmt_eur((float)$calc['cashflow_mensuel']) . "/mois)";
        if ((int)($a['qualite_emplacement'] ?? 0) <= 2)
            $out[] = "Emplacement moyen — vigilance sur la demande locative";
        if ((int)($a['potentiel_valorisation'] ?? 0) <= 2)
            $out[] = "Potentiel de valorisation limité";
        if ((int)($a['etat_general'] ?? '') && in_array($a['etat_general'], ['A rénover', 'Travaux'], true))
            $out[] = "Travaux nécessaires — enveloppe à sécuriser";
        if (in_array(strtoupper((string)($a['dpe'] ?? '')), ['F','G'], true))
            $out[] = "DPE " . strtoupper((string)$a['dpe']) . " — interdiction de location à prévoir (passoire énergétique)";
        if ((int)($a['tension_locative'] ?? 0) <= 2)
            $out[] = "Zone détendue — risque de vacance plus élevé";

        if (empty($out)) $out[] = "Aucune faiblesse majeure n'a été identifiée.";
        return "• " . implode("\n• ", $out);
    }
}

if (!function_exists('generer_risques')) {
    function generer_risques(array $a, array $calc): string {
        $out = [];
        if ((int)($a['niveau_risque'] ?? 0) >= 4)
            $out[] = "Niveau de risque déclaré élevé — décision à valider avec l'investisseur";
        if ((float)($calc['cashflow_mensuel'] ?? 0) < -200)
            $out[] = "Effort d'épargne significatif — impact trésorerie mensuelle";
        if ((int)($a['facilite_revente'] ?? 0) <= 2)
            $out[] = "Liquidité limitée — sortie potentiellement longue";
        if (inv_f($a['taux_credit'] ?? 0) >= 4.2)
            $out[] = "Taux de crédit tendu — sensibilité forte du cashflow";
        if (inv_f($a['vacance_locative'] ?? 0) >= 10)
            $out[] = "Hypothèse de vacance élevée (" . inv_fmt_pct(inv_f($a['vacance_locative'])) . ") — marge de sécurité étroite";
        if (!empty($a['annee_construction']) && (int)$a['annee_construction'] < 1970)
            $out[] = "Bâti ancien — provision travaux structurels à anticiper";

        if (empty($out)) $out[] = "Aucun risque structurel majeur identifié sur les données saisies.";
        return "• " . implode("\n• ", $out);
    }
}

if (!function_exists('generer_opportunites')) {
    function generer_opportunites(array $a, array $calc): string {
        $out = [];
        if ((int)($a['potentiel_valorisation'] ?? 0) >= 4)
            $out[] = "Potentiel de plus-value à moyen terme (valorisation de quartier, rénovation, etc.)";
        if (in_array(strtoupper((string)($a['dpe'] ?? '')), ['D','E'], true))
            $out[] = "Gain énergétique possible (DPE améliorable) — créer de la valeur par les travaux";
        if (!empty($a['type_location']) && stripos((string)$a['type_location'], 'meubl') !== false)
            $out[] = "Régime meublé — optimisation fiscale LMNP envisageable";
        if (in_array(strtolower((string)($a['strategie'] ?? '')), ['colocation','airbnb','saisonnier','lcd'], true))
            $out[] = "Stratégie de rendement majoré (colocation / location courte durée) déjà ciblée";
        if ((int)($a['tension_locative'] ?? 0) >= 4 && (float)($calc['cashflow_mensuel'] ?? 0) > -100)
            $out[] = "Marché tendu + cashflow supportable — effet volant de sécurité";

        if (empty($out)) $out[] = "Opportunités limitées au schéma classique (encaissement loyer + amortissement crédit).";
        return "• " . implode("\n• ", $out);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// RECO FINALE + ARGUMENTAIRES — trois tons (prudent / équilibré / offensif)
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('generer_recommandation_finale')) {
    function generer_recommandation_finale(array $a, array $calc): string {
        $sg = (int)($calc['score_global'] ?? 0);
        return match (true) {
            $sg >= 75 => "À retenir en priorité",
            $sg >= 60 => "À étudier sérieusement",
            $sg >= 45 => "À arbitrer selon profil",
            $sg >= 30 => "À écarter sauf contexte spécifique",
            default   => "À écarter",
        };
    }
}

if (!function_exists('generer_argumentaire_prudent')) {
    function generer_argumentaire_prudent(array $a, array $calc): string {
        $rn = (float)($calc['rendement_net'] ?? 0);
        $cf = (float)($calc['cashflow_mensuel'] ?? 0);
        $ville = trim((string)($a['ville'] ?? '')) ?: 'la zone';
        $s = "Ce dossier s'adresse à un investisseur prudent, qui cherche avant tout la sécurité et la lisibilité. ";
        $s .= "À {$ville}, le rendement net de " . inv_fmt_pct($rn) . " est ";
        $s .= $rn >= 5 ? "conforme aux exigences d'un placement sécurisé. " : "modéré, à compenser par la solidité du bien et de la zone. ";
        $s .= $cf >= 0
            ? "Le cashflow est positif dès la première année : l'investissement ne pèse pas sur la trésorerie."
            : "Le cashflow reste négatif — un effort d'épargne mensuel de " . inv_fmt_eur(abs($cf)) . " est à prévoir, compensé par le remboursement du capital.";
        return $s;
    }
}

if (!function_exists('generer_argumentaire_equilibre')) {
    function generer_argumentaire_equilibre(array $a, array $calc): string {
        $rn = (float)($calc['rendement_net'] ?? 0);
        $sg = (int)($calc['score_global'] ?? 0);
        $strat = trim((string)($a['strategie'] ?? '')) ?: 'location classique';
        $s = "Ce projet se positionne sur un équilibre entre rendement et sécurité. ";
        $s .= "Avec un score global de {$sg}/100 et un rendement net de " . inv_fmt_pct($rn) . ", ";
        $s .= "la stratégie retenue ({$strat}) permet de viser une performance solide à long terme. ";
        $s .= "Le profil du bien offre une double marge de manœuvre : stabilité locative sur la durée, et potentiel de valorisation en cas de revente dans un marché porteur.";
        return $s;
    }
}

if (!function_exists('generer_argumentaire_offensif')) {
    function generer_argumentaire_offensif(array $a, array $calc): string {
        $rn = (float)($calc['rendement_net'] ?? 0);
        $proj = (float)($calc['projection_10_ans'] ?? 0);
        $s = "Pour un investisseur offensif, ce dossier offre plusieurs leviers. ";
        $s .= "Rendement net de " . inv_fmt_pct($rn) . ", projection de cashflow cumulé à 10 ans de " . inv_fmt_eur($proj) . ". ";
        if ((int)($a['potentiel_valorisation'] ?? 0) >= 4)
            $s .= "Le potentiel de valorisation repéré sur la zone peut démultiplier le rendement effectif à la revente. ";
        if (in_array(strtolower((string)($a['strategie'] ?? '')), ['colocation','airbnb','lcd','saisonnier'], true))
            $s .= "La stratégie " . $a['strategie'] . " maximise le loyer brut par mètre carré. ";
        $s .= "Pour un profil acceptant le risque et capable d'absorber un effort d'épargne temporaire, l'opération peut se révéler particulièrement rentable.";
        return $s;
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// PRÉSENTATION CLIENT — bloc HTML prêt à présenter
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('generer_presentation_client')) {
    /**
     * Retourne un bloc HTML "présentation client" clé en main.
     * Mise en forme minimaliste, lisible, impression-friendly.
     */
    function generer_presentation_client(array $a, array $calc): string {
        $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $titre = $h($a['titre_analyse'] ?? 'Analyse investisseur');
        $ville = $h($a['ville'] ?? '');
        $quartier = $h($a['quartier'] ?? '');
        $type = $h($a['type_bien'] ?? '');
        $surface = inv_f($a['surface'] ?? 0);
        $prix = inv_fmt_eur(inv_f($a['prix_achat'] ?? 0));
        $cout = inv_fmt_eur((float)($calc['cout_total'] ?? 0));
        $loyer = inv_fmt_eur(inv_f($a['loyer_estime'] ?? 0));
        $rnBrut = inv_fmt_pct((float)($calc['rendement_brut'] ?? 0));
        $rnNet  = inv_fmt_pct((float)($calc['rendement_net'] ?? 0));
        $cf     = inv_fmt_eur((float)($calc['cashflow_mensuel'] ?? 0));
        $sg     = (int)($calc['score_global'] ?? 0);
        $reco   = $h(generer_recommandation_finale($a, $calc));

        $synth = $h(generer_synthese_analyse($a, $calc));

        return <<<HTML
<div class="pres-client">
    <h1>{$titre}</h1>
    <p class="pres-loc">{$type} — {$ville}{$quartier}</p>
    <p class="pres-synth">{$synth}</p>

    <table class="pres-kpi">
        <tr><th>Prix d'achat</th><td>{$prix}</td></tr>
        <tr><th>Coût total d'acquisition</th><td>{$cout}</td></tr>
        <tr><th>Loyer estimé (HC)</th><td>{$loyer} / mois</td></tr>
        <tr><th>Rendement brut</th><td>{$rnBrut}</td></tr>
        <tr><th>Rendement net</th><td>{$rnNet}</td></tr>
        <tr><th>Cashflow mensuel</th><td>{$cf}</td></tr>
        <tr><th>Score global</th><td>{$sg}/100</td></tr>
        <tr><th>Recommandation</th><td><strong>{$reco}</strong></td></tr>
    </table>
</div>
HTML;
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// RECALCUL GLOBAL + PERSISTANCE — prêt à stocker en BDD
// ═══════════════════════════════════════════════════════════════════════════

if (!function_exists('inv_generate_all_texts')) {
    /**
     * Produit l'ensemble des textes interprétatifs à partir d'une saisie + calculs.
     */
    function inv_generate_all_texts(array $a, array $calc): array {
        return [
            'synthese'               => generer_synthese_analyse($a, $calc),
            'forces_txt'             => generer_forces($a, $calc),
            'faiblesses_txt'         => generer_faiblesses($a, $calc),
            'risques_txt'            => generer_risques($a, $calc),
            'opportunites_txt'       => generer_opportunites($a, $calc),
            'reco_finale'            => generer_recommandation_finale($a, $calc),
            'argumentaire_prudent'   => generer_argumentaire_prudent($a, $calc),
            'argumentaire_equilibre' => generer_argumentaire_equilibre($a, $calc),
            'argumentaire_offensif'  => generer_argumentaire_offensif($a, $calc),
            'presentation_client'    => generer_presentation_client($a, $calc),
        ];
    }
}
