<?php
declare(strict_types=1);

/**
 * mbi_annonces_helpers.php
 * ───────────────────────────────────────────────────────────────────
 * Couche data + SEO du portail public MaBoxImmo.
 *
 * Filtre métier impératif :
 *   - a.visible_maboximmo = 1   (l'agent a coché "diffusion MBI" dans bien_detail)
 *   - a.statut publié, b.statut_bien commercialisable
 *   - a.indexable = 1 pour SEO (mais pas bloquant pour affichage)
 *
 * Indépendant de inc/vitrine_helpers.php (mini-sites par agence).
 */

if (!function_exists('mbi_annonces_active_statuses')) {
    function mbi_annonces_active_statuses(): array
    {
        return ['actif', 'active', 'publie', 'publiee', 'diffusee', 'en_ligne'];
    }
}

if (!function_exists('mbi_biens_publishable_statuses')) {
    function mbi_biens_publishable_statuses(): array
    {
        return ['actif', 'publie'];
    }
}

if (!function_exists('mbi_annonces_filters_from_get')) {
    /**
     * Lit + valide les filtres depuis $_GET et retourne un array stable.
     */
    function mbi_annonces_filters_from_get(array $get): array
    {
        $allowedTri = ['recent', 'prix_asc', 'prix_desc', 'surface_desc'];
        $tri = (string)($get['tri'] ?? 'recent');
        if (!in_array($tri, $allowedTri, true)) $tri = 'recent';

        $tx = (string)($get['transaction'] ?? '');
        if (!in_array($tx, ['', 'vente', 'location'], true)) $tx = '';

        $page = max(1, (int)($get['page'] ?? 1));
        $perPage = max(6, min(48, (int)($get['per_page'] ?? 24)));

        $allowedCategories = ['', 'habitation', 'entreprise', 'professionnel', 'commerce', 'investissement', 'annexe'];
        $categorie = (string)($get['categorie'] ?? '');
        if (!in_array($categorie, $allowedCategories, true)) $categorie = '';

        return [
            'q'            => trim((string)($get['q'] ?? '')),
            'ville'        => trim((string)($get['ville'] ?? '')),
            'code_postal'  => trim((string)($get['code_postal'] ?? '')),
            'transaction'  => $tx,
            'categorie'    => $categorie,
            'type_bien'    => trim((string)($get['type_bien'] ?? '')),
            'prix_min'     => max(0, (int)($get['prix_min'] ?? 0)),
            'prix_max'     => max(0, (int)($get['prix_max'] ?? 0)),
            'surface_min'  => max(0, (int)($get['surface_min'] ?? 0)),
            'pieces_min'   => max(0, (int)($get['pieces_min'] ?? 0)),
            'id_agence'    => max(0, (int)($get['id_agence'] ?? 0)),
            'tri'          => $tri,
            'page'         => $page,
            'per_page'     => $perPage,
        ];
    }
}

