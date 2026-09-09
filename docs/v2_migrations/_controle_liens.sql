SELECT '=== 1. LA BASE ===' AS x;
SELECT 'tiers' t, COUNT(*) n FROM tiers
UNION ALL SELECT 'tiers_roles', COUNT(*) FROM tiers_roles
UNION ALL SELECT 'adresses (valeurs uniques)', COUNT(*) FROM adresses
UNION ALL SELECT 'objet_adresses (liens)', COUNT(*) FROM objet_adresses
UNION ALL SELECT 'mandants', COUNT(*) FROM mandants
UNION ALL SELECT 'immeubles', COUNT(*) FROM immeubles
UNION ALL SELECT 'biens', COUNT(*) FROM biens
UNION ALL SELECT 'baux', COUNT(*) FROM baux
UNION ALL SELECT 'mandats', COUNT(*) FROM mandats
UNION ALL SELECT 'occupations', COUNT(*) FROM occupations
UNION ALL SELECT 'occupation_occupants', COUNT(*) FROM occupation_occupants
UNION ALL SELECT 'objet_codes', COUNT(*) FROM objet_codes;

SELECT '=== 2. LES ROLES, SUR LEUR OBJET ===' AS x;
SELECT role_code, objet_type, COUNT(*) n, COUNT(DISTINCT id_tiers) tiers, COUNT(DISTINCT id_objet) objets
  FROM tiers_roles GROUP BY 1,2 ORDER BY 3 DESC;

SELECT '=== 3. LES ADRESSES : PLUS AUCUNE COPIE ===' AS x;
SELECT 'adresses distinctes' q, COUNT(*) n FROM adresses
UNION ALL SELECT 'liens vers ces adresses', COUNT(*) FROM objet_adresses
UNION ALL SELECT '  dont TIERS', COUNT(*) FROM objet_adresses WHERE objet_type='TIERS'
UNION ALL SELECT '  dont IMMEUBLE', COUNT(*) FROM objet_adresses WHERE objet_type='IMMEUBLE'
UNION ALL SELECT '  dont AGENCE', COUNT(*) FROM objet_adresses WHERE objet_type='AGENCE'
UNION ALL SELECT '  dont SOCIETE', COUNT(*) FROM objet_adresses WHERE objet_type='SOCIETE'
UNION ALL SELECT 'adresses PARTAGEES par plusieurs objets', COUNT(*) FROM (
   SELECT id_adresse FROM objet_adresses GROUP BY 1 HAVING COUNT(*)>1) z
UNION ALL SELECT 'colonnes tiers.adr_* encore remplies (doit etre 0)', COUNT(*) FROM tiers WHERE adr_ligne_voie IS NOT NULL
UNION ALL SELECT 'colonnes immeubles.adresse_1 encore remplies (doit etre 0)', COUNT(*) FROM immeubles WHERE adresse_1 IS NOT NULL;

SELECT '=== 4. LADRESSE LA PLUS PARTAGEE ===' AS x;
SELECT a.ligne_1, a.code_postal, a.commune, COUNT(*) objets,
       GROUP_CONCAT(DISTINCT oa.objet_type) types
  FROM objet_adresses oa JOIN adresses a ON a.id=oa.id_adresse
 GROUP BY a.id ORDER BY objets DESC LIMIT 3;

