<?php
/**
 * Migration : table ia_extract_cache
 *
 * Cache permanent des extractions IA Vision (Claude Sonnet/Haiku PDF).
 * Clé : SHA-256 du fichier + modèle + version du prompt.
 *
 * Bénéfice : un fichier = une analyse = un paiement, peu importe combien de
 * fois ou dans combien d'environnements (local/dev/prod) il est ré-analysé.
 *
 * Le cache "suit" les environnements via export SQL (admin_ia_cache_export.php).
 */

return [
    'id'          => '20260522_ia_extract_cache',
    'title'       => 'Cache permanent extractions IA (SHA-256 → réponse Claude)',
    'description' => "Crée la table ia_extract_cache : clé (hash_sha256, model, prompt_version) + response_json + cout_centimes + hit_count. Évite de re-payer Claude pour un fichier déjà analysé (même en changeant d'environnement local→dev→prod via export SQL).",
    'created_at'  => '2026-05-22',
    'sql'         => <<<'SQL'

CREATE TABLE IF NOT EXISTS `ia_extract_cache` (
  `hash_sha256`    CHAR(64)     NOT NULL,
  `model`          VARCHAR(60)  NOT NULL,
  `prompt_version` VARCHAR(20)  NOT NULL DEFAULT '1',
  `response_json`  LONGTEXT     NOT NULL,
  `cout_centimes`  INT          NOT NULL DEFAULT 0,
  `hit_count`      INT UNSIGNED NOT NULL DEFAULT 1,
  `first_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `confidence`     TINYINT UNSIGNED NULL,
  `source_origin`  VARCHAR(40)  NULL COMMENT 'transaction_doc_extract_ia | autre',
  PRIMARY KEY (`hash_sha256`, `model`, `prompt_version`),
  INDEX `idx_iac_model`    (`model`, `prompt_version`),
  INDEX `idx_iac_first_at` (`first_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SQL
];
