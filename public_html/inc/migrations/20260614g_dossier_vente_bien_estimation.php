<?php
/**
 * Migration : `dossier_vente_bien.estimation` — montant d'estimation (avis de valeur)
 * par lot, DISTINCT du prix de vente du mandat.
 *
 * Le lot porte désormais : estimation (avis de valeur) + prix_vente (prix du mandat)
 * + loyer_reel + loyer_potentiel (loyer estimé).
 *
 * Idempotent (ADD COLUMN IF NOT EXISTS). down = rollback documenté.
 */
return [
    'id'          => '20260614g_dossier_vente_bien_estimation',
    'title'       => 'dossier_vente_bien.estimation (avis de valeur par lot)',
    'description' => "Ajoute dossier_vente_bien.estimation DECIMAL(12,2) : montant d'estimation (avis de valeur) par lot, distinct du prix du mandat (prix_vente).",
    'created_at'  => '2026-06-14',
    'sql' => <<<'SQL'
ALTER TABLE `dossier_vente_bien`
  ADD COLUMN IF NOT EXISTS `estimation` DECIMAL(12,2) NULL AFTER `id_bien`;
SQL
    ,
    'down' => <<<'SQL'
ALTER TABLE `dossier_vente_bien` DROP COLUMN `estimation`;
SQL
];
