<?php
declare(strict_types=1);

/**
 * ═══════════════════════════════════════════════════════════════════════
 * mbi_supports_helpers.php — Helpers communs du module Ma Box Communication
 * ═══════════════════════════════════════════════════════════════════════
 *
 * - Résolution de style (override agence > société > catalogue global > défaut)
 * - Helpers PDF (couleurs, polices, formatage prix)
 * - Helpers fichiers (chemin upload, slug, vérification image)
 * ═══════════════════════════════════════════════════════════════════════
 */

if (!defined('MBI_SUPPORTS_UPLOAD_DRAFT')) {
    define('MBI_SUPPORTS_UPLOAD_DRAFT', __DIR__ . '/../uploads/supports/drafts/');
    define('MBI_SUPPORTS_UPLOAD_VALIDE', __DIR__ . '/../uploads/supports/valides/');
}

// ─────────────────────────────────────────────────────────────────────────
// Résolution du style
// ─────────────────────────────────────────────────────────────────────────

if (!function_exists('mbi_supports_resoudre_style')) {
    /**
     * Résout le style applicable pour une société/agence donnée.
     * Cascade : override agence > override société > catalogue global is_default=1.
     *
     * @return array Style résolu (couleurs, polices, ton, etc.) — jamais null
     */
    function mbi_supports_resoudre_style(?int $idSociete, ?int $idAgence, ?string $code = null): array
    {
        $pdo = $GLOBALS['pdo'] ?? db();

        // 1. Style global de base : code explicite, ou is_default = 1
        try {
            if ($code !== null && $code !== '') {
                $st = $pdo->prepare("SELECT * FROM mbi_supports_styles WHERE code = :c AND actif = 1 LIMIT 1");
                $st->execute([':c' => $code]);
                $base = $st->fetch(PDO::FETCH_ASSOC);
            } else {
                $base = $pdo->query("SELECT * FROM mbi_supports_styles WHERE is_default = 1 AND actif = 1 LIMIT 1")
                            ->fetch(PDO::FETCH_ASSOC);
            }
        } catch (Throwable $e) {
            error_log('[mbi_supports_resoudre_style base] ' . $e->getMessage());
            $base = null;
        }

        // 1.b Fallback : style le plus permissif si rien trouvé
        if (!$base) {
            $base = [
                'id' => 0, 'code' => 'fallback', 'libelle' => 'Fallback',
                'couleur_primaire' => '#243B5C', 'couleur_secondaire' => '#D4A047',
                'couleur_texte' => '#1F2937',
                'police_titre' => 'helvetica', 'police_corps' => 'helvetica',
                'logo_path' => null,
                'ton_redactionnel' => 'neutre', 'niveau_detail' => 'standard',
            ];
        }

        // 2. Cherche override agence puis société
        $override = null;
        if ($idAgence !== null && $idAgence > 0) {
            try {
                $st = $pdo->prepare("
                    SELECT * FROM mbi_supports_styles_overrides
                    WHERE id_style = :s AND id_agence = :a AND actif = 1
                    ORDER BY id DESC LIMIT 1
                ");
                $st->execute([':s' => $base['id'] ?? 0, ':a' => $idAgence]);
                $override = $st->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable) {}
        }
        if (!$override && $idSociete !== null && $idSociete > 0) {
            try {
                $st = $pdo->prepare("
                    SELECT * FROM mbi_supports_styles_overrides
                    WHERE id_style = :s AND id_societe = :soc AND id_agence IS NULL AND actif = 1
                    ORDER BY id DESC LIMIT 1
                ");
                $st->execute([':s' => $base['id'] ?? 0, ':soc' => $idSociete]);
                $override = $st->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable) {}
        }

        // 3. Merge override (NULL = on garde la valeur de base)
        $resolu = $base;
        if (is_array($override)) {
            foreach (['couleur_primaire','couleur_secondaire','couleur_texte',
                      'police_titre','police_corps','logo_path',
                      'ton_redactionnel','niveau_detail',
                      'regles_layout_json','regles_photos_json'] as $k) {
                if (!empty($override[$k])) $resolu[$k] = $override[$k];
            }
        }

        // 4. Convertit les couleurs hex en triplets RGB pour TCPDF
        $resolu['rgb_primaire']    = mbi_supports_hex_to_rgb($resolu['couleur_primaire']    ?? '#243B5C');
        $resolu['rgb_secondaire']  = mbi_supports_hex_to_rgb($resolu['couleur_secondaire']  ?? '#D4A047');
        $resolu['rgb_texte']       = mbi_supports_hex_to_rgb($resolu['couleur_texte']       ?? '#1F2937');

        return $resolu;
    }
}

if (!function_exists('mbi_supports_hex_to_rgb')) {
    /**
     * Convertit "#RRGGBB" ou "RRGGBB" en [r, g, b].
     */
    function mbi_supports_hex_to_rgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) return [0, 0, 0];
        return [hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2))];
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Format prix / surface
// ─────────────────────────────────────────────────────────────────────────

