<?php
declare(strict_types=1);

/**
 * FluxBox — extraction Vision pour le nommage Variante A
 *
 * Lit un document (PDF / image) et extrait les segments nécessaires au format :
 *   {societe}_{agence}_{user}_{JJMMAA}_{n1}_{n2}_{banque}_{compte4}_{MM-AAAA}_{immeuble}_{type}.ext
 *
 * Les segments societe/agence/user/JJMMAA/n1/n2 viennent du contexte
 * applicatif (session + arborescence GED) — pas du document lui-même.
 * Vision n'extrait que les segments DOCUMENT : banque, compte, période,
 * immeuble (si présent), type document.
 *
 * Sécurité bancaire : on demande à Vision le n° de compte complet, mais on
 * ne stocke que les 4 derniers chiffres (compte4) — Vision peut renvoyer
 * l'IBAN entier, le post-traitement le tronque (fluxbox_va_extract_compte4).
 *
 * Modèle par défaut : Sonnet 4.6 (cohérent avec [[feedback_choix_modele_ia]]
 * pour les analyses critiques rares). Override possible via $modele.
 *
 * API publique :
 *   fluxbox_vision_extract_variant_a(string $cheminAbsolu, ?string $modele = null): array
 *     → {ok, extracted:{banque_text, compte_text, periode_text, immeuble_text, type_text, ...},
 *        modele, cout_centimes, confidence, raw_json, erreur}
 *
 * Réutilise les helpers cross-env de inc/agence_doc_officiel_ocr.php :
 *   - agence_doc_ocr_resolve_local_or_fetch() : télécharge depuis dev/prod si fichier absent en local
 *   - mbi_supports_ia_anthropic_key()         : lecture clé API
 *   - mbi_supports_ia_extract_json()          : parse JSON tolérant
 *   - mbi_supports_ia_estimer_cout()          : coût en centimes
 */

require_once __DIR__ . '/agence_doc_officiel_ocr.php';

if (!defined('FLUXBOX_VA_VISION_MODEL')) {
    define('FLUXBOX_VA_VISION_MODEL', 'claude-sonnet-4-6');
}

