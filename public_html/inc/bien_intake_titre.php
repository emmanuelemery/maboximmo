<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * Module d'extraction IA spécialisé — ACTES DE PROPRIÉTÉ / MUTATIONS
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Cible principale : NOTIFICATION DE TRANSFERT DE PROPRIÉTÉ (art. 6 décret
 * 67-223) + AVIS DE MUTATION (art. 20 loi 65-557) adressés par le notaire
 * au syndic après une vente. Document court (2 pages typiques).
 *
 * Peut aussi extraire d'un acte authentique complet les mêmes données.
 *
 * Contenu extrait :
 *   - Type de document + nature mutation
 *   - Dates acte / jouissance / notification
 *   - Notaire rédacteur (office, nom, CRPCEN, adresse)
 *   - Co-notaire vendeur (si double)
 *   - Vendeurs (1..n) et acquéreurs (1..n) avec quotités indivises
 *   - Désignation bien + adresse + cadastre (multi-parcelles JSON)
 *   - Lots de copropriété (JSON array)
 *   - Données financières (prix, frais mutation, emprunt remboursé)
 *   - Informations syndic (opposition, délai)
 */

function analyseTitreIA(string $text): array
{
    $apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
    $model  = defined('OPENAI_TEXT_MODEL') ? OPENAI_TEXT_MODEL : 'gpt-4o';

    if (!$apiKey) {
        return ['ok' => false, 'fields' => [], 'doc_type' => 'titre', 'error' => 'OPENAI_API_KEY non configurée'];
    }
    if (strlen(trim($text)) < 50) {
        return ['ok' => false, 'fields' => [], 'doc_type' => 'titre', 'error' => 'Texte trop court'];
    }

    $textTruncated = mb_substr($text, 0, 60000);

    $system = "Tu es un assistant expert en actes notariés français (actes de vente, "
        . "notifications de mutation art. 6 décret 67-223, avis de mutation art. 20 loi "
        . "65-557, attestations de propriété). Tu extrais TOUTES les données utiles au "
        . "format JSON strict. Tu réponds UNIQUEMENT avec du JSON valide.";

    $user = <<<PROMPT
Analyse cet acte / notification immobilier(e) français(e) et extrais toutes les données utiles.

Réponds UNIQUEMENT en JSON valide selon cette structure (null si absent) :
{
  "doc_type": "titre",
  "doc_titre": "string court — ex: 'Notification mutation DESPAGNE → MINET/CAPRA - 74170 Saint-Gervais'",
  "doc_date": "YYYY-MM-DD (date de l'acte)",

  "_acte": {
    "type_document": "notification_mutation|avis_mutation|acte_vente|attestation_propriete|autre",
    "nature_mutation": "vente|donation|succession|partage|apport|autre",
    "date_acte": "YYYY-MM-DD",
    "date_jouissance": "YYYY-MM-DD ou null (date d'entrée en jouissance si différée)",
    "date_notification": "YYYY-MM-DD (date du courrier au syndic)"
  },

  "_notaire_redacteur": {
    "_commentaire": "Notaire PRINCIPAL qui rédige l'acte (souvent celui de l'acquéreur). En-tête du document.",
    "office_nom": "string (raison sociale — ex: 'SELARL PMB NOTAIRES') ou null",
    "notaire_nom": "string (nom complet du notaire signataire — ex: 'Perrine MORAND')",
    "crpcen": "string (identifiant CRPCEN) ou null",
    "adresse_1": "string ou null",
    "code_postal": "string ou null",
    "ville": "string ou null",
    "telephone": "string ou null"
  },

  "_notaire_assistant_vendeur": {
    "_commentaire": "Co-notaire qui assiste le vendeur (facultatif — souvent à distance).",
    "notaire_nom": "string ou null",
    "crpcen": "string ou null",
    "ville": "string ou null"
  },

  "_vendeurs": {
    "_commentaire": "LISTE des vendeurs (1..n). Remplis le tableau 'liste'.",
    "liste": [
      {
        "type_personne": "physique|morale",
        "civilite": "M.|Mme|Mlle|null",
        "nom": "string",
        "prenom": "string ou null",
        "profession": "string ou null",
        "date_naissance": "YYYY-MM-DD ou null",
        "lieu_naissance": "string ou null",
        "nationalite": "string ou null",
        "adresse_1": "string ou null",
        "code_postal": "string ou null",
        "ville": "string ou null",
        "regime_matrimonial": "célibataire|marié communauté|marié séparation|PACS séparation|PACS indivision|divorcé|veuf|null",
        "date_regime": "YYYY-MM-DD (mariage/PACS) ou null"
      }
    ]
  },

  "_acquereurs": {
    "_commentaire": "LISTE des acquéreurs (1..n) avec quotité indivise.",
    "liste": [
      {
        "type_personne": "physique|morale",
        "civilite": "M.|Mme|Mlle|null",
        "nom": "string",
        "prenom": "string ou null",
        "profession": "string ou null",
        "date_naissance": "YYYY-MM-DD ou null",
        "lieu_naissance": "string ou null",
        "nationalite": "string ou null",
        "adresse_1": "string ou null",
        "code_postal": "string ou null",
        "ville": "string ou null",
        "quotite_indivise": "nombre décimal (0.54 pour 54%) ou null",
        "regime_matrimonial": "string ou null"
      }
    ]
  },

  "_bien": {
    "designation": "dénomination immeuble (ex: 'LES CHAMOIS') ou null",
    "adresse_1": "string (adresse postale du bien)",
    "code_postal": "string ou null",
    "ville": "string ou null",
    "nb_batiments": "nombre entier ou null",
    "description_libre": "string (description des bâtiments/lots, multi-lignes OK)"
  },

  "_cadastre": {
    "_commentaire": "LISTE des références cadastrales (un bien peut couvrir plusieurs parcelles).",
    "liste": [
      {
        "section": "string (ex: 'A')",
        "numero": "string (ex: '968')",
        "lieudit": "string ou null",
        "surface_ha": "nombre entier ou null",
        "surface_a": "nombre entier ou null",
        "surface_ca": "nombre entier ou null"
      }
    ]
  },

  "_lots_copropriete": {
    "_commentaire": "LISTE des lots de copropriété concernés par la mutation.",
    "liste": [
      {
        "numero_lot": "nombre entier",
        "ancienne_designation": "string (ex: 'anciennement partie du lot 5') ou null",
        "description": "string ou null",
        "tantiemes_parties_communes": "string (ex: '47/1000') ou null",
        "tantiemes_charges_generales": "string ou null"
      }
    ]
  },

  "_financier": {
    "prix_vente": "nombre (€) ou null",
    "frais_mutation": "nombre (€ — frais de mutation transférés au syndic) ou null",
    "provision_charges": "nombre (€) ou null",
    "charges_impayees": "nombre (€) ou null",
    "emprunt_collectif_rembourse": "nombre (€ — part emprunt collectif remboursée par le vendeur) ou null"
  },

  "_syndic": {
    "domicile_opposition": "string (ex: 'Me Pauline BOURGUIGNON, Lille') ou null",
    "delai_opposition_jours": "nombre entier (typiquement 15) ou null"
  },

  "_resume": "string (2-3 phrases résumant la mutation : qui vend/achète, quoi, quand)"
}

═══════════════════════════════════════════════════════════════════════
RÈGLES CRITIQUES
═══════════════════════════════════════════════════════════════════════

⚠️ RÈGLE N°1 — TYPE DE DOCUMENT :
  • "NOTIFICATION DE TRANSFERT DE PROPRIÉTÉ" / "article 6 du décret n° 67-223"
    → type_document = "notification_mutation"
  • "AVIS DE MUTATION" / "article 20 de la loi n° 65-557"
    → type_document = "avis_mutation"
  • "ACTE DE VENTE" / "ACTE AUTHENTIQUE" / "par-devant Maître"
    → type_document = "acte_vente"
  • "ATTESTATION DE PROPRIÉTÉ" / "attestation immobilière"
    → type_document = "attestation_propriete"

⚠️ RÈGLE N°2 — VENDEUR vs ACQUÉREUR :
  Sections typiques :
  • "Par :" ou "Par les soussignés :" → VENDEURS (ceux qui transmettent)
  • "Au profit de :" ou "L'ACQUEREUR" → ACQUEREURS (ceux qui reçoivent)

⚠️ RÈGLE N°3 — QUOTITÉS INDIVISES :
  • "acquiert la pleine propriété indivise à concurrence de 54 %"
    → acquereurs[0].quotite_indivise = 0.54
  • "en totalité" ou "seul(e)"
    → quotite_indivise = 1.0
  • Si non précisé → null (on supposera égalité en aval)

⚠️ RÈGLE N°4 — NOTAIRE RÉDACTEUR vs ASSISTANT :
  • Le notaire principal signe le courrier (en-tête + signature).
  • Un co-notaire peut être mentionné : "Avec le concours à distance de Maître X,
    notaire à VILLE, assistant le VENDEUR" → c'est _notaire_assistant_vendeur.

⚠️ RÈGLE N°5 — CADASTRE :
  Format tabulaire typique : "Section | N° | Lieudit | Surface"
  Ex: "A | 968 | 357 Avenue du Mont Paccard | 00 ha 04 a 60 ca"
  → { section: "A", numero: "968", lieudit: "357 Avenue du Mont Paccard",
      surface_ha: 0, surface_a: 4, surface_ca: 60 }

⚠️ RÈGLE N°6 — LOTS DE COPROPRIÉTÉ :
  Format : "Lot numéro vingt-sept (27) (anciennement partie du lot 5 et lot 16)"
  → { numero_lot: 27, ancienne_designation: "anciennement partie du lot 5 et lot 16" }

⚠️ RÈGLE N°7 — DATES :
  Format "3 juillet 2025" → "2025-07-03"
  Format JJ/MM/AAAA → YYYY-MM-DD strict

⚠️ RÈGLE N°8 — RÉGIME MATRIMONIAL :
  • "PACS sous le régime de la séparation de biens, le 4 juillet 2019"
    → regime_matrimonial="PACS séparation", date_regime="2019-07-04"
  • "demeurant ensemble à" (couple non-marié, non-PACS apparent)
    → si pas d'autre info, null

⚠️ RÈGLE N°9 — MONTANTS :
  Nettoyer format "8 387,07 eur" → 8387.07
  "942,12 euros" → 942.12

⚠️ RÈGLE N°10 — NE PAS INVENTER :
  Si un champ n'est pas explicite dans le document, mets null. Ne devine pas les
  dates de naissance manquantes, ne déduis pas une adresse à partir d'une autre.

TEXTE DE L'ACTE :
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

    if ($curlErr)          return ['ok' => false, 'fields' => [], 'doc_type' => 'titre', 'error' => 'Réseau : ' . $curlErr];
    if ($httpCode !== 200) return ['ok' => false, 'fields' => [], 'doc_type' => 'titre', 'error' => 'HTTP ' . $httpCode];

    $data    = json_decode((string)$response, true);
    $content = (string)($data['choices'][0]['message']['content'] ?? '');
    $parsed  = json_decode($content, true);
    if (!is_array($parsed)) {
        if (preg_match('/\{[\s\S]+\}/m', $content, $m)) $parsed = json_decode($m[0], true);
    }
    if (!is_array($parsed)) {
        return ['ok' => false, 'fields' => [], 'doc_type' => 'titre', 'error' => 'Réponse IA non-JSON'];
    }

    // ─── Aplatissement ──
    // _acte                       → préfixe acte_
    // _notaire_redacteur          → préfixe notaire_
    // _notaire_assistant_vendeur  → préfixe notaire_vendeur_
    // _bien                       → préfixe bien_ (évite collision avec bien principal)
    // _financier                  → pas de préfixe
    // _syndic                     → pas de préfixe
    // _vendeurs._lots_copropriete._acquereurs._cadastre → sérialisés en JSON
    $prefixes = [
        '_acte'                      => 'acte_',
        '_notaire_redacteur'         => 'notaire_',
        '_notaire_assistant_vendeur' => 'notaire_vendeur_',
        '_bien'                      => 'bien_',
        '_financier'                 => '',
        '_syndic'                    => '',
    ];

    $flat = [];
    foreach ($parsed as $section => $val) {
        if (in_array($section, ['doc_type','doc_titre','doc_date','_resume'], true)) continue;
        if (!is_array($val)) continue;

        // Sections tableaux → JSON
        if (in_array($section, ['_vendeurs','_acquereurs','_cadastre','_lots_copropriete'], true)) {
            $liste = $val['liste'] ?? null;
            if (is_array($liste) && !empty($liste)) {
                $fieldName = match ($section) {
                    '_vendeurs'          => 'vendeurs_json',
                    '_acquereurs'        => 'acquereurs_json',
                    '_cadastre'          => 'cadastre_json',
                    '_lots_copropriete'  => 'lots_copropriete_json',
                };
                $flat[$fieldName] = json_encode($liste, JSON_UNESCAPED_UNICODE);
            }
            continue;
        }

        // Sections à clés plates
        $prefix = $prefixes[$section] ?? '';
        foreach ($val as $k => $v) {
            if ($k === '_commentaire' || str_starts_with((string)$k, '_')) continue;
            // Évite double préfixage
            if ($prefix !== '' && !str_starts_with((string)$k, $prefix)) {
                $flat[$prefix . $k] = $v;
            } else {
                $flat[$k] = $v;
            }
        }
    }

    // ─── Normalisation ──
    $dateFields  = ['doc_date','acte_date_acte','acte_date_jouissance','acte_date_notification'];
    $intFields   = ['bien_nb_batiments','delai_opposition_jours'];
    $floatFields = [
        'prix_vente','frais_mutation','provision_charges','charges_impayees',
        'emprunt_collectif_rembourse',
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
        if (preg_match('/^(\d{2})[\/\-](\d{2})[\/\-](\d{4})$/', $s, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        // "3 juillet 2025" style (IA normalement déjà converti)
        static $mois = [
            'janvier' => '01','février' => '02','fevrier' => '02','mars' => '03','avril' => '04',
            'mai' => '05','juin' => '06','juillet' => '07','août' => '08','aout' => '08',
            'septembre' => '09','octobre' => '10','novembre' => '11','décembre' => '12','decembre' => '12',
        ];
        if (preg_match('/(\d{1,2})\s+([a-zéû]+)\s+(\d{4})/iu', $s, $m)) {
            $mm = $mois[mb_strtolower($m[2])] ?? null;
            if ($mm) return $m[3] . '-' . $mm . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }
        return null;
    };

    $fields = [];
    foreach ($flat as $k => $v) {
        if ($v === null || $v === '' || (is_array($v) && empty($v))) continue;

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

    return [
        'ok'        => true,
        'doc_type'  => 'titre',
        'doc_titre' => (string)($parsed['doc_titre'] ?? ''),
        'doc_date'  => (string)($parsed['doc_date'] ?? ''),
        'resume'    => (string)($parsed['_resume'] ?? ''),
        'fields'    => $fields,
        'raw'       => $parsed,
        'count'     => count($fields),
    ];
}
