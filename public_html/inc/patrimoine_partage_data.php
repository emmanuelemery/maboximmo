<?php
/**
 * inc/patrimoine_partage_data.php — Source UNIQUE des lignes "biens" du partage
 * patrimoine (page publique + export Excel). Garantit un contenu identique :
 * patrimoine ACTIF uniquement, dédupliqué (1 ligne par bien), type + surface.
 */
declare(strict_types=1);
require_once __DIR__ . '/patrimoine_base.php';

if (!function_exists('fmt_euro')) {
    function fmt_euro($v): string { return number_format((float)$v, 0, ',', ' ') . ' €'; }
}

if (!function_exists('pp_bien_desc')) {
    function pp_bien_desc(array $d): string {
        return implode(' · ', array_filter([
            trim((string)($d['bien_adresse'] ?? '')) ?: trim((string)($d['imm_adresse'] ?? '')),
            trim((string)($d['bien_ville'] ?? ''))   ?: trim((string)($d['imm_ville'] ?? '')),
        ]));
    }
}
if (!function_exists('pp_surface')) {
    function pp_surface(array $d): string {
        $s = (float)($d['surface'] ?? 0);
        return $s > 0 ? rtrim(rtrim(number_format($s, 1, ',', ' '), '0'), ',') . ' m²' : '—';
    }
}
if (!function_exists('pp_batiment')) {
    /** @return array{0:string icône,1:string libellé précis,2:string catégorie} */
    function pp_batiment(array $d): array {
        $cat   = strtolower(trim((string)($d['bat_cat'] ?? '')));
        $label = trim((string)($d['bat_label'] ?? ''));
        $catLbl = ['habitation'=>'Habitation','commerce'=>'Commerce','commercial'=>'Commerce',
                   'professionnel'=>'Professionnel','terrain'=>'Terrain','stationnement'=>'Stationnement','annexe'=>'Annexe'][$cat] ?? '';
        $icon = ['habitation'=>'🏠','commerce'=>'🏪','commercial'=>'🏪','professionnel'=>'🏭',
                 'terrain'=>'🌳','stationnement'=>'🅿️','annexe'=>'📦'][$cat] ?? '🏢';
        return [$icon, ($label !== '' ? $label : ($catLbl ?: '—')), $catLbl];
    }
}
if (!function_exists('pp_loyer_mois')) {
    function pp_loyer_mois(array $d): float {
        // Priorité au loyer du bail actif (bien_baux, source canonique), puis baux legacy,
        // puis loyer appelé CRG ramené au mois.
        $bb = (float)($d['bail_loyer_bb'] ?? 0);
        if ($bb > 0) return $bb;
        $bl = (float)($d['bail_loyer'] ?? 0);
        return $bl > 0 ? $bl : ((float)($d['loyer_appele'] ?? 0) / 3);
    }
}
if (!function_exists('pp_prix')) {
    function pp_prix(array $d): float {
        $px = (float)($d['prix_scenario'] ?? 0);
        if ($px <= 0) $px = (float)($d['prix_demande_initial'] ?? 0);
        return $px;
    }
}

if (!function_exists('pp_partage_base_sql')) {
    /**
     * SOURCE UNIQUE des listes de biens du module bailleur = MÊME logique qu'agency_biens
     * (liste de référence) : TOUS les biens actifs, DIRECTEMENT depuis `biens`, occupation &
     * loyer via `bien_baux` actif (repli annonce/loyer_hc), aucun filtre CRG/valorisation.
     * Colonnes de sortie compatibles avec $props et pp_load_details.
     * @param string $propFilterWhere ex. "AND ct.id_proprietaire IN (…)" (converti en b.id_proprietaire).
     */
    function pp_partage_base_sql(string $propFilterWhere): string {
        require_once __DIR__ . '/bien_liste_source.php';
        // Périmètre : "AND ct.id_proprietaire IN (…)" → "AND b.id_proprietaire IN (…)".
        $bienWhere = str_replace('ct.id_proprietaire', 'b.id_proprietaire', $propFilterWhere);
        // Source unique = API bien_liste_source. Expose id_bien, id_proprietaire, imm_vendu,
        // loyer_mois (agrégats $props) + toutes les colonnes d'affichage.
        return bien_liste_source_sql(['where' => $bienWhere]);
    }
}

if (!function_exists('pp_load_details')) {
    /**
     * Lignes détail (biens) du patrimoine ACTIF, groupées par propriétaire.
     * Source = pp_partage_base_sql (mêmes biens qu'agency_biens).
     * @return array<int, array<int, array>>  [id_proprietaire => rows]
     */
    function pp_load_details(PDO $pdo, string $propFilterWhere, string $scenSel): array {
        require_once __DIR__ . '/bien_liste_source.php';
        // Périmètre propriétaire : "AND ct.id_proprietaire IN (…)" → "AND b.id_proprietaire IN (…)".
        $bienWhere = str_replace('ct.id_proprietaire', 'b.id_proprietaire', $propFilterWhere);
        $src = bien_liste_source_sql(['where' => $bienWhere, 'scenario_code' => $scenSel]);
        // Enveloppe de compatibilité : mappe les colonnes de l'API vers celles attendues par le rendu.
        $sql = "
            SELECT x.id_proprietaire, x.id_bien, x.ref_affiche, x.reference_bien, x.numero_lot,
                   x.surface, x.type_categorie AS bat_cat, x.type_libelle AS bat_label,
                   x.locataire AS locataire_nom, x.locataire AS bail_locataire,
                   CASE WHEN x.has_bail THEN 'present' ELSE 'parti' END AS presence,
                   0 AS hors_crg, 0 AS loc_archive, 0 AS loyer_appele, 0 AS bail_loyer,
                   x.loyer_mois AS bail_loyer_bb, x.prix_scenario, x.prix_demande_initial,
                   x.id_immeuble, x.nom_immeuble, x.imm_adresse, x.imm_ville, x.imm_code_crg, x.imm_vendu,
                   x.bien_adresse, x.bien_ville
            FROM ({$src}) x
            WHERE x.imm_vendu = 0
            ORDER BY x.id_proprietaire, COALESCE(NULLIF(x.imm_code_crg,''), x.nom_immeuble, x.imm_adresse, '~'), x.numero_lot, x.ref_affiche";
        $byProp = [];
        foreach ($pdo->query($sql) as $r) { $byProp[(int)$r['id_proprietaire']][] = $r; }
        return $byProp;
    }
}

