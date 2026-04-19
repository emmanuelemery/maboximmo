-- ═══════════════════════════════════════════════════════════════════════
-- MaBoxImmo — Architecture TIERS (Phase 1 : fondations)
-- Créé le 2026-04-19 · Exécuté sur dev le 2026-04-19
-- ═══════════════════════════════════════════════════════════════════════
-- ORDRE D'EXÉCUTION EN PRODUCTION :
--   1. Ce fichier jusqu'à la section 6 (CREATE + ALTER) — tout passe
--   2. Charger `tiers_roles_seed.sql` (peuplement des 59 codes de rôle)
--   3. Rejouer la section 7 de ce fichier (backfill INSERT dans tiers_roles)
--
-- ATTENTION : la section 7 (backfill tiers_roles) exige que tiers_roles_codes
-- soit peuplé au préalable, sinon la FK fk_tr_role bloque les INSERT.
-- ═══════════════════════════════════════════════════════════════════════
-- Principe : users et tiers sont DEUX tables distinctes
--   - users   = identité applicative (auth, permissions, rôles app)
--   - tiers   = identité métier (source unique des personnes/entités externes)
--   - tiers_roles = rôles métier multiples + contextes (mandat, bien, immeuble...)
--   - user_tiers  = liaison user ↔ tiers (extranet, collaborateur-tiers, etc.)
-- ═══════════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ─────────────────────────────────────────────────────────────────────
-- 1. TIERS_ROLES_CODES — référentiel des codes de rôle métier
-- ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `tiers_roles_codes` (
  `code`             VARCHAR(40)  NOT NULL,
  `libelle`          VARCHAR(100) NOT NULL,
  `categorie`        VARCHAR(40)  NOT NULL COMMENT 'acteur_immo | prestataire | juridique | financier | crm | contact | extranet | autre',
  `description`      VARCHAR(255) DEFAULT NULL,
  `objet_type_defaut` VARCHAR(30) DEFAULT NULL COMMENT 'bien | immeuble | mandat | bail | reunion | sinistre | contentieux | societe | global',
  `actif`            TINYINT(1) NOT NULL DEFAULT 1,
  `ordre_affichage`  INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`code`),
  KEY `idx_tiers_roles_codes_categorie` (`categorie`, `actif`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────
-- 2. TIERS — racine métier unique
-- ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `tiers` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- Multi-tenant (cloisonnement société/agence)
  `id_societe`        INT UNSIGNED DEFAULT NULL,
  `id_agence`         INT UNSIGNED DEFAULT NULL,
  -- Typologie
  `type_tiers`        ENUM('personne_physique','personne_morale','entite_juridique','indivision','syndicat_coprop') NOT NULL DEFAULT 'personne_physique',
  `sous_type`         VARCHAR(40) DEFAULT NULL COMMENT 'sci | sarl | sas | association | copropriete | couple | famille | artisan ...',
  -- Identité personne physique
  `civilite`          VARCHAR(20)  DEFAULT NULL,
  `nom`               VARCHAR(150) DEFAULT NULL,
  `prenom`            VARCHAR(150) DEFAULT NULL,
  `nom_naissance`     VARCHAR(150) DEFAULT NULL,
  `date_naissance`    DATE DEFAULT NULL,
  `lieu_naissance`    VARCHAR(150) DEFAULT NULL,
  `nationalite`       VARCHAR(80) DEFAULT NULL,
  -- Identité personne morale / entité
  `raison_sociale`    VARCHAR(255) DEFAULT NULL,
  `forme_juridique`   VARCHAR(60)  DEFAULT NULL,
  `siret`             VARCHAR(20)  DEFAULT NULL,
  `siren`             VARCHAR(20)  DEFAULT NULL,
  `tva_intracom`      VARCHAR(20)  DEFAULT NULL,
  `rcs`               VARCHAR(80)  DEFAULT NULL,
  -- Affichage
  `nom_affichage`     VARCHAR(255) DEFAULT NULL COMMENT 'Calculé ou forcé — utilisé dans les listes/recherches',
  -- Contacts
  `email`             VARCHAR(190) DEFAULT NULL,
  `email_secondaire`  VARCHAR(190) DEFAULT NULL,
  `telephone`         VARCHAR(30)  DEFAULT NULL,
  `telephone_secondaire` VARCHAR(30) DEFAULT NULL,
  `mobile`            VARCHAR(30)  DEFAULT NULL,
  -- Adresse
  `adresse_ligne1`    VARCHAR(255) DEFAULT NULL,
  `adresse_ligne2`    VARCHAR(255) DEFAULT NULL,
  `code_postal`       VARCHAR(10)  DEFAULT NULL,
  `ville`             VARCHAR(150) DEFAULT NULL,
  `pays`              VARCHAR(100) DEFAULT 'France',
  -- Géocodage (Google Places)
  `latitude`          DECIMAL(10,7) DEFAULT NULL,
  `longitude`         DECIMAL(10,7) DEFAULT NULL,
  `google_place_id`   VARCHAR(190) DEFAULT NULL,
  `adresse_formatee`  VARCHAR(500) DEFAULT NULL,
  -- Commentaires
  `commentaire`       TEXT DEFAULT NULL COMMENT 'Visible par le tiers via extranet',
  `notes_internes`    TEXT DEFAULT NULL COMMENT 'Jamais visible du tiers — réservé interne',
  -- Traçabilité
  `source_creation`   VARCHAR(60) DEFAULT NULL COMMENT 'manuel | import_crg | notif_mutation | extranet | intake_ia',
  `origine`           VARCHAR(60) DEFAULT NULL COMMENT 'prospect | ancien_client | partenaire | recommandation',
  `id_user_createur`  INT UNSIGNED DEFAULT NULL,
  `actif`             TINYINT(1) NOT NULL DEFAULT 1,
  `date_creation`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_modification` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tiers_societe` (`id_societe`),
  KEY `idx_tiers_agence`  (`id_agence`),
  KEY `idx_tiers_nom`     (`nom`),
  KEY `idx_tiers_raison`  (`raison_sociale`),
  KEY `idx_tiers_email`   (`email`),
  KEY `idx_tiers_tel`     (`telephone`),
  KEY `idx_tiers_siret`   (`siret`),
  KEY `idx_tiers_actif`   (`actif`),
  KEY `idx_tiers_type`    (`type_tiers`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────
-- 3. TIERS_ROLES — affectations multiples d'un tiers
-- ─────────────────────────────────────────────────────────────────────
-- Un même tiers peut avoir N rôles sur N objets simultanément.
-- Ex: tiers #42 est [proprietaire, bien, 12], [bailleur, bien, 45],
--     [coproprietaire, immeuble, 7, quote_part=8.00], [membre_cs, immeuble, 7].
-- ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `tiers_roles` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_tiers`          INT UNSIGNED NOT NULL,
  `role_code`         VARCHAR(40)  NOT NULL,
  `objet_type`        VARCHAR(30)  DEFAULT NULL COMMENT 'bien | immeuble | mandat | bail | reunion | sinistre | societe | NULL (rôle global)',
  `id_objet`          INT UNSIGNED DEFAULT NULL,
  `id_mandat`         INT UNSIGNED DEFAULT NULL COMMENT 'Mandat éventuellement associé (contexte CRG, etc.)',
  `quote_part`        DECIMAL(8,4) DEFAULT NULL COMMENT 'Pour copro/indivision en % ou tantièmes',
  `date_debut`        DATE DEFAULT NULL,
  `date_fin`          DATE DEFAULT NULL,
  `priorite`          SMALLINT NOT NULL DEFAULT 0 COMMENT 'Ordre d\'affichage / contact principal si plusieurs',
  `metadata`          JSON DEFAULT NULL COMMENT 'Libre : caution_montant, garant_for_bail_id, etc.',
  `actif`             TINYINT(1) NOT NULL DEFAULT 1,
  `date_creation`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `date_modification` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tiers_role_objet` (`id_tiers`, `role_code`, `objet_type`, `id_objet`, `id_mandat`),
  KEY `idx_tr_tiers`   (`id_tiers`),
  KEY `idx_tr_role`    (`role_code`),
  KEY `idx_tr_objet`   (`objet_type`, `id_objet`),
  KEY `idx_tr_mandat`  (`id_mandat`),
  KEY `idx_tr_actif`   (`actif`),
  CONSTRAINT `fk_tr_tiers` FOREIGN KEY (`id_tiers`) REFERENCES `tiers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tr_role`  FOREIGN KEY (`role_code`) REFERENCES `tiers_roles_codes` (`code`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────
-- 4. USER_TIERS — liaison compte ↔ fiche métier
-- ─────────────────────────────────────────────────────────────────────
-- Permet : extranet bailleur/coproprio/locataire/prestataire,
-- ET : collaborateur interne qui est aussi un tiers métier (ex. copropriétaire d'un immeuble géré).
-- Un user peut pointer vers plusieurs tiers si plusieurs casquettes.
-- Un tiers peut être lié à 0 ou 1 ou N users (ex: 2 représentants légaux d'une SCI).
-- ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `user_tiers` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_user`     INT UNSIGNED NOT NULL,
  `id_tiers`    INT UNSIGNED NOT NULL,
  `type_lien`   VARCHAR(40)  NOT NULL DEFAULT 'self' COMMENT 'self | representant | mandataire | extranet_bailleur | extranet_coproprio | extranet_locataire | extranet_prestataire',
  `actif`       TINYINT(1) NOT NULL DEFAULT 1,
  `date_debut`  DATE DEFAULT NULL,
  `date_fin`    DATE DEFAULT NULL,
  `date_creation` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_tiers_lien` (`id_user`, `id_tiers`, `type_lien`),
  KEY `idx_ut_user`  (`id_user`),
  KEY `idx_ut_tiers` (`id_tiers`),
  CONSTRAINT `fk_ut_user`  FOREIGN KEY (`id_user`)  REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ut_tiers` FOREIGN KEY (`id_tiers`) REFERENCES `tiers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────
-- 5. TIERS_CONTACTS — personnes physiques rattachées à une entité morale
-- ─────────────────────────────────────────────────────────────────────
-- Ex: SCI DUPONT (tiers #10, personne_morale)
--       ├── Jean DUPONT (tiers #11, personne_physique) — gerant, principal
--       └── Marie DUPONT (tiers #12, personne_physique) — associee, secondaire
-- ─────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `tiers_contacts` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_tiers_entite`   INT UNSIGNED NOT NULL COMMENT 'Personne morale / entité',
  `id_tiers_contact`  INT UNSIGNED NOT NULL COMMENT 'Personne physique liée',
  `qualite`           VARCHAR(60) NOT NULL COMMENT 'gerant | associe | president | representant_legal | contact_comptable | contact_technique | contact_urgence',
  `priorite`          SMALLINT NOT NULL DEFAULT 0 COMMENT '0 = principal, 1+ = secondaires',
  `canal_principal`   ENUM('email','telephone','mobile','courrier','aucun') DEFAULT 'email',
  `actif`             TINYINT(1) NOT NULL DEFAULT 1,
  `date_debut`        DATE DEFAULT NULL,
  `date_fin`          DATE DEFAULT NULL,
  `commentaire`       VARCHAR(500) DEFAULT NULL,
  `date_creation`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_tc_lien` (`id_tiers_entite`, `id_tiers_contact`, `qualite`),
  KEY `idx_tc_entite`  (`id_tiers_entite`),
  KEY `idx_tc_contact` (`id_tiers_contact`),
  CONSTRAINT `fk_tc_entite`  FOREIGN KEY (`id_tiers_entite`)  REFERENCES `tiers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tc_contact` FOREIGN KEY (`id_tiers_contact`) REFERENCES `tiers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────
-- 6. ADAPTATIONS DES TABLES EXISTANTES (ajout id_tiers nullable — zéro casse)
-- ─────────────────────────────────────────────────────────────────────
-- Les colonnes existantes sont CONSERVÉES. id_tiers est ajouté en parallèle.
-- Au fil de la migration (Phase 3), les pages basculeront sur id_tiers.
-- ─────────────────────────────────────────────────────────────────────
ALTER TABLE `proprietaires`
    ADD COLUMN `id_tiers` INT UNSIGNED DEFAULT NULL AFTER `id`,
    ADD KEY `idx_proprietaires_tiers` (`id_tiers`),
    ADD CONSTRAINT `fk_proprietaires_tiers` FOREIGN KEY (`id_tiers`) REFERENCES `tiers` (`id`) ON DELETE SET NULL;

ALTER TABLE `mandants`
    ADD COLUMN `id_tiers` INT UNSIGNED DEFAULT NULL AFTER `id`,
    ADD KEY `idx_mandants_tiers` (`id_tiers`),
    ADD CONSTRAINT `fk_mandants_tiers` FOREIGN KEY (`id_tiers`) REFERENCES `tiers` (`id`) ON DELETE SET NULL;

ALTER TABLE `agency_mandant`
    ADD COLUMN `id_tiers` INT UNSIGNED DEFAULT NULL AFTER `id`,
    ADD KEY `idx_agency_mandant_tiers` (`id_tiers`),
    ADD CONSTRAINT `fk_agency_mandant_tiers` FOREIGN KEY (`id_tiers`) REFERENCES `tiers` (`id`) ON DELETE SET NULL;

-- ─────────────────────────────────────────────────────────────────────
-- 7. BACKFILL : peupler tiers depuis les tables existantes
-- ─────────────────────────────────────────────────────────────────────

-- 7.a — Depuis proprietaires
INSERT INTO `tiers`
    (id_agence, type_tiers, civilite, nom, prenom, raison_sociale, email, telephone, telephone_secondaire,
     adresse_ligne1, adresse_ligne2, code_postal, ville, pays, commentaire, notes_internes, actif, date_creation, date_modification, source_creation)
SELECT
    p.id_agence,
    CASE WHEN p.type_personne = 'morale' THEN 'personne_morale' ELSE 'personne_physique' END,
    p.civilite, p.nom, p.prenom, p.societe,
    p.email, p.telephone, p.telephone_2,
    p.adresse_1, p.adresse_2, p.code_postal, p.ville, p.pays,
    p.commentaire, p.notes_internes, p.actif, p.date_creation, p.date_modification,
    'backfill_proprietaires'
FROM `proprietaires` p
WHERE p.id_tiers IS NULL;

-- Lien proprietaires.id_tiers
UPDATE `proprietaires` p
JOIN `tiers` t ON t.source_creation = 'backfill_proprietaires'
             AND COALESCE(t.nom,'')=COALESCE(p.nom,'')
             AND COALESCE(t.prenom,'')=COALESCE(p.prenom,'')
             AND COALESCE(t.raison_sociale,'')=COALESCE(p.societe,'')
             AND COALESCE(t.email,'')=COALESCE(p.email,'')
SET p.id_tiers = t.id
WHERE p.id_tiers IS NULL;

-- Rôle proprietaire (global — non rattaché à un bien/immeuble spécifique ici)
INSERT INTO `tiers_roles` (id_tiers, role_code, objet_type, id_objet, actif)
SELECT p.id_tiers, 'proprietaire', NULL, NULL, p.actif
FROM `proprietaires` p
WHERE p.id_tiers IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `tiers_roles` tr
      WHERE tr.id_tiers = p.id_tiers AND tr.role_code='proprietaire' AND tr.objet_type IS NULL
  );

-- 7.b — Depuis mandants
INSERT INTO `tiers`
    (type_tiers, civilite, nom, prenom, email, telephone, adresse_ligne1, code_postal, ville, commentaire, actif, source_creation)
SELECT
    'personne_physique',
    m.civilite, m.nom, m.prenom, m.email, m.telephone,
    m.adresse, m.code_postal, m.ville, m.commentaire, 1, 'backfill_mandants'
FROM `mandants` m
WHERE m.id_tiers IS NULL;

UPDATE `mandants` m
JOIN `tiers` t ON t.source_creation='backfill_mandants'
             AND COALESCE(t.nom,'')=COALESCE(m.nom,'')
             AND COALESCE(t.prenom,'')=COALESCE(m.prenom,'')
             AND COALESCE(t.email,'')=COALESCE(m.email,'')
SET m.id_tiers = t.id
WHERE m.id_tiers IS NULL;

INSERT INTO `tiers_roles` (id_tiers, role_code, objet_type, actif)
SELECT m.id_tiers, 'mandant', NULL, 1
FROM `mandants` m
WHERE m.id_tiers IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM `tiers_roles` tr WHERE tr.id_tiers=m.id_tiers AND tr.role_code='mandant' AND tr.objet_type IS NULL);

-- 7.c — Depuis agency_mandant (copros)
INSERT INTO `tiers`
    (id_agence, type_tiers, raison_sociale, siret, email, telephone, adresse_ligne1, code_postal, ville, actif, source_creation)
SELECT
    am.id_etablissement,
    CASE WHEN am.type_mandant='copropriete' THEN 'syndicat_coprop'
         WHEN am.type_mandant='sci'         THEN 'personne_morale'
         ELSE 'personne_morale' END,
    am.raison_sociale, am.siret, am.email, am.telephone,
    am.adresse, am.code_postal, am.ville, am.actif, 'backfill_agency_mandant'
FROM `agency_mandant` am
WHERE am.id_tiers IS NULL;

UPDATE `agency_mandant` am
JOIN `tiers` t ON t.source_creation='backfill_agency_mandant'
             AND COALESCE(t.raison_sociale,'')=COALESCE(am.raison_sociale,'')
             AND COALESCE(t.siret,'')=COALESCE(am.siret,'')
SET am.id_tiers = t.id
WHERE am.id_tiers IS NULL;

-- Lien avec l'immeuble
INSERT INTO `tiers_roles` (id_tiers, role_code, objet_type, id_objet, actif)
SELECT am.id_tiers,
       CASE WHEN am.type_mandant='copropriete' THEN 'syndicat_coprop'
            WHEN am.type_mandant='sci'         THEN 'proprietaire'
            ELSE 'mandant' END,
       'immeuble', am.id_immeuble, am.actif
FROM `agency_mandant` am
WHERE am.id_tiers IS NOT NULL AND am.id_immeuble IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM `tiers_roles` tr
      WHERE tr.id_tiers=am.id_tiers AND tr.objet_type='immeuble' AND tr.id_objet=am.id_immeuble
  );

-- ─────────────────────────────────────────────────────────────────────
-- FIN PHASE 1
-- Les pages existantes fonctionnent toujours (id_tiers nullable, code inchangé).
-- Les nouvelles pages pourront créer directement dans tiers + tiers_roles.
-- ─────────────────────────────────────────────────────────────────────
