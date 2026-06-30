<?php
/**
 * Migration : distinction prix de vente (demandé par le vendeur)
 * vs prix d'achat (négocié, ce que l'investisseur paie réellement).
 *
 * Avant : le champ `prix_achat` contenait en pratique le prix du mandat.
 * Maintenant :
 *   - prix_vente_catalogue : prix affiché / demandé par le vendeur (mandat)
 *   - prix_achat           : prix négocié retenu pour l'étude (ce que l'investisseur paie)
 *
 * Backfill : pour les analyses existantes (seed Parc SIR notamment),
 *            on copie prix_achat dans prix_vente_catalogue (valeur identique
 *            tant qu'aucune négociation n'est simulée).
 */

return [
    'id'          => '20260423_investisseur_prix_vente_catalogue',
    'title'       => 'Investisseur : prix de vente catalogue vs prix d\'achat négocié',
    'description' => "Ajoute prix_vente_catalogue (prix affiché au mandat) en complément de prix_achat (prix négocié). Les calculs se basent toujours sur prix_achat, mais l'écart de négociation est désormais visible.",
    'created_at'  => '2026-04-23',
    'sql' => <<<'SQL'
ALTER TABLE `investisseur_analyses`
    ADD COLUMN IF NOT EXISTS `prix_vente_catalogue` DECIMAL(12,2) NULL
        COMMENT 'Prix de vente demandé par le vendeur (mandat/catalogue). prix_achat = prix négocié retenu pour l\'étude.'
        AFTER `prix_achat`;

UPDATE `investisseur_analyses`
    SET `prix_vente_catalogue` = `prix_achat`
    WHERE `prix_vente_catalogue` IS NULL
      AND `prix_achat` > 0;
SQL,
];
