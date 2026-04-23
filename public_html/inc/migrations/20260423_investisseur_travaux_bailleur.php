<?php
/**
 * Migration : travaux à prévoir par le bailleur s'il conserve le bien.
 *
 * Distinct de `travaux` qui représente le coût acquéreur (travaux engagés
 * par un acheteur qui rénove après achat). `travaux_bailleur` est le coût
 * ponctuel que le propriétaire actuel doit engager pour conserver le bien
 * en état (remise aux normes, rénovation, mise en conformité DPE, etc.).
 * Il se retire du cashflow de la 1re année dans la projection 5/10 ans.
 */

return [
    'id'          => '20260423_investisseur_travaux_bailleur',
    'title'       => 'Investisseur : travaux à charge du bailleur (projection conservation)',
    'description' => "Ajoute travaux_bailleur à investisseur_analyses pour simuler l'impact d'une rénovation sur le cashflow si le bailleur conserve le bien.",
    'created_at'  => '2026-04-23',
    'sql' => <<<'SQL'
ALTER TABLE `investisseur_analyses`
    ADD COLUMN IF NOT EXISTS `travaux_bailleur` DECIMAL(12,2) NULL DEFAULT 0
        COMMENT 'Travaux à charge du bailleur s\'il conserve le bien' AFTER `travaux`;
SQL,
];
