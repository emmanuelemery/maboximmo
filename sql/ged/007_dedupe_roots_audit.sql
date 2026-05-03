-- ════════════════════════════════════════════════════════════════════════
-- 007 — AUDIT doublons racines ged_folders (read-only, SAFE)
-- ════════════════════════════════════════════════════════════════════════
-- À exécuter dans phpMyAdmin (ou mysql CLI). Aucune modification BDD.
--
-- Objectif : identifier l'ampleur du problème "doublons racines"
--   (parent_id NULL non protégé par UNIQUE, et parent_id=0 fantômes).
--
-- Phase 2 du plan anti-doublons (Phase 1 = ged_level_codes, déjà livrée).
-- ════════════════════════════════════════════════════════════════════════

-- ─────────────────────────────────────────────────────────────────────
-- 0) Snapshot AVANT cleanup (CRITIQUE — à faire en CLI Hostinger)
-- ─────────────────────────────────────────────────────────────────────
-- mysqldump --single-transaction u630423897_maboximmo \
--   ged_folders ged_documents ged_document_links \
--   > backup_dedupe_folders.sql

-- ─────────────────────────────────────────────────────────────────────
-- 1) Version MySQL (doit être >= 5.7.6 pour colonnes générées STORED)
-- ─────────────────────────────────────────────────────────────────────
SELECT VERSION() AS mysql_version, @@sql_mode AS sql_mode;

-- ─────────────────────────────────────────────────────────────────────
-- 2) Schéma actuel
-- ─────────────────────────────────────────────────────────────────────
SHOW CREATE TABLE `ged_folders`;
SHOW INDEX FROM `ged_folders`;

-- ─────────────────────────────────────────────────────────────────────
-- 3) Doublons racines STRICTS (parent_id NULL, non archivés)
-- ─────────────────────────────────────────────────────────────────────
-- Groupe par tenant + slug : si > 1 → doublon
SELECT
  COALESCE(tenant_id, 0)               AS tenant_id_n,
  slug,
  COUNT(*)                             AS nb_doublons,
  GROUP_CONCAT(id ORDER BY id ASC)     AS ids,
  GROUP_CONCAT(uuid SEPARATOR ' | ')   AS uuids,
  GROUP_CONCAT(name_display SEPARATOR ' | ') AS noms,
  GROUP_CONCAT(is_system)              AS is_system_list,
  GROUP_CONCAT(IFNULL(gdrive_folder_id, 'NULL') SEPARATOR ' | ') AS gdrive_ids,
  GROUP_CONCAT(DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') SEPARATOR ' | ') AS created
FROM `ged_folders`
WHERE parent_id IS NULL
  AND is_archived = 0
GROUP BY tenant_id_n, slug
HAVING COUNT(*) > 1
ORDER BY tenant_id_n, slug;

-- ─────────────────────────────────────────────────────────────────────
-- 4) Doublons racines incluant les ARCHIVÉS (informatif)
-- ─────────────────────────────────────────────────────────────────────
SELECT
  COALESCE(tenant_id, 0) AS tenant_id_n,
  slug,
  SUM(is_archived = 0) AS nb_actifs,
  SUM(is_archived = 1) AS nb_archives,
  COUNT(*)             AS nb_total
FROM `ged_folders`
WHERE parent_id IS NULL
GROUP BY tenant_id_n, slug
HAVING COUNT(*) > 1
ORDER BY tenant_id_n, slug;

-- ─────────────────────────────────────────────────────────────────────
-- 5) Racines fantômes (parent_id = 0 au lieu de NULL)
-- ─────────────────────────────────────────────────────────────────────
SELECT
  id, uuid, tenant_id, slug, name_display, is_system, is_archived,
  gdrive_folder_id, created_at
FROM `ged_folders`
WHERE parent_id = 0
ORDER BY id;

SELECT COUNT(*) AS total_racines_fantomes
FROM `ged_folders`
WHERE parent_id = 0;

-- ─────────────────────────────────────────────────────────────────────
-- 6) Estimation impact rebranchement (enfants par dossier dupliqué)
-- ─────────────────────────────────────────────────────────────────────
-- Pour chaque dossier impliqué dans un doublon racine, combien d'enfants directs ?
SELECT
  f.id           AS dossier_id,
  f.slug,
  f.name_display,
  COUNT(c.id)    AS nb_enfants_directs
