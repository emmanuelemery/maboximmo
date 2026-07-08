<?php
/**
 * Migration : CO-MANDAT (mandat partagé entre deux agences).
 *  - id_agence            : agence qui COMMERCIALISE (mandataire principal, ex. partenaire).
 *  - id_agence_collaborateur : agence en COLLABORATION (soutien administratif + suivi/conseil,
 *                              ex. REGIE EMERY). NULL = mandat simple mono-agence.
 *  - part_honoraires_mandataire / part_honoraires_collaborateur : répartition en % (saisie).
 * Règle d'or : ADDITIF + idempotent.
 */
return [
    'id'          => '20260708d_mandats_comandat',
    'title'       => 'Mandats : co-mandat (agence collaboratrice + répartition honoraires)',
    'description' => "Ajoute id_agence_collaborateur, part_honoraires_mandataire, part_honoraires_collaborateur sur mandats.",
    'created_at'  => '2026-07-08',
    'sql' => <<<'SQL'
ALTER TABLE `mandats` ADD COLUMN IF NOT EXISTS `id_agence_collaborateur` INT(11) NULL AFTER `id_agence`;
ALTER TABLE `mandats` ADD COLUMN IF NOT EXISTS `part_honoraires_mandataire` DECIMAL(5,2) NULL AFTER `honoraires_charge`;
ALTER TABLE `mandats` ADD COLUMN IF NOT EXISTS `part_honoraires_collaborateur` DECIMAL(5,2) NULL AFTER `part_honoraires_mandataire`;
SQL
];
