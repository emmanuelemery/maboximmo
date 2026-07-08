<?php
/**
 * Migration : colonnes du barème VENTE sur societe_tarifs_honoraires.
 *
 * La page agency_honoraires_config.php lit/écrit déjà ces colonnes (barème Vente : méthode,
 * taux, forfait, tranches dégressives, charge par défaut, plancher). Elles existaient en prod
 * mais pas en local (écart de schéma) → on les crée de façon idempotente pour aligner et
 * permettre le calcul honoraires vente (honoraires_vente_bareme).
 *
 * Règle d'or : ADDITIF + idempotent.
 */
return [
    'id'          => '20260708b_honoraires_vente_columns',
    'title'       => 'Honoraires : colonnes barème VENTE (méthode/taux/forfait/tranches)',
    'description' => "Ajoute vente_methode, vente_taux_unique, vente_forfait, vente_tranches_json, vente_charge_par_defaut, vente_montant_minimum sur societe_tarifs_honoraires.",
    'created_at'  => '2026-07-08',
    'sql' => <<<'SQL'
ALTER TABLE `societe_tarifs_honoraires` ADD COLUMN IF NOT EXISTS `vente_methode` VARCHAR(20) NULL DEFAULT 'tranches' AFTER `honoraires_edl_m2`;
ALTER TABLE `societe_tarifs_honoraires` ADD COLUMN IF NOT EXISTS `vente_taux_unique` DECIMAL(6,3) NULL AFTER `vente_methode`;
ALTER TABLE `societe_tarifs_honoraires` ADD COLUMN IF NOT EXISTS `vente_forfait` DECIMAL(12,2) NULL AFTER `vente_taux_unique`;
ALTER TABLE `societe_tarifs_honoraires` ADD COLUMN IF NOT EXISTS `vente_tranches_json` TEXT NULL AFTER `vente_forfait`;
ALTER TABLE `societe_tarifs_honoraires` ADD COLUMN IF NOT EXISTS `vente_charge_par_defaut` VARCHAR(20) NULL DEFAULT 'acquereur' AFTER `vente_tranches_json`;
ALTER TABLE `societe_tarifs_honoraires` ADD COLUMN IF NOT EXISTS `vente_montant_minimum` DECIMAL(12,2) NULL AFTER `vente_charge_par_defaut`;
SQL
];
