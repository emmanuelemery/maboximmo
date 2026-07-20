<?php
/**
 * Migration : PILOTAGE MÉTIER — socle du référentiel des missions par service.
 *
 * Module « Service Location » (V1) conçu multi-services (Gestion, Syndic,
 * Transaction, Compta, Direction, RH, Accueil, Technique) dès l'origine.
 *
 * Isolation multi-tenant : id_societe (+ id_agence optionnel) — convention MBI
 * existante (PAS de tenant_id/company_id).
 *
 * Vocabulaire :
 *   - pilotage_services / pilotage_categories : arborescence métier.
 *   - pilotage_tasks              : MISSION métier (responsabilité récurrente).
 *   - pilotage_task_assignments   : QUI exécute / supervise / valide / remplace.
 *   - pilotage_task_checklist_items / pilotage_task_steps : procédure & contrôles.
 *   - pilotage_task_resources     : liens GED / pages MBI / modèles.
 *   - pilotage_task_legal_rules   : règles juridiques (jaune tant que non validées).
 *   - pilotage_task_instances     : ACTION réelle (occurrence liée à un dossier).
 *   - pilotage_task_history       : journal des modifications / validations.
 *
 * Statut documentaire : red | yellow | green (tricolore MBI).
 * Automatisation      : manual | assisted | partially_automated | automated.
 *
 * Idempotente (CREATE TABLE IF NOT EXISTS). Le seed du référentiel Location est
 * dans inc/pilotage_seed.php (pilotage_seed_referentiel()) — appelé par
 * admin/pilotage_seed.php, idempotent par slug.
 */
