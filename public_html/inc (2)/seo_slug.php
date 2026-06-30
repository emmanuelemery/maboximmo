<?php
declare(strict_types=1);

/**
 * SeoSlug — Helpers de génération de slugs et noms de fichiers SEO
 *
 * Centralise toute la logique de nommage SEO pour :
 *  - les slugs d'annonces (URL publiques)
 *  - les noms de fichiers photos (disque + URL)
 *  - les attributs alt / captions
 *
 * Règle d'or : un seul point d'entrée pour chaque forme, JAMAIS de
 * duplication de logique slugify ailleurs dans le code.
 */
final class SeoSlug
{
    /**
     * Translittère + normalise une chaîne en slug URL-safe.
     * Exemple : "Lyon 3e Arrondissement" → "lyon-3e-arrondissement"
     */
    public static function slugify(?string $texte): string
    {
        $texte = (string)$texte;
        if ($texte === '') return '';

        // Translittération des accents (ISO-8859-1 → ASCII)
        if (function_exists('iconv')) {
            $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texte);
            if ($tr !== false) $texte = $tr;
        }

        // Minuscules
        $texte = strtolower($texte);

        // Remplace tout ce qui n'est pas [a-z0-9] par un tiret
        $texte = preg_replace('/[^a-z0-9]+/', '-', $texte) ?? '';

        // Supprime les tirets multiples et les tirets en début/fin
        $texte = trim(preg_replace('/-+/', '-', $texte) ?? '', '-');

