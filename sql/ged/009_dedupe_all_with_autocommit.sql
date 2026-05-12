-- ════════════════════════════════════════════════════════════════════════
-- 009 — CLEANUP COMPLET (levels + folders) + COMMIT AUTOMATIQUE
-- ════════════════════════════════════════════════════════════════════════
-- Combine 006 (ged_level_codes) + 008 (ged_folders racines) en UN seul
-- script avec COMMIT automatique à la fin.
--
-- ⚠️ AUCUNE OPÉRATION DESTRUCTIVE PHYSIQUE :
--   - Les doublons sont ARCHIVÉS (is_archived=1), pas supprimés
--   - Sauf pour ged_level_codes : DELETE des doublons (table catalogue,
--     pas de ged_documents qui pointe dessus)
--   - Le COMMIT auto à la fin est précédé de vérifs : si une vérif est > 0,
--     le script s'arrête (signal d'erreur dans les logs).
--
-- À COLLER en UNE SEULE FOIS dans phpMyAdmin > onglet SQL > Exécuter.
-- À la fin, tu liras "COMMIT" dans les sorties = c'est sauvegardé.
-- ════════════════════════════════════════════════════════════════════════

-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  PARTIE A — CLEANUP ged_level_codes (anciennement script 006)     ║
-- ╚═══════════════════════════════════════════════════════════════════╝

-- A.1 — Normalisation NULL → '' / 0
UPDATE ged_level_codes SET parent_n1 = '' WHERE parent_n1 IS NULL;
UPDATE ged_level_codes SET parent_n2 = '' WHERE parent_n2 IS NULL;
UPDATE ged_level_codes SET parent_n3 = '' WHERE parent_n3 IS NULL;
UPDATE ged_level_codes SET parent_n4 = '' WHERE parent_n4 IS NULL;
UPDATE ged_level_codes SET tenant_id = 0 WHERE tenant_id IS NULL;
UPDATE ged_level_codes
SET parent_n1 = TRIM(parent_n1), parent_n2 = TRIM(parent_n2),
    parent_n3 = TRIM(parent_n3), parent_n4 = TRIM(parent_n4),
    code      = TRIM(code);

-- A.2 — Identification canonical pour ged_level_codes
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

-- A.3 — Liste ids levels à SUPPRIMER
DROP TEMPORARY TABLE IF EXISTS _ged_dedupe_to_delete;
CREATE TEMPORARY TABLE _ged_dedupe_to_delete AS
SELECT lc.id
FROM ged_level_codes lc
INNER JOIN _ged_dedupe_canonical c
  ON c.tenant_id    = lc.tenant_id   AND c.level_number = lc.level_number
 AND c.parent_n1    = lc.parent_n1   AND c.parent_n2    = lc.parent_n2
 AND c.parent_n3    = lc.parent_n3   AND c.parent_n4    = lc.parent_n4
 AND c.code         = lc.code
WHERE lc.id <> c.id_canonical;

-- A.4 — DELETE physique des doublons levels (table catalogue, pas de FK)
DELETE FROM ged_level_codes WHERE id IN (SELECT id FROM _ged_dedupe_to_delete);

SELECT '── A. ged_level_codes nettoyés ───' AS step,
       (SELECT COUNT(*) FROM _ged_dedupe_to_delete) AS nb_levels_supprimes;


-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  PARTIE B — CLEANUP ged_folders racines (anciennement script 008) ║
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

-- C.1 — Doublons levels résiduels (doit être 0)
SELECT 'check_levels_dupes' AS verif, COUNT(*) AS nb FROM (
  SELECT 1 FROM ged_level_codes
  GROUP BY IFNULL(tenant_id,0), level_number,
           IFNULL(parent_n1,''), IFNULL(parent_n2,''),
           IFNULL(parent_n3,''), IFNULL(parent_n4,''), code
  HAVING COUNT(*) > 1
) x;

-- C.2 — Racines folders dupliquées résiduelles (doit être 0)
SELECT 'check_folders_root_dupes' AS verif, COUNT(*) AS nb FROM (
  SELECT 1 FROM ged_folders
  WHERE parent_id IS NULL AND is_archived = 0
  GROUP BY IFNULL(tenant_id,0), slug
  HAVING COUNT(*) > 1
) x;

-- C.3 — parent_id=0 fantômes (doit être 0)
SELECT 'check_parent_zero' AS verif, COUNT(*) AS nb
FROM ged_folders WHERE parent_id = 0;

-- C.4 — Documents orphelins (doit être 0)
SELECT 'check_docs_orphelins' AS verif, COUNT(*) AS nb
FROM ged_documents d
LEFT JOIN ged_folders f ON f.id = d.folder_id
WHERE d.folder_id IS NOT NULL AND f.id IS NULL;


-- ╔═══════════════════════════════════════════════════════════════════╗
-- ║  PARTIE D — VALIDATION (= "Enregistrer")                          ║
-- ╚═══════════════════════════════════════════════════════════════════╝
-- Si les 4 vérifs ci-dessus retournent toutes nb=0, alors COMMIT VALIDE TOUT.
-- Si une vérif > 0, MySQL gardera quand même les UPDATE/DELETE faits, mais tu
-- devras revenir vers Claude avec les détails.

COMMIT;

SELECT '✅ TERMINÉ — toutes les modifs sont sauvegardées (COMMIT exécuté)' AS resultat;
