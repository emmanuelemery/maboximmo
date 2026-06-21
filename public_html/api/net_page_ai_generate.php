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

    // Contexte agence
    $st = $pdo->prepare("SELECT a.nom_agence, a.ville, a.code_postal, a.adresse_1, s.nom AS societe_nom
                         FROM agences a LEFT JOIN societes s ON s.id = a.id_societe WHERE a.id = ? LIMIT 1");
    $st->execute([$idAgence]);
    $ag = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $ville = (string)($ag['ville'] ?? '');
    $nom   = (string)($ag['nom_agence'] ?? 'notre agence');

    $apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
    if (!$apiKey) { exit(json_encode(['ok' => false, 'error' => 'Clé OpenAI non configurée'])); }
    $model = defined('OPENAI_TEXT_MODEL') ? OPENAI_TEXT_MODEL : ($GLOBALS['OPENAI_TEXT_MODEL'] ?? 'gpt-4o');

    $system = "Tu es un expert en référencement naturel (SEO) immobilier local en France. "
        . "Tu rédiges un contenu UNIQUE et différenciant pour la page vitrine d'une agence, ancré sur SA ville "
        . "(quartiers, repères, marché local) afin d'éviter tout contenu dupliqué entre agences. "
        . "Rédaction naturelle, pas de keyword stuffing, ton professionnel et chaleureux, conforme à la loi Hoguet. "
        . "Tu ne réponds QU'EN JSON VALIDE, sans texte avant ni après.";

    $ctx = [
        'agence'    => $nom,
        'societe'   => (string)($ag['societe_nom'] ?? ''),
        'ville'     => $ville,
        'code_postal' => (string)($ag['code_postal'] ?? ''),
        'theme_page' => $labels[$pageKey],
        'consigne_utilisateur' => $note ?: null,
    ];

    $user = "Rédige le contenu éditorial de la page « {$labels[$pageKey]} » de l'agence, optimisé SEO LOCAL.\n"
        . "Mentionne la ville et 1 à 3 repères/quartiers réels si pertinents. 200 à 350 mots.\n"
        . "Produis EXACTEMENT ce JSON :\n"
        . "{\n"
        . '  "titre": "titre de section accrocheur incluant la ville (max 90 chars)",' . "\n"
        . '  "contenu_html": "2 à 4 paragraphes en HTML SIMPLE autorisé uniquement: <p> <strong> <ul> <li> <h2> <h3>. Pas d\'attributs, pas de styles, pas de <a>.",' . "\n"
        . '  "meta_title": "balise <title> 50-60 chars avec ville + activité + nom agence",' . "\n"
        . '  "meta_description": "150-160 chars avec appel à l\'action et mot-clé principal en tête"' . "\n"
        . "}\n\n"
        . "Contexte:\n" . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    $payload = json_encode([
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
        'max_tokens' => 1400,
        'temperature' => 0.7,
        'response_format' => ['type' => 'json_object'],
    ]);

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
