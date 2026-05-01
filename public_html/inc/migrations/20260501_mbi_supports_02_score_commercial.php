<?php
/**
 * Migration : Ma Box Communication — Historisation des scores commerciaux
 *
 * Crée bien_score_commercial : un score = une ligne (jamais ecrasee).
 * Hybride deterministe + IA, audit IA (modele/prompt/cout en centimes).
 */

return [
    'id'          => '20260501_mbi_supports_02_score_commercial',
    'title'       => 'Ma Box Communication — table bien_score_commercial',
    'description' => "Historisation des calculs de score commercial sur les biens. Chaque calcul (manuel ou auto) cree une ligne. Mode hybride deterministe + IA Claude. Tracking des couts IA (cout_ia_centimes) et du data_snapshot pour rejouabilite.",
    'created_at'  => '2026-05-01',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `bien_score_commercial` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,

  `id_bien`               INT UNSIGNED NOT NULL,
  `id_user`               INT UNSIGNED NOT NULL,

  `id_societe`            INT UNSIGNED NOT NULL,
  `id_agence`             INT UNSIGNED NOT NULL,

  `calcul_mode`           ENUM('deterministe','ia','hybride') NOT NULL DEFAULT 'hybride',
  `statut`                ENUM('draft','calcule','erreur') NOT NULL DEFAULT 'draft',
  `derniere_erreur`       TEXT NULL,

  `score`                 TINYINT UNSIGNED NULL COMMENT '0 a 100',
  `score_breakdown_json`  LONGTEXT NULL,

  `points_forts_json`     LONGTEXT NULL,
  `points_faibles_json`   LONGTEXT NULL,
  `angle_recommande`      ENUM('famille','investisseur','premium','premier_achat','generique','autre') NULL,
  `photo_hero_id`         INT UNSIGNED NULL COMMENT 'Ref biens_photos.id',
  `niveau_urgence`        ENUM('faible','moyen','fort') NULL,

  `data_snapshot_json`    LONGTEXT NULL,

  `confidence_score`      TINYINT UNSIGNED NULL,
  `prompt_version`        VARCHAR(50) NULL,
  `modele_ia`             VARCHAR(80) NULL,
  `cout_ia_centimes`      INT UNSIGNED NOT NULL DEFAULT 0,

  `mentions_version`      VARCHAR(50) NULL,

  `date_calcul`           DATETIME NOT NULL,
  `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_bien_date`     (`id_bien`, `date_calcul`),
  KEY `idx_tenant`        (`id_societe`, `id_agence`),
  KEY `idx_statut`        (`statut`),
  KEY `idx_calcul_mode`   (`calcul_mode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