if (!function_exists('mbi_supports_format_prix')) {
    function mbi_supports_format_prix(?float $v): string
    {
        if ($v === null || $v <= 0) return '— €';
        return number_format($v, 0, ',', ' ') . ' €';
    }
}

if (!function_exists('mbi_supports_format_surface')) {
    function mbi_supports_format_surface(?float $v): string
    {
        if ($v === null || $v <= 0) return '—';
        return rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',') . ' m²';
    }
}

if (!function_exists('mbi_supports_get_prix')) {
    function mbi_supports_get_prix(array $bien): ?float
    {
        $p = (float)($bien['prix_vente_estime'] ?? $bien['prix_vente'] ?? $bien['prix'] ?? 0);
        return $p > 0 ? $p : null;
    }
}

if (!function_exists('mbi_supports_est_location')) {
    /**
     * Lecture directe du champ canonique : biens.type_commercialisation
     *   - 'location' ou 'gestion' → location
     *   - 'vente' → vente
     * Fallback type_transaction si type_commercialisation est vide.
     */
    function mbi_supports_est_location(array $bien): bool
    {
        $tc = strtolower(trim((string)($bien['type_commercialisation'] ?? '')));
        if ($tc === 'location' || $tc === 'gestion') return true;
        if ($tc === 'vente')  return false;

        // Fallback : type_transaction (sur bien ou annonce)
        $tt = strtolower((string)(
            $bien['type_transaction']
            ?? $bien['_annonce_type_transaction']
            ?? ''
        ));
        if (str_contains($tt, 'location')) return true;
        return false;
    }
}

if (!function_exists('mbi_supports_get_prix_ou_loyer')) {
    /**
     * Retourne le prix de vente OU le loyer selon détection automatique.
     * Format : ['valeur' => float|null, 'type' => 'vente'|'location'|'unknown',
     *          'suffixe' => '/mois CC'|'/mois HC'|'']
     */
    function mbi_supports_get_prix_ou_loyer(array $bien): array
    {
        if (mbi_supports_est_location($bien)) {
            $loyerCC = (float)($bien['_annonce_loyer_cc'] ?? 0);
            $loyer   = (float)($bien['_annonce_loyer']    ?? 0);
            if ($loyerCC > 0) {
                return ['valeur' => $loyerCC, 'type' => 'location', 'suffixe' => ' /mois CC'];
            }
            if ($loyer > 0) {
                return ['valeur' => $loyer, 'type' => 'location', 'suffixe' => ' /mois HC'];
            }
            return ['valeur' => null, 'type' => 'location', 'suffixe' => ''];
        }

        $p = (float)($bien['prix_vente_estime'] ?? $bien['prix_vente'] ?? $bien['prix'] ?? 0);
        if ($p > 0) {
            return ['valeur' => $p, 'type' => 'vente', 'suffixe' => ''];
        }
        return ['valeur' => null, 'type' => 'unknown', 'suffixe' => ''];
    }
}

