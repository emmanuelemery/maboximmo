<?php
/**
 * Migration : Ma GED Box V1 — Sécurité, audit, rétention, sync
 *
 * Tables créées :
 *   - ged_permissions    : ACL doc/folder par user/role
 *   - ged_audit_log      : journalisation toute action GED (create, update,
 *                          archive, link, unlink, view, download, …)
 *   - ged_retention_rules: règles de rétention/purge par type de doc
 *   - ged_arbo_sync_log  : trace des syncs Drive ↔ BDD (recalc, resync…)
 *
 * IMPORTANT : ne touche à AUCUNE table existante. AJOUT uniquement.
 */

return [
    'id'          => '20260502_ged_v1_04_security_audit',
    'title'       => 'Ma GED Box V1 — permissions + audit_log + retention + arbo_sync_log',
    'description' => "Crée 4 tables : ged_permissions (ACL doc/folder par user/role/scope, R/W/D/A), ged_audit_log (journal complet : actor, action, target_type, target_id, before_json, after_json, ip, user_agent), ged_retention_rules (règles de purge par type de doc), ged_arbo_sync_log (trace recalc path_cache + resync Drive). AJOUT uniquement.",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `ged_permissions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NULL,
  `target_type` ENUM('folder','document','tag','module') NOT NULL,
  `target_id` BIGINT UNSIGNED NOT NULL,
  `subject_type` ENUM('user','role','agence','societe','service') NOT NULL,
  `subject_id` INT UNSIGNED NULL COMMENT 'NULL pour role generique',
  `subject_role_code` VARCHAR(40) NULL COMMENT 'Pour subject_type = role',
  `permissions` SET('read','write','delete','admin','share') NOT NULL DEFAULT 'read',
  `granted_by` INT UNSIGNED NULL,
  `granted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_ged_perms_target` (`target_type`, `target_id`),
  INDEX `idx_ged_perms_subject` (`subject_type`, `subject_id`),
  INDEX `idx_ged_perms_tenant` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ged_audit_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NULL,
  `actor_id` INT UNSIGNED NULL COMMENT 'User a l''origine',
  `actor_role` VARCHAR(40) NULL,
  `action` VARCHAR(60) NOT NULL COMMENT 'create_folder | update_folder | archive_folder | create_doc | update_doc | move_doc | link_doc | unlink_doc | view_doc | download_doc | recalc_tree | resync_drive | ...',
  `target_type` VARCHAR(40) NULL,
  `target_id` BIGINT UNSIGNED NULL,
  `before_json` TEXT NULL,
  `after_json` TEXT NULL,
  `message` VARCHAR(500) NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_ged_audit_actor` (`actor_id`),
  INDEX `idx_ged_audit_target` (`target_type`, `target_id`),
  INDEX `idx_ged_audit_action` (`action`),
  INDEX `idx_ged_audit_tenant_date` (`tenant_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ged_retention_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NULL,
  `code` VARCHAR(80) NOT NULL,
  `name` VARCHAR(180) NOT NULL,
  `description` TEXT NULL,
  `document_type` VARCHAR(60) NULL,
  `module` VARCHAR(50) NULL,
  `min_keep_years` SMALLINT UNSIGNED NULL COMMENT 'Annees minimales de conservation',
  `max_keep_years` SMALLINT UNSIGNED NULL,
  `action_after_max` ENUM('archive','purge','flag_review') NOT NULL DEFAULT 'archive',
  `is_legal` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = obligation legale',
  `legal_reference` VARCHAR(255) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ged_retention_rules_code` (`tenant_id`, `code`),
  INDEX `idx_ged_retention_module` (`module`),
  INDEX `idx_ged_retention_doctype` (`document_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ged_arbo_sync_log` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NULL,
  `actor_id` INT UNSIGNED NULL,
  `sync_type` ENUM('recalc_path_cache','recalc_depth','resync_drive','rebuild_tree','seed','restore') NOT NULL,
  `scope` VARCHAR(180) NULL COMMENT 'Description courte du perimetre (tenant entier, sous-arbre, dossier X)',
  `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `finished_at` DATETIME NULL,
  `nb_folders_processed` INT UNSIGNED NOT NULL DEFAULT 0,
  `nb_errors` INT UNSIGNED NOT NULL DEFAULT 0,
  `error_log` TEXT NULL,
  `status` ENUM('running','done','error') NOT NULL DEFAULT 'running',
  PRIMARY KEY (`id`),
  INDEX `idx_ged_arbo_sync_log_tenant_date` (`tenant_id`, `started_at`),
  INDEX `idx_ged_arbo_sync_log_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];
