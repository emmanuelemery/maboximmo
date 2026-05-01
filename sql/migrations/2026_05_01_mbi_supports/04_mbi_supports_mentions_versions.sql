-- =============================================================================
-- Module : Ma Box Communication (mbi_supports)
-- Lot 1 / 5 — Versioning des règles de mentions légales
-- Date   : 2026-05-01
-- Cible  : MariaDB / MySQL — InnoDB + utf8mb4_unicode_ci
-- Notes  :
--   - Référentiel pilotant l'assistant critique (bloc dur + alertes).
--   - Une seule version `actif = 1` à la fois (contrôle applicatif V1).
--   - Le seed v1 sera créé en Lot 2 après rédaction et validation
--     de docs/mentions_legales_supports.md.
--   - Validation juridique externe à confirmer avant `valide_juridiquement = 1`.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `mbi_supports_mentions_versions` (
  `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `version`                  VARCHAR(50) NOT NULL
                             COMMENT 'ex: 2026-05-01.v1',
  `libelle`                  VARCHAR(200) NOT NULL,
  `description`              TEXT NULL,

  -- Règles structurées exploitées par le moteur critique
  `regles_json`              LONGTEXT NOT NULL
                             COMMENT 'Bloc dur + alertes par type_support et nature (vente / location / copro)',

  -- Périmètre couvert par la version
  `concerne_vente`           TINYINT(1) NOT NULL DEFAULT 1,
  `concerne_location`        TINYINT(1) NOT NULL DEFAULT 0,
  `concerne_copro`           TINYINT(1) NOT NULL DEFAULT 1,

  -- Cycle de vie
  `actif`                    TINYINT(1) NOT NULL DEFAULT 0
                             COMMENT 'Une seule version active à la fois (contrôle applicatif)',
  `date_application`         DATE NULL,
  `valide_juridiquement`     TINYINT(1) NOT NULL DEFAULT 0
                             COMMENT 'Validation juridique externe à confirmer',
  `valide_par`               INT UNSIGNED NULL,
  `date_validation`          DATETIME NULL,

  -- Audit / sourcing
  `created_by`               INT UNSIGNED NULL,
  `source_reference`         VARCHAR(255) NULL
                             COMMENT 'Loi Hoguet, ALUR, décret X, etc.',
  `commentaire_validation`   TEXT NULL,

  `created_at`               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                             ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_version`    (`version`),
  KEY `idx_actif`            (`actif`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
