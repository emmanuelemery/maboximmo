<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * mbi_supports_redaction_ia.php — Rédaction commerciale IA (Claude Haiku)
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Génère le contenu rédactionnel d'un support (affiche, fiche, dossier) :
 *   - accroche : 1 phrase punchy adaptée à l'angle marketing
 *   - paragraphe : 3-5 phrases de texte commercial pour la zone "L'ANNONCE"
 *   - atouts : 3 phrases courtes (✓) pour la zone "VOS ATOUTS"
 *   - titre_court : 4-6 mots si on veut un sur-titre
 *
 * Modèle par défaut : Haiku 4.5 (≈ 0.1-0.3¢/appel — adapté à 4 variantes/bien).
 *
 * API publique :
 *   mbi_supports_redaction_ia_generer(
 *     array $bien, array $photos, array $agence,
 *     string $angle = 'generique',
 *     ?array $score = null,
 *     ?string $modele = null
 *   ): array
 *
 *   Renvoie : {
 *     ok:bool,
 *     data: ?array {accroche, paragraphe, atouts:[3], titre_court},
 *     modele:string, cout_centimes:int, erreur:?string,
 *   }
 *
 * En cas d'erreur (pas de clé, JSON invalide, HTTP non 200) → ok=false,
 * le générateur PDF retombe sur le contenu existant (description bien /
 * annonce / accroche éditeur).
 * ═══════════════════════════════════════════════════════════════════════
 */

require_once __DIR__ . '/mbi_supports_score_ia.php'; // helpers Anthropic réutilisés

if (!defined('MBI_SUPPORTS_REDACTION_PROMPT_VERSION')) {
    define('MBI_SUPPORTS_REDACTION_PROMPT_VERSION', '2026-05-05.v1');
}
if (!defined('MBI_SUPPORTS_REDACTION_MODEL')) {
    define('MBI_SUPPORTS_REDACTION_MODEL', 'claude-haiku-4-5');
}

if (!function_exists('mbi_supports_redaction_ia_generer')) {

    function mbi_supports_redaction_ia_generer(
        array $bien,
        array $photos,
        array $agence,
        string $angle = 'generique',
        ?array $score = null,
        ?string $modele = null
    ): array {
        $modeleId = mbi_supports_ia_modele_id($modele ?: 'haiku');

        $apiKey = mbi_supports_ia_anthropic_key();
        if ($apiKey === '') {
            return ['ok'=>false,'data'=>null,'modele'=>$modeleId,'cout_centimes'=>0,'erreur'=>'no_api_key'];
        }

        $sys = mbi_supports_redaction_system_prompt();
        $usr = mbi_supports_redaction_user_prompt($bien, $photos, $agence, $angle, $score);

        $payload = [
            'model'       => $modeleId,
            'max_tokens'  => 1200,
            'temperature' => 0.4, // un peu de variété rédactionnelle
            'system'      => $sys,
            'messages'    => [['role'=>'user', 'content'=>$usr]],
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
            return ['ok'=>false,'data'=>null,'modele'=>$modeleId,'cout_centimes'=>0,
                    'erreur'=>"anthropic_http_{$code}: " . ($err ?: substr((string)$raw, 0, 300))];
        }

        $body = json_decode((string)$raw, true);
        $text = $body['content'][0]['text'] ?? '';
        if (!is_string($text) || $text === '') {
            return ['ok'=>false,'data'=>null,'modele'=>$modeleId,'cout_centimes'=>0,'erreur'=>'reponse_vide'];
        }

        $data = mbi_supports_ia_extract_json($text); // helper du score
        if ($data === null) {
            return ['ok'=>false,'data'=>null,'modele'=>$modeleId,
                    'cout_centimes'=>mbi_supports_ia_estimer_cout($body),
                    'erreur'=>'json_invalide'];
        }

        $data = mbi_supports_redaction_normalize($data);

        return [
            'ok'            => true,
            'data'          => $data,
            'modele'        => $modeleId,
            'cout_centimes' => mbi_supports_ia_estimer_cout($body),
            'erreur'        => null,
        ];
    }
}

if (!function_exists('mbi_supports_redaction_system_prompt')) {
    function mbi_supports_redaction_system_prompt(): string
    {
        return implode("\n", [
            "Tu es un rédacteur publicitaire immobilier français spécialisé dans les supports commerciaux d'agence.",
            "Tu rédiges l'accroche, le paragraphe et les atouts d'une affiche vitrine en français professionnel.",
            "",
            "RÈGLES NON NÉGOCIABLES :",
            "- Tu ne mentionnes JAMAIS un atout absent des données fournies (pas d'invention).",
            "- Tu adaptes le ton à l'angle marketing demandé (cf. user prompt).",
            "- Tu n'écris pas de phrases creuses ('bien rare', 'à voir absolument') sans contenu concret.",
            "- Tu n'évoques le DPE/GES que si défavorable ET que l'angle s'y prête (rénovation/MaPrimeRénov').",
            "- Aucune mention de prix, d'honoraires, de surface en chiffres dans la rédaction (le template les affiche déjà).",
            "- Tu retournes UNIQUEMENT un JSON strict, AUCUN markdown, AUCUN texte autour, AUCUN fence ```.",
            "",
            "Schéma JSON exact :",
            '{',
            '  "accroche":     "string",       // 1 phrase, ≤ 110 chars, sans guillemets dans le texte',
            '  "paragraphe":   "string",       // 3 à 5 phrases, ~400-700 chars',
            '  "atouts":       ["s","s","s"],  // EXACTEMENT 3 phrases courtes (≤ 100 chars chacune)',
            '  "titre_court":  "string"        // 3 à 6 mots',
            '}',
        ]);
    }
}

