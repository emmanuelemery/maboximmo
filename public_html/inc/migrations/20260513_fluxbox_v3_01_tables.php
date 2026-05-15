<?php
/**
 * Migration v3.01 — FluxBox tables fondations (validé EMERY 2026-05-13)
 *
 * Crée les 9 tables principales du module FluxBox :
 *   - fluxbox_documents        : flux brut entrant (anti-doublon SHA-256)
 *   - fluxbox_cartes           : dossiers d'action présentés au user
 *   - fluxbox_actions_ia       : préparations IA en attente
 *   - fluxbox_cache_fournisseurs : règles apprenantes par fournisseur
 *   - fluxbox_cache_ocr        : OCR mémorisé par hash
 *   - fluxbox_cache_patterns   : patterns email → workflow appris
 *   - fluxbox_regles_apprises  : règles user (désactivables)
 *   - fluxbox_ia_usage         : compteur consommation IA (plafond 75€/société)
 *   - fluxbox_auto_pilot_log   : historique auto-validations
 *
 * + colonne `fluxbox_source_id` sur ged_documents (lien vers fluxbox_documents
 *   quand un doc est promu en GED après validation carte).
 *
 * Cohérent avec [[project_fluxbox_module]] §3.
 * Multi-tenant strict : tenant_id NOT NULL + UK/INDEX systématiques.
 * Idempotent : CREATE TABLE IF NOT EXISTS + ADD COLUMN IF NOT EXISTS.
 */

