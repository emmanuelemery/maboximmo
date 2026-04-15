<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';

require_login();
verify_csrf_any();

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo) {
    http_response_code(500);
    exit('Erreur: PDO non disponible');
}

$roleId      = current_role_id();
$agenceScope = can_manage_salaires_agence();

if ($roleId !== 1 && $agenceScope === 0) {
    http_response_code(403);
    exit('Accès refusé');
}

$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    exit('ID manquant');
}

try {
    // Gestionnaire agence : vérifier que le congé appartient à son agence
    if ($roleId !== 1 && $agenceScope > 0) {
        $chk = $pdo->prepare("SELECT c.id FROM conges c JOIN users u ON c.id_user = u.id WHERE c.id = ? AND u.id_agence = ?");
        $chk->execute([$id, $agenceScope]);
        if (!$chk->fetch()) {
            http_response_code(403);
            exit('Congé hors de votre agence');
        }
    }

    $stmt = $pdo->prepare("DELETE FROM conges WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() > 0) {
        echo "success";
    } else {
        http_response_code(404);
        exit('Congé non trouvé');
    }
} catch (Exception $e) {
    error_log("Delete leave error: " . $e->getMessage());
    http_response_code(500);
    exit('Erreur: ' . $e->getMessage());
}
?>
