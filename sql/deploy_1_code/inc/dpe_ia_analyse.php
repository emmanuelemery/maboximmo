<?php
declare(strict_types=1);

/**
 * Analyse IA d'un DPE via OpenAI GPT-4o
 *
 * Reçoit le texte brut extrait d'un PDF DPE et renvoie un JSON
 * structuré avec tous les champs détectés. Plus robuste que les regex
 * car GPT comprend le contexte (DPE 2011 vs 2021, mises en page variées,
 * rapports d'experts, certificats officiels, exports portails).
 *
 * Réutilise la même clé API que ia_analyse.php (constante OPENAI_API_KEY).
 *
 * @return array {
 *     ok      : bool,
 *     fields  : ['key' => value, ...],   // structure compatible avec le form
 *     score   : int (0-100),
 *     model   : string,
 *     error   : string
 * }
 */
function analyseDpeIA(string $text): array
{
    $api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
    // Pour l'extraction structurée DPE on force gpt-4o-mini : rapide, précis, économique.
    // Les modèles de raisonnement (gpt-5, o1, o3) gaspillent les tokens en "thinking" interne
    // et sont mal adaptés pour ce type de tâche d'extraction JSON.
    $model = defined('OPENAI_DPE_MODEL') ? OPENAI_DPE_MODEL : 'gpt-4o-mini';

    if (!$api_key) {
        return ['ok' => false, 'fields' => [], 'score' => 0, 'error' => 'OPENAI_API_KEY non configurée'];
    }
    if (strlen(trim($text)) < 50) {
        return ['ok' => false, 'fields' => [], 'score' => 0, 'error' => 'Texte trop court ou vide'];
    }

    // Tronquer pour limiter le coût (~12 000 tokens max)
    $text_truncated = mb_substr($text, 0, 50000);

    $system_prompt = "Tu es un assistant expert en diagnostics immobiliers français. "
        . "Tu analyses des dossiers de diagnostics complets (DPE, Loi Boutin/Carrez, CREP plomb, "
        . "Amiante, Électricité, ERP) et tu extrais avec précision toutes les données utiles "
        . "pour pré-remplir une fiche de bien immobilier. Tu réponds UNIQUEMENT avec du JSON valide.";

    $user_prompt = <<<PROMPT
Analyse ce texte issu d'un dossier de diagnostics immobiliers français (peut contenir DPE, mesurage Loi Boutin/Carrez, CREP plomb, amiante, électricité, ERP, etc.) et extrais TOUTES les données utiles en JSON.

Réponds UNIQUEMENT avec du JSON valide selon cette structure exacte (null si absent, ne mets PAS de champ si tu n'es pas sûr) :
{
  "_meta": {
    "type_bien": "appartement|maison|villa|immeuble|terrain|local_commercial|bureau|garage|parking|null",
    "type_typologie": "T1|T2|T3|T4|T5|T6 ou null",
    "adresse_1": "string ou null",
    "code_postal": "string 5 chiffres ou null",
    "ville": "string ou null",
    "etage": "nombre entier ou null",
    "annee_construction": "nombre entier (4 chiffres) ou null",
    "lot_principal": "string ou null"
  },
  "_surfaces": {
    "surface_habitable": "nombre décimal (m²) ou null",
    "surface_sejour": "nombre décimal (m² du séjour, salon ou séjour-cuisine) ou null",
    "surface_carrez": "nombre décimal (loi Carrez si copro) ou null",
    "surface_terrain": "nombre décimal (m² terrain pour maison/terrain) ou null",
    "surface_balcon": "nombre décimal ou null",
    "surface_terrasse": "nombre décimal ou null",
    "surface_jardin": "nombre décimal ou null",
    "surface_cave": "nombre décimal ou null",
    "surface_garage": "nombre décimal ou null"
  },
  "_pieces": {
    "nb_pieces": "nombre entier (pièces principales : séjour + chambres + bureau, hors WC/SDB/cuisine séparée)",
    "nb_chambres": "nombre entier (compter les pièces explicitement nommées 'Chambre')",
    "nb_salles_bain": "nombre entier (Salle de bain)",
    "nb_salles_eau": "nombre entier (Salle d'eau, douche)",
    "nb_wc": "nombre entier (WC indépendants)"
  },
  "_dpe": {
    "dpe_classe": "A|B|C|D|E|F|G ou null",
    "ges_classe": "A|B|C|D|E|F|G ou null",
    "dpe_valeur": "nombre entier (kWh EP/m²/an) ou null",
    "ges_valeur": "nombre entier (kg CO2/m²/an) ou null",
    "dpe_valeur_conso_primaire": "nombre ou null",
    "dpe_valeur_conso_finale": "nombre ou null",
    "dpe_date_realisation": "YYYY-MM-DD ou null",
    "dpe_version": "2011|2021 ou null (déduit de la date : avant 01/07/2021 → 2011)",
    "dpe_vierge": "true|false (true si la consommation est 'Indéterminée' ou marqué 'DPE vierge')",
    "montant_estime_depenses_min": "nombre ou null",
    "montant_estime_depenses_max": "nombre ou null",
    "date_indice_prix_energies": "YYYY-MM-DD ou null",
    "dpe_reference_certificat": "string (numéro ADEME) ou null",
    "altitude": "nombre ou null"
  },
  "_chauffage_energie": {
    "chauffage_type": "individuel|collectif|null",
    "chauffage_energie": "electricite|gaz|fioul|bois|granules|pompe a chaleur|solaire|null",
    "eau_chaude_type": "individuel|collectif|null",
    "double_vitrage": "true|false (true si double vitrage mentionné)",
    "volets_roulants": "true|false",
    "menuiseries": "bois|pvc|aluminium|mixte|null"
  },
  "_diagnostics_alertes": {
    "plomb_present": "true|false (true si CREP indique présence de revêtements contenant du plomb au-delà des seuils)",
    "plomb_classe_max": "0|1|2|3 ou null (classe la plus élevée détectée)",
    "amiante_present": "true|false (true si matériaux amiante repérés, false si 'aucun matériau')",
    "electricite_anomalies": "true|false (true si l'installation comporte des anomalies)",
    "gaz_anomalies": "true|false ou null",
    "termites": "true|false ou null",
    "erp_zone_risque": "true|false (true si dans une zone PPRn ou PPRt approuvé)",
    "erp_inondation": "true|false (true si zone inondable)",
    "erp_sismicite_zone": "1|2|3|4|5 ou null"
  },
  "_diagnostiqueur": {
    "operateur_nom": "string ou null",
    "operateur_societe": "string ou null",
    "dossier_numero": "string ou null (n° de dossier expertise, ex: 2018-10-07506)"
  },
  "_resume_bailleur": "Résumé clair en 4-6 phrases en français destiné à être envoyé au propriétaire/bailleur, expliquant les principales conclusions du dossier de diagnostics : performance énergétique, présence éventuelle de plomb/amiante, anomalies électriques, recommandations d'action prioritaires. Ton professionnel et synthétique. (string ou null)"
}

RÈGLES IMPORTANTES :
- Pour "type_bien" : un APPARTEMENT T4 → type_bien=appartement, type_typologie=T4
- Pour "annee_construction" : si "Avant 1948" ou "< 1949" → 1948
- Pour "nb_pieces" depuis un T4 : T1=1, T2=2, T3=3, T4=4, T5=5
- Pour compter chambres/sdb/wc : utilise le tableau de mesurage Loi Boutin et compte les pièces explicitement nommées
- Si surface séjour mentionnée comme "Séjour cuis", c'est OK pour surface_sejour
- Pour "dpe_vierge" : si la consommation est "Indéterminée" ou marqué "DPE vierge" → true
- Si DPE vierge, NE PAS inventer de classe DPE/GES — laisse à null
- Pour "chauffage_energie" : si "Panneaux rayonnants" ou "convecteurs" → "electricite"
- Pour le CREP : "présence de revêtements contenant du plomb au-delà des seuils" → plomb_present=true
- Pour l'amiante : "il n'a pas été repéré" → amiante_present=false
- Pour les dates au format jj/mm/yyyy, convertis en YYYY-MM-DD
- Si tu n'es PAS sûr d'une valeur, mets null. Mieux vaut omettre que se tromper.

TEXTE DU DOSSIER :
---
{$text_truncated}
---
PROMPT;

    // Détection nouveau format API (gpt-5, o1, o3 et au-delà utilisent max_completion_tokens)
    $useNewParam = preg_match('/^(gpt-5|o1|o3|gpt-4\.1)/i', $model);
    $payload = [
        'model'    => $model,
        'messages' => [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user',   'content' => $user_prompt],
        ],
        'response_format' => ['type' => 'json_object'],
    ];
    if ($useNewParam) {
        $payload['max_completion_tokens'] = 2000;
    } else {
        $payload['max_tokens']  = 2000;
        $payload['temperature'] = 0.0;
    }
    $payload = json_encode($payload);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($curl_error) {
        return ['ok' => false, 'fields' => [], 'score' => 0, 'error' => 'Erreur réseau : ' . $curl_error];
    }

    $data = json_decode($response, true);
    if ($http_code !== 200) {
        $msg = $data['error']['message'] ?? "HTTP $http_code";
        return ['ok' => false, 'fields' => [], 'score' => 0, 'error' => 'OpenAI : ' . $msg];
    }

    $content = $data['choices'][0]['message']['content'] ?? '';
    $parsed = json_decode($content, true);
    if (!is_array($parsed)) {
        if (preg_match('/\{[\s\S]+\}/m', $content, $m)) {
            $parsed = json_decode($m[0], true);
        }
    }
    if (!is_array($parsed)) {
        return ['ok' => false, 'fields' => [], 'score' => 0, 'error' => 'Réponse IA non parsable'];
    }

    // ── Aplatissement : la réponse contient des sections (_meta, _surfaces, _pieces, _dpe, …) ──
    $flat = [];
    foreach ($parsed as $section => $val) {
        if (is_array($val)) {
            foreach ($val as $k => $v) $flat[$k] = $v;
        } else {
            // Champ scalaire au top level (ex: _resume_bailleur)
            $flat[ltrim($section, '_')] = $val;
        }
    }

    // ── Booléens connus ──
    $boolFields = [
        'dpe_vierge', 'double_vitrage', 'volets_roulants',
        'plomb_present', 'amiante_present', 'electricite_anomalies',
        'erp_zone_risque', 'erp_inondation',
    ];

    $fields = [];
    foreach ($flat as $k => $v) {
        if ($v === null || $v === '' || (is_array($v) && empty($v))) continue;

        // Normalise les classes A-G
        if (in_array($k, ['dpe_classe','ges_classe'], true)) {
            $v = strtoupper(trim((string)$v));
            if (!preg_match('/^[A-G]$/', $v)) continue;
        }

        // Booléens : true/1/'true' → 1 ; false → on omet sauf cas spéciaux
        if (in_array($k, $boolFields, true)) {
            $v = ($v === true || $v === 'true' || $v === 1 || $v === '1') ? 1 : 0;
            if ($v === 0) continue;
        }

        // Dates : YYYY-MM-DD strict
        if (in_array($k, ['dpe_date_realisation','date_indice_prix_energies'], true)) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v)) continue;
        }

        // type_typologie → déduit nb_pieces si non fourni explicitement
        if ($k === 'type_typologie' && preg_match('/^T(\d)/i', (string)$v, $mm)) {
            if (!isset($flat['nb_pieces']) || empty($flat['nb_pieces'])) {
                $fields['nb_pieces'] = (int)$mm[1];
            }
            continue; // on ne stocke pas type_typologie en tant que tel
        }

        // Mappings spéciaux : alertes diagnostics → colonnes diag_*
        if ($k === 'plomb_present')         { $fields['diag_plomb_present'] = 1;         $fields['_alerte_plomb'] = 1; continue; }
        if ($k === 'plomb_classe_max')      { $fields['diag_plomb_classe_max'] = (string)$v; continue; }
        if ($k === 'amiante_present')       { $fields['diag_amiante_present'] = 1;       $fields['_alerte_amiante'] = 1; continue; }
        if ($k === 'electricite_anomalies') { $fields['diag_electricite_anomalies'] = 1; $fields['_alerte_electricite'] = 1; continue; }
        if ($k === 'gaz_anomalies')         { $fields['diag_gaz_anomalies'] = 1; continue; }
        if ($k === 'termites')              { $fields['diag_termites'] = 1; continue; }
        if ($k === 'erp_zone_risque')       { $fields['zone_georisque'] = 1; continue; }
        if ($k === 'erp_inondation')        { $fields['zone_georisque'] = 1; continue; }
        if ($k === 'erp_sismicite_zone')    { continue; }
        if ($k === 'lot_principal' && !empty($v)) { $fields['lot_principal'] = $v; continue; }

        // Diagnostiqueur
        if ($k === 'operateur_nom')     { $fields['diag_operateur_nom'] = $v; continue; }
        if ($k === 'operateur_societe') { $fields['diag_operateur_societe'] = $v; continue; }
        if ($k === 'dossier_numero')    { $fields['diag_dossier_numero'] = $v; continue; }

        // Résumé bailleur (texte libre)
        if ($k === 'resume_bailleur') { $fields['diag_resume_ia'] = (string)$v; continue; }

        $fields[$k] = $v;
    }

    // Score : on compte la couverture sur l'ensemble des familles de données
    $allKeys = [
        // Identification (poids fort)
        'type_bien' => 4, 'adresse_1' => 3, 'code_postal' => 3, 'ville' => 3,
        'annee_construction' => 3, 'etage' => 1,
        // Surfaces et pièces (poids fort)
        'surface_habitable' => 4, 'nb_pieces' => 3, 'nb_chambres' => 3,
        'nb_wc' => 2, 'nb_salles_bain' => 2, 'surface_sejour' => 1,
        // DPE (poids fort si présent, sinon dpe_vierge compense)
        'dpe_classe' => 4, 'ges_classe' => 4, 'dpe_valeur' => 3, 'ges_valeur' => 3,
        'dpe_date_realisation' => 3, 'dpe_reference_certificat' => 2,
        'dpe_vierge' => 4, // si vierge, compense l'absence de classes
        // Bonus
        'chauffage_energie' => 1, 'eau_chaude_type' => 1, 'menuiseries' => 1,
        'double_vitrage' => 1, 'volets_roulants' => 1,
        'montant_estime_depenses_min' => 1, 'montant_estime_depenses_max' => 1,
        'zone_georisque' => 1,
    ];
    $totalWeight = array_sum($allKeys);
    $reachedWeight = 0;
    foreach ($allKeys as $k => $w) {
        if (!empty($fields[$k])) $reachedWeight += $w;
    }
    $score = $totalWeight > 0 ? min(100, (int) round(($reachedWeight / $totalWeight) * 100)) : 0;

    return [
        'ok'     => true,
        'fields' => $fields,
        'score'  => $score,
        'model'  => $model,
        'error'  => '',
    ];
}
