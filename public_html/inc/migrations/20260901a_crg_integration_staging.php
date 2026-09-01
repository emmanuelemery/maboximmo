<?php
/**
 * Migration : SOCLE DE STAGING DU MODULE « INTÉGRATION CRG ».
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * ── CE QUE CE SOCLE PERMET ─────────────────────────────────────────────────────
 * Déposer un PDF — un CRG, plusieurs CRG, ou un document de 906 pages mêlant
 * plusieurs agences et plusieurs mois — puis l'analyser phase par phase SANS
 * écrire une seule ligne dans les données métier de MBI. Chaque dépôt crée un
 * `import_id` auquel TOUT se rattache, et qu'on peut annuler.
 *
 * ── POURQUOI DES TABLES SÉPARÉES, PRÉFIXÉES `crgi_` ────────────────────────────
 * Parce que l'approche peut échouer. Si elle échoue, `DROP TABLE crgi_*` suffit :
 * ni `biens`, ni `bien_baux`, ni `crg_liens`, ni la GED n'auront été touchés. Un
 * staging qui partagerait les tables métier ne serait pas un staging — ce serait
 * une écriture qu'on espère pouvoir défaire.
 *
 * ── LES CINQ TABLES ────────────────────────────────────────────────────────────
 *   `crgi_import`  un dépôt. Porte le statut du parcours et la trace d'annulation.
 *   `crgi_piece`   un FICHIER physique déposé. ⚠️ `FICHIER PHYSIQUE ≠ CRG MÉTIER` :
 *                  une pièce peut contenir zéro, un ou deux cents CRG.
 *   `crgi_page`    une page du PDF, et le CRG auquel elle est affectée. C'est cette
 *                  table qui permet d'affirmer « 906 pages analysées, 906 affectées,
 *                  0 perdue » — une somme de compteurs ne le démontrerait pas.
 *   `crgi_crg`     un CRG LOGIQUE reconstruit : agence, période, arrêté, compte,
 *                  page début/fin, et son NIVEAU DE CERTITUDE.
 *   `crgi_phase`   l'état de chaque phase du parcours et sa validation humaine.
 *
 * ── LE CHAMP QUI COMPTE LE PLUS ────────────────────────────────────────────────
 * `crgi_crg.certitude`. Un CRG mal découpé qui se présente comme certain est plus
 * dangereux qu'un CRG signalé indéterminable : le second se corrige, le premier
 * se propage. La colonne `motif` dit toujours POURQUOI le moteur est sûr, ou non.
 *
 * ⚠️ AUCUNE CLÉ ÉTRANGÈRE VERS LES TABLES MÉTIER. Le staging observe MBI, il ne
 *    s'y accroche pas. Une FK rendrait la suppression du module impossible sans
 *    toucher au reste.
 *
 * ⚠️ Statements additifs (`IF NOT EXISTS`) : rejouables sans casse.
 */

