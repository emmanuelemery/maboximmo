<?php
/**
 * Migration : DÉCLENCHEURS métier — objet de premier niveau.
 *
 * Un déclencheur = un événement qui ARRIVE dans l'agence (préavis reçu, sinistre,
 * demande d'estimation…) et qui ouvre une ou plusieurs MISSIONS.
 * C'est l'amont de toute l'automatisation : le collaborateur ne « crée » pas une
 * mission, il reçoit un événement et MBI enclenche le travail.
 *
 *  - pilotage_declencheurs         : le référentiel des événements déclencheurs.
 *  - pilotage_declencheur_missions : liaison déclencheur → mission(s) ouverte(s).
 *
 * Isolation id_societe. Idempotent.
 */
return [
    'id'          => '20260720a_pilotage_declencheurs',
    'title'       => 'Déclencheurs métier : référentiel + liaison vers missions',
    'description' => "pilotage_declencheurs + pilotage_declencheur_missions.",
    'created_at'  => '2026-07-20',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `pilotage_declencheurs` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `id_societe`    INT NOT NULL,
  `service_slug`  VARCHAR(40) NOT NULL DEFAULT 'gestion',
  `label`         VARCHAR(200) NOT NULL,
  `slug`          VARCHAR(200) NULL,
  `icon`          VARCHAR(16) NULL,
  `description`   TEXT NULL,
  `example_text`  TEXT NULL,
  `source_canal`  VARCHAR(60) NULL,
  `status`        ENUM('propose','confirme','archive') NOT NULL DEFAULT 'propose',
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `display_order` INT NOT NULL DEFAULT 0,
  `created_by`    INT NULL,
  `created_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NULL,
  INDEX `idx_soc` (`id_societe`),
  INDEX `idx_service` (`service_slug`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pilotage_declencheur_missions` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `id_societe`     INT NOT NULL,
  `declencheur_id` INT NOT NULL,
  `task_id`        INT NULL,
  `label_libre`    VARCHAR(200) NULL,
  `detail`         TEXT NULL,
  `display_order`  INT NOT NULL DEFAULT 0,
  `created_by`     INT NULL,
  `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NULL,
  INDEX `idx_decl` (`declencheur_id`),
  INDEX `idx_task` (`task_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
];
