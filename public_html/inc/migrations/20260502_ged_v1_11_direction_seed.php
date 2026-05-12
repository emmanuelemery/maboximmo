<?php
/**
 * Migration v1.1 — Seed COMPLET de la branche 01_DIRECTION (N1 → N5)
 *
 * Ajoute :
 *   - N1 : 01_DIRECTION (en plus des 13 modules déjà seedés)
 *   - N2 : 24 sous-rubriques (01_SOCIETES, 02_BANQUES_FINANCEMENTS, …, 21_COFFRE_SECURISE,
 *          22_MAILS_DIRECTION, 23_ARCHIVES_DIRECTION, 99_A_CLASSER_DIRECTION)
 *   - N3/N4/N5 : structures détaillées par rubrique (cf. brief)
 *
 * IMPORTANT :
 *   - 21_COFFRE_SECURISE > ACCES = lien logique vers super_admin_coffre_acces.php
 *     (pas de fichiers en GED), géré côté UI.
 *   - N6 reste libre (pas seedé).
 *
 * Idempotent (INSERT IGNORE sur uk_ged_level_codes_path).
 * AJOUT uniquement.
 */

return [
    'id'          => '20260502_ged_v1_11_direction_seed',
    'title'       => 'Ma GED Box V1.1 — seed COMPLET branche 01_DIRECTION (N1+N2 24 rubriques + N3/N4/N5 par rubrique)',
    'description' => "Ajoute la branche complète 01_DIRECTION dans ged_level_codes : 1 N1 (01_DIRECTION en position 0 pour l'afficher en premier) + 24 N2 (01_SOCIETES → 99_A_CLASSER_DIRECTION) + tous les N3/N4/N5 par rubrique (sociétés, banques, comptabilité, juridique, assurances, contrats, patrimoine, RH direction, organisation, réunions, projets MBI, informatique, téléphonie, communication, formations, réseaux métiers, véhicules, photos, perso, contentieux, COFFRE_SECURISE, mails, archives, à classer). N6 libre. 21_COFFRE_SECURISE/ACCES = lien vers super_admin_coffre_acces.php (interdiction stocker mots de passe en GED).",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
-- ════════════════════════════════════════════════════════════════════════
-- N1 — 01_DIRECTION (position 0 pour le placer en premier dans la cascade)
-- ════════════════════════════════════════════════════════════════════════
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `code`, `label`, `position`) VALUES
  (NULL, 1, '01_DIRECTION', '👑 Direction', 0);

-- ════════════════════════════════════════════════════════════════════════
-- N2 — 24 sous-rubriques sous 01_DIRECTION
-- ════════════════════════════════════════════════════════════════════════
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `code`, `label`, `position`) VALUES
  (NULL, 2, '01_DIRECTION', '01_SOCIETES',                'Sociétés',                  1),
  (NULL, 2, '01_DIRECTION', '02_BANQUES_FINANCEMENTS',    'Banques & Financements',    2),
  (NULL, 2, '01_DIRECTION', '03_COMPTABILITE_DIRECTION',  'Comptabilité direction',    3),
  (NULL, 2, '01_DIRECTION', '04_JURIDIQUE_STRATEGIQUE',   'Juridique stratégique',     4),
  (NULL, 2, '01_DIRECTION', '05_ASSURANCES_DIRECTION',    'Assurances direction',      5),
  (NULL, 2, '01_DIRECTION', '06_CONTRATS_STRATEGIQUES',   'Contrats stratégiques',     6),
  (NULL, 2, '01_DIRECTION', '07_PATRIMOINE_ACQUISITIONS', 'Patrimoine & acquisitions', 7),
  (NULL, 2, '01_DIRECTION', '08_RH_DIRECTION',            'RH direction',              8),
  (NULL, 2, '01_DIRECTION', '09_ORGANISATION_INTERNE',    'Organisation interne',      9),
  (NULL, 2, '01_DIRECTION', '10_REUNIONS_DIRECTION',      'Réunions direction',       10),
  (NULL, 2, '01_DIRECTION', '11_PROJETS_MBI',             'Projets MBI',              11),
  (NULL, 2, '01_DIRECTION', '12_INFORMATIQUE',            'Informatique',             12),
  (NULL, 2, '01_DIRECTION', '13_TELEPHONIE',              'Téléphonie',               13),
  (NULL, 2, '01_DIRECTION', '14_COMMUNICATION_GRAPHISME', 'Communication & graphisme',14),
  (NULL, 2, '01_DIRECTION', '15_FORMATIONS',              'Formations',               15),
  (NULL, 2, '01_DIRECTION', '16_RESEAUX_METIERS',         'Réseaux métiers',          16),
  (NULL, 2, '01_DIRECTION', '17_VEHICULES',               'Véhicules',                17),
  (NULL, 2, '01_DIRECTION', '18_PHOTOS_DIRECTION',        'Photos direction',         18),
  (NULL, 2, '01_DIRECTION', '19_DOCUMENTS_PERSONNELS',    'Documents personnels',     19),
  (NULL, 2, '01_DIRECTION', '20_CONTENTIEUX_SENSIBLES',   'Contentieux sensibles',    20),
  (NULL, 2, '01_DIRECTION', '21_COFFRE_SECURISE',         '🔒 Coffre sécurisé',       21),
  (NULL, 2, '01_DIRECTION', '22_MAILS_DIRECTION',         'Mails direction',          22),
  (NULL, 2, '01_DIRECTION', '23_ARCHIVES_DIRECTION',      'Archives direction',       23),
  (NULL, 2, '01_DIRECTION', '99_A_CLASSER_DIRECTION',     '99 - À classer (direction)', 99);

-- ════════════════════════════════════════════════════════════════════════
-- N3 / N4 / N5 par rubrique
-- ════════════════════════════════════════════════════════════════════════

