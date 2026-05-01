-- ─────────────────────────────────────────────────────────────────────────────
-- Migration GED — 002_extend_ged_analyses_storage
-- Date    : 2026-05-01
-- Effet   : ajoute les colonnes de stockage (Drive/local) + référentiel objet
--           métier + versioning, conformément au prompt maître GED §7.
-- IDEMPOTENT : utilise une procédure stockée pour ADD COLUMN si manquante.
-- ─────────────────────────────────────────────────────────────────────────────

DROP PROCEDURE IF EXISTS ged_add_col;
DELIMITER $$
CREATE PROCEDURE ged_add_col(
    IN p_table  VARCHAR(64),
    IN p_col    VARCHAR(64),
    IN p_def    TEXT
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = p_table
          AND COLUMN_NAME  = p_col
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE ', p_table, ' ADD COLUMN ', p_col, ' ', p_def);
        PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
END$$
DELIMITER ;

CALL ged_add_col('ged_analyses', 'sha256',            'VARCHAR(64) NULL COMMENT ''SHA256 du fichier brut''');
CALL ged_add_col('ged_analyses', 'storage_driver',    'VARCHAR(20) NULL COMMENT ''local|google_drive''');
CALL ged_add_col('ged_analyses', 'storage_file_id',   'VARCHAR(255) NULL COMMENT ''ID du fichier côté driver''');
CALL ged_add_col('ged_analyses', 'storage_folder_id', 'VARCHAR(255) NULL COMMENT ''ID du dossier parent''');
CALL ged_add_col('ged_analyses', 'storage_size',      'BIGINT UNSIGNED NULL');
CALL ged_add_col('ged_analyses', 'storage_mime',      'VARCHAR(100) NULL');
CALL ged_add_col('ged_analyses', 'objet_type',        'VARCHAR(20) NULL COMMENT ''IMB|BIEN|MDT|CTX|EMP|FOUR''');
CALL ged_add_col('ged_analyses', 'objet_id',          'INT UNSIGNED NULL');
CALL ged_add_col('ged_analyses', 'ref_societe',       'VARCHAR(20) NULL COMMENT ''Code court societe (ex RE)''');
CALL ged_add_col('ged_analyses', 'ref_agence',        'VARCHAR(20) NULL COMMENT ''Code court agence (ex AGLYON)''');
CALL ged_add_col('ged_analyses', 'tiers_nom',         'VARCHAR(255) NULL COMMENT ''Nom du tiers principal du doc''');
CALL ged_add_col('ged_analyses', 'nom_original',      'VARCHAR(255) NULL');
CALL ged_add_col('ged_analyses', 'nom_renomme',       'VARCHAR(255) NULL COMMENT ''Nom calculé via ged_naming''');
CALL ged_add_col('ged_analyses', 'extension',         'VARCHAR(10) NULL');
CALL ged_add_col('ged_analyses', 'version_doc',       'INT UNSIGNED NOT NULL DEFAULT 1');
CALL ged_add_col('ged_analyses', 'date_document',     'DATE NULL COMMENT ''Date métier extraite du doc''');

DROP PROCEDURE IF EXISTS ged_add_col;

-- ─── Index (idempotents via INFORMATION_SCHEMA) ──────────────────────────────
SET @i1 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ged_analyses' AND INDEX_NAME = 'idx_ged_objet');
SET @sql = IF(@i1 = 0,
              'CREATE INDEX idx_ged_objet ON ged_analyses (objet_type, objet_id, id_societe, id_agence)',
              'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @i2 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ged_analyses' AND INDEX_NAME = 'idx_ged_sha256');
SET @sql = IF(@i2 = 0,
              'CREATE INDEX idx_ged_sha256 ON ged_analyses (sha256)',
              'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @i3 = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ged_analyses' AND INDEX_NAME = 'idx_ged_storage_file');
SET @sql = IF(@i3 = 0,
              'CREATE INDEX idx_ged_storage_file ON ged_analyses (storage_driver, storage_file_id)',
              'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Recréer la VIEW de compat pour qu'elle expose aussi les nouvelles colonnes
DROP VIEW IF EXISTS agent_ged_analyses;
CREATE VIEW agent_ged_analyses AS SELECT * FROM ged_analyses;
