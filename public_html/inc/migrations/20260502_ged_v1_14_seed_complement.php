<?php
/**
 * Migration v1.1 — Seed COMPLÉMENT : N3-N5 manquants pour SYNDIC + GESTION_LOCATIVE + TRANSACTION
 *
 * Complète le seed initial de la migration 10 qui n'avait qu'un échantillon
 * SYNDIC (AG + CONTRATS + IMMEUBLE) et GESTION_LOCATIVE (PROPRIETAIRES + BIENS
 * + LOCATAIRES partiel).
 *
 * Ajoute :
 *   - SYNDIC > IMMEUBLES > IMMEUBLE > N4 manquants (CONTROLES_DIAGNOSTICS N5,
 *     COMPTABILITE N5, TRAVAUX N5, SINISTRES N5, MUTATIONS N5, COURRIERS N5,
 *     MAILS N5)
 *   - GESTION_LOCATIVE > MANDATS, LOCATION_CANDIDATURES, BAUX, ETATS_DES_LIEUX,
 *     COMPTABILITE_GESTION, FISCALITE, FOURNISSEURS_INTERVENTIONS,
 *     TRAVAUX_SINISTRES, CONTENTIEUX_GLI, ASSURANCES, DIAGNOSTICS,
 *     COURRIERS_MAILS — N3/N4/N5 selon brief
 *   - TRANSACTION > toutes les sous-rubriques (PROPRIETAIRES, ESTIMATIONS,
 *     APPORTEURS_AFFAIRES, CADASTRES, ARCHIVES) — N3/N4/N5 selon brief
 *
 * Idempotent (INSERT IGNORE). AJOUT uniquement.
 */

