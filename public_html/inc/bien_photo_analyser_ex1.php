<?php
declare(strict_types=1);

/**
 * Analyse une photo de bien via GPT-4o Vision et retourne :
 *   - une description courte (~25 mots) de ce qui est visible
 *   - une catégorie ('exterieur'|'salon'|'cuisine'|'chambre'|'sdb'|'autre')
 *
 * Utilisé par le drag & drop photos de bien_intake.php pour amorcer
 * automatiquement le descriptif commercial du bien.
 */
function analyserPhotoBien(string $imagePath): array
{
    $api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
    if (!$api_key) {
        return ['ok' => false, 'error' => 'OPENAI_API_KEY non configurée'];
    }
    if (!is_readable($imagePath)) {
        return ['ok' => false, 'error' => 'Image illisible'];
    }

    // Encodage base64 + détection MIME
    $mime = 'image/jpeg';
    $info = @getimagesize($imagePath);
    if ($info && !empty($info['mime'])) $mime = $info['mime'];
    $b64 = base64_encode((string)file_get_contents($imagePath));
    $dataUri = 'data:' . $mime . ';base64,' . $b64;

    $prompt = <<<PROMPT
Tu analyses la photo d'un bien immobilier français pour aider l'agent à rédiger une annonce.
Réponds UNIQUEMENT en JSON valide :
{
  "categorie": "exterieur|salon|cuisine|chambre|salle_de_bain|wc|entree|couloir|terrasse|jardin|garage|cave|vue|plan|autre",
  "description": "phrase courte (15-30 mots) décrivant CE QUI EST RÉELLEMENT VISIBLE : matériaux, luminosité, style, équipements visibles. Style commercial sobre, en français. Pas d'invention."
}
PROMPT;

    $payload = json_encode([
        'model' => 'gpt-4o-mini',
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $dataUri, 'detail' => 'low']],
                ],
            ],
        ],
        'max_tokens' => 200,
        'temperature' => 0.3,
        'response_format' => ['type' => 'json_object'],
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err)              return ['ok' => false, 'error' => 'Réseau : ' . $err];
    if ($code !== 200)     return ['ok' => false, 'error' => 'HTTP ' . $code];

    $data = json_decode((string)$resp, true);
    $content = $data['choices'][0]['message']['content'] ?? '';
    $parsed = json_decode($content, true);
    if (!is_array($parsed)) {
        return ['ok' => false, 'error' => 'JSON IA non parsable'];
    }

    return [
        'ok'          => true,
        'categorie'   => (string)($parsed['categorie']   ?? 'autre'),
        'description' => trim((string)($parsed['description'] ?? '')),
    ];
}
