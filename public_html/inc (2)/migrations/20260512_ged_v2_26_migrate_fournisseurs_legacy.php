<?php
/**
 * Migration v2.26 — Audit + soft-archive des données legacy FOURNISSEURS
 *
 * Suite de _v2_25_complete_seeds (arbitrage EMERY 2026-05-12).
 *
 * Décision : on NE déplace PAS physiquement les docs/folders existants
 * (risque sur path_cache, linked_entities snapshots, callers UI legacy).
 * On marque les zones obsolètes en `is_archived=1` et on créé un journal
 * d'audit lisible via une vue temporaire.
 *
 * Actions :
 *   1. Soft-archive des ged_folders dont le path_cache contient FOURNISSEURS_AGENCE
 *      → is_archived=1, garde l'historique
 *   2. Crée la table `ged_fournisseurs_legacy_audit` (one-shot) pour tracer
 *      les docs/folders qui devraient être reclassés vers 13_FOURNISSEURS
 *   3. Peuple cette table avec les candidats (folders + docs)
 *      → permet à l'admin de voir d'un coup d'œil le volume à reclasser
 *
 * Idempotent. Aucune suppression. Réversible (UPDATE is_archived=0).
 */

return [
    'id'          => '20260512_ged_v2_26_migrate_fournisseurs_legacy',
    'title'       => 'Ma GED Box V2.26 — audit + soft-archive legacy FOURNISSEURS_AGENCE',
    'description' => "Crée une table d'audit ged_fournisseurs_legacy_audit listant les folders + docs encore classés sous FOURNISSEURS_AGENCE (01_AGENCE) ou avec des placeholders FOURNISSEUR (GE/SY) et soft-archive les folders obsolètes (is_archived=1). Aucun déplacement physique : la reclassification vers 13_FOURNISSEURS se fait manuellement via UI quand c'est pertinent. Idempotent et réversible.",
    'created_at'  => '2026-05-12',
    'sql' => <<<'SQL'
-- ════════════════════════════════════════════════════════════════════════
-- 1. Table d'audit (one-shot, lisible via super_admin_ged_niveaux UI)
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `ged_fournisseurs_legacy_audit` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NULL,
  `kind` ENUM('folder','document','document_link') NOT NULL,
  `legacy_id` BIGINT UNSIGNED NOT NULL COMMENT 'id ged_folders ou ged_documents ou ged_document_links',
  `legacy_path` VARCHAR(2048) NULL COMMENT 'path_cache ou name_canonical au moment du snapshot',
  `legacy_module` VARCHAR(50) NULL,
  `legacy_entity_type` VARCHAR(40) NULL,
  `legacy_entity_id` BIGINT UNSIGNED NULL,
  `reason` VARCHAR(100) NOT NULL COMMENT 'fournisseurs_agence | placeholder_fournisseur_ge | placeholder_fournisseur_sy',
  `status` ENUM('todo','reviewed','reclassified','keep_as_is','ignored') NOT NULL DEFAULT 'todo',
  `reviewed_by` INT UNSIGNED NULL,
  `reviewed_at` DATETIME NULL,
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ged_fournisseurs_legacy_audit` (`kind`, `legacy_id`, `reason`),
  INDEX `idx_ged_fournisseurs_legacy_audit_status` (`status`),
  INDEX `idx_ged_fournisseurs_legacy_audit_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════
-- 2. Audit ged_folders : sous FOURNISSEURS_AGENCE (path_cache match)
-- ════════════════════════════════════════════════════════════════════════
INSERT IGNORE INTO `ged_fournisseurs_legacy_audit`
  (`tenant_id`, `kind`, `legacy_id`, `legacy_path`, `legacy_module`, `legacy_entity_type`, `legacy_entity_id`, `reason`)
SELECT
  `tenant_id`, 'folder', `id`, `path_cache`, `module`, `entity_type`, `entity_id`,
  'fournisseurs_agence'
FROM `ged_folders`
WHERE `path_cache` LIKE '%fournisseurs-agence%'
   OR `path_cache` LIKE '%FOURNISSEURS_AGENCE%'
   OR (`name_canonical` = 'FOURNISSEURS_AGENCE' OR `name_canonical` LIKE '%fournisseurs_agence%');

-- Audit ged_folders : sous placeholders FOURNISSEUR dans GE
INSERT IGNORE INTO `ged_fournisseurs_legacy_audit`
  (`tenant_id`, `kind`, `legacy_id`, `legacy_path`, `legacy_module`, `legacy_entity_type`, `legacy_entity_id`, `reason`)
SELECT
  `tenant_id`, 'folder', `id`, `path_cache`, `module`, `entity_type`, `entity_id`,
  'placeholder_fournisseur_ge'
FROM `ged_folders`
WHERE `module` = 'GESTION_LOCATIVE'
  AND (`path_cache` LIKE '%/fournisseurs-interventions/%' OR `path_cache` LIKE '%FOURNISSEURS_INTERVENTIONS%')
  AND `entity_type` = 'FOUR';

-- Audit ged_folders : sous placeholders FOURNISSEUR dans SY
INSERT IGNORE INTO `ged_fournisseurs_legacy_audit`
  (`tenant_id`, `kind`, `legacy_id`, `legacy_path`, `legacy_module`, `legacy_entity_type`, `legacy_entity_id`, `reason`)
SELECT
  `tenant_id`, 'folder', `id`, `path_cache`, `module`, `entity_type`, `entity_id`,
  'placeholder_fournisseur_sy'
FROM `ged_folders`
WHERE `module` = 'SYNDIC'
  AND (`path_cache` LIKE '%/fournisseurs/%' OR `path_cache` LIKE '%IMMEUBLE%FOURNISSEURS%')
  AND `entity_type` = 'FOUR';

-- ════════════════════════════════════════════════════════════════════════
-- 3. Audit ged_documents : ceux liés à un folder déjà audité
-- ════════════════════════════════════════════════════════════════════════
INSERT IGNORE INTO `ged_fournisseurs_legacy_audit`
  (`tenant_id`, `kind`, `legacy_id`, `legacy_path`, `legacy_module`, `reason`)
SELECT
  d.`tenant_id`, 'document', d.`id`, d.`name_canonical`, d.`source_module`,
  a.`reason`
FROM `ged_documents` d
INNER JOIN `ged_fournisseurs_legacy_audit` a
  ON a.`kind` = 'folder' AND a.`legacy_id` = d.`folder_id`
WHERE d.`folder_id` IS NOT NULL
  AND d.`status` NOT IN ('deleted','archived');

-- ════════════════════════════════════════════════════════════════════════
-- 4. Audit ged_document_links : liens entity_type='FOUR' encore actifs
--    (pour avoir une vue exhaustive des docs "fournisseur" dans la BDD)
-- ════════════════════════════════════════════════════════════════════════
INSERT IGNORE INTO `ged_fournisseurs_legacy_audit`
  (`tenant_id`, `kind`, `legacy_id`, `legacy_entity_type`, `legacy_entity_id`, `reason`)
SELECT
  `tenant_id`, 'document_link', `id`, `entity_type`, `entity_id`,
  'link_entity_four'
FROM `ged_document_links`
WHERE `entity_type` IN ('FOUR', 'FOURNISSEUR');

-- ════════════════════════════════════════════════════════════════════════
-- 5. Soft-archive des folders FOURNISSEURS_AGENCE (n'apparaissent plus
--    dans la navigation mais conservés pour rollback / audit)
-- ════════════════════════════════════════════════════════════════════════
UPDATE `ged_folders` f
INNER JOIN `ged_fournisseurs_legacy_audit` a
  ON a.`kind` = 'folder' AND a.`legacy_id` = f.`id` AND a.`reason` = 'fournisseurs_agence'
SET f.`is_archived` = 1
WHERE f.`is_archived` = 0;

-- NB : on n'archive PAS les placeholders FOURNISSEUR sous GE/SY car ils
-- sont liés à des immeubles/propriétaires actifs. Soft-archive uniquement
-- pour le doublon strict 01_AGENCE > FOURNISSEURS_AGENCE.
SQL,
];