return [
    'id'          => '20260717c_pilotage_metier',
    'title'       => 'Pilotage métier : socle référentiel missions (10 tables pilotage_*)',
    'description' => "Services / catégories / missions / affectations / procédure / checklist / ressources / juridique / instances / historique.",
    'created_at'  => '2026-07-17',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `pilotage_services` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `id_societe`    INT NOT NULL,
  `name`          VARCHAR(120) NOT NULL,
  `slug`          VARCHAR(80) NOT NULL,
  `description`   VARCHAR(500) NULL,
  `color`         VARCHAR(16) NULL,
  `icon`          VARCHAR(16) NULL,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `display_order` INT NOT NULL DEFAULT 0,
  `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NULL,
  UNIQUE KEY `uniq_service` (`id_societe`,`slug`),
  INDEX `idx_soc` (`id_societe`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pilotage_categories` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `id_societe`    INT NOT NULL,
  `service_id`    INT NOT NULL,
  `name`          VARCHAR(150) NOT NULL,
  `slug`          VARCHAR(100) NOT NULL,
  `description`   VARCHAR(500) NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NULL,
  UNIQUE KEY `uniq_cat` (`id_societe`,`service_id`,`slug`),
  INDEX `idx_service` (`service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pilotage_tasks` (
  `id`                        INT AUTO_INCREMENT PRIMARY KEY,
  `id_societe`                INT NOT NULL,
  `service_id`                INT NOT NULL,
  `category_id`               INT NULL,
  `name`                      VARCHAR(200) NOT NULL,
  `slug`                      VARCHAR(160) NOT NULL,
  `short_description`         VARCHAR(500) NULL,
  `objective`                 TEXT NULL,
  `required_level`            VARCHAR(60) NULL,
  `frequency_type`            ENUM('daily','weekly','monthly','quarterly','yearly','event_based','on_demand','continuous') NOT NULL DEFAULT 'on_demand',
  `frequency_value`           VARCHAR(80) NULL,
  `trigger_event`             VARCHAR(200) NULL,
  `estimated_duration_minutes` INT NULL,
  `priority_level`            ENUM('low','normal','high','critical') NOT NULL DEFAULT 'normal',
  `documentation_status`      ENUM('red','yellow','green') NOT NULL DEFAULT 'red',
  `automation_level`          ENUM('manual','assisted','partially_automated','automated') NOT NULL DEFAULT 'manual',
  `procedure_text`            MEDIUMTEXT NULL,
  `legal_notes`               TEXT NULL,
  `accounting_notes`          TEXT NULL,
  `errors_to_avoid`           TEXT NULL,
  `internal_notes`            TEXT NULL,
  `ged_entity_type`           VARCHAR(20) NULL,
  `default_doc_template`      VARCHAR(60) NULL,
  `is_active`                 TINYINT(1) NOT NULL DEFAULT 1,
  `display_order`             INT NOT NULL DEFAULT 0,
  `created_by`                INT NULL,
  `updated_by`                INT NULL,
  `validated_by`              INT NULL,
  `validated_at`              DATETIME NULL,
  `created_at`                DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                DATETIME NULL,
  UNIQUE KEY `uniq_task` (`id_societe`,`slug`),
  INDEX `idx_service` (`service_id`),
  INDEX `idx_cat` (`category_id`),
  INDEX `idx_docstatus` (`documentation_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pilotage_task_assignments` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `id_societe`      INT NOT NULL,
  `task_id`         INT NOT NULL,
  `user_id`         INT NOT NULL,
  `assignment_role` ENUM('executor','primary_responsible','validator','supervisor','backup','informed') NOT NULL DEFAULT 'executor',
  `is_primary`      TINYINT(1) NOT NULL DEFAULT 0,
  `start_date`      DATE NULL,
  `end_date`        DATE NULL,
  `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NULL,
  UNIQUE KEY `uniq_assign` (`task_id`,`user_id`,`assignment_role`),
  INDEX `idx_task` (`task_id`),
  INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pilotage_task_checklist_items` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `id_societe`    INT NOT NULL,
  `task_id`       INT NOT NULL,
  `label`         VARCHAR(300) NOT NULL,
  `description`   VARCHAR(500) NULL,
  `is_mandatory`  TINYINT(1) NOT NULL DEFAULT 1,
  `display_order` INT NOT NULL DEFAULT 0,
  `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NULL,
  INDEX `idx_task` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pilotage_task_steps` (
  `id`                     INT AUTO_INCREMENT PRIMARY KEY,
  `id_societe`             INT NOT NULL,
  `task_id`                INT NOT NULL,
  `step_number`            INT NOT NULL DEFAULT 1,
  `title`                  VARCHAR(200) NOT NULL,
  `description`            TEXT NULL,
  `relative_deadline_value` INT NULL,
  `relative_deadline_unit` VARCHAR(20) NULL,
  `responsible_role`       VARCHAR(60) NULL,
  `requires_validation`    TINYINT(1) NOT NULL DEFAULT 0,
  `display_order`          INT NOT NULL DEFAULT 0,
  `created_at`             DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             DATETIME NULL,
  INDEX `idx_task` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pilotage_task_resources` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `id_societe`      INT NOT NULL,
  `task_id`         INT NOT NULL,
  `resource_type`   ENUM('ged_document','document_template','internal_page','external_reference','video','form','email_template','upload_link_template') NOT NULL DEFAULT 'internal_page',
  `title`           VARCHAR(200) NOT NULL,
  `url`             VARCHAR(500) NULL,
  `ged_document_id` INT NULL,
  `template_id`     VARCHAR(60) NULL,
  `page_route`      VARCHAR(200) NULL,
  `description`     VARCHAR(500) NULL,
  `display_order`   INT NOT NULL DEFAULT 0,
  `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NULL,
  INDEX `idx_task` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pilotage_task_legal_rules` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `id_societe`        INT NOT NULL,
  `task_id`           INT NOT NULL,
  `title`             VARCHAR(200) NOT NULL,
  `summary`           TEXT NULL,
  `legal_reference`   VARCHAR(300) NULL,
  `source_url`        VARCHAR(500) NULL,
  `effective_date`    DATE NULL,
  `expiry_date`       DATE NULL,
  `validation_status` ENUM('red','yellow','green') NOT NULL DEFAULT 'yellow',
  `validated_by`      INT NULL,
  `validated_at`      DATETIME NULL,
  `created_at`        DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NULL,
  INDEX `idx_task` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pilotage_task_instances` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `id_societe`      INT NOT NULL,
  `id_agence`       INT NULL,
  `task_id`         INT NOT NULL,
  `assigned_user_id` INT NULL,
  `entity_type`     VARCHAR(20) NULL,
  `entity_id`       INT NULL,
  `title`           VARCHAR(250) NOT NULL,
  `description`     TEXT NULL,
  `period_label`    VARCHAR(40) NULL,
  `due_at`          DATETIME NULL,
  `visible_from`    DATETIME NULL,
  `started_at`      DATETIME NULL,
  `completed_at`    DATETIME NULL,
  `status`          ENUM('pending','in_progress','waiting','completed','cancelled','overdue','a_preparer','en_cours','a_completer','soumis','a_corriger','valide_definitif') NOT NULL DEFAULT 'pending',
  `priority`        ENUM('low','normal','high','critical') NOT NULL DEFAULT 'normal',
  `source_type`     VARCHAR(40) NULL,
  `source_id`       INT NULL,
  `dedup_key`       VARCHAR(160) NULL,
  `created_by`      INT NULL,
  `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NULL,
  UNIQUE KEY `uniq_dedup` (`id_societe`,`dedup_key`),
  INDEX `idx_task` (`task_id`),
  INDEX `idx_user` (`assigned_user_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_due` (`due_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pilotage_task_history` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `id_societe`  INT NOT NULL,
  `task_id`     INT NOT NULL,
  `action`      VARCHAR(60) NOT NULL,
  `field`       VARCHAR(60) NULL,
  `old_value`   TEXT NULL,
  `new_value`   TEXT NULL,
  `reason`      VARCHAR(500) NULL,
  `user_id`     INT NULL,
  `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_task` (`task_id`),
  INDEX `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
];
