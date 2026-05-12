<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $possibleDbConfigs = [
        __DIR__ . '/../db_config.php',
        __DIR__ . '/../../db_config.php',
        __DIR__ . '/../home/db_config.php',
        '/home/u630423897/db_config.php',
        '/home/u630423897/u630423897/db_config.php',
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
        ?: (defined('DB_NAME') ? DB_NAME : 'maboximmo');

    $username = getenv('DB_USER')
        ?: ($_ENV['DB_USER'] ?? '')
        ?: ($_SERVER['DB_USER'] ?? '')
        ?: (defined('DB_USER') ? DB_USER : 'root');

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

