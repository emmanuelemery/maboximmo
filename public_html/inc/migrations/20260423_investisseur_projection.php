<?php
/**
 * Migration : projection multi-années + arbitrage vendre vs garder
 *
 * Ajoute les paramètres nécessaires pour :
 *   - Simuler un crédit en cours (CRD + durée restante)
 *   - Projeter les cashflows sur 5 ou 10 ans avec indexation loyer + revalorisation bien
 *   - Calculer l'IRA (indemnité remb. anticipée) en cas de vente avec crédit
 *   - (optionnel) Intégrer un taux d'imposition si rempli — sinon analyse brute avant impôt
 */

return [
    'id'          => '20260423_investisseur_projection',
    'title'       => 'Investisseur : projection N ans + arbitrage vendre vs garder',
    'description' => "Ajoute credit_crd, credit_duree_restante_mois, revalorisation_bien_pct_an, indexation_loyer_pct_an, ira_pct, taux_imposition_pct à investisseur_analyses.",
    'created_at'  => '2026-04-23',
    'sql' => <<<'SQL'
ALTER TABLE `investisseur_analyses`
    ADD COLUMN IF NOT EXISTS `credit_crd` DECIMAL(12,2) NULL
        COMMENT 'Capital restant dû aujourd\'hui (si crédit en cours)' AFTER `mensualite_credit`,
    ADD COLUMN IF NOT EXISTS `credit_duree_restante_mois` SMALLINT UNSIGNED NULL
        COMMENT 'Mois restants sur le crédit en cours' AFTER `credit_crd`,
    ADD COLUMN IF NOT EXISTS `revalorisation_bien_pct_an` DECIMAL(5,2) NULL DEFAULT 1.50
        COMMENT 'Taux annuel de revalorisation de la valeur du bien (%)' AFTER `credit_duree_restante_mois`,
    ADD COLUMN IF NOT EXISTS `indexation_loyer_pct_an` DECIMAL(5,2) NULL DEFAULT 1.00
        COMMENT 'Indexation annuelle du loyer (%)' AFTER `revalorisation_bien_pct_an`,
    ADD COLUMN IF NOT EXISTS `ira_pct` DECIMAL(5,2) NULL DEFAULT 3.00
        COMMENT 'Indemnité remboursement anticipé en % du CRD' AFTER `indexation_loyer_pct_an`,
    ADD COLUMN IF NOT EXISTS `taux_imposition_pct` DECIMAL(5,2) NULL
        COMMENT 'Taux d\'imposition global (%). Si NULL, calculs avant impôt.' AFTER `ira_pct`;
SQL,
];
