<?php
/**
 * Migration : Module CRÉANCIERS — capture générique des champs extraits (clé/valeur).
 *
 * creancier_doc_champ : stocke AUTOMATIQUEMENT chaque champ détecté par l'IA sur un
 * document (même inattendu), sans jamais altérer le schéma. Permet de tout conserver
 * et de tout requêter sans ALTER TABLE. Un champ récurrent pourra être « promu » en
 * vraie colonne plus tard (décision, via migration — jamais automatiquement).
 *
 * AJOUT uniquement (idempotent).
 *
 * ── ROLLBACK (-- DOWN) ──  DROP TABLE IF EXISTS `creancier_doc_champ`;
 */

return [
    'id'          => '20260622_creanciers_05',
    'title'       => 'CRÉANCIERS — champs extraits génériques (creancier_doc_champ)',
    'description' => "Table clé/valeur creancier_doc_champ : capture auto de tout champ extrait par l'IA (sans ALTER). Requêtable, lossless. AJOUT uniquement.",
    'created_at'  => '2026-06-22',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `creancier_doc_champ` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_dossier` INT UNSIGNED NULL,
  `ged_document_id` BIGINT UNSIGNED NULL,
  `cle` VARCHAR(150) NOT NULL COMMENT 'Nom du champ extrait (chemin pointé : creancier.nom, montants.total…)',
  `valeur` TEXT NULL,
  `source` ENUM('ia','manuel') NOT NULL DEFAULT 'ia',
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cdc_dossier_cle` (`id_dossier`,`cle`),
  KEY `idx_cdc_doc` (`ged_document_id`),
  KEY `idx_cdc_cle` (`cle`),
  CONSTRAINT `fk_cdc_dossier` FOREIGN KEY (`id_dossier`) REFERENCES `creancier_dossier` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
];
