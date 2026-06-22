<?php
/**
 * Migration : Propriétaires — flag « parti de la gestion » (perdu).
 *
 * Distinct de l'archivage (actif=0). Un propriétaire « parti de la gestion » n'apparaît
 * plus dans la liste par défaut, est compté dans le KPI « Perdus » et consultable via
 * le filtre dédié. Marqué depuis la fiche tiers 360°.
 *
 * AJOUT uniquement (idempotent — MariaDB ADD COLUMN IF NOT EXISTS).
 *
 * ── ROLLBACK (-- DOWN) ──
 *   ALTER TABLE `proprietaires` DROP COLUMN `parti_gestion`, DROP COLUMN `date_sortie_gestion`;
 */

return [
    'id'          => '20260622_proprietaire_parti_gestion',
    'title'       => 'Propriétaires — flag « parti de la gestion » (perdu)',
    'description' => "Ajoute proprietaires.parti_gestion (tinyint) + date_sortie_gestion (date). Distinct de l'archivage. AJOUT uniquement.",
    'created_at'  => '2026-06-22',
    'sql' => <<<'SQL'
ALTER TABLE `proprietaires`
  ADD COLUMN IF NOT EXISTS `parti_gestion` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Propriétaire parti de la gestion (perdu) — masqué de la liste' AFTER `actif`,
  ADD COLUMN IF NOT EXISTS `date_sortie_gestion` DATE NULL COMMENT 'Date de sortie de la gestion' AFTER `parti_gestion`;
SQL
];
