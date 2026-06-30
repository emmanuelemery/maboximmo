<?php
declare(strict_types=1);

/**
 * Helpers partagés des pages éditoriales du portail public MaBoxImmo
 * (activités, agences/horaires, contact). Lecture seule, sans login.
 *
 * Toutes les fonctions sont défensives (try/catch → fallback vide) : une page
 * vitrine ne doit JAMAIS rendre un écran blanc à cause d'une requête.
 */

if (!function_exists('mbi_pages_fetch_public_agences')) {
    /**
     * Liste les agences actives publiables (pour pages Agences / Contact /
     * personnalisation d'activité). Retourne [] en cas d'erreur.
     *
     * @param bool $onlyWithAnnonces si true, ne retourne que les agences ayant
     *                               au moins une annonce active visible sur MBI.
     */
    function mbi_pages_fetch_public_agences(PDO $pdo, bool $onlyWithAnnonces = false): array
    {
        try {
            $cols = "id, id_societe, nom_agence, nom_commercial, slug,
                       adresse_1, adresse_2, code_postal, ville, departement,
                       telephone, email, email_contact, site_web,
                       horaires, latitude, longitude, google_place_id,
                       note_google, nb_avis_google, logo_url, texte_intro, description,
                       transaction_active, location_active, gestion_active,
                       syndic_active, neuf_active";

            if ($onlyWithAnnonces) {
                $statuses = mbi_annonces_active_statuses();
                $in = implode(',', array_fill(0, count($statuses), '?'));
                $st = $pdo->prepare("
                    SELECT $cols
                    FROM agences ag
                    WHERE ag.actif = 1
                      AND EXISTS (
                          SELECT 1 FROM annonces a
                          INNER JOIN biens b ON b.id = a.id_bien
                          WHERE a.id_agence = ag.id
                            AND a.visible_maboximmo = 1
                            AND a.statut IN ($in)
                            AND b.statut_bien IN ('actif','publie')
                      )
                    ORDER BY ag.ordre_affichage ASC, ag.nom_agence ASC
                ");
                $st->execute($statuses);
                return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            $st = $pdo->query("
                SELECT $cols
                FROM agences
                WHERE actif = 1
                ORDER BY ordre_affichage ASC, nom_agence ASC
            ");
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            if (defined('APP_DEBUG') && APP_DEBUG) error_log('[mbi_pages] agences fetch: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('mbi_pages_fetch_agence_by_slug')) {
    /**
     * Récupère une agence active par son slug (personnalisation ?agence=slug).
     * Retourne null si introuvable / slug vide.
     */
    function mbi_pages_fetch_agence_by_slug(PDO $pdo, string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') return null;
        try {
            $st = $pdo->prepare("
                SELECT id, id_societe, nom_agence, nom_commercial, slug,
                       adresse_1, adresse_2, code_postal, ville, departement,
                       telephone, email, email_contact, site_web,
                       horaires, latitude, longitude, google_place_id,
                       note_google, nb_avis_google, logo_url, texte_intro, description,
                       transaction_active, location_active, gestion_active,
                       syndic_active, neuf_active
                FROM agences
                WHERE slug = :slug AND actif = 1
                LIMIT 1
            ");
            $st->execute([':slug' => $slug]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            if (defined('APP_DEBUG') && APP_DEBUG) error_log('[mbi_pages] agence by slug: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('mbi_pages_agence_address')) {
    /** Adresse postale compacte d'une agence : "12 rue X, 69002 Lyon". */
    function mbi_pages_agence_address(array $ag): string
    {
        $parts = [];
        $line1 = trim((string)($ag['adresse_1'] ?? ''));
        $line2 = trim((string)($ag['adresse_2'] ?? ''));
        if ($line1 !== '') $parts[] = $line1;
        if ($line2 !== '') $parts[] = $line2;
        $cpVille = trim(trim((string)($ag['code_postal'] ?? '')) . ' ' . trim((string)($ag['ville'] ?? '')));
        if ($cpVille !== '') $parts[] = $cpVille;
        return implode(', ', $parts);
    }
}

if (!function_exists('mbi_pages_faq_jsonld')) {
    /**
     * Génère un bloc JSON-LD FAQPage à partir de [['q'=>..,'a'=>..], ...].
     * Boost référencement (rich results Google + réponses ChatGPT).
     */
    function mbi_pages_faq_jsonld(array $faq): string
    {
        $items = [];
        foreach ($faq as $f) {
            $q = trim((string)($f['q'] ?? ''));
            $a = trim((string)($f['a'] ?? ''));
            if ($q === '' || $a === '') continue;
            $items[] = [
                '@type' => 'Question',
                'name'  => $q,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a],
            ];
        }
        if (!$items) return '';
        $data = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $items];
        return '<script type="application/ld+json">'
            . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . '</script>';
    }
}

if (!function_exists('mbi_pages_service_jsonld')) {
    /**
     * JSON-LD Service rattaché au fournisseur RealEstateAgent (MaBoxImmo ou
     * l'agence ciblée si personnalisation).
     */
    function mbi_pages_service_jsonld(string $serviceName, string $description, string $url, ?array $agence = null): string
    {
        $provider = ['@type' => 'RealEstateAgent', 'name' => 'Ma Box Immo'];
        if ($agence) {
            $provider['name'] = (string)($agence['nom_commercial'] ?: $agence['nom_agence']);
            $addr = [];
            if (!empty($agence['adresse_1'])) $addr['streetAddress'] = trim((string)$agence['adresse_1']);
            if (!empty($agence['code_postal'])) $addr['postalCode'] = (string)$agence['code_postal'];
            if (!empty($agence['ville'])) $addr['addressLocality'] = (string)$agence['ville'];
            if ($addr) { $addr['@type'] = 'PostalAddress'; $addr['addressCountry'] = 'FR'; $provider['address'] = $addr; }
            if (!empty($agence['telephone'])) $provider['telephone'] = (string)$agence['telephone'];
        }
        $data = [
            '@context'    => 'https://schema.org',
            '@type'       => 'Service',
            'name'        => $serviceName,
            'serviceType' => $serviceName,
            'description' => $description,
            'url'         => $url,
            'areaServed'  => ['@type' => 'AdministrativeArea', 'name' => 'Auvergne-Rhône-Alpes'],
            'provider'    => $provider,
        ];
        return '<script type="application/ld+json">'
            . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . '</script>';
    }
}
