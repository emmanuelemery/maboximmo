<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    exit('Erreur: PDO non disponible');
}

try {
    // Check if column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'service'");
    $columnExists = $stmt->rowCount() > 0;

    if (!$columnExists) {
        // Add the service column
        $pdo->exec("ALTER TABLE users ADD COLUMN service VARCHAR(20) DEFAULT 'gestion' AFTER id_societe");
        echo "✅ Colonne 'service' ajoutée avec succès à la table users";
    } else {
        echo "⚠️ La colonne 'service' existe déjà";
    }
} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage();
}