SELECT '=== 5. TOUS LES LIENS SONT-ILS FERMES ? ===' AS x;
SELECT 'biens sans mandant' q, COUNT(*) n FROM biens WHERE id_mandant IS NULL
UNION ALL SELECT 'biens sans immeuble', COUNT(*) FROM biens WHERE id_immeuble IS NULL
UNION ALL SELECT 'biens sans nature', COUNT(*) FROM biens WHERE nature_code IS NULL
UNION ALL SELECT 'biens sans mandat', COUNT(*) FROM biens b WHERE NOT EXISTS (SELECT 1 FROM mandats m WHERE m.id_bien=b.id)
UNION ALL SELECT 'mandants sans tiers', COUNT(*) FROM mandants WHERE id_tiers IS NULL
UNION ALL SELECT 'occupations sans bail', COUNT(*) FROM occupations o WHERE NOT EXISTS (SELECT 1 FROM baux x WHERE x.id_occupation=o.id)
UNION ALL SELECT 'baux sans bien', COUNT(*) FROM baux WHERE id_bien IS NULL
UNION ALL SELECT 'occupants sans tiers', COUNT(*) FROM occupation_occupants WHERE id_tiers IS NULL
UNION ALL SELECT 'tiers sans aucun role', COUNT(*) FROM tiers t WHERE NOT EXISTS (SELECT 1 FROM tiers_roles r WHERE r.id_tiers=t.id)
UNION ALL SELECT 'immeubles sans agence', COUNT(*) FROM immeubles WHERE id_agence IS NULL
UNION ALL SELECT 'agences sans societe', COUNT(*) FROM agences WHERE id_societe IS NULL;

SELECT '=== 6. TIERS → AGENCE → SOCIETE, PAR LES DEUX CHEMINS ===' AS x;
SELECT 'proprietaires atteignant une societe' q, COUNT(DISTINCT m.id_tiers) n
  FROM mandants m JOIN agences a ON a.id=m.id_agence JOIN societes s ON s.id=a.id_societe
UNION ALL SELECT 'locataires atteignant une societe', COUNT(DISTINCT oo.id_tiers)
  FROM occupation_occupants oo JOIN occupations o ON o.id=oo.id_occupation
  JOIN biens b ON b.id=o.id_bien JOIN mandants m ON m.id=b.id_mandant
  JOIN agences a ON a.id=m.id_agence JOIN societes s ON s.id=a.id_societe
 WHERE oo.id_tiers IS NOT NULL
UNION ALL SELECT 'locataires (total)', COUNT(DISTINCT id_tiers) FROM occupation_occupants WHERE id_tiers IS NOT NULL;

SELECT '=== 7. LA CHAINE ENTIERE, UN EXEMPLE ===' AS x;
SELECT s.nom societe, ag.nom agence, tp.nom_affichage proprietaire,
       CONCAT(ap.ligne_1,' ',ap.code_postal,' ',ap.commune) adresse_proprietaire,
       i.nom immeuble, CONCAT(ai.ligne_1,' ',ai.code_postal,' ',ai.commune) adresse_immeuble,
       b.designation lot, b.nature_code, md.type_code mandat,
       tl.nom_affichage locataire, bx.date_debut bail_du, bx.type_code type_bail
  FROM biens b
  JOIN mandants m   ON m.id = b.id_mandant
  JOIN agences ag   ON ag.id = m.id_agence
  JOIN societes s   ON s.id = ag.id_societe
  JOIN tiers tp     ON tp.id = m.id_tiers
  LEFT JOIN objet_adresses oap ON oap.objet_type='TIERS' AND oap.id_objet=tp.id
  LEFT JOIN adresses ap ON ap.id=oap.id_adresse
  JOIN immeubles i  ON i.id = b.id_immeuble
  LEFT JOIN objet_adresses oai ON oai.objet_type='IMMEUBLE' AND oai.id_objet=i.id
  LEFT JOIN adresses ai ON ai.id=oai.id_adresse
  LEFT JOIN mandats md ON md.id_bien = b.id
  LEFT JOIN occupations o ON o.id_bien = b.id AND o.date_fin IS NULL
  LEFT JOIN baux bx ON bx.id_occupation = o.id
  LEFT JOIN occupation_occupants oo ON oo.id_occupation = o.id
  LEFT JOIN tiers tl ON tl.id = oo.id_tiers
 WHERE ap.id IS NOT NULL AND ai.id IS NOT NULL AND tl.id IS NOT NULL AND bx.date_debut IS NOT NULL
 LIMIT 2;
