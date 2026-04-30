-- =====================================================================
-- Migration : indexes pour le portail public mbi_annonces
-- Date     : 2026-04-30 (révisé pour idempotence)
-- Cible    : MariaDB 10.5+ / MySQL 8.0.29+
--            (CREATE INDEX IF NOT EXISTS supporté)
-- =====================================================================
-- Objectif : optimiser les requêtes du portail public (mbi_annonces_*)
--   - Filtre principal :       a.visible_maboximmo = 1
--   - Statuts publiés :        a.statut IN (...) AND b.statut_bien IN (...)
--   - Filtres optionnels :     ville, code_postal, type_bien, prix, surface, pièces
--   - Tri :                    date_mise_en_ligne DESC / prix / surface
--
-- IMPORTANT : version révisée 2026-04-30 — chaque CREATE INDEX est un
-- statement indépendant avec IF NOT EXISTS. Safe à rejouer plusieurs fois
-- (les indexes déjà créés sont sautés, pas d'erreur 1061).
--
-- À APPLIQUER UNE FOIS sur dev.maboximmo.fr puis prod, après V1 du portail.
-- =====================================================================

-- ─────────────────────────────────────────────────────────────────────
-- 1. Annonces : filtre + tri portail (visible_maboximmo)
-- ─────────────────────────────────────────────────────────────────────

CREATE INDEX IF NOT EXISTS `idx_mbi_annonces_visible_mbi`
  ON `annonces` (`visible_maboximmo`, `statut`, `type_transaction`);

CREATE INDEX IF NOT EXISTS `idx_mbi_annonces_visible_mbi_date`
  ON `annonces` (`visible_maboximmo`, `statut`, `date_mise_en_ligne`);

CREATE INDEX IF NOT EXISTS `idx_mbi_annonces_visible_mbi_prix`
  ON `annonces` (`visible_maboximmo`, `statut`, `prix`);

CREATE INDEX IF NOT EXISTS `idx_mbi_annonces_visible_mbi_loyer`
  ON `annonces` (`visible_maboximmo`, `statut`, `loyer`);

-- ─────────────────────────────────────────────────────────────────────
-- 2. Biens : filtres ville/CP/surface/pièces (joints à `annonces`)
-- ─────────────────────────────────────────────────────────────────────

CREATE INDEX IF NOT EXISTS `idx_mbi_biens_statut_ville`
  ON `biens` (`statut_bien`, `ville`, `code_postal`);

CREATE INDEX IF NOT EXISTS `idx_mbi_biens_statut_surface`
  ON `biens` (`statut_bien`, `surface_habitable`);

CREATE INDEX IF NOT EXISTS `idx_mbi_biens_statut_pieces`
  ON `biens` (`statut_bien`, `nb_pieces`);

CREATE INDEX IF NOT EXISTS `idx_mbi_biens_statut_type`
  ON `biens` (`statut_bien`, `id_type_bien`);

-- ─────────────────────────────────────────────────────────────────────
-- 3. Photos : variante medium/large pour la liste
-- ─────────────────────────────────────────────────────────────────────

-- Aucun index supplémentaire requis — les sous-requêtes des helpers
-- utilisent (id_annonce, variante, ordre_affichage) déjà indexés via
-- idx_annonces_photos_variante + uk_annonces_photos_ordre_variante.

-- =====================================================================
-- VÉRIFICATION (à exécuter pour valider que tous les indexes sont en place)
-- =====================================================================
-- SELECT TABLE_NAME, INDEX_NAME
-- FROM INFORMATION_SCHEMA.STATISTICS
-- WHERE TABLE_SCHEMA = DATABASE()
--   AND INDEX_NAME LIKE 'idx_mbi_%'
-- GROUP BY TABLE_NAME, INDEX_NAME
-- ORDER BY TABLE_NAME, INDEX_NAME;
-- → doit retourner 8 lignes (4 sur annonces + 4 sur biens)

-- =====================================================================
-- ROLLBACK (à exécuter manuellement si besoin de revenir en arrière)
-- =====================================================================
-- DROP INDEX `idx_mbi_annonces_visible_mbi`       ON `annonces`;
-- DROP INDEX `idx_mbi_annonces_visible_mbi_date`  ON `annonces`;
-- DROP INDEX `idx_mbi_annonces_visible_mbi_prix`  ON `annonces`;
-- DROP INDEX `idx_mbi_annonces_visible_mbi_loyer` ON `annonces`;
-- DROP INDEX `idx_mbi_biens_statut_ville`         ON `biens`;
-- DROP INDEX `idx_mbi_biens_statut_surface`       ON `biens`;
-- DROP INDEX `idx_mbi_biens_statut_pieces`        ON `biens`;
-- DROP INDEX `idx_mbi_biens_statut_type`          ON `biens`;
