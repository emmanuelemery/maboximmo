<?php
/**
 * Migration : corrige la corruption legacy « date collée devant l'adresse ».
 *
 * Un vieil import a préfixé certaines adresses par un datetime, ex. :
 *   "2013-11-01 00:00:00 Rue CLAUDE BROSSE"  →  "Rue CLAUDE BROSSE".
 * On retire ce préfixe sur immeubles.adresse_1 ET biens.adresse_1.
 *
 * Idempotent : ne touche que les lignes commençant par AAAA-MM-JJ. Pas de `down`
 * fiable (la date d'origine n'a pas de sens métier ; rien à restaurer).
 */
return [
    'id'          => '20260614f_fix_adresse_date_prefix',
    'title'       => 'Nettoyage adresses préfixées par une date (legacy)',
    'description' => "Retire le préfixe datetime parasite (ex. '2013-11-01 00:00:00 ') devant adresse_1 sur immeubles et biens (corruption d'import).",
    'created_at'  => '2026-06-14',
    'sql' => <<<'SQL'
UPDATE immeubles
   SET adresse_1 = TRIM(REGEXP_REPLACE(adresse_1, '^[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}[[:space:]]+', ''))
 WHERE adresse_1 REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}';

UPDATE biens
   SET adresse_1 = TRIM(REGEXP_REPLACE(adresse_1, '^[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}[[:space:]]+', ''))
 WHERE adresse_1 REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}';
SQL
];
