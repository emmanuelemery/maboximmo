### `biens` (source: `sql/maboximmo_export_2026-04-13.sql:2008`)

**Colonnes**
- `id` — `int(10) unsigned NOT NULL AUTO_INCREMENT`
- `id_immeuble` — `int(10) unsigned DEFAULT NULL`
- `id_agence` — `int(10) unsigned DEFAULT NULL`
- `id_societe` — `int(10) unsigned DEFAULT NULL`
- `id_proprietaire` — `int(10) unsigned DEFAULT NULL`
- `id_programme` — `int(10) unsigned DEFAULT NULL`
- `id_ville` — `int(10) unsigned DEFAULT NULL`
- `id_type_bien` — `int(10) unsigned NOT NULL`
- `reference_bien` — `varchar(50) DEFAULT NULL`
- `reference_externe` — `varchar(100) DEFAULT NULL`
- `reference_source` — `varchar(100) DEFAULT NULL`
- `logiciel_source` — `varchar(100) DEFAULT NULL`
- `sous_type_bien` — `varchar(100) DEFAULT NULL`
- `usage_bien` — `varchar(50) DEFAULT NULL`
- `statut_bien` — `varchar(50) DEFAULT 'actif'`
- `type_commercialisation` — `varchar(50) DEFAULT NULL`
- `designation` — `varchar(255) DEFAULT NULL`
- `slug` — `varchar(190) DEFAULT NULL`
- `titre_seo` — `varchar(255) DEFAULT NULL`
- `meta_description` — `varchar(320) DEFAULT NULL`
- `schema_json` — `longtext DEFAULT NULL`
- `featured_score` — `int(11) NOT NULL DEFAULT 0`
- `adresse_visible_public` — `tinyint(1) NOT NULL DEFAULT 0`
- `adresse_1` — `varchar(255) DEFAULT NULL`
- `adresse_2` — `varchar(255) DEFAULT NULL`
- `code_postal` — `varchar(10) DEFAULT NULL`
- `ville` — `varchar(150) DEFAULT NULL`
- `precision_geoloc` — `varchar(50) DEFAULT NULL`
- `lot_principal` — `varchar(100) DEFAULT NULL`
- `lot_secondaire` — `varchar(100) DEFAULT NULL`
- `etage` — `int(11) DEFAULT NULL`
- `numero_porte` — `varchar(50) DEFAULT NULL`
- `dernier_etage` — `tinyint(1) NOT NULL DEFAULT 0`
- `ascenseur` — `tinyint(1) NOT NULL DEFAULT 0`
- `interphone` — `tinyint(1) NOT NULL DEFAULT 0`
- `digicode` — `tinyint(1) NOT NULL DEFAULT 0`
- `alarme` — `tinyint(1) NOT NULL DEFAULT 0`
- `climatisation` — `tinyint(1) NOT NULL DEFAULT 0`
- `fibre` — `tinyint(1) NOT NULL DEFAULT 0`
- `double_vitrage` — `tinyint(1) NOT NULL DEFAULT 0`
- `volets_roulants` — `tinyint(1) NOT NULL DEFAULT 0`
- `cheminee` — `tinyint(1) NOT NULL DEFAULT 0`
- `annee_construction` — `int(11) DEFAULT NULL`
- `nb_pieces` — `int(11) DEFAULT NULL`
- `nb_chambres` — `int(11) DEFAULT NULL`
- `nb_salles_bain` — `int(11) DEFAULT NULL`
- `nb_salles_eau` — `int(11) DEFAULT NULL`
- `nb_wc` — `int(11) DEFAULT NULL`
- `cuisine_type` — `varchar(100) DEFAULT NULL`
- `cuisine_equipee` — `tinyint(1) NOT NULL DEFAULT 0`
- `nb_niveaux` — `int(11) DEFAULT NULL`
- `balcon` — `tinyint(1) DEFAULT 0`
- `terrasse` — `tinyint(1) DEFAULT 0`
- `jardin` — `tinyint(1) DEFAULT 0`
- `cour` — `tinyint(1) DEFAULT 0`
- `cave` — `tinyint(1) DEFAULT 0`
- `grenier` — `tinyint(1) DEFAULT 0`
- `garage` — `tinyint(1) DEFAULT 0`
- `box` — `tinyint(1) DEFAULT 0`
- `parking_nb` — `int(11) DEFAULT NULL`
- `piscine` — `tinyint(1) DEFAULT 0`
- `dependances` — `tinyint(1) DEFAULT 0`
- `travaux_a_prevoir` — `tinyint(1) NOT NULL DEFAULT 0`
- `etat_bien` — `varchar(100) DEFAULT NULL`
- `standing` — `varchar(100) DEFAULT NULL`
- `exposition` — `varchar(100) DEFAULT NULL`
- `vue` — `varchar(100) DEFAULT NULL`
- `nuisances` — `varchar(255) DEFAULT NULL`
- `chauffage_type` — `varchar(100) DEFAULT NULL`
- `chauffage_energie` — `varchar(100) DEFAULT NULL`
- `eau_chaude_type` — `varchar(100) DEFAULT NULL`
- `menuiseries` — `varchar(100) DEFAULT NULL`
- `isolation` — `varchar(100) DEFAULT NULL`
- `acces_camion` — `tinyint(1) DEFAULT 0`
- `porte_sectionnelle` — `tinyint(1) DEFAULT 0`
- `visibilite_commerciale` — `tinyint(1) DEFAULT 0`
- `vitrine` — `tinyint(1) DEFAULT 0`
- `stationnement_facile` — `tinyint(1) DEFAULT 0`
- `occupation_bien` — `varchar(50) DEFAULT NULL`
- `disponibilite_bien` — `varchar(100) DEFAULT NULL`
- `dpe_classe` — `varchar(10) DEFAULT NULL`
- `ges_classe` — `varchar(10) DEFAULT NULL`
- `dpe_valeur` — `int(11) DEFAULT NULL`
- `ges_valeur` — `int(11) DEFAULT NULL`
- `dpe_date_realisation` — `date DEFAULT NULL COMMENT 'Date de r├®alisation du DPE (distingue version 2011/2021)'`
- `dpe_vierge` — `tinyint(1) NOT NULL DEFAULT 0 COMMENT 'DPE vierge (non r├®alisable) ÔÇö uniquement pour DPE < 01/07/2021'`
- `dpe_reference_certificat` — `varchar(50) DEFAULT NULL COMMENT 'Numero ADEME du DPE'`
- `annee_reference_depenses` — `int(11) DEFAULT NULL`
- `date_indice_prix_energies` — `date DEFAULT NULL COMMENT 'Date de r├®f├®rence des prix utilis├®s pour l''estimation (remplace annee_reference_depenses)'`
- `bien_en_copropriete` — `tinyint(1) DEFAULT 0`
- `copro_nb_lots` — `int(11) DEFAULT NULL`
- `copro_procedure` — `tinyint(1) NOT NULL DEFAULT 0`
- `alur_copropriete_plan_sauvegarde` — `tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Copropri├®t├® sous plan de sauvegarde'`
- `alur_copropriete_etat_carence` — `tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Copropri├®t├® en ├®tat de carence'`
- `louable_immediatement` — `tinyint(1) NOT NULL DEFAULT 0`
- `commentaire` — `text DEFAULT NULL`
- `reprise_descriptif` — `text DEFAULT NULL COMMENT 'Descriptif original extrait de la fiche source'`
- `published_at` — `datetime DEFAULT NULL`
- `date_creation` — `datetime DEFAULT current_timestamp()`
- `date_modification` — `datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()`
- `loyer_meuble` — `tinyint(1) NOT NULL DEFAULT 0`
- `animaux_acceptes` — `tinyint(1) NOT NULL DEFAULT 0`
- `enc_zone` — `varchar(10) DEFAULT NULL`
- `points_forts` — `text DEFAULT NULL`
- `description` — `text DEFAULT NULL`
- `mots_cles` — `text DEFAULT NULL`
- `accroche_commerciale` — `varchar(255) DEFAULT NULL`
- `chauffage_vmc` — `tinyint(1) NOT NULL DEFAULT 0`
- `chauffage_vmc_df` — `tinyint(1) NOT NULL DEFAULT 0`
- `chauffage_plancher` — `tinyint(1) NOT NULL DEFAULT 0`
- `chauffage_regulateur` — `tinyint(1) NOT NULL DEFAULT 0`
- `chauffage_thermostat` — `tinyint(1) NOT NULL DEFAULT 0`
- `eau_chaude_solaire` — `tinyint(1) NOT NULL DEFAULT 0`
- `syndic_type` — `varchar(50) DEFAULT NULL`
- `copro_travaux_nature` — `text DEFAULT NULL`
- `altitude` — `smallint(6) DEFAULT NULL COMMENT 'Altitude du bien en m├¿tres (requis pour zones H1b/H1c/H2d > 800m)'`
- `zone_georisque` — `tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Bien en zone expos├®e ├á des risques (G├®orisques) ÔÇö obligation 01/01/2023'`
- `obligation_debroussaillement` — `tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Zone soumise ├á obligation de d├®broussaillement ÔÇö obligation 01/01/2025'`
- `erp_date_realisation` — `date DEFAULT NULL COMMENT 'Date d''├®tablissement de l''├ëtat des Risques et Pollutions'`
- `estimation_agence_date` — `date DEFAULT NULL COMMENT 'Date estimation agence'`
- `estimation_agence_notes` — `text DEFAULT NULL COMMENT 'Notes internes estimation'`
- `numero_lot` — `varchar(20) DEFAULT NULL COMMENT 'Ex: 0001 ÔÇö num├®ro de lot dans l immeuble'`
- `code_crg` — `varchar(30) DEFAULT NULL`
- `disponible_le` — `date DEFAULT NULL`
- `annonce_texte_lbc` — `text DEFAULT NULL`
- `annonce_lbc_generee_le` — `timestamp NULL DEFAULT NULL`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `UNIQUE KEY `uk_biens_slug` (`slug`)`
- `KEY `idx_biens_immeuble` (`id_immeuble`)`
- `KEY `idx_biens_ville` (`id_ville`)`
- `KEY `idx_biens_type` (`id_type_bien`)`
- `KEY `idx_biens_reference` (`reference_bien`)`
- `KEY `idx_biens_usage` (`usage_bien`)`
- `KEY `idx_biens_agence` (`id_agence`)`
- `KEY `idx_biens_proprietaire` (`id_proprietaire`)`
- `KEY `idx_biens_programme` (`id_programme`)`
- `KEY `idx_biens_societe` (`id_societe`)`
- `KEY `idx_biens_statut_bien` (`statut_bien`)`
- `KEY `idx_biens_logiciel_source` (`logiciel_source`)`
- `KEY `idx_biens_dpe_classe` (`dpe_classe`)`
- `KEY `idx_biens_ges_classe` (`ges_classe`)`
- `KEY `idx_biens_featured_score` (`featured_score`)`
- `KEY `idx_biens_recherche_1` (`id_ville`,`id_type_bien`,`statut_bien`)`
- `KEY `idx_biens_recherche_2` (`id_agence`,`statut_bien`,`id_type_bien`)`
- `KEY `idx_biens_recherche_3` (`id_ville`,`statut_bien`,`featured_score`)`
- `KEY `idx_biens_recherche_4` (`id_ville`,`surface_habitable`,`nb_pieces`)`
- `KEY `idx_biens_zone_georisque` (`zone_georisque`)`

