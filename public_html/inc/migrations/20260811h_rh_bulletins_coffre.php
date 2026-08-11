<?php
/**
 * Migration : BULLETINS DE PAIE individuels au COFFRE RH, versionnés.
 *
 * Pourquoi :
 *   Jusqu'ici la découpe rangeait les bulletins dans `rh_documents`, table que le
 *   salarié lit lui-même (rh_documents_user.php) — sa paie lui était donc visible
 *   en ligne. Désormais le bulletin individuel est un document GED de niveau
 *   'coffre' routé vers `coffre_salaires` (module « salaire »), accessible
 *   NOMINATIVEMENT via `rh.coffre.read`. Le salarié ne le consulte jamais : il le
 *   reçoit par mail (ce coffre n'est pas `strict`, l'usage mail reste autorisé).
 *
 * 1) Permission `rh.coffre.read` — elle MANQUAIT au catalogue alors que
 *    ged_coffres() la référence déjà : sans elle le coffre salaires serait
 *    illisible par tout le monde, y compris l'administrateur.
 * 2) Attribution nominative (jamais par rôle : un coffre ouvert au rôle serait
 *    hérité en silence par tout compte créé plus tard) :
 *      · super admin  → scope 'groupe'  = toutes les sociétés ;
 *      · admin société → scope 'societe' = sa société seulement.
 * 3) Table `rh_bulletins` : l'index métier des versions. Le fichier et les droits
 *    restent dans la GED ; cette table dit seulement QUI a QUEL bulletin, en
 *    QUELLE version — même schéma que registre_scellement.ged_document_id.
 *
 * Additif + idempotent (INSERT … WHERE NOT EXISTS / ON DUPLICATE / IF NOT EXISTS).
 */
return [
    'id'          => '20260811h_rh_bulletins_coffre',
    'title'       => 'RH : bulletins individuels au coffre salaires (permission + table de versions)',
    'description' => "Crée la permission rh.coffre.read (absente du catalogue alors que ged_coffres() l'exige), l'attribue nominativement au super admin (groupe) et aux administrateurs de société (scope société), et crée rh_bulletins : un bulletin par salarié et par mois, versionné, pointant vers le document GED en coffre salaires.",
    'created_at'  => '2026-08-11',
    'sql' => <<<'SQL'
INSERT INTO `permissions` (`code`, `module`, `libelle`, `description`, `actif`)
SELECT 'rh.coffre.read', 'rh', 'Lire le coffre salaires',
       'Accès nominatif aux bulletins de paie individuels (GED niveau coffre). Le salarié ne voit jamais son bulletin en ligne : il le reçoit par mail.', 1
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `permissions` WHERE `code` = 'rh.coffre.read');

-- Super admin : toutes sociétés.
INSERT INTO `user_permissions` (`user_id`, `permission_code`, `scope_type`, `scope_id`, `actif`, `created_by`)
VALUES (8, 'rh.coffre.read', 'groupe', 0, 1, 8)
ON DUPLICATE KEY UPDATE `actif` = 1;

-- Administrateurs de société : leur société uniquement (l'admin de Chamalières
-- ne lit pas les payes de Lyon).
INSERT INTO `user_permissions` (`user_id`, `permission_code`, `scope_type`, `scope_id`, `actif`, `created_by`)
SELECT u.`id`, 'rh.coffre.read', 'societe', u.`id_societe`, 1, 8
  FROM `users` u
 WHERE u.`id_role` IN (1, 7)
   AND u.`actif` = 1
   AND u.`id` <> 8
   AND COALESCE(u.`id_societe`, 0) > 0
ON DUPLICATE KEY UPDATE `actif` = 1;

CREATE TABLE IF NOT EXISTS `rh_bulletins` (
    `id`              INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_user`         INT(10) UNSIGNED NOT NULL,          -- le salarié titulaire
    `id_societe`      INT(11) NULL,
    `id_agence`       INT(11) NULL,
    `mois`            TINYINT(2) UNSIGNED NOT NULL,
    `annee`           SMALLINT(4) UNSIGNED NOT NULL,
    `version`         SMALLINT(5) UNSIGNED NOT NULL DEFAULT 1,
    `statut`          ENUM('courant','remplace') NOT NULL DEFAULT 'courant',
    `ged_document_id` BIGINT(20) UNSIGNED NULL,           -- le PDF, en coffre salaires
    `file_path`       VARCHAR(255) NULL,                  -- copie durable servable
    `source_depot_id` INT(10) UNSIGNED NULL,              -- rh_salaires_comparaisons.id
    `source_sig`      CHAR(64) NULL,                      -- empreinte source+pages : anti double version
    `pages`           VARCHAR(120) NULL,                  -- pages extraites du dépôt (1,2)
    `detecte_via`     VARCHAR(20) NULL,                   -- n° sécu | matricule | nom | suite | manuel
    `remplace_id`     INT(10) UNSIGNED NULL,              -- la version que celle-ci remplace
    `envoye_at`       DATETIME NULL,                      -- date d'envoi au salarié (NULL = jamais envoyé)
    `created_by`      INT(10) UNSIGNED NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_version` (`id_user`, `annee`, `mois`, `version`),
    UNIQUE KEY `uk_source`  (`id_user`, `annee`, `mois`, `source_sig`),
    KEY `idx_courant` (`id_user`, `annee`, `mois`, `statut`),
    KEY `idx_periode` (`annee`, `mois`, `id_agence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
];
