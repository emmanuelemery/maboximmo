<?php
/**
 * Migration v1.1 — Seed des niveaux N1/N2/N3 + échantillon N4/N5
 *
 * Crée la table de référence ged_level_codes (N1→N6) qui sert de catalogue
 * pour la cascade de boutons de la page d'import. Indépendant de
 * ged_folder_templates (qui pilote l'instanciation par entité).
 *
 * AJOUT uniquement.
 *
 * Niveaux validés (cf. brief v1.1) :
 *   - N1 : 13 modules métier
 *   - N2 : par module (N1)
 *   - N3-N5 : seedé pour SYNDIC/IMMEUBLES + GESTION_LOCATIVE/PROPRIETAIRES
 *            (les plus critiques). Les autres seront ajoutés via
 *            super_admin_ged_niveaux.php (UI gestion niveaux).
 */

return [
    'id'          => '20260502_ged_v1_10_levels_seed',
    'title'       => 'Ma GED Box V1.1 — catalogue ged_level_codes (N1-N6) + seed N1/N2/N3 + échantillon N4/N5',
    'description' => "Crée la table de référence ged_level_codes utilisée par super_admin_ged_import.php (cascade N1→N6) et super_admin_ged_niveaux.php (gestion). Seed complet N1 (13 modules) + N2 par module + N3 base + échantillon N4/N5 (SYNDIC/IMMEUBLES, GESTION_LOCATIVE/PROPRIETAIRES). N6 reste libre (pas de référentiel obligatoire).",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `ged_level_codes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` INT UNSIGNED NULL,
  `level_number` TINYINT UNSIGNED NOT NULL COMMENT '1 a 6',
  `parent_n1` VARCHAR(80) NULL,
  `parent_n2` VARCHAR(80) NULL,
  `parent_n3` VARCHAR(80) NULL,
  `parent_n4` VARCHAR(80) NULL,
  `code` VARCHAR(80) NOT NULL COMMENT 'Identifiant stable (ex: 04_SYNDIC, IMMEUBLES, AG)',
  `label` VARCHAR(180) NOT NULL COMMENT 'Libellé humain affiché dans le bouton',
  `position` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ged_level_codes_path` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`),
  INDEX `idx_ged_level_codes_lookup` (`level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ────────── N1 (13 modules métier) ──────────
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `code`, `label`, `position`) VALUES
  (NULL, 1, '01_AGENCE',                 '01 - Agence',                  1),
  (NULL, 1, '02_RH',                     '02 - Ressources humaines',     2),
  (NULL, 1, '03_GESTION_LOCATIVE',       '03 - Gestion locative',        3),
  (NULL, 1, '04_SYNDIC',                 '04 - Syndic',                  4),
  (NULL, 1, '05_TRANSACTION',            '05 - Transaction',             5),
  (NULL, 1, '06_COMPTABILITE',           '06 - Comptabilité',            6),
  (NULL, 1, '07_JURIDIQUE_CONTENTIEUX',  '07 - Juridique & Contentieux', 7),
  (NULL, 1, '08_MARKETING_COMMUNICATION','08 - Marketing & Communication', 8),
  (NULL, 1, '09_MODELES_DOCUMENTS',      '09 - Modèles de documents',    9),
  (NULL, 1, '10_REFERENTIEL',            '10 - Référentiel',            10),
  (NULL, 1, '11_MAILS_COMMUNICATIONS',   '11 - Mails & Communications', 11),
  (NULL, 1, '12_ARCHIVES',               '12 - Archives',               12),
  (NULL, 1, '99_SYSTEME',                '99 - Système',                99);

-- ────────── N2 sous 01_AGENCE ──────────
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `code`, `label`, `position`) VALUES
  (NULL, 2, '01_AGENCE', 'PROCEDURES',              'Procédures',              1),
  (NULL, 2, '01_AGENCE', 'FORMATIONS',              'Formations',              2),
  (NULL, 2, '01_AGENCE', 'COMMUNICATION_INTERNE',   'Communication interne',   3),
  (NULL, 2, '01_AGENCE', 'FOURNISSEURS_AGENCE',     'Fournisseurs agence',     4),
  (NULL, 2, '01_AGENCE', 'OUTILS_LOGICIELS',        'Outils & logiciels',      5),
  (NULL, 2, '01_AGENCE', 'DOCUMENTS_ADMINISTRATIFS','Documents administratifs',6);

-- ────────── N2 sous 02_RH ──────────
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `code`, `label`, `position`) VALUES
  (NULL, 2, '02_RH', 'COLLABORATEURS',  'Collaborateurs',  1),
  (NULL, 2, '02_RH', 'SALAIRES',        'Salaires',        2),
  (NULL, 2, '02_RH', 'CONGES',          'Congés',          3),
  (NULL, 2, '02_RH', 'ENTRETIENS',      'Entretiens',      4),
  (NULL, 2, '02_RH', 'DOCUMENTS_RH',    'Documents RH',    5);

-- ────────── N2 sous 03_GESTION_LOCATIVE ──────────
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `code`, `label`, `position`) VALUES
  (NULL, 2, '03_GESTION_LOCATIVE', 'PROPRIETAIRES',             'Propriétaires',              1),
  (NULL, 2, '03_GESTION_LOCATIVE', 'BIENS',                     'Biens',                      2),
  (NULL, 2, '03_GESTION_LOCATIVE', 'LOCATAIRES',                'Locataires',                 3),
  (NULL, 2, '03_GESTION_LOCATIVE', 'MANDATS',                   'Mandats',                    4),
  (NULL, 2, '03_GESTION_LOCATIVE', 'LOCATION_CANDIDATURES',     'Candidatures location',      5),
  (NULL, 2, '03_GESTION_LOCATIVE', 'BAUX',                      'Baux',                       6),
  (NULL, 2, '03_GESTION_LOCATIVE', 'ETATS_DES_LIEUX',           'États des lieux',            7),
  (NULL, 2, '03_GESTION_LOCATIVE', 'COMPTABILITE_GESTION',      'Comptabilité gestion',       8),
  (NULL, 2, '03_GESTION_LOCATIVE', 'FISCALITE',                 'Fiscalité',                  9),
  (NULL, 2, '03_GESTION_LOCATIVE', 'FOURNISSEURS_INTERVENTIONS','Fournisseurs & interventions', 10),
  (NULL, 2, '03_GESTION_LOCATIVE', 'TRAVAUX_SINISTRES',         'Travaux & sinistres',        11),
  (NULL, 2, '03_GESTION_LOCATIVE', 'CONTENTIEUX_GLI',           'Contentieux & GLI',          12),
  (NULL, 2, '03_GESTION_LOCATIVE', 'ASSURANCES',                'Assurances',                 13),
  (NULL, 2, '03_GESTION_LOCATIVE', 'DIAGNOSTICS',               'Diagnostics',                14),
  (NULL, 2, '03_GESTION_LOCATIVE', 'COURRIERS_MAILS',           'Courriers & mails',          15),
  (NULL, 2, '03_GESTION_LOCATIVE', 'ARCHIVES',                  'Archives',                   16);

-- ────────── N2 sous 04_SYNDIC ──────────
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `code`, `label`, `position`) VALUES
  (NULL, 2, '04_SYNDIC', 'IMMEUBLES', 'Immeubles', 1);

-- ────────── N2 sous 05_TRANSACTION ──────────
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `code`, `label`, `position`) VALUES
  (NULL, 2, '05_TRANSACTION', 'PROPRIETAIRES',         'Propriétaires',         1),
  (NULL, 2, '05_TRANSACTION', 'ESTIMATIONS',           'Estimations',           2),
  (NULL, 2, '05_TRANSACTION', 'APPORTEURS_AFFAIRES',   'Apporteurs d''affaires',3),
  (NULL, 2, '05_TRANSACTION', 'CADASTRES',             'Cadastres',             4),
  (NULL, 2, '05_TRANSACTION', 'ARCHIVES',              'Archives',              5);

-- ────────── N2 sous 06_COMPTABILITE ──────────
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `code`, `label`, `position`) VALUES
  (NULL, 2, '06_COMPTABILITE', 'COMPTABILITE_GENERALE','Comptabilité générale',1),
  (NULL, 2, '06_COMPTABILITE', 'BANQUES',              'Banques',              2),
  (NULL, 2, '06_COMPTABILITE', 'FOURNISSEURS',         'Fournisseurs',         3),
  (NULL, 2, '06_COMPTABILITE', 'CLIENTS',              'Clients',              4),
  (NULL, 2, '06_COMPTABILITE', 'DECLARATIONS',         'Déclarations',         5),
  (NULL, 2, '06_COMPTABILITE', 'ARCHIVES',             'Archives',             6);

-- ────────── N2 sous 07_JURIDIQUE_CONTENTIEUX ──────────
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `code`, `label`, `position`) VALUES
  (NULL, 2, '07_JURIDIQUE_CONTENTIEUX', 'DOSSIERS_JURIDIQUES','Dossiers juridiques',1),
  (NULL, 2, '07_JURIDIQUE_CONTENTIEUX', 'PROCEDURES',         'Procédures',         2),
  (NULL, 2, '07_JURIDIQUE_CONTENTIEUX', 'CONTENTIEUX',        'Contentieux',        3),
  (NULL, 2, '07_JURIDIQUE_CONTENTIEUX', 'ARCHIVES',           'Archives',           4);

-- ────────── N2 sous 08_MARKETING_COMMUNICATION ──────────
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `code`, `label`, `position`) VALUES
  (NULL, 2, '08_MARKETING_COMMUNICATION', 'PHOTOS_COMMERCIALES','Photos commerciales',1),
  (NULL, 2, '08_MARKETING_COMMUNICATION', 'ANNONCES',           'Annonces',           2),
  (NULL, 2, '08_MARKETING_COMMUNICATION', 'SUPPORTS_MARKETING', 'Supports marketing', 3),
  (NULL, 2, '08_MARKETING_COMMUNICATION', 'RESEAUX_SOCIAUX',    'Réseaux sociaux',    4),
  (NULL, 2, '08_MARKETING_COMMUNICATION', 'ARCHIVES',           'Archives',           5);

-- ────────── N2 sous 09_MODELES_DOCUMENTS ──────────
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `code`, `label`, `position`) VALUES
  (NULL, 2, '09_MODELES_DOCUMENTS', 'MODELES_GESTION',     'Modèles gestion',     1),
  (NULL, 2, '09_MODELES_DOCUMENTS', 'MODELES_SYNDIC',      'Modèles syndic',      2),
  (NULL, 2, '09_MODELES_DOCUMENTS', 'MODELES_TRANSACTION', 'Modèles transaction', 3),
  (NULL, 2, '09_MODELES_DOCUMENTS', 'MODELES_RH',          'Modèles RH',          4),
  (NULL, 2, '09_MODELES_DOCUMENTS', 'MODELES_JURIDIQUE',   'Modèles juridique',   5),
  (NULL, 2, '09_MODELES_DOCUMENTS', 'MODELES_COURRIERS',   'Modèles courriers',   6);

-- ────────── N2 sous 10_REFERENTIEL ──────────
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `code`, `label`, `position`) VALUES
  (NULL, 2, '10_REFERENTIEL', 'TYPES_DOCUMENTS', 'Types de documents', 1),
  (NULL, 2, '10_REFERENTIEL', 'PARAMETRES_GED',  'Paramètres GED',     2),
  (NULL, 2, '10_REFERENTIEL', 'NOMENCLATURES',   'Nomenclatures',      3),
  (NULL, 2, '10_REFERENTIEL', 'CONFIGURATION',   'Configuration',      4),
  (NULL, 2, '10_REFERENTIEL', 'GUIDES_MBI',      'Guides MBI',         5);

-- ────────── N2 sous 11_MAILS_COMMUNICATIONS ──────────
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `code`, `label`, `position`) VALUES
  (NULL, 2, '11_MAILS_COMMUNICATIONS', 'A_TRAITER',      'À traiter',          1),
  (NULL, 2, '11_MAILS_COMMUNICATIONS', 'MAILS_ENTRANTS', 'Mails entrants',     2),
  (NULL, 2, '11_MAILS_COMMUNICATIONS', 'MAILS_SORTANTS', 'Mails sortants',     3),
  (NULL, 2, '11_MAILS_COMMUNICATIONS', 'PIECES_JOINTES', 'Pièces jointes',     4),
  (NULL, 2, '11_MAILS_COMMUNICATIONS', 'CONVERSATIONS',  'Conversations',      5),
  (NULL, 2, '11_MAILS_COMMUNICATIONS', 'ARCHIVES',       'Archives',           6);

-- ────────── N2 sous 12_ARCHIVES ──────────
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `code`, `label`, `position`) VALUES
  (NULL, 2, '12_ARCHIVES', 'GESTION_LOCATIVE','Gestion locative',1),
  (NULL, 2, '12_ARCHIVES', 'SYNDIC',          'Syndic',          2),
  (NULL, 2, '12_ARCHIVES', 'TRANSACTION',     'Transaction',     3),
  (NULL, 2, '12_ARCHIVES', 'RH',              'RH',              4),
  (NULL, 2, '12_ARCHIVES', 'COMPTABILITE',    'Comptabilité',    5),
  (NULL, 2, '12_ARCHIVES', 'AUTRES',          'Autres',          6);

-- ────────── N2 sous 99_SYSTEME ──────────
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `code`, `label`, `position`) VALUES
  (NULL, 2, '99_SYSTEME', 'CORBEILLE','Corbeille',1),
  (NULL, 2, '99_SYSTEME', 'IMPORTS',  'Imports',  2),
  (NULL, 2, '99_SYSTEME', 'LOGS',     'Logs',     3),
  (NULL, 2, '99_SYSTEME', 'BACKUPS',  'Backups',  4);

-- ════════════════════════════════════════════════════════════════════════
-- N3 / N4 / N5 — ÉCHANTILLONS représentatifs (extensible via UI niveaux)
-- ════════════════════════════════════════════════════════════════════════

-- ────────── 04_SYNDIC > IMMEUBLES > N3 ──────────
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'Immeuble (entité)', 1);

-- ────────── 04_SYNDIC > IMMEUBLES > IMMEUBLE > N4 ──────────
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'FICHE_IMMEUBLE',         'Fiche immeuble',         1),
  (NULL, 4, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'ADMINISTRATIF',          'Administratif',          2),
  (NULL, 4, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'JURIDIQUE',              'Juridique',              3),
  (NULL, 4, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'AG',                     'Assemblée générale',     4),
  (NULL, 4, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'CONSEIL_SYNDICAL',       'Conseil syndical',       5),
  (NULL, 4, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'COMPTABILITE',           'Comptabilité',           6),
  (NULL, 4, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'BANQUE',                 'Banque',                 7),
  (NULL, 4, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'CONTRATS',               'Contrats',               8),
  (NULL, 4, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'FOURNISSEURS',           'Fournisseurs',           9),
  (NULL, 4, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'DEVIS',                  'Devis',                  10),
  (NULL, 4, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'TRAVAUX',                'Travaux',                11),
  (NULL, 4, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'SINISTRES',              'Sinistres',              12),
  (NULL, 4, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'ASSURANCES',             'Assurances',             13),
  (NULL, 4, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'MUTATIONS',              'Mutations',              14),
  (NULL, 4, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'COURRIERS',              'Courriers',              15),
  (NULL, 4, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'MAILS',                  'Mails',                  16),
  (NULL, 4, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'PHOTOS_TECHNIQUES',      'Photos techniques',      17),
  (NULL, 4, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'PHOTOS_COMMERCIALES',    'Photos commerciales',    18),
  (NULL, 4, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'ACCES_CLES_BADGES',      'Accès / clés / badges',  19),
  (NULL, 4, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'COPROPRIETAIRES_OCCUPANTS', 'Copropriétaires/Occupants', 20),
  (NULL, 4, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'CONTENTIEUX',            'Contentieux',            21),
  (NULL, 4, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'CONTROLES_DIAGNOSTICS',  'Contrôles & diagnostics',22),
  (NULL, 4, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'ARCHIVES',               'Archives',               23),
  (NULL, 4, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'A_CLASSER',              'À classer',              24);

-- ────────── 04_SYNDIC > IMMEUBLES > IMMEUBLE > AG > N5 ──────────
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`) VALUES
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'AG', 'ANNEE',            'Année',              1),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'AG', 'CONVOCATION',      'Convocation',        2),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'AG', 'PV',               'PV',                 3),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'AG', 'FEUILLE_PRESENCE', 'Feuille de présence',4),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'AG', 'POUVOIRS',         'Pouvoirs',           5),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'AG', 'ANNEXES',          'Annexes',            6),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'AG', 'BUDGETS',          'Budgets',            7),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'AG', 'ENVOI_DIFFUSION',  'Envoi/Diffusion',    8);

