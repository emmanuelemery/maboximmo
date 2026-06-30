<?php
/**
 * Migration : champs « affiche vitrine » sur biens.
 *
 * - bien_annonce_affiche : texte d'annonce synthétisé (IA) et éditable, affiché
 *   sur les affiches vitrine (court). Persisté pour réutilisation.
 * - bien_titre_affiche   : titre validé/édité dans le modal d'affiche, persisté.
 *
 * Idempotent : ADD COLUMN IF NOT EXISTS. Rejouable sans casse.
 */
return [
    'id'          => '20260617_bien_annonce_affiche',
    'title'       => 'Affiche vitrine : texte annonce synthétisé + titre persistés sur biens',
    'description' => "Ajoute biens.bien_annonce_affiche (TEXT) et biens.bien_titre_affiche (VARCHAR 255).",
    'created_at'  => '2026-06-17',
    'sql' => <<<'SQL'
ALTER TABLE `biens`
  ADD COLUMN IF NOT EXISTS `bien_annonce_affiche` TEXT NULL DEFAULT NULL
      COMMENT 'Texte d''annonce synthétisé (IA) et éditable, affiché sur les affiches vitrine',
  ADD COLUMN IF NOT EXISTS `bien_titre_affiche` VARCHAR(255) NULL DEFAULT NULL
      COMMENT 'Titre d''annonce validé/édité dans le modal d''affiche, persisté pour réutilisation';
SQL
];
