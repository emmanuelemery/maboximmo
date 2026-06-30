<?php
/**
 * Migration : Import des biens à vendre « lignes jaunes » (non encore en BDD).
 *
 * Contexte : biens du tableau de vente GROUPE SIR / HIMMALAYA / CB FINANCES qui
 * n'existaient pas en base car non gérés par la régie EMERY et (pour la plupart)
 * non loués. On les crée pour qu'ils apparaissent dans la page Portefeuilles.
 *
 * Source : capture Excel (colonnes B..R), lignes dont la VILLE est surlignée jaune.
 *   prix_demande_initial = P (PRIX DE VENTE 23/04/26), repli sur O si P vide
 *   loyer_hc (mensuel)   = N (LOYER HT/AN) / 12
 *   P (prix 23/04), Q (net vendeur), R (hono acquéreur), locataire, bail,
 *   parking, photovoltaïque → conservés dans `commentaire`.
 *   type_commercialisation = 'vente' (tous à vendre).
 *
 * tiers-first : CB FINANCES n'existait pas → création tiers + tiers_roles + proprietaire.
 *
 * Rejouable : chaque bien est gardé par son reference_bien (VTE-2026-0xx) via NOT EXISTS.
 * Idem tiers/proprietaire CB FINANCES (NOT EXISTS sur raison_sociale/societe).
 */
return [
    'id'          => '20260605_import_biens_vente_groupe_sir',
    'title'       => 'Import biens à vendre (lignes jaunes) — GROUPE SIR / HIMMALAYA / CB FINANCES',
    'description' => '10 biens commerciaux à vendre non encore créés. tiers-first pour CB FINANCES. prix_demande_initial=O, loyer_hc=N/12, P/Q/R+bail en commentaire. Rejouable (NOT EXISTS sur reference_bien).',
    'created_at'  => '2026-06-05',
    'sql' => <<<'SQL'

-- ════════════════════════════════════════════════════════════════════
-- 1) CB FINANCES : création tiers-first (nouveau propriétaire moral)
-- ════════════════════════════════════════════════════════════════════
INSERT INTO `tiers` (type_tiers, raison_sociale, nom, prenom, actif, date_creation, date_modification)
SELECT 'personne_morale', 'CB FINANCES', '', '', 1, NOW(), NOW()
FROM (SELECT 1) t
WHERE NOT EXISTS (SELECT 1 FROM `tiers` WHERE raison_sociale = 'CB FINANCES');

INSERT INTO `tiers_roles` (id_tiers, role_code, actif, priorite, date_creation, date_modification)
SELECT ti.id, 'proprietaire', 1, 0, NOW(), NOW()
FROM `tiers` ti
WHERE ti.raison_sociale = 'CB FINANCES'
  AND NOT EXISTS (SELECT 1 FROM `tiers_roles` tr WHERE tr.id_tiers = ti.id AND tr.role_code = 'proprietaire');

INSERT INTO `proprietaires` (id_tiers, type_personne, nom, prenom, societe, actif, date_creation, date_modification)
SELECT ti.id, 'morale', '', '', 'CB FINANCES', 1, NOW(), NOW()
FROM `tiers` ti
WHERE ti.raison_sociale = 'CB FINANCES'
  AND NOT EXISTS (SELECT 1 FROM `proprietaires` p WHERE p.societe = 'CB FINANCES');

-- ════════════════════════════════════════════════════════════════════
-- 2) Biens à vendre (1 INSERT par bien, gardé par reference_bien)
--    id_type_bien : 8=Local d'activité, 5=Local commercial, 6=Bureau
-- ════════════════════════════════════════════════════════════════════