if (!function_exists('mbi_annonces_fetch_list')) {
    /**
     * Récupère les annonces visibles MBI selon les filtres.
     * Retourne ['items', 'total', 'page', 'per_page', 'total_pages'].
     */
    function mbi_annonces_fetch_list(PDO $pdo, array $filters): array
    {
        $where = [];
        $params = [];

        $where[] = 'a.visible_maboximmo = 1';

        $statuses = mbi_annonces_active_statuses();
        $inA = implode(',', array_fill(0, count($statuses), '?'));
        $where[] = "a.statut IN ($inA)";
        array_push($params, ...$statuses);

        $bs = mbi_biens_publishable_statuses();
        $inB = implode(',', array_fill(0, count($bs), '?'));
        $where[] = "b.statut_bien IN ($inB)";
        array_push($params, ...$bs);

        if ($filters['transaction'] !== '') {
            $where[] = 'a.type_transaction = ?';
            $params[] = $filters['transaction'];
        }

        if ($filters['ville'] !== '') {
            $where[] = '(b.ville LIKE ? OR i.ville LIKE ?)';
            $like = '%' . $filters['ville'] . '%';
            $params[] = $like;
            $params[] = $like;
        }

        if ($filters['code_postal'] !== '') {
            $where[] = '(b.code_postal LIKE ? OR i.code_postal LIKE ?)';
            $like = $filters['code_postal'] . '%';
            $params[] = $like;
            $params[] = $like;
        }

        if ($filters['type_bien'] !== '') {
            $where[] = '(tb.code = ? OR tb.libelle LIKE ?)';
            $params[] = $filters['type_bien'];
            $params[] = '%' . $filters['type_bien'] . '%';
        }

        // Catégorie : "entreprise" = professionnel + commerce (locaux, bureaux, entrepôts, fonds, baux).
        if ($filters['categorie'] !== '') {
            if ($filters['categorie'] === 'entreprise') {
                $where[] = "tb.categorie IN ('professionnel','commerce')";
            } else {
                $where[] = 'tb.categorie = ?';
                $params[] = $filters['categorie'];
            }
        }

        if ($filters['prix_min'] > 0) {
            $where[] = '(COALESCE(a.prix, a.loyer, 0) >= ?)';
            $params[] = $filters['prix_min'];
        }
        if ($filters['prix_max'] > 0) {
            $where[] = '(COALESCE(a.prix, a.loyer, 0) <= ?)';
            $params[] = $filters['prix_max'];
        }

        if ($filters['surface_min'] > 0) {
            $where[] = 'b.surface_habitable >= ?';
            $params[] = $filters['surface_min'];
        }

        if ($filters['pieces_min'] > 0) {
            $where[] = 'b.nb_pieces >= ?';
            $params[] = $filters['pieces_min'];
        }

        if ($filters['id_agence'] > 0) {
            $where[] = 'a.id_agence = ?';
            $params[] = $filters['id_agence'];
        }

        if ($filters['q'] !== '') {
            $where[] = '(b.designation LIKE ? OR b.ville LIKE ? OR b.code_postal LIKE ? OR a.titre LIKE ? OR a.titre_ia LIKE ?)';
            $like = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $orderBy = 'a.date_mise_en_ligne DESC, a.date_modification DESC, a.id DESC';
        switch ($filters['tri']) {
            case 'prix_asc':     $orderBy = 'COALESCE(a.prix, a.loyer, 0) ASC, a.id DESC'; break;
            case 'prix_desc':    $orderBy = 'COALESCE(a.prix, a.loyer, 0) DESC, a.id DESC'; break;
            case 'surface_desc': $orderBy = 'b.surface_habitable DESC, a.id DESC'; break;
        }

        $page = (int)$filters['page'];
        $perPage = (int)$filters['per_page'];
        $offset = ($page - 1) * $perPage;

        $joinFrom = "
            FROM annonces a
            INNER JOIN biens b ON b.id = a.id_bien
            LEFT JOIN immeubles i ON i.id = b.id_immeuble
            LEFT JOIN types_bien tb ON tb.id = b.id_type_bien
        ";

        try {
            $sqlCount = "SELECT COUNT(*) $joinFrom $whereClause";
            $stC = $pdo->prepare($sqlCount);
            $stC->execute($params);
            $total = (int)$stC->fetchColumn();
        } catch (Throwable $e) {
            if (APP_DEBUG ?? false) error_log('[mbi_annonces] count failed: ' . $e->getMessage());
            return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'total_pages' => 1];
        }

        $sql = "
            SELECT
                a.id              AS annonce_id,
                a.slug            AS annonce_slug,
                a.type_transaction,
                a.prix, a.loyer, a.loyer_cc, a.charges,
                a.titre, a.titre_ia, a.meta_title, a.meta_description,
                a.exclusivite, a.nouveaute, a.coup_coeur,
                a.date_mise_en_ligne, a.date_modification,
                a.id_agence,
                b.id              AS bien_id,
                b.designation, b.sous_type_bien,
                b.surface_habitable, b.surface_terrain,
                b.nb_pieces, b.nb_chambres,
                b.ville, b.code_postal,
                b.dpe_classe, b.ges_classe,
                b.prix_vente_estime, b.loyer_hc, b.charges_locatives,
                tb.code           AS type_bien_code,
                tb.libelle        AS type_bien_libelle,
                tb.categorie      AS type_bien_categorie,
                ag.nom_agence,
                ag.slug           AS agence_slug,
                (
                  SELECT COALESCE(ap.url_webp, ap.url_photo)
                  FROM annonces_photos ap
                  WHERE ap.id_annonce = a.id AND ap.variante IN ('medium','large','original')
                  ORDER BY (ap.variante='medium') DESC, (ap.variante='large') DESC, ap.principale DESC, ap.ordre_affichage ASC, ap.id ASC
                  LIMIT 1
                ) AS photo_url,
                (
                  SELECT ap.largeur FROM annonces_photos ap
                  WHERE ap.id_annonce = a.id AND ap.variante IN ('medium','large','original')
                  ORDER BY (ap.variante='medium') DESC, (ap.variante='large') DESC, ap.principale DESC, ap.ordre_affichage ASC, ap.id ASC
                  LIMIT 1
                ) AS photo_w,
                (
                  SELECT ap.hauteur FROM annonces_photos ap
                  WHERE ap.id_annonce = a.id AND ap.variante IN ('medium','large','original')
                  ORDER BY (ap.variante='medium') DESC, (ap.variante='large') DESC, ap.principale DESC, ap.ordre_affichage ASC, ap.id ASC
                  LIMIT 1
                ) AS photo_h,
                (
                  SELECT COALESCE(ap.caption, ap.alt_photo, ap.titre)
                  FROM annonces_photos ap
                  WHERE ap.id_annonce = a.id AND ap.variante IN ('medium','large','original')
                  ORDER BY (ap.variante='medium') DESC, (ap.variante='large') DESC, ap.principale DESC, ap.ordre_affichage ASC, ap.id ASC
                  LIMIT 1
                ) AS photo_alt
            $joinFrom
            LEFT JOIN agences ag ON ag.id = a.id_agence
            $whereClause
            ORDER BY $orderBy
            LIMIT $perPage OFFSET $offset
        ";

        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            if (APP_DEBUG ?? false) error_log('[mbi_annonces] fetch failed: ' . $e->getMessage());
            $items = [];
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, (int)ceil($total / $perPage)),
        ];
    }
}

