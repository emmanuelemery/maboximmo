<?php
/**
 * Migration : Module CRÉANCIERS — base technique
 *
 * Cockpit de pilotage des urgences créanciers / saisies (cas pilote : GROUPE SIR).
 * PRINCIPE : le module ne possède rien, il agrège l'existant (tiers, biens,
 * sociétés, GED, tâches). Aucune nouvelle GED, aucun nouveau référentiel tiers.
 *
 * Tables créées (AJOUT uniquement, IF NOT EXISTS — rejouable) :
 *   - creancier_dossier         : objet de tête (dossier débiteur, ex. SIR)
 *   - creancier_dossier_lien    : liaison polymorphe dossier ↔ entités existantes
 *   - creancier_dossier_item    : procédure/échéance/dette/risque/décision/action (ce qui n'existe nulle part ailleurs)
 *   - creancier_dossier_acces   : ACL par dossier (dossiers sensibles)
 *   - creancier_saisie          : OBJET PLATEFORME (pas d'id_dossier ; pointé par un lien)
 *   - creancier_versement       : montants versés au créancier (montant, date, par qui)
 *   - creancier_doc_analyse     : staging extraction IA (calqué bailleur_baux_analyses ; GED intouchée)
 *
 * Inserts idempotents : 5 codes de rôles dans tiers_roles_codes (avocat/notaire déjà présents).
 *
 * GED : ged_document_links.entity_type est VARCHAR(40) libre → AUCUN changement DB.
 *       Les valeurs 'CREANCIER_DOSSIER' / 'CREANCIER_SAISIE' sont gérées côté appli
 *       (gdl_normalize_entity_type retombe sur default => $t).
 *
 * NE TOUCHE À AUCUNE table existante. NE TOUCHE À AUCUN trigger.
 *
 * ── ROLLBACK (-- DOWN) ────────────────────────────────────────────────
 *   DROP TABLE IF EXISTS `creancier_doc_analyse`;
 *   DROP TABLE IF EXISTS `creancier_versement`;
 *   DROP TABLE IF EXISTS `creancier_saisie`;
 *   DROP TABLE IF EXISTS `creancier_dossier_acces`;
 *   DROP TABLE IF EXISTS `creancier_dossier_item`;
 *   DROP TABLE IF EXISTS `creancier_dossier_lien`;
 *   DROP TABLE IF EXISTS `creancier_dossier`;
 *   DELETE FROM `tiers_roles_codes` WHERE `code` IN
 *     ('creancier','commissaire_justice','expert_comptable','heritier','associe');
 *   -- (avocat / notaire NON supprimés : préexistants)
 */

