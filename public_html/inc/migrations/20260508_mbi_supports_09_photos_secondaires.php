<?php
/**
 * Migration : Ma Box Communication — Sélection manuelle des photos secondaires
 *
 * Ajoute mbi_supports_commerciaux.photos_secondaires_ids_json (LONGTEXT NULL)
 * pour stocker la liste ordonnée d'ids biens_photos sélectionnés comme
 * thumbnails de l'affiche A3 horizontale.
 *
 * Format : JSON array d'ids, ex: [12, 45, 78]
 * NULL = comportement auto (ordre BDD).
 */

return [
    'id'          => '20260508_mbi_supports_09_photos_secondaires',
    'title'       => 'Ma Box Communication — selection manuelle photos secondaires (affiche A3 H)',
    'description' => "Ajoute mbi_supports_commerciaux.photos_secondaires_ids_json (LONGTEXT NULL) pour stocker la liste ordonnee d ids biens_photos selectionnes comme thumbs de l affiche A3 horizontale. NULL = auto (ordre BDD).",
    'created_at'  => '2026-05-08',
    'sql' => <<<'SQL'
ALTER TABLE `mbi_supports_commerciaux`
  ADD COLUMN IF NOT EXISTS `photos_secondaires_ids_json` LONGTEXT NULL DEFAULT NULL
    COMMENT 'JSON array d ids biens_photos pour les thumbs (ordre = affichage). NULL = auto'
    AFTER `nb_photos`
SQL,
];