**Contraintes (FK)**
- `CONSTRAINT `fk_biens_agence` FOREIGN KEY (`id_agence`) REFERENCES `agences` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`
- `CONSTRAINT `fk_biens_immeuble` FOREIGN KEY (`id_immeuble`) REFERENCES `immeubles` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`
- `CONSTRAINT `fk_biens_programme` FOREIGN KEY (`id_programme`) REFERENCES `programmes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`
- `CONSTRAINT `fk_biens_proprietaire` FOREIGN KEY (`id_proprietaire`) REFERENCES `proprietaires` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`
- `CONSTRAINT `fk_biens_societe` FOREIGN KEY (`id_societe`) REFERENCES `societes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`
- `CONSTRAINT `fk_biens_type` FOREIGN KEY (`id_type_bien`) REFERENCES `types_bien` (`id`) ON UPDATE CASCADE`
- `CONSTRAINT `fk_biens_ville` FOREIGN KEY (`id_ville`) REFERENCES `villes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`

### `annonces` (source: `sql/maboximmo_export_2026-04-13.sql:857`)

**Colonnes**
- `id` — `int(10) unsigned NOT NULL AUTO_INCREMENT`
- `id_bien` — `int(10) unsigned NOT NULL`
- `id_agence` — `int(10) unsigned DEFAULT NULL`
- `id_societe` — `int(10) unsigned DEFAULT NULL`
- `id_mandat` — `int(10) unsigned DEFAULT NULL`
- `id_portail_source` — `int(10) unsigned DEFAULT NULL`
- `id_user` — `int(10) unsigned DEFAULT NULL`
- `publiee_par` — `int(10) unsigned DEFAULT NULL`
- `reference_annonce` — `varchar(50) DEFAULT NULL`
- `reference_externe_principale` — `varchar(100) DEFAULT NULL`
- `source_annonce` — `varchar(100) DEFAULT 'interne'`
- `slug` — `varchar(190) DEFAULT NULL`
- `meta_title` — `varchar(255) DEFAULT NULL`
- `meta_description` — `varchar(320) DEFAULT NULL`
- `url_canonique` — `varchar(255) DEFAULT NULL`
- `langue` — `varchar(10) DEFAULT 'fr'`
- `type_transaction` — `varchar(50) NOT NULL`
- `type_diffusion` — `varchar(50) DEFAULT NULL`
- `statut` — `varchar(50) DEFAULT 'brouillon'`
- `etat_publication` — `varchar(50) DEFAULT 'brouillon'`
- `motif_masquage` — `varchar(255) DEFAULT NULL`
- `titre` — `varchar(255) DEFAULT NULL`
- `description` — `text DEFAULT NULL`
- `resume_court` — `text DEFAULT NULL`
- `points_forts` — `text DEFAULT NULL`
- `accroche_commerciale` — `varchar(255) DEFAULT NULL`
- `devise` — `varchar(10) DEFAULT 'EUR'`
- `honoraires_charge` — `varchar(50) DEFAULT NULL`
- `honoraires_charge_acquereur` — `tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Honoraires (tout ou partie) ├á charge acqu├®reur'`
- `honoraires_charge_vendeur` — `tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Honoraires (tout ou partie) ├á charge vendeur'`
- `bareme_honoraires_url` — `varchar(255) DEFAULT NULL`
- `zone_encadrement_loyer` — `tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Bien en zone soumise ├á encadrement des loyers'`
- `ancien_loyer_date_revision` — `date DEFAULT NULL COMMENT 'Date de la derni├¿re r├®vision du loyer'`
- `ancien_locataire_date_sortie` — `date DEFAULT NULL COMMENT 'Date de sortie du pr├®c├®dent locataire'`
- `ancien_loyer_communique` — `tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Le bailleur souhaite communiquer ces infos (obligatoire en zone encadrement)'`
- `loyer_est_cc` — `tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Le montant loyer est charges comprises'`
- `duree_bail_mois` — `int(11) DEFAULT NULL`
- `dpe_classe` — `varchar(5) DEFAULT NULL`
- `ges_classe` — `varchar(5) DEFAULT NULL`
- `dpe_valeur` — `int(11) DEFAULT NULL`
- `ges_valeur` — `int(11) DEFAULT NULL`
- `video_url` — `varchar(255) DEFAULT NULL`
- `visite_virtuelle_url` — `varchar(255) DEFAULT NULL`
- `plan_url` — `varchar(255) DEFAULT NULL`
- `brochure_url` — `varchar(255) DEFAULT NULL`
- `contact_nom` — `varchar(190) DEFAULT NULL`
- `contact_email` — `varchar(190) DEFAULT NULL`
- `contact_telephone` — `varchar(30) DEFAULT NULL`
- `rdv_en_ligne_url` — `varchar(255) DEFAULT NULL`
- `meuble` — `tinyint(1) DEFAULT 0`
- `disponible_de_suite` — `tinyint(1) DEFAULT 0`
- `date_disponibilite` — `date DEFAULT NULL`
- `visible_site` — `tinyint(1) DEFAULT 1`
- `visible_portails` — `tinyint(1) DEFAULT 0`
- `exclusivite` — `tinyint(1) NOT NULL DEFAULT 0`
- `nouveaute` — `tinyint(1) NOT NULL DEFAULT 0`
- `vue_mer` — `tinyint(1) NOT NULL DEFAULT 0`
- `coup_coeur` — `tinyint(1) DEFAULT 0`
- `date_mise_en_ligne` — `datetime DEFAULT NULL`
- `date_fin_annonce` — `datetime DEFAULT NULL`
- `indexable` — `tinyint(1) NOT NULL DEFAULT 1`
- `schema_json` — `longtext DEFAULT NULL`
- `texte_ia` — `mediumtext DEFAULT NULL`
- `titre_ia` — `varchar(255) DEFAULT NULL`
- `score_qualite` — `int(11) NOT NULL DEFAULT 0`
- `boostee` — `tinyint(1) NOT NULL DEFAULT 0`
- `ordre_mise_en_avant` — `int(11) NOT NULL DEFAULT 0`
- `date_creation` — `datetime DEFAULT current_timestamp()`
- `date_modification` — `datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()`
- `date_validation` — `datetime DEFAULT NULL`
- `date_derniere_sync` — `datetime DEFAULT NULL`
- `url_tarifs_publics` — `varchar(2083) DEFAULT NULL COMMENT 'URL publique du bar├¿me des prix du professionnel (obligatoire)'`
- `mandat_numero` — `varchar(50) DEFAULT NULL COMMENT 'N┬░ de mandat'`
- `date_mandat` — `date DEFAULT NULL COMMENT 'Date de signature du mandat'`
- `mandat_echeance` — `date DEFAULT NULL COMMENT 'Date d''├®ch├®ance du mandat'`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `UNIQUE KEY `uk_annonces_slug` (`slug`)`
- `KEY `idx_annonces_bien` (`id_bien`)`
- `KEY `idx_annonces_user` (`id_user`)`
- `KEY `idx_annonces_transaction` (`type_transaction`)`
- `KEY `idx_annonces_statut` (`statut`)`
- `KEY `idx_annonces_prix` (`prix`)`
- `KEY `idx_annonces_loyer` (`loyer`)`
- `KEY `idx_annonces_agence` (`id_agence`)`
- `KEY `idx_annonces_mandat` (`id_mandat`)`
- `KEY `idx_annonces_portail_source` (`id_portail_source`)`
- `KEY `idx_annonces_societe` (`id_societe`)`
- `KEY `idx_annonces_etat_publication` (`etat_publication`)`
- `KEY `idx_annonces_source_annonce` (`source_annonce`)`
- `KEY `idx_annonces_date_validation` (`date_validation`)`
- `KEY `idx_annonces_score_qualite` (`score_qualite`)`
- `KEY `idx_annonces_boostee` (`boostee`)`
- `KEY `idx_annonces_ordre_mise_en_avant` (`ordre_mise_en_avant`)`
- `KEY `fk_annonces_publiee_par` (`publiee_par`)`
- `KEY `idx_annonces_recherche_1` (`visible_site`,`type_transaction`,`statut`,`prix`)`
- `KEY `idx_annonces_recherche_2` (`visible_site`,`type_transaction`,`loyer`)`
- `KEY `idx_annonces_recherche_3` (`id_agence`,`visible_site`,`etat_publication`)`
- `KEY `idx_annonces_recherche_4` (`id_bien`,`visible_site`,`type_transaction`)`
- `KEY `idx_annonces_recherche_5` (`boostee`,`ordre_mise_en_avant`,`date_mise_en_ligne`)`
- `KEY `idx_annonces_recherche_6` (`visible_site`,`indexable`,`date_mise_en_ligne`)`

