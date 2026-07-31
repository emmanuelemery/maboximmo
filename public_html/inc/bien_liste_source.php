<?php
declare(strict_types=1);
/**
 * inc/bien_liste_source.php — SOURCE UNIQUE (API interne) des listes de biens.
 *
 * Toute liste de biens du module bailleur DOIT passer par ici — plus aucune requête
 * `biens` ad hoc. Même vérité que la liste de référence `agency_biens` :
 *   - biens listés DIRECTEMENT depuis `biens` (aucun filtre CRG / valorisation) ;
 *   - TYPE via COALESCE(bien_types [id_bien_type], types_bien [id_type_bien])
 *     — JAMAIS base_types_bien (ids inversés) ;
 *   - OCCUPATION & LOYER via `bien_baux` (bail actif), repli annonce puis loyer_hc ;
 *   - RÉF affichée = reference_bien, sinon dérivée du code CRG (immeuble_lot).
 *
 * Usage :
 *   require_once inc/bien_liste_source.php;
 *   $sql = "SELECT ... FROM (".bien_liste_source_sql(['where'=>"AND b.id_proprietaire IN (1,2)"]).") x
 *           WHERE ... GROUP BY ... ORDER BY ...";
 *
 * @param array $opts {
 *   where?: string          Clause SQL brute additionnelle (déjà échappée/entiers),
 *                           ex. "AND b.id_proprietaire IN (1,2,3)" ou "AND b.id_immeuble = 10".
 *   scenario_code?: string  Code scénario pour `prix_scenario` (défaut 'courant').
 * }
 */
if (!function_exists('bien_liste_source_sql')) {
    function bien_liste_source_sql(array $opts = []): string {
        $where = (string)($opts['where'] ?? '');
        $scen  = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)($opts['scenario_code'] ?? 'courant'))) ?: 'courant';
        return "
        SELECT
          b.id AS id_bien, b.id_proprietaire, b.id_immeuble, b.id_societe, b.id_agence,
          b.reference_bien, b.numero_lot, b.code_crg, b.statut_bien,
          COALESCE(NULLIF(b.reference_bien,''), REPLACE(b.code_crg,'_','-'),
                   NULLIF(CONCAT(COALESCE(i.code_crg,''),'-',COALESCE(b.numero_lot,'')),'-')) AS ref_affiche,
          b.etage, b.nb_pieces, b.dpe_classe, b.prix_demande_initial, b.loyer_hc,
          COALESCE(NULLIF(b.surface_habitable,0), NULLIF(b.surface_carrez,0), NULLIF(b.surface_totale,0),
                   NULLIF(b.surface_commerciale,0), NULLIF(b.surface_depot,0), NULLIF(b.surface_bureau,0)) AS surface,
          COALESCE(bt.libelle, tb2.libelle)     AS type_libelle,
          COALESCE(bt.categorie, tb2.categorie) AS type_categorie,
          i.nom_immeuble, i.adresse_1 AS imm_adresse, i.ville AS imm_ville, i.code_crg AS imm_code_crg,
          COALESCE(i.vendu,0) AS imm_vendu,
          b.adresse_1 AS bien_adresse, b.ville AS bien_ville,
          p.id_tiers AS proprio_tiers,
          COALESCE(NULLIF(p.societe,''), TRIM(CONCAT_WS(' ', p.prenom, p.nom))) AS proprio_nom,
          (SELECT COALESCE(NULLIF(bb.locataire_raison_sociale,''), TRIM(CONCAT_WS(' ', bb.locataire_prenom, bb.locataire_nom)))
             FROM bien_baux bb WHERE bb.id_bien=b.id AND bb.statut='actif' ORDER BY bb.id DESC LIMIT 1) AS locataire,
          (SELECT bb.id FROM bien_baux bb WHERE bb.id_bien=b.id AND bb.statut='actif' ORDER BY bb.id DESC LIMIT 1) AS bail_id,
          EXISTS(SELECT 1 FROM bien_baux bb WHERE bb.id_bien=b.id AND bb.statut='actif') AS has_bail,
          COALESCE(
            (SELECT bb.loyer_mensuel_hc FROM bien_baux bb WHERE bb.id_bien=b.id AND bb.statut='actif' ORDER BY bb.id DESC LIMIT 1),
            (SELECT a.loyer FROM annonces a WHERE a.id_bien=b.id AND (a.etat_publication IS NULL OR a.etat_publication NOT IN ('archive','archivee','supprime')) ORDER BY a.id DESC LIMIT 1),
            b.loyer_hc, 0) AS loyer_mois,
          (SELECT bp.montant FROM bien_prix bp WHERE bp.id_bien=b.id AND bp.type_valeur='prix_vente' AND bp.is_courant=1 AND bp.scenario_code='courant'
             ORDER BY bp.date_validation DESC, bp.id DESC LIMIT 1) AS prix_courant,
          (SELECT bp.montant FROM bien_prix bp WHERE bp.id_bien=b.id AND bp.type_valeur='prix_vente' AND bp.is_courant=1 AND bp.scenario_code='{$scen}'
             ORDER BY bp.date_validation DESC, bp.id DESC LIMIT 1) AS prix_scenario
        FROM biens b
        LEFT JOIN immeubles i    ON i.id  = b.id_immeuble
        LEFT JOIN bien_types bt  ON bt.id = b.id_bien_type
        LEFT JOIN types_bien tb2 ON tb2.id = b.id_type_bien
        LEFT JOIN proprietaires p ON p.id = b.id_proprietaire
        WHERE (b.statut_bien IS NULL OR b.statut_bien NOT IN ('supprime','archive','vendu'))
          AND b.prix_final_vente IS NULL
          AND b.date_retrait_commercialisation IS NULL
          AND (b.reference_bien IS NULL OR b.reference_bien NOT LIKE 'VTE-%')
          {$where}
        ";
    }
}
