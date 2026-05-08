<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * mbi_supports_layouts_router.php — Routeur multi-layouts affiches A3 H
 * ═══════════════════════════════════════════════════════════════════════
 *
 * Choisit un layout parmi N selon des règles conditionnelles + tirage
 * pondéré reproductible (seed = id_bien + version → même bien/version
 * donne toujours le même layout).
 *
 * Layouts disponibles (V1 — 5 layouts cibles, 2 livrés en étape 1) :
 *   - cinema_coin       : photo plein cadre + carte info bas-droite (premium, peu de photos)
 *   - magazine_bandeau  : mosaïque photos haut + bandeau opaque navy bas avec descriptif
 *   - split_5050        : photo héro 50% gauche + colonne info 50% droite (à venir)
 *   - mosaique_haute    : 4 photos en grille haut + carte info compacte bas (à venir)
 *   - asymetrique       : composition diagonale style magazine (à venir, premium)
 *
 * API publique :
 *   mbi_supports_layout_choisir(array $ctx): string  → code layout
 *   mbi_supports_layout_render(string $code, array $ctx): TCPDF
 *
 * Override possible via $ctx['layout_force'] = 'cinema_coin' | etc.
 * ═══════════════════════════════════════════════════════════════════════
 */

if (!defined('MBI_SUPPORTS_LAYOUTS_DIR')) {
    define('MBI_SUPPORTS_LAYOUTS_DIR', __DIR__ . '/supports_layouts/');
}

if (!function_exists('mbi_supports_layout_catalogue')) {
    /**
     * Catalogue des layouts disponibles (code → fichier + builder + scoring).
     * @return array<string, array{file:string, fn:string, score:callable}>
     */
    function mbi_supports_layout_catalogue(): array
    {
        return [
            'cinema_coin' => [
                'file'  => 'cinema_coin.php',
                'fn'    => 'mbi_supports_layout_cinema_coin_build',
                'score' => function (array $ctx): int {
                    $bien   = $ctx['bien']   ?? [];
                    $angle  = (string)($ctx['angle'] ?? 'generique');
                    $nb     = (int)($ctx['nb_photos'] ?? 3);
                    $score  = 50;
                    if ($nb === 1)              $score += 30; // cinéma pur = best fit
                    if ($angle === 'premium')   $score += 20;
                    if ($angle === 'investisseur') $score += 5;
                    if ($nb >= 3)               $score -= 10; // moins adapté si bcp de photos
                    return $score;
                },
            ],
            'magazine_bandeau' => [
                'file'  => 'magazine_bandeau.php',
                'fn'    => 'mbi_supports_layout_magazine_bandeau_build',
                'score' => function (array $ctx): int {
                    $bien   = $ctx['bien']   ?? [];
                    $angle  = (string)($ctx['angle'] ?? 'generique');
                    $nb     = (int)($ctx['nb_photos'] ?? 3);
                    $desc   = (string)($bien['description'] ?? $bien['descriptif'] ?? '');
                    $score  = 50;
                    if ($nb >= 2)               $score += 15; // mosaïque mise en valeur
                    if (mb_strlen($desc) >= 300) $score += 15; // bonne place pour le texte
                    if ($angle === 'famille')   $score += 20;
                    if ($angle === 'premier_achat') $score += 10;
                    if ($nb === 1)              $score -= 20; // moins adapté avec 1 photo
                    return $score;
                },
            ],
            // 'split_5050'   => [...], // étape 2
            // 'mosaique_haute' => [...], // étape 2
            // 'asymetrique'  => [...], // étape 2
        ];
    }
}

if (!function_exists('mbi_supports_layout_choisir')) {
    /**
     * Choisit le layout pour un contexte donné.
     * Override > tirage pondéré reproductible (seed = id_bien + version).
     *
     * @return string Code du layout choisi (clé du catalogue)
     */
    function mbi_supports_layout_choisir(array $ctx): string
    {
        $catalogue = mbi_supports_layout_catalogue();

        // 1. Override explicite (éditeur force un layout précis)
        $force = (string)($ctx['layout_force'] ?? '');
        if ($force !== '' && isset($catalogue[$force])) {
            return $force;
        }

        // 2. Calcul du score pour chaque layout
        $scores = [];
        foreach ($catalogue as $code => $def) {
            $sc = (int)($def['score'])($ctx);
            if ($sc < 1) $sc = 1; // garantit chance non nulle
            $scores[$code] = $sc;
        }
        if (empty($scores)) {
            return 'cinema_coin'; // safety net
        }

        // 3. Tirage pondéré reproductible (seed = id_bien + version)
        $idBien  = (int)($ctx['bien']['id'] ?? 0);
        $version = (int)($ctx['version'] ?? 1);
        $seed    = $idBien * 1000 + $version;
        mt_srand($seed);

        $total = array_sum($scores);
        $tir   = mt_rand(1, $total);
        $cum   = 0;
        $choisi = array_key_first($scores);
        foreach ($scores as $code => $sc) {
            $cum += $sc;
            if ($tir <= $cum) {
                $choisi = $code;
                break;
            }
        }

        // 4. Reset srand pour ne pas polluer le reste de l'app
        mt_srand();

        return $choisi;
    }
}

if (!function_exists('mbi_supports_layout_render')) {
    /**
     * Charge le fichier du layout et appelle son builder.
     *
     * @throws RuntimeException si layout introuvable ou builder absent
     */
    function mbi_supports_layout_render(string $code, array $ctx): TCPDF
    {
        $catalogue = mbi_supports_layout_catalogue();
        if (!isset($catalogue[$code])) {
            throw new RuntimeException("Layout inconnu : {$code}");
        }
        $def = $catalogue[$code];

        $path = MBI_SUPPORTS_LAYOUTS_DIR . $def['file'];
        if (!is_file($path)) {
            throw new RuntimeException("Fichier layout introuvable : {$path}");
        }
        require_once $path;

        $fn = $def['fn'];
        if (!function_exists($fn)) {
            throw new RuntimeException("Builder layout introuvable : {$fn}");
        }
        $pdf = $fn($ctx);
        if (!($pdf instanceof TCPDF)) {
            throw new RuntimeException("Layout {$code} n'a pas retourné un TCPDF");
        }
        return $pdf;
    }
}
