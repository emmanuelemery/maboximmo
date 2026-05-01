<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * mbi_supports_score_ia.php — Lecture IA du bien (angle, forces, faiblesses)
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Module : Ma Box Communication (mbi_supports)
 *
 * Appelle Claude Sonnet 4.6 pour enrichir le score déterministe avec :
 *   - points_forts (3 à 5 phrases courtes)
 *   - points_faibles (1 à 3 phrases courtes)
 *   - angle_recommande (parmi 6 valeurs ENUM)
 *   - photo_hero_id (ID dans la liste fournie, si pertinent)
 *   - niveau_urgence (faible/moyen/fort)
 *   - confidence (0-100)
 *   - commentaire (synthèse 1 phrase)
 *
 * Anti-hallucination :
 *   - temperature 0
 *   - schéma JSON strict en sortie
 *   - rejet propre si JSON invalide → fallback sur déterministe seul
 *
 * En cas d'absence de clé Anthropic → renvoie {ok:false, erreur:'no_api_key'}
 * (le moteur retombe automatiquement sur le mode déterministe).
 *
 * API :
 *   mbi_supports_ia_analyser(array $bien, array $photos, array $deterministe): array
 *     → {
 *         ok:bool,
 *         data:array|null,
 *         modele:string,
 *         cout_centimes:int,
 *         confidence:int|null,
 *         erreur:?string,
 *         raw_response:?string
 *       }
 * ═══════════════════════════════════════════════════════════════════════
 */

if (!defined('MBI_SUPPORTS_SCORE_PROMPT_VERSION')) {
    define('MBI_SUPPORTS_SCORE_PROMPT_VERSION', '2026-05-01.v1');
}
// Modèle par défaut : Haiku 4.5 (5× moins cher que Sonnet) — adapté à la
// phase test/début. Surchageable via paramètre $modele de l'API publique.
if (!defined('MBI_SUPPORTS_SCORE_MODEL')) {
    define('MBI_SUPPORTS_SCORE_MODEL', 'claude-haiku-4-5');
}

/**
 * Mappe un alias court (haiku / sonnet / opus) vers l'ID modèle Anthropic complet.
 * Renvoie l'alias inchangé s'il est déjà un ID complet (claude-…).
 * Renvoie le modèle par défaut si null/vide.
 */
if (!function_exists('mbi_supports_ia_modele_id')) {
    function mbi_supports_ia_modele_id(?string $alias): string
    {
        $alias = trim((string)$alias);
        if ($alias === '') return MBI_SUPPORTS_SCORE_MODEL;
        return match (strtolower($alias)) {
            'haiku'  => 'claude-haiku-4-5',
            'sonnet' => 'claude-sonnet-4-6',
            'opus'   => 'claude-opus-4-7',
            default  => str_starts_with($alias, 'claude-') ? $alias : MBI_SUPPORTS_SCORE_MODEL,
        };
    }
}

