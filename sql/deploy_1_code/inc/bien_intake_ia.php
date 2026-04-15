<?php
declare(strict_types=1);

/**
 * Analyse intake — auto-détection du type de document + extraction
 *
 * Reçoit un texte brut extrait d'un PDF (peut être un DPE, un mandat, une
 * fiche commerciale, un mesurage Loi Boutin, un titre de propriété, etc.)
 * et demande à GPT-4o-mini :
 *  1. d'identifier le type de document
 *  2. d'extraire toutes les données utiles avec un prompt adapté
 *
 * Retourne un tableau structuré compatible avec le formulaire bien_ajouter.php.
 */
function analyseBienIntakeIA(string $text): array
{
    $api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
    $model   = defined('OPENAI_DPE_MODEL') ? OPENAI_DPE_MODEL : 'gpt-4o-mini';

    if (!$api_key) {
        return ['ok' => false, 'fields' => [], 'doc_type' => null, 'error' => 'OPENAI_API_KEY non configurée'];
    }
    if (strlen(trim($text)) < 50) {
        return ['ok' => false, 'fields' => [], 'doc_type' => null, 'error' => 'Texte trop court ou vide'];
    }

    $text_truncated = mb_substr($text, 0, 50000);

    $system_prompt = "Tu es un assistant expert en immobilier français. "
        . "Tu analyses tout type de document immobilier (DPE, dossier de diagnostics, mandat de gestion/vente/location, "
        . "fiche commerciale d'agence, titre de propriété, mesurage Loi Boutin/Carrez, état des risques, attestation, etc.) "
        . "et tu extrais TOUTES les données utiles pour pré-remplir une fiche de bien immobilier. "
        . "Tu réponds UNIQUEMENT avec du JSON valide.";

    $user_prompt = <<<PROMPT
Analyse ce document immobilier français. Identifie d'abord son type, puis extrais toutes les données utiles.

Réponds UNIQUEMENT avec du JSON valide selon cette structure (null si absent) :
{
  "doc_type": "dpe|dossier_diagnostics|mandat_gestion|mandat_vente|mandat_location|fiche_commerciale|titre_propriete|mesurage_boutin|mesurage_carrez|etat_risques|attestation|autre",
  "doc_titre": "string court décrivant le doc, ex: 'Mandat de gestion locative n°2024-042'",
  "doc_date": "YYYY-MM-DD ou null",

  "_bien": {
    "type_bien": "appartement|maison|villa|immeuble|terrain|local_commercial|bureau|garage|parking|null",
    "type_typologie": "T1|T2|T3|T4|T5|T6 ou null",
    "designation": "string courte commerciale ou null",
    "reference_bien": "string ou null",
    "annee_construction": "nombre entier ou null",
    "etage": "nombre entier ou null",
    "lot_principal": "string ou null"
  },

  "_adresse": {
    "_commentaire": "ATTENTION : c'est l'adresse du BIEN IMMOBILIER lui-même (l'appartement, la maison, le local…), PAS celle du propriétaire ni du diagnostiqueur. Cherche dans les sections 'Adresse du bien', 'Désignation du bâtiment', 'Bien immobilier', 'Logement' du document. Le propriétaire et le diagnostiqueur ont leur propre adresse, à mettre dans leurs sections respectives.",
    "adresse_1": "string ou null",
    "adresse_2": "string ou null",
    "code_postal": "string 5 chiffres ou null",
    "ville": "string ou null",
    "pays": "string ou null"
  },

  "_surfaces": {
    "surface_habitable": "nombre décimal ou null",
    "surface_carrez": "nombre décimal ou null",
    "surface_sejour": "nombre décimal ou null",
    "surface_terrain": "nombre décimal ou null",
    "surface_balcon": "nombre décimal ou null",
    "surface_terrasse": "nombre décimal ou null",
    "surface_jardin": "nombre décimal ou null",
    "surface_cave": "nombre décimal ou null",
    "surface_garage": "nombre décimal ou null"
  },

  "_pieces": {
    "nb_pieces": "nombre entier ou null",
    "nb_chambres": "nombre entier ou null",
    "nb_salles_bain": "nombre entier ou null",
    "nb_salles_eau": "nombre entier ou null",
    "nb_wc": "nombre entier ou null"
  },

  "_dpe": {
    "dpe_classe": "A|B|C|D|E|F|G ou null",
    "ges_classe": "A|B|C|D|E|F|G ou null",
    "dpe_valeur": "nombre entier ou null",
    "ges_valeur": "nombre entier ou null",
    "dpe_date_realisation": "YYYY-MM-DD ou null",
    "dpe_version": "2011|2021 ou null",
    "dpe_vierge": "true|false",
    "dpe_reference_certificat": "string (n° ADEME) ou null",
    "montant_estime_depenses_min": "nombre ou null",
    "montant_estime_depenses_max": "nombre ou null"
  },

  "_chauffage_energie": {
    "chauffage_type": "individuel|collectif|null",
    "chauffage_energie": "electricite|gaz|fioul|bois|granules|pompe a chaleur|solaire|null",
    "eau_chaude_type": "individuel|collectif|null",
    "double_vitrage": "true|false",
    "volets_roulants": "true|false",
    "menuiseries": "bois|pvc|aluminium|mixte|null"
  },

  "_alertes_diag": {
    "plomb_present": "true|false",
    "amiante_present": "true|false",
    "electricite_anomalies": "true|false",
    "gaz_anomalies": "true|false",
    "termites": "true|false",
    "zone_georisque": "true|false"
  },

  "_proprietaire": {
    "_commentaire": "Adresse PERSONNELLE du propriétaire (siège social SCI, domicile particulier). Souvent DIFFÉRENTE de l'adresse du bien. Dans un mandat / DPE, elle apparaît dans une section dédiée 'Propriétaire', 'Mandant', 'Bailleur'.",
    "nom": "string ou null",
    "prenom": "string ou null",
    "civilite": "M.|Mme|null",
    "type_personne": "physique|morale|null",
    "societe": "string ou null (raison sociale si SCI/SARL)",
    "adresse_1": "string ou null (adresse PERSONNELLE)",
    "code_postal": "string ou null",
    "ville": "string ou null",
    "email": "string ou null",
    "telephone": "string ou null"
  },

  "_mandat": {
    "numero_mandat": "string ou null",
    "type_mandat": "vente|location|gestion|recherche ou null",
    "nature_mandat": "simple|exclusif|semi ou null",
    "date_signature": "YYYY-MM-DD ou null",
    "date_debut": "YYYY-MM-DD ou null",
    "date_fin": "YYYY-MM-DD ou null",
    "honoraires": "nombre ou null",
    "honoraires_charge": "acquereur|vendeur|locataire|bailleur|null"
  },

  "_prix": {
    "prix_vente": "nombre ou null",
    "loyer_hc": "nombre ou null",
    "charges_locatives": "nombre ou null",
    "depot_garantie": "nombre ou null"
  },

  "_diagnostiqueur": {
    "operateur_nom": "string ou null",
    "operateur_societe": "string ou null",
    "dossier_numero": "string ou null"
  },

  "_resume": "string court (2-4 phrases) résumant le contenu du document et ce qui a été extrait"
}

RÈGLES IMPORTANTES :

⚠️ RÈGLE N°1 — DISTINCTION DES ADRESSES (CRITIQUE) :
Un document immobilier contient souvent PLUSIEURS adresses différentes :
  • Adresse du BIEN immobilier (logement, local) → mettre dans _adresse
  • Adresse du PROPRIÉTAIRE (domicile, siège SCI) → mettre dans _proprietaire.adresse_1
  • Adresse du DIAGNOSTIQUEUR / EXPERT / AGENCE → mettre dans _diagnostiqueur uniquement
  • Adresse du SYNDIC / MANDATAIRE → ignorer
NE LES CONFONDS JAMAIS. Cherche les sections explicitement intitulées "Adresse du bien", "Désignation du batiment", "Bien immobilier", "Logement", "Localisation", "Adresse de l'immeuble" pour _adresse.
Dans un mandat de gestion : l'adresse du bien est dans la section "Désignation du bien" ; l'adresse du propriétaire est dans la section "Mandant" / "Propriétaire".
Dans un DPE : l'adresse du bien est en haut de la première page sous "Adresse" ; l'adresse du propriétaire est dans une section "Propriétaire" séparée.
Si tu vois deux codes postaux différents dans le doc, c'est typique : un pour le bien, un pour le propriétaire — distingue-les bien.

Autres règles :
- Pour "doc_type" : choisis le type le plus précis. Dossier complet contenant DPE+plomb+amiante etc → "dossier_diagnostics"
- Pour "type_typologie" : T4 → nb_pieces=4
- Pour "annee_construction" : "Avant 1948" → 1948
- Pour les booléens : true uniquement si confirmé ; false ou null sinon
- Pour les dates au format jj/mm/yyyy, convertis en YYYY-MM-DD strict
- Pour le propriétaire : extrais les infos seulement si elles apparaissent EXPLICITEMENT dans le doc
- N'invente RIEN. Si tu n'es pas sûr, mets null.

TEXTE DU DOCUMENT :
---
{$text_truncated}
---
PROMPT;

    $payload = json_encode([
        'model'    => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user',   'content' => $user_prompt],
        ],
        'max_tokens'  => 2500,
        'temperature' => 0.0,
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
        return ['ok' => false, 'fields' => [], 'doc_type' => null, 'error' => 'Réseau : ' . $curl_error];
    }
    $data = json_decode($response, true);
    if ($http_code !== 200) {
        return ['ok' => false, 'fields' => [], 'doc_type' => null, 'error' => 'OpenAI : ' . ($data['error']['message'] ?? "HTTP $http_code")];
    }

    $content = $data['choices'][0]['message']['content'] ?? '';
    $parsed = json_decode($content, true);
    if (!is_array($parsed)) {
        if (preg_match('/\{[\s\S]+\}/m', $content, $m)) $parsed = json_decode($m[0], true);
    }
    if (!is_array($parsed)) {
        return ['ok' => false, 'fields' => [], 'doc_type' => null, 'error' => 'JSON IA non parsable'];
    }

    // Extraction du type + résumé top-level
    $docType = $parsed['doc_type'] ?? null;
    $docTitre = $parsed['doc_titre'] ?? null;
    $docDate = $parsed['doc_date'] ?? null;
    $resume = $parsed['_resume'] ?? null;

    // Aplatissement des sections en un dict simple, AVEC PRÉFIXES pour
    // éviter les collisions entre l'adresse du bien et celles du propriétaire/diagnostiqueur.
    //   _adresse.*       → no prefix (c'est l'adresse du BIEN, référence)
    //   _proprietaire.*  → préfixe `proprio_`
    //   _diagnostiqueur.*→ préfixe `diag_`
    //   _mandat.*        → no prefix (les noms sont déjà spécifiques : numero_mandat, type_mandat...)
    //   autres           → no prefix
    $sectionPrefixes = [
        '_proprietaire'   => 'proprio_',
        '_diagnostiqueur' => 'diag_',
    ];
    $flat = [];
    foreach ($parsed as $section => $val) {
        if (in_array($section, ['doc_type','doc_titre','doc_date','_resume'], true)) continue;
        if (!is_array($val)) continue;
        $prefix = $sectionPrefixes[$section] ?? '';
        foreach ($val as $k => $v) {
            // Ignore les commentaires de schéma
            if ($k === '_commentaire' || str_starts_with((string)$k, '_')) continue;
            $flat[$prefix . $k] = $v;
        }
    }

    // Nettoyage / normalisation
    $boolFields = [
        'dpe_vierge','double_vitrage','volets_roulants',
        'plomb_present','amiante_present','electricite_anomalies',
        'gaz_anomalies','termites','zone_georisque',
    ];
    $fields = [];
    foreach ($flat as $k => $v) {
        if ($v === null || $v === '' || (is_array($v) && empty($v))) continue;

        if (in_array($k, ['dpe_classe','ges_classe'], true)) {
            $vRaw = trim((string)$v);
            if (preg_match('/^vierge$/i', $vRaw)) {
                $v = 'vierge';
            } else {
                $v = strtoupper($vRaw);
                if (!preg_match('/^[A-G]$/', $v)) continue;
            }
        }
        if (in_array($k, $boolFields, true)) {
            $v = ($v === true || $v === 'true' || $v === 1 || $v === '1') ? 1 : 0;
            if ($v === 0) continue;
        }
        if (in_array($k, ['dpe_date_realisation','date_signature','date_debut','date_fin'], true)) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) continue;
        }
        // type_typologie → nb_pieces si pas déjà fourni
        if ($k === 'type_typologie' && preg_match('/^T(\d)/i', (string)$v, $mm)) {
            if (empty($flat['nb_pieces'])) $fields['nb_pieces'] = (int)$mm[1];
            continue;
        }
        $fields[$k] = $v;
    }

    return [
        'ok'       => true,
        'doc_type' => $docType,
        'doc_titre'=> $docTitre,
        'doc_date' => $docDate,
        'resume'   => $resume,
        'fields'   => $fields,
        'count'    => count($fields),
    ];
}
