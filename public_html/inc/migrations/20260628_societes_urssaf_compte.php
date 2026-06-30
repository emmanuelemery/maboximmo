<?php
/**
 * Migration : n° de compte employeur URSSAF (au niveau société).
 * Utilisé pour pré-remplir les contrats de travail (Régie Emery RH).
 * On ne stocke QUE le compte société.
 *
 * Idempotente : ADD COLUMN IF NOT EXISTS + UPDATE ciblé sur le SIREN 398912766
 * (Régie EMERY) — ne touche aucune autre société.
 */
return [
    'id'          => '20260628_societes_urssaf_compte',
    'title'       => 'Sociétés : n° compte employeur URSSAF + valeur Régie EMERY',
    'description' => "Ajoute `urssaf_compte` sur `societes` et renseigne le compte employeur URSSAF de Régie EMERY (827 2194384984), repéré par son SIREN 398912766.",
    'created_at'  => '2026-06-28',
    'sql' => <<<'SQL'
ALTER TABLE `societes`
  ADD COLUMN IF NOT EXISTS `urssaf_compte` VARCHAR(30) NULL AFTER `siret`;

UPDATE `societes`
   SET `urssaf_compte` = '827 2194384984'
 WHERE (`siren` = '398912766' OR `siret` LIKE '398912766%')
   AND (`urssaf_compte` IS NULL OR `urssaf_compte` = '');
SQL
];
