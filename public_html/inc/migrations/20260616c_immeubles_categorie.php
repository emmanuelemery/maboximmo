<?php
/**
 * Migration : catégorie métier manuelle d'un immeuble (Syndic / Gestion / Transaction).
 *
 * Règle métier : TOUT immeuble est GESTION par défaut, sauf ceux qui ont une annonce
 * (mis en vente/location) que l'on classe à la main en Syndic / Gestion / Transaction.
 * `categorie_mbi` NULL = GESTION (défaut). Saisi via les 3 boutons sur la fiche liste.
 *
 * Idempotent : ajoute la colonne seulement si absente.
 */
return [
    'id'          => '20260616c_immeubles_categorie',
    'title'       => 'Immeubles : colonne categorie_mbi (Syndic/Gestion/Transaction manuel)',
    'description' => "Ajoute immeubles.categorie_mbi (NULL=GESTION par défaut). Permet de classer manuellement les immeubles avec annonce.",
    'created_at'  => '2026-06-16',
    'sql' => <<<'SQL'
ALTER TABLE `immeubles`
  ADD COLUMN IF NOT EXISTS `categorie_mbi` VARCHAR(20) NULL DEFAULT NULL
  COMMENT 'SYNDIC|GESTION|TRANSACTION — NULL=GESTION par défaut';
SQL
];
