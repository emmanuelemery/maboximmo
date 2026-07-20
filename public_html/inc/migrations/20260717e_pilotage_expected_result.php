<?php
/**
 * Migration : Pilotage métier — champ « résultat attendu » sur les missions.
 * Additif, idempotent (ADD COLUMN IF NOT EXISTS — MariaDB 10.5+ / MySQL 8 via IF NOT EXISTS).
 */
return [
    'id'          => '20260717e_pilotage_expected_result',
    'title'       => 'Pilotage métier : colonne expected_result',
    'description' => "Ajoute pilotage_tasks.expected_result (résultat attendu de la mission).",
    'created_at'  => '2026-07-17',
    'sql' => <<<'SQL'
ALTER TABLE `pilotage_tasks` ADD COLUMN IF NOT EXISTS `expected_result` TEXT NULL AFTER `objective`;
SQL
];
