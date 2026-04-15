<?php
declare(strict_types=1);
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

require_admin();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    exit('Erreur: PDO non disponible');
}

// Palette de 20 couleurs uniques
$colorPalette = [
    '#FF6B6B', '#4ECDC4', '#45B7D1', '#FFA07A', '#98D8C8',
    '#F7DC6F', '#BB8FCE', '#85C1E2', '#F8B88B', '#ABEBC6',
    '#F1948A', '#A9DFBF', '#D7BDE2', '#F5B7B1', '#F9E79F',
    '#FADBD8', '#D5F4E6', '#EAFAF1', '#FCF3CF', '#FEF9E7'
];

try {
    // Get users without colors
    $stmt = $pdo->prepare("SELECT id FROM users WHERE actif = 1 AND (couleur IS NULL OR couleur = '') ORDER BY id");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $updated = 0;
    foreach ($users as $index => $user) {
        $color = $colorPalette[$index % count($colorPalette)];
        $updateStmt = $pdo->prepare("UPDATE users SET couleur = ? WHERE id = ?");
        $updateStmt->execute([$color, $user['id']]);
        $updated++;
    }

    echo json_encode([
        'success' => true,
        'message' => "Couleurs assignées à $updated utilisateurs",
        'updated' => $updated
    ]);

} catch (Exception $e) {
    error_log("Assign colors error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
