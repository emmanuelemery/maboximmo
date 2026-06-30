<?php
/**
 * Migration : Ma Box Communication — Catalogue de styles + overrides
 *
 * Crée mbi_supports_styles (catalogue global) + mbi_supports_styles_overrides
 * (specialisation societe/agence).
 *
 * Seed initial : mbi_default (charte navy/or) + regie_emery.
 *
 * Note : INSERT IGNORE pour ne pas planter si seeds deja presents.
 */

return [
    'id'          => '20260501_mbi_supports_03_styles',
    'title'       => 'Ma Box Communication — tables mbi_supports_styles + overrides + seed',
    'description' => "Catalogue de styles applicables aux supports PDF (couleurs, polices, ton redactionnel) + table d'overrides society/agence. Seed initial : style mbi_default (charte navy #243B5C / or #D4A047) + regie_emery (variante premium).",
    'created_at'  => '2026-05-01',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `mbi_supports_styles` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`                  VARCHAR(50) NOT NULL,
  `libelle`               VARCHAR(120) NOT NULL,
  `is_default`            TINYINT(1) NOT NULL DEFAULT 0,
  `actif`                 TINYINT(1) NOT NULL DEFAULT 1,
  `couleur_primaire`      VARCHAR(7) NULL,
  `couleur_secondaire`    VARCHAR(7) NULL,
  `couleur_texte`         VARCHAR(7) NULL,
  `police_titre`          VARCHAR(80) NULL,
  `police_corps`          VARCHAR(80) NULL,
  `logo_path`             VARCHAR(500) NULL,
  `ton_redactionnel`      ENUM('neutre','premium','familial','investisseur','dynamique') NOT NULL DEFAULT 'neutre',
  `niveau_detail`         ENUM('synthetique','standard','detaille') NOT NULL DEFAULT 'standard',
  `regles_layout_json`    LONGTEXT NULL,
  `regles_photos_json`    LONGTEXT NULL,
  `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code`    (`code`),
  KEY `idx_default`       (`is_default`, `actif`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `mbi_supports_styles`
  (`code`, `libelle`, `is_default`, `actif`,
   `couleur_primaire`, `couleur_secondaire`, `couleur_texte`,
   `ton_redactionnel`, `niveau_detail`)
VALUES
  ('mbi_default', 'MaBoxImmo — Standard',
   1, 1, '#243B5C', '#D4A047', '#1F2937',
   'neutre', 'standard'),
  ('regie_emery', 'Régie Emery — Premium',
   0, 1, '#243B5C', '#D4A047', '#1F2937',
   'premium', 'detaille');

CREATE TABLE IF NOT EXISTS `mbi_supports_styles_overrides` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_style`              INT UNSIGNED NOT NULL,
  `id_societe`            INT UNSIGNED NULL,
  `id_agence`             INT UNSIGNED NULL,
  `couleur_primaire`      VARCHAR(7) NULL,
  `couleur_secondaire`    VARCHAR(7) NULL,
  `couleur_texte`         VARCHAR(7) NULL,
  `police_titre`          VARCHAR(80) NULL,
  `police_corps`          VARCHAR(80) NULL,
  `logo_path`             VARCHAR(500) NULL,
  `ton_redactionnel`      ENUM('neutre','premium','familial','investisseur','dynamique') NULL,
  `niveau_detail`         ENUM('synthetique','standard','detaille') NULL,
  `regles_layout_json`    LONGTEXT NULL,
  `regles_photos_json`    LONGTEXT NULL,
  `actif`                 TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_style_tenant` (`id_style`, `id_societe`, `id_agence`),
  KEY `idx_tenant`             (`id_societe`, `id_agence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
