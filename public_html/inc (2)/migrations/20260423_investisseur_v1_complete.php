<?php
/**
 * Migration CONSOLIDÉE V1 — Module Investisseur complet.
 *
 * Cette migration est idempotente (IF NOT EXISTS partout) et peut être jouée
 * sur une base vierge ou sur une base où certaines sous-migrations ont déjà tourné.
 *
 * Pour PROD : on joue UNIQUEMENT celle-ci (ne pas rejouer les 7 migrations
 * fragmentées du 23/04/2026 qui étaient les étapes intermédiaires dev).
 *
 * Contenu :
 *   1. investisseur_analyses            (88 colonnes)
 *   2. investisseur_commentaires        (commentaires orientés + hook IA)
 *   3. investisseur_valo_scenarios      (scénarios de valorisation)
 *   4. investisseur_valo_snapshots      (snapshots figés historique)
 *   5. investisseur_partages            (liens magiques)
 *   6. investisseur_contacts_externes   (gérants / propriétaires externes)
 *   7. investisseur_contacts_proprietaires (liaison contact ↔ SCI)
 *   + ALTER sur biens_documents / biens_photos / bailleur_documents / documents_sir
 *     (colonnes visible_proprietaire, uploaded_by_externe, id_partage_source)
 */

return [
    'id'          => '20260423_investisseur_v1_complete',
    'title'       => 'Module Investisseur V1 — migration consolidée (prod-ready)',
    'description' => "Crée les 7 tables du module Investisseur (analyses, commentaires, valo, partages, contacts) + ajoute les colonnes de visibilité propriétaire sur les tables de documents existantes.",
    'created_at'  => '2026-04-23',
    'sql' => <<<'SQL'
-- ═══════════════════════════════════════════════════════════════════════════
-- 1. Table principale des analyses investisseur
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `investisseur_analyses` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_societe` INT(10) UNSIGNED NULL,
    `id_agence`  INT(10) UNSIGNED NULL,
    `id_user`    INT(10) UNSIGNED NULL,
    `id_bien_source`     INT(10) UNSIGNED NULL COMMENT 'biens.id — pré-remplissage depuis CRG/arbitrage',
    `id_proprietaire`    INT(10) UNSIGNED NULL,
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
    `prix_achat`           DECIMAL(12,2) NULL COMMENT 'Prix négocié retenu pour les calculs',
    `prix_vente_catalogue` DECIMAL(12,2) NULL COMMENT 'Prix demandé par le vendeur (mandat)',
    `frais_notaire`        DECIMAL(12,2) NULL,
    `frais_agence`         DECIMAL(12,2) NULL,
    `travaux`              DECIMAL(12,2) NULL,
    `ameublement`          DECIMAL(12,2) NULL,
    `cout_total`           DECIMAL(12,2) NULL,
    `apport`               DECIMAL(12,2) NULL,
    `montant_finance`      DECIMAL(12,2) NULL,
    `taux_credit`          DECIMAL(5,3)  NULL,
    `duree_credit`         TINYINT UNSIGNED NULL,
    `mensualite_credit`    DECIMAL(10,2) NULL,
    `credit_crd`           DECIMAL(12,2) NULL COMMENT 'Capital restant dû si crédit en cours',
    `credit_duree_restante_mois` SMALLINT UNSIGNED NULL,
    `revalorisation_bien_pct_an` DECIMAL(5,2) NULL DEFAULT 1.50,
    `indexation_loyer_pct_an`    DECIMAL(5,2) NULL DEFAULT 1.00,
    `ira_pct`              DECIMAL(5,2) NULL DEFAULT 3.00,
    `taux_imposition_pct`  DECIMAL(5,2) NULL COMMENT 'NULL = calculs brut avant impôt',
    `loyer_estime`              DECIMAL(10,2) NULL COMMENT 'mensuel HC',
    `charges_recuperables`      DECIMAL(10,2) NULL,
    `charges_non_recuperables`  DECIMAL(10,2) NULL,
    `taxe_fonciere`             DECIMAL(10,2) NULL,
    `assurance_pno`             DECIMAL(10,2) NULL,
    `gestion_locative`          DECIMAL(5,2)  NULL,
    `vacance_locative`          DECIMAL(5,2)  NULL,
    `entretien_imprevus`        DECIMAL(5,2)  NULL,
    `regime_fiscal`             VARCHAR(40)   NULL,
    `type_location`             VARCHAR(40)   NULL,
    `locataire_nom`             VARCHAR(180)  NULL,
    `bail_fin`                  DATE          NULL,
    `photovoltaique`            TINYINT(1) NOT NULL DEFAULT 0,
    `nb_parkings`               TINYINT UNSIGNED NULL,
    `honoraires_vente`          DECIMAL(12,2) NULL,
    `strategie`                 VARCHAR(60)  NULL,
    `potentiel_valorisation`    TINYINT UNSIGNED NULL,
    `tension_locative`          TINYINT UNSIGNED NULL,
    `facilite_revente`          TINYINT UNSIGNED NULL,
    `niveau_risque`             TINYINT UNSIGNED NULL,
    `qualite_emplacement`       TINYINT UNSIGNED NULL,
    `commentaire_humain`        TEXT NULL,
    `revenu_annuel`             DECIMAL(12,2) NULL,
    `charges_annuelles`         DECIMAL(12,2) NULL,
    `rendement_brut`            DECIMAL(6,2) NULL,
    `rendement_net`             DECIMAL(6,2) NULL,
    `cashflow_mensuel`          DECIMAL(10,2) NULL,
    `effort_epargne`            DECIMAL(10,2) NULL,
    `projection_10_ans`         DECIMAL(12,2) NULL,
    `score_risque`              TINYINT UNSIGNED NULL,
    `score_attractivite`        TINYINT UNSIGNED NULL,
    `score_global`              TINYINT UNSIGNED NULL,
    `prix_m2`                   DECIMAL(10,2) NULL,
    `multiple_loyer`            DECIMAL(6,2)  NULL,
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

-- ═══════════════════════════════════════════════════════════════════════════
-- 2. Commentaires orientés + hook IA
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `investisseur_commentaires` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_societe` INT(10) UNSIGNED NULL,
    `id_agence`  INT(10) UNSIGNED NULL,
    `id_user`    INT(10) UNSIGNED NULL,
    `id_analyse`  INT(10) UNSIGNED NULL,
    `id_bien`     INT(10) UNSIGNED NULL,
    `categorie` ENUM('force','faiblesse','risque','opportunite','instruction_ia','neutre') NOT NULL DEFAULT 'neutre',
    `orientation` ENUM('positif','neutre','negatif') NOT NULL DEFAULT 'neutre',
    `poids` TINYINT UNSIGNED NOT NULL DEFAULT 3,
    `titre`   VARCHAR(180) NULL,
    `contenu` TEXT NOT NULL,
    `actif` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_analyse`   (`id_analyse`),
    KEY `idx_bien`      (`id_bien`),
    KEY `idx_societe`   (`id_societe`),
    KEY `idx_categorie` (`categorie`),
    KEY `idx_actif`     (`actif`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- 3. Valorisation : scénarios + snapshots (versionning)
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `investisseur_valo_scenarios` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_societe` INT(10) UNSIGNED NULL,
    `id_agence`  INT(10) UNSIGNED NULL,
    `id_user`    INT(10) UNSIGNED NULL,
    `nom_scenario` VARCHAR(160) NOT NULL,
    `description`  TEXT NULL,
    `taux_commercial` DECIMAL(5,2) NOT NULL DEFAULT 7.00,
    `taux_bureau`     DECIMAL(5,2) NOT NULL DEFAULT 6.50,
    `taux_activite`   DECIMAL(5,2) NOT NULL DEFAULT 8.00,
    `taux_immeuble`   DECIMAL(5,2) NOT NULL DEFAULT 5.50,
    `taux_habitation` DECIMAL(5,2) NOT NULL DEFAULT 4.00,
    `taux_parking`    DECIMAL(5,2) NOT NULL DEFAULT 8.00,
    `taux_autre`      DECIMAL(5,2) NOT NULL DEFAULT 7.00,
    `ajust_lyon`       DECIMAL(5,2) NOT NULL DEFAULT -1.00,
    `ajust_metropole`  DECIMAL(5,2) NOT NULL DEFAULT  0.00,
    `ajust_ra`         DECIMAL(5,2) NOT NULL DEFAULT  0.50,
    `ajust_france`     DECIMAL(5,2) NOT NULL DEFAULT  1.50,
    `prixm2_commercial` DECIMAL(10,2) NOT NULL DEFAULT 2000,
    `prixm2_bureau`     DECIMAL(10,2) NOT NULL DEFAULT 2500,
    `prixm2_activite`   DECIMAL(10,2) NOT NULL DEFAULT  900,
    `prixm2_immeuble`   DECIMAL(10,2) NOT NULL DEFAULT 2500,
    `prixm2_habitation` DECIMAL(10,2) NOT NULL DEFAULT 3500,
    `prixm2_parking`    DECIMAL(10,2) NOT NULL DEFAULT  500,
    `prixm2_autre`      DECIMAL(10,2) NOT NULL DEFAULT 1500,
    `ajust_global` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `taux_honoraires` DECIMAL(5,2) NOT NULL DEFAULT 4.00,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user` (`id_user`),
    KEY `idx_societe` (`id_societe`),
    KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `investisseur_valo_snapshots` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_scenario` INT(10) UNSIGNED NOT NULL,
    `id_analyse`  INT(10) UNSIGNED NOT NULL,
    `titre_analyse`  VARCHAR(200) NULL,
    `type_bien`      VARCHAR(80)  NULL,
    `typologie`      VARCHAR(40)  NULL,
    `ville`          VARCHAR(150) NULL,
    `code_postal`    VARCHAR(10)  NULL,
    `secteur`        VARCHAR(40)  NULL,
    `surface`        DECIMAL(10,2) NULL,
    `loyer_annuel`   DECIMAL(12,2) NULL,
    `prix_catalogue` DECIMAL(12,2) NULL,
    `honoraires_catalogue` DECIMAL(12,2) NULL,
    `taux_applique`   DECIMAL(5,2) NULL,
    `prix_theorique`  DECIMAL(12,2) NULL,
    `honoraires_theoriques` DECIMAL(12,2) NULL,
    `prix_total_theorique`  DECIMAL(12,2) NULL,
    `ecart_valeur`    DECIMAL(12,2) NULL,
    `ecart_pct`       DECIMAL(6,2)  NULL,
    `methode`         ENUM('capitalisation','prix_m2','manuel') NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_scenario` (`id_scenario`),
    KEY `idx_analyse`  (`id_analyse`),
    CONSTRAINT `fk_valo_snap_scenario_v1` FOREIGN KEY (`id_scenario`) REFERENCES `investisseur_valo_scenarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- 4. Partages externes (liens magiques)
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `investisseur_partages` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `token`       VARCHAR(64) NOT NULL,
    `type`        ENUM('analyse','scenario','portefeuille','presentation') NOT NULL,
    `id_ref`      INT(10) UNSIGNED NULL,
    `id_contact_externe` INT(10) UNSIGNED NULL,
    `id_societe`  INT(10) UNSIGNED NULL,
    `id_agence`   INT(10) UNSIGNED NULL,
    `id_user_crea` INT(10) UNSIGNED NULL,
    `destinataire_email` VARCHAR(180) NOT NULL,
    `destinataire_nom`   VARCHAR(180) NULL,
    `message`            TEXT NULL,
    `expire_at`    DATETIME NOT NULL,
    `revoque`      TINYINT(1) NOT NULL DEFAULT 0,
    `consulte_at`    DATETIME NULL,
    `consulte_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `last_consult_ip` VARCHAR(45) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_token` (`token`),
    KEY `idx_ref`      (`type`, `id_ref`),
    KEY `idx_societe`  (`id_societe`),
    KEY `idx_contact`  (`id_contact_externe`),
    KEY `idx_expire`   (`expire_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- 5. Contacts externes (gérants / propriétaires externes)
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `investisseur_contacts_externes` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_societe` INT(10) UNSIGNED NULL,
    `id_agence`  INT(10) UNSIGNED NULL,
    `civilite`  VARCHAR(10) NULL,
    `prenom`    VARCHAR(120) NULL,
    `nom`       VARCHAR(120) NOT NULL,
    `email`     VARCHAR(180) NOT NULL,
    `telephone` VARCHAR(30)  NULL,
    `role`      VARCHAR(80)  NOT NULL DEFAULT 'gerant',
    `notes`     TEXT NULL,
    `actif`     TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_email_societe` (`id_societe`, `email`),
    KEY `idx_actif` (`actif`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `investisseur_contacts_proprietaires` (
    `id_contact`       INT(10) UNSIGNED NOT NULL,
    `id_proprietaire`  INT(10) UNSIGNED NOT NULL,
    `role_contact`     VARCHAR(80) NOT NULL DEFAULT 'gerant',
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_contact`, `id_proprietaire`),
    KEY `idx_prop` (`id_proprietaire`),
    CONSTRAINT `fk_contactprop_contact_v1` FOREIGN KEY (`id_contact`) REFERENCES `investisseur_contacts_externes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- 6. Colonnes de visibilité propriétaire sur tables docs existantes
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE `biens_documents`
    ADD COLUMN IF NOT EXISTS `uploaded_by_externe`  TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `id_partage_source`    INT(10) UNSIGNED NULL;

ALTER TABLE `biens_photos`
    ADD COLUMN IF NOT EXISTS `visible_proprietaire` TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS `uploaded_by_externe`  TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `id_partage_source`    INT(10) UNSIGNED NULL;

ALTER TABLE `bailleur_documents`
    ADD COLUMN IF NOT EXISTS `visible_proprietaire` TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS `uploaded_by_externe`  TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `id_partage_source`    INT(10) UNSIGNED NULL;

ALTER TABLE `documents_sir`
    ADD COLUMN IF NOT EXISTS `visible_proprietaire` TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS `uploaded_by_externe`  TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `id_partage_source`    INT(10) UNSIGNED NULL;
SQL,
];
