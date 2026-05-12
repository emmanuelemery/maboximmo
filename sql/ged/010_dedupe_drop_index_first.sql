-- ════════════════════════════════════════════════════════════════════════
-- 010 — CLEANUP COMPLET avec DROP+RECREATE INDEX (corrige bug 009)
-- ════════════════════════════════════════════════════════════════════════
-- Le script 009 plantait sur "Duplicata 0-1-----01_AGENCE" car l'index
-- UNIQUE existait encore quand on tentait UPDATE tenant_id NULL → 0.
--
-- Solution : DROP l'index UNIQUE D'ABORD, puis libre de UPDATE / DELETE,
-- puis RECREATE l'index UNIQUE à la fin (avec données propres).
--
-- ⚠️ Les DDL (DROP/ADD INDEX) font un COMMIT implicite en MariaDB. Donc on
-- ne peut pas envelopper tout dans une seule TRANSACTION. C'est OK car :
--   - Chaque étape valide est sauvegardée immédiatement
--   - En cas d'erreur, on s'arrête (rien d'irrécupérable car backup)
--
-- À COLLER en UNE SEULE FOIS dans phpMyAdmin > onglet SQL > Exécuter.
-- ════════════════════════════════════════════════════════════════════════

-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  PARTIE A — CLEANUP ged_level_codes                               ║
-- ╚═══════════════════════════════════════════════════════════════════╝

-- A.1 — DROP de l'UNIQUE KEY (libère les contraintes)
ALTER TABLE ged_level_codes DROP INDEX IF EXISTS uk_ged_level_codes_path;

-- A.2 — Normalisation NULL → '' / 0 (libre maintenant)
UPDATE ged_level_codes SET parent_n1 = '' WHERE parent_n1 IS NULL;
UPDATE ged_level_codes SET parent_n2 = '' WHERE parent_n2 IS NULL;
UPDATE ged_level_codes SET parent_n3 = '' WHERE parent_n3 IS NULL;
UPDATE ged_level_codes SET parent_n4 = '' WHERE parent_n4 IS NULL;
UPDATE ged_level_codes SET tenant_id = 0 WHERE tenant_id IS NULL;
UPDATE ged_level_codes
SET parent_n1 = TRIM(parent_n1), parent_n2 = TRIM(parent_n2),
    parent_n3 = TRIM(parent_n3), parent_n4 = TRIM(parent_n4),
    code      = TRIM(code);

-- A.3 — Identification canonical (id à GARDER pour chaque doublon)
DROP TEMPORARY TABLE IF EXISTS _ged_dedupe_canonical;
CREATE TEMPORARY TABLE _ged_dedupe_canonical AS
SELECT c.tenant_id, c.level_number,
       c.parent_n1, c.parent_n2, c.parent_n3, c.parent_n4, c.code,
  (SELECT id FROM ged_level_codes lc
   WHERE lc.tenant_id    = c.tenant_id   AND lc.level_number = c.level_number
     AND lc.parent_n1    = c.parent_n1   AND lc.parent_n2    = c.parent_n2
     AND lc.parent_n3    = c.parent_n3   AND lc.parent_n4    = c.parent_n4
     AND lc.code         = c.code
   ORDER BY lc.is_active DESC, lc.position ASC, lc.id ASC LIMIT 1
  ) AS id_canonical
FROM (
  SELECT tenant_id, level_number, parent_n1, parent_n2, parent_n3, parent_n4, code
  FROM ged_level_codes
  GROUP BY tenant_id, level_number, parent_n1, parent_n2, parent_n3, parent_n4, code
  HAVING COUNT(*) > 1
) c;

-- A.4 — DELETE des doublons (garde uniquement les canonical)
DELETE FROM ged_level_codes
WHERE id IN (
  SELECT id FROM (
    SELECT lc.id
    FROM ged_level_codes lc
    INNER JOIN _ged_dedupe_canonical c
      ON c.tenant_id    = lc.tenant_id   AND c.level_number = lc.level_number
     AND c.parent_n1    = lc.parent_n1   AND c.parent_n2    = lc.parent_n2
     AND c.parent_n3    = lc.parent_n3   AND c.parent_n4    = lc.parent_n4
     AND c.code         = lc.code
    WHERE lc.id <> c.id_canonical
  ) AS to_del
);

SELECT '── A. ged_level_codes nettoyés ───' AS step,
       (SELECT COUNT(*) FROM ged_level_codes) AS total_lignes_restantes,
       (SELECT COUNT(*) FROM _ged_dedupe_canonical) AS nb_groupes_dedupliqes;

-- A.5 — RECRÉE l'UNIQUE KEY (maintenant que les doublons sont supprimés)
ALTER TABLE ged_level_codes
  ADD UNIQUE KEY IF NOT EXISTS uk_ged_level_codes_path
    (tenant_id, level_number, parent_n1, parent_n2, parent_n3, parent_n4, code);


-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  PARTIE B — CLEANUP ged_folders racines                           ║
-- ╚═══════════════════════════════════════════════════════════════════╝

-- B.1 — Racines fantômes parent_id=0 → NULL
UPDATE ged_folders SET parent_id = NULL WHERE parent_id = 0;

-- B.2 — Identification canonical ged_folders
DROP TEMPORARY TABLE IF EXISTS _ged_roots_canonical;
CREATE TEMPORARY TABLE _ged_roots_canonical AS
SELECT c.tenant_id_n, c.slug,
  (SELECT id FROM ged_folders f
   WHERE f.parent_id IS NULL AND f.is_archived = 0
     AND f.slug = c.slug
     AND IFNULL(f.tenant_id, 0) = c.tenant_id_n
   ORDER BY f.is_system DESC, (f.gdrive_folder_id IS NOT NULL) DESC, f.id ASC
   LIMIT 1
  ) AS id_canonical
FROM (
  SELECT IFNULL(tenant_id, 0) AS tenant_id_n, slug
  FROM ged_folders
  WHERE parent_id IS NULL AND is_archived = 0
  GROUP BY tenant_id_n, slug
  HAVING COUNT(*) > 1
) c;

-- B.3 — Liste ids folders à archiver
DROP TEMPORARY TABLE IF EXISTS _ged_roots_to_archive;
CREATE TEMPORARY TABLE _ged_roots_to_archive AS
SELECT f.id, c.id_canonical
FROM ged_folders f
INNER JOIN _ged_roots_canonical c
  ON c.tenant_id_n = IFNULL(f.tenant_id, 0)
 AND c.slug        = f.slug
WHERE f.parent_id IS NULL AND f.is_archived = 0
  AND f.id <> c.id_canonical;

-- B.4 — Rebranchement enfants
UPDATE ged_folders f
INNER JOIN _ged_roots_to_archive a ON f.parent_id = a.id
SET f.parent_id = a.id_canonical;

-- B.5 — Rebranchement documents
UPDATE ged_documents d
INNER JOIN _ged_roots_to_archive a ON d.folder_id = a.id
SET d.folder_id = a.id_canonical;

-- B.6 — Archivage soft + suffixage slug (libère la place dans l'index strict)
UPDATE ged_folders f
INNER JOIN _ged_roots_to_archive a ON f.id = a.id
SET f.is_archived = 1,
    f.slug = CONCAT(LEFT(f.slug, 100), '_archived_', f.id),
    f.updated_at = NOW();

-- B.7 — Reset path_cache pour force recalcul PHP
UPDATE ged_folders f
INNER JOIN (
  SELECT DISTINCT id_canonical AS id FROM _ged_roots_to_archive
  UNION
  SELECT DISTINCT parent_id FROM ged_folders WHERE parent_id IN (SELECT id_canonical FROM _ged_roots_to_archive)
) c ON f.id = c.id
SET f.path_cache = '';

SELECT '── B. ged_folders racines nettoyées ───' AS step,
       (SELECT COUNT(*) FROM _ged_roots_to_archive) AS nb_folders_archives;


-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  PARTIE C — VÉRIFICATIONS FINALES                                 ║
-- ╚═══════════════════════════════════════════════════════════════════╝

SELECT 'check_levels_dupes' AS verif, COUNT(*) AS nb FROM (
  SELECT 1 FROM ged_level_codes
  GROUP BY tenant_id, level_number, parent_n1, parent_n2, parent_n3, parent_n4, code
  HAVING COUNT(*) > 1
) x;

SELECT 'check_folders_root_dupes' AS verif, COUNT(*) AS nb FROM (
  SELECT 1 FROM ged_folders
  WHERE parent_id IS NULL AND is_archived = 0
  GROUP BY IFNULL(tenant_id,0), slug
  HAVING COUNT(*) > 1
) x;

SELECT 'check_parent_zero' AS verif, COUNT(*) AS nb
FROM ged_folders WHERE parent_id = 0;

SELECT 'check_docs_orphelins' AS verif, COUNT(*) AS nb
FROM ged_documents d
LEFT JOIN ged_folders f ON f.id = d.folder_id
WHERE d.folder_id IS NOT NULL AND f.id IS NULL;

-- ⚠️ Pas de COMMIT explicite : les DDL (DROP/ADD INDEX) ont déjà commit
-- les données implicitement. Les UPDATE folders sont eux aussi auto-commit
-- en MariaDB sans START TRANSACTION explicite. Tout est sauvegardé.

SELECT '✅ TERMINÉ — toutes les modifs sont sauvegardées (auto-commit DDL+DML)' AS resultat;
