<?php
declare(strict_types=1);
set_time_limit(60);

/**
 * POST /api/net_page_ai_generate.php
 * Génère un contenu éditorial LOCAL optimisé SEO pour une page de vitrine
 * (agence_net_page) : titre, contenu_html, meta_title, meta_description.
 *
 * POST : csrf_token, ag (id_agence), page (page_key), note (consignes libres optionnelles)
 * Réponse JSON : { ok, generated:{titre, contenu_html, meta_title, meta_description}, model_used }
 */

require_once dirname(__DIR__) . '/inc/bootstrap.php';
require_once dirname(__DIR__) . '/inc/net_context.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

// Marqueur de version : GET ?ver=1 → confirme quelle version du fichier tourne.
if (isset($_GET['ver'])) { exit(json_encode(['ok' => true, 'ver' => 'net-ai-v4-secteur', 'model' => 'gpt-4o'])); }

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit(json_encode(['ok' => false, 'error' => 'Méthode non autorisée'])); }
    verify_csrf('net_ai');

    $pdo = $GLOBALS['pdo'];
    if (!net_admin_can()) { http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'Réservé aux managers'])); }

    $idAgence = (int)($_POST['ag'] ?? 0);
    $pageKey  = (string)($_POST['page'] ?? '');
    $note     = trim((string)($_POST['note'] ?? ''));
    if (!net_admin_agence_autorisee($pdo, $idAgence)) { http_response_code(403); exit(json_encode(['ok' => false, 'error' => 'Agence hors périmètre'])); }

    $labels = [
        'home' => "page d'accueil (présentation générale de l'agence et de la ville)",
        'transaction' => "achat & vente immobilier",
        'gestion' => "gestion locative",
        'location' => "location de biens",
        'syndic' => "syndic de copropriété",
        'investissement' => "investissement immobilier",
        'a-propos' => "présentation de l'agence (à propos)",
    ];
    if (!isset($labels[$pageKey])) { exit(json_encode(['ok' => false, 'error' => 'Page inconnue'])); }

    // Contexte agence (+ services réellement actifs → ne pas inventer d'offre)
    $st = $pdo->prepare("SELECT a.nom_agence, a.ville, a.code_postal, a.adresse_1,
                                a.transaction_active, a.location_active, a.gestion_active, a.syndic_active,
                                s.nom AS societe_nom
                         FROM agences a LEFT JOIN societes s ON s.id = a.id_societe WHERE a.id = ? LIMIT 1");
    $st->execute([$idAgence]);
    $ag = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $ville = (string)($ag['ville'] ?? '');
    $nom   = (string)($ag['nom_agence'] ?? 'notre agence');

    $services = [];
    if (!empty($ag['transaction_active'])) $services[] = 'Transaction (achat/vente)';
    if (!empty($ag['location_active']))    $services[] = 'Location';
    if (!empty($ag['gestion_active']))     $services[] = 'Gestion locative';
    if (!empty($ag['syndic_active']))      $services[] = 'Syndic de copropriété';

    $apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
    if (!$apiKey) { exit(json_encode(['ok' => false, 'error' => 'Clé OpenAI non configurée'])); }
    // Modèle figé en gpt-4o pour ce module (demande métier : rester en GPT-4,
    // indépendamment de OPENAI_TEXT_MODEL global qui peut être gpt-5).
    $model = 'gpt-4o';

    $system = "Tu es un expert en référencement naturel (SEO) immobilier local en France. "
        . "Tu rédiges un contenu UNIQUE et différenciant pour la page vitrine d'une agence, ancré sur SA ville "
        . "(quartiers, repères, marché local) afin d'éviter tout contenu dupliqué entre agences. "
        . "Rédaction naturelle, pas de keyword stuffing, ton professionnel et chaleureux, conforme à la loi Hoguet. "
        . "Tu ne réponds QU'EN JSON VALIDE, sans texte avant ni après.";

    $ctx = [
        'agence'      => $nom,
        'societe'     => (string)($ag['societe_nom'] ?? ''),
        'ville'       => $ville,
        'code_postal' => (string)($ag['code_postal'] ?? ''),
        'theme_page'  => $labels[$pageKey],
        'services_actifs' => $services,
        'consigne_utilisateur' => $note ?: null,
    ];

    $user = "Rédige le contenu éditorial de la page « {$labels[$pageKey]} » de l'agence, optimisé SEO LOCAL. 300 à 450 mots.\n\n"
        . "EXIGENCES DE CONTENU (pour un référencement local fort) :\n"
        . "1. SECTEUR ÉLARGI : en plus de la ville de l'agence, cite 4 à 6 communes VOISINES réelles et cohérentes géographiquement, "
        . "dans un paragraphe ou un <h2>\"Secteur d'intervention\" avec une <ul> de communes. Cela capte les recherches alentour sans surcharger le titre.\n"
        . "2. TYPES DE BIENS : mentionne les types de biens traités, pertinents pour le thème de la page "
        . "(ex. appartements, maisons, immeubles, locaux/commerces, terrains, parkings).\n"
        . "3. QUALITÉS DE LA SOCIÉTÉ : mets en avant le sérieux et l'expertise — connaissance fine du marché local, accompagnement personnalisé, "
        . "estimation, conformité loi Hoguet, et UNIQUEMENT les services réellement proposés (voir 'services_actifs'). N'invente AUCUN service absent de cette liste.\n"
        . "4. Termine par un appel à l'action (estimation/contact). Rédaction naturelle, sans keyword stuffing.\n\n"
        . "Produis EXACTEMENT ce JSON :\n"
        . "{\n"
        . '  "titre": "titre de section accrocheur incluant la ville principale (max 90 chars)",' . "\n"
        . '  "contenu_html": "plusieurs paragraphes structurés en HTML SIMPLE autorisé uniquement: <p> <strong> <ul> <li> <h2> <h3>. Pas d\'attributs, pas de styles, pas de <a>. Inclure une section Secteur d\'intervention avec <h2> + <ul> de communes.",' . "\n"
        . '  "meta_title": "balise <title> 50-60 chars : UNE SEULE ville principale + activité + nom agence (PAS de liste de villes)",' . "\n"
        . '  "meta_description": "150-160 chars avec appel à l\'action, mot-clé principal en tête, mention possible du secteur"' . "\n"
        . "}\n\n"
        . "Contexte:\n" . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    // Compat modèles : gpt-5 / o-series exigent max_completion_tokens et
    // n'acceptent pas de temperature personnalisée ; gpt-4o etc. utilisent max_tokens.
    $isNewGen = (bool)preg_match('/^(gpt-5|o\d)/i', $model);
    $payloadArr = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
        'response_format' => ['type' => 'json_object'],
    ];
    if ($isNewGen) {
        $payloadArr['max_completion_tokens'] = 1800;
    } else {
        $payloadArr['max_tokens'] = 1800;
        $payloadArr['temperature'] = 0.7;
    }
    $payload = json_encode($payloadArr);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError)        exit(json_encode(['ok' => false, 'error' => 'Réseau : ' . $curlError]));
    if ($httpCode !== 200) exit(json_encode(['ok' => false, 'error' => 'OpenAI HTTP ' . $httpCode . ' : ' . mb_substr((string)$response, 0, 250)]));

    $data = json_decode((string)$response, true);
    $gen  = json_decode((string)($data['choices'][0]['message']['content'] ?? ''), true);
    if (!is_array($gen)) { exit(json_encode(['ok' => false, 'error' => 'Réponse IA non-JSON'])); }

    // Nettoyage défensif du HTML (mêmes balises autorisées qu'à l'affichage).
    $allowed = '<p><br><strong><b><em><i><ul><ol><li><h2><h3>';
    $gen['contenu_html'] = strip_tags((string)($gen['contenu_html'] ?? ''), $allowed);

    echo json_encode([
        'ok' => true,
        'generated' => [
            'titre'            => (string)($gen['titre'] ?? ''),
            'contenu_html'     => (string)($gen['contenu_html'] ?? ''),
            'meta_title'       => (string)($gen['meta_title'] ?? ''),
            'meta_description' => (string)($gen['meta_description'] ?? ''),
        ],
        'model_used' => $model,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => APP_DEBUG ? $e->getMessage() : 'Erreur serveur']);
}
