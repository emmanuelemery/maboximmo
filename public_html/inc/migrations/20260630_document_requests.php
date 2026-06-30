<?php
/**
 * Migration : « Demande de document » — lien de dépôt sécurisé.
 *
 * 3 tables :
 *  - document_request_templates : modèles réutilisables (candidat locataire, projet salaires…).
 *  - document_requests          : une demande envoyée (token, destinataire, échéance, rappels).
 *  - document_request_items     : une pièce par carte de dépôt (type, rattachement, statut).
 *
 * Les modèles par défaut sont seedés par le helper inc/document_requests.php (dr_seed_templates),
 * pour éviter d'échapper du JSON à apostrophes dans le SQL.
 *
 * Idempotente (CREATE TABLE IF NOT EXISTS).
 */
return [
    'id'          => '20260630_document_requests',
    'title'       => 'Demande de document : lien de dépôt sécurisé (3 tables)',
    'description' => "Crée document_request_templates / document_requests / document_request_items.",
    'created_at'  => '2026-06-30',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `document_request_templates` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `code`        VARCHAR(60) NOT NULL,
  `nom`         VARCHAR(150) NOT NULL,
  `description` VARCHAR(500) NULL,
  `audience`    VARCHAR(40) NULL,
  `items_json`  LONGTEXT NULL,
  `actif`       TINYINT(1) NOT NULL DEFAULT 1,
  `created_by`  INT NULL,
  `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_code` (`code`)
);

CREATE TABLE IF NOT EXISTS `document_requests` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `token`            VARCHAR(64) NOT NULL,
  `template_code`    VARCHAR(60) NULL,
  `titre`            VARCHAR(200) NOT NULL,
  `message`          TEXT NULL,
  `recipient_email`  VARCHAR(190) NOT NULL,
  `recipient_name`   VARCHAR(190) NULL,
  `entity_type`      VARCHAR(20) NULL,
  `entity_id`        INT NULL,
  `societe_id`       INT NULL,
  `agence_id`        INT NULL,
  `created_by`       INT NULL,
  `status`           ENUM('en_attente','partiel','complet','expire','revoque') NOT NULL DEFAULT 'en_attente',
  `require_email_gate` TINYINT(1) NOT NULL DEFAULT 1,
  `expires_at`       DATETIME NULL,
  `reminder_mode`    ENUM('none','once','recurring') NOT NULL DEFAULT 'none',
  `reminder_first_at` DATETIME NULL,
  `reminder_interval_days` INT NULL,
  `last_reminded_at` DATETIME NULL,
  `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP,
  `completed_at`     DATETIME NULL,
  UNIQUE KEY `uniq_token` (`token`),
  INDEX `idx_status` (`status`),
  INDEX `idx_created_by` (`created_by`)
);

CREATE TABLE IF NOT EXISTS `document_request_items` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `request_id`      INT NOT NULL,
  `label`           VARCHAR(200) NOT NULL,
  `doc_type`        VARCHAR(60) NULL,
  `kind`            ENUM('file','files','text','photos') NOT NULL DEFAULT 'file',
  `max_files`       INT NOT NULL DEFAULT 1,
  `text_value`      TEXT NULL,
  `entity_type`     VARCHAR(20) NULL,
  `entity_id`       INT NULL,
  `period`          VARCHAR(20) NULL,
  `required`        TINYINT(1) NOT NULL DEFAULT 1,
  `status`          ENUM('en_attente','recu') NOT NULL DEFAULT 'en_attente',
  `ged_document_id` INT NULL,
  `original_name`   VARCHAR(255) NULL,
  `received_at`     DATETIME NULL,
  `sort_order`      INT NOT NULL DEFAULT 0,
  INDEX `idx_request` (`request_id`),
  INDEX `idx_status` (`status`)
);
SQL
];
