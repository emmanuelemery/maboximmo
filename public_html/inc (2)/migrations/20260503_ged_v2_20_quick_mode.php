<?php
/**
 * Migration v2 — Mode "Import rapide"
 *
 * Permet aux users d'importer + valider des documents sans renommage parfait
 * (TITLE / N6 / scores moyens acceptés). Utilisé pour les imports en masse
 * où la valeur ajoutée est le rattachement métier (entité + module), pas la
 * cosmétique du nom.
 *
 * Règles :
 *   - mode = 'normalized' (défaut) : validation classique (TITLE obligatoire,
 *     scores ≥ 70 recommandés, name_canonical complet)
 *   - mode = 'quick' : validation light si validated_n1 + n2 + entity = 1.
 *     name_canonical généré en arrière-plan automatiquement.
 *     name_file = uuid.ext (pas de renommage physique).
 *     status = 'classified_quick' au lieu de 'validated' (distinction visuelle).
 *
 * AJOUT uniquement, idempotent.
 */

return [
    'id'          => '20260503_ged_v2_20_quick_mode',
    'title'       => 'Ma GED Box V2 — mode quick (import rapide sans renommage parfait)',
    'description' => "Ajoute la colonne mode ENUM('normalized','quick') sur ged_import_items et ged_documents pour distinguer les imports rapides (validation light : N1+N2+entité suffisent, name_canonical en arrière-plan, name_file=uuid.ext) des imports normalisés (TITLE+scores complets). Étend l'ENUM status de ged_import_items pour ajouter 'classified_quick'. Idempotent.",
    'created_at'  => '2026-05-03',
    'sql' => <<<'SQL'
-- ─── ged_import_items : mode + extension status ───
ALTER TABLE `ged_import_items`
  ADD COLUMN IF NOT EXISTS `mode` ENUM('normalized','quick') NOT NULL DEFAULT 'normalized'
    COMMENT 'normalized = validation classique (TITLE+scores) ; quick = light (N1+N2+entite suffisent)';

-- Étend l'ENUM status pour ajouter 'classified_quick' (sans toucher aux valeurs existantes)
ALTER TABLE `ged_import_items`
  MODIFY COLUMN `status` ENUM('imported','proposed','validated','to_review','ignored','error','classified_quick') NOT NULL DEFAULT 'imported';

ALTER TABLE `ged_import_items`
  ADD INDEX IF NOT EXISTS `idx_ged_import_items_mode` (`mode`);

-- ─── ged_documents : mode (traçabilité du mode d'import) ───
ALTER TABLE `ged_documents`
  ADD COLUMN IF NOT EXISTS `mode` ENUM('normalized','quick') NOT NULL DEFAULT 'normalized'
    COMMENT 'Mode d import. quick = name_file en uuid.ext, name_canonical generated background';

ALTER TABLE `ged_documents`
  ADD INDEX IF NOT EXISTS `idx_ged_documents_mode` (`mode`);

-- ─── ged_import_batches : mode par défaut du batch (info) ───
ALTER TABLE `ged_import_batches`
  ADD COLUMN IF NOT EXISTS `default_mode` ENUM('normalized','quick') NOT NULL DEFAULT 'normalized'
    COMMENT 'Mode applique a tous les items du batch sauf override individuel';
SQL,
];
