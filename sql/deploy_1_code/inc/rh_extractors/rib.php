<?php
/**
 * inc/rh_extractors/rib.php — Extraction RIB (IBAN, BIC, titulaire, banque).
 *
 * Portage de la logique de `api/agence_rib_analyze.php` dans une fonction
 * réutilisable appelée par le router `inc/rh_document_extractor.php`.
 *
 * Stratégie :
 *   1. Si texte natif disponible : regex rapides (IBAN, BIC) — gratuit
 *   2. Si regex score < 70 : fallback IA OpenAI (GPT-4o Vision ou texte)
 *   3. Validation mod97 sur IBAN, format BIC
 */
declare(strict_types=1);

require_once __DIR__ . '/../rh_field_validators.php';

/**
 * Extraction RIB.
 *
 * @param string $text          Texte PDF extrait (peut être vide)
 * @param array  $visionImages  Images base64 pour fallback Vision
 * @param string $filePath      Chemin fichier original (non utilisé ici)
 * @return array ['fields', 'validations', 'score', 'engine']
 */
function rhExtract_rib(string $text, array $visionImages, string $filePath): array
{
    $fields = [];
    $valid  = [];
    $engine = 'regex';
    $score  = 0;

    /* ─── 1. Pass regex (gratuit) ──────────────────────────────────── */
    if (trim($text) !== '') {
        // IBAN français : FR + 2 chiffres + 23 alphanumériques (avec espaces tolérés)
        if (preg_match('/\b(FR\d{2}(?:[\s\.]?[A-Z0-9]){23})\b/i', $text, $m)) {
            $iban = rhNormalizeIban($m[1]);
            if (rhValidateIban($iban)) {
                $fields['iban']    = $iban;
                $valid['iban']     = 'ok';
                $score            += 40;
            } else {
                $fields['iban']    = $iban;
                $valid['iban']     = 'mod97_failed';
                $score            += 10;
            }
        }

        // IBAN autre pays (moins probable mais possible)
        if (empty($fields['iban']) && preg_match('/\b([A-Z]{2}\d{2}[\s\.]?(?:[A-Z0-9][\s\.]?){11,30})\b/', $text, $m)) {
            $iban = rhNormalizeIban($m[1]);
            if (rhValidateIban($iban)) {
                $fields['iban']    = $iban;
                $valid['iban']     = 'ok';
                $score            += 35;
            }
        }

        // BIC : 8 ou 11 caractères, souvent précédé de "BIC" ou "SWIFT"
        if (preg_match('/\bBIC[\s:]*([A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?)\b/i', $text, $m)
            || preg_match('/\bSWIFT[\s:]*([A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?)\b/i', $text, $m)
            || preg_match('/\b([A-Z]{6}FR[A-Z0-9]{2}(?:[A-Z0-9]{3})?)\b/', $text, $m)) {
            $bic = strtoupper(preg_replace('/\s+/', '', $m[1]) ?? '');
            if (rhValidateBic($bic)) {
                $fields['bic'] = $bic;
                $valid['bic']  = 'ok';
                $score        += 25;
            }
        }

        // Nom de banque : ligne après "Banque" ou "Établissement"
        if (preg_match('/(?:Banque|[ÉE]tablissement|Domiciliation)\s*[:\s]+([A-ZÉÈÊ][A-ZÉÈÊa-zéèê\s\-]+?)(?:\n|$)/u', $text, $m)) {
            $banque = trim($m[1]);
            if (mb_strlen($banque) >= 3 && mb_strlen($banque) <= 80) {
                $fields['banque'] = $banque;
                $score           += 15;
            }
        }

        // Titulaire : après "Titulaire" ou "Au nom de"
        if (preg_match('/(?:Titulaire(?:\s+du\s+compte)?|Au\s+nom\s+de)\s*[:\s]+([A-ZÉÈÊ][A-ZÉÈÊa-zéèêçàâû\s\-]+?)(?:\n|$)/u', $text, $m)) {
            $titulaire = trim($m[1]);
            if (mb_strlen($titulaire) >= 3 && mb_strlen($titulaire) <= 100) {
                $fields['titulaire'] = rhNormalizeName($titulaire);
                $score              += 15;
            }
        }
    }

    /* ─── 2. Fallback IA si score insuffisant ──────────────────────── */
    if ($score < 70 && (!empty($visionImages) || trim($text) !== '')) {
        try {
            $iaResult = rhRibCallIa($text, $visionImages);
            if (!empty($iaResult)) {
                $engine = empty($visionImages) ? 'hybrid' : 'vision';

                // Merge : IA ne doit pas écraser un champ déjà validé ok
                foreach (['titulaire', 'banque', 'iban', 'bic'] as $k) {
                    if (!empty($iaResult[$k]) && empty($fields[$k])) {
                        $fields[$k] = $iaResult[$k];
                        $score += 20;
                    }
                }

                // Re-validation après IA
                if (!empty($fields['iban'])) {
                    $fields['iban'] = rhNormalizeIban($fields['iban']);
                    $valid['iban'] = rhValidateIban($fields['iban']) ? 'ok' : 'mod97_failed';
                }
                if (!empty($fields['bic'])) {
                    $fields['bic'] = strtoupper(preg_replace('/\s+/', '', $fields['bic']) ?? '');
                    $valid['bic']  = rhValidateBic($fields['bic']) ? 'ok' : 'format_invalid';
                }
            }
        } catch (Throwable $e) {
            // Fallback IA échoué : on reste avec ce que les regex ont trouvé
            error_log('[rh_extractors/rib] IA fallback failed: ' . $e->getMessage());
        }
    }

    return [
        'fields'      => $fields,
        'validations' => $valid,
        'score'       => min(100, $score),
        'engine'      => $engine,
    ];
}

