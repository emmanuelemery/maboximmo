<?php
/**
 * Migration : Ma Box Communication — Colonnes surcharges éditeur (sécurité prod)
 *
 * Les colonnes de surcharge editeur (titre_personnalise, accroche,
 * description_personnalisee, photo_hero_id_personnalise,
 * mentions_overrides_json) sont normalement deja creees par la migration 01.
 *
 * Cette migration est un FILET DE SECURITE pour les bases ou la migration
 * 01 aurait ete appliquee AVANT que ces colonnes ne soient ajoutees au
 * script (cas du dev avant le 2026-05-02).
 *
 * Utilise ADD COLUMN IF NOT EXISTS (MariaDB 10.0+ / MySQL 8.0.29+) →
 * inoffensif si les colonnes sont deja presentes.
 */

return [
    'id'          => '20260502_mbi_supports_07_surcharges_editor',
    'title'       => 'Ma Box Communication — colonnes surcharges éditeur (filet de sécurité)',
    'description' => "Filet de securite : verifie que mbi_supports_commerciaux contient bien les 5 colonnes de surcharge editeur (titre_personnalise, accroche, description_personnalisee, photo_hero_id_personnalise, mentions_overrides_json). Utilise ADD COLUMN IF NOT EXISTS — inoffensif si colonnes deja la.",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
ALTER TABLE `mbi_supports_commerciaux`
  ADD COLUMN IF NOT EXISTS `titre_personnalise`         VARCHAR(200)  NULL AFTER `titre_support`,
  ADD COLUMN IF NOT EXISTS `accroche`                   VARCHAR(500)  NULL AFTER `titre_personnalise`,
  ADD COLUMN IF NOT EXISTS `description_personnalisee`  TEXT          NULL AFTER `accroche`,
  ADD COLUMN IF NOT EXISTS `photo_hero_id_personnalise` INT UNSIGNED  NULL AFTER `description_personnalisee`,
  ADD COLUMN IF NOT EXISTS `mentions_overrides_json`    LONGTEXT      NULL AFTER `photo_hero_id_personnalise`
SQL,
];
