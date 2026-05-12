<?php
/**
 * Migration : étend les valeurs ENUM logiciel_comptable.
 *
 * Cas : la 1re migration a été appliquée avec seulement SEPTEO/LOJJI/AUTRE,
 * puis on veut ajouter ICS + MaBoxImmo.
 */

return [
    'id'          => '20260502_logiciel_comptable_extend_values',
    'title'       => 'logiciel_comptable : ajoute ICS + MABOXIMMO',
    'description' => "Étend les ENUM logiciel_comptable (immeubles/biens + staging relevés) avec ICS et MABOXIMMO.",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
ALTER TABLE `immeubles`
    MODIFY COLUMN `logiciel_comptable` ENUM('SEPTEO','LOJJI','ICS','MABOXIMMO','AUTRE') NULL DEFAULT NULL
        COMMENT 'Progiciel comptable rattaché (prioritaire sur immeuble)';

ALTER TABLE `biens`
    MODIFY COLUMN `logiciel_comptable` ENUM('SEPTEO','LOJJI','ICS','MABOXIMMO','AUTRE') NULL DEFAULT NULL
        COMMENT 'Progiciel comptable (override éventuel)';

ALTER TABLE `ged_import_releves_items`
    MODIFY COLUMN `logiciel_comptable` ENUM('SEPTEO','LOJJI','ICS','MABOXIMMO','AUTRE') NULL DEFAULT NULL;
SQL,
];

