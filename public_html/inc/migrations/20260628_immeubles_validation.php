<?php
/**
 * Migration : VALIDATION de l'immeuble.
 *
 * Un immeuble devient « validé » une fois ses données publiques chargées
 * (cadastre, PLU, altitude, copropriété RNC, ERP) et confirmé par un utilisateur.
 * La validation matérialise que le jumeau numérique de l'immeuble est fiable.
 *
 * - `valide_le`  : horodatage de la validation (NULL = pas encore validé)
 * - `valide_par` : utilisateur qui a validé
 *
 * Idempotente (ADD COLUMN IF NOT EXISTS) — rejouable sans risque.
 */

return [
    'id'          => '20260628_immeubles_validation',
    'title'       => 'Immeubles : champs de validation (valide_le, valide_par)',
    'description' => "Ajoute `valide_le` (datetime) et `valide_par` (user) sur `immeubles`. La validation intervient après le chargement des données publiques.",
    'created_at'  => '2026-06-28',
    'sql' => <<<'SQL'
ALTER TABLE `immeubles`
  ADD COLUMN IF NOT EXISTS `valide_le`  DATETIME NULL AFTER `enrichi_risques_le`,
  ADD COLUMN IF NOT EXISTS `valide_par` INT      NULL AFTER `valide_le`;
SQL
];
