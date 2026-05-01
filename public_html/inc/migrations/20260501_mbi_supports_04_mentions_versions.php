<?php
/**
 * Migration : Ma Box Communication — Versioning des mentions légales
 *
 * Crée mbi_supports_mentions_versions : referentiel des mentions
 * legales applicables (bloc dur + alertes) versionne dans le temps.
 *
 * Le seed v1 est dans la migration suivante (06).
 */

return [
    'id'          => '20260501_mbi_supports_04_mentions_versions',
    'title'       => 'Ma Box Communication — table mbi_supports_mentions_versions',
    'description' => "Versioning des regles de mentions legales (bloc dur + alertes) qui pilotent l'assistant critique. Le seed v1 (vente, brouillon fonctionnel non valide juridiquement) est insere dans la migration 06.",
    'created_at'  => '2026-05-01',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `mbi_supports_mentions_versions` (
  `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version`                  VARCHAR(50) NOT NULL,
  `libelle`                  VARCHAR(200) NOT NULL,
  `description`              TEXT NULL,
  `regles_json`              LONGTEXT NOT NULL,
  `concerne_vente`           TINYINT(1) NOT NULL DEFAULT 1,
  `concerne_location`        TINYINT(1) NOT NULL DEFAULT 0,
  `concerne_copro`           TINYINT(1) NOT NULL DEFAULT 1,
  `actif`                    TINYINT(1) NOT NULL DEFAULT 0,
  `date_application`         DATE NULL,
  `valide_juridiquement`     TINYINT(1) NOT NULL DEFAULT 0,
  `valide_par`               INT UNSIGNED NULL,
  `date_validation`          DATETIME NULL,
  `created_by`               INT UNSIGNED NULL,
  `source_reference`         VARCHAR(255) NULL,
  `commentaire_validation`   TEXT NULL,
  `created_at`               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_version`    (`version`),
  KEY `idx_actif`            (`actif`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
