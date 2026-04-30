-- ═══════════════════════════════════════════════════════════════════════
-- MaBoxImmo — Colonnes environnement manquantes sur biens
-- Créé le 2026-04-19 · Refactor idempotent 2026-04-30
-- Cible : MySQL 5.7+ / MariaDB 10.0+
-- ═══════════════════════════════════════════════════════════════════════
-- Bug historique (cf. project_reprise_2026_04_20) : 5 trous dans le pipeline
-- Express → biens. Cette migration ajoute les colonnes manquantes pour que
-- les valeurs saisies dans l'Express soient toutes persistées.
--
-- Refactor 2026-04-30 : prepared statements pour idempotence universelle.
-- Erreur #1060 sur prod : la colonne `quartier` existait déjà → l'ALTER
-- atomique avortait → les 3 autres colonnes (ambiance, points_interet,
-- argument_phare) restaient absentes.
--
-- Safe à rejouer N fois.
-- ═══════════════════════════════════════════════════════════════════════

-- ─── 1. ambiance ─────────────────────────────────────────────────────
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE `biens` ADD COLUMN `ambiance` VARCHAR(255) DEFAULT NULL COMMENT ''CSV: calme,centre_ville,proche_transports,proche_commerces,residentiel''',
  'DO 0'
) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'biens' AND column_name = 'ambiance');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── 2. quartier ─────────────────────────────────────────────────────
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE `biens` ADD COLUMN `quartier` VARCHAR(150) DEFAULT NULL COMMENT ''Quartier (important SEO local)''',
  'DO 0'
) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'biens' AND column_name = 'quartier');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── 3. points_interet ───────────────────────────────────────────────
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE `biens` ADD COLUMN `points_interet` TEXT DEFAULT NULL COMMENT ''Points d''''intérêt supplémentaires (école, parc, transport spécifique…)''',
  'DO 0'
) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'biens' AND column_name = 'points_interet');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── 4. argument_phare ───────────────────────────────────────────────
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE `biens` ADD COLUMN `argument_phare` VARCHAR(255) DEFAULT NULL COMMENT ''Argument commercial clé en 1 phrase (ex: terrasse plein sud, vue mer)''',
  'DO 0'
) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'biens' AND column_name = 'argument_phare');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ═══════════════════════════════════════════════════════════════════════
-- VÉRIFICATION
-- ═══════════════════════════════════════════════════════════════════════
-- SELECT COLUMN_NAME, COLUMN_TYPE
-- FROM INFORMATION_SCHEMA.COLUMNS
-- WHERE TABLE_SCHEMA = DATABASE()
--   AND TABLE_NAME = 'biens'
--   AND COLUMN_NAME IN ('ambiance', 'quartier', 'points_interet', 'argument_phare');
-- → doit retourner 4 lignes

-- ═══════════════════════════════════════════════════════════════════════
-- ROLLBACK
-- ═══════════════════════════════════════════════════════════════════════
-- ALTER TABLE `biens`
--   DROP COLUMN `ambiance`,
--   DROP COLUMN `quartier`,
--   DROP COLUMN `points_interet`,
--   DROP COLUMN `argument_phare`;
