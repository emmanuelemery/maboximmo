<?php
/**
 * Migration : provisions de taxes (TF / TOM) + honoraires de gestion technique récupérables.
 *
 *  - provision_tf_mensuelle  : provision de taxe foncière refacturée (baux pro/commercial).
 *  - provision_tom_mensuelle : provision de taxe d'ordures ménagères (TEOM), refacturée (habitation surtout).
 *  - honoraires_gestion_tech_pct : % du loyer au titre de la gestion technique, récupérable
 *    sur le locataire (défaut métier 1,50 %), intégré en ligne de charges.
 *
 * Les honoraires d'entremise/rédaction (bailleur/locataire) et la TVA (tva_applicable/tva_taux)
 * existent déjà sur bien_baux : on les réutilise.
 *
 * Règle d'or : ADDITIF + idempotent.
 */
return [
    'id'          => '20260706e_baux_taxes_honoraires_technique',
    'title'       => 'Baux : provisions TF/TOM + honoraires gestion technique (%)',
    'description' => "Ajoute provision_tf_mensuelle, provision_tom_mensuelle et honoraires_gestion_tech_pct (1,5 % récupérable) sur bien_baux.",
    'created_at'  => '2026-07-06',
    'sql' => <<<'SQL'
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `periodicite_paiement` ENUM('mensuelle','trimestrielle','semestrielle','annuelle') NOT NULL DEFAULT 'mensuelle' AFTER `charges_type`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `provision_tf_mensuelle`     DECIMAL(10,2) NULL AFTER `periodicite_paiement`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `provision_tom_mensuelle`    DECIMAL(10,2) NULL AFTER `provision_tf_mensuelle`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `honoraires_gestion_tech_pct` DECIMAL(5,2) NULL AFTER `provision_tom_mensuelle`;
SQL
];
