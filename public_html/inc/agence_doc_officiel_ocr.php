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

if (!function_exists('agence_doc_ocr_resolve_local_or_fetch')) {
    /**
     * Si le fichier n'est pas accessible en local (cas localhost qui pointe sur
     * BDD dev mais pas sur le filesystem Hostinger), on le télécharge via HTTPS
     * depuis dev.maboximmo.fr ou maboximmo.fr et on retourne le chemin temp.
     *
     * Le caller doit unlink le fichier temp après usage. Si le fichier existe
     * déjà en local, on le retourne tel quel sans télécharger.
     *
     * @return array{path:string,is_temp:bool,erreur:?string}
     */
    function agence_doc_ocr_resolve_local_or_fetch(string $cheminAbsolu): array
    {
        if (is_file($cheminAbsolu) && is_readable($cheminAbsolu)) {
            return ['path' => $cheminAbsolu, 'is_temp' => false, 'erreur' => null];
        }

        // Détecte l'env d'origine depuis le chemin Hostinger
        $normalized = $cheminAbsolu;
        while (preg_match('#/[^/]+/\.\./#', $normalized)) {
            $new = preg_replace('#/[^/]+/\.\./#', '/', $normalized);
            if ($new === $normalized) break;
            $normalized = $new;
        }
        if (str_contains($normalized, '/public_html/dev/')) {
            $base = 'https://dev.maboximmo.fr';
            $rel  = preg_replace('#^.*?/public_html/dev/#', '/', $normalized) ?: $normalized;
        } elseif (str_contains($normalized, '/public_html/')) {
            $base = 'https://maboximmo.fr';
            $rel  = preg_replace('#^.*?/public_html/#', '/', $normalized) ?: $normalized;
        } else {
            return ['path' => '', 'is_temp' => false, 'erreur' => 'fichier_introuvable: ' . $cheminAbsolu];
        }
        $url = $base . $rel;

        // Téléchargement HTTPS via cURL → temp file
        $tmpPath = sys_get_temp_dir() . '/mbi_ocr_' . bin2hex(random_bytes(6)) . '.pdf';
        $fp = @fopen($tmpPath, 'w');
        if (!$fp) {
            return ['path' => '', 'is_temp' => false, 'erreur' => 'tmp_fopen_failed'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $ok   = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        @fclose($fp);

        if (!$ok || $code !== 200 || !is_file($tmpPath) || filesize($tmpPath) === 0) {
            @unlink($tmpPath);
            return ['path' => '', 'is_temp' => false,
                    'erreur' => sprintf('http_fetch_failed code=%d url=%s err=%s', $code, $url, $err ?: 'none')];
        }
        return ['path' => $tmpPath, 'is_temp' => true, 'erreur' => null];
    }
}

if (!function_exists('agence_doc_ocr_extraire')) {

    function agence_doc_ocr_extraire(string $cheminAbsolu, string $typeDoc): array
    {
        $modele = AGENCE_DOC_OCR_MODEL;

        // Résolution cross-env : si le fichier n'est pas en local (cas localhost
        // qui ne voit pas le filesystem Hostinger), on le télécharge via HTTPS
        // depuis dev/prod et on travaille sur un fichier temp.
        $resolved = agence_doc_ocr_resolve_local_or_fetch($cheminAbsolu);
        if ($resolved['erreur'] !== null) {
            return ['ok'=>false,'data'=>null,'modele'=>$modele,'cout_centimes'=>0,'confidence'=>0,'raw_json'=>null,
                    'erreur'=>$resolved['erreur']];
        }
        $cheminLocal = $resolved['path'];
        $tempToCleanup = $resolved['is_temp'] ? $cheminLocal : null;

        // Sécurité supplémentaire au cas où resolve_local_or_fetch retourne
        // un chemin "valide" mais inexistant
        if (!is_file($cheminLocal) || !is_readable($cheminLocal)) {
            if ($tempToCleanup) @unlink($tempToCleanup);
            return ['ok'=>false,'data'=>null,'modele'=>$modele,'cout_centimes'=>0,'confidence'=>0,'raw_json'=>null,'erreur'=>'fichier_introuvable'];
        }

        $cheminAbsolu = $cheminLocal; // continue avec le fichier local (potentiellement temp)

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
            if ($tempToCleanup) @unlink($tempToCleanup);
            return ['ok'=>false,'data'=>null,'modele'=>$modele,'cout_centimes'=>0,'confidence'=>0,'raw_json'=>null,
                    'erreur'=>"anthropic_http_{$code}: " . ($err ?: substr((string)$raw, 0, 300))];
        }

        $body = json_decode((string)$raw, true);
        $text = $body['content'][0]['text'] ?? '';
        if (!is_string($text) || $text === '') {
            if ($tempToCleanup) @unlink($tempToCleanup);
            return ['ok'=>false,'data'=>null,'modele'=>$modele,'cout_centimes'=>0,'confidence'=>0,'raw_json'=>$body,'erreur'=>'reponse_vide'];
        }

        $data = mbi_supports_ia_extract_json($text);
        if ($data === null) {
            if ($tempToCleanup) @unlink($tempToCleanup);
            return ['ok'=>false,'data'=>null,'modele'=>$modele,
                    'cout_centimes'=>mbi_supports_ia_estimer_cout($body),
                    'confidence'=>0,'raw_json'=>$body,'erreur'=>'json_invalide'];
        }

        // Cleanup temp file (succès)
        if ($tempToCleanup) @unlink($tempToCleanup);

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
        // Enrichi 2026-05-06 : capture détaillée pour chaque type de doc.
        // Tous les champs sont optionnels (null si non trouvé). Le hook range
        // les champs structurés dans rh_documents.* et le surplus dans
        // metadata_json (free-form).
        $schemas = [
            'carte_pro' => [
                "Document : CARTE PROFESSIONNELLE d'un agent immobilier (CPI Hoguet — T transaction, G gestion, S syndic).",
                'Schéma JSON (extrait LE PLUS DÉTAILLÉ POSSIBLE — ne saute aucun champ visible) :',
                '{',
                '  "numero":           "string|null",   // ex: CPI 6901 2018 000 012 345',
                '  "type_carte":       "T|G|S|TG|TGS|null",',
                '  "titulaire":        "string|null",   // raison sociale figurant sur la carte',
                '  "raison_sociale":   "string|null",   // identique au titulaire le plus souvent',
                '  "forme_juridique":  "string|null",   // SARL, SAS, SCI, EURL... si visible',
                '  "siret":            "string|null",',
                '  "siren":            "string|null",',
                '  "adresse_titulaire":"string|null",   // siège social',
                '  "emetteur":         "string|null",   // CCI émettrice (ex: CCI Lyon Métropole)',
                '  "adresse_emetteur": "string|null",   // adresse complète de la CCI',
                '  "date_emission":    "YYYY-MM-DD|null",',
                '  "date_validite":    "YYYY-MM-DD|null", // date de fin de validité (CRITIQUE)',
                '  "garantie_financiere_attestee_par": "string|null", // garant cité au dos',
                '  "rc_pro_attestee_par":              "string|null", // assureur cité au dos',
                '  "confidence":       int',
                '}',
            ],
            'kbis' => [
                "Document : EXTRAIT KBIS d'une société (registre du commerce et des sociétés).",
                'Schéma JSON (extrait COMPLET — capturer toutes les infos visibles) :',
                '{',
                '  "numero":              "string|null",   // n° RCS / SIREN (souvent identique)',
                '  "raison_sociale":      "string|null",',
                '  "forme_juridique":     "string|null",   // SARL, SAS, SCI...',
                '  "capital_social":      number|null,     // en €',
                '  "siret":               "string|null",   // 14 chiffres',
                '  "siren":               "string|null",   // 9 chiffres',
                '  "tva_intra":           "string|null",   // FR + 11 chiffres',
                '  "code_ape":            "string|null",   // ex: 6831Z',
                '  "adresse_titulaire":   "string|null",   // siège social complet',
                '  "emetteur":            "string|null",   // greffe du tribunal de commerce',
                '  "date_emission":       "YYYY-MM-DD|null", // date d\'émission de l\'extrait',
                '  "date_immatriculation":"YYYY-MM-DD|null", // date d\'immatriculation au RCS',
                '  "date_validite":       null,              // KBIS pas de validité (recommandé ≤ 3 mois)',
                '  "dirigeants":          [                  // liste des gérants/dirigeants',
                '    {"nom":"string","prenom":"string","fonction":"Gérant|Président|...","date_nomination":"YYYY-MM-DD|null"}',
                '  ],',
                '  "activite_principale": "string|null",   // texte de l\'objet social',
                '  "confidence":          int',
                '}',
            ],
            'garant_financier' => [
                "Document : ATTESTATION DE GARANTIE FINANCIÈRE (Galian, Socaf, MMA, etc.) au titre de la loi Hoguet.",
                'Schéma JSON (extrait DÉTAILLÉ — toutes les valeurs financières et dates) :',
                '{',
                '  "numero":            "string|null",   // n° de contrat / police',
                '  "numero_client":     "string|null",   // n° de client chez le garant (≠ n° contrat parfois)',
                '  "emetteur":          "string|null",   // nom du garant (Galian, Socaf, MMA Caution, …)',
                '  "adresse_emetteur":  "string|null",   // adresse complète siège du garant',
                '  "raison_sociale":    "string|null",   // société garantie (titulaire de la garantie)',
                '  "siret":             "string|null",',
                '  "adresse_titulaire": "string|null",',
                '  "montant_garantie":  number|null,     // plafond principal en € (transaction)',
                '  "montant_plafond_2": number|null,     // 2e plafond (gestion ou syndic) si distinct',
                '  "nature_garantie":   "string|null",   // ex: "Transaction sur immeubles + gestion immobilière"',
                '  "date_emission":     "YYYY-MM-DD|null", // date émission attestation',
                '  "date_effet":        "YYYY-MM-DD|null", // début de couverture',
                '  "date_echeance":     "YYYY-MM-DD|null", // = date_validite, fin de couverture',
                '  "date_anniversaire": "YYYY-MM-DD|null", // si renouvellement annuel mentionné',
                '  "date_validite":     "YYYY-MM-DD|null", // alias date_echeance',
                '  "confidence":        int',
                '}',
            ],
            'rc_pro' => [
                "Document : ATTESTATION D'ASSURANCE RC PROFESSIONNELLE (au titre de la loi Hoguet).",
                'Schéma JSON (extrait DÉTAILLÉ) :',
                '{',
                '  "numero":            "string|null",   // n° de contrat',
                '  "numero_client":     "string|null",   // n° de client chez l\'assureur',
                '  "emetteur":          "string|null",   // assureur (MMA, AXA, Allianz, Generali, …)',
                '  "adresse_emetteur":  "string|null",   // adresse complète assureur',
                '  "raison_sociale":    "string|null",   // société assurée',
                '  "siret":             "string|null",',
                '  "adresse_titulaire": "string|null",',
                '  "montant_garantie":  number|null,     // plafond global ou par sinistre',
                '  "montant_plafond_2": number|null,     // 2e plafond si dommages corporels distincts',
                '  "montant_franchise": number|null,     // franchise par sinistre',
                '  "nature_garantie":   "string|null",   // périmètre (transaction, gestion, syndic, expertise...)',
                '  "date_emission":     "YYYY-MM-DD|null",',
                '  "date_effet":        "YYYY-MM-DD|null",',
                '  "date_echeance":     "YYYY-MM-DD|null",',
                '  "date_anniversaire": "YYYY-MM-DD|null",',
                '  "date_validite":     "YYYY-MM-DD|null", // alias date_echeance',
                '  "confidence":        int',
                '}',
            ],
            'bareme_honoraires' => [
                "Document : BARÈME DES HONORAIRES affiché en agence (loi Hoguet, arrêté 10/01/2017 modifié 26/01/2022).",
                'Schéma JSON :',
                '{',
                '  "numero":          null,',
                '  "emetteur":        "string|null",   // nom de l\'agence',
                '  "raison_sociale":  "string|null",',
                '  "adresse_titulaire":"string|null",',
                '  "date_emission":   "YYYY-MM-DD|null", // date de mise à jour du barème',
                '  "date_validite":   null,             // pas d\'expiration stricte',
                '  "metadata_bareme": {                 // structure libre des tarifs visibles',
                '    "transaction": [{"tranche":"...","taux":"...","montant_min":number|null}],',
                '    "gestion":     {"taux_loyer":"X%","etat_des_lieux":"...","autres":"..."}',
                '  },',
                '  "confidence":      int',
                '}',
            ],
        ];

        if (!isset($schemas[$typeDoc])) {
            return "Type de document inconnu. Renvoie {\"confidence\":0}.";
        }
        return implode("\n", $schemas[$typeDoc])
             . "\n\nIMPORTANT : capture absolument TOUS les champs visibles. Si tu détectes des informations utiles non listées dans le schéma (numéro de notification, références internes, organismes d'agrément, garanties additionnelles…), AJOUTE-les dans une clé \"metadata_extra\" en plus du schéma demandé. Mieux vaut trop d'info que pas assez — le hook backend trie ce qui est utile.";
    }
}

if (!function_exists('agence_doc_ocr_normalize')) {
    function agence_doc_ocr_normalize(array $data, string $typeDoc): array
    {
        $isoDate = static function ($v): ?string {
            if (!is_string($v) || $v === '' || $v === 'null') return null;
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
        };
        $strOrNull = static function ($v, int $maxLen = 250): ?string {
            if (!is_string($v)) return null;
            $v = trim($v);
            return ($v === '' || $v === 'null') ? null : mb_substr($v, 0, $maxLen);
        };
        $numOrNull = static function ($v): ?float {
            if (is_numeric($v)) return (float)$v;
            return null;
        };

        // Champs structurés (mappés sur des colonnes rh_documents.*)
        $structured = [
            'numero'             => $strOrNull($data['numero']             ?? null),
            'numero_client'      => $strOrNull($data['numero_client']      ?? null),
            'type_carte'         => $strOrNull($data['type_carte']         ?? null, 10),
            'titulaire'          => $strOrNull($data['titulaire']          ?? null),
            'raison_sociale'     => $strOrNull($data['raison_sociale']     ?? null),
            'forme_juridique'    => $strOrNull($data['forme_juridique']    ?? null, 50),
            'siret'              => $strOrNull($data['siret']              ?? null, 20),
            'siren'              => $strOrNull($data['siren']              ?? null, 15),
            'tva_intra'          => $strOrNull($data['tva_intra']          ?? null, 20),
            'capital_social'    => $numOrNull($data['capital_social']     ?? null),
            'code_ape'           => $strOrNull($data['code_ape']           ?? null, 10),
            'adresse_titulaire'  => $strOrNull($data['adresse_titulaire']  ?? null, 500),
            'emetteur'           => $strOrNull($data['emetteur']           ?? null),
            'adresse_emetteur'   => $strOrNull($data['adresse_emetteur']   ?? null, 500),
            'date_emission'      => $isoDate ($data['date_emission']       ?? null),
            'date_effet'         => $isoDate ($data['date_effet']          ?? null),
            'date_echeance'      => $isoDate ($data['date_echeance']       ?? null),
            'date_anniversaire'  => $isoDate ($data['date_anniversaire']   ?? null),
            'date_validite'      => $isoDate ($data['date_validite']       ?? $data['date_echeance'] ?? null),
            'montant_garantie'   => $numOrNull($data['montant_garantie']   ?? null),
            'montant_plafond_2'  => $numOrNull($data['montant_plafond_2']  ?? null),
            'montant_franchise'  => $numOrNull($data['montant_franchise']  ?? null),
            'nature_garantie'    => $strOrNull($data['nature_garantie']    ?? null, 500),
            'confidence'         => max(0, min(100, (int)($data['confidence'] ?? 0))),
        ];

        // Dirigeants (KBIS) : tableau d'objets
        $dirigeants = [];
        if (isset($data['dirigeants']) && is_array($data['dirigeants'])) {
            foreach ($data['dirigeants'] as $d) {
                if (!is_array($d)) continue;
                $dirigeants[] = [
                    'nom'             => $strOrNull($d['nom']             ?? null, 100),
                    'prenom'          => $strOrNull($d['prenom']          ?? null, 100),
                    'fonction'        => $strOrNull($d['fonction']        ?? null, 100),
                    'date_nomination' => $isoDate ($d['date_nomination']  ?? null),
                ];
            }
        }
        $structured['dirigeants'] = $dirigeants;

        // Metadata fourre-tout : tout ce qu'on n'a pas mappé + champs supplémentaires IA
        $reservedKeys = array_keys($structured);
        $metadata = [];
        foreach ($data as $k => $v) {
            if (!in_array($k, $reservedKeys, true)) {
                $metadata[$k] = $v;
            }
        }
        if (isset($data['metadata_extra']) && is_array($data['metadata_extra'])) {
            $metadata = array_merge($metadata, $data['metadata_extra']);
        }
        $structured['metadata'] = $metadata;

        return $structured;
    }
}
