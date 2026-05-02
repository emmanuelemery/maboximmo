<?php
/**
 * Migration : Ma GED Box V1 — Arborescence (folders + levels + templates)
 *
 * Crée le socle d'arborescence dynamique BDD (jusqu'à 6 niveaux), pilotable
 * depuis admin_ged_arborescence.php. Source de vérité métier : MaBoxImmo BDD.
 * Google Drive = stockage physique uniquement, pas de référentiel parallèle.
 *
 * Tables créées :
 *   - ged_folders                : arbre des répertoires (parent_id récursif)
 *   - ged_folder_levels          : paramétrage des 6 niveaux par module
 *                                  (1=Métier, 2=Domaine, 3=Type, 4=Entité,
 *                                   5=Année, 6=Mois/Sous-type)
 *   - ged_folder_templates       : modèles d'arborescence par module
 *   - ged_folder_template_nodes  : nœuds des modèles (squelette d'arbre)
 *
 * IMPORTANT : ne touche à AUCUNE table existante. AJOUT uniquement.
 */

return [
    'id'          => '20260502_ged_v1_01_folders',
    'title'       => 'Ma GED Box V1 — arborescence (ged_folders, levels, templates)',
    'description' => "Crée 4 tables : ged_folders (arbre dynamique parent_id, depth, path_cache, name_display + name_canonical + slug, scope société/agence/service, gdrive_folder_id, is_system, is_archived), ged_folder_levels (paramétrage 6 niveaux par module), ged_folder_templates + ged_folder_template_nodes (modèles d'arborescence). AJOUT uniquement, aucune table existante touchée.",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `ged_folders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `tenant_id` INT UNSIGNED NULL COMMENT 'NULL = système global ; sinon = id_societe',
  `parent_id` BIGINT UNSIGNED NULL,
  `depth` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = racine, max 6',
  `path_cache` VARCHAR(2048) NOT NULL DEFAULT '' COMMENT 'Chemin slash-separated (slugs)',
  `slug` VARCHAR(120) NOT NULL,
  `name_display` VARCHAR(255) NOT NULL COMMENT 'Nom humain modifiable',
  `name_canonical` VARCHAR(255) NOT NULL COMMENT 'Nom machine stable (auto-genere)',
  `module` VARCHAR(50) NULL COMMENT 'DIRECTION|RH|SYNDIC|GESTION_LOCATIVE|TRANSACTION|COMPTABILITE|JURIDIQUE|MARKETING|MAILS|MODELES|ARCHIVES|...',
  `scope` ENUM('global','societe','agence','service','entity') NOT NULL DEFAULT 'global',
  `source_type` ENUM('auto','manual') NOT NULL DEFAULT 'manual',
  `entity_type` VARCHAR(40) NULL COMMENT 'IMB|BIEN|MDT|CTX|EMP|FOUR|TIERS|...',
  `entity_id` BIGINT UNSIGNED NULL,
  `societe_id` INT UNSIGNED NULL,
  `agence_id` INT UNSIGNED NULL,
  `service_id` INT UNSIGNED NULL,
  `gdrive_folder_id` VARCHAR(120) NULL COMMENT 'ID Google Drive (si stockage Drive)',
  `position` INT NOT NULL DEFAULT 0 COMMENT 'Ordre d''affichage parmi les freres',
  `is_system` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = dossier systeme intouchable',
  `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NULL,
  `updated_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ged_folders_uuid` (`uuid`),
  UNIQUE KEY `uk_ged_folders_parent_slug` (`parent_id`, `slug`),
  INDEX `idx_ged_folders_tenant_parent` (`tenant_id`, `parent_id`),
  INDEX `idx_ged_folders_tenant_societe` (`tenant_id`, `societe_id`),
  INDEX `idx_ged_folders_tenant_agence` (`tenant_id`, `agence_id`),
  INDEX `idx_ged_folders_module` (`module`),
  INDEX `idx_ged_folders_entity` (`entity_type`, `entity_id`),
  INDEX `idx_ged_folders_path_cache` (`path_cache`(255)),
  INDEX `idx_ged_folders_archived` (`is_archived`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ged_folder_levels` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NULL,
  `module` VARCHAR(50) NOT NULL COMMENT 'Module concerne (DIRECTION, SYNDIC, ...)',
  `level_number` TINYINT UNSIGNED NOT NULL COMMENT '1 a 6',
  `level_name` VARCHAR(80) NOT NULL COMMENT 'Metier / Domaine / Type / Entite / Annee / Mois',
  `description` VARCHAR(255) NULL,
  `is_required` TINYINT(1) NOT NULL DEFAULT 0,
  `is_editable` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ged_folder_levels` (`tenant_id`, `module`, `level_number`),
  INDEX `idx_ged_folder_levels_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ged_folder_templates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NULL,
  `module` VARCHAR(50) NOT NULL,
  `code` VARCHAR(80) NOT NULL COMMENT 'Identifiant stable du template',
  `name` VARCHAR(180) NOT NULL,
  `description` TEXT NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ged_folder_templates_code` (`tenant_id`, `module`, `code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ged_folder_template_nodes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_id` BIGINT UNSIGNED NOT NULL,
  `parent_node_id` BIGINT UNSIGNED NULL,
  `depth` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `slug` VARCHAR(120) NOT NULL,
  `name_display` VARCHAR(255) NOT NULL,
  `position` INT NOT NULL DEFAULT 0,
  `is_required` TINYINT(1) NOT NULL DEFAULT 1,
  `attributes_json` TEXT NULL COMMENT 'Conditions, variables (annee, mois, entite...)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_ged_folder_template_nodes_tpl` (`template_id`),
  INDEX `idx_ged_folder_template_nodes_parent` (`parent_node_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];
