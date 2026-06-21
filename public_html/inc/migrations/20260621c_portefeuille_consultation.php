<?php
/**
 * Migration : suivi enrichi des consultations de portefeuilles (p.php).
 * Journal d'événements open / view_bien / download. Additif / rejouable.
 */
return [
    'id'          => '20260621c_portefeuille_consultation',
    'title'       => 'Portefeuilles — journal des consultations',
    'description' => 'Crée portefeuille_consultation (open/view_bien/download) pour le suivi des liens p.php.',
    'created_at'  => '2026-06-21',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `portefeuille_consultation` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_envoi` INT UNSIGNED NOT NULL,
  `id_portefeuille` INT UNSIGNED NULL,
  `email` VARCHAR(190) NULL,
  `type` VARCHAR(20) NOT NULL,
  `id_bien` INT UNSIGNED NULL,
  `doc_id` INT UNSIGNED NULL,
  `doc_label` VARCHAR(190) NULL,
  `ip` VARCHAR(64) NULL,
  `user_agent` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_envoi` (`id_envoi`),
  KEY `idx_pf` (`id_portefeuille`),
  KEY `idx_type` (`type`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];
