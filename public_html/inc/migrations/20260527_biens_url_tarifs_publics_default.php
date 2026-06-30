<?php
/**
 * Migration : ajout colonne biens.url_tarifs_publics + backfill par défaut
 *
 * 1. Crée la colonne url_tarifs_publics sur biens si elle n'existe pas
 *    (elle existait sur annonces mais pas sur biens en prod)
 * 2. Backfill l'URL https://maboximmo.fr/tarifs.php sur les biens vides
 *
 * Obligation légale d'afficher le barème d'honoraires sur tous les supports
 * (annonces, supports d'affichage, sites de diffusion).
 *
 * - Idempotent : ADD COLUMN IF NOT EXISTS + UPDATE conditionnel
 * - Ne touche pas les biens qui ont déjà une URL personnalisée
 */

return [
    'id'          => '20260527_biens_url_tarifs_publics_default',
    'title'       => 'biens — colonne url_tarifs_publics + backfill défaut',
    'description' => "Ajoute la colonne url_tarifs_publics sur biens (manquait en prod, existait sur annonces) + remplit https://maboximmo.fr/tarifs.php sur les biens existants. Obligation légale (affichage barème honoraires).",
    'created_at'  => '2026-05-27',
    'sql'         => <<<'SQL'

ALTER TABLE `biens`
    ADD COLUMN IF NOT EXISTS `url_tarifs_publics` VARCHAR(2083) NULL DEFAULT NULL
        COMMENT 'URL publique du barème d''honoraires (obligation légale d''affichage)';

UPDATE `biens`
SET `url_tarifs_publics` = 'https://maboximmo.fr/tarifs.php'
WHERE `url_tarifs_publics` IS NULL
   OR `url_tarifs_publics` = '';

SQL
];
