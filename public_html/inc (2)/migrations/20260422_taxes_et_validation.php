<?php
/**
 * Migration 2026-04-22 ter : TOM (taxe ordures ménagères) sur annonces
 *
 * Ajoute annonces.taxe_ordures_menageres à côté de taxe_fonciere et
 * taxe_habitation pour la section Conditions financières de bien_detail.php
 * (Card 1 Annonce → rubrique Taxes annuelles).
 *
 * Idempotente via ADD COLUMN IF NOT EXISTS.
 */

return [
    'id'          => '20260422_taxes_et_validation',
    'title'       => 'Annonces : ajout colonne taxe_ordures_menageres (TOM)',
    'description' => "Ajoute annonces.taxe_ordures_menageres (DECIMAL 10,2 nullable) pour saisir la TEOM annuelle du bien dans la Card Conditions financières. Complète taxe_fonciere + taxe_habitation déjà présentes.",
    'created_at'  => '2026-04-22',
    'sql' => <<<'SQL'
ALTER TABLE `annonces`
    ADD COLUMN IF NOT EXISTS `taxe_ordures_menageres` DECIMAL(10,2) NULL
        COMMENT 'Taxe enlèvement ordures ménagères (TEOM/TOM), annuelle €'
        AFTER `taxe_habitation`;
SQL,
];
