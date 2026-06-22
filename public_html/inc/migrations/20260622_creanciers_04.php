<?php
/**
 * Migration : Module CRÉANCIERS — agenda des dossiers (échéances datées).
 *
 * creancier_echeance : toute date utile d'un dossier (audience, butoir, signification,
 * expertise, délai d'appel, RDV…), alimentée automatiquement par l'extraction IA des
 * scans (source='scan') ou saisie manuelle (source='manuel'). Alimente l'agenda global
 * (dashboard) et l'agenda du dossier (dossier360).
 *
 * AJOUT uniquement (idempotent).
 *
 * ── ROLLBACK (-- DOWN) ──  DROP TABLE IF EXISTS `creancier_echeance`;
 */

return [
    'id'          => '20260622_creanciers_04',
    'title'       => 'CRÉANCIERS — agenda (creancier_echeance)',
    'description' => "Table creancier_echeance : dates de dossier (audience/butoir/signification/expertise/délai/RDV) extraites des scans ou saisies. Alimente l'agenda global et par dossier. AJOUT uniquement.",
    'created_at'  => '2026-06-22',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `creancier_echeance` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_dossier` INT UNSIGNED NOT NULL,
  `type` VARCHAR(40) NOT NULL DEFAULT 'echeance' COMMENT 'audience|butoir|signification|expertise|delai_appel|rdv|echeance|autre',
  `libelle` VARCHAR(190) NOT NULL,
  `date_echeance` DATE NOT NULL,
  `statut` ENUM('a_venir','fait','annule') NOT NULL DEFAULT 'a_venir',
  `source` ENUM('scan','manuel','ia') NOT NULL DEFAULT 'manuel',
  `ged_document_id` BIGINT UNSIGNED NULL COMMENT 'Doc GED d''origine si extrait d''un scan',
  `id_tiers_lie` INT UNSIGNED NULL COMMENT 'Créancier / pro concerné',
  `note` VARCHAR(255) NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ce_dossier` (`id_dossier`,`date_echeance`),
  KEY `idx_ce_date` (`date_echeance`,`statut`),
  CONSTRAINT `fk_ce_dossier` FOREIGN KEY (`id_dossier`) REFERENCES `creancier_dossier` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ce_tiers` FOREIGN KEY (`id_tiers_lie`) REFERENCES `tiers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
];
