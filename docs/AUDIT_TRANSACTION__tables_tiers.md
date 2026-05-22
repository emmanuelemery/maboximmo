### `tiers` (source: `sql/SAUVEGARDES/u630423897_maboximmo (3).sql:40455`)

**Colonnes**
- `id` — `int(10) UNSIGNED NOT NULL`
- `id_societe` — `int(10) UNSIGNED DEFAULT NULL`
- `id_agence` — `int(10) UNSIGNED DEFAULT NULL`
- `sous_type` — `varchar(40) DEFAULT NULL COMMENT 'sci | sarl | sas | association | copropriete | couple | famille | artisan ...'`
- `civilite` — `varchar(20) DEFAULT NULL`
- `nom` — `varchar(150) DEFAULT NULL`
- `prenom` — `varchar(150) DEFAULT NULL`
- `nom_naissance` — `varchar(150) DEFAULT NULL`
- `date_naissance` — `date DEFAULT NULL`
- `lieu_naissance` — `varchar(150) DEFAULT NULL`
- `nationalite` — `varchar(80) DEFAULT NULL`
- `raison_sociale` — `varchar(255) DEFAULT NULL`
- `forme_juridique` — `varchar(60) DEFAULT NULL`
- `siret` — `varchar(20) DEFAULT NULL`
- `siren` — `varchar(20) DEFAULT NULL`
- `tva_intracom` — `varchar(20) DEFAULT NULL`
- `rcs` — `varchar(80) DEFAULT NULL`
- `nom_affichage` — `varchar(255) DEFAULT NULL COMMENT 'Calculé ou forcé — utilisé dans les listes/recherches'`
- `email` — `varchar(190) DEFAULT NULL`
- `email_secondaire` — `varchar(190) DEFAULT NULL`
- `telephone` — `varchar(30) DEFAULT NULL`
- `telephone_secondaire` — `varchar(30) DEFAULT NULL`
- `mobile` — `varchar(30) DEFAULT NULL`
- `adresse_ligne1` — `varchar(255) DEFAULT NULL`
- `adresse_ligne2` — `varchar(255) DEFAULT NULL`
- `code_postal` — `varchar(10) DEFAULT NULL`
- `ville` — `varchar(150) DEFAULT NULL`
- `pays` — `varchar(100) DEFAULT 'France'`
- `google_place_id` — `varchar(190) DEFAULT NULL`
- `adresse_formatee` — `varchar(500) DEFAULT NULL`
- `commentaire` — `text DEFAULT NULL COMMENT 'Visible par le tiers via extranet'`
- `notes_internes` — `text DEFAULT NULL COMMENT 'Jamais visible du tiers — réservé interne'`
- `source_creation` — `varchar(60) DEFAULT NULL COMMENT 'manuel | import_crg | notif_mutation | extranet | intake_ia'`
- `origine` — `varchar(60) DEFAULT NULL COMMENT 'prospect | ancien_client | partenaire | recommandation'`
- `id_user_createur` — `int(10) UNSIGNED DEFAULT NULL`
- `actif` — `tinyint(1) NOT NULL DEFAULT 1`
- `date_creation` — `datetime NOT NULL DEFAULT current_timestamp()`
- `date_modification` — `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()`

### `tiers_contacts` (source: `sql/SAUVEGARDES/u630423897_maboximmo (3).sql:41430`)

**Colonnes**
- `id` — `int(10) UNSIGNED NOT NULL`
- `id_tiers_entite` — `int(10) UNSIGNED NOT NULL COMMENT 'Personne morale / entité'`
- `id_tiers_contact` — `int(10) UNSIGNED NOT NULL COMMENT 'Personne physique liée'`
- `qualite` — `varchar(60) NOT NULL COMMENT 'gerant | associe | president | representant_legal | contact_comptable | contact_technique | contact_urgence'`
- `actif` — `tinyint(1) NOT NULL DEFAULT 1`
- `date_debut` — `date DEFAULT NULL`
- `date_fin` — `date DEFAULT NULL`
- `commentaire` — `varchar(500) DEFAULT NULL`
- `date_creation` — `datetime NOT NULL DEFAULT current_timestamp()`

### `tiers_roles` (source: `sql/SAUVEGARDES/u630423897_maboximmo (3).sql:41450`)

**Colonnes**
- `id` — `int(10) UNSIGNED NOT NULL`
- `id_tiers` — `int(10) UNSIGNED NOT NULL`
- `role_code` — `varchar(40) NOT NULL`
- `objet_type` — `varchar(30) DEFAULT NULL COMMENT 'bien | immeuble | mandat | bail | reunion | sinistre | societe | NULL (rôle global)'`
- `id_objet` — `int(10) UNSIGNED DEFAULT NULL`
- `date_debut` — `date DEFAULT NULL`
- `date_fin` — `date DEFAULT NULL`
- `priorite` — `smallint(6) NOT NULL DEFAULT 0 COMMENT 'Ordre d''affichage / contact principal si plusieurs'`
- `actif` — `tinyint(1) NOT NULL DEFAULT 1`
- `date_creation` — `datetime NOT NULL DEFAULT current_timestamp()`
- `date_modification` — `datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()`

### `tiers_roles_codes` (source: `sql/SAUVEGARDES/u630423897_maboximmo (3).sql:42393`)

**Colonnes**
- `code` — `varchar(40) NOT NULL`
- `libelle` — `varchar(100) NOT NULL`
- `categorie` — `varchar(40) NOT NULL COMMENT 'acteur_immo | prestataire | juridique | financier | crm | contact | extranet | autre'`
- `description` — `varchar(255) DEFAULT NULL`
- `objet_type_defaut` — `varchar(30) DEFAULT NULL COMMENT 'bien | immeuble | mandat | bail | reunion | sinistre | contentieux | societe | global'`
- `actif` — `tinyint(1) NOT NULL DEFAULT 1`
- `ordre_affichage` — `int(11) NOT NULL DEFAULT 0`

### `user_tiers` (source: `sql/SAUVEGARDES/u630423897_maboximmo (3).sql:42777`)

**Colonnes**
- `id` — `int(10) UNSIGNED NOT NULL`
- `id_user` — `int(10) UNSIGNED NOT NULL`
- `id_tiers` — `int(10) UNSIGNED NOT NULL`
- `type_lien` — `varchar(40) NOT NULL DEFAULT 'self' COMMENT 'self | representant | mandataire | extranet_bailleur | extranet_coproprio | extranet_locataire | extranet_prestataire'`
- `actif` — `tinyint(1) NOT NULL DEFAULT 1`
- `date_debut` — `date DEFAULT NULL`
- `date_fin` — `date DEFAULT NULL`
- `date_creation` — `datetime NOT NULL DEFAULT current_timestamp()`

