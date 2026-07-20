-- =====================================================================
-- Migration 2026-07-20 (03) : lien explicite dossier créancier ↔ propriétaire
-- ---------------------------------------------------------------------
-- Le voyant/KPI « dossiers créanciers » s'affiche sur la ligne du PROPRIÉTAIRE
-- (page patrimoine_partage.php). On matérialise donc un lien explicite
-- creancier_dossier_lien(entity_type='PROPRIETAIRE', entity_id=proprietaires.id,
-- role_dossier='proprietaire_concerne').
--
-- Backfill : les SCI/propriétaires figurent aujourd'hui comme DÉBITEUR dans les
-- dossiers (dettes bancaires/fiscales). On projette ce lien via
-- proprietaires.id_tiers = tiers débiteur du dossier. Idempotent (NOT EXISTS).
-- Les dossiers créés ensuite par l'avocat poseront directement ce lien.
-- =====================================================================

INSERT INTO creancier_dossier_lien (id_dossier, entity_type, entity_id, role_dossier, note, created_at)
SELECT DISTINCT dl.id_dossier, 'PROPRIETAIRE', p.id, 'proprietaire_concerne',
       'backfill: débiteur = propriétaire', NOW()
FROM creancier_dossier_lien dl
JOIN proprietaires p ON p.id_tiers = dl.entity_id
WHERE dl.entity_type = 'TIERS'
  AND dl.role_dossier IN ('debiteur','debiteur_solidaire')
  AND NOT EXISTS (
      SELECT 1 FROM creancier_dossier_lien x
      WHERE x.id_dossier = dl.id_dossier
        AND x.entity_type = 'PROPRIETAIRE'
        AND x.entity_id = p.id
  );
