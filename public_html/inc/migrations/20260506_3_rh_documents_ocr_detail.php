<?php
/**
 * Migration : enrichissement extraction OCR docs officiels
 *
 * Demande Emmanuel 2026-05-06 : pour les attestations RC pro, garanties
 * financières, KBIS, cartes pro, barèmes — capturer tous les détails utiles
 * (société titulaire, n° client, compagnie + adresse, montants détaillés,
 * dates effet/échéance/anniversaire, etc.).
 *
 * Stratégie : colonnes structurées pour les champs métier indexables +
 * metadata_json pour le reste (pas de prolifération de colonnes).
 *
 * Idempotente : ADD COLUMN IF NOT EXISTS partout.
 */

return [
    'id'          => '20260506_3_rh_documents_ocr_detail',
    'title'       => 'rh_documents : colonnes OCR détaillées (titulaire, compagnie, adresses, montants, dates)',
    'description' => 'Enrichit la table rh_documents avec les champs structurés capturés par OCR Sonnet sur les docs officiels (carte pro, KBIS, RC pro, garant, MRI, barème). Inclut adresse emetteur, n° client, raison sociale, SIRET/SIREN/TVA/capital/APE, dates effet/échéance/anniversaire, montant franchise/plafond, nature garantie, dirigeants_json + metadata_json fourre-tout.',
    'created_at'  => '2026-05-06',
    'sql' => <<<'SQL'
ALTER TABLE `rh_documents`
  ADD COLUMN IF NOT EXISTS `numero_client`     VARCHAR(150) NULL COMMENT 'OCR : n° de client chez l''émetteur (assureur, garant)',
  ADD COLUMN IF NOT EXISTS `adresse_emetteur`  TEXT NULL          COMMENT 'OCR : adresse de l''émetteur (assureur / garant / CCI / greffe)',
  ADD COLUMN IF NOT EXISTS `raison_sociale`    VARCHAR(255) NULL  COMMENT 'OCR : société titulaire (Régie Emery SARL, etc.)',
  ADD COLUMN IF NOT EXISTS `forme_juridique`   VARCHAR(50) NULL   COMMENT 'OCR : SARL, SAS, SCI, EURL...',
  ADD COLUMN IF NOT EXISTS `siret`             VARCHAR(20) NULL,
  ADD COLUMN IF NOT EXISTS `siren`             VARCHAR(15) NULL,
  ADD COLUMN IF NOT EXISTS `tva_intra`         VARCHAR(20) NULL   COMMENT 'OCR : n° TVA intracommunautaire',
  ADD COLUMN IF NOT EXISTS `capital_social`    DECIMAL(15,2) NULL COMMENT 'OCR : capital social (KBIS)',
  ADD COLUMN IF NOT EXISTS `code_ape`          VARCHAR(10) NULL,
  ADD COLUMN IF NOT EXISTS `date_effet`        DATE NULL          COMMENT 'OCR : date d''effet du contrat (souvent ≠ date_emission)',
  ADD COLUMN IF NOT EXISTS `date_echeance`     DATE NULL          COMMENT 'OCR : date d''échéance contractuelle (souvent = date_validite)',
  ADD COLUMN IF NOT EXISTS `date_anniversaire` DATE NULL          COMMENT 'OCR : date d''anniversaire / renouvellement annuel',
  ADD COLUMN IF NOT EXISTS `montant_franchise` DECIMAL(12,2) NULL,
  ADD COLUMN IF NOT EXISTS `montant_plafond_2` DECIMAL(15,2) NULL COMMENT 'OCR : 2e plafond (ex: dommages corporels distincts)',
  ADD COLUMN IF NOT EXISTS `nature_garantie`   VARCHAR(500) NULL  COMMENT 'OCR : description libre du périmètre couvert',
  ADD COLUMN IF NOT EXISTS `dirigeants_json`   JSON NULL          COMMENT 'OCR : liste dirigeants/gérants (KBIS) — [{nom,prenom,fonction}]',
  ADD COLUMN IF NOT EXISTS `metadata_json`     JSON NULL          COMMENT 'OCR : tous les champs additionnels capturés mais non structurés';

-- Index utiles pour les requêtes ultérieures (cron alertes anniv, etc.)
SET @stmt := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rh_documents'
      AND INDEX_NAME = 'idx_rh_docs_anniversaire') = 0,
  'CREATE INDEX `idx_rh_docs_anniversaire` ON `rh_documents` (`date_anniversaire`)',
  'SELECT 1'
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @stmt := IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rh_documents'
      AND INDEX_NAME = 'idx_rh_docs_siret') = 0,
  'CREATE INDEX `idx_rh_docs_siret` ON `rh_documents` (`siret`)',
  'SELECT 1'
);
PREPARE stmt FROM @stmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
SQL,
];