FROM `ged_folders` f
LEFT JOIN `ged_folders` c ON c.parent_id = f.id
WHERE f.id IN (
  SELECT g.id FROM `ged_folders` g
  WHERE g.parent_id IS NULL AND g.is_archived = 0
    AND EXISTS (
      SELECT 1 FROM `ged_folders` g2
      WHERE g2.parent_id IS NULL
        AND g2.is_archived = 0
        AND g2.id <> g.id
        AND g2.slug = g.slug
        AND IFNULL(g2.tenant_id, 0) = IFNULL(g.tenant_id, 0)
    )
)
GROUP BY f.id, f.slug, f.name_display
ORDER BY nb_enfants_directs DESC, f.id;

-- ─────────────────────────────────────────────────────────────────────
-- 7) Estimation impact rebranchement (documents par dossier dupliqué)
-- ─────────────────────────────────────────────────────────────────────
SELECT
  d.folder_id,
  COUNT(*)              AS nb_documents,
  f.slug                AS folder_slug,
  f.name_display        AS folder_name
FROM `ged_documents` d
LEFT JOIN `ged_folders` f ON f.id = d.folder_id
WHERE d.folder_id IN (
  SELECT g.id FROM `ged_folders` g
  WHERE g.parent_id IS NULL AND g.is_archived = 0
    AND EXISTS (
      SELECT 1 FROM `ged_folders` g2
      WHERE g2.parent_id IS NULL
        AND g2.is_archived = 0
        AND g2.id <> g.id
        AND g2.slug = g.slug
        AND IFNULL(g2.tenant_id, 0) = IFNULL(g.tenant_id, 0)
    )
)
GROUP BY d.folder_id, f.slug, f.name_display
ORDER BY nb_documents DESC;

-- ─────────────────────────────────────────────────────────────────────
-- 8) Identification du canonical pour chaque groupe doublon
-- ─────────────────────────────────────────────────────────────────────
-- Stratégie de priorité (la même que sera appliquée par 008_cleanup) :
--   1) is_system = 1
--   2) gdrive_folder_id IS NOT NULL
--   3) MIN(id)
SELECT
  c.tenant_id_n,
  c.slug,
  c.nb,
  c.ids_concernes,
  -- canonical = priorité is_system, puis gdrive, puis id min
  (SELECT id FROM ged_folders f
   WHERE f.parent_id IS NULL
     AND f.is_archived = 0
     AND f.slug = c.slug
     AND IFNULL(f.tenant_id, 0) = c.tenant_id_n
   ORDER BY
     f.is_system DESC,
     (f.gdrive_folder_id IS NOT NULL) DESC,
     f.id ASC
   LIMIT 1
  ) AS id_canonical
FROM (
  SELECT
    IFNULL(tenant_id, 0) AS tenant_id_n,
    slug,
    COUNT(*) AS nb,
    GROUP_CONCAT(id ORDER BY id ASC) AS ids_concernes
  FROM ged_folders
  WHERE parent_id IS NULL AND is_archived = 0
  GROUP BY tenant_id_n, slug
  HAVING COUNT(*) > 1
) c;

-- ─────────────────────────────────────────────────────────────────────
-- 9) Compteur ged_documents.folder_id pointant vers id potentiellement
--    archivable (best-effort, compte tous les documents impactés)
-- ─────────────────────────────────────────────────────────────────────
SELECT COUNT(DISTINCT d.folder_id) AS nb_folders_avec_docs,
       COUNT(*)                    AS nb_total_docs_impactes
FROM ged_documents d
WHERE d.folder_id IN (
  SELECT g.id FROM ged_folders g
  WHERE g.parent_id IS NULL AND g.is_archived = 0
    AND EXISTS (
      SELECT 1 FROM ged_folders g2
      WHERE g2.parent_id IS NULL AND g2.is_archived = 0
        AND g2.id <> g.id AND g2.slug = g.slug
        AND IFNULL(g2.tenant_id, 0) = IFNULL(g.tenant_id, 0)
    )
);
