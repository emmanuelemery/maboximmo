<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();

// Admin only
$roleId = current_role_id();
if ($roleId !== 1) {
    http_response_code(403);
    exit('Admin only');
}

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    exit('PDO not available');
}

try {
    $sql = "CREATE TABLE IF NOT EXISTS `mois_clos` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `mois` TINYINT NOT NULL,
      `annee` SMALLINT NOT NULL,
      `clos_par` INT NOT NULL,
      `date_fermeture` DATETIME DEFAULT CURRENT_TIMESTAMP,
      `commentaire` TEXT,
      UNIQUE KEY `unique_mois_annee` (`mois`, `annee`),
      KEY `idx_date` (`date_fermeture`),
      FOREIGN KEY (`clos_par`) REFERENCES `users`(`id`) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $pdo->exec($sql);

    // Verify table exists
    $check = $pdo->query("SHOW TABLES LIKE 'mois_clos'");
    $exists = (bool)$check->fetch();

    header('Content-Type: application/json');
    if ($exists) {
        echo json_encode(['success' => true, 'message' => 'Table mois_clos créée avec succès']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erreur: table non créée']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
