<?php
/**
 * Migration : renouvellement (tacite reconduction) du mandat.
 *  - renouvellement_tacite : reconduction automatique par défaut (1).
 *  - duree_initiale_mois   : durée du 1er terme (base de reconduction).
 *  - duree_max_mois        : plafond légal cumulé (36 mois = 3 ans).
 * Règle d'or : ADDITIF + idempotent.
 */
return [
    'id'          => '20260708c_mandats_renouvellement',
    'title'       => 'Mandats : renouvellement tacite (durée + plafond 3 ans)',
    'description' => "Ajoute renouvellement_tacite, duree_initiale_mois, duree_max_mois sur mandats.",
    'created_at'  => '2026-07-08',
    'sql' => <<<'SQL'
ALTER TABLE `mandats` ADD COLUMN IF NOT EXISTS `renouvellement_tacite` TINYINT(1) NOT NULL DEFAULT 1 AFTER `date_fin`;
ALTER TABLE `mandats` ADD COLUMN IF NOT EXISTS `duree_initiale_mois` INT(11) NULL AFTER `renouvellement_tacite`;
ALTER TABLE `mandats` ADD COLUMN IF NOT EXISTS `duree_max_mois` INT(11) NOT NULL DEFAULT 36 AFTER `duree_initiale_mois`;
SQL
];