if (!function_exists('mbi_supports_format_prix_complet')) {
    /**
     * Format prix complet avec suffixe (ex: "1 250 € /mois CC", "450 000 €")
     */
    function mbi_supports_format_prix_complet(array $infoPrix): string
    {
        if (($infoPrix['valeur'] ?? null) === null) return '— €';
        return mbi_supports_format_prix($infoPrix['valeur']) . ($infoPrix['suffixe'] ?? '');
    }
}

if (!function_exists('mbi_supports_get_conditions_financieres')) {
    /**
     * Retourne le détail des conditions financières à afficher sur l'affiche.
     * Format vente : prix + honoraires (charge/inclus) + détail.
     * Format location : loyer HC + charges + honoraires bail + EDL + dépôt garantie.
     *
     * @return array Liste de paires [label, valeur] dans l'ordre d'affichage.
     */
    function mbi_supports_get_conditions_financieres(array $bien): array
    {
        $estLocation = mbi_supports_est_location($bien);
        $lignes = [];

        if ($estLocation) {
            $loyer   = (float)($bien['_annonce_loyer']    ?? 0);
            $loyerCC = (float)($bien['_annonce_loyer_cc'] ?? 0);
            // Charges : source canonique = biens.charges_locatives
            // Fallback : loyer_cc - loyer si biens.charges_locatives vide
            $charges = (float)($bien['charges_locatives'] ?? 0);
            if ($charges <= 0 && $loyerCC > 0 && $loyer > 0 && $loyerCC > $loyer) {
                $charges = $loyerCC - $loyer;
            }
            $hBail   = (float)($bien['_annonce_honoraires_bail'] ?? 0);
            $hEdl    = (float)($bien['_annonce_honoraires_edl']  ?? 0);
            $depot   = (float)($bien['_annonce_depot_garantie']  ?? 0);

            if ($loyer > 0)   $lignes[] = ['Loyer HC',  number_format($loyer, 0, ',', ' ') . ' € /mois'];
            if ($charges > 0) $lignes[] = ['Charges',   number_format($charges, 0, ',', ' ') . ' € /mois'];
            if ($hBail > 0)   $lignes[] = ['Honoraires bail',     number_format($hBail, 2, ',', ' ') . ' €'];
            if ($hEdl > 0)    $lignes[] = ['État des lieux',      number_format($hEdl,  2, ',', ' ') . ' €'];
            if ($depot > 0)   $lignes[] = ['Dépôt de garantie',   number_format($depot, 0, ',', ' ') . ' €'];
            return $lignes;
        }

        // Vente
        $hMontant = (float)($bien['honoraires_montant'] ?? 0);
        $hCharge  = trim((string)($bien['honoraires_charge'] ?? ''));
        $hInclus  = trim((string)($bien['honoraires_inclus'] ?? ''));
        $prixHors = (float)($bien['prix_hors_honoraires'] ?? $bien['prix_net_vendeur'] ?? 0);

        // Prix total FAI = MÊME valeur que le prix affiché en grand sur l'affiche
        // (source unique = mbi_supports_get_prix_ou_loyer), pour éviter toute
        // incohérence entre le prix géant et le détail des conditions.
        $prixTotal = (float)(mbi_supports_get_prix_ou_loyer($bien)['valeur'] ?? 0);
        if ($prixTotal <= 0 && $prixHors > 0 && $hMontant > 0) $prixTotal = $prixHors + $hMontant;
        if ($prixTotal > 0) $lignes[] = ['Prix total FAI',    number_format($prixTotal, 0, ',', ' ') . ' €'];
        if ($prixHors > 0) $lignes[] = ['Prix net vendeur',   number_format($prixHors, 0, ',', ' ') . ' €'];
        if ($hMontant > 0) $lignes[] = ['Honoraires',         number_format($hMontant, 0, ',', ' ') . ' €'];
        if ($hCharge !== '') $lignes[] = ['À la charge',      ucfirst($hCharge)];
        // « Honoraires inclus » est redondant avec « À la charge » (même info) :
        // on ne l'affiche QUE si « À la charge » n'est pas déjà renseigné.
        if ($hInclus !== '' && $hCharge === '') $lignes[] = ['Honoraires inclus', ucfirst($hInclus)];
        return $lignes;
    }
}

