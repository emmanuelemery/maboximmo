<?php
/**
 * Migration : table d'archive des biens SUPPRIMÉS.
 *
 * Soft-delete : quand on "supprime" un bien depuis la liste des biens archivés
 * d'un immeuble, on snapshot la ligne complète (JSON) dans biens_supprimes,
 * puis on retire la ligne de `biens`. Réversible (le payload permet de restaurer).
 *
 * Distinct du statut 'archive' (bien masqué mais conservé dans `biens`) et de
 * 'vendu' (bien vendu, conservé). Ici = sortie définitive de `biens`.
 */

return [
    'id'          => '20260607_biens_supprimes',
    'title'       => 'Table biens_supprimes (archive des suppressions de biens)',
    'description' => "Crée biens_supprimes : snapshot JSON d'un bien retiré de la table biens (soft-delete réversible), avec contexte immeuble/proprio, auteur et date.",
    'created_at'  => '2026-06-07',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `biens_supprimes` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_bien_origine` INT UNSIGNED NOT NULL COMMENT 'biens.id avant suppression',
    `reference_bien`  VARCHAR(50)  NULL,
    `code_crg`        VARCHAR(30)  NULL,
    `id_immeuble`     INT UNSIGNED NULL,
    `id_proprietaire` INT UNSIGNED NULL,
    `id_societe`      INT UNSIGNED NULL,
    `id_agence`       INT UNSIGNED NULL,
    `payload`         LONGTEXT     NOT NULL COMMENT 'Snapshot JSON complet de la ligne biens',
    `raison`          VARCHAR(255) NULL,
    `supprime_par`    INT UNSIGNED NULL COMMENT 'users.id',
    `date_suppression` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_bs_origine`   (`id_bien_origine`),
    KEY `idx_bs_immeuble`  (`id_immeuble`),
    KEY `idx_bs_proprio`   (`id_proprietaire`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL,
];
