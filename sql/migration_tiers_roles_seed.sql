-- ═══════════════════════════════════════════════════════════════════════
-- MaBoxImmo — Seed du référentiel des codes de rôle métier
-- À exécuter APRÈS tiers_architecture_phase1.sql
-- ═══════════════════════════════════════════════════════════════════════

INSERT INTO `tiers_roles_codes` (code, libelle, categorie, description, objet_type_defaut, ordre_affichage) VALUES
-- ── Acteurs immobiliers (transaction / gestion) ──
('mandant',                    'Mandant',                      'acteur_immo', 'Personne qui confie un mandat à un professionnel',           'mandat',    10),
('proprietaire',               'Propriétaire',                 'acteur_immo', 'Propriétaire d''un bien ou d''un immeuble',                   'bien',      20),
('bailleur',                   'Bailleur',                     'acteur_immo', 'Propriétaire qui met un bien en location',                    'bien',      30),
('vendeur',                    'Vendeur',                      'acteur_immo', 'Propriétaire qui met un bien en vente',                       'bien',      40),
('acquereur',                  'Acquéreur',                    'acteur_immo', 'Personne qui achète un bien',                                 'bien',      50),
('locataire',                  'Locataire',                    'acteur_immo', 'Personne titulaire d''un bail',                               'bail',      60),
('occupant',                   'Occupant',                     'acteur_immo', 'Personne occupant un bien sans bail formel',                  'bien',      70),
('occupant_gratuit',           'Occupant à titre gratuit',     'acteur_immo', 'Occupant sans loyer (famille, proches…)',                     'bien',      80),
('ancien_locataire',           'Ancien locataire',             'acteur_immo', 'Locataire historique (archives)',                             'bail',      90),
('ancien_proprietaire',        'Ancien propriétaire',          'acteur_immo', 'Propriétaire historique (mutation enregistrée)',              'bien',     100),

-- ── Copropriété ──
('coproprietaire',             'Copropriétaire',               'acteur_immo', 'Propriétaire d''un lot en copropriété',                       'immeuble', 110),
('conseil_syndical',           'Conseil syndical',             'acteur_immo', 'Appartient au conseil syndical',                              'immeuble', 120),
('president_conseil_syndical', 'Président du CS',              'acteur_immo', 'Préside le conseil syndical',                                 'immeuble', 130),
('membre_cs',                  'Membre du CS',                 'acteur_immo', 'Membre du conseil syndical',                                  'immeuble', 140),
('syndic',                     'Syndic',                       'acteur_immo', 'Syndic professionnel de copropriété',                         'immeuble', 150),
('syndic_benevole',            'Syndic bénévole',              'acteur_immo', 'Syndic non professionnel (bénévole)',                         'immeuble', 160),
('syndic_concurrent',          'Syndic concurrent',            'acteur_immo', 'Ancien/autre syndic de la copro (historique)',                'immeuble', 170),

-- ── Structure juridique / représentation ──
('representant_legal',         'Représentant légal',           'juridique',   'Représentant légal d''une entité morale',                     NULL,       180),
('mandataire_legal',           'Mandataire légal',             'juridique',   'Mandataire désigné par décision judiciaire ou contrat',       NULL,       190),
('usufruitier',                'Usufruitier',                  'juridique',   'Dispose de l''usufruit sans la nue-propriété',                'bien',     200),
('nu_proprietaire',            'Nu-propriétaire',              'juridique',   'Propriétaire nu (sans usufruit)',                             'bien',     210),
('indivisaire',                'Indivisaire',                  'juridique',   'Partie d''une indivision',                                    'bien',     220),
('gerant_sci',                 'Gérant SCI',                   'juridique',   'Gérant d''une SCI',                                           NULL,       230),
('associe_sci',                'Associé SCI',                  'juridique',   'Associé d''une SCI',                                          NULL,       240),
('garant',                     'Garant',                       'juridique',   'Se porte garant d''un locataire ou acquéreur',                'bail',     250),
('caution',                    'Caution',                      'juridique',   'Caution solidaire ou simple',                                 'bail',     260),

