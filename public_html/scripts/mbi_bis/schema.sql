-- mbi_bis/schema.sql — GÉNÉRÉ par `php mbi_bis.php schema`, ne pas éditer à la main.
-- ⚠️ Le schéma est VERSIONNÉ pour que `reset` reconstruise une base identique au
--    bit près. Un RESET qui ne reproduit pas exactement la même structure ne
--    prouve rien : la passe suivante ne serait pas comparable à la précédente.
-- source : maboximmo

SET FOREIGN_KEY_CHECKS = 0;

-- crgi_arbitrage — staging du module d’intégration
CREATE TABLE `crgi_arbitrage` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `import_id` int(10) unsigned NOT NULL,
  `groupe` varchar(60) NOT NULL COMMENT 'La regle qui produit cet arbitrage.',
  `cible_type` varchar(20) NOT NULL COMMENT 'IMMEUBLE | OCCUPATION | MOUVEMENT',
  `cible_id` int(10) unsigned NOT NULL COMMENT 'id de la ligne de staging concernee',
  `choix` varchar(120) NOT NULL DEFAULT '' COMMENT 'Le choix retenu parmi ceux proposes.',
  `precision_h` varchar(1000) DEFAULT NULL COMMENT 'Ce qu Emmanuel ajoute en clair.',
  `decide_par` int(10) unsigned DEFAULT NULL,
  `decide_le` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cible` (`import_id`,`cible_type`,`cible_id`),
  KEY `idx_groupe` (`import_id`,`groupe`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Les decisions humaines sur les arbitrages. Decider n est pas integrer.';

-- crgi_crg — staging du module d’intégration
CREATE TABLE `crgi_crg` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `import_id` int(10) unsigned NOT NULL,
  `piece_id` int(10) unsigned NOT NULL,
  `page_debut` int(10) unsigned NOT NULL,
  `page_fin` int(10) unsigned NOT NULL,
  `agence` varchar(120) DEFAULT NULL,
  `agence_id` int(10) unsigned DEFAULT NULL COMMENT 'Agence MBI etablie SANS AMBIGUITE. NULL = non etablie, et cela se voit.',
  `agence_source` varchar(20) NOT NULL DEFAULT 'INDETERMINABLE' COMMENT 'LUE | RAPPROCHEE | INDETERMINABLE',
  `agence_motif` varchar(300) DEFAULT NULL COMMENT 'Pourquoi ce verdict. Jamais laisse vide sur un INDETERMINABLE.',
  `format` varchar(32) DEFAULT NULL COMMENT 'lyon | emery_immo | septeo_spi | inconnu',
  `periode_cle` varchar(40) DEFAULT NULL,
  `periode_source` varchar(20) NOT NULL DEFAULT 'INDETERMINABLE' COMMENT 'LUE | INDETERMINABLE',
  `periode_debut` date DEFAULT NULL,
  `periode_fin` date DEFAULT NULL,
  `date_arrete` date DEFAULT NULL,
  `proprietaire` varchar(255) DEFAULT NULL,
  `compte` varchar(40) DEFAULT NULL,
  `immeuble` varchar(120) DEFAULT NULL COMMENT 'septeo_spi : une piece = un immeuble.',
  `certitude` varchar(24) NOT NULL DEFAULT 'INDETERMINABLE' COMMENT 'CERTAIN | PROBABLE | INDETERMINABLE',
  `motif` varchar(500) DEFAULT NULL COMMENT 'POURQUOI le moteur est sur, ou ne l est pas.',
  `empreinte` char(64) DEFAULT NULL COMMENT 'SHA-256 du texte lu. Transforme « meme cle » en « meme contenu demontre ».',
  `caracteres_lus` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'Volume de texte ayant servi a l empreinte. Trois caracteres ne demontrent rien.',
  `doublon_de` int(10) unsigned DEFAULT NULL COMMENT 'Occurrence dont celle-ci est la reenonciation. Les deux restent en base.',
  `doublon_statut` varchar(32) NOT NULL DEFAULT 'UNIQUE' COMMENT 'UNIQUE | REENONCIATION | MEME CLE CONTENU DIFFERENT',
  `doublon_qualification` char(1) DEFAULT NULL COMMENT 'A meme situation | B situation complementaire | C arbitrage necessaire',
  `doublon_qualif_motif` varchar(400) DEFAULT NULL COMMENT 'Sur quoi repose le verdict : montants communs, isoles, ecart relatif.',
  `inventaire_statut` varchar(24) DEFAULT NULL COMMENT 'DEJA CONNUE | NOUVELLE | COMPTE INCONNU | A VERIFIER',
  `inventaire_motif` varchar(400) DEFAULT NULL COMMENT 'Pourquoi ce verdict, et a quelle situation MBI elle est rapprochee.',
  `mbi_trimestre_id` int(11) DEFAULT NULL COMMENT 'crg_trimestres.id rapproche. Lecture seule : rien n est ecrit dans MBI.',
  `compte_qualification` char(1) DEFAULT NULL COMMENT 'A nouveau compte d un proprietaire connu | B nouveau proprietaire | C compte present non rapproche | D arbitrage',
  `compte_qualif_motif` varchar(400) DEFAULT NULL,
  `mbi_proprietaire_id` int(10) unsigned DEFAULT NULL COMMENT 'Candidat NOMME, jamais retenu d office : aucune identite n est creee ici.',
  `cree_le` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_import` (`import_id`),
  KEY `idx_piece_page` (`piece_id`,`page_debut`),
  KEY `idx_agence_periode` (`agence`,`periode_cle`),
  KEY `idx_certitude` (`certitude`),
  KEY `idx_agence_source` (`agence_source`),
  KEY `idx_periode_source` (`periode_source`),
  KEY `idx_empreinte` (`empreinte`),
  KEY `idx_doublon` (`doublon_statut`),
  KEY `idx_qualification` (`doublon_qualification`),
  KEY `idx_inventaire` (`inventaire_statut`),
  KEY `idx_compte_qualif` (`compte_qualification`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Un CRG LOGIQUE reconstruit depuis les pages. Porte son niveau de certitude.';

-- crgi_immeuble — staging du module d’intégration
CREATE TABLE `crgi_immeuble` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `import_id` int(10) unsigned NOT NULL,
  `crg_id` int(10) unsigned NOT NULL,
  `code` varchar(20) DEFAULT NULL COMMENT 'Code lu dans la reference de lot.',
  `nom` varchar(255) NOT NULL,
  `code_postal` varchar(10) DEFAULT NULL,
  `ville` varchar(150) DEFAULT NULL,
  `page` int(10) unsigned NOT NULL,
  `statut` varchar(24) NOT NULL DEFAULT 'INDETERMINE' COMMENT 'IDENTIQUE | NOUVEAU | MODIFIE | A ARBITRER | INDETERMINE',
  `mbi_immeuble_id` int(10) unsigned DEFAULT NULL COMMENT 'immeubles.id, sur correspondance EXACTE seulement.',
  `avant_apres` text DEFAULT NULL COMMENT 'Ce qui differe, champ par champ.',
  `motif` varchar(400) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_import` (`import_id`),
  KEY `idx_crg` (`crg_id`),
  KEY `idx_statut` (`statut`),
  KEY `idx_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Un immeuble lu dans un CRG, et le verdict de sa confrontation a MBI.';

-- crgi_import — staging du module d’intégration
CREATE TABLE `crgi_import` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `libelle` varchar(200) NOT NULL DEFAULT '',
  `statut` varchar(32) NOT NULL DEFAULT 'ANALYSE EN COURS' COMMENT 'ANALYSE EN COURS | A VALIDER | VALIDE PARTIELLEMENT | PRET A INTEGRER | INTEGRE | ANNULE',
  `nb_pieces` int(10) unsigned NOT NULL DEFAULT 0,
  `nb_pages` int(10) unsigned NOT NULL DEFAULT 0,
  `note` text DEFAULT NULL,
  `cree_le` datetime NOT NULL,
  `cree_par` int(10) unsigned DEFAULT NULL,
  `annule_le` datetime DEFAULT NULL,
  `annule_par` int(10) unsigned DEFAULT NULL,
  `annule_motif` varchar(255) DEFAULT NULL,
  `integre_le` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_statut` (`statut`),
  KEY `idx_cree` (`cree_le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Un depot de PDF CRG. Tout le staging s y rattache et s annule avec lui.';

-- crgi_lot — staging du module d’intégration
CREATE TABLE `crgi_lot` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `import_id` int(10) unsigned NOT NULL,
  `crg_id` int(10) unsigned NOT NULL,
  `reference` varchar(40) NOT NULL,
  `code_immeuble` varchar(20) DEFAULT NULL,
  `numero` varchar(20) DEFAULT NULL,
  `libelle` varchar(160) DEFAULT NULL,
  `locataire` varchar(190) DEFAULT NULL,
  `page` int(10) unsigned NOT NULL,
  `statut` varchar(24) NOT NULL DEFAULT 'INDETERMINE',
  `mbi_bien_id` int(10) unsigned DEFAULT NULL,
  `avant_apres` text DEFAULT NULL,
  `motif` varchar(400) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_import` (`import_id`),
  KEY `idx_crg` (`crg_id`),
  KEY `idx_statut` (`statut`),
  KEY `idx_ref` (`reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Un lot lu dans un CRG, et le verdict de sa confrontation a MBI.';

-- crgi_mouvement — staging du module d’intégration
CREATE TABLE `crgi_mouvement` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `import_id` int(10) unsigned NOT NULL,
  `crg_id` int(10) unsigned NOT NULL,
  `page` int(10) unsigned NOT NULL COMMENT 'Page PDF : on doit toujours pouvoir y revenir.',
  `section` varchar(60) DEFAULT NULL COMMENT 'La section imprimee par le document.',
  `immeuble` varchar(190) DEFAULT NULL,
  `lot_reference` varchar(40) DEFAULT NULL COMMENT 'NULL si le document ne le demontre pas au lot.',
  `locataire` varchar(190) DEFAULT NULL COMMENT 'NULL si le document ne le demontre pas.',
  `date_piece` date DEFAULT NULL,
  `periode_cle` varchar(40) DEFAULT NULL,
  `date_arrete` date DEFAULT NULL,
  `libelle` varchar(220) NOT NULL DEFAULT '',
  `colonne` varchar(20) NOT NULL DEFAULT '' COMMENT 'loyers|charges|autres|reste_du|debit|credit|inline — la colonne EST la nature',
  `montant` decimal(14,2) NOT NULL,
  `categorie` varchar(40) NOT NULL COMMENT 'LOYER APPELE | CHARGE APPELEE AU LOCATAIRE | AUTRE APPELE AU LOCATAIRE | ENCAISSEMENT | ENCOURS | CHARGE | FRAIS ET ASSURANCES | VERSEMENT PROPRIETAIRE | SOLDE | AGREGAT | DETAIL | INDETERMINABLE',
  `maille` varchar(12) NOT NULL DEFAULT 'COMPTE' COMMENT 'COMPTE | IMMEUBLE | LOT',
  `flux` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = STOCK (encours, solde) : jamais additionne a un flux, jamais cumule entre deux periodes.',
  `additionnable` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 = agregat, detail ou reimpression : conserve et tracable, jamais somme.',
  `reimpression` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Page reimprimee a l identique dans le MEME CRG. Demontre sur le texte, jamais sur les montants.',
  `provenance` varchar(16) NOT NULL DEFAULT 'LUE' COMMENT 'LUE — la phase 4 ne deduit aucun montant.',
  `rapprochement` varchar(28) DEFAULT NULL COMMENT 'DEJA PRESENT | NOUVEAU | CANDIDAT NON DEMONTRABLE | CONTRADICTION | HORS PERIMETRE',
  `mbi_ecriture_id` int(10) unsigned DEFAULT NULL COMMENT 'L ecriture crg_ecritures retenue, quand elle est DEMONTREE.',
  `rappro_motif` varchar(400) DEFAULT NULL COMMENT 'Ce qui demontre le verdict, ou ce qui empeche de le demontrer.',
  `motif` varchar(400) DEFAULT NULL,
  `x1` decimal(7,1) DEFAULT NULL COMMENT 'Bord droit du montant : la preuve de sa colonne.',
  PRIMARY KEY (`id`),
  KEY `idx_import` (`import_id`),
  KEY `idx_crg` (`crg_id`),
  KEY `idx_cat` (`import_id`,`categorie`),
  KEY `idx_lot` (`import_id`,`lot_reference`),
  KEY `idx_page` (`import_id`,`page`),
  KEY `idx_rappro` (`import_id`,`rapprochement`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Un euro, une nature, une maille, une page. Aucun total n y est stocke.';

-- crgi_occupation — staging du module d’intégration
CREATE TABLE `crgi_occupation` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `import_id` int(10) unsigned NOT NULL,
  `crg_id` int(10) unsigned NOT NULL,
  `lot_reference` varchar(40) NOT NULL,
  `code_immeuble` varchar(20) DEFAULT NULL,
  `periode_cle` varchar(40) DEFAULT NULL,
  `date_arrete` date DEFAULT NULL,
  `locataire` varchar(190) DEFAULT NULL COMMENT 'NULL = aucune ligne locataire imprimee.',
  `bail_du` date DEFAULT NULL,
  `bail_au` date DEFAULT NULL COMMENT 'Fin de bail IMPRIMEE par le document. NULL = le document ne la dit pas.',
  `rang` tinyint(3) unsigned NOT NULL DEFAULT 0 COMMENT 'Rang de l occupant dans son bloc de lot : 0 = le premier imprime.',
  `solde` decimal(12,2) DEFAULT NULL,
  `solde_source` varchar(24) NOT NULL DEFAULT 'NON DEMONTRABLE' COMMENT 'LUE | NON DEMONTRABLE — le document detache Solde de son montant',
  `page` int(10) unsigned NOT NULL,
  `statut` varchar(32) DEFAULT NULL COMMENT 'IDENTIQUE | NOUVEL ENTRANT | CHANGEMENT DE LOCATAIRE | PARTI | ANCIEN LOCATAIRE AVEC DETTE | A ARBITRER',
  `statut_motif` varchar(400) DEFAULT NULL,
  `precedent` varchar(190) DEFAULT NULL COMMENT 'Le titulaire de la periode precedente.',
  PRIMARY KEY (`id`),
  KEY `idx_import` (`import_id`),
  KEY `idx_lot` (`import_id`,`lot_reference`,`date_arrete`),
  KEY `idx_statut` (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Une observation d occupation par lot et par periode. L ancien locataire y reste.';

-- crgi_page — staging du module d’intégration
CREATE TABLE `crgi_page` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `import_id` int(10) unsigned NOT NULL,
  `piece_id` int(10) unsigned NOT NULL,
  `page_no` int(10) unsigned NOT NULL COMMENT '1-indexe, tel que le lecteur PDF numerote.',
  `crg_id` int(10) unsigned DEFAULT NULL COMMENT 'NULL = page NON AFFECTEE, et cela se voit.',
  `signal_page` varchar(120) DEFAULT NULL COMMENT 'Ce que la page a montre',
  `cree_le` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_page` (`piece_id`,`page_no`),
  KEY `idx_import` (`import_id`),
  KEY `idx_crg` (`crg_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Une page et son CRG. Permet de DEMONTRER 0 page perdue, au lieu de l affirmer.';

-- crgi_phase — staging du module d’intégration
CREATE TABLE `crgi_phase` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `import_id` int(10) unsigned NOT NULL,
  `phase` tinyint(3) unsigned NOT NULL COMMENT '0 documents | 1 inventaire | 2 patrimoine | 3 locataires | 4 finances | 5 bilan',
  `statut` varchar(24) NOT NULL DEFAULT 'EN ATTENTE' COMMENT 'EN ATTENTE | EN ANALYSE | A VALIDER | VALIDEE | BLOQUEE',
  `resultat_sha` char(64) DEFAULT NULL COMMENT 'Empreinte du resultat VALIDE. Si l analyse rejoue et change, la validation ne vaut plus.',
  `message` varchar(500) DEFAULT NULL,
  `analyse_le` datetime DEFAULT NULL,
  `valide_le` datetime DEFAULT NULL,
  `valide_par` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_phase` (`import_id`,`phase`),
  KEY `idx_statut` (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Etat et validation humaine de chaque phase. Une phase ne s ouvre qu apres validation de la precedente.';

-- crgi_piece — staging du module d’intégration
CREATE TABLE `crgi_piece` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `import_id` int(10) unsigned NOT NULL,
  `nom_original` varchar(255) NOT NULL,
  `sha256` char(64) NOT NULL COMMENT 'Empreinte du FICHIER, jamais d un CRG metier.',
  `taille_octets` bigint(20) unsigned NOT NULL DEFAULT 0,
  `nb_pages` int(10) unsigned NOT NULL DEFAULT 0,
  `chemin` varchar(500) NOT NULL COMMENT 'Chemin de stockage staging, hors GED.',
  `etat` varchar(32) NOT NULL DEFAULT 'DEPOSEE' COMMENT 'DEPOSEE | ANALYSEE | ILLISIBLE',
  `message` varchar(500) DEFAULT NULL,
  `cree_le` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_import` (`import_id`),
  KEY `idx_sha` (`sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Un FICHIER physique depose. FICHIER PHYSIQUE != CRG METIER.';

-- crgi_plan — staging du module d’intégration
CREATE TABLE `crgi_plan` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `import_id` int(10) unsigned NOT NULL,
  `famille` varchar(30) NOT NULL COMMENT 'PROPRIETAIRES | COMPTES MANDANTS | IMMEUBLES | LOTS | LOCATAIRES | OCCUPATIONS | APPELS | ENCAISSEMENTS | ENCOURS | CHARGES | FRAIS ET ASSURANCES | FLUX PROPRIETAIRE | SOLDES',
  `action` varchar(20) NOT NULL COMMENT 'CREER | METTRE A JOUR | ARCHIVER | INCHANGE | A ARBITRER | NON INTEGRABLE — SUPPRIMER n existe pas',
  `nombre` int(10) unsigned NOT NULL DEFAULT 0,
  `maille` varchar(20) NOT NULL DEFAULT '' COMMENT 'objet compte par cette action',
  `motif` varchar(500) NOT NULL DEFAULT '' COMMENT 'ce qui justifie l action, en clair',
  `source` varchar(40) NOT NULL DEFAULT '' COMMENT 'la phase scellee qui le demontre',
  `bloque` varchar(300) DEFAULT NULL COMMENT 'ce qu un arbitrage empeche reellement, et ce qu il n empeche pas',
  `rang` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_import` (`import_id`,`rang`),
  KEY `idx_famille` (`import_id`,`famille`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Ce qui SERAIT ecrit si l integration etait validee. Rien n est ecrit.';

-- agences — VUE en lecture seule sur maboximmo, pour la confrontation
CREATE OR REPLACE SQL SECURITY INVOKER VIEW `agences` AS SELECT * FROM `maboximmo`.`agences`;

-- proprietaires — VUE en lecture seule sur maboximmo, pour la confrontation
CREATE OR REPLACE SQL SECURITY INVOKER VIEW `proprietaires` AS SELECT * FROM `maboximmo`.`proprietaires`;

-- proprietaire_comptes_crg — VUE en lecture seule sur maboximmo, pour la confrontation
CREATE OR REPLACE SQL SECURITY INVOKER VIEW `proprietaire_comptes_crg` AS SELECT * FROM `maboximmo`.`proprietaire_comptes_crg`;

-- immeubles — VUE en lecture seule sur maboximmo, pour la confrontation
CREATE OR REPLACE SQL SECURITY INVOKER VIEW `immeubles` AS SELECT * FROM `maboximmo`.`immeubles`;

-- biens — VUE en lecture seule sur maboximmo, pour la confrontation
CREATE OR REPLACE SQL SECURITY INVOKER VIEW `biens` AS SELECT * FROM `maboximmo`.`biens`;

-- bien_baux — VUE en lecture seule sur maboximmo, pour la confrontation
CREATE OR REPLACE SQL SECURITY INVOKER VIEW `bien_baux` AS SELECT * FROM `maboximmo`.`bien_baux`;

-- tiers — VUE en lecture seule sur maboximmo, pour la confrontation
CREATE OR REPLACE SQL SECURITY INVOKER VIEW `tiers` AS SELECT * FROM `maboximmo`.`tiers`;

SET FOREIGN_KEY_CHECKS = 1;
