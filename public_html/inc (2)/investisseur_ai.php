<?php
declare(strict_types=1);

/**
 * inc/investisseur_ai.php
 *
 * Helper IA générique pour le module Investisseur — wrapper OpenAI mince.
 *
 * Ne contient PAS de logique texte déterministe (celle-ci est dans
 * investisseur_interpretations.php qui fait déjà le travail sans IA).
 *
 * Utilisé uniquement par les futurs cas d'usage où l'IA apporte vraiment :
 *   - Chatbot propriétaire sur page publique (en contexte riche)
 *   - Analyse transversale de portefeuille (détection anomalies / concentration)
 *   - Détection risques réglementaires (DPE F/G, bail à échéance, etc.)
 *
 * Aucune de ces fonctionnalités n'est branchée dans l'UI actuelle.
 * Ce fichier sert d'infrastructure commune quand tu décideras de les activer.
 */

if (!function_exists('inv_ai_chat')) {
    /**
     * Appel OpenAI Chat Completions minimal.
     *
     * @param string $system        Directive système (rôle du modèle)
     * @param string $user          Message utilisateur (contexte + question)
     * @param bool   $json          Si true, force une réponse JSON
     * @param float  $temperature   0-1 (0.3 recommandé pour factuel)
     * @return array ['ok' => bool, 'text' => ?string, 'json' => ?array, 'error' => ?string]
     */
    function inv_ai_chat(string $system, string $user, bool $json = false, float $temperature = 0.3): array {
        $apiKey = $GLOBALS['OPENAI_API_KEY'] ?? (defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '');
        $model  = $GLOBALS['OPENAI_TEXT_MODEL'] ?? (defined('OPENAI_TEXT_MODEL') ? OPENAI_TEXT_MODEL : 'gpt-5');
        if (!$apiKey) return ['ok' => false, 'error' => 'OPENAI_API_KEY absente'];

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $user],
            ],
            'temperature' => $temperature,
            'max_completion_tokens' => 1800,
        ];
        if ($json) $payload['response_format'] = ['type' => 'json_object'];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_TIMEOUT => 90,
        ]);
        $raw  = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($http !== 200) {
            $msg = $err ?: ('HTTP ' . $http);
            $dec = $raw ? json_decode($raw, true) : null;
            if (is_array($dec) && !empty($dec['error']['message'])) $msg = $dec['error']['message'];
            return ['ok' => false, 'error' => $msg];
        }

        $dec = json_decode((string)$raw, true);
        $text = $dec['choices'][0]['message']['content'] ?? '';
        if (!$json) return ['ok' => true, 'text' => $text];

        $parsed = json_decode($text, true);
        if (!is_array($parsed)) return ['ok' => false, 'error' => 'Réponse non-JSON'];
        return ['ok' => true, 'json' => $parsed, 'text' => $text];
    }
}
