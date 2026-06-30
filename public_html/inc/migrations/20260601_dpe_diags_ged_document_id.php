<?php
/**
 * Migration : dpe_diags.ged_document_id — relier la PROJECTION DPE à la GED centrale.
 *
 * RÈGLE D'OR du chantier GED : chaque table d'EXTRACTION (projection de données
 * analysées) porte un FK `ged_document_id` vers `ged_documents` et ne stocke PAS
 * le fichier. dpe_diags est une projection mais re-stocke encore le fichier
 * (colonne `fichier_url`, LUE par personne d'Ubiflow) et n'avait aucun lien vers
 * la GED. On ajoute donc UNIQUEMENT la colonne + index + FK.
 *
 * 100 % ADDITIF / NON DESTRUCTIF :
 *   - aucune colonne supprimée (fichier_url conservée — backfill du lien viendra
 *     plus tard, une fois l'équivalence par hash prouvée) ;
 *   - colonne NULLABLE, tous les enregistrements existants restent NULL ;
 *   - FK ON DELETE SET NULL : supprimer un ged_documents met le lien à NULL,
 *     ne supprime JAMAIS la ligne dpe_diags (cf. politique crg id_bien SET NULL) ;
 *   - Ubiflow ne lit pas cette colonne → zéro impact sur le flux.
 *
 * REJOUABLE sur MariaDB (local) ET MySQL (prod) : on n'utilise PAS la syntaxe
 * `ADD COLUMN IF NOT EXISTS` (absente de MySQL 5.7/8). Chaque DDL est gardée par
 * un test information_schema + PREPARE/EXECUTE → no-op si déjà présent.
 */

return [
    'id'          => '20260601_dpe_diags_ged_document_id',
    'title'       => 'DPE : FK ged_document_id sur dpe_diags (lien GED centrale)',
    'description' => "Ajoute la colonne nullable ged_document_id (BIGINT UNSIGNED) + index + FK ON DELETE SET NULL vers ged_documents. Additif, rejouable, sans suppression de fichier_url. Ubiflow non impacté.",
    'created_at'  => '2026-06-01',
    'sql' => <<<'SQL'
-- 1. Colonne ged_document_id (BIGINT UNSIGNED pour matcher ged_documents.id) ----
SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'dpe_diags'
               AND column_name = 'ged_document_id');
SET @ddl := IF(@col = 0,
    'ALTER TABLE `dpe_diags` ADD COLUMN `ged_document_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL AFTER `fichier_url`',
    'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 2. Index sur la colonne (requis avant la FK) --------------------------------
SET @idx := (SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = 'dpe_diags'
               AND index_name = 'idx_dpe_ged_document');
SET @ddl := IF(@idx = 0,
    'ALTER TABLE `dpe_diags` ADD INDEX `idx_dpe_ged_document` (`ged_document_id`)',
    'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 3. Contrainte FK ON DELETE SET NULL -----------------------------------------
SET @fk := (SELECT COUNT(*) FROM information_schema.table_constraints
            WHERE table_schema = DATABASE() AND table_name = 'dpe_diags'
              AND constraint_name = 'fk_dpe_ged_document' AND constraint_type = 'FOREIGN KEY');
SET @ddl := IF(@fk = 0,
    'ALTER TABLE `dpe_diags` ADD CONSTRAINT `fk_dpe_ged_document` FOREIGN KEY (`ged_document_id`) REFERENCES `ged_documents` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
    'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
SQL,
];
