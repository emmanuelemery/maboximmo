<?php
/**
 * Migration v1.1 — Seed COMPLET 02_RH + ajouts COMPTABILITE (SYNDIC + GESTION)
 *
 * 02_RH (5 N2, ~70 entrées) :
 *   - COLLABORATEURS > COLLABORATEUR > 12 N4 (identité, contrat, paie...) avec N5=ANNEE
 *   - SALAIRES       > ANNEE         > 7 N4 + N5 (mois, justificatifs)
 *   - CONGES         > ANNEE         > 7 N4 + N5 (mois, collaborateur)
 *   - ENTRETIENS     > ANNEE         > 5 N4 + N5 (collaborateur)
 *   - DOCUMENTS_RH   > CATEGORIE     > 8 N4 + N5 (versions, année)
 *
 * 06_COMPTABILITE — AJOUT de 2 nouveaux N2 :
 *   - SYNDIC  > FICHIER_CFONDS, REMISE_CHEQUES, PRELEVEMENT
 *   - GESTION > FICHIER_CFONDS, REMISE_CHEQUES, PRELEVEMENT
 *
 * Idempotent (INSERT IGNORE sur uk_ged_level_codes_path). AJOUT uniquement.
 */

return [
    'id'          => '20260502_ged_v1_15_seed_rh_compta',
    'title'       => 'Ma GED Box V1.1 — seed COMPLET 02_RH + ajouts 06_COMPTABILITE (SYNDIC + GESTION)',
    'description' => "02_RH : seed N3/N4/N5 pour les 5 N2 (COLLABORATEURS, SALAIRES, CONGES, ENTRETIENS, DOCUMENTS_RH). 06_COMPTABILITE : ajoute 2 nouveaux N2 (SYNDIC + GESTION) avec N3 = FICHIER_CFONDS, REMISE_CHEQUES, PRELEVEMENT chacun. Idempotent.",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
-- ════════════════════════════════════════════════════════════════════════
-- 02_RH > COLLABORATEURS
-- ════════════════════════════════════════════════════════════════════════
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '02_RH', 'COLLABORATEURS', 'COLLABORATEUR', 'Collaborateur (entité)', 1);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '02_RH', 'COLLABORATEURS', 'COLLABORATEUR', '01_IDENTITE',         '01 - Identité',           1),
  (NULL, 4, '02_RH', 'COLLABORATEURS', 'COLLABORATEUR', '02_CONTRAT_TRAVAIL',  '02 - Contrat de travail', 2),
  (NULL, 4, '02_RH', 'COLLABORATEURS', 'COLLABORATEUR', '03_PAIE',             '03 - Paie',               3),
  (NULL, 4, '02_RH', 'COLLABORATEURS', 'COLLABORATEUR', '04_FORMATION',        '04 - Formation',          4),
  (NULL, 4, '02_RH', 'COLLABORATEURS', 'COLLABORATEUR', '05_SANTE_VISITES',    '05 - Santé / Visites',    5),
  (NULL, 4, '02_RH', 'COLLABORATEURS', 'COLLABORATEUR', '06_DISCIPLINAIRE',    '06 - Disciplinaire',      6),
  (NULL, 4, '02_RH', 'COLLABORATEURS', 'COLLABORATEUR', '07_ENTRETIENS',       '07 - Entretiens',         7),
  (NULL, 4, '02_RH', 'COLLABORATEURS', 'COLLABORATEUR', '08_OBJECTIFS',        '08 - Objectifs',          8),
  (NULL, 4, '02_RH', 'COLLABORATEURS', 'COLLABORATEUR', '09_IK_DEPLACEMENTS',  '09 - IK / Déplacements',  9),
  (NULL, 4, '02_RH', 'COLLABORATEURS', 'COLLABORATEUR', '10_COURRIERS_MAILS',  '10 - Courriers / Mails',  10),
  (NULL, 4, '02_RH', 'COLLABORATEURS', 'COLLABORATEUR', '11_SORTIE',           '11 - Sortie',             11),
  (NULL, 4, '02_RH', 'COLLABORATEURS', 'COLLABORATEUR', '12_ARCHIVES',         '12 - Archives',           12);

-- N5 = ANNEE pour chacun des N4 collaborateur
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '02_RH', 'COLLABORATEURS', 'COLLABORATEUR', n4, 'ANNEE', 'Année', 1 FROM (
  SELECT '01_IDENTITE' AS n4 UNION ALL SELECT '02_CONTRAT_TRAVAIL' UNION ALL SELECT '03_PAIE'
  UNION ALL SELECT '04_FORMATION' UNION ALL SELECT '05_SANTE_VISITES' UNION ALL SELECT '06_DISCIPLINAIRE'
  UNION ALL SELECT '07_ENTRETIENS' UNION ALL SELECT '08_OBJECTIFS' UNION ALL SELECT '09_IK_DEPLACEMENTS'
  UNION ALL SELECT '10_COURRIERS_MAILS' UNION ALL SELECT '11_SORTIE' UNION ALL SELECT '12_ARCHIVES'
) p;

