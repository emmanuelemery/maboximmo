<?php
/**
 * Migration : correction des ADRESSES d'immeubles mal renseignées (proprio FOCH).
 * Les biens ci-dessous étaient rattachés à des immeubles aux adresses erronées
 * (352 Route de Genas / 7 Rue des Platanes / 15 Rue de l'Horloge). On corrige
 * l'adresse de l'immeuble RATTACHÉ À CHAQUE BIEN (ciblage par reference_bien,
 * stable en prod ; s'adapte aux IDs immeuble réels de la prod).
 *
 * Confirmé via CRG (proprio 260) :
 *   - 01080141-0015/-0020/-0022 (GERING, BACHOUR) → 14 Rue Péricaud 69008 Lyon
 *   - 01080139-1090/-2090       (LAAD x2, SREOUNA) → 12 Rue Passet 69007 Lyon
 *   - 01080140-0245             (MINE)             → Les Campanelles, Rillieux (69140)
 *
 * ⚠️ Rue exacte « Les Campanelles » à confirmer : ici 100 Rue des Contamines.
 * Additif / rejouable (UPDATE idempotent).
 */
return [
    'id'          => '20260606e_fix_adresses_immeubles_foch',
    'title'       => 'Correction adresses immeubles (Péricaud / Passet / Campanelles)',
    'description' => "Corrige l'adresse des immeubles rattachés aux biens GERING/BACHOUR (14 Rue Péricaud), LAAD (12 Rue Passet) et MINE (Les Campanelles, Rillieux).",
    'created_at'  => '2026-06-06',
    'sql' => <<<'SQL'
-- 1) 14 Rue Péricaud 69008 Lyon (GERING FLORENCE + BACHOUR NIZAM + lot -0020)
UPDATE `immeubles`
SET nom_immeuble = '14 RUE ANTOINE PERRICAUD',
    adresse_1    = '14 Rue Péricaud',
    code_postal  = '69008',
    ville        = 'Lyon'
WHERE id IN (
    SELECT DISTINCT id_immeuble FROM `biens`
    WHERE reference_bien IN ('01080141-0015','01080141-0020','01080141-0022')
      AND id_immeuble IS NOT NULL
);

-- 2) 12 Rue Passet 69007 Lyon (LAAD — 2 lots — + SREOUNA)
UPDATE `immeubles`
SET nom_immeuble = '12 RUE PASSET LYON 7',
    adresse_1    = '12 Rue Passet',
    code_postal  = '69007',
    ville        = 'Lyon'
WHERE id IN (
    SELECT DISTINCT id_immeuble FROM `biens`
    WHERE reference_bien IN ('01080139-1090','01080139-2090')
      AND id_immeuble IS NOT NULL
);

-- 3) Les Campanelles, Rillieux-la-Pape 69140 (MINE SEBASTIEN)
--    ⚠️ Rue à confirmer (100 Rue des Contamines).
UPDATE `immeubles`
SET nom_immeuble = 'LES CAMPANELLES RILLIEUX',
    adresse_1    = '100 Rue des Contamines',
    code_postal  = '69140',
    ville        = 'Rillieux-la-Pape'
WHERE id IN (
    SELECT DISTINCT id_immeuble FROM `biens`
    WHERE reference_bien IN ('01080140-0245')
      AND id_immeuble IS NOT NULL
);
SQL,
];
