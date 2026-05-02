<?php
/**
 * Migration : Ma GED Box V1 — Tags + Index recherche
 *
 * Crée :
 *   - ged_tags          : referentiel de tags reutilisables (par tenant)
 *   - ged_document_tags : association doc <-> tag (N-N)
 *   - ged_index         : index plat pour recherche rapide
 *                         (key/value, ex. annee=2024, fournisseur=ORANGE)
 *
 * IMPORTANT : ne touche à AUCUNE table existante. AJOUT uniquement.
 */

return [
    'id'          => '20260502_ged_v1_03_tags_index',
    'title'       => 'Ma GED Box V1 — tags + association doc/tag + index recherche key/value',
    'description' => "Crée 3 tables : ged_tags (référentiel tags par tenant, slug + label + couleur), ged_document_tags (N-N doc/tag), ged_index (key/value plat pour recherche : annee=2024, fournisseur=ORANGE, montant=150.00…). AJOUT uniquement.",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `ged_tags` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NULL,
  `slug` VARCHAR(80) NOT NULL,
  `label` VARCHAR(120) NOT NULL,
  `color` VARCHAR(20) NULL COMMENT 'Hex #RRGGBB ou nom de couleur',
  `description` VARCHAR(255) NULL,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ged_tags_slug` (`tenant_id`, `slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ged_document_tags` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NULL,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `tag_id` BIGINT UNSIGNED NOT NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ged_document_tags` (`document_id`, `tag_id`),
  INDEX `idx_ged_document_tags_tag` (`tag_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ged_index` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NULL,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `index_key` VARCHAR(80) NOT NULL COMMENT 'annee | mois | fournisseur | montant | locataire | ...',
  `index_value` VARCHAR(255) NOT NULL,
  `index_value_num` DECIMAL(18,4) NULL COMMENT 'Si la valeur est numerique (montant, annee...)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_ged_index_doc` (`document_id`),
  INDEX `idx_ged_index_key_value` (`index_key`, `index_value`),
  INDEX `idx_ged_index_key_num` (`index_key`, `index_value_num`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];
