<?php
/**
 * Migration : queue jobs GED (traitements asynchrones fiables).
 *
 * Objectif :
 * - Remplacer les traitements "continue après fastcgi_finish_request()" (best-effort)
 *   par une queue BDD + cron worker relançable.
 * - Couvre en priorité : analyse IA avancée sur clic utilisateur.
 *
 * Design :
 * - Une ligne = un job.
 * - Claim/lock par `locked_until` + `status`.
 * - Idempotence via (job_type, analysis_id, status) gérée côté code (best-effort).
 */

return [
    'id'          => '20260502_ged_jobs_queue',
    'title'       => 'GED : queue de jobs asynchrones (ged_jobs)',
    'description' => "Ajoute une table `ged_jobs` pour exécuter de façon fiable les tâches GED lourdes (analyse IA avancée, etc.) via cron tokenisé.",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `ged_jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL,

  `job_type` VARCHAR(60) NOT NULL COMMENT 'ex: ged_analyze_advanced',
  `status` ENUM('queued','running','done','error','canceled') NOT NULL DEFAULT 'queued',
  `priority` TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '0=urgent, 9=low',
  `run_at` DATETIME NULL COMMENT 'NULL = asap',

  `analysis_id` INT UNSIGNED NULL COMMENT 'Référence ged_analyses.id si applicable',
  `payload_json` LONGTEXT NULL COMMENT 'JSON params du job (forced_service, etc.)',

  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 3,
  `last_error` TEXT NULL,

  `locked_by` VARCHAR(80) NULL COMMENT 'worker identity',
  `locked_until` DATETIME NULL COMMENT 'lease',

  `started_at` DATETIME NULL,
  `finished_at` DATETIME NULL,

  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_type_status` (`job_type`, `status`),
  KEY `idx_run_at` (`run_at`),
  KEY `idx_analysis` (`analysis_id`),
  KEY `idx_locked_until` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];

