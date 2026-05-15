<?php
/**
 * Migration v3.02 — FluxBox : statut de nommage Variante A
 *
 * Validé EMERY 2026-05-15.
 *
 * Variante A figée 2026-05-15 (voir [[project_fluxbox_naming_variant_a]]) :
 *   {societe}_{agence}_{user}_{JJMMAA}_{n1}_{n2}_{banque}_{compte4}_{MM-AAAA}_{immeuble}_{type}.ext
 *
 * Cycle de vie du nommage :
 *   pending → extracted → ready → applied
 *                       ↘ needs_review → ready (après action admin) → applied
 *
 * Colonnes ajoutées sur fluxbox_cartes :
 *   - naming_status         : où en est le nommage Variante A
 *   - naming_review_reason  : pourquoi en review (banque_unknown, immeuble_unknown, ...)
 *   - naming_extracted_json : sortie brute Vision (texte banque, compte, période, immeuble)
 *   - naming_resolved_json  : après matching glossaire (codes, compte4, MM-AAAA)
 *   - naming_proposed       : nom de fichier proposé final
 *   - naming_applied_at     : timestamp d'application sur le fichier
 *
 * Idempotent : ADD COLUMN IF NOT EXISTS.
 */

return [
    'id'          => '20260515_fluxbox_v3_02_naming_status',
    'title'       => 'FluxBox V3.02 — colonnes naming_* (Variante A) sur fluxbox_cartes',
    'description' => "Ajoute 6 colonnes à fluxbox_cartes pour piloter la Variante A : naming_status (pending/extracted/ready/needs_review/applied/skipped), naming_review_reason (banque_unknown, immeuble_unknown, compte4_missing, periode_missing, multiple), naming_extracted_json (Vision brut), naming_resolved_json (après matching glossaire), naming_proposed (nom final proposé), naming_applied_at. Idempotent.",
    'created_at'  => '2026-05-15',
    'sql' => <<<'SQL'
ALTER TABLE `fluxbox_cartes`
  ADD COLUMN IF NOT EXISTS `naming_status` ENUM('pending','extracted','ready','needs_review','applied','skipped')
    NOT NULL DEFAULT 'pending'
    COMMENT 'Cycle de vie du nommage Variante A';

ALTER TABLE `fluxbox_cartes`
  ADD COLUMN IF NOT EXISTS `naming_review_reason` VARCHAR(80) NULL
    COMMENT 'Si needs_review : banque_unknown | immeuble_unknown | compte4_missing | periode_missing | multiple | other';

ALTER TABLE `fluxbox_cartes`
  ADD COLUMN IF NOT EXISTS `naming_extracted_json` JSON NULL
    COMMENT 'Sortie brute Vision (banque_text, compte_text, periode_text, immeuble_text, type_text...)';

ALTER TABLE `fluxbox_cartes`
  ADD COLUMN IF NOT EXISTS `naming_resolved_json` JSON NULL
    COMMENT 'Après matching glossaire (banque_code, compte4, periode_mm_yyyy, immeuble_code, type_code)';

ALTER TABLE `fluxbox_cartes`
  ADD COLUMN IF NOT EXISTS `naming_proposed` VARCHAR(500) NULL
    COMMENT 'Nom de fichier Variante A proposé (avant application)';

ALTER TABLE `fluxbox_cartes`
  ADD COLUMN IF NOT EXISTS `naming_applied_at` DATETIME NULL
    COMMENT 'Quand le nom Variante A a été appliqué au fichier physique';

ALTER TABLE `fluxbox_cartes`
  ADD INDEX IF NOT EXISTS `idx_fluxbox_cartes_naming_status` (`tenant_id`, `naming_status`);

ALTER TABLE `fluxbox_cartes`
  ADD INDEX IF NOT EXISTS `idx_fluxbox_cartes_naming_review` (`naming_review_reason`);
SQL,
];
