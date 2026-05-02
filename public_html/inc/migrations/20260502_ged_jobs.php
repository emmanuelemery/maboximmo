<?php
/**
 * Migration : Ma GED Box — Queue interne ged_jobs
 *
 * Crée la file d'attente des tâches lourdes GED (analyse IA avancée,
 * OCR cascade, import lots…). Permet :
 *   - réponse HTTP immédiate côté inbox (fastcgi_finish_request best-effort
 *     remplacé par enqueue + worker cron-safe fiable)
 *   - retry automatique avec max_attempts
 *   - vue admin (jobs en erreur + relance manuelle)
 *
 * Worker CLI : public_html/scripts/ged_worker.php
 * Cron Hostinger : * * * * * php /home/u630423897/domains/maboximmo.fr/public_html/scripts/ged_worker.php --max=5
 */

return [
    'id'          => '20260502_ged_jobs',
    'title'       => 'Ma GED Box — queue interne ged_jobs (analyses IA avancées + OCR + imports)',
    'description' => "Crée la table ged_jobs (queue interne) et ses index. Sert au worker CLI ged_worker.php pour exécuter en différé les tâches lourdes (analyze_advanced, ocr, classify, link, rename, import_zip). Verrous via locked_at + locked_by. Retry via attempts/max_attempts. Trace erreur + scope tenant (id_societe).",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `ged_jobs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` VARCHAR(50) NOT NULL COMMENT 'analyze_advanced | ocr | classify | link | rename | import_zip | …',
  `payload_json` TEXT NOT NULL COMMENT 'JSON des paramètres (id_analysis, options, etc.)',
  `status` ENUM('queued','running','done','error') NOT NULL DEFAULT 'queued',
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 3,
  `locked_at` DATETIME NULL,
  `locked_by` VARCHAR(80) NULL COMMENT 'PID/host worker',
  `started_at` DATETIME NULL,
  `finished_at` DATETIME NULL,
  `error_message` TEXT NULL,
  `result_json` TEXT NULL COMMENT 'Résultat optionnel renvoyé par le worker',
  `id_societe` INT UNSIGNED NULL,
  `id_user` INT UNSIGNED NULL COMMENT 'User à l''origine du job (pour notif)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_status_type` (`status`, `type`),
  INDEX `idx_locked_at` (`locked_at`),
  INDEX `idx_societe_status` (`id_societe`, `status`),
  INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];
