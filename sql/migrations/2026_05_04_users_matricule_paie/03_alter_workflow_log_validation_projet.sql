-- =====================================================================
-- Migration 2026-05-04 (suite) : ajout du type d'action 'validation_projet'
-- dans rh_salaire_workflow_log.type_action.
--
-- Permet de tracer la validation par l'agent du projet de paie envoye par
-- le comptable, avec un email retour incluant les commentaires de l'agent.
-- =====================================================================

ALTER TABLE rh_salaire_workflow_log
  MODIFY COLUMN type_action ENUM(
    'envoi_comptable',
    'import_projet',
    'import_bulletins',
    'validation_projet'
  ) NOT NULL;
