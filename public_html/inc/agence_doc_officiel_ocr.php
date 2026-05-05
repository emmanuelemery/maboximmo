<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * inc/agence_doc_officiel_ocr.php — OCR Claude Sonnet pour docs officiels
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Extrait les champs métier d'un document officiel d'agence (carte pro,
 * KBIS, garant, RC pro, barème) via Claude Sonnet 4.6 vision/document.
 *
 * Le user a validé Sonnet pour ces docs car :
 *   - peu fréquent (1 upload tous les ~3 ans par doc)
 *   - critique (alimente la conformité de toutes les annonces)
 *   - le coût Sonnet (~3-5¢/doc) est négligeable face à l'enjeu légal
 *
 * Pour les analyses fréquentes (rédaction d'affiches, score) on reste sur
 * Haiku, voir mbi_supports_score_ia.php / mbi_supports_redaction_ia.php.
 *
 * API publique :
 *   agence_doc_ocr_extraire(string $cheminAbsolu, string $typeDoc): array
 *
 *   Renvoie : {
 *     ok:bool,
 *     data: ?array,       // champs extraits (numero, emetteur, dates, …)
 *     modele:string,
 *     cout_centimes:int,
 *     confidence:int,     // 0-100
 *     raw_json:?array,    // snapshot pour audit
 *     erreur:?string,
 *   }
 *
 * Types pris en charge : carte_pro, kbis, garant_financier, rc_pro,
 * bareme_honoraires.
 * ═══════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/mbi_supports_score_ia.php'; // helpers Anthropic réutilisés

if (!defined('AGENCE_DOC_OCR_MODEL')) {
    define('AGENCE_DOC_OCR_MODEL', 'claude-sonnet-4-6');
}

if (!function_exists('agence_doc_ocr_extraire')) {

    function agence_doc_ocr_extraire(string $cheminAbsolu, string $typeDoc): array
    {
        $modele = AGENCE_DOC_OCR_MODEL;

        if (!is_file($cheminAbsolu) || !is_readable($cheminAbsolu)) {
            return ['ok'=>false,'data'=>null,'modele'=>$modele,'cout_centimes'=>0,'confidence'=>0,'raw_json'=>null,'erreur'=>'fichier_introuvable'];
        }

        $apiKey = mbi_supports_ia_anthropic_key();
        if ($apiKey === '') {
            return ['ok'=>false,'data'=>null,'modele'=>$modele,'cout_centimes'=>0,'confidence'=>0,'raw_json'=>null,'erreur'=>'no_api_key'];
        }

        $mime = mime_content_type($cheminAbsolu) ?: 'application/octet-stream';
        $b64  = base64_encode((string)file_get_contents($cheminAbsolu));

        // Bloc media : Claude accepte image/* et application/pdf via content type document
        $mediaBlock = match (true) {
            str_starts_with($mime, 'image/') => [
                'type' => 'image',
                'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64],
            ],
            $mime === 'application/pdf' => [
                'type' => 'document',
                'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $b64],
            ],
            default => null,
        };
        if ($mediaBlock === null) {
            return ['ok'=>false,'data'=>null,'modele'=>$modele,'cout_centimes'=>0,'confidence'=>0,'raw_json'=>null,'erreur'=>'mime_non_supporte: ' . $mime];
        }

        $sys = agence_doc_ocr_system_prompt();
        $usr = agence_doc_ocr_user_prompt($typeDoc);

        $payload = [
            'model'       => $modele,
            'max_tokens'  => 1200,
            'temperature' => 0.0,
            'system'      => $sys,
            'messages'    => [[
                'role' => 'user',
                'content' => [
                    $mediaBlock,
                    ['type' => 'text', 'text' => $usr],
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
                'anthropic-beta: pdfs-2024-09-25',
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 90,
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $code !== 200) {
            return ['ok'=>false,'data'=>null,'modele'=>$modele,'cout_centimes'=>0,'confidence'=>0,'raw_json'=>null,
                    'erreur'=>"anthropic_http_{$code}: " . ($err ?: substr((string)$raw, 0, 300))];
        }

        $body = json_decode((string)$raw, true);
        $text = $body['content'][0]['text'] ?? '';
        if (!is_string($text) || $text === '') {
            return ['ok'=>false,'data'=>null,'modele'=>$modele,'cout_centimes'=>0,'confidence'=>0,'raw_json'=>$body,'erreur'=>'reponse_vide'];
        }

        $data = mbi_supports_ia_extract_json($text);
        if ($data === null) {
            return ['ok'=>false,'data'=>null,'modele'=>$modele,
                    'cout_centimes'=>mbi_supports_ia_estimer_cout($body),
                    'confidence'=>0,'raw_json'=>$body,'erreur'=>'json_invalide'];
        }

        $normalised = agence_doc_ocr_normalize($data, $typeDoc);

        return [
            'ok'            => true,
            'data'          => $normalised,
            'modele'        => $modele,
            'cout_centimes' => mbi_supports_ia_estimer_cout($body),
            'confidence'    => (int)($normalised['confidence'] ?? 0),
            'raw_json'      => $body,
            'erreur'        => null,
        ];
    }
}

if (!function_exists('agence_doc_ocr_system_prompt')) {
    function agence_doc_ocr_system_prompt(): string
    {
        return implode("\n", [
            "Tu es un assistant d'extraction de données pour des documents officiels d'agence immobilière française.",
            "Tu lis le document fourni (image ou PDF) et tu extrais UNIQUEMENT les champs structurés demandés.",
            "",
            "RÈGLES :",
            "- Tu ne réponds JAMAIS hors du JSON demandé. Pas de markdown, pas de fence ```, pas de texte autour.",
            "- Si un champ est absent ou illisible, mets sa valeur à null.",
            "- Les dates DOIVENT être au format ISO YYYY-MM-DD (ou null).",
            "- Les nombres (montants en €) sont des nombres décimaux, sans symbole € ni séparateur de milliers.",
            "- Confidence (0-100) reflète ta certitude globale sur l'extraction (lisibilité du document, complétude).",
        ]);
    }
}

if (!function_exists('agence_doc_ocr_user_prompt')) {
    function agence_doc_ocr_user_prompt(string $typeDoc): string
    {
        $schemas = [
            'carte_pro' => [
                "Document : CARTE PROFESSIONNELLE d'un agent immobilier (carte T transaction et/ou G gestion).",
                'Schéma JSON :',
                '{',
                '  "numero":         "string|null",     // ex: CPI 6901 2018 000 012 345',
                '  "type_carte":     "T|G|TG|null",     // T=transaction, G=gestion, TG=les deux',
                '  "titulaire":      "string|null",     // raison sociale ou nom du titulaire',
                '  "emetteur":       "string|null",     // CCI émettrice (ex: CCI Lyon Métropole)',
                '  "date_emission":  "YYYY-MM-DD|null",',
                '  "date_validite":  "YYYY-MM-DD|null", // date de fin de validité (importante !)',
                '  "confidence":     int                // 0-100',
                '}',
            ],
            'kbis' => [
                "Document : EXTRAIT KBIS d'une société (registre du commerce).",
                'Schéma JSON :',
                '{',
                '  "numero":         "string|null",     // n° RCS (ex: 123 456 789)',
                '  "raison_sociale": "string|null",',
                '  "emetteur":       "string|null",     // greffe du tribunal',
                '  "date_emission":  "YYYY-MM-DD|null", // date d\'émission de l\'extrait (≤ 3 mois recommandé)',
                '  "date_validite":  null,              // KBIS n\'a pas de date de fin officielle',
                '  "confidence":     int',
                '}',
            ],
            'garant_financier' => [
                "Document : ATTESTATION DE GARANTIE FINANCIÈRE (Galian, Socaf, etc.).",
                'Schéma JSON :',
                '{',
                '  "numero":         "string|null",     // n° de contrat / police',
                '  "emetteur":       "string|null",     // nom du garant (Galian, Socaf, …)',
                '  "montant_garantie":number|null,      // plafond en €',
                '  "date_emission":  "YYYY-MM-DD|null",',
                '  "date_validite":  "YYYY-MM-DD|null", // fin de validité de la garantie',
                '  "confidence":     int',
                '}',
            ],
            'rc_pro' => [
                "Document : ATTESTATION D'ASSURANCE RESPONSABILITÉ CIVILE PROFESSIONNELLE.",
                'Schéma JSON :',
                '{',
                '  "numero":         "string|null",     // n° de contrat',
                '  "emetteur":       "string|null",     // assureur (MMA, AXA, Allianz, …)',
                '  "date_emission":  "YYYY-MM-DD|null",',
                '  "date_validite":  "YYYY-MM-DD|null", // fin de validité',
                '  "confidence":     int',
                '}',
            ],
            'bareme_honoraires' => [
                "Document : BARÈME DES HONORAIRES affiché en agence (loi Hoguet, arrêté du 10/01/2017).",
                'Schéma JSON :',
                '{',
                '  "numero":         null,',
                '  "emetteur":       "string|null",     // nom de l\'agence',
                '  "date_emission":  "YYYY-MM-DD|null", // date de mise à jour du barème',
                '  "date_validite":  null,              // pas d\'expiration stricte',
                '  "confidence":     int',
                '}',
            ],
        ];

        if (!isset($schemas[$typeDoc])) {
            return "Type de document inconnu. Renvoie {\"confidence\":0}.";
        }
        return implode("\n", $schemas[$typeDoc]);
    }
}

if (!function_exists('agence_doc_ocr_normalize')) {
    function agence_doc_ocr_normalize(array $data, string $typeDoc): array
    {
        $isoDate = static function ($v): ?string {
            if (!is_string($v) || $v === '' || $v === 'null') return null;
            // Tolère YYYY-MM-DD strict
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        };
        $strOrNull = static function ($v): ?string {
            if (!is_string($v)) return null;
            $v = trim($v);
            return ($v === '' || $v === 'null') ? null : mb_substr($v, 0, 250);
        };
        $numOrNull = static function ($v): ?float {
            if (is_numeric($v)) return (float)$v;
            return null;
        };

        return [
            'numero'           => $strOrNull($data['numero']           ?? null),
            'type_carte'       => $strOrNull($data['type_carte']       ?? null),
            'titulaire'        => $strOrNull($data['titulaire']        ?? null),
            'raison_sociale'   => $strOrNull($data['raison_sociale']   ?? null),
            'emetteur'         => $strOrNull($data['emetteur']         ?? null),
            'date_emission'    => $isoDate ($data['date_emission']    ?? null),
            'date_validite'    => $isoDate ($data['date_validite']    ?? null),
            'montant_garantie' => $numOrNull($data['montant_garantie'] ?? null),
            'confidence'       => max(0, min(100, (int)($data['confidence'] ?? 0))),
        ];
    }
}