if (!function_exists('fluxbox_vision_extract_variant_a')) {
    /**
     * @param string      $cheminAbsolu  Chemin PDF/image
     * @param string|null $modele        'haiku' | 'sonnet' | model_id complet | null = défaut
     * @return array{
     *   ok:bool,
     *   extracted:?array,
     *   modele:string,
     *   cout_centimes:int,
     *   confidence:int,
     *   raw_json:?array,
     *   erreur:?string,
     * }
     */
    function fluxbox_vision_extract_variant_a(string $cheminAbsolu, ?string $modele = null): array
    {
        $modele = match (true) {
            $modele === null || $modele === ''       => FLUXBOX_VA_VISION_MODEL,
            strtolower($modele) === 'haiku'          => 'claude-haiku-4-5-20251001',
            strtolower($modele) === 'sonnet'         => 'claude-sonnet-4-6',
            default                                   => $modele,
        };

        $resolved = agence_doc_ocr_resolve_local_or_fetch($cheminAbsolu);
        if ($resolved['erreur'] !== null) {
            return ['ok'=>false,'extracted'=>null,'modele'=>$modele,'cout_centimes'=>0,'confidence'=>0,'raw_json'=>null,
                    'erreur'=>$resolved['erreur']];
        }
        $cheminLocal   = $resolved['path'];
        $tempToCleanup = $resolved['is_temp'] ? $cheminLocal : null;

        if (!is_file($cheminLocal) || !is_readable($cheminLocal)) {
            if ($tempToCleanup) @unlink($tempToCleanup);
            return ['ok'=>false,'extracted'=>null,'modele'=>$modele,'cout_centimes'=>0,'confidence'=>0,'raw_json'=>null,'erreur'=>'fichier_introuvable'];
        }

        $apiKey = mbi_supports_ia_anthropic_key();
        if ($apiKey === '') {
            if ($tempToCleanup) @unlink($tempToCleanup);
            return ['ok'=>false,'extracted'=>null,'modele'=>$modele,'cout_centimes'=>0,'confidence'=>0,'raw_json'=>null,'erreur'=>'no_api_key'];
        }

        $mime = mime_content_type($cheminLocal) ?: 'application/octet-stream';
        $b64  = base64_encode((string)file_get_contents($cheminLocal));

        $mediaBlock = match (true) {
            str_starts_with($mime, 'image/') => [
                'type'   => 'image',
                'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64],
            ],
            $mime === 'application/pdf' => [
                'type'   => 'document',
                'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $b64],
            ],
            default => null,
        };
        if ($mediaBlock === null) {
            if ($tempToCleanup) @unlink($tempToCleanup);
            return ['ok'=>false,'extracted'=>null,'modele'=>$modele,'cout_centimes'=>0,'confidence'=>0,'raw_json'=>null,'erreur'=>'mime_non_supporte: ' . $mime];
        }

        $payload = [
            'model'       => $modele,
            'max_tokens'  => 800,
            'temperature' => 0.0,
            'system'      => fluxbox_vision_va_system_prompt(),
            'messages'    => [[
                'role'    => 'user',
                'content' => [
                    $mediaBlock,
                    ['type' => 'text', 'text' => fluxbox_vision_va_user_prompt()],
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
            if ($tempToCleanup) @unlink($tempToCleanup);
            return ['ok'=>false,'extracted'=>null,'modele'=>$modele,'cout_centimes'=>0,'confidence'=>0,'raw_json'=>null,
                    'erreur'=>"anthropic_http_{$code}: " . ($err ?: substr((string)$raw, 0, 300))];
        }

        $body = json_decode((string)$raw, true);
        $text = $body['content'][0]['text'] ?? '';
        if (!is_string($text) || $text === '') {
            if ($tempToCleanup) @unlink($tempToCleanup);
            return ['ok'=>false,'extracted'=>null,'modele'=>$modele,'cout_centimes'=>0,'confidence'=>0,'raw_json'=>$body,'erreur'=>'reponse_vide'];
        }

        $data = mbi_supports_ia_extract_json($text);
        if ($data === null || !is_array($data)) {
            if ($tempToCleanup) @unlink($tempToCleanup);
            return ['ok'=>false,'extracted'=>null,'modele'=>$modele,
                    'cout_centimes'=>mbi_supports_ia_estimer_cout($body),
                    'confidence'=>0,'raw_json'=>$body,'erreur'=>'json_invalide'];
        }

        if ($tempToCleanup) @unlink($tempToCleanup);

        $extracted = fluxbox_vision_va_normalize($data);

        return [
            'ok'            => true,
            'extracted'     => $extracted,
            'modele'        => $modele,
            'cout_centimes' => mbi_supports_ia_estimer_cout($body),
            'confidence'    => (int)($extracted['confidence'] ?? 0),
            'raw_json'      => $body,
            'erreur'        => null,
        ];
    }
}

if (!function_exists('fluxbox_vision_va_system_prompt')) {
    function fluxbox_vision_va_system_prompt(): string
    {
        return implode("\n", [
            "Tu es un assistant d'extraction de données pour le nommage automatique de documents",
            "d'une régie immobilière française (relevés bancaires, factures fournisseurs,",
            "courriers, baux, etc.). Tu lis le document fourni (PDF ou image) et tu remplis",
            "le JSON demandé en t'appuyant UNIQUEMENT sur ce qui est lisible dans le document.",
            "",
            "RÈGLES STRICTES :",
            "- Tu ne réponds JAMAIS hors du JSON demandé. Pas de markdown, pas de fence ```, pas de texte autour.",
            "- Si un champ n'apparaît pas dans le document, mets sa valeur à null (pas une supposition).",
            "- Tu n'INVENTES PAS de noms d'immeubles, de banques ou de fournisseurs : si ce n'est pas écrit, c'est null.",
            "- Tu retournes les libellés EXACTS lus dans le document (orthographe, casse, accents).",
            "- Confidence (0-100) reflète ta certitude globale sur l'extraction.",
        ]);
    }
}

if (!function_exists('fluxbox_vision_va_user_prompt')) {
    function fluxbox_vision_va_user_prompt(): string
    {
        return implode("\n", [
            "Analyse ce document et remplis ce JSON :",
            "",
            "{",
            '  "banque_text":   "string|null",   // Nom de la banque émettrice (ex: "Crédit Mutuel", "BNP Paribas", "Caisse d\'Épargne")',
            '  "compte_text":   "string|null",   // N° de compte / IBAN si visible (chaîne brute, ex: "FR76 1234 5678 9012 3456 7890 123")',
            '  "periode_text":  "string|null",   // Période concernée par le document (ex: "Mars 2026", "01/03/2026 au 31/03/2026", "Bulletin de paie avril 2026")',
            '  "immeuble_text": "string|null",   // Nom d\'immeuble OU adresse si présent (ex: "Résidence Les Alizés", "12 rue Garibaldi Lyon 6e")',
            '  "type_text":     "string|null",   // Type de document (ex: "Relevé de compte", "Facture", "Bulletin de paie", "Avoir", "Quittance")',
            '  "fournisseur_text": "string|null", // Si facture : nom du fournisseur émetteur',
            '  "montant_ttc":   number|null,    // Montant TTC en € si visible (sans symbole, point décimal)',
            '  "date_doc":      "YYYY-MM-DD|null", // Date d\'émission du document si visible',
            '  "confidence":    int             // 0-100',
            "}",
            "",
            "Précisions :",
            "- periode_text : pour un relevé bancaire, c'est le mois du relevé (ex: \"Mars 2026\").",
            "  Pour une facture, c'est la période de service si distincte de la date d'émission.",
            "  Sinon, mets la date d'émission.",
            "- immeuble_text : SEULEMENT si un immeuble/adresse est explicitement mentionné dans le document.",
            "  Pour un relevé bancaire d'un compte syndic, c'est souvent le nom de la copropriété.",
            "- compte_text : retourne l'IBAN ou le n° de compte tel quel ; le post-traitement extraira les 4 derniers chiffres.",
        ]);
    }
}

if (!function_exists('fluxbox_vision_va_normalize')) {
    /**
     * Normalise la sortie Vision : trim, null si chaîne vide.
     */
    function fluxbox_vision_va_normalize(array $data): array
    {
        $out = [
            'banque_text'      => null,
            'compte_text'      => null,
            'periode_text'     => null,
            'immeuble_text'    => null,
            'type_text'        => null,
            'fournisseur_text' => null,
            'montant_ttc'      => null,
            'date_doc'         => null,
            'confidence'       => 0,
        ];

        foreach (['banque_text','compte_text','periode_text','immeuble_text','type_text','fournisseur_text','date_doc'] as $k) {
            if (isset($data[$k]) && is_string($data[$k])) {
                $v = trim($data[$k]);
                $out[$k] = $v === '' ? null : $v;
            }
        }

        if (isset($data['montant_ttc']) && is_numeric($data['montant_ttc'])) {
            $out['montant_ttc'] = (float)$data['montant_ttc'];
        }
        if (isset($data['confidence']) && is_numeric($data['confidence'])) {
            $out['confidence'] = max(0, min(100, (int)$data['confidence']));
        }

        return $out;
    }
}
