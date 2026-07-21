<?php
/**
 * Migration : socle générique « acteurs par entité ».
 *
 * Rattache un TIERS (contact) à n'importe quelle entité (BIEN, BAIL, IMB, TIERS,
 * CREANCIER_DOSSIER, FIN…) avec un rôle. Permet le bouton « + » d'ajout de contact
 * uniforme sur tous les 360, sans table dédiée par module.
 */
return [
    'id'          => '20260721c_entite_acteurs',
    'title'       => 'Socle acteurs par entité (contacts génériques 360)',
    'description' => "Table entite_acteurs : un tiers (contact) rattaché à une entité quelconque avec un rôle.",
    'created_at'  => '2026-07-21',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `entite_acteurs` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_societe`  INT UNSIGNED NULL,
  `entity_type` VARCHAR(30)  NOT NULL,
  `entity_id`   INT UNSIGNED NOT NULL,
  `id_tiers`    INT UNSIGNED NOT NULL,
  `role`        VARCHAR(60)  NULL,
  `note`        VARCHAR(255) NULL,
  `created_by`  INT UNSIGNED NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_acteur` (`entity_type`,`entity_id`,`id_tiers`,`role`),
  KEY `idx_entity` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
];
