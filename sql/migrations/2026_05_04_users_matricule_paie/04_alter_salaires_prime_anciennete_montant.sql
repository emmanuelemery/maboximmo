-- =====================================================================
-- Migration 2026-05-04 (suite) : ajout du champ saisi a la main
-- salaires.prime_anciennete_montant.
--
-- La formule auto brut * anciennete * 1% / 12 ne correspond pas au bareme
-- CCN immobilier reellement applique par le comptable (32 EUR par tranche
-- de 3 ans, plafonnee). On passe a un champ DECIMAL(10,2) saisi a la main
-- (comme prime_admin et prime_exceptionnelle) pour avoir une comparaison
-- correcte vs le PDF du comptable.
-- =====================================================================

ALTER TABLE salaires
  ADD COLUMN prime_anciennete_montant DECIMAL(10,2) NULL DEFAULT NULL
  COMMENT 'Prime anciennete saisie a la main (bareme CCN), utilisee a la place de la formule auto'
  AFTER anciennete,
  ADD COLUMN comment_prime_anciennete_montant TEXT NULL DEFAULT NULL
  AFTER comment_anciennete;
