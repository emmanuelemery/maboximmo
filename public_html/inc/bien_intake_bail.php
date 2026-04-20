<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Module d'extraction IA spécialisé — BAUX (tous types)
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Un bail peut couvrir :
 *   - HABITATION (nu/meublé, loi 6 juillet 1989)
 *   - COMMERCIAL (3-6-9, dérogatoire, précaire — L.145-1 code de commerce)
 *   - PROFESSIONNEL (professions libérales, code civil art. 1713+)
 *   - CIVIL (usage non réglementé)
 *   - TERRAIN nu / agricole
 *   - PARKING / GARAGE isolé
 *   - MEUBLÉ TOURISTIQUE
 *
 * Discriminateur : bail_nature. Le prompt le détecte en premier puis
 * enrichit les champs spécifiques (zone tendue, ILC vs IRL, etc.).
 *
 * Sections JSON :
 *   _bail        → métadonnées bail (nature, type, dates)     préfixe: bail_
 *   _loyer       → loyer, charges, dépôt, indexation          préfixe: aucun
 *   _bien        → adresse + caractéristiques bien            préfixe: aucun
 *   _bailleur    → vrai propriétaire (filtre REGIE/EMERY)     préfixe: proprio_
 *   _locataire   → locataire principal                        préfixe: locataire_
 *   _caution     → garant                                     préfixe: caution_
 *   _multi       → colocataires, cautions multiples, etc.     → stockés en JSON metadata
 */