-- ─── 01_SOCIETES ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_DIRECTION', '01_SOCIETES', 'SOCIETE', 'Société', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_DIRECTION', '01_SOCIETES', 'SOCIETE', 'STATUTS',       'Statuts',       1),
  (NULL, 4, '01_DIRECTION', '01_SOCIETES', 'SOCIETE', 'KBIS',          'KBIS',          2),
  (NULL, 4, '01_DIRECTION', '01_SOCIETES', 'SOCIETE', 'AG',            'AG',            3),
  (NULL, 4, '01_DIRECTION', '01_SOCIETES', 'SOCIETE', 'BANQUES',       'Banques',       4),
  (NULL, 4, '01_DIRECTION', '01_SOCIETES', 'SOCIETE', 'ASSURANCES',    'Assurances',    5),
  (NULL, 4, '01_DIRECTION', '01_SOCIETES', 'SOCIETE', 'CONTRATS',      'Contrats',      6),
  (NULL, 4, '01_DIRECTION', '01_SOCIETES', 'SOCIETE', 'FISCALITE',     'Fiscalité',     7),
  (NULL, 4, '01_DIRECTION', '01_SOCIETES', 'SOCIETE', 'ADMINISTRATIF', 'Administratif', 8),
  (NULL, 4, '01_DIRECTION', '01_SOCIETES', 'SOCIETE', 'ARCHIVES',      'Archives',      9);
-- N5 communs (ANNEE, DOCUMENTS, PIECES) sous chaque N4 de SOCIETE
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '01_DIRECTION', '01_SOCIETES', 'SOCIETE', n4, n5, n5_label, n5_pos FROM (
  SELECT 'STATUTS' AS n4 UNION ALL SELECT 'KBIS' UNION ALL SELECT 'AG' UNION ALL SELECT 'BANQUES'
  UNION ALL SELECT 'ASSURANCES' UNION ALL SELECT 'CONTRATS' UNION ALL SELECT 'FISCALITE'
  UNION ALL SELECT 'ADMINISTRATIF' UNION ALL SELECT 'ARCHIVES'
) parents
CROSS JOIN (
  SELECT 'ANNEE' AS n5, 'Année' AS n5_label, 1 AS n5_pos
  UNION ALL SELECT 'DOCUMENTS', 'Documents', 2
  UNION ALL SELECT 'PIECES',    'Pièces',    3
) levels;

-- ─── 02_BANQUES_FINANCEMENTS ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_DIRECTION', '02_BANQUES_FINANCEMENTS', 'BANQUE', 'Banque', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_DIRECTION', '02_BANQUES_FINANCEMENTS', 'BANQUE', 'COMPTES',             'Comptes',             1),
  (NULL, 4, '01_DIRECTION', '02_BANQUES_FINANCEMENTS', 'BANQUE', 'RELEVES',             'Relevés',             2),
  (NULL, 4, '01_DIRECTION', '02_BANQUES_FINANCEMENTS', 'BANQUE', 'PRETS',               'Prêts',               3),
  (NULL, 4, '01_DIRECTION', '02_BANQUES_FINANCEMENTS', 'BANQUE', 'FINANCEMENTS',        'Financements',        4),
  (NULL, 4, '01_DIRECTION', '02_BANQUES_FINANCEMENTS', 'BANQUE', 'GARANTIES',           'Garanties',           5),
  (NULL, 4, '01_DIRECTION', '02_BANQUES_FINANCEMENTS', 'BANQUE', 'INCIDENTS',           'Incidents',           6),
  (NULL, 4, '01_DIRECTION', '02_BANQUES_FINANCEMENTS', 'BANQUE', 'RELATIONS_BANCAIRES', 'Relations bancaires', 7),
  (NULL, 4, '01_DIRECTION', '02_BANQUES_FINANCEMENTS', 'BANQUE', 'ARCHIVES',            'Archives',            8);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '01_DIRECTION', '02_BANQUES_FINANCEMENTS', 'BANQUE', n4, n5, n5_label, n5_pos FROM
  (SELECT 'COMPTES' AS n4 UNION ALL SELECT 'RELEVES' UNION ALL SELECT 'PRETS' UNION ALL SELECT 'FINANCEMENTS'
   UNION ALL SELECT 'GARANTIES' UNION ALL SELECT 'INCIDENTS' UNION ALL SELECT 'RELATIONS_BANCAIRES' UNION ALL SELECT 'ARCHIVES') p
CROSS JOIN (SELECT 'ANNEE' AS n5, 'Année' AS n5_label, 1 AS n5_pos
            UNION ALL SELECT 'DOSSIERS', 'Dossiers', 2
            UNION ALL SELECT 'OPERATIONS','Opérations',3) l;

-- ─── 03_COMPTABILITE_DIRECTION ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_DIRECTION', '03_COMPTABILITE_DIRECTION', 'ANNEE', 'Année', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_DIRECTION', '03_COMPTABILITE_DIRECTION', 'ANNEE', 'BILANS',           'Bilans',            1),
  (NULL, 4, '01_DIRECTION', '03_COMPTABILITE_DIRECTION', 'ANNEE', 'LIASSES_FISCALES', 'Liasses fiscales',  2),
  (NULL, 4, '01_DIRECTION', '03_COMPTABILITE_DIRECTION', 'ANNEE', 'JOURNAUX',         'Journaux',          3),
  (NULL, 4, '01_DIRECTION', '03_COMPTABILITE_DIRECTION', 'ANNEE', 'SITUATIONS',       'Situations',        4),
  (NULL, 4, '01_DIRECTION', '03_COMPTABILITE_DIRECTION', 'ANNEE', 'EXPERT_COMPTABLE', 'Expert-comptable',  5),
  (NULL, 4, '01_DIRECTION', '03_COMPTABILITE_DIRECTION', 'ANNEE', 'DECLARATIONS',     'Déclarations',      6),
  (NULL, 4, '01_DIRECTION', '03_COMPTABILITE_DIRECTION', 'ANNEE', 'ARCHIVES',         'Archives',          7);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '01_DIRECTION', '03_COMPTABILITE_DIRECTION', 'ANNEE', n4, n5, n5_label, n5_pos FROM
  (SELECT 'BILANS' AS n4 UNION ALL SELECT 'LIASSES_FISCALES' UNION ALL SELECT 'JOURNAUX' UNION ALL SELECT 'SITUATIONS'
   UNION ALL SELECT 'EXPERT_COMPTABLE' UNION ALL SELECT 'DECLARATIONS' UNION ALL SELECT 'ARCHIVES') p
