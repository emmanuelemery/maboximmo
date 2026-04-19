-- =====================================================================
-- Migration : ajout des canaux de diffusion granulaires pour les annonces
-- Date      : 2026-04-19
-- Contexte  : bien_detail_v2 section Annonce / Card 3 Diffusion
-- =====================================================================
--
-- La table `annonces` utilise déjà `visible_portails` (TINYINT) pour
-- piloter la diffusion vers Ubiflow (LeBonCoin / SeLoger / Bien'ici).
-- Nouveau : 2 canaux internes distincts pour permettre à l'utilisateur
-- de choisir indépendamment où diffuser :
--   - `visible_maboximmo`   : visible dans l'annuaire interne MaBoxImmo
--   - `visible_site_perso`  : visible sur le site web perso de l'agence
--
-- `visible_portails` reste le canal Ubiflow existant (inchangé).
-- =====================================================================

ALTER TABLE `annonces`
  ADD COLUMN IF NOT EXISTS `visible_maboximmo`  TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Canal MaBoxImmo : visible dans l''annuaire interne',
  ADD COLUMN IF NOT EXISTS `visible_site_perso` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Canal Site perso : visible sur le site web de l''agence';

-- Index combiné pour les requêtes de listing public (optionnel mais utile)
-- ALTER TABLE `annonces`
--   ADD INDEX IF NOT EXISTS `idx_annonces_canaux`
--   (`visible_maboximmo`, `visible_site_perso`, `visible_portails`, `etat_publication`);
