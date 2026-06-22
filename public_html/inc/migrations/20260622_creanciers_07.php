<?php
/**
 * Migration : CRÉANCIERS — analyse IA de synthèse du dossier (onglet « Dossier »).
 *
 * Stocke la synthèse générée par l'IA (ChatGPT/Claude) à partir des documents analysés
 * et chargés sur le dossier, pour la bonne compréhension de la situation.
 *
 * AJOUT uniquement (idempotent).
 *
 * ── ROLLBACK (-- DOWN) ──
 *   ALTER TABLE `creancier_dossier` DROP COLUMN `analyse_ia`, DROP COLUMN `analyse_ia_at`;
 */

return [
    'id'          => '20260622_creanciers_07',
    'title'       => 'CRÉANCIERS — analyse IA de synthèse du dossier',
    'description' => "Ajoute creancier_dossier.analyse_ia (LONGTEXT) + analyse_ia_at (DATETIME) pour la synthèse IA de l'onglet Dossier. AJOUT uniquement.",
    'created_at'  => '2026-06-22',
    'sql' => <<<'SQL'
ALTER TABLE `creancier_dossier`
  ADD COLUMN IF NOT EXISTS `analyse_ia` LONGTEXT NULL COMMENT 'Synthèse IA du dossier (extraite des documents)' AFTER `commentaire`,
  ADD COLUMN IF NOT EXISTS `analyse_ia_at` DATETIME NULL COMMENT 'Date de génération de la synthèse IA' AFTER `analyse_ia`;
SQL
];
