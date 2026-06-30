<?php
/**
 * Migration : réintègre les biens VTE-2026-* du GROUPE SIR retirés de la
 * commercialisation PAR ERREUR.
 *
 * Contexte : VTE-2026-001 à 008 (SARL GROUPE SIR) sont gérés mais sans locataire
 * et hors CRG. Sur prod ils portaient une date_retrait_commercialisation (et/ou un
 * prix_final_vente) renseignée par erreur, ce qui les faisait disparaître du
 * Patrimoine actif et du Portefeuille (qui excluent tout bien retiré/vendu).
 *
 * Effet : efface le retrait erroné pour qu'ils réapparaissent au patrimoine.
 *   - date_retrait_commercialisation → NULL
 *   - prix_final_vente               → NULL
 *   - statut_bien remis à 'actif' s'il avait été basculé en vendu/archive
 *
 * Garde-fous : ne touche QUE les biens dont la réf commence par 'VTE-2026-' ET
 * dont le tiers propriétaire est « SARL GROUPE SIR » (résolu par raison sociale →
 * portable local/prod, pas d'id en dur). Idempotent (n'agit que si une valeur est
 * encore renseignée).
 */
return [
    'id'          => '20260612_reintegre_vte_groupe_sir',
    'title'       => 'Réintègre les VTE-2026-* du GROUPE SIR (retrait commercialisation erroné)',
    'description' => "Efface date_retrait_commercialisation / prix_final_vente mis par erreur sur les biens VTE-2026-* de SARL GROUPE SIR, pour qu'ils réapparaissent au patrimoine actif et au portefeuille.",
    'created_at'  => '2026-06-12',
    'sql' => <<<'SQL'
UPDATE `biens` b
JOIN `proprietaires` pr ON pr.id = b.id_proprietaire
JOIN `tiers` t          ON t.id = pr.id_tiers
SET b.date_retrait_commercialisation = NULL,
    b.prix_final_vente               = NULL,
    b.statut_bien = CASE WHEN b.statut_bien IN ('vendu','archive') THEN 'actif' ELSE b.statut_bien END
WHERE t.raison_sociale = 'SARL GROUPE SIR'
  AND b.reference_bien LIKE 'VTE-2026-%'
  AND (b.date_retrait_commercialisation IS NOT NULL
       OR b.prix_final_vente IS NOT NULL
       OR b.statut_bien IN ('vendu','archive'));
SQL,
];
