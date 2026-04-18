<?php
declare(strict_types=1);

function db(bool $forceReconnect = false): PDO
{
    static $pdo = null;

    if ($forceReconnect) {
        $pdo = null;
    }

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Cherche le fichier de credentials dans l'ordre de priorité
    // db_config_dev.php = environnement dev (dev.maboximmo.fr)
    // db_config.php     = environnement prod (maboximmo.fr)
    $possibleDbConfigs = [
        __DIR__ . '/../db_config_dev.php',
        __DIR__ . '/../../db_config_dev.php',
        '/home/u630423897/db_config_dev.php',
        __DIR__ . '/../db_config.php',
        __DIR__ . '/../../db_config.php',
        '/home/u630423897/db_config.php',
    ];

    foreach ($possibleDbConfigs as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            require_once $candidate;
            break;
        }
    }

    $host = getenv('DB_HOST')
        ?: ($_ENV['DB_HOST'] ?? '')
        ?: ($_SERVER['DB_HOST'] ?? '')
        ?: (defined('DB_HOST') ? DB_HOST : '127.0.0.1');

    $dbname = getenv('DB_NAME')
        ?: ($_ENV['DB_NAME'] ?? '')
        ?: ($_SERVER['DB_NAME'] ?? '')
        ?: (defined('DB_NAME') ? DB_NAME : 'u630423897_maboximmo');

    $username = getenv('DB_USER')
        ?: ($_ENV['DB_USER'] ?? '')
        ?: ($_SERVER['DB_USER'] ?? '')
        ?: (defined('DB_USER') ? DB_USER : 'u630423897_maboximmo');

    $password = getenv('DB_PASS')
        ?: ($_ENV['DB_PASS'] ?? '')
        ?: ($_SERVER['DB_PASS'] ?? '')
        ?: (defined('DB_PASS') ? DB_PASS : '');

    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO($dsn, $username, $password, $options);
    return $pdo;
}

/**
 * Vérifie que la connexion MySQL est toujours vivante (après un appel long
 * type OpenAI/curl qui a pu dépasser le wait_timeout du serveur MySQL).
 * Reconnecte automatiquement en cas d'erreur "MySQL server has gone away".
 * Met également à jour $GLOBALS['pdo'].
 */
function db_keepalive(): PDO
{
    $pdo = $GLOBALS['pdo'] ?? db();
    try {
        $pdo->query('SELECT 1');
        return $pdo;
    } catch (Throwable $e) {
        $pdo = db(true);
        $GLOBALS['pdo'] = $pdo;
        return $pdo;
    }
}

// ── Credentials FTP Ubiflow (fichier non versionné) ──────────────────
$_ubifCred = __DIR__ . '/ubiflow_credentials.local.php';
if (is_file($_ubifCred)) {
    require_once $_ubifCred;
}
