-- =====================================================================
-- Migration : indexes pour le portail public mbi_annonces
-- Date     : 2026-04-30 (V3 — prepared statements, universellement compatible)
-- Cible    : MySQL 5.7+ / MariaDB 10.0+
-- =====================================================================
-- Pourquoi V3 ?
--   - V1 : ALTER TABLE atomique → échoue dès qu'un index existe (#1061)
--   - V2 : CREATE INDEX IF NOT EXISTS → fonctionne sur MariaDB 10.5+
--          mais pas sur MySQL standard (syntaxe non supportée)
--   - V3 : prepared statements universels — fonctionne partout, idempotent.
--
-- Chaque index est créé via un bloc :
--   1. SELECT dans information_schema pour vérifier s'il existe
--   2. PREPARE/EXECUTE le CREATE INDEX uniquement si absent
--   3. Sinon, exécute un no-op (DO 0)
--
-- Safe à rejouer N fois.
-- =====================================================================

-- ─── 1. Annonces : idx_mbi_annonces_visible_mbi ──────────────────────
SET @s := (SELECT IF(COUNT(*)=0,
  'CREATE INDEX `idx_mbi_annonces_visible_mbi` ON `annonces` (`visible_maboximmo`, `statut`, `type_transaction`)',
  'DO 0'
) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'annonces' AND index_name = 'idx_mbi_annonces_visible_mbi');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── 2. Annonces : idx_mbi_annonces_visible_mbi_date ─────────────────
SET @s := (SELECT IF(COUNT(*)=0,
  'CREATE INDEX `idx_mbi_annonces_visible_mbi_date` ON `annonces` (`visible_maboximmo`, `statut`, `date_mise_en_ligne`)',
  'DO 0'
) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'annonces' AND index_name = 'idx_mbi_annonces_visible_mbi_date');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── 3. Annonces : idx_mbi_annonces_visible_mbi_prix ─────────────────
SET @s := (SELECT IF(COUNT(*)=0,
  'CREATE INDEX `idx_mbi_annonces_visible_mbi_prix` ON `annonces` (`visible_maboximmo`, `statut`, `prix`)',
  'DO 0'
) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'annonces' AND index_name = 'idx_mbi_annonces_visible_mbi_prix');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── 4. Annonces : idx_mbi_annonces_visible_mbi_loyer ────────────────
SET @s := (SELECT IF(COUNT(*)=0,
  'CREATE INDEX `idx_mbi_annonces_visible_mbi_loyer` ON `annonces` (`visible_maboximmo`, `statut`, `loyer`)',
  'DO 0'
) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'annonces' AND index_name = 'idx_mbi_annonces_visible_mbi_loyer');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── 5. Biens : idx_mbi_biens_statut_ville ───────────────────────────
SET @s := (SELECT IF(COUNT(*)=0,
  'CREATE INDEX `idx_mbi_biens_statut_ville` ON `biens` (`statut_bien`, `ville`, `code_postal`)',
  'DO 0'
) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'biens' AND index_name = 'idx_mbi_biens_statut_ville');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── 6. Biens : idx_mbi_biens_statut_surface ─────────────────────────
SET @s := (SELECT IF(COUNT(*)=0,
  'CREATE INDEX `idx_mbi_biens_statut_surface` ON `biens` (`statut_bien`, `surface_habitable`)',
  'DO 0'
) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'biens' AND index_name = 'idx_mbi_biens_statut_surface');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── 7. Biens : idx_mbi_biens_statut_pieces ──────────────────────────
SET @s := (SELECT IF(COUNT(*)=0,
  'CREATE INDEX `idx_mbi_biens_statut_pieces` ON `biens` (`statut_bien`, `nb_pieces`)',
  'DO 0'
) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'biens' AND index_name = 'idx_mbi_biens_statut_pieces');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── 8. Biens : idx_mbi_biens_statut_type ────────────────────────────
SET @s := (SELECT IF(COUNT(*)=0,
  'CREATE INDEX `idx_mbi_biens_statut_type` ON `biens` (`statut_bien`, `id_type_bien`)',
  'DO 0'
) FROM information_schema.statistics
  WHERE table_schema = DATABASE() AND table_name = 'biens' AND index_name = 'idx_mbi_biens_statut_type');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =====================================================================
-- VÉRIFICATION (à exécuter en SELECT séparé après le script)
-- =====================================================================
-- SELECT TABLE_NAME, INDEX_NAME
-- FROM INFORMATION_SCHEMA.STATISTICS
-- WHERE TABLE_SCHEMA = DATABASE()
--   AND INDEX_NAME LIKE 'idx_mbi_%'
-- GROUP BY TABLE_NAME, INDEX_NAME
-- ORDER BY TABLE_NAME, INDEX_NAME;
-- → doit retourner 8 lignes (4 sur annonces + 4 sur biens)

-- =====================================================================
-- ROLLBACK (à exécuter manuellement si besoin)
-- =====================================================================
-- DROP INDEX `idx_mbi_annonces_visible_mbi`       ON `annonces`;
-- DROP INDEX `idx_mbi_annonces_visible_mbi_date`  ON `annonces`;
-- DROP INDEX `idx_mbi_annonces_visible_mbi_prix`  ON `annonces`;
-- DROP INDEX `idx_mbi_annonces_visible_mbi_loyer` ON `annonces`;
-- DROP INDEX `idx_mbi_biens_statut_ville`         ON `biens`;
-- DROP INDEX `idx_mbi_biens_statut_surface`       ON `biens`;
-- DROP INDEX `idx_mbi_biens_statut_pieces`        ON `biens`;
-- DROP INDEX `idx_mbi_biens_statut_type`          ON `biens`;
