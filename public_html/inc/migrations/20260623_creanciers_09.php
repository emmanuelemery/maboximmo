<?php
/**
 * Migration : CRÉANCIERS — Mouvements financiers unifiés (phase 1).
 *
 * Table `creancier_mouvement` au niveau DOSSIER : registre unique des flux d'argent
 * du dossier — versements au créancier, honoraires avocat, frais huissier/commissaire,
 * frais de procédure, autres. Remplace l'usage détourné de creancier_versement
 * (qui reste INTACTE, rattachée aux saisies). Chaque mouvement peut citer un document
 * GED source (décompte huissier / jugement) et un tiers bénéficiaire.
 *
 * AJOUT uniquement (idempotent).
 *
 * ── ROLLBACK (-- DOWN) ────────────────────────────────────────────────
 *   DROP TABLE IF EXISTS `creancier_mouvement`;
 */

return [
    'id'          => '20260623_creanciers_09',
    'title'       => 'CRÉANCIERS — mouvements financiers unifiés (creancier_mouvement)',
    'description' => "Crée creancier_mouvement (niveau dossier) : versement_creancier / honoraire_avocat / frais_huissier / frais_procedure / autre, montant, date, tiers bénéficiaire, doc GED source, mode, référence. AJOUT uniquement. creancier_versement (saisie) intacte.",
    'created_at'  => '2026-06-23',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `creancier_mouvement` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_dossier` INT UNSIGNED NOT NULL,
  `type` ENUM('versement_creancier','honoraire_avocat','frais_huissier','frais_procedure','autre') NOT NULL,
  `sens` ENUM('sortant','entrant') NOT NULL DEFAULT 'sortant' COMMENT 'sortant = décaissé par le débiteur/la régie',
  `montant` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `date_mouvement` DATE NULL,
  `id_tiers_beneficiaire` INT UNSIGNED NULL COMMENT 'tiers.id (avocat / huissier / créancier payé)',
  `mode` ENUM('virement','cheque','prelevement','especes','autre') NULL,
  `reference` VARCHAR(190) NULL COMMENT 'N° pièce / virement / facture',
  `ged_document_id` BIGINT UNSIGNED NULL COMMENT 'Document source (décompte huissier / jugement / facture)',
  `note` VARCHAR(255) NULL,
  `id_societe` INT UNSIGNED NULL,
  `id_agence` INT UNSIGNED NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cm_dossier` (`id_dossier`,`type`),
  KEY `idx_cm_date` (`date_mouvement`),
  KEY `idx_cm_benef` (`id_tiers_beneficiaire`),
  CONSTRAINT `fk_cm_dossier` FOREIGN KEY (`id_dossier`) REFERENCES `creancier_dossier` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cm_benef` FOREIGN KEY (`id_tiers_beneficiaire`) REFERENCES `tiers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
];