return [
    'id'          => '20260502_ged_v1_14_seed_complement',
    'title'       => 'Ma GED Box V1.1 — seed COMPLÉMENT N3-N5 (SYNDIC manquants + GESTION_LOCATIVE manquants + TRANSACTION complet)',
    'description' => "Complète le seed initial : SYNDIC > IMMEUBLE > N5 pour COMPTABILITE/TRAVAUX/SINISTRES/MUTATIONS/COURRIERS/MAILS/CONTROLES_DIAGNOSTICS. GESTION_LOCATIVE > MANDATS/LOCATION_CANDIDATURES/BAUX/EDL/COMPTA_GESTION/FISCALITE/FOURNISSEURS/TRAVAUX/CONTENTIEUX_GLI/ASSURANCES/DIAGNOSTICS/COURRIERS_MAILS. TRANSACTION > toutes rubriques N3/N4/N5. Idempotent (INSERT IGNORE).",
    'created_at'  => '2026-05-02',
    'sql' => <<<'SQL'
-- ════════════════════════════════════════════════════════════════════════
-- SYNDIC > IMMEUBLES > IMMEUBLE > N5 manquants
-- ════════════════════════════════════════════════════════════════════════
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`) VALUES
  -- COMPTABILITE
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'COMPTABILITE', 'EXERCICE',           'Exercice',            1),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'COMPTABILITE', 'BUDGET_PREVISIONNEL','Budget prévisionnel', 2),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'COMPTABILITE', 'APPELS_FONDS',       'Appels de fonds',     3),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'COMPTABILITE', 'COMPTES_ANNUELS',    'Comptes annuels',     4),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'COMPTABILITE', 'BALANCE',            'Balance',             5),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'COMPTABILITE', 'GRAND_LIVRE',        'Grand livre',         6),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'COMPTABILITE', 'RELEVES_BANCAIRES',  'Relevés bancaires',   7),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'COMPTABILITE', 'RAPPROCHEMENTS',     'Rapprochements',      8),

  -- TRAVAUX
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'TRAVAUX', 'DOSSIER_TRAVAUX', 'Dossier travaux', 1),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'TRAVAUX', 'DEVIS',           'Devis',           2),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'TRAVAUX', 'FACTURES',        'Factures',        3),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'TRAVAUX', 'ORDRES_SERVICE',  'Ordres de service', 4),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'TRAVAUX', 'SUIVI_CHANTIER',  'Suivi chantier',  5),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'TRAVAUX', 'PHOTOS_AVANT',    'Photos avant',    6),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'TRAVAUX', 'PHOTOS_APRES',    'Photos après',    7),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'TRAVAUX', 'RECEPTION',       'Réception',       8),

  -- SINISTRES
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'SINISTRES', 'DDE',          'DDE (Déclaration)', 1),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'SINISTRES', 'INCENDIE',     'Incendie',          2),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'SINISTRES', 'INFILTRATION', 'Infiltration',      3),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'SINISTRES', 'VANDALISME',   'Vandalisme',        4),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'SINISTRES', 'ASSURANCE',    'Assurance',         5),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'SINISTRES', 'EXPERTISES',   'Expertises',        6),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'SINISTRES', 'PHOTOS',       'Photos',            7),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'SINISTRES', 'CLOTURES',     'Clôtures',          8),

  -- MUTATIONS
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'MUTATIONS', 'PRE_ETAT_DATE',          'Pré-état daté',          1),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'MUTATIONS', 'ETAT_DATE',              'État daté',              2),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'MUTATIONS', 'QUESTIONNAIRES_NOTAIRES','Questionnaires notaires',3),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'MUTATIONS', 'NOTAIRES',               'Notaires',               4),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'MUTATIONS', 'VENTES',                 'Ventes',                 5),

  -- COURRIERS
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'COURRIERS', 'COPROPRIETAIRES',   'Copropriétaires',   1),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'COURRIERS', 'CONSEIL_SYNDICAL',  'Conseil syndical',  2),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'COURRIERS', 'FOURNISSEURS',      'Fournisseurs',      3),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'COURRIERS', 'ADMINISTRATION',    'Administration',    4),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'COURRIERS', 'AVOCATS_HUISSIERS', 'Avocats/Huissiers', 5),

  -- MAILS
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'MAILS', 'ENTRANTS',                'Entrants',                1),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'MAILS', 'SORTANTS',                'Sortants',                2),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'MAILS', 'PIECES_JOINTES',          'Pièces jointes',          3),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'MAILS', 'CONVERSATIONS_IMPORTANTES','Conversations importantes',4),

  -- CONTROLES_DIAGNOSTICS
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'CONTROLES_DIAGNOSTICS', 'DTA',               'DTA',               1),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'CONTROLES_DIAGNOSTICS', 'PLOMB',             'Plomb',             2),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'CONTROLES_DIAGNOSTICS', 'AMIANTE',           'Amiante',           3),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'CONTROLES_DIAGNOSTICS', 'ASCENSEUR',         'Ascenseur',         4),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'CONTROLES_DIAGNOSTICS', 'SECURITE_INCENDIE', 'Sécurité incendie', 5),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'CONTROLES_DIAGNOSTICS', 'ELECTRICITE',       'Électricité',       6),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'CONTROLES_DIAGNOSTICS', 'GAZ',               'Gaz',               7),
  (NULL, 5, '04_SYNDIC', 'IMMEUBLES', 'IMMEUBLE', 'CONTROLES_DIAGNOSTICS', 'AUDITS',            'Audits',            8);

-- ════════════════════════════════════════════════════════════════════════
-- GESTION_LOCATIVE > rubriques manquantes
-- ════════════════════════════════════════════════════════════════════════

-- ─── MANDATS (N3=MANDATS_GESTION/LOCATION/etc directs) ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '03_GESTION_LOCATIVE', 'MANDATS', 'MANDATS_GESTION',     'Mandats de gestion',    1),
  (NULL, 3, '03_GESTION_LOCATIVE', 'MANDATS', 'MANDATS_LOCATION',    'Mandats de location',   2),
  (NULL, 3, '03_GESTION_LOCATIVE', 'MANDATS', 'DELEGATIONS_MANDAT',  'Délégations',           3),
  (NULL, 3, '03_GESTION_LOCATIVE', 'MANDATS', 'RESILIATIONS_MANDAT', 'Résiliations',          4),
  (NULL, 3, '03_GESTION_LOCATIVE', 'MANDATS', 'MODELES_MANDATS',     'Modèles',               5),
  (NULL, 3, '03_GESTION_LOCATIVE', 'MANDATS', 'ARCHIVES',            'Archives',              6);

-- ─── LOCATION_CANDIDATURES ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '03_GESTION_LOCATIVE', 'LOCATION_CANDIDATURES', 'DOSSIERS_A_TRAITER', 'Dossiers à traiter', 1),
  (NULL, 3, '03_GESTION_LOCATIVE', 'LOCATION_CANDIDATURES', 'DOSSIERS_COMPLETS',  'Dossiers complets',  2),
  (NULL, 3, '03_GESTION_LOCATIVE', 'LOCATION_CANDIDATURES', 'DOSSIERS_REFUSES',   'Dossiers refusés',   3),
  (NULL, 3, '03_GESTION_LOCATIVE', 'LOCATION_CANDIDATURES', 'DOSSIERS_VALIDES',   'Dossiers validés',   4),
  (NULL, 3, '03_GESTION_LOCATIVE', 'LOCATION_CANDIDATURES', 'VISITES',            'Visites',            5),
  (NULL, 3, '03_GESTION_LOCATIVE', 'LOCATION_CANDIDATURES', 'FICHES_VISITE',      'Fiches visite',      6),
  (NULL, 3, '03_GESTION_LOCATIVE', 'LOCATION_CANDIDATURES', 'COMMANDES_PLAQUES',  'Commandes plaques',  7),
  (NULL, 3, '03_GESTION_LOCATIVE', 'LOCATION_CANDIDATURES', 'ARCHIVES',           'Archives',           8);

-- ─── BAUX ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '03_GESTION_LOCATIVE', 'BAUX', 'BAUX_HABITATION',     'Baux habitation',     1),
  (NULL, 3, '03_GESTION_LOCATIVE', 'BAUX', 'BAUX_COMMERCIAUX',    'Baux commerciaux',    2),
  (NULL, 3, '03_GESTION_LOCATIVE', 'BAUX', 'BAUX_PROFESSIONNELS', 'Baux professionnels', 3),
  (NULL, 3, '03_GESTION_LOCATIVE', 'BAUX', 'AVENANTS',            'Avenants',            4),
  (NULL, 3, '03_GESTION_LOCATIVE', 'BAUX', 'ACTES_CAUTION',       'Actes de caution',    5),
  (NULL, 3, '03_GESTION_LOCATIVE', 'BAUX', 'DOCS_LIES_AU_BAIL',   'Docs liés au bail',   6),
  (NULL, 3, '03_GESTION_LOCATIVE', 'BAUX', 'MODELES_BAUX',        'Modèles',             7),
  (NULL, 3, '03_GESTION_LOCATIVE', 'BAUX', 'ARCHIVES',            'Archives',            8);

-- ─── ETATS_DES_LIEUX ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '03_GESTION_LOCATIVE', 'ETATS_DES_LIEUX', 'EDL_ENTREE',                  'EDL entrée',                  1),
  (NULL, 3, '03_GESTION_LOCATIVE', 'ETATS_DES_LIEUX', 'EDL_SORTIE',                  'EDL sortie',                  2),
  (NULL, 3, '03_GESTION_LOCATIVE', 'ETATS_DES_LIEUX', 'PHOTOS_EDL',                  'Photos EDL',                  3),
  (NULL, 3, '03_GESTION_LOCATIVE', 'ETATS_DES_LIEUX', 'CHIFFRAGE_REMISE_ETAT',       'Chiffrage remise en état',    4),
  (NULL, 3, '03_GESTION_LOCATIVE', 'ETATS_DES_LIEUX', 'RESTITUTION_DEPOT_GARANTIE',  'Restitution dépôt garantie',  5),
  (NULL, 3, '03_GESTION_LOCATIVE', 'ETATS_DES_LIEUX', 'ARCHIVES',                    'Archives',                    6);

-- ─── COMPTABILITE_GESTION ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '03_GESTION_LOCATIVE', 'COMPTABILITE_GESTION', 'FACTURES_GESTION',      'Factures gestion',      1),
  (NULL, 3, '03_GESTION_LOCATIVE', 'COMPTABILITE_GESTION', 'HONORAIRES',            'Honoraires',            2),
  (NULL, 3, '03_GESTION_LOCATIVE', 'COMPTABILITE_GESTION', 'PRELEVEMENTS',          'Prélèvements',          3),
  (NULL, 3, '03_GESTION_LOCATIVE', 'COMPTABILITE_GESTION', 'VIREMENTS_PROPRIETAIRES','Virements propriétaires',4),
  (NULL, 3, '03_GESTION_LOCATIVE', 'COMPTABILITE_GESTION', 'VIREMENTS_LOCATAIRES',  'Virements locataires',  5),
  (NULL, 3, '03_GESTION_LOCATIVE', 'COMPTABILITE_GESTION', 'VIREMENTS_FOURNISSEURS','Virements fournisseurs',6),
  (NULL, 3, '03_GESTION_LOCATIVE', 'COMPTABILITE_GESTION', 'QUITTANCEMENTS',        'Quittancements',        7),
  (NULL, 3, '03_GESTION_LOCATIVE', 'COMPTABILITE_GESTION', 'CAF',                   'CAF',                   8),
  (NULL, 3, '03_GESTION_LOCATIVE', 'COMPTABILITE_GESTION', 'BANQUES_RAPPROCHEMENTS','Banques/Rapprochements',9),
  (NULL, 3, '03_GESTION_LOCATIVE', 'COMPTABILITE_GESTION', 'BALANCES',              'Balances',              10),
  (NULL, 3, '03_GESTION_LOCATIVE', 'COMPTABILITE_GESTION', 'RELEVES_PROPRIETAIRES', 'Relevés propriétaires', 11),
  (NULL, 3, '03_GESTION_LOCATIVE', 'COMPTABILITE_GESTION', 'REMB_DG_LOCATAIRES',    'Remb. DG locataires',   12),
  (NULL, 3, '03_GESTION_LOCATIVE', 'COMPTABILITE_GESTION', 'CHORUS',                'Chorus',                13),
  (NULL, 3, '03_GESTION_LOCATIVE', 'COMPTABILITE_GESTION', 'ARCHIVES',              'Archives',              14);

-- ─── FISCALITE ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '03_GESTION_LOCATIVE', 'FISCALITE', 'TAXES_FONCIERES',         'Taxes foncières',          1),
  (NULL, 3, '03_GESTION_LOCATIVE', 'FISCALITE', 'TAXES_LOGEMENTS_VACANTS', 'Taxes logements vacants',  2),
  (NULL, 3, '03_GESTION_LOCATIVE', 'FISCALITE', 'REVENUS_FONCIERS',        'Revenus fonciers',         3),
  (NULL, 3, '03_GESTION_LOCATIVE', 'FISCALITE', 'DECLARATIONS_BIENS',      'Déclarations biens',       4),
  (NULL, 3, '03_GESTION_LOCATIVE', 'FISCALITE', 'DEGREVEMENTS',            'Dégrèvements',             5),
  (NULL, 3, '03_GESTION_LOCATIVE', 'FISCALITE', 'CRL',                     'CRL',                      6),
  (NULL, 3, '03_GESTION_LOCATIVE', 'FISCALITE', 'ARCHIVES',                'Archives',                 7);

-- ─── FOURNISSEURS_INTERVENTIONS ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '03_GESTION_LOCATIVE', 'FOURNISSEURS_INTERVENTIONS', 'FOURNISSEUR', 'Fournisseur', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '03_GESTION_LOCATIVE', 'FOURNISSEURS_INTERVENTIONS', 'FOURNISSEUR', 'RIB',           'RIB',           1),
  (NULL, 4, '03_GESTION_LOCATIVE', 'FOURNISSEURS_INTERVENTIONS', 'FOURNISSEUR', 'DEVIS',         'Devis',         2),
  (NULL, 4, '03_GESTION_LOCATIVE', 'FOURNISSEURS_INTERVENTIONS', 'FOURNISSEUR', 'FACTURES',      'Factures',      3),
  (NULL, 4, '03_GESTION_LOCATIVE', 'FOURNISSEURS_INTERVENTIONS', 'FOURNISSEUR', 'CONTRATS',      'Contrats',      4),
  (NULL, 4, '03_GESTION_LOCATIVE', 'FOURNISSEURS_INTERVENTIONS', 'FOURNISSEUR', 'INTERVENTIONS', 'Interventions', 5),
  (NULL, 4, '03_GESTION_LOCATIVE', 'FOURNISSEURS_INTERVENTIONS', 'FOURNISSEUR', 'COURRIERS',     'Courriers',     6),
  (NULL, 4, '03_GESTION_LOCATIVE', 'FOURNISSEURS_INTERVENTIONS', 'FOURNISSEUR', 'MAILS',         'Mails',         7),
  (NULL, 4, '03_GESTION_LOCATIVE', 'FOURNISSEURS_INTERVENTIONS', 'FOURNISSEUR', 'ARCHIVES',      'Archives',      8);

-- ─── TRAVAUX_SINISTRES ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '03_GESTION_LOCATIVE', 'TRAVAUX_SINISTRES', 'TRAVAUX',   'Travaux',   1),
  (NULL, 3, '03_GESTION_LOCATIVE', 'TRAVAUX_SINISTRES', 'SINISTRES', 'Sinistres', 2),
  (NULL, 3, '03_GESTION_LOCATIVE', 'TRAVAUX_SINISTRES', 'DEVIS',     'Devis',     3),
  (NULL, 3, '03_GESTION_LOCATIVE', 'TRAVAUX_SINISTRES', 'FACTURES',  'Factures',  4),
  (NULL, 3, '03_GESTION_LOCATIVE', 'TRAVAUX_SINISTRES', 'PHOTOS',    'Photos',    5),
  (NULL, 3, '03_GESTION_LOCATIVE', 'TRAVAUX_SINISTRES', 'COURRIERS', 'Courriers', 6),
  (NULL, 3, '03_GESTION_LOCATIVE', 'TRAVAUX_SINISTRES', 'MAILS',     'Mails',     7),
  (NULL, 3, '03_GESTION_LOCATIVE', 'TRAVAUX_SINISTRES', 'ARCHIVES',  'Archives',  8);

-- ─── CONTENTIEUX_GLI ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '03_GESTION_LOCATIVE', 'CONTENTIEUX_GLI', 'DOSSIERS_GLI',       'Dossiers GLI',         1),
  (NULL, 3, '03_GESTION_LOCATIVE', 'CONTENTIEUX_GLI', 'DOSSIERS_HORS_GLI',  'Dossiers hors GLI',    2),
  (NULL, 3, '03_GESTION_LOCATIVE', 'CONTENTIEUX_GLI', 'IMPAYES',            'Impayés',              3),
  (NULL, 3, '03_GESTION_LOCATIVE', 'CONTENTIEUX_GLI', 'RELANCES',           'Relances',             4),
  (NULL, 3, '03_GESTION_LOCATIVE', 'CONTENTIEUX_GLI', 'HUISSIERS',          'Huissiers',            5),
  (NULL, 3, '03_GESTION_LOCATIVE', 'CONTENTIEUX_GLI', 'ASSIGNATIONS',       'Assignations',         6),
  (NULL, 3, '03_GESTION_LOCATIVE', 'CONTENTIEUX_GLI', 'COMMANDES_DE_PAYER', 'Commandement de payer',7),
  (NULL, 3, '03_GESTION_LOCATIVE', 'CONTENTIEUX_GLI', 'EXPULSIONS',         'Expulsions',           8),
  (NULL, 3, '03_GESTION_LOCATIVE', 'CONTENTIEUX_GLI', 'ATD_SAISIES',        'ATD/Saisies',          9),
  (NULL, 3, '03_GESTION_LOCATIVE', 'CONTENTIEUX_GLI', 'ARCHIVES',           'Archives',             10);

-- ─── ASSURANCES ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '03_GESTION_LOCATIVE', 'ASSURANCES', 'PNO',                            'PNO',                            1),
  (NULL, 3, '03_GESTION_LOCATIVE', 'ASSURANCES', 'GLI',                            'GLI',                            2),
  (NULL, 3, '03_GESTION_LOCATIVE', 'ASSURANCES', 'ASSURANCE_PROPRIETAIRE_BAILLEUR','Assurance propriétaire bailleur',3),
  (NULL, 3, '03_GESTION_LOCATIVE', 'ASSURANCES', 'ATTESTATIONS_ASSURANCE',         'Attestations',                   4),
  (NULL, 3, '03_GESTION_LOCATIVE', 'ASSURANCES', 'CONTRATS',                       'Contrats',                       5),
  (NULL, 3, '03_GESTION_LOCATIVE', 'ASSURANCES', 'SINISTRES',                      'Sinistres',                      6),
  (NULL, 3, '03_GESTION_LOCATIVE', 'ASSURANCES', 'PROTECTION_JURIDIQUE',           'Protection juridique',           7),
  (NULL, 3, '03_GESTION_LOCATIVE', 'ASSURANCES', 'ARCHIVES',                       'Archives',                       8);

-- ─── DIAGNOSTICS ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '03_GESTION_LOCATIVE', 'DIAGNOSTICS', 'DPE',                          'DPE',                          1),
  (NULL, 3, '03_GESTION_LOCATIVE', 'DIAGNOSTICS', 'ERP_ERNMT',                    'ERP/ERNMT',                    2),
  (NULL, 3, '03_GESTION_LOCATIVE', 'DIAGNOSTICS', 'AMIANTE',                      'Amiante',                      3),
  (NULL, 3, '03_GESTION_LOCATIVE', 'DIAGNOSTICS', 'PLOMB',                        'Plomb',                        4),
  (NULL, 3, '03_GESTION_LOCATIVE', 'DIAGNOSTICS', 'ELECTRICITE',                  'Électricité',                  5),
  (NULL, 3, '03_GESTION_LOCATIVE', 'DIAGNOSTICS', 'GAZ',                          'Gaz',                          6),
  (NULL, 3, '03_GESTION_LOCATIVE', 'DIAGNOSTICS', 'SURFACE',                      'Surface (Carrez/Boutin)',      7),
  (NULL, 3, '03_GESTION_LOCATIVE', 'DIAGNOSTICS', 'ETAT_RISQUES',                 'État des risques',             8),
  (NULL, 3, '03_GESTION_LOCATIVE', 'DIAGNOSTICS', 'DOCUMENTATION_REGLEMENTAIRE',  'Documentation réglementaire',  9),
  (NULL, 3, '03_GESTION_LOCATIVE', 'DIAGNOSTICS', 'ARCHIVES',                     'Archives',                     10);

-- ════════════════════════════════════════════════════════════════════════
-- TRANSACTION — toutes les rubriques N3/N4/N5
-- ════════════════════════════════════════════════════════════════════════

-- ─── PROPRIETAIRES ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '05_TRANSACTION', 'PROPRIETAIRES', 'PROPRIETAIRE', 'Propriétaire (entité)', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '05_TRANSACTION', 'PROPRIETAIRES', 'PROPRIETAIRE', 'MANDATS_VENTE',   'Mandats de vente', 1),
  (NULL, 4, '05_TRANSACTION', 'PROPRIETAIRES', 'PROPRIETAIRE', 'BIENS_A_VENDRE',  'Biens à vendre',   2),
  (NULL, 4, '05_TRANSACTION', 'PROPRIETAIRES', 'PROPRIETAIRE', 'OFFRES_ACHAT',    'Offres d''achat',  3),
  (NULL, 4, '05_TRANSACTION', 'PROPRIETAIRES', 'PROPRIETAIRE', 'COMPROMIS',       'Compromis',        4),
  (NULL, 4, '05_TRANSACTION', 'PROPRIETAIRES', 'PROPRIETAIRE', 'ACTES',           'Actes',            5),
  (NULL, 4, '05_TRANSACTION', 'PROPRIETAIRES', 'PROPRIETAIRE', 'HONORAIRES',      'Honoraires',       6),
  (NULL, 4, '05_TRANSACTION', 'PROPRIETAIRES', 'PROPRIETAIRE', 'COURRIERS',       'Courriers',        7),
  (NULL, 4, '05_TRANSACTION', 'PROPRIETAIRES', 'PROPRIETAIRE', 'MAILS',           'Mails',            8),
  (NULL, 4, '05_TRANSACTION', 'PROPRIETAIRES', 'PROPRIETAIRE', 'ARCHIVES',        'Archives',         9);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '05_TRANSACTION', 'PROPRIETAIRES', 'PROPRIETAIRE', n4, n5, n5_label, n5_pos FROM
  (SELECT 'MANDATS_VENTE' AS n4 UNION ALL SELECT 'BIENS_A_VENDRE' UNION ALL SELECT 'OFFRES_ACHAT'
   UNION ALL SELECT 'COMPROMIS' UNION ALL SELECT 'ACTES' UNION ALL SELECT 'HONORAIRES'
   UNION ALL SELECT 'COURRIERS' UNION ALL SELECT 'MAILS' UNION ALL SELECT 'ARCHIVES') p
CROSS JOIN (SELECT 'ANNEE' AS n5, 'Année' AS n5_label, 1 AS n5_pos
            UNION ALL SELECT 'DOSSIERS', 'Dossiers', 2) l;

-- ─── ESTIMATIONS ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '05_TRANSACTION', 'ESTIMATIONS', 'A_REALISER', 'À réaliser', 1),
  (NULL, 3, '05_TRANSACTION', 'ESTIMATIONS', 'REALISEES',  'Réalisées',  2);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`)
