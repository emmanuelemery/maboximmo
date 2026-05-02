<?php
/**
 * Migration v1.1 — Tables import GED (préclassement par lots)
 *
 * AJOUT uniquement. Ne touche pas à ged_folders, ged_documents, ged_document_links,
 * ged_index, ged_audit_log (tables existantes conservées telles quelles).
 *
 * Tables :
 *   - ged_import_batches : un batch = un upload utilisateur (n fichiers)
 *   - ged_import_items   : 1 ligne = 1 fichier dans le batch, avec sélection
 *                          en cours N1→N6 et nom canonique calculé.
 *
 * Workflow :
 *   1. Upload → INSERT batch + N items (status='imported')
 *   2. Cascade IA propose N1/N2/N3/N4/N5/N6 (score) → status='proposed'
 *   3. User clique pour ajuster → status reste 'proposed'
 *   4. Validate → crée ged_documents + ged_document_links → status='validated',
 *      created_document_id rempli
 *   5. Ignore → status='ignored'
 *
 * Idempotent (CREATE TABLE IF NOT EXISTS).
 */

return [
    'id'          => '20260502_ged_v1_09_import_tables',
    'title'       => 'Ma GED Box V1.1 — tables import (ged_import_batches + ged_import_items)',
    'description' => "Crée 2 tables pour le préclassement par lots : ged_import_batches (1 upload = 1 batch) et ged_import_items (1 fichier par ligne, avec sélection N1→N6 + name_canonical proposé + score + status). Permet la page super_admin_ged_import.php sans toucher aux tables ged_documents/folders/links existantes. Validation finale crée les ged_documents + ged_document_links et passe le statut à 'validated'.",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `ged_import_batches` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid` CHAR(36) NOT NULL,
  `tenant_id` INT UNSIGNED NULL,
  `batch_name` VARCHAR(180) NOT NULL,
  `source_type` ENUM('upload_files','upload_folder','drive_scan','manual') NOT NULL DEFAULT 'upload_files',
  `uploaded_by` INT UNSIGNED NULL,
  `nb_items` INT UNSIGNED NOT NULL DEFAULT 0,
  `nb_validated` INT UNSIGNED NOT NULL DEFAULT 0,
  `nb_ignored` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('open','closed','archived') NOT NULL DEFAULT 'open',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `validated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ged_import_batches_uuid` (`uuid`),
  INDEX `idx_ged_import_batches_status` (`status`),
  INDEX `idx_ged_import_batches_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ged_import_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `batch_id` BIGINT UNSIGNED NOT NULL,
  `tenant_id` INT UNSIGNED NULL,
  `old_folder_path` VARCHAR(1024) NULL COMMENT 'Chemin source (si upload dossier)',
  `old_filename` VARCHAR(255) NOT NULL,
  `file_extension` VARCHAR(20) NULL,
  `mime_type` VARCHAR(100) NULL,
  `size_bytes` BIGINT UNSIGNED NULL,
  `hash_sha256` CHAR(64) NULL COMMENT 'Pour deduplication',
  `storage_path` VARCHAR(500) NULL COMMENT 'Chemin disque temporaire du fichier upload',
  `detected_text_preview` TEXT NULL COMMENT 'Premiers 1-2 ko de texte extrait (PDF/image)',

  -- Sélection cascade N1 → N6
  `selected_n1` VARCHAR(80) NULL,
  `selected_n2` VARCHAR(80) NULL,
  `selected_n3` VARCHAR(80) NULL,
  `selected_n4` VARCHAR(80) NULL,
  `selected_n5` VARCHAR(80) NULL,
  `selected_n6` VARCHAR(120) NULL COMMENT 'Libre, pas de référentiel obligatoire',

  -- Métadonnées affichage + nom normalisé
  `title_user` VARCHAR(255) NULL COMMENT 'Titre humain modifiable',
  `name_display` VARCHAR(255) NULL,
  `name_canonical` VARCHAR(500) NULL COMMENT 'Format : N1_SOC_AGENCE_REF_NOM_N2_..._N6_TITRE_YYMM',
  `proposed_destination` VARCHAR(1024) NULL COMMENT 'Path GED MBI proposé (slugs/separated)',
  `final_destination` VARCHAR(1024) NULL COMMENT 'Path GED MBI choisi (après validation)',

  -- Pilotage
  `confidence_score` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0-100',
  `status` ENUM('imported','proposed','validated','to_review','ignored','error') NOT NULL DEFAULT 'imported',
  `error_message` TEXT NULL,
  `created_document_id` BIGINT UNSIGNED NULL COMMENT 'FK ged_documents.id si validé',

  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  INDEX `idx_ged_import_items_batch` (`batch_id`),
  INDEX `idx_ged_import_items_status` (`status`),
  INDEX `idx_ged_import_items_score` (`confidence_score`),
  INDEX `idx_ged_import_items_hash` (`hash_sha256`),
  INDEX `idx_ged_import_items_n1n2` (`selected_n1`, `selected_n2`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];
