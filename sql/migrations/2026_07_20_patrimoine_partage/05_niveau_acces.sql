-- =====================================================================
-- Migration 2026-07-20 (05) : niveau d'accès du partage (lecture / contribution)
-- ---------------------------------------------------------------------
-- Par défaut un partage est en LECTURE seule. Un lien en CONTRIBUTION
-- (ex: l'avocat) autorise le dépôt de documents (assignation, commandement,
-- jugement), les commentaires datés et la complétion des dossiers créanciers.
-- Le staff connecté (aperçu) écrit toujours, indépendamment de ce niveau.
-- =====================================================================

ALTER TABLE patrimoine_partages
  ADD COLUMN niveau_acces ENUM('lecture','contribution') NOT NULL DEFAULT 'lecture' AFTER montrer_descriptif;
