<?php
/**
 * Migration : archivage / vente d'un immeuble
 *
 * Ajoute les colonnes nécessaires pour marquer un immeuble comme vendu ou
 * archivé (changement de gérance, démolition, etc.).
 *
 *   - statut_immeuble (VARCHAR existant) : on ajoute les valeurs 'vendu' et 'archive'
 *   - date_vente      : date effective de cession
 *   - prix_vente      : prix acte en main
 *   - motif_archivage : texte libre décrivant la raison d'archivage
 */

return [
    'id'          => '20260423_immeubles_vente',
    'title'       => 'Immeubles : champs vente / archivage',
    'description' => "Ajoute date_vente, prix_vente, motif_archivage à la table immeubles pour gérer les sorties du portefeuille.",
    'created_at'  => '2026-04-23',
    'sql' => <<<'SQL'
ALTER TABLE `immeubles`
    ADD COLUMN IF NOT EXISTS `date_vente`      DATE NULL COMMENT 'Date de cession' AFTER `statut_immeuble`,
    ADD COLUMN IF NOT EXISTS `prix_vente`      DECIMAL(12,2) NULL COMMENT 'Prix de cession (acte en main)' AFTER `date_vente`,
    ADD COLUMN IF NOT EXISTS `motif_archivage` VARCHAR(180) NULL AFTER `prix_vente`;
SQL,
];
