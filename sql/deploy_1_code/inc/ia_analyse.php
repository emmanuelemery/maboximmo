<?php
// inc/ia_analyse.php — Extraction texte PDF + analyse IA via Claude API
// Utilisé par agency_analyse_doc.php

/**
 * Extrait le texte brut d'un fichier PDF.
 * Essaie dans l'ordre : pdftotext (cli), smalot/pdfparser (composer), extraction regex basique.
 */
function extractPdfText(string $filepath): string
{
    if (!file_exists($filepath)) return '';

    // 1. pdftotext (poppler-utils). Cherche dans des chemins connus pour
    // contourner le PATH système qui n'est pas toujours hérité par PHP/XAMPP.
    if (function_exists('shell_exec') && !in_array('shell_exec', explode(',', ini_get('disable_functions')))) {
        $pdftotextCandidates = [
            'pdftotext',                               // PATH (Linux prod, Docker)
            'C:\\poppler\\Library\\bin\\pdftotext.exe',// XAMPP Windows Anaconda-style
            'C:\\Program Files\\poppler\\bin\\pdftotext.exe',
            'C:\\poppler\\bin\\pdftotext.exe',
        ];
        $escaped = escapeshellarg($filepath);
        foreach ($pdftotextCandidates as $bin) {
            // Sur Windows : ne tenter que si le fichier existe (évite un
            // shell_exec lent qui cherche dans le PATH). Sur Linux, on laisse
            // "pdftotext" sans chemin absolu être testé en premier.
            if ($bin !== 'pdftotext' && !is_file($bin)) continue;
            // -enc UTF-8 force le charset (sinon pdftotext sort en Latin-1 sur Windows)
            $cmd = (PHP_OS_FAMILY === 'Windows' ? '"' . $bin . '"' : $bin)
                 . " -layout -enc UTF-8 $escaped - 2>" . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
            $out = @shell_exec($cmd);
            if ($out && strlen(trim($out)) > 20) {
                // Garantit UTF-8 valide (au cas où pdftotext sort autre chose)
                if (!mb_check_encoding($out, 'UTF-8')) {
                    $out = mb_convert_encoding($out, 'UTF-8', 'Windows-1252');
                }
                return $out;
            }
        }
    }

    // 2. smalot/pdfparser via Composer
    $parserPath = __DIR__ . '/../../vendor/smalot/pdfparser/src/Smalot/PdfParser/Parser.php';
    if (file_exists($parserPath)) {
        try {
            require_once __DIR__ . '/../../vendor/autoload.php';
            $parser = new \Smalot\PdfParser\Parser();
            $pdf    = $parser->parseFile($filepath);
            $text   = $pdf->getText();
            if ($text && strlen(trim($text)) > 20) return $text;
        } catch (\Throwable $e) {
            error_log('[ia_analyse] pdfparser error: ' . $e->getMessage());
        }
    }

    // 3. Extraction regex basique sur le flux PDF brut (fallback dégradé)
    $raw  = file_get_contents($filepath);
    $text = '';
    preg_match_all('/BT\s*(.*?)\s*ET/s', $raw, $matches);
    foreach ($matches[1] as $block) {
        preg_match_all('/\((.*?)\)\s*Tj/s', $block, $strings);
        foreach ($strings[1] as $s) {
            $text .= $s . ' ';
        }
    }
    return trim($text);
}

/**
 * Envoie le texte à l'API OpenAI (ChatGPT) et retourne un résumé structuré JSON.
 *
 * @param string $text        Texte brut extrait du PDF
 * @param string $type_doc    'pv_ag' | 'convocation_ag' | autre
 * @param string $immeuble    Nom de l'immeuble (contexte)
 * @return array  ['ok'=>bool, 'summary'=>array|null, 'error'=>string]
 */
