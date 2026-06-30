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

    // Tronquer agressivement : un DPE/dossier diagnostic a toutes les infos utiles
    // dans les 20–25k premiers chars (page de garde + classes + conso + frais + diag).
    // Au-delà ce sont des annexes / fiches techniques qui ne contiennent rien de structuré.
    // Gain de temps : ~30-50% sur le first-call OpenAI (moins de tokens à traiter).
    $text_truncated = mb_substr($text, 0, 25000);

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
    "adresse_1": "ADRESSE DU BIEN DIAGNOSTIQUÉ UNIQUEMENT : numéro + voie (ex: '112 Rue Montesquieu'). ⚠️ INTERDIT : adresse du DIAGNOSTIQUEUR (cabinet d'expertise en en-tête : LYON ETUDES EXPERTISES, EXPERTIS, ALLODIAGNOSTIC, etc.), du PROPRIÉTAIRE, de l'AGENCE/RÉGIE. L'adresse du bien est typiquement sous 'DÉSIGNATION DU BÂTIMENT', 'Adresse du logement', 'IMMEUBLE EXPERTISÉ' — JAMAIS dans le bandeau d'en-tête du diagnostiqueur. Si plusieurs adresses dans le PDF, prendre celle du LOGEMENT/APPARTEMENT, jamais celle du CABINET.",
    "adresse_situation": "Compléments de situation dans l'immeuble : porte, cage, allée, bâtiment, escalier (ex: 'Porte F', 'Bâtiment A Escalier 2', 'Cage C Allée 3'). Null si aucun complément.",
    "code_postal": "string 5 chiffres du BIEN (jamais celui du cabinet diagnostiqueur)",
    "ville": "ville du BIEN (jamais celle du cabinet diagnostiqueur)",
    "etage": "nombre entier ou null",
    "annee_construction": "nombre entier (4 chiffres) ou null",
    "lot_principal": "string ou null (numéro de lot copropriété)"
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
    "dpe_valeur": "nombre entier (kWh EP/m²/an, consommation réelle) ou null",
    "ges_valeur": "nombre entier (kg CO2/m²/an, estimation des émissions) ou null",
    "dpe_valeur_conso_primaire": "nombre (kWhEP total annuel — TOTAL de toutes les énergies cumulées, colonne 'Consommations en énergie primaire') ou null",
    "dpe_valeur_conso_finale": "nombre (kWhEF total annuel — TOTAL de toutes les énergies cumulées, colonne 'Consommations en énergies finales') ou null",
    "frais_annuels_energie": "nombre (€ total annuel, colonne 'Frais annuels d'énergie' — somme de toutes les lignes + abonnements inclus si indiqué) ou null",
    "dpe_date_realisation": "YYYY-MM-DD ou null",
    "dpe_version": "2011|2021 ou null (déduit de la date : avant 01/07/2021 → 2011)",
    "dpe_vierge": "true|false (true si la consommation est 'Indéterminée' ou marqué 'DPE vierge')",
    "montant_estime_depenses_min": "nombre ou null",
    "montant_estime_depenses_max": "nombre ou null",
    "date_indice_prix_energies": "YYYY-MM-DD ou null",
    "dpe_reference_certificat": "string (numéro ADEME) ou null",
    "altitude": "nombre ou null"
  },
  "_proprietaire": {
    "proprio_nom": "Nom du PROPRIÉTAIRE RÉEL du bien (la personne qui possède l'immeuble/appartement diagnostiqué). ⚠️ INTERDIT : le nom du LOCATAIRE (Ferret, Saby…) qu'on trouve dans un bail, le nom du DIAGNOSTIQUEUR (Catel, Mickaël…), le nom de l'AGENCE/RÉGIE (EMERY, REGIE EMERY, Régie Ferret…), du CABINET (Lyon Etudes Expertises…). Cherche sous 'PROPRIÉTAIRE', 'Désignation du propriétaire', 'Maître d'ouvrage'. Si on lit 'REGIE EMERY' ou un cabinet → null.",
    "proprio_prenom": "Prénom du propriétaire RÉEL ou null",
    "proprio_societe": "Nom de la société SI le propriétaire est une personne morale (SCI, SARL, SCP…) MAIS JAMAIS une régie/agence/cabinet/expertise — sinon null",
    "proprio_adresse_1": "Adresse postale du propriétaire ou null",
    "proprio_code_postal": "string 5 chiffres ou null",
    "proprio_ville": "string ou null",
    "proprio_telephone": "string ou null",
    "proprio_email": "string ou null"
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
    "operateur_nom": "Nom de la personne physique qui a SIGNÉ le diagnostic (ex: 'CATEL Mickaël'). ⚠️ JAMAIS le propriétaire ni le locataire — uniquement le technicien certifié. Cherche sous 'OPÉRATEUR DE DIAGNOSTIC', 'Diagnostiqueur', 'Signataire'.",
    "operateur_societe": "Nom du CABINET d'expertise (ex: 'LYON ETUDES EXPERTISES', 'ALLODIAGNOSTIC'). ⚠️ JAMAIS une régie/agence immobilière. C'est l'entité dont l'adresse figure en EN-TÊTE du PDF.",
    "dossier_numero": "string ou null (n° de dossier expertise, ex: 2018-10-07506)"
  },
  "_resume_bailleur": "Résumé clair en 4-6 phrases en français destiné à être envoyé au propriétaire/bailleur, expliquant les principales conclusions du dossier de diagnostics : performance énergétique, présence éventuelle de plomb/amiante, anomalies électriques, recommandations d'action prioritaires. Ton professionnel et synthétique. (string ou null)"
}