if (!function_exists('pp_load_all_biens')) {
    /**
     * TOUS les biens actifs des propriétaires (indépendamment du CRG et d'une valeur).
     * Utilisé en mode IFI : on doit pouvoir saisir la valeur sur chaque bien, même vide.
     * @return array<int, array<int, array>>  [id_proprietaire => rows]
     */
    function pp_load_all_biens(PDO $pdo, array $propIds, string $scenSel): array {
        $ids = implode(',', array_map('intval', array_filter($propIds)));
        if ($ids === '') return [];
        $scenQuoted = $pdo->quote($scenSel);
        $sql = "
            SELECT b.id_proprietaire, b.id AS id_bien, b.reference_bien, b.prix_demande_initial,
                   COALESCE(NULLIF(b.surface_habitable,0), NULLIF(b.surface_carrez,0), NULLIF(b.surface_totale,0),
                            NULLIF(b.surface_commerciale,0), NULLIF(b.surface_depot,0), NULLIF(b.surface_bureau,0)) AS surface,
                   COALESCE(bt.categorie, tb2.categorie) AS bat_cat,
                   COALESCE(bt.libelle, tb2.libelle)     AS bat_label,
                   b.adresse_1 AS bien_adresse, b.ville AS bien_ville,
                   i.id AS id_immeuble, i.nom_immeuble, i.adresse_1 AS imm_adresse, i.ville AS imm_ville,
                   0 AS imm_vendu, 0 AS loc_archive, 0 AS loyer_appele,
                   (SELECT COALESCE(NULLIF(tl.nom_affichage,''), bb.locataire_raison_sociale,
                            NULLIF(TRIM(CONCAT_WS(' ', bb.locataire_prenom, bb.locataire_nom)),''))
                      FROM bien_baux bb LEFT JOIN tiers tl ON tl.id = bb.id_tiers_locataire
                     WHERE bb.id_bien = b.id ORDER BY (bb.statut='actif') DESC, bb.date_prise_effet DESC LIMIT 1) AS locataire_nom,
                   (SELECT bx.loyer FROM baux bx WHERE bx.id_bien=b.id AND bx.id_proprietaire=b.id_proprietaire
                      ORDER BY (bx.statut='actif') DESC, bx.id DESC LIMIT 1) AS bail_loyer,
                   (SELECT bp.montant FROM bien_prix bp
                      WHERE bp.id_bien=b.id AND bp.type_valeur='prix_vente' AND bp.is_courant=1 AND bp.scenario_code={$scenQuoted}
                      ORDER BY bp.date_validation DESC, bp.id DESC LIMIT 1) AS prix_scenario
            FROM biens b
            LEFT JOIN bien_types bt ON bt.id = b.id_bien_type
            LEFT JOIN types_bien tb2 ON tb2.id = b.id_type_bien
            LEFT JOIN immeubles i ON i.id = b.id_immeuble
            WHERE b.id_proprietaire IN ($ids)
              AND (b.statut_bien IS NULL OR b.statut_bien NOT IN ('vendu','archive','supprime'))
              AND b.prix_final_vente IS NULL
              AND (b.reference_bien IS NULL OR b.reference_bien NOT LIKE 'VTE-%')
            ORDER BY b.id_proprietaire, b.reference_bien";
        $byProp = [];
        foreach ($pdo->query($sql) as $r) {
            // Occupé si un locataire est rattaché, sinon vacant.
            $r['presence'] = trim((string)$r['locataire_nom']) !== '' ? 'present' : 'parti';
            $r['hors_crg'] = $r['presence'] === 'present' ? 0 : 1;
            $byProp[(int)$r['id_proprietaire']][] = $r;
        }
        return $byProp;
    }
}

if (!function_exists('pp_dedup_biens')) {
    /** Une seule ligne par bien : garde la ligne OCCUPÉE en priorité (repli vacant). */
    function pp_dedup_biens(array $rows): array {
        $parBien = [];
        foreach ($rows as $d) {
            $ib = (int)$d['id_bien'];
            $isPresent = ($d['presence'] === 'present' && empty($d['hors_crg']));
            if (!isset($parBien[$ib]) || ($isPresent && $parBien[$ib]['presence'] !== 'present')) {
                $parBien[$ib] = $d;
            }
        }
        return array_values($parBien);
    }
}
