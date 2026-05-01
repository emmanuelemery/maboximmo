<?php
declare(strict_types=1);

/**
 * GED MaBoxImmo — Pipeline cascade extraction document → JSON 20+ champs.
 * Fichier : modules/ged/ged_extraction.php
 *
 * Cascade :
 *   1. PDF natif texte → pdftotext / pure PHP → si texte > 50 chars → IA texte
 *   2. PDF scanné OU image (PNG/JPG/HEIC/WEBP) → IA vision (Sonnet 4.6) directement
 *   3. Échec / contenu non-document → null + status 'manual_review'
 *
 * Anti-hallucination :
 *   - temperature 0
 *   - système : "préfère null à une valeur fantaisiste"
 *   - chaque champ a son score de confiance individuel
 *   - score global = moyenne des scores par champ détecté
 */

require_once __DIR__ . '/../../inc/ged_ai_models.php';
require_once __DIR__ . '/ged_pdf_text.php';
require_once __DIR__ . '/ged_vision.php';

/**
 * Schéma JSON attendu en sortie (20+ champs).
 * Le prompt ENGAGE le modèle à respecter EXACTEMENT cette structure.
 */
const GED_EXTRACTION_SCHEMA = [
    'type_document'      => 'string|null  // FACTURE, BAIL, ETAT_DES_LIEUX, MANDAT, RIB, COMPROMIS, ATTESTATION, DPE, KBIS, BULLETIN_PAIE, etc.',
    'module'             => 'string|null  // RH|COMPTA|BAILLEUR|SYNDIC|AGENCE|FOURNISSEURS|ADMIN',
    'niveau_2'           => 'string|null',
    'niveau_3'           => 'string|null',
    'date_document'      => 'date|null    // YYYY-MM-DD (date principale du doc)',
    'date_emission'      => 'date|null    // YYYY-MM-DD',
    'date_echeance'      => 'date|null    // YYYY-MM-DD',
    'periode_debut'      => 'date|null    // pour relevés bancaires, baux',
    'periode_fin'        => 'date|null',
    'numero_document'    => 'string|null  // n° facture, n° contrat, référence',
    'emetteur_nom'       => 'string|null  // qui émet le doc',
    'emetteur_siret'     => 'string|null',
    'destinataire_nom'   => 'string|null',
    'destinataire_siret' => 'string|null',
    'fournisseur'        => 'string|null',
    'tiers_principal'    => 'string|null  // l\'entité métier centrale (cabinet, SDC, sci, locataire...)',
    'immeuble'           => 'string|null  // nom ou adresse de l\'immeuble (pas en clair en filename)',
    'bien_reference'     => 'string|null',
    'mandat_numero'      => 'string|null',
    'iban'               => 'string|null',
    'bic'                => 'string|null',
    'montant_ht'         => 'number|null',
    'montant_ttc'        => 'number|null',
    'montant_tva'        => 'number|null',
    'devise'             => 'string|null  // EUR, USD',
    'description_courte' => 'string|null  // 80 chars max — résumé du document',
    'action_proposee'    => 'string|null  // 1 phrase courte',
    'confiance_globale'  => 'number|null  // 0-100',
    'champs_confiance'   => 'object|null  // { "type_document": 95, "date_document": 80, ... }',
    'note_qualite_image' => 'string|null  // BONNE|MOYENNE|MAUVAISE — pour traçabilité',
];

/**
 * Pipeline complet : path local → JSON structuré (ou null si échec).
 *
 * @return array{
 *   ok:bool,
 *   path_engine:string,           // 'pdf_text' | 'vision_claude' | 'vision_openai' | 'failed'
 *   model_used:string,
 *   raw_text:?string,             // texte OCR/PDF si extrait
 *   data:array<string,mixed>,     // JSON conforme à GED_EXTRACTION_SCHEMA
 *   errors:array<string>
 * }
 */
