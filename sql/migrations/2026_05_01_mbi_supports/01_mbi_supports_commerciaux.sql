-- =============================================================================
-- Module : Ma Box Communication (mbi_supports)
-- Lot 1 / 5 — Table principale des supports commerciaux
-- Date   : 2026-05-01
-- Cible  : MariaDB / MySQL — InnoDB + utf8mb4_unicode_ci
-- Notes  :
--   - Pas de FK physiques en V1 (schéma existant hétérogène) — index seuls.
--     Contrôles d'intégrité assurés côté applicatif.
--   - Tables référencées (vérifiées par inspection du code, sans FK) :
--       biens, mbi_annonces, mandats, users, societes, agences, bien_photos.
--   - Versioning systématique : aucun support validé ne doit être écrasé.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `mbi_supports_commerciaux` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- ---------------------------------------------------------------------------
  -- Rattachements métier (id logiques, pas de FK V1)
  -- ---------------------------------------------------------------------------
  `id_bien`               INT UNSIGNED NOT NULL,
  `id_annonce`            INT UNSIGNED NULL,
  `id_mandat`             INT UNSIGNED NULL,
  `id_user`               INT UNSIGNED NOT NULL COMMENT 'Auteur de la génération',

  -- ---------------------------------------------------------------------------
  -- Multi-tenant — filtrage obligatoire (cf. règle filtrage_multi_tenant)
  -- ---------------------------------------------------------------------------
  `id_societe`            INT UNSIGNED NOT NULL,
  `id_agence`             INT UNSIGNED NOT NULL,

  -- ---------------------------------------------------------------------------
  -- Caractérisation du support
  -- ---------------------------------------------------------------------------
  `type_support`          ENUM(
                            'affiche_vitrine',
                            'fiche_client',
                            'fiche_visite_interne',
                            'dossier_presentation'
                          ) NOT NULL,
  `titre_support`         VARCHAR(200) NOT NULL,
  `angle_marketing`       ENUM(
                            'famille',
                            'investisseur',
                            'premium',
                            'premier_achat',
                            'generique',
                            'autre'
                          ) NOT NULL DEFAULT 'generique',
  `cible`                 VARCHAR(120) NULL,
  `moment_commercial`     ENUM(
                            'vitrine',
                            'avant_visite',
                            'pendant_visite',
                            'apres_visite',
                            'offre',
                            'prise_mandat'
                          ) NULL,
  `orientation_user`      TEXT NULL
                          COMMENT 'Brief libre du négociateur passé à l''IA',

  -- ---------------------------------------------------------------------------
  -- Liens vers le moteur de score et les règles légales
  -- ---------------------------------------------------------------------------
  `score_commercial_id`   INT UNSIGNED NULL,
  `mentions_version`      VARCHAR(50) NOT NULL
                          COMMENT 'Version du référentiel mentions légales appliqué',

  -- ---------------------------------------------------------------------------
  -- Sécurité fiche interne (anti-fuite)
  -- ---------------------------------------------------------------------------
  `is_interne`            TINYINT(1) NOT NULL DEFAULT 0,

  -- ---------------------------------------------------------------------------
  -- Versioning
  -- ---------------------------------------------------------------------------
  `version`               INT UNSIGNED NOT NULL DEFAULT 1,
  `statut`                ENUM(
                            'draft',
                            'en_validation',
                            'valide',
                            'diffuse',
                            'archive',
                            'erreur'
                          ) NOT NULL DEFAULT 'draft',

  -- ---------------------------------------------------------------------------
  -- Source et traces de génération
  -- ---------------------------------------------------------------------------
  `source_generation`     ENUM('manuel','ia','mixte') NOT NULL DEFAULT 'mixte',
  `derniere_erreur`       TEXT NULL,

  -- ---------------------------------------------------------------------------
  -- Contenu et fichiers
  -- ---------------------------------------------------------------------------
  `contenu_json`          LONGTEXT NULL
                          COMMENT 'Bloc structuré (titre, accroche, descriptif, mentions, blocs IA…)',
  `nom_fichier`           VARCHAR(255) NULL,
  `chemin_stockage`       VARCHAR(500) NULL
                          COMMENT 'Chemin relatif local OU clé GED, selon storage_driver',
  `fichier_pdf_path`      VARCHAR(500) NULL,
  `fichier_image_path`    VARCHAR(500) NULL
                          COMMENT 'Aperçu PNG/JPG facultatif',

  -- ---------------------------------------------------------------------------
  -- Stockage multi-driver (anticipation GED / Google Drive)
  -- ---------------------------------------------------------------------------
  `storage_driver`        ENUM('local','ged','google_drive') NULL DEFAULT 'local',
  `ged_document_id`       BIGINT UNSIGNED NULL,
  `google_drive_file_id`  VARCHAR(255) NULL,

  -- ---------------------------------------------------------------------------
  -- Snapshot DPE au moment de la génération (auditabilité juridique)
  -- ---------------------------------------------------------------------------
  `snapshot_dpe_statut`   ENUM('present','en_cours','non_soumis','manquant') NULL,
  `snapshot_dpe_classe`   VARCHAR(5) NULL,

  -- ---------------------------------------------------------------------------
  -- Traçabilité temporelle
  -- ---------------------------------------------------------------------------
  `date_generation`       DATETIME NULL,
  `date_validation`       DATETIME NULL,
  `date_diffusion`        DATETIME NULL,
  `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                          ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`            DATETIME NULL,

  -- ---------------------------------------------------------------------------
  -- Index (pas de FK physiques V1)
  -- ---------------------------------------------------------------------------
  PRIMARY KEY (`id`),
  KEY `idx_bien_version`   (`id_bien`, `version`),
  KEY `idx_tenant_statut`  (`id_societe`, `id_agence`, `statut`),
  KEY `idx_type_support`   (`type_support`),
  KEY `idx_mandat`         (`id_mandat`),
  KEY `idx_annonce`        (`id_annonce`),
  KEY `idx_user`           (`id_user`),
  KEY `idx_score`          (`score_commercial_id`),
  KEY `idx_is_interne`     (`is_interne`),
  KEY `idx_storage_driver` (`storage_driver`),
  KEY `idx_deleted_at`     (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
