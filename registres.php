<?php
declare(strict_types=1);

// Optional override file at project root
$possibleRegistresConfigs = [
    __DIR__ . '/../registres_config.php',
    __DIR__ . '/../../registres_config.php',
];

foreach ($possibleRegistresConfigs as $candidate) {
    if (is_file($candidate) && is_readable($candidate)) {
        require_once $candidate;
        break;
    }
}

if (!defined('REGISTRES_DB_HOST')) {
    define('REGISTRES_DB_HOST', '127.0.0.1');
}
if (!defined('REGISTRES_DB_NAME')) {
    define('REGISTRES_DB_NAME', 'registres_local');
}
if (!defined('REGISTRES_DB_USER')) {
    define('REGISTRES_DB_USER', 'root');
}
if (!defined('REGISTRES_DB_PASS')) {
    define('REGISTRES_DB_PASS', '');
}
if (!defined('REGISTRES_BASE_URL')) {
    // Local default. Override in prod via registres_config.php
    define('REGISTRES_BASE_URL', '/REGISTRES');
}

function registres_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('REGISTRES_DB_HOST')
        ?: ($_ENV['REGISTRES_DB_HOST'] ?? '')
        ?: ($_SERVER['REGISTRES_DB_HOST'] ?? '')
        ?: REGISTRES_DB_HOST;

    $dbname = getenv('REGISTRES_DB_NAME')
        ?: ($_ENV['REGISTRES_DB_NAME'] ?? '')
        ?: ($_SERVER['REGISTRES_DB_NAME'] ?? '')
        ?: REGISTRES_DB_NAME;

    $username = getenv('REGISTRES_DB_USER')
        ?: ($_ENV['REGISTRES_DB_USER'] ?? '')
        ?: ($_SERVER['REGISTRES_DB_USER'] ?? '')
        ?: REGISTRES_DB_USER;

    $password = getenv('REGISTRES_DB_PASS')
        ?: ($_ENV['REGISTRES_DB_PASS'] ?? '')
        ?: ($_SERVER['REGISTRES_DB_PASS'] ?? '')
        ?: REGISTRES_DB_PASS;

    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO($dsn, $username, $password, $options);
    return $pdo;
}
