-- =====================================================================
-- Migration : indexes pour le portail public mbi_annonces
-- Date     : 2026-04-30
-- Cible    : MariaDB 10.4+ / MySQL 8+
-- =====================================================================
-- Objectif : optimiser les requêtes du portail public (mbi_annonces_*)
--   - Filtre principal :       a.visible_maboximmo = 1
--   - Statuts publiés :        a.statut IN (...) AND b.statut_bien IN (...)
--   - Filtres optionnels :     ville, code_postal, type_bien, prix, surface, pièces
--   - Tri :                    date_mise_en_ligne DESC / prix / surface
--
-- NOTE : la table `annonces` a déjà un index `idx_annonces_recherche_1`
--        sur (visible_site, type_transaction, statut, prix). On en ajoute
--        des équivalents sur visible_maboximmo (qui est le flag MBI réel).
--
-- À APPLIQUER UNE FOIS sur dev.maboximmo.fr puis prod, après V1 du portail.
-- Toutes les commandes utilisent IF NOT EXISTS quand le moteur le permet.
-- =====================================================================

-- ─────────────────────────────────────────────────────────────────────
-- 1. Annonces : filtre + tri portail
-- ─────────────────────────────────────────────────────────────────────

ALTER TABLE `annonces`
  ADD INDEX `idx_mbi_annonces_visible_mbi`
    (`visible_maboximmo`, `statut`, `type_transaction`),
  ADD INDEX `idx_mbi_annonces_visible_mbi_date`
    (`visible_maboximmo`, `statut`, `date_mise_en_ligne`),
  ADD INDEX `idx_mbi_annonces_visible_mbi_prix`
    (`visible_maboximmo`, `statut`, `prix`),
  ADD INDEX `idx_mbi_annonces_visible_mbi_loyer`
    (`visible_maboximmo`, `statut`, `loyer`);

-- ─────────────────────────────────────────────────────────────────────
-- 2. Biens : filtres ville/CP/surface/pièces (joints à `annonces`)
-- ─────────────────────────────────────────────────────────────────────

-- Vérifier d'abord si ces indexes existent déjà (cf. dump prod).
-- Si oui, les statements ci-dessous lèveront une erreur 1061 — c'est SAFE
-- (rien n'est modifié), il suffit de continuer.

ALTER TABLE `biens`
  ADD INDEX `idx_mbi_biens_statut_ville`
    (`statut_bien`, `ville`, `code_postal`),
  ADD INDEX `idx_mbi_biens_statut_surface`
    (`statut_bien`, `surface_habitable`),
  ADD INDEX `idx_mbi_biens_statut_pieces`
    (`statut_bien`, `nb_pieces`),
  ADD INDEX `idx_mbi_biens_statut_type`
    (`statut_bien`, `id_type_bien`);

-- ─────────────────────────────────────────────────────────────────────
-- 3. Photos : variante medium/large pour la liste (déjà couvert par
--    idx_annonces_photos_variante + uk_annonces_photos_ordre_variante)
-- ─────────────────────────────────────────────────────────────────────

-- Aucun index supplémentaire requis — les sous-requêtes des helpers
-- utilisent (id_annonce, variante, ordre_affichage) déjà indexés.

-- =====================================================================
-- ROLLBACK (à exécuter manuellement si besoin)
-- =====================================================================
-- ALTER TABLE `annonces`
--   DROP INDEX `idx_mbi_annonces_visible_mbi`,
--   DROP INDEX `idx_mbi_annonces_visible_mbi_date`,
--   DROP INDEX `idx_mbi_annonces_visible_mbi_prix`,
--   DROP INDEX `idx_mbi_annonces_visible_mbi_loyer`;
--
-- ALTER TABLE `biens`
--   DROP INDEX `idx_mbi_biens_statut_ville`,
--   DROP INDEX `idx_mbi_biens_statut_surface`,
--   DROP INDEX `idx_mbi_biens_statut_pieces`,
--   DROP INDEX `idx_mbi_biens_statut_type`;
