<?php
declare(strict_types=1);

require_once __DIR__ . '/seo_slug.php';

function vitrine_origin(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function vitrine_base_url(): string
{
    // Origine (scheme + host). Les chemins internes utilisent app_url()
    // qui gère le base path (APP_BASE_PATH) si présent.
    return rtrim(vitrine_origin(), '/');
}

function vitrine_path(string $agenceSlug, string $suffix = ''): string
{
    $agenceSlug = trim($agenceSlug, "/ \t\n\r\0\x0B");
    $suffix = '/' . ltrim($suffix, '/');
    $suffix = $suffix === '/' ? '' : rtrim($suffix, '/');
    return app_url('/vitrine/' . $agenceSlug . $suffix);
}

function vitrine_url(string $path): string
{
    $base = vitrine_base_url();
    return $base . '/' . ltrim($path, '/');
}

function vitrine_fetch_agence_by_slug(PDO $pdo, string $slug): ?array
{
    $slug = trim($slug);
    if ($slug === '') return null;

    $st = $pdo->prepare("
        SELECT
            a.*,
            s.nom AS societe_nom,
            s.logo_url AS societe_logo_url,
            s.site_web AS societe_site_web
        FROM agences a
        LEFT JOIN societes s ON s.id = a.id_societe
        WHERE a.actif = 1 AND a.slug = :slug
        LIMIT 1
    ");
    $st->execute([':slug' => $slug]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function vitrine_active_annonce_statuses(): array
{
    // Tolérant aux variantes historiques.
    return ['actif', 'active', 'publie', 'publiee'];
}

function vitrine_fetch_annonces_actives(PDO $pdo, int $agenceId, array $opts = []): array
{
    $page = max(1, (int)($opts['page'] ?? 1));
    $perPage = (int)($opts['per_page'] ?? 12);
    $perPage = max(1, min(48, $perPage));
    $offset = ($page - 1) * $perPage;

    $tx = trim((string)($opts['transaction'] ?? '')); // vente|location
    if (!in_array($tx, ['', 'vente', 'location'], true)) $tx = '';

    $q = trim((string)($opts['q'] ?? ''));

    $statuses = vitrine_active_annonce_statuses();
    $in = implode(',', array_fill(0, count($statuses), '?'));

    $where = [];
    $params = [];
    $where[] = 'a.id_agence = ?';
    $params[] = $agenceId;
    $where[] = "a.statut IN ($in)";
    array_push($params, ...$statuses);
    // "Disponibles" : biens en actif/publie, pas vendu/loué/archivé.
    $where[] = "b.statut_bien IN ('actif','publie')";

    if ($tx !== '') {
        $where[] = 'a.type_transaction = ?';
        $params[] = $tx;
    }

    if ($q !== '') {
        $where[] = "(b.designation LIKE ? OR b.ville LIKE ? OR b.code_postal LIKE ? OR a.titre_seo LIKE ? OR a.h1_public LIKE ?)";
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like, $like);
    }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    try {
        $sqlCount = "
            SELECT COUNT(*)
            FROM annonces a
            INNER JOIN biens b ON b.id = a.id_bien
            $whereClause
        ";
        $stC = $pdo->prepare($sqlCount);
        $stC->execute($params);
        $total = (int)$stC->fetchColumn();
    } catch (Throwable $e) {
        if (ini_get('display_errors')) {
            error_log('[vitrine] count annonces failed: ' . $e->getMessage());
        }
        return [
            'items' => [],
            'total' => 0,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => 1,
            'transaction' => $tx,
            'q' => $q,
        ];
    }

    $sql = "
        SELECT
            a.id              AS annonce_id,
            a.slug            AS annonce_slug,
            a.type_transaction,
            a.prix,
            a.loyer,
            a.loyer_cc,
            a.titre_seo,
            a.h1_public,
            a.meta_description AS annonce_meta_description,
            a.date_modification,
            b.id              AS bien_id,
            b.designation,
            b.sous_type_bien,
            b.surface_habitable,
            b.surface_terrain,
            b.nb_pieces,
            b.nb_chambres,
            b.ville,
            b.code_postal,
            b.dpe_classe,
            b.ges_classe,
            b.prix_vente_estime,
            b.loyer_hc,
            b.charges_locatives,
            tb.code           AS type_bien_code,
            tb.libelle        AS type_bien_libelle,
            (
              SELECT COALESCE(ap.url_webp, ap.url_photo)
              FROM annonces_photos ap
              WHERE ap.id_annonce = a.id AND ap.variante IN ('medium','original')
              ORDER BY (ap.variante='medium') DESC, ap.principale DESC, ap.ordre_affichage ASC, ap.id ASC
              LIMIT 1
            ) AS photo_url,
            (
              SELECT ap.largeur
              FROM annonces_photos ap
              WHERE ap.id_annonce = a.id AND ap.variante IN ('medium','original')
              ORDER BY (ap.variante='medium') DESC, ap.principale DESC, ap.ordre_affichage ASC, ap.id ASC
              LIMIT 1
            ) AS photo_w,
            (
              SELECT ap.hauteur
              FROM annonces_photos ap
              WHERE ap.id_annonce = a.id AND ap.variante IN ('medium','original')
              ORDER BY (ap.variante='medium') DESC, ap.principale DESC, ap.ordre_affichage ASC, ap.id ASC
              LIMIT 1
            ) AS photo_h,
            (
              SELECT COALESCE(ap.caption, ap.alt_photo)
              FROM annonces_photos ap
              WHERE ap.id_annonce = a.id AND ap.variante IN ('medium','original')
              ORDER BY (ap.variante='medium') DESC, ap.principale DESC, ap.ordre_affichage ASC, ap.id ASC
              LIMIT 1
            ) AS photo_alt
        FROM annonces a
        INNER JOIN biens b ON b.id = a.id_bien
        LEFT JOIN types_bien tb ON tb.id = b.id_type_bien
        $whereClause
        ORDER BY a.date_modification DESC, a.id DESC
        LIMIT $perPage OFFSET $offset
    ";
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        if (ini_get('display_errors')) {
            error_log('[vitrine] fetch annonces failed: ' . $e->getMessage());
        }
        $items = [];
    }

    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => max(1, (int)ceil($total / $perPage)),
        'transaction' => $tx,
        'q' => $q,
    ];
}

function vitrine_fetch_annonce_detail(PDO $pdo, int $agenceId, int $annonceId): ?array
{
    if ($annonceId <= 0) return null;

    $statuses = vitrine_active_annonce_statuses();
    $in = implode(',', array_fill(0, count($statuses), '?'));

    $params = [$annonceId, $agenceId];
    array_push($params, ...$statuses);

    $sql = "
        SELECT
            a.*,
            b.*,
            tb.code AS type_bien_code,
            tb.libelle AS type_bien_libelle,
            ag.nom_agence,
            ag.slug AS agence_slug,
            ag.logo_url AS agence_logo_url,
            ag.telephone AS agence_telephone,
            ag.email AS agence_email,
            ag.adresse_1 AS agence_adresse_1,
            ag.adresse_2 AS agence_adresse_2,
            ag.code_postal AS agence_code_postal,
            ag.ville AS agence_ville
        FROM annonces a
        INNER JOIN biens b ON b.id = a.id_bien
        LEFT JOIN types_bien tb ON tb.id = b.id_type_bien
        INNER JOIN agences ag ON ag.id = a.id_agence
        WHERE a.id = ? AND a.id_agence = ? AND a.statut IN ($in) AND b.statut_bien IN ('actif','publie')
        LIMIT 1
    ";
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        if (ini_get('display_errors')) {
            error_log('[vitrine] fetch annonce detail failed: ' . $e->getMessage());
        }
        return null;
    }
}

