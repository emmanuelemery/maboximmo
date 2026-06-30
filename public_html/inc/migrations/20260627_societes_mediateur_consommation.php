<?php
/**
 * Migration : médiateur de la consommation au niveau SOCIÉTÉ
 *
 * Le médiateur de la consommation (obligation Loi Hoguet / Code conso) est une
 * information juridique de la SOCIÉTÉ (entité juridique), commune à toutes ses
 * agences. On stocke nom, adresse, coordonnées et URL sur `societes`.
 * La page admin honoraires (agency_honoraires_config.php) pré-remplit et persiste
 * ces champs au niveau société (single source of truth).
 */

return [
    'id'          => '20260627_societes_mediateur_consommation',
    'title'       => 'Médiateur de la consommation (nom / adresse / coordonnées / URL) sur societes',
    'description' => "Ajoute les colonnes mediateur_nom, mediateur_adresse, mediateur_coordonnees, mediateur_url sur la table societes (mentions légales Loi Hoguet, niveau société).",
    'created_at'  => '2026-06-27',
    'sql' => <<<'SQL'
ALTER TABLE `societes`
  ADD COLUMN IF NOT EXISTS `mediateur_nom`         VARCHAR(190) NULL COMMENT 'Médiateur de la consommation : nom de l''organisme',
  ADD COLUMN IF NOT EXISTS `mediateur_adresse`     VARCHAR(255) NULL COMMENT 'Médiateur de la consommation : adresse postale',
  ADD COLUMN IF NOT EXISTS `mediateur_coordonnees` VARCHAR(255) NULL COMMENT 'Médiateur de la consommation : coordonnées (tél / email)',
  ADD COLUMN IF NOT EXISTS `mediateur_url`         VARCHAR(500) NULL COMMENT 'Médiateur de la consommation : URL du site de saisine';
SQL
];