-- #1 GROUPE SIR — BOURG-EN-BRESSE
INSERT INTO `biens` (reference_bien, id_type_bien, id_proprietaire, type_commercialisation, usage_bien, statut_bien, adresse_1, code_postal, ville, surface_habitable, surface_totale, parking_nb, loyer_hc, prix_demande_initial, prix_vente_estime, commentaire, date_creation, date_modification)
SELECT 'VTE-2026-001', 8, (SELECT id FROM proprietaires WHERE societe='SARL GROUPE SIR' LIMIT 1), 'vente', 'professionnel', 'actif', "PARC CÉNORD - 101 RUE RADIOR", '01000', 'BOURG-EN-BRESSE', 1001, 1001, 12, 8166.67, 1635000, 1635000,
 "Import vente jaune. Locataire CDO SAINT-GOBAIN, bail 01/02/2026 au 31/01/2035. Loyer HT 98 000 €/an. Parking 12. Photovoltaïque OUI. Prix V. 01/01/26: 1 635 000 €.", NOW(), NOW()
FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM `biens` WHERE reference_bien = 'VTE-2026-001');

-- #2 GROUPE SIR — DAVEZIEUX
INSERT INTO `biens` (reference_bien, id_type_bien, id_proprietaire, type_commercialisation, usage_bien, statut_bien, adresse_1, code_postal, ville, surface_habitable, surface_totale, parking_nb, loyer_hc, prix_demande_initial, prix_vente_estime, commentaire, date_creation, date_modification)
SELECT 'VTE-2026-002', 8, (SELECT id FROM proprietaires WHERE societe='SARL GROUPE SIR' LIMIT 1), 'vente', 'professionnel', 'actif', "RUE DU BOSQUET DES CHENES", '07430', 'DAVEZIEUX', 361, 361, 9, 3750.00, 350000, 350000,
 "Import vente jaune. Ex CEP (vacant). Loyer HT 45 000 €/an. Parking 9. Photovoltaïque OUI. Prix V. 01/01/26: 645 000 €. Prix vente 23/04/26: 350 000 €. Net vendeur (30/05/26): 325 000 €. Hono charge acquéreur: 19 500 €.", NOW(), NOW()
FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM `biens` WHERE reference_bien = 'VTE-2026-002');

-- #3 GROUPE SIR — BOURGES
INSERT INTO `biens` (reference_bien, id_type_bien, id_proprietaire, type_commercialisation, usage_bien, statut_bien, adresse_1, code_postal, ville, surface_habitable, surface_totale, parking_nb, loyer_hc, prix_demande_initial, prix_vente_estime, commentaire, date_creation, date_modification)
SELECT 'VTE-2026-003', 8, (SELECT id FROM proprietaires WHERE societe='SARL GROUPE SIR' LIMIT 1), 'vente', 'professionnel', 'actif', "25 BIS BLV DE LA REPUBLIQUE", '18000', 'BOURGES', 539, 539, 5, 4500.00, 420000, 420000,
 "Import vente jaune. Ex CEP (vacant). Loyer HT 54 000 €/an. Parking 5. Prix V. 01/01/26: 690 000 €. Prix vente 23/04/26: 420 000 €. Net vendeur (30/05/26): 400 000 €. Hono charge acquéreur: 24 000 €.", NOW(), NOW()
FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM `biens` WHERE reference_bien = 'VTE-2026-003');

-- #4 GROUPE SIR — ECHIROLLES
INSERT INTO `biens` (reference_bien, id_type_bien, id_proprietaire, type_commercialisation, usage_bien, statut_bien, adresse_1, code_postal, ville, surface_habitable, surface_totale, parking_nb, loyer_hc, prix_demande_initial, prix_vente_estime, commentaire, date_creation, date_modification)
SELECT 'VTE-2026-004', 8, (SELECT id FROM proprietaires WHERE societe='SARL GROUPE SIR' LIMIT 1), 'vente', 'professionnel', 'actif', "2 RUE DU 19 MARS 1692", '38130', 'ECHIROLLES', 534, 534, 8, 3900.00, 380000, 380000,
 "Import vente jaune. Ex CEP (vacant). Loyer HT 46 800 €/an. Parking 8. Prix V. 01/01/26: 690 000 €. Prix vente 23/04/26: 380 000 €. Net vendeur (30/05/26): 340 000 €. Hono charge acquéreur: 20 400 €.", NOW(), NOW()
FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM `biens` WHERE reference_bien = 'VTE-2026-004');

-- #5 GROUPE SIR — CRISSEY
INSERT INTO `biens` (reference_bien, id_type_bien, id_proprietaire, type_commercialisation, usage_bien, statut_bien, adresse_1, code_postal, ville, surface_habitable, surface_totale, parking_nb, loyer_hc, prix_demande_initial, prix_vente_estime, commentaire, date_creation, date_modification)
SELECT 'VTE-2026-005', 8, (SELECT id FROM proprietaires WHERE societe='SARL GROUPE SIR' LIMIT 1), 'vente', 'professionnel', 'actif', "2 RUE A. LAMARTINE", '71530', 'CRISSEY', 1959, 1959, 14, 9833.33, 1475000, 1475000,
 "Import vente jaune. Ex CEP (vacant). Loyer HT 118 000 €/an. Parking 14. Photovoltaïque OUI. Prix V. 01/01/26: 1 475 000 €. Prix vente 23/04/26: 1 475 000 €. Net vendeur (30/05/26): 1 200 000 €. Hono charge acquéreur: 72 000 €.", NOW(), NOW()
FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM `biens` WHERE reference_bien = 'VTE-2026-005');

-- #6 GROUPE SIR — LA RAVOIRE
INSERT INTO `biens` (reference_bien, id_type_bien, id_proprietaire, type_commercialisation, usage_bien, statut_bien, adresse_1, code_postal, ville, surface_habitable, surface_totale, parking_nb, loyer_hc, prix_demande_initial, prix_vente_estime, commentaire, date_creation, date_modification)
SELECT 'VTE-2026-006', 8, (SELECT id FROM proprietaires WHERE societe='SARL GROUPE SIR' LIMIT 1), 'vente', 'professionnel', 'actif', "635 RUE P.ET M. CURIE", '73490', 'LA RAVOIRE', 732, 732, 22, 6728.33, 900000, 900000,
 "Import vente jaune. Ex CEP (vacant). Loyer HT 80 740 €/an. Parking 22. Photovoltaïque OUI. Prix V. 01/01/26: 1 345 000 €. Prix vente 23/04/26: 900 000 €. Net vendeur (30/05/26): 760 000 €. Hono charge acquéreur: 45 600 €.", NOW(), NOW()
FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM `biens` WHERE reference_bien = 'VTE-2026-006');

-- #7 GROUPE SIR — VILLEFRANCHE-SUR-SAONE (573 RUE D'ANSE) — local commercial vide neuf
INSERT INTO `biens` (reference_bien, id_type_bien, id_proprietaire, type_commercialisation, usage_bien, statut_bien, adresse_1, code_postal, ville, surface_habitable, surface_totale, parking_nb, loyer_hc, prix_demande_initial, prix_vente_estime, commentaire, date_creation, date_modification)
SELECT 'VTE-2026-007', 5, (SELECT id FROM proprietaires WHERE societe='SARL GROUPE SIR' LIMIT 1), 'vente', 'professionnel', 'actif', "573 RUE D'ANSE", '69400', 'VILLEFRANCHE-SUR-SAONE', 275, 275, 3, NULL, 400000, 400000,
 "Import vente jaune. VIDE — immeuble neuf. Parking 3. Prix V. 01/01/26: 680 000 €. Prix vente 23/04/26: 400 000 €. Net vendeur (30/05/26): 325 000 €. Hono charge acquéreur: 19 500 €.", NOW(), NOW()
FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM `biens` WHERE reference_bien = 'VTE-2026-007');

