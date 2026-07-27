<?php
/**
 * Migration : champs spécifiques au BAIL HABITATION NU (loi 89-462, modèle FNAIM) sur bien_baux.
 *
 * Le socle bien_baux (loyer HC, complément, charges provisions/forfait, IRL, zone tendue,
 * loyer réf/majoré, honoraires, locataire/garant/caution, travaux, dépôt de garantie…) est
 * réutilisé. On ajoute ici ce qui manque au bail habitation, en ADDITIF + idempotent.
 */
return [
    'id'          => '20260727a_bien_baux_champs_habitation',
    'title'       => 'bien_baux : champs bail habitation nu (FNAIM 89-462)',
    'description' => "Ajoute DPE, surface, dernier loyer, TEOM, dépenses énergie, honoraires détaillés, colocation, etc.",
    'created_at'  => '2026-07-27',
    'sql' => <<<'SQL'
ALTER TABLE `bien_baux`
  ADD COLUMN IF NOT EXISTS `dpe_classe` VARCHAR(4) NULL AFTER `usage_bien`,
  ADD COLUMN IF NOT EXISTS `surface_habitable` DECIMAL(8,2) NULL AFTER `dpe_classe`,
  ADD COLUMN IF NOT EXISTS `nb_pieces` SMALLINT UNSIGNED NULL AFTER `surface_habitable`,
  ADD COLUMN IF NOT EXISTS `equipement_tic` VARCHAR(255) NULL AFTER `nb_pieces`,
  ADD COLUMN IF NOT EXISTS `colocation` TINYINT(1) NOT NULL DEFAULT 0 AFTER `equipement_tic`,
  ADD COLUMN IF NOT EXISTS `dernier_loyer_montant` DECIMAL(10,2) NULL AFTER `loyer_reference_majore`,
  ADD COLUMN IF NOT EXISTS `dernier_loyer_date_versement` DATE NULL AFTER `dernier_loyer_montant`,
  ADD COLUMN IF NOT EXISTS `dernier_loyer_date_revision` DATE NULL AFTER `dernier_loyer_date_versement`,
  ADD COLUMN IF NOT EXISTS `complement_loyer_caracteristiques` TEXT NULL AFTER `complement_loyer`,
  ADD COLUMN IF NOT EXISTS `teom_annee` SMALLINT UNSIGNED NULL AFTER `provision_tom_mensuelle`,
  ADD COLUMN IF NOT EXISTS `teom_montant` DECIMAL(10,2) NULL AFTER `teom_annee`,
  ADD COLUMN IF NOT EXISTS `assurance_colocataires_mensuel` DECIMAL(8,2) NULL AFTER `teom_montant`,
  ADD COLUMN IF NOT EXISTS `paiement_jour` VARCHAR(5) NULL AFTER `periodicite_paiement`,
  ADD COLUMN IF NOT EXISTS `paiement_beneficiaire` VARCHAR(190) NULL AFTER `paiement_jour`,
  ADD COLUMN IF NOT EXISTS `depenses_energie_min` DECIMAL(10,2) NULL AFTER `assurance_colocataires_mensuel`,
  ADD COLUMN IF NOT EXISTS `depenses_energie_max` DECIMAL(10,2) NULL AFTER `depenses_energie_min`,
  ADD COLUMN IF NOT EXISTS `depenses_energie_annee` SMALLINT UNSIGNED NULL AFTER `depenses_energie_max`,
  ADD COLUMN IF NOT EXISTS `honoraires_plafond_visite_m2` DECIMAL(6,2) NULL AFTER `honoraires_locataire_ttc`,
  ADD COLUMN IF NOT EXISTS `honoraires_plafond_edl_m2` DECIMAL(6,2) NULL AFTER `honoraires_plafond_visite_m2`,
  ADD COLUMN IF NOT EXISTS `hono_bailleur_visite` DECIMAL(10,2) NULL AFTER `honoraires_plafond_edl_m2`,
  ADD COLUMN IF NOT EXISTS `hono_bailleur_entremise` DECIMAL(10,2) NULL AFTER `hono_bailleur_visite`,
  ADD COLUMN IF NOT EXISTS `hono_bailleur_edl` DECIMAL(10,2) NULL AFTER `hono_bailleur_entremise`,
  ADD COLUMN IF NOT EXISTS `hono_locataire_visite` DECIMAL(10,2) NULL AFTER `hono_bailleur_edl`,
  ADD COLUMN IF NOT EXISTS `hono_locataire_edl` DECIMAL(10,2) NULL AFTER `hono_locataire_visite`,
  ADD COLUMN IF NOT EXISTS `lieu_signature` VARCHAR(120) NULL AFTER `hono_locataire_edl`,
  ADD COLUMN IF NOT EXISTS `annexes_json` TEXT NULL AFTER `lieu_signature`;
SQL
];
