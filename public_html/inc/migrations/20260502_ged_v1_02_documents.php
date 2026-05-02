<?php
/**
 * Migration : Ma GED Box V1 — Documents (documents + links + relations)
 *
 * Crée les tables de stockage des documents (BDD = source de vérité,
 * Drive = stockage physique). Inclut les liens polymorphes vers entités
 * MaBoxImmo (immeubles / biens / mandats / tiers / etc.) et les relations
 * inter-documents (versionning, parent/enfant, dérivés).
 *
 * Tables créées :
 *   - ged_documents          : métadonnées + nom humain/machine + Drive
 *   - ged_document_links     : liens polymorphes doc ↔ entité métier
 *   - ged_document_relations : relations inter-documents (version, dérivé)
 *
 * IMPORTANT : ne touche à AUCUNE table existante. AJOUT uniquement.
 */

return [
    'id'          => '20260502_ged_v1_02_documents',
    'title'       => 'Ma GED Box V1 — documents + liens polymorphes + relations inter-documents',
    'description' => "Crée 3 tables : ged_documents (uuid, folder_id, name_display + name_canonical + name_file, document_type, source_module, storage_provider + gdrive_file_id, hash_sha256, search_vector FULLTEXT, metadata JSON, linked_entities JSON, security_level, status, version, parent_document_id), ged_document_links (liens polymorphes doc ↔ entité métier), ged_document_relations (versionning, dérivé). AJOUT uniquement.",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `ged_documents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `tenant_id` INT UNSIGNED NULL,
  `folder_id` BIGINT UNSIGNED NULL COMMENT 'FK ged_folders (NULL si non encore range)',
  `societe_id` INT UNSIGNED NULL,
  `agence_id` INT UNSIGNED NULL,
  `service_id` INT UNSIGNED NULL,
  `name_display` VARCHAR(255) NOT NULL COMMENT 'Nom humain modifiable',
  `name_canonical` VARCHAR(255) NOT NULL COMMENT 'Nom machine stable',
  `name_file` VARCHAR(255) NOT NULL COMMENT 'Nom physique sur disque/Drive (avec extension)',
  `document_type` VARCHAR(60) NULL COMMENT 'FACTURE | RIB | BAIL | MANDAT | DPE | MAIL | ...',
  `source_module` VARCHAR(50) NULL COMMENT 'Module metier d''origine (DIRECTION, SYNDIC, ...)',
  `storage_provider` ENUM('local','google_drive','onedrive','s3') NOT NULL DEFAULT 'local',
  `gdrive_file_id` VARCHAR(120) NULL,
  `mime_type` VARCHAR(100) NULL,
  `size_bytes` BIGINT UNSIGNED NULL,
  `hash_sha256` CHAR(64) NULL COMMENT 'Deduplication',
  `search_vector` TEXT NULL COMMENT 'Texte indexe (FULLTEXT)',
  `metadata` JSON NULL,
  `linked_entities` JSON NULL COMMENT 'Snapshot rapide des entites liees (lecture rapide)',
  `security_level` ENUM('public','interne','confidentiel','coffre') NOT NULL DEFAULT 'interne',
  `status` ENUM('draft','active','archived','deleted','quarantine') NOT NULL DEFAULT 'active',
  `version` INT UNSIGNED NOT NULL DEFAULT 1,
  `parent_document_id` BIGINT UNSIGNED NULL COMMENT 'Version anterieure ou doc maitre',
  `created_by` INT UNSIGNED NULL,
  `updated_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ged_documents_uuid` (`uuid`),
  INDEX `idx_ged_documents_tenant_folder` (`tenant_id`, `folder_id`),
  INDEX `idx_ged_documents_tenant_societe` (`tenant_id`, `societe_id`),
  INDEX `idx_ged_documents_tenant_agence` (`tenant_id`, `agence_id`),
  INDEX `idx_ged_documents_module` (`source_module`),
  INDEX `idx_ged_documents_type` (`document_type`),
  INDEX `idx_ged_documents_hash` (`hash_sha256`),
  INDEX `idx_ged_documents_parent` (`parent_document_id`),
  INDEX `idx_ged_documents_status` (`status`),
  FULLTEXT KEY `ft_ged_documents_search` (`search_vector`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ged_document_links` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NULL,
  `document_id` BIGINT UNSIGNED NOT NULL,
  `entity_type` VARCHAR(40) NOT NULL COMMENT 'IMB | BIEN | MDT | CTX | EMP | FOUR | TIERS | SDC | ...',
  `entity_id` BIGINT UNSIGNED NOT NULL,
  `relation_type` VARCHAR(40) NOT NULL DEFAULT 'main' COMMENT 'main | annexe | reference | piece_jointe',
  `confidence` DECIMAL(4,3) NULL COMMENT 'Score IA (0.000 a 1.000) si suggere',
  `is_validated` TINYINT(1) NOT NULL DEFAULT 0,
  `validated_by` INT UNSIGNED NULL,
  `validated_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ged_document_links` (`document_id`, `entity_type`, `entity_id`, `relation_type`),
  INDEX `idx_ged_document_links_tenant` (`tenant_id`),
  INDEX `idx_ged_document_links_entity` (`entity_type`, `entity_id`),
  INDEX `idx_ged_document_links_validated` (`is_validated`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ged_document_relations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NULL,
  `from_document_id` BIGINT UNSIGNED NOT NULL,
  `to_document_id` BIGINT UNSIGNED NOT NULL,
  `relation_type` ENUM('previous_version','next_version','derived_from','attachment_of','reply_of','reference') NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ged_document_relations` (`from_document_id`, `to_document_id`, `relation_type`),
  INDEX `idx_ged_document_relations_to` (`to_document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];
