-- ═══════════════════════════════════════════════════════════════════════════
--  MBI · 0023 — L'ADRESSE DEVIENT UNE TABLE, ET NE SE RECOPIE PLUS
-- ═══════════════════════════════════════════════════════════════════════════
--
--  Emmanuel, 09/09/2026 : « une adresse est liée uniquement par les tables,
--  jamais de saisie double ou copie : que des liens ».
--
--  ─────────────────────────────────────────────────────────────────────────
--  CE QUI ÉTAIT FAUX, ET C'EST MESURÉ
--
--  La même notion était modélisée QUATRE FOIS, avec trois conventions de
--  nommage différentes :
--
--      tiers      adr_ligne_voie · adr_code_postal · adr_commune · adr_pays
--      immeubles  adresse_1 · adresse_2 · code_postal · ville
--      agences    adresse_1 · code_postal · ville · pays
--      societes   adresse_1 · code_postal · ville
--
--  Une routine de rapprochement — celle des taxes foncières, la prochaine —
--  aurait dû connaître quatre formes pour comparer une seule chose.
--
--  Et le même libellé s'écrivait plusieurs fois : **34 adresses figurent au
--  moins deux fois**, sur 91 lignes ; **10 sont partagées entre un tiers et un
--  immeuble** — un propriétaire dont le siège est un bâtiment que nous gérons.
--  « 76 RUE DE VERDUN, 69100 VILLEURBANNE » est écrite **19 fois**. Corriger
--  cette adresse demandait donc dix-neuf corrections, et dix-huit oublis
--  possibles.
--
--  ─────────────────────────────────────────────────────────────────────────
--  LE MÊME PARTI QUE `objet_codes`, ET CE N'EST PAS UN HASARD
--
--  La migration 0017 avait déjà tranché la question pour les codes : « LE CODE
--  N'EST JAMAIS UNE COLONNE — C'EST UNE TABLE », parce qu'un objet en porte
--  plusieurs. L'adresse pose exactement le même problème dans l'autre sens :
--  une adresse est portée par plusieurs objets. La réponse est la même —
--  une table de valeurs, une table de liens.
--
--      adresses         la valeur, écrite UNE SEULE FOIS
--      objet_adresses   qui l'utilise, à quel titre, depuis quand
--
--  ⚠️ L'UNICITÉ EST TENUE PAR LA BASE, PAS PAR L'APPELANT. `cle` est une
--     colonne CALCULÉE : ponctuation et espaces retirés par le regexp, casse
--     et accents repliés par la collation `utf8mb4_unicode_ci`. Un UNIQUE
--     dessus rend la copie MATÉRIELLEMENT IMPOSSIBLE — pas simplement
--     déconseillée.
--
--     C'est aussi ce qui évite le piège connu : deux normalisations, une en
--     SQL et une en PHP, divergent et le rapprochement échoue en silence. Ici
--     il n'y en a qu'une, et l'appelant ne la connaît même pas — il écrit avec
--     `ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)` et reçoit l'existante.
--
--  ⚠️ CE QUE CETTE MIGRATION NE FAIT PAS : elle ne SUPPRIME pas les colonnes
--     d'adresse. Deux fichiers de tests (`01_structure.php`, `12_tiers.php`)
--     en affirment la présence, et ils appartiennent à l'autre session qui
--     travaille sur ce dépôt. Les colonnes de `tiers` et `immeubles` sont
--     VIDÉES — une colonne vide n'est pas une seconde vérité — et leur retrait
--     appartient à qui tiendra ces tests. Celles d'`agences` et `societes`
--     sont conservées telles quelles : 11 lignes tenues à la main, qu'on ne
--     casse pas depuis l'extérieur.
-- ═══════════════════════════════════════════════════════════════════════════


