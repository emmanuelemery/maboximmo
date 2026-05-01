-- =============================================================================
-- Module : Ma Box Communication (mbi_supports)
-- Lot 1 / 5 — Catalogue des styles + overrides société/agence
-- Date   : 2026-05-01
-- Cible  : MariaDB / MySQL — InnoDB + utf8mb4_unicode_ci
-- Notes  :
--   - Catalogue global MBI (mbi_supports_styles).
--   - Overrides société/agence (mbi_supports_styles_overrides) : NULL signifie
--     "hériter du style global". Résolution applicative : agence > société > global.
--   - ⚠️ ATTENTION UNIQUE + NULL : MySQL autorise plusieurs lignes avec NULL
--     dans un index UNIQUE. La contrainte uk_style_tenant ne suffit donc PAS
--     à elle seule à empêcher les doublons d'override quand id_agence est NULL.
--     Pour V1 on garde simple, l'unicité réelle (1 override par couple
--     style/société/agence) est garantie côté applicatif. À renforcer plus tard
--     via un champ `scope_key VARCHAR(100)` calculé (ex: "1-S5-A12" / "1-S5-N").
--   - Pas de FK physiques en V1.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Catalogue global
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mbi_supports_styles` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`                  VARCHAR(50) NOT NULL
                          COMMENT 'mbi_default, regie_emery, premium, sobre, reseaux_sociaux, mandat_exclusif…',
  `libelle`               VARCHAR(120) NOT NULL,
  `is_default`            TINYINT(1) NOT NULL DEFAULT 0
                          COMMENT 'Style par défaut MBI — exactement 1 actif global',
  `actif`                 TINYINT(1) NOT NULL DEFAULT 1,

  -- Identité visuelle
  `couleur_primaire`      VARCHAR(7) NULL COMMENT 'Hex ex: #243B5C',
  `couleur_secondaire`    VARCHAR(7) NULL COMMENT 'Hex ex: #D4A047',
  `couleur_texte`         VARCHAR(7) NULL,
  `police_titre`          VARCHAR(80) NULL,
  `police_corps`          VARCHAR(80) NULL,
  `logo_path`             VARCHAR(500) NULL,

  -- Tonalité rédactionnelle
  `ton_redactionnel`      ENUM('neutre','premium','familial','investisseur','dynamique')
                          NOT NULL DEFAULT 'neutre',
  `niveau_detail`         ENUM('synthetique','standard','detaille')
                          NOT NULL DEFAULT 'standard',

  -- Mise en page / règles photos (JSON pour flexibilité)
  `regles_layout_json`    LONGTEXT NULL,
  `regles_photos_json`    LONGTEXT NULL,

  `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_code`    (`code`),
  KEY `idx_default`       (`is_default`, `actif`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- Seed initial
--   - mbi_default : style par défaut MBI (charte officielle navy + or)
--   - regie_emery : style premium pour la Régie Emery
-- -----------------------------------------------------------------------------
INSERT INTO `mbi_supports_styles`
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

-- -----------------------------------------------------------------------------
-- Overrides société / agence
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mbi_supports_styles_overrides` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_style`              INT UNSIGNED NOT NULL,
  `id_societe`            INT UNSIGNED NULL,
  `id_agence`             INT UNSIGNED NULL
                          COMMENT 'NULL : override au niveau société (ou global si id_societe NULL)',

  -- Champs override (NULL = on garde la valeur du style global)
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
  `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  -- Voir note en tête : unicité réelle garantie applicativement V1.
  UNIQUE KEY `uk_style_tenant` (`id_style`, `id_societe`, `id_agence`),
  KEY `idx_tenant`             (`id_societe`, `id_agence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
