-- =====================================================================
-- Migration 2026-05-04 (suite) : ajout rh_salaires_comparaisons.id_agence
-- Objet : permettre une comparaison PAR AGENCE (et plus seulement par
--         société) — nécessaire au mode "import unifié multi-agences"
--         qui dispatche automatiquement les bulletins du PDF mensuel In
--         Extenso vers la bonne agence via matricule -> user.id_agence.
-- =====================================================================

ALTER TABLE rh_salaires_comparaisons
  ADD COLUMN id_agence INT NULL DEFAULT NULL
  COMMENT 'Agence concernee (NULL si import societe-global, dispatch auto sinon)'
  AFTER id_societe;

ALTER TABLE rh_salaires_comparaisons
  ADD INDEX idx_compare_lookup (id_societe, id_agence, mois, annee, type);

-- Cleanup test data avant ré-import : retire les comparaisons et l'historique
-- workflow des mois de test 03/04/05 2026, pour repartir d'une base propre.
DELETE FROM rh_salaires_comparaisons
  WHERE annee = 2026 AND mois IN (3, 4, 5);

DELETE FROM rh_salaire_workflow_log
  WHERE mois_reference IN ('2026-03-01', '2026-04-01', '2026-05-01');
