-- ════════════════════════════════════════════════════════════════════════
-- 005 — AUDIT doublons GED (read-only, SAFE à exécuter sur prod)
-- ════════════════════════════════════════════════════════════════════════
-- À exécuter dans phpMyAdmin (Hostinger → Bases de données → phpMyAdmin)
-- ou via mysql CLI. Aucune modification BDD ici.
--
-- Sortie : tableaux récapitulatifs de tous les doublons à nettoyer.
-- ════════════════════════════════════════════════════════════════════════

-- ─────────────────────────────────────────────────────────────────────────
-- ÉTAPE 0 — Snapshot AVANT cleanup (CRITIQUE !!)
-- ─────────────────────────────────────────────────────────────────────────
-- À faire en CLI Hostinger ou via phpMyAdmin → Exporter :
--   mysqldump --single-transaction u630423897_maboximmo \
--     ged_level_codes ged_folders ged_documents \
--     > backup_dedupe_$(date +%Y%m%d_%H%M).sql
-- (à conserver impérativement avant l'étape 006 cleanup)

-- ─────────────────────────────────────────────────────────────────────────
-- A.1 — Compter les doublons ged_level_codes (NULL == '')
-- ─────────────────────────────────────────────────────────────────────────
-- Regroupe par tenant, level, parents normalisés (NULL→''), code.
-- Tout groupe avec count > 1 = doublon à fusionner.

SELECT
  COALESCE(tenant_id, 0)                AS tenant_id_n,
  level_number,
  COALESCE(parent_n1, '')               AS p1,
  COALESCE(parent_n2, '')               AS p2,
  COALESCE(parent_n3, '')               AS p3,
  COALESCE(parent_n4, '')               AS p4,
  code,
  COUNT(*)                              AS nb_doublons,
  GROUP_CONCAT(id ORDER BY id ASC)      AS ids_concernes,
  GROUP_CONCAT(label SEPARATOR ' | ')   AS labels,
  GROUP_CONCAT(is_active)               AS is_active_list
FROM ged_level_codes
GROUP BY tenant_id_n, level_number, p1, p2, p3, p4, code
HAVING COUNT(*) > 1
ORDER BY level_number ASC, p1, p2, p3, p4, code;

-- ─────────────────────────────────────────────────────────────────────────
-- A.2 — Total doublons levels par niveau
-- ─────────────────────────────────────────────────────────────────────────
SELECT
  level_number,
  COUNT(*) - COUNT(DISTINCT
    CONCAT(
      COALESCE(tenant_id, 0), '|',
      level_number, '|',
      COALESCE(parent_n1, ''), '|',
      COALESCE(parent_n2, ''), '|',
      COALESCE(parent_n3, ''), '|',
      COALESCE(parent_n4, ''), '|',
      code
    )
  ) AS nb_doublons_a_supprimer
FROM ged_level_codes
GROUP BY level_number
ORDER BY level_number;

-- ─────────────────────────────────────────────────────────────────────────
-- A.3 — Combien de NULL vs '' actuellement dans parent_n*
-- ─────────────────────────────────────────────────────────────────────────
SELECT
  SUM(parent_n1 IS NULL)         AS p1_nulls, SUM(parent_n1 = '')         AS p1_empty,
  SUM(parent_n2 IS NULL)         AS p2_nulls, SUM(parent_n2 = '')         AS p2_empty,
  SUM(parent_n3 IS NULL)         AS p3_nulls, SUM(parent_n3 = '')         AS p3_empty,
  SUM(parent_n4 IS NULL)         AS p4_nulls, SUM(parent_n4 = '')         AS p4_empty,
  SUM(tenant_id IS NULL)         AS tenant_nulls
FROM ged_level_codes;

-- ─────────────────────────────────────────────────────────────────────────
-- B.1 — Racines fantômes ged_folders (parent_id = 0 vs NULL)
-- ─────────────────────────────────────────────────────────────────────────
-- ged_get_tree() normalise NULL → 0 côté lecture, mais en BDD on doit avoir
-- NULL pour les racines. Toute ligne parent_id=0 = bug à corriger.

SELECT
  parent_id,
  COUNT(*) AS nb,
  GROUP_CONCAT(id ORDER BY id ASC) AS ids,
  GROUP_CONCAT(name_display SEPARATOR ' | ') AS noms
FROM ged_folders
WHERE parent_id = 0 OR parent_id IS NULL
GROUP BY parent_id
ORDER BY parent_id;

-- ─────────────────────────────────────────────────────────────────────────
-- B.2 — Doublons exacts ged_folders (même parent_id + même slug)
-- ─────────────────────────────────────────────────────────────────────────
-- Schéma 20260502_ged_v1_01_folders.php déclare UNIQUE KEY
-- uk_ged_folders_parent_slug(parent_id, slug). Mais : si parent_id IS NULL,
-- MySQL autorise les doublons sur cette UNIQUE → racines dupliquées.

SELECT
  parent_id,
  slug,
  COUNT(*) AS nb_doublons,
  GROUP_CONCAT(id ORDER BY id ASC) AS ids,
  GROUP_CONCAT(name_display SEPARATOR ' | ') AS noms,
  GROUP_CONCAT(is_archived) AS archived_list
FROM ged_folders
GROUP BY parent_id, slug
HAVING COUNT(*) > 1
ORDER BY parent_id, slug;

-- ─────────────────────────────────────────────────────────────────────────
-- B.3 — Doublons "visuels" (même parent + même name_display)
-- ─────────────────────────────────────────────────────────────────────────
-- Informatif : peut révéler des slugs différents mais noms identiques
-- (exemple : "Travaux" et "TRAVAUX " avec espace) → souvent OK mais à voir.

SELECT
  COALESCE(parent_id, 0) AS parent_id_n,
  name_display,
  COUNT(*) AS nb,
  GROUP_CONCAT(id ORDER BY id ASC) AS ids,
  GROUP_CONCAT(slug SEPARATOR ' | ') AS slugs
FROM ged_folders
GROUP BY parent_id_n, name_display
HAVING COUNT(*) > 1
ORDER BY parent_id_n, name_display;

-- ─────────────────────────────────────────────────────────────────────────
-- B.4 — Documents impactés par les doublons folders
-- ─────────────────────────────────────────────────────────────────────────
-- Combien de ged_documents pointent sur les ids dupliqués (toutes occurrences)
SELECT
  d.folder_id,
  COUNT(*) AS nb_documents,
  f.name_display,
  f.slug,
  f.parent_id
FROM ged_documents d
LEFT JOIN ged_folders f ON f.id = d.folder_id
WHERE d.folder_id IN (
  SELECT id FROM ged_folders f2
  WHERE EXISTS (
    SELECT 1 FROM ged_folders f3
    WHERE f3.id <> f2.id
      AND ((f3.parent_id IS NULL AND f2.parent_id IS NULL)
           OR f3.parent_id = f2.parent_id)
      AND f3.slug = f2.slug
  )
)
GROUP BY d.folder_id, f.name_display, f.slug, f.parent_id
ORDER BY nb_documents DESC, d.folder_id;

-- ─────────────────────────────────────────────────────────────────────────
-- B.5 — Présence de l'UNIQUE KEY sur ged_folders (vérification schéma prod)
-- ─────────────────────────────────────────────────────────────────────────
SHOW INDEX FROM ged_folders WHERE Key_name LIKE 'uk_%';

-- ─────────────────────────────────────────────────────────────────────────
-- B.6 — Présence de l'UNIQUE KEY sur ged_level_codes (vérification schéma prod)
-- ─────────────────────────────────────────────────────────────────────────
SHOW INDEX FROM ged_level_codes WHERE Key_name LIKE 'uk_%';

-- ─────────────────────────────────────────────────────────────────────────
-- A.4 — Détail par doublon avec id à GARDER (canonical) et ids à SUPPRIMER
-- ─────────────────────────────────────────────────────────────────────────
-- Stratégie : on garde l'id ACTIF avec position min ; sinon l'id min.
-- Cette query est purement informative, le DELETE réel est dans 006.

SELECT
  c.tenant_id_n,
  c.level_number,
  c.p1, c.p2, c.p3, c.p4, c.code,
  c.nb_doublons,
  c.ids_concernes,
  -- canonical = premier ACTIF par position, sinon premier id
  (SELECT id FROM ged_level_codes lc
   WHERE COALESCE(lc.tenant_id, 0) = c.tenant_id_n
     AND lc.level_number = c.level_number
     AND COALESCE(lc.parent_n1, '') = c.p1
     AND COALESCE(lc.parent_n2, '') = c.p2
     AND COALESCE(lc.parent_n3, '') = c.p3
     AND COALESCE(lc.parent_n4, '') = c.p4
     AND lc.code = c.code
   ORDER BY lc.is_active DESC, lc.position ASC, lc.id ASC
   LIMIT 1) AS id_canonical
FROM (
  SELECT
    COALESCE(tenant_id, 0) AS tenant_id_n,
    level_number,
    COALESCE(parent_n1, '') AS p1,
    COALESCE(parent_n2, '') AS p2,
    COALESCE(parent_n3, '') AS p3,
    COALESCE(parent_n4, '') AS p4,
    code,
    COUNT(*) AS nb_doublons,
    GROUP_CONCAT(id ORDER BY id ASC) AS ids_concernes
  FROM ged_level_codes
  GROUP BY tenant_id_n, level_number, p1, p2, p3, p4, code
  HAVING COUNT(*) > 1
) c;
