-- ============================================================================
-- Migration Module Transaction V0 — 2026-05-18
-- Aucune table-mère nouvelle. 2 ALTER + 1 VIEW.
--
-- Application : exécuter sur dev en premier, puis prod après validation.
--   mysql -u <user> -p <db> < sql/migration_transaction_v0_2026-05-18.sql
--
-- IDEMPOTENT : exécutable plusieurs fois (vérifications information_schema).
-- ============================================================================

-- ─── A. biens : suivi commercialisation ─────────────────────────────────────

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biens' AND COLUMN_NAME = 'date_mise_en_vente');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `biens` ADD COLUMN `date_mise_en_vente` DATE NULL DEFAULT NULL COMMENT ''Date mise en commercialisation'' AFTER `prix_vente_estime`',
    'SELECT ''skip date_mise_en_vente'' AS _');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biens' AND COLUMN_NAME = 'date_retrait_commercialisation');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `biens` ADD COLUMN `date_retrait_commercialisation` DATE NULL DEFAULT NULL COMMENT ''Date retrait marché (vendu/loué/retiré)'' AFTER `date_mise_en_vente`',
    'SELECT ''skip date_retrait_commercialisation'' AS _');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biens' AND COLUMN_NAME = 'prix_demande_initial');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `biens` ADD COLUMN `prix_demande_initial` DECIMAL(14,2) NULL DEFAULT NULL COMMENT ''Prix affiché lancement'' AFTER `date_retrait_commercialisation`',
    'SELECT ''skip prix_demande_initial'' AS _');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biens' AND COLUMN_NAME = 'prix_final_vente');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `biens` ADD COLUMN `prix_final_vente` DECIMAL(14,2) NULL DEFAULT NULL COMMENT ''Prix net signé notaire'' AFTER `prix_demande_initial`',
    'SELECT ''skip prix_final_vente'' AS _');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biens' AND INDEX_NAME = 'idx_biens_date_mise_en_vente');
SET @sql := IF(@idx_exists = 0,
    'CREATE INDEX `idx_biens_date_mise_en_vente` ON `biens` (`date_mise_en_vente`)',
    'SELECT ''skip idx_biens_date_mise_en_vente'' AS _');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── B. leads_annonces : structuration offres ───────────────────────────────

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads_annonces' AND COLUMN_NAME = 'prix_propose');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `leads_annonces` ADD COLUMN `prix_propose` DECIMAL(14,2) NULL DEFAULT NULL COMMENT ''Montant offre acquéreur'' AFTER `message`',
    'SELECT ''skip prix_propose'' AS _');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads_annonces' AND COLUMN_NAME = 'financement_type');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `leads_annonces` ADD COLUMN `financement_type` VARCHAR(20) NULL DEFAULT NULL COMMENT ''cash|emprunt|mixte|inconnu'' AFTER `prix_propose`',
    'SELECT ''skip financement_type'' AS _');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads_annonces' AND COLUMN_NAME = 'statut_offre');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `leads_annonces` ADD COLUMN `statut_offre` VARCHAR(30) NULL DEFAULT NULL COMMENT ''recue|transmise_vendeur|acceptee|refusee|contre_offre|expiree'' AFTER `financement_type`',
    'SELECT ''skip statut_offre'' AS _');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'leads_annonces' AND INDEX_NAME = 'idx_leads_statut_offre');
SET @sql := IF(@idx_exists = 0,
    'CREATE INDEX `idx_leads_statut_offre` ON `leads_annonces` (`statut_offre`)',
    'SELECT ''skip idx_leads_statut_offre'' AS _');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── B-bis. Priorité de vente (haute/normale/differee) ──────────────────────

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biens' AND COLUMN_NAME = 'priorite_vente');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE `biens` ADD COLUMN `priorite_vente` VARCHAR(20) NULL DEFAULT NULL COMMENT ''haute|normale|differee'' AFTER `prix_final_vente`',
    'SELECT ''skip priorite_vente'' AS _');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'biens' AND INDEX_NAME = 'idx_biens_priorite_vente');
SET @sql := IF(@idx_exists = 0,
    'CREATE INDEX `idx_biens_priorite_vente` ON `biens` (`priorite_vente`)',
    'SELECT ''skip idx_biens_priorite_vente'' AS _');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ─── B-ter. Table staging pour le module Chargement par lot ─────────────────
-- (persistance des docs uploadés avant validation finale dans ged_documents)

CREATE TABLE IF NOT EXISTS `transaction_chargement_staging` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `filename` VARCHAR(255) NOT NULL,
  `stored_path` VARCHAR(500) NOT NULL,
  `mime_type` VARCHAR(100) NULL,
  `size_bytes` BIGINT UNSIGNED NULL,
  `metadata` JSON NULL COMMENT 'detected_type, ia_data, match_biens, selected_bien_id, etc.',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_staging_user` (`user_id`),
  INDEX `idx_staging_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── C. Vue agrégée vw_transactions ─────────────────────────────────────────

