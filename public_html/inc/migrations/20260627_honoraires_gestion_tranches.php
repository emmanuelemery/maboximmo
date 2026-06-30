<?php
/**
 * Migration 2026-06-27 : barème gestion locative par tranches de loyer.
 *
 * Comme la vente (tranches dégressives), la gestion locative peut être facturée
 * par tranches de loyer mensuel. On ajoute :
 *   - gestion_methode        : 'pct' (taux unique, défaut) | 'tranches'
 *   - gestion_tranches_json  : [{min, max, pct}, ...] (loyer mensuel → %)
 *
 * Rétrocompatible : gestion_pct_loyer reste utilisé quand methode = 'pct'.
 */
return [
    'id'          => '20260627_honoraires_gestion_tranches',
    'title'       => 'Gestion locative : barème par tranches de loyer (gestion_methode + gestion_tranches_json)',
    'description' => "Ajoute gestion_methode (pct|tranches) et gestion_tranches_json sur societe_honoraires pour facturer la gestion par tranches de loyer.",
    'created_at'  => '2026-06-27',
    'sql' => <<<'SQL'
ALTER TABLE `societe_honoraires`
  ADD COLUMN IF NOT EXISTS `gestion_methode`       VARCHAR(20) NULL DEFAULT 'pct' COMMENT 'pct (taux unique) | tranches (par tranches de loyer)',
  ADD COLUMN IF NOT EXISTS `gestion_tranches_json` TEXT        NULL COMMENT 'Tranches gestion [{min,max,pct}] par loyer mensuel';
SQL
];