CROSS JOIN (SELECT 'MOIS' AS n5, 'Mois' AS n5_label, 1 AS n5_pos UNION ALL SELECT 'DOSSIERS', 'Dossiers', 2) l;

-- ─── 04_JURIDIQUE_STRATEGIQUE ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_DIRECTION', '04_JURIDIQUE_STRATEGIQUE', 'DOSSIER', 'Dossier', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_DIRECTION', '04_JURIDIQUE_STRATEGIQUE', 'DOSSIER', 'CONTRATS',      'Contrats',      1),
  (NULL, 4, '01_DIRECTION', '04_JURIDIQUE_STRATEGIQUE', 'DOSSIER', 'CONSULTATIONS', 'Consultations', 2),
  (NULL, 4, '01_DIRECTION', '04_JURIDIQUE_STRATEGIQUE', 'DOSSIER', 'PROCEDURES',    'Procédures',    3),
  (NULL, 4, '01_DIRECTION', '04_JURIDIQUE_STRATEGIQUE', 'DOSSIER', 'COURRIERS',     'Courriers',     4),
  (NULL, 4, '01_DIRECTION', '04_JURIDIQUE_STRATEGIQUE', 'DOSSIER', 'MAILS',         'Mails',         5),
  (NULL, 4, '01_DIRECTION', '04_JURIDIQUE_STRATEGIQUE', 'DOSSIER', 'PIECES',        'Pièces',        6),
  (NULL, 4, '01_DIRECTION', '04_JURIDIQUE_STRATEGIQUE', 'DOSSIER', 'ARCHIVES',      'Archives',      7);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '01_DIRECTION', '04_JURIDIQUE_STRATEGIQUE', 'DOSSIER', n4, n5, n5_label, n5_pos FROM
  (SELECT 'CONTRATS' AS n4 UNION ALL SELECT 'CONSULTATIONS' UNION ALL SELECT 'PROCEDURES' UNION ALL SELECT 'COURRIERS'
   UNION ALL SELECT 'MAILS' UNION ALL SELECT 'PIECES' UNION ALL SELECT 'ARCHIVES') p
CROSS JOIN (SELECT 'AVOCATS' AS n5, 'Avocats' AS n5_label, 1 AS n5_pos UNION ALL SELECT 'TRIBUNAUX', 'Tribunaux', 2) l;

-- ─── 05_ASSURANCES_DIRECTION ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_DIRECTION', '05_ASSURANCES_DIRECTION', 'ASSURANCE', 'Assurance', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_DIRECTION', '05_ASSURANCES_DIRECTION', 'ASSURANCE', 'CONTRATS',     'Contrats',     1),
  (NULL, 4, '01_DIRECTION', '05_ASSURANCES_DIRECTION', 'ASSURANCE', 'ATTESTATIONS', 'Attestations', 2),
  (NULL, 4, '01_DIRECTION', '05_ASSURANCES_DIRECTION', 'ASSURANCE', 'SINISTRES',    'Sinistres',    3),
  (NULL, 4, '01_DIRECTION', '05_ASSURANCES_DIRECTION', 'ASSURANCE', 'COURRIERS',    'Courriers',    4),
  (NULL, 4, '01_DIRECTION', '05_ASSURANCES_DIRECTION', 'ASSURANCE', 'MAILS',        'Mails',        5),
  (NULL, 4, '01_DIRECTION', '05_ASSURANCES_DIRECTION', 'ASSURANCE', 'ARCHIVES',     'Archives',     6);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '01_DIRECTION', '05_ASSURANCES_DIRECTION', 'ASSURANCE', n4, n5, n5_label, n5_pos FROM
  (SELECT 'CONTRATS' AS n4 UNION ALL SELECT 'ATTESTATIONS' UNION ALL SELECT 'SINISTRES'
   UNION ALL SELECT 'COURRIERS' UNION ALL SELECT 'MAILS' UNION ALL SELECT 'ARCHIVES') p
CROSS JOIN (SELECT 'ANNEE' AS n5, 'Année' AS n5_label, 1 AS n5_pos UNION ALL SELECT 'DOSSIERS', 'Dossiers', 2) l;

-- ─── 06_CONTRATS_STRATEGIQUES ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_DIRECTION', '06_CONTRATS_STRATEGIQUES', 'PARTENAIRE', 'Partenaire', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_DIRECTION', '06_CONTRATS_STRATEGIQUES', 'PARTENAIRE', 'CONTRATS',        'Contrats',        1),
  (NULL, 4, '01_DIRECTION', '06_CONTRATS_STRATEGIQUES', 'PARTENAIRE', 'AVENANTS',        'Avenants',        2),
  (NULL, 4, '01_DIRECTION', '06_CONTRATS_STRATEGIQUES', 'PARTENAIRE', 'RENOUVELLEMENTS', 'Renouvellements', 3),
  (NULL, 4, '01_DIRECTION', '06_CONTRATS_STRATEGIQUES', 'PARTENAIRE', 'RESILIATIONS',    'Résiliations',    4),
  (NULL, 4, '01_DIRECTION', '06_CONTRATS_STRATEGIQUES', 'PARTENAIRE', 'COURRIERS',       'Courriers',       5),
  (NULL, 4, '01_DIRECTION', '06_CONTRATS_STRATEGIQUES', 'PARTENAIRE', 'MAILS',           'Mails',           6),
  (NULL, 4, '01_DIRECTION', '06_CONTRATS_STRATEGIQUES', 'PARTENAIRE', 'ARCHIVES',        'Archives',        7);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '01_DIRECTION', '06_CONTRATS_STRATEGIQUES', 'PARTENAIRE', n4, 'ANNEE', 'Année', 1 FROM
  (SELECT 'CONTRATS' AS n4 UNION ALL SELECT 'AVENANTS' UNION ALL SELECT 'RENOUVELLEMENTS' UNION ALL SELECT 'RESILIATIONS'
   UNION ALL SELECT 'COURRIERS' UNION ALL SELECT 'MAILS' UNION ALL SELECT 'ARCHIVES') p;

