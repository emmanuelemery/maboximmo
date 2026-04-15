<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_login();
verify_csrf_any();

$pdo    = $GLOBALS['pdo'];
$roleId = current_role_id();
$userId = current_user_id();

// Réservé manager (2) et admin (1)
if ($roleId !== 1 && $roleId !== 2) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès réservé aux managers.']);
    exit;
}

// ── Lire le body JSON ─────────────────────────────────────────────────────────
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Corps JSON invalide.']);
    exit;
}

$entretienId      = isset($data['entretien_id'])      ? (int)$data['entretien_id']         : 0;
$rubriqueName     = isset($data['rubrique'])           ? trim((string)$data['rubrique'])     : '';
$critereName      = isset($data['critere'])            ? trim((string)$data['critere'])      : '';
$note             = isset($data['note'])               ? max(1, min(5, (int)$data['note']))  : 3;
$reponsePre       = isset($data['reponse_prealable'])  ? trim((string)$data['reponse_prealable']) : '';
$contexte         = isset($data['contexte'])           ? trim((string)$data['contexte'])     : '';

if ($entretienId <= 0 || $rubriqueName === '' || $critereName === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Paramètres manquants.']);
    exit;
}

// ── Vérifier ownership de l'entretien ────────────────────────────────────────
try {
    $stmt = $pdo->prepare("SELECT manager_id FROM rh_entretiens WHERE id = ?");
    $stmt->execute([$entretienId]);
    $entretien = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $entretien = null;
}

