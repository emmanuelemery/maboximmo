<?php
declare(strict_types=1);

/**
 * GED MaBoxImmo — Vision IA (Claude Sonnet 4.6 principal, GPT-4o fallback).
 * Fichier : modules/ged/ged_vision.php
 *
 * Envoie une image (ou page PDF rendue en PNG) au modèle vision et récupère
 * un JSON structuré conforme au schéma demandé.
 *
 * Anti-hallucination : modèles configurés temperature=0, prompt système
 * exigeant explicitement null > devinette, scoring par champ.
 *
 * Bascule auto sur GPT-4o si ANTHROPIC_API_KEY indisponible ou si appel Claude échoue.
 */

require_once __DIR__ . '/../../inc/ged_ai_models.php';

/**
 * Appel vision IA primaire (Claude Sonnet 4.6) avec fallback GPT-4o.
 *
 * @param string $imagePath  Chemin local d'une image (PNG/JPG/WEBP) ou PDF (auto-converti page 1).
 * @param string $systemPrompt
 * @param string $userPrompt  Doit contenir le mot 'JSON' pour le mode strict.
 * @return array{data:array<string,mixed>, model_used:string} JSON parsé + model effectivement utilisé.
 * @throws RuntimeException si les deux providers échouent.
 */
function gedVisionExtract(string $imagePath, string $systemPrompt, string $userPrompt): array
{
    if (!is_file($imagePath)) {
        throw new InvalidArgumentException("Fichier vision introuvable : {$imagePath}");
    }

    // Si PDF : on convertit la page 1 en PNG temporaire
    $cleanup = null;
    $mime = ged_vision_mime($imagePath);
    if ($mime === 'application/pdf') {
        require_once __DIR__ . '/ged_pdf_text.php';
        $tmpPng = gedPdfFirstPageToPng($imagePath);
        if ($tmpPng === null) {
            throw new RuntimeException('Conversion PDF→PNG impossible (Imagick/Ghostscript absents).');
        }
        $imagePath = $tmpPng;
        $mime      = 'image/png';
        $cleanup   = $tmpPng;
    }

    try {
        // 1) Tentative Claude Sonnet 4.6
        $anthropicKey = ged_vision_anthropic_key();
        if ($anthropicKey !== '') {
            try {
                $data = ged_vision_call_claude($anthropicKey, $imagePath, $mime, $systemPrompt, $userPrompt);
                return ['data' => $data, 'model_used' => GED_MODEL_EXTRACTION];
            } catch (Throwable $e) {
                error_log('[ged_vision] Claude vision KO, fallback GPT-4o : ' . $e->getMessage());
            }
        }

        // 2) Fallback GPT-4o
        $openaiKey = ged_vision_openai_key();
        if ($openaiKey === '') {
            throw new RuntimeException('Aucune clé IA disponible (ANTHROPIC_API_KEY ni OPENAI_API_KEY).');
        }
        $data = ged_vision_call_openai($openaiKey, $imagePath, $mime, $systemPrompt, $userPrompt);
        return ['data' => $data, 'model_used' => GED_MODEL_FALLBACK];

    } finally {
        if ($cleanup !== null) @unlink($cleanup);
    }
}

// ── Providers ────────────────────────────────────────────────────────────────

function ged_vision_call_claude(string $apiKey, string $imgPath, string $mime, string $sys, string $usr): array
{
    $b64 = base64_encode((string)file_get_contents($imgPath));
    $payload = [
        'model'      => GED_MODEL_EXTRACTION,
        'max_tokens' => 2000,
        'temperature'=> 0.0,
        'system'     => $sys,
        'messages'   => [[
            'role' => 'user',
            'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64]],
                ['type' => 'text',  'text' => $usr],
            ],
        ]],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'content-type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 120,
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) throw new RuntimeException("Claude cURL: {$err}");
    if ($code !== 200) {
        $body = json_decode((string)$raw, true);
        $msg  = $body['error']['message'] ?? substr((string)$raw, 0, 400);
        throw new RuntimeException("Claude HTTP {$code}: {$msg}");
    }
    $body = json_decode((string)$raw, true);
    $text = $body['content'][0]['text'] ?? null;
    if (!is_string($text)) {
        throw new RuntimeException('Claude response missing content[].text');
    }
    return ged_vision_extract_json($text);
}

function ged_vision_call_openai(string $apiKey, string $imgPath, string $mime, string $sys, string $usr): array
{
    $b64 = base64_encode((string)file_get_contents($imgPath));
    $dataUrl = "data:{$mime};base64,{$b64}";

    $payload = [
        'model'           => GED_MODEL_FALLBACK,
        'response_format' => ['type' => 'json_object'],
        'temperature'     => 0.0,
        'max_tokens'      => 2000,
        'messages'        => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' => [
                ['type' => 'text',      'text' => $usr],
                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
            ]],
        ],
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 120,
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($raw === false) throw new RuntimeException("OpenAI cURL: {$err}");
    if ($code !== 200) {
        $body = json_decode((string)$raw, true);
        $msg  = $body['error']['message'] ?? substr((string)$raw, 0, 400);
        throw new RuntimeException("OpenAI HTTP {$code}: {$msg}");
    }
    $body = json_decode((string)$raw, true);
    $text = $body['choices'][0]['message']['content'] ?? null;
    if (!is_string($text)) {
        throw new RuntimeException('OpenAI response missing content');
    }
    return ged_vision_extract_json($text);
}

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Extrait le bloc JSON d'une réponse texte (Claude n'a pas de json_mode strict,
 * donc on pèle les ```json fences ou on isole le 1er bloc {...}).
 */
function ged_vision_extract_json(string $text): array
{
    $t = trim($text);
    // Strip ```json / ```
    if (preg_match('/^```(?:json)?\s*(.*)```$/s', $t, $m)) {
        $t = trim($m[1]);
    }
    $j = json_decode($t, true);
    if (is_array($j)) return $j;

    // Tentative : isoler le premier bloc JSON équilibré
    if (preg_match('/\{.*\}/s', $t, $m)) {
        $j = json_decode($m[0], true);
        if (is_array($j)) return $j;
    }
    throw new RuntimeException('Vision IA : réponse non-JSON');
}

function ged_vision_mime(string $path): string
{
    if (function_exists('finfo_open')) {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        if ($f) {
            $m = finfo_file($f, $path);
            finfo_close($f);
            if (is_string($m) && $m !== '') return $m;
        }
    }
    $ext = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
    return match ($ext) {
        'png'         => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'webp'        => 'image/webp',
        'gif'         => 'image/gif',
        'pdf'         => 'application/pdf',
        default       => 'application/octet-stream',
    };
}

function ged_vision_anthropic_key(): string
{
    $k = $GLOBALS['ANTHROPIC_API_KEY'] ?? '';
    if (is_string($k) && $k !== '') return $k;
    if (defined('ANTHROPIC_API_KEY')) return (string)ANTHROPIC_API_KEY;
    return (string)(getenv('ANTHROPIC_API_KEY') ?: '');
}

function ged_vision_openai_key(): string
{
    $k = $GLOBALS['OPENAI_API_KEY'] ?? '';
    if (is_string($k) && $k !== '') return $k;
    if (defined('OPENAI_API_KEY')) return (string)OPENAI_API_KEY;
    return (string)(getenv('OPENAI_API_KEY') ?: '');
}
