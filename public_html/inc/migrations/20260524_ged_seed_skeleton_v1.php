<?php
/**
 * Migration : Seed squelette GED V1 (architecture hybride C — 2026-05-23)
 *
 * Crée le squelette stable N1 → N3 dans ged_folders :
 *   - 15 N1 (métiers) — 14 déjà en BDD via slugs underscore, 1 nouveau (13_fournisseurs)
 *   - 78 N2 (domaines) — 7 déjà en BDD (5 sous MAILS + 2 sous SYSTEME)
 *   - 109 N3 V1 (types de documents)
 *   - Total cible : 202 rows globales (tenant_id = NULL)
 *
 * Slugs alignés sur l'existant BDD (format NN_nom_underscores) — décision 2026-05-23.
 * Permet idempotence : 21 SKIP + 181 INSERT attendus à la 1ère exécution.
 *
 * Pattern non-standard : retourne une closure `callable` au lieu d'un `sql`.
 * Le runner admin_migrations.php ignore ce fichier (pas de clé 'sql').
 * Invoqué par admin_ged_seed_skeleton_v1.php (dry-run + apply).
 *
 * Marqueur : metadata.seed_version = 'skeleton_v1' (permet rollback ciblé).
 *
 * Décisions validées 2026-05-23 :
 *   - architecture hybride C (N1-N3 matérialisés, N4-N6 virtualisés)
 *   - 109 N3 V1, 173 N3 reportés en backlog V2
 *   - N2 pluriel, N3 singulier
 *   - préfixe numérique sur N3 (`01_nom`, `02_nom`)
 *   - MODELES sans N3 V1
 *   - doublon KBIS régie / KBIS fournisseur volontaire
 *   - squelette global (tenant_id = NULL)
 *   - slugs alignés sur format BDD existant (NN_nom_underscores)
 *
 * Réf : docs/ged_skeleton_v1.md
 */

declare(strict_types=1);

/**
 * Canon V1 du squelette GED.
 * Slugs alignés sur format BDD : NN_nom_underscores.
 * Structure : N1 → children_n2 → children_n3.
 */