-- ─── 07_PATRIMOINE_ACQUISITIONS ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_DIRECTION', '07_PATRIMOINE_ACQUISITIONS', 'DOSSIER', 'Dossier', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_DIRECTION', '07_PATRIMOINE_ACQUISITIONS', 'DOSSIER', 'ACQUISITIONS',    'Acquisitions',    1),
  (NULL, 4, '01_DIRECTION', '07_PATRIMOINE_ACQUISITIONS', 'DOSSIER', 'VENTES',          'Ventes',          2),
  (NULL, 4, '01_DIRECTION', '07_PATRIMOINE_ACQUISITIONS', 'DOSSIER', 'AGENCES',         'Agences',         3),
  (NULL, 4, '01_DIRECTION', '07_PATRIMOINE_ACQUISITIONS', 'DOSSIER', 'SCI',             'SCI',             4),
  (NULL, 4, '01_DIRECTION', '07_PATRIMOINE_ACQUISITIONS', 'DOSSIER', 'INVESTISSEMENTS', 'Investissements', 5),
  (NULL, 4, '01_DIRECTION', '07_PATRIMOINE_ACQUISITIONS', 'DOSSIER', 'COURRIERS',       'Courriers',       6),
  (NULL, 4, '01_DIRECTION', '07_PATRIMOINE_ACQUISITIONS', 'DOSSIER', 'MAILS',           'Mails',           7),
  (NULL, 4, '01_DIRECTION', '07_PATRIMOINE_ACQUISITIONS', 'DOSSIER', 'ARCHIVES',        'Archives',        8);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '01_DIRECTION', '07_PATRIMOINE_ACQUISITIONS', 'DOSSIER', n4, n5, n5_label, n5_pos FROM
  (SELECT 'ACQUISITIONS' AS n4 UNION ALL SELECT 'VENTES' UNION ALL SELECT 'AGENCES' UNION ALL SELECT 'SCI'
   UNION ALL SELECT 'INVESTISSEMENTS' UNION ALL SELECT 'COURRIERS' UNION ALL SELECT 'MAILS' UNION ALL SELECT 'ARCHIVES') p
CROSS JOIN (SELECT 'NOTAIRES' AS n5, 'Notaires' AS n5_label, 1 AS n5_pos UNION ALL SELECT 'DOSSIERS', 'Dossiers', 2) l;

-- ─── 08_RH_DIRECTION ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_DIRECTION', '08_RH_DIRECTION', 'COLLABORATEUR', 'Collaborateur', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_DIRECTION', '08_RH_DIRECTION', 'COLLABORATEUR', 'CONTRATS',     'Contrats',     1),
  (NULL, 4, '01_DIRECTION', '08_RH_DIRECTION', 'COLLABORATEUR', 'SALAIRES',     'Salaires',     2),
  (NULL, 4, '01_DIRECTION', '08_RH_DIRECTION', 'COLLABORATEUR', 'ENTRETIENS',   'Entretiens',   3),
  (NULL, 4, '01_DIRECTION', '08_RH_DIRECTION', 'COLLABORATEUR', 'DISCIPLINAIRE','Disciplinaire',4),
  (NULL, 4, '01_DIRECTION', '08_RH_DIRECTION', 'COLLABORATEUR', 'FORMATIONS',   'Formations',   5),
  (NULL, 4, '01_DIRECTION', '08_RH_DIRECTION', 'COLLABORATEUR', 'MAILS',        'Mails',        6),
  (NULL, 4, '01_DIRECTION', '08_RH_DIRECTION', 'COLLABORATEUR', 'ARCHIVES',     'Archives',     7);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '01_DIRECTION', '08_RH_DIRECTION', 'COLLABORATEUR', n4, 'ANNEE', 'Année', 1 FROM
  (SELECT 'CONTRATS' AS n4 UNION ALL SELECT 'SALAIRES' UNION ALL SELECT 'ENTRETIENS' UNION ALL SELECT 'DISCIPLINAIRE'
   UNION ALL SELECT 'FORMATIONS' UNION ALL SELECT 'MAILS' UNION ALL SELECT 'ARCHIVES') p;

-- ─── 09_ORGANISATION_INTERNE ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_DIRECTION', '09_ORGANISATION_INTERNE', 'STRUCTURE', 'Structure', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_DIRECTION', '09_ORGANISATION_INTERNE', 'STRUCTURE', 'PROCEDURES',     'Procédures',     1),
  (NULL, 4, '01_DIRECTION', '09_ORGANISATION_INTERNE', 'STRUCTURE', 'ORGANIGRAMMES',  'Organigrammes',  2),
  (NULL, 4, '01_DIRECTION', '09_ORGANISATION_INTERNE', 'STRUCTURE', 'NOTES_INTERNES', 'Notes internes', 3),
  (NULL, 4, '01_DIRECTION', '09_ORGANISATION_INTERNE', 'STRUCTURE', 'PROCESS',        'Process',        4),
  (NULL, 4, '01_DIRECTION', '09_ORGANISATION_INTERNE', 'STRUCTURE', 'DOCUMENTATION',  'Documentation',  5),
  (NULL, 4, '01_DIRECTION', '09_ORGANISATION_INTERNE', 'STRUCTURE', 'ARCHIVES',       'Archives',       6);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '01_DIRECTION', '09_ORGANISATION_INTERNE', 'STRUCTURE', n4, 'MODULES', 'Modules', 1 FROM
  (SELECT 'PROCEDURES' AS n4 UNION ALL SELECT 'ORGANIGRAMMES' UNION ALL SELECT 'NOTES_INTERNES'
   UNION ALL SELECT 'PROCESS' UNION ALL SELECT 'DOCUMENTATION' UNION ALL SELECT 'ARCHIVES') p;

