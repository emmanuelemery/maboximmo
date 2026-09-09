-- ═══════════════════════════════════════════════════════════════════════════
--  MBI · 0024 — LE BAIL : la structure entière, les montants plus tard
-- ═══════════════════════════════════════════════════════════════════════════
--
--  Emmanuel, 09/09/2026 : « le fait que le CRG nous donne un occupant tiers
--  avec des appels de loyer, alors le bail doit être créé et complété avec le
--  montant du loyer, des charges, les dates indiquées dans les CRG […] mais la
--  création du bail se fait avec l'intégration des CRG en phase suivante ».
--  Et : « pour l'instant nous ne devons créer que la structure, mais TOUTE la
--  structure ! les info financières seront dans le 2e temps ».
--
--  ─────────────────────────────────────────────────────────────────────────
--  1. LE BAIL N'EST PAS L'OCCUPATION, ET LES DEUX SONT NÉCESSAIRES
--
--  La migration 0017 refusait d'appeler `occupations` une table de baux, et
--  elle avait raison : le CRG imprime « Du 01.01.26 Au 31.01.26 », ce sont des
--  PÉRIODES DE FACTURATION. Mais un appel de loyer PROUVE qu'un bail existe.
--
--      occupations   ce qu'on a OBSERVÉ : ce nom apparaît sur ce lot, de ce
--                    CRG à celui-là. Une observation, jamais un contrat.
--      baux          ce qu'on en DÉDUIT : un contrat existe, voici ce que le
--                    document en dit — sa date, son loyer, ses charges.
--
--  ⚠️ LA DATE DE CONTRAT ET LA BORNE OBSERVÉE NE VONT PAS DANS LA MÊME
--     COLONNE, ET C'ÉTAIT MA FAUTE. J'écrivais « Bail du 05/08/2024 » dans
--     `occupations.date_debut` — une date de contrat rangée là où la 0017
--     écrit noir sur blanc « les BORNES OBSERVÉES, pas les dates d'un
--     contrat ». Les deux vivent désormais chacune chez elle, et
--     `baux.id_occupation` les relie sans les confondre.
--
--  ─────────────────────────────────────────────────────────────────────────
--  2. CE QUE LE DOCUMENT DONNE AUJOURD'HUI, ET CE QU'IL DONNERA
--
--  Mesuré sur le corpus : **475 couples lot × locataire portent une date de
--  bail** (format septeo), 44 une date de fin. Les formats ICS — LYON et RIOM —
--  n'impriment pas la date du bail, mais ils impriment les LOYERS, dans un
--  tableau « Locataires | Période | Loyers | Taxes | Provisions | Divers ».
--
--  ⚠️ CES MONTANTS NE SONT PAS ENCORE LUS. La phase 4 du moteur CRG, celle des
--     mouvements, n'a jamais tourné : `crgi_mouvement` est à 0 ligne. Les
--     colonnes financières existent donc ICI, vides, et c'est délibéré — la
--     structure est complète, le remplissage est la passe suivante.
--     `UNE COLONNE VIDE ANNONCE UN TRAVAIL ; UNE COLONNE ABSENTE LE CACHE.`
--
--  ─────────────────────────────────────────────────────────────────────────
--  3. ET LE RÔLE `locataire` DEVIENT ENFIN VALIDE
--
--  `tiers_roles_codes.objets` vaut `bail` pour `locataire`, `colocataire`,
--  `bailleur` et `caution`. Tant que la table n'existait pas, ces rôles ne
--  pouvaient se poser nulle part — le lien locataire ne vivait que dans
--  `occupation_occupants`. Il peut maintenant être porté par `tiers_roles`,
--  sur son objet, comme le registre l'exige.
-- ═══════════════════════════════════════════════════════════════════════════


