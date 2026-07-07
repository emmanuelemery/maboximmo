<?php
/**
 * Migration : point de départ du calcul du loyer (prorata) + travaux (Annexe 2 L.145-40-2).
 *
 *  - prorata_date_debut : date à partir de laquelle le loyer est calculé (peut différer de la
 *    prise d'effet en cas de franchise). Utilisée pour le prorata du 1er terme.
 *  - travaux_realises_3ans : récapitulatif des travaux des 3 années écoulées.
 *  - travaux_prevus_3ans   : état prévisionnel des travaux des 3 années à venir.
 *
 * Règle d'or : ADDITIF + idempotent.
 */
return [
    'id'          => '20260707d_baux_prorata_travaux',
    'title'       => 'Baux : date de prorata loyer + travaux (Annexe 2)',
    'description' => "Ajoute prorata_date_debut, travaux_realises_3ans, travaux_prevus_3ans sur bien_baux.",
    'created_at'  => '2026-07-07',
    'sql' => <<<'SQL'
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `prorata_date_debut` DATE NULL AFTER `date_prise_effet`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `travaux_realises_3ans` TEXT NULL AFTER `conditions_particulieres_loyer`;
ALTER TABLE `bien_baux` ADD COLUMN IF NOT EXISTS `travaux_prevus_3ans` TEXT NULL AFTER `travaux_realises_3ans`;
SQL
];
