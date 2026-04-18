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
/**
 * Détecte le type de document via des patterns regex sur les premiers 3000 chars.
 * Renvoie 'diag' | 'mandat' | 'bail' | 'fiche' | 'titre' | 'inconnu'.
 * Économique (pas d'appel IA), très rapide. Pour le cas ambigu → 'inconnu'
 * qui provoque un fallback sur l'extraction générique.
 */
function detectBienIntakeDocType(string $text): string
{
    $head = mb_strtolower(mb_substr($text, 0, 3000));

    $score = ['diag' => 0, 'mandat' => 0, 'bail' => 0, 'fiche' => 0, 'titre' => 0];

    // DIAGNOSTICS (DPE, plomb, amiante, électricité, gaz, termites, ERP, Loi Boutin, Carrez)
    foreach (['diagnostic de performance', 'dpe', 'attestation de surface', 'loi boutin', 'loi carrez',
              'numero d\'enregistrement ademe', 'constat de risque', 'amiante', 'plomb (crep)',
              'état des risques', 'ernt', 'diagnostic termites', 'mesurage'] as $kw) {
        if (str_contains($head, $kw)) $score['diag'] += 3;
    }

    // MANDAT
    foreach (['mandat de vente', 'mandat de location', 'mandat de gestion', 'mandat exclusif',
              'mandat simple', 'numéro de mandat', 'numero de mandat', 'je soussigné', 'mandant',
              'articles 6 et 7 de la loi hoguet', 'carte professionnelle'] as $kw) {
        if (str_contains($head, $kw)) $score['mandat'] += 3;
    }

    // BAIL
    foreach (['contrat de bail', 'bail d\'habitation', 'bail commercial', 'bail mobilité',
              'le bailleur', 'le preneur', 'durée du bail', 'préavis', 'état des lieux d\'entrée'] as $kw) {
        if (str_contains($head, $kw)) $score['bail'] += 3;
    }

    // FICHE commerciale
    foreach (['fiche commerciale', 'descriptif commercial', 'à vendre', 'a vendre',
              'à louer', 'a louer', 'honoraires de vente'] as $kw) {
        if (str_contains($head, $kw)) $score['fiche'] += 2;
    }

    // TITRE de propriété
    foreach (['acte authentique', 'titre de propriété', 'me notaire', 'par-devant maître'] as $kw) {
        if (str_contains($head, $kw)) $score['titre'] += 3;
    }

    arsort($score);
    $best = array_key_first($score);
    $bestScore = $score[$best];
    // Seuil minimum pour confiance
    return $bestScore >= 3 ? $best : 'inconnu';
}

/**
 * Point d'entrée principal — DISPATCHER.
 * Détecte le type de document et délègue au module spécialisé.
 * Retombe sur l'extraction générique (code historique) si le type est inconnu.
 */
function analyseBienIntakeIA(string $text): array
{
    // ─── 1. Détection rapide du type (regex, pas d'IA) ─────────
    $docType = detectBienIntakeDocType($text);

    // ─── 2. Route vers le module spécialisé ────────────────────
    switch ($docType) {
        case 'diag':
            require_once __DIR__ . '/bien_intake_diag.php';
            $r = analyseDiagIA($text);
            $r['doc_type'] = $r['doc_type'] ?? 'diag';
            $r['router']   = 'diag';
            return $r;

        case 'mandat':
            require_once __DIR__ . '/bien_intake_mandat.php';
            $r = analyseMandatIA($text);
            $r['router']   = 'mandat';
            return $r;

        // case 'bail':   → inc/bien_intake_bail.php   (à créer)
        // case 'fiche':  → inc/bien_intake_fiche.php  (à créer)
        // case 'titre':  → inc/bien_intake_titre.php  (à créer)

        default:
            // Fallback : extraction générique ci-dessous
            return analyseBienIntakeIAGeneric($text);
    }
}

/**
 * Extraction générique (code historique) — utilisé en fallback quand
 * le type de document n'a pas pu être détecté précisément.
 *
 * À terme, tous les types devraient avoir leur module dédié et cette
 * fonction ne servira plus qu'en ultime secours.
 */
