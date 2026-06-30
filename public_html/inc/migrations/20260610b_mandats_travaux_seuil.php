<?php
/**
 * Migration : ajoute le seuil de travaux autorisés sans accord du propriétaire
 * au registre des mandats (ex. « 1 loyer mensuel » ou « 150 € »).
 * Idempotent : ADD COLUMN IF NOT EXISTS (MariaDB 10.0.2+).
 */
return [
    'id'          => '20260610b_mandats_travaux_seuil',
    'title'       => 'Mandats : seuil de travaux sans autorisation propriétaire',
    'description' => "Ajoute travaux_seuil_autorisation (VARCHAR) à mandats_registre : montant max de réparations/travaux que le mandataire peut engager sans accord du mandant.",
    'created_at'  => '2026-06-10',
    'sql' => <<<'SQL'
ALTER TABLE `mandats_registre`
  ADD COLUMN IF NOT EXISTS `travaux_seuil_autorisation` VARCHAR(120) NULL AFTER `crg_periodicite`;
SQL
];
