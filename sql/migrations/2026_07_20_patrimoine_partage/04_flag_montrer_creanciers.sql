-- =====================================================================
-- Migration 2026-07-20 (04) : flag d'affichage des dossiers créanciers
-- ---------------------------------------------------------------------
-- Le voyant/KPI « dossiers créanciers » sur la ligne propriétaire ne doit
-- apparaître que pour les destinataires autorisés (ex: avocat), jamais par
-- défaut (confidentialité vis-à-vis d'un banquier). Éteint par défaut.
-- =====================================================================

ALTER TABLE patrimoine_partages
  ADD COLUMN montrer_creanciers TINYINT(1) NOT NULL DEFAULT 0 AFTER montrer_prix_vente;
