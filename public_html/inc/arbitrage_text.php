<?php
declare(strict_types=1);

require_once __DIR__ . '/arbitrage_calc.php';

function arb_fmt_eur(float $v, int $decimals = 0): string
{
    return number_format($v, $decimals, ',', ' ') . ' €';
}

function arb_fmt_pct(float $v): string
{
    return number_format($v, 2, ',', ' ') . ' %';
}

function arb_posture_label(string $posture): string
{
    return match ($posture) {
        'patrimoniale' => 'Patrimoniale / affective',
        'urgence_tresorerie' => 'Urgence trésorerie',
        default => 'Analyse neutre',
    };
}

function arb_build_synthese_template(array $bien, array $arb, array $crgSnap, array $metrics, array $settings): string
{
    $designation = trim((string)($bien['designation'] ?? $bien['reference_bien'] ?? 'Bien'));
    $ville = trim((string)($bien['ville'] ?? ''));
    $posture = (string)($arb['posture'] ?? 'neutre');

    $statut = (string)($arb['statut_locatif'] ?? '');
    if ($statut === '') $statut = (string)($crgSnap['latest']['statut_trimestre'] ?? '');
    $statutTxt = match ($statut) {
        'loue', 'occupé', 'occupe' => 'loué',
        'partiel' => 'partiellement loué',
        'vide', 'vacant' => 'vide',
        default => '—',
    };

    $prix = (float)($metrics['prix_vente_realiste'] ?? 0.0);
    $loyer = (float)($metrics['loyer_actuel_mensuel'] ?? 0.0);
    $coutAnnuel = (float)($metrics['cout_annuel'] ?? 0.0);
    $rendReel = (float)($metrics['rendement_reel_pct'] ?? 0.0);
    $vacMois = (int)($metrics['vacance_mois'] ?? 0);
    $travaux = (float)($metrics['travaux_total'] ?? 0.0);
    $impaye = (float)($crgSnap['latest']['impaye_latest'] ?? 0.0);
    $score = (int)($metrics['score_strategique'] ?? 0);

    $tresoTarget = arb_f($settings['objectif_tresorerie'] ?? 15000000.0);
    $horizon = (int)($settings['horizon_mois'] ?? 18);

    $lines = [];
    $lines[] = "Synthèse automatique (" . arb_posture_label($posture) . ")";
    $lines[] = "Bien : {$designation}" . ($ville ? " — {$ville}" : "") . ".";
    $lines[] = "Statut locatif : {$statutTxt}" . ($vacMois > 0 ? " (vacance estimée : {$vacMois} mois)" : "") . ".";
    if ($prix > 0) $lines[] = "Valeur / prix de vente réaliste : " . arb_fmt_eur($prix) . ".";
    if ($loyer > 0) $lines[] = "Loyer actuel : " . arb_fmt_eur($loyer) . "/mois (hors charges).";
    if ($coutAnnuel > 0) $lines[] = "Coût annuel estimé : " . arb_fmt_eur($coutAnnuel) . "/an.";
    $lines[] = "Rendement réel (acte en main) : " . arb_fmt_pct($rendReel) . ".";
    if ($travaux > 0) $lines[] = "Travaux prévisibles (ordre de grandeur) : " . arb_fmt_eur($travaux) . ".";
    if ($impaye > 0) $lines[] = "Signal CRG : impayés détectés (dernier CRG) : " . arb_fmt_eur($impaye, 2) . ".";
    $lines[] = "Score stratégique : {$score}/100 (heuristique v1).";
    $lines[] = "Objectif trésorerie : " . arb_fmt_eur($tresoTarget) . " sur {$horizon} mois.";

    // Ton (posture)
    if ($posture === 'urgence_tresorerie') {
        $lines[] = "Lecture urgence : priorité à la liquidité et à la sécurisation des flux sur {$horizon} mois.";
    } elseif ($posture === 'patrimoniale') {
        $lines[] = "Lecture patrimoniale : privilégier la conservation des actifs solides et la décision progressive si l'attachement / le contexte le justifie.";
    } else {
        $lines[] = "Lecture neutre : arbitrer en équilibrant rendement, coûts, travaux, risque et liquidité.";
    }

    return implode("\n", $lines);
}

