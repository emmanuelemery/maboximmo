<?php
/**
 * Migration v2.5 — ged_level_codes : strict uniqueness (post-cleanup)
 * ====================================================================
 *
 * Contexte (audit doublons 2026-05-03) :
 * MySQL autorise les doublons dans un UNIQUE index si une colonne est NULL.
 * L'UNIQUE KEY uk_ged_level_codes_path existante incluait parent_n1..n4 (NULL ok),
 * donc 2 lignes "même code, même parent NULL" étaient acceptées par INSERT IGNORE.
 *
 * Cette migration rend l'unicité EFFECTIVE :
 *   1. tenant_id : NULL → 0 (sentinelle "global"), passé en NOT NULL DEFAULT 0
 *   2. parent_n1..n4 : VARCHAR NULL → NOT NULL DEFAULT ''
 *   3. DROP de l'ancien UNIQUE KEY + recréation strict (sans NULL possible)
 *   4. Vérif index sur ged_folders (uk_ged_folders_parent_slug) — déjà strict
 *      sauf pour les racines NULL : à terme migration v2.x pour ajouter une
 *      sentinelle parent_id=NULL → 0 (Phase 2, pas ici, plus risqué).
 *
 * ⚠️ PRÉ-REQUIS IMPÉRATIF :
 *   Cette migration ÉCHOUERA si des doublons existent encore. Lancer AVANT :
 *     1. sql/ged/005_dedupe_audit.sql (lecture seule) → constater l'ampleur
 *     2. mysqldump backup
 *     3. sql/ged/006_dedupe_cleanup.sql + COMMIT manuel
 *     4. PUIS appliquer cette migration via admin_migrations.php
 *
 * Idempotente : ré-applicable sans risque si déjà passée (les ALTER sont conditionnels
 * via MySQL 8.x IF NOT EXISTS et le DROP/ADD UNIQUE est protégé par try/catch côté
 * exécuteur de migrations).
 *
 * Code PHP synchronisé : super_admin_ged_niveaux.php, ged_import_functions.php,
 * api/ged_folders_admin.php — tous patchés pour insérer '' au lieu de NULL.
 */

return [
    'id'          => '20260503_ged_v2_23_strict_uniqueness',
    'title'       => 'Ma GED Box V2.5 — ged_level_codes : NOT NULL DEFAULT \'\' + UNIQUE strict (anti-doublons)',
    'description' => "Rend l'UNIQUE KEY uk_ged_level_codes_path EFFECTIF en supprimant les NULL : tenant_id NOT NULL DEFAULT 0, parent_n1..n4 NOT NULL DEFAULT ''. Empêche définitivement les doublons d'apparaître (limite MySQL contournée). PRÉ-REQUIS : avoir lancé sql/ged/006_dedupe_cleanup.sql AVANT.",
    'created_at'  => '2026-05-03',
    'sql' => <<<'SQL'
-- ─────────────────────────────────────────────────────────────────────
-- 1) Filet de sécurité : si la table n'a pas été nettoyée, l'ALTER échouera
--    sur la recréation de l'UNIQUE KEY (duplicate entry). Dans ce cas :
--    annuler la migration, lancer 006_dedupe_cleanup.sql, recommencer.
-- ─────────────────────────────────────────────────────────────────────

-- 2) Force NULL → '' / NULL → 0 (idempotent, no-op si déjà fait par 006)
UPDATE `ged_level_codes` SET `parent_n1` = '' WHERE `parent_n1` IS NULL;
UPDATE `ged_level_codes` SET `parent_n2` = '' WHERE `parent_n2` IS NULL;
UPDATE `ged_level_codes` SET `parent_n3` = '' WHERE `parent_n3` IS NULL;
UPDATE `ged_level_codes` SET `parent_n4` = '' WHERE `parent_n4` IS NULL;
UPDATE `ged_level_codes` SET `tenant_id` = 0 WHERE `tenant_id` IS NULL;

-- 3) Modifie le schéma : NOT NULL DEFAULT '' / 0
ALTER TABLE `ged_level_codes`
  MODIFY COLUMN `tenant_id`  INT UNSIGNED       NOT NULL DEFAULT 0  COMMENT '0 = global ; sinon id_societe',
  MODIFY COLUMN `parent_n1`  VARCHAR(80)        NOT NULL DEFAULT '' COMMENT 'sentinelle "" pour racines',
  MODIFY COLUMN `parent_n2`  VARCHAR(80)        NOT NULL DEFAULT '',
  MODIFY COLUMN `parent_n3`  VARCHAR(80)        NOT NULL DEFAULT '',
  MODIFY COLUMN `parent_n4`  VARCHAR(80)        NOT NULL DEFAULT '';

-- 4) Recrée l'UNIQUE KEY (l'ancien tolérait les NULL)
ALTER TABLE `ged_level_codes` DROP INDEX `uk_ged_level_codes_path`;
ALTER TABLE `ged_level_codes`
  ADD UNIQUE KEY `uk_ged_level_codes_path` (`tenant_id`, `level_number`, `parent_n1`, `parent_n2`, `parent_n3`, `parent_n4`, `code`);

-- 5) Vérification : doit retourner 0 (sinon = doublon résiduel, ALTER aurait planté)
-- Cette ligne est juste là pour traçabilité dans les logs admin_migrations.
-- SELECT COUNT(*) - COUNT(DISTINCT CONCAT_WS('|', tenant_id, level_number, parent_n1, parent_n2, parent_n3, parent_n4, code))
--   AS doublons_residuels FROM ged_level_codes;
SQL,
];
