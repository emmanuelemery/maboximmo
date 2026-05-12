<?php
/**
 * Migration : Ma Box Communication — Colonne nb_photos
 *
 * Ajoute mbi_supports_commerciaux.nb_photos (TINYINT NULL, 1..5).
 * Pilote le nombre de photos affichées sur l'affiche vitrine A3 horizontale :
 *   1 = juste la photo héro plein cadre (mode cinéma pur)
 *   2 = héro + 1 thumb
 *   3 = héro + 2 thumbs
 *   4 = héro + 3 thumbs (default si NULL)
 *   5 = héro + 4 thumbs (max)
 *
 * NULL = comportement par défaut (= 4 dans le template).
 */

return [
    'id'          => '20260508_mbi_supports_08_nb_photos',
    'title'       => 'Ma Box Communication — colonne nb_photos (affiche A3 horizontale)',
    'description' => "Ajoute mbi_supports_commerciaux.nb_photos (TINYINT NULL, 1..5) pour piloter le nombre de photos affichees sur l'affiche vitrine A3 H. NULL = default (4 dans le template).",
    'created_at'  => '2026-05-08',
    'sql' => <<<'SQL'
ALTER TABLE `mbi_supports_commerciaux`
  ADD COLUMN IF NOT EXISTS `nb_photos` TINYINT UNSIGNED NULL DEFAULT NULL
    COMMENT 'Nb total photos sur l affiche vitrine A3 H (1..5, NULL=auto)'
    AFTER `photo_hero_id_personnalise`
SQL,
];
