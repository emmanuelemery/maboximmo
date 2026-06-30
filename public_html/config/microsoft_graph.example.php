<?php
/**
 * Configuration Microsoft Graph / OneDrive — MODÈLE
 * ------------------------------------------------------------------
 * Copier ce fichier en `microsoft_graph.local.php` (NON versionné)
 * et y renseigner les vraies valeurs de l'application Azure
 * « MaBoxImmo OneDrive ».
 *
 * Sécurité : NE JAMAIS commiter le fichier .local.php.
 *            Le client_secret ne doit jamais apparaître dans le HTML.
 *
 * Permissions Azure requises (consentement admin) :
 *   - Files.Read.All
 *   - Sites.Read.All
 *   - User.Read
 * Mode : client_credentials (accès serveur en arrière-plan).
 */

// Identifiant du tenant Azure (annuaire)
define('GRAPH_TENANT_ID', 'REMPLACER_PAR_TENANT_ID');

// Identifiant d'application (client)
define('GRAPH_CLIENT_ID', 'REMPLACER_PAR_CLIENT_ID');

// Secret client — NE JAMAIS AFFICHER NI COMMITER
define('GRAPH_CLIENT_SECRET', 'REMPLACER_PAR_CLIENT_SECRET');

/**
 * UPN du compte OneDrive à explorer.
 * En client_credentials, /me ne fonctionne pas : on utilise
 * /users/{UPN}/drive. Cette valeur sert de drive cible par défaut.
 */
// Drive cible par défaut (« Drive Métier » dans l'explorateur)
define('GRAPH_ONEDRIVE_USER', 'onedrive@regie-emery.com');

// Second drive proposé dans l'explorateur (« Mon Drive » / perso)
define('GRAPH_ONEDRIVE_USER_PERSO', 'emmanuel.emery@regie-emery.com');