function gedExtractDocument(string $localPath, ?string $forcedService = null): array
{
    $errors = [];
    if (!is_file($localPath)) {
        return ['ok' => false, 'path_engine' => 'failed', 'model_used' => '',
                'raw_text' => null, 'data' => [], 'errors' => ["Fichier introuvable : {$localPath}"]];
    }

    $mime = ged_vision_mime($localPath);
    $rawText = null;

    // ── Étape 1 : si PDF, tenter l'extraction texte natif ────────────────────
    if ($mime === 'application/pdf') {
        try {
            $rawText = gedPdfExtractText($localPath, 50);
        } catch (Throwable $e) {
            $errors[] = 'pdfExtractText: ' . $e->getMessage();
        }
    }

    // ── Étape 2 : si on a du texte propre, on appelle Claude en mode TEXT ────
    if ($rawText !== null && mb_strlen(trim($rawText)) >= 50) {
        try {
            $textResult = ged_extract_via_text($rawText, $forcedService);
            return [
                'ok'          => true,
                'path_engine' => 'pdf_text',
                'model_used'  => $textResult['model_used'],
                'raw_text'    => $rawText,
                'data'        => $textResult['data'],
                'errors'      => $errors,
            ];
        } catch (Throwable $e) {
            $errors[] = 'extract_via_text: ' . $e->getMessage();
            // On retombe en vision en cas d'échec texte.
        }
    }

    // ── Étape 3 : vision IA (image directement, ou PDF page 1 convertie) ─────
    try {
        $sys = ged_extraction_system_prompt();
        $usr = ged_extraction_user_prompt(null, $forcedService);
        $vis = gedVisionExtract($localPath, $sys, $usr);
        $data = ged_extraction_postprocess($vis['data']);
        return [
            'ok'          => true,
            'path_engine' => ged_is_anthropic_model($vis['model_used']) ? 'vision_claude' : 'vision_openai',
            'model_used'  => $vis['model_used'],
            'raw_text'    => $rawText,
            'data'        => $data,
            'errors'      => $errors,
        ];
    } catch (Throwable $e) {
        $errors[] = 'vision: ' . $e->getMessage();
    }

    return [
        'ok'          => false,
        'path_engine' => 'failed',
        'model_used'  => '',
        'raw_text'    => $rawText,
        'data'        => [],
        'errors'      => $errors,
    ];
}

// ── Système prompt enrichi (anti-hallucination strict) ───────────────────────