if (!function_exists('mbi_supports_get_copropriete_lignes')) {
    /**
     * Retourne les infos de COPROPRIÉTÉ à afficher sur l'affiche — VENTE uniquement.
     * (Obligation d'information ALUR pour la vente d'un lot en copropriété.)
     * Les infos communes (nb lots, budget prévisionnel, total tantièmes, procédures)
     * viennent de l'immeuble ; charges du lot + tantièmes du lot viennent du bien.
     * Retourne [] si location, si non en copropriété, ou si aucune donnée exploitable.
     *
     * @return array Liste de paires [label, valeur] dans l'ordre d'affichage.
     */
    function mbi_supports_get_copropriete_lignes(array $bien): array
    {
        // Vente uniquement
        if (mbi_supports_est_location($bien)) return [];
        // En copropriété uniquement
        $estCopro = (int)($bien['bien_en_copropriete'] ?? $bien['copropriete'] ?? $bien['est_copro'] ?? 0) === 1;
        if (!$estCopro) return [];

        $lignes = [];

        // Nb de lots de la copropriété (commun — immeuble)
        $nbLots = (int)($bien['copro_nb_lots'] ?? $bien['nb_lots_copro'] ?? 0);
        if ($nbLots > 0) $lignes[] = ['Lots copropriété', number_format($nbLots, 0, ',', ' ')];

        // Tantièmes : lot / total (les 2 valeurs)
        $tLot   = (int)($bien['lot_tantiemes'] ?? 0);
        $tTotal = (int)($bien['copro_tantiemes_total'] ?? 0);
        if ($tLot > 0 && $tTotal > 0) {
            $lignes[] = ['Tantièmes', number_format($tLot, 0, ',', ' ') . ' / ' . number_format($tTotal, 0, ',', ' ')];
        } elseif ($tLot > 0) {
            $lignes[] = ['Tantièmes du lot', number_format($tLot, 0, ',', ' ')];
        } elseif ($tTotal > 0) {
            $lignes[] = ['Total tantièmes', number_format($tTotal, 0, ',', ' ')];
        }

        // Charges courantes du lot (propre au bien)
        $charges = (float)($bien['copro_quote_part_charges'] ?? 0);
        if ($charges > 0) $lignes[] = ['Charges du lot', number_format($charges, 0, ',', ' ') . ' €/an'];

        // Budget prévisionnel total de la copropriété (commun — immeuble)
        $budget = (float)($bien['copro_budget_previsionnel_annuel'] ?? 0);
        if ($budget > 0) $lignes[] = ['Budget copro', number_format($budget, 0, ',', ' ') . ' €/an'];

        // Procédures (art. L.611-1 et s.) : syndic en procédure / plan de sauvegarde /
        // état de carence. Ligne toujours affichée pour la vente (info obligatoire).
        $proc = (int)($bien['copro_procedure'] ?? 0) === 1
             || (int)($bien['alur_copropriete_plan_sauvegarde'] ?? 0) === 1
             || (int)($bien['alur_copropriete_etat_carence'] ?? 0) === 1;
        $lignes[] = ['Procédures', $proc ? 'En cours' : 'Aucune'];

        return $lignes;
    }
}

