<?php
/**
 * Migration : tables staging pour l'import en masse de documents GED.
 *
 * Philosophie :
 *   - 4 tables séparées par phase, chacune avec flag de validation humaine
 *   - Rien n'est dupliqué vers les tables prod tant que validated=1
 *   - Liaison par md5 pour traçabilité et anti-doublons
 *   - Scope : id_societe + id_agence (pas de user — batch technique)
 */

return [
    'id'          => '20260424_ged_imports_staging',
    'title'       => 'GED Imports : 4 tables staging (manifest / classification / extractions / upload)',
    'description' => "Tables de travail pour l'import en masse de docs GED (CRG, baux, TF, diagnostics, loyers). Chaque phase conserve ses données en staging avec validation humaine avant bascule vers tables prod (biens_photos, bien_baux, annonces, etc.).",
    'created_at'  => '2026-04-24',
    'sql' => <<<'SQL'
-- PHASE 1 : Inventaire physique (scan dossier source)
CREATE TABLE IF NOT EXISTS `ged_manifest` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `id_societe`      INT UNSIGNED NOT NULL,
    `id_agence`       INT UNSIGNED NOT NULL,
    `batch_id`        VARCHAR(50)  NOT NULL COMMENT 'Identifiant batch import (ex: sir_2026_04_24)',
    `path_source`     VARCHAR(500) NOT NULL COMMENT 'Chemin local absolu',
    `filename`        VARCHAR(255) NOT NULL,
    `md5`             CHAR(32)     NOT NULL,
    `taille_octets`   BIGINT UNSIGNED NOT NULL,
    `extension`       VARCHAR(10)  NULL,
    `famille_doc`     VARCHAR(30)  NULL COMMENT 'loyer|crg|bail|taxe_fonciere|dpe|diag|valorisation|autre',
    `date_fichier`    DATETIME     NULL COMMENT 'mtime du fichier source',
    `status`          VARCHAR(30)  NOT NULL DEFAULT 'inventaire' COMMENT 'inventaire|classe|extrait|uploade|rejete',
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_md5_batch` (`md5`, `batch_id`),
    KEY `idx_batch` (`batch_id`),
    KEY `idx_status` (`status`),
    KEY `idx_societe` (`id_societe`, `id_agence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PHASE 2 : Classification propriétaire / immeuble / bien
-- ANTI-DOUBLON : tout id_* référence une entité EXISTANTE. Si création nécessaire,
-- c'est écrit dans creation_needed_json + un hash dedup pour détecter les doublons
-- entre suggestions du même batch.
CREATE TABLE IF NOT EXISTS `ged_classification_staging` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `id_manifest`       INT UNSIGNED NOT NULL,
    `id_proprietaire`   INT UNSIGNED NULL COMMENT 'NULL = à créer OU ambigu',
    `id_immeuble`       INT UNSIGNED NULL,
    `id_bien`           INT UNSIGNED NULL,
    `creation_needed_json` TEXT NULL COMMENT 'JSON {proprietaire:{nom,...}, immeuble:{adresse,...}, bien:{...}} si création nouvelle validée',
    `dedup_hash`        CHAR(40) NULL COMMENT 'sha1(proprio_label + adresse_norm + bien_ref) pour détecter doublons intra-batch',
    `confidence`        ENUM('certain','probable','ambigu','non_classe') NOT NULL DEFAULT 'ambigu',
    `score`             DECIMAL(5,2) NULL COMMENT 'Score fuzzy match 0-100',
    `hint_filename`     VARCHAR(500) NULL COMMENT 'Texte extrait du filename ayant guidé le match',
    `hint_proprio`      VARCHAR(255) NULL COMMENT 'Nom propriétaire détecté',
    `hint_adresse`      VARCHAR(500) NULL COMMENT 'Adresse détectée',
    `validated`         TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '0=en attente, 1=validé humain, -1=rejeté',
    `validated_by`      INT UNSIGNED NULL,
    `validated_at`      DATETIME     NULL,
    `comment`           TEXT         NULL,
    `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_manifest` (`id_manifest`),
    KEY `idx_confidence` (`confidence`),
    KEY `idx_validated` (`validated`),
    KEY `idx_dedup` (`dedup_hash`),
    CONSTRAINT `fk_class_manifest` FOREIGN KEY (`id_manifest`) REFERENCES `ged_manifest`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PHASE 3 : Extractions IA structurées (JSON par famille de doc)
CREATE TABLE IF NOT EXISTS `ged_extractions_staging` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `id_manifest`     INT UNSIGNED NOT NULL,
    `famille_doc`     VARCHAR(30)  NOT NULL,
    `donnees_json`    LONGTEXT     NOT NULL COMMENT 'Payload structuré selon la famille',
    `prompt_version`  VARCHAR(20)  NULL COMMENT 'Version du prompt GPT utilisé',
    `model_used`      VARCHAR(30)  NULL,
    `tokens_in`       INT UNSIGNED NULL,
    `tokens_out`      INT UNSIGNED NULL,
    `cost_eur`        DECIMAL(8,4) NULL,
    `validated`       TINYINT(1)   NOT NULL DEFAULT 0,
    `validated_by`    INT UNSIGNED NULL,
    `validated_at`    DATETIME     NULL,
    `review_flags`    TEXT         NULL COMMENT 'Liste des alertes auto (ex: charges_suspectes)',
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_manifest` (`id_manifest`),
    KEY `idx_famille` (`famille_doc`),
    KEY `idx_validated` (`validated`),
    CONSTRAINT `fk_extract_manifest` FOREIGN KEY (`id_manifest`) REFERENCES `ged_manifest`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- PHASE 4 : Suivi upload FTP / bascule prod
CREATE TABLE IF NOT EXISTS `ged_upload_log` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `id_manifest`     INT UNSIGNED NOT NULL,
    `target_env`      ENUM('dev','prod') NOT NULL DEFAULT 'prod',
    `target_path`     VARCHAR(500) NOT NULL COMMENT 'Chemin cible sur le serveur',
    `md5_verifie`     CHAR(32)     NULL COMMENT 'MD5 recalculé après upload pour verif',
    `status`          ENUM('pending','ok','failed','skipped') NOT NULL DEFAULT 'pending',
    `error_msg`       TEXT         NULL,
    `started_at`      DATETIME     NULL,
    `completed_at`    DATETIME     NULL,
    `retry_count`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_manifest` (`id_manifest`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_upload_manifest` FOREIGN KEY (`id_manifest`) REFERENCES `ged_manifest`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL,
];
