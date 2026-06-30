<?php
/**
 * Migration : colonnes Acte en Main sur portefeuille_biens (biens professionnels).
 *   - droits_mutation_pct       : taux droits de mutation (défaut 7,8 % pour le pro)
 *   - droits_mutation_montant   : droits en € = FAI × taux
 *   - prix_acte_en_main         : Prix FAI + droits de mutation
 * Additif / rejouable.
 */
return [
    'id'          => '20260606b_portefeuille_biens_aem',
    'title'       => 'Portefeuille_biens — droits de mutation + prix acte en main',
    'description' => 'Ajoute droits_mutation_pct, droits_mutation_montant, prix_acte_en_main (rentabilité acte en main pour le professionnel).',
    'created_at'  => '2026-06-06',
    'sql' => <<<'SQL'
ALTER TABLE `portefeuille_biens`
  ADD COLUMN IF NOT EXISTS `droits_mutation_pct` DECIMAL(6,3) NULL AFTER `net_vendeur`;
ALTER TABLE `portefeuille_biens`
  ADD COLUMN IF NOT EXISTS `droits_mutation_montant` DECIMAL(15,2) NULL AFTER `droits_mutation_pct`;
ALTER TABLE `portefeuille_biens`
  ADD COLUMN IF NOT EXISTS `prix_acte_en_main` DECIMAL(15,2) NULL AFTER `droits_mutation_montant`;
SQL,
];
