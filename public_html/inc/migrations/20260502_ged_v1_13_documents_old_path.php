<?php
/**
 * Migration v1.1 — ged_documents : ajout des colonnes natives "ancien chemin/nom"
 *
 * Le brief impose que old_filename + old_folder_path soient des colonnes natives
 * dans ged_documents (pas seulement dans metadata JSON), pour permettre des
 * recherches/joins rapides sans parsing JSON.
 *
 * Ajoute aussi proposed_destination + final_destination (chemins GED MBI proposé
 * par l'IA et choisi par l'humain — utile pour reporting et dédoublonnage).
 *
 * AJOUT uniquement (ADD COLUMN IF NOT EXISTS, idempotent). Ne touche aucune
 * colonne existante. Compatible avec les écritures actuelles (qui mettront
 * NULL par défaut, et continueront éventuellement à dupliquer dans metadata
 * JSON pour rétrocompatibilité).
 */

return [
    'id'          => '20260502_ged_v1_13_documents_old_path',
    'title'       => 'Ma GED Box V1.1 — ged_documents : old_filename, old_folder_path, proposed/final_destination',
    'description' => "Ajoute 4 colonnes natives à ged_documents : old_filename, old_folder_path, proposed_destination, final_destination. Brief v1.1 demande ces champs en colonnes (pas seulement metadata JSON) pour requêtes/joins rapides. Idempotent. Aucune colonne existante touchée — additif strict.",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
ALTER TABLE `ged_documents`
  ADD COLUMN IF NOT EXISTS `old_filename` VARCHAR(255) NULL
    COMMENT 'Nom de fichier d origine (avant import GED, conserve pour audit)',
  ADD COLUMN IF NOT EXISTS `old_folder_path` VARCHAR(1024) NULL
    COMMENT 'Chemin source du fichier (Telechargements/, dossier client, etc.)',
  ADD COLUMN IF NOT EXISTS `proposed_destination` VARCHAR(1024) NULL
    COMMENT 'Path GED MBI propose par IA cascade (slug/separated)',
  ADD COLUMN IF NOT EXISTS `final_destination` VARCHAR(1024) NULL
    COMMENT 'Path GED MBI finalement choisi par l humain (= source de verite)';

ALTER TABLE `ged_documents`
  ADD INDEX IF NOT EXISTS `idx_ged_documents_old_filename` (`old_filename`),
  ADD INDEX IF NOT EXISTS `idx_ged_documents_final_destination` (`final_destination`(255));
SQL,
];
