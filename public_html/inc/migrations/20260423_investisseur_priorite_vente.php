<?php
/**
 * Migration : priorité de vente pour le module Investisseur.
 *
 * Permet d'ordonner les biens candidats à la vente (1 = top priorité,
 * 10 = urgent, 0 = non prioritaire / conservation).
 */

return [
    'id'          => '20260423_investisseur_priorite_vente',
    'title'       => 'Investisseur : priorité de vente (0-10)',
    'description' => "Ajoute priorite_vente à investisseur_analyses pour ordonner les biens lors des réunions d'arbitrage.",
    'created_at'  => '2026-04-23',
    'sql' => <<<'SQL'
ALTER TABLE `investisseur_analyses`
    ADD COLUMN IF NOT EXISTS `priorite_vente` TINYINT UNSIGNED NULL DEFAULT 0
        COMMENT '0 = non prioritaire, 1 = top, ..., 10 = urgent' AFTER `statut`,
    ADD KEY IF NOT EXISTS `idx_priorite` (`priorite_vente`);
SQL,
];
