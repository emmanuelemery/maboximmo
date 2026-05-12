<?php
/**
 * Migration : activités par société + table RCP/GF par activité (T/G/S/M)
 *
 * BESOIN MÉTIER (2026-05-08)
 * Une société immobilière peut exercer plusieurs activités, chacune avec
 * ses propres attestations RC pro et garantie financière :
 *   - Transaction (T)  → RCP_T + GF_T
 *   - Gestion locative (G)  → RCP_G + GF_G
 *   - Syndic (S)  → RCP_S + GF_S
 *   - Marchand de biens (M)  → RCP_M + GF_M
 *
 * Donc max 4 RCP + 4 GF par société. Idem certaines sociétés ne font PAS
 * d'immobilier (ex: holding RH only). Les agences héritent par défaut des
 * activités de leur société, mais peuvent en avoir un sous-ensemble.
 *
 * SCHÉMA
 * - `societes` : flags activités exercées (transaction/gestion/syndic/
 *   marchand/immobilier/rh)
 * - `agences`  : mêmes flags, override par établissement
 * - Nouvelle table `societe_activites_docs` : 1 ligne par (id_societe,
 *   activite) avec les détails RCP + GF de cette activité
 *
 * COMPATIBILITÉ
 * Les colonnes societes.rc_pro / rc_pro_* / garant_* / garant_montant
 * (ajoutées par migration 20260508_2) restent en place pour les sociétés
 * mono-activité — agissent comme "RCP/GF par défaut" si l'entrée par
 * activité n'existe pas. Le helper resolveOfficialDocsByActivite() pioche
 * d'abord dans la table par activité, puis fallback sur les colonnes
 * génériques.
 */

