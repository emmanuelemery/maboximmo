<?php
/**
 * Migration 20260630b — FluxBox : ingestion par lot à contexte partagé (Correctif 6)
 *
 * AJOUTS UNIQUEMENT (idempotent) :
 *   1. fluxbox_lots          : un dépôt de dossier / lot multi-fichiers = un PÉRIMÈTRE.
 *                              Entité + société + agence verrouillées pour tous ses fichiers.
 *   2. fluxbox_cartes.lot_id  + index : rattache chaque carte à son lot.
 *
 * Invariant : dans un même lot_id, entité/société/agence IDENTIQUES pour tous les fichiers.
 * Cohérent avec [[project_fluxbox_resolution_contexte]] et le contrat fluxbox_resoudre_contexte().
 */

return [
    'id'          => '20260630b_fluxbox_lots',
    'title'       => 'FluxBox — lots à contexte partagé (fluxbox_lots + fluxbox_cartes.lot_id)',
    'description' => "Ajoute la table fluxbox_lots (un dossier/lot = un périmètre : entité+société+agence verrouillées) et la colonne lot_id sur fluxbox_cartes. Permet d'hériter le contexte du lot (source 'lot') sur tous les fichiers. Ajouts seulement, idempotent.",
    'created_at'  => '2026-06-30',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `fluxbox_lots` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`    INT UNSIGNED NOT NULL,
  `libelle`      VARCHAR(255) NULL COMMENT 'Nom du dossier déposé (ancre entité prioritaire)',
  `entite_type`  VARCHAR(40)  NULL COMMENT 'BIEN|IMB|TIERS|USER|SOCIETE — entité verrouillée du lot',
  `entite_id`    BIGINT UNSIGNED NULL,
  `entite_label` VARCHAR(255) NULL,
  `societe_id`   INT UNSIGNED NULL,
  `agence_id`    INT UNSIGNED NULL,
  `source`       ENUM('fiche','nom_dossier','contexte_explicite','contexte_user') NOT NULL DEFAULT 'nom_dossier',
  `nb_fichiers`  INT UNSIGNED NOT NULL DEFAULT 0,
  `created_by`   INT UNSIGNED NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_fluxbox_lots_tenant` (`tenant_id`, `entite_type`, `entite_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `fluxbox_cartes`
  ADD COLUMN IF NOT EXISTS `lot_id` BIGINT UNSIGNED NULL
    COMMENT 'Rattachement au lot (fluxbox_lots) — contexte partagé verrouillé'
    AFTER `resolution_json`;

ALTER TABLE `fluxbox_cartes`
  ADD INDEX IF NOT EXISTS `idx_fluxbox_cartes_lot` (`tenant_id`, `lot_id`);
SQL,
];
