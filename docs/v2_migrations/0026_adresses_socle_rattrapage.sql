-- ═══════════════════════════════════════════════════════════════════════════
--  MBI · 0026 — les adresses du socle, RATTRAPÉES (5 agences sur 8 manquaient)
-- ═══════════════════════════════════════════════════════════════════════════
--
--  La migration 0025 n'a rattaché que 3 agences sur 8, et aucune société. Ce
--  n'était pas une donnée absente — les 8 agences portent leur adresse depuis
--  la migration 0015. C'était MA jointure.
--
--  ⚠️ LES DEUX CÔTÉS D'UNE COMPARAISON DOIVENT AVOIR LA MÊME COLLATION, ET PAS
--     SEULEMENT LA MÊME FONCTION. `agences.pays` vaut « France » ; l'import CRG
--     écrit « FRANCE ». La colonne `adresses.cle` est en `utf8mb4_unicode_ci`
--     et les replie donc correctement — l'INSERT a bien vu que c'était LA MÊME
--     adresse et n'a rien créé. Mais ma jointure comparait `cle` à une
--     expression dont la collation n'était pas celle-là : elle ne trouvait
--     rien, et le lien n'était pas posé.
--
--     Le défaut est jumeau de celui déjà consigné (`crgi_plat()` d'un côté,
--     `UPPER(TRIM())` de l'autre). La leçon s'étend : ce n'est pas seulement
--     la FONCTION de normalisation qui doit être la même des deux côtés, c'est
--     aussi la COLLATION de la comparaison.
--
--  ⚠️ ET C'EST LA PREUVE QUE LE RÉFÉRENTIEL FAIT SON TRAVAIL. L'agence de
--     CHAPONOST est au « 10 place Maréchal FOCH » ; un CRG avait déjà fait
--     entrer « 10 PLACE MARECHAL FOCH » pour un immeuble. Une seule ligne
--     existe, et l'agence s'y rattache — au lieu d'en écrire une seconde.
--
--     Réponse à la question d'Emmanuel — « les adresses des agences : je dois
--     créer un immeuble ? » : NON. Une agence n'est pas un immeuble du
--     patrimoine. Elle porte un LIEN vers une adresse, exactement comme un
--     immeuble en porte un — et si les deux sont au même endroit, ils
--     pointent la MÊME ligne.
-- ═══════════════════════════════════════════════════════════════════════════

INSERT INTO adresses (ligne_1, code_postal, commune, pays, latitude, longitude, created_by)
SELECT a.adresse_1, a.code_postal, a.ville, COALESCE(a.pays,'FRANCE'), a.latitude, a.longitude, 113
  FROM agences a
 WHERE a.adresse_1 IS NOT NULL AND TRIM(a.adresse_1) <> ''
   AND a.ville IS NOT NULL AND TRIM(a.ville) <> ''
ON DUPLICATE KEY UPDATE adresses.id = LAST_INSERT_ID(adresses.id);

INSERT IGNORE INTO objet_adresses (objet_type, id_objet, id_adresse, role_code, created_by)
SELECT 'AGENCE', a.id, x.id, 'siege', 113
  FROM agences a
  JOIN adresses x
    ON x.cle = REGEXP_REPLACE(
         CONCAT_WS('|', a.adresse_1, '', COALESCE(a.code_postal,''), a.ville, COALESCE(a.pays,'FRANCE')),
         '[^[:alnum:]|]+', '') COLLATE utf8mb4_unicode_ci
 WHERE a.adresse_1 IS NOT NULL AND TRIM(a.adresse_1) <> ''
   AND a.ville IS NOT NULL AND TRIM(a.ville) <> ''
   AND NOT EXISTS (SELECT 1 FROM objet_adresses o
                    WHERE o.objet_type='AGENCE' AND o.id_objet=a.id AND o.date_fin IS NULL);

INSERT INTO adresses (ligne_1, code_postal, commune, pays, created_by)
SELECT s.adresse_1, s.code_postal, s.ville, 'FRANCE', 113
  FROM societes s
 WHERE s.adresse_1 IS NOT NULL AND TRIM(s.adresse_1) <> ''
   AND s.ville IS NOT NULL AND TRIM(s.ville) <> ''
ON DUPLICATE KEY UPDATE adresses.id = LAST_INSERT_ID(adresses.id);

INSERT IGNORE INTO objet_adresses (objet_type, id_objet, id_adresse, role_code, created_by)
SELECT 'SOCIETE', s.id, x.id, 'siege', 113
  FROM societes s
  JOIN adresses x
    ON x.cle = REGEXP_REPLACE(
         CONCAT_WS('|', s.adresse_1, '', COALESCE(s.code_postal,''), s.ville, 'FRANCE'),
         '[^[:alnum:]|]+', '') COLLATE utf8mb4_unicode_ci
 WHERE s.adresse_1 IS NOT NULL AND TRIM(s.adresse_1) <> ''
   AND s.ville IS NOT NULL AND TRIM(s.ville) <> ''
   AND NOT EXISTS (SELECT 1 FROM objet_adresses o
                    WHERE o.objet_type='SOCIETE' AND o.id_objet=s.id AND o.date_fin IS NULL);


-- ═══════════════════════════════════════════════════════════════════════════
--  UN PROPRIÉTAIRE RETROUVÉ SUR UN CRG PORTE SON RÔLE, MÊME SANS LOT
-- ═══════════════════════════════════════════════════════════════════════════
--
--  Emmanuel, 09/09/2026 : « si c'est un propriétaire retrouvé d'un CRG il faut
--  quand même créer le tiers avec un rôle propriétaire ! ».
--
--  30 tiers étaient sans aucun rôle. Ce ne sont pas des inconnus : **29 ont un
--  COMPTE MANDANT** — CHAMOUSSET Michel chez VIENNE, PONCET Alain chez
--  CHAPONOST — mais aucun lot n'a pu leur être rattaché, souvent parce que le
--  compte rendu ne portait plus qu'un solde. Le registre refusant un
--  `proprietaire` sans objet, ils n'avaient rien.
--
--  ⚠️ LE COMPTE EST L'ANCRAGE QUI NE MANQUE JAMAIS. Un lot peut être illisible,
--     un immeuble non rattaché — le COMPTE, lui, est ce qui a fait exister le
--     compte rendu. `proprietaire` reçoit donc `mandant` parmi ses objets.
--
--  ⚠️ CE N'EST PAS UN DOUBLON DE `mandants.id_tiers`. Cette colonne dit QUI est
--     derrière le compte ; le rôle dit À QUEL TITRE. Le jour où un compte est
--     tenu par un mandataire, un indivisaire ou un usufruitier, l'identité ne
--     change pas et le rôle si — et c'est là qu'on l'écrira.
--  ⚠️ AUCUN ALTER : `objets` est déjà en VARCHAR(255), et `chk_trc_objets` ne
--     contraint que la FORME de la liste — `^[a-z][a-z0-9_]*(,[a-z][a-z0-9_]*)*$`
--     — pas un vocabulaire fermé d'objets. Vérifié avant d'écrire : ma première
--     version ajoutait un MODIFY qui aurait RÉTRÉCI la colonne de 255 à 190.
UPDATE tiers_roles_codes SET objets = 'bien,immeuble,mandant' WHERE code = 'proprietaire';
