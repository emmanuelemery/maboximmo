<?php
/**
 * Migration : unification GROUPE SIR au niveau du TIERS (et non du proprietaire).
 *
 * La compta CRG impose 1 compte par (proprietaire, année, trimestre) → on ne peut pas
 * fusionner les fiches proprietaire. La bonne unité d'« un seul propriétaire » est le
 * TIERS. On rattache donc les fiches proprietaire du groupe (SIR IMMO / GROUPE SIR / SIR)
 * au MÊME tiers que « SARL GROUPE SIR » (tiers personne morale). Les comptes CRG restent
 * distincts ; le regroupement « 1 seul GROUPE SIR » se fait à l'affichage (par tiers).
 *
 * Portable local/prod (ciblage par raison sociale / société). Idempotent (id_tiers IS NULL).
 */
return [
    'id'          => '20260609b_groupe_sir_tiers_unique',
    'title'       => 'GROUPE SIR : rattacher les fiches au tiers unique (SARL GROUPE SIR)',
    'description' => "Pose proprietaires.id_tiers = tiers « SARL GROUPE SIR » sur SIR IMMO / GROUPE SIR / SIR (qui étaient sans tiers). Comptes CRG conservés ; unification au niveau tiers.",
    'created_at'  => '2026-06-09',
    'sql' => <<<'SQL'
UPDATE `proprietaires`
SET id_tiers = (SELECT id FROM (SELECT id FROM `tiers` WHERE raison_sociale = 'SARL GROUPE SIR' ORDER BY id LIMIT 1) t)
WHERE id_tiers IS NULL
  AND (SELECT id FROM `tiers` WHERE raison_sociale = 'SARL GROUPE SIR' LIMIT 1) IS NOT NULL
  AND societe IN ('SARL GROUPE SIR (immo)', 'GROUPE SIR', 'SOCIETE IMMOBILIERE DU RHONE (SIR)');
SQL,
];
