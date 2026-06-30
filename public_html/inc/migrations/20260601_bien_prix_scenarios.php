<?php
/**
 * Migration : scénarios de valorisation sur bien_prix.
 *
 * Ajoute une dimension "scénario" aux prix : 'courant' (vérité du jour, diffusée
 * annonce/Ubiflow) + scénarios alternatifs internes ('ifi', 'prix_min', 'prix_max',
 * ou snapshots datés libres). Permet d'afficher/charger un jeu de valeurs le temps
 * d'une réunion sans toucher le prix courant.
 *
 * is_courant reste le "courant DANS un scénario" : une seule ligne is_courant=1
 * par (id_bien, type_valeur, scenario_code).
 *
 * Additif / rejouable (ADD COLUMN IF NOT EXISTS). Les lignes existantes deviennent
 * scénario 'courant' (valeur par défaut).
 */
return [
    'id'          => '20260601_bien_prix_scenarios',
    'title'       => 'Bien : scénarios de valorisation (bien_prix.scenario_code)',
    'description' => "Ajoute scenario_code ('courant'|'ifi'|'prix_min'|'prix_max'|snapshot) et scenario_label à bien_prix. Seul 'courant' alimente annonce/Ubiflow.",
    'created_at'  => '2026-06-01',
    'sql' => <<<'SQL'
ALTER TABLE `bien_prix`
  ADD COLUMN IF NOT EXISTS `scenario_code` VARCHAR(40) NOT NULL DEFAULT 'courant' AFTER `type_valeur`;
ALTER TABLE `bien_prix`
  ADD COLUMN IF NOT EXISTS `scenario_label` VARCHAR(120) NULL AFTER `scenario_code`;
ALTER TABLE `bien_prix`
  ADD INDEX IF NOT EXISTS `idx_scenario` (`id_bien`,`type_valeur`,`scenario_code`,`is_courant`);
SQL,
];
