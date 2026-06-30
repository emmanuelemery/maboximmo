<?php
/**
 * Migration : table `mandats_registre` — registre légal des mandats de gestion (loi Hoguet,
 * art. 65 décret 72-678) + données extraites par IA depuis les mandats PDF en GED.
 *
 * 1 ligne = 1 mandat (lié à un ged_documents PDF + au propriétaire/tiers).
 * Le « numéro de registre » = le N° porté par le mandat lui-même (ex. 6804), extrait du PDF.
 * Le statut (actif/terminé) est calculé depuis date_effet + durée + reconduction/résiliation.
 *
 * Idempotent : CREATE TABLE IF NOT EXISTS.
 */
return [
    'id'          => '20260610_mandats_registre',
    'title'       => 'Registre des mandats de gestion (+ données IA)',
    'description' => "Crée mandats_registre : 1 mandat = 1 PDF GED + proprio, n° de registre, dates (effet/fin théorique), reconduction, honoraires, statut actif/terminé. Alimentée par l'extraction IA.",
    'created_at'  => '2026-06-10',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `mandats_registre` (
    `id`                         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `ged_document_id`            BIGINT(20) UNSIGNED NULL,
    `id_proprietaire`            INT(11) NULL,
    `id_tiers`                   INT(11) NULL,
    `numero_mandat`              VARCHAR(40)  NULL,          -- N° porté par le mandat (= n° registre)
    `mandataire`                 VARCHAR(255) NULL,
    `mandant_noms`               VARCHAR(500) NULL,
    `bien_designation`           VARCHAR(500) NULL,
    `date_effet`                 DATE NULL,
    `duree_initiale_ans`         SMALLINT NULL,
    `duree_ferme`                TINYINT(1) NOT NULL DEFAULT 0,
    `tacite_reconduction`        TINYINT(1) NOT NULL DEFAULT 0,
    `periode_reconduction_ans`   SMALLINT NULL,
    `date_fin_theorique`         DATE NULL,
    `preavis_resiliation_mois`   SMALLINT NULL,
    `date_resiliation`           DATE NULL,
    `hono_gestion_taux_ht`       DECIMAL(6,3) NULL,
    `hono_gestion_taux_ttc`      DECIMAL(6,3) NULL,
    `hono_gestion_assiette`      VARCHAR(120) NULL,
    `hono_location`              VARCHAR(255) NULL,
    `hono_location_remise_pct`   DECIMAL(5,2) NULL,
    `hono_contentieux`           VARCHAR(120) NULL,
    `hono_declaration_fiscale_eur` DECIMAL(8,2) NULL,
    `crg_periodicite`            VARCHAR(40) NULL,
    `statut`                     ENUM('actif','termine','inconnu') NOT NULL DEFAULT 'inconnu',
    `extraction_modele`          VARCHAR(60) NULL,
    `extraction_cout_centimes`   INT NULL,
    `extraction_confidence`      TINYINT NULL,
    `extraction_raw`             MEDIUMTEXT NULL,
    `extracted_at`               DATETIME NULL,
    `created_at`                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ged_document` (`ged_document_id`),
    KEY `idx_proprio` (`id_proprietaire`),
    KEY `idx_tiers` (`id_tiers`),
    KEY `idx_numero` (`numero_mandat`),
    KEY `idx_statut` (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
];
