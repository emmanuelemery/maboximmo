-- ============================================================================
-- Migration : 3 colonnes ALUR copropriété sur la table immeubles
-- Date      : 2026-05-28
-- Contexte  : ces 3 booléens décrivent le STATUT JURIDIQUE de la copropriété
--             entière (pas du lot individuel) → ils appartiennent à l'immeuble.
-- ----------------------------------------------------------------------------
-- Idempotent : IF NOT EXISTS (MariaDB 10.0.2+ / MySQL 8.0.29+).
-- Si votre version ne supporte pas, retirer ces clauses.
-- Pas de backfill : les anciennes colonnes biens.* étaient vides chez tous.
-- ============================================================================

ALTER TABLE `immeubles`
  ADD COLUMN IF NOT EXISTS `copro_procedure` TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'Syndic en procédure (balise <alur_syndic_en_procedure>)',
  ADD COLUMN IF NOT EXISTS `alur_copropriete_plan_sauvegarde` TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'Plan de sauvegarde (balise <alur_copropriete_plan_de_sauvegarde>)',
  ADD COLUMN IF NOT EXISTS `alur_copropriete_etat_carence` TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'État de carence (balise <copropriete_etat_carence>)';
