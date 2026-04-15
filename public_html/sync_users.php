<?php
declare(strict_types=1);
require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth.php';
require_login();
if ((int)current_role_id() !== 1) { http_response_code(403); exit('Accès réservé admin.'); }

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    exit('PDO non disponible');
}

// Essayer de se connecter à registres_local
try {
    $pdo_local = new PDO(
        'mysql:host=localhost;dbname=registres_local;charset=utf8mb4',
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    exit('Erreur connexion registres_local: ' . $e->getMessage());
}

// Récupérer les users 36 et 38 de registres_local
$ids = [36, 38];
$stmt = $pdo_local->prepare("SELECT * FROM users WHERE id IN (?, ?)");
$stmt->execute($ids);
$users_local = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Trouvé " . count($users_local) . " users dans registres_local\n\n";

foreach ($users_local as $u) {
    echo "Processing user: " . $u['prenom'] . " " . $u['nom'] . " (ID: " . $u['id'] . ")\n";

    // Vérifier si l'user existe déjà en MaBoxImmo
    $checkStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? OR email = ?");
    $checkStmt->execute([$u['id'], $u['email']]);
    $exists = $checkStmt->fetch();

    if ($exists) {
        echo "  ✗ User existe déjà en MaBoxImmo (ID: " . $exists['id'] . ")\n";
        continue;
    }

    // Copier le user vers MaBoxImmo
    try {
        $insertStmt = $pdo->prepare("
            INSERT INTO users (
                id, username, password, email, telephone, prenom, nom,
                id_societe, id_agence, id_role, actif, date_creation, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $insertStmt->execute([
            $u['id'],
            $u['username'] ?? null,
            $u['password'] ?? null,
            $u['email'] ?? null,
            $u['telephone'] ?? null,
            $u['prenom'] ?? null,
            $u['nom'] ?? null,
            $u['id_societe'] ?? null,
            $u['id_agence'] ?? null,
            $u['id_role'] ?? 3, // collaborateur par défaut
            1, // actif
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s')
        ]);

        echo "  ✓ User copié avec succès\n";
    } catch (Exception $e) {
        echo "  ✗ Erreur insertion: " . $e->getMessage() . "\n";
    }
}

echo "\nSync terminé!\n";
?>
