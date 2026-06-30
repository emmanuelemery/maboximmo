<?php
/**
 * Migration : Ma GED Box V1 — Adaptation MÉTIER (ALTER colonnes)
 *
 * Transforme la GED en système métier (vue propriétaire → bien → locataire
 * → documents). Drive = stockage technique invisible. La source de vérité
 * reste la BDD MaBoxImmo.
 *
 * AJOUTS de colonnes (ADD COLUMN IF NOT EXISTS, idempotent) :
 *
 *   ged_folders :
 *     - is_virtual TINYINT DEFAULT 1
 *         1 = dossier MBI virtuel (pas matérialisé sur Drive)
 *         0 = dossier physique aussi présent sur Drive
 *     - folder_kind ENUM('business_view','storage_folder','system')
 *         business_view  : dossiers MBI (proprio, bien, ...)
 *         storage_folder : dossiers techniques Drive
 *         system         : corbeille, import, archives
 *     - storage_path VARCHAR(500)
 *         chemin physique relatif si folder_kind = 'storage_folder'
 *
 *   ged_documents :
 *     - confidence_score INT DEFAULT 0
 *         score IA de classement (0-100). >= 80 = auto-classé,
 *         < 80 = envoyé vers 99_A_CLASSER_IA pour validation humaine.
 *
 *   ged_document_links :
 *     - link_role VARCHAR(40) DEFAULT 'principal'
 *         principal | secondaire | piece_jointe | reference (cohabite
 *         avec relation_type pour compat ascendante).
 *
 * AJOUT uniquement — aucune table existante touchée, aucune colonne renommée.
 */

return [
    'id'          => '20260502_ged_v1_06_metier_alter',
    'title'       => 'Ma GED Box V1 — adaptation métier (is_virtual, folder_kind, storage_path, confidence_score, link_role)',
    'description' => "Ajoute les colonnes nécessaires à la transformation GED métier : ged_folders gagne is_virtual + folder_kind (business_view/storage_folder/system) + storage_path. ged_documents gagne confidence_score (0-100, seuil 80 pour auto-classement). ged_document_links gagne link_role (principal/secondaire/piece_jointe). Toutes les colonnes en ADD IF NOT EXISTS, idempotent. Backfill : tous les dossiers existants marqués folder_kind='system' is_virtual=1 (sécurité, peut être ré-affiné après).",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
-- ─── ged_folders : champs métier ──────────────────────────────────────
ALTER TABLE `ged_folders`
  ADD COLUMN IF NOT EXISTS `is_virtual` TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1 = dossier MBI virtuel (pas materialise Drive), 0 = aussi present Drive',
  ADD COLUMN IF NOT EXISTS `folder_kind` ENUM('business_view','storage_folder','system') NOT NULL DEFAULT 'business_view'
    COMMENT 'business_view (vue MBI metier) | storage_folder (Drive) | system (corbeille, archives)',
  ADD COLUMN IF NOT EXISTS `storage_path` VARCHAR(500) NULL
    COMMENT 'Chemin physique relatif si folder_kind = storage_folder';

ALTER TABLE `ged_folders`
  ADD INDEX IF NOT EXISTS `idx_ged_folders_folder_kind` (`folder_kind`),
  ADD INDEX IF NOT EXISTS `idx_ged_folders_is_virtual` (`is_virtual`);

-- ─── ged_documents : score IA ────────────────────────────────────────
ALTER TABLE `ged_documents`
  ADD COLUMN IF NOT EXISTS `confidence_score` INT NOT NULL DEFAULT 0
    COMMENT 'Score IA classement (0-100). >=80 auto-classe, <80 envoie 99_A_CLASSER_IA';

ALTER TABLE `ged_documents`
  ADD INDEX IF NOT EXISTS `idx_ged_documents_confidence` (`confidence_score`);

-- ─── ged_document_links : role du lien ───────────────────────────────
ALTER TABLE `ged_document_links`
  ADD COLUMN IF NOT EXISTS `link_role` VARCHAR(40) NOT NULL DEFAULT 'principal'
    COMMENT 'principal | secondaire | piece_jointe | reference';

ALTER TABLE `ged_document_links`
  ADD INDEX IF NOT EXISTS `idx_ged_document_links_role` (`link_role`);

-- ─── Backfill (sécurité) ─────────────────────────────────────────────
-- Les dossiers déjà créés sont des dossiers système globaux, on les
-- marque folder_kind='system' (le seed business_view sera fait par la
-- migration suivante 07_metier_seed).
UPDATE `ged_folders`
SET `folder_kind` = 'system'
WHERE `is_system` = 1 AND `folder_kind` = 'business_view';
SQL,
];
