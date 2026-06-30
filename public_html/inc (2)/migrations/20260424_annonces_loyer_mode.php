<?php
/**
 * Migration : mode de loyer HC pour les annonces en location encadrée.
 *
 * 'libre'     : loyer HC saisie manuelle (zone non encadrée)
 * 'majore'    : loyer HC = loyer_reference_majore + SUM(lignes complément)
 * 'reference' : loyer HC = surface × enc_loyer_ref (complément INTERDIT, lignes conservées mais non sommées)
 * 'minore'    : loyer HC = surface × enc_loyer_min (complément INTERDIT, lignes conservées mais non sommées)
 *
 * Auto-switching :
 *   - activation zone_encadrement_loyer=1 → mode passe à 'majore'
 *   - désactivation zone_encadrement_loyer=0 → mode passe à 'libre'
 */

return [
    'id'          => '20260424_annonces_loyer_mode',
    'title'       => 'Annonces : mode de loyer HC (libre/majoré/référence/minoré)',
    'description' => "Ajoute loyer_mode à annonces pour gérer l'encadrement avec choix majoré/référence/minoré. En mode référence ou minoré, les compléments de loyer sont interdits (art. 18 loi 89-462).",
    'created_at'  => '2026-04-24',
    'sql' => <<<'SQL'
ALTER TABLE `annonces`
    ADD COLUMN IF NOT EXISTS `loyer_mode` ENUM('libre','majore','reference','minore') NOT NULL DEFAULT 'libre'
        COMMENT 'Mode calcul loyer HC : libre (manuelle), majore (réf + complément), reference (complément interdit), minore (complément interdit)' AFTER `loyer_est_cc`;

UPDATE `annonces`
   SET `loyer_mode` = CASE WHEN COALESCE(`zone_encadrement_loyer`, 0) = 1 THEN 'majore' ELSE 'libre' END
   WHERE `loyer_mode` = 'libre';
SQL,
];
