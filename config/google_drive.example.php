<?php
/**
 * GED MaBoxImmo — Exemple de configuration Google Drive
 * Fichier réel recommandé : config/google_drive.php (NON versionné)
 *
 * Notes sécurité :
 * - Ne jamais committer le fichier réel (il contient un refresh_token).
 * - En production Hostinger, idéalement stocker ce fichier hors webroot
 *   (ex : /home/uXXXX/maboximmo_drive_config.php) et laisser la config dans
 *   `u630423897/maboximmo_drive_config.php` comme prévu.
 */

define('GOOGLE_DRIVE_CLIENT_ID',      'PASTE_YOUR_CLIENT_ID_HERE.apps.googleusercontent.com');
define('GOOGLE_DRIVE_CLIENT_SECRET',  'PASTE_YOUR_CLIENT_SECRET_HERE');
define('GOOGLE_DRIVE_REFRESH_TOKEN',  'PASTE_YOUR_REFRESH_TOKEN_HERE');
define('GOOGLE_DRIVE_ROOT_FOLDER_ID', 'PASTE_FOLDER_ID_OF_MaBoxImmo');

// Optionnel — Shared Drive d'organisation :
// define('GOOGLE_DRIVE_DRIVE_ID', 'PASTE_SHARED_DRIVE_ID');

