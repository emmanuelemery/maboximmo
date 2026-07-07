<?php
/**
 * Migration : comptes bancaires multiples PAR AGENCE + typés par usage.
 *
 * Réutilise la table existante `societes_rib` (déjà multi-comptes par société) en ajoutant :
 *   - id_agence  : rattachement à une agence (NULL = compte au niveau société) ;
 *   - type_compte: usage du compte — 'gestion' (clients : baux + compta gestion),
 *                  'societe' (factures transaction/location hors gestion, compta société),
 *                  'transaction' (séquestre / transaction dédié).
 *
 * Le compte GESTION alimente les baux et tous les documents comptables de la gestion.
 * Règle d'or : ADDITIF + idempotent.
 */
return [
    'id'          => '20260707e_rib_agence_type',
    'title'       => 'RIB : comptes bancaires par agence + type (gestion/société/transaction)',
    'description' => "Ajoute id_agence et type_compte sur societes_rib pour gérer plusieurs comptes typés par agence.",
    'created_at'  => '2026-07-07',
    'sql' => <<<'SQL'
ALTER TABLE `societes_rib` ADD COLUMN IF NOT EXISTS `id_agence` INT(11) NULL AFTER `id_societe`;
ALTER TABLE `societes_rib` ADD COLUMN IF NOT EXISTS `type_compte` VARCHAR(20) NOT NULL DEFAULT 'gestion' AFTER `id_agence`;
ALTER TABLE `societes_rib` ADD INDEX IF NOT EXISTS `idx_rib_agence_type` (`id_agence`, `type_compte`);
SQL
];
