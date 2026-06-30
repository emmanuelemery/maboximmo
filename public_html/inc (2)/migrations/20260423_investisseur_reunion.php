<?php
/**
 * Migration : mode réunion investisseur
 *
 *   - commentaire_reunion (TEXT) : notes saisies durant la réunion d'arbitrage
 *   - taux_renta_retenu    : taux de rentabilité retenu pour le bien
 *   - table investisseur_audios : stockage des enregistrements vocaux par analyse
 */

return [
    'id'          => '20260423_investisseur_reunion',
    'title'       => 'Investisseur : mode réunion (commentaires + audio)',
    'description' => "Ajoute commentaire_reunion + taux_renta_retenu à investisseur_analyses et crée la table investisseur_audios pour les notes vocales.",
    'created_at'  => '2026-04-23',
    'sql' => <<<'SQL'
ALTER TABLE `investisseur_analyses`
    ADD COLUMN IF NOT EXISTS `commentaire_reunion` TEXT NULL AFTER `commentaire_humain`,
    ADD COLUMN IF NOT EXISTS `taux_renta_retenu` DECIMAL(5,2) NULL AFTER `priorite_vente`;

CREATE TABLE IF NOT EXISTS `investisseur_audios` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_analyse` INT(10) UNSIGNED NOT NULL,
    `id_user`    INT(10) UNSIGNED NULL,
    `url_fichier` VARCHAR(255) NOT NULL,
    `duree_sec`   INT UNSIGNED NULL,
    `transcription` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_analyse` (`id_analyse`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];
