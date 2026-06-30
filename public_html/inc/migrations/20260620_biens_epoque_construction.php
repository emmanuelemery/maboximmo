<?php
/**
 * Migration : époque de construction du bien (pour l'encadrement des loyers).
 *
 * Quand l'année exacte de construction n'est pas connue, on doit pouvoir choisir une
 * PÉRIODE (avant 1946 / 1946-1970 / 1971-1990 / après 1990) — catégories officielles
 * des observatoires des loyers / encadrement. Si l'année exacte existe, la période en
 * découle automatiquement (calculée côté UI, on stocke quand même la valeur résolue).
 *
 * Idempotent : ADD COLUMN IF NOT EXISTS (MariaDB).
 */
return [
    'id'          => '20260620_biens_epoque_construction',
    'title'       => 'Biens : colonne epoque_construction (encadrement des loyers)',
    'description' => "Ajoute biens.epoque_construction (avant_1946 | 1946_1970 | 1971_1990 | apres_1990). NULL si non renseigné.",
    'created_at'  => '2026-06-20',
    'sql' => <<<'SQL'
ALTER TABLE `biens`
  ADD COLUMN IF NOT EXISTS `epoque_construction` VARCHAR(20) NULL DEFAULT NULL
  COMMENT 'avant_1946 | 1946_1970 | 1971_1990 | apres_1990 — période pour encadrement loyers';
SQL
];
