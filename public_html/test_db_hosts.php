<?php
/**
 * Teste automatiquement tous les hostnames MySQL probables pour Hostinger.
 * Ouvre : http://localhost/MaBoxImmo2026/public_html/test_db_hosts.php
 * ⚠️ Supprime ce fichier après usage (contient des tentatives de connexion).
 */
declare(strict_types=1);

// Force utilisation du db_config local
require_once __DIR__ . '/../db_config.php';

header('Content-Type: text/plain; charset=utf-8');

$dbName = DB_NAME;
$dbUser = DB_USER;
$dbPass = DB_PASS;

// Hostnames candidats pour Hostinger (server513)
$candidates = [
    'server513.hstgr.io',
    'srv513.hstgr.io',
    'server513.hostinger.io',
    'server513.main-hosting.eu',
    'mysql.maboximmo.fr',
    'maboximmo.fr',
    '153.92.220.112',
    'localhost',
];

echo "🔍 Test des hostnames MySQL pour Hostinger\n";
echo "Base : $dbName\n";
echo "User : $dbUser\n";
echo "───────────────────────────────────\n\n";

$found = null;
foreach ($candidates as $host) {
    echo "Test : $host ... ";
    try {
        $dsn = "mysql:host={$host};dbname={$dbName};charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 4,  // 4s timeout
        ]);
        $ver = $pdo->query("SELECT VERSION() AS v")->fetch(PDO::FETCH_ASSOC)['v'];
        echo "✅ OK (MariaDB/MySQL $ver)\n";
        $found = $host;
        break;
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        // Tronque les messages longs
        if (mb_strlen($msg) > 100) $msg = mb_substr($msg, 0, 100) . '…';
        echo "❌ $msg\n";
    }
}

echo "\n───────────────────────────────────\n";

if ($found) {
    echo "🎯 HOSTNAME TROUVÉ : $found\n\n";
    echo "Colle cette valeur dans db_config.php :\n";
    echo "  define('DB_HOST', '$found');\n";
} else {
    echo "😭 Aucun host standard n'a fonctionné.\n\n";
    echo "Causes possibles :\n";
    echo " 1. Ton IP n'est pas whitelistée dans hPanel > MySQL distant\n";
    echo " 2. L'accès MySQL distant n'est pas activé sur ton plan Hostinger\n";
    echo " 3. Le hostname est spécifique — regarde dans hPanel > Accès MySQL distant,\n";
    echo "    tout en bas de la page, une ligne 'Accès aux bases de données' affiche\n";
    echo "    l'hostname exact à utiliser.\n";
}
