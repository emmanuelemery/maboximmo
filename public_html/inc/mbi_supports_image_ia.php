<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * mbi_supports_image_ia.php — Génération d'image affiche via gpt-image-1
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Pivot 2026-05-06 : on abandonne le template TCPDF figé pour des affiches
 * générées par IA d'image (OpenAI gpt-image-1). Voir mémoire
 * project_pivot_affiche_ia_image.md pour le contexte.
 *
 * POC V1 :
 *   - Génère une image d'AMBIANCE (sans photo du bien, sans mentions légales)
 *   - Format paysage 1536×1024 (proche A3 horizontal, ratio 3:2)
 *   - Le prompt est adapté à l'angle marketing (famille / investisseur /
 *     premium / premier_achat)
 *
 * Phase 2 (après validation rendu) :
 *   - Composition finale en PHP/Imagick : image IA + photo réelle insérée
 *     + bandeau mentions légales pixel-parfait (Option B hybride)
 *
 * API publique :
 *   mbi_supports_image_ia_generer(array $bien, array $agence,
 *                                  string $angle, ?array $redaction = null,
 *                                  ?string $size = null): array
 *
 *   Renvoie : {
 *     ok:bool, image_b64:string|null, modele:string,
 *     cout_centimes:int, prompt:string, erreur:?string
 *   }
 * ═══════════════════════════════════════════════════════════════════════
 */

if (!defined('MBI_SUPPORTS_IMAGE_MODEL')) {
    define('MBI_SUPPORTS_IMAGE_MODEL', 'gpt-image-1');
}
if (!defined('MBI_SUPPORTS_IMAGE_SIZE_DEFAULT')) {
    // 1536×1024 = paysage 3:2 (proche A3 paysage 1.41:1)
    define('MBI_SUPPORTS_IMAGE_SIZE_DEFAULT', '1536x1024');
}

if (!function_exists('mbi_supports_image_openai_key')) {
    function mbi_supports_image_openai_key(): string
    {
        if (defined('OPENAI_API_KEY') && OPENAI_API_KEY !== '') return (string)OPENAI_API_KEY;
        $k = $GLOBALS['OPENAI_API_KEY'] ?? '';
        if (is_string($k) && $k !== '') return $k;
        return (string)(getenv('OPENAI_API_KEY') ?: '');
    }
}

if (!function_exists('mbi_supports_image_ia_generer')) {

    /**
     * Génère une image d'affiche via gpt-image-1.
     *
     * @param array $bien      Contexte bien (designation, surface, prix, ville, etc.)
     * @param array $agence    Contexte agence (nom_agence, ville_agence, etc.)
     * @param string $angle    famille|investisseur|premium|premier_achat|generique
     * @param ?array $redaction Sortie LOT 2 (accroche, paragraphe, atouts) si dispo
     * @param ?string $size    Format image (1024x1024 / 1536x1024 / 1024x1536). Default = paysage.
     */
    function mbi_supports_image_ia_generer(
        array $bien,
        array $agence,
        string $angle = 'generique',
        ?array $redaction = null,
        ?string $size = null
    ): array {
        $modele = MBI_SUPPORTS_IMAGE_MODEL;
        $size   = $size ?: MBI_SUPPORTS_IMAGE_SIZE_DEFAULT;

        $apiKey = mbi_supports_image_openai_key();
        if ($apiKey === '') {
            return ['ok'=>false,'image_b64'=>null,'modele'=>$modele,'cout_centimes'=>0,'prompt'=>'','erreur'=>'no_openai_key'];
        }

        $prompt = mbi_supports_image_ia_build_prompt($bien, $agence, $angle, $redaction);

        $payload = [
            'model'   => $modele,
            'prompt'  => $prompt,
            'n'       => 1,
            'size'    => $size,
            'quality' => 'high',     // low / medium / high / auto
            'output_format'  => 'png',
            'background'     => 'auto',
        ];

        $ch = curl_init('https://api.openai.com/v1/images/generations');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 180, // gpt-image-1 peut prendre 30-60s en quality=high
        ]);
        $raw  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $code !== 200) {
            return ['ok'=>false,'image_b64'=>null,'modele'=>$modele,'cout_centimes'=>0,'prompt'=>$prompt,
                    'erreur'=>"openai_http_{$code}: " . ($err ?: substr((string)$raw, 0, 500))];
        }

        $body = json_decode((string)$raw, true);
        $b64  = $body['data'][0]['b64_json'] ?? null;
        if (!is_string($b64) || $b64 === '') {
            return ['ok'=>false,'image_b64'=>null,'modele'=>$modele,'cout_centimes'=>0,'prompt'=>$prompt,
                    'erreur'=>'reponse_vide_ou_url_only',
                    'raw'=>$body];
        }

        // Estimation coût : gpt-image-1 high ≈ 17¢ pour 1024x1024, ~25¢ pour 1536x1024
        $coutCentimes = (int)round(match ($size) {
            '1024x1024' => 17,
            '1536x1024', '1024x1536' => 25,
            default => 17,
        });

        return [
            'ok'            => true,
            'image_b64'     => $b64,
            'modele'        => $modele,
            'cout_centimes' => $coutCentimes,
            'prompt'        => $prompt,
            'erreur'        => null,
        ];
    }
}

