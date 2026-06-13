<?php
/**
 * Migration : table `mandat_signatures` — signatures électroniques simples des mandats
 * de vente via la page web (portail token). Preuve = IP + horodatage + user-agent.
 *
 * 1 ligne = 1 signataire (gère indivision/SCI : plusieurs vendeurs sur un mandat).
 * Le mandat lui-même + ses termes restent dans `mandats` (aucune duplication).
 *
 * Idempotent : CREATE TABLE IF NOT EXISTS. `down` = rollback documenté.
 */
return [
    'id'          => '20260614d_mandat_signatures',
    'title'       => 'Signatures électroniques des mandats (IP + horodatage)',
    'description' => "Crée mandat_signatures : 1 signataire = 1 token, statut pending/signe/refuse, capture nom + lu_approuve + IP + user_agent + signed_at. Lié au mandat (mandats) et au dossier_vente.",
    'created_at'  => '2026-06-14',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `mandat_signatures` (
    `id`             BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_mandat`      INT(10) UNSIGNED NOT NULL,
    `id_dossier`     BIGINT(20) UNSIGNED NULL,
    `id_tiers`       INT(10) UNSIGNED NULL,            -- le signataire (vendeur)
    `role_code`      VARCHAR(40) NULL,
    `id_societe`     INT(10) UNSIGNED NULL,
    `token`          CHAR(64) NOT NULL,
    `statut`         ENUM('pending','signe','refuse') NOT NULL DEFAULT 'pending',
    `destinataire_email` VARCHAR(190) NULL,
    `nom_signataire` VARCHAR(190) NULL,
    `lu_approuve`    TINYINT(1) NOT NULL DEFAULT 0,
    `ip`             VARCHAR(45) NULL,
    `user_agent`     VARCHAR(500) NULL,
    `signature_data` TEXT NULL,                        -- nom tapé / tracé éventuel
    `sent_at`        DATETIME NULL,
    `signed_at`      DATETIME NULL,
    `id_user_created` INT(10) UNSIGNED NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_token` (`token`),
    KEY `idx_mandat` (`id_mandat`),
    KEY `idx_dossier` (`id_dossier`),
    KEY `idx_statut` (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
    ,
    'down' => <<<'SQL'
DROP TABLE IF EXISTS `mandat_signatures`;
SQL
];
