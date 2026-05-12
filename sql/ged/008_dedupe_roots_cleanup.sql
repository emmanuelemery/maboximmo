-- ════════════════════════════════════════════════════════════════════════
-- 008 — CLEANUP doublons racines ged_folders (DESTRUCTIF — backup obligatoire)
-- ════════════════════════════════════════════════════════════════════════
-- ⚠️ PRÉ-REQUIS :
--   1. Avoir lancé 007_dedupe_roots_audit.sql et lu le résultat
--   2. Avoir un dump SQL frais (mysqldump ged_folders + ged_documents)
--   3. Avoir confirmé les "id_canonical" attendus pour chaque groupe doublon
--
-- Ce script :
--   - Étape 1 : corrige parent_id=0 → NULL (racines fantômes)
--   - Étape 2 : identifie le canonical (priorité is_system / gdrive / id min)
--   - Étape 3 : rebranche enfants ged_folders + documents ged_documents
--   - Étape 4 : archive soft (is_archived=1) les doublons (NE supprime PAS)
--   - Étape 5 : vérifications finales
--
-- COMMIT/ROLLBACK manuel à la fin selon les vérifs.
-- ════════════════════════════════════════════════════════════════════════

START TRANSACTION;

-- ─────────────────────────────────────────────────────────────────────
-- ÉTAPE 1 — Racines fantômes parent_id=0 → NULL
-- ─────────────────────────────────────────────────────────────────────
SELECT '── 1. Fix parent_id=0 → NULL ───' AS step;

-- Liste avant correction (pour log)
SELECT id, name_display, slug, parent_id, is_archived
FROM ged_folders
WHERE parent_id = 0;

UPDATE ged_folders SET parent_id = NULL WHERE parent_id = 0;
SELECT ROW_COUNT() AS nb_racines_corrigees;

-- ─────────────────────────────────────────────────────────────────────
-- ÉTAPE 2 — Identification du canonical pour chaque groupe doublon
-- ─────────────────────────────────────────────────────────────────────
-- Stratégie : pour chaque (tenant_id, slug) racine non archivée avec > 1 ligne :
--   - canonical = première ligne par : is_system DESC, gdrive_folder_id IS NOT NULL DESC, id ASC

SELECT '── 2. Identification canonical ───' AS step;

DROP TEMPORARY TABLE IF EXISTS _ged_roots_canonical;
CREATE TEMPORARY TABLE _ged_roots_canonical AS
SELECT
  c.tenant_id_n,
  c.slug,
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
  SELECT IFNULL(tenant_id, 0) AS tenant_id_n, slug
  FROM ged_folders
  WHERE parent_id IS NULL AND is_archived = 0
  GROUP BY tenant_id_n, slug
  HAVING COUNT(*) > 1
) c;

SELECT COUNT(*) AS nb_groupes_dupliques FROM _ged_roots_canonical;
SELECT * FROM _ged_roots_canonical ORDER BY tenant_id_n, slug;

-- ─────────────────────────────────────────────────────────────────────
-- ÉTAPE 3 — Liste des ids à ARCHIVER (autres que canonical)
-- ─────────────────────────────────────────────────────────────────────
SELECT '── 3. Liste ids à archiver ───' AS step;

DROP TEMPORARY TABLE IF EXISTS _ged_roots_to_archive;
CREATE TEMPORARY TABLE _ged_roots_to_archive AS
SELECT
  f.id, f.uuid, f.name_display, f.slug, f.tenant_id, f.is_system,
  f.gdrive_folder_id, c.id_canonical
FROM ged_folders f
INNER JOIN _ged_roots_canonical c
  ON c.tenant_id_n = IFNULL(f.tenant_id, 0)
 AND c.slug        = f.slug
WHERE f.parent_id IS NULL
  AND f.is_archived = 0
  AND f.id <> c.id_canonical;

SELECT * FROM _ged_roots_to_archive ORDER BY tenant_id, slug, id;
SELECT COUNT(*) AS nb_a_archiver FROM _ged_roots_to_archive;

-- ─────────────────────────────────────────────────────────────────────
-- ÉTAPE 4 — Rebranchement enfants ged_folders
-- ─────────────────────────────────────────────────────────────────────
SELECT '── 4a. Rebranchement enfants directs ───' AS step;

-- Compter avant
SELECT COUNT(*) AS nb_enfants_a_rebrancher
FROM ged_folders f
INNER JOIN _ged_roots_to_archive a ON f.parent_id = a.id;

UPDATE ged_folders f
INNER JOIN _ged_roots_to_archive a ON f.parent_id = a.id
SET f.parent_id = a.id_canonical;
SELECT ROW_COUNT() AS nb_enfants_rebranches;

-- ─────────────────────────────────────────────────────────────────────
-- ÉTAPE 5 — Rebranchement ged_documents.folder_id
-- ─────────────────────────────────────────────────────────────────────
SELECT '── 5. Rebranchement ged_documents.folder_id ───' AS step;

