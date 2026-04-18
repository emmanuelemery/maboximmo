<?php
/**
 * Migration : Tables bien_baux + bailleur_acte
 *
 * - bien_baux : tous types de baux (habitation, commercial, professionnel,
 *   civil, terrain, parking, meublé touristique). Discriminateur : bail_nature.
 *   Champs multi-locataires / multi-cautions stockés dans metadata JSON.
 *
 * - bailleur_acte : notifications de mutation reçues par la régie (2 pages
 *   syndic, art. 6 décret 67-223 + art. 20 loi 65-557). Vendeurs, acquéreurs,
 *   lots de copropriété et cadastre stockés en JSON.
 *
 * Règle d'or : statements additifs uniquement (IF NOT EXISTS) → rejouables.
 */

return [
    'id'          => '20260418_baux_et_actes',
    'title'       => 'Baux (tous types) + Actes de mutation (notifications syndic)',
    'description' => "Création des tables bien_baux (7 natures + metadata JSON) et bailleur_acte (notification de mutation du notaire au syndic, 2 pages). Modules d'intake IA associés (bien_intake_bail.php, bien_intake_titre.php).",
    'created_at'  => '2026-04-18',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `bien_baux` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_bien` INT UNSIGNED NOT NULL,
  `id_proprietaire` INT UNSIGNED NULL,
  `id_agence` INT UNSIGNED NULL,
  `id_societe` INT UNSIGNED NULL,

  `bail_nature` ENUM('habitation','commercial','professionnel','civil','terrain','parking','meuble_touristique','autre') NOT NULL DEFAULT 'habitation',
  `bail_type` VARCHAR(60) NULL,
  `usage_bien` VARCHAR(100) NULL,
  `destination_activite` TEXT NULL,

  `reference_bail` VARCHAR(60) NULL,
  `date_signature` DATE NULL,
  `date_prise_effet` DATE NULL,
  `duree_mois` SMALLINT UNSIGNED NULL,
  `date_fin` DATE NULL,
  `periode_triennale` TINYINT(1) NOT NULL DEFAULT 0,
  `reconduction` VARCHAR(40) NULL,

  `loyer_mensuel_hc` DECIMAL(10,2) NULL,
  `complement_loyer` DECIMAL(10,2) NULL,
  `charges_mensuelles` DECIMAL(10,2) NULL,
  `charges_type` ENUM('provisions','forfait') NULL DEFAULT 'provisions',
  `total_mensuel` DECIMAL(10,2) NULL,
  `tva_applicable` TINYINT(1) NOT NULL DEFAULT 0,
  `tva_taux` DECIMAL(5,2) NULL,

  `indice_type` ENUM('IRL','ILC','ILAT','ICC','autre') NULL,
  `indice_trimestre` VARCHAR(10) NULL,
  `indice_valeur` DECIMAL(8,3) NULL,
  `date_revision_jour_mois` VARCHAR(5) NULL,

  `zone_tendue` TINYINT(1) NOT NULL DEFAULT 0,
  `loyer_reference` DECIMAL(6,2) NULL,
  `loyer_reference_majore` DECIMAL(6,2) NULL,

  `depot_garantie` DECIMAL(10,2) NULL,
  `nb_termes_garantie` TINYINT UNSIGNED NULL,
  `clause_resolutoire` TINYINT(1) NOT NULL DEFAULT 1,

  `honoraires_bailleur_ttc` DECIMAL(10,2) NULL,
  `honoraires_locataire_ttc` DECIMAL(10,2) NULL,
  `honoraires_charge` ENUM('bailleur','locataire','partage') NULL,

  `locataire_type` ENUM('physique','societe') NOT NULL DEFAULT 'physique',
  `locataire_nom` VARCHAR(120) NULL,
  `locataire_prenom` VARCHAR(80) NULL,
  `locataire_raison_sociale` VARCHAR(160) NULL,
  `locataire_siren` VARCHAR(20) NULL,
  `locataire_email` VARCHAR(160) NULL,
  `locataire_telephone` VARCHAR(30) NULL,

  `caution_type` ENUM('physique','visale','assurance','aucune') NOT NULL DEFAULT 'aucune',
  `caution_nom` VARCHAR(120) NULL,
  `caution_prenom` VARCHAR(80) NULL,

  `metadata` JSON NULL,

  `document_pdf` VARCHAR(255) NULL,
  `statut` ENUM('brouillon','actif','resilie','expire') NOT NULL DEFAULT 'actif',
  `commentaire_admin` TEXT NULL,
  `id_user_created` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_bien` (`id_bien`),
  KEY `idx_proprietaire` (`id_proprietaire`),
  KEY `idx_statut` (`statut`),
  KEY `idx_nature` (`bail_nature`),
  KEY `idx_reference` (`reference_bail`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `bailleur_acte` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_bien` INT UNSIGNED NULL,
  `id_proprietaire` INT UNSIGNED NULL,
  `id_societe` INT UNSIGNED NULL,

  `type_document` ENUM('notification_mutation','avis_mutation','acte_vente','attestation_propriete','autre') NOT NULL DEFAULT 'notification_mutation',
  `nature_mutation` ENUM('vente','donation','succession','partage','apport','autre') NOT NULL DEFAULT 'vente',

  `date_acte` DATE NULL,
  `date_jouissance` DATE NULL,
  `date_notification` DATE NULL,

  `notaire_office` VARCHAR(160) NULL,
  `notaire_nom` VARCHAR(120) NULL,
  `notaire_crpcen` VARCHAR(20) NULL,
  `notaire_adresse_1` VARCHAR(200) NULL,
  `notaire_code_postal` VARCHAR(10) NULL,
  `notaire_ville` VARCHAR(80) NULL,
  `notaire_telephone` VARCHAR(30) NULL,

  `notaire_vendeur_nom` VARCHAR(120) NULL,
  `notaire_vendeur_crpcen` VARCHAR(20) NULL,
  `notaire_vendeur_ville` VARCHAR(80) NULL,

  `vendeurs` JSON NULL,
  `acquereurs` JSON NULL,

  `designation` VARCHAR(200) NULL,
  `bien_adresse_1` VARCHAR(200) NULL,
  `bien_code_postal` VARCHAR(10) NULL,
  `bien_ville` VARCHAR(80) NULL,
  `cadastre` JSON NULL,
  `lots_copropriete` JSON NULL,

  `prix_vente` DECIMAL(12,2) NULL,
  `frais_mutation` DECIMAL(10,2) NULL,
  `provision_charges` DECIMAL(10,2) NULL,
  `charges_impayees` DECIMAL(10,2) NULL,
  `emprunt_collectif_rembourse` DECIMAL(10,2) NULL,

  `domicile_opposition` VARCHAR(200) NULL,
  `delai_opposition_jours` TINYINT UNSIGNED NULL,

  `metadata` JSON NULL,

  `document_pdf` VARCHAR(255) NULL,
  `commentaire_admin` TEXT NULL,
  `id_user_created` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_bien` (`id_bien`),
  KEY `idx_proprietaire` (`id_proprietaire`),
  KEY `idx_date_acte` (`date_acte`),
  KEY `idx_type_document` (`type_document`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];
