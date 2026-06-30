<?php
/**
 * Migration v2 — ged_documents : scores granulaires + validation par champ + review
 *
 * Brief V2 : remplacer le score unique par 5 scores granulaires (type, entity,
 * date, structure, destination) + un score_global agrégé. Ajouter des flags de
 * validation par champ pour audit ciblé + apprentissage IA.
 *
 * RÉUTILISATION (pas de doublon créé) :
 *   - confidence_score existant CONSERVÉ → devient alias de score_global
 *     (compat ascendante avec le code existant)
 *   - name_display = équivalent du brief "name_export" (humain lisible)
 *   - old_filename = équivalent du brief "name_original" (créé v1_13)
 *
 * AJOUT uniquement (ADD COLUMN IF NOT EXISTS, idempotent).
 */

return [
    'id'          => '20260502_ged_v2_17_documents_scores_validated',
    'title'       => 'Ma GED Box V2 — ged_documents : 5 scores granulaires + validated_* + needs_review_reason + date_estimated',
    'description' => "Ajoute à ged_documents : 5 scores granulaires (score_type, score_entity, score_date, score_structure, score_destination — 0-100 chacun) + score_global (alias de confidence_score existant). 7 flags validated_* (n1, n2, entity, date, title, destination, global) pour audit ciblé. needs_review_reason ENUM (low_confidence, missing_entity, unknown_date, duplicate, ambiguous_type, manual_flag). date_estimated TINYINT(1) flag pour distinguer date réelle vs imputée à l'import. Pas de doublon : name_display réutilisé pour name_export, old_filename pour name_original. Idempotent.",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
ALTER TABLE `ged_documents`
  -- ─── Scores granulaires V2 ───
  ADD COLUMN IF NOT EXISTS `score_type` TINYINT UNSIGNED NULL
    COMMENT 'Score IA 0-100 confiance type document (FACTURE, BAIL...)',
  ADD COLUMN IF NOT EXISTS `score_entity` TINYINT UNSIGNED NULL
    COMMENT 'Score IA 0-100 confiance entite metier (proprio, immeuble...)',
  ADD COLUMN IF NOT EXISTS `score_date` TINYINT UNSIGNED NULL
    COMMENT 'Score IA 0-100 confiance date document',
  ADD COLUMN IF NOT EXISTS `score_structure` TINYINT UNSIGNED NULL
    COMMENT 'Score IA 0-100 confiance structure cascade N1-N5',
  ADD COLUMN IF NOT EXISTS `score_destination` TINYINT UNSIGNED NULL
    COMMENT 'Score IA 0-100 confiance destination GED MBI',
  ADD COLUMN IF NOT EXISTS `score_global` TINYINT UNSIGNED NULL
    COMMENT 'Score IA agrege 0-100 (alias de confidence_score si NULL)',

  -- ─── Validation par champ V2 ───
  ADD COLUMN IF NOT EXISTS `validated_n1` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `validated_n2` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `validated_entity` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `validated_date` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `validated_title` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `validated_destination` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `validated_global` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = doc valide en final (audit final OK)',

  -- ─── File "à revoir" (JSON pour cumuler plusieurs raisons, IA-friendly) ───
  ADD COLUMN IF NOT EXISTS `needs_review_reason` JSON NULL
    COMMENT 'Array : ["low_confidence","missing_entity","duplicate"...]',

  -- ─── Versioning des scores (pour recalcul futur) ───
  ADD COLUMN IF NOT EXISTS `score_version` INT NOT NULL DEFAULT 1
    COMMENT 'Version de l algo de scoring utilise. Permet recalcul si > version actuelle',

  -- ─── Date estimee ───
  ADD COLUMN IF NOT EXISTS `date_estimated` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = date_document non extraite, fallback = date import';

ALTER TABLE `ged_documents`
  ADD INDEX IF NOT EXISTS `idx_ged_documents_score_global` (`score_global`),
  ADD INDEX IF NOT EXISTS `idx_ged_documents_validated_global` (`validated_global`),
  ADD INDEX IF NOT EXISTS `idx_ged_documents_score_version` (`score_version`);
-- Pas d'index sur needs_review_reason (JSON), utiliser JSON_CONTAINS dans les requêtes
SQL,
];