function analyseBienIntakeIAGeneric(string $text): array
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
    "dpe_classe": "A|B|C|D|E|F|G ou null (si absent, déduis-le de la valeur DPE : A≤50, B≤90, C≤150, D≤230, E≤330, F≤450, G>450 kWhEP/m².an)",
    "ges_classe": "A|B|C|D|E|F|G ou null (déduction : A≤5, B≤10, C≤20, D≤35, E≤55, F≤80, G>80 kgCO2/m².an)",
    "dpe_valeur": "nombre entier (kWh EP/m²/an, consommation réelle) ou null",
    "ges_valeur": "nombre entier (kg CO2/m²/an, émissions estimées) ou null",
    "dpe_valeur_conso_primaire": "nombre entier (kWhEP TOTAL annuel, SOMME de toutes les énergies colonne 'Consommations en énergie primaire') ou null",
    "dpe_valeur_conso_finale": "nombre entier (kWhEF TOTAL annuel, SOMME de toutes les énergies colonne 'Consommations en énergies finales') ou null",
    "frais_annuels_energie": "nombre (€ TOTAL annuel, SOMME colonne 'Frais annuels d'énergie' + abonnements inclus) ou null",
    "date_indice_prix_energies": "YYYY-MM-DD ou null",
    "altitude": "nombre entier (m) ou null",
    "dpe_date_realisation": "YYYY-MM-DD ou null",
    "dpe_version": "2011|2021 ou null",
    "dpe_vierge": "true|false (true UNIQUEMENT si 'Indéterminée' / 'DPE vierge' explicite)",
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
    "_commentaire": "PROPRIÉTAIRE RÉEL uniquement. NE JAMAIS extraire les noms d'agences / régies (REGIE EMERY, EMERY, Cabinet, Agence, Administrateur de biens, Syndic). Ces entités sont des MANDATAIRES, PAS les vrais bailleurs. Si le doc indique 'REGIE EMERY' comme propriétaire → mets nom=null. Extrais uniquement un particulier (Mr/Mme Dupont) ou une SCI/SARL réelle (SCI FOCH). Adresse = adresse PERSONNELLE du propriétaire (siège SCI, domicile particulier), jamais celle de l'agence.",
    "nom": "string ou null (IGNORE : REGIE EMERY, EMERY, Agence, Cabinet, Régie, Administrateur, Syndic)",
    "prenom": "string ou null",
    "civilite": "M.|Mme|null",
    "type_personne": "physique|morale|null",
    "societe": "string ou null (raison sociale SCI/SARL réelle — jamais une régie/agence)",
    "adresse_1": "string ou null (adresse PERSONNELLE du bailleur, pas celle de l'agence)",
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
- Pour "annee_construction" :
    • "Avant 1948" / "< 1949" → 1948 (borne haute avant 1948)
    • "Avant 1975" → 1975
    • "Avant 2000" → 2000
    • Toujours l'année de la borne haute (celle AVANT laquelle il est construit)
- Pour "etage" : "RDC" / "Rez-de-chaussée" → 0, "1er" → 1, "2ème" → 2, etc.
- Pour les booléens : true uniquement si confirmé ; false ou null sinon
- Pour les dates au format jj/mm/yyyy, convertis en YYYY-MM-DD strict
- Pour "dpe_valeur_conso_finale" / "dpe_valeur_conso_primaire" / "frais_annuels_energie" :
    SOMME les lignes si plusieurs énergies. Ex :
    - Gaz 8283 kWhEF + Électricité 1200 kWhEF → dpe_valeur_conso_finale = 9483
    - Frais : 482€ gaz + 187€ abonnement = 669€
