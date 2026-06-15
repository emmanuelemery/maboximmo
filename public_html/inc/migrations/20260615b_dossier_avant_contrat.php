<?php
/**
 * Migration : avant-contrat (compromis / promesse de vente) + sélection des lots.
 *
 * `dossier_avant_contrat`     : 1 acte (compromis|promesse), N possibles par dossier.
 *                               Ne stocke QUE le spécifique avant-contrat (le reste = référence :
 *                               vendeur/acquéreur/notaire via tiers_roles, prix via dossier_vente_bien,
 *                               honoraires via mandats, désignation via biens/immeubles).
 * `dossier_avant_contrat_lot` : pivot — lots du dossier RETENUS dans l'acte (sélection),
 *                               avec prix_acte optionnel (override du prix mandat).
 *
 * Idempotent (CREATE TABLE IF NOT EXISTS). down = rollback documenté.
 */
return [
    'id'          => '20260615b_dossier_avant_contrat',
    'title'       => 'Avant-contrat (compromis/promesse) + lots',
    'description' => "Crée dossier_avant_contrat (compromis|promesse : dépôt, conditions suspensives, SRU, jouissance, mobilier…) + dossier_avant_contrat_lot (sélection des lots de l'acte). Aucun doublon avec biens/mandats/tiers_roles/dossier_vente_bien.",
    'created_at'  => '2026-06-15',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `dossier_avant_contrat` (
    `id`                 BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_dossier`         BIGINT(20) UNSIGNED NOT NULL,
    `type`               ENUM('compromis','promesse_unilaterale') NOT NULL DEFAULT 'compromis',
    `copro`              TINYINT(1) NOT NULL DEFAULT 0,            -- 0 hors copro / 1 copro
    `statut`             ENUM('brouillon','signe','caduc','realise','annule') NOT NULL DEFAULT 'brouillon',
    `reference`          VARCHAR(50) NULL,
    `date_signature`     DATE NULL,
    `date_reiteration_max` DATE NULL,                             -- date limite acte authentique
    `lieu_signature`     VARCHAR(120) NULL,
    -- Dépôt / séquestre / promesse
    `depot_garantie_montant`   DECIMAL(12,2) NULL,
    `depot_garantie_pct`       DECIMAL(5,2)  NULL,
    `sequestre_type`           ENUM('notaire','agence','aucun') NULL,
    `indemnite_immobilisation` DECIMAL(12,2) NULL,                -- promesse uniquement
    `date_levee_option`        DATE NULL,                         -- promesse uniquement
    -- Condition suspensive de prêt
    `cs_pret`            TINYINT(1) NOT NULL DEFAULT 1,
    `pret_montant`       DECIMAL(12,2) NULL,
    `pret_duree_mois`    SMALLINT(5) UNSIGNED NULL,
    `pret_taux_max`      DECIMAL(5,2) NULL,
    `pret_nb_offres`     TINYINT(3) UNSIGNED NULL,
    `pret_date_limite`   DATE NULL,
    `pret_apport`        DECIMAL(12,2) NULL,
    `pret_organismes`    VARCHAR(255) NULL,
    -- Autres conditions suspensives
    `cs_preemption`      TINYINT(1) NOT NULL DEFAULT 0,
    `cs_preemption_detail` VARCHAR(255) NULL,
    `cs_servitudes`      TINYINT(1) NOT NULL DEFAULT 0,
    `cs_urbanisme`       TINYINT(1) NOT NULL DEFAULT 0,
    `cs_hypotheques`     TINYINT(1) NOT NULL DEFAULT 0,
    `cs_vente_bien_acquereur` TINYINT(1) NOT NULL DEFAULT 0,
    `cs_autres`          TEXT NULL,
    -- Jouissance / mobilier
    `date_entree_jouissance` DATE NULL,
    `occupation`         ENUM('libre','occupe','loue') NULL,
    `mobilier_inclus`    TINYINT(1) NOT NULL DEFAULT 0,
    `mobilier_valeur`    DECIMAL(12,2) NULL,
    `mobilier_detail`    TEXT NULL,
    -- Acte / frais / rédacteur
    `notaire_redacteur`  ENUM('vendeur','acquereur','commun') NULL,
    `frais_acte_charge`  ENUM('acquereur','vendeur','partage') NOT NULL DEFAULT 'acquereur',
    -- Rétractation SRU (acquéreur)
    `sru_date_notification`     DATE NULL,
    `sru_date_fin_retractation` DATE NULL,
    -- Divers
    `conditions_particulieres`  TEXT NULL,
    `id_user`            INT(10) UNSIGNED NULL,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_dossier` (`id_dossier`),
    KEY `idx_statut` (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dossier_avant_contrat_lot` (
    `id`                 BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_avant_contrat`   BIGINT(20) UNSIGNED NOT NULL,
    `id_bien`            INT(10) UNSIGNED NOT NULL,
    `prix_acte`          DECIMAL(12,2) NULL,                      -- override éventuel (sinon prix dossier_vente_bien)
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ac_lot` (`id_avant_contrat`, `id_bien`),
    KEY `idx_ac` (`id_avant_contrat`),
    KEY `idx_bien` (`id_bien`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
    ,
    'down' => <<<'SQL'
DROP TABLE IF EXISTS `dossier_avant_contrat_lot`;
DROP TABLE IF EXISTS `dossier_avant_contrat`;
SQL
];
