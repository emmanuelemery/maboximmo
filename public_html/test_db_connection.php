<?php
/**
 * Test rapide de la connexion BDD.
 * Ouvre http://localhost/MaBoxImmo2026/public_html/test_db_connection.php
 * Affiche host, nom de base, version MariaDB + nb de tables.
 * ⚠️ Ne PAS déployer sur Hostinger (à supprimer avant push prod).
 */
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = db();

    $version   = $pdo->query("SELECT VERSION() AS v")->fetch()['v'] ?? '?';
    $database  = $pdo->query("SELECT DATABASE() AS d")->fetch()['d'] ?? '?';
    $host      = $pdo->query("SELECT @@hostname AS h")->fetch()['h'] ?? '?';
    $nbTables  = $pdo->query("SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = DATABASE()")->fetch()['c'] ?? 0;
    $nbUsers   = $pdo->query("SELECT COUNT(*) AS c FROM users")->fetch()['c'] ?? 0;
    $nbBiens   = $pdo->query("SELECT COUNT(*) AS c FROM biens")->fetch()['c'] ?? 0;

    echo "✅ CONNEXION OK\n";
    echo "───────────────────────────────────\n";
    echo "Serveur     : $host\n";
    echo "Base        : $database\n";
    echo "Version     : $version\n";
    echo "Nb tables   : $nbTables\n";
    echo "Nb users    : $nbUsers\n";
    echo "Nb biens    : $nbBiens\n";
    echo "───────────────────────────────────\n";
    echo "Si le serveur affiché est 'hstgr.io' ou similaire → tu es sur la base Hostinger dev.\n";
    echo "Si c'est 'localhost' ou '127.0.0.1' → tu es encore sur XAMPP.\n";

} catch (Throwable $e) {
    echo "❌ ÉCHEC DE CONNEXION\n";
    echo "───────────────────────────────────\n";
    echo "Erreur : " . $e->getMessage() . "\n\n";
    echo "Causes fréquentes :\n";
    echo " - IP non whitelistée dans hPanel > Bases > MySQL distant\n";
    echo " - db_config.php mal rempli (host/user/pass)\n";
    echo " - Le user n'a pas accès à la base spécifiée\n";
    echo " - Port 3306 bloqué par ton FAI ou firewall\n";
}
