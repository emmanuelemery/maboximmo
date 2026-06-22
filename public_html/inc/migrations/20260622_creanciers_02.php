<?php
/**
 * Migration : Module CRÉANCIERS — persistance fichier pour le commit GED.
 *
 * Ajoute à creancier_doc_analyse les colonnes nécessaires pour committer le PDF
 * analysé vers la GED (gus_commit_document) AU MOMENT DE LA VALIDATION, et pas
 * à l'analyse. Sans ça, le fichier uploadé serait perdu après l'extraction IA.
 *
 * AJOUT uniquement (ADD COLUMN IF NOT EXISTS — MariaDB). NE TOUCHE À RIEN d'autre.
 *
 * ── ROLLBACK (-- DOWN) ────────────────────────────────────────────────
 *   ALTER TABLE `creancier_doc_analyse`
 *     DROP COLUMN `storage_path`, DROP COLUMN `file_hash`,
 *     DROP COLUMN `file_mime`, DROP COLUMN `file_size`, DROP COLUMN `file_name`;
 */

return [
    'id'          => '20260622_creanciers_02',
    'title'       => 'CRÉANCIERS — colonnes fichier sur creancier_doc_analyse (commit GED à la validation)',
    'description' => "Ajoute storage_path, file_hash, file_mime, file_size, file_name à creancier_doc_analyse pour permettre le commit GED du document au moment de sa validation. AJOUT uniquement.",
    'created_at'  => '2026-06-22',
    'sql' => <<<'SQL'
ALTER TABLE `creancier_doc_analyse`
  ADD COLUMN IF NOT EXISTS `storage_path` VARCHAR(500) NULL COMMENT 'Chemin disque du fichier en attente de commit GED' AFTER `donnees_json`,
  ADD COLUMN IF NOT EXISTS `file_hash` CHAR(64) NULL COMMENT 'SHA-256 (déduplication GED)' AFTER `storage_path`,
  ADD COLUMN IF NOT EXISTS `file_mime` VARCHAR(100) NULL AFTER `file_hash`,
  ADD COLUMN IF NOT EXISTS `file_size` BIGINT UNSIGNED NULL AFTER `file_mime`,
  ADD COLUMN IF NOT EXISTS `file_name` VARCHAR(255) NULL COMMENT 'Nom original du fichier' AFTER `file_size`;
SQL
];