-- ─── 10_REUNIONS_DIRECTION ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_DIRECTION', '10_REUNIONS_DIRECTION', 'REUNION', 'Réunion', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_DIRECTION', '10_REUNIONS_DIRECTION', 'REUNION', 'CONVOCATIONS',    'Convocations',     1),
  (NULL, 4, '01_DIRECTION', '10_REUNIONS_DIRECTION', 'REUNION', 'ORDRES_DU_JOUR',  'Ordres du jour',   2),
  (NULL, 4, '01_DIRECTION', '10_REUNIONS_DIRECTION', 'REUNION', 'COMPTES_RENDUS',  'Comptes rendus',   3),
  (NULL, 4, '01_DIRECTION', '10_REUNIONS_DIRECTION', 'REUNION', 'DECISIONS',       'Décisions',        4),
  (NULL, 4, '01_DIRECTION', '10_REUNIONS_DIRECTION', 'REUNION', 'MAILS',           'Mails',            5),
  (NULL, 4, '01_DIRECTION', '10_REUNIONS_DIRECTION', 'REUNION', 'ARCHIVES',        'Archives',         6);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '01_DIRECTION', '10_REUNIONS_DIRECTION', 'REUNION', n4, 'ANNEE', 'Année', 1 FROM
  (SELECT 'CONVOCATIONS' AS n4 UNION ALL SELECT 'ORDRES_DU_JOUR' UNION ALL SELECT 'COMPTES_RENDUS'
   UNION ALL SELECT 'DECISIONS' UNION ALL SELECT 'MAILS' UNION ALL SELECT 'ARCHIVES') p;

-- ─── 11_PROJETS_MBI ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_DIRECTION', '11_PROJETS_MBI', 'MODULE', 'Module', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_DIRECTION', '11_PROJETS_MBI', 'MODULE', 'GED',           'GED',           1),
  (NULL, 4, '01_DIRECTION', '11_PROJETS_MBI', 'MODULE', 'MAIL',          'Mail',          2),
  (NULL, 4, '01_DIRECTION', '11_PROJETS_MBI', 'MODULE', 'RH',            'RH',            3),
  (NULL, 4, '01_DIRECTION', '11_PROJETS_MBI', 'MODULE', 'SYNDIC',        'Syndic',        4),
  (NULL, 4, '01_DIRECTION', '11_PROJETS_MBI', 'MODULE', 'GESTION',       'Gestion',       5),
  (NULL, 4, '01_DIRECTION', '11_PROJETS_MBI', 'MODULE', 'TRANSACTION',   'Transaction',   6),
  (NULL, 4, '01_DIRECTION', '11_PROJETS_MBI', 'MODULE', 'SEO',           'SEO',           7),
  (NULL, 4, '01_DIRECTION', '11_PROJETS_MBI', 'MODULE', 'DEV',           'Dev',           8),
  (NULL, 4, '01_DIRECTION', '11_PROJETS_MBI', 'MODULE', 'PROMPTS',       'Prompts',       9),
  (NULL, 4, '01_DIRECTION', '11_PROJETS_MBI', 'MODULE', 'DOCUMENTATION', 'Documentation', 10),
  (NULL, 4, '01_DIRECTION', '11_PROJETS_MBI', 'MODULE', 'ARCHIVES',      'Archives',      11);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '01_DIRECTION', '11_PROJETS_MBI', 'MODULE', n4, 'VERSIONS', 'Versions', 1 FROM
  (SELECT 'GED' AS n4 UNION ALL SELECT 'MAIL' UNION ALL SELECT 'RH' UNION ALL SELECT 'SYNDIC' UNION ALL SELECT 'GESTION'
   UNION ALL SELECT 'TRANSACTION' UNION ALL SELECT 'SEO' UNION ALL SELECT 'DEV' UNION ALL SELECT 'PROMPTS'
   UNION ALL SELECT 'DOCUMENTATION' UNION ALL SELECT 'ARCHIVES') p;

-- ─── 12_INFORMATIQUE ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_DIRECTION', '12_INFORMATIQUE', 'SYSTEME', 'Système', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_DIRECTION', '12_INFORMATIQUE', 'SYSTEME', 'SERVEURS',    'Serveurs',    1),
  (NULL, 4, '01_DIRECTION', '12_INFORMATIQUE', 'SYSTEME', 'LICENCES',    'Licences',    2),
  (NULL, 4, '01_DIRECTION', '12_INFORMATIQUE', 'SYSTEME', 'SAUVEGARDES', 'Sauvegardes', 3),
  (NULL, 4, '01_DIRECTION', '12_INFORMATIQUE', 'SYSTEME', 'SECURITE',    'Sécurité',    4),
  (NULL, 4, '01_DIRECTION', '12_INFORMATIQUE', 'SYSTEME', 'ACCES',       'Accès',       5),
  (NULL, 4, '01_DIRECTION', '12_INFORMATIQUE', 'SYSTEME', 'INCIDENTS',   'Incidents',   6),
  (NULL, 4, '01_DIRECTION', '12_INFORMATIQUE', 'SYSTEME', 'ARCHIVES',    'Archives',    7);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '01_DIRECTION', '12_INFORMATIQUE', 'SYSTEME', n4, 'ANNEE', 'Année', 1 FROM
  (SELECT 'SERVEURS' AS n4 UNION ALL SELECT 'LICENCES' UNION ALL SELECT 'SAUVEGARDES'
   UNION ALL SELECT 'SECURITE' UNION ALL SELECT 'ACCES' UNION ALL SELECT 'INCIDENTS' UNION ALL SELECT 'ARCHIVES') p;

