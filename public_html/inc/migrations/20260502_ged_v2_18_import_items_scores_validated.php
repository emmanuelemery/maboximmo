<?php
/**
 * Migration v2 — ged_import_items : mêmes scores granulaires + validated + review
 *
 * Symétrique de la migration v2_17 mais sur ged_import_items (préclassement
 * en cours, avant validation finale qui crée le ged_documents).
 *
 * AJOUT uniquement, idempotent.
 */

return [
    'id'          => '20260502_ged_v2_18_import_items_scores_validated',
    'title'       => 'Ma GED Box V2 — ged_import_items : 5 scores granulaires + validated_* + needs_review_reason',
    'description' => "Symétrique de v2_17 mais sur ged_import_items. Permet de tracer le score IA détaillé et la validation par champ pendant le préclassement (avant qu'un item devienne un ged_documents). Idempotent.",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
ALTER TABLE `ged_import_items`
  ADD COLUMN IF NOT EXISTS `score_type` TINYINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `score_entity` TINYINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `score_date` TINYINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `score_structure` TINYINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `score_destination` TINYINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `score_version` INT NOT NULL DEFAULT 1,

  ADD COLUMN IF NOT EXISTS `validated_n1` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `validated_n2` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `validated_entity` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `validated_date` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `validated_title` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `validated_destination` TINYINT(1) NOT NULL DEFAULT 0,

  ADD COLUMN IF NOT EXISTS `needs_review_reason` JSON NULL
    COMMENT 'Array de raisons cumulables : ["low_confidence","missing_entity"...]',

  ADD COLUMN IF NOT EXISTS `date_document` DATE NULL
    COMMENT 'Date reelle extraite du doc (sinon = date_estimated=1)',
  ADD COLUMN IF NOT EXISTS `date_estimated` TINYINT(1) NOT NULL DEFAULT 0,

  ADD COLUMN IF NOT EXISTS `entity_type_resolved` VARCHAR(40) NULL
    COMMENT 'Type entite metier resolu (proprietaire, immeuble, bien, locataire, fournisseur...)',
  ADD COLUMN IF NOT EXISTS `entity_id_resolved` BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS `entity_label_resolved` VARCHAR(180) NULL,

  ADD COLUMN IF NOT EXISTS `warnings_json` TEXT NULL
    COMMENT 'JSON array des warnings non bloquants (entity_missing, date_estimated...)';

ALTER TABLE `ged_import_items`
  ADD INDEX IF NOT EXISTS `idx_ged_import_items_score_version` (`score_version`),
  ADD INDEX IF NOT EXISTS `idx_ged_import_items_entity_resolved` (`entity_type_resolved`, `entity_id_resolved`);
-- Pas d'index sur needs_review_reason (JSON), utiliser JSON_CONTAINS
SQL,
];
