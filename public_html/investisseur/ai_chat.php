<?php
declare(strict_types=1);
/**
 * investisseur/ai_chat.php — Chatbot IA sur le portefeuille investisseur.
 *
 * Reçoit une question en langage naturel, construit un contexte riche avec
 * les données du portefeuille (top biens, flop, stats consolidées, alertes
 * baux, concentrations locataires), et interroge GPT-5.
 *
 * La réponse est du markdown simple (paragraphes + puces) argumentée.
 */

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();
require_once __DIR__ . '/../inc/csrf.php';
require_once __DIR__ . '/../inc/investisseur_helpers.php';
require_once __DIR__ . '/../inc/investisseur_ai.php';

header('Content-Type: application/json; charset=utf-8');

$pdo = $GLOBALS['pdo'];

try {
    verify_csrf_any();
    $question = trim((string)($_POST['question'] ?? ''));
    if ($question === '') throw new RuntimeException('Question vide');
    if (mb_strlen($question) > 500) throw new RuntimeException('Question trop longue (max 500 car.)');

    $idAnalyse = (int)($_POST['id_analyse'] ?? 0);

    // ═══════════════════════════════════════════════════════════════════
    // MODE BIEN SPÉCIFIQUE (page réunion)
    // ═══════════════════════════════════════════════════════════════════
    if ($idAnalyse > 0) {
        $row = inv_load($pdo, $idAnalyse);
        if (!$row) throw new RuntimeException('Bien introuvable ou hors périmètre');

        // Historique prix
        $stH = $pdo->prepare("SELECT prix_ancien, prix_nouveau, motif, changed_at
                              FROM investisseur_prix_historique
                              WHERE id_analyse = :id ORDER BY changed_at DESC LIMIT 10");
        $stH->bindValue(':id', $idAnalyse, PDO::PARAM_INT);
        $stH->execute();
        $historique = $stH->fetchAll(PDO::FETCH_ASSOC);

        // Commentaires orientés
        $stC = $pdo->prepare("SELECT categorie, titre, contenu, poids FROM investisseur_commentaires
                              WHERE id_analyse = :id AND actif = 1 ORDER BY poids DESC LIMIT 15");
        try { $stC->bindValue(':id', $idAnalyse, PDO::PARAM_INT); $stC->execute(); $comms = $stC->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $comms = []; }

        $ctx = [
            'mode' => 'bien_specifique',
            'bien' => [
                'titre'       => $row['titre_analyse'],
                'type'        => $row['type_bien'],
                'ville'       => $row['ville'],
                'adresse'     => $row['adresse'],
                'surface_m2'  => (float)$row['surface'],
                'locataire'   => $row['locataire_nom'],
                'bail_fin'    => $row['bail_fin'],
                'photovoltaique' => !empty($row['photovoltaique']),
                'prix_vente_catalogue' => (float)$row['prix_vente_catalogue'],
                'prix_achat'  => (float)$row['prix_achat'],
                'loyer_mensuel' => (float)$row['loyer_estime'],
                'loyer_annuel'  => (float)$row['loyer_estime'] * 12,
                'frais_notaire' => (float)$row['frais_notaire'],
                'honoraires_vente' => (float)$row['honoraires_vente'],
                'travaux_acquereur' => (float)$row['travaux'],
                'travaux_bailleur'  => (float)($row['travaux_bailleur'] ?? 0),
                'credit_crd'    => (float)($row['credit_crd'] ?? 0),
                'credit_duree_restante_mois' => (int)($row['credit_duree_restante_mois'] ?? 0),
                'rdt_brut'      => (float)$row['rendement_brut'],
                'rdt_net'       => (float)$row['rendement_net'],
                'prix_m2'       => (float)$row['prix_m2'],
                'multiple_loyer'=> (float)$row['multiple_loyer'],
                'cashflow_mensuel' => (float)$row['cashflow_mensuel'],
                'score_global'  => (int)$row['score_global'],
                'score_risque'  => (int)$row['score_risque'],
                'score_attractivite' => (int)$row['score_attractivite'],
                'priorite_vente'=> (int)($row['priorite_vente'] ?? 0),
                'strategie'     => $row['strategie'],
                'reco_finale'   => $row['reco_finale'],
                'synthese'      => $row['synthese'],
                'commentaire_humain'  => $row['commentaire_humain'],
                'commentaire_reunion' => $row['commentaire_reunion'],
            ],
            'historique_prix' => array_map(fn($h) => [
                'date' => $h['changed_at'], 'ancien' => (float)$h['prix_ancien'],
                'nouveau' => (float)$h['prix_nouveau'], 'motif' => $h['motif'],
            ], $historique),
            'commentaires_orientes' => array_map(fn($c) => [
                'categorie' => $c['categorie'], 'titre' => $c['titre'],
                'contenu' => $c['contenu'], 'poids' => (int)$c['poids'],
            ], $comms),
        ];

        $system = "Tu es un analyste investissement immobilier français expérimenté. "
            . "Tu réponds aux questions d'un professionnel sur UN bien spécifique (voir contexte JSON). "
            . "Sois concis (max 200 mots), argumente avec les chiffres fournis. "
            . "Nomme les éléments précis du bien quand pertinent. "
            . "Si la donnée manque, dis-le plutôt qu'inventer. "
            . "Format : paragraphes courts + puces. Ton direct et professionnel.";

        $user = "CONTEXTE BIEN (JSON) :\n" . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n"
              . "QUESTION :\n" . $question;

        $resp = inv_ai_chat($system, $user, false, 0.3);
        if (!$resp['ok']) throw new RuntimeException($resp['error'] ?? 'Erreur IA');
        echo json_encode(['ok' => true, 'reponse' => $resp['text']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ═══════════════════════════════════════════════════════════════════
    // MODE PORTEFEUILLE (dashboard)
    // ═══════════════════════════════════════════════════════════════════
    // ── Contexte du portefeuille (scopé utilisateur) ────────────────────
    $scope = inv_scope_where();
    $sql = "SELECT id, titre_analyse, reference_bien, type_bien, ville, surface,
                   prix_vente_catalogue, prix_achat, loyer_estime,
                   rendement_brut, rendement_net, prix_m2, multiple_loyer,
                   cashflow_mensuel, score_global, priorite_vente,
                   locataire_nom, bail_fin, statut
            FROM investisseur_analyses
            WHERE " . $scope['sql'] . "
            ORDER BY score_global DESC LIMIT 300";
    $st = $pdo->prepare($sql);
    foreach ($scope['params'] as $k => $v) $st->bindValue($k, $v);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);

    // Stats consolidées
    $n = count($rows);
    $totValeur = array_sum(array_column($rows, 'prix_achat'));
    $totLoyer = array_sum(array_map(fn($r) => (float)$r['loyer_estime'] * 12, $rows));
    $rdtBrutMoy = $totValeur > 0 ? round(($totLoyer / $totValeur) * 100, 2) : 0;

    // Répartitions
    $parTypo = []; $parVille = []; $parLoc = [];
    foreach ($rows as $r) {
        $t = inv_typologie_of($r['type_bien']);
        $parTypo[$t] = ($parTypo[$t] ?? 0) + (float)$r['prix_achat'];
        if ($r['ville']) $parVille[$r['ville']] = ($parVille[$r['ville']] ?? 0) + 1;
        if ($r['locataire_nom']) $parLoc[$r['locataire_nom']] = ($parLoc[$r['locataire_nom']] ?? 0) + (float)$r['prix_achat'];
    }
    arsort($parTypo); arsort($parVille); arsort($parLoc);

    // Top / Flop par score
    $top5 = array_slice($rows, 0, 5);
    $flop5 = array_slice(array_reverse($rows), 0, 5);

    // Baux à échéance proche
    $baux = [];
    foreach ($rows as $r) {
        if (!empty($r['bail_fin']) && $r['bail_fin'] !== '0000-00-00') {
            $days = (int)floor((strtotime((string)$r['bail_fin']) - time()) / 86400);
            if ($days >= 0 && $days <= 540) {
                $baux[] = ['bien' => $r['titre_analyse'], 'locataire' => $r['locataire_nom'], 'fin' => $r['bail_fin'], 'jours' => $days];
            }
        }
    }
    usort($baux, fn($a, $b) => $a['jours'] <=> $b['jours']);
    $baux = array_slice($baux, 0, 8);

    // ── Construction du contexte pour le modèle ─────────────────────────
    $ctx = [
        'portefeuille' => [
            'nb_biens' => $n,
            'valeur_totale_eur' => round($totValeur, 0),
            'loyers_annuels_eur' => round($totLoyer, 0),
            'rdt_brut_moyen_pct' => $rdtBrutMoy,
        ],
        'repartition_typologie_eur' => $parTypo,
        'top_5_villes_nb_biens'     => array_slice($parVille, 0, 5, true),
        'top_locataires_valeur_eur' => array_slice($parLoc, 0, 5, true),
        'top_5_biens' => array_map(fn($r) => [
            'titre' => $r['titre_analyse'], 'ville' => $r['ville'],
            'score' => (int)$r['score_global'], 'rdt_net' => (float)$r['rendement_net'],
            'prix' => (float)$r['prix_achat'], 'locataire' => $r['locataire_nom'],
        ], $top5),
        'flop_5_biens' => array_map(fn($r) => [
            'titre' => $r['titre_analyse'], 'ville' => $r['ville'],
            'score' => (int)$r['score_global'], 'rdt_net' => (float)$r['rendement_net'],
            'prix' => (float)$r['prix_achat'], 'cashflow_mens' => (float)$r['cashflow_mensuel'],
        ], $flop5),
        'baux_echeance_18_mois' => $baux,
    ];

    $system = "Tu es un analyste investissement immobilier français expérimenté. "
        . "Tu réponds aux questions de ton interlocuteur (un professionnel de l'immobilier) sur un portefeuille de biens investisseur. "
        . "Tu bases tes réponses STRICTEMENT sur les données fournies dans le contexte JSON ci-dessous. "
        . "RÈGLES :\n"
        . "- Sois concis et argumenté (max 250 mots).\n"
        . "- Cite des chiffres précis quand pertinent.\n"
        . "- Nomme les biens spécifiques quand utile.\n"
        . "- Si la donnée n'est pas dans le contexte, dis-le clairement plutôt que d'inventer.\n"
        . "- Format : paragraphes courts + puces si liste. Pas de titres.\n"
        . "- Utilise le ton direct et professionnel.";

    $user = "CONTEXTE PORTEFEUILLE (JSON) :\n" . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n"
          . "QUESTION :\n" . $question;

    $resp = inv_ai_chat($system, $user, false, 0.3);
    if (!$resp['ok']) throw new RuntimeException($resp['error'] ?? 'Erreur IA');

    echo json_encode(['ok' => true, 'reponse' => $resp['text']], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