function analyseDocumentIA(string $text, string $type_doc, string $immeuble = ''): array
{
    // Récupère la clé depuis la constante ou la variable globale chargée par bootstrap
    $api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
    $model   = defined('OPENAI_TEXT_MODEL') ? OPENAI_TEXT_MODEL : ($GLOBALS['OPENAI_TEXT_MODEL'] ?? 'gpt-4o');

    if (!$api_key) {
        return ['ok' => false, 'summary' => null, 'error' => 'Clé API OpenAI non configurée (OPENAI_API_KEY).'];
    }

    if (strlen(trim($text)) < 50) {
        return ['ok' => false, 'summary' => null, 'error' => "Impossible d'extraire le texte du document. Vérifiez que le PDF n'est pas scanné sans OCR."];
    }

    // Tronquer à ~12 000 tokens max (~50 000 caractères)
    $text_truncated = mb_substr($text, 0, 50000);

    $type_label = match($type_doc) {
        'pv_ag'               => "procès-verbal d'assemblée générale de copropriété",
        'convocation_ag'      => "convocation à une assemblée générale de copropriété",
        'budget_previsionnel' => "budget prévisionnel de copropriété",
        'releve_charges'      => "relevé de charges de copropriété",
        default               => "document de copropriété",
    };

    $system_prompt = "Tu es un assistant expert en gestion de copropriété française (syndic). "
        . "Tu analyses des documents de copropriété et produis des résumés structurés en JSON. "
        . "Tu réponds UNIQUEMENT avec du JSON valide, sans texte avant ni après.";

    $user_prompt = <<<PROMPT
Analyse ce {$type_label} concernant l'immeuble "{$immeuble}" et fournis un résumé structuré destiné à être envoyé aux copropriétaires.

Réponds UNIQUEMENT en JSON valide avec cette structure exacte :
{
  "type_document": "string",
  "date_document": "string (format dd/mm/yyyy ou vide si absent)",
  "immeuble": "string",
  "resume_general": "string (2-4 phrases, langage clair et accessible pour un propriétaire non-expert)",
  "points_cles": ["string", ...],
  "resolutions": [
    {"numero": "string", "objet": "string", "resultat": "ADOPTEE|REJETEE|REPORTEE|INFO", "votes": "string optionnel ex: 15 pour, 2 contre"}
  ],
  "decisions_importantes": ["string", ...],
  "montants": [
    {"libelle": "string", "montant": "string ex: 1 200,00 €"}
  ],
  "prochaines_echeances": ["string", ...],
  "message_proprietaire": "string (court paragraphe chaleureux et synthétique pour le courrier au propriétaire, en vouvoiement)"
}

Règles :
- Si un champ n'a pas d'information, utilise un tableau vide [] ou une chaîne vide ""
- Pour "resolutions", inclure uniquement les points mis au vote (pas les simples informations)
- "message_proprietaire" doit être rédigé comme un extrait de lettre professionnelle

TEXTE DU DOCUMENT :
---
{$text_truncated}
---
PROMPT;

    $payload = json_encode([
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user',   'content' => $user_prompt],
        ],
        'max_tokens'  => 2048,
        'temperature' => 0.2,  // réponse factuelle et stable
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
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['ok' => false, 'summary' => null, 'error' => 'Erreur réseau : ' . $curl_error];
    }

    $data = json_decode($response, true);

    if ($http_code !== 200) {
        $msg = $data['error']['message'] ?? "HTTP $http_code";
        return ['ok' => false, 'summary' => null, 'error' => 'Erreur API OpenAI : ' . $msg];
    }

    $content = $data['choices'][0]['message']['content'] ?? '';

    // `response_format: json_object` garantit du JSON pur, mais on sécurise quand même
    $summary = json_decode($content, true);
    if ($summary) {
        return ['ok' => true, 'summary' => $summary, 'error' => ''];
    }

    // Fallback : extraire le JSON si du texte parasite entoure la réponse
    if (preg_match('/\{[\s\S]+\}/m', $content, $jsonMatch)) {
        $summary = json_decode($jsonMatch[0], true);
        if ($summary) {
            return ['ok' => true, 'summary' => $summary, 'error' => ''];
        }
    }

    error_log('[ia_analyse] Réponse brute OpenAI : ' . $content);
    return ['ok' => false, 'summary' => null, 'error' => 'Réponse IA invalide — impossible de parser le JSON.'];
}
