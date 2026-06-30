<?php
/**
 * Migration : table `immeubles_gestion` (couche mandat scopé de l'archi immeubles 3 couches).
 *
 * Contexte : la table a été créée + peuplée DIRECTEMENT en prod (439 SYNDIC) sans migration
 * versionnée → absente des BDD dev/local, ce qui faisait planter agency_immeubles.php
 * (SQLSTATE 42S02 : table doesn't exist). On la versionne ici pour la reproduire partout.
 *
 * CREATE TABLE IF NOT EXISTS → strictement inoffensif en prod (no-op, la table y existe déjà
 * avec ses données). En local/dev, crée la table vide → la page fonctionne (0 mandat affiché).
 *
 * Colonnes reconstruites depuis les usages : agency_immeubles.php (ig.type, ig.id_immeuble,
 * ig.id_gestionnaire) + immeuble_360.php (ig.id_societe, id_agence, id_gestionnaire,
 * id_assistante, id_comptable). NE PAS confondre avec `agency_mandat` (à conserver).
 */
return [
    'id'          => '20260616b_immeubles_gestion',
    'title'       => 'Immeubles : table immeubles_gestion (couche mandat scopé)',
    'description' => "Crée immeubles_gestion si absente (dev/local). No-op en prod où elle existe déjà. Débloque agency_immeubles.php.",
    'created_at'  => '2026-06-16',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `immeubles_gestion` (
  `id`              INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_immeuble`     INT(10) UNSIGNED NOT NULL,
  `type`            VARCHAR(30) NOT NULL DEFAULT 'SYNDIC',
  `id_societe`      INT(10) UNSIGNED NULL,
  `id_agence`       INT(10) UNSIGNED NULL,
  `id_gestionnaire` INT(10) UNSIGNED NULL,
  `id_assistante`   INT(10) UNSIGNED NULL,
  `id_comptable`    INT(10) UNSIGNED NULL,
  `date_debut`      DATE NULL,
  `date_fin`        DATE NULL,
  `statut`          VARCHAR(20) NULL DEFAULT 'actif',
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_immeuble` (`id_immeuble`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
];
