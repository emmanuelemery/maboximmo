<?php
/**
 * Migration : cycle de vie « projet de bail » (générateur type mandat) sur bien_baux
 * + table bail_signatures (clone de mandat_signatures).
 *
 * Ajoute le lifecycle projet → envoye → signe (figé) → avenant, le CANDIDAT locataire
 * (avant signature), le lien AVENANT (parent_bail_id), le n° séquentiel, le lien GED,
 * et les toggles des clauses greffées du bail notaire (6 ans fermes, ERP, option d'achat).
 *
 * Règle d'or : ADDITIF + idempotent (ADD COLUMN IF NOT EXISTS / MODIFY rejouable).
 * Les infos société/agence gestionnaire NE sont PAS stockées ici : elles sont lues en BDD
 * (societes/agences) au moment de la génération.
 */
return [
    'id'          => '20260706c_baux_projet_lifecycle',
    'title'       => 'Baux : cycle de vie projet/envoyé/signé/avenant + candidat + bail_signatures',
    'description' => "Étend bien_baux (statuts projet/envoye/signe/avenant, candidat_tiers_id, parent_bail_id, numero_bail séquentiel, ged_document_id, toggles 6 ans fermes/ERP/option d'achat) et crée bail_signatures (tokens OTP d'envoi à signer).",
    'created_at'  => '2026-07-06',
    'sql' => <<<'SQL'
-- ── Cycle de vie : on étend l'enum sans changer le DEFAULT ('actif') pour ne pas
--    casser l'intake IA qui crée des baux déjà actifs. Le create-projet posera 'projet'.
ALTER TABLE `bien_baux`
  MODIFY COLUMN `statut` ENUM('brouillon','projet','envoye','signe','actif','avenant','resilie','expire')
  NOT NULL DEFAULT 'actif';

-- ── Candidat locataire (avant signature) : le bail projet le vise sans toucher au bail actif.
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `candidat_tiers_id` INT(10) UNSIGNED NULL AFTER `id_tiers_locataire`;

-- ── Avenant : un avenant pointe le bail d'origine.
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `parent_bail_id` INT(10) UNSIGNED NULL AFTER `id`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `avenant_numero` SMALLINT UNSIGNED NULL AFTER `parent_bail_id`;

-- ── N° séquentiel (généré à la création, format société), lien GED, dates de cycle.
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `numero_bail` VARCHAR(40) NULL AFTER `reference_bail`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `ged_document_id` BIGINT(20) UNSIGNED NULL AFTER `document_pdf`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `sent_at` DATETIME NULL AFTER `date_signature`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `origin` VARCHAR(30) NULL AFTER `ged_document_id`;

-- ── Clauses greffées du bail notaire (first-class pour la génération PDF + requêtes).
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `duree_ferme_ans` SMALLINT UNSIGNED NULL AFTER `duree_mois`;      -- renonciation 1re triennale (ex. 6)
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `erp_local` TINYINT(1) NOT NULL DEFAULT 0 AFTER `usage_bien`;     -- local recevant du public
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `option_achat` TINYINT(1) NOT NULL DEFAULT 0 AFTER `destination_activite`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `option_achat_prix` DECIMAL(12,2) NULL AFTER `option_achat`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `option_achat_delai_mois` SMALLINT UNSIGNED NULL AFTER `option_achat_prix`;

-- ── Index utiles au cycle de vie.
ALTER TABLE `bien_baux` ADD INDEX IF NOT EXISTS `idx_statut` (`statut`);
ALTER TABLE `bien_baux` ADD INDEX IF NOT EXISTS `idx_candidat` (`candidat_tiers_id`);
ALTER TABLE `bien_baux` ADD INDEX IF NOT EXISTS `idx_parent_bail` (`parent_bail_id`);

-- ── Tokens de signature (clone fonctionnel de mandat_signatures).
CREATE TABLE IF NOT EXISTS `bail_signatures` (
    `id`                 BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_bail`            INT(10) UNSIGNED NOT NULL,
    `id_tiers`           INT(10) UNSIGNED NULL,
    `role_code`          VARCHAR(40) NULL,             -- bailleur / preneur / caution / representant
    `id_societe`         INT(10) UNSIGNED NULL,
    `token`              CHAR(64) NOT NULL,
    `statut`             ENUM('pending','signe','refuse') NOT NULL DEFAULT 'pending',
    `destinataire_email` VARCHAR(190) NULL,
    `nom_signataire`     VARCHAR(190) NULL,
    `lu_approuve`        TINYINT(1) NOT NULL DEFAULT 0,
    `ip`                 VARCHAR(45) NULL,
    `user_agent`         VARCHAR(500) NULL,
    `signature_data`     TEXT NULL,
    `sent_at`            DATETIME NULL,
    `signed_at`          DATETIME NULL,
    `id_user_created`    INT(10) UNSIGNED NULL,
    `created_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_token` (`token`),
    KEY `idx_bail` (`id_bail`),
    KEY `idx_statut` (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
];
