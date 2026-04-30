-- =====================================================================
-- Migration : sécurise les colonnes des canaux de diffusion sur `annonces`
-- Date     : 2026-04-30 (V2 — prepared statements, universellement compatible)
-- Cible    : MySQL 5.7+ / MariaDB 10.0+
-- =====================================================================
-- Pourquoi ?
--   Les colonnes `visible_maboximmo` et `visible_site_perso` ont été ajoutées
--   à la main en BDD lors d'un patch précédent. Aucune migration .sql n'avait
--   été versionnée. Sur certaines instances elles peuvent manquer.
--
--   Le portail public mbi_annonces_* (livré 2026-04-30) filtre les annonces
--   sur `visible_maboximmo = 1`. Si la colonne manque, le portail crashe
--   avec "Unknown column 'a.visible_maboximmo'".
--
--   V2 utilise des prepared statements pour idempotence universelle (la
--   syntaxe ADD COLUMN IF NOT EXISTS n'est pas supportée par tous les
--   MySQL/MariaDB).
-- =====================================================================

-- ─── 1. visible_maboximmo ────────────────────────────────────────────
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE `annonces` ADD COLUMN `visible_maboximmo` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Canal MaBoxImmo : visible dans le portail public mbi_annonces_*''',
  'DO 0'
) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'annonces' AND column_name = 'visible_maboximmo');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── 2. visible_site_perso ───────────────────────────────────────────
SET @s := (SELECT IF(COUNT(*)=0,
  'ALTER TABLE `annonces` ADD COLUMN `visible_site_perso` TINYINT(1) NOT NULL DEFAULT 0 COMMENT ''Canal Site perso : visible sur le mini-site vitrine de l''''agence''',
  'DO 0'
) FROM information_schema.columns
  WHERE table_schema = DATABASE() AND table_name = 'annonces' AND column_name = 'visible_site_perso');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- =====================================================================
-- VÉRIFICATION
-- =====================================================================
-- SELECT COLUMN_NAME, COLUMN_TYPE, COLUMN_COMMENT
-- FROM INFORMATION_SCHEMA.COLUMNS
-- WHERE TABLE_SCHEMA = DATABASE()
--   AND TABLE_NAME = 'annonces'
--   AND COLUMN_NAME IN ('visible_maboximmo', 'visible_site_perso');
-- → doit retourner 2 lignes

-- =====================================================================
-- ROLLBACK
-- =====================================================================
-- ALTER TABLE `annonces`
--   DROP COLUMN `visible_maboximmo`,
--   DROP COLUMN `visible_site_perso`;
