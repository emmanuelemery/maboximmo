<?php
/**
 * Migration : Pilotage métier — affectation par POSTE + versioning tricolore.
 *
 *  - pilotage_task_postes    : affectation canonique d'une mission à un POSTE
 *                              (indépendant des personnes réelles). Le référentiel
 *                              propose ainsi une affectation même sans collaborateur.
 *  - pilotage_poste_mapping  : correspondance poste → compte MBI réel (user_id),
 *                              confirmée par l'admin. Pas de résolution floue par prénom.
 *  - pilotage_task_versions  : snapshot d'une mission VERTE avant modification
 *                              (conserve la version validée ; la nouvelle repasse en jaune).
 *
 * Idempotente (CREATE TABLE IF NOT EXISTS).
 */
return [
    'id'          => '20260717d_pilotage_postes',
    'title'       => 'Pilotage métier : postes canoniques + mapping poste→user + versions',
    'description' => "Affectation par poste, correspondance manuelle vers comptes MBI réels, versioning tricolore.",
    'created_at'  => '2026-07-17',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `pilotage_task_postes` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `id_societe`      INT NOT NULL,
  `task_id`         INT NOT NULL,
  `poste_code`      VARCHAR(60) NOT NULL,
  `assignment_role` ENUM('executor','primary_responsible','validator','supervisor','backup','informed') NOT NULL DEFAULT 'executor',
  `is_primary`      TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`      DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_task_poste` (`task_id`,`poste_code`,`assignment_role`),
  INDEX `idx_task` (`task_id`),
  INDEX `idx_poste` (`poste_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pilotage_poste_mapping` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `id_societe` INT NOT NULL,
  `poste_code` VARCHAR(60) NOT NULL,
  `user_id`    INT NOT NULL,
  `updated_by` INT NULL,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_map` (`id_societe`,`poste_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pilotage_task_versions` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `id_societe`     INT NOT NULL,
  `task_id`        INT NOT NULL,
  `version_number` INT NOT NULL DEFAULT 1,
  `documentation_status` VARCHAR(10) NULL,
  `snapshot_json`  MEDIUMTEXT NOT NULL,
  `created_by`     INT NULL,
  `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_task` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
];