function arb_build_argumentaire_template(array $bien, array $arb, array $crgSnap, array $metrics, array $settings): string
{
    $posture = (string)($arb['posture'] ?? 'neutre');
    $decision = (string)($arb['decision'] ?? '');

    $designation = trim((string)($bien['designation'] ?? $bien['reference_bien'] ?? 'Bien'));
    $ville = trim((string)($bien['ville'] ?? ''));

    $prix = (float)($metrics['prix_vente_realiste'] ?? 0.0);
    $rendReel = (float)($metrics['rendement_reel_pct'] ?? 0.0);
    $coutAnnuel = (float)($metrics['cout_annuel'] ?? 0.0);
    $vacMois = (int)($metrics['vacance_mois'] ?? 0);
    $travaux = (float)($metrics['travaux_total'] ?? 0.0);

    $proj = (array)($metrics['projection_18m'] ?? []);
    $pConserver = (array)($proj['conserver'] ?? []);
    $pVendre = (array)($proj['vendre'] ?? []);
    $pVR = (array)($proj['vendre_reinvestir'] ?? []);

    $signals = (array)($crgSnap['signals'] ?? []);
    $signalsTxt = [];
    foreach ($signals as $s) {
        $t = trim((string)($s['title'] ?? ''));
        $v = trim((string)($s['value'] ?? ''));
        if ($t === '') continue;
        $signalsTxt[] = $v ? "{$t} — {$v}" : $t;
    }

    $parts = [];

    // 1. Constat factuel
    $parts[] = "1) Constat factuel";
    $parts[] = "- Actif : {$designation}" . ($ville ? " ({$ville})" : "") . ".";
    if ($prix > 0) $parts[] = "- Valeur / prix de vente réaliste retenu : " . arb_fmt_eur($prix) . ".";
    $parts[] = "- Rendement réel (acte en main) estimé : " . arb_fmt_pct($rendReel) . ".";

    // 2. État locatif et coût réel
    $parts[] = "\n2) État locatif et coût réel";
    if ($vacMois > 0) $parts[] = "- Vacance estimée : {$vacMois} mois (si confirmé, coût d'inaction significatif).";
    if ($coutAnnuel > 0) $parts[] = "- Coût annuel : " . arb_fmt_eur($coutAnnuel) . "/an (hors opportunité).";

    // 3. Passif technique / travaux
    $parts[] = "\n3) Passif technique / travaux";
    if ($travaux > 0) $parts[] = "- Travaux prévisibles : " . arb_fmt_eur($travaux) . " (impact direct sur valeur et trésorerie).";
    else $parts[] = "- Aucun travaux significatif saisi à ce stade.";

    // 4. Liquidité / décote / risque
    $parts[] = "\n4) Liquidité / décote / risque";
    $liq = (int)($metrics['liquidite_niveau'] ?? 3);
    $risk = (int)($metrics['risque_niveau'] ?? 3);
    $delai = (int)($metrics['delai_vente_mois'] ?? 6);
    $parts[] = "- Liquidité (1-5) : {$liq}/5 ; risque (1-5) : {$risk}/5 ; délai de vente estimé : {$delai} mois.";

    // 5. Apports issus des CRG
    $parts[] = "\n5) Apports issus des CRG";
    if (!empty($signalsTxt)) {
        foreach (array_slice($signalsTxt, 0, 8) as $line) {
            $parts[] = "- " . $line;
        }
    } else {
        $parts[] = "- Aucun signal CRG spécifique détecté (ou CRG non relié au lot).";
    }

    // 6. Mon analyse (réservé au champ Emery)
    $parts[] = "\n6) Mon analyse (Emery)";
    $parts[] = "- Voir commentaire et contexte stratégique (terrain / patrimonial / groupe).";

    // 7. Conclusion stratégique (pré-conclusion + influence posture)
    $parts[] = "\n7) Conclusion stratégique";
    $ton = match ($posture) {
        'urgence_tresorerie' => "Lecture prioritaire : contribution rapide à la trésorerie (objectif 15 M€ / 18 mois).",
        'patrimoniale' => "Lecture patrimoniale : arbitrage progressif, préserver la qualité d'actif si la trajectoire le permet.",
        default => "Lecture neutre : décider selon rendement, coûts, travaux, risque et liquidité.",
    };
    $parts[] = "- " . $ton;
    if ($decision !== '') {
        $parts[] = "- Décision sélectionnée : " . strtoupper($decision) . ".";
    } else {
        $parts[] = "- Décision : à trancher (conserver / vendre / arbitrer / réinvestir).";
    }

    // Ajout mini projection (utile en réunion)
    $parts[] = "\nProjection " . (int)($proj['horizon_mois'] ?? 18) . " mois (ordre de grandeur)";
    if (!empty($pConserver)) $parts[] = "- Conserver : résultat net ≈ " . arb_fmt_eur((float)($pConserver['resultat_net'] ?? 0.0), 0) . ".";
    if (!empty($pVendre)) $parts[] = "- Vendre : cash ≈ " . arb_fmt_eur((float)($pVendre['cash'] ?? 0.0), 0) . " ; impact trésorerie ≈ " . arb_fmt_eur((float)($pVendre['impact_tresorerie'] ?? 0.0), 0) . ".";
    if (!empty($pVR)) $parts[] = "- Vendre + réinvestir : impact trésorerie ≈ " . arb_fmt_eur((float)($pVR['impact_tresorerie'] ?? 0.0), 0) . " (rendement cible " . arb_fmt_pct((float)($pVR['rendement_cible_pct'] ?? 0.0)) . ").";

    return implode("\n", $parts);
}

function arb_merge_argumentaire_final(string $syntheseAuto, array $crgSnap, string $commentaireUser, string $conclusionUser, string $argumentaireTemplate): string
{
    $commentaireUser = trim($commentaireUser);
    $conclusionUser  = trim($conclusionUser);

    $signals = (array)($crgSnap['signals'] ?? []);
    $crgBlock = [];
    if (!empty($signals)) {
        $crgBlock[] = "CRG — Signaux clés";
        foreach (array_slice($signals, 0, 10) as $s) {
            $t = trim((string)($s['title'] ?? ''));
            $v = trim((string)($s['value'] ?? ''));
            if ($t === '') continue;
            $crgBlock[] = "- " . $t . ($v ? " : {$v}" : "");
        }
    }

    $final = [];
    $final[] = $syntheseAuto;
    if (!empty($crgBlock)) {
        $final[] = "";
        $final[] = implode("\n", $crgBlock);
    }
    $final[] = "";
    $final[] = "Argumentaire (version réunion)";
    $final[] = $argumentaireTemplate;

    $final[] = "";
    $final[] = "Analyse & recommandation Emery";
    $final[] = $commentaireUser !== '' ? $commentaireUser : "—";
    $final[] = "";
    $final[] = "Conclusion";
    $final[] = $conclusionUser !== '' ? $conclusionUser : "—";

    return implode("\n", $final);
}