/**
 * Appel IA OpenAI pour extraction RIB — portage direct de
 * `api/agence_rib_analyze.php` (prompt identique).
 */
function rhRibCallIa(string $text, array $visionImages): array
{
    $systemPrompt = <<<SYS
Tu es un expert en RIB / IBAN français. Tu extrais les informations
bancaires présentes dans le document et retournes UNIQUEMENT un objet
JSON plat avec ces 4 clés exactes :

  - "titulaire" : nom exact du titulaire du compte tel qu'il apparaît
  - "banque"    : nom de la banque ou de l'établissement (ex: "BNP Paribas",
                  "Crédit Agricole Centre-Est", "Banque Populaire AURA")
  - "iban"      : IBAN complet SANS ESPACES (ex: "FR7630004000031234567890143")
  - "bic"       : code BIC/SWIFT (ex: "BNPAFRPP" ou "AGRIFRPP881")

RÈGLES STRICTES :
- Omets toute clé dont la valeur n'est pas clairement présente dans le document.
- N'INVENTE JAMAIS de valeur. Si tu n'es pas sûr d'un caractère, n'émets pas la clé.
- IBAN : vérifier qu'il commence par FR (France) ou autre code pays ISO,
  suivi de 25 caractères (27 au total pour FR). Retire TOUS les espaces.
- BIC : 8 ou 11 caractères majuscules/chiffres, pas d'espaces.
- Retourne du JSON pur, pas de markdown.
SYS;

    $userText = trim($text) !== ''
        ? "Voici le texte extrait d'un RIB français :\n\n--- DÉBUT ---\n{$text}\n--- FIN ---\n\nRetourne le JSON."
        : "Voici un scan de RIB français. Lis attentivement et retourne le JSON avec titulaire, banque, iban (sans espaces), bic.";

    // gpt-4o Vision : plus rapide et moins cher que gpt-5 pour cette tâche
    return rhDxOpenAiJson($systemPrompt, $userText, $visionImages, 'gpt-4o');
}
