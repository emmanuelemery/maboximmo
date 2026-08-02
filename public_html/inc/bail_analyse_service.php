<?php
/**
 * inc/bail_analyse_service.php — Service réutilisable d'analyse IA d'un bail PDF.
 * Extrait les données structurées (locataire, loyer, charges, dates, révision, clauses).
 * Même logique/prompt que api/bail_analyze.php, mais exposée en fonction pour être
 * appelée depuis l'upload (api/bail_doc_upload.php) sans dépendre de $_FILES.
 *
 * bail_analyse_pdf($pdfPath) → ['ok'=>bool, 'data'=>array|null, 'error'=>string|null]
 */
declare(strict_types=1);
require_once __DIR__ . '/ia_analyse.php';   // extractPdfText()

if (!function_exists('bail_analyse_pdf')) {
    function bail_analyse_pdf(string $pdfPath): array
    {
        if (!is_file($pdfPath)) return ['ok'=>false, 'data'=>null, 'error'=>'Fichier introuvable'];
        if (!function_exists('extractPdfText')) return ['ok'=>false, 'data'=>null, 'error'=>'Extraction PDF indisponible'];

        $text = extractPdfText($pdfPath);
        if (strlen(trim((string)$text)) < 100) {
            return ['ok'=>false, 'data'=>null, 'error'=>'Texte non extractible (scan sans OCR ?)'];
        }

        $api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : ($GLOBALS['OPENAI_API_KEY'] ?? '');
        if (!$api_key) return ['ok'=>false, 'data'=>null, 'error'=>'Clé OpenAI absente'];

        $model = 'gpt-4o-mini';
        $text_truncated = mb_substr($text, 0, 40000);

        $system_prompt = "Tu es un juriste expert en droit immobilier français, spécialisé en baux d'habitation (loi du 6 juillet 1989) et baux commerciaux. Tu analyses des baux et extrais toutes les données structurées. Tu réponds UNIQUEMENT en JSON valide.";

        $user_prompt = <<<PROMPT
Analyse ce bail et extrais toutes les données structurées.

Réponds UNIQUEMENT en JSON valide avec cette structure :
{
  "type_bail": "habitation|commercial|professionnel|meuble",
  "locataire": {
    "nom": "string (nom complet)",
    "prenom": "string ou null",
    "adresse_precedente": "string ou null"
  },
  "bailleur": {
    "nom": "string",
    "adresse": "string ou null"
  },
  "bien": {
    "adresse": "string (adresse complète du bien loué)",
    "type": "appartement|maison|local_commercial|bureau|autre",
    "surface": "number ou null (m²)",
    "nb_pieces": "number ou null",
    "etage": "string ou null",
    "description": "string (description courte)"
  },
  "conditions": {
    "loyer_mensuel_hc": "number (loyer hors charges en euros)",
    "charges_provisions": "number (provisions pour charges en euros)",
    "loyer_total_cc": "number (loyer charges comprises)",
    "depot_garantie": "number ou null",
    "date_signature": "string (format YYYY-MM-DD ou null)",
    "date_entree": "string (format YYYY-MM-DD — date d'effet/prise d'effet)",
    "duree_bail": "string (ex: 3 ans, 6 ans, 9 ans)",
    "date_fin_prevue": "string (format YYYY-MM-DD ou null)"
  },
  "revision": {
    "type": "IRL|ICC|ILAT|fixe|aucune",
    "trimestre_reference": "number (1-4) — trimestre de l'indice de référence mentionné",
    "annee_reference": "number — année de l'indice de référence",
    "indice_reference": "number ou null — valeur de l'indice mentionné dans le bail",
    "date_revision_annuelle": "string — date anniversaire de révision (format MM-DD ou texte)",
    "clause_revision_texte": "string — texte exact de la clause de révision si trouvé"
  },
  "clauses_particulieres": ["string — liste des clauses particulières notables"],
  "diagnostics_mentionnes": ["string — DPE, amiante, plomb, etc. mentionnés"],
  "cautions": [
    {
      "type_personne": "physique|morale",
      "civilite": "M.|Mme ou null",
      "nom": "string (nom de famille de la caution, ou nom de la société)",
      "prenom": "string ou null",
      "raison_sociale": "string ou null (si personne morale / organisme)",
      "adresse": "string ou null",
      "date_naissance": "string YYYY-MM-DD ou null",
      "lieu_naissance": "string ou null",
      "email": "string ou null",
      "telephone": "string ou null",
      "type_engagement": "solidaire|simple",
      "montant_max": "number ou null (montant maximal garanti en euros)",
      "duree_ans": "number ou null (durée de l'engagement en années)",
      "engagement_texte": "string ou null (mention/clause d'engagement de caution)"
    }
  ]
}

Règles :
- Les montants sont en euros, sans symbole
- Si une information est absente du bail, utilise null
- Le type_bail se déduit du contexte (loi 89 = habitation, code de commerce = commercial)
- Pour la révision, cherche la mention de l'IRL, ICC ou ILAT et le trimestre/année de référence
- La date d'entrée est la date de prise d'effet, pas la date de signature
- CAUTIONS : extrais toute personne se portant caution du locataire (acte/engagement de cautionnement,
  mention manuscrite « je me porte caution solidaire… »). Une caution est une PERSONNE distincte du
  locataire. Précise pour chacune le type d'engagement (solidaire = le plus fréquent, sinon simple),
  le montant maximal garanti et la durée si indiqués. Si aucune caution : liste vide []. Ne confonds
  PAS la caution (garant) avec le dépôt de garantie (somme d'argent).

TEXTE DU BAIL :
{$text_truncated}
PROMPT;

        $payload = [
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user',   'content' => $user_prompt],
            ],
            'response_format' => ['type' => 'json_object'],
            'max_tokens'      => 4000,
            'temperature'     => 0.1,
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $api_key],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 90,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) return ['ok'=>false, 'data'=>null, 'error'=>'API IA HTTP ' . $httpCode];

        $result  = json_decode((string)$response, true);
        $content = $result['choices'][0]['message']['content'] ?? '';
        $parsed  = json_decode((string)$content, true);
        if (!$parsed && preg_match('/\{[\s\S]*\}/u', (string)$content, $m)) $parsed = json_decode($m[0], true);
        if (!$parsed) return ['ok'=>false, 'data'=>null, 'error'=>'Réponse IA non parsable'];

        return ['ok'=>true, 'data'=>$parsed, 'error'=>null];
    }
}