**Contraintes (FK)**
- `CONSTRAINT `fk_annonces_agence` FOREIGN KEY (`id_agence`) REFERENCES `agences` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`
- `CONSTRAINT `fk_annonces_bien` FOREIGN KEY (`id_bien`) REFERENCES `biens` (`id`) ON DELETE CASCADE ON UPDATE CASCADE`
- `CONSTRAINT `fk_annonces_mandat` FOREIGN KEY (`id_mandat`) REFERENCES `mandats` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`
- `CONSTRAINT `fk_annonces_portail_source` FOREIGN KEY (`id_portail_source`) REFERENCES `portails` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`
- `CONSTRAINT `fk_annonces_publiee_par` FOREIGN KEY (`publiee_par`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`
- `CONSTRAINT `fk_annonces_societe` FOREIGN KEY (`id_societe`) REFERENCES `societes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`
- `CONSTRAINT `fk_annonces_user` FOREIGN KEY (`id_user`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`

### `baux` (source: `sql/maboximmo_export_2026-04-13.sql:1675`)

**Colonnes**
- `id` — `int(10) unsigned NOT NULL AUTO_INCREMENT`
- `id_bien` — `int(10) unsigned NOT NULL`
- `id_proprietaire` — `int(10) unsigned DEFAULT NULL`
- `reference_bail` — `varchar(50) DEFAULT NULL`
- `type_bail` — `varchar(50) DEFAULT NULL`
- `date_signature` — `date DEFAULT NULL`
- `date_debut` — `date DEFAULT NULL`
- `date_fin` — `date DEFAULT NULL`
- `locataire_nom` — `varchar(255) DEFAULT NULL`
- `locataire_email` — `varchar(190) DEFAULT NULL`
- `locataire_telephone` — `varchar(30) DEFAULT NULL`
- `statut` — `varchar(50) DEFAULT 'actif'`
- `commentaire` — `text DEFAULT NULL`
- `date_creation` — `datetime DEFAULT current_timestamp()`
- `date_modification` — `datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()`
- `locataire_societe` — `varchar(200) DEFAULT NULL`
- `notes` — `text DEFAULT NULL`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `KEY `idx_baux_bien` (`id_bien`)`
- `KEY `idx_baux_type` (`type_bail`)`
- `KEY `idx_baux_statut` (`statut`)`
- `KEY `idx_baux_date_debut` (`date_debut`)`
- `KEY `idx_baux_proprietaire` (`id_proprietaire`)`

**Contraintes (FK)**
- `CONSTRAINT `fk_baux_bien` FOREIGN KEY (`id_bien`) REFERENCES `biens` (`id`) ON DELETE CASCADE ON UPDATE CASCADE`
- `CONSTRAINT `fk_baux_proprietaire` FOREIGN KEY (`id_proprietaire`) REFERENCES `proprietaires` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`

### `documents` (source: `sql/maboximmo_export_2026-04-13.sql:3080`)

**Colonnes**
- `id` — `int(10) unsigned NOT NULL AUTO_INCREMENT`
- `id_societe` — `int(10) unsigned DEFAULT NULL`
- `id_agence` — `int(10) unsigned DEFAULT NULL`
- `id_user` — `int(10) unsigned DEFAULT NULL`
- `id_bien` — `int(10) unsigned DEFAULT NULL`
- `id_annonce` — `int(10) unsigned DEFAULT NULL`
- `id_mandat` — `int(10) unsigned DEFAULT NULL`
- `id_proprietaire` — `int(10) unsigned DEFAULT NULL`
- `id_programme` — `int(10) unsigned DEFAULT NULL`
- `id_visite` — `int(10) unsigned DEFAULT NULL`
- `type_document` — `varchar(100) NOT NULL`
- `categorie_document` — `varchar(100) DEFAULT NULL`
- `nom_fichier` — `varchar(255) NOT NULL`
- `chemin_fichier` — `varchar(255) NOT NULL`
- `mime_type` — `varchar(100) DEFAULT NULL`
- `taille_octets` — `bigint(20) DEFAULT NULL`
- `titre` — `varchar(255) DEFAULT NULL`
- `description` — `text DEFAULT NULL`
- `visible_public` — `tinyint(1) NOT NULL DEFAULT 0`
- `date_document` — `date DEFAULT NULL`
- `date_expiration` — `date DEFAULT NULL`
- `date_creation` — `datetime NOT NULL DEFAULT current_timestamp()`
- `date_modification` — `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()`
- `analyzed_at` — `datetime DEFAULT NULL COMMENT 'Horodatage dernière analyse IA'`
- `analysis_json` — `longtext DEFAULT NULL COMMENT 'Résultat JSON brut de l''analyse IA'`
- `analysis_fields_filled` — `int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'Nombre de champs remplis grâce à l''analyse'`
- `analysis_comment` — `text DEFAULT NULL COMMENT 'Commentaire utilisateur : ce que l''IA doit chercher'`
- `analysis_error` — `varchar(500) DEFAULT NULL COMMENT 'Message d''erreur dernière analyse (si échec)'`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `KEY `idx_documents_societe` (`id_societe`)`
- `KEY `idx_documents_agence` (`id_agence`)`
- `KEY `idx_documents_user` (`id_user`)`
- `KEY `idx_documents_bien` (`id_bien`)`
- `KEY `idx_documents_annonce` (`id_annonce`)`
- `KEY `idx_documents_mandat` (`id_mandat`)`
- `KEY `idx_documents_proprietaire` (`id_proprietaire`)`
- `KEY `idx_documents_programme` (`id_programme`)`
- `KEY `idx_documents_visite` (`id_visite`)`
- `KEY `idx_documents_type_document` (`type_document`)`

**Contraintes (FK)**
- `CONSTRAINT `fk_documents_agence` FOREIGN KEY (`id_agence`) REFERENCES `agences` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`
- `CONSTRAINT `fk_documents_annonce` FOREIGN KEY (`id_annonce`) REFERENCES `annonces` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`
- `CONSTRAINT `fk_documents_bien` FOREIGN KEY (`id_bien`) REFERENCES `biens` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`
- `CONSTRAINT `fk_documents_mandat` FOREIGN KEY (`id_mandat`) REFERENCES `mandats` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`
- `CONSTRAINT `fk_documents_programme` FOREIGN KEY (`id_programme`) REFERENCES `programmes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`
- `CONSTRAINT `fk_documents_proprietaire` FOREIGN KEY (`id_proprietaire`) REFERENCES `proprietaires` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`
- `CONSTRAINT `fk_documents_societe` FOREIGN KEY (`id_societe`) REFERENCES `societes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`
- `CONSTRAINT `fk_documents_user` FOREIGN KEY (`id_user`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`
- `CONSTRAINT `fk_documents_visite` FOREIGN KEY (`id_visite`) REFERENCES `visites` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`

### `biens_documents` (source: `sql/maboximmo_export_2026-04-13.sql:2406`)

**Colonnes**
- `id` — `int(10) unsigned NOT NULL AUTO_INCREMENT`
- `id_bien` — `int(10) unsigned NOT NULL`
- `type_document` — `varchar(50) NOT NULL COMMENT 'dpe | certificat_surface | mesurage_loi_carrez | erp | autre'`
- `libelle` — `varchar(150) DEFAULT NULL`
- `url_fichier` — `varchar(2083) NOT NULL`
- `nom_original` — `varchar(255) DEFAULT NULL`
- `mime_type` — `varchar(50) DEFAULT NULL`
- `taille_octets` — `int(10) unsigned DEFAULT NULL`
- `date_validite` — `date DEFAULT NULL`
- `id_user_upload` — `int(10) unsigned DEFAULT NULL`
- `date_upload` — `datetime NOT NULL DEFAULT current_timestamp()`
- `visible_proprietaire` — `tinyint(1) DEFAULT 1`
- `visible_agences` — `tinyint(1) DEFAULT 0`
- `confidentiel` — `tinyint(1) DEFAULT 0 COMMENT '1 = admin seulement'`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `KEY `idx_bien_type` (`id_bien`,`type_document`)`

### `biens_photos` (source: `sql/maboximmo_export_2026-04-13.sql:2444`)

**Colonnes**
- `id` — `int(10) unsigned NOT NULL AUTO_INCREMENT`
- `id_bien` — `int(10) unsigned NOT NULL`
- `ordre` — `tinyint(3) unsigned NOT NULL DEFAULT 1 COMMENT 'Ordre d''affichage dans la biblioth├¿que du bien'`
- `url_photo` — `varchar(255) NOT NULL COMMENT 'Chemin relatif depuis public_html/'`
- `nom_original` — `varchar(255) DEFAULT NULL COMMENT 'Nom du fichier tel qu''upload├®'`
- `largeur` — `smallint(5) unsigned DEFAULT NULL`
- `hauteur` — `smallint(5) unsigned DEFAULT NULL`
- `poids_octets` — `int(10) unsigned DEFAULT NULL`
- `hash_md5` — `char(32) DEFAULT NULL COMMENT 'Empreinte MD5 ÔÇö d├®duplication'`
- `mime_type` — `varchar(50) DEFAULT NULL`
- `id_user_upload` — `int(10) unsigned DEFAULT NULL`
- `date_upload` — `datetime NOT NULL DEFAULT current_timestamp()`
- `date_modification` — `datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()`
- `categorie` — `varchar(30) DEFAULT NULL`
- `description_ia` — `text DEFAULT NULL`
- `description_ia_date` — `datetime DEFAULT NULL`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `KEY `idx_biens_photos_bien` (`id_bien`)`
- `KEY `idx_biens_photos_ordre` (`id_bien`,`ordre`)`
- `KEY `idx_biens_photos_hash` (`hash_md5`)`
- `KEY `idx_biens_photos_categorie` (`categorie`)`

**Contraintes (FK)**
- `CONSTRAINT `fk_biens_photos_bien` FOREIGN KEY (`id_bien`) REFERENCES `biens` (`id`) ON DELETE CASCADE ON UPDATE CASCADE`

### `annonces_photos` (source: `sql/maboximmo_export_2026-04-13.sql:1277`)

**Colonnes**
- `id` — `int(10) unsigned NOT NULL AUTO_INCREMENT`
- `id_annonce` — `int(10) unsigned NOT NULL`
- `url_photo` — `varchar(255) NOT NULL`
- `largeur` — `smallint(5) unsigned DEFAULT NULL COMMENT 'Largeur en px — évite le CLS (Core Web Vitals)'`
- `hauteur` — `smallint(5) unsigned DEFAULT NULL COMMENT 'Hauteur en px — évite le CLS (Core Web Vitals)'`
- `poids_octets` — `int(10) unsigned DEFAULT NULL COMMENT 'Poids du fichier en octets (supervision)'`
- `hash_md5` — `char(32) DEFAULT NULL COMMENT 'Empreinte MD5 du fichier original — déduplication'`
- `variante` — `varchar(20) NOT NULL DEFAULT 'original' COMMENT 'original | thumb | medium | large | xlarge'`
- `titre` — `varchar(255) DEFAULT NULL`
- `alt_photo` — `varchar(255) DEFAULT NULL`
- `caption` — `varchar(255) DEFAULT NULL COMMENT 'Légende affichée publiquement (SEO + accessibilité)'`
- `ordre_affichage` — `int(11) DEFAULT 0`
- `principale` — `tinyint(1) DEFAULT 0`
- `date_creation` — `datetime DEFAULT current_timestamp()`
- `date_modification` — `datetime DEFAULT current_timestamp() ON UPDATE current_timestamp() COMMENT 'Mise à jour auto à chaque changement'`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `UNIQUE KEY `uk_annonces_photos_ordre_variante` (`id_annonce`,`ordre_affichage`,`variante`)`
- `KEY `idx_annonces_photos_annonce` (`id_annonce`)`
- `KEY `idx_annonces_photos_principale` (`principale`)`
- `KEY `idx_annonces_photos_ordre` (`ordre_affichage`)`
- `KEY `idx_annonces_photos_variante` (`variante`)`
- `KEY `idx_annonces_photos_hash` (`hash_md5`)`

**Contraintes (FK)**
- `CONSTRAINT `fk_annonces_photos_annonce` FOREIGN KEY (`id_annonce`) REFERENCES `annonces` (`id`) ON DELETE CASCADE ON UPDATE CASCADE`

### `diffusion_portails` (source: `sql/maboximmo_export_2026-04-13.sql:3012`)

**Colonnes**
- `id` — `int(10) unsigned NOT NULL AUTO_INCREMENT`
- `id_annonce` — `int(10) unsigned NOT NULL`
- `id_portail` — `int(10) unsigned DEFAULT NULL`
- `portail` — `varchar(100) NOT NULL`
- `reference_portail` — `varchar(100) DEFAULT NULL`
- `date_envoi` — `datetime DEFAULT NULL`
- `date_retour` — `datetime DEFAULT NULL`
- `statut` — `varchar(50) DEFAULT 'a_envoyer'`
- `message_retour` — `text DEFAULT NULL`
- `date_creation` — `datetime DEFAULT current_timestamp()`
- `date_modification` — `datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `KEY `idx_diffusion_portails_annonce` (`id_annonce`)`
- `KEY `idx_diffusion_portails_portail` (`portail`)`
- `KEY `idx_diffusion_portails_statut` (`statut`)`
- `KEY `idx_diffusion_portails_id_portail` (`id_portail`)`

**Contraintes (FK)**
- `CONSTRAINT `fk_diffusion_portails_annonce` FOREIGN KEY (`id_annonce`) REFERENCES `annonces` (`id`) ON DELETE CASCADE ON UPDATE CASCADE`
- `CONSTRAINT `fk_diffusion_portails_id_portail` FOREIGN KEY (`id_portail`) REFERENCES `portails` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`

### `diffusion_portails_logs` (source: `sql/maboximmo_export_2026-04-13.sql:3050`)

**Colonnes**
- `id` — `bigint(20) unsigned NOT NULL AUTO_INCREMENT`
- `id_diffusion` — `int(10) unsigned NOT NULL`
- `statut` — `varchar(50) DEFAULT NULL`
- `message` — `text DEFAULT NULL`
- `payload_envoye` — `longtext DEFAULT NULL`
- `payload_retour` — `longtext DEFAULT NULL`
- `date_creation` — `datetime NOT NULL DEFAULT current_timestamp()`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `KEY `idx_diffusion_portails_logs_diffusion` (`id_diffusion`)`

**Contraintes (FK)**
- `CONSTRAINT `fk_diffusion_portails_logs_diffusion` FOREIGN KEY (`id_diffusion`) REFERENCES `diffusion_portails` (`id`) ON DELETE CASCADE ON UPDATE CASCADE`

### `mandats` (source: `sql/maboximmo_export_2026-04-13.sql:4505`)

**Colonnes**
- `id` — `int(10) unsigned NOT NULL AUTO_INCREMENT`
- `id_bien` — `int(10) unsigned NOT NULL`
- `id_proprietaire` — `int(10) unsigned DEFAULT NULL`
- `id_agence` — `int(10) unsigned DEFAULT NULL`
- `id_user` — `int(10) unsigned DEFAULT NULL`
- `numero_mandat` — `varchar(100) DEFAULT NULL`
- `type_mandat` — `varchar(50) DEFAULT NULL`
- `nature_mandat` — `varchar(50) DEFAULT NULL`
- `exclusif` — `tinyint(1) NOT NULL DEFAULT 0`
- `date_signature` — `date DEFAULT NULL`
- `date_debut` — `date DEFAULT NULL`
- `date_fin` — `date DEFAULT NULL`
- `honoraires_charge` — `varchar(50) DEFAULT NULL`
- `statut` — `varchar(50) NOT NULL DEFAULT 'projet'`
- `document_pdf` — `varchar(255) DEFAULT NULL`
- `commentaire` — `text DEFAULT NULL`
- `date_creation` — `datetime NOT NULL DEFAULT current_timestamp()`
- `date_modification` — `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()`
- `mandat_commercialisation` — `tinyint(1) DEFAULT 0 COMMENT '1 = mandat de diffusion vers agences externes'`
- `notes_agence` — `text DEFAULT NULL COMMENT 'Instructions visibles par les agences assign├®es'`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `UNIQUE KEY `uk_mandats_numero` (`numero_mandat`)`
- `KEY `idx_mandats_bien` (`id_bien`)`
- `KEY `idx_mandats_proprietaire` (`id_proprietaire`)`
- `KEY `idx_mandats_agence` (`id_agence`)`
- `KEY `idx_mandats_user` (`id_user`)`
- `KEY `idx_mandats_statut` (`statut`)`
- `KEY `idx_mandats_type` (`type_mandat`)`
- `KEY `idx_mandats_date_signature` (`date_signature`)`

**Contraintes (FK)**
- `CONSTRAINT `fk_mandats_agence` FOREIGN KEY (`id_agence`) REFERENCES `agences` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`
- `CONSTRAINT `fk_mandats_bien` FOREIGN KEY (`id_bien`) REFERENCES `biens` (`id`) ON DELETE CASCADE ON UPDATE CASCADE`
- `CONSTRAINT `fk_mandats_proprietaire` FOREIGN KEY (`id_proprietaire`) REFERENCES `proprietaires` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`
- `CONSTRAINT `fk_mandats_user` FOREIGN KEY (`id_user`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`

### `mandants` (source: `sql/maboximmo_export_2026-04-13.sql:4473`)

**Colonnes**
- `id` — `int(11) NOT NULL AUTO_INCREMENT`
- `civilite` — `varchar(10) DEFAULT NULL`
- `nom` — `varchar(100) NOT NULL`
- `prenom` — `varchar(100) DEFAULT NULL`
- `adresse` — `text DEFAULT NULL`
- `code_postal` — `varchar(10) DEFAULT NULL`
- `ville` — `varchar(100) DEFAULT NULL`
- `email` — `varchar(100) DEFAULT NULL`
- `telephone` — `varchar(20) DEFAULT NULL`
- `commentaire` — `text DEFAULT NULL`

**Index / clés**
- `PRIMARY KEY (`id`)`

### `proprietaires` (source: `sql/maboximmo_export_2026-04-13.sql:4913`)

**Colonnes**
- `id` — `int(10) unsigned NOT NULL AUTO_INCREMENT`
- `id_agence` — `int(10) unsigned DEFAULT NULL`
- `type_personne` — `varchar(30) DEFAULT 'physique'`
- `civilite` — `varchar(20) DEFAULT NULL`
- `nom` — `varchar(150) NOT NULL`
- `prenom` — `varchar(150) DEFAULT NULL`
- `societe` — `varchar(255) DEFAULT NULL`
- `email` — `varchar(190) DEFAULT NULL`
- `telephone` — `varchar(30) DEFAULT NULL`
- `telephone_2` — `varchar(30) DEFAULT NULL`
- `adresse_1` — `varchar(255) DEFAULT NULL`
- `adresse_2` — `varchar(255) DEFAULT NULL`
- `code_postal` — `varchar(10) DEFAULT NULL`
- `ville` — `varchar(150) DEFAULT NULL`
- `pays` — `varchar(100) DEFAULT 'France'`
- `commentaire` — `text DEFAULT NULL`
- `actif` — `tinyint(1) NOT NULL DEFAULT 1`
- `date_creation` — `datetime NOT NULL DEFAULT current_timestamp()`
- `date_modification` — `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()`
- `code_compte` — `varchar(20) DEFAULT NULL COMMENT 'Code CRG ex: 01040000'`
- `regie` — `varchar(150) DEFAULT NULL`
- `notes_internes` — `text DEFAULT NULL COMMENT 'Jamais visible du propri├®taire'`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `KEY `idx_proprietaires_agence` (`id_agence`)`
- `KEY `idx_proprietaires_nom` (`nom`)`
- `KEY `idx_proprietaires_email` (`email`)`
- `KEY `idx_proprietaires_telephone` (`telephone`)`

**Contraintes (FK)**
- `CONSTRAINT `fk_proprietaires_agence` FOREIGN KEY (`id_agence`) REFERENCES `agences` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`

### `users` (source: `sql/maboximmo_export_2026-04-13.sql:9880`)

**Colonnes**
- `id` — `int(10) unsigned NOT NULL AUTO_INCREMENT`
- `id_role` — `int(10) unsigned NOT NULL`
- `id_societe` — `int(10) unsigned DEFAULT NULL`
- `service` — `varchar(20) DEFAULT 'gestion'`
- `externe` — `tinyint(1) DEFAULT 0`
- `id_agence` — `int(10) unsigned DEFAULT NULL`
- `nom` — `varchar(150) NOT NULL`
- `prenom` — `varchar(150) DEFAULT NULL`
- `username` — `varchar(100) DEFAULT NULL`
- `fonction` — `varchar(150) DEFAULT NULL`
- `email` — `varchar(190) NOT NULL`
- `slug` — `varchar(190) DEFAULT NULL`
- `avatar_url` — `varchar(255) DEFAULT NULL`
- `bio_courte` — `text DEFAULT NULL`
- `telephone` — `varchar(30) DEFAULT NULL`
- `telephone_pro` — `varchar(30) DEFAULT NULL`
- `mot_de_passe` — `varchar(255) DEFAULT NULL`
- `last_login_at` — `datetime DEFAULT NULL`
- `last_login_ip` — `varchar(45) DEFAULT NULL`
- `email_verified_at` — `datetime DEFAULT NULL`
- `actif` — `tinyint(1) DEFAULT 1`
- `ordre_affichage` — `int(11) NOT NULL DEFAULT 0`
- `date_creation` — `datetime DEFAULT current_timestamp()`
- `date_modification` — `datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()`
- `id_legacy` — `int(11) DEFAULT NULL`
- `couleur` — `varchar(7) DEFAULT NULL`
- `vehicule_nom` — `varchar(100) DEFAULT NULL`
- `vehicule_puissance_fiscale` — `int(11) DEFAULT NULL`
- `super_admin` — `tinyint(1) NOT NULL DEFAULT 0`
- `gestion_salaires` — `tinyint(1) NOT NULL DEFAULT 0`
- `date_naissance` — `date DEFAULT NULL`
- `lieu_naissance` — `varchar(100) DEFAULT NULL`
- `nationalite` — `varchar(60) DEFAULT 'Française'`
- `num_secu` — `varchar(20) DEFAULT NULL`
- `adresse` — `varchar(255) DEFAULT NULL`
- `adresse2` — `varchar(255) DEFAULT NULL`
- `code_postal` — `varchar(10) DEFAULT NULL`
- `ville` — `varchar(100) DEFAULT NULL`
- `pays` — `varchar(60) DEFAULT 'France'`
- `date_entree` — `date DEFAULT NULL`
- `date_sortie` — `date DEFAULT NULL`
- `iban` — `varchar(34) DEFAULT NULL`
- `bic` — `varchar(11) DEFAULT NULL`
- `permis_conduire` — `varchar(20) DEFAULT NULL`
- `vehicule_immat` — `varchar(20) DEFAULT NULL`
- `contact_urgence_nom` — `varchar(150) DEFAULT NULL`
- `contact_urgence_tel` — `varchar(30) DEFAULT NULL`
- `photo_url` — `varchar(255) DEFAULT NULL`
- `notes_rh` — `text DEFAULT NULL`
- `vehicule_type` — `varchar(120) DEFAULT NULL`
- `user_conges_validated_at` — `datetime DEFAULT NULL COMMENT 'Horodatage de la validation initiale de l historique congés par l employé'`
- `user_agence_confirmed_at` — `datetime DEFAULT NULL COMMENT 'Horodatage de la confirmation des coordonnées agence par l employé'`
- `onboarding_completed` — `tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Devient 1 quand les 4 tâches A sont toutes complétées'`
- `onboarding_completed_at` — `datetime DEFAULT NULL COMMENT 'Horodatage de bascule onboarding_completed → 1'`
- `ik_declared_at` — `datetime DEFAULT NULL COMMENT 'Horodatage de la réponse à la modale véhicule (1er login)'`
- `salaire_submitted` — `tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Flag Section B : salaire du mois courant soumis'`
- `frais_submitted` — `tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Flag Section B : frais du mois courant soumis'`
- `ik_submitted` — `tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Flag Section B : IK du mois courant soumis'`
- `monthly_flags_month` — `varchar(7) DEFAULT NULL COMMENT 'YYYY-MM du mois des 3 flags ci-dessus — reset auto à la lecture'`
- `code_acces` — `varchar(20) DEFAULT NULL COMMENT 'ADMIN | PROPRIO | SIR | AGENCE | NEGO'`
- `id_proprietaire` — `int(10) unsigned DEFAULT NULL COMMENT 'FK proprietaires.id ÔÇö pour r├┤le PROPRIO/SIR'`
- `dashboard_type` — `varchar(30) DEFAULT 'standard'`
- `acces_maboximmo` — `tinyint(1) DEFAULT 0 COMMENT '1 = peut se connecter au portail MaBoxImmo'`
- `token_reset` — `varchar(100) DEFAULT NULL`
- `token_reset_expire` — `datetime DEFAULT NULL`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `UNIQUE KEY `uk_users_email` (`email`)`
- `UNIQUE KEY `uk_users_slug` (`slug`)`
- `UNIQUE KEY `uk_users_username` (`username`)`
- `KEY `idx_users_role` (`id_role`)`
- `KEY `idx_users_actif` (`actif`)`
- `KEY `idx_users_nom` (`nom`)`
- `KEY `idx_users_prenom` (`prenom`)`
- `KEY `idx_users_societe` (`id_societe`)`
- `KEY `idx_users_agence` (`id_agence`)`
- `KEY `idx_users_fonction` (`fonction`)`
- `KEY `idx_users_last_login_at` (`last_login_at`)`
- `KEY `idx_users_agence_actif` (`id_agence`,`actif`)`
- `KEY `idx_users_societe_actif` (`id_societe`,`actif`)`

**Contraintes (FK)**
- `CONSTRAINT `fk_users_agence` FOREIGN KEY (`id_agence`) REFERENCES `agences` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`
- `CONSTRAINT `fk_users_role` FOREIGN KEY (`id_role`) REFERENCES `roles` (`id`) ON UPDATE CASCADE`
- `CONSTRAINT `fk_users_societe` FOREIGN KEY (`id_societe`) REFERENCES `societes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`

### `roles` (source: `sql/maboximmo_export_2026-04-13.sql:8125`)

**Colonnes**
- `id` — `int(10) unsigned NOT NULL AUTO_INCREMENT`
- `nom` — `varchar(100) NOT NULL`
- `code` — `varchar(50) NOT NULL`
- `description` — `text DEFAULT NULL`
- `niveau_acces` — `int(11) DEFAULT 1`
- `actif` — `tinyint(1) DEFAULT 1`
- `date_creation` — `datetime DEFAULT current_timestamp()`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `UNIQUE KEY `uk_roles_code` (`code`)`
- `KEY `idx_roles_actif` (`actif`)`
- `KEY `idx_roles_niveau` (`niveau_acces`)`

### `taches` (source: `sql/maboximmo_export_2026-04-13.sql:9178`)

**Colonnes**
- `id` — `int(11) NOT NULL AUTO_INCREMENT`
- `id_projet` — `int(11) DEFAULT NULL`
- `id_immeuble` — `int(11) DEFAULT NULL`
- `id_createur` — `int(11) DEFAULT NULL`
- `titre` — `varchar(255) NOT NULL`
- `description` — `mediumtext DEFAULT NULL`
- `date_creation` — `datetime NOT NULL DEFAULT current_timestamp()`
- `date_echeance` — `date DEFAULT NULL`
- `date_cloture` — `datetime DEFAULT NULL`
- `dossier_ged` — `varchar(255) DEFAULT NULL`
- `reference_progiciel` — `varchar(255) DEFAULT NULL`
- `parent_tache_id` — `int(11) DEFAULT NULL`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `KEY `idx_id_projet` (`id_projet`)`
- `KEY `idx_id_immeuble` (`id_immeuble`)`
- `KEY `idx_statut` (`statut`)`
- `KEY `idx_priorite` (`priorite`)`
- `KEY `idx_date_echeance` (`date_echeance`)`

### `taches_documents` (source: `sql/maboximmo_export_2026-04-13.sql:9336`)

**Colonnes**
- `id` — `int(11) NOT NULL AUTO_INCREMENT`
- `id_tache` — `int(11) NOT NULL`
- `fichier` — `varchar(255) NOT NULL`
- `chemin` — `varchar(1024) NOT NULL`
- `type` — `varchar(100) DEFAULT NULL`
- `taille` — `bigint(20) DEFAULT NULL`
- `date_ajout` — `datetime NOT NULL DEFAULT current_timestamp()`
- `ajoute_par` — `int(11) DEFAULT NULL`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `KEY `idx_id_tache` (`id_tache`)`

### `ged_buckets` (source: `sql/maboximmo_export_2026-04-13.sql:3835`)

**Colonnes**
- `id` — `int(11) NOT NULL AUTO_INCREMENT`
- `code` — `varchar(50) NOT NULL`
- `libelle` — `varchar(255) DEFAULT NULL`
- `path_pattern` — `varchar(1024) NOT NULL`
- `actif` — `tinyint(1) DEFAULT 1`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `UNIQUE KEY `code` (`code`)`

### `immeubles` (source: `sql/maboximmo_export_2026-04-13.sql:3960`)

**Colonnes**
- `id` — `int(10) unsigned NOT NULL AUTO_INCREMENT`
- `id_ville` — `int(10) unsigned DEFAULT NULL`
- `id_agence` — `int(10) unsigned DEFAULT NULL`
- `id_societe` — `int(10) unsigned DEFAULT NULL`
- `reference_immeuble` — `varchar(50) DEFAULT NULL`
- `nom_immeuble` — `varchar(255) DEFAULT NULL`
- `slug` — `varchar(190) DEFAULT NULL`
- `adresse_1` — `varchar(255) NOT NULL`
- `adresse_2` — `varchar(255) DEFAULT NULL`
- `code_postal` — `varchar(10) DEFAULT NULL`
- `ville` — `varchar(150) DEFAULT NULL`
- `departement` — `varchar(100) DEFAULT NULL`
- `region` — `varchar(100) DEFAULT NULL`
- `pays` — `varchar(100) DEFAULT 'France'`
- `reference_cadastrale` — `varchar(100) DEFAULT NULL`
- `type_immeuble` — `varchar(100) DEFAULT NULL`
- `statut_immeuble` — `varchar(50) DEFAULT 'actif'`
- `mode_gestion` — `varchar(50) DEFAULT NULL`
- `annee_construction` — `int(11) DEFAULT NULL`
- `nb_niveaux` — `int(11) DEFAULT NULL`
- `nb_lots` — `int(11) DEFAULT NULL`
- `nb_batiments` — `int(11) DEFAULT NULL`
- `nb_logements` — `int(11) DEFAULT NULL`
- `nb_commerces` — `int(11) DEFAULT NULL`
- `nb_stationnements` — `int(11) DEFAULT NULL`
- `presence_ascenseur` — `tinyint(1) DEFAULT 0`
- `gardien` — `tinyint(1) NOT NULL DEFAULT 0`
- `chauffage_collectif` — `tinyint(1) NOT NULL DEFAULT 0`
- `eau_chaude_collective` — `tinyint(1) NOT NULL DEFAULT 0`
- `espace_vert` — `tinyint(1) NOT NULL DEFAULT 0`
- `piscine_collective` — `tinyint(1) NOT NULL DEFAULT 0`
- `date_mise_en_copro` — `date DEFAULT NULL`
- `syndic_actuel` — `varchar(255) DEFAULT NULL`
- `commentaire` — `text DEFAULT NULL`
- `description_courte` — `text DEFAULT NULL`
- `meta_title` — `varchar(255) DEFAULT NULL`
- `meta_description` — `varchar(320) DEFAULT NULL`
- `date_creation` — `datetime DEFAULT current_timestamp()`
- `date_modification` — `datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()`
- `code_crg` — `varchar(20) DEFAULT NULL COMMENT 'Code issu du PDF CRG ex: 01040076'`
- `id_proprietaire` — `int(10) unsigned DEFAULT NULL`
- `compte_gestion` — `varchar(20) DEFAULT NULL`
- `gps_source` — `varchar(30) DEFAULT NULL COMMENT 'nominatim | manuel | google'`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `UNIQUE KEY `uk_immeubles_slug` (`slug`)`
- `KEY `idx_immeubles_ville_id` (`id_ville`)`
- `KEY `idx_immeubles_reference` (`reference_immeuble`)`
- `KEY `idx_immeubles_cp` (`code_postal`)`
- `KEY `idx_immeubles_ville` (`ville`)`
- `KEY `idx_immeubles_agence` (`id_agence`)`
- `KEY `idx_immeubles_societe` (`id_societe`)`
- `KEY `idx_immeubles_statut_immeuble` (`statut_immeuble`)`

**Contraintes (FK)**
- `CONSTRAINT `fk_immeubles_agence` FOREIGN KEY (`id_agence`) REFERENCES `agences` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`
- `CONSTRAINT `fk_immeubles_societe` FOREIGN KEY (`id_societe`) REFERENCES `societes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`
- `CONSTRAINT `fk_immeubles_ville` FOREIGN KEY (`id_ville`) REFERENCES `villes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`

### `agences` (source: `sql/maboximmo_export_2026-04-13.sql:49`)

**Colonnes**
- `id` — `int(10) unsigned NOT NULL AUTO_INCREMENT`
- `id_societe` — `int(10) unsigned NOT NULL`
- `nom_agence` — `varchar(190) NOT NULL`
- `code_agence` — `varchar(50) DEFAULT NULL`
- `code_interne` — `varchar(50) DEFAULT NULL`
- `type_agence` — `varchar(100) DEFAULT NULL`
- `transaction_active` — `tinyint(1) NOT NULL DEFAULT 1`
- `location_active` — `tinyint(1) NOT NULL DEFAULT 1`
- `gestion_active` — `tinyint(1) NOT NULL DEFAULT 0`
- `syndic_active` — `tinyint(1) NOT NULL DEFAULT 0`
- `neuf_active` — `tinyint(1) NOT NULL DEFAULT 0`
- `rayon_prospection_km` — `int(11) DEFAULT NULL`
- `adresse_1` — `varchar(255) DEFAULT NULL`
- `adresse_2` — `varchar(255) DEFAULT NULL`
- `code_postal` — `varchar(10) DEFAULT NULL`
- `ville` — `varchar(150) DEFAULT NULL`
- `id_ville` — `int(10) unsigned DEFAULT NULL`
- `departement` — `varchar(100) DEFAULT NULL`
- `region` — `varchar(150) DEFAULT NULL`
- `pays` — `varchar(100) DEFAULT 'France'`
- `google_place_id` — `varchar(100) DEFAULT NULL`
- `nb_avis_google` — `int(11) DEFAULT NULL`
- `telephone` — `varchar(30) DEFAULT NULL`
- `email` — `varchar(190) DEFAULT NULL`
- `site_web` — `varchar(255) DEFAULT NULL`
- `slug` — `varchar(190) DEFAULT NULL`
- `logo_url` — `varchar(255) DEFAULT NULL`
- `cover_url` — `varchar(255) DEFAULT NULL`
- `video_url` — `varchar(255) DEFAULT NULL`
- `description` — `text DEFAULT NULL`
- `h1` — `varchar(255) DEFAULT NULL`
- `meta_title` — `varchar(255) DEFAULT NULL`
- `meta_description` — `varchar(320) DEFAULT NULL`
- `texte_intro` — `mediumtext DEFAULT NULL`
- `horaires` — `text DEFAULT NULL`
- `actif` — `tinyint(1) NOT NULL DEFAULT 1`
- `ordre_affichage` — `int(11) NOT NULL DEFAULT 0`
- `date_creation` — `datetime NOT NULL DEFAULT current_timestamp()`
- `date_modification` — `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()`
- `id_etablissement` — `int(11) DEFAULT NULL`
- `siret` — `varchar(20) NOT NULL`
- `commentaires` — `text NOT NULL`
- `nom_commercial` — `varchar(191) DEFAULT NULL`
- `email_contact` — `varchar(191) DEFAULT NULL`
- `email_facturation` — `varchar(191) DEFAULT NULL`
- `siren_siret` — `varchar(20) DEFAULT NULL`
- `rcs` — `varchar(191) DEFAULT NULL`
- `tva_intracom` — `varchar(32) DEFAULT NULL`
- `iban` — `varchar(34) DEFAULT NULL`
- `bic` — `varchar(11) DEFAULT NULL`
- `banque_nom` — `varchar(191) DEFAULT NULL`
- `titulaire_compte` — `varchar(191) DEFAULT NULL`
- `logo_path` — `varchar(255) DEFAULT NULL`
- `entete_pdf` — `text DEFAULT NULL`
- `pied_pdf` — `text DEFAULT NULL`
- `is_active` — `tinyint(1) NOT NULL DEFAULT 1`
- `created_at` — `timestamp NOT NULL DEFAULT current_timestamp()`
- `updated_at` — `timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `UNIQUE KEY `uk_agences_slug` (`slug`)`
- `KEY `idx_agences_societe` (`id_societe`)`
- `KEY `idx_agences_ville` (`ville`)`
- `KEY `idx_agences_id_ville` (`id_ville`)`
- `KEY `idx_agences_actif` (`actif`)`
- `KEY `idx_agences_transaction_active` (`transaction_active`)`
- `KEY `idx_agences_location_active` (`location_active`)`
- `KEY `idx_agences_gestion_active` (`gestion_active`)`
- `KEY `idx_agences_syndic_active` (`syndic_active`)`
- `KEY `idx_agences_ordre_affichage` (`ordre_affichage`)`
- `KEY `idx_agences_id_etablissement` (`id_etablissement`)`

**Contraintes (FK)**
- `CONSTRAINT `fk_agences_societe` FOREIGN KEY (`id_societe`) REFERENCES `societes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE`
- `CONSTRAINT `fk_agences_ville` FOREIGN KEY (`id_ville`) REFERENCES `villes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE`

### `societes` (source: `sql/maboximmo_export_2026-04-13.sql:8958`)

**Colonnes**
- `id` — `int(10) unsigned NOT NULL AUTO_INCREMENT`
- `nom` — `varchar(190) NOT NULL`
- `raison_sociale` — `varchar(255) DEFAULT NULL`
- `forme_juridique` — `varchar(100) DEFAULT NULL`
- `siret` — `varchar(20) DEFAULT NULL`
- `siren` — `varchar(20) DEFAULT NULL`
- `tva_intracom` — `varchar(50) DEFAULT NULL`
- `numero_carte_t` — `varchar(100) DEFAULT NULL`
- `cci_carte_t` — `varchar(190) DEFAULT NULL`
- `carte_t_activites` — `longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`carte_t_activites`))`
- `carte_t_date_expiration` — `date DEFAULT NULL`
- `garantie_financiere` — `varchar(255) DEFAULT NULL`
- `assurance_rcp` — `varchar(255) DEFAULT NULL`
- `adresse_1` — `varchar(255) DEFAULT NULL`
- `adresse_2` — `varchar(255) DEFAULT NULL`
- `code_postal` — `varchar(10) DEFAULT NULL`
- `ville` — `varchar(150) DEFAULT NULL`
- `pays` — `varchar(100) DEFAULT 'France'`
- `telephone` — `varchar(30) DEFAULT NULL`
- `email` — `varchar(190) DEFAULT NULL`
- `site_web` — `varchar(255) DEFAULT NULL`
- `slug` — `varchar(190) DEFAULT NULL`
- `logo_url` — `varchar(255) DEFAULT NULL`
- `couleur_principale` — `varchar(20) DEFAULT NULL`
- `couleur_secondaire` — `varchar(20) DEFAULT NULL`
- `favicon_url` — `varchar(255) DEFAULT NULL`
- `cover_url` — `varchar(255) DEFAULT NULL`
- `facebook_url` — `varchar(255) DEFAULT NULL`
- `instagram_url` — `varchar(255) DEFAULT NULL`
- `linkedin_url` — `varchar(255) DEFAULT NULL`
- `youtube_url` — `varchar(255) DEFAULT NULL`
- `google_business_url` — `varchar(255) DEFAULT NULL`
- `description` — `text DEFAULT NULL`
- `mention_legale` — `text DEFAULT NULL`
- `actif` — `tinyint(1) NOT NULL DEFAULT 1`
- `ordre_affichage` — `int(11) NOT NULL DEFAULT 0`
- `date_creation` — `datetime NOT NULL DEFAULT current_timestamp()`
- `date_modification` — `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()`
- `comptable_nom` — `varchar(120) DEFAULT NULL`
- `comptable_email` — `varchar(190) DEFAULT NULL`
- `comptable_telephone` — `varchar(50) DEFAULT NULL`
- `comptable_societe` — `varchar(190) DEFAULT NULL`
- `rib_emetteur_nom` — `varchar(190) DEFAULT NULL`
- `rib_emetteur_iban` — `varchar(64) DEFAULT NULL`
- `rib_emetteur_bic` — `varchar(16) DEFAULT NULL`
- `code_ape` — `varchar(10) DEFAULT '6831Z' COMMENT 'Code NAF/APE (Nomenclature des Activités Françaises)'`
- `convention_collective` — `varchar(190) DEFAULT 'IMMOBILIER' COMMENT 'Convention collective nationale applicable'`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `UNIQUE KEY `uk_societes_slug` (`slug`)`
- `KEY `idx_societes_nom` (`nom`)`
- `KEY `idx_societes_siret` (`siret`)`
- `KEY `idx_societes_actif` (`actif`)`
- `KEY `idx_societes_ordre_affichage` (`ordre_affichage`)`

### `etablissements` (source: `sql/maboximmo_export_2026-04-13.sql:3421`)

**Colonnes**
- `id` — `int(11) NOT NULL AUTO_INCREMENT`
- `nom` — `varchar(255) DEFAULT NULL`
- `adresse` — `varchar(255) DEFAULT NULL`
- `id_societe` — `int(11) NOT NULL`
- `logo` — `varchar(255) DEFAULT NULL`
- `siret` — `varchar(20) NOT NULL`
- `telephone` — `varchar(20) NOT NULL`
- `email` — `varchar(50) NOT NULL`
- `commentaires` — `text NOT NULL`
- `code_postal` — `varchar(5) NOT NULL`
- `ville` — `varchar(50) NOT NULL`
- `nom_commercial` — `varchar(191) DEFAULT NULL`
- `pays` — `varchar(64) DEFAULT 'FR'`
- `email_contact` — `varchar(191) DEFAULT NULL`
- `email_facturation` — `varchar(191) DEFAULT NULL`
- `siren_siret` — `varchar(20) DEFAULT NULL`
- `rcs` — `varchar(191) DEFAULT NULL`
- `tva_intracom` — `varchar(32) DEFAULT NULL`
- `iban` — `varchar(34) DEFAULT NULL`
- `bic` — `varchar(11) DEFAULT NULL`
- `banque_nom` — `varchar(191) DEFAULT NULL`
- `titulaire_compte` — `varchar(191) DEFAULT NULL`
- `logo_path` — `varchar(255) DEFAULT NULL`
- `entete_pdf` — `text DEFAULT NULL`
- `pied_pdf` — `text DEFAULT NULL`
- `is_active` — `tinyint(1) NOT NULL DEFAULT 1`
- `created_at` — `timestamp NOT NULL DEFAULT current_timestamp()`
- `updated_at` — `timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()`

**Index / clés**
- `PRIMARY KEY (`id`)`
- `KEY `id_societe` (`id_societe`)`
- `KEY `idx_etab_societe` (`id_societe`)`
- `KEY `idx_etab_ville` (`ville`)`
- `KEY `idx_etab_active` (`is_active`)`

**Contraintes (FK)**
- `CONSTRAINT `etablissements_ibfk_1` FOREIGN KEY (`id_societe`) REFERENCES `reg_societes` (`id`) ON DELETE CASCADE`