SELECT NULL, 4, '05_TRANSACTION', 'ESTIMATIONS', n3, n4, n4_label, n4_pos FROM
  (SELECT 'A_REALISER' AS n3 UNION ALL SELECT 'REALISEES') p
CROSS JOIN (SELECT 'RAPPORTS' AS n4, 'Rapports' AS n4_label, 1 AS n4_pos
            UNION ALL SELECT 'COMPARABLES', 'Comparables', 2
            UNION ALL SELECT 'PHOTOS',      'Photos',      3
            UNION ALL SELECT 'COURRIERS',   'Courriers',   4
            UNION ALL SELECT 'MAILS',       'Mails',       5
            UNION ALL SELECT 'ARCHIVES',    'Archives',    6) l;
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '05_TRANSACTION', 'ESTIMATIONS', n3, n4, n5, n5_label, n5_pos FROM
  (SELECT 'A_REALISER' AS n3 UNION ALL SELECT 'REALISEES') p3
CROSS JOIN
  (SELECT 'RAPPORTS' AS n4 UNION ALL SELECT 'COMPARABLES' UNION ALL SELECT 'PHOTOS'
   UNION ALL SELECT 'COURRIERS' UNION ALL SELECT 'MAILS' UNION ALL SELECT 'ARCHIVES') p4