        return $texte;
    }

    /**
     * Tronque un slug à une longueur maximale en coupant proprement sur un tiret.
     */
    public static function truncate(string $slug, int $maxLen = 80): string
    {
        if (strlen($slug) <= $maxLen) return $slug;
        $cut = substr($slug, 0, $maxLen);
        $lastDash = strrpos($cut, '-');
        if ($lastDash !== false && $lastDash > 20) {
            $cut = substr($cut, 0, $lastDash);
        }
        return trim($cut, '-');
    }

    /**
     * Génère le slug complet d'une annonce.
     *
     * Format : {type}-{sous-type}-{surface}m2-{ville}-{agence}-{id}
     * Exemple : "maison-t4-95m2-lyon-3e-mabox-part-dieu-412"
     *
     * Tous les paramètres sont tolérants au null / vide — la fonction
     * compose ce qu'elle peut et ignore les morceaux absents.
     *
     * @param array $bien    ['type_bien','sous_type_bien','surface_habitable','ville',...]
     * @param array $annonce ['id']
     * @param array $agence  ['slug' ou 'nom_agence']
     */
    public static function annonceSlug(array $bien, array $annonce, array $agence): string
    {
        $parts = [];

        // 1. Type de bien (maison, appartement, terrain…)
        if (!empty($bien['type_bien'])) {
            $parts[] = self::slugify((string)$bien['type_bien']);
        }

        // 2. Sous-type (T1/T2/T3…) — seulement si pertinent
        if (!empty($bien['sous_type_bien'])) {
            $parts[] = self::slugify((string)$bien['sous_type_bien']);
        }

        // 3. Surface habitable (ou terrain à défaut)
        $surface = $bien['surface_habitable'] ?? $bien['surface_terrain'] ?? null;
        if ($surface !== null && (float)$surface > 0) {
            $parts[] = ((int)round((float)$surface)) . 'm2';
        }

        // 4. Ville
        if (!empty($bien['ville'])) {
            $parts[] = self::slugify((string)$bien['ville']);
        }

        // 5. Agence (préférer le slug déjà calculé)
        $agenceSlug = '';
        if (!empty($agence['slug'])) {
            $agenceSlug = (string)$agence['slug'];
        } elseif (!empty($agence['nom_agence'])) {
            $agenceSlug = self::slugify((string)$agence['nom_agence']);
        }
        if ($agenceSlug !== '') {
            $parts[] = $agenceSlug;
        }

        // 6. ID annonce (toujours en fin, garantit l'unicité)
        if (!empty($annonce['id'])) {
            $parts[] = (string)(int)$annonce['id'];
        }

        $slug = implode('-', array_filter($parts, fn($p) => $p !== ''));
        return self::truncate($slug, 180); // marge sous la limite BDD de 190
    }

    /**
     * Génère le nom de fichier SEO d'une photo.
     *
     * Format court (pour ne pas exploser la longueur) :
     *   {type}-{ville}-{agence}-{id}-{NN}[-variante].{ext}
     *
     * Exemples :
     *   maison-lyon-3e-mabox-part-dieu-412-01.jpg
     *   maison-lyon-3e-mabox-part-dieu-412-01-thumb.webp
     *   maison-lyon-3e-mabox-part-dieu-412-01-800.webp
     *
     * @param int    $ordre    Numéro d'ordre (1-99)
     * @param string $variante original | thumb | medium | large | xlarge
     * @param string $ext      Extension finale (jpg, webp, png…)
     */
    public static function photoName(
        array $bien,
        array $annonce,
        array $agence,
        int $ordre,
        string $variante = 'original',
        string $ext = 'jpg'
    ): string {
        $parts = [];

        if (!empty($bien['type_bien'])) {
            $parts[] = self::slugify((string)$bien['type_bien']);
        }
        if (!empty($bien['ville'])) {
            $parts[] = self::slugify((string)$bien['ville']);
        }

        $agenceSlug = !empty($agence['slug'])
            ? (string)$agence['slug']
            : self::slugify((string)($agence['nom_agence'] ?? ''));
        if ($agenceSlug !== '') {
            $parts[] = $agenceSlug;
        }

        if (!empty($annonce['id'])) {
            $parts[] = (string)(int)$annonce['id'];
        }

        // Numéro d'ordre sur 2 chiffres
        $parts[] = str_pad((string)max(1, min(99, $ordre)), 2, '0', STR_PAD_LEFT);

        $base = implode('-', array_filter($parts, fn($p) => $p !== ''));
        $base = self::truncate($base, 140);

        // Suffixe variante (sauf pour l'original)
        $suffixe = '';
        switch ($variante) {
            case 'original': $suffixe = '';         break;
            case 'thumb':    $suffixe = '-thumb';   break;
            case 'medium':   $suffixe = '-800';     break;
            case 'large':    $suffixe = '-1600';    break;
            case 'xlarge':   $suffixe = '-2400';    break;
            default:         $suffixe = '-' . self::slugify($variante);
        }

        $ext = strtolower(preg_replace('/[^a-z0-9]/i', '', $ext) ?? 'jpg');
        if ($ext === '') $ext = 'jpg';

        return $base . $suffixe . '.' . $ext;
    }

    /**
     * Retourne le dossier disque (absolu) où stocker les photos d'une annonce.
     * Crée l'arborescence si elle n'existe pas.
     *
     * Convention : uploads/annonces/{id_societe}/{id_annonce}/
     */
    public static function photoDirAbs(int $idSociete, int $idAnnonce): string
    {
        $base = dirname(__DIR__) . '/uploads/annonces/' . $idSociete . '/' . $idAnnonce . '/';
        if (!is_dir($base)) {
            @mkdir($base, 0755, true);
        }
        return $base;
    }

    /**
     * Retourne le chemin relatif (depuis public_html/) pour stocker en BDD.
     * À concaténer avec le site_url côté front.
     */
    public static function photoDirRel(int $idSociete, int $idAnnonce): string
    {
        return 'uploads/annonces/' . $idSociete . '/' . $idAnnonce . '/';
    }

    /**
     * Génère un attribut ALT descriptif et unique pour une photo.
     *
     * Exemple : "Photo 1 — Maison T4 95 m² à Lyon 3e — Mabox Part-Dieu"
     */
    public static function photoAlt(array $bien, array $annonce, array $agence, int $ordre): string
    {
        $morceaux = [];
        $morceaux[] = 'Photo ' . $ordre;

        $titre = [];
        if (!empty($bien['type_bien']))      $titre[] = ucfirst((string)$bien['type_bien']);
        if (!empty($bien['sous_type_bien'])) $titre[] = strtoupper((string)$bien['sous_type_bien']);
        $surface = $bien['surface_habitable'] ?? $bien['surface_terrain'] ?? null;
        if ($surface !== null && (float)$surface > 0) {
            $titre[] = ((int)round((float)$surface)) . ' m²';
        }
        if (!empty($bien['ville'])) {
            $titre[] = 'à ' . (string)$bien['ville'];
        }

        if ($titre) {
            $morceaux[] = implode(' ', $titre);
        }

        $nomAgence = $agence['nom_agence'] ?? $agence['nom_commercial'] ?? '';
        if ($nomAgence !== '') {
            $morceaux[] = (string)$nomAgence;
        }

        return implode(' — ', $morceaux);
    }
}
