<?php
/**
 * Migration 20260630 — FluxBox : résolution de contexte fiable + type requêtable
 *
 * AJOUTS UNIQUEMENT (idempotent, ne casse rien) :
 *   1. ged_document_types         : vocabulaire contrôlé des types de document
 *                                   (CV, CNI, RIB, KBIS, BAIL, MANDAT, DPE, …) — extensible.
 *   2. fluxbox_cartes.type_document   + index  : le « quoi » requêtable sur la carte.
 *   3. fluxbox_cartes.resolution_json (TEXT)   : persiste le retour exact de
 *                                   fluxbox_resoudre_contexte() (confiance + source par champ).
 *   4. ged_documents.type_document    + index  : le « quoi » requêtable sur le doc GED final.
 *   5. fluxbox_ancres              : ancres non résolues → rattachement rétroactif.
 *
 * Le chemin (folder GED) dit OÙ ; type_document dit QUOI. Indépendants.
 * Cohérent avec [[project_fluxbox_module]] et le contrat fluxbox_resoudre_contexte().
 * Idempotent : CREATE TABLE IF NOT EXISTS + ADD COLUMN IF NOT EXISTS + INSERT … ON DUPLICATE KEY.
 */

return [
    'id'          => '20260630_fluxbox_resolution_contexte',
    'title'       => 'FluxBox — résolution contexte fiable + type_document requêtable + ancres',
    'description' => "Ajoute le vocabulaire contrôlé ged_document_types (16 valeurs initiales), la colonne indexée type_document sur fluxbox_cartes ET ged_documents (le « quoi », indépendant du chemin), la colonne resolution_json sur fluxbox_cartes (confiance + source par champ), et la table fluxbox_ancres (rattachement rétroactif des ancres non résolues). Ajouts seulement, idempotent.",
    'created_at'  => '2026-06-30',
    'sql' => <<<'SQL'
-- ════════════════════════════════════════════════════════════════════════
-- 1. ged_document_types — vocabulaire contrôlé des types de document
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `ged_document_types` (
  `code`       VARCHAR(40)  NOT NULL COMMENT 'Code stable du type (CV, RIB, BAIL, …)',
  `libelle`    VARCHAR(120) NOT NULL COMMENT 'Libellé humain affiché',
  `actif`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `ged_document_types` (`code`,`libelle`,`actif`) VALUES
  ('CV','Curriculum vitae',1),
  ('CNI','Pièce d''identité',1),
  ('RIB','Relevé d''identité bancaire',1),
  ('KBIS','Extrait Kbis',1),
  ('BAIL','Bail',1),
  ('MANDAT','Mandat',1),
  ('DPE','Diagnostic de performance énergétique',1),
  ('RELEVE_BANCAIRE','Relevé bancaire',1),
  ('BILAN','Bilan comptable',1),
  ('PV_AG','Procès-verbal d''assemblée générale',1),
  ('DIPLOME','Diplôme',1),
  ('CONTRAT_TRAVAIL','Contrat de travail',1),
  ('ATTESTATION','Attestation',1),
  ('FACTURE','Facture',1),
  ('JUGEMENT','Jugement / décision de justice',1),
  ('AUTRE','Autre / non classé',1)
ON DUPLICATE KEY UPDATE `libelle` = VALUES(`libelle`);

-- ════════════════════════════════════════════════════════════════════════
-- 2. fluxbox_cartes — type_document requêtable + resolution_json
-- ════════════════════════════════════════════════════════════════════════
ALTER TABLE `fluxbox_cartes`
  ADD COLUMN IF NOT EXISTS `type_document` VARCHAR(40) NULL
    COMMENT 'Vocabulaire contrôlé ged_document_types — le « quoi » (indépendant du chemin)'
    AFTER `naming_proposed`;

ALTER TABLE `fluxbox_cartes`
  ADD COLUMN IF NOT EXISTS `resolution_json` TEXT NULL
    COMMENT 'Retour exact de fluxbox_resoudre_contexte() : confiance + source par champ'
    AFTER `type_document`;

ALTER TABLE `fluxbox_cartes`
  ADD INDEX IF NOT EXISTS `idx_fluxbox_cartes_type_document` (`tenant_id`, `type_document`);

-- ════════════════════════════════════════════════════════════════════════
-- 3. ged_documents — type_document requêtable (le doc final)
--    NB : ged_documents possède déjà document_type (libre) ; on ajoute
--    type_document (vocabulaire contrôlé) pour la requête « tous les CV ».
-- ════════════════════════════════════════════════════════════════════════
ALTER TABLE `ged_documents`
  ADD COLUMN IF NOT EXISTS `type_document` VARCHAR(40) NULL
    COMMENT 'Vocabulaire contrôlé ged_document_types — requêtable sur tout le périmètre'
    AFTER `document_type`;

ALTER TABLE `ged_documents`
  ADD INDEX IF NOT EXISTS `idx_ged_documents_type_document` (`tenant_id`, `type_document`);

-- ════════════════════════════════════════════════════════════════════════
-- 4. fluxbox_ancres — ancres non résolues (rattachement rétroactif)
-- ════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `fluxbox_ancres` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`   INT UNSIGNED NOT NULL,
  `carte_id`    BIGINT UNSIGNED NOT NULL,
  `niveau`      VARCHAR(40) NOT NULL COMMENT 'Champ concerné : societe|agence|entite|type|metier|domaine',
  `valeur_brute` VARCHAR(255) NOT NULL COMMENT 'Valeur extraite non résolue (nom, SIREN, adresse…)',
  `entite_type` VARCHAR(40)  NULL COMMENT 'Type d''entité cible présumé : TIERS|USER|IMB|SOCIETE…',
  `entite_id`   BIGINT UNSIGNED NULL COMMENT 'Renseigné quand la fiche apparaît (rattachement rétroactif)',
  `statut`      ENUM('en_attente','resolu','ignore') NOT NULL DEFAULT 'en_attente',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_fluxbox_ancres_carte` (`carte_id`),
  INDEX `idx_fluxbox_ancres_statut` (`tenant_id`, `statut`, `entite_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];
