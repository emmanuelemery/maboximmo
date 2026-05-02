<?php
/**
 * Migration v2 — Tables ged_synonyms + ged_classification_feedback + ged_naming_rules
 *
 * Brief V2 :
 *   - ged_synonyms : keyword → code GED normalisé (améliore IA / OCR / import)
 *   - ged_classification_feedback : corrections user pour apprentissage continu
 *   - ged_naming_rules : configuration des templates de nommage par module
 *
 * RÉUTILISATION (pas de doublon créé) :
 *   - ged_modules → on a déjà ged_level_codes (catalogue cascade) qui contient
 *     les modules. On ne crée PAS une table ged_modules redondante. Si besoin
 *     d'un référentiel "modules" plus simple, faire une VIEW qui filtre
 *     ged_level_codes WHERE level_number = 1.
 *
 * AJOUT uniquement, idempotent.
 */

return [
    'id'          => '20260502_ged_v2_19_synonyms_feedback_rules',
    'title'       => 'Ma GED Box V2 — ged_synonyms + ged_classification_feedback + ged_naming_rules',
    'description' => "Crée 3 tables : ged_synonyms (keyword → normalized_code par module, weight pour IA), ged_classification_feedback (corrections user → apprentissage IA), ged_naming_rules (templates de nommage par module : segments order, séparateur, normalisation par champ). Pas de doublon : ged_modules N'EST PAS créée car ged_level_codes WHERE level_number=1 fait déjà le job (vue à créer si besoin). Idempotent.",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
-- ────────── ged_synonyms : keyword → code normalisé ──────────
CREATE TABLE IF NOT EXISTS `ged_synonyms` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NULL,
  `keyword` VARCHAR(120) NOT NULL COMMENT 'Mot ou expression vue dans nom fichier/OCR (ex: pv, assemblee, dde)',
  `normalized_code` VARCHAR(80) NOT NULL COMMENT 'Code GED cible (ex: PV, AG, SINISTRE_DDE)',
  `target_level` TINYINT UNSIGNED NULL COMMENT 'Niveau visé (1-5) si connu',
  `module` VARCHAR(50) NULL COMMENT 'Module concerne (SYNDIC, GESTION_LOCATIVE...) ou NULL = global',
  `weight` SMALLINT NOT NULL DEFAULT 100 COMMENT 'Poids 0-1000 pour scoring IA (defaut 100)',
  `auto_validated` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = synonyme valide humainement (poids x2)',
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ged_synonyms_keyword_code` (`tenant_id`, `keyword`, `normalized_code`, `module`),
  INDEX `idx_ged_synonyms_lookup` (`keyword`, `module`, `weight`),
  INDEX `idx_ged_synonyms_normalized` (`normalized_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────── Seed initial : synonymes les plus utiles (extensible via UI) ──────────
INSERT IGNORE INTO `ged_synonyms` (`tenant_id`, `keyword`, `normalized_code`, `target_level`, `module`, `weight`, `auto_validated`) VALUES
  -- Syndic
  (NULL, 'pv',                  'PV',                       5, '04_SYNDIC',           300, 1),
  (NULL, 'proces verbal',       'PV',                       5, '04_SYNDIC',           300, 1),
  (NULL, 'assemblee',           'AG',                       4, '04_SYNDIC',           300, 1),
  (NULL, 'assemblee generale',  'AG',                       4, '04_SYNDIC',           500, 1),
  (NULL, 'ag',                  'AG',                       4, '04_SYNDIC',           300, 1),
  (NULL, 'convocation',         'CONVOCATION',              5, '04_SYNDIC',           300, 1),
  (NULL, 'budget previsionnel', 'BUDGET_PREVISIONNEL',      5, '04_SYNDIC',           400, 1),
  (NULL, 'appel de fonds',      'APPELS_FONDS',             5, '04_SYNDIC',           400, 1),
  (NULL, 'releve bancaire',     'RELEVES_BANCAIRES',        5, '04_SYNDIC',           400, 1),
  (NULL, 'devis',               'DEVIS',                    5, '04_SYNDIC',           250, 1),
  (NULL, 'facture',             'FACTURES',                 5, '04_SYNDIC',           250, 1),
  (NULL, 'sinistre',            'SINISTRES',                4, '04_SYNDIC',           300, 1),
  (NULL, 'dde',                 'DDE',                      5, '04_SYNDIC',           400, 1),
  (NULL, 'declaration sinistre','DDE',                      5, '04_SYNDIC',           500, 1),
  (NULL, 'dta',                 'DTA',                      5, '04_SYNDIC',           400, 1),
  (NULL, 'amiante',             'AMIANTE',                  5, '04_SYNDIC',           400, 1),
  (NULL, 'plomb',               'PLOMB',                    5, '04_SYNDIC',           400, 1),
  -- Gestion locative
  (NULL, 'bail',                'BAIL',                     4, '03_GESTION_LOCATIVE', 400, 1),
  (NULL, 'bail signe',          'BAIL',                     4, '03_GESTION_LOCATIVE', 500, 1),
  (NULL, 'edl',                 'EDL_ENTREE',               3, '03_GESTION_LOCATIVE', 300, 1),
  (NULL, 'etat des lieux',      'EDL_ENTREE',               3, '03_GESTION_LOCATIVE', 400, 1),
  (NULL, 'caf',                 'CAF',                      4, '03_GESTION_LOCATIVE', 300, 1),
  (NULL, 'apl',                 'CAF_APL',                  4, '03_GESTION_LOCATIVE', 300, 1),
  (NULL, 'mandat',              'MANDATS_GESTION',          3, '03_GESTION_LOCATIVE', 300, 1),
  (NULL, 'mandat gestion',      'MANDATS_GESTION',          3, '03_GESTION_LOCATIVE', 500, 1),
  (NULL, 'mandat location',     'MANDATS_LOCATION',         3, '03_GESTION_LOCATIVE', 500, 1),
  (NULL, 'taxe fonciere',       'TAXES_FONCIERES',          3, '03_GESTION_LOCATIVE', 500, 1),
  (NULL, 'dpe',                 'DPE',                      3, '03_GESTION_LOCATIVE', 500, 1),
  (NULL, 'erp',                 'ERP_ERNMT',                3, '03_GESTION_LOCATIVE', 400, 1),
  (NULL, 'rib',                 'RIB',                      4, NULL,                  300, 1),
  -- Transaction
  (NULL, 'mandat vente',        'MANDATS_VENTE',            4, '05_TRANSACTION',      500, 1),
  (NULL, 'compromis',           'COMPROMIS',                4, '05_TRANSACTION',      500, 1),
  (NULL, 'acte authentique',    'ACTES',                    4, '05_TRANSACTION',      500, 1),
  (NULL, 'estimation',          'ESTIMATIONS',              2, '05_TRANSACTION',      300, 1),
  -- RH
  (NULL, 'bulletin de paie',    'BULLETINS',                4, '02_RH',               500, 1),
  (NULL, 'bulletin paie',       'BULLETINS',                4, '02_RH',               400, 1),
  (NULL, 'fiche de paie',       'BULLETINS',                4, '02_RH',               400, 1),
  (NULL, 'contrat travail',     '02_CONTRAT_TRAVAIL',       4, '02_RH',               500, 1),
  (NULL, 'cdi',                 '02_CONTRAT_TRAVAIL',       4, '02_RH',               300, 1),
  (NULL, 'cdd',                 '02_CONTRAT_TRAVAIL',       4, '02_RH',               300, 1),
  (NULL, 'attestation employeur','11_SORTIE',               4, '02_RH',               400, 1),
  -- Direction
  (NULL, 'kbis',                'KBIS',                     4, '01_DIRECTION',        500, 1),
  (NULL, 'statuts',             'STATUTS',                  4, '01_DIRECTION',        400, 1),
  (NULL, 'expert comptable',    'EXPERT_COMPTABLE',         4, '01_DIRECTION',        400, 1);

-- ────────── ged_classification_feedback : apprentissage des corrections ──────────
CREATE TABLE IF NOT EXISTS `ged_classification_feedback` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NULL,
  `import_item_id` BIGINT UNSIGNED NULL COMMENT 'ged_import_items.id si applicable',
  `document_id` BIGINT UNSIGNED NULL COMMENT 'ged_documents.id si applicable',
  `old_filename` VARCHAR(255) NULL,
  `field` VARCHAR(40) NOT NULL COMMENT 'n1 | n2 | n3 | n4 | n5 | n6 | entity | date | title',
  `suggestion_value` VARCHAR(255) NULL COMMENT 'Ce que l IA avait propose',
  `correction_value` VARCHAR(255) NULL COMMENT 'Ce que l humain a corrige',
  `module` VARCHAR(50) NULL,
  `user_id` INT UNSIGNED NULL,
  `weight_applied` SMALLINT NOT NULL DEFAULT 100 COMMENT 'Pour reprendre le poids dans les futures suggestions',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_ged_feedback_field_value` (`field`, `correction_value`),
  INDEX `idx_ged_feedback_module` (`module`),
  INDEX `idx_ged_feedback_filename` (`old_filename`),
  INDEX `idx_ged_feedback_user_date` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────── ged_naming_rules : templates de nommage par module ──────────
CREATE TABLE IF NOT EXISTS `ged_naming_rules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NULL,
  `module` VARCHAR(50) NOT NULL COMMENT 'N1_CODE (04_SYNDIC, 03_GESTION_LOCATIVE...) ou DEFAULT',
  `template` VARCHAR(255) NOT NULL DEFAULT '{N1}_{SOC}_{AGE}_[{REF}_{ENTITE}_]{N2}[_{N3}[_{N4}[_{N5}]]]_[{N6}_]{TITLE}_{YYMMDD}.{ext}'
    COMMENT 'Template avec placeholders. Optionnels entre [ ]',
  `separator` VARCHAR(5) NOT NULL DEFAULT '_',
  `max_length` SMALLINT NOT NULL DEFAULT 120,
  `entity_max_length` TINYINT NOT NULL DEFAULT 15 COMMENT 'NOM_ENTITE max chars',
  `title_max_length` TINYINT NOT NULL DEFAULT 30 COMMENT 'TITLE max chars',
  `n6_max_length` TINYINT NOT NULL DEFAULT 25,
  `priority_drop_order` VARCHAR(255) NOT NULL DEFAULT 'TITLE,NOM_ENTITE,N6'
    COMMENT 'Ordre de troncature en cas de depassement',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ged_naming_rules_module` (`tenant_id`, `module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed : règle DEFAULT (utilisée pour tous les modules sans règle spécifique)
INSERT IGNORE INTO `ged_naming_rules` (`tenant_id`, `module`, `template`, `separator`, `max_length`, `entity_max_length`, `title_max_length`, `n6_max_length`, `priority_drop_order`)
VALUES
  (NULL, 'DEFAULT', '{N1}_{SOC}_{AGE}_[{REF}_{ENTITE}_]{N2}[_{N3}[_{N4}[_{N5}]]]_[{N6}_]{TITLE}_{YYMMDD}.{ext}', '_', 120, 15, 30, 25, 'TITLE,NOM_ENTITE,N6');
SQL,
];