return [
    'id'          => '20260508_4_activites_par_societe_agence',
    'title'       => 'Activités cochables (T/G/S/M/Immo/RH) + RCP/GF par activité (table societe_activites_docs)',
    'description' => "Met en place le multi-activité : flags cochables sur societes et agences, table dédiée pour les attestations RCP/GF par activité (4 max par société). Compatible avec l'existant — les colonnes génériques societes.rc_pro restent comme fallback.",
    'created_at'  => '2026-05-08',
    'sql' => <<<'SQL'
-- ────────────────────────────────────────────────────────────────────
-- 1. Flags activités sur societes
-- ────────────────────────────────────────────────────────────────────
ALTER TABLE `societes`
  ADD COLUMN IF NOT EXISTS `activite_immobilier`  TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'Société exerce une activité immobilière (vs holding RH-only)',
  ADD COLUMN IF NOT EXISTS `activite_transaction` TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Active si Transaction (T) exercée — RCP_T + GF_T requis',
  ADD COLUMN IF NOT EXISTS `activite_gestion`     TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Active si Gestion locative (G) exercée — RCP_G + GF_G requis',
  ADD COLUMN IF NOT EXISTS `activite_syndic`      TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Active si Syndic (S) exercé — RCP_S + GF_S requis',
  ADD COLUMN IF NOT EXISTS `activite_marchand`    TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Active si Marchand de biens (M) exercé — RCP_M + GF_M requis',
  ADD COLUMN IF NOT EXISTS `activite_rh`          TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Société gère du RH (paie, congés, salariés)';

-- ────────────────────────────────────────────────────────────────────
-- 2. Flags activités sur agences (héritent par défaut de la société)
-- ────────────────────────────────────────────────────────────────────
ALTER TABLE `agences`
  ADD COLUMN IF NOT EXISTS `activite_transaction` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `activite_gestion`     TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `activite_syndic`      TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `activite_marchand`    TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `activite_rh`          TINYINT(1) NOT NULL DEFAULT 0;

-- ────────────────────────────────────────────────────────────────────
-- 3. Table societe_activites_docs : RCP + GF par activité
-- ────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `societe_activites_docs` (
  `id_societe`          INT UNSIGNED NOT NULL,
  `activite`            ENUM('transaction','gestion','syndic','marchand') NOT NULL
                        COMMENT 'Activité Hoguet : T/G/S/M',

  -- RC Pro de l'activité
  `rc_pro_assureur`     VARCHAR(150)  NULL COMMENT 'Assureur (AXA, MMA, Allianz, Generali...)',
  `rc_pro_numero`       VARCHAR(80)   NULL COMMENT 'N° de contrat',
  `rc_pro_validite`     DATE          NULL COMMENT 'Date fin de validité',
  `rc_pro_montant`      DECIMAL(12,2) NULL COMMENT 'Plafond garantie (€)',
  `rc_pro_franchise`    DECIMAL(12,2) NULL COMMENT 'Franchise par sinistre (€)',

  -- Garantie financière de l'activité
  `garant_nom`          VARCHAR(150)  NULL COMMENT 'Nom du garant (Galian, Socaf, MMA Caution...)',
  `garant_numero`       VARCHAR(80)   NULL COMMENT 'N° de contrat garantie',
  `garant_validite`     DATE          NULL COMMENT 'Date fin de validité',
  `garant_montant`      DECIMAL(12,2) NULL COMMENT 'Plafond garantie (€)',

  -- Audit
  `id_rh_doc_rcp`       INT UNSIGNED  NULL COMMENT 'FK vers rh_documents.id pour la RCP (audit)',
  `id_rh_doc_gf`        INT UNSIGNED  NULL COMMENT 'FK vers rh_documents.id pour la GF (audit)',
  `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by`          INT UNSIGNED  NULL,

  PRIMARY KEY (`id_societe`, `activite`),
  KEY `idx_sad_validite_rcp`    (`rc_pro_validite`),
  KEY `idx_sad_validite_garant` (`garant_validite`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT 'Documents officiels par activité (T/G/S/M) — 4 lignes max par société';

-- ────────────────────────────────────────────────────────────────────
-- 4. Backfill activités sur societes (les sociétés existantes ont des biens
--    immo → on infère les activités depuis les biens / les rh_documents)
-- ────────────────────────────────────────────────────────────────────
-- Toute société qui a au moins un rh_documents type rcp_transaction → activite_transaction=1
UPDATE `societes` s
SET s.activite_transaction = 1
WHERE EXISTS (
  SELECT 1 FROM `rh_documents` d
  WHERE d.id_societe = s.id AND d.actif = 1 AND d.type_document = 'rcp_transaction'
);
UPDATE `societes` s
SET s.activite_gestion = 1
WHERE EXISTS (
  SELECT 1 FROM `rh_documents` d
  WHERE d.id_societe = s.id AND d.actif = 1 AND d.type_document = 'rcp_gestion'
);
UPDATE `societes` s
SET s.activite_syndic = 1
WHERE EXISTS (
  SELECT 1 FROM `rh_documents` d
  WHERE d.id_societe = s.id AND d.actif = 1 AND d.type_document = 'rcp_syndic'
);
UPDATE `societes` s
SET s.activite_marchand = 1
WHERE EXISTS (
  SELECT 1 FROM `rh_documents` d
  WHERE d.id_societe = s.id AND d.actif = 1 AND d.type_document = 'rcp_marchand'
);

-- Toute société avec des users actifs → activite_rh = 1 (gestion paie/congés)
UPDATE `societes` s
SET s.activite_rh = 1
WHERE EXISTS (SELECT 1 FROM `users` u WHERE u.id_societe = s.id AND u.actif = 1);

-- Toute société avec des biens → activite_immobilier = 1 (déjà 1 par défaut, idempotent)
-- ────────────────────────────────────────────────────────────────────
-- 5. Backfill activités sur agences (héritent de leur société)
-- ────────────────────────────────────────────────────────────────────
UPDATE `agences` a
JOIN `societes` s ON s.id = a.id_societe
SET a.activite_transaction = s.activite_transaction,
    a.activite_gestion     = s.activite_gestion,
    a.activite_syndic      = s.activite_syndic,
    a.activite_marchand    = s.activite_marchand,
    a.activite_rh          = s.activite_rh
WHERE a.activite_transaction = 0
  AND a.activite_gestion = 0
  AND a.activite_syndic = 0
  AND a.activite_marchand = 0
  AND a.activite_rh = 0;

-- ────────────────────────────────────────────────────────────────────
-- 6. Backfill societe_activites_docs depuis rh_documents OCR
-- ────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `societe_activites_docs`
  (id_societe, activite, rc_pro_assureur, rc_pro_numero, rc_pro_validite, rc_pro_montant, id_rh_doc_rcp)
SELECT
  d.id_societe,
  CASE d.type_document
    WHEN 'rcp_transaction' THEN 'transaction'
    WHEN 'rcp_gestion'     THEN 'gestion'
    WHEN 'rcp_syndic'      THEN 'syndic'
    WHEN 'rcp_marchand'    THEN 'marchand'
  END AS activite,
  d.emetteur,
  d.numero,
  d.date_validite,
  d.montant_garantie,
  d.id
FROM `rh_documents` d
WHERE d.categorie = 'societe' AND d.actif = 1
  AND d.type_document IN ('rcp_transaction','rcp_gestion','rcp_syndic','rcp_marchand')
  AND d.emetteur IS NOT NULL;

-- Idem pour les GF (UPDATE car la PK existe déjà après l'INSERT IGNORE des RCP)
UPDATE `societe_activites_docs` sad
JOIN (
  SELECT
    d.id_societe,
    CASE d.type_document
      WHEN 'gf_transaction' THEN 'transaction'
      WHEN 'gf_gestion'     THEN 'gestion'
      WHEN 'gf_syndic'      THEN 'syndic'
      WHEN 'gf_marchand'    THEN 'marchand'
    END AS activite,
    d.emetteur, d.numero, d.date_validite, d.montant_garantie, d.id
  FROM `rh_documents` d
  WHERE d.categorie = 'societe' AND d.actif = 1
    AND d.type_document IN ('gf_transaction','gf_gestion','gf_syndic','gf_marchand')
    AND d.emetteur IS NOT NULL
) gf ON gf.id_societe = sad.id_societe AND gf.activite = sad.activite
SET sad.garant_nom      = COALESCE(sad.garant_nom,      gf.emetteur),
    sad.garant_numero   = COALESCE(sad.garant_numero,   gf.numero),
    sad.garant_validite = COALESCE(sad.garant_validite, gf.date_validite),
    sad.garant_montant  = COALESCE(sad.garant_montant,  gf.montant_garantie),
    sad.id_rh_doc_gf    = COALESCE(sad.id_rh_doc_gf,    gf.id);

-- Insert GF des activités sans RCP (pas matchées par l'UPDATE ci-dessus)
INSERT IGNORE INTO `societe_activites_docs`
  (id_societe, activite, garant_nom, garant_numero, garant_validite, garant_montant, id_rh_doc_gf)
SELECT
  d.id_societe,
  CASE d.type_document
    WHEN 'gf_transaction' THEN 'transaction'
    WHEN 'gf_gestion'     THEN 'gestion'
    WHEN 'gf_syndic'      THEN 'syndic'
    WHEN 'gf_marchand'    THEN 'marchand'
  END AS activite,
  d.emetteur, d.numero, d.date_validite, d.montant_garantie, d.id
FROM `rh_documents` d
WHERE d.categorie = 'societe' AND d.actif = 1
  AND d.type_document IN ('gf_transaction','gf_gestion','gf_syndic','gf_marchand')
  AND d.emetteur IS NOT NULL;
SQL,
];
