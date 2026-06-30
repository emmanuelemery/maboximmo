<?php
/**
 * Migration : schéma Portefeuilles de vente.
 *
 *  - portefeuilles        : un portefeuille à proposer (commercialisateur / investisseur)
 *  - portefeuille_biens   : lignes (bien + prix de vente, prix/m², rendement, honoraires, net vendeur)
 *
 * Snapshot (snap_*) figé à l'enregistrement pour un affichage stable même si le bien évolue.
 * Pas de FK vers biens (un bien peut être supprimé / dédoublonné) — id_bien reste indicatif + snapshot.
 * Additif et rejouable (IF NOT EXISTS).
 */
return [
    'id'          => '20260606_portefeuilles_schema',
    'title'       => 'Portefeuilles de vente — schéma (portefeuilles + portefeuille_biens)',
    'description' => 'Crée portefeuilles et portefeuille_biens (prix de vente, prix/m², rendement, honoraires, net vendeur, snapshot).',
    'created_at'  => '2026-06-06',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `portefeuilles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nom` VARCHAR(160) NOT NULL,
  `type_destinataire` VARCHAR(20) NULL COMMENT 'commercialisateur | investisseur',
  `destinataire_nom` VARCHAR(190) NULL,
  `id_tiers_destinataire` INT UNSIGNED NULL,
  `statut` VARCHAR(20) NOT NULL DEFAULT 'brouillon' COMMENT 'brouillon|propose|envoye|archive',
  `commentaire` TEXT NULL,
  `nb_biens` INT NOT NULL DEFAULT 0,
  `total_prix_vente` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `total_net_vendeur` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `total_honoraires` DECIMAL(15,2) NOT NULL DEFAULT 0,
  `id_user` INT UNSIGNED NULL,
  `date_creation` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_modification` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_pf_statut` (`statut`),
  INDEX `idx_pf_dest` (`type_destinataire`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `portefeuille_biens` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_portefeuille` INT UNSIGNED NOT NULL,
  `id_bien` INT UNSIGNED NOT NULL,
  `prix_vente` DECIMAL(15,2) NULL,
  `prix_m2` DECIMAL(12,2) NULL,
  `rendement` DECIMAL(6,2) NULL,
  `honoraires_pct` DECIMAL(6,3) NULL,
  `honoraires_montant` DECIMAL(15,2) NULL,
  `net_vendeur` DECIMAL(15,2) NULL,
  `snap_adresse` VARCHAR(255) NULL,
  `snap_ville` VARCHAR(150) NULL,
  `snap_reference` VARCHAR(60) NULL,
  `snap_surface` DECIMAL(10,2) NULL,
  `snap_loyer_mensuel` DECIMAL(12,2) NULL,
  `ordre` INT NOT NULL DEFAULT 0,
  `date_creation` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pf_bien` (`id_portefeuille`,`id_bien`),
  INDEX `idx_pfb_portefeuille` (`id_portefeuille`),
  INDEX `idx_pfb_bien` (`id_bien`),
  CONSTRAINT `fk_pfb_portefeuille` FOREIGN KEY (`id_portefeuille`) REFERENCES `portefeuilles`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];
