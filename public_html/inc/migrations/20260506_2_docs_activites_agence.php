<?php
/**
 * Migration : 4 RCP / 4 GF par activité + nouvelle rubrique Agence
 *
 * Correction métier 2026-05-06 :
 * - RC pro et Garantie financière : jusqu'à 4 attestations, 1 par activité
 *   (Transaction, Gestion, Syndic, Marchand de biens)
 * - Barème honoraires : par AGENCE (pas société)
 * - Assurance MRI (Multi-Risques Immeuble) : par AGENCE — nouveau type
 *
 * → Création d'une nouvelle rubrique 'agence' dans rh_doc_types
 * → Désactivation des types legacy rcp / garant_financier (génériques) au
 *   profit de 4 sous-types par activité chacun
 * → Bareme déplacé de société vers agence
 * → Ajout colonnes agences.mri_* pour la réplication
 */

return [
    'id'          => '20260506_2_docs_activites_agence',
    'title'       => 'Docs officiels : 4 RCP/GF par activité + rubrique Agence (barème + MRI)',
    'description' => 'Refonte métier : RCP/GF déclinés en 4 par activité (T/G/S/M), barème honoraires et nouvelle assurance MRI au niveau agence. Ajoute la rubrique "agence" dans rh_doc_types.',
    'created_at'  => '2026-05-06',
    'sql' => <<<'SQL'
-- ─── 1. Désactivation des types génériques legacy (remplacés par 4 sous-types) ──
UPDATE `rh_doc_types` SET `actif` = 0
  WHERE `rubrique` = 'societe' AND `type_key` IN ('rcp', 'garant_financier');

-- ─── 2. Déplacement barème honoraires : société → agence ─────────────────────
UPDATE `rh_doc_types` SET `rubrique` = 'agence', `ordre` = 1
  WHERE `type_key` = 'bareme_honoraires';

-- ─── 3. Seed 4 sous-types RCP par activité (rubrique societe) ────────────────
INSERT IGNORE INTO `rh_doc_types`
  (`rubrique`, `type_key`, `label`, `obligatoire`, `dispo`, `ordre`, `systeme`)
VALUES
  ('societe', 'rcp_transaction',    'RC pro — Transaction (T)',           1, 'public',  10, 1),
  ('societe', 'rcp_gestion',        'RC pro — Gestion (G)',               1, 'public',  11, 1),
  ('societe', 'rcp_syndic',         'RC pro — Syndic (S)',                0, 'public',  12, 1),
  ('societe', 'rcp_marchand',       'RC pro — Marchand de biens (M)',     0, 'public',  13, 1),

  ('societe', 'gf_transaction',     'Garantie financière — Transaction',  1, 'public',  20, 1),
  ('societe', 'gf_gestion',         'Garantie financière — Gestion',      1, 'public',  21, 1),
  ('societe', 'gf_syndic',          'Garantie financière — Syndic',       0, 'public',  22, 1),
  ('societe', 'gf_marchand',        'Garantie financière — Marchand',     0, 'public',  23, 1);

-- ─── 4. Seed types Agence (barème + MRI) ─────────────────────────────────────
INSERT IGNORE INTO `rh_doc_types`
  (`rubrique`, `type_key`, `label`, `obligatoire`, `dispo`, `ordre`, `systeme`)
VALUES
  ('agence', 'bareme_honoraires', 'Barème honoraires',          1, 'public', 1, 1),
  ('agence', 'assurance_mri',     'Assurance MRI (Multi-Risques)', 1, 'public', 2, 1);

-- ─── 5. Colonnes agences pour réplication MRI ────────────────────────────────
ALTER TABLE `agences`
  ADD COLUMN IF NOT EXISTS `mri_assureur`  VARCHAR(255) NULL,
  ADD COLUMN IF NOT EXISTS `mri_numero`    VARCHAR(150) NULL,
  ADD COLUMN IF NOT EXISTS `mri_validite`  DATE         NULL;

-- ─── 6. Colonne sur rh_documents pour stocker l'activité (T/G/S/M) ──────────
-- Permet de filtrer / regrouper les RCP / GF par activité plus facilement.
ALTER TABLE `rh_documents`
  ADD COLUMN IF NOT EXISTS `activite_code` VARCHAR(20) NULL COMMENT 'T / G / S / M pour les RCP et GF';
SQL,
];
