<?php
/**
 * Migration : Ma Box Communication — Table principale des supports commerciaux
 *
 * Crée mbi_supports_commerciaux : versioning + multi-tenant + stockage
 * multi-driver (local / GED / Google Drive) + snapshot DPE.
 *
 * Pas de FK physiques (V1) — index seuls + contrôles applicatifs.
 * Toutes les colonnes de surcharges éditeur (titre_personnalise,
 * accroche, description_personnalisee, photo_hero_id_personnalise,
 * mentions_overrides_json) sont incluses dès cette migration.
 */

return [
    'id'          => '20260501_mbi_supports_01_table_principale',
    'title'       => 'Ma Box Communication — table mbi_supports_commerciaux',
    'description' => "Table principale du module Communication : 1 ligne = 1 support PDF (affiche vitrine, fiche client, fiche visite interne, dossier de présentation). Versionnée, multi-tenant. Inclut les 5 colonnes de surcharges éditeur (titre/accroche/description/photo/mentions overrides) dès le départ.",
    'created_at'  => '2026-05-01',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `mbi_supports_commerciaux` (
  `id`                    INT UNSIGNED NOT NULL AUTO_INCREMENT,

  `id_bien`               INT UNSIGNED NOT NULL,
  `id_annonce`            INT UNSIGNED NULL,
  `id_mandat`             INT UNSIGNED NULL,
  `id_user`               INT UNSIGNED NOT NULL COMMENT 'Auteur de la generation',

  `id_societe`            INT UNSIGNED NOT NULL,
  `id_agence`             INT UNSIGNED NOT NULL,

  `type_support`          ENUM(
                            'affiche_vitrine',
                            'fiche_client',
                            'fiche_visite_interne',
                            'dossier_presentation'
                          ) NOT NULL,
  `titre_support`         VARCHAR(200) NOT NULL,

  `titre_personnalise`         VARCHAR(200)  NULL COMMENT 'Surcharge editeur — bien.designation',
  `accroche`                   VARCHAR(500)  NULL COMMENT 'Phrase accroche commerciale (italique grand format)',
  `description_personnalisee`  TEXT          NULL COMMENT 'Surcharge editeur — annonce/bien description',
  `photo_hero_id_personnalise` INT UNSIGNED  NULL COMMENT 'biens_photos.id forcee',
  `mentions_overrides_json`    LONGTEXT      NULL COMMENT 'Surcharges mentions ponctuelles',

  `angle_marketing`       ENUM(
                            'famille','investisseur','premium','premier_achat','generique','autre'
                          ) NOT NULL DEFAULT 'generique',
  `cible`                 VARCHAR(120) NULL,
  `moment_commercial`     ENUM(
                            'vitrine','avant_visite','pendant_visite','apres_visite','offre','prise_mandat'
                          ) NULL,
  `orientation_user`      TEXT NULL COMMENT 'Brief libre du negociateur passe a l IA',

  `score_commercial_id`   INT UNSIGNED NULL,
  `mentions_version`      VARCHAR(50) NOT NULL COMMENT 'Version referentiel mentions legales appliquee',

  `is_interne`            TINYINT(1) NOT NULL DEFAULT 0,

  `version`               INT UNSIGNED NOT NULL DEFAULT 1,
  `statut`                ENUM(
                            'draft','en_validation','valide','diffuse','archive','erreur'
                          ) NOT NULL DEFAULT 'draft',

  `source_generation`     ENUM('manuel','ia','mixte') NOT NULL DEFAULT 'mixte',
  `derniere_erreur`       TEXT NULL,

  `contenu_json`          LONGTEXT NULL,
  `nom_fichier`           VARCHAR(255) NULL,
  `chemin_stockage`       VARCHAR(500) NULL,
  `fichier_pdf_path`      VARCHAR(500) NULL,
  `fichier_image_path`    VARCHAR(500) NULL,

  `storage_driver`        ENUM('local','ged','google_drive') NULL DEFAULT 'local',
  `ged_document_id`       BIGINT UNSIGNED NULL,
  `google_drive_file_id`  VARCHAR(255) NULL,

  `snapshot_dpe_statut`   ENUM('present','en_cours','non_soumis','manquant') NULL,
  `snapshot_dpe_classe`   VARCHAR(5) NULL,

  `date_generation`       DATETIME NULL,
  `date_validation`       DATETIME NULL,
  `date_diffusion`        DATETIME NULL,
  `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`            DATETIME NULL,

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
];
