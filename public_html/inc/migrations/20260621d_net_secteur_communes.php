<?php
/**
 * Migration : communes du secteur par agence (SEO local des vitrines).
 * Champ libre éditable depuis "Textes des vitrines", lu par la génération IA.
 * Additif / rejouable. Seed Chaponost (#4) seulement si vide.
 */
return [
    'id'          => '20260621d_net_secteur_communes',
    'title'       => 'Vitrines — communes du secteur par agence',
    'description' => 'Ajoute agences.net_secteur_communes (+ seed Chaponost si vide).',
    'created_at'  => '2026-06-21',
    'sql' => <<<'SQL'
ALTER TABLE `agences`
  ADD COLUMN IF NOT EXISTS `net_secteur_communes` TEXT NULL AFTER `horaires`;

UPDATE `agences`
  SET `net_secteur_communes` = "Brindas, Brignais, Vaugneray, Tassin-la-Demi-Lune, Sainte-Foy-lès-Lyon, Craponne, Thurins, Orliénas, Saint-Laurent-d'Agny, Soucieu-en-Jarrest"
  WHERE `id` = 4 AND (`net_secteur_communes` IS NULL OR `net_secteur_communes` = '');
SQL,
];