if (!function_exists('bail_analyse_apply_to_bien_baux')) {
    /**
     * Reporte les données extraites dans bien_baux. Par défaut ($overwrite=false), remplit
     * UNIQUEMENT les colonnes vides (NULL / 0 / '') pour ne jamais écraser une saisie manuelle.
     * Avec $overwrite=true, écrase depuis le PDF (toute valeur extraite non nulle). Renvoie la
     * liste des champs effectivement remplis/mis à jour.
     */
    function bail_analyse_apply_to_bien_baux(PDO $pdo, int $bailId, array $data, bool $overwrite = false): array
    {
        if ($bailId <= 0 || empty($data)) return [];
        $cond = $data['conditions'] ?? [];
        $rev  = $data['revision'] ?? [];
        $loc  = $data['locataire'] ?? [];

        $num = fn($v) => ($v === null || $v === '' || !is_numeric($v)) ? null : (float)$v;
        $str = fn($v) => ($v === null || trim((string)$v) === '') ? null : trim((string)$v);

        // candidat => [valeur, type] ; on ne pose que si la colonne est "vide".
        $cands = [
            'bail_nature'           => $str($data['type_bail'] ?? null),
            'loyer_mensuel_hc'      => $num($cond['loyer_mensuel_hc'] ?? null),
            'charges_mensuelles'    => $num($cond['charges_provisions'] ?? null),
            'depot_garantie'        => $num($cond['depot_garantie'] ?? null),
            'date_signature'        => $str($cond['date_signature'] ?? null),
            'date_prise_effet'      => $str($cond['date_entree'] ?? null),
            'date_fin'              => $str($cond['date_fin_prevue'] ?? null),
            'indice_type'           => $str($rev['type'] ?? null),
            'indice_trimestre'      => $num($rev['trimestre_reference'] ?? null),
            'indice_valeur'         => $num($rev['indice_reference'] ?? null),
            'date_revision_jour_mois'=> $str($rev['date_revision_annuelle'] ?? null),
            'locataire_nom'         => $str($loc['nom'] ?? null),
            'locataire_prenom'      => $str($loc['prenom'] ?? null),
        ];
        // Clauses particulières → conditions_particulieres (texte concaténé)
        if (!empty($data['clauses_particulieres']) && is_array($data['clauses_particulieres'])) {
            $cands['conditions_particulieres'] = trim(implode("\n• ", array_merge([''], array_map('strval', $data['clauses_particulieres']))));
        }

        // État actuel du bail
        $cur = $pdo->prepare("SELECT * FROM bien_baux WHERE id = ? LIMIT 1");
        $cur->execute([$bailId]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) return [];

        // AUTORITÉ CRG : le loyer et le locataire sont (ré)écrits à CHAQUE trimestre par l'import
        // CRG et font foi. L'extraction du bail donne le loyer À LA SIGNATURE (potentiellement
        // périmé) → on ne les REMPLIT que s'ils sont vides, JAMAIS d'écrasement (même overwrite=1).
        $crgAuthoritative = ['loyer_mensuel_hc', 'locataire_nom'];

        $sets = []; $args = []; $filled = [];
        foreach ($cands as $col => $val) {
            if ($val === null || !array_key_exists($col, $row)) continue;
            $existing = $row[$col];
            $isEmpty = ($existing === null || $existing === '' || (is_numeric($existing) && (float)$existing == 0.0));
            $protected = in_array($col, $crgAuthoritative, true);
            // Écrasement autorisé seulement hors colonnes « autorité CRG » et si la valeur diffère.
            if ($isEmpty || ($overwrite && !$protected && (string)$existing !== (string)$val)) {
                $sets[] = "`$col` = ?";
                $args[] = $val;
                $filled[] = $col;
            }
        }
        // Trace : on garde l'analyse brute dans metadata (toujours).
        $meta = json_decode((string)($row['metadata'] ?? ''), true) ?: [];
        $meta['analyse_ia_bail'] = ['data' => $data, 'at' => date('Y-m-d H:i:s')];
        $sets[] = "`metadata` = ?";
        $args[] = json_encode($meta, JSON_UNESCAPED_UNICODE);

        $args[] = $bailId;
        $pdo->prepare("UPDATE bien_baux SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
        return $filled;
    }
}
