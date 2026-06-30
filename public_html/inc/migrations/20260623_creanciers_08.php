<?php
/**
 * Migration : CRÉANCIERS — Fil d'actualité + Partage en lecture seule (V1).
 *
 *  - creancier_dossier_message.canal : sépare le CHAT IA ('chat', défaut, rétro-compat)
 *    du FIL D'ACTUALITÉ ('feed') où les intervenants postent des commentaires signés.
 *  - creancier_dossier_partage : jetons de partage public en lecture seule (Deal Room),
 *    avec expiration + révocation (jamais de suppression silencieuse).
 *
 * AJOUT uniquement (idempotent). Les lignes existantes restent en 'chat'.
 *
 * ── ROLLBACK (-- DOWN) ────────────────────────────────────────────────
 *   DROP TABLE IF EXISTS `creancier_dossier_partage`;
 *   ALTER TABLE `creancier_dossier_message` DROP COLUMN `canal`;
 */

return [
    'id'          => '20260623_creanciers_08',
    'title'       => 'CRÉANCIERS — fil d\'actualité (canal) + partage lecture seule',
    'description' => "Ajoute creancier_dossier_message.canal ('chat'|'feed') pour séparer le chat IA du fil d'actualité signé, et la table creancier_dossier_partage (jetons publics read-only, expiration + révocation). AJOUT uniquement.",
    'created_at'  => '2026-06-23',
    'sql' => <<<'SQL'
ALTER TABLE `creancier_dossier_message`
  ADD COLUMN IF NOT EXISTS `canal` ENUM('chat','feed') NOT NULL DEFAULT 'chat'
  COMMENT 'chat = assistant IA ; feed = fil d''actualité signé' AFTER `role`;

CREATE TABLE IF NOT EXISTS `creancier_dossier_partage` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_dossier` INT UNSIGNED NOT NULL,
  `token` CHAR(48) NOT NULL COMMENT 'Jeton URL public (aléatoire)',
  `libelle` VARCHAR(190) NULL COMMENT 'Étiquette du lien (ex. destinataire)',
  `expires_at` DATETIME NULL COMMENT 'NULL = sans expiration',
  `revoked_at` DATETIME NULL COMMENT 'Révoqué si renseigné',
  `nb_vues` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_view_at` DATETIME NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cdp_token` (`token`),
  KEY `idx_cdp_dossier` (`id_dossier`),
  CONSTRAINT `fk_cdp_dossier` FOREIGN KEY (`id_dossier`) REFERENCES `creancier_dossier` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
];