-- ─── 13_TELEPHONIE ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_DIRECTION', '13_TELEPHONIE', 'OPERATEUR', 'Opérateur', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_DIRECTION', '13_TELEPHONIE', 'OPERATEUR', 'CONTRATS', 'Contrats', 1),
  (NULL, 4, '01_DIRECTION', '13_TELEPHONIE', 'OPERATEUR', 'LIGNES',   'Lignes',   2),
  (NULL, 4, '01_DIRECTION', '13_TELEPHONIE', 'OPERATEUR', 'FACTURES', 'Factures', 3),
  (NULL, 4, '01_DIRECTION', '13_TELEPHONIE', 'OPERATEUR', 'INCIDENTS','Incidents',4),
  (NULL, 4, '01_DIRECTION', '13_TELEPHONIE', 'OPERATEUR', 'MAILS',    'Mails',    5),
  (NULL, 4, '01_DIRECTION', '13_TELEPHONIE', 'OPERATEUR', 'ARCHIVES', 'Archives', 6);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '01_DIRECTION', '13_TELEPHONIE', 'OPERATEUR', n4, 'ANNEE', 'Année', 1 FROM
  (SELECT 'CONTRATS' AS n4 UNION ALL SELECT 'LIGNES' UNION ALL SELECT 'FACTURES'
   UNION ALL SELECT 'INCIDENTS' UNION ALL SELECT 'MAILS' UNION ALL SELECT 'ARCHIVES') p;

-- ─── 14_COMMUNICATION_GRAPHISME ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_DIRECTION', '14_COMMUNICATION_GRAPHISME', 'SUPPORT', 'Support', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_DIRECTION', '14_COMMUNICATION_GRAPHISME', 'SUPPORT', 'LOGOS',           'Logos',           1),
  (NULL, 4, '01_DIRECTION', '14_COMMUNICATION_GRAPHISME', 'SUPPORT', 'CHARTES',         'Chartes',         2),
  (NULL, 4, '01_DIRECTION', '14_COMMUNICATION_GRAPHISME', 'SUPPORT', 'VISUELS',         'Visuels',         3),
  (NULL, 4, '01_DIRECTION', '14_COMMUNICATION_GRAPHISME', 'SUPPORT', 'CAMPAGNES',       'Campagnes',       4),
  (NULL, 4, '01_DIRECTION', '14_COMMUNICATION_GRAPHISME', 'SUPPORT', 'PUBLICITES',      'Publicités',      5),
  (NULL, 4, '01_DIRECTION', '14_COMMUNICATION_GRAPHISME', 'SUPPORT', 'RESEAUX_SOCIAUX', 'Réseaux sociaux', 6),
  (NULL, 4, '01_DIRECTION', '14_COMMUNICATION_GRAPHISME', 'SUPPORT', 'ARCHIVES',        'Archives',        7);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '01_DIRECTION', '14_COMMUNICATION_GRAPHISME', 'SUPPORT', n4, 'ANNEE', 'Année', 1 FROM
  (SELECT 'LOGOS' AS n4 UNION ALL SELECT 'CHARTES' UNION ALL SELECT 'VISUELS' UNION ALL SELECT 'CAMPAGNES'
   UNION ALL SELECT 'PUBLICITES' UNION ALL SELECT 'RESEAUX_SOCIAUX' UNION ALL SELECT 'ARCHIVES') p;

-- ─── 15_FORMATIONS ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_DIRECTION', '15_FORMATIONS', 'THEME', 'Thème', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_DIRECTION', '15_FORMATIONS', 'THEME', 'SUPPORTS',     'Supports',     1),
  (NULL, 4, '01_DIRECTION', '15_FORMATIONS', 'THEME', 'INSCRIPTIONS', 'Inscriptions', 2),
  (NULL, 4, '01_DIRECTION', '15_FORMATIONS', 'THEME', 'CERTIFICATS',  'Certificats',  3),
  (NULL, 4, '01_DIRECTION', '15_FORMATIONS', 'THEME', 'ORGANISMES',   'Organismes',   4),
  (NULL, 4, '01_DIRECTION', '15_FORMATIONS', 'THEME', 'MAILS',        'Mails',        5),
  (NULL, 4, '01_DIRECTION', '15_FORMATIONS', 'THEME', 'ARCHIVES',     'Archives',     6);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '01_DIRECTION', '15_FORMATIONS', 'THEME', n4, 'ANNEE', 'Année', 1 FROM
  (SELECT 'SUPPORTS' AS n4 UNION ALL SELECT 'INSCRIPTIONS' UNION ALL SELECT 'CERTIFICATS'
   UNION ALL SELECT 'ORGANISMES' UNION ALL SELECT 'MAILS' UNION ALL SELECT 'ARCHIVES') p;

