-- ═══════════════════════════════════════════════════════════════════════════
--  LA RÉFÉRENCE — l'état de `mbi` après l'import CRG du 09/09/2026
--  Toute passe future se juge contre ces chiffres. Requête inchangée = comparaison valable.
-- ═══════════════════════════════════════════════════════════════════════════

SELECT '=== A. PAR AGENCE ===' AS x;
SELECT a.id, a.nom, s.nom societe,
  (SELECT COUNT(*) FROM mandants m WHERE m.id_agence=a.id) comptes,
  (SELECT COUNT(DISTINCT m.id_tiers) FROM mandants m WHERE m.id_agence=a.id) proprietaires,
  (SELECT COUNT(*) FROM immeubles i WHERE i.id_agence=a.id) immeubles,
  (SELECT COUNT(*) FROM biens b JOIN mandants m ON m.id=b.id_mandant WHERE m.id_agence=a.id) lots,
  (SELECT COUNT(DISTINCT o.id_bien) FROM occupations o JOIN biens b ON b.id=o.id_bien
     JOIN mandants m ON m.id=b.id_mandant WHERE m.id_agence=a.id AND o.date_fin IS NULL) occupes,
  (SELECT COUNT(*) FROM biens b JOIN mandants m ON m.id=b.id_mandant WHERE m.id_agence=a.id
     AND NOT EXISTS (SELECT 1 FROM occupations o WHERE o.id_bien=b.id AND o.date_fin IS NULL)) vides,
  (SELECT COUNT(*) FROM mandats md WHERE md.id_agence=a.id) mandats,
  (SELECT COUNT(*) FROM baux bx JOIN biens b ON b.id=bx.id_bien
     JOIN mandants m ON m.id=b.id_mandant WHERE m.id_agence=a.id) baux
FROM agences a JOIN societes s ON s.id=a.id_societe ORDER BY a.id;

SELECT '=== B. NATURES DE BIEN, PAR AGENCE ===' AS x;
SELECT m.id_agence agence, b.nature_code, n.usage_code, COUNT(*) lots
  FROM biens b JOIN mandants m ON m.id=b.id_mandant
  JOIN biens_natures n ON n.code=b.nature_code
 GROUP BY 1,2 ORDER BY 1, 4 DESC;

SELECT '=== C. TOTAUX ===' AS x;
SELECT 'tiers' t, COUNT(*) n FROM tiers
UNION ALL SELECT '  dont MORALE', COUNT(*) FROM tiers WHERE type='MORALE'
UNION ALL SELECT '  dont PHYSIQUE', COUNT(*) FROM tiers WHERE type='PHYSIQUE'
UNION ALL SELECT 'tiers_roles', COUNT(*) FROM tiers_roles
UNION ALL SELECT 'mandants', COUNT(*) FROM mandants
UNION ALL SELECT 'immeubles', COUNT(*) FROM immeubles
UNION ALL SELECT 'biens', COUNT(*) FROM biens
UNION ALL SELECT 'baux', COUNT(*) FROM baux
UNION ALL SELECT 'mandats', COUNT(*) FROM mandats
UNION ALL SELECT 'occupations', COUNT(*) FROM occupations
UNION ALL SELECT 'occupation_occupants', COUNT(*) FROM occupation_occupants
UNION ALL SELECT 'objet_codes', COUNT(*) FROM objet_codes
UNION ALL SELECT 'adresses', COUNT(*) FROM adresses
UNION ALL SELECT 'objet_adresses', COUNT(*) FROM objet_adresses;

SELECT '=== D. RÔLES, SUR LEUR OBJET ===' AS x;
SELECT role_code, objet_type, COUNT(*) lignes, COUNT(DISTINCT id_tiers) tiers, COUNT(DISTINCT id_objet) objets
  FROM tiers_roles GROUP BY 1,2 ORDER BY 3 DESC;

SELECT '=== E. ADRESSES ===' AS x;
SELECT objet_type, COUNT(*) liens, COUNT(DISTINCT id_adresse) adresses_distinctes
  FROM objet_adresses GROUP BY 1 ORDER BY 2 DESC;
