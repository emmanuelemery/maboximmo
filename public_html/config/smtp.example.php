<?php
declare(strict_types=1);

/**
 * CONFIG SMTP — EXEMPLE / TEMPLATE
 * =================================
 * Copiez ce fichier en `smtp.php` (sur le SERVEUR uniquement, par FTP)
 * et remplissez avec le mot de passe SMTP réel.
 *
 * Le fichier `smtp.php` n'est JAMAIS versionné (voir .gitignore) et
 * n'est PAS touché par les workflows de déploiement FTP (exclusion
 * explicite dans .github/workflows/deploy-*.yml).
 *
 * Surcharge possible via variables d'environnement (priorité supérieure) :
 *   SMTP_HOST, SMTP_PORT, SMTP_SECURE,
 *   SMTP_USERNAME, SMTP_PASSWORD,
 *   MAIL_FROM, MAIL_FROM_NAME
 *
 * Ou via constantes globales (à définir AVANT le require de ce fichier).
 */

function smtp_config(): array
{
    $host = getenv('SMTP_HOST')
        ?: ($_ENV['SMTP_HOST'] ?? '')
        ?: ($_SERVER['SMTP_HOST'] ?? '')
        ?: (defined('SMTP_HOST') ? SMTP_HOST : 'smtp.hostinger.com');

    $port = (int)(getenv('SMTP_PORT')
        ?: ($_ENV['SMTP_PORT'] ?? '')
        ?: ($_SERVER['SMTP_PORT'] ?? '')
        ?: (defined('SMTP_PORT') ? SMTP_PORT : 465));

    $secure = getenv('SMTP_SECURE')
        ?: ($_ENV['SMTP_SECURE'] ?? '')
        ?: ($_SERVER['SMTP_SECURE'] ?? '')
        ?: (defined('SMTP_SECURE') ? SMTP_SECURE : 'ssl');

    $username = getenv('SMTP_USERNAME')
        ?: ($_ENV['SMTP_USERNAME'] ?? '')
        ?: ($_SERVER['SMTP_USERNAME'] ?? '')
        ?: (defined('SMTP_USERNAME') ? SMTP_USERNAME : 'contact@maboximmo.fr');

    $password = getenv('SMTP_PASSWORD')
        ?: ($_ENV['SMTP_PASSWORD'] ?? '')
        ?: ($_SERVER['SMTP_PASSWORD'] ?? '')
        ?: (defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '________________');

    $from = getenv('MAIL_FROM')
        ?: ($_ENV['MAIL_FROM'] ?? '')
        ?: ($_SERVER['MAIL_FROM'] ?? '')
        ?: (defined('MAIL_FROM') ? MAIL_FROM : 'contact@maboximmo.fr');

    $fromName = getenv('MAIL_FROM_NAME')
        ?: ($_ENV['MAIL_FROM_NAME'] ?? '')
        ?: ($_SERVER['MAIL_FROM_NAME'] ?? '')
        ?: (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'MaBoxImmo');

    return [
        'host'      => $host,
        'port'      => $port,
        'secure'    => $secure,
        'username'  => $username,
        'password'  => $password,
        'from'      => $from,
        'fromName'  => $fromName,
    ];
}
