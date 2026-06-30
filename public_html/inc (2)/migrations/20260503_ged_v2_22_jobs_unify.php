<?php
/**
 * Migration v2.5 — ged_jobs : unification snake_case
 * ===================================================
 *
 * Contexte (memory feedback_ged_decisions_finales_2026-05-02 / Phase 1.2) :
 * Deux migrations historiques créaient `ged_jobs` avec des schémas DIFFÉRENTS :
 *
 *   - 20260502_ged_jobs.php       (legacy / camelCase callers, à supprimer)
 *     cols : type, payload_json, status (4), attempts, max_attempts,
 *            locked_at, locked_by, started_at, finished_at,
 *            error_message, result_json, id_societe, id_user, created_at
 *     Worker associé : public_html/scripts/ged_worker.php (mort, fonctions inexistantes)
 *
 *   - 20260502_ged_jobs_queue.php (snake_case, GARDÉ)
 *     cols : created_at, updated_at, job_type, status (5 + canceled),
 *            priority, run_at, analysis_id, payload_json,
 *            attempts, max_attempts, last_error,
 *            locked_by, locked_until, started_at, finished_at
 *     Worker associé : public_html/api/cron_ged_jobs.php (HTTP cron tokenisé, ACTIF)
 *     Lib associée   : public_html/modules/ged/ged_jobs.php
 *
 * Comme les deux migrations utilisent CREATE TABLE IF NOT EXISTS, l'ordre
 * d'application sur prod détermine le schéma résultant — on peut donc avoir
 * une table `ged_jobs` qui ne possède pas les colonnes snake_case attendues
 * par le worker actif.
 *
 * Cette migration garantit, de façon strictement additive et idempotente, la
 * présence des colonnes + index snake_case requis par le worker `cron_ged_jobs.php`.
 *
 * Règles respectées :
 *   - additif strict : aucune colonne supprimée, aucune donnée modifiée
 *   - idempotent     : ADD COLUMN/INDEX IF NOT EXISTS partout
 *   - ne touche pas aux colonnes legacy (type, locked_at, error_message,
 *     result_json, id_societe, id_user, started_at, finished_at) — elles
 *     sont conservées telles quelles, simplement obsolètes
 *   - ne migre pas les données existantes (aucun in-flight job legacy
 *     possible : le worker scripts/ged_worker.php était mort)
 */

return [
    'id'          => '20260503_ged_v2_22_jobs_unify',
    'title'       => 'Ma GED Box V2.5 — ged_jobs : unification snake_case (additive, idempotente)',
    'description' => "Garantit la présence des colonnes snake_case (job_type, priority, run_at, analysis_id, last_error, locked_until, updated_at) + index attendus par le worker actif api/cron_ged_jobs.php. Aucune colonne legacy supprimée — additif strict. Idempotente : applicable plusieurs fois sans effet.",
    'created_at'  => '2026-05-03',
    'sql' => <<<'SQL'
-- 1) Crée la table si elle n'existe pas (cas BDD vierge) — schéma snake_case canonique
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

-- 2) Ajoute les colonnes snake_case si la table existait déjà avec le schéma legacy
ALTER TABLE `ged_jobs` ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NULL AFTER `created_at`;
ALTER TABLE `ged_jobs` ADD COLUMN IF NOT EXISTS `job_type` VARCHAR(60) NOT NULL DEFAULT '' COMMENT 'snake_case canonical; legacy = type';
ALTER TABLE `ged_jobs` ADD COLUMN IF NOT EXISTS `priority` TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '0=urgent, 9=low';
ALTER TABLE `ged_jobs` ADD COLUMN IF NOT EXISTS `run_at` DATETIME NULL COMMENT 'NULL = asap';
ALTER TABLE `ged_jobs` ADD COLUMN IF NOT EXISTS `analysis_id` INT UNSIGNED NULL COMMENT 'Référence ged_analyses.id si applicable';
ALTER TABLE `ged_jobs` ADD COLUMN IF NOT EXISTS `last_error` TEXT NULL COMMENT 'snake_case canonical; legacy = error_message';
ALTER TABLE `ged_jobs` ADD COLUMN IF NOT EXISTS `locked_until` DATETIME NULL COMMENT 'lease; snake_case canonical (legacy = locked_at)';

-- 3) Ajoute les index snake_case si absents
ALTER TABLE `ged_jobs` ADD INDEX IF NOT EXISTS `idx_status` (`status`);
ALTER TABLE `ged_jobs` ADD INDEX IF NOT EXISTS `idx_type_status` (`job_type`, `status`);
ALTER TABLE `ged_jobs` ADD INDEX IF NOT EXISTS `idx_run_at` (`run_at`);
ALTER TABLE `ged_jobs` ADD INDEX IF NOT EXISTS `idx_analysis` (`analysis_id`);
ALTER TABLE `ged_jobs` ADD INDEX IF NOT EXISTS `idx_locked_until` (`locked_until`);

-- 4) Note : les colonnes legacy (type, error_message, locked_at, result_json,
--    id_societe, id_user, started_at, finished_at) sont laissées intactes si
--    présentes — additif strict, aucun DROP. Cf. doc en tête de migration pour
--    le mapping legacy → snake_case. Suppression physique des colonnes obsolètes
--    sera traitée Phase 4 (clean schema), pas avant que tous les workers/lib
--    aient été audités.
SQL,
];
