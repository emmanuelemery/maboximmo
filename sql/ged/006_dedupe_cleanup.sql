-- ════════════════════════════════════════════════════════════════════════
-- 006 — CLEANUP doublons GED (DESTRUCTIF — backup OBLIGATOIRE avant)
-- ════════════════════════════════════════════════════════════════════════
-- ⚠️ PRÉ-REQUIS :
--   1. Avoir lancé 005_dedupe_audit.sql et lu le résultat
--   2. Avoir un dump SQL frais (backup_dedupe_YYYYMMDD_HHMM.sql)
--   3. Avoir consulté l'équipe si > 50 doublons inattendus
--
-- Ce script :
--   - Normalise parent_n* (NULL → '') et tenant_id (NULL → 0) sur ged_level_codes
--   - Identifie le canonical de chaque groupe dupliqué et SUPPRIME les autres
--   - Corrige parent_id=0 → NULL dans ged_folders (racines fantômes)
--   - Identifie les doublons (parent_id, slug) dans ged_folders, rebranche
--     les enfants + ged_documents.folder_id, puis archive les dupliqués
--
-- Toutes les opérations sont LOGGUEES (SELECT avant DELETE/UPDATE) pour
-- traçabilité dans la sortie phpMyAdmin.
-- ════════════════════════════════════════════════════════════════════════

START TRANSACTION;

-- ─────────────────────────────────────────────────────────────────────────
-- ÉTAPE 1 — Normalisation ged_level_codes (NULL → '' et NULL → 0)
-- ─────────────────────────────────────────────────────────────────────────
-- Avant cleanup, on doit harmoniser pour que GROUP BY fonctionne identiquement.

SELECT '── 1. Normalisation NULL → vide/0 ───' AS step;

UPDATE ged_level_codes SET parent_n1 = '' WHERE parent_n1 IS NULL;
UPDATE ged_level_codes SET parent_n2 = '' WHERE parent_n2 IS NULL;
UPDATE ged_level_codes SET parent_n3 = '' WHERE parent_n3 IS NULL;
UPDATE ged_level_codes SET parent_n4 = '' WHERE parent_n4 IS NULL;
UPDATE ged_level_codes SET tenant_id = 0 WHERE tenant_id IS NULL;

-- Aussi : trim des espaces parasites éventuels
UPDATE ged_level_codes
SET parent_n1 = TRIM(parent_n1),
    parent_n2 = TRIM(parent_n2),
    parent_n3 = TRIM(parent_n3),
    parent_n4 = TRIM(parent_n4),
    code      = TRIM(code);

-- ─────────────────────────────────────────────────────────────────────────
-- ÉTAPE 2 — Identifier les ids canonical (à garder) pour chaque doublon
-- ─────────────────────────────────────────────────────────────────────────
-- Stratégie : pour chaque groupe (tenant + level + parents + code) :
--   - on garde l'id ACTIF avec position MIN
--   - sinon l'id MIN
-- Les autres ids du groupe seront supprimés en étape 4.

SELECT '── 2. Identification canonical (ids à GARDER) ───' AS step;

DROP TEMPORARY TABLE IF EXISTS _ged_dedupe_canonical;
CREATE TEMPORARY TABLE _ged_dedupe_canonical AS
SELECT
  c.tenant_id, c.level_number,
  c.parent_n1, c.parent_n2, c.parent_n3, c.parent_n4,
  c.code,
  (SELECT id FROM ged_level_codes lc
   WHERE lc.tenant_id   = c.tenant_id
     AND lc.level_number = c.level_number
     AND lc.parent_n1   = c.parent_n1
     AND lc.parent_n2   = c.parent_n2
     AND lc.parent_n3   = c.parent_n3
     AND lc.parent_n4   = c.parent_n4
     AND lc.code        = c.code
   ORDER BY lc.is_active DESC, lc.position ASC, lc.id ASC
   LIMIT 1
  ) AS id_canonical
FROM (
  SELECT tenant_id, level_number, parent_n1, parent_n2, parent_n3, parent_n4, code
  FROM ged_level_codes
  GROUP BY tenant_id, level_number, parent_n1, parent_n2, parent_n3, parent_n4, code
  HAVING COUNT(*) > 1
) c;