function analyseBailIA(string $text): array
{
    $apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
    $model  = defined('OPENAI_TEXT_MODEL') ? OPENAI_TEXT_MODEL : 'gpt-4o';

    if (!$apiKey) {
        return ['ok' => false, 'fields' => [], 'doc_type' => 'bail', 'error' => 'OPENAI_API_KEY non configurée'];
    }
    if (strlen(trim($text)) < 50) {
        return ['ok' => false, 'fields' => [], 'doc_type' => 'bail', 'error' => 'Texte trop court'];
    }

    $textTruncated = mb_substr($text, 0, 60000);

    $system = "Tu es un assistant expert en baux immobiliers français (habitation, commercial, "
        . "professionnel, civil, terrain, parking, meublé touristique). Tu extrais TOUTES les "
        . "données utiles au format JSON strict pour pré-remplir une fiche de bail. "
        . "Tu réponds UNIQUEMENT avec du JSON valide.";

    $user = <<<PROMPT
Analyse ce bail immobilier français. Identifie d'abord sa NATURE (habitation/commercial/…), puis extrais toutes les données utiles.

Réponds UNIQUEMENT en JSON valide selon cette structure (null si absent) :
{
  "doc_type": "bail",
  "doc_titre": "string court — ex: 'Bail habitation T1bis Lyon 7e - CLARY/COURT'",
  "doc_date": "YYYY-MM-DD (date de signature)",

  "_bail": {
    "bail_nature": "habitation|commercial|professionnel|civil|terrain|parking|meuble_touristique|autre",
    "bail_type": "nu|meublé|colocation|étudiant|mobilité|3-6-9|dérogatoire|précaire|ferme|autre",
    "usage_bien": "résidence principale|résidence secondaire|activité commerciale|profession libérale|null",
    "destination_activite": "string (description activité commerciale / profession) ou null",
    "reference_bail": "n° mandat de gestion ou n° dossier ou null",
    "date_signature": "YYYY-MM-DD ou null",
    "date_prise_effet": "YYYY-MM-DD ou null",
    "duree_mois": "nombre entier (mois) — 36 pour habit 3 ans, 108 pour commercial 9 ans",
    "date_fin": "YYYY-MM-DD ou null",
    "periode_triennale": "true|false (commercial uniquement)",
    "reconduction": "tacite|non|étudiant 9 mois|null",
    "clause_resolutoire": "true|false"
  },

  "_loyer": {
    "loyer_mensuel_hc": "nombre décimal (€/mois hors charges) ou null",
    "complement_loyer": "nombre (€/mois — zone tendue uniquement) ou null",
    "charges_mensuelles": "nombre (€/mois) ou null",
    "charges_type": "provisions|forfait ou null",
    "total_mensuel": "nombre (loyer + charges) ou null",
    "tva_applicable": "true|false",
    "tva_taux": "nombre (%) ou null",
    "indice_type": "IRL|ILC|ILAT|ICC|autre (IRL=habitation, ILC=commercial commerce, ILAT=commercial bureaux)",
    "indice_trimestre": "ex 'T2 2025' ou null",
    "indice_valeur": "nombre décimal ou null",
    "date_revision_jour_mois": "string 'JJ-MM' ou null",
    "zone_tendue": "true|false",
    "loyer_reference": "nombre (€/m² — zone tendue) ou null",
    "loyer_reference_majore": "nombre (€/m² — zone tendue) ou null",
    "depot_garantie": "nombre (€) ou null",
    "nb_termes_garantie": "nombre entier (1 pour habit, 2 meublé/commercial) ou null",
    "honoraires_bailleur_ttc": "nombre (€ TTC) ou null",
    "honoraires_locataire_ttc": "nombre (€ TTC) ou null",
    "honoraires_charge": "bailleur|locataire|partage ou null"
  },

  "_bien": {
    "type_bien": "appartement|maison|local_commercial|bureau|terrain|parking|garage|autre",
    "adresse_1": "string (adresse postale du bien loué)",
    "adresse_2": "étage/porte/complément ou null",
    "code_postal": "5 chiffres ou null",
    "ville": "string ou null",
    "etage": "nombre entier (RDC=0) ou null",
    "lot_principal": "string (n° lot principal) ou null",
    "lot_secondaire": "string (cave, parking — liste coll. ';') ou null",
    "surface_habitable": "nombre (m²) ou null",
    "surface_commerciale": "nombre (m² pondérés ou utiles pour local pro) ou null",
    "nb_pieces": "nombre entier ou null",
    "nb_chambres": "nombre entier ou null",
    "annee_construction": "nombre entier ou null",
    "chauffage_type": "individuel|collectif|null",
    "chauffage_energie": "electricite|gaz|fioul|bois|pompe a chaleur|null",
    "eau_chaude_type": "individuel|collectif|null",
    "dpe_classe": "A|B|C|D|E|F|G ou null",
    "dpe_valeur": "nombre entier (kWh EP/m²/an) ou null",
    "ges_classe": "A|B|C|D|E|F|G ou null"
  },

  "_bailleur": {
    "_commentaire": "VRAI BAILLEUR uniquement. IGNORE REGIE EMERY / EMERY IMMOBILIER / Agence / Cabinet / Administrateur. Si le doc indique 'Avec le concours de EMERY IMMOBILIER' → EMERY est le mandataire, pas le bailleur. Le vrai bailleur est la personne/société AU-DESSUS dans la section 'Pour le BAILLEUR' ou 'Pour le bailleur'.",
    "type_personne": "physique|morale",
    "civilite": "M.|Mme|Mlle|null",
    "nom": "string (nom de famille réel) ou null",
    "prenom": "string ou null",
    "societe": "raison sociale SCI/SARL/SAS (ex: 'SCI LOCA VENTE') ou null",
    "forme_juridique": "SCI|SARL|SAS|SA|EURL|SCS|null",
    "capital": "nombre (€) ou null",
    "siren": "string ou null",
    "rcs_ville": "string (ex: 'LYON') ou null",
    "adresse_1": "adresse réelle du bailleur (siège SCI, domicile particulier)",
    "code_postal": "string ou null",
    "ville": "string ou null",
    "representant_nom": "si société : nom du représentant signataire",
    "representant_prenom": "si société : prénom",
    "representant_qualite": "si société : gérant, président, etc."
  },

  "_locataire": {
    "_commentaire": "Locataire PRINCIPAL. Pour commercial, c'est souvent une société.",
    "type_personne": "physique|societe",
    "civilite": "M.|Mme|Mlle|null",
    "nom": "string ou null",
    "prenom": "string ou null",
    "date_naissance": "YYYY-MM-DD ou null",
    "lieu_naissance": "string ou null",
    "nationalite": "string ou null",
    "profession": "string ou null",
    "raison_sociale": "si societe : ex 'Angel Beauty Institut'",
    "forme_juridique": "EI|SARL|SAS|SCI|null",
    "siren": "string ou null",
    "adresse_1": "adresse du locataire (avant bail, ou siège société)",
    "code_postal": "string ou null",
    "ville": "string ou null",
    "email": "string ou null",
    "telephone": "string ou null",
    "situation_familiale": "celibataire|marie|pacsé|null"
  },

  "_caution": {
    "_commentaire": "Caution / garant du locataire.",
    "type_caution": "physique|visale|assurance|aucune",
    "civilite": "M.|Mme|null",
    "nom": "string ou null",
    "prenom": "string ou null",
    "date_naissance": "YYYY-MM-DD ou null",
    "adresse": "string ou null"
  },

  "_mandataire": {
    "_commentaire": "Agence/régie qui gère le bail. EMERY IMMOBILIER, REGIE EMERY, etc.",
    "nom_agence": "string ou null",
    "carte_pro": "string (n° CPI) ou null",
    "caisse_garantie": "string (ex: 'GALIAN') ou null",
    "montant_garantie": "nombre (€) ou null"
  },

  "_multi": {
    "_commentaire": "UNIQUEMENT si multi-locataires/multi-cautions. Sinon null.",
    "colocataires": "array ou null — chaque entrée: {civilite,nom,prenom,date_naissance,email,telephone}",
    "cautions": "array ou null — chaque entrée: {type,nom,prenom,adresse}"
  },

  "_taxes_recuperables": {
    "_commentaire": "Commercial : cases cochées dans la clause 9 du bail.",
    "taxe_fonciere": "true|false",
    "teom": "true|false (taxe ordures ménagères)",
    "taxe_bureaux": "true|false",
    "gestion_fiscalite": "true|false"
  },

  "_resume": "string (2-3 phrases résumant le bail)"
}

═══════════════════════════════════════════════════════════════════════
RÈGLES CRITIQUES
═══════════════════════════════════════════════════════════════════════

⚠️ RÈGLE N°1 — IDENTIFICATION DE LA NATURE DU BAIL :
  • "BAIL D'HABITATION" / "loi du 6 juillet 1989" / "résidence principale" → habitation
  • "BAIL COMMERCIAL" / "L.145-1 code de commerce" / "3-6-9" → commercial
  • "BAIL PROFESSIONNEL" / "profession libérale" → professionnel
  • "BAIL DE DROIT COMMUN" / "article 1713" → civil
  • "TERRAIN" + "bail rural" / "fermage" → terrain
  • "PARKING" / "GARAGE" seul → parking
  • "MEUBLÉ DE TOURISME" / "location saisonnière" → meuble_touristique

⚠️ RÈGLE N°2 — VRAI BAILLEUR vs MANDATAIRE (CRITIQUE) :
  Les baux sont souvent négociés "avec le concours de" EMERY IMMOBILIER / REGIE EMERY.
  Cette agence est le MANDATAIRE, PAS le bailleur. Elle va dans _mandataire, pas _bailleur.

  Exemples à distinguer :
  • "La Société LOCA VENTE, SCI au capital de 1 000, siège 76 RUE DE VERDUN 69100
    VILLEURBANNE, représentée par Monsieur EMERY Emmanuel"
    → _bailleur = { societe: "LOCA VENTE", forme_juridique: "SCI", capital: 1000,
                    adresse: "76 RUE DE VERDUN", ville: "VILLEURBANNE",
                    representant_nom: "EMERY", representant_prenom: "Emmanuel",
                    representant_qualite: "gérant/mandataire" }
    ⚠️ Attention : ici le bailleur EST la SCI LOCA VENTE, même si Emmanuel EMERY
    la représente. Emmanuel EMERY n'est PAS à mettre dans _mandataire.

  • "Monsieur CLARY BERNARD demeurant chez REGIE EMERY"
    → _bailleur = { nom: "CLARY", prenom: "BERNARD", type_personne: "physique" }
    → _mandataire = { nom_agence: "REGIE EMERY" }
    ⚠️ "demeurant chez REGIE EMERY" signifie domicile élu chez la régie → la régie
    n'est PAS le bailleur, c'est bien Bernard CLARY.

  • "EMERY IMMOBILIER, SAS au capital de 7622.45 €" seul dans la section bailleur
    → _bailleur.nom = null (l'agence n'est pas le vrai bailleur)
    → _mandataire = { nom_agence: "EMERY IMMOBILIER", ... }

⚠️ RÈGLE N°3 — INDICE D'INDEXATION :
  • Bail habitation → IRL (Indice de Référence des Loyers)
  • Bail commercial commerce/artisanat → ILC (Indice des Loyers Commerciaux)
  • Bail commercial bureaux → ILAT (Indice des Loyers Activités Tertiaires)
  • Ne jamais confondre ILC/ILAT/IRL.

⚠️ RÈGLE N°4 — ZONE TENDUE :
  Uniquement pour bail habitation. Si le bail mentionne "zone tendue" ou "loyer
  de référence" ou "loyer de référence majoré" → zone_tendue=true + extrais
  loyer_reference et loyer_reference_majore en €/m².

⚠️ RÈGLE N°5 — DURÉE EN MOIS :
  • Habitation nu : 3 ans = 36 mois (6 ans si bailleur personne morale = 72)
  • Habitation meublé : 1 an = 12 mois (étudiant 9 mois = 9)
  • Commercial : 9 ans = 108 mois (dérogatoire max 3 ans = 36)

⚠️ RÈGLE N°6 — SOCIÉTÉ BAILLEUR (SCI, SARL) :
  type_personne = "morale", ne remplis pas _bailleur.nom ni _bailleur.prenom.
  Remplis societe + forme_juridique + capital + siren + rcs_ville + adresse siège.

⚠️ RÈGLE N°7 — FILTRAGE REGIE/EMERY dans _bailleur :
  • IGNORER ABSOLUMENT comme bailleur : "REGIE EMERY", "EMERY IMMOBILIER",
    "EMERY", "Cabinet EMERY", "Agence EMERY"
  • Mais attention à la règle 2 : si le bailleur est une SCI qui a son siège
    à l'adresse EMERY, la SCI reste le bailleur (exemple SCI LOCA VENTE).

⚠️ RÈGLE N°8 — DATES :
  Format JJ/MM/AAAA → convertis en YYYY-MM-DD strict.

⚠️ RÈGLE N°9 — HONORAIRES :
  Parfois deux colonnes (charge bailleur / charge locataire) identiques
  → remplis les DEUX champs (bailleur_ttc ET locataire_ttc) + honoraires_charge="partage".

⚠️ RÈGLE N°10 — DPE :
  Souvent annexé au bail habitation. Extrais la classe DPE et la valeur si mentionnées.

N'invente RIEN. Si tu n'es pas sûr, mets null.

TEXTE DU BAIL :
---
{$textTruncated}
---
PROMPT;

    $payload = json_encode([
        'model'           => $model,
        'messages'        => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ],
        'max_tokens'      => 3500,
        'temperature'     => 0.0,
        'response_format' => ['type' => 'json_object'],
    ]);

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr)          return ['ok' => false, 'fields' => [], 'doc_type' => 'bail', 'error' => 'Réseau : ' . $curlErr];
    if ($httpCode !== 200) return ['ok' => false, 'fields' => [], 'doc_type' => 'bail', 'error' => 'HTTP ' . $httpCode];

    $data    = json_decode((string)$response, true);
    $content = (string)($data['choices'][0]['message']['content'] ?? '');
    $parsed  = json_decode($content, true);
    if (!is_array($parsed)) {
        if (preg_match('/\{[\s\S]+\}/m', $content, $m)) $parsed = json_decode($m[0], true);
    }
    if (!is_array($parsed)) {
        return ['ok' => false, 'fields' => [], 'doc_type' => 'bail', 'error' => 'Réponse IA non-JSON'];
    }

    // ─── Aplatissement avec préfixes ──
    // _bail       → préfixe bail_ (évite collision avec mandat/date_signature)
    // _loyer      → pas de préfixe (noms spécifiques)
    // _bien       → pas de préfixe (standard)
    // _bailleur   → préfixe proprio_
    // _locataire  → préfixe locataire_
    // _caution    → préfixe caution_
    // _mandataire → préfixe mandataire_ (pas essentiel mais évite collision)
    // _taxes_recuperables → préfixe taxe_
    $prefixes = [
        '_bail'               => 'bail_',
        '_loyer'              => '',
        '_bien'               => '',
        '_bailleur'           => 'proprio_',
        '_locataire'          => 'locataire_',
        '_caution'            => 'caution_',
        '_mandataire'         => 'mandataire_',
        '_taxes_recuperables' => 'taxe_',
    ];

    $flat = [];
    $multi = [];
    foreach ($parsed as $section => $val) {
        if ($section === '_multi' && is_array($val)) {
            // Stocké brut pour passer en JSON metadata côté BDD
            $multi = array_filter($val, fn($k) => $k !== '_commentaire', ARRAY_FILTER_USE_KEY);
            continue;
        }
        if (in_array($section, ['doc_type','doc_titre','doc_date','_resume'], true)) continue;
        if (!is_array($val)) continue;
        $prefix = $prefixes[$section] ?? '';
        foreach ($val as $k => $v) {
            if ($k === '_commentaire' || str_starts_with((string)$k, '_')) continue;
            // Évite double préfixage si l'IA a déjà préfixé
            if ($prefix !== '' && !str_starts_with((string)$k, $prefix)) {
                $flat[$prefix . $k] = $v;
            } else {
                $flat[$k] = $v;
            }
        }
    }

    // ─── Normalisation ──
    $boolFields = [
        'bail_periode_triennale','bail_clause_resolutoire',
        'tva_applicable','zone_tendue',
        'taxe_taxe_fonciere','taxe_teom','taxe_taxe_bureaux','taxe_gestion_fiscalite',
    ];
    $dateFields = [
        'doc_date','bail_date_signature','bail_date_prise_effet','bail_date_fin',
        'locataire_date_naissance','caution_date_naissance',
    ];
    $intFields = [
        'bail_duree_mois','etage','nb_pieces','nb_chambres','annee_construction',
        'dpe_valeur','ges_valeur','nb_termes_garantie','mandataire_montant_garantie',
    ];
    $floatFields = [
        'loyer_mensuel_hc','complement_loyer','charges_mensuelles','total_mensuel',
        'tva_taux','indice_valeur','loyer_reference','loyer_reference_majore',
        'depot_garantie','honoraires_bailleur_ttc','honoraires_locataire_ttc',
        'surface_habitable','surface_commerciale',
        'proprio_capital',
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
        return $f === null ? null : (int)round($f);
    };
    $toDate = static function ($v): ?string {
        $s = trim((string)$v);
        if ($s === '') return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return $s;
        if (preg_match('/^(\d{2})[\/\-\s](\d{2})[\/\-\s](\d{4})$/', $s, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        // Format "17/04/2026" avec espaces
        if (preg_match('/(\d{1,2})\s*[\/\-]\s*(\d{1,2})\s*[\/\-]\s*(\d{4})/', $s, $m)) {
            return $m[3] . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT) . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }
        return null;
    };

    $fields = [];
    foreach ($flat as $k => $v) {
        if ($v === null || $v === '' || (is_array($v) && empty($v))) continue;

        if (in_array($k, ['dpe_classe','ges_classe'], true)) {
            $v = strtoupper(trim((string)$v));
            if (!preg_match('/^[A-G]$/', $v)) continue;
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

        $fields[$k] = $v;
    }

    // Calcul automatique total_mensuel si manquant
    if (!isset($fields['total_mensuel']) && isset($fields['loyer_mensuel_hc'])) {
        $total = (float)$fields['loyer_mensuel_hc'];
        if (isset($fields['charges_mensuelles'])) $total += (float)$fields['charges_mensuelles'];
        if (isset($fields['complement_loyer']))   $total += (float)$fields['complement_loyer'];
        $fields['total_mensuel'] = $total;
    }

    // Injecte multi-locataires/cautions en JSON si présent
    if (!empty($multi)) {
        $fields['bail_metadata_multi'] = json_encode($multi, JSON_UNESCAPED_UNICODE);
    }

    return [
        'ok'        => true,
        'doc_type'  => 'bail',
        'doc_titre' => (string)($parsed['doc_titre'] ?? ''),
        'doc_date'  => (string)($parsed['doc_date'] ?? ''),
        'resume'    => (string)($parsed['_resume'] ?? ''),
        'fields'    => $fields,
        'raw'       => $parsed,
        'count'     => count($fields),
    ];
}