-- ── Prestataires / fournisseurs ──
('prestataire',                'Prestataire',                  'prestataire', 'Prestataire de services générique',                           NULL,       300),
('fournisseur',                'Fournisseur',                  'prestataire', 'Fournisseur de biens ou consommables',                        NULL,       310),
('artisan',                    'Artisan',                      'prestataire', 'Artisan individuel',                                          NULL,       320),
('entreprise_travaux',         'Entreprise de travaux',        'prestataire', 'Entreprise réalisant des travaux',                            NULL,       330),
('entreprise_entretien',       'Entreprise d''entretien',      'prestataire', 'Entretien régulier (ménage, espaces verts…)',                 NULL,       340),
('entreprise_multiservice',    'Entreprise multiservice',      'prestataire', 'Prestataire couvrant plusieurs corps d''état',                NULL,       350),
('diagnostiqueur',             'Diagnostiqueur',               'prestataire', 'Professionnel DPE / amiante / plomb / etc.',                  NULL,       360),
('expert',                     'Expert',                       'prestataire', 'Expert immobilier, expert judiciaire…',                       NULL,       370),

-- ── Professions réglementées / juridique ──
('notaire',                    'Notaire',                      'juridique',   'Notaire',                                                     NULL,       380),
('avocat',                     'Avocat',                       'juridique',   'Avocat',                                                      NULL,       390),
('huissier',                   'Huissier',                     'juridique',   'Huissier de justice',                                         NULL,       400),

-- ── Assurance / banque / finance ──
('assureur',                   'Assureur',                     'financier',   'Compagnie d''assurance',                                      NULL,       410),
('courtier',                   'Courtier',                     'financier',   'Courtier assurance ou crédit',                                NULL,       420),
('assure_gli',                 'Assuré GLI',                   'financier',   'Locataire couvert par une garantie loyers impayés',           'bail',     430),
('banquier',                   'Banquier',                     'financier',   'Interlocuteur bancaire',                                      NULL,       440),
('interlocuteur_banque',       'Interlocuteur banque',         'financier',   'Contact dans un établissement bancaire',                      NULL,       450),
('beneficiaire_virement',      'Bénéficiaire virement',        'financier',   'Destinataire d''un virement récurrent',                       NULL,       460),

-- ── Commercial / CRM ──
('prospect_vendeur',           'Prospect vendeur',             'crm',         'Souhaite vendre (pas encore mandaté)',                        NULL,       500),
('prospect_bailleur',          'Prospect bailleur',            'crm',         'Souhaite louer son bien',                                     NULL,       510),
('prospect_acquereur',         'Prospect acquéreur',           'crm',         'Recherche un bien à acheter',                                 NULL,       520),
('prospect_locataire',         'Prospect locataire',           'crm',         'Recherche un bien à louer',                                   NULL,       530),
('partenaire_apporteur',       'Partenaire apporteur',         'crm',         'Apporteur d''affaires',                                       NULL,       540),
('partenaire_commercial',      'Partenaire commercial',        'crm',         'Partenaire business (agence, institutionnel…)',               NULL,       550),

-- ── Sinistres / interventions / réunions ──
('declarant_sinistre',         'Déclarant sinistre',           'acteur_immo', 'Personne ayant déclaré un sinistre',                          'sinistre', 600),
('intervenant_sinistre',       'Intervenant sinistre',         'prestataire', 'Expert ou prestataire sur un sinistre',                       'sinistre', 610),
('demandeur_intervention',     'Demandeur intervention',       'acteur_immo', 'Demandeur d''une intervention technique',                     'bien',     620),
('participant_reunion',        'Participant réunion',          'acteur_immo', 'Convoqué/présent à une réunion',                              'reunion',  630),

-- ── Contacts opérationnels ──
('contact_urgence',            'Contact d''urgence',           'contact',     'Contact joignable en urgence pour un bien/immeuble',          NULL,       700),
('contact_facturation',        'Contact facturation',          'contact',     'Interlocuteur facturation d''une entité',                     NULL,       710),
('contact_comptable',          'Contact comptable',            'contact',     'Interlocuteur comptable',                                     NULL,       720),
('contact_juridique',          'Contact juridique',            'contact',     'Interlocuteur juridique',                                     NULL,       730),
('contact_technique',          'Contact technique',            'contact',     'Interlocuteur technique',                                     NULL,       740),
('voisin',                     'Voisin',                       'contact',     'Voisin identifié (contexte d''un bien)',                      'bien',     750)
ON DUPLICATE KEY UPDATE libelle=VALUES(libelle), categorie=VALUES(categorie), description=VALUES(description);
