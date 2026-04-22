<?php
/**
 * Migration : commentaires orientés d'une analyse investisseur
 *
 * Permet au conseiller d'ajouter plusieurs entrées de commentaires,
 * typées (force / faiblesse / risque / opportunite / instruction_ia / neutre)
 * et orientées (positif / neutre / negatif). Ces commentaires sont ré-injectés
 * dans le moteur d'interprétation et servent aussi de directives pour une
 * future génération IA d'argumentaire.
 *
 * Rattachement :
 *   - soit à une analyse (id_analyse)
 *   - soit directement à un bien de la base (id_bien) — utile pour capitaliser
 *     des commentaires terrain avant même d'avoir créé l'analyse.
 */

return [
    'id'          => '20260423_investisseur_commentaires',
    'title'       => 'Module Investisseur : commentaires orientés (+ hook IA)',
    'description' => "Table investisseur_commentaires : notes terrain catégorisées qui orientent la synthèse, les argumentaires et la future génération IA.",
    'created_at'  => '2026-04-23',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `investisseur_commentaires` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_societe` INT(10) UNSIGNED NULL,
    `id_agence`  INT(10) UNSIGNED NULL,
    `id_user`    INT(10) UNSIGNED NULL,

    `id_analyse`  INT(10) UNSIGNED NULL COMMENT 'investisseur_analyses.id',
    `id_bien`     INT(10) UNSIGNED NULL COMMENT 'biens.id — commentaire attaché directement à un bien',

    `categorie` ENUM('force','faiblesse','risque','opportunite','instruction_ia','neutre')
                NOT NULL DEFAULT 'neutre'
                COMMENT 'oriente l''intégration dans le texte final',
    `orientation` ENUM('positif','neutre','negatif') NOT NULL DEFAULT 'neutre',
    `poids` TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT 'de 1 (info) à 5 (déterminant)',

    `titre`   VARCHAR(180) NULL,
    `contenu` TEXT NOT NULL,

    `actif` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = archivé / ignoré par l''IA',

    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_analyse`     (`id_analyse`),
    KEY `idx_bien`        (`id_bien`),
    KEY `idx_societe`     (`id_societe`),
    KEY `idx_categorie`   (`categorie`),
    KEY `idx_actif`       (`actif`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];
