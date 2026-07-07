<?php
/**
 * Migration : identité COMPLÈTE du locataire + bloc GARANT / caution solidaire sur bien_baux.
 *
 * Motivation :
 *  - Un bail (commercial ou habitation) exige l'identité complète du preneur : adresse,
 *    date et lieu de naissance, nom(s) et prénom(s).
 *  - Le GARANT (caution solidaire) est la protection n°1 du bailleur pour un candidat :
 *    on stocke son identité complète, le montant et la durée de son engagement.
 *
 * Règle d'or : ADDITIF + idempotent (ADD COLUMN IF NOT EXISTS). Rien de la société/agence
 * gestionnaire ici (lue en BDD à la génération).
 */
return [
    'id'          => '20260706d_baux_locataire_identite_garant',
    'title'       => 'Baux : identité complète locataire + garant/caution solidaire',
    'description' => "Ajoute sur bien_baux l'adresse, la date et le lieu de naissance et la nationalité du locataire, ainsi qu'un bloc GARANT complet (identité, montant et durée de l'engagement, caution solidaire).",
    'created_at'  => '2026-07-06',
    'sql' => <<<'SQL'
-- ── Identité complète du LOCATAIRE (preneur) ──
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `locataire_adresse`        VARCHAR(255) NULL AFTER `locataire_telephone`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `locataire_date_naissance` DATE         NULL AFTER `locataire_adresse`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `locataire_lieu_naissance` VARCHAR(120) NULL AFTER `locataire_date_naissance`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `locataire_nationalite`    VARCHAR(60)  NULL AFTER `locataire_lieu_naissance`;

-- ── GARANT / caution solidaire ──
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `garant_present`        TINYINT(1)   NOT NULL DEFAULT 0 AFTER `locataire_nationalite`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `garant_type`           ENUM('physique','societe') NULL AFTER `garant_present`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `garant_nom`            VARCHAR(120) NULL AFTER `garant_type`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `garant_prenom`         VARCHAR(120) NULL AFTER `garant_nom`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `garant_raison_sociale` VARCHAR(160) NULL AFTER `garant_prenom`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `garant_siren`          VARCHAR(20)  NULL AFTER `garant_raison_sociale`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `garant_adresse`        VARCHAR(255) NULL AFTER `garant_siren`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `garant_date_naissance` DATE         NULL AFTER `garant_adresse`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `garant_lieu_naissance` VARCHAR(120) NULL AFTER `garant_date_naissance`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `garant_email`          VARCHAR(160) NULL AFTER `garant_lieu_naissance`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `garant_telephone`      VARCHAR(30)  NULL AFTER `garant_email`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `garant_montant_max`    DECIMAL(12,2) NULL AFTER `garant_telephone`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `garant_duree_ans`      SMALLINT UNSIGNED NULL AFTER `garant_montant_max`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `garant_solidaire`      TINYINT(1)   NOT NULL DEFAULT 1 AFTER `garant_duree_ans`;
SQL
];
