-- ════════════════════════════════════════════════════════════════
-- Migration 2026-04-22 : Honoraires location ALUR + zone tendue + loyer CC
-- ════════════════════════════════════════════════════════════════
-- Objectifs :
--   1. Table de config des tarifs honoraires par zone tendue et société
--   2. Table de lookup CP → zone tendue (auto-fill à la saisie d'adresse)
--   3. Nouvelle colonne annonces.honoraires_location_bail
--      (car les honoraires concernent l'annonce pas le bien)
-- À lancer sur la base prod via phpMyAdmin après déploiement code.

-- ── 1. Table de config des tarifs honoraires par zone ─────────────
CREATE TABLE IF NOT EXISTS `societe_tarifs_honoraires` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_societe` INT(10) UNSIGNED NULL COMMENT 'NULL = tarifs par défaut (plafonds ALUR)',
    `zone_tendue` ENUM('non_tendue','tendue','tres_tendue') NOT NULL,
    `honoraires_location_bail_m2` DECIMAL(6,2) NOT NULL DEFAULT 0
        COMMENT '€/m² pour honoraires location + bail (plafond ALUR : 8/10/12 €/m²)',
    `honoraires_edl_m2` DECIMAL(6,2) NOT NULL DEFAULT 3
        COMMENT '€/m² pour état des lieux (plafond ALUR : 3 €/m² toutes zones)',
    `actif` TINYINT(1) NOT NULL DEFAULT 1,
    `date_creation` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `date_modification` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_societe_zone` (`id_societe`, `zone_tendue`),
    KEY `idx_zone` (`zone_tendue`),
    CONSTRAINT `fk_tarifs_societe` FOREIGN KEY (`id_societe`) REFERENCES `societes`(`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tarifs plafond ALUR par défaut (id_societe NULL = fallback universel)
INSERT IGNORE INTO `societe_tarifs_honoraires` (`id_societe`, `zone_tendue`, `honoraires_location_bail_m2`, `honoraires_edl_m2`) VALUES
(NULL, 'non_tendue',   8.00, 3.00),
(NULL, 'tendue',      10.00, 3.00),
(NULL, 'tres_tendue', 12.00, 3.00);

-- ── 2. Table de lookup CP → zone tendue (auto-fill au changement d'adresse) ─────────
CREATE TABLE IF NOT EXISTS `base_zones_tendues` (
    `code_postal` VARCHAR(10) NOT NULL,
    `zone_tendue` ENUM('non_tendue','tendue','tres_tendue') NOT NULL DEFAULT 'non_tendue',
    `commune` VARCHAR(200) NULL,
    `source` VARCHAR(50) NULL COMMENT 'ex: arrete_2023, insee_officiel…',
    PRIMARY KEY (`code_postal`),
    KEY `idx_zone` (`zone_tendue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Données initiales : Paris + petite couronne (très tendue) + Lyon/Villeurbanne (tendue)
-- + quelques autres grandes métropoles en tendue.
-- À étendre avec la liste officielle des 1151 communes en zone tendue.
INSERT IGNORE INTO `base_zones_tendues` (`code_postal`, `zone_tendue`, `commune`, `source`) VALUES
-- Paris intra-muros
('75001','tres_tendue','Paris 1er','arrete_alur'),
('75002','tres_tendue','Paris 2e','arrete_alur'),
('75003','tres_tendue','Paris 3e','arrete_alur'),
('75004','tres_tendue','Paris 4e','arrete_alur'),
('75005','tres_tendue','Paris 5e','arrete_alur'),
('75006','tres_tendue','Paris 6e','arrete_alur'),
('75007','tres_tendue','Paris 7e','arrete_alur'),
('75008','tres_tendue','Paris 8e','arrete_alur'),
('75009','tres_tendue','Paris 9e','arrete_alur'),
('75010','tres_tendue','Paris 10e','arrete_alur'),
('75011','tres_tendue','Paris 11e','arrete_alur'),
('75012','tres_tendue','Paris 12e','arrete_alur'),
('75013','tres_tendue','Paris 13e','arrete_alur'),
('75014','tres_tendue','Paris 14e','arrete_alur'),
('75015','tres_tendue','Paris 15e','arrete_alur'),
('75016','tres_tendue','Paris 16e','arrete_alur'),
('75017','tres_tendue','Paris 17e','arrete_alur'),
('75018','tres_tendue','Paris 18e','arrete_alur'),
('75019','tres_tendue','Paris 19e','arrete_alur'),
('75020','tres_tendue','Paris 20e','arrete_alur'),
-- Lyon + Villeurbanne
('69001','tendue','Lyon 1er','arrete_alur'),
('69002','tendue','Lyon 2e','arrete_alur'),
('69003','tendue','Lyon 3e','arrete_alur'),
('69004','tendue','Lyon 4e','arrete_alur'),
('69005','tendue','Lyon 5e','arrete_alur'),
('69006','tendue','Lyon 6e','arrete_alur'),
('69007','tendue','Lyon 7e','arrete_alur'),
('69008','tendue','Lyon 8e','arrete_alur'),
('69009','tendue','Lyon 9e','arrete_alur'),
('69100','tendue','Villeurbanne','arrete_alur'),
-- Bordeaux
('33000','tendue','Bordeaux','arrete_alur'),
('33100','tendue','Bordeaux','arrete_alur'),
('33200','tendue','Bordeaux','arrete_alur'),
('33300','tendue','Bordeaux','arrete_alur'),
('33800','tendue','Bordeaux','arrete_alur'),
-- Lille + métropole
('59000','tendue','Lille','arrete_alur'),
('59160','tendue','Lomme','arrete_alur'),
('59260','tendue','Hellemmes','arrete_alur'),
('59800','tendue','Lille','arrete_alur'),
-- Marseille
('13001','tendue','Marseille 1','arrete_alur'),
('13002','tendue','Marseille 2','arrete_alur'),
('13003','tendue','Marseille 3','arrete_alur'),
('13004','tendue','Marseille 4','arrete_alur'),
('13005','tendue','Marseille 5','arrete_alur'),
('13006','tendue','Marseille 6','arrete_alur'),
('13007','tendue','Marseille 7','arrete_alur'),
('13008','tendue','Marseille 8','arrete_alur'),
('13009','tendue','Marseille 9','arrete_alur'),
('13010','tendue','Marseille 10','arrete_alur'),
('13011','tendue','Marseille 11','arrete_alur'),
('13012','tendue','Marseille 12','arrete_alur'),
('13013','tendue','Marseille 13','arrete_alur'),
('13014','tendue','Marseille 14','arrete_alur'),
('13015','tendue','Marseille 15','arrete_alur'),
('13016','tendue','Marseille 16','arrete_alur'),
-- Toulouse
('31000','tendue','Toulouse','arrete_alur'),
('31100','tendue','Toulouse','arrete_alur'),
('31200','tendue','Toulouse','arrete_alur'),
('31300','tendue','Toulouse','arrete_alur'),
('31400','tendue','Toulouse','arrete_alur'),
('31500','tendue','Toulouse','arrete_alur'),
-- Nantes
('44000','tendue','Nantes','arrete_alur'),
('44100','tendue','Nantes','arrete_alur'),
('44200','tendue','Nantes','arrete_alur'),
('44300','tendue','Nantes','arrete_alur'),
-- Rennes
('35000','tendue','Rennes','arrete_alur'),
('35200','tendue','Rennes','arrete_alur'),
('35700','tendue','Rennes','arrete_alur'),
-- Nice
('06000','tendue','Nice','arrete_alur'),
('06100','tendue','Nice','arrete_alur'),
('06200','tendue','Nice','arrete_alur'),
('06300','tendue','Nice','arrete_alur'),
-- Strasbourg
('67000','tendue','Strasbourg','arrete_alur'),
('67100','tendue','Strasbourg','arrete_alur'),
('67200','tendue','Strasbourg','arrete_alur'),
-- Grenoble
('38000','tendue','Grenoble','arrete_alur'),
('38100','tendue','Grenoble','arrete_alur');

-- ── 3. Nouvelle colonne annonces.honoraires_location_bail ─────────
ALTER TABLE `annonces`
ADD COLUMN `honoraires_location_bail` DECIMAL(10,2) NULL
    COMMENT 'Honoraires location + bail (€, TTC). Plafonné ALUR = surface × tarif par zone. Additionné à honoraires_etat_des_lieux pour le total locataire envoyé à Ubiflow.'
AFTER `honoraires_etat_des_lieux`;
