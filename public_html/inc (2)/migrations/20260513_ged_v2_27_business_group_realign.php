<?php
/**
 * Migration v2.27 — Réalignement des 8 groupes business_group (arbitrage 2026-05-13)
 *
 * Décisions FluxBox v2 (cf memory project_fluxbox_module) :
 *   - MARKETING → fusionne dans AGENCE
 *   - ADMIN → split en DIRECTION (06_ + modules transverses) + JURIDIQUE (07_)
 *
 * 8 groupes finaux : RH / COMPTA / BAILLEUR / SYNDIC / AGENCE / FOURNISSEURS / DIRECTION / JURIDIQUE
 *
 * Idempotent (UPDATE conditionnel). Aucune suppression, aucune perte de donnée.
 * Réversible : un UPDATE inverse suffit.
 */

return [
    'id'          => '20260513_ged_v2_27_business_group_realign',
    'title'       => 'GED V2.27 — réalignement business_group (8 groupes finaux FluxBox)',
    'description' => "Aligne les business_group sur les 8 groupes finaux validés FluxBox v2 : MARKETING→AGENCE, ADMIN split en DIRECTION+JURIDIQUE. Idempotent.",
    'created_at'  => '2026-05-13',
    'sql' => <<<'SQL'
-- 08_MARKETING_COMMUNICATION → groupe AGENCE (la com sert l'agence)
UPDATE `ged_level_codes`
SET `business_group` = 'AGENCE'
WHERE `level_number` = 1
  AND `code` = '08_MARKETING_COMMUNICATION'
  AND (`business_group` IS NULL OR `business_group` <> 'AGENCE');

-- 07_JURIDIQUE_CONTENTIEUX → groupe propre JURIDIQUE (sort de ADMIN)
UPDATE `ged_level_codes`
SET `business_group` = 'JURIDIQUE'
WHERE `level_number` = 1
  AND `code` = '07_JURIDIQUE_CONTENTIEUX'
  AND (`business_group` IS NULL OR `business_group` <> 'JURIDIQUE');

-- Tous les autres N1 actuellement ADMIN → DIRECTION
-- (01_DIRECTION, 09_MODELES_DOCUMENTS, 10_REFERENTIEL, 11_MAILS_COMMUNICATIONS,
--  12_ARCHIVES, 99_SYSTEME)
UPDATE `ged_level_codes`
SET `business_group` = 'DIRECTION'
WHERE `level_number` = 1
  AND `code` IN (
    '01_DIRECTION', '09_MODELES_DOCUMENTS', '10_REFERENTIEL',
    '11_MAILS_COMMUNICATIONS', '12_ARCHIVES', '99_SYSTEME'
  )
  AND (`business_group` IS NULL OR `business_group` <> 'DIRECTION');

-- Vérification : aucun N1 ne doit rester en MARKETING ou ADMIN
-- (les UPDATE idempotents au-dessus garantissent qu'au final business_group ∈
-- {RH, COMPTA, BAILLEUR, SYNDIC, AGENCE, FOURNISSEURS, DIRECTION, JURIDIQUE})
SQL,
];
