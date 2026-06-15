<?php
/**
 * Migration : `dossier_vente.reference` — référence propre du dossier de vente.
 *
 * Format : <code_agence>-DV-AAMM-id  (ex. 69-2-DV-2606-0068)
 *   - code_agence depuis agences (fallback 'MBI' si absent)
 *   - DV = Dossier Vente · AAMM = année/mois de création · id sur 4 chiffres
 *   - unique par construction (id), pas de compteur.
 *
 * Le dossier agrège N biens et survit à la revente → réf distincte de la réf bien.
 * Idempotent. down = rollback documenté.
 */
return [
    'id'          => '20260614i_dossier_vente_reference',
    'title'       => 'dossier_vente.reference (réf propre du dossier)',
    'description' => "Ajoute dossier_vente.reference VARCHAR(50) au format <code_agence>-DV-AAMM-id et backfill l'existant.",
    'created_at'  => '2026-06-14',
    'sql' => <<<'SQL'
ALTER TABLE `dossier_vente` ADD COLUMN IF NOT EXISTS `reference` VARCHAR(50) NULL AFTER `id_bien`;

UPDATE `dossier_vente` dv
  LEFT JOIN `agences` a ON a.id = dv.id_agence
  SET dv.reference = CONCAT(COALESCE(NULLIF(a.code_agence,''),'MBI'), '-DV-',
                            DATE_FORMAT(dv.created_at,'%y%m'), '-', LPAD(dv.id,4,'0'))
  WHERE dv.reference IS NULL OR dv.reference = '';
SQL
    ,
    'down' => <<<'SQL'
ALTER TABLE `dossier_vente` DROP COLUMN `reference`;
SQL
];