function ged_extraction_system_prompt(): string
{
    return implode("\n", [
        "Tu es un assistant d'extraction structurée de documents immobiliers français pour un cabinet de gestion (syndic + transaction + gérance + comptabilité + RH).",
        "Tu retournes UNIQUEMENT un JSON strict conforme au schéma demandé. AUCUN texte autour, pas de markdown, pas de fences.",
        "",
        "RÈGLES ANTI-HALLUCINATION (impératives) :",
        "- Si un champ n'apparaît PAS clairement dans le document, mets null. Ne JAMAIS deviner.",
        "- Une date au format DD/MM/YYYY se convertit en YYYY-MM-DD.",
        "- Les montants sont des nombres décimaux (point, pas virgule). Pas d'unité dans la valeur.",
        "- IBAN sans espaces, BIC en majuscules.",
        "- Pour chaque champ extrait, fournis un score 0-100 dans `champs_confiance`.",
        "- `confiance_globale` = moyenne des scores des champs effectivement extraits (non-null).",
        "",
        "TAXONOMIE MÉTIER (modules valides) :",
        "RH, COMPTA, BAILLEUR, SYNDIC, AGENCE, FOURNISSEURS, ADMIN",
        "",
        "TYPES DE DOCUMENT — vocabulaire normalisé pour `type_document` (utilise EXACTEMENT ces libellés en MAJUSCULES) :",
        "FACTURE, DEVIS, BAIL, ETAT_DES_LIEUX, MANDAT, COMPROMIS, ACTE_VENTE,",
        "QUITTANCE, AVIS_ECHEANCE, RIB, RELEVE_BANCAIRE, ATTESTATION_ASSURANCE,",
        "TAXE_FONCIERE, TAXE_HABITATION, AVIS_IMPOT,",
        "PV_AG, CONVOCATION_AG, REGLEMENT_COPRO, APPEL_DE_FONDS, CRG_ANNUEL,",
        "BULLETIN_PAIE, CONTRAT_TRAVAIL, DPAE, PIECE_IDENTITE,",
        "DPE, DIAGNOSTIC, KBIS, STATUTS, COURRIER, EMAIL, AUTRE",
        "",
        "RÈGLES CRITIQUES DE CLASSEMENT :",
        "1. Avis d'impôt foncier (DGFiP, Direction Générale des Finances Publiques, Trésor Public) → type_document=TAXE_FONCIERE, module=COMPTA, niveau_2='Fiscalité', niveau_3='Taxe foncière'",
        "2. Relevé bancaire (liste d'opérations + soldes débit/crédit + période) → type_document=RELEVE_BANCAIRE, module=COMPTA, niveau_2='Banque', niveau_3='Relevés'",
        "3. RIB (page simple avec coordonnées bancaires : titulaire+IBAN+BIC, AUCUNE opération) → type_document=RIB, module=COMPTA, niveau_2='Banque', niveau_3='RIB'. NE PAS confondre RIB et RELEVE_BANCAIRE : un relevé liste les opérations du mois.",
        "4. Facture multi-TVA : `montant_ht` = SOUS-TOTAL HT GLOBAL toutes lignes confondues, `montant_ttc` = TOTAL TTC FINAL (le grand total à payer), `montant_tva` = TVA totale (somme des TVA si plusieurs taux).",
        "5. Facture + immeuble + travaux copro → SYNDIC / Travaux / Factures. Sinon facture entrante → FOURNISSEURS / Factures.",
        "6. Bail → type_document=BAIL, module=BAILLEUR, niveau_2='Baux', niveau_3='Contrats'",
        "7. État des lieux → BAILLEUR / Baux / États des lieux",
        "8. Quittance loyer → BAILLEUR / Loyers / Quittances",
        "9. PV AG → SYNDIC / Assemblées / PV",
        "10. Mandat (gestion / vente / syndic) → AGENCE / Mandats",
        "11. Compromis / promesse vente → AGENCE / Ventes / Compromis",
        "12. Police assurance → ADMIN / Assurances",
        "13. KBIS / Statuts → ADMIN / Administratif",
        "14. Bulletin paie → RH / Paie / Bulletins",
        "15. Si aucune règle : module='ADMIN', niveau_2='Administratif', niveau_3='Divers'",
        "",
        "RÈGLE `tiers_principal` (CRITIQUE) :",
        "- C'est l'entité MÉTIER centrale du document, JAMAIS le cabinet/agence de gestion (MaBoxImmo, agence Émery, etc. sont des intermédiaires, pas le tiers principal).",
        "- Bail → tiers_principal = LE LOCATAIRE (preneur), pas le bailleur ni l'agence",
        "- Facture fournisseur → tiers_principal = LE FOURNISSEUR (émetteur)",
        "- Quittance loyer → tiers_principal = LE LOCATAIRE",
        "- PV AG / Convocation → tiers_principal = LE SYNDICAT DES COPROPRIÉTAIRES (SDC) ou la résidence",
        "- Mandat → tiers_principal = LE PROPRIÉTAIRE / MANDANT (celui qui donne mandat)",
        "- Compromis vente → tiers_principal = L'ACHETEUR (ou vendeur si avis vendeur uniquement)",
        "- Avis impôt → tiers_principal = LE PROPRIÉTAIRE imposé",
        "- Bulletin paie → tiers_principal = LE SALARIÉ",
        "- Si plusieurs candidats légitimes → choisis le client final, pas l'intermédiaire.",
    ]);
}

function ged_extraction_user_prompt(?string $rawText, ?string $forcedService): string
{
    $hint = $forcedService !== null
        ? "ORIENTATION FORCÉE : ce document concerne le service métier **{$forcedService}**. Tu DOIS classer dans ce service.\n\n"
        : '';

    $schema = "Schéma JSON attendu (20+ champs, types stricts) :\n";
    foreach (GED_EXTRACTION_SCHEMA as $k => $desc) {
        $schema .= "  - {$k} : {$desc}\n";
    }

    $base = $hint . $schema . "\n";
    if ($rawText !== null) {
        $base .= "DOCUMENT (texte extrait) :\n---\n" . mb_substr($rawText, 0, 12000) . "\n---\n\n";
    } else {
        $base .= "Le document ci-joint est une image (ou PDF scanné). Extrais les champs visibles.\n\n";
    }
    $base .= "Réponse JSON :";
    return $base;
}

// ── Path 1 : extraction sur texte (Claude messages, pas vision) ──────────────

/**
 * @return array{data:array<string,mixed>, model_used:string}
 */
