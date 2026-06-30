<?php
/**
 * Migration : canaux de diffusion granulaires pour les annonces
 *
 * Contexte : bien_detail_v2 section Annonce / Card 3 Diffusion permet
 * de choisir indépendamment où publier une annonce :
 *   - `visible_maboximmo`   → annuaire interne MaBoxImmo
 *   - `visible_site_perso`  → site web de l'agence
 *   - `visible_portails`    → Ubiflow (LeBonCoin, SeLoger, Bien'ici) [existe déjà]
 *
 * Règle d'or : statements additifs (IF NOT EXISTS) → rejouables.
 */

return [
    'id'          => '20260420_annonces_canaux_diffusion',
    'title'       => 'Annonces : canaux de diffusion granulaires (MaBoxImmo + Site perso)',
    'description' => 'Ajoute 2 colonnes TINYINT sur `annonces` pour piloter séparément la diffusion vers l\'annuaire interne MaBoxImmo et le site perso de l\'agence. `visible_portails` (Ubiflow) reste inchangé.',
    'created_at'  => '2026-04-20',
    'sql' => <<<'SQL'
ALTER TABLE `annonces`
  ADD COLUMN IF NOT EXISTS `visible_maboximmo`  TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Canal MaBoxImmo : visible dans l\'annuaire interne';

ALTER TABLE `annonces`
  ADD COLUMN IF NOT EXISTS `visible_site_perso` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Canal Site perso : visible sur le site web de l\'agence';
SQL,
];
