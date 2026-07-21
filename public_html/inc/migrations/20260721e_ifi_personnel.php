<?php
/**
 * Migration : IFI — marquage des propriétaires « personnels ».
 *
 * L'IFI est un impôt PERSONNEL : sur le scénario IFI du partage patrimoine,
 * seuls les propriétaires détenus personnellement doivent apparaître.
 * Flag proprietaires.ifi_personnel + backfill (FOCH 260, SMH 17, MR&MME SABY 11,
 * Yves SABY 10). La valeur IFI reste stockée dans bien_prix (scenario 'ifi',
 * is_courant = historique natif) — pas de nouvelle table.
 */
return [
    'id'          => '20260721e_ifi_personnel',
    'title'       => 'IFI : flag proprietaires.ifi_personnel + backfill SCI perso',
    'description' => "Marque les propriétaires soumis à l'IFI personnel (FOCH, SMH, SABY).",
    'created_at'  => '2026-07-21',
    'sql' => <<<'SQL'
ALTER TABLE proprietaires ADD COLUMN ifi_personnel TINYINT(1) NOT NULL DEFAULT 0;
UPDATE proprietaires SET ifi_personnel = 1 WHERE societe LIKE '%FOCH%' OR societe LIKE '%SMH%' OR societe LIKE '%SABY%' OR nom LIKE '%SABY%';
SQL
];
