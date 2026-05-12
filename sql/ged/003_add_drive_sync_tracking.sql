-- ─────────────────────────────────────────────────────────────────────────────
-- Migration GED — 003_add_drive_sync_tracking
-- Date    : 2026-05-01
-- Effet   : ajoute le suivi de synchronisation Google Drive (statut/erreur/URL)
--           sans casser le schéma existant. IDEMPOTENT.
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

-- Suivi sync Google Drive (demandé : pending/success/error + message d'erreur + URL)
CALL ged_add_col('ged_analyses', 'drive_sync_status',
    "ENUM('pending','success','error') NULL DEFAULT NULL COMMENT 'Synchronisation Google Drive (pending|success|error)'");
CALL ged_add_col('ged_analyses', 'drive_sync_error', 'TEXT NULL');
CALL ged_add_col('ged_analyses', 'drive_sync_attempts', 'INT UNSIGNED NOT NULL DEFAULT 0');
CALL ged_add_col('ged_analyses', 'drive_sync_last_attempt_at', 'DATETIME NULL');
CALL ged_add_col('ged_analyses', 'drive_sync_success_at', 'DATETIME NULL');

-- Redondance explicite (facilite UI / exports sans interpréter storage_*)
CALL ged_add_col('ged_analyses', 'google_drive_file_id', 'VARCHAR(255) NULL');
CALL ged_add_col('ged_analyses', 'google_drive_folder_id', 'VARCHAR(255) NULL');
CALL ged_add_col('ged_analyses', 'google_drive_url', 'VARCHAR(512) NULL');

DROP PROCEDURE IF EXISTS ged_add_col;

