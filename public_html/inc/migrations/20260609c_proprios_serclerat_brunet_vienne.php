<?php
/**
 * Migration : rattache les 2 derniers propriétaires sans agence à REGIE EMERY VIENNE.
 *   - SERCLERAT (#77 local) et BRUNET Agnès (#243 local) → agence code 38-1.
 * (SCI TISSOT → REGIE EMERY LYON est déjà traité par 20260609_proprios_cercle_sir_saby_agence_lyon.)
 *
 * Garde-fou : ne touche QUE les fiches encore sans agence (id_agence IS NULL).
 * Portable local/prod : agence résolue par CODE 38-1 (pas par id). Idempotent.
 */
return [
    'id'          => '20260609c_proprios_serclerat_brunet_vienne',
    'title'       => 'SERCLERAT + BRUNET → agence REGIE EMERY VIENNE (38-1)',
    'description' => "Rattache les propriétaires SERCLERAT et BRUNET (sans agence) à REGIE EMERY VIENNE (code 38-1).",
    'created_at'  => '2026-06-09',
    'sql' => <<<'SQL'
UPDATE `proprietaires`
SET id_agence = (SELECT id FROM `agences` WHERE code_agence = '38-1' ORDER BY id LIMIT 1)
WHERE id_agence IS NULL
  AND (SELECT id FROM `agences` WHERE code_agence = '38-1' LIMIT 1) IS NOT NULL
  AND nom IN ('SERCLERAT', 'BRUNET');
SQL,
];