-- ════════════════════════════════════════════════════════════════════════
-- 02_RH > SALAIRES
-- ════════════════════════════════════════════════════════════════════════
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '02_RH', 'SALAIRES', 'ANNEE', 'Année', 1);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '02_RH', 'SALAIRES', 'ANNEE', 'BULLETINS',             'Bulletins de paie',     1),
  (NULL, 4, '02_RH', 'SALAIRES', 'ANNEE', 'CHARGES_SOCIALES',      'Charges sociales',      2),
  (NULL, 4, '02_RH', 'SALAIRES', 'ANNEE', 'PRIMES',                'Primes',                3),
  (NULL, 4, '02_RH', 'SALAIRES', 'ANNEE', 'VARIABLES',             'Variables',             4),
  (NULL, 4, '02_RH', 'SALAIRES', 'ANNEE', 'DECLARATIONS_SOCIALES', 'Déclarations sociales', 5),
  (NULL, 4, '02_RH', 'SALAIRES', 'ANNEE', 'EXPORTS_SILAE',         'Exports Silae',         6),
  (NULL, 4, '02_RH', 'SALAIRES', 'ANNEE', 'ARCHIVES',              'Archives',              7);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '02_RH', 'SALAIRES', 'ANNEE', n4, n5, n5_label, n5_pos FROM (
  SELECT 'BULLETINS' AS n4 UNION ALL SELECT 'CHARGES_SOCIALES' UNION ALL SELECT 'PRIMES'
  UNION ALL SELECT 'VARIABLES' UNION ALL SELECT 'DECLARATIONS_SOCIALES' UNION ALL SELECT 'EXPORTS_SILAE'
  UNION ALL SELECT 'ARCHIVES'
) p
CROSS JOIN (
  SELECT 'MOIS' AS n5, 'Mois' AS n5_label, 1 AS n5_pos
  UNION ALL SELECT 'JUSTIFICATIFS', 'Justificatifs', 2
) l;

-- ════════════════════════════════════════════════════════════════════════
-- 02_RH > CONGES
-- ════════════════════════════════════════════════════════════════════════
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '02_RH', 'CONGES', 'ANNEE', 'Année', 1);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '02_RH', 'CONGES', 'ANNEE', 'DEMANDES',     'Demandes',    1),
  (NULL, 4, '02_RH', 'CONGES', 'ANNEE', 'VALIDATIONS',  'Validations', 2),
  (NULL, 4, '02_RH', 'CONGES', 'ANNEE', 'REFUS',        'Refus',       3),
  (NULL, 4, '02_RH', 'CONGES', 'ANNEE', 'SOLDES',       'Soldes',      4),
  (NULL, 4, '02_RH', 'CONGES', 'ANNEE', 'HISTORIQUE',   'Historique',  5),
  (NULL, 4, '02_RH', 'CONGES', 'ANNEE', 'EXPORTS',      'Exports',     6),
  (NULL, 4, '02_RH', 'CONGES', 'ANNEE', 'ARCHIVES',     'Archives',    7);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '02_RH', 'CONGES', 'ANNEE', n4, n5, n5_label, n5_pos FROM (
  SELECT 'DEMANDES' AS n4 UNION ALL SELECT 'VALIDATIONS' UNION ALL SELECT 'REFUS'
  UNION ALL SELECT 'SOLDES' UNION ALL SELECT 'HISTORIQUE' UNION ALL SELECT 'EXPORTS' UNION ALL SELECT 'ARCHIVES'
) p
CROSS JOIN (
  SELECT 'MOIS' AS n5, 'Mois' AS n5_label, 1 AS n5_pos
  UNION ALL SELECT 'COLLABORATEUR', 'Collaborateur', 2
) l;

