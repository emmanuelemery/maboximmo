<?php
/**
 * Migration : ajoute le rôle 'commercialisateur' à dossier_vente_partage
 * (pour les installs où la table existait déjà avec 2 valeurs d'ENUM). Idempotente.
 */
return [
    'id'          => '20260727b_dossier_vente_partage_commercialisateur',
    'title'       => 'Transaction : 3e rôle de partage « commercialisateur »',
    'description' => "Étend role_destinataire (acquereur, notaire, commercialisateur).",
    'created_at'  => '2026-07-27',
    'sql' => <<<'SQL'
ALTER TABLE `dossier_vente_partage`
  MODIFY COLUMN `role_destinataire` ENUM('acquereur','notaire','commercialisateur') NOT NULL DEFAULT 'acquereur';
SQL,
];
