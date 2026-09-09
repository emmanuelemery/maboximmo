-- ═══════════════════════════════════════════════════════════════════════════
--  MBI · 0025 — les adresses du SOCLE entrent au référentiel
-- ═══════════════════════════════════════════════════════════════════════════
--
--  Les 8 agences et les 3 sociétés portent leur adresse en colonnes depuis les
--  migrations 0013-0015. Elles rejoignent `adresses` comme tout le reste : une
--  routine de rapprochement ne doit connaître qu'UNE forme d'adresse.
--
--  ⚠️ LEURS COLONNES NE SONT PAS SUPPRIMÉES, ET C'EST DÉLIBÉRÉ. Onze lignes
--     tenues à la main, dont deux fichiers de tests (`01_structure.php`,
--     `12_tiers.php`) affirment la structure — et ces tests appartiennent à
--     l'autre session qui travaille sur ce dépôt. On ne casse pas depuis
--     l'extérieur ce dont quelqu'un d'autre répond.
--
--     C'est donc la SEULE duplication d'adresse qui subsiste dans la base, et
--     elle est nommée ici pour qu'elle ne s'oublie pas : le jour où ces tests
--     sont ajustés, `agences.adresse_1/code_postal/ville/pays` et
--     `societes.adresse_1/code_postal/ville` peuvent tomber.
--
--  `ON DUPLICATE KEY UPDATE adresses.id = LAST_INSERT_ID(adresses.id)` : si l'adresse existe
--  déjà — le siège d'une agence peut être un immeuble que nous gérons — on
--  récupère la sienne au lieu d'en écrire une seconde.
-- ═══════════════════════════════════════════════════════════════════════════

INSERT INTO adresses (ligne_1, code_postal, commune, pays, latitude, longitude, created_by)
SELECT a.adresse_1, a.code_postal, a.ville, COALESCE(a.pays, 'FRANCE'),
       a.latitude, a.longitude, 113
  FROM agences a
 WHERE a.adresse_1 IS NOT NULL AND TRIM(a.adresse_1) <> ''
   AND a.ville IS NOT NULL AND TRIM(a.ville) <> ''
ON DUPLICATE KEY UPDATE adresses.id = LAST_INSERT_ID(adresses.id);

INSERT INTO objet_adresses (objet_type, id_objet, id_adresse, role_code, created_by)
SELECT 'AGENCE', a.id, x.id, 'siege', 113
  FROM agences a
  JOIN adresses x ON x.cle = REGEXP_REPLACE(
        CONCAT_WS('|', a.adresse_1, '', COALESCE(a.code_postal,''), a.ville, COALESCE(a.pays,'FRANCE')),
        '[^[:alnum:]|]+', '')
 WHERE a.adresse_1 IS NOT NULL AND TRIM(a.adresse_1) <> ''
   AND a.ville IS NOT NULL AND TRIM(a.ville) <> '';

INSERT INTO adresses (ligne_1, code_postal, commune, pays, created_by)
SELECT s.adresse_1, s.code_postal, s.ville, 'FRANCE', 113
  FROM societes s
 WHERE s.adresse_1 IS NOT NULL AND TRIM(s.adresse_1) <> ''
   AND s.ville IS NOT NULL AND TRIM(s.ville) <> ''
ON DUPLICATE KEY UPDATE adresses.id = LAST_INSERT_ID(adresses.id);

INSERT INTO objet_adresses (objet_type, id_objet, id_adresse, role_code, created_by)
SELECT 'SOCIETE', s.id, x.id, 'siege', 113
  FROM societes s
  JOIN adresses x ON x.cle = REGEXP_REPLACE(
        CONCAT_WS('|', s.adresse_1, '', COALESCE(s.code_postal,''), s.ville, 'FRANCE'),
        '[^[:alnum:]|]+', '')
 WHERE s.adresse_1 IS NOT NULL AND TRIM(s.adresse_1) <> ''
   AND s.ville IS NOT NULL AND TRIM(s.ville) <> '';


-- ═══════════════════════════════════════════════════════════════════════════
--  LA VUE D'ADRESSAGE, REFAITE SUR LE RÉFÉRENTIEL
-- ═══════════════════════════════════════════════════════════════════════════
--  Elle lisait `tiers.adr_*` et `immeubles.adresse_1`, qui ne portent plus
--  rien. Elle lit maintenant les liens — et la réponse est la même.
CREATE OR REPLACE VIEW v_tiers_adresse_courrier AS
SELECT
    t.id                                   AS id_tiers,
    t.nom_affichage,
    CASE WHEN at.id IS NOT NULL THEN 'TIERS'
         WHEN ai.id IS NOT NULL THEN 'LOT_OCCUPE' END AS source,
    COALESCE(at.ligne_1,     ai.ligne_1)     AS ligne_voie,
    COALESCE(at.code_postal, ai.code_postal) AS code_postal,
    COALESCE(at.commune,     ai.commune)     AS commune,
    COALESCE(at.pays,        ai.pays)        AS pays,
    o.id AS id_occupation, b.id AS id_bien, i.id AS id_immeuble
FROM tiers t
-- L'adresse propre du tiers, si elle est connue.
LEFT JOIN objet_adresses oat ON oat.objet_type = 'TIERS' AND oat.id_objet = t.id
                            AND oat.date_fin IS NULL
LEFT JOIN adresses at ON at.id = oat.id_adresse
-- Sinon, celle du logement qu'il occupe AUJOURD'HUI — jamais d'une occupation close.
LEFT JOIN occupation_occupants oo
       ON oo.id = (SELECT oo2.id FROM occupation_occupants oo2
                     JOIN occupations o2 ON o2.id = oo2.id_occupation
                    WHERE oo2.id_tiers = t.id AND o2.date_fin IS NULL
                    ORDER BY o2.date_debut DESC, o2.id DESC LIMIT 1)
LEFT JOIN occupations o ON o.id = oo.id_occupation
LEFT JOIN biens       b ON b.id = o.id_bien
LEFT JOIN immeubles   i ON i.id = b.id_immeuble
LEFT JOIN objet_adresses oai ON oai.objet_type = 'IMMEUBLE' AND oai.id_objet = i.id
                            AND oai.date_fin IS NULL
LEFT JOIN adresses ai ON ai.id = oai.id_adresse;
