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

// Load Anthropic config
$anthropicKey = null;

// Try to load from environment or config
if (getenv('ANTHROPIC_API_KEY')) {
    $anthropicKey = getenv('ANTHROPIC_API_KEY');
} else {
    // Try loading from config file
    $configPaths = [
        dirname(__DIR__, 2) . '/u630423897/maboximmo_openai_config.php',
        dirname(__DIR__, 2) . '/u630423897/openai_config.php',
    ];

    foreach ($configPaths as $path) {
        if (file_exists($path)) {
            @include $path;
            if (defined('ANTHROPIC_API_KEY')) {
                $anthropicKey = ANTHROPIC_API_KEY;
                break;
            }
        }
    }
}

if (!$anthropicKey) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Anthropic API key not configured. Please set ANTHROPIC_API_KEY environment variable.',
    ]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['prompt'])) {
    echo json_encode(['success' => false, 'message' => 'Missing prompt']);
    exit;
}

$prompt = trim($data['prompt']);

try {
    $ch = curl_init('https://api.anthropic.com/v1/messages');

    $requestBody = [
        'model'       => 'claude-3-5-sonnet-20241022',
        'max_tokens'  => 1500,
        'system'      => 'Tu es un assistant RH qui rédige des mails professionnels en français. Tu réponds UNIQUEMENT en JSON valide avec deux clés : "sujet" (string, objet du mail, sans guillemets ni préfixe "Objet:") et "corps" (string, texte complet du mail, sans balises markdown).',
        'messages'    => [
            [
                'role'    => 'user',
                'content' => $prompt
            ]
        ]
    ];

    // Find SSL cert for Windows XAMPP
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
            'x-api-key: ' . $anthropicKey,
            'anthropic-version: 2023-06-01'
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

    error_log('claude_draft: HTTP code=' . $httpCode . ', response_length=' . strlen($response));

    if ($response === false || $error) {
        throw new Exception('Anthropic API request failed: ' . $error);
    }

    if ($httpCode !== 200) {
        error_log('claude_draft: API returned error - ' . substr($response, 0, 500));
        throw new Exception('Anthropic API error: HTTP ' . $httpCode);
    }

    $result = json_decode($response, true);

    // Check for Anthropic error in response
    if (isset($result['error'])) {
        throw new Exception('Anthropic error: ' . ($result['error']['message'] ?? 'Unknown error'));
    }

    if (!isset($result['content'][0]['text'])) {
        error_log('claude_draft: Invalid response structure - ' . json_encode($result));
        throw new Exception('Invalid Anthropic response structure');
    }

    $content = trim($result['content'][0]['text']);
    $parsed  = json_decode($content, true);

    if (!$parsed) {
        // Fallback : Claude didn't return valid JSON, try manual parsing
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
        // Look for keys even if Claude uses subject/body/objet/message etc.
        $sujet = $parsed['sujet'] ?? $parsed['subject'] ?? $parsed['objet'] ?? $parsed['titre'] ?? '';
        $corps = $parsed['corps'] ?? $parsed['body'] ?? $parsed['contenu'] ?? $parsed['message'] ?? $parsed['content'] ?? '';
        $sujet = trim(preg_replace('/^(objet\s*:\s*)/i', '', $sujet));
        $corps = trim($corps);
    }

    if (empty($sujet) || empty($corps)) {
        error_log('claude_draft: Raw content from AI = ' . $content);
        error_log('claude_draft: Parsed JSON = ' . json_encode($parsed));
        error_log('claude_draft: Final values - sujet="' . $sujet . '", corps="' . substr($corps, 0, 100) . '"');
        throw new Exception('Réponse IA invalide — sujet: ' . ($sujet ? 'OK (' . strlen($sujet) . ' chars)' : 'EMPTY') . ', corps: ' . ($corps ? 'OK (' . strlen($corps) . ' chars)' : 'EMPTY'));
    }

    echo json_encode(['success' => true, 'sujet' => $sujet, 'corps' => $corps]);

} catch (Exception $e) {
    http_response_code(500);
    $message = $e->getMessage();
    error_log('claude_draft EXCEPTION: ' . $message);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'provider' => 'Anthropic Claude'
    ]);
}
?>
