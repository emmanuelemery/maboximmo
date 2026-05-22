-- ============================================================================
-- Migration bien_baux — Représentants + Assurance + Conditions particulières
-- 2026-05-18. Idempotente.
-- ============================================================================

-- ─── Représentant bailleur ──────────────────────────────────────────────────
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bien_baux' AND COLUMN_NAME = 'bailleur_representant_nom');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `bien_baux` ADD COLUMN `bailleur_representant_nom` VARCHAR(150) NULL COMMENT ''Représentant légal du bailleur (gérant, président, etc.)'' AFTER `id_proprietaire`',
    'SELECT ''skip bailleur_representant_nom'' AS _');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bien_baux' AND COLUMN_NAME = 'bailleur_representant_qualite');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `bien_baux` ADD COLUMN `bailleur_representant_qualite` VARCHAR(80) NULL COMMENT ''gérant|président|directeur|mandataire|propriétaire en personne'' AFTER `bailleur_representant_nom`',
    'SELECT ''skip bailleur_representant_qualite'' AS _');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bien_baux' AND COLUMN_NAME = 'bailleur_representant_email');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `bien_baux` ADD COLUMN `bailleur_representant_email` VARCHAR(190) NULL AFTER `bailleur_representant_qualite`',
    'SELECT ''skip bailleur_representant_email'' AS _');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bien_baux' AND COLUMN_NAME = 'bailleur_representant_telephone');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `bien_baux` ADD COLUMN `bailleur_representant_telephone` VARCHAR(30) NULL AFTER `bailleur_representant_email`',
    'SELECT ''skip bailleur_representant_telephone'' AS _');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── Représentant locataire ─────────────────────────────────────────────────
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bien_baux' AND COLUMN_NAME = 'locataire_representant_nom');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `bien_baux` ADD COLUMN `locataire_representant_nom` VARCHAR(150) NULL COMMENT ''Représentant légal du locataire'' AFTER `locataire_telephone`',
    'SELECT ''skip locataire_representant_nom'' AS _');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bien_baux' AND COLUMN_NAME = 'locataire_representant_qualite');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `bien_baux` ADD COLUMN `locataire_representant_qualite` VARCHAR(80) NULL AFTER `locataire_representant_nom`',
    'SELECT ''skip locataire_representant_qualite'' AS _');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bien_baux' AND COLUMN_NAME = 'locataire_representant_email');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `bien_baux` ADD COLUMN `locataire_representant_email` VARCHAR(190) NULL AFTER `locataire_representant_qualite`',
    'SELECT ''skip locataire_representant_email'' AS _');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bien_baux' AND COLUMN_NAME = 'locataire_representant_telephone');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `bien_baux` ADD COLUMN `locataire_representant_telephone` VARCHAR(30) NULL AFTER `locataire_representant_email`',
    'SELECT ''skip locataire_representant_telephone'' AS _');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── Assurance : renonciation à recours réciproque ──────────────────────────
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bien_baux' AND COLUMN_NAME = 'renonciation_recours_reciproque');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `bien_baux` ADD COLUMN `renonciation_recours_reciproque` TINYINT(1) NULL DEFAULT NULL COMMENT ''0=non, 1=oui, NULL=non précisé dans le bail'' AFTER `clause_resolutoire`',
    'SELECT ''skip renonciation_recours_reciproque'' AS _');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── Conditions particulières (texte libre) ─────────────────────────────────
SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bien_baux' AND COLUMN_NAME = 'conditions_particulieres');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `bien_baux` ADD COLUMN `conditions_particulieres` TEXT NULL COMMENT ''Conditions particulières du bail (clauses spécifiques)'' AFTER `metadata`',
    'SELECT ''skip conditions_particulieres'' AS _');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