return [
    'id'          => '20260901a_crg_integration_staging',
    'title'       => 'Intégration CRG — socle de staging annulable',
    'description' => "Cinq tables préfixées crgi_ : import, pièce, page, CRG logique, phase. "
                   . "Aucune écriture métier, aucune clé étrangère vers MBI, "
                   . "suppression du module possible par un simple DROP.",
    'created_at'  => '2026-09-01',

    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `crgi_import` (
  `id`             INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `libelle`        VARCHAR(200)     NOT NULL DEFAULT '',
  `statut`         VARCHAR(32)      NOT NULL DEFAULT 'ANALYSE EN COURS'
                   COMMENT 'ANALYSE EN COURS | A VALIDER | VALIDE PARTIELLEMENT | PRET A INTEGRER | INTEGRE | ANNULE',
  `nb_pieces`      INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `nb_pages`       INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `note`           TEXT             NULL DEFAULT NULL,
  `cree_le`        DATETIME         NOT NULL,
  `cree_par`       INT(10) UNSIGNED NULL DEFAULT NULL,
  `annule_le`      DATETIME         NULL DEFAULT NULL,
  `annule_par`     INT(10) UNSIGNED NULL DEFAULT NULL,
  `annule_motif`   VARCHAR(255)     NULL DEFAULT NULL,
  `integre_le`     DATETIME         NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_statut` (`statut`),
  KEY `idx_cree` (`cree_le`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Un depot de PDF CRG. Tout le staging s y rattache et s annule avec lui.';

CREATE TABLE IF NOT EXISTS `crgi_piece` (
  `id`             INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `import_id`      INT(10) UNSIGNED NOT NULL,
  `nom_original`   VARCHAR(255)     NOT NULL,
  `sha256`         CHAR(64)         NOT NULL COMMENT 'Empreinte du FICHIER, jamais d un CRG metier.',
  `taille_octets`  BIGINT UNSIGNED  NOT NULL DEFAULT 0,
  `nb_pages`       INT(10) UNSIGNED NOT NULL DEFAULT 0,
  `chemin`         VARCHAR(500)     NOT NULL COMMENT 'Chemin de stockage staging, hors GED.',
  `etat`           VARCHAR(32)      NOT NULL DEFAULT 'DEPOSEE'
                   COMMENT 'DEPOSEE | ANALYSEE | ILLISIBLE',
  `message`        VARCHAR(500)     NULL DEFAULT NULL,
  `cree_le`        DATETIME         NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_import` (`import_id`),
  KEY `idx_sha` (`sha256`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Un FICHIER physique depose. FICHIER PHYSIQUE != CRG METIER.';

CREATE TABLE IF NOT EXISTS `crgi_crg` (
  `id`             INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `import_id`      INT(10) UNSIGNED NOT NULL,
  `piece_id`       INT(10) UNSIGNED NOT NULL,
  `page_debut`     INT(10) UNSIGNED NOT NULL,
  `page_fin`       INT(10) UNSIGNED NOT NULL,
  `agence`         VARCHAR(120)     NULL DEFAULT NULL,
  `format`         VARCHAR(32)      NULL DEFAULT NULL COMMENT 'lyon | emery_immo | septeo_spi | inconnu',
  `periode_cle`    VARCHAR(40)      NULL DEFAULT NULL,
  `periode_debut`  DATE             NULL DEFAULT NULL,
  `periode_fin`    DATE             NULL DEFAULT NULL,
  `date_arrete`    DATE             NULL DEFAULT NULL,
  `proprietaire`   VARCHAR(255)     NULL DEFAULT NULL,
  `compte`         VARCHAR(40)      NULL DEFAULT NULL,
  `immeuble`       VARCHAR(120)     NULL DEFAULT NULL COMMENT 'septeo_spi : une piece = un immeuble.',
  `certitude`      VARCHAR(24)      NOT NULL DEFAULT 'INDETERMINABLE'
                   COMMENT 'CERTAIN | PROBABLE | INDETERMINABLE',
  `motif`          VARCHAR(500)     NULL DEFAULT NULL COMMENT 'POURQUOI le moteur est sur, ou ne l est pas.',
  `cree_le`        DATETIME         NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_import` (`import_id`),
  KEY `idx_piece_page` (`piece_id`, `page_debut`),
  KEY `idx_agence_periode` (`agence`, `periode_cle`),
  KEY `idx_certitude` (`certitude`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Un CRG LOGIQUE reconstruit depuis les pages. Porte son niveau de certitude.';

CREATE TABLE IF NOT EXISTS `crgi_page` (
  `id`             INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `import_id`      INT(10) UNSIGNED NOT NULL,
  `piece_id`       INT(10) UNSIGNED NOT NULL,
  `page_no`        INT(10) UNSIGNED NOT NULL COMMENT '1-indexe, tel que le lecteur PDF numerote.',
  `crg_id`         INT(10) UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = page NON AFFECTEE, et cela se voit.',
  -- ⚠️ `signal` EST UN MOT RÉSERVÉ MARIADB. Nommer la colonne ainsi obligerait à l'entourer
  --    de backticks dans CHAQUE requête, et le premier oubli produit une erreur de syntaxe
  --    incomprehensible loin de sa cause. Le nom porte donc son objet : `signal_page`.
  `signal_page`    VARCHAR(120)     NULL DEFAULT NULL COMMENT 'Ce que la page a montre : entete, suite, recapitulatif...',
  `cree_le`        DATETIME         NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_page` (`piece_id`, `page_no`),
  KEY `idx_import` (`import_id`),
  KEY `idx_crg` (`crg_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Une page et son CRG. Permet de DEMONTRER 0 page perdue, au lieu de l affirmer.';

CREATE TABLE IF NOT EXISTS `crgi_phase` (
  `id`             INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `import_id`      INT(10) UNSIGNED NOT NULL,
  `phase`          TINYINT UNSIGNED NOT NULL COMMENT '0 documents | 1 inventaire | 2 patrimoine | 3 locataires | 4 finances | 5 bilan',
  `statut`         VARCHAR(24)      NOT NULL DEFAULT 'EN ATTENTE'
                   COMMENT 'EN ATTENTE | EN ANALYSE | A VALIDER | VALIDEE | BLOQUEE',
  `resultat_sha`   CHAR(64)         NULL DEFAULT NULL
                   COMMENT 'Empreinte du resultat VALIDE. Si l analyse rejoue et change, la validation ne vaut plus.',
  `message`        VARCHAR(500)     NULL DEFAULT NULL,
  `analyse_le`     DATETIME         NULL DEFAULT NULL,
  `valide_le`      DATETIME         NULL DEFAULT NULL,
  `valide_par`     INT(10) UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_phase` (`import_id`, `phase`),
  KEY `idx_statut` (`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Etat et validation humaine de chaque phase. Une phase ne s ouvre qu apres validation de la precedente.';
SQL,

    'down' => <<<'SQL'
DROP TABLE IF EXISTS `crgi_page`;
DROP TABLE IF EXISTS `crgi_crg`;
DROP TABLE IF EXISTS `crgi_piece`;
DROP TABLE IF EXISTS `crgi_phase`;
DROP TABLE IF EXISTS `crgi_import`;
SQL,
];
