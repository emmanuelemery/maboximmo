<?php
/**
 * Migration : Ma Box Net — vitrines SEO par agence.
 * Table des textes éditoriaux par agence + flag collaborateur visible.
 * Additif / rejouable (IF NOT EXISTS).
 */
return [
    'id'          => '20260621a_net_vitrine',
    'title'       => 'Ma Box Net — textes vitrines + visible_net',
    'description' => 'Crée agence_net_page (textes éditoriaux par agence/page) et ajoute users.visible_net.',
    'created_at'  => '2026-06-21',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `agence_net_page` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_agence` INT NOT NULL,
  `page_key` VARCHAR(40) NOT NULL,
  `titre` VARCHAR(255) NULL,
  `contenu_html` MEDIUMTEXT NULL,
  `meta_title` VARCHAR(255) NULL,
  `meta_description` VARCHAR(320) NULL,
  `actif` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_by` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_agence_page` (`id_agence`, `page_key`),
  KEY `idx_agence` (`id_agence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `visible_net` TINYINT(1) NOT NULL DEFAULT 0 AFTER `avatar_url`;
ALTER TABLE `users`
  ADD INDEX IF NOT EXISTS `idx_visible_net` (`id_agence`, `visible_net`);
SQL,
];