if (!function_exists('mbi_supports_redaction_user_prompt')) {
    function mbi_supports_redaction_user_prompt(
        array $bien, array $photos, array $agence, string $angle, ?array $score
    ): string {
        $vue = [
            'reference'         => $bien['reference_bien'] ?? null,
            'designation'       => $bien['designation'] ?? null,
            'description'       => mb_substr((string)($bien['description'] ?? $bien['descriptif'] ?? ''), 0, 1500),
            'type_bien'         => $bien['type_bien_libelle'] ?? $bien['type'] ?? null,
            'surface'           => $bien['surface_habitable'] ?? $bien['surface'] ?? null,
            'nb_pieces'         => $bien['nb_pieces'] ?? $bien['nombre_pieces'] ?? null,
            'etage'             => $bien['etage'] ?? null,
            'exposition'        => $bien['exposition'] ?? $bien['orientation'] ?? null,
            'etat'              => $bien['etat'] ?? $bien['etat_general'] ?? null,
            'annee_construction'=> $bien['annee_construction'] ?? null,
            'ville'             => $bien['ville'] ?? null,
            'code_postal'       => $bien['code_postal'] ?? null,
            'dpe'               => $bien['dpe_classe'] ?? $bien['dpe'] ?? null,
            'ges'               => $bien['ges_classe'] ?? $bien['ges'] ?? null,
            'copropriete'       => (int)($bien['bien_en_copropriete'] ?? $bien['copropriete'] ?? 0) === 1,
            'jardin'            => $bien['jardin'] ?? null,
            'terrasse'          => $bien['terrasse'] ?? null,
            'balcon'            => $bien['balcon'] ?? null,
            'parking'           => $bien['parking'] ?? null,
            'cave'              => $bien['cave'] ?? null,
        ];

        $consigneAngle = match($angle) {
            'famille'        => "Cible : FAMILLE avec enfants. Ton : chaleureux, projet de vie, qualité du quartier (écoles, calme, espaces). Mots-clés : convivial, lumineux, fonctionnel, sécurisant.",
            'investisseur'   => "Cible : INVESTISSEUR locatif. Ton : factuel, chiffré, rationnel. Mots-clés : rendement, demande locative, faibles charges, emplacement, peu d'entretien. Pas d'émotion.",
            'premium'        => "Cible : ACHETEUR PREMIUM. Ton : sobre, exigeant, raffiné. Mots-clés : prestations, matériaux, exclusivité, finitions, signature. JAMAIS « rare » ou « unique » sans donnée concrète.",
            'premier_achat'  => "Cible : PRIMO-ACCÉDANT. Ton : accessible, pédagogique, rassurant. Mots-clés : projet, équilibre, transports, vie pratique, à votre rythme.",
            default          => "Cible : ACHETEUR GÉNÉRIQUE. Ton : sobre, factuel, professionnel. Met en avant les atouts les plus convaincants du bien.",
        };

        $jsonBien   = json_encode($vue,   JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $jsonScore  = $score ? json_encode([
            'angle_recommande' => $score['angle_recommande'] ?? null,
            'points_forts'     => json_decode((string)($score['points_forts_json'] ?? '[]'), true) ?: [],
            'points_faibles'   => json_decode((string)($score['points_faibles_json'] ?? '[]'), true) ?: [],
            'commentaire'      => $score['commentaire_ia'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : 'null';

        return implode("\n\n", [
            "ANGLE MARKETING DEMANDÉ : {$angle}",
            $consigneAngle,
            "BIEN À RÉDIGER :\n{$jsonBien}",
            "SCORE IA EXISTANT (si dispo, à RESPECTER comme contraintes mais pas à recopier mot pour mot) :\n{$jsonScore}",
            "Renvoie le JSON strict du schéma.",
        ]);
    }
}

if (!function_exists('mbi_supports_redaction_normalize')) {
    function mbi_supports_redaction_normalize(array $data): array
    {
        $accroche = trim((string)($data['accroche'] ?? ''));
        // Sécurise la longueur de l'accroche (110 chars max)
        if (mb_strlen($accroche) > 110) {
            $accroche = mb_substr($accroche, 0, 107) . '...';
        }
        // Retire d'éventuels guillemets parasites
        $accroche = trim($accroche, " \"'«»");

        $paragraphe = trim((string)($data['paragraphe'] ?? ''));
        if (mb_strlen($paragraphe) > 1500) {
            $paragraphe = mb_substr($paragraphe, 0, 1497) . '...';
        }

        $atouts = (array)($data['atouts'] ?? []);
        $atouts = array_values(array_filter(
            array_map(fn($a) => mb_substr(trim((string)$a), 0, 100), $atouts),
            fn($a) => $a !== ''
        ));
        $atouts = array_slice($atouts, 0, 3);

        $titre = trim((string)($data['titre_court'] ?? ''));
        if (mb_strlen($titre) > 80) $titre = mb_substr($titre, 0, 77) . '...';

        return [
            'accroche'    => $accroche,
            'paragraphe'  => $paragraphe,
            'atouts'      => $atouts,
            'titre_court' => $titre,
        ];
    }
}
