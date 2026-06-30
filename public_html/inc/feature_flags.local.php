<?php
/**
 * inc/feature_flags.local.php
 *
 * Overrides locaux des feature flags. Ce fichier est dans .gitignore.
 * Chargé en premier par inc/feature_flags.php — les define() ici sont définitifs.
 *
 * Activé 2026-05-23 pour valider Sprint 2B/2C sur la BDD dev locale.
 * Production reste OFF tant que ce fichier n'existe pas sur le serveur prod.
 */
declare(strict_types=1);

define('FEATURE_ENTITY_MATCHER', true);
define('FEATURE_ENTITY_MATCHER_LOG', true);
