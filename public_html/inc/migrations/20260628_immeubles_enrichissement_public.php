<?php
/**
 * Migration : enrichissement public PERSISTÉ sur l'immeuble (Manifeste — Loi 2).
 *
 * Les données publiques retrouvées depuis l'adresse (parcelle cadastrale, zone PLU,
 * altitude, copropriété au Registre National, risques ERP) sont des propriétés de
 * l'IMMEUBLE. On les stocke ici pour ne plus rappeler les APIs à chaque ouverture
 * et pour qu'elles fassent partie du jumeau numérique. PAS de table parallèle
 * (une seule vérité) : on enrichit `immeubles`.
 *
 * - Champs first-class indexés : immatriculation copro + référence cadastrale
 *   (re-match instantané), zone PLU, altitude.
 * - `enrichissement_public_json` : snapshot complet par source (cadastre/plu/risques/
 *   registre), horodaté → permet d'afficher le détail sans re-fetch.
 * - Une date par source : chaque bloc a son bouton « 🔄 Actualiser » et sa fraîcheur
 *   (registre = trimestriel, ERP = volatil, cadastre = stable).
 *
 * Idempotente (ADD COLUMN/INDEX IF NOT EXISTS) — rejouable sans risque.
 */

return [
    'id'          => '20260628_immeubles_enrichissement_public',
    'title'       => 'Immeubles : champs d\'enrichissement public (cadastre, PLU, altitude, copro RNC, ERP)',
    'description' => "Ajoute sur `immeubles` les champs persistant l'enrichissement public retrouvé depuis l'adresse : immatriculation copropriété (RNC), référence cadastrale, zone PLU, altitude, snapshot JSON horodaté + une date de mise à jour par source (cadastre/registre/risques). Support des boutons d'actualisation et de la Loi 2 (le jumeau garde l'info).",
    'created_at'  => '2026-06-28',
    'sql' => <<<'SQL'
ALTER TABLE `immeubles`
  ADD COLUMN IF NOT EXISTS `parcelle_reference`             VARCHAR(20)  NULL AFTER `adresse_formatee`,
  ADD COLUMN IF NOT EXISTS `altitude`                       SMALLINT     NULL AFTER `parcelle_reference`,
  ADD COLUMN IF NOT EXISTS `zone_plu`                       VARCHAR(20)  NULL AFTER `altitude`,
  ADD COLUMN IF NOT EXISTS `registre_copro_immatriculation` VARCHAR(20)  NULL AFTER `zone_plu`,
  ADD COLUMN IF NOT EXISTS `registre_copro_periode`         VARCHAR(40)  NULL AFTER `registre_copro_immatriculation`,
  ADD COLUMN IF NOT EXISTS `registre_copro_maj`             DATE         NULL AFTER `registre_copro_periode`,
  ADD COLUMN IF NOT EXISTS `enrichissement_public_json`     LONGTEXT     NULL AFTER `registre_copro_maj`,
  ADD COLUMN IF NOT EXISTS `enrichi_cadastre_le`            DATETIME     NULL AFTER `enrichissement_public_json`,
  ADD COLUMN IF NOT EXISTS `enrichi_registre_le`            DATETIME     NULL AFTER `enrichi_cadastre_le`,
  ADD COLUMN IF NOT EXISTS `enrichi_risques_le`             DATETIME     NULL AFTER `enrichi_registre_le`;

ALTER TABLE `immeubles`
  ADD INDEX IF NOT EXISTS `idx_imm_parcelle` (`parcelle_reference`),
  ADD INDEX IF NOT EXISTS `idx_imm_copro_immat` (`registre_copro_immatriculation`);
SQL
];
