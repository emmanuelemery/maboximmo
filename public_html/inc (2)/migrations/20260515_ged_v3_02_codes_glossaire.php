<?php
/**
 * Migration v3.02 — Glossaire centralisé des codes utilisés pour nommer les
 * fichiers (sociétés, agences, users, banques, immeubles, types document, etc.)
 *
 * Validé EMERY 2026-05-15.
 *
 * Une seule table multi-catégories. Le code dans un nom de fichier est résolu
 * par lookup glossaire UNIQUEMENT (plus de dérivation à la volée).
 *
 * Table :
 *   - ged_codes_glossaire
 *
 * NB : on n'intègre PAS encore le glossaire dans le nommage (phase 3 à venir).
 * Cette migration crée juste la table. Le seed est fait via la page admin
 * /admin/admin_ged_glossaire.php.
 *
 * Idempotent.
 */

return [
    'id'          => '20260515_ged_v3_02_codes_glossaire',
    'title'       => 'GED V3.02 — Glossaire centralisé des codes (sociétés, agences, users, immeubles, types doc...)',
    'description' => "Crée la table ged_codes_glossaire : nomenclature stable des codes courts utilisés dans les noms de fichiers. Multi-catégories (societe, agence, user, banque, immeuble, type_document, fournisseur, metier_n1/n2/n3, exercice). Lookup par (tenant_id, category, entity_id) ou (tenant_id, category, code). Codes verrouillables (is_locked). Aucun seed (fait via admin/admin_ged_glossaire.php). Idempotent.",
    'created_at'  => '2026-05-15',
    'sql' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `ged_codes_glossaire` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id`    INT UNSIGNED NOT NULL COMMENT 'Isolation multi-tenant',
  `category`     VARCHAR(40) NOT NULL COMMENT 'societe|agence|user|banque|immeuble|type_document|fournisseur|proprietaire|metier_n1|metier_n2|metier_n3|exercice|autre',
  `entity_table` VARCHAR(60) NULL COMMENT 'Table source (societes|agences|users|immeubles|...) NULL = code libre',
  `entity_id`    BIGINT UNSIGNED NULL COMMENT 'FK vers entity_table.id, NULL = code libre',
  `code`         VARCHAR(20) NOT NULL COMMENT 'Code court utilisé dans le nom de fichier (ex: RE, LYO, 0042, ALIZEE)',
  `label`        VARCHAR(255) NOT NULL COMMENT 'Libellé humain (cache de la source pour lisibilité admin)',
  `is_locked`    TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = code figé (non régénéré au reseed). 0 = peut être écrasé par seed auto',
  `is_active`    TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = code retiré du glossaire (ne sert plus aux noms)',
  `notes`        TEXT NULL COMMENT 'Notes libres admin',
  `created_by`   INT UNSIGNED NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_glossaire_code`   (`tenant_id`, `category`, `code`),
  UNIQUE KEY `uk_glossaire_entity` (`tenant_id`, `category`, `entity_table`, `entity_id`),
  INDEX `idx_glossaire_lookup_entity` (`tenant_id`, `category`, `entity_id`),
  INDEX `idx_glossaire_category` (`category`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
];
