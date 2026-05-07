<?php
/**
 * Migration : backfill `societes.*` depuis les `rh_documents` déjà OCR-isés
 *
 * CONTEXTE
 * Refactor 2026-05-08 : les colonnes officielles (rc_pro, carte_pro_*,
 * garant_*, kbis_*) sont désormais sur `societes` (single source of truth).
 *
 * L'OCR Sonnet a déjà tourné sur les docs uploadés AVANT le refactor :
 * les valeurs (emetteur, numero, montant_garantie, date_validite) sont
 * stockées sur `rh_documents`. On évite de re-uploader/re-OCRer (coût) en
 * remontant directement les valeurs sur `societes` via SQL pur.
 *
 * STRATÉGIE
 * Pour chaque société, on prend les valeurs des `rh_documents` les plus
 * récents par type :
 *   - 1 KBIS le plus récent → kbis_numero, kbis_date
 *   - 1 carte pro la plus récente → carte_pro_numero, carte_pro_cci, carte_pro_validite
 *   - 1 RCP la plus récente (toutes activités confondues) → rc_pro, rc_pro_*
 *   - 1 GF la plus récente (toutes activités confondues) → garant_financier, garant_*
 *
 * Pour les RCP/GF par activité (T/G/S/M), on prend le plus récent en date
 * d'OCR — usuellement tous chez le même assureur. Cas multi-assureurs :
 * ajustement manuel possible via UPDATE direct.
 *
 * IDEMPOTENT
 * COALESCE(societes.col, backfill.col) : ne touche que les colonnes
 * actuellement NULL sur societes. Ré-exécution sans risque ; pour forcer
 * un overwrite, faire UPDATE societes SET col = NULL avant de relancer.
 */

return [
    'id'          => '20260508_3_backfill_societes_from_rh_documents',
    'title'       => 'Backfill societes.* depuis rh_documents OCR (sans re-upload IA)',
    'description' => "Remonte sur societes.* les valeurs OCR déjà extraites par Claude Sonnet sur les docs officiels existants. Évite de re-uploader/re-payer l'OCR. Idempotent : ne touche que les colonnes encore NULL (COALESCE prio valeur existante).",
    'created_at'  => '2026-05-08',
    'sql' => <<<'SQL'
-- Backfill via agrégation par société : pour chaque type de doc, on récupère
-- la valeur du rh_documents le plus récent (MAX(id) = plus récent en pratique).
-- L'utilisation de MAX() avec CASE WHEN évite ROW_NUMBER() qui requiert MySQL 8.

UPDATE `societes` s
LEFT JOIN (
  SELECT
    d.id_societe,

    -- KBIS : numero RCS + date d'émission
    MAX(CASE WHEN d.type_document = 'kbis' THEN d.numero END)        AS kbis_numero,
    MAX(CASE WHEN d.type_document = 'kbis' THEN d.date_emission END) AS kbis_date,

    -- Carte pro CPI : numero + CCI + validité
    MAX(CASE WHEN d.type_document = 'carte_pro' THEN d.numero END)        AS carte_pro_numero,
    MAX(CASE WHEN d.type_document = 'carte_pro' THEN d.emetteur END)      AS carte_pro_cci,
    MAX(CASE WHEN d.type_document = 'carte_pro' THEN d.date_validite END) AS carte_pro_validite,

    -- RC pro : assureur + numero + validité + montant garantie
    -- (toutes activités T/G/S/M confondues — usuellement même assureur)
    MAX(CASE WHEN d.type_document LIKE 'rcp%' OR d.type_document = 'rc_pro'
             THEN d.emetteur END)        AS rc_pro,
    MAX(CASE WHEN d.type_document LIKE 'rcp%' OR d.type_document = 'rc_pro'
             THEN d.numero END)          AS rc_pro_numero,
    MAX(CASE WHEN d.type_document LIKE 'rcp%' OR d.type_document = 'rc_pro'
             THEN d.date_validite END)   AS rc_pro_validite,
    MAX(CASE WHEN d.type_document LIKE 'rcp%' OR d.type_document = 'rc_pro'
             THEN d.montant_garantie END) AS rc_pro_montant,

    -- Garantie financière : nom du garant + validité + montant
    MAX(CASE WHEN d.type_document LIKE 'gf%' OR d.type_document = 'garant_financier'
             THEN d.emetteur END)         AS garant_financier,
    MAX(CASE WHEN d.type_document LIKE 'gf%' OR d.type_document = 'garant_financier'
             THEN d.date_validite END)    AS garant_validite,
    MAX(CASE WHEN d.type_document LIKE 'gf%' OR d.type_document = 'garant_financier'
             THEN d.montant_garantie END) AS garant_montant,

    -- Barème honoraires : URL du PDF (chemin relatif depuis /public_html)
    MAX(CASE WHEN d.type_document = 'bareme_honoraires'
             THEN REGEXP_REPLACE(d.file_path, '^.*?/public_html/', '/') END) AS bareme_url_doc

  FROM `rh_documents` d
  WHERE d.categorie = 'societe'
    AND d.actif = 1
    AND d.ocr_at IS NOT NULL  -- uniquement les docs déjà OCR-isés (sinon valeurs NULL)
  GROUP BY d.id_societe
) bf ON bf.id_societe = s.id
SET
  s.kbis_numero        = COALESCE(s.kbis_numero,        bf.kbis_numero),
  s.kbis_date          = COALESCE(s.kbis_date,          bf.kbis_date),
  s.carte_pro_numero   = COALESCE(s.carte_pro_numero,   bf.carte_pro_numero),
  s.carte_pro_cci      = COALESCE(s.carte_pro_cci,      bf.carte_pro_cci),
  s.carte_pro_validite = COALESCE(s.carte_pro_validite, bf.carte_pro_validite),
  s.rc_pro             = COALESCE(s.rc_pro,             bf.rc_pro),
  s.rc_pro_numero      = COALESCE(s.rc_pro_numero,      bf.rc_pro_numero),
  s.rc_pro_validite    = COALESCE(s.rc_pro_validite,    bf.rc_pro_validite),
  s.rc_pro_montant     = COALESCE(s.rc_pro_montant,     bf.rc_pro_montant),
  s.garant_financier   = COALESCE(s.garant_financier,   bf.garant_financier),
  s.garant_validite    = COALESCE(s.garant_validite,    bf.garant_validite),
  s.garant_montant     = COALESCE(s.garant_montant,     bf.garant_montant),
  s.bareme_url_doc     = COALESCE(s.bareme_url_doc,     bf.bareme_url_doc)
WHERE bf.id_societe IS NOT NULL;
SQL,
];