return [
    'id'          => '20260622_creanciers_01',
    'title'       => 'Module CRÉANCIERS — base technique (7 tables + 5 rôles)',
    'description' => "Crée creancier_dossier, creancier_dossier_lien (polymorphe), creancier_dossier_item, creancier_dossier_acces (ACL), creancier_saisie (objet plateforme sans id_dossier, montant_net_bloque réel), creancier_versement (montant/date/par qui), creancier_doc_analyse (staging IA). Insère 5 codes de rôles tiers (creancier, commissaire_justice, expert_comptable, heritier, associe). Aucun changement GED (entity_type varchar libre). AJOUT uniquement.",
    'created_at'  => '2026-06-22',
    'sql' => <<<'SQL'

-- ════════════════════════════════════════════════════════════════════
-- A. creancier_dossier — objet de tête (dossier débiteur)
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `creancier_dossier` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(40) NOT NULL COMMENT 'Identifiant court (ex. SIR)',
  `libelle` VARCHAR(190) NOT NULL,
  `statut` ENUM('actif','surveillance','clos') NOT NULL DEFAULT 'actif',
  `niveau_risque` ENUM('vert','orange','rouge') NOT NULL DEFAULT 'orange',
  `synthese` TEXT NULL COMMENT 'Pitch 30s, éditable',
  `numero_dossier_adverse` VARCHAR(120) NULL COMMENT 'N° dossier huissier / TJ / créancier',
  `id_societe` INT UNSIGNED NULL COMMENT 'Tenant (societes.id) — filtrage multi-tenant',
  `id_agence` INT UNSIGNED NULL COMMENT 'Tenant (agences.id)',
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_creancier_dossier_code` (`code`),
  KEY `idx_cd_tenant` (`id_societe`,`id_agence`),
  KEY `idx_cd_statut` (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- B. creancier_dossier_lien — liaison polymorphe dossier ↔ existant
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `creancier_dossier_lien` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_dossier` INT UNSIGNED NOT NULL,
  `entity_type` VARCHAR(20) NOT NULL COMMENT 'TIERS|BIEN|SOCIETE|BAILLEUR|TACHE|EVENEMENT|SAISIE',
  `entity_id` INT UNSIGNED NOT NULL COMMENT 'Polymorphe — AUCUNE FK',
  `role_dossier` VARCHAR(60) NULL COMMENT 'Rôle dans CE dossier (ex. creancier_principal, avocat_procedure)',
  `note` VARCHAR(255) NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cdl` (`id_dossier`,`entity_type`,`entity_id`,`role_dossier`),
  KEY `idx_cdl_dossier_entity` (`id_dossier`,`entity_type`,`entity_id`),
  CONSTRAINT `fk_cdl_dossier` FOREIGN KEY (`id_dossier`) REFERENCES `creancier_dossier` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- C. creancier_dossier_item — items propres au dossier (sans équivalent ailleurs)
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `creancier_dossier_item` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_dossier` INT UNSIGNED NOT NULL,
  `type` ENUM('PROCEDURE','ECHEANCE','DETTE','RISQUE','DECISION','ACTION') NOT NULL,
  `titre` VARCHAR(190) NOT NULL,
  `description` TEXT NULL,
  `montant` DECIMAL(12,2) NULL COMMENT 'Renseigné pour DETTE',
  `date_echeance` DATE NULL COMMENT 'Renseigné pour ECHEANCE/PROCEDURE',
  `statut` VARCHAR(40) NULL,
  `priorite` SMALLINT NOT NULL DEFAULT 0,
  `id_tiers_lie` INT UNSIGNED NULL COMMENT 'tiers.id (ex. créancier d''une DETTE)',
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cdi_dossier` (`id_dossier`,`type`),
  KEY `idx_cdi_echeance` (`date_echeance`),
  CONSTRAINT `fk_cdi_dossier` FOREIGN KEY (`id_dossier`) REFERENCES `creancier_dossier` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cdi_tiers` FOREIGN KEY (`id_tiers_lie`) REFERENCES `tiers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- D. creancier_dossier_acces — ACL (ces dossiers sont sensibles)
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `creancier_dossier_acces` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_dossier` INT UNSIGNED NOT NULL,
  `id_user` INT UNSIGNED NOT NULL,
  `niveau` ENUM('lecture','edition','pilote') NOT NULL DEFAULT 'lecture',
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cda` (`id_dossier`,`id_user`),
  KEY `idx_cda_user` (`id_user`),
  CONSTRAINT `fk_cda_dossier` FOREIGN KEY (`id_dossier`) REFERENCES `creancier_dossier` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- E. creancier_saisie — OBJET PLATEFORME (PAS d'id_dossier ; pointé par un lien)
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `creancier_saisie` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_societe` INT UNSIGNED NULL COMMENT 'Tenant — OBLIGATOIRE à l''usage',
  `id_agence` INT UNSIGNED NULL,
  `type_saisie` ENUM('ATTRIBUTION_LOYER','IMMOBILIERE','CONSERVATOIRE','COMPTE','REMUNERATION') NOT NULL,
  `id_creancier` INT UNSIGNED NOT NULL COMMENT 'tiers.id (rôle creancier)',
  `id_commissaire` INT UNSIGNED NULL COMMENT 'tiers.id (huissier / commissaire de justice)',
  `cible_type` ENUM('BIEN','LOCATAIRE','SOCIETE','COMPTE') NOT NULL,
  `cible_id` INT UNSIGNED NULL COMMENT 'Polymorphe — AUCUNE FK',
  `montant_reclame` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `montant_cantonne` DECIMAL(12,2) NULL,
  `montant_net_bloque` DECIMAL(12,2) NULL COMMENT 'Réel (non généré) — init = reclame - cantonne, ajustable (paiements partiels)',
  `loyer_mensuel_capte` DECIMAL(12,2) NULL COMMENT 'Seulement si ATTRIBUTION_LOYER',
  `statut` ENUM('en_cours','cantonnee','mainlevee_partielle','mainlevee','soldee','contestee') NOT NULL DEFAULT 'en_cours',
  `date_pv` DATE NULL,
  `date_audience` DATE NULL,
  `date_butoir` DATE NULL,
  `date_mainlevee` DATE NULL,
  `reference_acte` VARCHAR(190) NULL,
  `prochaine_action` VARCHAR(255) NULL,
  `id_user_porteur` INT UNSIGNED NULL,
  `note` TEXT NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cs_creancier` (`id_creancier`),
  KEY `idx_cs_cible` (`cible_type`,`cible_id`),
  KEY `idx_cs_butoir` (`date_butoir`),
  KEY `idx_cs_statut` (`statut`),
  KEY `idx_cs_tenant` (`id_societe`,`id_agence`),
  CONSTRAINT `fk_cs_creancier` FOREIGN KEY (`id_creancier`) REFERENCES `tiers` (`id`),
  CONSTRAINT `fk_cs_commissaire` FOREIGN KEY (`id_commissaire`) REFERENCES `tiers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- F. creancier_versement — montants versés au créancier (montant / date / par qui)
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `creancier_versement` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_saisie` INT UNSIGNED NOT NULL,
  `montant` DECIMAL(12,2) NOT NULL,
  `date_versement` DATE NOT NULL,
  `id_tiers_payeur` INT UNSIGNED NULL COMMENT 'tiers.id — entité débitrice qui paie (par qui)',
  `id_user_saisi` INT UNSIGNED NULL COMMENT 'users.id — qui a enregistré (traçabilité)',
  `mode` ENUM('virement','cheque','prelevement','autre') NOT NULL DEFAULT 'virement',
  `reference` VARCHAR(190) NULL,
  `note` VARCHAR(255) NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cv_saisie` (`id_saisie`),
  KEY `idx_cv_date` (`date_versement`),
  CONSTRAINT `fk_cv_saisie` FOREIGN KEY (`id_saisie`) REFERENCES `creancier_saisie` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cv_payeur` FOREIGN KEY (`id_tiers_payeur`) REFERENCES `tiers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- G. creancier_doc_analyse — staging extraction IA (GED ged_documents intouchée)
-- ════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `creancier_doc_analyse` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_dossier` INT UNSIGNED NULL COMMENT 'Rattachement après validation',
  `id_saisie` INT UNSIGNED NULL COMMENT 'Saisie liée après validation',
  `ged_document_id` BIGINT UNSIGNED NULL COMMENT 'ged_documents.id après commit GED',
  `type_doc` VARCHAR(40) NULL COMMENT 'conclusions|jugement|commandement|correspondance|mail|acte_saisie|autre',
  `donnees_json` LONGTEXT NULL COMMENT 'Extraction IA brute (JSON)',
  `extr_creancier_nom` VARCHAR(190) NULL,
  `extr_pro_nom` VARCHAR(190) NULL COMMENT 'Avocat / huissier extrait',
  `extr_numero_dossier` VARCHAR(120) NULL,
  `extr_montant_principal` DECIMAL(12,2) NULL,
  `extr_montant_total` DECIMAL(12,2) NULL,
  `extr_objet` VARCHAR(255) NULL COMMENT 'Cause / objet',
  `id_tiers_creancier_match` INT UNSIGNED NULL COMMENT 'tiers.id proposé par matching anti-doublon',
  `confidence` DECIMAL(4,3) NULL COMMENT 'Score IA 0.000-1.000',
  `model_used` VARCHAR(30) NULL,
  `tokens_in` INT UNSIGNED NULL,
  `tokens_out` INT UNSIGNED NULL,
  `cost_eur` DECIMAL(8,4) NULL,
  `statut` ENUM('a_valider','valide','rejete') NOT NULL DEFAULT 'a_valider',
  `review_flags` TEXT NULL COMMENT 'Doublons / incohérences détectés (comparaison inter-dossiers)',
  `validated_by` INT UNSIGNED NULL,
  `validated_at` DATETIME NULL,
  `id_societe` INT UNSIGNED NULL,
  `id_agence` INT UNSIGNED NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cda_dossier` (`id_dossier`),
  KEY `idx_cda_saisie` (`id_saisie`),
  KEY `idx_cda_statut` (`statut`),
  KEY `idx_cda_numdoss` (`extr_numero_dossier`),
  KEY `idx_cda_match` (`id_tiers_creancier_match`),
  CONSTRAINT `fk_cdoc_dossier` FOREIGN KEY (`id_dossier`) REFERENCES `creancier_dossier` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cdoc_saisie` FOREIGN KEY (`id_saisie`) REFERENCES `creancier_saisie` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cdoc_tiers` FOREIGN KEY (`id_tiers_creancier_match`) REFERENCES `tiers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════
-- Rôles tiers (catalogue tiers_roles_codes) — idempotent. avocat/notaire préexistants.
-- ════════════════════════════════════════════════════════════════════
INSERT IGNORE INTO `tiers_roles_codes` (`code`,`libelle`,`categorie`,`description`,`objet_type_defaut`,`actif`,`ordre_affichage`) VALUES
  ('creancier','Créancier','financier','Personne/organisme à qui une somme est due (banque, Trésor public, SIP…)','global',1,200),
  ('commissaire_justice','Commissaire de justice','juridique','Huissier / commissaire de justice (saisies, significations)','global',1,201),
  ('expert_comptable','Expert-comptable','prestataire','Expert-comptable du dossier','global',1,202),
  ('heritier','Héritier','juridique','Héritier / ayant droit (succession, indivision)','global',1,203),
  ('associe','Associé','juridique','Associé d''une société du dossier','societe',1,204);

SQL
];