if (!function_exists('mbi_supports_ia_analyser')) {

    /**
     * @param array  $bien          Données du bien (clés tolérantes)
     * @param array  $photos        Liste bien_photos
     * @param array  $deterministe  Sortie de mbi_supports_rules_calcul()
     * @param ?string $modele       Alias 'haiku' / 'sonnet' / 'opus' ou ID complet.
     *                              Null = MBI_SUPPORTS_SCORE_MODEL (haiku par défaut).
     * @return array
     */
    function mbi_supports_ia_analyser(array $bien, array $photos, array $deterministe, ?string $modele = null): array
    {
        $modeleId = mbi_supports_ia_modele_id($modele);

        // 1. Récupère la clé Anthropic (réutilise le helper GED)
        $apiKey = mbi_supports_ia_anthropic_key();
        if ($apiKey === '') {
            return [
                'ok'            => false,
                'data'          => null,
                'modele'        => $modeleId,
                'cout_centimes' => 0,
                'confidence'    => null,
                'erreur'        => 'no_api_key',
                'raw_response'  => null,
            ];
        }

        // 2. Prépare le prompt système et utilisateur
        $sys = mbi_supports_ia_system_prompt();
        $usr = mbi_supports_ia_user_prompt($bien, $photos, $deterministe);

        // 3. Appel API Anthropic
        $payload = [
            'model'       => $modeleId,
            'max_tokens'  => 1500,
            'temperature' => 0.0,
            'system'      => $sys,
            'messages'    => [['role' => 'user', 'content' => $usr]],
        ];

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
                'content-type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 60,
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $code !== 200) {
            return [
                'ok'            => false,
                'data'          => null,
                'modele'        => $modeleId,
                'cout_centimes' => 0,
                'confidence'    => null,
                'erreur'        => "anthropic_http_{$code}: " . ($err ?: substr((string)$raw, 0, 300)),
                'raw_response'  => is_string($raw) ? $raw : null,
            ];
        }

        // 4. Parse réponse
        $body = json_decode((string)$raw, true);
        $text = $body['content'][0]['text'] ?? '';
        if (!is_string($text) || $text === '') {
            return [
                'ok'            => false,
                'data'          => null,
                'modele'        => $modeleId,
                'cout_centimes' => 0,
                'confidence'    => null,
                'erreur'        => 'reponse_vide',
                'raw_response'  => (string)$raw,
            ];
        }

        $data = mbi_supports_ia_extract_json($text);
        if ($data === null) {
            return [
                'ok'            => false,
                'data'          => null,
                'modele'        => $modeleId,
                'cout_centimes' => mbi_supports_ia_estimer_cout($body),
                'confidence'    => null,
                'erreur'        => 'json_invalide',
                'raw_response'  => $text,
            ];
        }

        // 5. Normalisation + validation des champs
        $data = mbi_supports_ia_normalize($data, $photos);

        return [
            'ok'            => true,
            'data'          => $data,
            'modele'        => $modeleId,
            'cout_centimes' => mbi_supports_ia_estimer_cout($body),
            'confidence'    => isset($data['confidence']) ? (int)$data['confidence'] : null,
            'erreur'        => null,
            'raw_response'  => null,
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────

if (!function_exists('mbi_supports_ia_anthropic_key')) {
    function mbi_supports_ia_anthropic_key(): string
    {
        $k = $GLOBALS['ANTHROPIC_API_KEY'] ?? '';
        if (is_string($k) && $k !== '') return $k;
        if (defined('ANTHROPIC_API_KEY')) return (string)ANTHROPIC_API_KEY;
        return (string)(getenv('ANTHROPIC_API_KEY') ?: '');
    }
}

if (!function_exists('mbi_supports_ia_system_prompt')) {
    function mbi_supports_ia_system_prompt(): string
    {
        return implode("\n", [
            "Tu es un expert en marketing immobilier français au service d'une agence professionnelle.",
            "Tu analyses la fiche d'un bien à vendre et tu identifies son potentiel commercial.",
            "",
            "Tu retournes UNIQUEMENT un JSON strict, conforme au schéma demandé.",
            "AUCUN texte autour, pas de markdown, pas de fences ```.",
            "",
            "RÈGLES :",
            "- Sois concret, ancré sur les données fournies. Ne JAMAIS inventer un atout absent.",
            "- Si un point fort est faible ou contestable, mets-le plutôt dans points_faibles.",
            "- L'angle recommandé doit être cohérent avec le bien (pas 'premium' pour un studio en zone rurale).",
            "- Niveau d'urgence = vitesse à laquelle l'agence doit agir (DPE bientôt interdit, marché tendu, etc.).",
            "- Confidence = 0-100, reflète ta certitude sur l'analyse globale.",
            "- Tout en français professionnel, ton sobre.",
            "",
            "Schéma JSON :",
            '{',
            '  "points_forts": ["string", "string", "string"],     // 3 à 5 phrases courtes',
            '  "points_faibles": ["string"],                       // 1 à 3 phrases courtes',
            '  "angle_recommande": "famille|investisseur|premium|premier_achat|generique|autre",',
            '  "photo_hero_id": int|null,                          // id parmi la liste fournie',
            '  "niveau_urgence": "faible|moyen|fort",',
            '  "confidence": int,                                   // 0-100',
            '  "commentaire": "string"                              // 1 phrase de synthèse'  ,
            '}',
        ]);
    }
}

if (!function_exists('mbi_supports_ia_user_prompt')) {
    function mbi_supports_ia_user_prompt(array $bien, array $photos, array $deterministe): string
    {
        // Sélection des champs présentés à l'IA (pas tout : on ne pollue pas le contexte)
        $vue = [
            'reference'         => $bien['reference_bien'] ?? null,
            'designation'       => $bien['designation'] ?? null,
            'description'       => mb_substr((string)($bien['description'] ?? $bien['descriptif'] ?? ''), 0, 2000),
            'type_bien'         => $bien['type_bien_libelle'] ?? $bien['type'] ?? null,
            'surface'           => $bien['surface_habitable'] ?? $bien['surface'] ?? null,
            'nb_pieces'         => $bien['nb_pieces'] ?? $bien['nombre_pieces'] ?? null,
            'etage'             => $bien['etage'] ?? null,
            'exposition'        => $bien['exposition'] ?? $bien['orientation'] ?? null,
            'etat'              => $bien['etat'] ?? $bien['etat_general'] ?? null,
            'annee_construction'=> $bien['annee_construction'] ?? null,
            'ville'             => $bien['ville'] ?? null,
            'code_postal'       => $bien['code_postal'] ?? null,
            'prix'              => $bien['prix_vente_estime'] ?? $bien['prix_vente'] ?? $bien['prix'] ?? null,
            'dpe'               => $bien['dpe_classe'] ?? $bien['dpe'] ?? null,
            'ges'               => $bien['ges_classe'] ?? $bien['ges'] ?? null,
            'copropriete'       => (int)($bien['copropriete'] ?? $bien['est_copro'] ?? 0) === 1,
        ];

        $photosList = [];
        foreach ($photos as $p) {
            $photosList[] = [
                'id'        => (int)($p['id'] ?? 0),
                'legende'   => (string)($p['legende'] ?? $p['titre'] ?? ''),
                'piece'     => (string)($p['type_piece'] ?? $p['piece'] ?? $p['categorie'] ?? ''),
                'is_hero'   => (int)($p['is_hero'] ?? $p['hero'] ?? 0) === 1,
            ];
        }

        $jsonBien    = json_encode($vue, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $jsonPhotos  = json_encode($photosList, JSON_UNESCAPED_UNICODE);
        $jsonDeter   = json_encode([
            'score_total' => $deterministe['total'] ?? null,
            'signaux'     => $deterministe['signaux'] ?? [],
            'breakdown_resume' => array_map(
                fn($b) => ['libelle' => $b['libelle'] ?? '', 'points' => $b['points'] ?? 0, 'max' => $b['max'] ?? 0],
                $deterministe['breakdown'] ?? []
            ),
        ], JSON_UNESCAPED_UNICODE);

        return "Voici le bien à analyser :\n"
             . "BIEN:\n{$jsonBien}\n\n"
             . "PHOTOS DISPONIBLES (id : description) :\n{$jsonPhotos}\n\n"
             . "SCORE DÉTERMINISTE DÉJÀ CALCULÉ (à enrichir, pas à recalculer) :\n{$jsonDeter}\n\n"
             . "Renvoie le JSON strict conforme au schéma :";
    }
}

if (!function_exists('mbi_supports_ia_extract_json')) {
    /**
     * Extrait un JSON propre d'une réponse IA (gère les éventuels fences).
     */
    function mbi_supports_ia_extract_json(string $text): ?array
    {
        $text = trim($text);
        // Supprime éventuels fences markdown
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```\s*$/', '', $text);
        $text = trim((string)$text);

        // Trouve le premier { et le dernier }
        $start = strpos($text, '{');
        $end   = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) return null;
        $json = substr($text, $start, $end - $start + 1);

        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }
}

if (!function_exists('mbi_supports_ia_normalize')) {
    function mbi_supports_ia_normalize(array $data, array $photos): array
    {
        // points_forts / points_faibles : tableau de strings, max 5/3 entrées
        $data['points_forts']   = array_slice(array_filter(
            array_map('strval', (array)($data['points_forts'] ?? [])),
            fn($s) => trim($s) !== ''
        ), 0, 5);
        $data['points_faibles'] = array_slice(array_filter(
            array_map('strval', (array)($data['points_faibles'] ?? [])),
            fn($s) => trim($s) !== ''
        ), 0, 3);

        // angle_recommande : whitelist
        $angles = ['famille','investisseur','premium','premier_achat','generique','autre'];
        $angle = strtolower(trim((string)($data['angle_recommande'] ?? '')));
        $data['angle_recommande'] = in_array($angle, $angles, true) ? $angle : 'generique';

        // photo_hero_id : doit exister dans la liste fournie
        $idsValides = array_map(fn($p) => (int)($p['id'] ?? 0), $photos);
        $hid = $data['photo_hero_id'] ?? null;
        $hid = is_numeric($hid) ? (int)$hid : null;
        $data['photo_hero_id'] = ($hid !== null && in_array($hid, $idsValides, true)) ? $hid : null;

        // niveau_urgence : whitelist
        $urgences = ['faible','moyen','fort'];
        $urg = strtolower(trim((string)($data['niveau_urgence'] ?? '')));
        $data['niveau_urgence'] = in_array($urg, $urgences, true) ? $urg : 'moyen';

        // confidence : 0-100
        $conf = $data['confidence'] ?? null;
        $data['confidence'] = is_numeric($conf) ? max(0, min(100, (int)$conf)) : 50;

        // commentaire : string
        $data['commentaire'] = (string)($data['commentaire'] ?? '');

        return $data;
    }
}

if (!function_exists('mbi_supports_ia_estimer_cout')) {
    /**
     * Estimation très approximative du coût en centimes basée sur les usage tokens
     * de la réponse Anthropic (champ usage.input_tokens / usage.output_tokens).
     * Tarifs Sonnet 4.6 indicatifs : 3$/M input, 15$/M output → ≈ 1 centime / 3000 tokens mix.
     */
    function mbi_supports_ia_estimer_cout(array $body): int
    {
        $inTok  = (int)($body['usage']['input_tokens']  ?? 0);
        $outTok = (int)($body['usage']['output_tokens'] ?? 0);
        // Coût en centimes EUR (approx : 0.3¢ / 1k input + 1.5¢ / 1k output, approx EUR ≈ USD)
        $cents = ($inTok * 0.0003) + ($outTok * 0.0015);
        return (int)ceil($cents);
    }
}
