<?php
/**
 * Migration v2.25 — Modèle GED unifié (arbitrage EMERY 2026-05-12)
 *
 * Implémente les décisions fermes du 2026-05-12 (cf. memory project_ged_arbitrage_modele_n1_2026-05-12) :
 *
 * A. Colonnes `business_group` + `is_virtual` sur `ged_level_codes`
 * B. Création nouveau N1 `13_FOURNISSEURS` (entité au même niveau que IMMEUBLE/PROPRIETAIRE)
 * C. Mapping `business_group` sur les 15 N1 (8 groupes UI : RH/COMPTA/BAILLEUR/SYNDIC/AGENCE/FOURNISSEURS/MARKETING/ADMIN)
 * D. Complétion seeds N3/N4/N5 sur les 7 branches incomplètes :
 *    - 01_AGENCE (5 N2 — FOURNISSEURS_AGENCE marqué is_virtual=1)
 *    - 06_COMPTABILITE (6 N2 manquants — SYNDIC/GESTION déjà seedés en _v1_15)
 *    - 07_JURIDIQUE_CONTENTIEUX (4 N2)
 *    - 08_MARKETING_COMMUNICATION (5 N2)
 *    - 09_MODELES_DOCUMENTS (6 N2 — structure uniforme par modèle)
 *    - 10_REFERENTIEL (5 N2)
 *    - 11_MAILS_COMMUNICATIONS (6 N2 — boîte mail par user, sous-classement par sujet)
 *    - 12_ARCHIVES (6 N2 minimalistes — squelette ANNEE+TYPE)
 * E. Virtualisation des codes ANNEE existants (is_virtual=1, filtres UI dérivés date_document)
 * F. Seed exercices comptables 2023-2027 en codes durs sous 06_COMPTABILITE
 *    (le cron annuel ged_create_next_year sera livré séparément)
 * G. Virtualisation 99_A_CLASSER_DIRECTION (doublon de 99_SYSTEME > IMPORTS)
 *
 * Idempotent (INSERT IGNORE + UPDATE conditionnel). Aucune suppression de code existant.
 * Migration de suivi _v2_26 (à venir) : déplacement physique des docs déjà classés
 * sous FOURNISSEURS_AGENCE et placeholders FOURNISSEUR (GE/SY) vers 13_FOURNISSEURS.
 */

return [
    'id'          => '20260512_ged_v2_25_complete_seeds',
    'title'       => 'Ma GED Box V2.25 — modèle unifié (15 N1 × 8 groupes, complétion 7 branches, virtualisation ANNEE, N1 13_FOURNISSEURS)',
    'description' => "Arbitrage EMERY 2026-05-12 : ajoute business_group + is_virtual sur ged_level_codes, crée le N1 13_FOURNISSEURS, complète les seeds N3/N4/N5 des 7 branches incomplètes (01_AGENCE/06_COMPTABILITE/07_JURIDIQUE/08_MARKETING/09_MODELES/10_REFERENTIEL/11_MAILS/12_ARCHIVES), mappe les 15 N1 vers les 8 groupes UI, virtualise les codes ANNEE existants et marque les doublons (FOURNISSEURS_AGENCE, 99_A_CLASSER_DIRECTION). Seed exercices comptables 2023-2027 en dur. Idempotent.",
    'created_at'  => '2026-05-12',
    'sql' => <<<'SQL'
-- ════════════════════════════════════════════════════════════════════════
-- A. COLONNES business_group + is_virtual
-- ════════════════════════════════════════════════════════════════════════
ALTER TABLE `ged_level_codes`
  ADD COLUMN IF NOT EXISTS `business_group` VARCHAR(20) NULL
    COMMENT 'RH|COMPTA|BAILLEUR|SYNDIC|AGENCE|FOURNISSEURS|MARKETING|ADMIN — uniquement sur N1';

ALTER TABLE `ged_level_codes`
  ADD COLUMN IF NOT EXISTS `is_virtual` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = noeud filtre/derive (ex ANNEE), pas materialise en dossier physique';

ALTER TABLE `ged_level_codes`
  ADD INDEX IF NOT EXISTS `idx_ged_level_codes_business_group` (`business_group`, `level_number`);

ALTER TABLE `ged_level_codes`
  ADD INDEX IF NOT EXISTS `idx_ged_level_codes_is_virtual` (`is_virtual`);

-- ════════════════════════════════════════════════════════════════════════
-- B. NOUVEAU N1 — 13_FOURNISSEURS
-- ════════════════════════════════════════════════════════════════════════
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `code`, `label`, `position`) VALUES
  (NULL, 1, '13_FOURNISSEURS', '13 - Fournisseurs', 13);

-- N2 = placeholder FOURNISSEUR (instance par fournisseur)
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `code`, `label`, `position`, `is_entity_placeholder`) VALUES
  (NULL, 2, '13_FOURNISSEURS', 'FOURNISSEUR', 'Fournisseur (entité)', 1, 1);

-- N3 sous FOURNISSEUR (6 catégories numérotées)
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '13_FOURNISSEURS', 'FOURNISSEUR', '01_DOSSIER_FOURNISSEUR', '01 - Dossier fournisseur', 1),
  (NULL, 3, '13_FOURNISSEURS', 'FOURNISSEUR', '02_CONTRATS',            '02 - Contrats',            2),
  (NULL, 3, '13_FOURNISSEURS', 'FOURNISSEUR', '03_INTERVENTIONS',       '03 - Interventions',       3),
  (NULL, 3, '13_FOURNISSEURS', 'FOURNISSEUR', '04_FACTURATION',         '04 - Facturation',         4),
  (NULL, 3, '13_FOURNISSEURS', 'FOURNISSEUR', '05_COURRIERS_MAILS',     '05 - Courriers & mails',   5),
  (NULL, 3, '13_FOURNISSEURS', 'FOURNISSEUR', '99_ARCHIVES',            '99 - Archives',            99);

-- N4 sous 01_DOSSIER_FOURNISSEUR
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '13_FOURNISSEURS', 'FOURNISSEUR', '01_DOSSIER_FOURNISSEUR', 'IDENTITE',       'Identité (Kbis, RIB, RC)',  1),
  (NULL, 4, '13_FOURNISSEURS', 'FOURNISSEUR', '01_DOSSIER_FOURNISSEUR', 'CONTACTS',       'Contacts',                  2),
  (NULL, 4, '13_FOURNISSEURS', 'FOURNISSEUR', '01_DOSSIER_FOURNISSEUR', 'QUALIFICATION',  'Qualification',             3),
  (NULL, 4, '13_FOURNISSEURS', 'FOURNISSEUR', '01_DOSSIER_FOURNISSEUR', 'NOTES_INTERNES', 'Notes internes',            4);

