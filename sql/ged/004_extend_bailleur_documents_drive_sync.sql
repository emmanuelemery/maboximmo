-- ─────────────────────────────────────────────────────────────────────────────
-- Migration GED — 004_extend_bailleur_documents_drive_sync
-- Date    : 2026-05-01
-- Effet   : ajoute les colonnes de sync Google Drive sur bailleur_documents.
-- IDEMPOTENT.
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

CALL ged_add_col('bailleur_documents', 'drive_sync_status',
    "ENUM('pending','success','error') NULL DEFAULT NULL COMMENT 'Synchronisation Google Drive (pending|success|error)'");
CALL ged_add_col('bailleur_documents', 'drive_sync_error', 'TEXT NULL');
CALL ged_add_col('bailleur_documents', 'drive_sync_attempts', 'INT UNSIGNED NOT NULL DEFAULT 0');
CALL ged_add_col('bailleur_documents', 'drive_sync_last_attempt_at', 'DATETIME NULL');
CALL ged_add_col('bailleur_documents', 'drive_sync_success_at', 'DATETIME NULL');

CALL ged_add_col('bailleur_documents', 'google_drive_file_id', 'VARCHAR(255) NULL');
CALL ged_add_col('bailleur_documents', 'google_drive_folder_id', 'VARCHAR(255) NULL');
CALL ged_add_col('bailleur_documents', 'google_drive_url', 'VARCHAR(512) NULL');

DROP PROCEDURE IF EXISTS ged_add_col;

