<?php
/**
 * Migration : Pilotage métier — parcours opératoire MBI (manuel pas à pas) stocké
 * en JSON sur la mission. Chaque étape = { title, status_hint, route, entity, help }.
 * Additif, idempotent.
 */
return [
    'id'          => '20260718a_pilotage_mbi_journey',
    'title'       => 'Pilotage métier : colonne mbi_journey_json',
    'description' => "Manuel opératoire MBI pas à pas (Tiers→Immeuble→Bien→Mandat→…→Registre).",
    'created_at'  => '2026-07-18',
    'sql' => <<<'SQL'
ALTER TABLE `pilotage_tasks` ADD COLUMN IF NOT EXISTS `mbi_journey_json` MEDIUMTEXT NULL AFTER `procedure_text`;
SQL
];
