-- ═══════════════════════════════════════════════════════════════════════════
--  MBI · migration 0020 — LA NATURE DU BIEN, LE MANDAT, ET L'IMPORT ANNULABLE
-- ═══════════════════════════════════════════════════════════════════════════
--
--  Trois manques constatés en confrontant le schéma 0017-0019 à ce que le CRG
--  donne réellement, à la veille du premier import de masse.
--
--  ─────────────────────────────────────────────────────────────────────────
--  1. LA NATURE DU BIEN N'ÉTAIT NULLE PART
--
--  `biens` ne portait que `designation` — le libellé imprimé, « Appartement
--  1 Pièce », « Local commercial RDC ». Une chaîne libre ne répond pas à
--  « combien de locaux commerciaux ? », et c'est exactement la question que
--  la taxe foncière posera : elle ne s'établit pas de la même façon sur un
--  logement, un local professionnel et un garage.
--
--  Emmanuel, 08/09/2026 : « tu dois trouver si c'est du local commercial,
--  bureau, appartement, maison ou simplement habitation en cas de silence ».
--  `habitation` est donc une nature à part entière — celle du SILENCE
--  qualifié — et non un trou. On ne remplace pas une lecture manquante par
--  une supposition d'appartement.
--
--  ⚠️ LA LISTE EST FERMÉE, ET TIRÉE DU CORPUS. Les valeurs viennent des
--     1 125 lots réellement lus (745 appartements, 158 locaux commerciaux,
--     126 maisons, 80 garages, 7 bureaux, 4 parties communes, 2 terrains,
--     2 panneaux, 2 habitations). On n'invente pas `cave` ni `parking` « au
--     cas où » : un vocabulaire s'étend par INSERT le jour où un document le
--     prouve, et rester vide n'a jamais coûté à personne.
--
--  ─────────────────────────────────────────────────────────────────────────
--  2. LE MANDAT N'EXISTAIT PAS — ET `mandants` N'EST PAS SA TABLE
--
--  `mandants` (0017) est un COMPTE chez une agence. Le MANDAT est le CONTRAT
--  qui autorise l'agence à agir. Ce ne sont pas deux noms de la même chose :
--  un compte n'a ni type, ni date de prise d'effet, ni portée.
--
--  Emmanuel : « local = commerce, quand c'est dans un CRG c'est une gestion
--  avec mandat ». Tout lot présent dans un CRG de gestion est donc couvert
--  par un mandat de gestion : c'est le CRG lui-même qui le prouve.
--
--  ⚠️ PORTÉE : L'IMMEUBLE **OU** LE BIEN, JAMAIS « LE TIERS SEUL ».
--     Emmanuel, 08/09/2026 : « il peut y avoir 1 mandat par bien s'ils ont
--     été donnés à des périodes différentes ». Ce sont les DATES qui
--     distinguent deux mandats sur un même bien, jamais une contrainte
--     d'unicité — la poser ferait perdre l'historique.
--
--  ⚠️ QUI DONNE LE MANDAT : un compte mandant, OU un tiers directement.
--     Les deux, parce qu'un mandat de VENTE ou de LOCATION se signe avec
--     quelqu'un qui n'a pas forcément de compte de gestion. Ce n'est pas un
--     doublon : ce sont deux voies EXCLUSIVES vers la même personne, et le
--     CHECK impose qu'au moins l'une soit empruntée.
--
--  ─────────────────────────────────────────────────────────────────────────
--  3. UN IMPORT QU'ON NE PEUT PAS DÉFAIRE N'EST PAS UN IMPORT, C'EST UN PARI
--
--  Emmanuel : « attention tout doit être annulable ! pour corriger les
--  imports et recommencer éventuellement ».
--
--  `UN IMPORT N'EST ANNULABLE QUE SI TOUTE ÉCRITURE PASSE PAR UN SEUL ENDROIT
--   QUI LA NOTE.` Le journal enregistre table, ligne, action, et l'état
--  d'avant sur les SEULES colonnes modifiées — journaliser la ligne entière
--  ferait revenir, à l'annulation, des champs qu'un humain a changés depuis.
--
--  ⚠️ POURQUOI UN JOURNAL PLUTÔT QU'UNE COLONNE `id_import` SUR CHAQUE TABLE.
--     Une colonne ne sait défaire que les CRÉATIONS. Dès le deuxième dépôt,
--     l'import touche des lignes que le premier avait créées — un code de
--     plus sur un immeuble, une occupation qu'on referme. Supprimer par
--     `id_import` laisserait ces modifications en place en annonçant un
--     succès. Le journal, lui, les restaure.
--
--  ─────────────────────────────────────────────────────────────────────────
--  4. ET UN REGISTRE DES MIGRATIONS, PARCE QUE SON ABSENCE A DÉJÀ COÛTÉ
--
--  Rien n'enregistrait ce qui était appliqué. Le 09/09/2026, la question
--  « la base est-elle à 0017 ou à 0019 ? » a demandé d'inspecter les colonnes
--  d'`occupations` — et une première lecture, faite avec un compte qui ne
--  voyait pas encore les tables, a répondu FAUX. Une base doit pouvoir dire
--  où elle en est sans qu'on l'ausculte.
-- ═══════════════════════════════════════════════════════════════════════════


