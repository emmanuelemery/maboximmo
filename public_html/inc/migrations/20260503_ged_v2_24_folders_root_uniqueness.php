<?php
/**
 * Migration v2.5 — ged_folders : strict uniqueness à la racine
 * ═════════════════════════════════════════════════════════════
 *
 * Contexte :
 * Phase 1 (v2_23) a réglé les doublons sur ged_level_codes (NULL → '' + UNIQUE strict).
 * Phase 2 (cette migration) règle le même problème sur ged_folders pour les RACINES :
 * MySQL autorise plusieurs lignes (parent_id NULL, slug=X) car NULL bypass UNIQUE.
 *
 * Stratégie : colonnes générées STORED (sentinelles) + UNIQUE strict dessus.
 *   - parent_id_norm = IFNULL(parent_id, 0)
 *   - tenant_id_norm = IFNULL(tenant_id, 0)  (ajout pour cohérence avec v2_23)
 *   - UNIQUE KEY (tenant_id_norm, parent_id_norm, slug)
 *
 * Avantage des colonnes générées STORED :
 *   - Pas besoin de modifier le code PHP existant (parent_id et tenant_id restent
 *     nullables, le code écrit toujours NULL pour les racines, MySQL calcule la
 *     sentinelle automatiquement).
 *   - Index strict effectif sans NULL → INSERT IGNORE protégé.
 *
 * ⚠️ PRÉ-REQUIS IMPÉRATIF :
 *   - sql/ged/007_dedupe_roots_audit.sql exécuté → constater l'ampleur
 *   - mysqldump backup
 *   - sql/ged/008_dedupe_roots_cleanup.sql exécuté + COMMIT manuel
 *   - PUIS appliquer cette migration (sinon ALTER UNIQUE échoue sur "Duplicate entry")
 *
 * ⚠️ COMPATIBILITÉ MYSQL :
 *   - Generated columns STORED : MySQL >= 5.7.6 (Hostinger : OK, généralement 8.0+)
 *   - Si erreur "Generated columns not supported", basculer sur option fallback :
 *     1) ALTER ged_folders MODIFY COLUMN parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0
 *     2) ALTER ged_folders MODIFY COLUMN tenant_id INT UNSIGNED NOT NULL DEFAULT 0
 *     3) UPDATE ged_folders SET parent_id=0 WHERE parent_id IS NULL  (avant l'ALTER NOT NULL)
 *     ⚠️ Cette option requiert d'adapter le code (ged_create_folder, etc.) — plus invasif.
 *
 * Idempotente : ré-applicable sans risque.
 */

return [
    'id'          => '20260503_ged_v2_24_folders_root_uniqueness',
    'title'       => 'Ma GED Box V2.5 — ged_folders : colonnes générées tenant_id_norm + parent_id_norm + UNIQUE strict racine',
    'description' => "Empêche définitivement les doublons à la racine (parent_id NULL bypass MySQL UNIQUE). Ajoute 2 colonnes générées STORED (tenant_id_norm = IFNULL(tenant_id,0), parent_id_norm = IFNULL(parent_id,0)) + UNIQUE KEY uk_ged_folders_norm_parent_slug. Aucun changement de code requis (parent_id/tenant_id restent nullables côté PHP). PRÉ-REQUIS : sql/ged/008_dedupe_roots_cleanup.sql doit avoir tourné AVANT.",
    'created_at'  => '2026-05-03',
    'sql' => <<<'SQL'
-- ─────────────────────────────────────────────────────────────────────
-- 1) Filet de sécurité : si la table contient encore des racines dupliquées
--    actives (parent_id NULL, is_archived=0, même slug + même tenant), l'ALTER
--    ADD UNIQUE échouera sur "Duplicate entry". Dans ce cas :
--      → annuler la migration, lancer 008_dedupe_roots_cleanup.sql, recommencer.
-- ─────────────────────────────────────────────────────────────────────

-- 2) Force parent_id=0 → NULL (idempotent, no-op si déjà fait par 008)
UPDATE `ged_folders` SET `parent_id` = NULL WHERE `parent_id` = 0;

-- 3) Ajout des colonnes générées STORED (sentinelles 0 pour NULL)
ALTER TABLE `ged_folders`
  ADD COLUMN `parent_id_norm` BIGINT UNSIGNED
    GENERATED ALWAYS AS (IFNULL(`parent_id`, 0)) STORED
    COMMENT 'Sentinelle 0 pour racines NULL — permet UNIQUE strict via uk_ged_folders_norm_parent_slug';

ALTER TABLE `ged_folders`
  ADD COLUMN `tenant_id_norm` INT UNSIGNED
    GENERATED ALWAYS AS (IFNULL(`tenant_id`, 0)) STORED
    COMMENT 'Sentinelle 0 pour tenant global NULL — permet UNIQUE strict cohérent avec v2_23';

-- 4) Création de l'UNIQUE KEY effective (sans NULL possible)
-- Note : on filtre sur is_archived=0 indirectement via le suffixage de slug
-- imposé par 008_cleanup (slug = 'xxx_archived_<id>') → les archivés ne créent
-- jamais de collision dans cet index.
ALTER TABLE `ged_folders`
  ADD UNIQUE KEY `uk_ged_folders_norm_parent_slug` (`tenant_id_norm`, `parent_id_norm`, `slug`);

-- 5) Conservation de l'ancien UNIQUE uk_ged_folders_parent_slug
--    Il devient secondaire (pour les non-NULL il est équivalent).
--    Pas de DROP : reste utile comme garde-fou doublé.
--    Si tu veux le supprimer ultérieurement (Phase 3) :
--      ALTER TABLE `ged_folders` DROP INDEX `uk_ged_folders_parent_slug`;
SQL,
];