-- Compter avant
SELECT COUNT(*) AS nb_docs_a_rebrancher
FROM ged_documents d
INNER JOIN _ged_roots_to_archive a ON d.folder_id = a.id;

UPDATE ged_documents d
INNER JOIN _ged_roots_to_archive a ON d.folder_id = a.id
SET d.folder_id = a.id_canonical;
SELECT ROW_COUNT() AS nb_docs_rebranches;

-- ─────────────────────────────────────────────────────────────────────
-- ÉTAPE 5bis — Best-effort sur tables potentielles (à adapter prod)
-- ─────────────────────────────────────────────────────────────────────
-- Si d'autres tables référencent ged_folders.id, ajouter ici. Décommenter
-- les blocs ci-dessous SI les tables existent en prod.

-- ged_document_links (si la colonne folder_id existe)
-- UPDATE ged_document_links l INNER JOIN _ged_roots_to_archive a ON l.folder_id = a.id
--   SET l.folder_id = a.id_canonical;
-- SELECT ROW_COUNT() AS nb_links_rebranches;

-- ged_import_items (target_folder_id si existe)
-- UPDATE ged_import_items i INNER JOIN _ged_roots_to_archive a ON i.target_folder_id = a.id
--   SET i.target_folder_id = a.id_canonical;
-- SELECT ROW_COUNT() AS nb_items_rebranches;

-- ─────────────────────────────────────────────────────────────────────
-- ÉTAPE 6 — Archivage soft (is_archived=1) des doublons
-- ─────────────────────────────────────────────────────────────────────
-- IMPORTANT : on N'ENLEVE PAS le slug ni le parent_id. On archive seulement.
-- L'index UNIQUE strict de la migration v2_24 ne portera QUE sur is_archived=0
-- … sauf que MySQL ne supporte pas WHERE dans UNIQUE INDEX (uniquement filtered
-- index sur SQL Server). Donc on doit aussi changer le slug pour éviter conflit.

SELECT '── 6. Archivage soft + suffixage slug ───' AS step;

-- Renomme le slug avec suffixe "_archived_<id>" pour libérer la place dans l'index strict
UPDATE ged_folders f
INNER JOIN _ged_roots_to_archive a ON f.id = a.id
SET
  f.is_archived = 1,
  f.slug         = CONCAT(LEFT(f.slug, 100), '_archived_', f.id),
  f.updated_at   = NOW();

SELECT ROW_COUNT() AS nb_folders_archives;

-- ─────────────────────────────────────────────────────────────────────
-- ÉTAPE 7 — Recalcul path_cache + depth (best-effort SQL)
-- ─────────────────────────────────────────────────────────────────────
-- Note : ged_recalculate_folder_tree() (PHP) fait ça mieux, le bouton existe
--        sur /super_admin_ged_niveaux.php. À déclencher après le COMMIT.
--
-- Reset path_cache des dossiers rebranchés (force recalcul au prochain accès)
SELECT '── 7. Reset path_cache (pour force recalcul PHP) ───' AS step;
UPDATE ged_folders f
INNER JOIN (
  SELECT DISTINCT id_canonical AS id FROM _ged_roots_to_archive
  UNION
  SELECT DISTINCT parent_id FROM ged_folders WHERE parent_id IS NOT NULL
    AND parent_id IN (SELECT id_canonical FROM _ged_roots_to_archive)
) c ON f.id = c.id
SET f.path_cache = '';
SELECT ROW_COUNT() AS nb_path_cache_reset;

-- ─────────────────────────────────────────────────────────────────────
-- ÉTAPE 8 — Vérifications finales
-- ─────────────────────────────────────────────────────────────────────
SELECT '── 8. Vérifications finales ───' AS step;

-- Doit retourner 0 : plus aucun doublon racine actif
SELECT 'racines_dupliquees_residuelles' AS check_name, COUNT(*) AS nb FROM (
  SELECT 1 FROM ged_folders
  WHERE parent_id IS NULL AND is_archived = 0
  GROUP BY IFNULL(tenant_id, 0), slug
  HAVING COUNT(*) > 1
) x;

-- Doit retourner 0 : plus aucun parent_id=0
SELECT 'parent_id_zero' AS check_name, COUNT(*) AS nb
FROM ged_folders WHERE parent_id = 0;

-- Doit retourner 0 : aucun document orphelin créé par le rebranchement
SELECT 'docs_orphelins' AS check_name, COUNT(*) AS nb
FROM ged_documents d
LEFT JOIN ged_folders f ON f.id = d.folder_id
WHERE d.folder_id IS NOT NULL AND f.id IS NULL;

-- ─────────────────────────────────────────────────────────────────────
-- ÉTAPE 9 — Décision COMMIT / ROLLBACK
-- ─────────────────────────────────────────────────────────────────────
-- Si toutes les vérifs ci-dessus retournent 0, lancer manuellement :
--   COMMIT;
-- Sinon :
--   ROLLBACK;
-- ════════════════════════════════════════════════════════════════════════

-- ⚠️ NE PAS DÉCOMMENTER AUTOMATIQUEMENT :
-- COMMIT;
-- ROLLBACK;
