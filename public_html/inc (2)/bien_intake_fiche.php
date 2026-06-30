<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Module d'extraction IA spécialisé — FICHES COMMERCIALES
 * (Hektor, Périclès, Poliris, Apimo, ICI, Netty, etc.)
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Une fiche commerciale contient :
 *   - Métadonnées mandat (n°, type, dates)
 *   - Données bien complètes (type, typologie, surfaces, pièces, équipements)
 *   - Adresse + secteur / quartier
 *   - Informations financières (prix vente, prix net, taxe foncière)
 *   - Propriétaire (civilité, coordonnées)
 *   - Description commerciale rédigée
 *   - Souvent photo principale en haut
 *
 * Structure typique Hektor :
 *   MANDAT ET DISPONIBILITE / PROPRIETAIRE / INFORMATIONS FINANCIERES /
 *   SECTEUR ET COMMODITES / DESCRIPTION / SURFACES / NOTES
 */

function analyseFicheIA(string $text): array
{
    $apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
    // Force gpt-4o-mini : extraction structurée → reasoning models = JSON tronqué + lent.
    $model = defined('OPENAI_FICHE_MODEL') ? OPENAI_FICHE_MODEL : 'gpt-4o-mini';

    if (!$apiKey) {
        return ['ok' => false, 'fields' => [], 'doc_type' => 'fiche', 'error' => 'OPENAI_API_KEY non configurée'];
    }
    if (strlen(trim($text)) < 50) {
        return ['ok' => false, 'fields' => [], 'doc_type' => 'fiche', 'error' => 'Texte trop court'];
    }

    $textTruncated = mb_substr($text, 0, 50000);

    $system = "Tu es un assistant expert en fiches commerciales immobilières françaises "
        . "(export des logiciels de transaction : Hektor, Périclès, Apimo, Poliris, Netty, ICI…). "
        . "Tu extrais TOUTES les données utiles au format JSON strict. "
        . "Tu réponds UNIQUEMENT avec du JSON valide.";

    $user = <<<PROMPT
Analyse cette fiche commerciale immobilière française et extrais toutes les données utiles.

Réponds UNIQUEMENT en JSON valide selon cette structure exacte (null si absent) :
{
  "doc_type": "fiche_commerciale",
  "doc_source": "hektor|pericles|apimo|poliris|netty|ici|autre (déduit du layout)",
  "doc_titre": "string court — ex: 'Fiche parking Chamalières n°450'",

  "_bien": {
    "type_bien": "appartement|maison|villa|immeuble|terrain|local_commercial|bureau|garage|parking|cave|null",
    "sous_type_bien": "string (ex: 'Box', 'Studio', 'Loft', 'Duplex') ou null",
    "type_typologie": "T1|T2|T3|T4|T5|T6 ou null",
    "designation": "string courte commerciale (titre de l'annonce) ou null",
    "reference_bien": "N° de dossier de la fiche ou null",
    "adresse_1": "adresse POSTALE (numéro + rue) du bien — ex: '15 avenue Pasteur'",
    "adresse_situation": "porte, bâtiment, box, complément d'étage ou null",
    "code_postal": "5 chiffres ou null",
    "ville": "ville du bien ou null",
    "quartier": "secteur / quartier si mentionné (ex: 'Centre Ville') ou null",
    "etage": "nombre entier (RDC=0) ou null",
    "lot_principal": "string ou null",
    "annee_construction": "nombre entier ou null",
    "nb_niveaux": "nombre entier ou null",
    "hauteur_sous_plafond": "nombre décimal (m) ou null"
  },

  "_surfaces": {
    "surface_habitable": "nombre décimal (m²) ou null",
    "surface_carrez": "nombre décimal ou null",
    "surface_sejour": "nombre décimal ou null",
    "surface_terrain": "nombre décimal ou null",
    "surface_balcon": "nombre décimal ou null",
    "surface_terrasse": "nombre décimal ou null",
    "surface_jardin": "nombre décimal ou null",
    "surface_cave": "nombre décimal ou null",
    "surface_garage": "nombre décimal ou null",
    "surface_parking": "nombre décimal ou null"
  },

  "_pieces": {
    "nb_pieces": "nombre entier ou null",
    "nb_chambres": "nombre entier ou null",
    "nb_salles_bain": "nombre entier ou null",
    "nb_salles_eau": "nombre entier ou null",
    "nb_wc": "nombre entier ou null"
  },

  "_equipements": {
    "cave": "true|false",
    "garage": "true|false",
    "parking_interieur": "nombre entier ou null",
    "parking_exterieur": "nombre entier ou null",
    "box": "true|false",
    "balcon": "true|false",
    "terrasse": "true|false",
    "jardin": "true|false",
    "piscine": "true|false",
    "ascenseur": "true|false",
    "interphone": "true|false",
    "digicode": "true|false",
    "cuisine_equipee": "true|false",
    "double_vitrage": "true|false",
    "volets_roulants": "true|false",
    "climatisation": "true|false",
    "fibre": "true|false",
    "alarme": "true|false"
  },

  "_dpe": {
    "dpe_classe": "A|B|C|D|E|F|G ou null",
    "ges_classe": "A|B|C|D|E|F|G ou null",
    "dpe_valeur": "nombre entier ou null",
    "ges_valeur": "nombre entier ou null",
    "dpe_date_realisation": "YYYY-MM-DD ou null"
  },

  "_financier": {
    "prix_vente": "nombre (prix public FAI si vente) ou null",
    "prix_net_vendeur": "nombre (prix net vendeur) ou null",
    "honoraires": "nombre ou null",
    "honoraires_charge": "acquereur|vendeur|null",
    "honoraires_pourcentage": "nombre (%) ou null",
    "loyer_hc": "nombre (€/mois) ou null",
    "charges_locatives": "nombre (€/mois) ou null",
    "depot_garantie": "nombre ou null",
    "taxe_fonciere": "nombre (€/an) ou null",
    "taxe_habitation": "nombre (€/an) ou null",
    "charges_annuelles_copro": "nombre (€/an) ou null"
  },

  "_mandat": {
    "numero_mandat": "N° de mandat ou null",
    "type_mandat": "vente|location|gestion|recherche ou null (déduit de 'Type d'offre')",
    "nature_mandat": "simple|exclusif|semi ou null (déduit de 'Type de mandat')",
    "exclusif": "true|false (true si 'EXCLUSIF' mentionné dans le type de mandat)",
    "date_signature": "YYYY-MM-DD (date d'enregistrement) ou null",
    "date_debut": "YYYY-MM-DD (date de début) ou null",
    "date_fin": "YYYY-MM-DD (date de fin) ou null"
  },

  "_proprietaire": {
    "_commentaire": "PROPRIÉTAIRE RÉEL uniquement. IGNORE REGIE EMERY / EMERY / cabinets / agences.",
    "type_personne": "physique|morale ou null",
    "civilite": "M.|Mme|Mlle|Dr ou null",
    "nom": "nom du propriétaire réel ou null (IGNORE les agences)",
    "prenom": "prenom ou null",
    "societe": "SCI/SARL réelle ou null (pas une régie/agence)",
    "adresse_1": "adresse personnelle du bailleur ou null",
    "code_postal": "string ou null",
    "ville": "string ou null",
    "telephone": "string (ex: 'portable 06...') ou null",
    "email": "string ou null"
  },

  "_agence": {
    "_commentaire": "Agence qui a la fiche (généralement dans le footer). C'est le MANDATAIRE.",
    "nom_agence": "string ou null",
    "email_agence": "string ou null",
    "telephone_agence": "string ou null",
    "adresse_agence": "string ou null",
    "syndic_bien": "string (si un syndic est mentionné) ou null"
  },

  "description": "string — description commerciale rédigée complète (conserve la mise en forme si possible)",
  "notes": "string — notes internes éventuelles ou null",
  "_resume": "string court (2-3 phrases) résumant le bien"
}

RÈGLES IMPORTANTES :

⚠️ DÉTECTION DU TYPE DE BIEN :
- Si la fiche parle de "PARKING", "Pkg couvert", "Box" → type_bien=parking, sous_type_bien=Box/Couvert/Extérieur
- Si "APPARTEMENT" ou "T2/T3..." → type_bien=appartement + type_typologie
- Si "MAISON" ou "VILLA" → type_bien=maison
- Si "LOCAL" ou "COMMERCE" → type_bien=local_commercial

⚠️ TYPE DE MANDAT (FICHES HEKTOR) :
- "Type d'offre : Vente" → type_mandat=vente (champ _mandat.type_mandat)
- "Type d'offre : Location" → type_mandat=location
- "Type de mandat : EXCLUSIF CONTACT" → nature_mandat=exclusif, exclusif=true
- "Type de mandat : SIMPLE" → nature_mandat=simple, exclusif=false
- "Type de mandat : SEMI-EXCLUSIF" → nature_mandat=semi

⚠️ DATES :
- Format Hektor courant : 'dd-mm-yyyy' ou 'dd-mm-yy' → conversion YYYY-MM-DD
- Si année à 2 chiffres (26), ajoute 20 devant (2026)

⚠️ FINANCIER :
- "Prix vente public" → _financier.prix_vente (FAI)
- "Prix net vendeur" → _financier.prix_net_vendeur
- "Taxe foncière" → _financier.taxe_fonciere
- Nettoie les nombres (retire €, espaces, virgules → point décimal)

⚠️ PROPRIÉTAIRE (CRITIQUE) :
- "Civilité : M. MOULIN FABRICE" → civilite=M., nom=MOULIN, prenom=FABRICE
- IGNORE ABSOLUMENT : REGIE EMERY, EMERY, Emery Immobilier, Cabinet EMERY, Agence EMERY
- Si le propriétaire mentionné EST l'agence → nom=null
- L'AGENCE qui tient la fiche est dans _agence (footer du PDF)

⚠️ ADRESSE :
- "Adresse du bien : 15 avenue Pasteur" → adresse_1=15 avenue Pasteur
- "Ville : Chamalières 63400" → ville=Chamalières, code_postal=63400
- NE JAMAIS confondre avec l'adresse de l'agence (footer)

⚠️ SECTEUR / QUARTIER :
- "Secteur : Centre Ville" → quartier=Centre Ville

⚠️ DESCRIPTION :
- Conserve la description commerciale COMPLÈTE dans "description"
- Si saut de ligne, remplace par \\n (JSON)

⚠️ ÉQUIPEMENTS (booléens) :
- true UNIQUEMENT si confirmé (valeur > 0, "OUI", "Double Flux", présence d'un champ numérique non nul…)
- "Cave : NON" → cave=false
- "Nombre de parkings intérieur : 0" → parking_interieur=0 (le booléen sera fausse via count)
- "Nombre de parkings intérieur : 2" → parking_interieur=2

Texte de la fiche à analyser :
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
        $payloadArr['max_completion_tokens'] = 3000;
    } else {
        $payloadArr['max_tokens']  = 3000;
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

    if ($curlErr) return ['ok' => false, 'fields' => [], 'doc_type' => 'fiche_commerciale', 'error' => 'Réseau : ' . $curlErr];
    if ($httpCode !== 200) {
        $errBody = json_decode((string)$response, true);
        $apiMsg  = $errBody['error']['message'] ?? substr((string)$response, 0, 300);
        error_log('[analyseFicheIA] HTTP ' . $httpCode . ' OpenAI : ' . $apiMsg);
        return ['ok' => false, 'fields' => [], 'doc_type' => 'fiche_commerciale',
                'error' => 'OpenAI HTTP ' . $httpCode . ' : ' . $apiMsg];
    }

    $data = json_decode((string)$response, true);
    $content = (string)($data['choices'][0]['message']['content'] ?? '');
    $parsed = json_decode($content, true);
    if (!is_array($parsed)) {
        return ['ok' => false, 'fields' => [], 'doc_type' => 'fiche_commerciale', 'error' => 'Réponse IA non-JSON'];
    }

    // ─── Aplatissement avec préfixes cohérents ──
    $flat = [];
    foreach ($parsed as $section => $val) {
        if (is_array($val)) {
            foreach ($val as $k => $v) {
                if ($k === '_commentaire') continue;
                // Préfixes
                if ($section === '_proprietaire' || $section === '_bailleur') {
                    if (!str_starts_with($k, 'proprio_')) $k = 'proprio_' . $k;
                } elseif ($section === '_mandat') {
                    if (!in_array($k, ['numero_mandat','type_mandat','nature_mandat','exclusif'], true)) {
                        $k = 'mandats_' . $k;
                    }
                } elseif ($section === '_agence') {
                    // Pas de préfixe spécifique, mais on conserve dans $flat pour info
                    $k = 'agence_' . $k;
                }
                $flat[$k] = $v;
            }
        } else {
            $flat[ltrim($section, '_')] = $val;
        }
    }

    // Normalisation : retire les champs vides
    foreach ($flat as $k => $v) {
        if ($v === null || $v === '' || (is_array($v) && empty($v))) {
            unset($flat[$k]);
        }
    }

    // Cas spécial : honoraires calculés depuis prix_vente - prix_net_vendeur
    if (!isset($flat['honoraires']) && isset($flat['prix_vente'], $flat['prix_net_vendeur'])) {
        $flat['honoraires'] = (float)$flat['prix_vente'] - (float)$flat['prix_net_vendeur'];
    }

    return [
        'ok'        => true,
        'doc_type'  => (string)($parsed['doc_type'] ?? 'fiche_commerciale'),
        'doc_source'=> (string)($parsed['doc_source'] ?? ''),
        'doc_titre' => (string)($parsed['doc_titre'] ?? ''),
        'fields'    => $flat,
        'raw'       => $parsed,
        'count'     => count($flat),
    ];
}
