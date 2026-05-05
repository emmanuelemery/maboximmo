<?php
/**
 * Migration : OCR docs officiels Société sur rh_documents (refonte LOT 4.B)
 *
 * Le user a corrigé le 2026-05-06 : les docs officiels (KBIS, carte pro CPI,
 * garantie financière, RC pro, barème honoraires) sont délivrés au niveau
 * SOCIÉTÉ, et la page rh_documents.php (rubrique « Société ») est déjà
 * conçue pour les héberger. On enrichit donc rh_documents avec les colonnes
 * OCR/alertes au lieu de créer une table dédiée.
 *
 * Avantages :
 *   - 1 seule page de gestion (rh_documents.php) — pas de duplication d'UX
 *   - Système de droits déjà en place (rh_doc_archive avec role check)
 *   - 3 types KBIS/RCP/Convention déjà seedés — on ajoute carte_pro,
 *     garant_financier, bareme_honoraires
 *
 * Suppression de la table agences_documents_officiels (créée la veille,
 * jamais utilisée en prod, refonte d'architecture).
 */

return [
    'id'          => '20260506_rh_documents_ocr_societe',
    'title'       => 'Docs officiels Société : OCR + alertes sur rh_documents (refonte)',
    'description' => 'Ajoute colonnes OCR (numero, emetteur, dates, ocr_json, etc.) et tracking alertes 120/90/60j sur rh_documents. Seed 3 types manquants dans rh_doc_types (carte_pro, garant_financier, bareme_honoraires). Supprime agences_documents_officiels (refonte).',
    'created_at'  => '2026-05-06',
    'sql' => <<<'SQL'
-- Suppression de la table erronée du 2026-05-05
DROP TABLE IF EXISTS `agences_documents_officiels`;

-- Colonnes OCR sur rh_documents (idempotent : ADD COLUMN IF NOT EXISTS)
ALTER TABLE `rh_documents`
  ADD COLUMN IF NOT EXISTS `numero`                 VARCHAR(150)   NULL COMMENT 'OCR : N° carte pro / RCS / contrat',
  ADD COLUMN IF NOT EXISTS `emetteur`               VARCHAR(255)   NULL COMMENT 'OCR : CCI / Greffe / Assureur / Garant',
  ADD COLUMN IF NOT EXISTS `montant_garantie`       DECIMAL(12,2)  NULL COMMENT 'OCR : plafond garant financier (€)',
  ADD COLUMN IF NOT EXISTS `date_emission`          DATE           NULL COMMENT 'OCR : date d''émission du document',
  ADD COLUMN IF NOT EXISTS `date_validite`          DATE           NULL COMMENT 'OCR : pivot des alertes 120/90/60j',
  ADD COLUMN IF NOT EXISTS `ocr_modele`             VARCHAR(50)    NULL COMMENT 'Modèle IA (claude-sonnet-4-6)',
  ADD COLUMN IF NOT EXISTS `ocr_confidence`         TINYINT UNSIGNED NULL COMMENT '0-100',
  ADD COLUMN IF NOT EXISTS `ocr_cout_centimes`      INT UNSIGNED   NULL,
  ADD COLUMN IF NOT EXISTS `ocr_json`               JSON           NULL COMMENT 'Snapshot OCR brut (audit)',
  ADD COLUMN IF NOT EXISTS `ocr_at`                 TIMESTAMP      NULL,
  ADD COLUMN IF NOT EXISTS `alerte_120j_envoyee_at` TIMESTAMP      NULL,
  ADD COLUMN IF NOT EXISTS `alerte_90j_envoyee_at`  TIMESTAMP      NULL,
  ADD COLUMN IF NOT EXISTS `alerte_60j_envoyee_at`  TIMESTAMP      NULL;

-- Index sur date_validite pour le cron alertes (uniquement docs actifs et non archivés)
-- Note : on ne peut pas utiliser CREATE INDEX IF NOT EXISTS dans toutes les versions MySQL,
-- on tente la création avec gestion silencieuse via PROCEDURE.
SET @stmt := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rh_documents'
      AND INDEX_NAME = 'idx_rh_docs_validite_alertes') = 0,
  'CREATE INDEX `idx_rh_docs_validite_alertes` ON `rh_documents` (`date_validite`, `actif`)',
  'SELECT 1'
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Seed des 3 types Société manquants (INSERT IGNORE = idempotent)
-- Réutilise la table rh_doc_types créée par rh_doc_types_load() au runtime.
-- Les ordres sont alignés : kbis=1, convention=2, carte_pro=3, garant=4,
-- rcp=5, assurance_soc=6, bareme=7, entete=8.
CREATE TABLE IF NOT EXISTS `rh_doc_types` (
    `id`           INT AUTO_INCREMENT PRIMARY KEY,
    `rubrique`     VARCHAR(50)  NOT NULL,
    `type_key`     VARCHAR(100) NOT NULL,
    `label`        VARCHAR(255) NOT NULL,
    `obligatoire`  TINYINT      NOT NULL DEFAULT 0,
    `confidentiel` TINYINT      NOT NULL DEFAULT 0,
    `dispo`        ENUM('public','manager','admin') NOT NULL DEFAULT 'public',
    `ordre`        INT          NOT NULL DEFAULT 0,
    `systeme`      TINYINT      NOT NULL DEFAULT 0,
    `actif`        TINYINT      NOT NULL DEFAULT 1,
    UNIQUE KEY `uk_rub_key` (`rubrique`, `type_key`),
    INDEX `idx_rubrique` (`rubrique`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Décale rcp / assurance_soc / entete pour laisser de la place aux 3 nouveaux
UPDATE `rh_doc_types` SET `ordre` = 5  WHERE `rubrique` = 'societe' AND `type_key` = 'rcp';
UPDATE `rh_doc_types` SET `ordre` = 6  WHERE `rubrique` = 'societe' AND `type_key` = 'assurance_soc';
UPDATE `rh_doc_types` SET `ordre` = 8  WHERE `rubrique` = 'societe' AND `type_key` = 'entete';

INSERT IGNORE INTO `rh_doc_types` (`rubrique`, `type_key`, `label`, `obligatoire`, `dispo`, `ordre`, `systeme`)
VALUES
  ('societe', 'carte_pro',         'Carte professionnelle (CPI)', 1, 'public', 3, 1),
  ('societe', 'garant_financier',  'Garantie financière',          1, 'public', 4, 1),
  ('societe', 'bareme_honoraires', 'Barème honoraires',            1, 'public', 7, 1);
SQL,
];