if (!$entretien || ($roleId !== 1 && (int)$entretien['manager_id'] !== $userId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès refusé.']);
    exit;
}

// ── Fallback local ────────────────────────────────────────────────────────────
$fallbacks = [
    'performance' => [
        1 => "Des difficultés significatives sont constatées sur ce critère. Un accompagnement renforcé est nécessaire.",
        2 => "Les résultats restent en deçà des attentes. Un plan d'amélioration doit être mis en place.",
        3 => "Les résultats sont conformes aux attentes sur ce critère, avec une marge de progression identifiée.",
        4 => "Le collaborateur affiche de bons résultats et démontre une maîtrise satisfaisante de ce critère.",
        5 => "Les résultats sont excellents. Le collaborateur dépasse les attentes sur ce critère.",
    ],
    'comportement' => [
        1 => "Des comportements inadaptés ont été observés, nécessitant un recadrage immédiat.",
        2 => "Le comportement professionnel présente des lacunes qui doivent être adressées rapidement.",
        3 => "Le comportement est globalement satisfaisant, quelques axes d'amélioration sont identifiés.",
        4 => "Le collaborateur fait preuve d'un comportement professionnel apprécié de l'équipe.",
        5 => "Le collaborateur est un modèle de comportement professionnel et contribue positivement à l'ambiance d'équipe.",
    ],
    'motivation' => [
        1 => "Une démotivation importante est constatée. Un accompagnement personnalisé est indispensable.",
        2 => "Le niveau de motivation est faible, des actions de remotivation doivent être envisagées.",
        3 => "La motivation est présente bien que variable. Des leviers supplémentaires pourraient être activés.",
        4 => "Le collaborateur affiche une bonne motivation et s'investit avec enthousiasme.",
        5 => "La motivation du collaborateur est exemplaire et communicative au sein de l'équipe.",
    ],
    'competences' => [
        1 => "Des lacunes importantes ont été identifiées. Une formation urgente ou un accompagnement renforcé est nécessaire.",
        2 => "Le niveau de compétences est insuffisant pour le poste. Un plan de développement doit être établi.",
        3 => "Les compétences sont satisfaisantes pour le poste, avec des axes de développement à travailler.",
        4 => "Le collaborateur maîtrise bien les compétences requises et continue à progresser.",
        5 => "Le collaborateur est un expert reconnu, ses compétences constituent un réel atout pour l'entreprise.",
    ],
    'default' => [
        1 => "Des points de vigilance importants ont été identifiés sur ce critère. Un plan d'action correctif est nécessaire.",
        2 => "Les attentes ne sont pas pleinement satisfaites sur ce point. Des améliorations sont attendues.",
        3 => "Le collaborateur répond aux attentes sur ce critère, avec des opportunités de progression identifiées.",
        4 => "Le collaborateur affiche de bonnes performances sur ce critère, reconnu positivement.",
        5 => "Performances remarquables sur ce critère. Le collaborateur dépasse largement les attentes.",
    ],
];

function getFallback(array $fallbacks, string $rubrique, int $note): string {
    $key = mb_strtolower($rubrique);
    foreach (array_keys($fallbacks) as $k) {
        if ($k !== 'default' && str_contains($key, $k)) {
            return $fallbacks[$k][$note] ?? $fallbacks['default'][$note];
        }
    }
    return $fallbacks['default'][$note];
}

function getTonalite(int $note): string {
    if ($note <= 2) return 'vigilance';
    if ($note === 3) return 'neutre';
    return 'valorisant';
}

// ── Charger la clé OpenAI ─────────────────────────────────────────────────────
$configPaths = [
    '/home/u630423897/maboximmo_openai_config.php',
    dirname(__DIR__, 2) . '/u630423897/maboximmo_openai_config.php',
    dirname(__DIR__, 2) . '/u630423897/openai_config.php',
    __DIR__ . '/../../u630423897/maboximmo_openai_config.php',
    __DIR__ . '/../../u630423897/openai_config.php',
];

$apiKey = null;
foreach ($configPaths as $path) {
    if (file_exists($path)) {
        @include $path;
        if (defined('OPENAI_API_KEY')) {
            $apiKey = OPENAI_API_KEY;
            break;
        }
    }
}

$model = defined('OPENAI_TEXT_MODEL') ? OPENAI_TEXT_MODEL : 'gpt-4o';
$tonalite = getTonalite($note);

// ── Construire le prompt ──────────────────────────────────────────────────────
$tonDescr = match(true) {
    $note <= 2  => 'vigilance et bienveillance corrective (ton professionnel mais sérieux)',
    $note === 3 => 'neutre et équilibré, objectif',
    default     => 'valorisant et positif, reconnaissant les mérites',
};

$promptLines = [
    "Tu es un expert RH spécialisé en entretiens individuels.",
    "Dans le cadre d'un entretien individuel :",
    "- Rubrique : {$rubriqueName}",
    "- Critère : {$critereName}",
    "- Note : {$note}/5",
];
if ($reponsePre !== '') {
    $promptLines[] = "- Le collaborateur a dit en préalable : \"{$reponsePre}\"";
}
if ($contexte !== '') {
    $promptLines[] = "- Contexte complémentaire : \"{$contexte}\"";
}
$promptLines[] = "";
$promptLines[] = "Rédige UN commentaire de 2 à 3 phrases, professionnel et objectif, utilisable directement dans un compte-rendu RH officiel.";
$promptLines[] = "Ton requis : {$tonDescr}.";
$promptLines[] = "Réponds UNIQUEMENT avec le commentaire, sans titre, sans explication, sans guillemets.";

$prompt = implode("\n", $promptLines);

// ── Si pas de clé API → fallback immédiat ────────────────────────────────────
if (!$apiKey) {
    echo json_encode([
        'success'   => true,
        'suggestion' => getFallback($fallbacks, $rubriqueName, $note),
        'tonalite'  => $tonalite,
        'source'    => 'local',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Appel OpenAI ──────────────────────────────────────────────────────────────
try {
    $ch = curl_init('https://api.openai.com/v1/chat/completions');

    $requestBody = [
        'model'    => $model,
        'messages' => [
            [
                'role'    => 'user',
                'content' => $prompt,
            ],
        ],
    ];

    if (str_contains($model, 'gpt-5')) {
        $requestBody['max_completion_tokens'] = 300;
    } else {
        $requestBody['max_tokens']   = 300;
        $requestBody['temperature']  = 0.65;
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS     => json_encode($requestBody),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlErr || $httpCode !== 200) {
        throw new RuntimeException('OpenAI unavailable: HTTP ' . $httpCode . ' ' . $curlErr);
    }

    $result = json_decode($response, true);

    if (!isset($result['choices'][0]['message']['content'])) {
        throw new RuntimeException('Structure de réponse OpenAI inattendue.');
    }

    $suggestion = trim($result['choices'][0]['message']['content']);

    // Nettoyer les guillemets éventuels retournés par l'API
    $suggestion = trim($suggestion, '"\'«»');

    echo json_encode([
        'success'    => true,
        'suggestion' => $suggestion,
        'tonalite'   => $tonalite,
        'source'     => 'openai',
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    // Fallback silencieux : on ne retourne pas d'erreur visible
    echo json_encode([
        'success'    => true,
        'suggestion' => getFallback($fallbacks, $rubriqueName, $note),
        'tonalite'   => $tonalite,
        'source'     => 'local',
    ], JSON_UNESCAPED_UNICODE);
}

