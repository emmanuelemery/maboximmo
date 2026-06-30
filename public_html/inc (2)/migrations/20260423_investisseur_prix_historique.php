<?php
/**
 * Migration : historique des changements de prix de vente.
 *
 * Chaque fois que le prix_vente_catalogue d'une analyse change, on archive
 * l'ancienne valeur + la nouvelle + date + user pour reconstituer la
 * trajectoire de prix dans le temps (utile pour les réunions successives).
 */

return [
    'id'          => '20260423_investisseur_prix_historique',
    'title'       => 'Investisseur : historique des changements de prix',
    'description' => "Trace chaque modification du prix de vente d'une analyse pour afficher un historique daté en réunion.",
    'created_at'  => '2026-04-23',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `investisseur_prix_historique` (
    `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_analyse` INT(10) UNSIGNED NOT NULL,
    `id_user`    INT(10) UNSIGNED NULL,
    `prix_ancien`  DECIMAL(12,2) NULL,
    `prix_nouveau` DECIMAL(12,2) NOT NULL,
    `motif`        VARCHAR(200) NULL,
    `changed_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_analyse` (`id_analyse`),
    KEY `idx_changed` (`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];