-- N4 sous 02_CONTRATS
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '13_FOURNISSEURS', 'FOURNISSEUR', '02_CONTRATS', 'MAINTENANCE',      'Maintenance',     1),
  (NULL, 4, '13_FOURNISSEURS', 'FOURNISSEUR', '02_CONTRATS', 'PRESTATION',       'Prestation',      2),
  (NULL, 4, '13_FOURNISSEURS', 'FOURNISSEUR', '02_CONTRATS', 'RENOUVELLEMENTS',  'Renouvellements', 3),
  (NULL, 4, '13_FOURNISSEURS', 'FOURNISSEUR', '02_CONTRATS', 'RESILIATIONS',     'Résiliations',    4);

-- N4 sous 03_INTERVENTIONS
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '13_FOURNISSEURS', 'FOURNISSEUR', '03_INTERVENTIONS', 'DEMANDES',              'Demandes',               1),
  (NULL, 4, '13_FOURNISSEURS', 'FOURNISSEUR', '03_INTERVENTIONS', 'BONS_INTERVENTION',     'Bons d''intervention',   2),
  (NULL, 4, '13_FOURNISSEURS', 'FOURNISSEUR', '03_INTERVENTIONS', 'DEVIS',                 'Devis',                  3),
  (NULL, 4, '13_FOURNISSEURS', 'FOURNISSEUR', '03_INTERVENTIONS', 'RAPPORTS_INTERVENTION', 'Rapports d''intervention',4);

-- N4 sous 04_FACTURATION
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '13_FOURNISSEURS', 'FOURNISSEUR', '04_FACTURATION', 'FACTURES_A_VALIDER', 'Factures à valider', 1),
  (NULL, 4, '13_FOURNISSEURS', 'FOURNISSEUR', '04_FACTURATION', 'FACTURES_VALIDEES',  'Factures validées',  2),
  (NULL, 4, '13_FOURNISSEURS', 'FOURNISSEUR', '04_FACTURATION', 'AVOIRS',             'Avoirs',             3),
  (NULL, 4, '13_FOURNISSEURS', 'FOURNISSEUR', '04_FACTURATION', 'LITIGES_PAIEMENT',   'Litiges paiement',   4);

-- N4 sous 05_COURRIERS_MAILS
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '13_FOURNISSEURS', 'FOURNISSEUR', '05_COURRIERS_MAILS', 'COURRIERS_ENTRANTS', 'Courriers entrants', 1),
  (NULL, 4, '13_FOURNISSEURS', 'FOURNISSEUR', '05_COURRIERS_MAILS', 'COURRIERS_SORTANTS', 'Courriers sortants', 2),
  (NULL, 4, '13_FOURNISSEURS', 'FOURNISSEUR', '05_COURRIERS_MAILS', 'MAILS',              'Mails',              3),
  (NULL, 4, '13_FOURNISSEURS', 'FOURNISSEUR', '05_COURRIERS_MAILS', 'PIECES_JOINTES',     'Pièces jointes',     4);

-- ════════════════════════════════════════════════════════════════════════
-- C. MAPPING business_group sur les 15 N1 (8 groupes UI)
-- ════════════════════════════════════════════════════════════════════════
UPDATE `ged_level_codes` SET `business_group` = 'RH'           WHERE `level_number` = 1 AND `code` = '02_RH';
UPDATE `ged_level_codes` SET `business_group` = 'COMPTA'       WHERE `level_number` = 1 AND `code` = '06_COMPTABILITE';
UPDATE `ged_level_codes` SET `business_group` = 'BAILLEUR'     WHERE `level_number` = 1 AND `code` = '03_GESTION_LOCATIVE';
UPDATE `ged_level_codes` SET `business_group` = 'SYNDIC'       WHERE `level_number` = 1 AND `code` = '04_SYNDIC';
UPDATE `ged_level_codes` SET `business_group` = 'AGENCE'       WHERE `level_number` = 1 AND `code` IN ('05_TRANSACTION', '01_AGENCE');
UPDATE `ged_level_codes` SET `business_group` = 'FOURNISSEURS' WHERE `level_number` = 1 AND `code` = '13_FOURNISSEURS';
UPDATE `ged_level_codes` SET `business_group` = 'MARKETING'    WHERE `level_number` = 1 AND `code` = '08_MARKETING_COMMUNICATION';
UPDATE `ged_level_codes` SET `business_group` = 'ADMIN'        WHERE `level_number` = 1 AND `code` IN (
  '01_DIRECTION', '07_JURIDIQUE_CONTENTIEUX', '09_MODELES_DOCUMENTS',
  '10_REFERENTIEL', '11_MAILS_COMMUNICATIONS', '12_ARCHIVES', '99_SYSTEME'
);

-- ════════════════════════════════════════════════════════════════════════
-- D1. COMPLÉTION 01_AGENCE (FOURNISSEURS_AGENCE marqué virtual + 5 N2 seedés)
-- ════════════════════════════════════════════════════════════════════════
-- Marquer FOURNISSEURS_AGENCE comme virtuel (doublon de 13_FOURNISSEURS)
UPDATE `ged_level_codes` SET `is_virtual` = 1
  WHERE `level_number` = 2 AND `parent_n1` = '01_AGENCE' AND `code` = 'FOURNISSEURS_AGENCE';

-- PROCEDURES > N3 = CATEGORIE (6 catégories métier)
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_AGENCE', 'PROCEDURES', '01_METIER',       '01 - Métier',       1),
  (NULL, 3, '01_AGENCE', 'PROCEDURES', '02_COMPTA',       '02 - Compta',       2),
  (NULL, 3, '01_AGENCE', 'PROCEDURES', '03_RH',           '03 - RH',           3),
  (NULL, 3, '01_AGENCE', 'PROCEDURES', '04_JURIDIQUE',    '04 - Juridique',    4),
  (NULL, 3, '01_AGENCE', 'PROCEDURES', '05_COMMERCIAL',   '05 - Commercial',   5),
  (NULL, 3, '01_AGENCE', 'PROCEDURES', '06_INFORMATIQUE', '06 - Informatique', 6);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`)
SELECT NULL, 4, '01_AGENCE', 'PROCEDURES', n3, n4, n4_label, n4_pos FROM (
  SELECT '01_METIER' AS n3 UNION ALL SELECT '02_COMPTA' UNION ALL SELECT '03_RH'
  UNION ALL SELECT '04_JURIDIQUE' UNION ALL SELECT '05_COMMERCIAL' UNION ALL SELECT '06_INFORMATIQUE'
) p
CROSS JOIN (
  SELECT 'VERSION_EN_COURS'      AS n4, 'Version en cours'        AS n4_label, 1 AS n4_pos
  UNION ALL SELECT 'VERSIONS_PRECEDENTES', 'Versions précédentes', 2
  UNION ALL SELECT 'BROUILLONS',           'Brouillons',           3
) l;

-- FORMATIONS > N3 = THEME (placeholder)
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`, `is_entity_placeholder`) VALUES
  (NULL, 3, '01_AGENCE', 'FORMATIONS', 'THEME', 'Thème (entité)', 1, 1);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_AGENCE', 'FORMATIONS', 'THEME', '01_SUPPORTS',     '01 - Supports',     1),
  (NULL, 4, '01_AGENCE', 'FORMATIONS', 'THEME', '02_INSCRIPTIONS', '02 - Inscriptions', 2),
  (NULL, 4, '01_AGENCE', 'FORMATIONS', 'THEME', '03_CERTIFICATS',  '03 - Certificats',  3),
  (NULL, 4, '01_AGENCE', 'FORMATIONS', 'THEME', '04_EVALUATIONS',  '04 - Évaluations',  4),
  (NULL, 4, '01_AGENCE', 'FORMATIONS', 'THEME', '99_ARCHIVES',     '99 - Archives',    99);