CROSS JOIN
  (SELECT 'ANNEE' AS n5, 'Année' AS n5_label, 1 AS n5_pos
   UNION ALL SELECT 'DOSSIERS', 'Dossiers', 2) l;

-- ─── APPORTEURS_AFFAIRES ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '05_TRANSACTION', 'APPORTEURS_AFFAIRES', 'APPORTEUR', 'Apporteur', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '05_TRANSACTION', 'APPORTEURS_AFFAIRES', 'APPORTEUR', 'CONVENTIONS', 'Conventions', 1),
  (NULL, 4, '05_TRANSACTION', 'APPORTEURS_AFFAIRES', 'APPORTEUR', 'COMMISSIONS', 'Commissions', 2),
  (NULL, 4, '05_TRANSACTION', 'APPORTEURS_AFFAIRES', 'APPORTEUR', 'FACTURES',    'Factures',    3),
  (NULL, 4, '05_TRANSACTION', 'APPORTEURS_AFFAIRES', 'APPORTEUR', 'COURRIERS',   'Courriers',   4),
  (NULL, 4, '05_TRANSACTION', 'APPORTEURS_AFFAIRES', 'APPORTEUR', 'MAILS',       'Mails',       5),
  (NULL, 4, '05_TRANSACTION', 'APPORTEURS_AFFAIRES', 'APPORTEUR', 'ARCHIVES',    'Archives',    6);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '05_TRANSACTION', 'APPORTEURS_AFFAIRES', 'APPORTEUR', n4, 'ANNEE', 'Année', 1 FROM
  (SELECT 'CONVENTIONS' AS n4 UNION ALL SELECT 'COMMISSIONS' UNION ALL SELECT 'FACTURES'
   UNION ALL SELECT 'COURRIERS' UNION ALL SELECT 'MAILS' UNION ALL SELECT 'ARCHIVES') p;