$GED_SKELETON_V1_CANON = [

    // ─────────────────────────────────────────────────────────────────
    // N1.00 — INBOX (existe en BDD : 00_a_classer_ia)
    // ─────────────────────────────────────────────────────────────────
    [
        'slug' => '00_a_classer_ia', 'name_display' => '00 - À classer (IA)', 'name_canonical' => 'INBOX', 'module' => 'INBOX',
        'children_n2' => [
            ['slug' => '01_haute_confiance',     'name_display' => '01 - Haute confiance',     'name_canonical' => 'HAUTE_CONFIANCE',     'children_n3' => []],
            ['slug' => '02_confiance_moyenne',   'name_display' => '02 - Confiance moyenne',   'name_canonical' => 'CONFIANCE_MOYENNE',   'children_n3' => []],
            ['slug' => '03_basse_confiance',     'name_display' => '03 - Basse confiance',     'name_canonical' => 'BASSE_CONFIANCE',     'children_n3' => []],
            ['slug' => '04_erreurs_ocr',         'name_display' => '04 - Erreurs OCR',         'name_canonical' => 'ERREURS_OCR',         'children_n3' => []],
            ['slug' => '05_doublons',            'name_display' => '05 - Doublons détectés',   'name_canonical' => 'DOUBLONS',            'children_n3' => []],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────
    // N1.01 — DIRECTION (existe en BDD : 01_direction)
    // ─────────────────────────────────────────────────────────────────
    [
        'slug' => '01_direction', 'name_display' => '01 - Direction', 'name_canonical' => 'DIRECTION', 'module' => 'DIRECTION',
        'children_n2' => [
            ['slug' => '01_identite_societe', 'name_display' => '01 - Identité société', 'name_canonical' => 'IDENTITE_SOCIETE', 'children_n3' => [
                ['slug' => '01_kbis',    'name_display' => '01 - KBIS',     'name_canonical' => 'KBIS'],
                ['slug' => '02_statuts', 'name_display' => '02 - Statuts',  'name_canonical' => 'STATUTS'],
            ]],
            ['slug' => '02_garanties_pro', 'name_display' => '02 - Garanties professionnelles', 'name_canonical' => 'GARANTIES_PRO', 'children_n3' => [
                ['slug' => '01_carte_professionnelle', 'name_display' => '01 - Carte professionnelle', 'name_canonical' => 'CARTE_PRO'],
                ['slug' => '02_rc_pro',                'name_display' => '02 - RC Pro',                'name_canonical' => 'RC_PRO'],
                ['slug' => '03_garantie_financiere',   'name_display' => '03 - Garantie financière',   'name_canonical' => 'GARANTIE_FINANCIERE'],
            ]],
            ['slug' => '03_gouvernance', 'name_display' => '03 - Assemblées & gouvernance', 'name_canonical' => 'GOUVERNANCE', 'children_n3' => [
                ['slug' => '01_pv_ag', 'name_display' => '01 - PV AG', 'name_canonical' => 'PV_AG'],
            ]],
            ['slug' => '04_comptes_annuels', 'name_display' => '04 - Comptes annuels', 'name_canonical' => 'COMPTES_ANNUELS', 'children_n3' => [
                ['slug' => '01_bilan',          'name_display' => '01 - Bilan',          'name_canonical' => 'BILAN'],
                ['slug' => '02_liasse_fiscale', 'name_display' => '02 - Liasse fiscale', 'name_canonical' => 'LIASSE_FISCALE'],
            ]],
            ['slug' => '05_vie_sociale', 'name_display' => '05 - Vie sociale', 'name_canonical' => 'VIE_SOCIALE', 'children_n3' => [
                ['slug' => '01_decisions_associes', 'name_display' => '01 - Décisions associés', 'name_canonical' => 'DECISIONS_ASSOCIES'],
            ]],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────
    // N1.02 — REFERENTIEL (existe en BDD : 02_referentiel)
    // ─────────────────────────────────────────────────────────────────
    [
        'slug' => '02_referentiel', 'name_display' => '02 - Référentiel', 'name_canonical' => 'REFERENTIEL', 'module' => 'REFERENTIEL',
        'children_n2' => [
            ['slug' => '01_reglementation', 'name_display' => '01 - Réglementation', 'name_canonical' => 'REGLEMENTATION', 'children_n3' => [
                ['slug' => '01_loi',    'name_display' => '01 - Loi',    'name_canonical' => 'LOI'],
                ['slug' => '02_decret', 'name_display' => '02 - Décret', 'name_canonical' => 'DECRET'],
            ]],
            ['slug' => '02_baremes_indices', 'name_display' => '02 - Barèmes & indices', 'name_canonical' => 'BAREMES_INDICES', 'children_n3' => [
                ['slug' => '01_irl',                'name_display' => '01 - IRL',              'name_canonical' => 'IRL'],
                ['slug' => '02_bareme_honoraires',  'name_display' => '02 - Barème honoraires','name_canonical' => 'BAREME_HONORAIRES'],
            ]],
            ['slug' => '03_technique', 'name_display' => '03 - Technique', 'name_canonical' => 'TECHNIQUE', 'children_n3' => [
                ['slug' => '01_documentation_logiciels', 'name_display' => '01 - Documentation logiciels', 'name_canonical' => 'DOC_LOGICIELS'],
            ]],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────
    // N1.03 — RH (existe en BDD : 03_rh)
    // ─────────────────────────────────────────────────────────────────
    [
        'slug' => '03_rh', 'name_display' => '03 - Ressources humaines', 'name_canonical' => 'RH', 'module' => 'RH',
        'children_n2' => [
            ['slug' => '01_embauche', 'name_display' => '01 - Embauche', 'name_canonical' => 'EMBAUCHE', 'children_n3' => [
                ['slug' => '01_cv',                  'name_display' => '01 - CV',                   'name_canonical' => 'CV'],
                ['slug' => '02_contrat_de_travail',  'name_display' => '02 - Contrat de travail',   'name_canonical' => 'CONTRAT_TRAVAIL'],
                ['slug' => '03_dpae',                'name_display' => '03 - DPAE',                 'name_canonical' => 'DPAE'],
                ['slug' => '04_piece_identite',      'name_display' => '04 - Pièce d\'identité',    'name_canonical' => 'PIECE_IDENTITE'],
                ['slug' => '05_rib_salarie',         'name_display' => '05 - RIB salarié',          'name_canonical' => 'RIB_SALARIE'],
            ]],
            ['slug' => '02_contrats', 'name_display' => '02 - Contrats & avenants', 'name_canonical' => 'CONTRATS', 'children_n3' => [
                ['slug' => '01_cdi',     'name_display' => '01 - CDI',     'name_canonical' => 'CDI'],
                ['slug' => '02_cdd',     'name_display' => '02 - CDD',     'name_canonical' => 'CDD'],
                ['slug' => '03_avenant', 'name_display' => '03 - Avenant', 'name_canonical' => 'AVENANT'],
            ]],
            ['slug' => '03_paye', 'name_display' => '03 - Paye', 'name_canonical' => 'PAYE', 'children_n3' => [
                ['slug' => '01_bulletin_de_paie', 'name_display' => '01 - Bulletin de paie', 'name_canonical' => 'BULLETIN_PAIE'],
                ['slug' => '02_dsn',              'name_display' => '02 - DSN',              'name_canonical' => 'DSN'],
            ]],
            ['slug' => '04_formation', 'name_display' => '04 - Formation', 'name_canonical' => 'FORMATION', 'children_n3' => [
                ['slug' => '01_convention_opco',      'name_display' => '01 - Convention OPCO',     'name_canonical' => 'CONVENTION_OPCO'],
                ['slug' => '02_attestation_formation','name_display' => '02 - Attestation formation','name_canonical' => 'ATTESTATION_FORMATION'],
            ]],
            ['slug' => '05_evaluation', 'name_display' => '05 - Évaluation & carrière', 'name_canonical' => 'EVALUATION', 'children_n3' => [
                ['slug' => '01_entretien_annuel', 'name_display' => '01 - Entretien annuel', 'name_canonical' => 'ENTRETIEN_ANNUEL'],
            ]],
            ['slug' => '06_conges', 'name_display' => '06 - Congés & absences', 'name_canonical' => 'CONGES', 'children_n3' => [
                ['slug' => '01_demande_conges', 'name_display' => '01 - Demande congés', 'name_canonical' => 'DEMANDE_CONGES'],
                ['slug' => '02_arret_maladie',  'name_display' => '02 - Arrêt maladie',  'name_canonical' => 'ARRET_MALADIE'],
            ]],
            ['slug' => '07_sortie', 'name_display' => '07 - Sortie', 'name_canonical' => 'SORTIE', 'children_n3' => [
                ['slug' => '01_solde_tout_compte',     'name_display' => '01 - Solde tout compte',     'name_canonical' => 'SOLDE_TOUT_COMPTE'],
                ['slug' => '02_certificat_de_travail', 'name_display' => '02 - Certificat de travail', 'name_canonical' => 'CERTIFICAT_TRAVAIL'],
            ]],
            ['slug' => '08_medecine_travail', 'name_display' => '08 - Médecine du travail', 'name_canonical' => 'MEDECINE_TRAVAIL', 'children_n3' => [
                ['slug' => '01_visite_medicale', 'name_display' => '01 - Visite médicale', 'name_canonical' => 'VISITE_MEDICALE'],
            ]],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────
    // N1.04 — SYNDIC (existe en BDD : 04_syndic)
    // ─────────────────────────────────────────────────────────────────
    [
        'slug' => '04_syndic', 'name_display' => '04 - Syndic', 'name_canonical' => 'SYNDIC', 'module' => 'SYNDIC',
        'children_n2' => [
            ['slug' => '01_assemblee_generale', 'name_display' => '01 - Assemblées générales', 'name_canonical' => 'ASSEMBLEE_GENERALE', 'children_n3' => [
                ['slug' => '01_convocation', 'name_display' => '01 - Convocation',        'name_canonical' => 'CONVOCATION'],
                ['slug' => '02_pv',          'name_display' => '02 - Procès-verbal (PV)', 'name_canonical' => 'PV'],
            ]],
            ['slug' => '02_comptabilite_copro', 'name_display' => '02 - Comptabilités copropriété', 'name_canonical' => 'COMPTABILITE_COPRO', 'children_n3' => [
                ['slug' => '01_budget_previsionnel',    'name_display' => '01 - Budget prévisionnel',    'name_canonical' => 'BUDGET_PREVISIONNEL'],
                ['slug' => '02_appel_de_fonds',         'name_display' => '02 - Appel de fonds',         'name_canonical' => 'APPEL_FONDS'],
                ['slug' => '03_regularisation_annuelle','name_display' => '03 - Régularisation annuelle','name_canonical' => 'REGULARISATION_ANNUELLE'],
            ]],
            ['slug' => '03_travaux', 'name_display' => '03 - Travaux & maintenance', 'name_canonical' => 'TRAVAUX', 'children_n3' => [
                ['slug' => '01_devis',        'name_display' => '01 - Devis',          'name_canonical' => 'DEVIS'],
                ['slug' => '02_marche_signe', 'name_display' => '02 - Marché signé',   'name_canonical' => 'MARCHE_SIGNE'],
                ['slug' => '03_pv_reception', 'name_display' => '03 - PV de réception','name_canonical' => 'PV_RECEPTION'],
                ['slug' => '04_facture',      'name_display' => '04 - Facture',        'name_canonical' => 'FACTURE'],
            ]],
            ['slug' => '04_contrats_prestataires', 'name_display' => '04 - Contrats & prestataires', 'name_canonical' => 'CONTRATS_PRESTATAIRES', 'children_n3' => [
                ['slug' => '01_contrat_entretien',     'name_display' => '01 - Contrat d\'entretien',     'name_canonical' => 'CONTRAT_ENTRETIEN'],
                ['slug' => '02_attestation_assurance', 'name_display' => '02 - Attestation d\'assurance', 'name_canonical' => 'ATTESTATION_ASSURANCE'],
            ]],
            ['slug' => '05_sinistres', 'name_display' => '05 - Sinistres & assurances', 'name_canonical' => 'SINISTRES', 'children_n3' => [
                ['slug' => '01_dde',           'name_display' => '01 - DDE',           'name_canonical' => 'DDE'],
                ['slug' => '02_expertise',     'name_display' => '02 - Expertise',     'name_canonical' => 'EXPERTISE'],
                ['slug' => '03_indemnisation', 'name_display' => '03 - Indemnisation', 'name_canonical' => 'INDEMNISATION'],
            ]],
            ['slug' => '06_diagnostics_immeuble', 'name_display' => '06 - Diagnostics techniques immeuble', 'name_canonical' => 'DIAGNOSTICS_IMMEUBLE', 'children_n3' => [
                ['slug' => '01_dta',                       'name_display' => '01 - DTA',                  'name_canonical' => 'DTA'],
                ['slug' => '02_dpe_collectif',             'name_display' => '02 - DPE collectif',        'name_canonical' => 'DPE_COLLECTIF'],
                ['slug' => '03_carnet_entretien_immeuble', 'name_display' => '03 - Carnet d\'entretien',  'name_canonical' => 'CARNET_ENTRETIEN'],
            ]],
            ['slug' => '07_gouvernance_cs', 'name_display' => '07 - Gouvernance & conseil syndical', 'name_canonical' => 'GOUVERNANCE_CS', 'children_n3' => [
                ['slug' => '01_election_cs', 'name_display' => '01 - Élection CS', 'name_canonical' => 'ELECTION_CS'],
                ['slug' => '02_mandat_cs',   'name_display' => '02 - Mandat CS',   'name_canonical' => 'MANDAT_CS'],
            ]],
            ['slug' => '08_carnet_entretien', 'name_display' => '08 - Carnet d\'entretien', 'name_canonical' => 'CARNET_ENTRETIEN_N2', 'children_n3' => [
                ['slug' => '01_fiche_intervention', 'name_display' => '01 - Fiche d\'intervention', 'name_canonical' => 'FICHE_INTERVENTION'],
                ['slug' => '02_ppt',                'name_display' => '02 - PPT',                   'name_canonical' => 'PPT'],
            ]],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────
    // N1.05 — GESTION_LOCATIVE (existe en BDD : 05_gestion_locative)
    // NOTE : 01_proprietaires existe en BDD (id=24) sous ce N1, reste orphelin du canon V1.
    // ─────────────────────────────────────────────────────────────────
    [
        'slug' => '05_gestion_locative', 'name_display' => '05 - Gestion locative', 'name_canonical' => 'GESTION_LOCATIVE', 'module' => 'GESTION_LOCATIVE',
        'children_n2' => [
            ['slug' => '01_mandat_gestion', 'name_display' => '01 - Mandats de gestion', 'name_canonical' => 'MANDAT_GESTION', 'children_n3' => [
                ['slug' => '01_mandat_signe',   'name_display' => '01 - Mandat signé',   'name_canonical' => 'MANDAT_SIGNE'],
                ['slug' => '02_avenant_mandat', 'name_display' => '02 - Avenant mandat', 'name_canonical' => 'AVENANT_MANDAT'],
            ]],
            ['slug' => '02_bail', 'name_display' => '02 - Baux & locataires', 'name_canonical' => 'BAIL', 'children_n3' => [
                ['slug' => '01_bail_signe',     'name_display' => '01 - Bail signé',         'name_canonical' => 'BAIL_SIGNE'],
                ['slug' => '02_edl_entree',     'name_display' => '02 - EDL d\'entrée',      'name_canonical' => 'EDL_ENTREE'],
                ['slug' => '03_caution_garant', 'name_display' => '03 - Caution / Garant',   'name_canonical' => 'CAUTION_GARANT'],
            ]],
            ['slug' => '03_loyers', 'name_display' => '03 - Loyers & charges', 'name_canonical' => 'LOYERS', 'children_n3' => [
                ['slug' => '01_quittance',                'name_display' => '01 - Quittance',                'name_canonical' => 'QUITTANCE'],
                ['slug' => '02_avis_echeance',            'name_display' => '02 - Avis d\'échéance',         'name_canonical' => 'AVIS_ECHEANCE'],
                ['slug' => '03_regularisation_charges',   'name_display' => '03 - Régularisation charges',   'name_canonical' => 'REGULARISATION_CHARGES'],
            ]],
            ['slug' => '04_fiscalite_proprio', 'name_display' => '04 - Fiscalité propriétaire', 'name_canonical' => 'FISCALITE_PROPRIO', 'children_n3' => [
                ['slug' => '01_taxe_fonciere',                'name_display' => '01 - Taxe foncière',                'name_canonical' => 'TAXE_FONCIERE'],
                ['slug' => '02_declaration_revenus_fonciers', 'name_display' => '02 - Déclaration revenus fonciers', 'name_canonical' => 'DECLARATION_REVENUS_FONCIERS'],
                ['slug' => '03_crg_annuel',                   'name_display' => '03 - CRG annuel',                   'name_canonical' => 'CRG_ANNUEL'],
            ]],
            ['slug' => '05_sinistres_locatifs', 'name_display' => '05 - Sinistres locatifs', 'name_canonical' => 'SINISTRES_LOCATIFS', 'children_n3' => [
                ['slug' => '01_dde_locataire', 'name_display' => '01 - DDE locataire', 'name_canonical' => 'DDE_LOCATAIRE'],
            ]],
            ['slug' => '06_sortie_locataire', 'name_display' => '06 - Sorties locataire', 'name_canonical' => 'SORTIE_LOCATAIRE', 'children_n3' => [
                ['slug' => '01_preavis',                  'name_display' => '01 - Préavis',                  'name_canonical' => 'PREAVIS'],
                ['slug' => '02_edl_sortie',               'name_display' => '02 - EDL de sortie',            'name_canonical' => 'EDL_SORTIE'],
                ['slug' => '03_restitution_depot_garantie','name_display' => '03 - Restitution dépôt garantie','name_canonical' => 'RESTITUTION_DG'],
            ]],
            ['slug' => '07_caf_apl', 'name_display' => '07 - CAF & APL', 'name_canonical' => 'CAF_APL', 'children_n3' => [
                ['slug' => '01_attestation_caf', 'name_display' => '01 - Attestation CAF', 'name_canonical' => 'ATTESTATION_CAF'],
            ]],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────
    // N1.06 — TRANSACTION (existe en BDD : 06_transaction)
    // ─────────────────────────────────────────────────────────────────
    [
        'slug' => '06_transaction', 'name_display' => '06 - Transaction', 'name_canonical' => 'TRANSACTION', 'module' => 'TRANSACTION',
        'children_n2' => [
            ['slug' => '01_mandat_vente', 'name_display' => '01 - Mandats de vente', 'name_canonical' => 'MANDAT_VENTE', 'children_n3' => [
                ['slug' => '01_mandat_exclusif', 'name_display' => '01 - Mandat exclusif', 'name_canonical' => 'MANDAT_EXCLUSIF'],
                ['slug' => '02_mandat_simple',   'name_display' => '02 - Mandat simple',   'name_canonical' => 'MANDAT_SIMPLE'],
            ]],
            ['slug' => '02_acte', 'name_display' => '02 - Avant-contrats & actes', 'name_canonical' => 'ACTE', 'children_n3' => [
                ['slug' => '01_compromis',        'name_display' => '01 - Compromis',        'name_canonical' => 'COMPROMIS'],
                ['slug' => '02_acte_authentique', 'name_display' => '02 - Acte authentique', 'name_canonical' => 'ACTE_AUTHENTIQUE'],
            ]],
            ['slug' => '03_diagnostics_transaction', 'name_display' => '03 - Diagnostics & DPE', 'name_canonical' => 'DIAGNOSTICS_TRANSACTION', 'children_n3' => [
                ['slug' => '01_dpe',       'name_display' => '01 - DPE',        'name_canonical' => 'DPE'],
                ['slug' => '02_erp_ernmt', 'name_display' => '02 - ERP/ERNMT',  'name_canonical' => 'ERP_ERNMT'],
            ]],
            ['slug' => '04_acquereur', 'name_display' => '04 - Acquéreurs', 'name_canonical' => 'ACQUEREUR', 'children_n3' => [
                ['slug' => '01_dossier_acquereur', 'name_display' => '01 - Dossier acquéreur', 'name_canonical' => 'DOSSIER_ACQUEREUR'],
                ['slug' => '02_accord_de_pret',    'name_display' => '02 - Accord de prêt',    'name_canonical' => 'ACCORD_PRET'],
            ]],
            ['slug' => '05_suivi_vente', 'name_display' => '05 - Suivi vente', 'name_canonical' => 'SUIVI_VENTE', 'children_n3' => [
                ['slug' => '01_decompte_vendeur',     'name_display' => '01 - Décompte vendeur',     'name_canonical' => 'DECOMPTE_VENDEUR'],
                ['slug' => '02_quittance_honoraires', 'name_display' => '02 - Quittance honoraires', 'name_canonical' => 'QUITTANCE_HONORAIRES'],
            ]],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────
    // N1.07 — COMPTABILITE (existe en BDD : 07_comptabilite)
    // ─────────────────────────────────────────────────────────────────
    [
        'slug' => '07_comptabilite', 'name_display' => '07 - Comptabilité', 'name_canonical' => 'COMPTABILITE', 'module' => 'COMPTABILITE',
        'children_n2' => [
            ['slug' => '01_banque', 'name_display' => '01 - Banques', 'name_canonical' => 'BANQUE', 'children_n3' => [
                ['slug' => '01_releve_bancaire',  'name_display' => '01 - Relevé bancaire',  'name_canonical' => 'RELEVE_BANCAIRE'],
                ['slug' => '02_avis_de_virement', 'name_display' => '02 - Avis de virement', 'name_canonical' => 'AVIS_VIREMENT'],
                ['slug' => '03_tlmc',             'name_display' => '03 - TLMC',             'name_canonical' => 'TLMC'],
            ]],
            ['slug' => '02_recettes', 'name_display' => '02 - Recettes', 'name_canonical' => 'RECETTES', 'children_n3' => [
                ['slug' => '01_facture_emise',  'name_display' => '01 - Facture émise',  'name_canonical' => 'FACTURE_EMISE'],
                ['slug' => '02_note_honoraires','name_display' => '02 - Note d\'honoraires','name_canonical' => 'NOTE_HONORAIRES'],
            ]],
            ['slug' => '03_depenses', 'name_display' => '03 - Dépenses', 'name_canonical' => 'DEPENSES', 'children_n3' => [
                ['slug' => '01_facture_fournisseur',    'name_display' => '01 - Facture fournisseur',    'name_canonical' => 'FACTURE_FOURNISSEUR'],
                ['slug' => '02_justificatif_paiement',  'name_display' => '02 - Justificatif de paiement','name_canonical' => 'JUSTIFICATIF_PAIEMENT'],
            ]],
            ['slug' => '04_fiscalite_societe', 'name_display' => '04 - Fiscalité société', 'name_canonical' => 'FISCALITE_SOCIETE', 'children_n3' => [
                ['slug' => '01_tva', 'name_display' => '01 - TVA', 'name_canonical' => 'TVA'],
                ['slug' => '02_is',  'name_display' => '02 - IS',  'name_canonical' => 'IS'],
            ]],
            ['slug' => '05_cloture', 'name_display' => '05 - Comptes & clôture', 'name_canonical' => 'CLOTURE', 'children_n3' => [
                ['slug' => '01_fec', 'name_display' => '01 - FEC', 'name_canonical' => 'FEC'],
            ]],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────
    // N1.08 — JURIDIQUE (existe en BDD : 08_juridique_contentieux)
    // ─────────────────────────────────────────────────────────────────
    [
        'slug' => '08_juridique_contentieux', 'name_display' => '08 - Juridique & Contentieux', 'name_canonical' => 'JURIDIQUE', 'module' => 'JURIDIQUE',
        'children_n2' => [
            ['slug' => '01_procedures', 'name_display' => '01 - Procédures judiciaires', 'name_canonical' => 'PROCEDURES', 'children_n3' => [
                ['slug' => '01_assignation', 'name_display' => '01 - Assignation', 'name_canonical' => 'ASSIGNATION'],
                ['slug' => '02_jugement',    'name_display' => '02 - Jugement',    'name_canonical' => 'JUGEMENT'],
            ]],
            ['slug' => '02_contrats_generaux', 'name_display' => '02 - Contrats généraux', 'name_canonical' => 'CONTRATS_GENERAUX', 'children_n3' => [
                ['slug' => '01_contrat_societe', 'name_display' => '01 - Contrat société', 'name_canonical' => 'CONTRAT_SOCIETE'],
            ]],
            ['slug' => '03_rgpd', 'name_display' => '03 - RGPD & conformité', 'name_canonical' => 'RGPD', 'children_n3' => [
                ['slug' => '01_registre_traitements', 'name_display' => '01 - Registre des traitements', 'name_canonical' => 'REGISTRE_TRAITEMENTS'],
            ]],
            ['slug' => '04_contentieux_locatif', 'name_display' => '04 - Contentieux locatif', 'name_canonical' => 'CONTENTIEUX_LOCATIF', 'children_n3' => [
                ['slug' => '01_commandement_de_payer', 'name_display' => '01 - Commandement de payer', 'name_canonical' => 'COMMANDEMENT_PAYER'],
                ['slug' => '02_saisie',                'name_display' => '02 - Saisie',                'name_canonical' => 'SAISIE'],
            ]],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────
    // N1.09 — MARKETING (existe en BDD : 09_marketing_communication)
    // ─────────────────────────────────────────────────────────────────
    [
        'slug' => '09_marketing_communication', 'name_display' => '09 - Marketing & Communication', 'name_canonical' => 'MARKETING', 'module' => 'MARKETING',
        'children_n2' => [
            ['slug' => '01_affiches', 'name_display' => '01 - Affiches & vitrine', 'name_canonical' => 'AFFICHES', 'children_n3' => [
                ['slug' => '01_affiche_a3', 'name_display' => '01 - Affiche A3', 'name_canonical' => 'AFFICHE_A3'],
                ['slug' => '02_affiche_a4', 'name_display' => '02 - Affiche A4', 'name_canonical' => 'AFFICHE_A4'],
            ]],
            ['slug' => '02_supports_commerciaux', 'name_display' => '02 - Supports commerciaux', 'name_canonical' => 'SUPPORTS_COMMERCIAUX', 'children_n3' => [
                ['slug' => '01_fiche_descriptive', 'name_display' => '01 - Fiche descriptive', 'name_canonical' => 'FICHE_DESCRIPTIVE'],
            ]],
            ['slug' => '03_annonces', 'name_display' => '03 - Annonces externes', 'name_canonical' => 'ANNONCES', 'children_n3' => [
                ['slug' => '01_annonce_ubiflow', 'name_display' => '01 - Annonce Ubiflow', 'name_canonical' => 'ANNONCE_UBIFLOW'],
                ['slug' => '02_annonce_externe', 'name_display' => '02 - Annonce externe', 'name_canonical' => 'ANNONCE_EXTERNE'],
            ]],
            ['slug' => '04_presse', 'name_display' => '04 - Presse & événementiel', 'name_canonical' => 'PRESSE', 'children_n3' => [
                ['slug' => '01_article_presse', 'name_display' => '01 - Article presse', 'name_canonical' => 'ARTICLE_PRESSE'],
            ]],
            ['slug' => '05_reseaux_sociaux', 'name_display' => '05 - Réseaux sociaux', 'name_canonical' => 'RESEAUX_SOCIAUX', 'children_n3' => [
                ['slug' => '01_post_reseau_social', 'name_display' => '01 - Post réseau social', 'name_canonical' => 'POST_RESEAU'],
            ]],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────
    // N1.10 — MODELES (existe en BDD : 10_modeles_documents — pas de N3 V1)
    // ─────────────────────────────────────────────────────────────────
    [
        'slug' => '10_modeles_documents', 'name_display' => '10 - Modèles de documents', 'name_canonical' => 'MODELES', 'module' => 'MODELES',
        'children_n2' => [
            ['slug' => '01_modeles_syndic',      'name_display' => '01 - Modèles Syndic',           'name_canonical' => 'MODELES_SYNDIC',      'children_n3' => []],
            ['slug' => '02_modeles_gestion',     'name_display' => '02 - Modèles Gestion locative', 'name_canonical' => 'MODELES_GESTION',     'children_n3' => []],
            ['slug' => '03_modeles_transaction', 'name_display' => '03 - Modèles Transaction',      'name_canonical' => 'MODELES_TRANSACTION', 'children_n3' => []],
            ['slug' => '04_modeles_compta',      'name_display' => '04 - Modèles Comptabilité',     'name_canonical' => 'MODELES_COMPTA',      'children_n3' => []],
            ['slug' => '05_modeles_rh',          'name_display' => '05 - Modèles RH',               'name_canonical' => 'MODELES_RH',          'children_n3' => []],
            ['slug' => '06_modeles_juridique',   'name_display' => '06 - Modèles Juridique',        'name_canonical' => 'MODELES_JURIDIQUE',   'children_n3' => []],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────
    // N1.11 — MAILS (existe en BDD : 11_mails_communications)
    // NOTE : les 5 N2 existent déjà en BDD (ids 17-21), tous SKIP attendus.
    // ─────────────────────────────────────────────────────────────────
    [
        'slug' => '11_mails_communications', 'name_display' => '11 - Mails & Communications', 'name_canonical' => 'MAILS', 'module' => 'MAILS',
        'children_n2' => [
            ['slug' => '01_entrants',                  'name_display' => '01 - Entrants',                  'name_canonical' => 'ENTRANTS',                  'children_n3' => []],
            ['slug' => '02_sortants',                  'name_display' => '02 - Sortants',                  'name_canonical' => 'SORTANTS',                  'children_n3' => []],
            ['slug' => '03_archives',                  'name_display' => '03 - Archives',                  'name_canonical' => 'ARCHIVES_MAILS',            'children_n3' => []],
            ['slug' => '04_pieces_jointes',            'name_display' => '04 - Pièces jointes',            'name_canonical' => 'PIECES_JOINTES',            'children_n3' => []],
            ['slug' => '05_conversations_importantes', 'name_display' => '05 - Conversations importantes', 'name_canonical' => 'CONVERSATIONS_IMPORTANTES', 'children_n3' => []],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────
    // N1.12 — ARCHIVES (existe en BDD : 12_archives)
    // ─────────────────────────────────────────────────────────────────
    [
        'slug' => '12_archives', 'name_display' => '12 - Archives', 'name_canonical' => 'ARCHIVES', 'module' => 'ARCHIVES',
        'children_n2' => [
            ['slug' => '01_mandats_clos',   'name_display' => '01 - Mandats clôturés',  'name_canonical' => 'MANDATS_CLOS',   'children_n3' => []],
            ['slug' => '02_baux_resilies',  'name_display' => '02 - Baux résiliés',     'name_canonical' => 'BAUX_RESILIES',  'children_n3' => []],
            ['slug' => '03_copro_perdues',  'name_display' => '03 - Copropriétés perdues','name_canonical' => 'COPRO_PERDUES','children_n3' => []],
            ['slug' => '04_obsoletes',      'name_display' => '04 - Documents obsolètes','name_canonical' => 'OBSOLETES',     'children_n3' => []],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────
    // N1.13 — FOURNISSEURS (NOUVEAU N1 — INSERT attendu)
    // ─────────────────────────────────────────────────────────────────
    [
        'slug' => '13_fournisseurs', 'name_display' => '13 - Fournisseurs', 'name_canonical' => 'FOURNISSEURS', 'module' => 'FOURNISSEURS',
        'children_n2' => [
            ['slug' => '01_referencement', 'name_display' => '01 - Référencement fournisseur', 'name_canonical' => 'REFERENCEMENT', 'children_n3' => [
                ['slug' => '01_fiche_fournisseur', 'name_display' => '01 - Fiche fournisseur', 'name_canonical' => 'FICHE_FOURNISSEUR'],
                ['slug' => '02_kbis_fournisseur',  'name_display' => '02 - KBIS fournisseur',  'name_canonical' => 'KBIS_FOURNISSEUR'],
            ]],
            ['slug' => '02_contrats_cadres', 'name_display' => '02 - Contrats-cadres', 'name_canonical' => 'CONTRATS_CADRES', 'children_n3' => [
                ['slug' => '01_contrat_cadre',   'name_display' => '01 - Contrat-cadre',   'name_canonical' => 'CONTRAT_CADRE'],
                ['slug' => '02_grille_tarifaire','name_display' => '02 - Grille tarifaire','name_canonical' => 'GRILLE_TARIFAIRE'],
            ]],
            ['slug' => '03_factures', 'name_display' => '03 - Factures fournisseurs', 'name_canonical' => 'FACTURES_FOURN', 'children_n3' => [
                ['slug' => '01_facture_a_classer', 'name_display' => '01 - Facture à classer', 'name_canonical' => 'FACTURE_A_CLASSER'],
                ['slug' => '02_facture_payee',     'name_display' => '02 - Facture payée',     'name_canonical' => 'FACTURE_PAYEE'],
            ]],
            ['slug' => '04_qualite', 'name_display' => '04 - Qualité & litiges', 'name_canonical' => 'QUALITE', 'children_n3' => [
                ['slug' => '01_reclamation', 'name_display' => '01 - Réclamation', 'name_canonical' => 'RECLAMATION'],
            ]],
        ],
    ],

    // ─────────────────────────────────────────────────────────────────
    // N1.99 — SYSTEME (existe en BDD : 99_systeme)
    // NOTE : coffre_securise + corbeille existent SANS préfixe (BDD legacy).
    //        On garde ces slugs tels quels pour idempotence.
    // ─────────────────────────────────────────────────────────────────
    [
        'slug' => '99_systeme', 'name_display' => '99 - Système', 'name_canonical' => 'SYSTEME', 'module' => 'SYSTEME',
        'children_n2' => [
            ['slug' => 'coffre_securise', 'name_display' => 'Coffre sécurisé', 'name_canonical' => 'COFFRE',      'children_n3' => []],
            ['slug' => 'corbeille',       'name_display' => 'Corbeille',       'name_canonical' => 'CORBEILLE',   'children_n3' => []],
            ['slug' => 'parametrage',     'name_display' => 'Paramétrage GED', 'name_canonical' => 'PARAMETRAGE', 'children_n3' => []],
            ['slug' => 'logs',            'name_display' => 'Logs & audit',    'name_canonical' => 'LOGS',        'children_n3' => []],
        ],
    ],
];

// ─────────────────────────────────────────────────────────────────────
// Helper UUID v4
// ─────────────────────────────────────────────────────────────────────
$ged_seed_uuid_v4 = static function (): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
};

// ─────────────────────────────────────────────────────────────────────
// Closure principale : seed avec dry-run
// ─────────────────────────────────────────────────────────────────────
$ged_seed_run = static function (PDO $pdo, bool $dryRun = true)
                use ($GED_SKELETON_V1_CANON, $ged_seed_uuid_v4): array {

    $report = [
        'mode'         => $dryRun ? 'dry_run' : 'apply',
        'started_at'   => date('Y-m-d H:i:s'),
        'finished_at'  => null,
        'totals'       => ['n1_insert' => 0, 'n2_insert' => 0, 'n3_insert' => 0,
                           'n1_skip' => 0, 'n2_skip' => 0, 'n3_skip' => 0,
                           'errors' => 0],
        'log'          => [],
        'inserts'      => [],
        'skips'        => [],
        'errors'       => [],
    ];

    // Marqueur du seed : utilise storage_path (champ libre, jamais utilisé en mode local).
    // La colonne `metadata` n'existe pas dans ged_folders (DDL refusé).
    // folder_kind est un ENUM strict (business_view|storage_folder|system) sans valeur 'skeleton'.
    // Choix : storage_path = 'seed:skeleton_v1' → unique, identifiable, sans conflit.
    $SEED_MARKER = 'seed:skeleton_v1';

    $lookupExisting = function (?int $parentId, string $slug) use ($pdo): ?array {
        $sql = "SELECT id, name_display, module FROM ged_folders
                WHERE slug = ? AND " . ($parentId === null ? "parent_id IS NULL" : "parent_id = ?") . "
                LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute($parentId === null ? [$slug] : [$slug, $parentId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    };

    $buildPathCache = function (?int $parentId, string $slug) use ($pdo): string {
        if ($parentId === null) return $slug;
        $st = $pdo->prepare("SELECT path_cache FROM ged_folders WHERE id = ?");
        $st->execute([$parentId]);
        $parentPath = (string)$st->fetchColumn();
        return $parentPath !== '' ? $parentPath . '/' . $slug : $slug;
    };

    if (!$dryRun) {
        $pdo->beginTransaction();
        $report['log'][] = '🔓 BEGIN TRANSACTION';
    }

    $simulatedNextId = -1;

    try {
        foreach ($GED_SKELETON_V1_CANON as $pos1 => $n1) {
            $existing = $lookupExisting(null, $n1['slug']);
            if ($existing) {
                $report['totals']['n1_skip']++;
                $report['skips'][] = ['level' => 'N1', 'id_existing' => $existing['id'],
                                       'slug' => $n1['slug'], 'reason' => 'UK existe (id=' . $existing['id'] . ')'];
                $report['log'][] = "⏭️  SKIP N1 {$n1['slug']} (id={$existing['id']})";
                $idN1 = (int)$existing['id'];
            } else {
                $report['totals']['n1_insert']++;
                $report['inserts'][] = ['level' => 'N1', 'parent_id' => null,
                                         'slug' => $n1['slug'], 'name_display' => $n1['name_display'],
                                         'module' => $n1['module']];
                if ($dryRun) {
                    $idN1 = $simulatedNextId--;
                    $report['log'][] = "✨ INSERT N1 {$n1['slug']} (sim_id=$idN1)";
                } else {
                    $uuid = $ged_seed_uuid_v4();
                    $pathCache = $buildPathCache(null, $n1['slug']);
                    $st = $pdo->prepare("
                        INSERT INTO ged_folders
                            (uuid, tenant_id, parent_id, depth, path_cache, slug, name_display, name_canonical,
                             module, scope, source_type, position, is_system, is_archived,
                             created_by, created_at, updated_at, folder_kind, storage_path)
                        VALUES (?, NULL, NULL, 0, ?, ?, ?, ?, ?, 'global', 'auto', ?, 1, 0,
                                NULL, NOW(), NOW(), 'system', ?)
                    ");
                    $st->execute([$uuid, $pathCache, $n1['slug'], $n1['name_display'],
                                  $n1['name_canonical'], $n1['module'], $pos1, $SEED_MARKER]);
                    $idN1 = (int)$pdo->lastInsertId();
                    $report['log'][] = "✅ INSERT N1 {$n1['slug']} (id=$idN1)";
                }
            }

            foreach ($n1['children_n2'] as $pos2 => $n2) {
                $existingN2 = $idN1 > 0 ? $lookupExisting($idN1, $n2['slug']) : null;
                if ($existingN2) {
                    $report['totals']['n2_skip']++;
                    $report['skips'][] = ['level' => 'N2', 'id_existing' => $existingN2['id'],
                                          'slug' => $n2['slug'], 'reason' => 'UK existe',
                                          'parent_slug' => $n1['slug']];
                    $report['log'][] = "⏭️  SKIP N2 {$n1['slug']}/{$n2['slug']} (id={$existingN2['id']})";
                    $idN2 = (int)$existingN2['id'];
                } else {
                    $report['totals']['n2_insert']++;
                    $report['inserts'][] = ['level' => 'N2', 'parent_id' => $idN1,
                                            'parent_slug' => $n1['slug'],
                                            'slug' => $n2['slug'], 'name_display' => $n2['name_display'],
                                            'module' => $n1['module']];
                    if ($dryRun) {
                        $idN2 = $simulatedNextId--;
                        $report['log'][] = "✨ INSERT N2 {$n1['slug']}/{$n2['slug']} (sim_id=$idN2)";
                    } else {
                        $uuid = $ged_seed_uuid_v4();
                        $pathCache = $buildPathCache($idN1, $n2['slug']);
                        $st = $pdo->prepare("
                            INSERT INTO ged_folders
                                (uuid, tenant_id, parent_id, depth, path_cache, slug, name_display, name_canonical,
                                 module, scope, source_type, position, is_system, is_archived,
                                 created_by, created_at, updated_at, folder_kind, storage_path)
                            VALUES (?, NULL, ?, 1, ?, ?, ?, ?, ?, 'global', 'auto', ?, 1, 0,
                                    NULL, NOW(), NOW(), 'system', ?)
                        ");
                        $st->execute([$uuid, $idN1, $pathCache, $n2['slug'], $n2['name_display'],
                                      $n2['name_canonical'], $n1['module'], $pos2, $SEED_MARKER]);
                        $idN2 = (int)$pdo->lastInsertId();
                        $report['log'][] = "✅ INSERT N2 {$n1['slug']}/{$n2['slug']} (id=$idN2)";
                    }
                }

                foreach (($n2['children_n3'] ?? []) as $pos3 => $n3) {
                    $existingN3 = $idN2 > 0 ? $lookupExisting($idN2, $n3['slug']) : null;
                    if ($existingN3) {
                        $report['totals']['n3_skip']++;
                        $report['skips'][] = ['level' => 'N3', 'id_existing' => $existingN3['id'],
                                              'slug' => $n3['slug'], 'reason' => 'UK existe',
                                              'parent_slug' => $n2['slug']];
                        $report['log'][] = "⏭️  SKIP N3 {$n1['slug']}/{$n2['slug']}/{$n3['slug']} (id={$existingN3['id']})";
                    } else {
                        $report['totals']['n3_insert']++;
                        $report['inserts'][] = ['level' => 'N3', 'parent_id' => $idN2,
                                                'parent_slug' => $n2['slug'],
                                                'slug' => $n3['slug'], 'name_display' => $n3['name_display'],
                                                'module' => $n1['module']];
                        if ($dryRun) {
                            $report['log'][] = "✨ INSERT N3 {$n1['slug']}/{$n2['slug']}/{$n3['slug']}";
                        } else {
                            $uuid = $ged_seed_uuid_v4();
                            $pathCache = $buildPathCache($idN2, $n3['slug']);
                            $st = $pdo->prepare("
                                INSERT INTO ged_folders
                                    (uuid, tenant_id, parent_id, depth, path_cache, slug, name_display, name_canonical,
                                     module, scope, source_type, position, is_system, is_archived,
                                     created_by, created_at, updated_at, folder_kind, storage_path)
                                VALUES (?, NULL, ?, 2, ?, ?, ?, ?, ?, 'global', 'auto', ?, 1, 0,
                                        NULL, NOW(), NOW(), 'system', ?)
                            ");
                            $st->execute([$uuid, $idN2, $pathCache, $n3['slug'], $n3['name_display'],
                                          $n3['name_canonical'], $n1['module'], $pos3, $SEED_MARKER]);
                            $newN3Id = (int)$pdo->lastInsertId();
                            $report['log'][] = "✅ INSERT N3 {$n1['slug']}/{$n2['slug']}/{$n3['slug']} (id=$newN3Id)";
                        }
                    }
                }
            }
        }

        if (!$dryRun) {
            $pdo->commit();
            $report['log'][] = '🔒 COMMIT';
        }
    } catch (Throwable $e) {
        if (!$dryRun && $pdo->inTransaction()) {
            $pdo->rollBack();
            $report['log'][] = '🔴 ROLLBACK : ' . $e->getMessage();
        }
        $report['totals']['errors']++;
        $report['errors'][] = $e->getMessage();
    }

    $report['finished_at'] = date('Y-m-d H:i:s');
    return $report;
};

// ─────────────────────────────────────────────────────────────────────
// Closure rollback (SOFT ARCHIVE — pas de DELETE physique)
// Décision 2026-05-23 : `is_archived = 1` au lieu de DELETE.
// Conforme à [[feedback_suppression_bancaire_inviolable]] (jamais de suppression silencieuse).
// Les rows restent en BDD, juste invisibles dans les cascades 3C.
// Une nouvelle exécution du seed les fera REVIVRE via INSERT IGNORE (UK respecté →
// SKIP, mais on peut overrider en remettant is_archived=0 si besoin).
// ─────────────────────────────────────────────────────────────────────
$ged_seed_rollback = static function (PDO $pdo, bool $dryRun = true): array {
    $report = [
        'mode'        => $dryRun ? 'dry_run' : 'apply',
        'started_at'  => date('Y-m-d H:i:s'),
        'totals'      => ['archive' => 0, 'blocked' => 0, 'skipped' => 0, 'errors' => 0],
        'log'         => [],
        'archives'    => [],
        'blocked'     => [],
        'errors'      => [],
    ];

    // Marqueur du seed (cf. INSERT plus haut) : storage_path = 'seed:skeleton_v1'
    $candidates = $pdo->query("
        SELECT id, parent_id, depth, slug, name_display, module, path_cache, is_archived
        FROM ged_folders
        WHERE storage_path = 'seed:skeleton_v1'
          AND is_system = 1
          AND created_by IS NULL
        ORDER BY depth DESC, id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (count($candidates) === 0) {
        $report['log'][] = 'ℹ️  Aucun row seedé skeleton_v1 trouvé. Rien à archiver.';
        $report['finished_at'] = date('Y-m-d H:i:s');
        return $report;
    }

    if (!$dryRun) {
        $pdo->beginTransaction();
        $report['log'][] = '🔓 BEGIN TRANSACTION';
    }

    try {
        foreach ($candidates as $c) {
            $id = (int)$c['id'];

            // Si déjà archivé → skip
            if ((int)$c['is_archived'] === 1) {
                $report['totals']['skipped']++;
                $report['log'][] = "⏭️  SKIP (déjà archivé) #$id {$c['path_cache']}";
                continue;
            }

            // Vérif 1 : aucun document actif lié
            $nbDocs = (int)$pdo->query("SELECT COUNT(*) FROM ged_documents WHERE folder_id = $id AND status != 'deleted'")->fetchColumn();
            if ($nbDocs > 0) {
                $report['totals']['blocked']++;
                $report['blocked'][] = ['id' => $id, 'slug' => $c['slug'],
                                        'reason' => "$nbDocs document(s) lié(s)"];
                $report['log'][] = "🛑 BLOCKED #$id {$c['path_cache']} ($nbDocs docs liés)";
                continue;
            }

            // Vérif 2 : aucun enfant user-créé actif (non archivé)
            $nbChildren = (int)$pdo->query("
                SELECT COUNT(*) FROM ged_folders
                WHERE parent_id = $id
                  AND (source_type = 'manual' OR is_system = 0)
                  AND is_archived = 0
            ")->fetchColumn();
            if ($nbChildren > 0) {
                $report['totals']['blocked']++;
                $report['blocked'][] = ['id' => $id, 'slug' => $c['slug'],
                                        'reason' => "$nbChildren enfant(s) user-créés actifs"];
                $report['log'][] = "🛑 BLOCKED #$id {$c['path_cache']} ($nbChildren enfants user)";
                continue;
            }

            // OK pour archive (soft)
            $report['totals']['archive']++;
            $report['archives'][] = ['id' => $id, 'slug' => $c['slug'], 'path' => $c['path_cache']];
            if ($dryRun) {
                $report['log'][] = "📦 ARCHIVE (dry) #$id {$c['path_cache']}";
            } else {
                $pdo->prepare("UPDATE ged_folders SET is_archived = 1, updated_at = NOW() WHERE id = ?")
                    ->execute([$id]);
                $report['log'][] = "📦 ARCHIVE #$id {$c['path_cache']} (is_archived=1)";
            }
        }

        if (!$dryRun) {
            $pdo->commit();
            $report['log'][] = '🔒 COMMIT';
        }
    } catch (Throwable $e) {
        if (!$dryRun && $pdo->inTransaction()) {
            $pdo->rollBack();
            $report['log'][] = '🔴 ROLLBACK : ' . $e->getMessage();
        }
        $report['totals']['errors']++;
        $report['errors'][] = $e->getMessage();
    }

    $report['finished_at'] = date('Y-m-d H:i:s');
    return $report;
};

return [
    'id'           => '20260524_ged_seed_skeleton_v1',
    'title'        => 'Seed squelette GED v1 (hybride C — 109 N3 V1, slugs alignés BDD)',
    'description'  => "Seed les 15 N1 + 78 N2 + 109 N3 V1 dans ged_folders (squelette global tenant_id=NULL, is_system=1). Slugs alignés sur format BDD existant (NN_nom_underscores) pour idempotence : 21 SKIP + 181 INSERT attendus. Pattern non-standard (closures dry-run/apply/rollback). Réf docs/ged_skeleton_v1.md.",
    'created_at'   => '2026-05-24',
    'callable'     => $ged_seed_run,
    'rollback'     => $ged_seed_rollback,
    'canon'        => $GED_SKELETON_V1_CANON,
    'seed_version' => 'skeleton_v1',
];
