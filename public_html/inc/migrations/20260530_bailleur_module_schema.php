<?php
/**
 * Migration : Module Bailleur — Schéma (tables + colonnes)
 * Chaque ALTER est séparé pour éviter les unbuffered query errors.
 */

return [
    'id'          => '20260530_bailleur_module_schema',
    'title'       => 'Module Bailleur — Tables et colonnes',
    'description' => 'Crée locataires_statuts, user_bailleur_modules et ajoute vendu/archive/pdf_fichier/parse_statut/page_pdf.',
    'created_at'  => '2026-05-30',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `locataires_statuts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `locataire_nom` varchar(255) NOT NULL,
  `id_bien` int(11) DEFAULT NULL,
  `id_proprietaire` int(11) NOT NULL,
  `statut` enum('actif','debiteur_actif','irrecoverable') NOT NULL DEFAULT 'actif',
  `montant_creance` decimal(10,2) DEFAULT 0.00,
  `date_decision` date DEFAULT NULL,
  `commentaire` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `archive` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_loc_bien_prop` (`locataire_nom`(200),`id_bien`,`id_proprietaire`),
  KEY `idx_statut` (`statut`),
  KEY `idx_proprietaire` (`id_proprietaire`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS `user_bailleur_modules` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `id_user` INT NOT NULL,
  `module_code` VARCHAR(50) NOT NULL,
  UNIQUE KEY `uk_user_module` (`id_user`, `module_code`),
  KEY `idx_user` (`id_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE `immeubles` ADD COLUMN IF NOT EXISTS `vendu` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `immeubles` ADD COLUMN IF NOT EXISTS `date_vente` DATE NULL;
ALTER TABLE `locataires_statuts` ADD COLUMN IF NOT EXISTS `archive` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `crg_trimestres` ADD COLUMN IF NOT EXISTS `pdf_fichier` VARCHAR(500) NULL;
ALTER TABLE `crg_trimestres` ADD COLUMN IF NOT EXISTS `parse_statut` ENUM('en_attente','en_cours','ok','erreur') NULL DEFAULT 'en_attente';
ALTER TABLE `crg_situations_locataires` ADD COLUMN IF NOT EXISTS `page_pdf` SMALLINT NULL;
UPDATE `crg_trimestres` SET `parse_statut` = 'ok' WHERE `parse_statut` IS NULL OR `parse_statut` = 'en_attente';
SQL
];
