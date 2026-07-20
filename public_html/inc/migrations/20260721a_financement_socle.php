<?php
/**
 * Migration : FINANCEMENT — socle Tranche 1 (dossier financier collaboratif).
 *
 * 3 tables (le minimum) — cf. docs/financement_audit_architecture.md § DÉCISION VALIDÉE :
 *   - fin_dossier        : tête du dossier financier (type, sujet propriétaire/société, pilote, statut, confidentialité).
 *   - fin_dossier_lien   : liens POLYMORPHES (CREANCIER_DOSSIER, BIEN, IMMEUBLE, SOCIETE, TIERS, DOSSIER_VENTE…).
 *                          On RÉFÉRENCE, on ne duplique jamais (créancier, saisies, versements restent chez eux).
 *   - fin_dossier_acces  : ACL par dossier + rôle d'intervenant (prépare avocat/comptable/notaire/propriétaire).
 *
 * Historique = AuditLog existant (table audit_log), pas de table dédiée en T1.
 * Documents = ged_document_links (entity_type='FINANCEMENT'), pas de table doc.
 * Multi-tenant : id_societe (+ id_agence). Idempotente (CREATE TABLE IF NOT EXISTS).
 */
return [
    'id'          => '20260721a_financement_socle',
    'title'       => 'Financement : socle T1 (fin_dossier / fin_dossier_lien / fin_dossier_acces)',
    'description' => "Dossier financier collaboratif — tête, liens polymorphes, ACL par dossier.",
    'created_at'  => '2026-07-21',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `fin_dossier` (
  `id`                  INT AUTO_INCREMENT PRIMARY KEY,
  `id_societe`          INT NOT NULL,
  `id_agence`           INT NULL,
  `type`                ENUM('financement_bancaire','dette_creancier','procedure_financiere') NOT NULL DEFAULT 'financement_bancaire',
  `libelle`             VARCHAR(200) NOT NULL,
  `id_tiers`            INT NULL,               -- propriétaire concerné (tiers)
  `id_societe_concernee` INT NULL,             -- OU société concernée (societes.id)
  `pilote_user_id`      INT NULL,
  `statut`              ENUM('ouvert','en_cours','suspendu','clos') NOT NULL DEFAULT 'ouvert',
  `confidentialite`     ENUM('normal','confidentiel','restreint') NOT NULL DEFAULT 'normal',
  `synthese`            TEXT NULL,
  `created_by`          INT NULL,
  `created_at`          DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NULL,
  INDEX `idx_soc` (`id_societe`),
  INDEX `idx_tiers` (`id_tiers`),
  INDEX `idx_type` (`type`),
  INDEX `idx_statut` (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fin_dossier_lien` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `id_societe`  INT NOT NULL,
  `id_dossier`  INT NOT NULL,
  `entity_type` VARCHAR(30) NOT NULL,   -- CREANCIER_DOSSIER | CREANCIER_SAISIE | BIEN | IMMEUBLE | SOCIETE | TIERS | DOSSIER_VENTE
  `entity_id`   INT NOT NULL,
  `role_lien`   VARCHAR(40) NULL,        -- ex. bien_concerne | creancier_lie | garantie
  `note`        VARCHAR(255) NULL,
  `created_by`  INT NULL,
  `created_at`  DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_lien` (`id_dossier`,`entity_type`,`entity_id`),
  INDEX `idx_dossier` (`id_dossier`),
  INDEX `idx_entity` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fin_dossier_acces` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `id_societe`       INT NOT NULL,
  `id_dossier`       INT NOT NULL,
  `identite_type`    ENUM('user','tiers','jeton') NOT NULL DEFAULT 'user',
  `identite_id`      INT NOT NULL,
  `role_intervenant` ENUM('proprietaire','avocat','comptable','notaire','banque','commissaire','regie','pilote') NOT NULL DEFAULT 'regie',
  `niveau`           ENUM('lecture','contribution','validation','pilote') NOT NULL DEFAULT 'lecture',
  `perimetre`        VARCHAR(40) NOT NULL DEFAULT 'tout',   -- tout | juridique | montants | garanties
  `actif`            TINYINT(1) NOT NULL DEFAULT 1,
  `created_by`       INT NULL,
  `created_at`       DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NULL,
  UNIQUE KEY `uniq_acces` (`id_dossier`,`identite_type`,`identite_id`),
  INDEX `idx_dossier` (`id_dossier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
];
