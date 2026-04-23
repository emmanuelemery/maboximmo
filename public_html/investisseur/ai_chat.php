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
