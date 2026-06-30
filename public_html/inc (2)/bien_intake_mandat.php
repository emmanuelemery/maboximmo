<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Module d'extraction IA spécialisé — MANDATS immobiliers
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Prompt focalisé sur les mandats de vente / location / gestion locative.
 * Beaucoup plus précis que le prompt générique car ne disperse pas l'attention
 * sur des champs non-pertinents (DPE, surfaces, diagnostics…).
 *
 * Utilisé par le dispatcher bien_intake_ia.php quand le texte est détecté
 * comme un mandat.
 *
 * Champs extraits :
 *   - Métadonnées mandat : n°, type, nature, dates, honoraires
 *   - Bien : adresse, type, lot, étage (si présents dans le mandat)
 *   - Propriétaire/Mandant : nom, prénom, société, adresse, contact
 *     (en IGNORANT les mandataires : REGIE EMERY, EMERY, Agence, Cabinet…)
 *   - Prix/loyer si mentionnés dans le mandat
 */

function analyseMandatIA(string $text): array
{
    $apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
    // Force gpt-4o-mini : extraction structurée → reasoning models = JSON tronqué + lent.
    $model = defined('OPENAI_MANDAT_MODEL') ? OPENAI_MANDAT_MODEL : 'gpt-4o-mini';

    if (!$apiKey) {
        return ['ok' => false, 'fields' => [], 'doc_type' => 'mandat', 'error' => 'OPENAI_API_KEY non configurée'];
    }
    if (strlen(trim($text)) < 50) {
        return ['ok' => false, 'fields' => [], 'doc_type' => 'mandat', 'error' => 'Texte trop court'];
    }

    $textTruncated = mb_substr($text, 0, 50000);

    $system = "Tu es un assistant expert en mandats immobiliers français (loi Hoguet). "
        . "Tu extrais précisément les données d'un mandat de vente/location/gestion au format JSON strict. "
        . "Tu réponds UNIQUEMENT avec du JSON valide.";

    $user = <<<PROMPT
Analyse ce mandat immobilier français et extrais TOUTES les données utiles en JSON strict.

Réponds UNIQUEMENT en JSON valide selon cette structure exacte (null si absent) :
{
  "doc_type": "mandat_vente|mandat_location|mandat_gestion|mandat_recherche|mandat_autre",
  "doc_titre": "string court — ex: 'Mandat de gestion n°2024-042'",
  "doc_date": "YYYY-MM-DD (date de signature) ou null",

  "_mandat": {
    "numero_mandat": "string (numéro du mandat) ou null",
    "type_mandat": "vente|location|gestion|recherche",
    "nature_mandat": "simple|exclusif|semi ou null",
    "exclusif": "true|false",
    "date_signature": "YYYY-MM-DD ou null",
    "date_debut": "YYYY-MM-DD ou null",
    "date_fin": "YYYY-MM-DD ou null",
    "duree_mois": "nombre ou null",
    "honoraires": "nombre (€ ou %) ou null",
    "honoraires_pourcentage": "nombre (si exprimé en %) ou null",
    "honoraires_charge": "acquereur|vendeur|locataire|bailleur ou null"
  },

  "_bien": {
    "type_bien": "appartement|maison|villa|immeuble|terrain|local_commercial|bureau|garage|parking|null",
    "type_typologie": "T1|T2|T3|T4|T5|T6 ou null",
    "designation": "string courte décrivant le bien ou null",
    "reference_bien": "string (référence agence) ou null",
    "adresse_1": "adresse postale du BIEN (rue + numéro UNIQUEMENT, pas porte ni étage)",
    "adresse_situation": "porte, cage, allée, bâtiment, escalier (ex: 'Porte F') ou null",
    "code_postal": "string 5 chiffres ou null",
    "ville": "string ou null",
    "etage": "nombre entier ou null (RDC = 0)",
    "lot_principal": "string (numéro de lot copropriété) ou null",
    "surface_habitable": "nombre décimal (m²) ou null",
    "nb_pieces": "nombre entier ou null",
    "annee_construction": "nombre entier ou null"
  },

  "_proprietaire": {
    "_commentaire": "PROPRIÉTAIRE / MANDANT / BAILLEUR uniquement. IGNORE les mandataires (REGIE EMERY, EMERY, Agence, Cabinet, Régie, Administrateur, Syndic).",
    "type_personne": "physique|morale ou null",
    "civilite": "M.|Mme|Mlle|Dr ou null",
    "nom": "string — nom du propriétaire RÉEL (particulier). IGNORE REGIE EMERY / EMERY / Cabinet / Agence — sinon null",
    "prenom": "string ou null",
    "societe": "string (SCI/SARL réelle) ou null — JAMAIS une régie/agence",
    "adresse_1": "adresse personnelle du bailleur (pas celle de l'agence) ou null",
    "code_postal": "string 5 chiffres ou null",
    "ville": "string ou null",
    "telephone": "string ou null",
    "email": "string ou null",
    "siret": "string 14 chiffres ou null"
  },

  "_prix": {
    "prix_vente": "nombre (€ pour vente) ou null",
    "loyer_hc": "nombre (€/mois loyer hors charges pour location) ou null",
    "charges_locatives": "nombre (€/mois) ou null",
    "depot_garantie": "nombre (€) ou null"
  },

  "_agence": {
    "_commentaire": "Agence/régie/cabinet qui reçoit le mandat. C'est le MANDATAIRE, pas le propriétaire.",
    "nom_agence": "string ou null",
    "numero_carte_t": "string (n° de la carte T) ou null",
    "garantie_financiere": "string ou null"
  },

  "_resume": "string court (2-4 phrases) résumant le mandat : type, parties, durée, conditions clés"
}

RÈGLES IMPORTANTES :

⚠️ DISTINCTION PROPRIÉTAIRE / AGENCE :
- Le PROPRIÉTAIRE (mandant / bailleur) donne le mandat. C'est lui qui signe en tant que MANDANT.
- L'AGENCE (mandataire) reçoit le mandat. Elle est identifiée par son numéro de carte T, sa garantie financière.
- NE JAMAIS mélanger les deux. Si tu vois "REGIE EMERY" ou "Agence XYZ", c'est le mandataire → section _agence.
- Le vrai propriétaire est généralement identifié par "Je soussigné(e)", "Le Mandant", "Le Bailleur", "Le Propriétaire".

Règles mandats :
- type_mandat "gestion" : mandat de gestion locative (propriétaire confie la gestion complète)
- type_mandat "location" : mandat de location simple (agence trouve locataire puis retrait)
- type_mandat "vente" : mandat pour vendre le bien
- type_mandat "recherche" : mandat de recherche (acheteur/locataire cherche)
- nature_mandat "exclusif" si "mandat exclusif" mentionné, "simple" sinon
- exclusif : true si mandat exclusif, false sinon

Règles adresse :
- adresse_1 = rue + numéro UNIQUEMENT (ex: "1 rue Jubin"). Jamais de porte/étage/allée ici.
- adresse_situation = porte, cage, étage, bâtiment (ex: "Porte F Bâtiment A")
- Les adresses du BIEN et du PROPRIÉTAIRE peuvent être différentes — ne les confonds pas.

Règles honoraires :
- Si "% TTC", remplis honoraires_pourcentage
- Si valeur absolue en €, remplis honoraires
- honoraires_charge = qui paie (acquéreur/vendeur/locataire/bailleur)

Texte du mandat à analyser :
---
{$textTruncated}
---
PROMPT;

    $useNewParam = (bool)preg_match('/^(gpt-5|o1|o3|gpt-4\.1)/i', $model);
    $payloadArr = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
        'response_format' => ['type' => 'json_object'],
    ];
    if ($useNewParam) {
        $payloadArr['max_completion_tokens'] = 2500;
    } else {
        $payloadArr['max_tokens']  = 2500;
        $payloadArr['temperature'] = 0.1;
    }
    $payload = json_encode($payloadArr);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) return ['ok' => false, 'fields' => [], 'doc_type' => 'mandat', 'error' => 'Réseau : ' . $curlErr];
    if ($httpCode !== 200) {
        $errBody = json_decode((string)$response, true);
        $apiMsg  = $errBody['error']['message'] ?? substr((string)$response, 0, 300);
        error_log('[analyseMandatIA] HTTP ' . $httpCode . ' OpenAI : ' . $apiMsg);
        return ['ok' => false, 'fields' => [], 'doc_type' => 'mandat',
                'error' => 'OpenAI HTTP ' . $httpCode . ' : ' . $apiMsg];
    }

    $data = json_decode((string)$response, true);
    $content = (string)($data['choices'][0]['message']['content'] ?? '');
    $parsed = json_decode($content, true);
    if (!is_array($parsed)) {
        return ['ok' => false, 'fields' => [], 'doc_type' => 'mandat', 'error' => 'Réponse IA non-JSON'];
    }

    // Aplatissement : les sections _meta/_bien/_proprietaire deviennent un dict plat
    $flat = [];
    foreach ($parsed as $section => $val) {
        if (is_array($val)) {
            foreach ($val as $k => $v) {
                if ($k === '_commentaire') continue;
                // Préfixe proprio_ pour les champs propriétaire (cohérence avec form)
                if ($section === '_proprietaire' || $section === '_bailleur') {
                    if (!str_starts_with($k, 'proprio_')) $k = 'proprio_' . $k;
                }
                // Préfixe mandats_ pour les champs mandat
                elseif ($section === '_mandat') {
                    if (!in_array($k, ['numero_mandat','type_mandat','nature_mandat','exclusif','honoraires'], true)
                        && !str_starts_with($k, 'date_')) {
                        $k = 'mandats_' . $k;
                    } elseif (str_starts_with($k, 'date_')) {
                        $k = 'mandats_' . $k;
                    }
                }
                $flat[$k] = $v;
            }
        } else {
            $flat[ltrim($section, '_')] = $val;
        }
    }

    // Normalisation
    foreach ($flat as $k => $v) {
        if ($v === null || $v === '' || (is_array($v) && empty($v))) {
            unset($flat[$k]);
        }
    }

    return [
        'ok'        => true,
        'doc_type'  => (string)($parsed['doc_type'] ?? 'mandat'),
        'doc_titre' => (string)($parsed['doc_titre'] ?? ''),
        'doc_date'  => (string)($parsed['doc_date']  ?? ''),
        'fields'    => $flat,
        'raw'       => $parsed,
        'count'     => count($flat),
    ];
}
