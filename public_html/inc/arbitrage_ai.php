<?php
declare(strict_types=1);

/**
 * Génération IA de l'argumentaire d'arbitrage.
 * - Utilise l'infra OpenAI déjà présente dans le projet.
 * - Retourne un JSON structuré ; fallback vers templates si pas de clé.
 */

function arb_openai_chat_json(array $payload, string $apiKey): array
{
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT => 60,
    ]);

    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = $raw ? json_decode($raw, true) : null;
    return ['http' => $http, 'raw' => $raw ?: '', 'json' => is_array($decoded) ? $decoded : null];
}

function arb_generate_argumentaire_ai(array $input): array
{
    $apiKey = $GLOBALS['OPENAI_API_KEY'] ?? (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '');
    $model  = $GLOBALS['OPENAI_TEXT_MODEL'] ?? (defined('OPENAI_TEXT_MODEL') ? OPENAI_TEXT_MODEL : 'gpt-5');

    if (!$apiKey) {
        return ['ok' => false, 'error' => 'OPENAI_API_KEY non configurée.'];
    }

    $posture = (string)($input['posture'] ?? 'neutre');
    $postureRule = match ($posture) {
        'urgence_tresorerie' => "Ton direct, orienté liquidité et sécurisation du groupe. Prioriser la contribution trésorerie 18 mois.",
        'patrimoniale' => "Ton diplomatique et prudent, intégrant l'attachement et la conservation d'actifs solides si cohérent.",
        default => "Ton factuel, équilibré et mesuré.",
    };

    $factsJson = json_encode($input['facts'] ?? [], JSON_UNESCAPED_UNICODE);
    $commentaire = (string)($input['commentaire_emery'] ?? '');
    $conclusion  = (string)($input['conclusion_emery'] ?? '');

    $system = "Tu es un expert en arbitrage patrimonial immobilier (France), orienté décision et présentation client.\n"
        . "Tu dois produire un argumentaire utilisable en réunion, basé sur des chiffres et des signaux CRG.\n"
        . "Règles :\n"
        . "- {$postureRule}\n"
        . "- Toujours intégrer les chiffres clés fournis.\n"
        . "- Toujours intégrer les signaux CRG.\n"
        . "- Toujours intégrer le commentaire Emery s'il existe (et le laisser influencer fortement la conclusion).\n"
        . "- Ne jamais inventer de données absentes.\n"
        . "- Répondre uniquement en JSON valide.\n";

    $user = "Données factuelles (JSON) :\n{$factsJson}\n\n"
        . "Commentaire Emery (texte libre) :\n{$commentaire}\n\n"
        . "Conclusion Emery (courte) :\n{$conclusion}\n\n"
        . "Retour attendu (JSON) :\n"
        . "{\n"
        . "  \"synthese_auto\": \"...\",\n"
        . "  \"argumentaire_final\": \"...\",\n"
        . "  \"resume_client\": \"...\",\n"
        . "  \"bullets\": [\"...\",\"...\",\"...\"]\n"
        . "}\n";

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
        'temperature' => 0.3,
        'max_tokens' => 1400,
        'response_format' => ['type' => 'json_object'],
    ];

    $resp = arb_openai_chat_json($payload, (string)$apiKey);
    if ($resp['http'] !== 200 || !is_array($resp['json'])) {
        $msg = 'Erreur IA (HTTP ' . $resp['http'] . ').';
        if (is_array($resp['json']) && isset($resp['json']['error']['message'])) {
            $msg .= ' ' . (string)$resp['json']['error']['message'];
        }
        return ['ok' => false, 'error' => $msg, 'raw' => substr((string)$resp['raw'], 0, 800)];
    }

    $content = $resp['json']['choices'][0]['message']['content'] ?? '';
    if (!is_string($content) || trim($content) === '') {
        return ['ok' => false, 'error' => 'Réponse IA vide.', 'raw' => substr((string)$resp['raw'], 0, 800)];
    }

    $parsed = json_decode($content, true);
    if (!is_array($parsed)) {
        // Tentative extraction JSON
        if (preg_match('/\{[\s\S]*\}/u', $content, $m)) {
            $parsed = json_decode($m[0], true);
        }
    }

    if (!is_array($parsed)) {
        return ['ok' => false, 'error' => 'Réponse IA non-JSON.', 'raw' => substr($content, 0, 800)];
    }

    return ['ok' => true, 'data' => $parsed, 'model' => $model];
}

