-- =============================================================================
-- Module : Ma Box Communication (mbi_supports)
-- Lot 1 / 5 — Historisation des scores de commercialisation
-- Date   : 2026-05-01
-- Cible  : MariaDB / MySQL — InnoDB + utf8mb4_unicode_ci
-- Notes  :
--   - Calcul hybride : règles déterministes (chiffré, rejouable) +
--     IA (points forts/faibles, angle, photo héro).
--   - Aucun écrasement : un nouveau calcul = nouvelle ligne (historisé).
--   - Pas de FK physiques en V1 — contrôles applicatifs.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `bien_score_commercial` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- ---------------------------------------------------------------------------
  -- Rattachements
  -- ---------------------------------------------------------------------------
  `id_bien`               INT UNSIGNED NOT NULL,
  `id_user`               INT UNSIGNED NOT NULL COMMENT 'Calcul déclenché par',

  -- Multi-tenant (snapshot pour stats agrégées)
  `id_societe`            INT UNSIGNED NOT NULL,
  `id_agence`             INT UNSIGNED NOT NULL,

  -- ---------------------------------------------------------------------------
  -- Mode de calcul et statut
  -- ---------------------------------------------------------------------------
  `calcul_mode`           ENUM('deterministe','ia','hybride')
                          NOT NULL DEFAULT 'hybride',
  `statut`                ENUM('draft','calcule','erreur')
                          NOT NULL DEFAULT 'draft',
  `derniere_erreur`       TEXT NULL,

  -- ---------------------------------------------------------------------------
  -- Score
  -- ---------------------------------------------------------------------------
  `score`                 TINYINT UNSIGNED NULL COMMENT '0 à 100',
  `score_breakdown_json`  LONGTEXT NULL
                          COMMENT 'Détail des règles déterministes (poids, points)',

  -- ---------------------------------------------------------------------------
  -- Lecture IA
  -- ---------------------------------------------------------------------------
  `points_forts_json`     LONGTEXT NULL,
  `points_faibles_json`   LONGTEXT NULL,
  `angle_recommande`      ENUM(
                            'famille',
                            'investisseur',
                            'premium',
                            'premier_achat',
                            'generique',
                            'autre'
                          ) NULL,
  `photo_hero_id`         INT UNSIGNED NULL COMMENT 'Réf bien_photos.id',
  `niveau_urgence`        ENUM('faible','moyen','fort') NULL,

  -- ---------------------------------------------------------------------------
  -- Données utilisées pour le calcul (audit / rejouabilité)
  -- ---------------------------------------------------------------------------
  `data_snapshot_json`    LONGTEXT NULL
                          COMMENT 'Snapshot des champs du bien utilisés au moment du calcul',

  -- ---------------------------------------------------------------------------
  -- Fiabilité et audit IA (cost tracking)
  -- ---------------------------------------------------------------------------
  `confidence_score`      TINYINT UNSIGNED NULL COMMENT 'Confiance IA 0-100',
  `prompt_version`        VARCHAR(50) NULL,
  `modele_ia`             VARCHAR(80) NULL
                          COMMENT 'ex: claude-opus-4-7, claude-sonnet-4-6',
  `cout_ia_centimes`      INT UNSIGNED NOT NULL DEFAULT 0,

  -- ---------------------------------------------------------------------------
  -- Lien règles légales (cohérence avec support qui en découle)
  -- ---------------------------------------------------------------------------
  `mentions_version`      VARCHAR(50) NULL,

  -- ---------------------------------------------------------------------------
  -- Temporalité
  -- ---------------------------------------------------------------------------
  `date_calcul`           DATETIME NOT NULL,
  `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,

  -- ---------------------------------------------------------------------------
  -- Index (pas de FK physiques V1)
  -- ---------------------------------------------------------------------------
  PRIMARY KEY (`id`),
  KEY `idx_bien_date`     (`id_bien`, `date_calcul` DESC),
  KEY `idx_tenant`        (`id_societe`, `id_agence`),
  KEY `idx_statut`        (`statut`),
  KEY `idx_calcul_mode`   (`calcul_mode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
