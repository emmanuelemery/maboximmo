<?php
/**
 * Migration : FK directes sur ged_documents (id_bien, id_immeuble, id_bail)
 *
 * Évite de devoir interroger JSON_EXTRACT(metadata, '$.classement.bien_id_bdd')
 * à chaque requête. Permet à bien_detail.php et autres pages de lire les docs
 * Transaction depuis ged_documents sans coût SQL.
 *
 * Backfill : pour chaque ged_documents avec metadata.classement.bien_id_bdd,
 * recopie la valeur dans id_bien.
 *
 * Statements additifs uniquement (IF NOT EXISTS) → rejouables.
 */

return [
    'id'          => '20260522_ged_documents_fk_directes',
    'title'       => 'ged_documents — FK directes id_bien / id_immeuble / id_bail',
    'description' => "Ajout des FK directes sur ged_documents pour accélérer les requêtes par bien/immeuble/bail (au lieu de JSON_EXTRACT metadata). Backfill automatique depuis metadata.classement.bien_id_bdd existant.",
    'created_at'  => '2026-05-22',
    'sql'         => <<<'SQL'

ALTER TABLE `ged_documents`
    ADD COLUMN IF NOT EXISTS `id_bien`     INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'FK directe vers biens.id (raccourci JSON_EXTRACT(metadata))' AFTER `service_id`,
    ADD COLUMN IF NOT EXISTS `id_immeuble` INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'FK directe vers immeubles.id' AFTER `id_bien`,
    ADD COLUMN IF NOT EXISTS `id_bail`     INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'FK directe vers bien_baux.id' AFTER `id_immeuble`,
    ADD INDEX IF NOT EXISTS `idx_ged_id_bien`     (`id_bien`),
    ADD INDEX IF NOT EXISTS `idx_ged_id_immeuble` (`id_immeuble`),
    ADD INDEX IF NOT EXISTS `idx_ged_id_bail`     (`id_bail`);

-- ─── Backfill id_bien depuis metadata.classement.bien_id_bdd ───────────────
UPDATE `ged_documents`
SET `id_bien` = CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.classement.bien_id_bdd')) AS UNSIGNED)
WHERE `id_bien` IS NULL
  AND `metadata` IS NOT NULL
  AND JSON_EXTRACT(metadata, '$.classement.bien_id_bdd') IS NOT NULL
  AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.classement.bien_id_bdd')) REGEXP '^[0-9]+$';

-- Backfill id_immeuble (si présent dans metadata)
UPDATE `ged_documents`
SET `id_immeuble` = CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.classement.immeuble_id_bdd')) AS UNSIGNED)
WHERE `id_immeuble` IS NULL
  AND `metadata` IS NOT NULL
  AND JSON_EXTRACT(metadata, '$.classement.immeuble_id_bdd') IS NOT NULL
  AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.classement.immeuble_id_bdd')) REGEXP '^[0-9]+$';

SQL
];