-- ─── 16_RESEAUX_METIERS ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_DIRECTION', '16_RESEAUX_METIERS', 'ORGANISME', 'Organisme', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_DIRECTION', '16_RESEAUX_METIERS', 'ORGANISME', 'FNAIM',       'FNAIM',       1),
  (NULL, 4, '01_DIRECTION', '16_RESEAUX_METIERS', 'ORGANISME', 'UNIS',        'UNIS',        2),
  (NULL, 4, '01_DIRECTION', '16_RESEAUX_METIERS', 'ORGANISME', 'PARTENAIRES', 'Partenaires', 3),
  (NULL, 4, '01_DIRECTION', '16_RESEAUX_METIERS', 'ORGANISME', 'RESEAUX',     'Réseaux',     4),
  (NULL, 4, '01_DIRECTION', '16_RESEAUX_METIERS', 'ORGANISME', 'DOCUMENTS',   'Documents',   5),
  (NULL, 4, '01_DIRECTION', '16_RESEAUX_METIERS', 'ORGANISME', 'MAILS',       'Mails',       6),
  (NULL, 4, '01_DIRECTION', '16_RESEAUX_METIERS', 'ORGANISME', 'ARCHIVES',    'Archives',    7);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '01_DIRECTION', '16_RESEAUX_METIERS', 'ORGANISME', n4, 'ANNEE', 'Année', 1 FROM
  (SELECT 'FNAIM' AS n4 UNION ALL SELECT 'UNIS' UNION ALL SELECT 'PARTENAIRES'
   UNION ALL SELECT 'RESEAUX' UNION ALL SELECT 'DOCUMENTS' UNION ALL SELECT 'MAILS' UNION ALL SELECT 'ARCHIVES') p;

-- ─── 17_VEHICULES ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_DIRECTION', '17_VEHICULES', 'VEHICULE', 'Véhicule', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_DIRECTION', '17_VEHICULES', 'VEHICULE', 'CONTRATS',   'Contrats',   1),
  (NULL, 4, '01_DIRECTION', '17_VEHICULES', 'VEHICULE', 'ASSURANCES', 'Assurances', 2),
  (NULL, 4, '01_DIRECTION', '17_VEHICULES', 'VEHICULE', 'ENTRETIEN',  'Entretien',  3),
  (NULL, 4, '01_DIRECTION', '17_VEHICULES', 'VEHICULE', 'FACTURES',   'Factures',   4),
  (NULL, 4, '01_DIRECTION', '17_VEHICULES', 'VEHICULE', 'SINISTRES',  'Sinistres',  5),
  (NULL, 4, '01_DIRECTION', '17_VEHICULES', 'VEHICULE', 'MAILS',      'Mails',      6),
  (NULL, 4, '01_DIRECTION', '17_VEHICULES', 'VEHICULE', 'ARCHIVES',   'Archives',   7);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '01_DIRECTION', '17_VEHICULES', 'VEHICULE', n4, 'ANNEE', 'Année', 1 FROM
  (SELECT 'CONTRATS' AS n4 UNION ALL SELECT 'ASSURANCES' UNION ALL SELECT 'ENTRETIEN'
   UNION ALL SELECT 'FACTURES' UNION ALL SELECT 'SINISTRES' UNION ALL SELECT 'MAILS' UNION ALL SELECT 'ARCHIVES') p;

-- ─── 18_PHOTOS_DIRECTION ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_DIRECTION', '18_PHOTOS_DIRECTION', 'DOSSIER', 'Dossier', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_DIRECTION', '18_PHOTOS_DIRECTION', 'DOSSIER', 'EVENEMENTS',    'Événements',    1),
  (NULL, 4, '01_DIRECTION', '18_PHOTOS_DIRECTION', 'DOSSIER', 'COMMUNICATION', 'Communication', 2),
  (NULL, 4, '01_DIRECTION', '18_PHOTOS_DIRECTION', 'DOSSIER', 'PROJETS',       'Projets',       3),
  (NULL, 4, '01_DIRECTION', '18_PHOTOS_DIRECTION', 'DOSSIER', 'ARCHIVES',      'Archives',      4);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '01_DIRECTION', '18_PHOTOS_DIRECTION', 'DOSSIER', n4, 'ANNEE', 'Année', 1 FROM
  (SELECT 'EVENEMENTS' AS n4 UNION ALL SELECT 'COMMUNICATION' UNION ALL SELECT 'PROJETS' UNION ALL SELECT 'ARCHIVES') p;

-- ─── 19_DOCUMENTS_PERSONNELS ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_DIRECTION', '19_DOCUMENTS_PERSONNELS', 'DOSSIER', 'Dossier', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_DIRECTION', '19_DOCUMENTS_PERSONNELS', 'DOSSIER', 'ADMINISTRATIF', 'Administratif', 1),
  (NULL, 4, '01_DIRECTION', '19_DOCUMENTS_PERSONNELS', 'DOSSIER', 'FISCALITE',     'Fiscalité',     2),
  (NULL, 4, '01_DIRECTION', '19_DOCUMENTS_PERSONNELS', 'DOSSIER', 'BANQUE',        'Banque',        3),
  (NULL, 4, '01_DIRECTION', '19_DOCUMENTS_PERSONNELS', 'DOSSIER', 'ASSURANCES',    'Assurances',    4),
  (NULL, 4, '01_DIRECTION', '19_DOCUMENTS_PERSONNELS', 'DOSSIER', 'ARCHIVES',      'Archives',      5);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '01_DIRECTION', '19_DOCUMENTS_PERSONNELS', 'DOSSIER', n4, 'ANNEE', 'Année', 1 FROM
  (SELECT 'ADMINISTRATIF' AS n4 UNION ALL SELECT 'FISCALITE' UNION ALL SELECT 'BANQUE'
   UNION ALL SELECT 'ASSURANCES' UNION ALL SELECT 'ARCHIVES') p;

