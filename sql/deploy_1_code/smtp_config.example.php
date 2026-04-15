<?php
/**
 * SMTP Configuration Example
 *
 * This file shows how to configure SMTP credentials.
 *
 * Option 1: Create a smtp_config.php file in the parent directory
 * Option 2: Create a smtp_config.php in the home directory
 * Option 3: Set environment variables (recommended for production)
 *
 * IMPORTANT: Do NOT commit passwords to version control.
 * Use .gitignore to exclude smtp_config.php files.
 */

// Example configuration (copy this to a secured location):
/*
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_PORT', 465);
define('SMTP_SECURE', 'ssl');
define('SMTP_USERNAME', 'contact@maboximmo.fr');
define('SMTP_PASSWORD', 'your-password-here');
define('MAIL_FROM', 'contact@maboximmo.fr');
define('MAIL_FROM_NAME', 'MaBoxImmo');
*/

// Or set via environment variables:
// export SMTP_HOST="smtp.hostinger.com"
// export SMTP_PORT="465"
// export SMTP_SECURE="ssl"
// export SMTP_USERNAME="contact@maboximmo.fr"
// export SMTP_PASSWORD="your-password-here"
// export MAIL_FROM="contact@maboximmo.fr"
// export MAIL_FROM_NAME="MaBoxImmo"