SELECT
  COUNT(*) AS nb_groupes_avec_doublons,
  COUNT(DISTINCT id_canonical) AS nb_canonicals_distincts
FROM _ged_dedupe_canonical;

-- ─────────────────────────────────────────────────────────────────────────
-- ÉTAPE 3 — Liste les ids à SUPPRIMER (avant DELETE, pour log)
-- ─────────────────────────────────────────────────────────────────────────

SELECT '── 3. Liste des ids à SUPPRIMER (à logger) ───' AS step;

DROP TEMPORARY TABLE IF EXISTS _ged_dedupe_to_delete;
CREATE TEMPORARY TABLE _ged_dedupe_to_delete AS
SELECT lc.id, lc.code, lc.label, lc.level_number,
       lc.parent_n1, lc.parent_n2, lc.parent_n3, lc.parent_n4,
       c.id_canonical
FROM ged_level_codes lc
INNER JOIN _ged_dedupe_canonical c
  ON c.tenant_id   = lc.tenant_id
 AND c.level_number = lc.level_number
 AND c.parent_n1   = lc.parent_n1
 AND c.parent_n2   = lc.parent_n2
 AND c.parent_n3   = lc.parent_n3
 AND c.parent_n4   = lc.parent_n4
 AND c.code        = lc.code
WHERE lc.id <> c.id_canonical;

-- Affiche le détail (à conserver dans le rapport post-cleanup)
SELECT * FROM _ged_dedupe_to_delete ORDER BY level_number, parent_n1, parent_n2, parent_n3, parent_n4, code, id;

SELECT COUNT(*) AS total_a_supprimer FROM _ged_dedupe_to_delete;

-- ─────────────────────────────────────────────────────────────────────────
-- ÉTAPE 4 — Suppression effective des doublons ged_level_codes
-- ─────────────────────────────────────────────────────────────────────────

SELECT '── 4. DELETE doublons ged_level_codes ───' AS step;

DELETE FROM ged_level_codes
WHERE id IN (SELECT id FROM _ged_dedupe_to_delete);

SELECT ROW_COUNT() AS nb_lignes_supprimees;

-- Vérification post-DELETE : doit retourner 0 ligne
SELECT '── 4bis. Vérif post-DELETE (doit être vide) ───' AS step;
SELECT
  tenant_id, level_number, parent_n1, parent_n2, parent_n3, parent_n4, code,
  COUNT(*) AS nb
FROM ged_level_codes
GROUP BY tenant_id, level_number, parent_n1, parent_n2, parent_n3, parent_n4, code
HAVING COUNT(*) > 1;

-- ─────────────────────────────────────────────────────────────────────────
-- ÉTAPE 5 — Cleanup ged_folders : racines fantômes (parent_id = 0)
-- ─────────────────────────────────────────────────────────────────────────

SELECT '── 5. Racines parent_id=0 → NULL ───' AS step;

-- Liste avant correction
SELECT id, name_display, slug, parent_id, is_archived
FROM ged_folders
WHERE parent_id = 0;

UPDATE ged_folders SET parent_id = NULL WHERE parent_id = 0;
SELECT ROW_COUNT() AS nb_racines_corrigees;

-- ─────────────────────────────────────────────────────────────────────────
-- ÉTAPE 6 — Identifier doublons ged_folders (même parent_id + même slug)
-- ─────────────────────────────────────────────────────────────────────────

SELECT '── 6. Identification doublons ged_folders ───' AS step;

DROP TEMPORARY TABLE IF EXISTS _ged_folders_canonical;
CREATE TEMPORARY TABLE _ged_folders_canonical AS
SELECT
  f.parent_id, f.slug,
  (SELECT id FROM ged_folders f2
   WHERE (f2.parent_id <=> f.parent_id)  -- <=> : NULL-safe equality
     AND f2.slug = f.slug
   ORDER BY f2.is_archived ASC, f2.id ASC  -- non-archivé prioritaire, puis id min
   LIMIT 1) AS id_canonical
FROM (
  SELECT parent_id, slug
  FROM ged_folders
  GROUP BY parent_id, slug
  HAVING COUNT(*) > 1
) f;