-- ─── 20_CONTENTIEUX_SENSIBLES ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_DIRECTION', '20_CONTENTIEUX_SENSIBLES', 'DOSSIER', 'Dossier', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_DIRECTION', '20_CONTENTIEUX_SENSIBLES', 'DOSSIER', 'PROCEDURES','Procédures',1),
  (NULL, 4, '01_DIRECTION', '20_CONTENTIEUX_SENSIBLES', 'DOSSIER', 'PIECES',    'Pièces',    2),
  (NULL, 4, '01_DIRECTION', '20_CONTENTIEUX_SENSIBLES', 'DOSSIER', 'COURRIERS', 'Courriers', 3),
  (NULL, 4, '01_DIRECTION', '20_CONTENTIEUX_SENSIBLES', 'DOSSIER', 'MAILS',     'Mails',     4),
  (NULL, 4, '01_DIRECTION', '20_CONTENTIEUX_SENSIBLES', 'DOSSIER', 'DECISIONS', 'Décisions', 5),
  (NULL, 4, '01_DIRECTION', '20_CONTENTIEUX_SENSIBLES', 'DOSSIER', 'ARCHIVES',  'Archives',  6);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '01_DIRECTION', '20_CONTENTIEUX_SENSIBLES', 'DOSSIER', n4, 'ANNEE', 'Année', 1 FROM
  (SELECT 'PROCEDURES' AS n4 UNION ALL SELECT 'PIECES' UNION ALL SELECT 'COURRIERS'
   UNION ALL SELECT 'MAILS' UNION ALL SELECT 'DECISIONS' UNION ALL SELECT 'ARCHIVES') p;

-- ─── 21_COFFRE_SECURISE ───
-- ACCES = lien logique vers super_admin_coffre_acces.php (pas de fichiers GED)
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_DIRECTION', '21_COFFRE_SECURISE', 'ACCES',              '🔑 Accès (module sécurisé)',    1),
  (NULL, 3, '01_DIRECTION', '21_COFFRE_SECURISE', 'DOCUMENTS_SENSIBLES','Documents sensibles',          2);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_DIRECTION', '21_COFFRE_SECURISE', 'DOCUMENTS_SENSIBLES', 'COMPTES',      'Comptes',      1),
  (NULL, 4, '01_DIRECTION', '21_COFFRE_SECURISE', 'DOCUMENTS_SENSIBLES', 'IDENTIFIANTS', 'Identifiants', 2),
  (NULL, 4, '01_DIRECTION', '21_COFFRE_SECURISE', 'DOCUMENTS_SENSIBLES', 'CONTRATS',     'Contrats',     3),
  (NULL, 4, '01_DIRECTION', '21_COFFRE_SECURISE', 'DOCUMENTS_SENSIBLES', 'BANQUES',      'Banques',      4),
  (NULL, 4, '01_DIRECTION', '21_COFFRE_SECURISE', 'DOCUMENTS_SENSIBLES', 'FISCAUX',      'Fiscaux',      5),
  (NULL, 4, '01_DIRECTION', '21_COFFRE_SECURISE', 'DOCUMENTS_SENSIBLES', 'ARCHIVES',     'Archives',     6);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '01_DIRECTION', '21_COFFRE_SECURISE', 'DOCUMENTS_SENSIBLES', n4, 'CATEGORIES', 'Catégories', 1 FROM
  (SELECT 'COMPTES' AS n4 UNION ALL SELECT 'IDENTIFIANTS' UNION ALL SELECT 'CONTRATS'
   UNION ALL SELECT 'BANQUES' UNION ALL SELECT 'FISCAUX' UNION ALL SELECT 'ARCHIVES') p;

-- ─── 22_MAILS_DIRECTION ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_DIRECTION', '22_MAILS_DIRECTION', 'A_TRAITER',      'À traiter',         1),
  (NULL, 3, '01_DIRECTION', '22_MAILS_DIRECTION', 'IMPORTES',       'Importés',          2),
  (NULL, 3, '01_DIRECTION', '22_MAILS_DIRECTION', 'ENVOYES',        'Envoyés',           3),
  (NULL, 3, '01_DIRECTION', '22_MAILS_DIRECTION', 'PIECES_JOINTES', 'Pièces jointes',    4),
  (NULL, 3, '01_DIRECTION', '22_MAILS_DIRECTION', 'CONVERSATIONS',  'Conversations',     5),
  (NULL, 3, '01_DIRECTION', '22_MAILS_DIRECTION', 'ARCHIVES',       'Archives',          6);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`)
SELECT NULL, 4, '01_DIRECTION', '22_MAILS_DIRECTION', n3, 'DOSSIERS', 'Dossiers', 1 FROM
  (SELECT 'A_TRAITER' AS n3 UNION ALL SELECT 'IMPORTES' UNION ALL SELECT 'ENVOYES'
   UNION ALL SELECT 'PIECES_JOINTES' UNION ALL SELECT 'CONVERSATIONS' UNION ALL SELECT 'ARCHIVES') p;
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '01_DIRECTION', '22_MAILS_DIRECTION', n3, 'DOSSIERS', 'ANNEE', 'Année', 1 FROM
  (SELECT 'A_TRAITER' AS n3 UNION ALL SELECT 'IMPORTES' UNION ALL SELECT 'ENVOYES'
   UNION ALL SELECT 'PIECES_JOINTES' UNION ALL SELECT 'CONVERSATIONS' UNION ALL SELECT 'ARCHIVES') p;

-- ─── 23_ARCHIVES_DIRECTION ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_DIRECTION', '23_ARCHIVES_DIRECTION', 'MODULE', 'Module', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_DIRECTION', '23_ARCHIVES_DIRECTION', 'MODULE', 'ANNEE', 'Année', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`) VALUES
  (NULL, 5, '01_DIRECTION', '23_ARCHIVES_DIRECTION', 'MODULE', 'ANNEE', 'DOSSIERS', 'Dossiers', 1);

-- ─── 99_A_CLASSER_DIRECTION ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '01_DIRECTION', '99_A_CLASSER_DIRECTION', 'A_TRAITER', 'À traiter', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '01_DIRECTION', '99_A_CLASSER_DIRECTION', 'A_TRAITER', 'IMPORT', 'Import', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`) VALUES
  (NULL, 5, '01_DIRECTION', '99_A_CLASSER_DIRECTION', 'A_TRAITER', 'IMPORT', 'TEMP', 'Temp', 1);
SQL,
];