-- COMMUNICATION_INTERNE > N3 directs
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_AGENCE', 'COMMUNICATION_INTERNE', '01_NOTES_SERVICE', '01 - Notes de service', 1),
  (NULL, 3, '01_AGENCE', 'COMMUNICATION_INTERNE', '02_ANNONCES',      '02 - Annonces',         2),
  (NULL, 3, '01_AGENCE', 'COMMUNICATION_INTERNE', '03_NEWSLETTER',    '03 - Newsletter',       3),
  (NULL, 3, '01_AGENCE', 'COMMUNICATION_INTERNE', '04_EVENEMENTS',    '04 - Événements',       4),
  (NULL, 3, '01_AGENCE', 'COMMUNICATION_INTERNE', '05_SEMINAIRES',    '05 - Séminaires',       5);

-- OUTILS_LOGICIELS > N3 = LOGICIEL (placeholder)
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`, `is_entity_placeholder`) VALUES
  (NULL, 3, '01_AGENCE', 'OUTILS_LOGICIELS', 'LOGICIEL', 'Logiciel (entité)', 1, 1);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_AGENCE', 'OUTILS_LOGICIELS', 'LOGICIEL', '01_LICENCES',           '01 - Licences',            1),
  (NULL, 4, '01_AGENCE', 'OUTILS_LOGICIELS', 'LOGICIEL', '02_FACTURES_ABONNEMENT','02 - Factures abonnement', 2),
  (NULL, 4, '01_AGENCE', 'OUTILS_LOGICIELS', 'LOGICIEL', '03_DOCUMENTATION',      '03 - Documentation',       3),
  (NULL, 4, '01_AGENCE', 'OUTILS_LOGICIELS', 'LOGICIEL', '04_MISES_A_JOUR',       '04 - Mises à jour',        4),
  (NULL, 4, '01_AGENCE', 'OUTILS_LOGICIELS', 'LOGICIEL', '05_INCIDENTS',          '05 - Incidents',           5);

-- DOCUMENTS_ADMINISTRATIFS > N3 directs
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_AGENCE', 'DOCUMENTS_ADMINISTRATIFS', '01_CARTES_PRO',            '01 - Cartes pro',             1),
  (NULL, 3, '01_AGENCE', 'DOCUMENTS_ADMINISTRATIFS', '02_GARANTIES_FINANCIERES', '02 - Garanties financières',  2),
  (NULL, 3, '01_AGENCE', 'DOCUMENTS_ADMINISTRATIFS', '03_ASSURANCES_RC_PRO',     '03 - Assurances RC Pro',      3),
  (NULL, 3, '01_AGENCE', 'DOCUMENTS_ADMINISTRATIFS', '04_KBIS_AGENCE',           '04 - Kbis agence',            4),
  (NULL, 3, '01_AGENCE', 'DOCUMENTS_ADMINISTRATIFS', '05_AUTORISATIONS',         '05 - Autorisations',          5);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`)
SELECT NULL, 4, '01_AGENCE', 'DOCUMENTS_ADMINISTRATIFS', n3, n4, n4_label, n4_pos FROM (
  SELECT '01_CARTES_PRO' AS n3 UNION ALL SELECT '02_GARANTIES_FINANCIERES'
  UNION ALL SELECT '03_ASSURANCES_RC_PRO' UNION ALL SELECT '04_KBIS_AGENCE' UNION ALL SELECT '05_AUTORISATIONS'
) p
CROSS JOIN (
  SELECT 'EN_COURS'      AS n4, 'En cours'      AS n4_label, 1 AS n4_pos
  UNION ALL SELECT 'ANCIENS',       'Anciens',       2
  UNION ALL SELECT 'JUSTIFICATIFS', 'Justificatifs',3
) l;

-- ════════════════════════════════════════════════════════════════════════
-- D2. COMPLÉTION 06_COMPTABILITE (6 N2 manquants, SYNDIC/GESTION déjà seedés)
-- ════════════════════════════════════════════════════════════════════════
-- F. Exercices comptables 2023-2027 (codes durs, pas placeholder — années obligatoires)
-- Sous COMPTABILITE_GENERALE, FOURNISSEURS, CLIENTS, ARCHIVES (entités à exercice)
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`)
SELECT NULL, 3, '06_COMPTABILITE', n2, code, label, pos FROM (
  SELECT 'COMPTABILITE_GENERALE' AS n2 UNION ALL SELECT 'FOURNISSEURS'
  UNION ALL SELECT 'CLIENTS' UNION ALL SELECT 'ARCHIVES'
) p
CROSS JOIN (
  SELECT 'EXERCICE_2023' AS code, 'Exercice 2023' AS label, 1 AS pos
  UNION ALL SELECT 'EXERCICE_2024', 'Exercice 2024', 2
  UNION ALL SELECT 'EXERCICE_2025', 'Exercice 2025', 3
  UNION ALL SELECT 'EXERCICE_2026', 'Exercice 2026', 4
  UNION ALL SELECT 'EXERCICE_2027', 'Exercice 2027', 5
) y;

-- N4 sous COMPTABILITE_GENERALE > EXERCICE_YYYY
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`)
SELECT NULL, 4, '06_COMPTABILITE', 'COMPTABILITE_GENERALE', exercice, code, label, pos FROM (
  SELECT 'EXERCICE_2023' AS exercice UNION ALL SELECT 'EXERCICE_2024'
  UNION ALL SELECT 'EXERCICE_2025' UNION ALL SELECT 'EXERCICE_2026' UNION ALL SELECT 'EXERCICE_2027'
) e
CROSS JOIN (
  SELECT 'BILAN'              AS code, 'Bilan'                AS label, 1 AS pos
  UNION ALL SELECT 'GRAND_LIVRE',         'Grand livre',          2
  UNION ALL SELECT 'BALANCE',             'Balance',              3
  UNION ALL SELECT 'JOURNAUX',            'Journaux',             4
  UNION ALL SELECT 'FEC',                 'FEC',                  5
  UNION ALL SELECT 'ECRITURES_MANUELLES', 'Écritures manuelles',  6
  UNION ALL SELECT 'CLOTURE',             'Clôture',              7
) n;