-- #8 GROUPE SIR — SAINT-YVOINE (CHEMIN DU TREZINS) — local commercial à louer
INSERT INTO `biens` (reference_bien, id_type_bien, id_proprietaire, type_commercialisation, usage_bien, statut_bien, adresse_1, code_postal, ville, surface_habitable, surface_totale, parking_nb, loyer_hc, prix_demande_initial, prix_vente_estime, commentaire, date_creation, date_modification)
SELECT 'VTE-2026-008', 5, (SELECT id FROM proprietaires WHERE societe='SARL GROUPE SIR' LIMIT 1), 'vente', 'professionnel', 'actif', "CHEMIN DU TREZINS", '63500', 'SAINT-YVOINE', 2331, 2331, NULL, NULL, 860000, 860000,
 "Import vente jaune. À LOUER (vacant). Prix V. 01/01/26: 860 000 €. Net vendeur (30/05/26): 585 000 €. Hono charge acquéreur: 35 100 €.", NOW(), NOW()
FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM `biens` WHERE reference_bien = 'VTE-2026-008');

-- #9 HIMMALAYA — CAUDAN
INSERT INTO `biens` (reference_bien, id_type_bien, id_proprietaire, type_commercialisation, usage_bien, statut_bien, adresse_1, code_postal, ville, surface_habitable, surface_totale, parking_nb, loyer_hc, prix_demande_initial, prix_vente_estime, commentaire, date_creation, date_modification)
SELECT 'VTE-2026-009', 5, (SELECT id FROM proprietaires WHERE societe='SCI HIMMALAYA' LIMIT 1), 'vente', 'professionnel', 'actif', "215 RUE JOSEPH BIGOT", '56850', 'CAUDAN', 1410, 1410, NULL, 8100.17, 850000, 850000,
 "Import vente jaune. Locataire AKZO NOBEL. Loyer HT 97 202 €/an. Prix V. 01/01/26: 1 350 000 €. Prix vente 23/04/26: 850 000 €. Net vendeur (30/05/26): 780 000 €. Hono charge acquéreur: 46 800 €.", NOW(), NOW()
FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM `biens` WHERE reference_bien = 'VTE-2026-009');

-- #10 CB FINANCES — VENISSIEUX (MOULIN A VENT) — immeuble de bureaux à louer
INSERT INTO `biens` (reference_bien, id_type_bien, id_proprietaire, type_commercialisation, usage_bien, statut_bien, adresse_1, code_postal, ville, surface_habitable, surface_totale, parking_nb, loyer_hc, prix_demande_initial, prix_vente_estime, commentaire, date_creation, date_modification)
SELECT 'VTE-2026-010', 6, (SELECT id FROM `proprietaires` WHERE societe = 'CB FINANCES' LIMIT 1), 'vente', 'professionnel', 'actif', "MOULIN A VENT", '69200', 'VENISSIEUX', 1723, 1723, NULL, NULL, 1450000, 1450000,
 "Import vente jaune. À LOUER (immeuble de bureaux vacant). Prix V. 01/01/26: 1 950 000 €. Prix vente 23/04/26: 1 450 000 €. Net vendeur (30/05/26): 1 000 000 €. Hono charge acquéreur: 60 000 €.", NOW(), NOW()
FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM `biens` WHERE reference_bien = 'VTE-2026-010');

-- ════════════════════════════════════════════════════════════════════
-- 3) Prix de vente courant dans bien_prix (source de vérité) pour chaque bien créé
-- ════════════════════════════════════════════════════════════════════
INSERT INTO `bien_prix` (id_bien, type_valeur, scenario_code, montant, source, is_courant, date_validation)
SELECT b.id, 'prix_vente', 'courant', b.prix_demande_initial, 'import_vente_jaune_2026', 1, NOW()
FROM `biens` b
WHERE b.reference_bien LIKE 'VTE-2026-0%'
  AND b.prix_demande_initial IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `bien_prix` bp
    WHERE bp.id_bien = b.id AND bp.type_valeur = 'prix_vente' AND bp.scenario_code = 'courant'
  );

SQL,
];