-- ────────── 04_SYNDIC > IMMEUBLES > IMMEUBLE > CONTRATS > N5 ──────────
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`) VALUES
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'CONTRATS', 'ASCENSEUR',          'Ascenseur',         1),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'CONTRATS', 'NETTOYAGE',          'Nettoyage',         2),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'CONTRATS', 'ESPACES_VERTS',      'Espaces verts',     3),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'CONTRATS', 'CHAUFFAGE',          'Chauffage',         4),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'CONTRATS', 'EAU',                'Eau',               5),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'CONTRATS', 'ELECTRICITE',        'Électricité',       6),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'CONTRATS', 'PORTAIL_INTERPHONE', 'Portail/Interphone',7),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'CONTRATS', 'ASSURANCE',          'Assurance',         8),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'CONTRATS', 'MAINTENANCE',        'Maintenance',       9),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'CONTRATS', 'ANCIENS_CONTRATS',   'Anciens contrats',  10);

-- ────────── 03_GESTION_LOCATIVE > PROPRIETAIRES > N3 ──────────
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '03_GESTION_LOCATIVE', 'PROPRIETAIRES', 'PROPRIETAIRE', 'Propriétaire (entité)', 1);

-- ────────── 03_GESTION_LOCATIVE > PROPRIETAIRES > PROPRIETAIRE > N4 ──────────
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '03_GESTION_LOCATIVE', 'PROPRIETAIRES', 'PROPRIETAIRE', 'ADMINISTRATIF', 'Administratif', 1),
  (NULL, 4, '03_GESTION_LOCATIVE', 'PROPRIETAIRES', 'PROPRIETAIRE', 'MANDATS',       'Mandats',       2),
  (NULL, 4, '03_GESTION_LOCATIVE', 'PROPRIETAIRES', 'PROPRIETAIRE', 'FISCALITE',     'Fiscalité',     3),
  (NULL, 4, '03_GESTION_LOCATIVE', 'PROPRIETAIRES', 'PROPRIETAIRE', 'COMPTABILITE',  'Comptabilité',  4),
  (NULL, 4, '03_GESTION_LOCATIVE', 'PROPRIETAIRES', 'PROPRIETAIRE', 'BIENS',         'Biens',         5),
  (NULL, 4, '03_GESTION_LOCATIVE', 'PROPRIETAIRES', 'PROPRIETAIRE', 'COURRIERS',     'Courriers',     6),
  (NULL, 4, '03_GESTION_LOCATIVE', 'PROPRIETAIRES', 'PROPRIETAIRE', 'MAILS',         'Mails',         7),
  (NULL, 4, '03_GESTION_LOCATIVE', 'PROPRIETAIRES', 'PROPRIETAIRE', 'CONTENTIEUX',   'Contentieux',   8),
  (NULL, 4, '03_GESTION_LOCATIVE', 'PROPRIETAIRES', 'PROPRIETAIRE', 'ARCHIVES',      'Archives',      9);

-- ────────── 03_GESTION_LOCATIVE > BIENS > N3 ──────────
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '03_GESTION_LOCATIVE', 'BIENS', 'BIEN', 'Bien (entité)', 1);

-- ────────── 03_GESTION_LOCATIVE > BIENS > BIEN > N4 ──────────
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '03_GESTION_LOCATIVE', 'BIENS', 'BIEN', 'ADMINISTRATIF',         'Administratif',         1),
  (NULL, 4, '03_GESTION_LOCATIVE', 'BIENS', 'BIEN', 'PROPRIETAIRE',          'Propriétaire',          2),
  (NULL, 4, '03_GESTION_LOCATIVE', 'BIENS', 'BIEN', 'LOCATAIRES',            'Locataires',            3),
  (NULL, 4, '03_GESTION_LOCATIVE', 'BIENS', 'BIEN', 'MANDATS',               'Mandats',               4),
  (NULL, 4, '03_GESTION_LOCATIVE', 'BIENS', 'BIEN', 'BAUX',                  'Baux',                  5),
  (NULL, 4, '03_GESTION_LOCATIVE', 'BIENS', 'BIEN', 'ETATS_DES_LIEUX',       'États des lieux',       6),
  (NULL, 4, '03_GESTION_LOCATIVE', 'BIENS', 'BIEN', 'DIAGNOSTICS',           'Diagnostics',           7),
  (NULL, 4, '03_GESTION_LOCATIVE', 'BIENS', 'BIEN', 'PHOTOS',                'Photos',                8),
  (NULL, 4, '03_GESTION_LOCATIVE', 'BIENS', 'BIEN', 'TRAVAUX_INTERVENTIONS', 'Travaux/Interventions', 9),
  (NULL, 4, '03_GESTION_LOCATIVE', 'BIENS', 'BIEN', 'SINISTRES',             'Sinistres',             10),
  (NULL, 4, '03_GESTION_LOCATIVE', 'BIENS', 'BIEN', 'ASSURANCES',            'Assurances',            11),
  (NULL, 4, '03_GESTION_LOCATIVE', 'BIENS', 'BIEN', 'FISCALITE',             'Fiscalité',             12),
  (NULL, 4, '03_GESTION_LOCATIVE', 'BIENS', 'BIEN', 'COMPTABILITE',          'Comptabilité',          13),
  (NULL, 4, '03_GESTION_LOCATIVE', 'BIENS', 'BIEN', 'COURRIERS',             'Courriers',             14),
  (NULL, 4, '03_GESTION_LOCATIVE', 'BIENS', 'BIEN', 'MAILS',                 'Mails',                 15),
  (NULL, 4, '03_GESTION_LOCATIVE', 'BIENS', 'BIEN', 'ARCHIVES',              'Archives',              16);

-- ────────── 03_GESTION_LOCATIVE > LOCATAIRES > N3 ──────────
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '03_GESTION_LOCATIVE', 'LOCATAIRES', 'LOCATAIRE', 'Locataire (entité)', 1);

-- ────────── 03_GESTION_LOCATIVE > LOCATAIRES > LOCATAIRE > N4 ──────────
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '03_GESTION_LOCATIVE', 'LOCATAIRES', 'LOCATAIRE', 'DOSSIER_CANDIDATURE','Dossier candidature',1),
  (NULL, 4, '03_GESTION_LOCATIVE', 'LOCATAIRES', 'LOCATAIRE', 'IDENTITE',           'Identité',           2),
  (NULL, 4, '03_GESTION_LOCATIVE', 'LOCATAIRES', 'LOCATAIRE', 'REVENUS_GARANTS',    'Revenus & garants',  3),
  (NULL, 4, '03_GESTION_LOCATIVE', 'LOCATAIRES', 'LOCATAIRE', 'BAIL',               'Bail',               4),
  (NULL, 4, '03_GESTION_LOCATIVE', 'LOCATAIRES', 'LOCATAIRE', 'ETATS_DES_LIEUX',    'États des lieux',    5),
  (NULL, 4, '03_GESTION_LOCATIVE', 'LOCATAIRES', 'LOCATAIRE', 'PAIEMENTS',          'Paiements',          6),
  (NULL, 4, '03_GESTION_LOCATIVE', 'LOCATAIRES', 'LOCATAIRE', 'CAF_APL',            'CAF / APL',          7),
  (NULL, 4, '03_GESTION_LOCATIVE', 'LOCATAIRES', 'LOCATAIRE', 'COURRIERS',          'Courriers',          8),
  (NULL, 4, '03_GESTION_LOCATIVE', 'LOCATAIRES', 'LOCATAIRE', 'MAILS',              'Mails',              9),
  (NULL, 4, '03_GESTION_LOCATIVE', 'LOCATAIRES', 'LOCATAIRE', 'CONTENTIEUX',        'Contentieux',        10),
  (NULL, 4, '03_GESTION_LOCATIVE', 'LOCATAIRES', 'LOCATAIRE', 'SORTIE_LOCATAIRE',   'Sortie locataire',   11),
  (NULL, 4, '03_GESTION_LOCATIVE', 'LOCATAIRES', 'LOCATAIRE', 'ARCHIVES',           'Archives',           12);
SQL,
];
