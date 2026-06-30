<?php
/**
 * Migration : coordonnées destinataire + critères de recherche sur portefeuilles.
 * Permet de saisir le contact (commercialisateur/investisseur) et ses critères (prix/surface)
 * une seule fois — le nom du portefeuille est ensuite généré à partir de ces infos.
 * Additif / rejouable.
 */
return [
    'id'          => '20260606c_portefeuille_destinataire',
    'title'       => 'Portefeuilles — coordonnées destinataire + critères',
    'description' => 'Ajoute destinataire_prenom/email/tel et critere_prix_min/max, critere_surf_min/max sur portefeuilles.',
    'created_at'  => '2026-06-06',
    'sql' => <<<'SQL'
ALTER TABLE `portefeuilles`
  ADD COLUMN IF NOT EXISTS `destinataire_prenom` VARCHAR(120) NULL AFTER `destinataire_nom`;
ALTER TABLE `portefeuilles`
  ADD COLUMN IF NOT EXISTS `destinataire_email` VARCHAR(190) NULL AFTER `destinataire_prenom`;
ALTER TABLE `portefeuilles`
  ADD COLUMN IF NOT EXISTS `destinataire_tel` VARCHAR(40) NULL AFTER `destinataire_email`;
ALTER TABLE `portefeuilles`
  ADD COLUMN IF NOT EXISTS `critere_prix_min` DECIMAL(15,2) NULL AFTER `destinataire_tel`;
ALTER TABLE `portefeuilles`
  ADD COLUMN IF NOT EXISTS `critere_prix_max` DECIMAL(15,2) NULL AFTER `critere_prix_min`;
ALTER TABLE `portefeuilles`
  ADD COLUMN IF NOT EXISTS `critere_surf_min` DECIMAL(10,2) NULL AFTER `critere_prix_max`;
ALTER TABLE `portefeuilles`
  ADD COLUMN IF NOT EXISTS `critere_surf_max` DECIMAL(10,2) NULL AFTER `critere_surf_min`;
SQL,
];
