<?php
/**
 * Migration : valorisation pilotable du parc investisseur (+ versionning)
 *
 * Deux tables :
 *   - investisseur_valo_scenarios : un scénario = un jeu de paramètres
 *     (taux de capitalisation par typologie, ajustements secteur, honoraires, etc.)
 *     Chaque user peut avoir ses propres scénarios nommés.
 *
 *   - investisseur_valo_snapshots : pour chaque bien inclus dans un scénario,
 *     on fige les valeurs calculées à l'instant T (versionning historique
 *     des prix et honoraires théoriques vs catalogue).
 */

return [
    'id'          => '20260423_investisseur_valorisation',
    'title'       => 'Investisseur : scénarios de valorisation + snapshots (versionning)',
    'description' => "Moteur de valorisation capitalisation × secteur avec scénarios nommés et snapshots figés par bien pour l'historique.",
    'created_at'  => '2026-04-23',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `investisseur_valo_scenarios` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_societe` INT(10) UNSIGNED NULL,
    `id_agence`  INT(10) UNSIGNED NULL,
    `id_user`    INT(10) UNSIGNED NULL,

    `nom_scenario` VARCHAR(160) NOT NULL,
    `description`  TEXT NULL,

    -- Taux de capitalisation par typologie (en %)
    -- Valeur théorique = loyer_annuel / (taux / 100)
    `taux_commercial` DECIMAL(5,2) NOT NULL DEFAULT 7.00,
    `taux_bureau`     DECIMAL(5,2) NOT NULL DEFAULT 6.50,
    `taux_activite`   DECIMAL(5,2) NOT NULL DEFAULT 8.00,
    `taux_immeuble`   DECIMAL(5,2) NOT NULL DEFAULT 5.50,
    `taux_habitation` DECIMAL(5,2) NOT NULL DEFAULT 4.00,
    `taux_parking`    DECIMAL(5,2) NOT NULL DEFAULT 8.00,
    `taux_autre`      DECIMAL(5,2) NOT NULL DEFAULT 7.00,

    -- Ajustement secteur (additif sur taux : +1 = taux augmenté d'1 point = prix plus bas)
    `ajust_lyon`       DECIMAL(5,2) NOT NULL DEFAULT -1.00 COMMENT 'Lyon intra 69001-69009',
    `ajust_metropole`  DECIMAL(5,2) NOT NULL DEFAULT  0.00 COMMENT 'Métropole Lyon 69xxx hors centre',
    `ajust_ra`         DECIMAL(5,2) NOT NULL DEFAULT  0.50 COMMENT 'Auvergne-Rhône-Alpes hors Lyon',
    `ajust_france`     DECIMAL(5,2) NOT NULL DEFAULT  1.50 COMMENT 'France hors région',

    -- Prix plancher m² (biens sans loyer → valeur = surface × prix_m2)
    `prixm2_commercial` DECIMAL(10,2) NOT NULL DEFAULT 2000,
    `prixm2_bureau`     DECIMAL(10,2) NOT NULL DEFAULT 2500,
    `prixm2_activite`   DECIMAL(10,2) NOT NULL DEFAULT  900,
    `prixm2_immeuble`   DECIMAL(10,2) NOT NULL DEFAULT 2500,
    `prixm2_habitation` DECIMAL(10,2) NOT NULL DEFAULT 3500,
    `prixm2_parking`    DECIMAL(10,2) NOT NULL DEFAULT  500,
    `prixm2_autre`      DECIMAL(10,2) NOT NULL DEFAULT 1500,

    -- Levier global (additif sur le taux final, en % — 0 = neutre)
    `ajust_global` DECIMAL(5,2) NOT NULL DEFAULT 0.00,

    -- Honoraires vente (% du prix théorique)
    `taux_honoraires` DECIMAL(5,2) NOT NULL DEFAULT 4.00,

    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_user`     (`id_user`),
    KEY `idx_societe`  (`id_societe`),
    KEY `idx_created`  (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `investisseur_valo_snapshots` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_scenario` INT(10) UNSIGNED NOT NULL,
    `id_analyse`  INT(10) UNSIGNED NOT NULL,

    -- Snapshot des champs bien à l'instant T
    `titre_analyse`  VARCHAR(200) NULL,
    `type_bien`      VARCHAR(80)  NULL,
    `typologie`      VARCHAR(40)  NULL COMMENT 'commercial/bureau/activite/immeuble/habitation/parking/autre',
    `ville`          VARCHAR(150) NULL,
    `code_postal`    VARCHAR(10)  NULL,
    `secteur`        VARCHAR(40)  NULL COMMENT 'lyon/metropole/ra/france',
    `surface`        DECIMAL(10,2) NULL,
    `loyer_annuel`   DECIMAL(12,2) NULL,
    `prix_catalogue` DECIMAL(12,2) NULL,
    `honoraires_catalogue` DECIMAL(12,2) NULL,

    -- Calculés par le scénario
    `taux_applique`   DECIMAL(5,2) NULL,
    `prix_theorique`  DECIMAL(12,2) NULL,
    `honoraires_theoriques` DECIMAL(12,2) NULL,
    `prix_total_theorique`  DECIMAL(12,2) NULL,
    `ecart_valeur`    DECIMAL(12,2) NULL COMMENT 'prix_theorique - prix_catalogue',
    `ecart_pct`       DECIMAL(6,2)  NULL,
    `methode`         ENUM('capitalisation','prix_m2','manuel') NULL,

    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_scenario` (`id_scenario`),
    KEY `idx_analyse`  (`id_analyse`),
    CONSTRAINT `fk_valo_snap_scenario` FOREIGN KEY (`id_scenario`) REFERENCES `investisseur_valo_scenarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];
