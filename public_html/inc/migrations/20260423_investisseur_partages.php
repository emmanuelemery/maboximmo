<?php
/**
 * Migration : liens de partage sécurisés pour le module Investisseur.
 *
 * Permet de générer un lien magique à usage externe (propriétaire, investisseur,
 * partenaire) qui ouvre une VUE LECTURE SEULE de :
 *   - une analyse (présentation client)
 *   - un scénario de valorisation
 *   - un portefeuille (futur)
 * sans nécessiter de compte MaBoxImmo.
 *
 * Sécurité : token cryptographique, expiration, révocation, tracking consultations.
 */

return [
    'id'          => '20260423_investisseur_partages',
    'title'       => 'Investisseur : liens de partage externes (token)',
    'description' => "Table investisseur_partages pour gérer les liens magiques à usage propriétaire/client externe.",
    'created_at'  => '2026-04-23',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `investisseur_partages` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,

    `token`       VARCHAR(64) NOT NULL COMMENT '32 octets hex = 64 chars',
    `type`        ENUM('analyse','scenario','portefeuille','presentation') NOT NULL,
    `id_ref`      INT(10) UNSIGNED NULL COMMENT 'id_analyse ou id_scenario selon type',

    `id_societe`  INT(10) UNSIGNED NULL,
    `id_agence`   INT(10) UNSIGNED NULL,
    `id_user_crea` INT(10) UNSIGNED NULL COMMENT 'user MaBoxImmo qui a créé le lien',

    `destinataire_email` VARCHAR(180) NOT NULL,
    `destinataire_nom`   VARCHAR(180) NULL,
    `message`            TEXT NULL,

    `expire_at`    DATETIME NOT NULL,
    `revoque`      TINYINT(1) NOT NULL DEFAULT 0,

    `consulte_at`    DATETIME NULL COMMENT 'date de 1ère consultation',
    `consulte_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `last_consult_ip` VARCHAR(45) NULL,

    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_token` (`token`),
    KEY `idx_ref`      (`type`, `id_ref`),
    KEY `idx_societe`  (`id_societe`),
    KEY `idx_expire`   (`expire_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];