DROP VIEW IF EXISTS `vw_transactions`;

CREATE VIEW `vw_transactions` AS
SELECT
  b.id                              AS bien_id,
  b.reference_bien,
  b.designation,
  b.type_commercialisation,
  b.usage_bien,
  b.statut_bien,
  b.id_societe,
  b.id_agence,
  b.id_proprietaire,
  -- Lot : hérite de l'adresse de l'immeuble si vide sur le bien
  COALESCE(NULLIF(b.ville, ''), i.ville)             AS ville,
  COALESCE(NULLIF(b.code_postal, ''), i.code_postal) AS code_postal,
  COALESCE(NULLIF(b.adresse_1, ''), i.adresse_1)     AS adresse_1,
  b.surface_habitable,
  b.nb_pieces,
  b.dpe_classe,
  b.ges_classe,
  b.loyer_hc,
  b.charges_locatives,
  b.prix_vente_estime,
  b.rendement_brut,
  b.date_mise_en_vente,
  b.date_retrait_commercialisation,
  b.prix_demande_initial,
  b.prix_final_vente,
  b.priorite_vente,
  b.estimation_agence_vente,
  b.estimation_agence_location,
  -- Annonce courante (id max non archivée)
  a.id                              AS annonce_id,
  a.type_transaction,
  a.statut                          AS annonce_statut,
  a.etat_publication,
  a.prix                            AS annonce_prix,
  a.loyer                           AS annonce_loyer,
  a.honoraires                      AS annonce_honoraires,
  a.visible_portails,
  a.visible_maboximmo,
  a.date_mise_en_ligne,
  a.score_qualite                   AS annonce_score_qualite,
  -- KPIs agrégés
  COALESCE(leads.nb_leads_total, 0)      AS nb_leads_total,
  COALESCE(leads.nb_visites, 0)           AS nb_visites,
  COALESCE(leads.nb_offres_actives, 0)    AS nb_offres_actives,
  leads.meilleure_offre,
  leads.dernier_contact,
  leads.derniere_offre_date,
  COALESCE(docs.nb_docs, 0)               AS nb_docs,
  -- Statut métier dérivé
  CASE
    WHEN b.prix_final_vente IS NOT NULL OR b.date_retrait_commercialisation IS NOT NULL THEN 'vendu'
    WHEN COALESCE(leads.nb_offres_actives, 0) > 0 THEN 'offre_recue'
    WHEN a.etat_publication = 'diffusee' OR a.visible_portails = 1 OR a.visible_maboximmo = 1 THEN 'commercialise'
    WHEN b.statut_bien = 'actif' THEN 'pret'
    ELSE 'a_preparer'
  END                                     AS statut_transaction,
  b.date_creation                         AS bien_date_creation,
  b.date_modification                     AS bien_date_modification
FROM biens b
LEFT JOIN immeubles i ON i.id = b.id_immeuble
LEFT JOIN annonces a
  ON a.id_bien = b.id
 AND a.id = (
       SELECT MAX(a2.id) FROM annonces a2
       WHERE a2.id_bien = b.id
         AND (a2.statut IS NULL OR a2.statut <> 'archivee')
     )
LEFT JOIN (
  SELECT
    l.id_bien,
    COUNT(*) AS nb_leads_total,
    SUM(CASE WHEN l.type_contact IN ('visite','demande_visite') THEN 1 ELSE 0 END) AS nb_visites,
    SUM(CASE WHEN l.type_contact = 'offre'
              AND (l.statut_offre IS NULL OR l.statut_offre NOT IN ('refusee','expiree')) THEN 1 ELSE 0 END) AS nb_offres_actives,
    MAX(CASE WHEN l.type_contact = 'offre' THEN l.prix_propose END) AS meilleure_offre,
    MAX(l.date_creation) AS dernier_contact,
    MAX(CASE WHEN l.type_contact = 'offre' THEN l.date_creation END) AS derniere_offre_date
  FROM leads_annonces l
  WHERE l.id_bien IS NOT NULL
  GROUP BY l.id_bien
) AS leads ON leads.id_bien = b.id
LEFT JOIN (
  SELECT
    CAST(JSON_UNQUOTE(JSON_EXTRACT(d.metadata, '$.classement.bien_id_bdd')) AS UNSIGNED) AS bien_id,
    COUNT(*) AS nb_docs
  FROM ged_documents d
  WHERE d.status = 'active'
    AND d.source_module = '05_TRANSACTION'
    AND JSON_EXTRACT(d.metadata, '$.classement.bien_id_bdd') IS NOT NULL
  GROUP BY bien_id
) AS docs ON docs.bien_id = b.id
WHERE b.type_commercialisation IS NOT NULL
  AND b.type_commercialisation <> ''
  AND (b.statut_bien IS NULL OR b.statut_bien NOT IN ('supprime','archive'));
