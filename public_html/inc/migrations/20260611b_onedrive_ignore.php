<?php
/**
 * Migration : table des documents OneDrive IGNORÉS pour le classement GED.
 * Un doc ignoré (clé = item_id OneDrive) n'est plus proposé ni rescanné.
 * Idempotent : CREATE TABLE IF NOT EXISTS.
 */
return [
    'id'          => '20260611b_onedrive_ignore',
    'title'       => 'OneDrive : table des documents ignorés',
    'description' => "Crée onedrive_classer_ignore : mémorise les docs OneDrive écartés du classement GED (item_id), pour ne plus les reproposer.",
    'created_at'  => '2026-06-11',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `onedrive_classer_ignore` (
  `item_id`         VARCHAR(255) NOT NULL,
  `id_proprietaire` INT(10) UNSIGNED NULL,
  `name`            VARCHAR(255) NULL,
  `ignored_by`      INT(10) UNSIGNED NULL,
  `ignored_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`item_id`),
  KEY `idx_proprio` (`id_proprietaire`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
];
