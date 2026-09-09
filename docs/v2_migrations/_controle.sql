SELECT '=== 1. PAR AGENCE ===' AS x;
SELECT a.id, a.nom,
       (SELECT COUNT(*) FROM mandants m WHERE m.id_agence=a.id) AS comptes,
       (SELECT COUNT(*) FROM immeubles i WHERE i.id_agence=a.id) AS immeubles,
       (SELECT COUNT(*) FROM biens b JOIN mandants m ON m.id=b.id_mandant WHERE m.id_agence=a.id) AS biens,
       (SELECT COUNT(*) FROM mandats md WHERE md.id_agence=a.id) AS mandats
  FROM agences a ORDER BY a.id;

SELECT '=== 2. NATURE DES BIENS ===' AS x;
SELECT COALESCE(b.nature_code,'(aucune)') nature, n.usage_code, COUNT(*) n
  FROM biens b LEFT JOIN biens_natures n ON n.code=b.nature_code
 GROUP BY 1,2 ORDER BY 3 DESC;

SELECT '=== 3. OCCUPATION ===' AS x;
SELECT 'occupations en cours (date_fin NULL)' q, COUNT(*) n FROM occupations WHERE date_fin IS NULL
UNION ALL SELECT 'occupations closes', COUNT(*) FROM occupations WHERE date_fin IS NOT NULL
UNION ALL SELECT 'biens occupes aujourdhui', COUNT(DISTINCT id_bien) FROM occupations WHERE date_fin IS NULL
UNION ALL SELECT 'biens sans aucune occupation', COUNT(*) FROM biens b
   WHERE NOT EXISTS (SELECT 1 FROM occupations o WHERE o.id_bien=b.id)
UNION ALL SELECT 'occupants rattaches a un tiers', COUNT(*) FROM occupation_occupants WHERE id_tiers IS NOT NULL
UNION ALL SELECT 'occupants sans tiers', COUNT(*) FROM occupation_occupants WHERE id_tiers IS NULL;

SELECT '=== 4. TROUS A COMPLETER ===' AS x;
SELECT 'biens sans immeuble' q, COUNT(*) n FROM biens WHERE id_immeuble IS NULL
UNION ALL SELECT 'biens sans compte mandant', COUNT(*) FROM biens WHERE id_mandant IS NULL
UNION ALL SELECT 'biens sans nature', COUNT(*) FROM biens WHERE nature_code IS NULL
UNION ALL SELECT 'immeubles sans adresse', COUNT(*) FROM immeubles WHERE adresse_1 IS NULL
UNION ALL SELECT 'immeubles sans ville', COUNT(*) FROM immeubles WHERE ville IS NULL
UNION ALL SELECT 'immeubles sans code postal', COUNT(*) FROM immeubles WHERE code_postal IS NULL
UNION ALL SELECT 'comptes mandants sans tiers', COUNT(*) FROM mandants WHERE id_tiers IS NULL
UNION ALL SELECT 'tiers sans aucun role', COUNT(*) FROM tiers t
   WHERE NOT EXISTS (SELECT 1 FROM tiers_roles r WHERE r.id_tiers=t.id)
UNION ALL SELECT 'mandats sans immeuble', COUNT(*) FROM mandats WHERE id_immeuble IS NULL;

SELECT '=== 5. TIERS ET ROLES ===' AS x;
SELECT t.type, COUNT(*) n FROM tiers t GROUP BY 1;
SELECT r.role_code, COUNT(*) n FROM tiers_roles r GROUP BY 1 ORDER BY 2 DESC;
SELECT 'tiers portant DEUX roles' q, COUNT(*) n FROM (
  SELECT id_tiers FROM tiers_roles GROUP BY 1 HAVING COUNT(*)>1) z;

SELECT '=== 6. CODES CRG REPRIS ===' AS x;
SELECT systeme, objet_type, COUNT(*) n, SUM(principal) principaux
  FROM objet_codes GROUP BY 1,2 ORDER BY 1,2;
SELECT 'immeubles portant PLUSIEURS codes' q, COUNT(*) n FROM (
  SELECT id_objet FROM objet_codes WHERE objet_type='IMMEUBLE' GROUP BY 1 HAVING COUNT(*)>1) z;

SELECT '=== 7. COHERENCE ===' AS x;
SELECT 'biens sans mandat de gestion' q, COUNT(*) n FROM biens b
   WHERE NOT EXISTS (SELECT 1 FROM mandats m WHERE m.id_bien=b.id)
UNION ALL SELECT 'mandats dont le bien a un autre immeuble', COUNT(*) FROM mandats m
   JOIN biens b ON b.id=m.id_bien
  WHERE m.id_immeuble IS NOT NULL AND b.id_immeuble IS NOT NULL AND m.id_immeuble<>b.id_immeuble
UNION ALL SELECT 'occupations hors bien existant', COUNT(*) FROM occupations o
   WHERE NOT EXISTS (SELECT 1 FROM biens b WHERE b.id=o.id_bien)
UNION ALL SELECT 'lignes du journal', COUNT(*) FROM imports_journal
UNION ALL SELECT 'total lignes metier ecrites',
   (SELECT COUNT(*) FROM tiers)+(SELECT COUNT(*) FROM tiers_roles)+(SELECT COUNT(*) FROM mandants)
  +(SELECT COUNT(*) FROM immeubles)+(SELECT COUNT(*) FROM biens)+(SELECT COUNT(*) FROM mandats)
  +(SELECT COUNT(*) FROM objet_codes)+(SELECT COUNT(*) FROM occupations)
  +(SELECT COUNT(*) FROM occupation_occupants);

SELECT '=== 8. ECHANTILLON ===' AS x;
SELECT i.id, i.nom, i.adresse_1, i.code_postal, i.ville,
       (SELECT COUNT(*) FROM biens b WHERE b.id_immeuble=i.id) lots,
       (SELECT GROUP_CONCAT(c.code) FROM objet_codes c WHERE c.objet_type='IMMEUBLE' AND c.id_objet=i.id) codes
  FROM immeubles i ORDER BY lots DESC LIMIT 8;
