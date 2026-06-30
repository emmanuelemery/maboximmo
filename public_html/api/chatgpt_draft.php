<?php
declare(strict_types=1);
header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();
$roleId = current_role_id();
if ($roleId !== 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Load OpenAI config - try multiple paths
$configPaths = [
    // Hostinger paths
    '/home/u630423897/maboximmo_openai_config.php',
    '/home/u630423897/openai_config.php',
    // Local dev paths
    dirname(__DIR__, 2) . '/u630423897/maboximmo_openai_config.php',
    dirname(__DIR__, 2) . '/u630423897/openai_config.php',
    // Fallback
    __DIR__ . '/../../u630423897/maboximmo_openai_config.php',
];

$configLoaded = false;
$apiKey = null;

foreach ($configPaths as $path) {
    error_log("Checking config path: " . $path . " - exists: " . (file_exists($path) ? 'YES' : 'NO'));
    if (file_exists($path)) {
        @include $path;
        if (defined('OPENAI_API_KEY')) {
            $apiKey = OPENAI_API_KEY;
            $configLoaded = true;
            error_log("Config loaded from: " . $path);
            break;
        }
    }
}

if (!$apiKey) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'OpenAI API key not configured',
        'debug_config_paths' => $configPaths,
        'debug_apiKey' => $apiKey,
        'debug_configLoaded' => $configLoaded
    ]);
    exit;
}

// Use the configured model, fallback to gpt-3.5-turbo (most stable)
$model = (defined('OPENAI_TEXT_MODEL') && OPENAI_TEXT_MODEL && OPENAI_TEXT_MODEL !== 'gpt-5') ? OPENAI_TEXT_MODEL : 'gpt-3.5-turbo';
error_log('chatgpt_draft: Using model=' . $model);

$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['prompt'])) {
    echo json_encode(['success' => false, 'message' => 'Missing prompt']);
    exit;
}

$prompt = trim($data['prompt']);

// DEBUG MODE - Remove this in production
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';

try {
    $ch = curl_init('https://api.openai.com/v1/chat/completions');

    $isGpt5 = strpos($model, 'gpt-5') !== false;

    $requestBody = [
        'model'    => $model,
        'messages' => [
            [
                'role'    => 'system',
                'content' => 'Tu es un assistant RH qui rédige des mails professionnels en français. Tu réponds UNIQUEMENT en JSON valide avec deux clés : "sujet" (string, objet du mail, sans guillemets ni préfixe "Objet:") et "corps" (string, texte complet du mail, sans balises markdown).'
            ],
            [
                'role'    => 'user',
                'content' => $prompt
            ]
        ],
        'response_format' => ['type' => 'json_object'],
    ];

    if ($isGpt5) {
        $requestBody['max_completion_tokens'] = 1500;
    } else {
        $requestBody['max_tokens']   = 1500;
        $requestBody['temperature']  = 0.7;
    }

    // Chercher le bundle cacert.pem (XAMPP Windows)
    $cacertPaths = [
        'C:/xampp/php/extras/ssl/cacert.pem',
        'C:/xampp/apache/conf/ssl.crt/server.crt',
        dirname(__DIR__, 3) . '/php/extras/ssl/cacert.pem',
    ];
    $cacert = null;
    foreach ($cacertPaths as $p) { if (file_exists($p)) { $cacert = $p; break; } }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_POSTFIELDS       => json_encode($requestBody),
        CURLOPT_SSL_VERIFYPEER   => $cacert ? true : false,
        CURLOPT_SSL_VERIFYHOST   => $cacert ? 2 : 0,
        CURLOPT_CAINFO           => $cacert ?: null,
        CURLOPT_CONNECTTIMEOUT   => 15,
        CURLOPT_TIMEOUT          => 60,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    error_log('chatgpt_draft: HTTP code=' . $httpCode . ', response_length=' . strlen($response));

    if ($response === false || $error) {
        throw new Exception('OpenAI API request failed: ' . $error);
    }

    if ($httpCode !== 200) {
        error_log('chatgpt_draft: API returned error - ' . substr($response, 0, 500));
        throw new Exception('OpenAI API error: HTTP ' . $httpCode . ' - ' . substr($response, 0, 200));
    }

    $result = json_decode($response, true);

    // Check for OpenAI error in response
    if (isset($result['error'])) {
        throw new Exception('OpenAI error: ' . ($result['error']['message'] ?? 'Unknown error'));
    }

    if (!isset($result['choices'][0]['message']['content'])) {
        error_log('chatgpt_draft: Invalid response structure - ' . json_encode($result));
        throw new Exception('Invalid OpenAI response structure');
    }

    $content = trim($result['choices'][0]['message']['content']);
    $parsed  = json_decode($content, true);

    if (!$parsed) {
        // Fallback : GPT n'a pas renvoyé du JSON valide, on parse manuellement
        $lines  = explode("\n", $content);
        $sujet  = '';
        $bodyLines = [];
        $inBody = false;
        foreach ($lines as $line) {
            $t = trim($line);
            if (!$sujet && $t !== '') { $sujet = preg_replace('/^(objet\s*:\s*)/i', '', $t); continue; }
            if ($sujet) { if (!$inBody && $t === '') continue; $inBody = true; $bodyLines[] = $line; }
        }
        $corps = trim(implode("\n", $bodyLines));
    } else {
        // Chercher les clés même si GPT utilise subject/body/objet/message etc.
        $sujet = $parsed['sujet'] ?? $parsed['subject'] ?? $parsed['objet'] ?? $parsed['titre'] ?? '';
        $corps = $parsed['corps'] ?? $parsed['body'] ?? $parsed['contenu'] ?? $parsed['message'] ?? $parsed['content'] ?? '';
        $sujet = trim(preg_replace('/^(objet\s*:\s*)/i', '', $sujet));
        $corps = trim($corps);
    }

    if (empty($sujet) || empty($corps)) {
        error_log('chatgpt_draft: Raw content from AI = ' . $content);
        error_log('chatgpt_draft: Parsed JSON = ' . json_encode($parsed));
        error_log('chatgpt_draft: Final values - sujet="' . $sujet . '", corps="' . substr($corps, 0, 100) . '"');

        // Return detailed error to help debug
        throw new Exception('Réponse IA invalide — sujet: ' . ($sujet ? 'OK (' . strlen($sujet) . ' chars)' : 'EMPTY') . ', corps: ' . ($corps ? 'OK (' . strlen($corps) . ' chars)' : 'EMPTY'));
    }

    echo json_encode(['success' => true, 'sujet' => $sujet, 'corps' => $corps]);

} catch (Exception $e) {
    http_response_code(500);
    $message = $e->getMessage();
    error_log('chatgpt_draft EXCEPTION: ' . $message);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'debug_model' => $model ?? 'UNDEFINED',
        'debug_apiKey_set' => !empty($apiKey)
    ]);
}
?>
