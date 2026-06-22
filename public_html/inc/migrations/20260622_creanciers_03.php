<?php
/**
 * Migration : Module CRÉANCIERS — commentaire avocat + chat de dossier.
 *
 *  - creancier_dossier.commentaire : commentaire/avis de l'avocat recopié sur le dossier.
 *  - creancier_dossier_message     : fil de discussion (chat) propre à chaque dossier
 *                                    (messages utilisateurs + réponses IA contextualisées).
 *
 * AJOUT uniquement (idempotent). NE TOUCHE À RIEN d'autre.
 *
 * ── ROLLBACK (-- DOWN) ────────────────────────────────────────────────
 *   DROP TABLE IF EXISTS `creancier_dossier_message`;
 *   ALTER TABLE `creancier_dossier` DROP COLUMN `commentaire`;
 */

return [
    'id'          => '20260622_creanciers_03',
    'title'       => 'CRÉANCIERS — commentaire avocat + chat de dossier',
    'description' => "Ajoute creancier_dossier.commentaire (avis avocat recopié) et la table creancier_dossier_message (chat par dossier, messages user + IA). AJOUT uniquement.",
    'created_at'  => '2026-06-22',
    'sql' => <<<'SQL'
ALTER TABLE `creancier_dossier`
  ADD COLUMN IF NOT EXISTS `commentaire` TEXT NULL COMMENT 'Commentaire / avis de l''avocat recopié sur le dossier' AFTER `synthese`;

CREATE TABLE IF NOT EXISTS `creancier_dossier_message` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_dossier` INT UNSIGNED NOT NULL,
  `role` ENUM('user','ia','system') NOT NULL DEFAULT 'user',
  `id_user` INT UNSIGNED NULL COMMENT 'Auteur si role=user',
  `message` TEXT NOT NULL,
  `meta` LONGTEXT NULL COMMENT 'Contexte IA / sources citées (JSON)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cdm_dossier` (`id_dossier`,`created_at`),
  CONSTRAINT `fk_cdm_dossier` FOREIGN KEY (`id_dossier`) REFERENCES `creancier_dossier` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
];