- Pour "chauffage_energie" : identifié dans la colonne "Moyenne annuelle des consommations" (ex : "Facture Gaz Naturel" → gaz)
- Pour "menuiseries" : matériau DOMINANT des fenêtres (ignorer portes) ; "métal avec rupteur" → "aluminium" ; mixte → "mixte"
- Pour le propriétaire :
    • Extrais uniquement si un NOM RÉEL apparaît (personne physique ou SCI/SARL)
    • ⚠️ IGNORE ABSOLUMENT : REGIE EMERY, EMERY, Cabinet EMERY, Agence EMERY, Agence immobilière, Régie, Cabinet, Administrateur de biens, Syndic, Gestionnaire — ce sont des MANDATAIRES, pas des propriétaires
    • Si le doc indique comme propriétaire l'une de ces entités → nom=null (on ne connaît pas le vrai bailleur)
    • Exemple valide : "Mr MICHELLIER-VINOUZE" → nom=MICHELLIER-VINOUZE ; "SCI FOCH" → societe=SCI FOCH
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

    $dateFields = [
        'dpe_date_realisation','date_signature','date_debut','date_fin',
        'date_indice_prix_energies',
    ];

    $intFields = [
        'annee_construction','etage','nb_pieces','nb_chambres','nb_salles_bain','nb_salles_eau','nb_wc',
        'dpe_valeur','ges_valeur','altitude',
    ];

    $floatFields = [
        'surface_habitable','surface_carrez','surface_sejour','surface_terrain',
        'surface_balcon','surface_terrasse','surface_jardin','surface_cave','surface_garage',
        'montant_estime_depenses_min','montant_estime_depenses_max',
        'dpe_valeur_conso_primaire','dpe_valeur_conso_finale',
        'prix_vente','loyer_hc','charges_locatives','depot_garantie','honoraires',
    ];

    $toFloat = static function ($v): ?float {
        if (is_int($v) || is_float($v)) return (float)$v;
        $s = trim((string)$v);
        if ($s === '') return null;
        $s = str_replace(["\xC2\xA0", ' '], '', $s);
        $s = str_replace(',', '.', $s);
        $s = preg_replace('/[^0-9\.\-]/', '', $s);
        if ($s === '' || $s === '-' || $s === '.') return null;
        return is_numeric($s) ? (float)$s : null;
    };

    $toInt = static function ($v) use ($toFloat): ?int {
        $f = $toFloat($v);
        if ($f === null) return null;
        return (int)round($f);
    };

    $toDate = static function ($v): ?string {
        $s = trim((string)$v);
        if ($s === '') return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
        if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})$/', $s, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        return null;
    };

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

        if (in_array($k, $dateFields, true)) {
            $d = $toDate($v);
            if ($d === null) continue;
            $v = $d;
        }

        if (in_array($k, $intFields, true)) {
            $n = $toInt($v);
            if ($n === null) continue;
            $v = $n;
        } elseif (in_array($k, $floatFields, true)) {
            $n = $toFloat($v);
            if ($n === null) continue;
            $v = $n;
        }

        if ($k === 'type_typologie' && preg_match('/^T(\d)/i', (string)$v, $mm)) {
            if (empty($flat['nb_pieces'])) $fields['nb_pieces'] = (int)$mm[1];
            continue;
        }

        $fields[$k] = $v;
    }

    // Fallback regex (utile si l'IA oublie certains champs DPE)
    if (in_array((string)$docType, ['dpe','dossier_diagnostics'], true)) {
        $t = $text_truncated;

        if (empty($fields['dpe_date_realisation']) && preg_match('/date\s+de\s+(?:r[ée]alisation|r[ée]alis[ée])\s+(?:du\s+)?dpe\s*[:\-]?\s*(\d{2}[\/\-]\d{2}[\/\-]\d{4}|\d{4}-\d{2}-\d{2})/i', $t, $m)) {
            $d = $toDate($m[1]);
            if ($d) $fields['dpe_date_realisation'] = $d;
        }

        if (empty($fields['date_indice_prix_energies']) && preg_match('/(?:prix\s+(?:moyens\s+)?des\s+[ée]nergies\s+index[ée]s?\s+au|date\s+d[\'’]indice\s+des\s+prix\s+(?:des\s+)?[ée]nergies)\s*[:\-]?\s*(\d{2}[\/\-]\d{2}[\/\-]\d{4}|\d{4}-\d{2}-\d{2})/i', $t, $m)) {
            $d = $toDate($m[1]);
            if ($d) $fields['date_indice_prix_energies'] = $d;
        }

        if (empty($fields['dpe_valeur_conso_primaire']) && preg_match('/(?:consommation|conso)[^\n]{0,80}[ée]nergie\s+primaire[^\d]{0,40}(\d{2,4}(?:[\.,]\d{1,2})?)\s*kwh/i', $t, $m)) {
            $n = $toFloat($m[1]);
            if ($n !== null) $fields['dpe_valeur_conso_primaire'] = $n;
        }

        if (empty($fields['dpe_valeur_conso_finale']) && preg_match('/(?:consommation|conso)[^\n]{0,80}[ée]nergie\s+finale[^\d]{0,40}(\d{2,4}(?:[\.,]\d{1,2})?)\s*kwh/i', $t, $m)) {
            $n = $toFloat($m[1]);
            if ($n !== null) $fields['dpe_valeur_conso_finale'] = $n;
        }

        if (empty($fields['altitude']) && preg_match('/\bAltitude\s*[:\-]?\s*(\d{1,4})\s*m\b/i', $t, $m)) {
            $n = $toInt($m[1]);
            if ($n !== null) $fields['altitude'] = $n;
        }

        if (empty($fields['dpe_reference_certificat']) && preg_match('/\b(?:N[°o]\s*(?:ADEME|d[\'’]enregistrement)|num[ée]ro\s+ademe)\s*[:\-]?\s*([A-Z0-9\-]{6,})\b/i', $t, $m)) {
            $fields['dpe_reference_certificat'] = $m[1];
        }

        if (!isset($fields['dpe_valeur']) && isset($fields['dpe_valeur_conso_primaire'])) {
            $fields['dpe_valeur'] = (int)round((float)$fields['dpe_valeur_conso_primaire']);
        }
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

