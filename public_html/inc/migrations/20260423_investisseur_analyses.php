<?php
/**
 * Migration : module Analyse, Arbitrage et Pilotage Investisseur — V1
 *
 * Crée la table `investisseur_analyses` qui stocke une fiche d'analyse
 * complète par bien étudié (saisie + calculs + scoring + interprétations).
 *
 * Le module est autonome : pas de FK vers `biens` (une analyse peut être
 * faite AVANT qu'un bien soit créé dans la base). On garde juste un champ
 * texte `reference_bien` pour le lien métier.
 */

return [
    'id'          => '20260423_investisseur_analyses',
    'title'       => 'Module Investisseur : table investisseur_analyses (V1)',
    'description' => "Crée la table principale du module Analyse, Arbitrage et Pilotage Investisseur : saisie bien + calculs rendements/cashflow + scoring + argumentaires + synthèse.",
    'created_at'  => '2026-04-23',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `investisseur_analyses` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_societe` INT(10) UNSIGNED NULL,
    `id_agence`  INT(10) UNSIGNED NULL,
    `id_user`    INT(10) UNSIGNED NULL,

    -- Lien optionnel vers un bien existant (réutilisation CRG + arbitrage)
    `id_bien_source`     INT(10) UNSIGNED NULL COMMENT 'biens.id — pré-remplissage depuis CRG/arbitrage',
    `id_proprietaire`    INT(10) UNSIGNED NULL COMMENT 'proprietaires.id — si bien déjà rattaché',

    -- Identification
    `titre_analyse`        VARCHAR(200) NOT NULL,
    `reference_bien`       VARCHAR(80)  NULL,
    `type_bien`            VARCHAR(50)  NULL,
    `ville`                VARCHAR(150) NULL,
    `quartier`             VARCHAR(150) NULL,
    `adresse`              VARCHAR(255) NULL,
    `surface`              DECIMAL(10,2) NULL,
    `nb_pieces`            TINYINT UNSIGNED NULL,
    `etage`                VARCHAR(20)  NULL,
    `etat_general`         VARCHAR(40)  NULL,
    `annee_construction`   SMALLINT UNSIGNED NULL,
    `exterieur`            VARCHAR(80)  NULL,
    `cave`                 TINYINT(1) NOT NULL DEFAULT 0,
    `garage`               TINYINT(1) NOT NULL DEFAULT 0,
    `parking`              TINYINT(1) NOT NULL DEFAULT 0,
    `dpe`                  CHAR(1) NULL,
    `ges`                  CHAR(1) NULL,

    -- Acquisition
    `prix_achat`           DECIMAL(12,2) NULL,
    `frais_notaire`        DECIMAL(12,2) NULL,
    `frais_agence`         DECIMAL(12,2) NULL,
    `travaux`              DECIMAL(12,2) NULL,
    `ameublement`          DECIMAL(12,2) NULL,
    `cout_total`           DECIMAL(12,2) NULL,
    `apport`               DECIMAL(12,2) NULL,
    `montant_finance`      DECIMAL(12,2) NULL,
    `taux_credit`          DECIMAL(5,3)  NULL COMMENT 'en %, ex 3.5',
    `duree_credit`         TINYINT UNSIGNED NULL COMMENT 'années',
    `mensualite_credit`    DECIMAL(10,2) NULL,

    -- Exploitation locative (tous en mensuel ou annuel selon champ)
    `loyer_estime`              DECIMAL(10,2) NULL COMMENT 'mensuel HC',
    `charges_recuperables`      DECIMAL(10,2) NULL COMMENT 'mensuel',
    `charges_non_recuperables`  DECIMAL(10,2) NULL COMMENT 'mensuel',
    `taxe_fonciere`             DECIMAL(10,2) NULL COMMENT 'annuelle',
    `assurance_pno`             DECIMAL(10,2) NULL COMMENT 'annuelle',
    `gestion_locative`          DECIMAL(5,2)  NULL COMMENT '% du loyer',
    `vacance_locative`          DECIMAL(5,2)  NULL COMMENT '% annuelle',
    `entretien_imprevus`        DECIMAL(5,2)  NULL COMMENT '% du loyer annuel',
    `regime_fiscal`             VARCHAR(40)   NULL,
    `type_location`             VARCHAR(40)   NULL,
    `locataire_nom`             VARCHAR(180)  NULL,
    `bail_fin`                  DATE          NULL,
    `photovoltaique`            TINYINT(1) NOT NULL DEFAULT 0,
    `nb_parkings`               TINYINT UNSIGNED NULL,
    `honoraires_vente`          DECIMAL(12,2) NULL COMMENT 'estimation honoraires vente si cession',

    -- Qualité / stratégie
    `strategie`                 VARCHAR(60)  NULL,
    `potentiel_valorisation`    TINYINT UNSIGNED NULL COMMENT '1-5',
    `tension_locative`          TINYINT UNSIGNED NULL COMMENT '1-5',
    `facilite_revente`          TINYINT UNSIGNED NULL COMMENT '1-5',
    `niveau_risque`             TINYINT UNSIGNED NULL COMMENT '1-5',
    `qualite_emplacement`       TINYINT UNSIGNED NULL COMMENT '1-5',
    `commentaire_humain`        TEXT NULL COMMENT 'Terrain / ressenti — poids ~40 pct narration',

    -- Indicateurs calculés (stockés pour tri / perf)
    `revenu_annuel`             DECIMAL(12,2) NULL,
    `charges_annuelles`         DECIMAL(12,2) NULL,
    `rendement_brut`            DECIMAL(6,2) NULL COMMENT '%',
    `rendement_net`             DECIMAL(6,2) NULL COMMENT '%',
    `cashflow_mensuel`          DECIMAL(10,2) NULL,
    `effort_epargne`            DECIMAL(10,2) NULL COMMENT 'si négatif = cashflow à charge',
    `projection_10_ans`         DECIMAL(12,2) NULL COMMENT 'cashflow cumulé estimé 10 ans',
    `score_risque`              TINYINT UNSIGNED NULL COMMENT '0-100 (100 = risque faible)',
    `score_attractivite`        TINYINT UNSIGNED NULL COMMENT '0-100',
    `score_global`              TINYINT UNSIGNED NULL COMMENT '0-100',
    `prix_m2`                   DECIMAL(10,2) NULL COMMENT 'prix_achat / surface',
    `multiple_loyer`            DECIMAL(6,2)  NULL COMMENT 'prix_achat / revenu_annuel',

    -- Interprétations (générées)
    `synthese`                  MEDIUMTEXT NULL,
    `forces_txt`                TEXT NULL,
    `faiblesses_txt`            TEXT NULL,
    `risques_txt`               TEXT NULL,
    `opportunites_txt`          TEXT NULL,
    `reco_finale`               VARCHAR(60) NULL,
    `argumentaire_prudent`      MEDIUMTEXT NULL,
    `argumentaire_equilibre`    MEDIUMTEXT NULL,
    `argumentaire_offensif`     MEDIUMTEXT NULL,
    `presentation_client`       MEDIUMTEXT NULL,

    `statut`                    ENUM('brouillon','finalisee','archivee') NOT NULL DEFAULT 'brouillon',
    `created_at`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_societe` (`id_societe`),
    KEY `idx_agence`  (`id_agence`),
    KEY `idx_user`    (`id_user`),
    KEY `idx_bien_source`   (`id_bien_source`),
    KEY `idx_proprietaire`  (`id_proprietaire`),
    KEY `idx_score_global` (`score_global`),
    KEY `idx_updated` (`updated_at`),
    KEY `idx_statut`  (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];
