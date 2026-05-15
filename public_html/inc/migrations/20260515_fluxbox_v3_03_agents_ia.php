<?php
/**
 * Migration v3.03 — FluxBox : agents IA configurables (templates + clones)
 *
 * Validé EMERY 2026-05-15.
 *
 * Architecture hiérarchique 4 niveaux :
 *   Agent → Section → Sous-section → Actions conditionnelles
 *
 * Ex : Agent COMPTABLE
 *        └─ Section BANQUE
 *             └─ Sous-section RELEVE_MENSUEL_SYNDIC
 *                  ├─ Champs : banque, iban, compte4, période, solde_début, solde_fin
 *                  └─ Actions :
 *                       • TOUJOURS : classement_ged → 06_COMPTA/BANQUES/RELEVE
 *                       • TOUJOURS : extraction_typee → extraction_releve_bancaire
 *                       • SI solde_fin<0 : envoi_mail → gestionnaire
 *
 * Stratégie templates (décision C validée 2026-05-15) :
 *   - is_template=1 : seedé en dur, non modifiable (cloné uniquement)
 *   - is_template=0 : copies du tenant, éditables
 *
 * Provider IA (3 options) :
 *   - anthropic (Claude Sonnet/Haiku)
 *   - openai (GPT-4o)
 *   - mindee (OCR templates, économique pour docs standardisés)
 *   - cascade (mix : Mindee → fallback Anthropic si confiance < seuil)
 *
 * Langage de condition (décision A validée 2026-05-15) :
 *   JSON simple {"field":"X","op":"<","value":Y} ou {"always":true}
 *   Opérateurs supportés : =, !=, <, >, <=, >=, in, contains, regex
 *
 * Actions V1 (décision 3 actions validée 2026-05-15) :
 *   - classement_ged
 *   - extraction_typee
 *   - envoi_mail
 *
 * V2 (semaine suivante) : creation_doc_groupe, creation_tache, archivage_inviolable
 * V3 (back compta) : workflow_compta, notification
 *
 * Idempotent (CREATE TABLE IF NOT EXISTS).
 */