-- ════════════════════════════════════════════════════════════════════════
-- 02_RH > ENTRETIENS
-- ════════════════════════════════════════════════════════════════════════
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '02_RH', 'ENTRETIENS', 'ANNEE', 'Année', 1);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '02_RH', 'ENTRETIENS', 'ANNEE', 'ENTRETIENS_ANNUELS',        'Entretiens annuels',         1),
  (NULL, 4, '02_RH', 'ENTRETIENS', 'ANNEE', 'ENTRETIENS_PROFESSIONNELS', 'Entretiens professionnels',  2),
  (NULL, 4, '02_RH', 'ENTRETIENS', 'ANNEE', 'BILAN_COMPETENCES',         'Bilan de compétences',       3),
  (NULL, 4, '02_RH', 'ENTRETIENS', 'ANNEE', 'OBJECTIFS',                 'Objectifs',                  4),
  (NULL, 4, '02_RH', 'ENTRETIENS', 'ANNEE', 'ARCHIVES',                  'Archives',                   5);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '02_RH', 'ENTRETIENS', 'ANNEE', n4, 'COLLABORATEUR', 'Collaborateur', 1 FROM (
  SELECT 'ENTRETIENS_ANNUELS' AS n4 UNION ALL SELECT 'ENTRETIENS_PROFESSIONNELS'
  UNION ALL SELECT 'BILAN_COMPETENCES' UNION ALL SELECT 'OBJECTIFS' UNION ALL SELECT 'ARCHIVES'
) p;

-- ════════════════════════════════════════════════════════════════════════
-- 02_RH > DOCUMENTS_RH (transverses, pas par employé)
-- ════════════════════════════════════════════════════════════════════════
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '02_RH', 'DOCUMENTS_RH', 'CATEGORIE', 'Catégorie', 1);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '02_RH', 'DOCUMENTS_RH', 'CATEGORIE', 'REGLEMENT_INTERIEUR',      'Règlement intérieur',         1),
  (NULL, 4, '02_RH', 'DOCUMENTS_RH', 'CATEGORIE', 'ACCORDS_ENTREPRISE',       'Accords entreprise',          2),
  (NULL, 4, '02_RH', 'DOCUMENTS_RH', 'CATEGORIE', 'AFFICHAGES_OBLIGATOIRES',  'Affichages obligatoires',     3),
  (NULL, 4, '02_RH', 'DOCUMENTS_RH', 'CATEGORIE', 'DUER',                     'DUER',                        4),
  (NULL, 4, '02_RH', 'DOCUMENTS_RH', 'CATEGORIE', 'MUTUELLE_PREVOYANCE',      'Mutuelle / Prévoyance',       5),
  (NULL, 4, '02_RH', 'DOCUMENTS_RH', 'CATEGORIE', 'FORMATIONS_OBLIGATOIRES',  'Formations obligatoires',     6),
  (NULL, 4, '02_RH', 'DOCUMENTS_RH', 'CATEGORIE', 'REPRESENTANTS_PERSONNEL',  'Représentants du personnel',  7),
  (NULL, 4, '02_RH', 'DOCUMENTS_RH', 'CATEGORIE', 'ARCHIVES',                 'Archives',                    8);

INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '02_RH', 'DOCUMENTS_RH', 'CATEGORIE', n4, n5, n5_label, n5_pos FROM (
  SELECT 'REGLEMENT_INTERIEUR' AS n4 UNION ALL SELECT 'ACCORDS_ENTREPRISE'
  UNION ALL SELECT 'AFFICHAGES_OBLIGATOIRES' UNION ALL SELECT 'DUER'
  UNION ALL SELECT 'MUTUELLE_PREVOYANCE' UNION ALL SELECT 'FORMATIONS_OBLIGATOIRES'
  UNION ALL SELECT 'REPRESENTANTS_PERSONNEL' UNION ALL SELECT 'ARCHIVES'
) p
CROSS JOIN (
  SELECT 'VERSIONS' AS n5, 'Versions' AS n5_label, 1 AS n5_pos
  UNION ALL SELECT 'ANNEE', 'Année', 2
) l;

-- ════════════════════════════════════════════════════════════════════════
-- 06_COMPTABILITE — AJOUT 2 nouveaux N2 (SYNDIC + GESTION)
-- ════════════════════════════════════════════════════════════════════════
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `code`, `label`, `position`) VALUES
  (NULL, 2, '06_COMPTABILITE', 'SYNDIC',  'Syndic',  10),
  (NULL, 2, '06_COMPTABILITE', 'GESTION', 'Gestion', 11);

-- N3 sous SYNDIC
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '06_COMPTABILITE', 'SYNDIC', 'FICHIER_CFONDS',  'Fichier C.Fonds',   1),
  (NULL, 3, '06_COMPTABILITE', 'SYNDIC', 'REMISE_CHEQUES',  'Remises de chèques',2),
  (NULL, 3, '06_COMPTABILITE', 'SYNDIC', 'PRELEVEMENT',     'Prélèvements',      3);

-- N3 sous GESTION
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '06_COMPTABILITE', 'GESTION', 'FICHIER_CFONDS',  'Fichier C.Fonds',   1),
  (NULL, 3, '06_COMPTABILITE', 'GESTION', 'REMISE_CHEQUES',  'Remises de chèques',2),
  (NULL, 3, '06_COMPTABILITE', 'GESTION', 'PRELEVEMENT',     'Prélèvements',      3);
SQL,
];
