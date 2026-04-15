<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    exit('Erreur: PDO non disponible');
}

try {
    // Get all users with nom containing space but prenom is NULL/empty
    $stmt = $pdo->query("SELECT id, nom FROM users WHERE nom LIKE '% %' AND (prenom IS NULL OR prenom = '')");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $updated = 0;
    foreach ($users as $user) {
        // Split nom into nom and prenom
        // Format: "NOM Prenom" -> nom="NOM", prenom="Prenom"
        $parts = explode(' ', trim($user['nom']));
        $prenom = array_pop($parts); // Last part is prenom
        $nom = implode(' ', $parts); // Rest is nom

        // Update user
        $updateStmt = $pdo->prepare("UPDATE users SET nom = ?, prenom = ? WHERE id = ?");
        $updateStmt->execute([$nom, $prenom, $user['id']]);
        $updated++;
    }

    echo "✅ Migration réussie: $updated utilisateur(s) mis à jour\n";
    echo "Exemple: 'BRIAND Benoit' -> nom='BRIAND', prenom='Benoit'";
} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage();
}