-- ═══════════════════════════════════════════════════════════════════════════
--  LES TYPES DE BAIL — vocabulaire fermé
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE baux_types (
  code       VARCHAR(24) COLLATE utf8mb4_nopad_bin NOT NULL,
  libelle    VARCHAR(80) NOT NULL,
  -- Le regime juridique commande les durees, les preavis et les plafonds.
  -- Le porter ici evite de le redeviner a chaque ecran.
  regime     VARCHAR(24) COLLATE utf8mb4_nopad_bin NOT NULL,
  ordre      INT UNSIGNED NOT NULL DEFAULT 0,
  date_fin   DATE NULL,
  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  created_by INT UNSIGNED NULL,
  CONSTRAINT pk_baux_types PRIMARY KEY (code),
  CONSTRAINT chk_bt_code    CHECK (code REGEXP '^[a-z][a-z0-9_]*$'),
  CONSTRAINT chk_bt_libelle CHECK (TRIM(libelle) <> ''),
  CONSTRAINT chk_bt_regime  CHECK (regime IN
    ('habitation','commercial','professionnel','rural','precaire','annexe','autre')),
  CONSTRAINT fk_bt_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO baux_types (code, libelle, regime, ordre) VALUES
  ('habitation_vide',    'Bail d''habitation nu (loi 89-462)', 'habitation',    10),
  ('habitation_meuble',  'Bail d''habitation meublé',          'habitation',    20),
  ('mobilite',           'Bail mobilité',                      'habitation',    30),
  ('etudiant',           'Bail étudiant meublé',               'habitation',    40),
  ('commercial',         'Bail commercial (3-6-9)',            'commercial',    50),
  ('derogatoire',        'Bail dérogatoire de courte durée',   'commercial',    60),
  ('professionnel',      'Bail professionnel',                 'professionnel', 70),
  ('rural',              'Bail rural',                         'rural',         80),
  ('precaire',           'Convention d''occupation précaire',  'precaire',      90),
  ('parking_garage',     'Location de garage ou de parking',   'annexe',       100),
  ('indetermine',        'Bail de nature non précisée',        'autre',        110);


-- ═══════════════════════════════════════════════════════════════════════════
--  LES BAUX
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE baux (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_bien       INT UNSIGNED NOT NULL,

  -- ⚠️ L OCCUPATION QUE CE BAIL EXPLIQUE. C est le lien que la 0017 annoncait :
  --    « le bail viendra avec le document signe, et pointera l occupation qu il
  --    explique ». NULL est possible : un bail signe avant toute facturation.
  id_occupation INT UNSIGNED NULL,

  -- ⚠️ `indetermine` PAR DEFAUT, ET C EST UNE LECTURE. Le CRG ne dit pas la
  --    nature juridique du bail ; la deduire de la nature du lot serait une
  --    supposition rangee dans une colonne qu on lira comme un fait.
  type_code     VARCHAR(24) COLLATE utf8mb4_nopad_bin NOT NULL DEFAULT 'indetermine',

  -- LES DATES DU CONTRAT — « Bail du 05/08/2024 » quand le document l imprime.
  -- A ne pas confondre avec `occupations.date_debut`, qui est une observation.
  date_debut    DATE NULL,
  date_fin      DATE NULL,
  -- La date reelle de sortie, qui n est pas le terme prevu au contrat.
  date_conge    DATE NULL,

  -- ── LES MONTANTS — vides jusqu a la phase 4 des mouvements ──────────────
  loyer_hc          DECIMAL(12,2) NULL COMMENT 'Loyer hors charges',
  charges           DECIMAL(12,2) NULL COMMENT 'Provisions pour charges',
  depot_garantie    DECIMAL(12,2) NULL,
  -- Le CRG appelle au mois ou au trimestre : la periodicite change la lecture
  -- de tous les montants, la supposer mensuelle fausserait un tiers du corpus.
  periodicite       VARCHAR(16) COLLATE utf8mb4_nopad_bin NULL,
  jour_echeance     TINYINT UNSIGNED NULL,
  -- L encadrement et la revision : places ici parce qu ils se lisent sur le
  -- bail, pas ailleurs. Vides tant que le document signe n est pas depouille.
  indice_reference  VARCHAR(16) COLLATE utf8mb4_nopad_bin NULL,
  date_revision     DATE NULL,

  -- D OU VIENT CE BAIL : deduit d un CRG, saisi, ou depouille de l acte signe.
  -- Sans cette colonne, un loyer lu sur un appel et un loyer lu sur le contrat
  -- se ressembleraient a s y meprendre.
  source        VARCHAR(10) COLLATE utf8mb4_nopad_bin NOT NULL DEFAULT 'CRG',

  -- Le document signe, quand il existera. La GED est encore vide.
  id_document   INT UNSIGNED NULL,

  created_at    DATETIME NOT NULL DEFAULT current_timestamp(),
  created_by    INT UNSIGNED NULL,

  CONSTRAINT pk_baux PRIMARY KEY (id),
  CONSTRAINT chk_baux_bornes CHECK (date_fin IS NULL OR date_debut IS NULL OR date_fin >= date_debut),
  CONSTRAINT chk_baux_source CHECK (source IN ('CRG','SAISIE','ACTE')),
  CONSTRAINT chk_baux_periodicite CHECK (periodicite IS NULL OR periodicite IN
    ('mensuelle','trimestrielle','semestrielle','annuelle')),
  CONSTRAINT chk_baux_echeance CHECK (jour_echeance IS NULL OR jour_echeance BETWEEN 1 AND 31),
  CONSTRAINT chk_baux_montants CHECK (
    (loyer_hc IS NULL OR loyer_hc >= 0) AND (charges IS NULL OR charges >= 0)
    AND (depot_garantie IS NULL OR depot_garantie >= 0)),
  CONSTRAINT fk_baux_bien FOREIGN KEY (id_bien) REFERENCES biens (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_baux_occupation FOREIGN KEY (id_occupation) REFERENCES occupations (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_baux_type FOREIGN KEY (type_code) REFERENCES baux_types (code)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_baux_document FOREIGN KEY (id_document) REFERENCES documents (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_baux_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ⚠️ AUCUNE UNICITE SUR (id_bien) : un lot porte SUCCESSIVEMENT plusieurs baux,
--    et ce sont les dates qui les distinguent. Meme raison que pour `mandats`.
CREATE INDEX ix_baux_bien       ON baux (id_bien, date_debut);
CREATE UNIQUE INDEX uk_baux_occ ON baux (id_occupation);