-- BANQUES > N3 = COMPTE_BANCAIRE (placeholder)
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`, `is_entity_placeholder`) VALUES
  (NULL, 3, '06_COMPTABILITE', 'BANQUES', 'COMPTE_BANCAIRE', 'Compte bancaire (entité)', 1, 1);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '06_COMPTABILITE', 'BANQUES', 'COMPTE_BANCAIRE', '01_RELEVES',         '01 - Relevés',         1),
  (NULL, 4, '06_COMPTABILITE', 'BANQUES', 'COMPTE_BANCAIRE', '02_RAPPROCHEMENTS',  '02 - Rapprochements',  2),
  (NULL, 4, '06_COMPTABILITE', 'BANQUES', 'COMPTE_BANCAIRE', '03_ECHANGES_BANQUE', '03 - Échanges banque', 3),
  (NULL, 4, '06_COMPTABILITE', 'BANQUES', 'COMPTE_BANCAIRE', '04_INCIDENTS',       '04 - Incidents',       4),
  (NULL, 4, '06_COMPTABILITE', 'BANQUES', 'COMPTE_BANCAIRE', '05_CFONB_SOURCE',    '05 - CFONB source',    5),
  (NULL, 4, '06_COMPTABILITE', 'BANQUES', 'COMPTE_BANCAIRE', '06_CFONB_INTEGRE',   '06 - CFONB intégré',   6);

-- N4 sous FOURNISSEURS > EXERCICE_YYYY (vue comptable, distincte de N1 13_FOURNISSEURS)
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`)
SELECT NULL, 4, '06_COMPTABILITE', 'FOURNISSEURS', exercice, code, label, pos FROM (
  SELECT 'EXERCICE_2023' AS exercice UNION ALL SELECT 'EXERCICE_2024'
  UNION ALL SELECT 'EXERCICE_2025' UNION ALL SELECT 'EXERCICE_2026' UNION ALL SELECT 'EXERCICE_2027'
) e
CROSS JOIN (
  SELECT 'FACTURES_A_PAYER'           AS code, 'Factures à payer'          AS label, 1 AS pos
  UNION ALL SELECT 'FACTURES_PAYEES',           'Factures payées',           2
  UNION ALL SELECT 'RAPPROCHEMENTS_FOURNISSEURS','Rapprochements fournisseurs',3
  UNION ALL SELECT 'AVOIRS',                    'Avoirs',                    4
  UNION ALL SELECT 'LITIGES_PAIEMENT',          'Litiges paiement',          5
  UNION ALL SELECT 'ECHEANCIER',                'Échéancier',                6
) n;

-- N4 sous CLIENTS > EXERCICE_YYYY
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`)
SELECT NULL, 4, '06_COMPTABILITE', 'CLIENTS', exercice, code, label, pos FROM (
  SELECT 'EXERCICE_2023' AS exercice UNION ALL SELECT 'EXERCICE_2024'
  UNION ALL SELECT 'EXERCICE_2025' UNION ALL SELECT 'EXERCICE_2026' UNION ALL SELECT 'EXERCICE_2027'
) e
CROSS JOIN (
  SELECT 'FACTURES_EMISES'   AS code, 'Factures émises'    AS label, 1 AS pos
  UNION ALL SELECT 'REGLEMENTS_RECUS', 'Règlements reçus',   2
  UNION ALL SELECT 'AVOIRS',           'Avoirs',             3
  UNION ALL SELECT 'RELANCES',         'Relances',           4
  UNION ALL SELECT 'IMPAYES',          'Impayés',            5
  UNION ALL SELECT 'ECHEANCIER',       'Échéancier',         6
) n;

-- DECLARATIONS > N3 directs (par type fiscalité)
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '06_COMPTABILITE', 'DECLARATIONS', '01_TVA',                  '01 - TVA',                   1),
  (NULL, 3, '06_COMPTABILITE', 'DECLARATIONS', '02_IS',                   '02 - IS',                    2),
  (NULL, 3, '06_COMPTABILITE', 'DECLARATIONS', '03_CFE',                  '03 - CFE',                   3),
  (NULL, 3, '06_COMPTABILITE', 'DECLARATIONS', '04_CVAE',                 '04 - CVAE',                  4),
  (NULL, 3, '06_COMPTABILITE', 'DECLARATIONS', '05_TAXES_DIVERSES',       '05 - Taxes diverses',        5),
  (NULL, 3, '06_COMPTABILITE', 'DECLARATIONS', '06_DECLARATIONS_SOCIALES','06 - Déclarations sociales', 6);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`)
SELECT NULL, 4, '06_COMPTABILITE', 'DECLARATIONS', n3, n4, n4_label, n4_pos FROM (
  SELECT '01_TVA' AS n3 UNION ALL SELECT '02_IS' UNION ALL SELECT '03_CFE'
  UNION ALL SELECT '04_CVAE' UNION ALL SELECT '05_TAXES_DIVERSES' UNION ALL SELECT '06_DECLARATIONS_SOCIALES'
) p
CROSS JOIN (
  SELECT 'DECLARATIONS_DEPOSEES' AS n4, 'Déclarations déposées' AS n4_label, 1 AS n4_pos
  UNION ALL SELECT 'JUSTIFICATIFS',     'Justificatifs',          2
  UNION ALL SELECT 'PAIEMENTS',         'Paiements',              3
  UNION ALL SELECT 'RELANCES_FISCALES', 'Relances fiscales',      4
) l;

