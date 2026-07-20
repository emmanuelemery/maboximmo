-- =====================================================================
-- Migration 2026-07-20 (02) : destinataire = TIERS (BDD) + scénarios multi
-- ---------------------------------------------------------------------
-- - Le destinataire du partage est désormais un TIERS existant (recherche
--   BDD via api/tiers_lookup.php). destinataire_nom/email = snapshot d'affichage.
-- - scenario_code devient une LISTE de scénarios partagés (CSV), le tiers
--   choisit lequel consulter sur la page publique. Le 1er = scénario par défaut.
-- =====================================================================

ALTER TABLE patrimoine_partages
  ADD COLUMN id_tiers_destinataire INT UNSIGNED NULL COMMENT 'tiers.id du destinataire' AFTER id_user_gestionnaire,
  MODIFY COLUMN scenario_code VARCHAR(255) NOT NULL DEFAULT 'courant'
    COMMENT 'liste CSV des scénarios partagés (1er = défaut)';

ALTER TABLE patrimoine_partages
  ADD KEY idx_tiers (id_tiers_destinataire);
