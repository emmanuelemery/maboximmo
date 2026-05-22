<?php
/**
 * Migration : mandats.autorisation_diffusion
 *
 * Ajoute la colonne booléenne `autorisation_diffusion` à la table `mandats`.
 *
 * Bug constaté 2026-05-22 : la card « Mentions légales » propose une case
 * « Autorisation de diffusion signée » qui s'enregistre dans
 * mandats.autorisation_diffusion. Comme la colonne n'existait pas, le save
 * tombait en `column_missing_in_db` et l'utilisateur restait bloqué avec
 * 2 mentions à corriger (la règle `autorisation_diffusion_signee` ET sa
 * sœur `autorisation_diffusion_couvre_canaux` testent toutes deux ce flag).
 *
 * Une fois cochée, les 2 règles passent simultanément.
 */

return [
    'id'          => '20260523_mandats_autorisation_diffusion',
    'title'       => 'Ajout colonne mandats.autorisation_diffusion (TINYINT)',
    'description' => "Ajoute la colonne `autorisation_diffusion` à la table `mandats`. Permet à la card « Mentions légales » d'enregistrer la case « Autorisation de diffusion signée » (sinon bug column_missing_in_db et compteur de mentions bloqué à 2).",
    'created_at'  => '2026-05-23',
    'sql'         => <<<'SQL'

ALTER TABLE `mandats`
  ADD COLUMN IF NOT EXISTS `autorisation_diffusion` TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Le mandat autorise la diffusion sur les portails (cf. card Mentions légales)';

SQL
];
