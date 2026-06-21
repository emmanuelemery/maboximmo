-- =====================================================================
-- Migration 2026-06-21 : communes du secteur par agence (SEO local)
-- Champ libre éditable depuis l'admin "Textes des vitrines", utilisé par
-- la génération IA pour lister le secteur d'intervention (factuel).
-- =====================================================================
ALTER TABLE agences
  ADD COLUMN net_secteur_communes TEXT NULL
  COMMENT 'Communes du secteur (séparées par des virgules) pour le SEO des vitrines'
  AFTER horaires;