if (!function_exists('mbi_supports_get_honoraires_ligne')) {
    /**
     * Retourne la ligne d'honoraires à afficher selon vente / location.
     * Vente : "Honoraires inclus : ..." ou "Honoraires charge ..." (ancien helper)
     * Location ALUR : "Honoraires bail X € · État des lieux Y €"
     */
    function mbi_supports_get_honoraires_ligne(array $bien): string
    {
        $estLocation = mbi_supports_est_location($bien);

        if ($estLocation) {
            $hBail = (float)($bien['_annonce_honoraires_bail']  ?? 0);
            $hEdl  = (float)($bien['_annonce_honoraires_edl']   ?? 0);
            $parts = [];
            if ($hBail > 0) $parts[] = 'Honoraires bail ' . number_format($hBail, 0, ',', ' ') . ' €';
            if ($hEdl  > 0) $parts[] = 'État des lieux ' . number_format($hEdl, 0, ',', ' ') . ' €';
            return $parts ? implode(' · ', $parts) . ' (à charge du locataire)' : '';
        }

        // Vente — fallback sur l'ancienne logique
        $inclus = trim((string)($bien['honoraires_inclus'] ?? ''));
        if ($inclus !== '') return 'Honoraires inclus : ' . $inclus;
        $charge = trim((string)($bien['honoraires_charge'] ?? ''));
        if ($charge !== '') return 'Honoraires à charge ' . $charge;
        return '';
    }
}