if (!function_exists('mbi_supports_image_ia_build_prompt')) {
    /**
     * Construit le prompt selon l'angle marketing et le contexte du bien.
     * Le rendu doit être ESTHÉTIQUE et SANS photo réelle ni mentions légales
     * (ce sont les couches PHP qui les ajouteront en option B).
     */
    function mbi_supports_image_ia_build_prompt(array $bien, array $agence, string $angle, ?array $redaction): string
    {
        $type   = (string)($bien['type_bien_libelle'] ?? $bien['type'] ?? 'bien immobilier');
        $surf   = $bien['surface_habitable'] ?? $bien['surface'] ?? null;
        $pieces = $bien['nb_pieces'] ?? $bien['nombre_pieces'] ?? null;
        $ville  = (string)($bien['ville'] ?? '');
        $prix   = $bien['prix_vente_estime'] ?? $bien['prix_vente'] ?? $bien['prix'] ?? null;

        $accroche = '';
        $atouts   = [];
        if (is_array($redaction)) {
            $accroche = trim((string)($redaction['accroche'] ?? ''));
            if (!empty($redaction['atouts']) && is_array($redaction['atouts'])) {
                $atouts = array_slice($redaction['atouts'], 0, 3);
            }
        }
        // Fallback accroche si pas de Haiku
        if ($accroche === '') {
            $accroche = $type . ' ' . ($surf ? $surf . ' m²' : '') . ($ville ? ' à ' . $ville : '');
            $accroche = trim($accroche);
        }

        // Style et palette par angle marketing — STRICT, renforcé 2026-05-06
        // gpt-image-1 a tendance à ignorer les nuances → on insiste avec
        // hex codes répétés, mots-clés visuels concrets, et negative prompt.
        $stylePalette = match ($angle) {
            'famille' => [
                'mood'    => 'warm cozy family home — Scandinavian-meets-Provence vibe, soft natural daylight',
                'palette' => 'DOMINANT colors: warm cream (#FAF1E0) + sage green (#7A9B6F / #A8C9A1) + oak wood beige (#C9A57B). NO terracotta, NO orange, NO red.',
                'tone'    => 'reassuring, lifestyle-oriented, gentle — like Maisons du Monde catalog',
                'forbidden' => 'AVOID: orange, terracotta, red tones, bright neon colors, dark navy',
            ],
            'investisseur' => [
                'mood'    => 'sleek financial professional — Bloomberg meets architecture digest',
                'palette' => 'DOMINANT colors: deep navy blue (#243B5C / #1a3458) + cool steel grey (#6B7785) + clean ivory (#F5F2EB) + minimal slate accents. NO warm tones at all.',
                'tone'    => 'rational, precise, data-driven — like a private banking brochure',
                'forbidden' => 'AVOID: warm beiges, terracotta, orange, gold accents, decorative plants',
            ],
            'premium' => [
                'mood'    => 'haute couture real estate — Christian Liaigre interior magazine',
                'palette' => 'DOMINANT colors: deep midnight navy (#1a2536) + antique gold (#A57C32 / #C8A05C) + warm ivory (#F2EBDD) + soft marble grey textures. NO bright colors, NO terracotta.',
                'tone'    => 'sober elegance, signature feel, exclusivity — Goyard, Hermès aesthetic',
                'forbidden' => 'AVOID: terracotta, orange, bright greens, casual vibes, sun rays clichés',
            ],
            'premier_achat' => [
                'mood'    => 'optimistic first-home journey — sunny, hopeful, accessible',
                'palette' => 'DOMINANT colors: terracotta (#C06646) + cream (#FAF1E0) + soft coral (#E8A88C) + warm beige (#D9C9A8) + light wood. This is the ONLY angle where terracotta is welcome.',
                'tone'    => 'welcoming, journey-oriented, hopeful — Pinterest moodboard',
                'forbidden' => 'AVOID: cold blues, navy, gold luxury accents',
            ],
            default => [
                'mood'    => 'modern professional real estate',
                'palette' => 'DOMINANT colors: navy blue (#243B5C) + antique gold (#D4A047) + white + light grey',
                'tone'    => 'sober, factual, trustworthy',
                'forbidden' => 'AVOID: garish colors',
            ],
        };

        $atoutsTxt = $atouts ? "Key strengths to evoke visually (DO NOT WRITE THESE WORDS, just inspire the visual mood): " . implode(' ; ', $atouts) : '';

        // Le prompt est en anglais (gpt-image-1 fonctionne mieux en anglais).
        $prompt = <<<PROMPT
Design a high-end real estate window display poster, A3 LANDSCAPE format (3:2 ratio), in French context.

PURPOSE: Window-display poster for a real estate agency in {$ville}, France. Will be PRINTED at A3 (420mm × 297mm) and displayed behind glass.

═══ STRICT VISUAL DIRECTION FOR ANGLE: {$angle} ═══
- Mood: {$stylePalette['mood']}
- Color palette (THIS IS NON-NEGOTIABLE — use ONLY these tones): {$stylePalette['palette']}
- Tone: {$stylePalette['tone']}
- {$stylePalette['forbidden']}

═══ LAYOUT (CRITICAL — must be respected) ═══
- LEFT 35% of the poster: KEEP EMPTY, CALM, with only subtle gradient or solid background color from the palette. NO design elements here. The developer will paste a real property photo on top.
- CENTER & RIGHT 65%: this is where ALL the visual design goes — accroche text, decorative shapes, geometric patterns, abstract architectural elements, plants/icons
- BOTTOM BAND (last 80 pixels): MUST stay calm and low-contrast — NO design, NO shapes — for legal mentions added later in PHP

═══ TEXT TO INCLUDE ═══
Render this text in elegant French serif typography (think Didot, Playfair, Tiempos):
- Main headline (large): "{$accroche}"
- Subtitle (smaller): {$type} • {$ville}

═══ STRICTLY FORBIDDEN — DO NOT GENERATE ═══
- Real photographs of buildings, houses, rooms, interiors (the real photo will be inserted later)
- Any legal text (no carte pro number, no garant, no RC pro, no DPE)
- Any price or amount in euros
- Any agency logo or website URL
- Any QR code
- Any real estate cliché (no key icons, no house outlines, no "for sale" signs)

═══ STYLE REFERENCE ═══
Editorial magazine layout — like a curated French interior design magazine cover (Côté Maison, AD France, Marie Claire Maison) crossed with a luxury boutique window installation. Minimalist, lots of white space, refined typography, geometric / botanical accents.

{$atoutsTxt}

OUTPUT: single PNG image, A3 landscape (3:2 ratio), high quality. Make it ELEGANT, DIFFERENTIATED from typical real estate templates, and STRICTLY following the color palette specified for the "{$angle}" angle.
PROMPT;

        return $prompt;
    }
}
