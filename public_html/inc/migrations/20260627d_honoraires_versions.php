<?php
/**
 * Migration 2026-06-27d : versions de barème (brouillon / déployé).
 *
 * Table de snapshots JSON, à part des tables live (societe_honoraires /
 * societe_tarifs_honoraires) qui restent l'état PUBLIÉ lu par le helper.
 *
 *   - statut = 'brouillon' : provisoire, non appliqué (1 seul par société+agence).
 *   - statut = 'deploye'   : archive d'un barème déployé (historique, reprenable).
 *
 * payload_json = le contenu complet du formulaire honoraires (toutes sections).
 */
return [
    'id'          => '20260627d_honoraires_versions',
    'title'       => 'Table honoraires_versions (brouillon / déployé + historique)',
    'description' => "Snapshots JSON des barèmes : brouillon provisoire + versions déployées reprenables. Les tables live restent l'état publié.",
    'created_at'  => '2026-06-27',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `honoraires_versions` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_societe`   INT NOT NULL DEFAULT 0,
  `id_agence`    INT NOT NULL DEFAULT 0,
  `statut`       VARCHAR(12) NOT NULL DEFAULT 'brouillon',
  `label`        VARCHAR(190) NULL,
  `payload_json` MEDIUMTEXT NOT NULL,
  `created_by`   INT NULL,
  `created_at`   DATETIME NOT NULL,
  `deployed_at`  DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cible_statut` (`id_societe`,`id_agence`,`statut`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
];