function vitrine_fetch_annonce_photos(PDO $pdo, int $annonceId): array
{
    if ($annonceId <= 0) return [];
    try {
        $st = $pdo->prepare("
            SELECT
                id, url_photo, url_webp, largeur, hauteur, poids_octets,
                variante, alt_photo, caption, principale, ordre_affichage
            FROM annonces_photos
            WHERE id_annonce = ?
            ORDER BY ordre_affichage ASC, principale DESC, id ASC
        ");
        $st->execute([$annonceId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        if (ini_get('display_errors')) {
            error_log('[vitrine] fetch photos failed: ' . $e->getMessage());
        }
        return [];
    }
}

function vitrine_pick_photo_variant(array $photos, string $wanted, array $fallbacks = ['large','medium','original','thumb']): ?array
{
    if (!$photos) return null;
    $priority = array_values(array_unique(array_merge([$wanted], $fallbacks)));
    foreach ($priority as $var) {
        foreach ($photos as $p) {
            if (($p['variante'] ?? '') === $var) return $p;
        }
    }
    // Dernier recours : première
    return $photos[0] ?? null;
}

function vitrine_photo_url(?array $photo): string
{
    if (!$photo) return '';
    $u = (string)($photo['url_webp'] ?? '');
    if ($u === '') $u = (string)($photo['url_photo'] ?? '');
    return $u;
}

function vitrine_tx_label(string $tx): string
{
    return $tx === 'vente' ? 'Vente' : ($tx === 'location' ? 'Location' : 'Transaction');
}

function vitrine_fmt_eur(?float $v): string
{
    if ($v === null) return '';
    $v = (float)$v;
    if ($v <= 0) return '';
    return number_format($v, 0, ',', ' ') . ' €';
}

function vitrine_price_label(array $row): string
{
    $tx = (string)($row['type_transaction'] ?? '');
    if ($tx === 'vente') {
        $p = (float)($row['prix'] ?? 0);
        if ($p <= 0) $p = (float)($row['prix_vente_estime'] ?? 0);
        return vitrine_fmt_eur($p ?: null);
    }
    if ($tx === 'location') {
        $l = (float)($row['loyer'] ?? 0);
        if ($l <= 0) $l = (float)($row['loyer_hc'] ?? 0);
        $txt = vitrine_fmt_eur($l ?: null);
        return $txt !== '' ? ($txt . ' / mois') : '';
    }
    return '';
}

function vitrine_annonce_h1(array $row): string
{
    foreach (['h1_public', 'titre_seo', 'designation'] as $k) {
        $v = trim((string)($row[$k] ?? ''));
        if ($v !== '') return $v;
    }
    return 'Annonce immobilière';
}

function vitrine_annonce_url(string $agenceSlug, int $annonceId, ?string $annonceSlug = null): string
{
    $annonceSlug = trim((string)$annonceSlug);
    if ($annonceSlug === '') $annonceSlug = (string)$annonceId;
    return vitrine_path($agenceSlug, '/annonce/' . $annonceId . '/' . $annonceSlug);
}

function vitrine_breadcrumb_jsonld(array $crumbs, string $baseUrl): string
{
    // $crumbs = [['name'=>'Accueil','item'=>'/..'], ...]
    $items = [];
    $pos = 1;
    foreach ($crumbs as $c) {
        $name = trim((string)($c['name'] ?? ''));
        $item = trim((string)($c['item'] ?? ''));
        if ($name === '' || $item === '') continue;
        $items[] = [
            '@type' => 'ListItem',
            'position' => $pos++,
            'name' => $name,
            'item' => str_starts_with($item, 'http') ? $item : ($baseUrl . '/' . ltrim($item, '/')),
        ];
    }
    if (!$items) return '';
    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => $items,
    ];
    return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
}

function vitrine_jsonld_agence(array $agence, string $baseUrl, string $homeUrl): string
{
    $name = (string)($agence['nom_agence'] ?? '');
    $logo = (string)($agence['logo_url'] ?? '');
    $tel  = (string)($agence['telephone'] ?? '');
    $mail = (string)($agence['email'] ?? '');

    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'RealEstateAgent',
        'name' => $name,
        'url' => $homeUrl,
        'telephone' => $tel !== '' ? $tel : null,
        'email' => $mail !== '' ? $mail : null,
        'address' => [
            '@type' => 'PostalAddress',
            'streetAddress' => trim((string)($agence['adresse_1'] ?? '') . ' ' . (string)($agence['adresse_2'] ?? '')) ?: null,
            'postalCode' => (string)($agence['code_postal'] ?? ''),
            'addressLocality' => (string)($agence['ville'] ?? ''),
            'addressCountry' => (string)($agence['pays'] ?? 'FR'),
        ],
    ];
    if ($logo !== '') {
        $absLogo = vitrine_url(app_url('/' . ltrim($logo, '/')));
        $data['logo'] = $absLogo;
        $data['image'] = $absLogo;
    }

    // Nettoyage des nulls (Schema tolère, mais on garde propre)
    $data = array_filter($data, fn($v) => $v !== null);
    if (isset($data['address']) && is_array($data['address'])) {
        $data['address'] = array_filter($data['address'], fn($v) => $v !== null && $v !== '');
    }

    return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
}

function vitrine_jsonld_annonce(array $row, string $url, array $imageUrls): string
{
    $price = 0.0;
    $currency = 'EUR';
    if (($row['type_transaction'] ?? '') === 'vente') {
        $price = (float)($row['prix'] ?? 0) ?: (float)($row['prix_vente_estime'] ?? 0);
    } elseif (($row['type_transaction'] ?? '') === 'location') {
        $price = (float)($row['loyer'] ?? 0) ?: (float)($row['loyer_hc'] ?? 0);
    }

    $data = [
        '@context' => 'https://schema.org',
        '@type' => 'RealEstateListing',
        'name' => vitrine_annonce_h1($row),
        'description' => (string)($row['meta_description'] ?? $row['annonce_meta_description'] ?? $row['description'] ?? ''),
        'url' => $url,
        'image' => array_values(array_filter($imageUrls)),
        'address' => [
            '@type' => 'PostalAddress',
            'addressLocality' => (string)($row['ville'] ?? ''),
            'postalCode' => (string)($row['code_postal'] ?? ''),
            'addressCountry' => 'FR',
        ],
        'offers' => [
            '@type' => 'Offer',
            'price' => $price > 0 ? $price : null,
            'priceCurrency' => $currency,
            'availability' => 'https://schema.org/InStock',
        ],
    ];

    // Nettoyage nulls
    $data['offers'] = array_filter($data['offers'], fn($v) => $v !== null);

    return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
}

function vitrine_insert_lead(PDO $pdo, array $lead): bool
{
    // $lead keys: id_societe,id_agence,type,nom,email,telephone,ville,message,source_url,ip,user_agent
    try {
        $st = $pdo->prepare("
            INSERT INTO vitrine_leads
              (id_societe, id_agence, type, nom, email, telephone, ville, message, source_url, ip, user_agent, created_at)
            VALUES
              (:id_societe, :id_agence, :type, :nom, :email, :telephone, :ville, :message, :source_url, :ip, :user_agent, NOW())
        ");
        $st->execute([
            ':id_societe' => $lead['id_societe'] ?? null,
            ':id_agence' => $lead['id_agence'] ?? null,
            ':type' => $lead['type'] ?? 'estimation',
            ':nom' => $lead['nom'] ?? '',
            ':email' => $lead['email'] ?? '',
            ':telephone' => $lead['telephone'] ?? null,
            ':ville' => $lead['ville'] ?? null,
            ':message' => $lead['message'] ?? null,
            ':source_url' => $lead['source_url'] ?? null,
            ':ip' => $lead['ip'] ?? null,
            ':user_agent' => $lead['user_agent'] ?? null,
        ]);
        return true;
    } catch (Throwable $e) {
        // Table absente ou insert impossible.
        if (ini_get('display_errors')) {
            error_log('[vitrine] lead insert failed: ' . $e->getMessage());
        }
        return false;
    }
}