return [
    'id'          => '20260515_fluxbox_v3_03_agents_ia',
    'title'       => 'FluxBox V3.03 — agents IA configurables (4 tables : agents/sections/subsections/actions) + 10 templates seed',
    'description' => "Crée le système d'agents IA configurables. 4 tables hiérarchiques (fluxbox_agents_ia, fluxbox_agents_sections, fluxbox_agents_subsections, fluxbox_agents_actions). Support multi-provider (anthropic/openai/mindee/cascade). Templates seedés avec is_template=1 (clone-only), copies tenant éditables. Conditions JSON simples. 3 actions V1 (classement_ged, extraction_typee, envoi_mail). Seed initial : 10 templates (COMPTABLE, SYNDIC, GESTION, TRANSACTION, RH, JURIDIQUE, FOURNISSEURS, DIRECTION, CONFORMITE, BAILLEUR). Idempotent.",
    'created_at'  => '2026-05-15',
    'sql' => <<<'SQL'
-- ════════════════════════════════════════════════════════════════════════
-- 1. fluxbox_agents_ia — racine
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `fluxbox_agents_ia` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`        INT UNSIGNED NOT NULL,
  `code`             VARCHAR(40) NOT NULL COMMENT 'COMPTABLE | SYNDIC | RH | ...',
  `nom`              VARCHAR(80) NOT NULL,
  `description`      TEXT NULL,
  `provider`         ENUM('anthropic','openai','mindee','cascade') NOT NULL DEFAULT 'anthropic',
  `modele`           VARCHAR(120) NULL COMMENT 'claude-sonnet-4-6 / gpt-4o-mini / mindee:bank-statement-v2 / etc.',
  `prompt_systeme`   TEXT NULL COMMENT 'Personnalité + règles métier de l agent (NULL si provider=mindee)',
  `plafond_eur_mensuel` DECIMAL(8,2) NOT NULL DEFAULT 75.00,
  `is_template`      TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = template système (clone-only). 0 = copie tenant éditable.',
  `cloned_from`      BIGINT UNSIGNED NULL COMMENT 'FK vers l agent template d origine',
  `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
  `created_by`       INT UNSIGNED NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fluxbox_agents_tenant_code` (`tenant_id`, `code`),
  INDEX `idx_fluxbox_agents_template` (`is_template`),
  INDEX `idx_fluxbox_agents_provider` (`provider`),
  INDEX `idx_fluxbox_agents_active` (`tenant_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════
-- 2. fluxbox_agents_sections — premier niveau de découpe par agent
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `fluxbox_agents_sections` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agent_id`         BIGINT UNSIGNED NOT NULL,
  `code`             VARCHAR(40) NOT NULL COMMENT 'BANQUE | FACTURE | AVOIR | ...',
  `nom`              VARCHAR(80) NOT NULL,
  `description`      VARCHAR(255) NULL,
  `ordre`            INT UNSIGNED NOT NULL DEFAULT 0,
  `detection_rules`  JSON NULL COMMENT 'Critères pour savoir qu un doc relève de cette section',
  `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fluxbox_agents_sections` (`agent_id`, `code`),
  INDEX `idx_fluxbox_agents_sections_ordre` (`agent_id`, `ordre`),
  CONSTRAINT `fk_fluxbox_agents_sections_agent` FOREIGN KEY (`agent_id`)
    REFERENCES `fluxbox_agents_ia` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════
-- 3. fluxbox_agents_subsections — niveau de configuration le plus précis
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `fluxbox_agents_subsections` (
  `id`                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `section_id`             BIGINT UNSIGNED NOT NULL,
  `code`                   VARCHAR(60) NOT NULL COMMENT 'RELEVE_MENSUEL_SYNDIC | FACTURE_FOURNISSEUR | ...',
  `nom`                    VARCHAR(100) NOT NULL,
  `description`            VARCHAR(255) NULL,
  `ordre`                  INT UNSIGNED NOT NULL DEFAULT 0,
  `detection_rules`        JSON NULL COMMENT 'Comment identifier les docs de cette sous-section',
  `fields_schema`          JSON NULL COMMENT 'Champs à extraire : [{name, type, required, description}, ...]',
  `table_extraction_cible` VARCHAR(60) NULL COMMENT 'Table typée alimentée à la promotion (ex extraction_releve_bancaire)',
  `provider_override`      ENUM('anthropic','openai','mindee','cascade') NULL COMMENT 'Override du provider agent pour cette sous-section',
  `modele_override`        VARCHAR(120) NULL,
  `is_active`              TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_fluxbox_agents_subsections` (`section_id`, `code`),
  INDEX `idx_fluxbox_agents_subsections_ordre` (`section_id`, `ordre`),
  CONSTRAINT `fk_fluxbox_agents_subsections_section` FOREIGN KEY (`section_id`)
    REFERENCES `fluxbox_agents_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════
-- 4. fluxbox_agents_actions — actions conditionnelles déclenchées par sous-section
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `fluxbox_agents_actions` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subsection_id`  BIGINT UNSIGNED NOT NULL,
  `ordre`          INT UNSIGNED NOT NULL DEFAULT 0,
  `label`          VARCHAR(120) NOT NULL COMMENT 'Texte humain : "Envoi alerte si solde négatif"',
  `condition_json` JSON NULL COMMENT '{"always":true} ou {"field":"X","op":"<","value":Y}',
  `action_type`    ENUM('classement_ged','extraction_typee','envoi_mail',
                        'creation_doc_groupe','creation_tache','archivage_inviolable',
                        'notification','workflow_compta') NOT NULL,
  `payload_json`   JSON NOT NULL COMMENT 'Params : {path, template_id, destinataire, etc.}',
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_fluxbox_agents_actions_subsection` (`subsection_id`, `ordre`),
  INDEX `idx_fluxbox_agents_actions_type` (`action_type`),
  CONSTRAINT `fk_fluxbox_agents_actions_subsection` FOREIGN KEY (`subsection_id`)
    REFERENCES `fluxbox_agents_subsections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════
-- 5. Seed initial — 10 templates système (tenant_id = 0, is_template = 1)
-- ════════════════════════════════════════════════════════════════════════
-- Templates = tenant_id 0, clonés vers chaque tenant via UI admin.
INSERT IGNORE INTO `fluxbox_agents_ia`
  (`tenant_id`, `code`, `nom`, `description`, `provider`, `modele`, `prompt_systeme`, `is_template`, `is_active`)
VALUES
(0, 'COMPTABLE', 'Agent Comptable',
 'Traite les relevés bancaires, factures, avoirs et écritures comptables. Production FEC, audit Galian.',
 'cascade', 'mindee:bank-statement-v2',
 'Tu es un agent comptable spécialisé immobilier (syndic + gérance). Tu extrais les données financières structurées. Tu vérifies : montant TTC = HT + TVA, IBAN cohérent, période = mois concerné. Tu refuses d''inventer des champs absents.',
 1, 1),

(0, 'SYNDIC', 'Agent Syndic',
 'Traite les documents de copropriété : AG, PV, contrats, sinistres, travaux, ascenseurs.',
 'anthropic', 'claude-sonnet-4-6',
 'Tu es un agent syndic spécialisé copropriété française (loi Hoguet + ordonnance Mandat). Tu extrais les références d''immeuble, date d''AG, points d''ordre du jour, votes, devis travaux. Tu identifies les contrats à renouveler.',
 1, 1),

(0, 'GESTION', 'Agent Gestion Locative',
 'Traite les baux d''habitation, locataires, quittances, EDL, garants, dépôts de garantie.',
 'anthropic', 'claude-sonnet-4-6',
 'Tu es un agent de gestion locative spécialisé droit français (loi ALUR + loi du 6 juillet 1989). Tu extrais bailleur, locataire, immeuble, loyer, charges, dépôt de garantie, durée du bail. Tu calcules le plafond ALUR si applicable.',
 1, 1),

(0, 'TRANSACTION', 'Agent Transaction',
 'Traite les mandats de vente, compromis, actes de propriété, diagnostics.',
 'anthropic', 'claude-sonnet-4-6',
 'Tu es un agent transaction immobilière (carte T). Tu extrais : type de mandat, dates, vendeur, acquéreur, prix, honoraires, diagnostics joints (DPE, amiante, plomb, termites). Tu vérifies les délais légaux (rétractation SRU).',
 1, 1),

(0, 'RH', 'Agent RH',
 'Traite les bulletins de paie, contrats, déclarations sociales, justificatifs salariés.',
 'mindee', 'mindee:payslip-fr-v2',
 NULL,
 1, 1),

(0, 'JURIDIQUE', 'Agent Juridique',
 'Traite les contentieux, mises en demeure, jugements, courriers d''avocats.',
 'anthropic', 'claude-sonnet-4-6',
 'Tu es un agent juridique immobilier. Tu extrais les références de procédure, tribunal, parties, montants en jeu, dates clés (audience, signification). Tu identifies les actions urgentes (prescriptions, recours).',
 1, 1),

(0, 'FOURNISSEURS', 'Agent Fournisseurs',
 'Traite les devis fournisseurs, contrats d''abonnement, attestations RC.',
 'anthropic', 'claude-haiku-4-5-20251001',
 'Tu es un agent fournisseur. Tu extrais : raison sociale, SIRET, type de prestation, montant, période, dates limites. Tu détectes les renouvellements automatiques et les conditions de résiliation.',
 1, 1),

(0, 'DIRECTION', 'Agent Direction',
 'Traite les documents société : KBIS, statuts, AG société, courriers institutionnels.',
 'anthropic', 'claude-sonnet-4-6',
 'Tu es un agent direction société. Tu extrais : numéro RCS/SIREN, raison sociale, dirigeants, capital, adresse siège, dates d''AG. Pour KBIS : vérifier que la date est < 3 mois.',
 1, 1),

(0, 'CONFORMITE', 'Agent Conformité',
 'Traite la carte pro, RC pro, garantie financière, attestations Galian, contrôles ANAH/CCI.',
 'anthropic', 'claude-sonnet-4-6',
 'Tu es un agent conformité Hoguet. Tu extrais : type de carte (T/G/S), numéro CPI, date de validité (CRITIQUE), émetteur (CCI), garant financier, assureur RC pro. Tu alertes 60 jours avant expiration.',
 1, 1),

(0, 'BAILLEUR', 'Agent Bailleur/Propriétaire',
 'Traite les actes de propriété, fiscalité bailleurs, états mensuels propriétaires, mandats de gestion.',
 'anthropic', 'claude-sonnet-4-6',
 'Tu es un agent bailleur. Tu extrais : propriétaire, immeuble, lot, type d''acte (vente, donation, succession), date de mutation, mandat de gestion en cours. Tu rattaches les biens au bon dossier propriétaire.',
 1, 1);
SQL,
];