-- ═══════════════════════════════════════════════════════════════════════════
--  LE REGISTRE DES MIGRATIONS
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS schema_migrations (
  id          VARCHAR(80) NOT NULL,
  applique_le DATETIME NOT NULL DEFAULT current_timestamp(),
  CONSTRAINT pk_schema_migrations PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ═══════════════════════════════════════════════════════════════════════════
--  LES NATURES DE BIEN — vocabulaire fermé
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE biens_natures (
  -- Meme parti que `tiers_roles_codes` : la cle EST le code, pas un entier.
  -- Collation binaire, sinon « Appartement » et « appartement » seraient la
  -- meme cle et la valeur stockee dependrait de qui a saisi le premier.
  code       VARCHAR(32) COLLATE utf8mb4_nopad_bin NOT NULL,
  libelle    VARCHAR(80) NOT NULL,

  -- L USAGE, et il n est pas decoratif : la taxe fonciere ne s etablit pas
  -- de la meme facon sur un logement, un local professionnel et une annexe.
  -- C est la colonne que le module TF interrogera, pas le libelle.
  usage_code VARCHAR(16) COLLATE utf8mb4_nopad_bin NOT NULL,

  ordre      INT UNSIGNED NOT NULL DEFAULT 0,
  date_fin   DATE NULL,
  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  created_by INT UNSIGNED NULL,
  CONSTRAINT pk_biens_natures PRIMARY KEY (code),
  CONSTRAINT chk_bn_code    CHECK (code REGEXP '^[a-z][a-z0-9_]*$'),
  CONSTRAINT chk_bn_libelle CHECK (TRIM(libelle) <> ''),
  CONSTRAINT chk_bn_usage   CHECK (usage_code IN ('habitation','professionnel','annexe','autre')),
  CONSTRAINT fk_bn_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO biens_natures (code, libelle, usage_code, ordre) VALUES
  ('appartement',      'Appartement',           'habitation',    10),
  ('maison',           'Maison',                'habitation',    20),
  -- Le SILENCE QUALIFIE : le document dit « habitation » sans preciser. Ce
  -- n est pas un trou a combler par « appartement » — c est une lecture.
  ('habitation',       'Habitation',            'habitation',    30),
  ('local_commercial', 'Local commercial',      'professionnel', 40),
  ('bureau',           'Bureau',                'professionnel', 50),
  ('garage',           'Garage',                'annexe',        60),
  ('terrain',          'Terrain',               'autre',         70),
  ('parties_communes', 'Parties communes',      'autre',         80),
  ('panneau',          'Panneau publicitaire',  'autre',         90),
  ('autre',            'Autre',                 'autre',        100);

-- ⚠️ NULLABLE, ET C EST UNE REGLE. Un lot dont le document ne dit pas la
--    nature reste sans nature. Le remplir par defaut ferait entrer une
--    supposition dans une colonne que la taxe fonciere lira comme un fait.
ALTER TABLE biens
  ADD COLUMN nature_code VARCHAR(32) COLLATE utf8mb4_nopad_bin NULL
    COMMENT 'Nature lue sur le document. NULL = le document ne la dit pas.'
    AFTER designation,
  ADD CONSTRAINT fk_biens_nature FOREIGN KEY (nature_code) REFERENCES biens_natures (code)
    ON DELETE RESTRICT ON UPDATE RESTRICT;


-- ═══════════════════════════════════════════════════════════════════════════
--  LES TYPES DE MANDAT — vocabulaire fermé
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE mandats_types (
  code       VARCHAR(24) COLLATE utf8mb4_nopad_bin NOT NULL,
  libelle    VARCHAR(80) NOT NULL,
  ordre      INT UNSIGNED NOT NULL DEFAULT 0,
  date_fin   DATE NULL,
  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  created_by INT UNSIGNED NULL,
  CONSTRAINT pk_mandats_types PRIMARY KEY (code),
  CONSTRAINT chk_mt_code    CHECK (code REGEXP '^[a-z][a-z0-9_]*$'),
  CONSTRAINT chk_mt_libelle CHECK (TRIM(libelle) <> ''),
  CONSTRAINT fk_mt_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO mandats_types (code, libelle, ordre) VALUES
  ('gestion',  'Mandat de gestion locative', 10),
  ('location', 'Mandat de location',         20),
  ('vente',    'Mandat de vente',            30),
  ('syndic',   'Mandat de syndic',           40);


-- ═══════════════════════════════════════════════════════════════════════════
--  LES MANDATS — le contrat qui autorise l'agence à agir
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE mandats (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- L agence titulaire. Un mandat est signe AVEC une agence, pas avec le
  -- groupe : c est elle qui repond de son execution.
  id_agence  INT UNSIGNED NOT NULL,
  type_code  VARCHAR(24) COLLATE utf8mb4_nopad_bin NOT NULL,

  -- QUI DONNE LE MANDAT — deux voies vers la meme personne :
  --   `id_mandant` quand un compte de gestion existe (le cas du CRG),
  --   `id_tiers`   quand il n y en a pas (vente, location).
  id_mandant INT UNSIGNED NULL,
  id_tiers   INT UNSIGNED NULL,

  -- SUR QUOI IL PORTE. Un mandat couvre un immeuble entier OU un lot precis.
  -- Les deux colonnes peuvent etre remplies : `id_immeuble` est alors le
  -- chemin d acces direct — « tous les mandats de cet immeuble » sans passer
  -- par les biens, ce qui perdait ceux dont le lot n est pas rattache.
  id_immeuble INT UNSIGNED NULL,
  id_bien     INT UNSIGNED NULL,

  -- Le numero au registre des mandats. NULL tant qu il n est pas repris :
  -- le CRG ne l imprime pas, et le fabriquer serait un faux.
  numero     VARCHAR(32) NULL,

  -- ⚠️ PAS DE CONTRAINTE D UNICITE SUR LE BIEN. Un bien peut porter plusieurs
  --    mandats donnes a des periodes differentes ; ce sont ces dates qui les
  --    distinguent. Une unicite ferait perdre l historique.
  date_debut DATE NOT NULL,
  date_fin   DATE NULL,

  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  created_by INT UNSIGNED NULL,

  CONSTRAINT pk_mandats PRIMARY KEY (id),
  CONSTRAINT chk_mandats_donneur CHECK (id_mandant IS NOT NULL OR id_tiers IS NOT NULL),
  CONSTRAINT chk_mandats_portee  CHECK (id_immeuble IS NOT NULL OR id_bien IS NOT NULL),
  CONSTRAINT chk_mandats_bornes  CHECK (date_fin IS NULL OR date_fin >= date_debut),
  CONSTRAINT fk_mandats_agence FOREIGN KEY (id_agence) REFERENCES agences (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_mandats_type FOREIGN KEY (type_code) REFERENCES mandats_types (code)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_mandats_mandant FOREIGN KEY (id_mandant) REFERENCES mandants (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_mandats_tiers FOREIGN KEY (id_tiers) REFERENCES tiers (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_mandats_immeuble FOREIGN KEY (id_immeuble) REFERENCES immeubles (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_mandats_bien FOREIGN KEY (id_bien) REFERENCES biens (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_mandats_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_mandats_immeuble ON mandats (id_immeuble, date_debut);
CREATE INDEX ix_mandats_bien     ON mandats (id_bien, date_debut);
CREATE INDEX ix_mandats_mandant  ON mandats (id_mandant);
CREATE INDEX ix_mandats_agence   ON mandats (id_agence, type_code);


-- ═══════════════════════════════════════════════════════════════════════════
--  LES IMPORTS — une passe, et ce qu'elle a écrit
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE imports (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  source     VARCHAR(10) COLLATE utf8mb4_nopad_bin NOT NULL DEFAULT 'CRG',
  libelle    VARCHAR(190) NOT NULL,
  id_agence  INT UNSIGNED NULL,
  -- Ce que la passe a lu, pour pouvoir le confronter a ce qu elle a ecrit.
  nb_documents  INT UNSIGNED NULL,
  periode_debut DATE NULL,
  periode_fin   DATE NULL,
  fait_le    DATETIME NOT NULL DEFAULT current_timestamp(),
  fait_par   INT UNSIGNED NULL,
  -- Annuler ne supprime pas la trace de la passe : on saura qu elle a eu lieu.
  annule_le    DATETIME NULL,
  annule_motif VARCHAR(300) NULL,
  CONSTRAINT pk_imports PRIMARY KEY (id),
  CONSTRAINT chk_imports_source CHECK (source IN ('CRG','SAISIE','MBI')),
  CONSTRAINT fk_imports_agence FOREIGN KEY (id_agence) REFERENCES agences (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_imports_fait_par FOREIGN KEY (fait_par) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
--  LE JOURNAL — l'unique raison pour laquelle un import est annulable
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE imports_journal (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_import  INT UNSIGNED NOT NULL,
  table_cible VARCHAR(64) NOT NULL,
  id_ligne   INT UNSIGNED NOT NULL,
  action     VARCHAR(10) COLLATE utf8mb4_nopad_bin NOT NULL,
  -- L etat d AVANT, en JSON, sur les SEULES colonnes touchees. Vide pour une
  -- creation : il n y avait rien avant. Restaurer la ligne entiere ferait
  -- revenir, a l annulation, des champs qu un humain a changes depuis.
  avant      LONGTEXT NULL,
  fait_le    DATETIME NOT NULL DEFAULT current_timestamp(),
  CONSTRAINT pk_imports_journal PRIMARY KEY (id),
  CONSTRAINT chk_ij_action CHECK (action IN ('CREER','MODIFIER')),
  CONSTRAINT chk_ij_avant  CHECK (avant IS NULL OR JSON_VALID(avant)),
  CONSTRAINT fk_ij_import FOREIGN KEY (id_import) REFERENCES imports (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- L annulation rejoue le journal A L ENVERS : c est le seul parcours qui
-- respecte les cles etrangeres sans avoir a connaitre l ordre des tables.
CREATE INDEX ix_ij_import ON imports_journal (id_import, id DESC);
CREATE INDEX ix_ij_cible  ON imports_journal (table_cible, id_ligne);
