<?php
// inc/transaction_doc_extract_ia.php — Extraction IA Vision Claude pour docs Transaction
// ─────────────────────────────────────────────────────────────────
// Extrait depuis un PDF/image les champs métier d'un bail, mandat, avenant, diagnostic…
// Modèle par défaut : Claude Haiku 4.5 (rapide + ~1ct par doc).
// Fallback Sonnet possible via paramètre.
// ─────────────────────────────────────────────────────────────────
declare(strict_types=1);

require_once __DIR__ . '/mbi_supports_score_ia.php'; // helpers : key, extract_json

// Sonnet par défaut pour les PDFs (support garanti). Haiku reste utilisable
// pour les images via le paramètre $modele de la fonction.
if (!defined('TRANSACTION_DOC_EXTRACT_MODEL_DEFAULT')) {
    define('TRANSACTION_DOC_EXTRACT_MODEL_DEFAULT', 'claude-sonnet-4-6');
}
if (!defined('TRANSACTION_DOC_EXTRACT_MODEL_IMAGE')) {
    define('TRANSACTION_DOC_EXTRACT_MODEL_IMAGE', 'claude-haiku-4-5-20251001');
}
// Version du prompt : à bumper quand on enrichit le prompt système/user
// (invalide le cache pour les nouveaux champs attendus).
if (!defined('TRANSACTION_DOC_EXTRACT_PROMPT_VERSION')) {
    define('TRANSACTION_DOC_EXTRACT_PROMPT_VERSION', '3'); // v3 = assurance détaillée + descriptif bien
}

