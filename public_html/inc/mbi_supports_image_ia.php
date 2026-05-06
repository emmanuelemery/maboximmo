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

        // Style et palette par angle marketing
        $stylePalette = match ($angle) {
            'famille' => [
                'mood'    => 'warm and inviting, family-friendly atmosphere, soft natural light',
                'palette' => 'warm cream, soft sage green (#A8C9A1), oak wood tones, brushed gold accents',
                'tone'    => 'reassuring, lifestyle-oriented, gentle',
            ],
            'investisseur' => [
                'mood'    => 'sleek, professional, financial confidence',
                'palette' => 'navy blue (#243B5C), steel grey, clean white, slate accents',
                'tone'    => 'rational, precise, trust-inducing, data-driven aesthetic',
            ],
            'premium' => [
                'mood'    => 'luxurious, refined, exclusive',
                'palette' => 'deep navy (#1a2536), antique gold (#A57C32), ivory, marble textures',
                'tone'    => 'sober elegance, signature feel, high-end',
            ],
            'premier_achat' => [
                'mood'    => 'fresh, optimistic, accessible',
                'palette' => 'terracotta (#C06646), cream, warm beige, soft coral, light wood',
                'tone'    => 'welcoming, modern, journey-oriented',
            ],
            default => [
                'mood'    => 'modern professional real estate',
                'palette' => 'navy blue (#243B5C), antique gold (#D4A047), white, light grey',
                'tone'    => 'sober, factual, trustworthy',
            ],
        };

        $atoutsTxt = $atouts ? "Key strengths to evoke visually (DO NOT WRITE THESE WORDS, just inspire the visual mood): " . implode(' ; ', $atouts) : '';

        // Le prompt est en anglais (gpt-image-1 fonctionne mieux en anglais).
        $prompt = <<<PROMPT
Design a high-end real estate window display poster, A3 LANDSCAPE format (3:2 ratio), in French context.

PURPOSE: Window-display poster shown in a real estate agency window, designed to attract walking pedestrians. The poster will be PRINTED at A3 size (420mm × 297mm).

VISUAL STYLE:
- {$stylePalette['mood']}
- Color palette: {$stylePalette['palette']}
- Tone: {$stylePalette['tone']}
- Editorial magazine layout, refined typography, lots of white space
- NO photographic content of the property itself (a real photo will be inserted later by the developer in a reserved zone — do NOT generate any building, room, or interior)
- Instead, suggest the property MOOD with: minimalist abstract architectural shapes, geometric patterns, soft gradients, decorative objects (plants, light rays), or stylized icons

LAYOUT REQUIREMENTS (CRITICAL):
- LEFT THIRD of the poster: reserved for the actual property photo — leave this area visually CALM and EMPTY (subtle gradient or solid color), so the developer can paste the real photo there
- CENTER & RIGHT: the design and accroche
- A 80-pixel-tall band at the BOTTOM must remain CALM (low contrast, no design elements) — for legal mentions added later

TEXT TO INCLUDE (large, elegant typography):
- Main accroche (1 short line, in French): "{$accroche}"
- Property type: {$type}
- Location: {$ville}

DO NOT INCLUDE:
- Real photos of buildings/rooms (will be added by developer)
- Legal mentions (carte pro, garant, RC pro — added by developer)
- Price (will be added by developer)
- Logo of any agency (will be added by developer)
- Any QR code or website URL

{$atoutsTxt}

OUTPUT: a single PNG image, A3 landscape (3:2), printable quality. Style = a curated French real estate magazine cover (Côté Maison, AD France) crossed with a luxury boutique window. Make it BEAUTIFUL and DIFFERENTIATED — avoid cliché real estate templates.
PROMPT;

        return $prompt;
    }
}
