<?php
/**
 * Migration : mapping poste→compte MULTIPLE (plusieurs collaborateurs par poste),
 * avec agence optionnelle. Un même poste (ex. Assistante Location) peut être tenu
 * par plusieurs personnes de la société, éventuellement réparties par agence.
 *
 * Retire l'ancienne contrainte unique (id_societe, poste_code) qui limitait à 1 compte.
 * Additif/idempotent (IF EXISTS / IF NOT EXISTS). Le runner ignore une erreur isolée.
 */
return [
    'id'          => '20260720b_pilotage_poste_mapping_multi',
    'title'       => 'Pilotage : mapping poste→compte multiple (par agence)',
    'description' => "Autorise plusieurs collaborateurs par poste ; ajoute id_agence.",
    'created_at'  => '2026-07-20',
    'sql' => <<<'SQL'
ALTER TABLE `pilotage_poste_mapping` DROP INDEX IF EXISTS `uniq_map`;
ALTER TABLE `pilotage_poste_mapping` ADD COLUMN IF NOT EXISTS `id_agence` INT NULL AFTER `poste_code`;
ALTER TABLE `pilotage_poste_mapping` ADD INDEX IF NOT EXISTS `idx_map` (`id_societe`,`poste_code`);
SQL
];