SELECT COUNT(*) AS nb_groupes_folders_dupliques FROM _ged_folders_canonical;

DROP TEMPORARY TABLE IF EXISTS _ged_folders_to_archive;
CREATE TEMPORARY TABLE _ged_folders_to_archive AS
SELECT f.id, f.name_display, f.slug, f.parent_id, c.id_canonical
FROM ged_folders f
INNER JOIN _ged_folders_canonical c
  ON (c.parent_id <=> f.parent_id)
 AND c.slug = f.slug
WHERE f.id <> c.id_canonical;

SELECT * FROM _ged_folders_to_archive ORDER BY parent_id, slug, id;

-- ─────────────────────────────────────────────────────────────────────────
-- ÉTAPE 7 — Rebranchement enfants + documents
-- ─────────────────────────────────────────────────────────────────────────

SELECT '── 7a. Rebranchement enfants ged_folders ───' AS step;

-- Pour chaque dossier dupliqué, ses enfants sont déplacés vers le canonical
UPDATE ged_folders f
INNER JOIN _ged_folders_to_archive a ON f.parent_id = a.id
SET f.parent_id = a.id_canonical;
SELECT ROW_COUNT() AS nb_enfants_rebranches;

SELECT '── 7b. Rebranchement ged_documents.folder_id ───' AS step;

UPDATE ged_documents d
INNER JOIN _ged_folders_to_archive a ON d.folder_id = a.id
SET d.folder_id = a.id_canonical;
SELECT ROW_COUNT() AS nb_documents_rebranches;

-- Best-effort : si d'autres tables référencent ged_folders.id (à adapter selon le schéma prod)
-- Décommenter si besoin :
-- UPDATE ged_import_items i INNER JOIN _ged_folders_to_archive a ON i.target_folder_id = a.id
--   SET i.target_folder_id = a.id_canonical;

-- ─────────────────────────────────────────────────────────────────────────
-- ÉTAPE 8 — Archivage (soft) des dossiers dupliqués
-- ─────────────────────────────────────────────────────────────────────────

SELECT '── 8. Archivage soft des dossiers dupliqués ───' AS step;

UPDATE ged_folders
SET is_archived = 1
WHERE id IN (SELECT id FROM _ged_folders_to_archive);
SELECT ROW_COUNT() AS nb_folders_archives;

-- ─────────────────────────────────────────────────────────────────────────
-- ÉTAPE 9 — Vérifications finales
-- ─────────────────────────────────────────────────────────────────────────

SELECT '── 9. Vérifs finales ───' AS step;

-- Plus aucun doublon ged_level_codes (doit être vide)
SELECT 'level_codes_dupes_residuels' AS check_name, COUNT(*) AS nb FROM (
  SELECT 1 FROM ged_level_codes
  GROUP BY tenant_id, level_number, parent_n1, parent_n2, parent_n3, parent_n4, code
  HAVING COUNT(*) > 1
) x;

-- Plus aucun parent_id=0 dans ged_folders (doit être 0)
SELECT 'folders_parent_zero' AS check_name, COUNT(*) AS nb FROM ged_folders WHERE parent_id = 0;

-- Plus aucun doublon visible (parent_id, slug) hors archivés (doit être vide)
SELECT 'folders_dupes_visibles' AS check_name, COUNT(*) AS nb FROM (
  SELECT 1 FROM ged_folders
  WHERE is_archived = 0
  GROUP BY parent_id, slug
  HAVING COUNT(*) > 1
) x;

-- ─────────────────────────────────────────────────────────────────────────
-- ÉTAPE 10 — Décision de commit
-- ─────────────────────────────────────────────────────────────────────────
-- Si tout est OK ci-dessus :
--   COMMIT;
-- Si quelque chose est anormal :
--   ROLLBACK;
-- Le COMMIT/ROLLBACK est laissé MANUEL pour relire les sorties avant validation.
-- ════════════════════════════════════════════════════════════════════════

-- ⚠️ NE PAS DÉCOMMENTER AUTOMATIQUEMENT — choisir l'un des deux :
-- COMMIT;
-- ROLLBACK;