-- N4 sous ARCHIVES > EXERCICE_YYYY (squelette minimal)
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`)
SELECT NULL, 4, '06_COMPTABILITE', 'ARCHIVES', exercice, code, label, pos FROM (
  SELECT 'EXERCICE_2023' AS exercice UNION ALL SELECT 'EXERCICE_2024'
  UNION ALL SELECT 'EXERCICE_2025' UNION ALL SELECT 'EXERCICE_2026' UNION ALL SELECT 'EXERCICE_2027'
) e
CROSS JOIN (
  SELECT 'BILANS' AS code, 'Bilans' AS label, 1 AS pos
  UNION ALL SELECT 'JOURNAUX',     'Journaux',     2
  UNION ALL SELECT 'BALANCES',     'Balances',     3
  UNION ALL SELECT 'DECLARATIONS', 'Déclarations', 4
  UNION ALL SELECT 'ECRITURES',    'Écritures',    5
) n;

-- ════════════════════════════════════════════════════════════════════════
-- D3. COMPLÉTION 07_JURIDIQUE_CONTENTIEUX (4 N2)
-- ════════════════════════════════════════════════════════════════════════
-- DOSSIERS_JURIDIQUES > N3 = DOSSIER (placeholder)
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`, `is_entity_placeholder`) VALUES
  (NULL, 3, '07_JURIDIQUE_CONTENTIEUX', 'DOSSIERS_JURIDIQUES', 'DOSSIER', 'Dossier (entité)', 1, 1);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '07_JURIDIQUE_CONTENTIEUX', 'DOSSIERS_JURIDIQUES', 'DOSSIER', '01_PIECES',          '01 - Pièces',             1),
  (NULL, 4, '07_JURIDIQUE_CONTENTIEUX', 'DOSSIERS_JURIDIQUES', 'DOSSIER', '02_COURRIERS',       '02 - Courriers',          2),
  (NULL, 4, '07_JURIDIQUE_CONTENTIEUX', 'DOSSIERS_JURIDIQUES', 'DOSSIER', '03_MAILS',           '03 - Mails',              3),
  (NULL, 4, '07_JURIDIQUE_CONTENTIEUX', 'DOSSIERS_JURIDIQUES', 'DOSSIER', '04_DECISIONS',       '04 - Décisions',          4),
  (NULL, 4, '07_JURIDIQUE_CONTENTIEUX', 'DOSSIERS_JURIDIQUES', 'DOSSIER', '05_ECHEANCIER',      '05 - Échéancier',         5),
  (NULL, 4, '07_JURIDIQUE_CONTENTIEUX', 'DOSSIERS_JURIDIQUES', 'DOSSIER', '06_FRAIS_JURIDIQUES','06 - Frais juridiques',   6),
  (NULL, 4, '07_JURIDIQUE_CONTENTIEUX', 'DOSSIERS_JURIDIQUES', 'DOSSIER', '99_ARCHIVES',        '99 - Archives',          99);

-- PROCEDURES > N3 = PROCEDURE (placeholder)
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`, `is_entity_placeholder`) VALUES
  (NULL, 3, '07_JURIDIQUE_CONTENTIEUX', 'PROCEDURES', 'PROCEDURE', 'Procédure (entité)', 1, 1);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '07_JURIDIQUE_CONTENTIEUX', 'PROCEDURES', 'PROCEDURE', '01_ASSIGNATIONS', '01 - Assignations',  1),
  (NULL, 4, '07_JURIDIQUE_CONTENTIEUX', 'PROCEDURES', 'PROCEDURE', '02_CONCLUSIONS',  '02 - Conclusions',   2),
  (NULL, 4, '07_JURIDIQUE_CONTENTIEUX', 'PROCEDURES', 'PROCEDURE', '03_JUGEMENTS',    '03 - Jugements',     3),
  (NULL, 4, '07_JURIDIQUE_CONTENTIEUX', 'PROCEDURES', 'PROCEDURE', '04_APPELS',       '04 - Appels',        4),
  (NULL, 4, '07_JURIDIQUE_CONTENTIEUX', 'PROCEDURES', 'PROCEDURE', '05_PIECES_ADVERSES','05 - Pièces adverses',5),
  (NULL, 4, '07_JURIDIQUE_CONTENTIEUX', 'PROCEDURES', 'PROCEDURE', '06_NOS_PIECES',   '06 - Nos pièces',    6),
  (NULL, 4, '07_JURIDIQUE_CONTENTIEUX', 'PROCEDURES', 'PROCEDURE', '07_ECHEANCIER',   '07 - Échéancier',    7);

-- CONTENTIEUX > N3 = TYPE_CONTENTIEUX
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '07_JURIDIQUE_CONTENTIEUX', 'CONTENTIEUX', '01_LOCATAIRE_IMPAYE',      '01 - Locataire impayé',       1),
  (NULL, 3, '07_JURIDIQUE_CONTENTIEUX', 'CONTENTIEUX', '02_COPROPRIETAIRE_DEBITEUR','02 - Copropriétaire débiteur',2),
  (NULL, 3, '07_JURIDIQUE_CONTENTIEUX', 'CONTENTIEUX', '03_PRESTATAIRE_LITIGE',    '03 - Prestataire litige',     3),
  (NULL, 3, '07_JURIDIQUE_CONTENTIEUX', 'CONTENTIEUX', '04_VOISINAGE',             '04 - Voisinage',              4),
  (NULL, 3, '07_JURIDIQUE_CONTENTIEUX', 'CONTENTIEUX', '99_AUTRE',                 '99 - Autre',                 99);

-- N4 = DOSSIER placeholder pour chaque type
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`, `is_entity_placeholder`)
SELECT NULL, 4, '07_JURIDIQUE_CONTENTIEUX', 'CONTENTIEUX', n3, 'DOSSIER', 'Dossier (entité)', 1, 1 FROM (
  SELECT '01_LOCATAIRE_IMPAYE' AS n3 UNION ALL SELECT '02_COPROPRIETAIRE_DEBITEUR'
  UNION ALL SELECT '03_PRESTATAIRE_LITIGE' UNION ALL SELECT '04_VOISINAGE' UNION ALL SELECT '99_AUTRE'
) p;

-- ARCHIVES > N3 = ANNEE virtualisée
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`, `is_virtual`) VALUES
  (NULL, 3, '07_JURIDIQUE_CONTENTIEUX', 'ARCHIVES', 'ANNEE', 'Année (filtre)', 1, 1);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '07_JURIDIQUE_CONTENTIEUX', 'ARCHIVES', 'ANNEE', 'DOSSIERS_CLOTURES', 'Dossiers clôturés', 1);

-- ════════════════════════════════════════════════════════════════════════
-- D4. COMPLÉTION 08_MARKETING_COMMUNICATION (5 N2)
-- ════════════════════════════════════════════════════════════════════════
-- PHOTOS_COMMERCIALES > N3 = BIEN (placeholder)
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`, `is_entity_placeholder`) VALUES
  (NULL, 3, '08_MARKETING_COMMUNICATION', 'PHOTOS_COMMERCIALES', 'BIEN', 'Bien (entité)', 1, 1);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '08_MARKETING_COMMUNICATION', 'PHOTOS_COMMERCIALES', 'BIEN', '01_ORIGINALES',      '01 - Originales',       1),
  (NULL, 4, '08_MARKETING_COMMUNICATION', 'PHOTOS_COMMERCIALES', 'BIEN', '02_RETOUCHEES',      '02 - Retouchées',       2),
  (NULL, 4, '08_MARKETING_COMMUNICATION', 'PHOTOS_COMMERCIALES', 'BIEN', '03_VITRINE',         '03 - Vitrine',          3),
  (NULL, 4, '08_MARKETING_COMMUNICATION', 'PHOTOS_COMMERCIALES', 'BIEN', '04_PORTAILS',        '04 - Portails',         4),
  (NULL, 4, '08_MARKETING_COMMUNICATION', 'PHOTOS_COMMERCIALES', 'BIEN', '05_RESEAUX_SOCIAUX', '05 - Réseaux sociaux',  5),
  (NULL, 4, '08_MARKETING_COMMUNICATION', 'PHOTOS_COMMERCIALES', 'BIEN', '06_DRONE_360',       '06 - Drone / 360°',     6);

