<?php
/**
 * Migration : Pilotage métier — moteur d'AUTOMATISATIONS déclaratives + enrichissement
 * du modèle de mission (étapes à clé stable, checklists groupées, conditions d'entrée).
 *
 * Principe : les règles sont DÉCLARATIVES (trigger + condition JSON + action), jamais
 * du code PHP stocké en base. Une action juridiquement engageante n'est jamais exécutée
 * automatiquement : elle passe par exec_mode='validation_required'.
 *
 * Additif, idempotent (IF NOT EXISTS).
 */
return [
    'id'          => '20260717f_pilotage_automations',
    'title'       => 'Pilotage métier : automatisations déclaratives + modèle mission enrichi',
    'description' => "pilotage_task_automations + colonnes step_key/group_label/entry_conditions.",
    'created_at'  => '2026-07-17',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `pilotage_task_automations` (
  `id`                 INT AUTO_INCREMENT PRIMARY KEY,
  `id_societe`         INT NOT NULL,
  `source_task_id`     INT NOT NULL,
  `source_step_id`     INT NULL,
  `label`              VARCHAR(200) NULL,
  `trigger_type`       ENUM('task_started','step_started','step_completed','task_completed','status_changed','document_received','document_signed','form_submitted','deadline_reached','manual_trigger','field_changed') NOT NULL,
  `trigger_status`     VARCHAR(60) NULL,
  `condition_json`     TEXT NULL,
  `action_type`        ENUM('create_task_instance','create_step_instance','complete_task_instance','open_next_step','launch_other_mission','assign_user','send_notification','send_email','generate_document','create_upload_link','schedule_reminder','update_entity_status','add_fluxbox_item','open_validation_request') NOT NULL,
  `target_task_id`     INT NULL,
  `target_step_id`     INT NULL,
  `assigned_user_rule` VARCHAR(60) NULL,
  `due_date_rule`      VARCHAR(60) NULL,
  `delay_value`        INT NULL,
  `delay_unit`         ENUM('minute','hour','day','business_day','week','month') NULL,
  `action_title_template` VARCHAR(255) NULL,
  `exec_mode`          ENUM('informative','assisted','automatic','validation_required') NOT NULL DEFAULT 'assisted',
  `is_active`          TINYINT(1) NOT NULL DEFAULT 1,
  `display_order`      INT NOT NULL DEFAULT 0,
  `last_run_at`        DATETIME NULL,
  `last_run_status`    VARCHAR(40) NULL,
  `last_run_error`     VARCHAR(500) NULL,
  `created_by`         INT NULL,
  `created_at`         DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         DATETIME NULL,
  INDEX `idx_src_task` (`source_task_id`),
  INDEX `idx_trigger` (`trigger_type`,`trigger_status`),
  INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pilotage_automation_runs` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `id_societe`    INT NOT NULL,
  `automation_id` INT NOT NULL,
  `instance_id`   INT NULL,
  `status`        VARCHAR(40) NOT NULL,
  `message`       VARCHAR(500) NULL,
  `triggered_by`  INT NULL,
  `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_auto` (`automation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `pilotage_task_steps`
  ADD COLUMN IF NOT EXISTS `step_key` VARCHAR(60) NULL AFTER `task_id`,
  ADD COLUMN IF NOT EXISTS `expected_result` TEXT NULL AFTER `description`,
  ADD COLUMN IF NOT EXISTS `automation_note` VARCHAR(500) NULL AFTER `expected_result`;

ALTER TABLE `pilotage_task_checklist_items`
  ADD COLUMN IF NOT EXISTS `group_label` VARCHAR(80) NULL AFTER `task_id`,
  ADD COLUMN IF NOT EXISTS `auto_source` VARCHAR(40) NULL AFTER `is_mandatory`;

ALTER TABLE `pilotage_tasks`
  ADD COLUMN IF NOT EXISTS `entry_conditions` TEXT NULL AFTER `expected_result`;
SQL
];