-- ─── CADASTRES ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '05_TRANSACTION', 'CADASTRES', 'COMMUNE', 'Commune', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '05_TRANSACTION', 'CADASTRES', 'COMMUNE', 'PLANS',      'Plans',      1),
  (NULL, 4, '05_TRANSACTION', 'CADASTRES', 'COMMUNE', 'MATRICES',   'Matrices',   2),
  (NULL, 4, '05_TRANSACTION', 'CADASTRES', 'COMMUNE', 'EXTRAITS',   'Extraits',   3),
  (NULL, 4, '05_TRANSACTION', 'CADASTRES', 'COMMUNE', 'RECHERCHES', 'Recherches', 4),
  (NULL, 4, '05_TRANSACTION', 'CADASTRES', 'COMMUNE', 'ARCHIVES',   'Archives',   5);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`)
SELECT NULL, 5, '05_TRANSACTION', 'CADASTRES', 'COMMUNE', n4, 'PARCELLES', 'Parcelles', 1 FROM
  (SELECT 'PLANS' AS n4 UNION ALL SELECT 'MATRICES' UNION ALL SELECT 'EXTRAITS'
   UNION ALL SELECT 'RECHERCHES' UNION ALL SELECT 'ARCHIVES') p;

-- ─── ARCHIVES ───
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `code`, `label`, `position`) VALUES
  (NULL, 3, '05_TRANSACTION', 'ARCHIVES', 'ANNEE', 'Année', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `code`, `label`, `position`) VALUES
  (NULL, 4, '05_TRANSACTION', 'ARCHIVES', 'ANNEE', 'DOSSIERS_CLOTURES', 'Dossiers clôturés', 1);
INSERT IGNORE INTO `ged_level_codes` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`, `label`, `position`) VALUES
  (NULL, 5, '05_TRANSACTION', 'ARCHIVES', 'ANNEE', 'DOSSIERS_CLOTURES', 'TRANSACTIONS', 'Transactions', 1);
SQL,
];
