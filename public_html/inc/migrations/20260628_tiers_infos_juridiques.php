<?php
/**
 * Migration : infos juridiques (Pappers) persistées sur le tiers.
 *
 * Quand on récupère les données légales d'une société (Pappers / annuaire des
 * entreprises) — depuis la fiche tiers OU lors de la création d'un bien hors
 * gestion — on les stocke ici. La fiche tiers les affiche alors directement,
 * sans re-interroger l'API (Loi 2 : le jumeau garde l'info).
 *
 * Idempotente (ADD COLUMN IF NOT EXISTS).
 */
return [
    'id'          => '20260628_tiers_infos_juridiques',
    'title'       => 'Tiers : infos juridiques Pappers persistées (JSON + date)',
    'description' => "Ajoute `infos_juridiques_json` (snapshot Pappers/annuaire : capital, dirigeants, NAF, CA…) et `infos_juridiques_maj` sur `tiers`.",
    'created_at'  => '2026-06-28',
    'sql' => <<<'SQL'
ALTER TABLE `tiers`
  ADD COLUMN IF NOT EXISTS `infos_juridiques_json` LONGTEXT NULL,
  ADD COLUMN IF NOT EXISTS `infos_juridiques_maj`  DATETIME NULL;
SQL
];