if (!function_exists('mbi_supports_get_surface')) {
    function mbi_supports_get_surface(array $bien): ?float
    {
        $s = (float)($bien['surface_habitable'] ?? $bien['surface'] ?? 0);
        return $s > 0 ? $s : null;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Photos : résolution chemin image avec fallback placeholder
// ─────────────────────────────────────────────────────────────────────────

if (!function_exists('mbi_supports_resoudre_photo_path')) {
    /**
     * Renvoie le chemin absolu d'une photo si elle existe sur le disque,
     * sinon null (le template affichera un placeholder).
     *
     * Schéma réel `biens_photos` : champ unique `url_photo` (relatif depuis
     * public_html/), ex: "uploads/biens/<id_societe>/<id_bien>/01_<hash>.jpg".
     * Les autres candidats (url_webp, url, chemin, file, path) sont gardés
     * comme fallback pour compatibilité avec d'éventuelles autres tables.
     */
    function mbi_supports_resoudre_photo_path(array $photo): ?string
    {
        $candidats = [
            $photo['url_photo']     ?? null,   // ← schéma réel biens_photos
            $photo['url_webp']      ?? null,
            $photo['url']           ?? null,
            $photo['url_lbc']       ?? null,
            $photo['chemin']        ?? null,
            $photo['file']          ?? null,
            $photo['path']          ?? null,
        ];
        $rootPublic = __DIR__ . '/..';
        foreach ($candidats as $rel) {
            if (!is_string($rel) || $rel === '') continue;
            // Tente d'abord en relatif depuis public_html
            $abs = $rel[0] === '/' ? ($rootPublic . $rel) : ($rootPublic . '/' . $rel);
            if (is_file($abs) && is_readable($abs)) return $abs;
            // Tente en strippant un éventuel préfixe public_html/ ou /public_html/
            $stripped = preg_replace('#^/?public_html/#', '', $rel);
            if (is_string($stripped) && $stripped !== $rel) {
                $abs2 = $rootPublic . '/' . ltrim($stripped, '/');
                if (is_file($abs2) && is_readable($abs2)) return $abs2;
            }
        }
        return null;
    }
}

if (!function_exists('mbi_supports_photo_hero')) {
    /**
     * Renvoie la photo héro selon priorité :
     *   1. photo avec id = $preferenceId (suggérée par l'IA via le score / éditeur)
     *   2. photo avec is_hero/hero = 1 (compat éventuelle autre table)
     *   3. photo avec ordre = 1 (convention biens_photos : 1ère photo = héro)
     *   4. 1re photo de la liste (fallback)
     * @param array $photos       Liste biens_photos
     * @param ?int  $preferenceId  ID préféré (suggéré par l'IA ou via l'éditeur)
     */
    function mbi_supports_photo_hero(array $photos, ?int $preferenceId = null): ?array
    {
        if (empty($photos)) return null;
        // 1. Préférence explicite (éditeur / IA)
        if ($preferenceId !== null && $preferenceId > 0) {
            foreach ($photos as $p) {
                if ((int)($p['id'] ?? 0) === $preferenceId) return $p;
            }
        }
        // 2. Champ is_hero/hero (compat schémas alternatifs)
        foreach ($photos as $p) {
            if ((int)($p['is_hero'] ?? $p['hero'] ?? 0) === 1) return $p;
        }
        // 3. Convention biens_photos : ordre = 1
        foreach ($photos as $p) {
            if ((int)($p['ordre'] ?? 0) === 1) return $p;
        }
        // 4. Fallback
        return $photos[0];
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Helpers nom de fichier
// ─────────────────────────────────────────────────────────────────────────

if (!function_exists('mbi_supports_slug')) {
    function mbi_supports_slug(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');

        // Translittération : intl si disponible, sinon iconv, sinon fallback ASCII
        if (function_exists('transliterator_transliterate')) {
            $t = transliterator_transliterate('Any-Latin; Latin-ASCII; [^a-zA-Z0-9 _-] Remove', $s);
            if (is_string($t) && $t !== '') $s = $t;
        } elseif (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if (is_string($t) && $t !== '') $s = $t;
        } else {
            // Remplacements manuels des accents les plus courants
            $s = strtr($s, [
                'à'=>'a','á'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a',
                'ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
                'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
                'ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','õ'=>'o',
                'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','ÿ'=>'y',
            ]);
        }

        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? $s;
        return trim((string)$s, '-');
    }
}

if (!function_exists('mbi_supports_appliquer_surcharges')) {
    /**
     * Applique les surcharges d'un support sur le bien chargé.
     * Retourne un nouveau tableau "bien effectif" avec les valeurs
     * personnalisées du support en priorité (sinon valeurs du bien).
     *
     * @param array $bien    Ligne biens chargée
     * @param array $support Ligne mbi_supports_commerciaux (peut être null/empty)
     * @return array  bien effectif (jamais modifie l'original)
     */
    function mbi_supports_appliquer_surcharges(array $bien, array $support): array
    {
        $eff = $bien;

        if (!empty($support['titre_personnalise'])) {
            $eff['designation'] = (string)$support['titre_personnalise'];
        }
        if (!empty($support['description_personnalisee'])) {
            $eff['description'] = (string)$support['description_personnalisee'];
            $eff['descriptif']  = (string)$support['description_personnalisee'];
            // _annonce_description est lu EN PRIORITÉ par les layouts (get_description_annonce)
            // → il faut l'écraser aussi, sinon le texte édité/synthétisé est ignoré.
            $eff['_annonce_description'] = (string)$support['description_personnalisee'];
        }
        if (!empty($support['accroche'])) {
            $eff['_accroche'] = (string)$support['accroche'];
        }
        if (!empty($support['photo_hero_id_personnalise'])) {
            $eff['_photo_hero_id_force'] = (int)$support['photo_hero_id_personnalise'];
        }
        if (!empty($support['angle_marketing'])) {
            $eff['_angle_marketing'] = (string)$support['angle_marketing'];
        }
        return $eff;
    }
}

if (!function_exists('mbi_supports_nom_fichier')) {
    function mbi_supports_nom_fichier(array $bien, string $type_support, int $version, bool $isInterne = false): string
    {
        $ref = $bien['reference_bien'] ?? ('bien-' . ($bien['id'] ?? '0'));
        $stamp = date('Ymd_His');
        $base = mbi_supports_slug((string)$ref) . '_' . $type_support . '_v' . $version . '_' . $stamp;
        if ($isInterne) $base .= '_INTERNE';
        return $base . '.pdf';
    }
}