-- ═══════════════════════════════════════════════════════════════════════════
--  LES ADRESSES — la valeur, écrite une seule fois
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE adresses (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- DEUX lignes de voie, et ce n est pas du confort : un immeuble a plusieurs
  -- allees s annonce sur deux voies. Les concatener perdrait la seconde.
  ligne_1     VARCHAR(255) NOT NULL,
  ligne_2     VARCHAR(255) NULL,

  -- Un code postal est un CODE, pas du texte : collation binaire.
  code_postal VARCHAR(10) COLLATE utf8mb4_nopad_bin NULL,
  commune     VARCHAR(150) NOT NULL,
  pays        VARCHAR(100) NOT NULL DEFAULT 'FRANCE',

  -- Decision d Emmanuel : toute adresse doit pouvoir aller sur une carte.
  latitude    DECIMAL(10,7) NULL,
  longitude   DECIMAL(10,7) NULL,
  -- L identifiant cartographique, quand il est connu. Il ENRICHIT l adresse,
  -- il ne la constitue pas : une adresse sans lui reste une adresse.
  place_id    VARCHAR(190) NULL,

  -- ⚠️ LA CLE D UNICITE, CALCULEE PAR LA BASE. Ponctuation et espaces retires
  --    par le regexp ; casse et accents replies par la collation. « 45, rue
  --    Druge » et « 45 RUE DRUGE » sont donc la MEME adresse, et la seconde
  --    ecriture ne cree rien.
  --    ⚠️ `[[:alnum:]]` ET NON `\p{L}` : la classe Unicode n'est pas interprétée ici, et
  --       l'expression rendait « |||NN|N » — une clé qui aurait replié en une seule
  --       adresse des bâtiments sans aucun rapport. Vérifié : « 45, rue Druge » et
  --       « 45 RUE DRUGE » rendent la même clé, « Béranger » et « BERANGER » aussi.
  cle         VARCHAR(600) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
              GENERATED ALWAYS AS (
                REGEXP_REPLACE(
                  CONCAT_WS('|', ligne_1, COALESCE(ligne_2,''),
                            COALESCE(code_postal,''), commune, pays),
                  '[^[:alnum:]|]+', '')
              ) STORED,

  created_at  DATETIME NOT NULL DEFAULT current_timestamp(),
  created_by  INT UNSIGNED NULL,

  CONSTRAINT pk_adresses PRIMARY KEY (id),
  CONSTRAINT uk_adresses_cle UNIQUE (cle),
  CONSTRAINT chk_adresses_ligne1  CHECK (TRIM(ligne_1) <> ''),
  CONSTRAINT chk_adresses_commune CHECK (TRIM(commune) <> ''),
  CONSTRAINT fk_adresses_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_adresses_commune ON adresses (code_postal, commune);


-- ═══════════════════════════════════════════════════════════════════════════
--  QUI UTILISE UNE ADRESSE, ET À QUEL TITRE
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE objet_adresses (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,

  -- Registre FERME, garde par un CHECK. Un bien n y figure pas : son adresse
  -- est celle de son immeuble, et la lui recopier serait le doublon meme.
  objet_type VARCHAR(10) COLLATE utf8mb4_nopad_bin NOT NULL,
  id_objet   INT UNSIGNED NOT NULL,
  id_adresse INT UNSIGNED NOT NULL,

  -- A QUEL TITRE. Un tiers peut avoir un siege ET une adresse de
  -- correspondance ; un immeuble a une situation, et parfois une seconde
  -- allee. Sans ce role, deux adresses sur un objet seraient indiscernables.
  role_code  VARCHAR(20) COLLATE utf8mb4_nopad_bin NOT NULL DEFAULT 'principale',

  -- Une adresse change : on ferme, on n efface pas. C est ce qui permet de
  -- comprendre pourquoi un courrier de l an dernier est parti la-bas.
  date_debut DATE NULL,
  date_fin   DATE NULL,

  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  created_by INT UNSIGNED NULL,

  -- ⚠️ UNE SEULE ADRESSE OUVERTE PAR OBJET ET PAR TITRE. Meme parti que
  --    `objet_codes.principal_cle` : la colonne vaut NULL des que la ligne est
  --    fermee, et deux NULL n entrent jamais en collision — l index tolere
  --    donc autant d adresses historiques qu on veut, et une seule ouverte.
  ouverte_cle VARCHAR(64) COLLATE utf8mb4_nopad_bin
              GENERATED ALWAYS AS (
                CASE WHEN date_fin IS NULL
                     THEN CONCAT(objet_type, ':', id_objet, ':', role_code) END
              ) VIRTUAL,

  CONSTRAINT pk_objet_adresses PRIMARY KEY (id),
  CONSTRAINT uk_objet_adresses_ouverte UNIQUE (ouverte_cle),
  CONSTRAINT chk_oa_type CHECK (objet_type IN ('TIERS','IMMEUBLE','AGENCE','SOCIETE')),
  CONSTRAINT chk_oa_role CHECK (role_code IN
    ('principale','siege','correspondance','facturation','situation','allee')),
  CONSTRAINT chk_oa_bornes CHECK (date_fin IS NULL OR date_debut IS NULL OR date_fin >= date_debut),
  CONSTRAINT fk_oa_adresse FOREIGN KEY (id_adresse) REFERENCES adresses (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_oa_created_by FOREIGN KEY (created_by) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX ix_oa_objet   ON objet_adresses (objet_type, id_objet);
CREATE INDEX ix_oa_adresse ON objet_adresses (id_adresse);
