<?php
/**
 * Migration : bien_baux — FK directe vers le tiers locataire
 *
 * Ajoute `bien_baux.id_tiers_locataire` pour permettre un JOIN direct
 * sans passer par tiers_roles. Reste compatible avec l'architecture
 * tiers_roles qui demeure source de vérité pour les rôles multiples.
 *
 * Pour le BAILLEUR/PROPRIÉTAIRE : pas de colonne nouvelle — on utilise
 * la chaîne existante biens.id_proprietaire → proprietaires.id_tiers
 * (cohérent avec le backfill du 2026-04-19).
 *
 * Convention de nommage : préfixe `id_tiers_*` (cohérent avec
 * proprietaires.id_tiers, mandants.id_tiers, agency_mandant.id_tiers).
 */

return [
    'id'          => '20260518_bien_baux_tiers_locataire',
    'title'       => 'bien_baux — FK directe id_tiers_locataire',
    'description' => "Ajoute la colonne `id_tiers_locataire` (FK vers tiers.id, nullable) pour accéder au locataire en JOIN direct. tiers_roles reste source de vérité canonique. Pour le bailleur, la chaîne existante (biens.id_proprietaire → proprietaires.id_tiers) suffit.",
    'created_at'  => '2026-05-18',
    'sql'         => <<<'SQL'

ALTER TABLE `bien_baux`
    ADD COLUMN IF NOT EXISTS `id_tiers_locataire` INT UNSIGNED NULL DEFAULT NULL
        COMMENT 'FK directe vers tiers.id (locataire principal). Pour multi-locataires : voir tiers_roles.'
        AFTER `locataire_telephone`,
    ADD INDEX IF NOT EXISTS `idx_bb_tiers_locataire` (`id_tiers_locataire`);

-- FK : on l'ajoute via un check séparé (idempotent — pas de syntaxe IF NOT EXISTS sur ADD CONSTRAINT)
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bien_baux'
      AND CONSTRAINT_NAME = 'fk_bb_tiers_locataire' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE `bien_baux` ADD CONSTRAINT `fk_bb_tiers_locataire` FOREIGN KEY (`id_tiers_locataire`) REFERENCES `tiers`(`id`) ON DELETE SET NULL',
    'SELECT ''skip fk_bb_tiers_locataire'' AS _');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SQL
];