-- ANNONCES > N3 = BIEN (placeholder)
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`, `is_entity_placeholder`) VALUES
  (NULL, 3, '08_MARKETING_COMMUNICATION', 'ANNONCES', 'BIEN', 'Bien (entité)', 1, 1);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '08_MARKETING_COMMUNICATION', 'ANNONCES', 'BIEN', '01_ANNONCE_TEXTE',  '01 - Annonce texte',  1),
  (NULL, 4, '08_MARKETING_COMMUNICATION', 'ANNONCES', 'BIEN', '02_AFFICHE_VITRINE','02 - Affiche vitrine',2),
  (NULL, 4, '08_MARKETING_COMMUNICATION', 'ANNONCES', 'BIEN', '03_FICHE_PORTAILS', '03 - Fiche portails', 3),
  (NULL, 4, '08_MARKETING_COMMUNICATION', 'ANNONCES', 'BIEN', '04_FLYER',          '04 - Flyer',          4),
  (NULL, 4, '08_MARKETING_COMMUNICATION', 'ANNONCES', 'BIEN', '05_VIDEO',          '05 - Vidéo',          5),
  (NULL, 4, '08_MARKETING_COMMUNICATION', 'ANNONCES', 'BIEN', '06_VR_360',         '06 - VR / 360°',      6);

-- SUPPORTS_MARKETING > N3 = TYPE_SUPPORT
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '08_MARKETING_COMMUNICATION', 'SUPPORTS_MARKETING', '01_CARTES_VISITE',         '01 - Cartes de visite',        1),
  (NULL, 3, '08_MARKETING_COMMUNICATION', 'SUPPORTS_MARKETING', '02_PLAQUETTES',            '02 - Plaquettes',              2),
  (NULL, 3, '08_MARKETING_COMMUNICATION', 'SUPPORTS_MARKETING', '03_FLYERS',                '03 - Flyers',                  3),
  (NULL, 3, '08_MARKETING_COMMUNICATION', 'SUPPORTS_MARKETING', '04_ROLLUPS',               '04 - Rollups',                 4),
  (NULL, 3, '08_MARKETING_COMMUNICATION', 'SUPPORTS_MARKETING', '05_KAKEMONOS',             '05 - Kakémonos',               5),
  (NULL, 3, '08_MARKETING_COMMUNICATION', 'SUPPORTS_MARKETING', '06_OBJETS_PUBLICITAIRES',  '06 - Objets publicitaires',    6),
  (NULL, 3, '08_MARKETING_COMMUNICATION', 'SUPPORTS_MARKETING', '07_PRESSE',                '07 - Presse',                  7);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`)
SELECT NULL, 4, '08_MARKETING_COMMUNICATION', 'SUPPORTS_MARKETING', n3, n4, n4_label, n4_pos FROM (
  SELECT '01_CARTES_VISITE' AS n3 UNION ALL SELECT '02_PLAQUETTES' UNION ALL SELECT '03_FLYERS'
  UNION ALL SELECT '04_ROLLUPS' UNION ALL SELECT '05_KAKEMONOS' UNION ALL SELECT '06_OBJETS_PUBLICITAIRES'
  UNION ALL SELECT '07_PRESSE'
) p
CROSS JOIN (
  SELECT 'EN_COURS'           AS n4, 'En cours'             AS n4_label, 1 AS n4_pos
  UNION ALL SELECT 'IMPRIMES',           'Imprimés',           2
  UNION ALL SELECT 'MAQUETTES',          'Maquettes',          3
  UNION ALL SELECT 'FACTURES_IMPRESSION','Factures impression',4
) l;

-- RESEAUX_SOCIAUX > N3 = RESEAU
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '08_MARKETING_COMMUNICATION', 'RESEAUX_SOCIAUX', '01_FACEBOOK',  '01 - Facebook',  1),
  (NULL, 3, '08_MARKETING_COMMUNICATION', 'RESEAUX_SOCIAUX', '02_INSTAGRAM', '02 - Instagram', 2),
  (NULL, 3, '08_MARKETING_COMMUNICATION', 'RESEAUX_SOCIAUX', '03_LINKEDIN',  '03 - LinkedIn',  3),
  (NULL, 3, '08_MARKETING_COMMUNICATION', 'RESEAUX_SOCIAUX', '04_TIKTOK',    '04 - TikTok',    4),
  (NULL, 3, '08_MARKETING_COMMUNICATION', 'RESEAUX_SOCIAUX', '05_YOUTUBE',   '05 - YouTube',   5);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`)
SELECT NULL, 4, '08_MARKETING_COMMUNICATION', 'RESEAUX_SOCIAUX', n3, n4, n4_label, n4_pos FROM (
  SELECT '01_FACEBOOK' AS n3 UNION ALL SELECT '02_INSTAGRAM' UNION ALL SELECT '03_LINKEDIN'
  UNION ALL SELECT '04_TIKTOK' UNION ALL SELECT '05_YOUTUBE'
) p
CROSS JOIN (
  SELECT 'PUBLICATIONS'           AS n4, 'Publications'              AS n4_label, 1 AS n4_pos
  UNION ALL SELECT 'STORIES_REELS',           'Stories / Reels',           2
  UNION ALL SELECT 'CAMPAGNES_PUB',           'Campagnes pub',             3
  UNION ALL SELECT 'STATISTIQUES',            'Statistiques',              4
  UNION ALL SELECT 'COMMENTAIRES_MODERATION', 'Commentaires / Modération', 5
) l;

-- ARCHIVES > ANNEE virtualisée
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`, `is_virtual`) VALUES
  (NULL, 3, '08_MARKETING_COMMUNICATION', 'ARCHIVES', 'ANNEE', 'Année (filtre)', 1, 1);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '08_MARKETING_COMMUNICATION', 'ARCHIVES', 'ANNEE', 'TYPE', 'Type', 1);