if (!function_exists('transaction_doc_extract_ia')) {
    /**
     * Extrait les champs métier d'un document Transaction (bail, mandat, avenant…).
     *
     * @param string $cheminAbsolu Chemin vers le PDF/image (lisible localement).
     * @param string|null $modele  Override modèle Anthropic (par défaut Haiku 4.5).
     * @return array {
     *   ok:bool,
     *   data: ?array,    // champs extraits
     *   modele:string,
     *   cout_centimes:int,
     *   confidence:int,  // 0-100
     *   raw_json:?array,
     *   erreur:?string,
     * }
     */
    function transaction_doc_extract_ia(string $cheminAbsolu, ?string $modele = null): array
    {
        // Auto-choix modèle si non spécifié : Sonnet pour PDF, Haiku pour image
        if ($modele === null) {
            $extLow = strtolower(pathinfo($cheminAbsolu, PATHINFO_EXTENSION));
            $modele = in_array($extLow, ['jpg','jpeg','png','webp'], true)
                ? TRANSACTION_DOC_EXTRACT_MODEL_IMAGE
                : TRANSACTION_DOC_EXTRACT_MODEL_DEFAULT;
        }
        $key    = mbi_supports_ia_anthropic_key();
        if ($key === '') {
            return ['ok'=>false,'data'=>null,'modele'=>$modele,'cout_centimes'=>0,'confidence'=>0,'raw_json'=>null,'erreur'=>'no_api_key'];
        }
        if (!is_file($cheminAbsolu) || !is_readable($cheminAbsolu)) {
            return ['ok'=>false,'data'=>null,'modele'=>$modele,'cout_centimes'=>0,'confidence'=>0,'raw_json'=>null,'erreur'=>'fichier_introuvable'];
        }

        $ext  = strtolower(pathinfo($cheminAbsolu, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'pdf'         => 'application/pdf',
            'jpg','jpeg'  => 'image/jpeg',
            'png'         => 'image/png',
            'webp'        => 'image/webp',
            default       => null,
        };
        if ($mime === null) {
            return ['ok'=>false,'data'=>null,'modele'=>$modele,'cout_centimes'=>0,'confidence'=>0,'raw_json'=>null,'erreur'=>'extension_non_supportee'];
        }

        // ─── Cache permanent par SHA-256 ──────────────────────────────────
        // Un fichier = une analyse = un paiement, peu importe l'environnement.
        $hash = hash_file('sha256', $cheminAbsolu);
        $promptVersion = TRANSACTION_DOC_EXTRACT_PROMPT_VERSION;
        try {
            $pdoCache = $GLOBALS['pdo'] ?? null;
            if ($pdoCache instanceof PDO) {
                // Auto-create de la table si absente (idempotent)
                $pdoCache->exec("CREATE TABLE IF NOT EXISTS `ia_extract_cache` (
                    `hash_sha256` CHAR(64) NOT NULL,
                    `model` VARCHAR(60) NOT NULL,
                    `prompt_version` VARCHAR(20) NOT NULL DEFAULT '1',
                    `response_json` LONGTEXT NOT NULL,
                    `cout_centimes` INT NOT NULL DEFAULT 0,
                    `hit_count` INT UNSIGNED NOT NULL DEFAULT 1,
                    `first_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `last_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    `confidence` TINYINT UNSIGNED NULL,
                    `source_origin` VARCHAR(40) NULL,
                    PRIMARY KEY (`hash_sha256`,`model`,`prompt_version`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                $stC = $pdoCache->prepare('SELECT response_json, cout_centimes, confidence FROM ia_extract_cache
                    WHERE hash_sha256 = ? AND model = ? AND prompt_version = ? LIMIT 1');
                $stC->execute([$hash, $modele, $promptVersion]);
                $cached = $stC->fetch(PDO::FETCH_ASSOC);
                if ($cached) {
                    // Hit cache : on incrémente le compteur d'utilisations
                    try {
                        $pdoCache->prepare('UPDATE ia_extract_cache SET hit_count = hit_count + 1
                            WHERE hash_sha256 = ? AND model = ? AND prompt_version = ?')
                            ->execute([$hash, $modele, $promptVersion]);
                    } catch (Throwable $e) {}
                    $cachedData = json_decode((string)$cached['response_json'], true);
                    if (is_array($cachedData)) {
                        return [
                            'ok'           => true,
                            'data'         => $cachedData,
                            'modele'       => $modele,
                            'cout_centimes'=> 0, // 0 car cache hit (économie)
                            'cout_centimes_original' => (int)$cached['cout_centimes'],
                            'confidence'   => (int)($cached['confidence'] ?? 0),
                            'raw_json'     => null,
                            'erreur'       => null,
                            'from_cache'   => true,
                            'cache_hash'   => substr($hash, 0, 12) . '…',
                        ];
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('[ia_extract_cache check] ' . $e->getMessage());
        }

        $b64 = base64_encode((string)file_get_contents($cheminAbsolu));
        if ($b64 === '') {
            return ['ok'=>false,'data'=>null,'modele'=>$modele,'cout_centimes'=>0,'confidence'=>0,'raw_json'=>null,'erreur'=>'fichier_vide'];
        }

        // Prompt structuré
        $systemPrompt = "Tu es un expert en immobilier commercial et baux français. "
            . "Tu analyses des documents (baux commerciaux, mandats vente/location, avenants, diagnostics, taxes foncières) "
            . "et tu extraits UNIQUEMENT les informations EXPLICITEMENT présentes dans le document. "
            . "Si une information n'est pas écrite, tu mets null (jamais d'invention). "
            . "FORMAT DE RÉPONSE OBLIGATOIRE : un seul objet JSON valide, RIEN d'autre. "
            . "Pas de markdown, pas de ```json, pas de texte avant ou après. "
            . "Le premier caractère de ta réponse DOIT être '{', le dernier '}'. "
            . "Tu termines TOUJOURS le JSON proprement (pas de troncature au milieu). "
            . "Si tu manques de place, abrège conditions_particulieres et bien_description (max 500 chars chacun).";

        $userPrompt = <<<TXT
Analyse ce document et retourne un JSON avec EXACTEMENT cette structure (mets null pour les champs non trouvés) :

{
  "type_doc": "bail_commercial|bail_habitation|mandat_vente|mandat_location|avenant|compromis|promesse_vente|acte_vente|dpe|diagnostic|taxe_fonciere|autre",
  "type_doc_label": "libellé humain court ex: 'Bail commercial', 'Avenant n°1 au bail'",
  "proprietaire": "nom complet du propriétaire/bailleur (société ou personne)",
  "proprietaire_forme": "societe|personne_physique|null",
  "proprietaire_siren": "SIREN du bailleur (9 chiffres, sans espaces) si présent dans le bail. null sinon.",
  "bailleur_representant_nom": "Nom du représentant légal du bailleur (gérant, président, mandataire). null si propriétaire en personne.",
  "bailleur_representant_qualite": "gérant|président|directeur|mandataire|administrateur|null",
  "bailleur_representant_email": "email si indiqué dans le bail, sinon null",
  "bailleur_representant_telephone": "téléphone si indiqué, sinon null",
  "locataire": "nom complet du locataire/preneur (société ou personne)",
  "locataire_forme": "societe|personne_physique|null",
  "locataire_representant_nom": "Nom du représentant légal du locataire. null si personne physique en propre.",
  "locataire_representant_qualite": "gérant|président|directeur|mandataire|null",
  "locataire_representant_email": "email si indiqué",
  "locataire_representant_telephone": "téléphone si indiqué",
  "adresse_bien": "adresse complète du bien (rue + numéro)",
  "code_postal": "code postal du bien",
  "ville": "ville du bien",
  "date_signature": "YYYY-MM-DD",
  "date_debut_bail": "YYYY-MM-DD",
  "date_fin_bail": "YYYY-MM-DD",
  "duree_mois": entier ou null,
  "loyer_annuel_ht": nombre en € (sans symbole),
  "loyer_mensuel_ht": nombre en €,
  "charges_annuelles": nombre en €,
  "depot_garantie": nombre en €,
  "surface_m2": nombre,
  "indice_revision": "ILC|ILAT|IRL|ICC|null",
  "indice_reference_trimestre": "ex: 2T2024",
  "indice_reference_valeur": nombre,
  "tva_applicable": true|false|null,
  "signature_status": "signe|non_signe|inconnu",
  "signature_details": "court texte sur ce qu'on voit (ex: 'signé bailleur+preneur', 'page signature absente')",
  "renonciation_recours_reciproque": true|false|null,
  "renonciation_recours_locataire": true|false|null,
  "renonciation_recours_bailleur": true|false|null,
  "renonciation_details": "court texte (ex: 'article 14 — locataire renonce à recours contre bailleur+assureurs', null si non précisé)",
  "assurance_surprimes_a_charge": "locataire|bailleur|partage|null",
  "assurance_justification_annuelle": true|false|null,
  "assurance_risques_couverts": ["incendie","risques_locatifs","risques_professionnels","recours_voisins","recours_tiers","degats_eaux","recherche_fuites","explosions","bris_glace","vandalisme","dommages_materiels","dommages_immateriels"],
  "conditions_particulieres": "Texte complet des conditions particulières / clauses spécifiques du bail (autoconsommation électrique, sous-location autorisée, exclusivité, droit de préemption, restrictions d'activité, franchise, garants spécifiques, etc.). null si aucune.",

  "bien_surface_totale_m2": nombre,
  "bien_surfaces_detail": "texte libre (ex: '495 m² RDC + 70 m² 1er étage = 565 m²')",
  "bien_nb_parkings": nombre,
  "bien_parkings_detail": "ex: '2 places en sous-sol' ou null",
  "bien_numero_lot_copro": "string (ex: '170', '4B') ou null",
  "bien_quote_part_copro": "string exacte (ex: '589/10000ème', '5,89%') ou null",
  "bien_quote_part_copro_pct": nombre décimal (ex: 5.89) ou null,
  "bien_etage": "RDC|1|2|3|sous-sol|...",
  "bien_destination_usage": "commercial|professionnel|habitation|mixte|null",
  "bien_description": "Court résumé descriptif du bien tel que décrit dans le bail (composition, agencement, équipements)",
  "bien_etat": "neuf|bon|moyen|degrade|null",
  "bien_annee_construction": nombre ou null,
  "bien_sous_location_autorisee": true|false|null,
  "numero_mandat": "numéro si mandat",
  "honoraires_pct": nombre,
  "exclusif": true|false|null,
  "confidence": nombre 0-100 (ta confiance globale dans l'extraction),
  "notes": "remarques utiles courtes (ex: 'document tronqué', 'lecture partielle', 'plusieurs preneurs en JV')"
}

Règles :
- Tu ne mets JAMAIS d'information non écrite dans le document.
- Pour signature_status : "signe" si signatures visibles, "non_signe" si bloc signature présent mais vide, "inconnu" si page signature absente.
- Pour les dates : format YYYY-MM-DD strict. Si seulement le mois/année : YYYY-MM-01.
- Pour les montants : nombre brut sans espace ni symbole (ex: 64296 et non "64 296 €").
- Si plusieurs locataires (JV) : concatène avec " ; ".
- Pour la RENONCIATION À RECOURS (clause d'assurance) — sois précis sur le SENS :
   * renonciation_recours_locataire = true si le BAIL DIT QUE LE LOCATAIRE renonce à se retourner contre le bailleur en cas de sinistre.
   * renonciation_recours_bailleur = true si le BAIL DIT QUE LE BAILLEUR renonce à se retourner contre le locataire.
   * renonciation_recours_reciproque = true UNIQUEMENT si les 2 renoncent mutuellement (cas le plus protecteur).
   Beaucoup de baux ne prévoient qu'une renonciation UNILATÉRALE (souvent du locataire vers le bailleur). Dans ce cas, mets renonciation_recours_locataire=true, renonciation_recours_bailleur=null/false, et renonciation_recours_reciproque=false.
- Pour assurance_surprimes_a_charge : "locataire" si le bail dit que les surprimes liées à l'activité du locataire sont à sa charge.
- Pour assurance_risques_couverts : liste explicitement les risques que le locataire DOIT assurer selon le bail (chercher dans l'article Assurances).
- Pour conditions_particulieres : extrais le texte intégral des clauses spécifiques (souvent en fin de contrat, intitulées "Conditions particulières", "Dispositions particulières", "Clauses additionnelles"). Inclus aussi les engagements spéciaux du preneur ou du bailleur. Si aucune section dédiée, null.
- IMPORTANT — DESCRIPTIF DU BIEN : extrais TOUS les éléments qui décrivent le bien LUI-MÊME (pas le bail). Les baux mentionnent souvent la composition du lot (n° lot, tantièmes/quote-part copro, surface répartie par niveau, nombre de places parking, étage, destination commerciale/habitation). Ces informations doivent être extraites dans les champs bien_* — elles serviront à enrichir la fiche bien.
- Pour bien_quote_part_copro_pct : convertis en pourcentage décimal (589/10000ème = 5.89, 8% = 8.00).
- Pour bien_numero_lot_copro : juste le numéro (sans "Lot n°"), ex: "170" ou "4B" si subdivision.
- Pour les représentants : si une société est partie, identifie la personne physique qui signe en son nom (gérant SARL, président SAS, etc.) — ne confonds pas avec les associés.
- IMPORTANT — SIREN/SIRET : si la moindre référence à un numéro SIREN (9 chiffres) ou SIRET (14 chiffres) est présente pour le BAILLEUR ou le LOCATAIRE, tu DOIS l'extraire (champs locataire_siren / bailleur_siren). Le SIREN est l'identifiant unique national d'une société — il évite les doublons quand le nom varie ("UNIKALO" vs "NUANCES UNIKALO RHONE ALPES"). Cherche dans toutes les pages (mentions légales, signature, en-tête).
- Pour la raison_sociale : retourne le nom JURIDIQUE EXACT tel qu'écrit dans le bail (sans normaliser). Si le bail dit "NUANCES UNIKALO RHONE ALPES SAS", retourne ça intégralement.

Réponds UNIQUEMENT par le JSON, rien d'autre.
TXT;

        // Source PDF ou image
        $sourceBlock = $mime === 'application/pdf'
            ? ['type' => 'document', 'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $b64]]
            : ['type' => 'image',    'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64]];

        $payload = [
            'model'      => $modele,
            // 4000 tokens nécessaires depuis l'enrichissement du prompt
            // (conditions_particulieres + risques_couverts + bien_description peuvent être longs)
            'max_tokens' => 4000,
            'system'     => $systemPrompt,
            'messages'   => [[
                'role' => 'user',
                'content' => [
                    $sourceBlock,
                    ['type' => 'text', 'text' => $userPrompt],
                ],
            ]],
        ];

        // Reset chrono PHP avant appel (Vision peut prendre 30-90s)
        @set_time_limit(180);

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $key,
                'anthropic-version: 2023-06-01',
                'anthropic-beta: pdfs-2024-09-25',
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 120,
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $code !== 200) {
            // Décode la réponse d'erreur Anthropic pour avoir le message clair
            $bodyErr = is_string($raw) ? json_decode($raw, true) : null;
            $msgErr = '';
            if (is_array($bodyErr) && isset($bodyErr['error'])) {
                $msgErr = ($bodyErr['error']['type'] ?? '') . ' — ' . ($bodyErr['error']['message'] ?? '');
            } else {
                $msgErr = $err ?: substr((string)$raw, 0, 300);
            }
            error_log('[transaction_doc_extract_ia] HTTP ' . $code . ' / model=' . $modele . ' / err=' . $msgErr);
            return ['ok'=>false,'data'=>null,'modele'=>$modele,'cout_centimes'=>0,'confidence'=>0,'raw_json'=>$bodyErr,
                    'erreur'=>"anthropic_http_{$code}: " . $msgErr];
        }

        $body = json_decode((string)$raw, true);
        $text = $body['content'][0]['text'] ?? '';
        if (!is_string($text) || $text === '') {
            return ['ok'=>false,'data'=>null,'modele'=>$modele,'cout_centimes'=>0,'confidence'=>0,'raw_json'=>$body,'erreur'=>'reponse_vide'];
        }

        $data = mbi_supports_ia_extract_json($text);
        if ($data === null) {
            // Tentative de récupération : décode le JSON brut en mode tolérant (tronqué)
            // 1. Ferme les chaînes ouvertes, les objets/arrays non fermés
            $repaired = trim($text);
            // Trouve le premier { et essaie de fermer ce qui manque
            $start = strpos($repaired, '{');
            if ($start !== false) {
                $repaired = substr($repaired, $start);
                // Si la dernière clé n'a pas de valeur (tronquée), on tronque jusqu'à la dernière virgule
                $repaired = preg_replace('/,\s*"[^"]*"\s*:\s*$/', '', $repaired);
                // Ferme les guillemets si la dernière chaîne est ouverte
                $nbQuotes = substr_count($repaired, '"') - substr_count($repaired, '\"');
                if ($nbQuotes % 2 !== 0) $repaired .= '"';
                // Ferme les accolades/crochets manquants
                $openObj = substr_count($repaired, '{') - substr_count($repaired, '}');
                $openArr = substr_count($repaired, '[') - substr_count($repaired, ']');
                for ($i = 0; $i < $openArr; $i++) $repaired .= ']';
                for ($i = 0; $i < $openObj; $i++) $repaired .= '}';
                $data = mbi_supports_ia_extract_json($repaired);
            }
            if ($data === null) {
                // Log le raw text pour debug
                error_log('[transaction_doc_extract_ia] json_invalide — modèle=' . $modele . ' — output_tokens=' . ($body['usage']['output_tokens'] ?? '?') . ' — stop_reason=' . ($body['stop_reason'] ?? '?') . ' — texte (300 premiers chars) : ' . substr($text, 0, 300));
                $stopReason = $body['stop_reason'] ?? '';
                $errDetail = 'json_invalide';
                if ($stopReason === 'max_tokens') $errDetail = 'json_tronque_max_tokens (réponse coupée à ' . ($body['usage']['output_tokens'] ?? '?') . ' tokens)';
                return ['ok'=>false,'data'=>null,'modele'=>$modele,'cout_centimes'=>0,'confidence'=>0,'raw_json'=>$body,'erreur'=>$errDetail,'raw_text'=>substr($text, 0, 500)];
            }
            // Récupération réussie — on log un warning pour suivi
            error_log('[transaction_doc_extract_ia] json récupéré après réparation — modèle=' . $modele);
        }

        // Coût en centimes (approx Haiku/Sonnet) : input_tokens * 0.0001¢ / output_tokens * 0.0005¢
        $usage = $body['usage'] ?? [];
        $costEur = (((int)($usage['input_tokens'] ?? 0)) * 0.000001 + ((int)($usage['output_tokens'] ?? 0)) * 0.000005);
        if (str_contains($modele, 'sonnet')) {
            $costEur = (((int)($usage['input_tokens'] ?? 0)) * 0.00001 + ((int)($usage['output_tokens'] ?? 0)) * 0.00005);
        }
        $costCent = (int)round($costEur * 100);

        $confidence = (int)($data['confidence'] ?? 0);

        // ─── Cache write (idempotent via INSERT IGNORE) ──────────────────
        try {
            if (isset($pdoCache) && $pdoCache instanceof PDO) {
                $pdoCache->prepare('INSERT IGNORE INTO ia_extract_cache
                    (hash_sha256, model, prompt_version, response_json, cout_centimes, confidence, source_origin)
                    VALUES (?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$hash, $modele, $promptVersion, json_encode($data, JSON_UNESCAPED_UNICODE), $costCent, $confidence, 'transaction_doc_extract_ia']);
            }
        } catch (Throwable $e) {
            error_log('[ia_extract_cache write] ' . $e->getMessage());
        }

        return [
            'ok'           => true,
            'data'         => $data,
            'modele'       => $modele,
            'cout_centimes'=> $costCent,
            'confidence'   => $confidence,
            'raw_json'     => $body,
            'erreur'       => null,
            'from_cache'   => false,
        ];
    }
}
