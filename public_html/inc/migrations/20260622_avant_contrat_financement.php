<?php
/**
 * Migration : Avant-contrat — jalons de financement de l'acquéreur.
 * Ajoute les dates de demande et d'accord de financement (suivi du prêt
 * au-delà de la simple condition suspensive). Additive, rejouable.
 */

return [
    'id'          => '20260622_avant_contrat_financement',
    'title'       => 'Avant-contrat — demande & accord de financement',
    'description' => 'Ajoute demande_financement_date et accord_financement_date à dossier_avant_contrat.',
    'created_at'  => '2026-06-22',
    'sql' => <<<'SQL'
ALTER TABLE `dossier_avant_contrat` ADD COLUMN IF NOT EXISTS `demande_financement_date` DATE NULL;
ALTER TABLE `dossier_avant_contrat` ADD COLUMN IF NOT EXISTS `accord_financement_date` DATE NULL;
SQL,
];
