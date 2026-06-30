<?php
/**
 * Migration : backfill url_tarifs_publics par défaut
 *
 * Met l'URL maboximmo.fr/tarifs.php sur tous les biens qui n'en ont pas.
 * Obligation légale d'afficher le barème d'honoraires sur tous les supports
 * (annonces, supports d'affichage, sites de diffusion).
 *
 * - Touche uniquement les biens avec url_tarifs_publics IS NULL ou ''
 * - Ne touche pas les biens qui ont déjà une URL personnalisée
 * - Rejouable sans effet (la 2ème exécution ne trouve plus rien à mettre à jour)
 */

return [
    'id'          => '20260527_biens_url_tarifs_publics_default',
    'title'       => 'biens — backfill url_tarifs_publics par défaut',
    'description' => "Met https://maboximmo.fr/tarifs.php comme URL de tarifs publics par défaut sur tous les biens qui n'en ont pas. Obligation légale (affichage barème honoraires).",
    'created_at'  => '2026-05-27',
    'sql'         => <<<'SQL'

UPDATE `biens`
SET `url_tarifs_publics` = 'https://maboximmo.fr/tarifs.php'
WHERE `url_tarifs_publics` IS NULL
   OR `url_tarifs_publics` = '';

SQL
];