-- ════════════════════════════════════════════════════════════════════════
-- D5. COMPLÉTION 09_MODELES_DOCUMENTS (6 N2 — structure uniforme par modèle)
-- ════════════════════════════════════════════════════════════════════════
-- N3 = MODELE (placeholder) sous chaque N2
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`, `is_entity_placeholder`)
SELECT NULL, 3, '09_MODELES_DOCUMENTS', n2, 'MODELE', 'Modèle (entité)', 1, 1 FROM (
  SELECT 'MODELES_GESTION' AS n2 UNION ALL SELECT 'MODELES_SYNDIC'
  UNION ALL SELECT 'MODELES_TRANSACTION' UNION ALL SELECT 'MODELES_RH'
  UNION ALL SELECT 'MODELES_JURIDIQUE' UNION ALL SELECT 'MODELES_COURRIERS'
) p;

-- N4 sous chaque MODELE
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`)
SELECT NULL, 4, '09_MODELES_DOCUMENTS', n2, 'MODELE', n4, n4_label, n4_pos FROM (
  SELECT 'MODELES_GESTION' AS n2 UNION ALL SELECT 'MODELES_SYNDIC'
  UNION ALL SELECT 'MODELES_TRANSACTION' UNION ALL SELECT 'MODELES_RH'
  UNION ALL SELECT 'MODELES_JURIDIQUE' UNION ALL SELECT 'MODELES_COURRIERS'
) p
CROSS JOIN (
  SELECT '01_VERSION_EN_COURS'    AS n4, '01 - Version en cours'    AS n4_label, 1 AS n4_pos
  UNION ALL SELECT '02_VERSIONS_PRECEDENTES', '02 - Versions précédentes', 2
  UNION ALL SELECT '03_DOCUMENTATION_USAGE',  '03 - Documentation d''usage',3
  UNION ALL SELECT '04_EXEMPLES_REMPLIS',     '04 - Exemples remplis',     4
  UNION ALL SELECT '99_ARCHIVES',             '99 - Archives',            99
) l;

-- ════════════════════════════════════════════════════════════════════════
-- D6. COMPLÉTION 10_REFERENTIEL (5 N2)
-- ════════════════════════════════════════════════════════════════════════
-- TYPES_DOCUMENTS > N3 = ACTIVITE
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '10_REFERENTIEL', 'TYPES_DOCUMENTS', '01_GESTION',      '01 - Gestion',      1),
  (NULL, 3, '10_REFERENTIEL', 'TYPES_DOCUMENTS', '02_SYNDIC',       '02 - Syndic',       2),
  (NULL, 3, '10_REFERENTIEL', 'TYPES_DOCUMENTS', '03_TRANSACTION',  '03 - Transaction',  3),
  (NULL, 3, '10_REFERENTIEL', 'TYPES_DOCUMENTS', '04_RH',           '04 - RH',           4),
  (NULL, 3, '10_REFERENTIEL', 'TYPES_DOCUMENTS', '05_COMPTA',       '05 - Compta',       5),
  (NULL, 3, '10_REFERENTIEL', 'TYPES_DOCUMENTS', '06_DIRECTION',    '06 - Direction',    6),
  (NULL, 3, '10_REFERENTIEL', 'TYPES_DOCUMENTS', '07_AGENCE',       '07 - Agence',       7),
  (NULL, 3, '10_REFERENTIEL', 'TYPES_DOCUMENTS', '08_FOURNISSEURS', '08 - Fournisseurs', 8);

-- PARAMETRES_GED > N3 directs
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '10_REFERENTIEL', 'PARAMETRES_GED', '01_NIVEAUX',           '01 - Niveaux',            1),
  (NULL, 3, '10_REFERENTIEL', 'PARAMETRES_GED', '02_REGLES_CLASSEMENT', '02 - Règles classement', 2),
  (NULL, 3, '10_REFERENTIEL', 'PARAMETRES_GED', '03_SEUILS_IA',         '03 - Seuils IA',         3),
  (NULL, 3, '10_REFERENTIEL', 'PARAMETRES_GED', '04_PROMPTS_IA',        '04 - Prompts IA',        4),
  (NULL, 3, '10_REFERENTIEL', 'PARAMETRES_GED', '05_CHARTES_NOMMAGE',   '05 - Chartes nommage',   5);

-- NOMENCLATURES > N3 directs
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '10_REFERENTIEL', 'NOMENCLATURES', '01_TIERS',         '01 - Tiers',         1),
  (NULL, 3, '10_REFERENTIEL', 'NOMENCLATURES', '02_CODES_POSTAUX', '02 - Codes postaux', 2),
  (NULL, 3, '10_REFERENTIEL', 'NOMENCLATURES', '03_COMMUNES',      '03 - Communes',      3),
  (NULL, 3, '10_REFERENTIEL', 'NOMENCLATURES', '04_RUES',          '04 - Rues',          4),
  (NULL, 3, '10_REFERENTIEL', 'NOMENCLATURES', '05_IBAN_BANQUES',  '05 - IBAN banques',  5);

-- CONFIGURATION > N3 directs
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '10_REFERENTIEL', 'CONFIGURATION', '01_TENANT',       '01 - Tenant',       1),
  (NULL, 3, '10_REFERENTIEL', 'CONFIGURATION', '02_SOCIETES',     '02 - Sociétés',     2),
  (NULL, 3, '10_REFERENTIEL', 'CONFIGURATION', '03_AGENCES',      '03 - Agences',      3),
  (NULL, 3, '10_REFERENTIEL', 'CONFIGURATION', '04_UTILISATEURS', '04 - Utilisateurs', 4),
  (NULL, 3, '10_REFERENTIEL', 'CONFIGURATION', '05_ROLES',        '05 - Rôles',        5),
  (NULL, 3, '10_REFERENTIEL', 'CONFIGURATION', '06_PERMISSIONS',  '06 - Permissions',  6);

