<?php
/**
 * Migration 2026-06-27b : correctif clé unique societe_tarifs_honoraires.
 *
 * La migration 20260627_honoraires_par_agence a échoué au DROP de
 * `uk_societe_zone` : « Cannot drop index, needed in a foreign key constraint »
 * (la FK sur id_societe utilisait cet index).
 *
 * Correctif : on AJOUTE d'abord la nouvelle clé unique (qui commence par
 * id_societe → couvre la FK), PUIS on retire l'ancienne. La FK bascule
 * automatiquement sur la nouvelle clé.
 */
return [
    'id'          => '20260627b_honoraires_tarifs_fix_uk',
    'title'       => 'Correctif clé unique societe_tarifs_honoraires (id_societe,id_agence,zone)',
    'description' => "Ajoute uk_soc_age_zone (couvre la FK id_societe) puis retire uk_societe_zone. Répare le DROP bloqué par la contrainte de clé étrangère.",
    'created_at'  => '2026-06-27',
    'sql' => <<<'SQL'
ALTER TABLE `societe_tarifs_honoraires` ADD UNIQUE KEY `uk_soc_age_zone` (`id_societe`,`id_agence`,`zone_tendue`);
ALTER TABLE `societe_tarifs_honoraires` DROP INDEX `uk_societe_zone`;
SQL
];