function ged_extract_via_text(string $rawText, ?string $forcedService): array
{
    $apiKey = ged_vision_anthropic_key();
    if ($apiKey === '') {
        return ged_extract_via_text_openai($rawText, $forcedService);
    }

    $sys = ged_extraction_system_prompt();
    $usr = ged_extraction_user_prompt($rawText, $forcedService);

    $payload = [
        'model'      => GED_MODEL_EXTRACTION,
        'max_tokens' => 2000,
        'temperature'=> 0.0,
        'system'     => $sys,
        'messages'   => [['role' => 'user', 'content' => $usr]],
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
        CURLOPT_TIMEOUT        => 60,
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code !== 200) {
        // Tentative OpenAI en fallback
        return ged_extract_via_text_openai($rawText, $forcedService);
    }
    $body = json_decode((string)$raw, true);
    $text = $body['content'][0]['text'] ?? '';
    if (!is_string($text) || $text === '') {
        return ged_extract_via_text_openai($rawText, $forcedService);
    }
    $data = ged_extraction_postprocess(ged_vision_extract_json($text));
    return ['data' => $data, 'model_used' => GED_MODEL_EXTRACTION];
}

/**
 * @return array{data:array<string,mixed>, model_used:string}
 */
function ged_extract_via_text_openai(string $rawText, ?string $forcedService): array
{
    $apiKey = ged_vision_openai_key();
    if ($apiKey === '') {
        throw new RuntimeException('Aucune clé IA disponible (Anthropic ni OpenAI).');
    }
    $sys = ged_extraction_system_prompt();
    $usr = ged_extraction_user_prompt($rawText, $forcedService);
    $payload = [
        'model'           => GED_MODEL_FALLBACK,
        'response_format' => ['type' => 'json_object'],
        'temperature'     => 0.0,
        'max_tokens'      => 2000,
        'messages'        => [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user',   'content' => $usr],
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
        CURLOPT_TIMEOUT        => 60,
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) throw new RuntimeException('OpenAI text extraction cURL error');
    if ($code !== 200) {
        $b = json_decode((string)$raw, true);
        throw new RuntimeException("OpenAI text HTTP {$code}: " . ($b['error']['message'] ?? substr((string)$raw, 0, 300)));
    }
    $b = json_decode((string)$raw, true);
    $text = $b['choices'][0]['message']['content'] ?? '';
    $data = ged_extraction_postprocess(ged_vision_extract_json((string)$text));
    return ['data' => $data, 'model_used' => GED_MODEL_FALLBACK];
}

// ── Post-traitement : normalise + recalcule confiance globale si manquante ───

function ged_extraction_postprocess(array $data): array
{
    // Module en MAJUSCULES
    if (!empty($data['module']) && is_string($data['module'])) {
        $data['module'] = strtoupper($data['module']);
    }
    // Dates : tente normalisation YYYY-MM-DD si DD/MM/YYYY rencontré
    foreach (['date_document', 'date_emission', 'date_echeance', 'periode_debut', 'periode_fin'] as $df) {
        if (!empty($data[$df]) && is_string($data[$df])) {
            $data[$df] = ged_extraction_normalize_date($data[$df]);
        }
    }
    // IBAN sans espaces
    if (!empty($data['iban']) && is_string($data['iban'])) {
        $data['iban'] = strtoupper(preg_replace('/\s+/', '', $data['iban']) ?? $data['iban']);
    }
    // BIC majuscules
    if (!empty($data['bic']) && is_string($data['bic'])) {
        $data['bic'] = strtoupper($data['bic']);
    }
    // Montants en float
    foreach (['montant_ht', 'montant_ttc', 'montant_tva'] as $mf) {
        if (isset($data[$mf]) && $data[$mf] !== null) {
            $data[$mf] = (float)str_replace([' ', ','], ['', '.'], (string)$data[$mf]);
        }
    }
    // Confiance globale : recalc si absente, à partir des champs_confiance
    if (empty($data['confiance_globale']) && !empty($data['champs_confiance']) && is_array($data['champs_confiance'])) {
        $vals = array_filter(array_map('floatval', $data['champs_confiance']), fn($v) => $v > 0);
        $data['confiance_globale'] = count($vals) > 0 ? round(array_sum($vals) / count($vals), 1) : null;
    }
    return $data;
}

function ged_extraction_normalize_date(string $d): string
{
    $d = trim($d);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return $d;
    if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})$/', $d, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    $ts = strtotime($d);
    if ($ts !== false) return date('Y-m-d', $ts);
    return $d;
}
