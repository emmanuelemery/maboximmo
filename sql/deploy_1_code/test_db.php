<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';

try {
    $pdo = db();
    echo "Connexion OK";
} catch (Throwable $e) {
    echo "Erreur : " . $e->getMessage();
}