SELECT 'adresses (valeurs uniques)' q, COUNT(*) n FROM adresses
UNION ALL SELECT 'partagees par plusieurs objets', COUNT(*) FROM (
   SELECT id_adresse FROM objet_adresses GROUP BY 1 HAVING COUNT(*)>1) z;

SELECT '=== F. CE QUI MANQUE — les trous connus ===' AS x;
SELECT 'immeubles sans adresse' q, COUNT(*) n FROM immeubles i
  WHERE NOT EXISTS (SELECT 1 FROM objet_adresses o WHERE o.objet_type='IMMEUBLE' AND o.id_objet=i.id)
UNION ALL SELECT 'proprietaires sans adresse', COUNT(DISTINCT m.id_tiers) FROM mandants m
  WHERE m.id_tiers IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM objet_adresses o WHERE o.objet_type='TIERS' AND o.id_objet=m.id_tiers)
UNION ALL SELECT 'lots sans immeuble', COUNT(*) FROM biens WHERE id_immeuble IS NULL
UNION ALL SELECT 'immeubles sans lot', COUNT(*) FROM immeubles i
  WHERE NOT EXISTS (SELECT 1 FROM biens b WHERE b.id_immeuble=i.id)
UNION ALL SELECT 'lots sans occupation', COUNT(*) FROM biens b
  WHERE NOT EXISTS (SELECT 1 FROM occupations o WHERE o.id_bien=b.id);

SELECT '=== G. INVARIANTS — doivent tous valoir 0 ===' AS x;
SELECT 'tiers sans role' q, COUNT(*) n FROM tiers t WHERE NOT EXISTS (SELECT 1 FROM tiers_roles r WHERE r.id_tiers=t.id)
UNION ALL SELECT 'biens sans compte mandant', COUNT(*) FROM biens WHERE id_mandant IS NULL
UNION ALL SELECT 'biens sans nature', COUNT(*) FROM biens WHERE nature_code IS NULL
UNION ALL SELECT 'biens sans mandat', COUNT(*) FROM biens b WHERE NOT EXISTS (SELECT 1 FROM mandats m WHERE m.id_bien=b.id)
UNION ALL SELECT 'comptes sans tiers', COUNT(*) FROM mandants WHERE id_tiers IS NULL
UNION ALL SELECT 'occupations sans bail', COUNT(*) FROM occupations o WHERE NOT EXISTS (SELECT 1 FROM baux x WHERE x.id_occupation=o.id)
UNION ALL SELECT 'occupants sans tiers', COUNT(*) FROM occupation_occupants WHERE id_tiers IS NULL
UNION ALL SELECT 'immeubles sans agence', COUNT(*) FROM immeubles WHERE id_agence IS NULL
-- ⚠️ TOUTE LIGNE ÉCRITE PAR UN IMPORT DOIT AVOIR SON ENTRÉE AU JOURNAL, SINON
--    L'ANNULATION LA LAISSERAIT. Les seules exceptions légitimes sont les lignes
--    posées par une MIGRATION, hors import : les 8 adresses et les 11 liens
--    AGENCE/SOCIETE de la 0026. Elles ne doivent JUSTEMENT pas être défaites par
--    un retour arrière d'import — ce sont des données de socle.
--    Ma première version de ce contrôle oubliait `adresses` et `objet_adresses`
--    dans la somme et annonçait un écart de 2 058 : le défaut était dans la
--    formule, pas dans la base.
UNION ALL SELECT 'lignes dimport SANS entree au journal', (
   SELECT COUNT(*) FROM adresses a WHERE NOT EXISTS (
     SELECT 1 FROM imports_journal j JOIN imports i ON i.id=j.id_import
      WHERE i.annule_le IS NULL AND j.table_cible='adresses' AND j.id_ligne=a.id)) - 8
UNION ALL SELECT 'liens dadresse hors journal (hors socle)', (
   SELECT COUNT(*) FROM objet_adresses o
    WHERE o.objet_type NOT IN ('AGENCE','SOCIETE') AND NOT EXISTS (
     SELECT 1 FROM imports_journal j JOIN imports i ON i.id=j.id_import
      WHERE i.annule_le IS NULL AND j.table_cible='objet_adresses' AND j.id_ligne=o.id));
