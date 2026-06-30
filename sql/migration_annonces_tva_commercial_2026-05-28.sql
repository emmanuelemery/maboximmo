-- ============================================================================
-- Migration : champs TVA pour location commerciale/professionnelle
-- Date      : 2026-05-28
-- Contexte  : si usage_bien ∈ {commercial, pro}, l'annonce peut être assujettie
--             à TVA (20%). On stocke les montants HT + TVA + périodicité de paiement.
-- ----------------------------------------------------------------------------
-- Idempotent : IF NOT EXISTS (MariaDB 10.0.2+ / MySQL 8.0.29+)
-- ============================================================================

ALTER TABLE `annonces`
  ADD COLUMN IF NOT EXISTS `tva_assujetti` TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'Annonce assujettie à TVA 20% (bail commercial)',
  ADD COLUMN IF NOT EXISTS `loyer_hc_ht` DECIMAL(10,2) NULL DEFAULT NULL
      COMMENT 'Loyer hors charges HT (saisie principale si TVA assujettie)',
  ADD COLUMN IF NOT EXISTS `charges_ht` DECIMAL(10,2) NULL DEFAULT NULL
      COMMENT 'Charges locatives HT (si TVA assujettie)',
  ADD COLUMN IF NOT EXISTS `tva_montant` DECIMAL(10,2) NULL DEFAULT NULL
      COMMENT 'Montant TVA calculé (20% × (loyer_hc_ht + charges_ht))',
  ADD COLUMN IF NOT EXISTS `periodicite_loyer` VARCHAR(20) NULL DEFAULT 'mensuel'
      COMMENT 'Périodicité paiement loyer (mensuel | trimestriel)';
