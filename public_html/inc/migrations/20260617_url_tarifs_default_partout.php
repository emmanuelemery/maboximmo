<?php
/**
 * Migration : URL barème (tarifs publics) par DÉFAUT partout + backfill.
 *
 * Problème : la diffusion Ubiflow lit annonces.url_tarifs_publics, or ~46 % des
 * annonces l'avaient vide (et 100 % pour bareme_honoraires_url). Le défaut posé
 * à la création d'un BIEN ne descendait pas dans l'annonce.
 *
 * Correctif :
 *  1. DEFAULT au niveau colonne (annonces + biens) → toute nouvelle ligne qui
 *     n'envoie pas le champ reçoit automatiquement l'URL du barème.
 *  2. Backfill de toutes les lignes existantes vides.
 *  (Un filet de sécurité est aussi posé dans ubiflow_mapping.php : si vide à
 *   l'export, l'URL est injectée — la diffusion n'est jamais bloquée.)
 *
 * Idempotent : MODIFY (rejouable) + UPDATE conditionnel sur les vides.
 */
return [
    'id'          => '20260617_url_tarifs_default_partout',
    'title'       => 'Barème : URL tarifs publics par défaut sur annonces + biens (+ backfill)',
    'description' => "DEFAULT 'https://maboximmo.fr/tarifs.php' sur annonces.url_tarifs_publics et biens.url_tarifs_publics + remplissage des lignes vides.",
    'created_at'  => '2026-06-17',
    'sql' => <<<'SQL'
ALTER TABLE `annonces`
  MODIFY `url_tarifs_publics` VARCHAR(2083) NULL DEFAULT 'https://maboximmo.fr/tarifs.php';

UPDATE `annonces`
  SET `url_tarifs_publics` = 'https://maboximmo.fr/tarifs.php'
  WHERE `url_tarifs_publics` IS NULL OR `url_tarifs_publics` = '';

ALTER TABLE `biens`
  MODIFY `url_tarifs_publics` VARCHAR(2083) NULL DEFAULT 'https://maboximmo.fr/tarifs.php';

UPDATE `biens`
  SET `url_tarifs_publics` = 'https://maboximmo.fr/tarifs.php'
  WHERE `url_tarifs_publics` IS NULL OR `url_tarifs_publics` = '';
SQL
];
