-- =====================================================================
-- Migration : sécurise les colonnes des canaux de diffusion sur `annonces`
-- Date     : 2026-04-30
-- Cible    : MariaDB 10.4+ / MySQL 8+
-- =====================================================================
-- Pourquoi ?
--   Les colonnes `visible_maboximmo` et `visible_site_perso` ont été ajoutées
--   à la main en BDD lors d'un patch précédent (visibles dans les sauvegardes
--   datées de mi-avril 2026). Aucune migration .sql n'avait été versionnée.
--
--   Le portail public mbi_annonces_* (livré 2026-04-30) filtre les annonces
--   sur `visible_maboximmo = 1`. Si la colonne manque sur une instance, le
--   portail crashe avec "Unknown column 'a.visible_maboximmo'".
--
--   Ce script ajoute les 2 colonnes IF NOT EXISTS (idempotent, safe à rejouer).
--
-- Usage : à exécuter UNE FOIS sur prod (et toute autre instance) avant
--         le déploiement du portail.
-- =====================================================================

-- MariaDB 10.0+ et MySQL 8.0.29+ supportent ADD COLUMN IF NOT EXISTS.
-- Sur des versions plus anciennes, retirer "IF NOT EXISTS" et accepter
-- l'erreur 1060 ("Duplicate column") si la colonne existe déjà.

ALTER TABLE `annonces`
  ADD COLUMN IF NOT EXISTS `visible_maboximmo`  TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Canal MaBoxImmo : visible dans le portail public mbi_annonces_*',
  ADD COLUMN IF NOT EXISTS `visible_site_perso` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Canal Site perso : visible sur le mini-site vitrine de l''agence';

-- =====================================================================
-- ROLLBACK (à exécuter manuellement si besoin)
-- =====================================================================
-- ALTER TABLE `annonces`
--   DROP COLUMN `visible_maboximmo`,
--   DROP COLUMN `visible_site_perso`;