if (!function_exists('mbi_annonces_fetch_detail')) {
    function mbi_annonces_fetch_detail(PDO $pdo, int $annonceId): ?array
    {
        if ($annonceId <= 0) return null;

        $statuses = mbi_annonces_active_statuses();
        $inA = implode(',', array_fill(0, count($statuses), '?'));
        $bs = mbi_biens_publishable_statuses();
        $inB = implode(',', array_fill(0, count($bs), '?'));

        $params = [$annonceId];
        array_push($params, ...$statuses);
        array_push($params, ...$bs);

        // Whitelist explicite : on n'expose JAMAIS commentaire_admin / note_admin / etc.
        $sql = "
            SELECT
                a.id AS annonce_id, a.slug AS annonce_slug, a.id_bien, a.id_agence, a.id_societe,
                a.type_transaction, a.statut,
                a.titre, a.titre_ia, a.meta_title, a.meta_description, a.url_canonique,
                a.description, a.resume_court, a.points_forts, a.accroche_commerciale,
                a.prix, a.prix_honoraires_inclus, a.prix_net_vendeur, a.honoraires,
                a.honoraires_charge, a.honoraires_charge_acquereur, a.honoraires_charge_vendeur,
                a.alur_pourcentage_honoraires_ttc, a.bareme_honoraires_url,
                a.charges, a.charges_annuelles, a.taxe_fonciere,
                a.loyer, a.loyer_cc, a.complement_loyer, a.depot_garantie,
                a.dpe_classe AS a_dpe_classe, a.ges_classe AS a_ges_classe,
                a.dpe_valeur AS a_dpe_valeur, a.ges_valeur AS a_ges_valeur,
                a.video_url, a.visite_virtuelle_url, a.plan_url, a.brochure_url,
                a.contact_nom, a.contact_email, a.contact_telephone,
                a.meuble, a.disponible_de_suite, a.date_disponibilite,
                a.exclusivite, a.nouveaute, a.coup_coeur,
                a.date_mise_en_ligne, a.date_modification,
                a.reference_annonce, a.reference_externe_principale,
                b.id AS bien_id, b.reference_bien, b.designation, b.sous_type_bien, b.usage_bien,
                b.surface_habitable, b.surface_carrez, b.surface_sejour, b.surface_terrain,
                b.surface_balcon, b.surface_terrasse, b.surface_jardin, b.surface_cave, b.surface_garage,
                b.nb_pieces, b.nb_chambres, b.nb_salles_bain, b.nb_salles_eau, b.nb_wc,
                b.cuisine_type, b.cuisine_equipee,
                b.etage, b.dernier_etage, b.ascenseur, b.interphone, b.digicode, b.alarme,
                b.climatisation, b.fibre, b.double_vitrage, b.volets_roulants, b.cheminee,
                b.balcon, b.terrasse, b.jardin, b.cour, b.cave, b.grenier, b.garage, b.box,
                b.parking_nb, b.piscine, b.dependances,
                b.annee_construction, b.hauteur_sous_plafond,
                b.exposition, b.vue, b.etat_bien, b.standing,
                b.chauffage_type, b.chauffage_energie, b.eau_chaude_type,
                b.dpe_classe, b.ges_classe, b.dpe_valeur, b.ges_valeur,
                b.dpe_date_realisation, b.dpe_version, b.dpe_vierge,
                b.montant_estime_depenses_min, b.montant_estime_depenses_max, b.annee_reference_depenses,
                b.ville, b.code_postal,
                b.adresse_visible_public,
                CASE WHEN b.adresse_visible_public = 1 THEN b.adresse_1 ELSE NULL END AS adresse_1,
                CASE WHEN b.adresse_visible_public = 1 THEN b.adresse_2 ELSE NULL END AS adresse_2,
                b.latitude, b.longitude, b.precision_geoloc,
                b.bien_en_copropriete, b.copro_nb_lots,
                tb.code AS type_bien_code, tb.libelle AS type_bien_libelle, tb.categorie AS type_bien_categorie,
                ag.id AS agence_id, ag.nom_agence, ag.slug AS agence_slug,
                ag.logo_url AS agence_logo_url, ag.telephone AS agence_telephone, ag.email AS agence_email,
                ag.adresse_1 AS agence_adresse_1, ag.code_postal AS agence_code_postal, ag.ville AS agence_ville,
                s.nom AS societe_nom
            FROM annonces a
            INNER JOIN biens b ON b.id = a.id_bien
            LEFT JOIN types_bien tb ON tb.id = b.id_type_bien
            LEFT JOIN agences ag ON ag.id = a.id_agence
            LEFT JOIN societes s ON s.id = a.id_societe
            WHERE a.id = ?
              AND a.visible_maboximmo = 1
              AND a.statut IN ($inA)
              AND b.statut_bien IN ($inB)
            LIMIT 1
        ";

        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            if (APP_DEBUG ?? false) error_log('[mbi_annonces] detail failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('mbi_annonces_fetch_photos')) {
    function mbi_annonces_fetch_photos(PDO $pdo, int $annonceId): array
    {
        if ($annonceId <= 0) return [];
        try {
            $st = $pdo->prepare("
                SELECT id, url_photo, url_webp, largeur, hauteur, variante,
                       titre, alt_photo, caption, principale, ordre_affichage
                FROM annonces_photos
                WHERE id_annonce = ?
                ORDER BY ordre_affichage ASC, principale DESC, id ASC
            ");
            $st->execute([$annonceId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            if (APP_DEBUG ?? false) error_log('[mbi_annonces] photos failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('mbi_annonces_pick_photo')) {
    function mbi_annonces_pick_photo(array $photos, string $wanted = 'large', array $fallbacks = ['xlarge','medium','original','thumb']): ?array
    {
        if (!$photos) return null;
        $priority = array_values(array_unique(array_merge([$wanted], $fallbacks)));
        foreach ($priority as $var) {
            foreach ($photos as $p) {
                if (($p['variante'] ?? '') === $var) return $p;
            }
        }
        return $photos[0] ?? null;
    }
}

if (!function_exists('mbi_annonces_photo_src')) {
    function mbi_annonces_photo_src(?array $photo, string $placeholder = '/images/Home.png'): string
    {
        if (!$photo) return app_url($placeholder);
        $u = (string)($photo['url_webp'] ?? '');
        if ($u === '') $u = (string)($photo['url_photo'] ?? '');
        if ($u === '') return app_url($placeholder);
        return app_url('/' . ltrim($u, '/'));
    }
}

if (!function_exists('mbi_annonces_fmt_eur')) {
    function mbi_annonces_fmt_eur(?float $v): string
    {
        if ($v === null) return '';
        $v = (float)$v;
        if ($v <= 0) return '';
        return number_format($v, 0, ',', ' ') . ' €';
    }
}

if (!function_exists('mbi_annonces_price_label')) {
    function mbi_annonces_price_label(array $row): string
    {
        $tx = (string)($row['type_transaction'] ?? '');
        if ($tx === 'vente') {
            $p = (float)($row['prix'] ?? 0);
            if ($p <= 0) $p = (float)($row['prix_vente_estime'] ?? 0);
            return mbi_annonces_fmt_eur($p ?: null);
        }
        if ($tx === 'location') {
            $l = (float)($row['loyer'] ?? 0);
            if ($l <= 0) $l = (float)($row['loyer_hc'] ?? 0);
            $txt = mbi_annonces_fmt_eur($l ?: null);
            return $txt !== '' ? ($txt . ' / mois') : '';
        }
        return '';
    }
}

if (!function_exists('mbi_annonces_tx_label')) {
    function mbi_annonces_tx_label(string $tx): string
    {
        return $tx === 'vente' ? 'Vente' : ($tx === 'location' ? 'Location' : '—');
    }
}

if (!function_exists('mbi_annonces_h1')) {
    function mbi_annonces_h1(array $row): string
    {
        $type = trim((string)($row['type_bien_libelle'] ?? $row['type_bien_code'] ?? ''));
        $pieces = (int)($row['nb_pieces'] ?? 0);
        $tx = (string)($row['type_transaction'] ?? '');
        $ville = trim((string)($row['ville'] ?? ''));

        if ($type !== '' && $tx !== '' && $ville !== '') {
            $piecesStr = $pieces > 0 ? ' T' . $pieces : '';
            $txStr = $tx === 'vente' ? 'à vendre' : 'à louer';
            return ucfirst($type) . $piecesStr . ' ' . $txStr . ' à ' . $ville;
        }

        foreach (['titre', 'titre_ia', 'meta_title', 'designation'] as $k) {
            $v = trim((string)($row[$k] ?? ''));
            if ($v !== '') return $v;
        }
        return 'Annonce immobilière';
    }
}

if (!function_exists('mbi_annonces_meta_description')) {
    function mbi_annonces_meta_description(array $row): string
    {
        $custom = trim((string)($row['meta_description'] ?? ''));
        if ($custom !== '') return mb_substr($custom, 0, 320);

        $type = trim((string)($row['type_bien_libelle'] ?? 'bien'));
        $surface = (int)($row['surface_habitable'] ?? 0);
        $ville = trim((string)($row['ville'] ?? ''));
        $price = mbi_annonces_price_label($row);
        $agence = trim((string)($row['nom_agence'] ?? ''));

        $bits = ['Découvrez ' . mb_strtolower($type)];
        if ($surface > 0) $bits[] = 'de ' . $surface . ' m²';
        if ($ville !== '') $bits[] = 'à ' . $ville;
        if ($agence !== '') $bits[] = ', proposé par ' . $agence;
        $line1 = implode(' ', $bits) . '.';

        $line2 = '';
        if ($price !== '') $line2 = 'Prix : ' . $price . '. ';
        $line2 .= 'Consultez les photos, caractéristiques et contactez l’agence.';

        return mb_substr($line1 . ' ' . $line2, 0, 320);
    }
}

if (!function_exists('mbi_annonces_url_detail')) {
    function mbi_annonces_url_detail(int $annonceId, ?string $slug = null): string
    {
        $url = app_url('/mbi_annonces_detail.php?id=' . $annonceId);
        $slug = trim((string)$slug);
        if ($slug !== '') $url .= '&slug=' . rawurlencode($slug);
        return $url;
    }
}

if (!function_exists('mbi_annonces_origin')) {
    function mbi_annonces_origin(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }
}

if (!function_exists('mbi_annonces_abs_url')) {
    function mbi_annonces_abs_url(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) return $path;
        return rtrim(mbi_annonces_origin(), '/') . '/' . ltrim($path, '/');
    }
}

if (!function_exists('mbi_annonces_jsonld')) {
    function mbi_annonces_jsonld(array $row, string $canonicalAbsUrl, array $imageAbsUrls): string
    {
        $price = 0.0;
        $tx = (string)($row['type_transaction'] ?? '');
        if ($tx === 'vente')   $price = (float)($row['prix']  ?? 0) ?: (float)($row['prix_vente_estime'] ?? 0);
        if ($tx === 'location') $price = (float)($row['loyer'] ?? 0) ?: (float)($row['loyer_hc'] ?? 0);

        $data = [
            '@context'    => 'https://schema.org',
            '@type'       => 'RealEstateListing',
            'name'        => mbi_annonces_h1($row),
            'description' => mbi_annonces_meta_description($row),
            'url'         => $canonicalAbsUrl,
            'image'       => array_values(array_filter($imageAbsUrls)),
            'address'     => [
                '@type'           => 'PostalAddress',
                'addressLocality' => (string)($row['ville'] ?? ''),
                'postalCode'      => (string)($row['code_postal'] ?? ''),
                'addressCountry'  => 'FR',
            ],
            'offers'      => [
                '@type'         => 'Offer',
                'price'         => $price > 0 ? $price : null,
                'priceCurrency' => 'EUR',
                'availability'  => 'https://schema.org/InStock',
            ],
        ];
        $data['offers'] = array_filter($data['offers'], fn($v) => $v !== null);
        return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    }
}

if (!function_exists('mbi_annonces_breadcrumb_jsonld')) {
    function mbi_annonces_breadcrumb_jsonld(array $crumbs): string
    {
        $items = [];
        $pos = 1;
        foreach ($crumbs as $c) {
            $name = trim((string)($c['name'] ?? ''));
            $item = trim((string)($c['url'] ?? ''));
            if ($name === '' || $item === '') continue;
            $items[] = [
                '@type'    => 'ListItem',
                'position' => $pos++,
                'name'     => $name,
                'item'     => mbi_annonces_abs_url($item),
            ];
        }
        if (!$items) return '';
        $data = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
        return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    }
}

if (!function_exists('mbi_annonces_fetch_agences_with_annonces')) {
    /**
     * Liste les agences ayant au moins 1 annonce visible MBI.
     * Utile pour le filtre "Agence" du moteur de recherche.
     */
    function mbi_annonces_fetch_agences_with_annonces(PDO $pdo): array
    {
        try {
            $statuses = mbi_annonces_active_statuses();
            $in = implode(',', array_fill(0, count($statuses), '?'));
            $st = $pdo->prepare("
                SELECT ag.id, ag.nom_agence, ag.ville, COUNT(a.id) AS nb
                FROM agences ag
                INNER JOIN annonces a ON a.id_agence = ag.id
                INNER JOIN biens b ON b.id = a.id_bien
                WHERE a.visible_maboximmo = 1
                  AND a.statut IN ($in)
                  AND b.statut_bien IN ('actif','publie')
                GROUP BY ag.id
                ORDER BY ag.nom_agence ASC
            ");
            $st->execute($statuses);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            if (APP_DEBUG ?? false) error_log('[mbi_annonces] agences list failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('mbi_annonces_fetch_types_bien_with_annonces')) {
    function mbi_annonces_fetch_types_bien_with_annonces(PDO $pdo): array
    {
        try {
            $statuses = mbi_annonces_active_statuses();
            $in = implode(',', array_fill(0, count($statuses), '?'));
            $st = $pdo->prepare("
                SELECT tb.id, tb.code, tb.libelle, COUNT(a.id) AS nb
                FROM types_bien tb
                INNER JOIN biens b ON b.id_type_bien = tb.id
                INNER JOIN annonces a ON a.id_bien = b.id
                WHERE a.visible_maboximmo = 1
                  AND a.statut IN ($in)
                  AND b.statut_bien IN ('actif','publie')
                GROUP BY tb.id
                ORDER BY tb.libelle ASC
            ");
            $st->execute($statuses);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            if (APP_DEBUG ?? false) error_log('[mbi_annonces] types_bien list failed: ' . $e->getMessage());
            return [];
        }
    }
}
