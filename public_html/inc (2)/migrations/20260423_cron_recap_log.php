<?php
/**
 * Migration 2026-04-23 : table log pour cron recap annonces
 *
 * Trace chaque exécution du cron matinal (9h00) qui envoie le récapitulatif
 * d'activité aux commerciaux + récap global au super-admin. Permet de
 * suivre : quand ça a tourné, combien de mails envoyés, combien d'erreurs,
 * détails par user.
 */

return [
    'id'          => '20260423_cron_recap_log',
    'title'       => 'Table de log pour le cron récap annonces (mail matinal 9h)',
    'description' => "Crée cron_recap_log pour tracer les exécutions du cron qui envoie le récapitulatif d'activité annonces aux commerciaux chaque matin.",
    'created_at'  => '2026-04-23',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `cron_recap_log` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ended_at` DATETIME NULL,
    `window_start` DATETIME NULL COMMENT 'Début fenêtre d\'activité couverte',
    `window_end` DATETIME NULL COMMENT 'Fin fenêtre',
    `window_hours` INT(4) NULL COMMENT '24 ou 72 selon jour de la semaine',
    `mails_envoyes` INT(6) NOT NULL DEFAULT 0,
    `mails_echecs` INT(6) NOT NULL DEFAULT 0,
    `users_skipped_conges` INT(6) NOT NULL DEFAULT 0,
    `users_sans_activite` INT(6) NOT NULL DEFAULT 0,
    `details_json` LONGTEXT NULL COMMENT 'Détail par user (id, nom, mail, nb_annonces, status)',
    `error_log` TEXT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_started` (`started_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];