-- GUIDES_MBI > N3 = MODULE
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '10_REFERENTIEL', 'GUIDES_MBI', '01_GED',         '01 - GED',         1),
  (NULL, 3, '10_REFERENTIEL', 'GUIDES_MBI', '02_RH',          '02 - RH',          2),
  (NULL, 3, '10_REFERENTIEL', 'GUIDES_MBI', '03_GESTION',     '03 - Gestion',     3),
  (NULL, 3, '10_REFERENTIEL', 'GUIDES_MBI', '04_SYNDIC',      '04 - Syndic',      4),
  (NULL, 3, '10_REFERENTIEL', 'GUIDES_MBI', '05_TRANSACTION', '05 - Transaction', 5),
  (NULL, 3, '10_REFERENTIEL', 'GUIDES_MBI', '06_COMPTA',      '06 - Compta',      6),
  (NULL, 3, '10_REFERENTIEL', 'GUIDES_MBI', '07_ADMIN',       '07 - Admin',       7),
  (NULL, 3, '10_REFERENTIEL', 'GUIDES_MBI', '08_MAIL',        '08 - Mail',        8);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`)
SELECT NULL, 4, '10_REFERENTIEL', 'GUIDES_MBI', n3, n4, n4_label, n4_pos FROM (
  SELECT '01_GED' AS n3 UNION ALL SELECT '02_RH' UNION ALL SELECT '03_GESTION'
  UNION ALL SELECT '04_SYNDIC' UNION ALL SELECT '05_TRANSACTION' UNION ALL SELECT '06_COMPTA'
  UNION ALL SELECT '07_ADMIN' UNION ALL SELECT '08_MAIL'
) p
CROSS JOIN (
  SELECT 'TUTORIELS_PDF' AS n4, 'Tutoriels PDF' AS n4_label, 1 AS n4_pos
  UNION ALL SELECT 'VIDEOS',         'Vidéos',         2
  UNION ALL SELECT 'FAQ',            'FAQ',            3
  UNION ALL SELECT 'RELEASE_NOTES',  'Release notes',  4
  UNION ALL SELECT 'GUIDES_RAPIDES', 'Guides rapides', 5
) l;

-- ════════════════════════════════════════════════════════════════════════
-- D7. COMPLÉTION 11_MAILS_COMMUNICATIONS (6 N2)
-- ════════════════════════════════════════════════════════════════════════
-- A_TRAITER > N3 = PRIORITE
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '11_MAILS_COMMUNICATIONS', 'A_TRAITER', '01_URGENT', '01 - Urgent', 1),
  (NULL, 3, '11_MAILS_COMMUNICATIONS', 'A_TRAITER', '02_NORMAL', '02 - Normal', 2),
  (NULL, 3, '11_MAILS_COMMUNICATIONS', 'A_TRAITER', '03_FAIBLE', '03 - Faible', 3);

-- MAILS_ENTRANTS > N3 = BOITE_MAIL (placeholder, instance auto par user.email)
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`, `is_entity_placeholder`) VALUES
  (NULL, 3, '11_MAILS_COMMUNICATIONS', 'MAILS_ENTRANTS', 'BOITE_MAIL', 'Boîte mail (par user)', 1, 1);

-- N4 = CONVERSATION (placeholder, par sujet/thread)
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`, `is_entity_placeholder`) VALUES
  (NULL, 4, '11_MAILS_COMMUNICATIONS', 'MAILS_ENTRANTS', 'BOITE_MAIL', 'CONVERSATION', 'Conversation (sujet)', 1, 1);

-- MAILS_SORTANTS > même structure
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`, `is_entity_placeholder`) VALUES
  (NULL, 3, '11_MAILS_COMMUNICATIONS', 'MAILS_SORTANTS', 'BOITE_MAIL', 'Boîte mail (par user)', 1, 1);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`, `is_entity_placeholder`) VALUES
  (NULL, 4, '11_MAILS_COMMUNICATIONS', 'MAILS_SORTANTS', 'BOITE_MAIL', 'CONVERSATION', 'Conversation (sujet)', 1, 1);

-- PIECES_JOINTES > N3 = TYPE_PJ
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '11_MAILS_COMMUNICATIONS', 'PIECES_JOINTES', '01_PDF',          '01 - PDF',          1),
  (NULL, 3, '11_MAILS_COMMUNICATIONS', 'PIECES_JOINTES', '02_IMAGES',       '02 - Images',       2),
  (NULL, 3, '11_MAILS_COMMUNICATIONS', 'PIECES_JOINTES', '03_BUREAUTIQUE',  '03 - Bureautique',  3),
  (NULL, 3, '11_MAILS_COMMUNICATIONS', 'PIECES_JOINTES', '04_ARCHIVES_ZIP', '04 - Archives ZIP', 4),
  (NULL, 3, '11_MAILS_COMMUNICATIONS', 'PIECES_JOINTES', '99_AUTRES',       '99 - Autres',      99);

-- CONVERSATIONS (vue agrégée cross-boîte) > N3 = CONVERSATION (placeholder)
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`, `is_entity_placeholder`) VALUES
  (NULL, 3, '11_MAILS_COMMUNICATIONS', 'CONVERSATIONS', 'CONVERSATION', 'Conversation (sujet)', 1, 1);

-- ARCHIVES > ANNEE virtualisée
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`, `is_virtual`) VALUES
  (NULL, 3, '11_MAILS_COMMUNICATIONS', 'ARCHIVES', 'ANNEE', 'Année (filtre)', 1, 1);

-- ════════════════════════════════════════════════════════════════════════
-- D8. COMPLÉTION 12_ARCHIVES (6 N2 — squelette minimaliste : ANNEE virtualisée + TYPE)
-- ════════════════════════════════════════════════════════════════════════
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`, `is_virtual`)
SELECT NULL, 3, '12_ARCHIVES', n2, 'ANNEE', 'Année (filtre)', 1, 1 FROM (
  SELECT 'GESTION_LOCATIVE' AS n2 UNION ALL SELECT 'SYNDIC' UNION ALL SELECT 'TRANSACTION'
  UNION ALL SELECT 'RH' UNION ALL SELECT 'COMPTABILITE' UNION ALL SELECT 'AUTRES'
) p;

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`)
SELECT NULL, 4, '12_ARCHIVES', n2, 'ANNEE', 'TYPE', 'Type', 1 FROM (
  SELECT 'GESTION_LOCATIVE' AS n2 UNION ALL SELECT 'SYNDIC' UNION ALL SELECT 'TRANSACTION'
  UNION ALL SELECT 'RH' UNION ALL SELECT 'COMPTABILITE' UNION ALL SELECT 'AUTRES'
) p;

-- ════════════════════════════════════════════════════════════════════════
-- E. VIRTUALISATION codes ANNEE existants (filtre UI dérivé de date_document)
-- ════════════════════════════════════════════════════════════════════════
-- Marque tous les codes 'ANNEE' (anywhere, any niveau) is_virtual=1
-- → les UI ne créeront plus de dossier physique ANNEE, mais un filtre temporel
UPDATE `ged_level_codes`
SET `is_virtual` = 1
WHERE `code` = 'ANNEE';

-- ════════════════════════════════════════════════════════════════════════
-- G. VIRTUALISATION doublons d'inbox (99_A_CLASSER_DIRECTION → 99_SYSTEME > IMPORTS)
-- ════════════════════════════════════════════════════════════════════════
UPDATE `ged_level_codes`
SET `is_virtual` = 1
WHERE `level_number` = 2 AND `parent_n1` = '01_DIRECTION' AND `code` = '99_A_CLASSER_DIRECTION';
SQL,
];
