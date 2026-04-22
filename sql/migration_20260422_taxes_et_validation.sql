-- ════════════════════════════════════════════════════════════════
-- Migration 2026-04-22 ter : Ajouts pour validation bien + taxes
-- ════════════════════════════════════════════════════════════════
-- Objectifs :
--   1. Ajouter annonces.taxe_ordures_menageres (TOM) à côté de taxe_fonciere
--      et taxe_habitation pour la section Conditions financières
--   2. (statut_bien existe déjà, pas besoin d'ALTER)

ALTER TABLE `annonces`
ADD COLUMN `taxe_ordures_menageres` DECIMAL(10,2) NULL
    COMMENT 'Taxe enlèvement ordures ménagères (TEOM/TOM), annuelle €'
AFTER `taxe_habitation`;
