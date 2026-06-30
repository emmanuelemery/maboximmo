<?php
/**
 * Migration 2026-06-27 : barème honoraires PAR AGENCE (option A, override).
 *
 * Modèle :
 *   - id_agence = 0  → barème MODÈLE de la société (défaut / fallback).
 *   - id_agence = X  → barème propre à l'agence X (override).
 *
 * Lecture (helper) : agence X → modèle société (0) → plafond légal.
 * Cascade : enregistrer le modèle société propage à toutes ses agences (côté PHP).
 *
 * On ajoute id_agence aux 2 tables, on dédoublonne, puis on élargit les clés uniques.
 */
return [
    'id'          => '20260627_honoraires_par_agence',
    'title'       => 'Barème honoraires par agence (id_agence + clés uniques)',
    'description' => "Ajoute id_agence (0 = modèle société) sur societe_honoraires et societe_tarifs_honoraires, dédoublonne, et élargit les clés uniques.",
    'created_at'  => '2026-06-27',
    'sql' => <<<'SQL'
ALTER TABLE `societe_honoraires`
  ADD COLUMN IF NOT EXISTS `id_agence` INT NOT NULL DEFAULT 0 COMMENT '0 = modele societe, sinon bareme propre a l agence';

ALTER TABLE `societe_tarifs_honoraires`
  ADD COLUMN IF NOT EXISTS `id_agence` INT NOT NULL DEFAULT 0 COMMENT '0 = modele societe, sinon bareme propre a l agence';

DELETE t1 FROM `societe_tarifs_honoraires` t1
  INNER JOIN `societe_tarifs_honoraires` t2
  WHERE t1.id > t2.id
    AND t1.id_societe <=> t2.id_societe
    AND t1.id_agence = t2.id_agence
    AND t1.zone_tendue = t2.zone_tendue;

ALTER TABLE `societe_honoraires` DROP INDEX `uk_societe`;
ALTER TABLE `societe_honoraires` ADD UNIQUE KEY `uk_soc_age` (`id_societe`,`id_agence`);

ALTER TABLE `societe_tarifs_honoraires` DROP INDEX `uk_societe_zone`;
ALTER TABLE `societe_tarifs_honoraires` ADD UNIQUE KEY `uk_soc_age_zone` (`id_societe`,`id_agence`,`zone_tendue`);
SQL
];
