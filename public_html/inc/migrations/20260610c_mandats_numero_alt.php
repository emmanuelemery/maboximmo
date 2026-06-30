<?php
/**
 * Migration : numéro secondaire de mandat (renumérotation — un même document porte 2 numéros).
 * numero_mandat = numéro courant/récent ; numero_mandat_alt = ancien numéro.
 * Idempotent : ADD COLUMN IF NOT EXISTS.
 */
return [
    'id'          => '20260610c_mandats_numero_alt',
    'title'       => 'Mandats : numéro secondaire (ancien numéro)',
    'description' => "Ajoute numero_mandat_alt à mandats_registre : ancien numéro quand un même mandat porte 2 numéros (renumérotation). 1 seule entrée par mandat.",
    'created_at'  => '2026-06-10',
    'sql' => <<<'SQL'
ALTER TABLE `mandats_registre`
  ADD COLUMN IF NOT EXISTS `numero_mandat_alt` VARCHAR(40) NULL AFTER `numero_mandat`;
SQL
];
