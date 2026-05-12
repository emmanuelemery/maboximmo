<?php
/**
 * Migration : colonnes des documents officiels au niveau SOCIÉTÉ
 *
 * CONTEXTE — DÉCISION ARCHITECTURALE (mémoire 2026-05-06)
 * Les 5 documents officiels d'une agence immobilière sont délivrés à la
 * SOCIÉTÉ (entité juridique), pas à l'établissement (agence) :
 *   - KBIS                           → société
 *   - Carte professionnelle CPI      → société
 *   - Garantie financière (Galian…)  → société
 *   - RC professionnelle             → société
 *   - Barème honoraires              → société (via lien URL)
 *
 * Une SAS/SARL immobilière a UN KBIS, UNE CPI, UNE GF, UNE RC pro — peu
 * importe le nombre d'établissements. Les agences "héritent" via JOIN.
 *
 * RESTENT AU NIVEAU AGENCE (par établissement, pas par société) :
 *   - Assurance MRI (Multi-Risques Immeuble) — par locaux
 *   - URL barème spécifique d'agence (différent du PDF société)
 *
 * REFACTOR
 * - Avant : on répliquait depuis rh_documents vers TOUTES les agences de
 *   la société (hack de compat avec affiches qui lisent agences.rc_pro).
 * - Après : single source of truth = `societes.*`. Les readers font JOIN
 *   societes via agences.id_societe. Pas de duplication, pas de drift.
 *
 * Les anciennes colonnes sur `agences` (rc_pro, garant_financier, etc.,
 * ajoutées par migration 20260508 ou pré-existantes) ne sont plus alimentées
 * mais restent en place pour rétrocompat. Une migration ultérieure pourra
 * les DROP une fois tous les readers passés sur le JOIN.
 */

return [
    'id'          => '20260508_2_societes_colonnes_officielles',
    'title'       => 'Documents officiels au niveau société (rc_pro, carte_pro_*, garant_*, kbis_*)',
    'description' => "Refactor architectural : KBIS / CPI / GF / RC pro / Barème = niveau société (mémoire 2026-05-06). Ajoute les colonnes BASE sur societes pour single source of truth. Les agences héritent via JOIN. Met fin à la réplication N agences → 1 société.",
    'created_at'  => '2026-05-08',
    'sql' => <<<'SQL'
ALTER TABLE `societes`
  ADD COLUMN IF NOT EXISTS `rc_pro`             VARCHAR(150)  NULL COMMENT 'Assureur RC pro (MMA, AXA, Allianz, Generali...) — répliqué depuis rh_documents type=rc_pro',
  ADD COLUMN IF NOT EXISTS `rc_pro_numero`      VARCHAR(80)   NULL COMMENT 'N° de contrat RC pro',
  ADD COLUMN IF NOT EXISTS `rc_pro_validite`    DATE          NULL COMMENT 'Date fin de validité RC pro (cron alertes 120/90/60j)',
  ADD COLUMN IF NOT EXISTS `rc_pro_montant`     DECIMAL(12,2) NULL COMMENT 'Plafond garantie RC pro (€)',
  ADD COLUMN IF NOT EXISTS `carte_pro_numero`   VARCHAR(50)   NULL COMMENT 'N° carte professionnelle CPI (loi Hoguet)',
  ADD COLUMN IF NOT EXISTS `carte_pro_cci`      VARCHAR(150)  NULL COMMENT 'CCI émettrice (ex : CCI Lyon Métropole)',
  ADD COLUMN IF NOT EXISTS `carte_pro_validite` DATE          NULL COMMENT 'Date fin de validité carte pro (cron alertes 120/90/60j)',
  ADD COLUMN IF NOT EXISTS `garant_financier`   VARCHAR(150)  NULL COMMENT 'Nom du garant (Galian, Socaf, MMA Caution...)',
  ADD COLUMN IF NOT EXISTS `garant_validite`    DATE          NULL,
  ADD COLUMN IF NOT EXISTS `garant_montant`     DECIMAL(12,2) NULL COMMENT 'Plafond garantie financière (€)',
  ADD COLUMN IF NOT EXISTS `kbis_numero`        VARCHAR(50)   NULL COMMENT 'N° RCS du KBIS',
  ADD COLUMN IF NOT EXISTS `kbis_date`          DATE          NULL COMMENT 'Date émission KBIS (recommandé ≤ 3 mois)',
  ADD COLUMN IF NOT EXISTS `bareme_url`         VARCHAR(500)  NULL COMMENT 'URL publique du barème (page ou PDF) — page publique du site agence',
  ADD COLUMN IF NOT EXISTS `bareme_url_doc`     VARCHAR(500)  NULL COMMENT 'URL téléchargement direct PDF barème (chemin /uploads/rh_docs/...)';

-- Backfill : si les colonnes existaient déjà sur agences avec des valeurs (réplication
-- précédente), on ramène la valeur la plus récente sur la société. Idempotent.
-- Si une colonne agences.* n'existe pas, le UPDATE échoue silencieusement (skip).
SET @hasAgRcPro := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agences' AND COLUMN_NAME = 'rc_pro');

SET @sqlBackfill := IF(@hasAgRcPro = 1, "
  UPDATE societes s
  LEFT JOIN (
    SELECT id_societe, MAX(rc_pro) AS rc_pro
    FROM agences WHERE rc_pro IS NOT NULL AND rc_pro != ''
    GROUP BY id_societe
  ) ag ON ag.id_societe = s.id
  SET s.rc_pro = COALESCE(s.rc_pro, ag.rc_pro)
", "DO 1");
PREPARE _bf FROM @sqlBackfill; EXECUTE _bf; DEALLOCATE PREPARE _bf;

SET @hasAgCartePro := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agences' AND COLUMN_NAME = 'carte_pro_numero');

SET @sqlBackfill := IF(@hasAgCartePro = 1, "
  UPDATE societes s
  LEFT JOIN (
    SELECT id_societe, MAX(carte_pro_numero) AS num, MAX(carte_pro_cci) AS cci
    FROM agences
    WHERE carte_pro_numero IS NOT NULL AND carte_pro_numero != ''
    GROUP BY id_societe
  ) ag ON ag.id_societe = s.id
  SET s.carte_pro_numero = COALESCE(s.carte_pro_numero, ag.num),
      s.carte_pro_cci    = COALESCE(s.carte_pro_cci,    ag.cci)
", "DO 1");
PREPARE _bf FROM @sqlBackfill; EXECUTE _bf; DEALLOCATE PREPARE _bf;

SET @hasAgGarant := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agences' AND COLUMN_NAME = 'garant_financier');

SET @sqlBackfill := IF(@hasAgGarant = 1, "
  UPDATE societes s
  LEFT JOIN (
    SELECT id_societe, MAX(garant_financier) AS g
    FROM agences WHERE garant_financier IS NOT NULL AND garant_financier != ''
    GROUP BY id_societe
  ) ag ON ag.id_societe = s.id
  SET s.garant_financier = COALESCE(s.garant_financier, ag.g)
", "DO 1");
PREPARE _bf FROM @sqlBackfill; EXECUTE _bf; DEALLOCATE PREPARE _bf;
SQL,
];
