<?php
/**
 * Migration : accès portefeuille propriétaire externe (type T. Saby pour Groupe SIR)
 *
 *   - Nouvelle table `investisseur_contacts_externes` : personne physique externe
 *     (gérant / mandataire / proprio d'une ou plusieurs SCI)
 *   - Table de liaison `investisseur_contacts_proprietaires` : un contact peut
 *     être rattaché à N propriétaires (SCI)
 *   - Ajoute `visible_proprietaire` sur `biens_photos`, `bailleur_documents`,
 *     `documents_sir` (biens_documents l'a déjà)
 *   - Ajoute `id_contact_externe` à `investisseur_partages` pour lier un lien
 *     à un contact (type='portefeuille' : accès à tous les biens des SCI rattachées)
 *   - Ajoute `uploaded_by_partage` et `uploaded_by_externe` aux tables de docs
 *     pour tracer les uploads du côté propriétaire
 */

return [
    'id'          => '20260423_investisseur_acces_proprio',
    'title'       => 'Investisseur : accès propriétaire externe + portefeuille partagé',
    'description' => "Contacts externes (gérants) + liaison multi-SCI + visibilité documents + upload externe tracé.",
    'created_at'  => '2026-04-23',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `investisseur_contacts_externes` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_societe` INT(10) UNSIGNED NULL,
    `id_agence`  INT(10) UNSIGNED NULL,

    `civilite`  VARCHAR(10) NULL,
    `prenom`    VARCHAR(120) NULL,
    `nom`       VARCHAR(120) NOT NULL,
    `email`     VARCHAR(180) NOT NULL,
    `telephone` VARCHAR(30)  NULL,
    `role`      VARCHAR(80)  NOT NULL DEFAULT 'gerant' COMMENT 'gerant | proprietaire | mandataire | associe',
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
    CONSTRAINT `fk_contactprop_contact` FOREIGN KEY (`id_contact`) REFERENCES `investisseur_contacts_externes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `investisseur_partages`
    ADD COLUMN IF NOT EXISTS `id_contact_externe` INT(10) UNSIGNED NULL
        COMMENT 'Lien vers investisseur_contacts_externes quand type=portefeuille' AFTER `id_ref`;

ALTER TABLE `biens_photos`
    ADD COLUMN IF NOT EXISTS `visible_proprietaire` TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS `uploaded_by_externe`  TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 si uploadé par le propriétaire via lien magique',
    ADD COLUMN IF NOT EXISTS `id_partage_source`    INT(10) UNSIGNED NULL COMMENT 'id du partage utilisé pour l''upload';

ALTER TABLE `bailleur_documents`
    ADD COLUMN IF NOT EXISTS `visible_proprietaire` TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS `uploaded_by_externe`  TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `id_partage_source`    INT(10) UNSIGNED NULL;

ALTER TABLE `documents_sir`
    ADD COLUMN IF NOT EXISTS `visible_proprietaire` TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS `uploaded_by_externe`  TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `id_partage_source`    INT(10) UNSIGNED NULL;

ALTER TABLE `biens_documents`
    ADD COLUMN IF NOT EXISTS `uploaded_by_externe`  TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `id_partage_source`    INT(10) UNSIGNED NULL;
SQL,
];