RÈGLES IMPORTANTES :
- Pour "type_bien" : un APPARTEMENT T4 → type_bien=appartement, type_typologie=T4
- Pour "annee_construction" : si "Avant 1948" ou "< 1949" → 1948, "Avant 1975" → 1975, etc. (prendre la borne haute)
- Pour "nb_pieces" depuis un T4 : T1=1, T2=2, T3=3, T4=4, T5=5
- Pour compter chambres/sdb/wc : utilise le tableau de mesurage Loi Boutin et compte les pièces explicitement nommées
- Si surface séjour mentionnée comme "Séjour cuis", c'est OK pour surface_sejour
- Pour "etage" : "RDC"/"Rez-de-chaussée"/"Rez de chaussée" → 0 ; "1er"/"1ère"/"Premier" → 1 ; "2ème"/"Deuxième" → 2 ; etc.
- Pour "dpe_vierge" : true UNIQUEMENT si la consommation énergétique est "Indéterminée" / "Non renseignée" / le DPE est explicitement marqué "vierge" ; false si des valeurs chiffrées sont présentes
- Pour "dpe_classe" / "ges_classe" : déduis la classe de la valeur si nécessaire :
    • DPE : A(≤50), B(51-90), C(91-150), D(151-230), E(231-330), F(331-450), G(>450) kWhEP/m²/an
    • GES : A(≤5), B(6-10), C(11-20), D(21-35), E(36-55), F(56-80), G(>80) kgCO2/m²/an
- Pour "chauffage_type" : "individuel" si chaudière/radiateur/pompe à chaleur DANS le logement ; "collectif" si chauffage urbain/immeuble
- Pour "chauffage_energie" : extrais l'énergie PRINCIPALE (celle qui chauffe le plus) ; si mention "Gaz Naturel" → "gaz"
- Pour "menuiseries" : matériau DOMINANT des fenêtres (ignorer les portes) ; si "métal avec rupteur" → "aluminium" ; si mixte → "mixte"
- Pour "ventilation" : si "VMC Double Flux" mentionné, considère que c'est équipé VMC (utilisé par le bien)
- Pour "dpe_valeur_conso_finale" / "dpe_valeur_conso_primaire" / "frais_annuels_energie" :
    • Cherche les TOTAUX, pas les lignes détaillées. Si plusieurs énergies (Gaz + Électricité par exemple), SOMME les valeurs.
    • Ex : "Gaz Naturel : 8 283 kWhEF" + "Électricité : 1 200 kWhEF" → dpe_valeur_conso_finale = 9483
    • Pour frais_annuels_energie : ADDITIONNE tous les coûts annuels de toutes les lignes (abonnements inclus si indiqué "abonnement de XX € inclus")
    • Ex : 482 € (gaz) + 187 € (abonnement) = 669 € total
- Pour "proprio_*" (propriétaire RÉEL) :
    • Lis la section "PROPRIÉTAIRE" ou "Nom du propriétaire"
    • IGNORE et NE JAMAIS extraire les noms suivants comme propriétaires : REGIE EMERY, EMERY, Agence EMERY, Cabinet EMERY, EMERY Immobilier, Régie, Agence, Cabinet, Administrateur de biens, Syndic (ce sont des mandataires/gestionnaires, pas les vrais propriétaires)
    • Si le doc indique "REGIE EMERY" comme propriétaire, mets proprio_nom = null (on ne peut pas déduire le vrai bailleur)
    • Sinon extrais nom/prénom OU nom de société (SCI, SARL, SCP…) du propriétaire réel
    • Exemple : "Mr et Mme MICHELLIER - VINOUZE" → proprio_nom=MICHELLIER, proprio_prenom=null (pas de prénom clair)
    • Exemple : "SCI FOCH" → proprio_societe=SCI FOCH, proprio_nom=null
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
    // ⚠️ Régression 20/04 : le prompt a grossi (section _proprietaire + adresse_situation
    // + résumé bailleur 4-6 phrases + règles étendues) mais max_tokens était resté à 2000.
    // Résultat : la réponse JSON était tronquée → json_decode échoue → IA retourne ok=false
    // silencieusement → seuls les regex tournent, score retombe à ~40%.
    // 5000 tokens couvrent confortablement la sortie complète (~3500 tokens observés).
    if ($useNewParam) {
        $payload['max_completion_tokens'] = 5000;
    } else {
        $payload['max_tokens']  = 5000;
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

    $content      = $data['choices'][0]['message']['content'] ?? '';
    $finishReason = $data['choices'][0]['finish_reason'] ?? '';
    $parsed = json_decode($content, true);
    if (!is_array($parsed)) {
        if (preg_match('/\{[\s\S]+\}/m', $content, $m)) {
            $parsed = json_decode($m[0], true);
        }
    }
    if (!is_array($parsed)) {
        $hint = ($finishReason === 'length')
            ? ' (réponse tronquée — max_tokens dépassé)'
            : '';
        error_log('[dpe_ia_analyse] JSON non parsable, finish_reason=' . $finishReason
            . ' content_len=' . strlen($content));
        return ['ok' => false, 'fields' => [], 'score' => 0, 'error' => 'Réponse IA non parsable' . $hint];
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
