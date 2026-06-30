<?php
/**
 * Migration : ajoute biens.a_proposer (flag « bien à proposer » à un investisseur).
 * Concept DISTINCT de type_commercialisation (qui reste le mode de commercialisation
 * réel : vente/location). Un bien loué peut être « à proposer » sans perdre sa location.
 * Additif / rejouable.
 */
return [
    'id'          => '20260606f_biens_a_proposer',
    'title'       => 'biens.a_proposer (flag à proposer)',
    'description' => "Ajoute la colonne a_proposer (TINYINT) sur biens pour le mode « Biens à proposer », sans toucher à type_commercialisation.",
    'created_at'  => '2026-06-06',
    'sql' => <<<'SQL'
-- Défaut 1 : à la création de la colonne, TOUS les biens existants deviennent « à proposer ».
ALTER TABLE `biens`
  ADD COLUMN IF NOT EXISTS `a_proposer` TINYINT(1) NOT NULL DEFAULT 1 AFTER `type_commercialisation`;
SQL,
];
