<?php
/**
 * Migration : Table immeubles_documents
 *
 * Centralise tous les documents rattachés à un immeuble (règlement copro,
 * AG, assurance, diagnostics communs, mandats de syndic, actes, etc.)
 * avec catégorisation par type et extraction IA optionnelle.
 *
 * Pendant de `biens_documents` côté bien/lot. Ne remplace aucune table
 * existante (rien à casser).
 *
 * Règle d'or : statements additifs (IF NOT EXISTS) → rejouables.
 */

return [
    'id'          => '20260419_immeubles_documents',
    'title'       => 'Table immeubles_documents (stockage documents rattachés à un immeuble)',
    'description' => 'Création de la table immeubles_documents avec catégorisation par type (diag/mandat/titre/fiche/divers/autre) + champ JSON pour extraction IA + résumé texte pour mode "divers".',
    'created_at'  => '2026-04-19',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `immeubles_documents` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_immeuble` INT UNSIGNED NOT NULL,
  `id_societe` INT UNSIGNED NULL,
  `id_agence` INT UNSIGNED NULL,

  `type_document` ENUM('diag','bail','mandat','titre','fiche','divers','autre') NOT NULL DEFAULT 'autre',
  `sous_type` VARCHAR(80) NULL,

  `nom_fichier` VARCHAR(255) NULL,
  `url_fichier` VARCHAR(255) NULL,
  `taille_octets` INT UNSIGNED NULL,
  `mime_type` VARCHAR(80) NULL,

  `extraction_json` JSON NULL,
  `resume_ia` TEXT NULL,
  `method_extraction` VARCHAR(32) NULL,

  `commentaire_admin` TEXT NULL,

  `id_user_created` INT UNSIGNED NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_immeuble` (`id_immeuble`),
  KEY `idx_type` (`type_document`),
  KEY `idx_societe` (`id_societe`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];
