<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    exit('Erreur: PDO non disponible');
}

try {
    // Check if table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'mail_templates'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("
            CREATE TABLE mail_templates (
                id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                titre VARCHAR(100) NOT NULL,
                categorie VARCHAR(50) NOT NULL,
                sujet VARCHAR(255) NOT NULL,
                corps LONGTEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_categorie (categorie)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo '✅ Table mail_templates créée';
    } else {
        echo '✓ Table mail_templates existe déjà';
    }
} catch (Exception $e) {
    echo '❌ Erreur: ' . $e->getMessage();
}
?>
