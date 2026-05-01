-- ─────────────────────────────────────────────────────────────────────────────
-- Migration GED — 001_rename_agent_ged_analyses_to_ged_analyses
-- Date    : 2026-05-01
-- Effet   : renomme agent_ged_analyses → ged_analyses (table principale GED).
--           Crée une VIEW de compatibilité agent_ged_analyses → ged_analyses
--           pour ne casser aucun code existant qui référence l'ancien nom.
-- IDEMPOTENT : exécutable plusieurs fois sans erreur.
--
-- Scénarios couverts :
--   1) Legacy : agent_ged_analyses existe → RENAME → ged_analyses. VIEW créée.
--   2) Fresh  : aucune des 2 → CREATE TABLE ged_analyses + VIEW.
--   3) Re-run : ged_analyses déjà OK → no-op + VIEW recréée.
-- ─────────────────────────────────────────────────────────────────────────────

-- ─── 1. RENAME conditionnel (legacy → canonical) ─────────────────────────────
SET @cnt_old = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME   = 'agent_ged_analyses'
                  AND TABLE_TYPE   = 'BASE TABLE');
SET @cnt_new = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME   = 'ged_analyses'
                  AND TABLE_TYPE   = 'BASE TABLE');

-- Si une VIEW agent_ged_analyses existe déjà (cas re-run), on la drop avant rename
SET @cnt_view_old = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.VIEWS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'agent_ged_analyses');
SET @sql_drop_view = IF(@cnt_view_old = 1, 'DROP VIEW agent_ged_analyses', 'DO 0');
PREPARE s FROM @sql_drop_view; EXECUTE s; DEALLOCATE PREPARE s;

-- RENAME si source=table base ET cible n'existe pas
SET @sql_rename = IF(@cnt_old = 1 AND @cnt_new = 0,
                     'RENAME TABLE agent_ged_analyses TO ged_analyses',
                     'DO 0');
PREPARE s FROM @sql_rename; EXECUTE s; DEALLOCATE PREPARE s;

-- ─── 2. CREATE TABLE ged_analyses si pas encore là (fresh install) ───────────
CREATE TABLE IF NOT EXISTS ged_analyses (
    id                    INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,

    document_id           INT UNSIGNED NULL,
    document_table        VARCHAR(64)  NULL COMMENT 'Table source (admin_documents, agence_documents, etc.)',

    source_type           ENUM('upload','email','ocr','manual','reanalyze') NOT NULL DEFAULT 'reanalyze',
    ocr_engine            VARCHAR(50)  NULL COMMENT 'tesseract|mindee|google_vision|aws_textract|none',
    ocr_text              LONGTEXT     NULL,
    ocr_confidence        DECIMAL(5,2) NULL,
    ia_engine             VARCHAR(50)  NULL,

    suggested_module      VARCHAR(50)  NULL COMMENT 'RH|COMPTA|BAILLEUR|SYNDIC|AGENCE|FOURNISSEURS|ADMIN',
    suggested_level_2     VARCHAR(100) NULL,
    suggested_level_3     VARCHAR(100) NULL,
    suggested_filename    VARCHAR(255) NULL,

    detected_immeuble     VARCHAR(255) NULL,
    detected_fournisseur  VARCHAR(255) NULL,
    detected_locataire    VARCHAR(255) NULL,
    detected_salarie      VARCHAR(255) NULL,
    detected_proprietaire VARCHAR(255) NULL,
    detected_montant      DECIMAL(12,2) NULL,
    detected_date         DATE          NULL,

    suggested_action      TEXT         NULL,
    confidence_score      DECIMAL(5,2) NULL COMMENT '0-100',

    status                ENUM('draft','to_validate','validated','rejected','manual_review')
                          NOT NULL DEFAULT 'to_validate',
    validated_by          INT UNSIGNED NULL,
    validated_at          DATETIME     NULL,

    id_societe            INT UNSIGNED NULL,
    id_agence             INT UNSIGNED NULL,

    ai_raw_response       JSON         NULL,

    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_status            (status),
    KEY idx_document          (document_table, document_id),
    KEY idx_suggested_module  (suggested_module),
    KEY idx_detected_immeuble (detected_immeuble),
    KEY idx_id_societe        (id_societe, status),
    KEY idx_id_agence         (id_agence, status),
    KEY idx_confidence_status (status, confidence_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='GED MaBoxImmo — analyses IA + index documents (rename de agent_ged_analyses)';

-- ─── 3. VIEW de compat (l'ancien nom continue de fonctionner) ────────────────
DROP VIEW IF EXISTS agent_ged_analyses;
CREATE VIEW agent_ged_analyses AS SELECT * FROM ged_analyses;
