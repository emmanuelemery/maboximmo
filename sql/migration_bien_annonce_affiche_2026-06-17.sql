-- ============================================================================
-- Migration : champ "texte d'annonce synthétisé pour l'affiche" sur biens
-- Date      : 2026-06-17
-- Contexte  : le modal de choix de style d'affiche pré-remplit un texte
--             d'annonce synthétisé (IA) et éditable. On le persiste sur le bien
--             pour le réutiliser à chaque génération (et éviter de re-synthétiser).
-- ----------------------------------------------------------------------------
-- Idempotent : IF NOT EXISTS (MariaDB 10.0.2+ / MySQL 8.0.29+).
-- ============================================================================

ALTER TABLE `biens`
  ADD COLUMN IF NOT EXISTS `bien_annonce_affiche` TEXT NULL DEFAULT NULL
      COMMENT 'Texte d''annonce synthétisé (IA) et éditable, affiché sur les affiches vitrine',
  ADD COLUMN IF NOT EXISTS `bien_titre_affiche` VARCHAR(255) NULL DEFAULT NULL
      COMMENT 'Titre d''annonce validé/édité dans le modal d''affiche, persisté pour réutilisation';