return [
    'id'          => '20260513_fluxbox_v3_01_tables',
    'title'       => 'FluxBox V3.01 — tables fondations (9 tables fluxbox_* + lien GED)',
    'description' => "Crée les 9 tables principales du module FluxBox : fluxbox_documents (anti-doublon SHA-256 multi-tenant), fluxbox_cartes (pile cartes user), fluxbox_actions_ia, 3 tables cache (fournisseurs/ocr/patterns), fluxbox_regles_apprises, fluxbox_ia_usage (plafond 75€/société), fluxbox_auto_pilot_log. Ajoute la colonne fluxbox_source_id sur ged_documents. Toutes les tables ont tenant_id NOT NULL + index. Idempotent.",
    'created_at'  => '2026-05-13',
    'sql' => <<<'SQL'
-- ════════════════════════════════════════════════════════════════════════
-- 1. fluxbox_documents — flux brut entrant + anti-doublon SHA-256
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `fluxbox_documents` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`      INT UNSIGNED NOT NULL COMMENT 'Isolation RGPD multi-tenant',
  `hash_sha256`    CHAR(64) NOT NULL COMMENT 'N1 anti-doublon : hash binaire',
  `hash_contenu`   CHAR(64) NULL COMMENT 'N2 anti-doublon : hash texte OCR normalisé',
  `embedding_ref`  VARCHAR(64) NULL COMMENT 'N3 référence externe (vector DB) si calculé',
  `source_type`    ENUM('manual','email','watcher','zip','webhook','photo','api','other') NOT NULL DEFAULT 'manual',
  `source_meta`    JSON NULL COMMENT 'Provenance détaillée (email_id, watcher path, etc.)',
  `fichier_nom`    VARCHAR(255) NOT NULL,
  `fichier_chemin` VARCHAR(500) NOT NULL,
  `taille_octets`  BIGINT UNSIGNED NULL,
  `mime_type`      VARCHAR(100) NULL,
  `ocr_status`     ENUM('pending','done','failed','skipped','cached') NOT NULL DEFAULT 'pending',
  `ocr_text`       MEDIUMTEXT NULL,
  `first_seen_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `seen_count`     INT UNSIGNED NOT NULL DEFAULT 1,
  `created_by`     INT UNSIGNED NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fluxbox_documents_tenant_hash` (`tenant_id`, `hash_sha256`),
  INDEX `idx_fluxbox_documents_tenant_contenu` (`tenant_id`, `hash_contenu`),
  INDEX `idx_fluxbox_documents_source_type` (`source_type`),
  INDEX `idx_fluxbox_documents_ocr_status` (`ocr_status`),
  INDEX `idx_fluxbox_documents_seen` (`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════
-- 2. fluxbox_cartes — pile de cartes à valider
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `fluxbox_cartes` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`        INT UNSIGNED NOT NULL,
  `document_id`      BIGINT UNSIGNED NULL COMMENT 'FK fluxbox_documents (peut être NULL pour cartes pures email/tâche)',
  `titre`            VARCHAR(255) NOT NULL COMMENT 'Titre humain affiché : "Facture ascenseur Saint-Pol"',
  `sous_titre`       VARCHAR(255) NULL COMMENT 'Détail contextuel : "Otis SA · 1 240 €"',
  `priorite`         ENUM('urgent','important','normal','faible') NOT NULL DEFAULT 'normal',
  `priorite_reason`  VARCHAR(255) NULL COMMENT 'Justification IA visible : "montant > 1000€"',
  `statut`           ENUM('pending','in_progress','validated','dismissed','later','error') NOT NULL DEFAULT 'pending',
  `confiance_ia`     DECIMAL(5,2) NULL COMMENT 'Score global 0-100',
  `proposition_json` JSON NULL COMMENT 'Proposition IA complète (classement, envois, tâches, workflow)',
  `assigned_to`      INT UNSIGNED NULL COMMENT 'User en cours de traitement (verrouillage)',
  `assigned_at`      DATETIME NULL,
  `validated_by`     INT UNSIGNED NULL,
  `validated_at`     DATETIME NULL,
  `dismissed_by`     INT UNSIGNED NULL,
  `dismissed_at`     DATETIME NULL,
  `dismissed_reason` VARCHAR(255) NULL,
  `later_until`      DATETIME NULL COMMENT 'Si statut=later : ne pas réafficher avant cette date',
  `created_by`       INT UNSIGNED NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_fluxbox_cartes_tenant_statut` (`tenant_id`, `statut`),
  INDEX `idx_fluxbox_cartes_tenant_priorite` (`tenant_id`, `priorite`, `statut`),
  INDEX `idx_fluxbox_cartes_document` (`document_id`),
  INDEX `idx_fluxbox_cartes_assigned` (`assigned_to`, `statut`),
  INDEX `idx_fluxbox_cartes_later` (`statut`, `later_until`),
  CONSTRAINT `fk_fluxbox_cartes_doc` FOREIGN KEY (`document_id`)
    REFERENCES `fluxbox_documents` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════
-- 3. fluxbox_actions_ia — préparations IA détaillées
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `fluxbox_actions_ia` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`    INT UNSIGNED NOT NULL,
  `carte_id`     BIGINT UNSIGNED NOT NULL,
  `action_type`  ENUM('classement_ged','envoi_email','creation_tache','workflow','marquer_paye','autre') NOT NULL,
  `action_label` VARCHAR(255) NOT NULL COMMENT 'Texte affiché user : "Classer en Syndic/Travaux/Ascenseur"',
  `payload_json` JSON NOT NULL COMMENT 'Détail technique de l action à exécuter',
  `confiance`    DECIMAL(5,2) NULL,
  `statut`       ENUM('proposed','accepted','rejected','executed','failed') NOT NULL DEFAULT 'proposed',
  `executed_at`  DATETIME NULL,
  `result_json`  JSON NULL COMMENT 'Résultat d exécution (id GED créé, mail envoyé, etc.)',
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_fluxbox_actions_ia_tenant_carte` (`tenant_id`, `carte_id`),
  INDEX `idx_fluxbox_actions_ia_statut` (`statut`),
  CONSTRAINT `fk_fluxbox_actions_ia_carte` FOREIGN KEY (`carte_id`)
    REFERENCES `fluxbox_cartes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════
-- 4. fluxbox_cache_fournisseurs — règles apprises par fournisseur
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `fluxbox_cache_fournisseurs` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`         INT UNSIGNED NOT NULL,
  `fournisseur_key`   VARCHAR(255) NOT NULL COMMENT 'Slug normalisé du fournisseur',
  `fournisseur_label` VARCHAR(255) NOT NULL,
  `n1_proposed`       VARCHAR(80) NULL,
  `n2_proposed`       VARCHAR(80) NULL,
  `n3_proposed`       VARCHAR(80) NULL,
  `workflow_default`  VARCHAR(80) NULL,
  `usage_count`       INT UNSIGNED NOT NULL DEFAULT 1,
  `last_used_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fluxbox_cache_fournisseurs` (`tenant_id`, `fournisseur_key`),
  INDEX `idx_fluxbox_cache_fournisseurs_label` (`fournisseur_label`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════
-- 5. fluxbox_cache_ocr — OCR mémorisé par hash (ne jamais refaire)
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `fluxbox_cache_ocr` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`   INT UNSIGNED NOT NULL,
  `hash_sha256` CHAR(64) NOT NULL,
  `ocr_text`    MEDIUMTEXT NOT NULL,
  `ocr_engine`  VARCHAR(40) NOT NULL DEFAULT 'tesseract',
  `pages`       INT UNSIGNED NULL,
  `confidence`  DECIMAL(5,2) NULL,
  `cost_eur`    DECIMAL(8,4) NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fluxbox_cache_ocr` (`tenant_id`, `hash_sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════
-- 6. fluxbox_cache_patterns — patterns email → workflow (apprentissage)
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `fluxbox_cache_patterns` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`      INT UNSIGNED NOT NULL,
  `pattern_type`   ENUM('email_from','email_subject','filename_regex','mime') NOT NULL,
  `pattern_value`  VARCHAR(500) NOT NULL,
  `workflow_code`  VARCHAR(80) NOT NULL,
  `usage_count`    INT UNSIGNED NOT NULL DEFAULT 1,
  `last_used_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fluxbox_cache_patterns` (`tenant_id`, `pattern_type`, `pattern_value`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════
-- 7. fluxbox_regles_apprises — règles user désactivables
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `fluxbox_regles_apprises` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`    INT UNSIGNED NOT NULL,
  `regle_type`   ENUM('fournisseur_categorie','expediteur_workflow','sujet_priorite','immeuble_collaborateur','autre') NOT NULL,
  `regle_label`  VARCHAR(255) NOT NULL COMMENT 'Affichage user : "Factures Otis SA → Travaux/Ascenseur"',
  `match_json`   JSON NOT NULL COMMENT 'Critères de match',
  `action_json`  JSON NOT NULL COMMENT 'Action à appliquer',
  `is_active`    TINYINT(1) NOT NULL DEFAULT 1,
  `created_by`   INT UNSIGNED NULL,
  `usage_count`  INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_fluxbox_regles_tenant_active` (`tenant_id`, `is_active`),
  INDEX `idx_fluxbox_regles_type` (`regle_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════
-- 8. fluxbox_ia_usage — compteur consommation IA (plafond 75€/société)
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `fluxbox_ia_usage` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`    INT UNSIGNED NOT NULL,
  `societe_id`   INT UNSIGNED NULL COMMENT 'Plafonnement par société',
  `user_id`      INT UNSIGNED NULL,
  `carte_id`     BIGINT UNSIGNED NULL,
  `ia_provider`  ENUM('anthropic','openai','local','other') NOT NULL DEFAULT 'anthropic',
  `ia_model`     VARCHAR(80) NOT NULL COMMENT 'haiku-4-5 / sonnet-4-6 / tesseract / etc.',
  `purpose`      VARCHAR(80) NOT NULL COMMENT 'classement | extraction | redaction | ocr | search',
  `tokens_in`    INT UNSIGNED NOT NULL DEFAULT 0,
  `tokens_out`   INT UNSIGNED NOT NULL DEFAULT 0,
  `cost_eur`     DECIMAL(8,4) NOT NULL DEFAULT 0,
  `duration_ms`  INT UNSIGNED NULL,
  `cache_hit`    TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = pas d appel IA réel (cache)',
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_fluxbox_ia_usage_tenant_date` (`tenant_id`, `created_at`),
  INDEX `idx_fluxbox_ia_usage_societe_date` (`societe_id`, `created_at`),
  INDEX `idx_fluxbox_ia_usage_user` (`user_id`),
  INDEX `idx_fluxbox_ia_usage_model` (`ia_model`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════
-- 9. fluxbox_auto_pilot_log — historique auto-validations
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `fluxbox_auto_pilot_log` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`     INT UNSIGNED NOT NULL,
  `carte_id`      BIGINT UNSIGNED NOT NULL,
  `mode`          ENUM('rule','global_high_confidence') NOT NULL,
  `confiance`     DECIMAL(5,2) NULL,
  `rule_id`       BIGINT UNSIGNED NULL COMMENT 'FK fluxbox_regles_apprises si mode=rule',
  `actions_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `cancelled_by`  INT UNSIGNED NULL,
  `cancelled_at`  DATETIME NULL COMMENT 'Annulation 24h disponible',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_fluxbox_auto_pilot_tenant` (`tenant_id`, `created_at`),
  INDEX `idx_fluxbox_auto_pilot_carte` (`carte_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════
-- 10. Lien GED ← FluxBox : ged_documents.fluxbox_source_id
-- ════════════════════════════════════════════════════════════════════════
ALTER TABLE `ged_documents`
  ADD COLUMN IF NOT EXISTS `fluxbox_source_id` BIGINT UNSIGNED NULL
    COMMENT 'FK fluxbox_documents : quand promu de FluxBox à GED après validation carte';

ALTER TABLE `ged_documents`
  ADD INDEX IF NOT EXISTS `idx_ged_documents_fluxbox_source` (`fluxbox_source_id`);

-- ════════════════════════════════════════════════════════════════════════
-- 11. Table de paramétrage plafond IA par société
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `fluxbox_societe_plafonds` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`       INT UNSIGNED NOT NULL,
  `societe_id`      INT UNSIGNED NOT NULL,
  `plafond_eur`     DECIMAL(8,2) NOT NULL DEFAULT 75.00,
  `alert_50_sent`   DATETIME NULL,
  `alert_80_sent`   DATETIME NULL,
  `alert_95_sent`   DATETIME NULL,
  `alert_100_sent`  DATETIME NULL,
  `mois_courant`    CHAR(7) NOT NULL COMMENT 'YYYY-MM',
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fluxbox_plafonds_mois` (`tenant_id`, `societe_id`, `mois_courant`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];
