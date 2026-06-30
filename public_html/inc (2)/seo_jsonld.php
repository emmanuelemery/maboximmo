<?php
/**
 * inc/seo_jsonld.php — SEO JSON-LD helpers (Phase 3)
 *
 * Fonctions pures qui génèrent du HTML <script type="application/ld+json">
 * pour les structured data Schema.org + balises Open Graph / Twitter Cards.
 *
 * La fonction h() est fournie par inc/security.php (déjà chargé via bootstrap).
 */
declare(strict_types=1);

/**
 * JSON-LD RealEstateListing pour un bien immobilier.
 * Adapté aux colonnes réelles de la table `biens` dans MaBoxImmo.
 */
function jsonld_real_estate(array $bien, string $baseUrl = ''): string
{
    if (empty($baseUrl)) {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'maboximmo.fr');
    }

    $title       = h($bien['designation'] ?? $bien['titre_seo'] ?? $bien['accroche_commerciale'] ?? '');
    $description = h($bien['description'] ?? $bien['meta_description'] ?? '');
    $price       = (float)($bien['prix_vente_estime'] ?? $bien['loyer_hc'] ?? 0);
    $ville       = h($bien['ville'] ?? '');
    $cp          = h($bien['code_postal'] ?? '');
    $slug        = h($bien['slug'] ?? (string)($bien['id'] ?? ''));
    $disponible  = ($bien['statut_bien'] ?? '') !== 'vendu' ? 'InStock' : 'OutOfStock';

    // Images depuis biens_photos ou champ inline
    $images = [];
    if (!empty($bien['_photos']) && is_array($bien['_photos'])) {
        foreach (array_slice($bien['_photos'], 0, 5) as $photo) {
            $src = is_array($photo) ? ($photo['url'] ?? $photo['chemin'] ?? '') : (string)$photo;
            if ($src) $images[] = $baseUrl . '/' . ltrim($src, '/');
        }
    }

    $data = [
        '@context'    => 'https://schema.org',
        '@type'       => 'RealEstateListing',
        'name'        => $title,
        'description' => $description,
        'url'         => $baseUrl . '/bien_recherche.php?slug=' . $slug,
        'image'       => $images,
        'address'     => [
            '@type'           => 'PostalAddress',
            'addressLocality' => $ville,
            'postalCode'      => $cp,
            'addressCountry'  => 'FR',
        ],
        'offers' => [
            '@type'         => 'Offer',
            'price'         => $price,
            'priceCurrency' => 'EUR',
            'availability'  => 'https://schema.org/' . $disponible,
        ],
    ];

    return '<script type="application/ld+json">'
        . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        . '</script>';
}

/**
 * JSON-LD RealEstateAgent / LocalBusiness pour une agence.
 * Adapté aux colonnes réelles de la table `agences` dans MaBoxImmo.
 */
function jsonld_local_business(array $agence, string $baseUrl = ''): string
{
    if (empty($baseUrl)) {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $baseUrl = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'maboximmo.fr');
    }

    $name    = h($agence['nom_agence'] ?? $agence['nom'] ?? '');
    $tel     = h($agence['telephone'] ?? '');
    $ville   = h($agence['ville'] ?? '');
    $cp      = h($agence['code_postal'] ?? '');
    $adresse = h($agence['adresse_1'] ?? '');
    $slug    = h($agence['slug'] ?? (string)($agence['id'] ?? ''));
    $logo    = !empty($agence['logo_url']) ? $baseUrl . '/' . ltrim($agence['logo_url'], '/') : '';

    $data = [
        '@context'  => 'https://schema.org',
        '@type'     => 'RealEstateAgent',
        'name'      => $name,
        'url'       => $baseUrl . '/agence_portail.php?slug=' . $slug,
        'telephone' => $tel,
        'address'   => [
            '@type'           => 'PostalAddress',
            'streetAddress'   => $adresse,
            'addressLocality' => $ville,
            'postalCode'      => $cp,
            'addressCountry'  => 'FR',
        ],
    ];
    if ($logo) $data['logo'] = $logo;

    return '<script type="application/ld+json">'
        . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        . '</script>';
}

/**
 * Balises Open Graph + Twitter Cards.
 */
function og_tags(array $meta): string
{
    $proto   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $baseUrl = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'maboximmo.fr');
    $defaultImg = $baseUrl . '/images/Home.png';

    $title       = h($meta['title'] ?? 'MaBoxImmo — Plateforme immobilière');
    $description = h($meta['description'] ?? 'Gestion agence, syndic, RH et annonces immobilières.');
    $image       = h($meta['image'] ?? $defaultImg);
    $url         = h($meta['url'] ?? $baseUrl . ($_SERVER['REQUEST_URI'] ?? '/'));
    $type        = h($meta['type'] ?? 'website');

    $tags  = '<meta property="og:title" content="' . $title . '">' . PHP_EOL;
    $tags .= '<meta property="og:description" content="' . $description . '">' . PHP_EOL;
    $tags .= '<meta property="og:type" content="' . $type . '">' . PHP_EOL;
    $tags .= '<meta property="og:url" content="' . $url . '">' . PHP_EOL;
    $tags .= '<meta property="og:image" content="' . $image . '">' . PHP_EOL;
    $tags .= '<meta property="og:locale" content="fr_FR">' . PHP_EOL;
    $tags .= '<meta property="og:site_name" content="MaBoxImmo">' . PHP_EOL;
    $tags .= '<meta name="twitter:card" content="summary_large_image">' . PHP_EOL;
    $tags .= '<meta name="twitter:title" content="' . $title . '">' . PHP_EOL;
    $tags .= '<meta name="twitter:description" content="' . $description . '">' . PHP_EOL;
    $tags .= '<meta name="twitter:image" content="' . $image . '">' . PHP_EOL;

    return $tags;
}
