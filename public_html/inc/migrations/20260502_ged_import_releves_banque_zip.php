<?php
/**
 * Migration : Import ZIP relevés bancaires (staging)
 *
 * 2 tables :
 *   - ged_import_releves_batches : suivi des lots
 *   - ged_import_releves_items   : 1 ligne par PDF extrait/analysé
 *
 * Objectif : pipeline spécifique (ZIP → PDF → reconnaissance immeuble → GED)
 * sans impacter la GED existante.
 */

return [
    'id'          => '20260502_ged_import_releves_banque_zip',
    'title'       => 'GED : Import ZIP relevés bancaires (batches/items)',
    'description' => "Ajoute le staging d'import ZIP de relevés bancaires (lots + items), avec hash, statuts, confiance et traçabilité Drive.",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `ged_import_releves_batches` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `id_societe`        INT UNSIGNED NULL,
    `created_by`        INT UNSIGNED NULL,
    `statut`            ENUM('created','extracting','analyzing','ready','importing','done','error') NOT NULL DEFAULT 'created',
    `mois_annee_defaut` CHAR(7) NULL COMMENT 'YYYY-MM si période non reconnue',
    `commentaire`       TEXT NULL,

    `nb_zip`            INT UNSIGNED NOT NULL DEFAULT 0,
    `nb_pdf`            INT UNSIGNED NOT NULL DEFAULT 0,
    `nb_reconnus`       INT UNSIGNED NOT NULL DEFAULT 0,
    `nb_a_valider`      INT UNSIGNED NOT NULL DEFAULT 0,
    `nb_erreurs`        INT UNSIGNED NOT NULL DEFAULT 0,

    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY `idx_societe` (`id_societe`),
    KEY `idx_statut` (`statut`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ged_import_releves_items` (
    `id`                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `batch_id`           INT UNSIGNED NOT NULL,

    `zip_original`       VARCHAR(255) NULL,
    `fichier_original`   VARCHAR(255) NOT NULL,
    `pdf_rel_path`       VARCHAR(500) NULL COMMENT 'Chemin staging (storage/ged/...)',
    `pdf_sha256`         CHAR(64) NULL,
    `pdf_taille_octets`  INT UNSIGNED NULL,

    `id_immeuble`        INT UNSIGNED NULL,
    `id_bien`            INT UNSIGNED NULL,
    `logiciel_comptable` ENUM('SEPTEO','LOJJI','ICS','MABOXIMMO','AUTRE') NULL DEFAULT NULL,

    `banque_detectee`    VARCHAR(80) NULL,
    `periode_annee`      SMALLINT UNSIGNED NULL,
    `periode_mois`       TINYINT UNSIGNED NULL,

    `confiance_globale`  DECIMAL(5,2) NULL,
    `confiance_immeuble` DECIMAL(5,2) NULL,
    `raison_detection`   TEXT NULL,

    `statut`             ENUM('extracted','analyzed','recognized','uncertain','validated','rejected','imported','error','duplicate') NOT NULL DEFAULT 'extracted',
    `erreur`             TEXT NULL,

    `drive_file_id_immeuble` VARCHAR(80) NULL,
    `drive_file_id_compta`   VARCHAR(80) NULL,
    `drive_url_immeuble`     VARCHAR(255) NULL,
    `drive_url_compta`       VARCHAR(255) NULL,

    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `validated_at`       DATETIME NULL,
    `validated_by`       INT UNSIGNED NULL,

    KEY `idx_batch_sha` (`batch_id`, `pdf_sha256`),
    KEY `idx_batch` (`batch_id`),
    KEY `idx_statut` (`statut`),
    KEY `idx_immeuble` (`id_immeuble`),
    KEY `idx_logiciel` (`logiciel_comptable`),
    KEY `idx_periode` (`periode_annee`, `periode_mois`),

    CONSTRAINT `fk_releves_batch` FOREIGN KEY (`batch_id`) REFERENCES `ged_import_releves_batches`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];
